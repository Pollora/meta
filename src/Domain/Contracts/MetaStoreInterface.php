<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Contracts;

use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Reads and writes stored meta values, through WordPress's meta API.
 */
interface MetaStoreInterface
{
    /**
     * The stored value, or null when the meta does not exist.
     */
    public function get(MetaObjectType $objectType, int $objectId, string $key): mixed;

    /**
     * Every stored row of a non-single meta, in order.
     *
     * @return list<mixed>
     */
    public function getAll(MetaObjectType $objectType, int $objectId, string $key): array;

    /**
     * @param  string|array<array-key, mixed>  $value  A string, or an array WordPress serializes
     */
    public function update(MetaObjectType $objectType, int $objectId, string $key, string|array $value): void;

    /**
     * Replaces every row of a non-single meta.
     *
     * @param  list<string>  $values
     */
    public function replaceAll(MetaObjectType $objectType, int $objectId, string $key, array $values): void;

    public function delete(MetaObjectType $objectType, int $objectId, string $key): void;
}
