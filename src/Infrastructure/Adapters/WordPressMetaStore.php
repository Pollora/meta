<?php

declare(strict_types=1);

namespace Pollora\Meta\Infrastructure\Adapters;

use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Stored meta values through WordPress's meta API, so the object cache,
 * sanitize callbacks and meta hooks apply to every read and write.
 */
final class WordPressMetaStore implements MetaStoreInterface
{
    public function get(MetaObjectType $objectType, int $objectId, string $key): mixed
    {
        // The raw read skips the registered default: an absent meta must read as
        // the property default, after the cast, not as WordPress's.
        return \get_metadata_raw($objectType->value, $objectId, $key, true);
    }

    public function getAll(MetaObjectType $objectType, int $objectId, string $key): array
    {
        return array_values((array) \get_metadata_raw($objectType->value, $objectId, $key, false));
    }

    public function update(MetaObjectType $objectType, int $objectId, string $key, string|array $value): void
    {
        \update_metadata($objectType->value, $objectId, \wp_slash($key), \wp_slash($value));
    }

    public function replaceAll(MetaObjectType $objectType, int $objectId, string $key, array $values): void
    {
        \delete_metadata($objectType->value, $objectId, \wp_slash($key));

        foreach ($values as $value) {
            \add_metadata($objectType->value, $objectId, \wp_slash($key), \wp_slash($value));
        }
    }

    public function delete(MetaObjectType $objectType, int $objectId, string $key): void
    {
        \delete_metadata($objectType->value, $objectId, \wp_slash($key));
    }
}
