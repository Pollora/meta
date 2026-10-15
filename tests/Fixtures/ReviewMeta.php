<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\Meta;

#[CommentMeta]
class ReviewMeta
{
    #[Meta]
    public int $rating = 5;
}
