<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaRecord;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\RatedEvent;

require_once dirname(__DIR__).'/Fixtures/validator.php';

/**
 * An in-memory meta store that records its writes.
 */
function memoryMetaStore(array $stored = []): MetaStoreInterface
{
    return new class($stored) implements MetaStoreInterface
    {
        public array $writes = [];

        public int $reads = 0;

        public function __construct(public array $stored) {}

        public function get(MetaObjectType $objectType, int $objectId, string $key): mixed
        {
            $this->reads++;

            return $this->stored[$key] ?? null;
        }

        public function update(MetaObjectType $objectType, int $objectId, string $key, string|array $value): void
        {
            $this->writes[] = ['update', $objectType, $objectId, $key, $value];
            $this->stored[$key] = $value;
        }

        public function delete(MetaObjectType $objectType, int $objectId, string $key): void
        {
            $this->writes[] = ['delete', $objectType, $objectId, $key];
            unset($this->stored[$key]);
        }

        public function getAll(MetaObjectType $objectType, int $objectId, string $key): array
        {
            $this->reads++;

            return (array) ($this->stored[$key] ?? []);
        }

        public function replaceAll(MetaObjectType $objectType, int $objectId, string $key, array $values): void
        {
            $this->writes[] = ['replaceAll', $objectType, $objectId, $key, $values];
            $this->stored[$key] = $values;
        }
    };
}

function eventRecord(MetaStoreInterface $store, ?Closure $onUnreadable = null): MetaRecord
{
    return new MetaRecord(
        (new MetaSchemaBuilder)->build(Event::class),
        42,
        $store,
        new MetaValueCaster,
        $onUnreadable ?? fn (InvalidMetaValueException $exception): never => throw $exception,
    );
}

it('reads each meta with its declared type, by property name or key', function (): void {
    $record = eventRecord(memoryMetaStore(['capacity' => '250', 'sold_out' => '1', 'starts_at' => '2026-11-14T09:00:00+00:00']));

    expect($record->capacity)->toBe(250)
        ->and($record->soldOut)->toBeTrue()
        ->and($record->sold_out)->toBeTrue()
        ->and($record->startsAt->toIso8601String())->toBe('2026-11-14T09:00:00+00:00')
        ->and($record->objectId())->toBe(42);
});

it('reads an absent meta as the property default', function (): void {
    $record = eventRecord(memoryMetaStore());

    expect($record->capacity)->toBe(0)
        ->and($record->status)->toBe(EventStatus::Draft)
        ->and($record->startsAt)->toBeNull();
});

it('reads each meta from the store once', function (): void {
    $store = memoryMetaStore(['capacity' => '250']);
    $record = eventRecord($store);

    $record->capacity;
    $record->capacity;

    expect($store->reads)->toBe(1);
});

it('writes pending values on save, in their stored form', function (): void {
    $store = memoryMetaStore();
    $record = eventRecord($store);

    $record->capacity = 250;
    $record->fill(['status' => EventStatus::Published, 'sold_out' => true]);

    expect($store->writes)->toBe([])
        ->and($record->capacity)->toBe(250);

    $record->save();

    expect($store->writes)->toBe([
        ['update', MetaObjectType::Post, 42, 'capacity', '250'],
        ['update', MetaObjectType::Post, 42, 'status', 'published'],
        ['update', MetaObjectType::Post, 42, 'sold_out', '1'],
    ]);
});

it('deletes a nullable meta set to null', function (): void {
    $store = memoryMetaStore(['starts_at' => '2026-11-14T09:00:00+00:00']);

    eventRecord($store)->set('startsAt', null)->save();

    expect($store->writes)->toBe([['delete', MetaObjectType::Post, 42, 'starts_at']]);
});

it('does not write twice', function (): void {
    $store = memoryMetaStore();
    $record = eventRecord($store)->set('capacity', 3)->save()->save();

    expect($store->writes)->toHaveCount(1)
        ->and($record->capacity)->toBe(3);
});

it('refuses a value of the wrong type before anything is written', function (): void {
    $store = memoryMetaStore();

    expect(fn (): MetaRecord => eventRecord($store)->fill(['capacity' => 'many']))->toThrow(InvalidMetaValueException::class)
        ->and($store->writes)->toBe([]);
});

it('refuses a meta the class does not declare', function (): void {
    eventRecord(memoryMetaStore())->unknown;
})->throws(InvalidArgumentException::class, 'Pollora\Meta\Tests\Fixtures\Event declares no meta named "unknown".');

it('hands an unreadable stored value to the unreadable handler', function (): void {
    $record = eventRecord(
        memoryMetaStore(['capacity' => 'many']),
        fn (InvalidMetaValueException $exception, MetaDefinition $definition): int => -1,
    );

    expect($record->capacity)->toBe(-1);
});

it('lists every meta by property name', function (): void {
    $values = eventRecord(memoryMetaStore(['capacity' => '7']))->toArray();

    expect($values)->toHaveKeys(['startsAt', 'capacity', 'status'])
        ->and($values['capacity'])->toBe(7);
});

it('tells which meta are set', function (): void {
    $record = eventRecord(memoryMetaStore(['capacity' => '7']));

    expect(isset($record->capacity))->toBeTrue()
        ->and(isset($record->startsAt))->toBeFalse()
        ->and(isset($record->unknown))->toBeFalse();
});

it('checks the rules of a meta on write, and keeps nothing pending when one fails', function (): void {
    $store = memoryMetaStore();
    $record = new MetaRecord((new MetaSchemaBuilder)->build(RatedEvent::class), 42, $store, new MetaValueCaster, fn (): mixed => null, metaValidator());

    expect(fn (): MetaRecord => $record->set('capacity', 6000))->toThrow(MetaValidationException::class);

    $record->set('capacity', 300)->set('contact', null)->save();

    expect($store->stored)->toBe(['capacity' => '300']);
});
