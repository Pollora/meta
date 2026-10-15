<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
