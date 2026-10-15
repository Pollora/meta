<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;
use Pollora\Attributes\Taxonomy;

#[PostType('invalid-union')]
class InvalidUnion
{
    #[Meta]
    public int|string $value = 0;
}

#[PostType('invalid-array')]
class InvalidArray
{
    #[Meta]
    public array $values = [];
}

#[PostType('invalid-pure-enum')]
class InvalidPureEnum
{
    #[Meta]
    public ?Mood $mood = null;
}

#[PostType('invalid-no-default')]
class InvalidNoDefault
{
    #[Meta]
    public int $count;
}

#[PostType('invalid-protected')]
class InvalidProtectedInRest
{
    #[Meta(key: '_secret', showInRest: true)]
    public string $secret = '';
}

#[Taxonomy('invalid-revisions')]
class InvalidTermRevisions
{
    #[Meta(revisions: true)]
    public string $note = '';
}

#[PostType('invalid-duplicate')]
class InvalidDuplicateKey
{
    #[Meta(key: 'shared')]
    public string $first = '';

    #[Meta(key: 'shared')]
    public string $second = '';
}

#[PostType('invalid-untyped')]
class InvalidUntyped
{
    #[Meta]
    public $value = '';
}

#[PostType('invalid-single')]
class InvalidSingleFalse
{
    #[Meta(single: false)]
    public int $count = 0;
}

#[PostType('invalid-nested')]
class InvalidNestedArray
{
    #[Meta(items: 'array')]
    public array $matrix = [];
}

#[PostType('invalid-unknown-item')]
class InvalidUnknownItem
{
    /** @var list<Speaker> */
    #[Meta]
    public array $speakers = [];
}

#[PostType('invalid-rows-of-objects')]
class InvalidRowsOfObjects
{
    #[Meta(single: false, items: Schedule::class)]
    public array $sessions = [];
}

final class ScheduleWithList
{
    public array $tags = [];
}

#[PostType('invalid-object-property')]
class InvalidObjectProperty
{
    #[Meta]
    public ?ScheduleWithList $schedule = null;
}

#[PostType('invalid-media')]
class InvalidMedia
{
    #[Meta(media: true)]
    public ?string $cover = null;
}
