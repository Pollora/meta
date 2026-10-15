<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Models;

use Pollora\Meta\Domain\Enums\MetaObjectType;

/**
 * Every typed meta one class declares, with the object they are attached to.
 */
final readonly class MetaSchema
{
    /**
     * @param  class-string  $declaringClass  The class declaring the meta
     * @param  MetaObjectType  $objectType  The WordPress object the meta belong to
     * @param  list<string>  $subtypes  The post type or taxonomy slugs; empty for every object of the type
     * @param  array<string, MetaDefinition>  $definitions  Definitions keyed by property name
     * @param  bool  $declaresSubtypes  Whether the class also declares its post type or taxonomy (`#[PostType]`, `#[Taxonomy]`)
     */
    public function __construct(
        public string $declaringClass,
        public MetaObjectType $objectType,
        public array $subtypes,
        public array $definitions,
        public bool $declaresSubtypes = false,
    ) {}

    /**
     * Whether some object can carry the meta of both schemas.
     */
    public function overlaps(self $other): bool
    {
        if ($this->objectType !== $other->objectType) {
            return false;
        }

        return $this->subtypes === [] || $other->subtypes === [] || array_intersect($this->subtypes, $other->subtypes) !== [];
    }

    /**
     * The objects the meta belong to, for messages: `post "event"`, `user`.
     */
    public function ownerName(): string
    {
        return $this->subtypes === []
            ? $this->objectType->value
            : sprintf('%s "%s"', $this->objectType->value, implode('", "', $this->subtypes));
    }

    /**
     * The same schema, with only the given definitions.
     *
     * @param  array<string, MetaDefinition>  $definitions
     */
    public function withDefinitions(array $definitions): self
    {
        return new self($this->declaringClass, $this->objectType, $this->subtypes, $definitions, $this->declaresSubtypes);
    }

    /**
     * Whether a meta of the schema is exposed in REST.
     */
    public function exposesInRest(): bool
    {
        foreach ($this->definitions as $definition) {
            if ($definition->showInRest) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->definitions === [];
    }

    /**
     * The definition of a meta, found by its property name or its key.
     */
    public function find(string $propertyOrKey): ?MetaDefinition
    {
        if (isset($this->definitions[$propertyOrKey])) {
            return $this->definitions[$propertyOrKey];
        }

        foreach ($this->definitions as $definition) {
            if ($definition->key === $propertyOrKey) {
                return $definition;
            }
        }

        return null;
    }
}
