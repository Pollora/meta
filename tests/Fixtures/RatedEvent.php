<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;

#[PostType('rated_event')]
class RatedEvent
{
    #[Meta(showInRest: true, label: 'Capacity', rules: ['min:0', 'max:5000'])]
    public int $capacity = 0;

    #[Meta(rules: ['in:published'])]
    public EventStatus $status = EventStatus::Draft;

    #[Meta(rules: ['email'])]
    public ?string $contact = null;

    #[Meta]
    public int $free = 0;
}
