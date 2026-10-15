<?php

declare(strict_types=1);

namespace Pollora\Meta\Infrastructure\Adapters;

use Pollora\Meta\Domain\Contracts\MetaInventoryInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Reads the meta tables through $wpdb, in a few grouped queries: the audit
 * reads keys across thousands of objects, which the meta API would load one
 * object at a time.
 */
final readonly class WordPressMetaInventory implements MetaInventoryInterface
{
    public function values(MetaObjectType $objectType, array $subtypes, string $key, int $limit): array
    {
        $wpdb = $this->wpdb();
        [$from, $where, $params] = $this->scope($wpdb, $objectType, $subtypes);

        /** @var list<object{object_id: string, meta_value: string|null}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.{$this->column($objectType)} AS object_id, m.meta_value FROM {$from} WHERE m.meta_key = %s{$where} ORDER BY m.{$this->idColumn($objectType)} LIMIT %d",
            ...[$key, ...$params, max(1, $limit)]
        ));

        $values = [];

        foreach ($rows as $row) {
            $values[(int) $row->object_id][] = $this->unserialize($row->meta_value);
        }

        return $values;
    }

    public function keys(MetaObjectType $objectType, array $subtypes, int $limit): array
    {
        $wpdb = $this->wpdb();
        [$from, $where, $params] = $this->scope($wpdb, $objectType, $subtypes);
        $column = $this->column($objectType);

        /** @var list<object{meta_key: string, objects: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_key, COUNT(DISTINCT m.{$column}) AS objects FROM {$from} WHERE m.meta_key NOT LIKE %s{$where} GROUP BY m.meta_key ORDER BY objects DESC, m.meta_key LIMIT %d",
            ...['\_%', ...$params, max(1, $limit)]
        ));

        $keys = [];

        foreach ($rows as $row) {
            $keys[$row->meta_key] = (int) $row->objects;
        }

        return $keys;
    }

    /**
     * The tables to read and the condition on the subtypes.
     *
     * @param  list<string>  $subtypes
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function scope(object $wpdb, MetaObjectType $objectType, array $subtypes): array
    {
        $placeholders = implode(', ', array_fill(0, count($subtypes), '%s'));

        return match ($objectType) {
            MetaObjectType::Post => [
                "{$wpdb->postmeta} m INNER JOIN {$wpdb->posts} o ON o.ID = m.post_id",
                " AND o.post_type <> 'revision'".($subtypes === [] ? '' : " AND o.post_type IN ({$placeholders})"),
                $subtypes,
            ],
            MetaObjectType::Term => [
                "{$wpdb->termmeta} m".($subtypes === [] ? '' : " INNER JOIN {$wpdb->term_taxonomy} o ON o.term_id = m.term_id"),
                $subtypes === [] ? '' : " AND o.taxonomy IN ({$placeholders})",
                $subtypes,
            ],
            MetaObjectType::User => ["{$wpdb->usermeta} m", '', []],
            MetaObjectType::Comment => ["{$wpdb->commentmeta} m", '', []],
        };
    }

    private function column(MetaObjectType $objectType): string
    {
        return $objectType->value.'_id';
    }

    private function idColumn(MetaObjectType $objectType): string
    {
        return $objectType === MetaObjectType::User ? 'umeta_id' : 'meta_id';
    }

    /**
     * A stored value as WordPress would return it, never instantiating a class.
     */
    private function unserialize(?string $value): mixed
    {
        if ($value === null || ! \is_serialized($value)) {
            return $value;
        }

        return @unserialize(trim($value), ['allowed_classes' => false]);
    }

    /**
     * WordPress's database object, typed loosely: only its table names,
     * prepare() and get_results() are used.
     *
     * @return \wpdb
     */
    private function wpdb(): object
    {
        return $GLOBALS['wpdb'];
    }
}
