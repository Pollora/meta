<?php

declare(strict_types=1);

namespace Pollora\Meta\Application\Services;

use BackedEnum;
use DateTimeInterface;
use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\Meta;
use Pollora\Attributes\PostMeta;
use Pollora\Attributes\PostType;
use Pollora\Attributes\Support\Naming;
use Pollora\Attributes\Taxonomy;
use Pollora\Attributes\TermMeta;
use Pollora\Attributes\UserMeta;
use Pollora\Meta\Domain\Enums\Control;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use UnitEnum;

/**
 * Builds the meta schema of a class declaring meta (`#[PostType]`, `#[Taxonomy]`,
 * `#[PostMeta]`, `#[TermMeta]`, `#[UserMeta]`, `#[CommentMeta]`) from its
 * `#[Meta]` properties.
 *
 * Every rule WordPress would otherwise break silently is checked here, so a
 * wrong declaration fails at discovery with the class and property named.
 */
final class MetaSchemaBuilder
{
    /**
     * @param  class-string  $class
     *
     * @throws InvalidMetaDefinitionException When the class is not a declaration or a property cannot be registered
     */
    public function build(string $class): MetaSchema
    {
        $reflection = new ReflectionClass($class);
        [$objectType, $subtypes, $declaresSubtypes] = $this->resolveOwner($reflection);

        $definitions = [];
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Meta::class);

            if ($attributes === [] || $property->isStatic()) {
                continue;
            }

            $definition = $this->buildDefinition($class, $property, $attributes[0]->newInstance(), $objectType);

            if (isset($properties[$definition->key])) {
                throw InvalidMetaDefinitionException::forProperty($class, $property->getName(), sprintf(
                    'the key "%s" is already used by $%s.',
                    $definition->key,
                    $properties[$definition->key]
                ));
            }

