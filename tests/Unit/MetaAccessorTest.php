<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Tests\Fixtures\BookGenre;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\NotADeclaration;
use Psr\Log\LoggerInterface;

function storeReturning(mixed $value): MetaStoreInterface
{
    $store = Mockery::mock(MetaStoreInterface::class);
    $store->shouldReceive('get')->andReturn($value);

    return $store;
}

function metaAccessor(MetaStoreInterface $store, bool $debug = false, ?LoggerInterface $logger = null, ?MetaSchemaRepository $schemas = null): MetaAccessor
{
    return new MetaAccessor($schemas ?? new MetaSchemaRepository, new MetaSchemaBuilder, $store, new MetaValueCaster, $debug, $logger);
}

it('gives the typed meta of an object', function (): void {
    $record = metaAccessor(storeReturning('250'))->of(Event::class, 42);

    expect($record->capacity)->toBe(250)
        ->and($record->objectId())->toBe(42);
});

it('uses the discovered schema when there is one', function (): void {
    $store = Mockery::mock(MetaStoreInterface::class);
    $store->shouldReceive('get')->with(MetaObjectType::Term, 7, 'color')->once()->andReturn('red');
    $schemas = new MetaSchemaRepository;
    $schemas->add((new MetaSchemaBuilder)->build(BookGenre::class));

    expect(metaAccessor($store, schemas: $schemas)->of(BookGenre::class, 7)->color)->toBe('red');
});

it('refuses a class that declares no meta', function (): void {
    metaAccessor(storeReturning(null))->of(NotADeclaration::class, 1);
})->throws(InvalidMetaDefinitionException::class);

it('throws on an unreadable stored value in debug mode', function (): void {
    metaAccessor(storeReturning('many'), debug: true)->of(Event::class, 42)->capacity;
})->throws(InvalidMetaValueException::class, 'The stored value of the meta "capacity" cannot be read as integer');

it('reads an unreadable stored value as the default in production, and logs it', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/meta "capacity" cannot be read as integer/'));

    expect(metaAccessor(storeReturning('many'), logger: $logger)->of(Event::class, 42)->capacity)->toBe(0);
});
