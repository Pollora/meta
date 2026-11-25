<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Tests\Fixtures\Conference;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\Priority;
use Pollora\Meta\Tests\Fixtures\Schedule;

beforeEach(function (): void {
    $this->caster = new MetaValueCaster;
    $this->event = (new MetaSchemaBuilder)->build(Event::class)->definitions;
    $this->conference = (new MetaSchemaBuilder)->build(Conference::class)->definitions;
});

it('keeps a null default when the type is not an object, even for a non-nullable meta', function (): void {
    $definition = new MetaDefinition('name', 'name', MetaValueType::String, null, false, null);

    expect($this->caster->absentValue($definition))->toBeNull();
});

describe('toStorage() of a non-single array', function (): void {
    it('stores one string per item, as a list', function (): void {
        $rooms = new MetaDefinition('rooms', 'rooms', MetaValueType::ArrayOf, null, false, [], single: false, items: new MetaDefinition('rooms', 'rooms', MetaValueType::Integer, null, false, null));

        $stored = $this->caster->toStorage($rooms, [5 => 1, 9 => 2]);

        expect($stored)->toBe(['1', '2']);
    });

    it('refuses an item that is itself an array, which is not one row', function (): void {
        $inner = new MetaDefinition('rooms', 'rooms', MetaValueType::ArrayOf, null, false, [], items: new MetaDefinition('rooms', 'rooms', MetaValueType::Integer, null, false, null));
        $rooms = new MetaDefinition('rooms', 'rooms', MetaValueType::ArrayOf, null, false, [], single: false, items: $inner);

        expect(fn () => $this->caster->toStorage($rooms, [[1, 2]]))->toThrow(InvalidMetaValueException::class);
    });

    it('refuses what is not an array', function (): void {
        expect(fn () => $this->caster->toStorage($this->conference['speakers'], 'Ada'))
            ->toThrow(InvalidMetaValueException::class, 'The meta "speakers" expects');
    });

    it('stores the enum cases of its items by value', function (): void {
        expect($this->caster->toStorage($this->conference['statuses'], [EventStatus::Published, EventStatus::Draft]))->toBe(['published', 'draft']);
    });
});

describe('toStorage() of an enum', function (): void {
    it('refuses a plain value instead of a case', function (mixed $value): void {
        expect(fn () => $this->caster->toStorage($this->event['status'], $value))
            ->toThrow(InvalidMetaValueException::class);
    })->with(['its raw value' => 'published', 'an int' => 2, 'an array' => [[]]]);
});

describe('toJson()', function (): void {
    it('keeps numbers and booleans typed, turns the rest into strings', function (string $property, mixed $value, mixed $expected): void {
        expect($this->caster->toJson($this->event[$property], $value))->toBe($expected);
    })->with([
        'string' => ['summary', 'Hello', 'Hello'],
        'stringable' => ['summary', new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Stringable';
            }
        }, 'Stringable'],
        'int' => ['capacity', 7, 7],
        'float' => ['price', 1.5, 1.5],
        'int for a float' => ['price', 3, 3],
        'bool' => ['soldOut', true, true],
        'date' => ['startsAt', new DateTimeImmutable('2026-11-14T09:00:00+00:00'), '2026-11-14T09:00:00+00:00'],
        'string enum' => ['status', EventStatus::Published, 'published'],
        'int enum' => ['priority', Priority::High, 2],
    ]);

    it('refuses a value of the wrong type', function (string $property, mixed $value): void {
        expect(fn () => $this->caster->toJson($this->event[$property], $value))
            ->toThrow(InvalidMetaValueException::class);
    })->with([
        'int for a string' => ['summary', 5],
        'string for an int' => ['capacity', '7'],
        'string for a float' => ['price', '1.5'],
        'int for a bool' => ['soldOut', 1],
        'string for a date' => ['startsAt', '2026-11-14'],
        'raw value for an enum' => ['status', 'published'],
        'another enum' => ['status', Priority::High],
        'array for a string' => ['summary', []],
    ]);

    it('gives a list for an array, whatever the keys it received', function (): void {
        expect($this->caster->toJson($this->conference['roomIds'], [4 => 10, 8 => 20]))->toBe([10, 20]);
    });

    it('refuses to write something else than an object of the declared class', function (mixed $value): void {
        expect(fn () => $this->caster->toJson($this->conference['schedule'], $value))
            ->toThrow(InvalidMetaValueException::class);
    })->with(['a string' => 'room', 'an array' => [[]], 'another class' => new stdClass]);
});

it('keeps a data object as it is when sanitizing', function (): void {
    $schedule = new Schedule;
    $schedule->room = 'A1';

    $sanitized = $this->caster->sanitize($this->conference['schedule'], $schedule);

    expect($sanitized)->toBeArray()
        ->and($sanitized['room'])->toBe('A1')
        ->and($sanitized['duration_minutes'])->toBe(60);
});

it('reads a date as the exact native class the property asks for', function (string $class): void {
    $definition = new MetaDefinition('at', 'at', MetaValueType::DateTime, $class, true, null);

    $date = $this->caster->toPhp($definition, '2026-01-02T03:04:05+00:00');

    expect($date::class)->toBe($class === DateTimeInterface::class ? CarbonImmutable::class : $class);
})->with([DateTimeImmutable::class, DateTime::class, DateTimeInterface::class]);
