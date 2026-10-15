<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Exceptions;

use LogicException;

/**
 * A `#[Meta]` declaration WordPress cannot register as written.
 *
 * Raised while the schema is built, so that the mistake surfaces at discovery
 * rather than as an empty value on a page.
 */
final class InvalidMetaDefinitionException extends LogicException
{
    public static function forProperty(string $class, string $property, string $reason): self
    {
        return new self(sprintf('#[Meta] on %s::$%s: %s', $class, $property, $reason));
    }

    public static function notADeclaration(string $class): self
    {
        return new self(sprintf('%s declares no meta: it carries none of #[PostType], #[Taxonomy], #[PostMeta], #[TermMeta], #[UserMeta], #[CommentMeta].', $class));
    }

    public static function duplicateKey(string $key, string $owner, string $firstClass, string $secondClass): self
    {
        return new self(sprintf(
            'The meta key "%s" of %s is declared twice, by %s and by %s.',
            $key,
            $owner,
            $firstClass,
            $secondClass
        ));
    }
}
