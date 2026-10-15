<?php

declare(strict_types=1);

namespace Pollora\Meta\Application\Services;

use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;

/**
 * The meta schemas discovered in the project, by declaring class.
 */
final class MetaSchemaRepository
{
    /**
     * @var array<class-string, MetaSchema>
     */
    private array $schemas = [];

    /**
     * @var array<string, array<string, true>> Property names and keys, by object type
     */
    private array $names = [];

    /**
     * @var array<class-string, string> Declarations discovery refused, with the reason
     */
    private array $failures = [];

    /**
     * @throws InvalidMetaDefinitionException When another class already declares one of its keys on the same object
     */
    public function add(MetaSchema $schema): void
    {
        foreach ($this->schemas as $existing) {
            if ($existing->declaringClass === $schema->declaringClass || ! $existing->overlaps($schema)) {
                continue;
            }

            foreach ($schema->definitions as $definition) {
                if ($existing->find($definition->key) instanceof MetaDefinition) {
                    throw InvalidMetaDefinitionException::duplicateKey($definition->key, $schema->ownerName(), $existing->declaringClass, $schema->declaringClass);
                }
            }
        }

        $this->schemas[$schema->declaringClass] = $schema;

        foreach ($schema->definitions as $definition) {
            $this->names[$schema->objectType->value][$definition->property] = true;
            $this->names[$schema->objectType->value][$definition->key] = true;
        }
    }

    /**
     * @param  class-string  $class
     */
    public function forClass(string $class): ?MetaSchema
    {
        return $this->schemas[$class] ?? null;
    }

    /**
     * The schemas whose meta an object can carry: those of its type covering every
     * object, and those listing its subtype.
     *
     * @return list<MetaSchema>
     */
    public function forObject(MetaObjectType $objectType, ?string $subtype): array
    {
        return array_values(array_filter(
            $this->schemas,
            static fn (MetaSchema $schema): bool => $schema->objectType === $objectType
                && ($schema->subtypes === [] || ($subtype !== null && in_array($subtype, $schema->subtypes, true)))
        ));
    }

    /**
     * Whether a schema of this object type declares a meta under that property name or key.
     */
    public function declares(MetaObjectType $objectType, string $propertyOrKey): bool
    {
        return isset($this->names[$objectType->value][$propertyOrKey]);
    }

    /**
     * Records a declaration discovery could not register, so that it can be
     * reported (pollora:doctor) instead of only logged.
     *
     * @param  class-string  $class
     */
    public function fail(string $class, string $reason): void
    {
        $this->failures[$class] = $reason;
    }

    /**
     * The declarations discovery refused, with the reason, by class.
     *
     * @return array<class-string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return list<MetaSchema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }
}
