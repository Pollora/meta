<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Contracts;

use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * What the database holds, read in bulk for the audit of typed meta: the
 * stored values of a key, and the keys stored on a kind of object.
 */
interface MetaInventoryInterface
{
    /**
     * The stored values of a key, by object, unserialized (never into objects).
     * Revisions are left out.
     *
     * @param  list<string>  $subtypes  Post types or taxonomies; empty for every object of the type
     * @return array<int, list<mixed>> Values by object ID, one per row
     */
    public function values(MetaObjectType $objectType, array $subtypes, string $key, int $limit): array;

    /**
     * The keys stored on these objects that do not start with "_", with the
     * number of objects carrying each.
     *
     * @param  list<string>  $subtypes
     * @return array<string, int>
     */
    public function keys(MetaObjectType $objectType, array $subtypes, int $limit): array;
}