            $properties[$definition->key] = $definition->property;
            $definitions[$definition->property] = $definition;
        }

        return new MetaSchema($class, $objectType, $subtypes, $definitions, $declaresSubtypes);
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array{0: MetaObjectType, 1: list<string>, 2: bool}
     */
    private function resolveOwner(ReflectionClass $reflection): array
    {
        $class = $reflection->getName();
        $owner = static fn (string $attribute): ?object => ($reflection->getAttributes($attribute)[0] ?? null)?->newInstance();

        return match (true) {
            ($postType = $owner(PostType::class)) instanceof PostType => [MetaObjectType::Post, [$postType->resolveSlug($class)], true],
            ($taxonomy = $owner(Taxonomy::class)) instanceof Taxonomy => [MetaObjectType::Term, [$taxonomy->resolveSlug($class)], true],
            ($postMeta = $owner(PostMeta::class)) instanceof PostMeta => [MetaObjectType::Post, $postMeta->postTypes, false],
            ($termMeta = $owner(TermMeta::class)) instanceof TermMeta => [MetaObjectType::Term, $termMeta->taxonomies, false],
            $owner(UserMeta::class) instanceof UserMeta => [MetaObjectType::User, [], false],
            $owner(CommentMeta::class) instanceof CommentMeta => [MetaObjectType::Comment, [], false],
            default => throw InvalidMetaDefinitionException::notADeclaration($class),
        };
    }

    private function buildDefinition(string $class, ReflectionProperty $property, Meta $meta, MetaObjectType $objectType): MetaDefinition
    {
        $name = $property->getName();
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'the property needs a single type (no union, no untyped property).');
        }

        [$valueType, $valueClass] = $this->resolveValueType($class, $name, $type->getName());

        // A data object without a default reads as an instance with its own defaults.
        if (! $property->hasDefaultValue() && ! $type->allowsNull() && $valueType !== MetaValueType::DataObject) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'give the property a default value or make it nullable: it is what an absent meta reads as.');
        }

        if (! $meta->single && $valueType !== MetaValueType::ArrayOf) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'single: false stores one row per item, so it needs an array property.');
        }

        $items = $valueType === MetaValueType::ArrayOf ? $this->buildItems($class, $property, $meta) : null;
        $properties = $valueType === MetaValueType::DataObject ? $this->buildProperties($class, $name, (string) $valueClass) : [];

        $key = $meta->resolveKey($name);

        if ($meta->showInRest && str_starts_with($key, '_') && $meta->capability === null) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, sprintf(
                'the protected key "%s" can only be exposed in REST with an explicit capability.',
                $key
            ));
        }

        if ($meta->media && $valueType !== MetaValueType::Integer) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'media: true holds an attachment ID, so it needs an int property.');
        }

        if ($meta->revisions && $objectType !== MetaObjectType::Post) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'revisions only exist for post types.');
        }

        return new MetaDefinition(
            property: $name,
            key: $key,
            valueType: $valueType,
            valueClass: $valueClass,
            nullable: $type->allowsNull(),
            default: $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            showInRest: $meta->showInRest,
            label: $meta->label,
            description: $meta->description,
            sanitize: $meta->sanitize,
            capability: $meta->capability,
            revisions: $meta->revisions,
            rules: array_values($meta->rules),
            single: $meta->single,
            items: $items,
            properties: $properties,
            control: $meta->control ?? ($meta->media ? Control::Media : $this->defaultControl($valueType, $meta)),
            group: $meta->group,
            hints: $meta->hints,
            media: $meta->media,
            public: $meta->public,
        );
    }

    /**
     * @return array{0: MetaValueType, 1: class-string|null}
     */
    private function resolveValueType(string $class, string $property, string $typeName): array
    {
        $scalar = match ($typeName) {
            'string' => MetaValueType::String,
            'int' => MetaValueType::Integer,
            'float' => MetaValueType::Number,
            'bool' => MetaValueType::Boolean,
            default => null,
        };

        if ($scalar instanceof MetaValueType) {
            return [$scalar, null];
        }

        if (is_a($typeName, DateTimeInterface::class, true)) {
            return [MetaValueType::DateTime, $typeName];
        }

        if (is_a($typeName, BackedEnum::class, true)) {
            return [MetaValueType::Enum, $typeName];
        }

        if ($typeName === 'array') {
            return [MetaValueType::ArrayOf, null];
        }

        if (class_exists($typeName) && ! is_a($typeName, UnitEnum::class, true) && (new ReflectionClass($typeName))->isInstantiable()) {
            return [MetaValueType::DataObject, $typeName];
        }

        $reason = is_a($typeName, UnitEnum::class, true)
            ? sprintf('the enum %s needs backing values (enum %s: string).', $typeName, class_basename($typeName))
            : sprintf('the type %s is not supported; use string, int, float, bool, a date, a backed enum, an array or a class with public typed properties.', $typeName);

        throw InvalidMetaDefinitionException::forProperty($class, $property, $reason);
    }

    /**
     * The input a type calls for; none for an array or an object, which a UI
     * driver builds its own way.
     */
    private function defaultControl(MetaValueType $valueType, Meta $meta): ?Control
    {
        return match ($valueType) {
            MetaValueType::String => $meta->sanitize === 'wp_kses_post' ? Control::RichText : Control::Text,
            MetaValueType::Integer, MetaValueType::Number => Control::Number,
            MetaValueType::Boolean => Control::Toggle,
            MetaValueType::DateTime => Control::DateTime,
            MetaValueType::Enum => Control::Select,
            MetaValueType::ArrayOf, MetaValueType::DataObject => null,
        };
    }

    /**
     * The item of an array property, from `items:` or the `@var list<…>` docblock.
     */
    private function buildItems(string $class, ReflectionProperty $property, Meta $meta): MetaDefinition
    {
        $name = $property->getName();
        $itemType = $meta->items ?? $this->itemTypeFromDocblock((string) $property->getDocComment());

        if ($itemType === null) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, "say what the array holds, with items: 'string' (or int, float, bool, a class) or a @var list<string> docblock.");
        }

        $itemType = ltrim($itemType, '\\');

        if ($itemType === 'array') {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'an item cannot be an array: use a class with public typed properties.');
        }

        $scalar = ['string' => 'string', 'int' => 'int', 'integer' => 'int', 'float' => 'float', 'bool' => 'bool', 'boolean' => 'bool'][$itemType] ?? null;

        if ($scalar === null && ! class_exists($itemType) && ! enum_exists($itemType)) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, sprintf('the item type %s is unknown: use string, int, float, bool or a fully qualified class.', $itemType));
        }

        [$valueType, $valueClass] = $this->resolveValueType($class, $name, $scalar ?? $itemType);

        if ($valueType === MetaValueType::ArrayOf || ($valueType === MetaValueType::DataObject && ! $meta->single)) {
            throw InvalidMetaDefinitionException::forProperty($class, $name, 'an item cannot be an array, and objects need single: true.');
        }

        return new MetaDefinition(
            property: $name,
            key: $meta->resolveKey($name),
            valueType: $valueType,
            valueClass: $valueClass,
            nullable: false,
            default: null,
            properties: $valueType === MetaValueType::DataObject ? $this->buildProperties($class, $name, (string) $valueClass) : [],
        );
    }

    private function itemTypeFromDocblock(string $docblock): ?string
    {
        if (preg_match('/@var\s+(?:list<\s*([\w\\\\]+)\s*>|array<\s*(?:int\s*,\s*)?([\w\\\\]+)\s*>|([\w\\\\]+)\[\])/', $docblock, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?: ($matches[2] ?? '') ?: ($matches[3] ?? null);
    }

    /**
     * The properties of a data object: public typed properties of a scalar, date
     * or enum type, each with a default or nullable.
     *
     * @param  class-string|string  $objectClass
     * @return array<string, MetaDefinition>
     */
    private function buildProperties(string $class, string $name, string $objectClass): array
    {
        $reflection = new ReflectionClass($objectClass);
        $defaults = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $type = $property->getType();
            $where = sprintf('%s::$%s', $objectClass, $propertyName);

            if (! $type instanceof ReflectionNamedType) {
                throw InvalidMetaDefinitionException::forProperty($class, $name, sprintf('%s needs a single type.', $where));
            }

            [$valueType, $valueClass] = $this->resolveValueType($class, $name, $type->getName());

            if ($valueType === MetaValueType::ArrayOf || $valueType === MetaValueType::DataObject) {
                throw InvalidMetaDefinitionException::forProperty($class, $name, sprintf('%s: an object property can be a string, int, float, bool, date or backed enum, not an array or an object.', $where));
            }

            $hasDefault = $property->hasDefaultValue() || array_key_exists($propertyName, $defaults);

            if (! $hasDefault && ! $type->allowsNull()) {
                throw InvalidMetaDefinitionException::forProperty($class, $name, sprintf('%s needs a default value or a nullable type.', $where));
            }

            $properties[$propertyName] = new MetaDefinition(
                property: $propertyName,
                key: Naming::snake($propertyName),
                valueType: $valueType,
                valueClass: $valueClass,
                nullable: $type->allowsNull(),
                default: $property->hasDefaultValue() ? $property->getDefaultValue() : ($defaults[$propertyName] ?? null),
            );
        }

        return $properties;
    }
}
