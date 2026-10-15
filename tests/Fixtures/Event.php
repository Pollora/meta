<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;

#[PostType('event')]
class Event
{
    #[Meta(showInRest: true, label: 'Start', description: 'When the event starts')]
    public ?CarbonImmutable $startsAt = null;

    #[Meta(showInRest: true)]
    public int $capacity = 0;

    #[Meta]
    public float $price = 9.5;

    #[Meta(showInRest: true)]
    public bool $soldOut = false;

    #[Meta(showInRest: true)]
    public EventStatus $status = EventStatus::Draft;

    #[Meta]
    public ?Priority $priority = null;

    #[Meta(revisions: true)]
    public ?string $subtitle = null;

    #[Meta(key: '_event_internal_ref', capability: 'manage_options')]
    public ?string $internalRef = null;

    #[Meta(sanitize: 'wp_kses_post')]
    public string $summary = '';

    #[Meta]
    public ?DateTimeInterface $endsAt = null;

    public string $notAMeta = '';

    #[Meta]
    public static string $ignoredStatic = '';
}
