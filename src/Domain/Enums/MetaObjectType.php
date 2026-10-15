<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Enums;

/**
 * The WordPress object a meta is attached to, as `register_meta()` names it.
 */
enum MetaObjectType: string
{
    case Post = 'post';
    case Term = 'term';
    case User = 'user';
    case Comment = 'comment';
}
