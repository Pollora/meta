<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\Priority;

beforeEach(function (): void {
    $this->caster = new MetaValueCaster;
    $this->definitions = (new MetaSchemaBuilder)->build(Event::class)->definitions;
    $this->definition = fn (string $property): MetaDefinition => $this->definitions[$property];
});

describe('toPhp()', function (): void {
    it('returns the default for an absent or empty value', function (mixed $raw): void {
        expect($this->caster->toPhp(($this->definition)('capacity'), $raw))->toBe(0)
            ->and($this->caster->toPhp(($this->definition)('status'), $raw))->toBe(EventStatus::Draft)
            ->and($this->caster->toPhp(($this->definition)('startsAt'), $raw))->toBeNull();
    })->with([null, '']);

    it('reads each type from its stored form', function (string $property, mixed $raw, mixed $expected): void {
        expect($this->caster->toPhp(($this->definition)($property), $raw))->toBe($expected);
    })->with([
        'string' => ['summary', 'Hello', 'Hello'],
        'string from a number' => ['summary', 42, '42'],
        'int' => ['capacity', '250', 250],
        'negative int' => ['capacity', '-3', -3],
        'float' => ['price', '12.75', 12.75],
        'string enum' => ['status', 'published', EventStatus::Published],
        'int enum' => ['priority', '2', Priority::High],
    ]);

    it('reads booleans tolerantly', function (mixed $raw, bool $expected): void {
        expect($this->caster->toPhp(($this->definition)('soldOut'), $raw))->toBe($expected);
    })->with([
        ['1', true], ['true', true], ['yes', true], ['on', true], [true, true],
        ['0', false], ['false', false], ['no', false], ['off', false], [false, false],
    ]);

    it('reads a date as the declared class, in UTC', function (): void {
        $startsAt = $this->caster->toPhp(($this->definition)('startsAt'), '2026-11-14T09:00:00+00:00');
        $endsAt = $this->caster->toPhp(($this->definition)('endsAt'), '2026-11-14 18:30:00');

        expect($startsAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($startsAt->toIso8601String())->toBe('2026-11-14T09:00:00+00:00')
            ->and($endsAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($endsAt->toIso8601String())->toBe('2026-11-14T18:30:00+00:00');
    });

    it('reads a date into a mutable class when the property asks for one', function (): void {
        $definition = new MetaDefinition('at', 'at', MetaValueType::DateTime, Carbon::class, true, null);

        expect($this->caster->toPhp($definition, '2026-01-02T03:04:05+00:00'))->toBeInstanceOf(Carbon::class);
    });

    it('refuses a stored value it cannot read as the declared type', function (string $property, mixed $raw): void {
        expect(fn () => $this->caster->toPhp(($this->definition)($property), $raw))
            ->toThrow(InvalidMetaValueException::class, 'cannot be read as');
    })->with([
        'array for a string' => ['summary', ['a']],
        'text for an int' => ['capacity', 'many'],
        'decimal for an int' => ['capacity', '1.5'],
        'array for an int' => ['capacity', ['1']],
        'text for a float' => ['price', 'cheap'],
        'text for a bool' => ['soldOut', 'maybe'],
        'array for a bool' => ['soldOut', ['1']],
        'garbage for a date' => ['startsAt', 'not a date'],
        'number for a date' => ['startsAt', 12],
        'unknown enum case' => ['status', 'archived'],
        'array for an enum' => ['status', ['draft']],
        'text for an int enum' => ['priority', 'high'],
        'unknown int enum case' => ['priority', '9'],
    ]);
});

describe('toStorage()', function (): void {
    it('stores each type as a string', function (string $property, mixed $value, string $expected): void {
        expect($this->caster->toStorage(($this->definition)($property), $value))->toBe($expected);
    })->with([
        'string' => ['summary', 'Hello', 'Hello'],
        'stringable' => ['summary', new class implements Stringable
        {
            public function __toString(): string
            {
                return 'Stringable';
            }
        }, 'Stringable'],
        'int' => ['capacity', 250, '250'],
        'float' => ['price', 12.5, '12.5'],
        'int for a float' => ['price', 3, '3'],
        'true' => ['soldOut', true, '1'],
        'false' => ['soldOut', false, '0'],
        'string enum' => ['status', EventStatus::Published, 'published'],
        'int enum' => ['priority', Priority::High, '2'],
    ]);

    it('stores dates in ISO 8601, in UTC', function (): void {
        $date = new DateTimeImmutable('2026-11-14 10:00:00', new DateTimeZone('Europe/Paris'));

        expect($this->caster->toStorage(($this->definition)('startsAt'), $date))->toBe('2026-11-14T09:00:00+00:00');
    });

    it('turns null into a deletion for a nullable meta', function (): void {
        expect($this->caster->toStorage(($this->definition)('startsAt'), null))->toBeNull();
    });

    it('refuses a value of the wrong type', function (string $property, mixed $value, string $message): void {
        expect(fn () => $this->caster->toStorage(($this->definition)($property), $value))
            ->toThrow(InvalidMetaValueException::class, $message);
    })->with([
        'null for a non-nullable meta' => ['capacity', null, 'The meta "capacity" expects integer, null given.'],
        'string for an int' => ['capacity', '250', 'expects integer, string given'],
        'int for a string' => ['summary', 12, 'expects string, int given'],
        'int for a bool' => ['soldOut', 1, 'expects boolean, int given'],
        'string for a date' => ['startsAt', '2026-11-14', 'expects ?Carbon\CarbonImmutable, string given'],
        'another enum' => ['status', Priority::High, 'expects Pollora\Meta\Tests\Fixtures\EventStatus'],
        'string for a float' => ['price', '1.5', 'expects number, string given'],
    ]);
});

describe('sanitize()', function (): void {
    it('normalizes values written through WordPress to the stored form', function (string $property, mixed $value, string $expected): void {
        expect($this->caster->sanitize(($this->definition)($property), $value))->toBe($expected);
    })->with([
        'numeric string for an int' => ['capacity', '42', '42'],
        'yes for a bool' => ['soldOut', 'yes', '1'],
        'bool for a bool' => ['soldOut', false, '0'],
        'enum value' => ['status', 'published', 'published'],
        'enum case' => ['status', EventStatus::Published, 'published'],
        'date string' => ['startsAt', '2026-11-14 10:00:00+01:00', '2026-11-14T09:00:00+00:00'],
        'date object' => ['startsAt', new DateTimeImmutable('2026-11-14T09:00:00+00:00'), '2026-11-14T09:00:00+00:00'],
        'empty for a nullable meta' => ['startsAt', '', ''],
        'empty for a meta with a default' => ['capacity', '', ''],
        'null' => ['capacity', null, ''],
    ]);

    it('gives the same result when applied twice', function (string $property, mixed $value): void {
        $definition = ($this->definition)($property);

        expect($this->caster->sanitize($definition, $this->caster->sanitize($definition, $value)))
            ->toBe($this->caster->sanitize($definition, $value));
    })->with([
        'unreadable int' => ['capacity', '42abc'],
        'int' => ['capacity', '42'],
        'bool' => ['soldOut', 'yes'],
        'date' => ['startsAt', '2026-11-14 10:00:00+01:00'],
    ]);

    it('empties a value it cannot read, which then reads as the default', function (string $property, mixed $value): void {
        expect($this->caster->sanitize(($this->definition)($property), $value))->toBe('');
    })->with([
        'text for an int' => ['capacity', 'many'],
        'unknown enum case' => ['status', 'archived'],
        'wrong enum' => ['status', Priority::Low],
        'date for an int' => ['capacity', new DateTimeImmutable],
    ]);
});

it('publishes the default in its REST form', function (): void {
    expect($this->caster->toRestDefault(($this->definition)('capacity')))->toBe(0)
        ->and($this->caster->toRestDefault(($this->definition)('soldOut')))->toBeFalse()
        ->and($this->caster->toRestDefault(($this->definition)('status')))->toBe('draft')
        ->and($this->caster->toRestDefault(($this->definition)('startsAt')))->toBeNull();
});
