<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Meta\Domain\Contracts\MetaInventoryInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Meta rows in memory, by object type, then subtype, then object ID and key.
 */
final class ArrayMetaInventory implements MetaInventoryInterface
{
    /** @var list<string> */
    public array $reads = [];

    /**
     * @param  array<string, array<string, array<int, array<string, list<mixed>>>>>  $rows
     */
    public function __construct(private readonly array $rows = []) {}

    public function values(MetaObjectType $objectType, array $subtypes, string $key, int $limit): array
    {
        $this->reads[] = $objectType->value.':'.$key;
        $values = [];

        foreach ($this->rows[$objectType->value] ?? [] as $subtype => $objects) {
            if ($subtypes !== [] && ! in_array($subtype, $subtypes, true)) {
                continue;
            }

            foreach ($objects as $objectId => $meta) {
                if (isset($meta[$key])) {
                    $values[$objectId] = $meta[$key];
                }
            }
        }

        return array_slice($values, 0, $limit, true);
    }

    public function keys(MetaObjectType $objectType, array $subtypes, int $limit): array
    {
        $keys = [];

        foreach ($this->rows[$objectType->value] ?? [] as $subtype => $objects) {
            if ($subtypes !== [] && ! in_array($subtype, $subtypes, true)) {
                continue;
            }

            foreach ($objects as $meta) {
                foreach (array_keys($meta) as $key) {
                    if (! str_starts_with($key, '_')) {
                        $keys[$key] = ($keys[$key] ?? 0) + 1;
                    }
                }
            }
        }

        return $keys;
    }
}
