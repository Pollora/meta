<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\PostMeta;

/**
 * Asks for revisions on a post type that does not support them (registered by the test that uses it).
 */
#[PostMeta('plain_type')]
class RevisionsOnPlainType
{
    #[Meta]
    public int $pages = 0;

    #[Meta(revisions: true)]
    public ?string $summary = null;
}
