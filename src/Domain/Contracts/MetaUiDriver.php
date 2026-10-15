<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Contracts;

use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;

/**
 * Builds input fields for typed meta, in an admin UI the framework does not
 * provide: ACF field groups, Meta Box fields, an editor panel. A package
 * implements it and registers it with `Meta::extend('acf', AcfDriver::class)`;
 * the project picks it with `meta.ui`.
 *
 * Rules a driver keeps:
 *  - it writes values in the stored form of the schema (`MetaValueCaster`); when
 *    it cannot, `supports()` says false and the meta gets no field;
 *  - writes go through WordPress's meta API, so sanitization, `rules` and
 *    `capability` apply;
 *  - plugin-specific options come from `MetaDefinition::$hints`, never from the
 *    framework.
 *
 * `Pollora\Meta\Testing\MetaUiDriverConformance` checks a driver against them.
 */
interface MetaUiDriver
{
    /**
     * Whether the driver can build a field for this meta.
     */
    public function supports(MetaDefinition $definition): bool;

    /**
     * Builds the fields of a schema, given only the meta the driver supports.
     */
    public function register(MetaSchema $schema): void;
}
