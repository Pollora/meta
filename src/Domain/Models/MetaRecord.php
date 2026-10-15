<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Models;

use Closure;
use InvalidArgumentException;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Contracts\MetaValidatorInterface;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Domain\Services\MetaValueCaster;

/**
 * The typed meta of one WordPress object, as returned by `Meta::of()`.
 *
 * Properties are read lazily and returned with their declared PHP type; writes
 * are validated immediately and stored on `save()`:
 *
 *     $event = Meta::of(Event::class, $postId);
 *     $event->capacity;                         // int
 *     $event->fill(['capacity' => 250])->save();
 *
 * Properties can be named as declared (`startsAt`) or by their key (`starts_at`).
 */
final class MetaRecord
{
    /**
     * @var array<string, mixed> Values already read or written, by property name
     */
    private array $values = [];

    /**
     * @var array<string, string|array<array-key, mixed>|null> Stored forms waiting for save(), by property name
     */
    private array $pending = [];

    /**
     * @param  Closure(InvalidMetaValueException, MetaDefinition): mixed  $onUnreadable  Decides what an unreadable stored value gives
     */
    public function __construct(
        private readonly MetaSchema $schema,
        private readonly int $objectId,
        private readonly MetaStoreInterface $store,
        private readonly MetaValueCaster $caster,
        private readonly Closure $onUnreadable,
        private readonly ?MetaValidatorInterface $validator = null,
    ) {}

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->schema->find($name) instanceof MetaDefinition && $this->get($name) !== null;
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    /**
     * The value of a meta, with its declared PHP type.
     */
    public function get(string $name): mixed
    {
        $definition = $this->definition($name);

        if (! array_key_exists($definition->property, $this->values)) {
            $this->values[$definition->property] = $this->read($definition);
        }

        return $this->values[$definition->property];
    }

    /**
     * Sets a meta, written on `save()`. Null deletes a nullable meta.
     *
     * @throws InvalidMetaValueException When the value does not match the property type
     * @throws MetaValidationException When the value breaks a rule of the meta
     */
    public function set(string $name, mixed $value): static
    {
        $definition = $this->definition($name);
        $stored = $this->caster->toStorage($definition, $value);

        if ($stored !== null) {
            $this->validator?->validate($definition, $value);
        }

        $this->pending[$definition->property] = $stored;
        $this->values[$definition->property] = $value;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values  Values by property name or key
     */
    public function fill(array $values): static
    {
        foreach ($values as $name => $value) {
            $this->set($name, $value);
        }

        return $this;
    }

    /**
     * Writes the pending values through WordPress's meta API.
     */
    public function save(): static
    {
        foreach ($this->pending as $property => $stored) {
            $definition = $this->schema->definitions[$property];

            match (true) {
                $stored === null => $this->store->delete($this->schema->objectType, $this->objectId, $definition->key),
                ! $definition->single && is_array($stored) => $this->store->replaceAll($this->schema->objectType, $this->objectId, $definition->key, array_values(array_map(strval(...), $stored))),
                default => $this->store->update($this->schema->objectType, $this->objectId, $definition->key, $stored),
            };
        }

        $this->pending = [];

        return $this;
    }

    /**
     * Every declared meta, by property name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (array_keys($this->schema->definitions) as $property) {
            $values[$property] = $this->get($property);
        }

        return $values;
    }

    private function definition(string $name): MetaDefinition
    {
        return $this->schema->find($name) ?? throw new InvalidArgumentException(sprintf(
            '%s declares no meta named "%s".',
            $this->schema->declaringClass,
            $name
        ));
    }

    private function read(MetaDefinition $definition): mixed
    {
        $raw = $definition->single
            ? $this->store->get($this->schema->objectType, $this->objectId, $definition->key)
            : $this->store->getAll($this->schema->objectType, $this->objectId, $definition->key);

        try {
            return $this->caster->toPhp($definition, $raw);
        } catch (InvalidMetaValueException $invalidMetaValueException) {
            return ($this->onUnreadable)($invalidMetaValueException, $definition);
        }
    }
}
