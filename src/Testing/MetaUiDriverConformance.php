<?php

declare(strict_types=1);

namespace Pollora\Meta\Testing;

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaUiDrivers;
use Pollora\Meta\Domain\Contracts\MetaUiDriver;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Testing\Fixtures\EveryMetaKind;
use Throwable;

/**
 * Checks a UI driver against the MetaUiDriver contract, from any test framework:
 *
 *     expect(MetaUiDriverConformance::check(new AcfDriver))->toBe([]);
 *
 * It gives the driver a schema with one meta of every type and control, and
 * returns what breaks the contract. The rules about storage (write in the
 * schema's stored form, through WordPress's meta API) depend on the plugin the
 * driver targets: the driver's own tests cover them.
 */
final class MetaUiDriverConformance
{
    /**
     * A schema with one meta of every type and control.
     */
    public static function schema(): MetaSchema
    {
        return (new MetaSchemaBuilder)->build(EveryMetaKind::class);
    }

    /**
     * What the driver does against the contract; empty when it conforms.
     *
     * @return list<string>
     */
    public static function check(MetaUiDriver $driver): array
    {
        $schema = self::schema();
        $violations = [];

        foreach ($schema->definitions as $definition) {
            try {
                if ($driver->supports($definition) !== $driver->supports($definition)) {
                    $violations[] = sprintf('supports() answers differently for "%s" when asked twice.', $definition->key);
                }
            } catch (Throwable $throwable) {
                $violations[] = sprintf('supports() throws for "%s" (%s): it must answer false instead.', $definition->key, $throwable->getMessage());
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        try {
            (new MetaUiDrivers(new InstantiatingContainer))->build($driver, [$schema]);
        } catch (Throwable $throwable) {
            $violations[] = sprintf('register() throws for the meta it supports: %s', $throwable->getMessage());
        }

        return $violations;
    }
}
