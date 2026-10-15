<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;

class NotADeclaration
{
    #[Meta]
    public string $color = '';
}
