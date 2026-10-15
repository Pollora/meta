<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Carbon\CarbonImmutable;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Domain\Models\MetaRecord;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaRegistry;
use Pollora\Meta\Tests\Fixtures\Conference;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\InvalidNestedArray;
use Pollora\Meta\Tests\Fixtures\InvalidObjectProperty;
use Pollora\Meta\Tests\Fixtures\InvalidRowsOfObjects;
use Pollora\Meta\Tests\Fixtures\InvalidSingleFalse;
use Pollora\Meta\Tests\Fixtures\InvalidUnknownItem;
use Pollora\Meta\Tests\Fixtures\Schedule;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';
require_once dirname(__DIR__).'/Fixtures/validator.php';

beforeEach(function (): void {
    $this->schema = (new MetaSchemaBuilder)->build(Conference::class);
    $this->caster = new MetaValueCaster;
});

describe('declaring', function (): void {
    it('reads the item type from the docblock or items:', function (): void {
        $definitions = $this->schema->definitions;

        expect($definitions['speakers']->valueType)->toBe(MetaValueType::ArrayOf)
            ->and($definitions['speakers']->single)->toBeFalse()
            ->and($definitions['speakers']->item()->valueType)->toBe(MetaValueType::String)
            ->and($definitions['roomIds']->item()->valueType)->toBe(MetaValueType::Integer)
            ->and($definitions['statuses']->item()->valueClass)->toBe(EventStatus::class)
            ->and($definitions['sessions']->item()->valueType)->toBe(MetaValueType::DataObject)
            ->and($definitions['schedule']->valueType)->toBe(MetaValueType::DataObject)
            ->and(array_keys($definitions['schedule']->properties))->toBe(['startsAt', 'durationMinutes', 'status', 'room', 'public']);
    });

    it('publishes REST schemas WordPress accepts', function (): void {
        $definitions = $this->schema->definitions;

        expect($definitions['speakers']->wordPressType())->toBe('string')
            ->and($definitions['speakers']->restSchema())->toBe(['type' => 'string'])
            ->and($definitions['roomIds']->wordPressType())->toBe('array')
            ->and($definitions['roomIds']->restSchema())->toBe(['type' => 'array', 'items' => ['type' => 'integer']])
            ->and($definitions['schedule']->wordPressType())->toBe('object')
            ->and($definitions['schedule']->restSchema())->toBe([
                'type' => 'object',
                'properties' => [
                    'starts_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'duration_minutes' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                    'room' => ['type' => ['string', 'null']],
                    'public' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ]);
    });

    it('refuses what it cannot store', function (string $class, string $message): void {
        expect(fn (): MetaSchema => (new MetaSchemaBuilder)->build($class))->toThrow(InvalidMetaDefinitionException::class, $message);
    })->with([
        'single: false on a scalar' => [InvalidSingleFalse::class, 'single: false stores one row per item, so it needs an array property'],
        'an array of arrays' => [InvalidNestedArray::class, 'an item cannot be an array'],
        'an unresolvable docblock class' => [InvalidUnknownItem::class, 'the item type Speaker is unknown'],
        'rows of objects' => [InvalidRowsOfObjects::class, 'objects need single: true'],
        'an object with an array' => [InvalidObjectProperty::class, 'ScheduleWithList::$tags: an object property can be'],
    ]);
});

describe('converting', function (): void {
    it('stores one row per item, as strings', function (): void {
        expect($this->caster->toStorage($this->schema->definitions['speakers'], ['Ada', 'Grace']))->toBe(['Ada', 'Grace'])
            ->and($this->caster->toStorage($this->schema->definitions['statuses'], [EventStatus::Published]))->toBe(['published'])
            ->and($this->caster->toPhp($this->schema->definitions['statuses'], ['draft', 'published']))->toBe([EventStatus::Draft, EventStatus::Published]);
    });

    it('stores a single array with JSON values', function (): void {
        $roomIds = $this->schema->definitions['roomIds'];

        expect($this->caster->toStorage($roomIds, [3, 7]))->toBe([3, 7])
            ->and($this->caster->toPhp($roomIds, ['3', 7]))->toBe([3, 7])
            ->and(fn () => $this->caster->toStorage($roomIds, ['three']))->toThrow(InvalidMetaValueException::class)
            ->and(fn () => $this->caster->toPhp($roomIds, 'not an array'))->toThrow(InvalidMetaValueException::class);
    });

    it('stores a data object as an array, never as a PHP object, and reads it back', function (): void {
        $definition = $this->schema->definitions['schedule'];
        $schedule = new Schedule(public: false);
        $schedule->startsAt = CarbonImmutable::parse('2026-11-14 09:00', 'Europe/Paris');
        $schedule->status = EventStatus::Published;

        $stored = $this->caster->toStorage($definition, $schedule);
        $read = $this->caster->toPhp($definition, $stored);

        expect($stored)->toBe([
            'starts_at' => '2026-11-14T08:00:00+00:00',
            'duration_minutes' => 60,
            'status' => 'published',
            'room' => null,
            'public' => false,
        ])
            ->and($read)->toBeInstanceOf(Schedule::class)
            ->and($read->startsAt?->toIso8601String())->toBe('2026-11-14T08:00:00+00:00')
            ->and($read->status)->toBe(EventStatus::Published)
            ->and($read->public)->toBeFalse();
    });

    it('reads an absent object as an instance with its defaults, and a missing property as its default', function (): void {
        $definition = $this->schema->definitions['schedule'];

        expect($this->caster->toPhp($definition, null))->toEqual(new Schedule)
            ->and($this->caster->toPhp($definition, ['duration_minutes' => 90])->durationMinutes)->toBe(90)
            ->and($this->caster->toPhp($definition, ['duration_minutes' => 90])->public)->toBeTrue()
            ->and($this->caster->toPhp($this->schema->definitions['backup'], null))->toBeNull()
            ->and(fn () => $this->caster->toPhp($definition, ['duration_minutes' => 'long']))->toThrow(InvalidMetaValueException::class);
    });

    it('stores a list of objects', function (): void {
        $sessions = $this->schema->definitions['sessions'];

        $stored = $this->caster->toStorage($sessions, [new Schedule, new Schedule(public: false)]);

        expect($stored)->toHaveCount(2)
            ->and($stored[1]['public'])->toBeFalse()
            ->and($this->caster->toPhp($sessions, $stored)[1])->toBeInstanceOf(Schedule::class);
    });

    it('sanitizes REST input into the stored form', function (): void {
        expect($this->caster->sanitize($this->schema->definitions['roomIds'], ['3', 4]))->toBe([3, 4])
            ->and($this->caster->sanitize($this->schema->definitions['schedule'], ['duration_minutes' => '45']))->toMatchArray(['duration_minutes' => 45, 'public' => true])
            ->and($this->caster->sanitize($this->schema->definitions['roomIds'], 'junk'))->toBe('');
    });
});

describe('reading and writing', function (): void {
    it('reads rows and replaces them all on save', function (): void {
        $store = Mockery::mock(MetaStoreInterface::class);
        $store->shouldReceive('getAll')->once()->with(MetaObjectType::Post, 9, 'speakers')->andReturn(['Ada', 'Grace']);
        $store->shouldReceive('replaceAll')->once()->with(MetaObjectType::Post, 9, 'speakers', ['Linus']);
        $store->shouldReceive('update')->once()->with(MetaObjectType::Post, 9, 'schedule', Mockery::on(fn (array $value): bool => $value['duration_minutes'] === 30));
        $record = new MetaRecord($this->schema, 9, $store, $this->caster, fn (): mixed => null, metaValidator());

        expect($record->speakers)->toBe(['Ada', 'Grace']);

        $schedule = new Schedule;
        $schedule->durationMinutes = 30;
        $record->fill(['speakers' => ['Linus'], 'schedule' => $schedule])->save();
    });

    it('checks the rules on the whole array', function (): void {
        $record = new MetaRecord($this->schema, 9, Mockery::mock(MetaStoreInterface::class), $this->caster, fn (): mixed => null, metaValidator());

        $record->set('speakers', ['A', 'B', 'C', 'D']);
    })->throws(MetaValidationException::class);
});

describe('registering', function (): void {
    it('registers a non-single meta and sanitizes each row, and strings inside arrays and objects', function (): void {
        $calls = [];
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_post_type_support')->justReturn();
        Functions\when('sanitize_text_field')->alias(fn (string $value): string => strip_tags($value));
        Functions\when('register_meta')->alias(function (string $objectType, string $key, array $args) use (&$calls): bool {
            $calls[$key] = $args;

            return true;
        });

        (new WordPressMetaRegistry(Mockery::mock(Action::class), new MetaValueCaster))->register($this->schema);

        expect($calls['speakers']['single'])->toBeFalse()
            ->and($calls['speakers']['sanitize_callback'])->toBe('sanitize_text_field')
            ->and($calls['speakers'])->not->toHaveKey('default')
            ->and($calls['room_ids']['default'])->toBe([])
            ->and($calls['schedule']['default'])->toMatchArray(['duration_minutes' => 60, 'public' => true])
            ->and(($calls['schedule']['sanitize_callback'])(['room' => '<b>Hall</b> A']))->toMatchArray(['room' => 'Hall A']);
    });
});
