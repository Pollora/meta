<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;

#[UserMeta]
class BillingProfile
{
    #[Meta]
    public ?string $jobTitle = null;
}
