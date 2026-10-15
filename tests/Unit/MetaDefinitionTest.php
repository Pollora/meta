<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Tests\Fixtures\Event;

beforeEach(function (): void {
    $this->definitions = (new MetaSchemaBuilder)->build(Event::class)->definitions;
});

it('maps each value type to its register_meta() type', function (string $property, string $type): void {
    expect($this->definitions[$property]->wordPressType())->toBe($type);
})->with([
    ['summary', 'string'],
    ['startsAt', 'string'],
    ['capacity', 'integer'],
    ['price', 'number'],
    ['soldOut', 'boolean'],
    ['status', 'string'],
    ['priority', 'integer'],
]);

it('publishes a REST schema with the date format and the enum values', function (): void {
    expect($this->definitions['capacity']->restSchema())->toBe(['type' => 'integer'])
        ->and($this->definitions['startsAt']->restSchema())->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($this->definitions['status']->restSchema())->toBe(['type' => 'string', 'enum' => ['draft', 'published']])
        ->and($this->definitions['priority']->restSchema())->toBe(['type' => 'integer', 'enum' => [1, 2]]);
});

it('knows a key starting with an underscore is protected', function (): void {
    expect($this->definitions['internalRef']->isProtected())->toBeTrue()
        ->and($this->definitions['capacity']->isProtected())->toBeFalse();
});

it('finds a definition by property name or by key', function (): void {
    $schema = (new MetaSchemaBuilder)->build(Event::class);

    expect($schema->find('startsAt')?->key)->toBe('starts_at')
        ->and($schema->find('starts_at')?->property)->toBe('startsAt')
        ->and($schema->find('unknown'))->toBeNull()
        ->and($schema->isEmpty())->toBeFalse()
        ->and((new MetaSchema(Event::class, $schema->objectType, ['event'], []))->isEmpty())->toBeTrue();
});
