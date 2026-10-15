<?php

declare(strict_types=1);

namespace Pollora\Meta\Application\Services;

use Closure;
use InvalidArgumentException;
use Pollora\Meta\Domain\Contracts\MetaUiDriver;
use Pollora\Meta\Domain\Models\MetaSchema;
use Psr\Container\ContainerInterface;

/**
 * The UI drivers packages register, and the hand-off of the schemas to the one
 * the project picks.
 */
final class MetaUiDrivers
{
    /** @var array<string, class-string<MetaUiDriver>|Closure(ContainerInterface): MetaUiDriver> */
    private array $drivers = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param  class-string<MetaUiDriver>|Closure(ContainerInterface): MetaUiDriver  $driver
     */
    public function extend(string $name, string|Closure $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    /**
     * @throws InvalidArgumentException When no package registered that driver
     */
    public function driver(string $name): MetaUiDriver
    {
        $driver = $this->drivers[$name] ?? throw new InvalidArgumentException(sprintf('No meta UI driver is named "%s"; its package registers it with Meta::extend().', $name));
        $instance = $driver instanceof Closure ? $driver($this->container) : $this->container->get($driver);

        return $instance instanceof MetaUiDriver
            ? $instance
            : throw new InvalidArgumentException(sprintf('The meta UI driver "%s" does not implement %s.', $name, MetaUiDriver::class));
    }

    /**
     * Gives each schema to the driver, with only the meta it supports; a schema
     * left without any is skipped.
     *
     * @param  list<MetaSchema>  $schemas
     */
    public function build(MetaUiDriver $driver, array $schemas): void
    {
        foreach ($schemas as $schema) {
            $supported = array_filter($schema->definitions, $driver->supports(...));

            if ($supported !== []) {
                $driver->register($schema->withDefinitions($supported));
            }
        }
    }
}
