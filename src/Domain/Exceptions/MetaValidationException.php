<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Exceptions;

use InvalidArgumentException;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * A value of the right type that breaks a rule of its meta (`rules: ['max:5000']`).
 */
final class MetaValidationException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $messages  The messages of the failed rules
     */
    public function __construct(
        public readonly MetaDefinition $definition,
        public readonly array $messages,
    ) {
        parent::__construct(sprintf('The meta "%s" is invalid: %s', $definition->key, implode(' ', $messages)));
    }
}
