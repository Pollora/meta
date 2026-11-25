<?php

declare(strict_types=1);

namespace Pollora\Meta\Infrastructure\Adapters;

use Pollora\Hook\Domain\Contract\Action;
use Pollora\Meta\Domain\Contracts\MetaRegistryInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Domain\Services\MetaValueCaster;

/**
 * Registers typed meta with `register_meta()`, on `init` after post types and
 * taxonomies (priority 20), or right away when `init` has already run, and adds
 * `custom-fields` to a declared post type that exposes a meta in REST.
 */
final readonly class WordPressMetaRegistry implements MetaRegistryInterface
{
    public const int INIT_PRIORITY = 20;

    public function __construct(
        private Action $action,
        private MetaValueCaster $caster,
    ) {}

    public function register(MetaSchema $schema): void
    {
        if (\did_action('init') > 0) {
            $this->registerNow($schema);

            return;
        }

        $this->action->add('init', fn () => $this->registerNow($schema), self::INIT_PRIORITY);
    }

    private function registerNow(MetaSchema $schema): void
    {
        // WordPress leaves `meta` out of a post's REST response unless its post
        // type supports custom-fields. Only a post type the class declares is
        // changed: one targeted by #[PostMeta] belongs to someone else.
        if ($schema->objectType === MetaObjectType::Post && $schema->declaresSubtypes && $schema->exposesInRest()) {
            foreach ($schema->subtypes as $postType) {
                \add_post_type_support($postType, 'custom-fields');
            }
        }

        // An empty subtype registers the meta for every object of the type.
        $subtypes = $schema->subtypes === [] ? [''] : $schema->subtypes;

        // Checked first: a refused declaration registers none of its meta.
        foreach ($subtypes as $subtype) {
            foreach ($schema->definitions as $definition) {
                $this->assertRevisionsSupported($schema, $definition, $subtype);
            }
        }

        foreach ($subtypes as $subtype) {
            foreach ($schema->definitions as $definition) {
                \register_meta($schema->objectType->value, $definition->key, $this->argumentsFor($schema, $definition, $subtype));
            }
        }
    }

    /**
     * Refuses a meta that asks for revisions on a post type that does not support them.
     *
     * `register_meta()` returns false for it (with a `_doing_it_wrong()` notice that is silent without WP_DEBUG) and the
     * meta is never registered. Checked here, when the post types are registered, as WordPress checks it.
     *
     * @throws InvalidMetaDefinitionException
     */
    private function assertRevisionsSupported(MetaSchema $schema, MetaDefinition $definition, string $subtype): void
    {
        if (! $definition->revisions || $schema->objectType !== MetaObjectType::Post || $subtype === '') {
            return;
        }

        if (! \post_type_supports($subtype, 'revisions')) {
            throw InvalidMetaDefinitionException::forProperty($schema->declaringClass, $definition->property, sprintf(
                'revisions: true needs the post type "%s" to support "revisions", and it does not: WordPress would not register the meta "%s". Add "revisions" to the supports of the post type, or drop revisions: true.',
                $subtype,
                $definition->key
            ));
        }
    }

    /**
     * @return array{object_subtype: string, type: string, single: bool, sanitize_callback: callable, show_in_rest: array<string, mixed>|false, label?: string, description?: string, default?: mixed, auth_callback?: callable, revisions_enabled?: bool}
     */
    private function argumentsFor(MetaSchema $schema, MetaDefinition $definition, string $subtype): array
    {
        $arguments = [
            'object_subtype' => $subtype,
            'type' => $definition->wordPressType(),
            'single' => $definition->single,
            'sanitize_callback' => $this->sanitizerOf($schema, $definition),
            'show_in_rest' => $definition->showInRest ? ['schema' => $definition->restSchema()] : false,
        ];

        if ($definition->label !== null) {
            $arguments['label'] = $definition->label;
        }

        if ($definition->description !== null) {
            $arguments['description'] = $definition->description;
        }

        $default = $this->caster->toRestDefault($definition);

        if ($default !== null) {
            $arguments['default'] = $default;
        }

        if ($definition->capability !== null) {
            $capability = $definition->capability;
            $arguments['auth_callback'] = static fn (bool $allowed, string $key, int $objectId, int $userId): bool => \user_can($userId, $capability);
        }

        if ($definition->revisions && $schema->objectType === MetaObjectType::Post) {
            $arguments['revisions_enabled'] = true;
        }

        return $arguments;
    }

    /**
     * The callback WordPress runs to sanitize the meta: the declared one, or the one derived from the type.
     *
     * A declared callback that is not callable yet (a function a plugin defines later) is resolved when WordPress
     * sanitizes a value, as it always was.
     */
    private function sanitizerOf(MetaSchema $schema, MetaDefinition $definition): callable
    {
        $declared = $definition->sanitize;

        if ($declared === null) {
            return $this->sanitizerFor($definition);
        }

        if (is_callable($declared)) {
            return $declared;
        }

        $class = $schema->declaringClass;
        $property = $definition->property;

        return static fn (mixed ...$arguments): mixed => self::sanitizeLate($class, $property, $declared, $arguments);
    }

    /**
     * Runs a declared sanitize callback that was not callable when the meta was registered.
     *
     * @param  array<int|string, mixed>  $arguments
     *
     * @throws InvalidMetaDefinitionException When it is still not callable.
     */
    private static function sanitizeLate(string $class, string $property, mixed $declared, array $arguments): mixed
    {
        if (! is_callable($declared)) {
            throw InvalidMetaDefinitionException::forProperty($class, $property, 'sanitize: is not callable. Name a function, or a [class, method] pair that exists.');
        }

        return call_user_func_array($declared, $arguments);
    }

    private function sanitizerFor(MetaDefinition $definition): callable
    {
        // WordPress sanitizes each row of a non-single meta on its own.
        if ($definition->valueType === MetaValueType::ArrayOf && ! $definition->single) {
            return $this->sanitizerFor($definition->item());
        }

        if ($definition->valueType === MetaValueType::String) {
            return 'sanitize_text_field';
        }

        return fn (mixed $value): mixed => $this->sanitizeText($definition, $this->caster->sanitize($definition, $value));
    }

    /**
     * Strips HTML from the strings inside an array or an object, as from a string meta.
     */
    private function sanitizeText(MetaDefinition $definition, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $definition->valueType === MetaValueType::String && is_string($value) ? \sanitize_text_field($value) : $value;
        }

        if ($definition->valueType === MetaValueType::ArrayOf) {
            return array_map(fn (mixed $item): mixed => $this->sanitizeText($definition->item(), $item), $value);
        }

        foreach ($definition->properties as $property) {
            if (array_key_exists($property->key, $value)) {
                $value[$property->key] = $this->sanitizeText($property, $value[$property->key]);
            }
        }

        return $value;
    }
}
