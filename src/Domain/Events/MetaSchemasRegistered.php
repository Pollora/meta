<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Events;

use Pollora\Meta\Domain\Models\MetaSchema;

/**
 * Dispatched once the typed meta are registered with WordPress, on `init`.
 */
final readonly class MetaSchemasRegistered
{
    /**
     * @param  list<MetaSchema>  $schemas
     */
    public function __construct(public array $schemas) {}
}
