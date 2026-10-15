<?php

declare(strict_types=1);

namespace Pollora\Meta\Application\Services;

use Pollora\Meta\Domain\Contracts\MetaInventoryInterface;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Services\MetaValueCaster;

/**
 * Compares what the database holds with what the code declares:
 *
 *  - stored values a `#[Meta]` cannot read as its type (a property whose type
 *    changed, a value written by hand or by a plugin), which read as the
 *    default and are logged — the page shows the default, silently;
 *  - keys stored on the project's own post types and taxonomies that no
 *    `#[Meta]` declares (a renamed property leaves its old key behind).
 */
final readonly class MetaAuditor
{
    /** Objects named per finding, at most. */
    private const int SAMPLE = 5;

    public function __construct(
        private MetaSchemaRepository $schemas,
        private MetaInventoryInterface $inventory,
        private MetaValueCaster $caster,
    ) {}

    /**
     * @param  int  $limit  Rows read per key, at most
     * @return list<array{class: class-string, property: string, key: string, owner: string, checked: int, unreadable: int, objects: list<int>, example: string}>
     */
    public function unreadable(int $limit): array
    {
        $findings = [];

        foreach ($this->schemas->all() as $schema) {
            foreach ($schema->definitions as $definition) {
                $values = $this->inventory->values($schema->objectType, $schema->subtypes, $definition->key, $limit);
                $objects = [];
                $example = '';

                foreach ($values as $objectId => $rows) {
                    try {
                        $this->caster->toPhp($definition, $definition->single ? $rows[0] : $rows);
                    } catch (InvalidMetaValueException $invalidMetaValueException) {
                        $objects[] = $objectId;
                        $example = $example === '' ? $invalidMetaValueException->getMessage() : $example;
                    }
                }

                if ($objects === []) {
                    continue;
                }

                $findings[] = [
                    'class' => $schema->declaringClass,
                    'property' => $definition->property,
                    'key' => $definition->key,
                    'owner' => $schema->ownerName(),
                    'checked' => count($values),
                    'unreadable' => count($objects),
                    'objects' => array_slice($objects, 0, self::SAMPLE),
                    'example' => $example,
                ];
            }
        }

        return $findings;
    }

    /**
     * Keys stored on the post types and taxonomies the project declares that
     * no `#[Meta]` declares. Protected keys (starting with "_") are left out.
     *
     * @param  int  $limit  Keys read per post type or taxonomy, at most
     * @return list<array{owner: string, key: string, objects: int}>
     */
    public function undeclared(int $limit): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->schemas->all() as $schema) {
            if (! $schema->declaresSubtypes) {
                continue;
            }

            foreach ($schema->subtypes as $subtype) {
                if (isset($seen[$schema->objectType->value][$subtype])) {
                    continue;
                }

                $seen[$schema->objectType->value][$subtype] = true;

                foreach ($this->inventory->keys($schema->objectType, [$subtype], $limit) as $key => $objects) {
                    if (! $this->schemas->declares($schema->objectType, $key)) {
                        $findings[] = ['owner' => sprintf('%s "%s"', $schema->objectType->value, $subtype), 'key' => $key, 'objects' => $objects];
                    }
                }
            }
        }

        return $findings;
    }
}
