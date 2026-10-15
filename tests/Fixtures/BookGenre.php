<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\Taxonomy;

#[Taxonomy]
class BookGenre
{
    #[Meta(showInRest: true)]
    public ?string $color = null;
}
