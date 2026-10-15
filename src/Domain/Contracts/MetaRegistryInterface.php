<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Contracts;

use Pollora\Meta\Domain\Models\MetaSchema;

/**
 * Registers typed meta with WordPress (`register_meta()`).
 */
interface MetaRegistryInterface
{
    public function register(MetaSchema $schema): void;
}
