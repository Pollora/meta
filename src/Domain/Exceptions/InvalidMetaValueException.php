<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Exceptions;

use InvalidArgumentException;
use Pollora\Meta\Domain\Models\MetaDefinition;

/**
 * A meta value that does not match its declared type.
 */
final class InvalidMetaValueException extends InvalidArgumentException
{
    /**
     * A value written from PHP that the property type does not accept.
     */
    public static function forWrite(MetaDefinition $definition, mixed $value): self
    {
        return new self(sprintf(
            'The meta "%s" expects %s, %s given.',
            $definition->key,
            self::describeExpected($definition),
            get_debug_type($value)
        ));
    }

    /**
     * A value stored in the database that cannot be read as the declared type.
     */
    public static function forRead(MetaDefinition $definition, mixed $raw): self
    {
        return new self(sprintf(
            'The stored value of the meta "%s" cannot be read as %s: %s.',
            $definition->key,
            self::describeExpected($definition),
            is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw)
        ));
    }

    private static function describeExpected(MetaDefinition $definition): string
    {
        $expected = $definition->valueClass ?? $definition->wordPressType();

        return $definition->nullable ? '?'.$expected : $expected;
    }
}
