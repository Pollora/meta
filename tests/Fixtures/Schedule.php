<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Carbon\CarbonImmutable;

final class Schedule
{
    public ?CarbonImmutable $startsAt = null;

    public int $durationMinutes = 60;

    public EventStatus $status = EventStatus::Draft;

    public ?string $room = null;

    public function __construct(public bool $public = true) {}
}
