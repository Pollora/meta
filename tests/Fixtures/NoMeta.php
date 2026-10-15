<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\PostType;

#[PostType('no-meta')]
class NoMeta
{
    public string $title = '';
}
