<?php

declare(strict_types=1);

namespace Pollora\Meta\Application\Services;

use Closure;
use LogicException;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Contracts\MetaUiDriver;
use Pollora\Meta\Domain\Contracts\MetaValidatorInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaRecord;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives the typed meta of a WordPress object: the service behind `Meta::of()`.
 *
 * A stored value that cannot be read as its declared type throws in debug mode,
 * so it shows during development; in production it reads as the property
 * default and is logged, so a damaged value never takes a public page down.
 */
final readonly class MetaAccessor
{
    public function __construct(
        private MetaSchemaRepository $schemas,
        private MetaSchemaBuilder $builder,
        private MetaStoreInterface $store,
        private MetaValueCaster $caster,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
        private ?MetaValidatorInterface $validator = null,
        private ?MetaUiDrivers $drivers = null,
    ) {}

    /**
     * @param  class-string  $class  The class declaring the meta
     * @param  int  $objectId  The post, term, user or comment ID
     */
    public function of(string $class, int $objectId): MetaRecord
    {
        return $this->record($this->schema($class), $objectId);
    }

    /**
     * The schema a class declares, discovered or built on the spot.
     *
     * @param  class-string  $class
     */
    public function schema(string $class): MetaSchema
    {
        return $this->schemas->forClass($class) ?? $this->builder->build($class);
    }

    /**
     * Every typed meta schema of the project.
     *
     * @return list<MetaSchema>
     */
    public function schemas(): array
    {
        return $this->schemas->all();
    }

    /**
     * The schemas whose meta an object carries: `Meta::schemaFor('post', 'event')`,
     * `Meta::schemaFor('user')`.
     *
     * @return list<MetaSchema>
     */
    public function schemaFor(MetaObjectType|string $objectType, ?string $subtype = null): array
    {
        return $this->schemas->forObject($objectType instanceof MetaObjectType ? $objectType : MetaObjectType::from($objectType), $subtype);
    }

    /**
     * Registers a UI driver, from a package's service provider:
     * `Meta::extend('acf', AcfDriver::class)`.
     *
     * @param  class-string<MetaUiDriver>|Closure(ContainerInterface): MetaUiDriver  $driver
     */
    public function extend(string $name, string|Closure $driver): void
    {
        ($this->drivers ?? throw new LogicException('Meta UI drivers are not available.'))->extend($name, $driver);
    }

    /**
     * The typed meta of a schema on one object.
     */
    public function record(MetaSchema $schema, int $objectId): MetaRecord
    {
        return new MetaRecord($schema, $objectId, $this->store, $this->caster, $this->handleUnreadable(...), $this->validator);
    }

    private function handleUnreadable(InvalidMetaValueException $exception, MetaDefinition $definition): mixed
    {
        if ($this->debug) {
            throw $exception;
        }

        $this->logger?->warning($exception->getMessage());

        return $definition->default;
    }
}
