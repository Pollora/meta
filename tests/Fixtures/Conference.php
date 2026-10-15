<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;

#[PostType('conference')]
class Conference
{
    /** @var list<string> */
    #[Meta(showInRest: true, rules: ['max:3'], single: false)]
    public array $speakers = [];

    #[Meta(showInRest: true, items: 'int')]
    public array $roomIds = [];

    #[Meta(single: false, items: EventStatus::class)]
    public array $statuses = [];

    #[Meta(showInRest: true)]
    public Schedule $schedule;

    #[Meta]
    public ?Schedule $backup = null;

    #[Meta(showInRest: true, items: Schedule::class)]
    public array $sessions = [];
}
