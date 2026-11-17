<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\Mood;

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

it('types an enum without backing values as a string', function (): void {
    $definition = new MetaDefinition('mood', 'mood', MetaValueType::Enum, Mood::class, true, null);

    expect($definition->wordPressType())->toBe('string');
});

it('says which keys a meta overlaps on, by object type and subtype', function (array $first, array $second, bool $overlaps): void {
    $schema = static fn (MetaObjectType $type, array $subtypes): MetaSchema => new MetaSchema('A', $type, $subtypes, []);

    expect($schema(...$first)->overlaps($schema(...$second)))->toBe($overlaps);
})->with(function (): array {
    $post = MetaObjectType::Post;
    $term = MetaObjectType::Term;

    return [
        'another object type, same subtypes' => [[$post, ['a']], [$term, ['a']], false],
        'another object type, no subtypes' => [[$post, []], [$term, []], false],
        'a shared subtype' => [[$post, ['a', 'b']], [$post, ['b']], true],
        'no shared subtype' => [[$post, ['a']], [$post, ['b']], false],
        'every object, then a subtype' => [[$post, []], [$post, ['b']], true],
        'a subtype, then every object' => [[$post, ['a']], [$post, []], true],
        'every object, both' => [[$post, []], [$post, []], true],
    ];
});
