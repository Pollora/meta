<?php

declare(strict_types=1);

namespace Pollora\Meta\Infrastructure\Adapters;

use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Contracts\MetaValidatorInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use ReflectionProperty;
use WP_Error;
use WP_REST_Comments_Controller;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Terms_Controller;
use WP_REST_Users_Controller;

/**
 * Applies the `rules` of typed meta to REST writes, before anything is stored.
 *
 * WordPress validates a meta written through REST against its JSON schema only.
 * Hooked on `rest_request_before_callbacks`, this checks the `meta` of a request
 * to a post, term, user or comment route, and answers 400 with the message of
 * the failed rule, as WordPress does for an invalid parameter.
 */
final readonly class WordPressRestMetaValidation
{
    public function __construct(
        private MetaSchemaRepository $schemas,
        private MetaValueCaster $caster,
        private MetaValidatorInterface $validator,
    ) {}

    /**
     * @param  mixed  $response  The response so far: a WP_Error stops the request
     * @param  array<string, mixed>  $handler  The matched route handler
     */
    public function validate(mixed $response, array $handler, WP_REST_Request $request): mixed
    {
        $meta = $request->get_param('meta');

        if ($response instanceof WP_Error || ! is_array($meta) || $meta === [] || $request->get_method() === 'GET') {
            return $response;
        }

        [$objectType, $subtype] = $this->objectOf($handler['callback'][0] ?? null);

        if (! $objectType instanceof MetaObjectType) {
            return $response;
        }

        $errors = [];

        foreach ($this->schemas->forObject($objectType, $subtype) as $schema) {
            foreach ($schema->definitions as $definition) {
                if ($definition->rules === [] || ! array_key_exists($definition->key, $meta)) {
                    continue;
                }

                $message = $this->check($definition, $meta[$definition->key]);

                if ($message !== null) {
                    $errors['meta.'.$definition->key] = $message;
                }
            }
        }

        if ($errors === []) {
            return $response;
        }

        return new WP_Error(
            'rest_invalid_param',
            /* translators: %s: List of invalid parameters. */
            sprintf(__('Invalid parameter(s): %s'), 'meta'),
            ['status' => 400, 'params' => $errors]
        );
    }

    private function check(MetaDefinition $definition, mixed $value): ?string
    {
        try {
            // A value of the wrong type is left to WordPress's schema validation.
            $phpValue = $this->caster->toPhp($definition, $value);
        } catch (InvalidMetaValueException) {
            return null;
        }

        if ($phpValue === null) {
            return null;
        }

        try {
            $this->validator->validate($definition, $phpValue);
        } catch (MetaValidationException $metaValidationException) {
            return implode(' ', $metaValidationException->messages);
        }

        return null;
    }

    /**
     * The meta object type and subtype a core REST controller writes.
     *
     * @return array{0: MetaObjectType|null, 1: string|null}
     */
    private function objectOf(mixed $controller): array
    {
        return match (true) {
            $controller instanceof WP_REST_Posts_Controller => [MetaObjectType::Post, $this->property($controller, 'post_type')],
            $controller instanceof WP_REST_Terms_Controller => [MetaObjectType::Term, $this->property($controller, 'taxonomy')],
            $controller instanceof WP_REST_Users_Controller => [MetaObjectType::User, null],
            $controller instanceof WP_REST_Comments_Controller => [MetaObjectType::Comment, null],
            default => [null, null],
        };
    }

    private function property(object $controller, string $name): ?string
    {
        $value = (new ReflectionProperty($controller, $name))->getValue($controller);

        return is_string($value) ? $value : null;
    }
}
