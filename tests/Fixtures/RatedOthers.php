<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\Meta;
use Pollora\Attributes\Taxonomy;
use Pollora\Attributes\UserMeta;

#[Taxonomy('rated_genre')]
class RatedGenre
{
    #[Meta(rules: ['max:3'])]
    public int $stars = 0;
}

#[UserMeta]
class RatedMember
{
    #[Meta(rules: ['max:10'])]
    public int $level = 0;
}

#[CommentMeta]
class RatedReview
{
    #[Meta(rules: ['max:5'])]
    public int $score = 0;
}
