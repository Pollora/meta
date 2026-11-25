<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\Meta;

#[CommentMeta]
class LateSanitizers
{
    #[Meta(sanitize: 'pollora_meta_defined_late')]
    public string $late = '';

    #[Meta(sanitize: 'pollora_meta_never_defined')]
    public string $never = '';
}
