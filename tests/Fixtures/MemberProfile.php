<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;

#[UserMeta]
class MemberProfile
{
    #[Meta(showInRest: true)]
    public bool $newsletterOptIn = false;

    #[Meta]
    public ?string $jobTitle = null;
}
