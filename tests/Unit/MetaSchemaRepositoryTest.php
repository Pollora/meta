<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Tests\Fixtures\ArticleExtras;
use Pollora\Meta\Tests\Fixtures\BillingProfile;
use Pollora\Meta\Tests\Fixtures\BookGenre;
use Pollora\Meta\Tests\Fixtures\CategoryExtras;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventExtras;
use Pollora\Meta\Tests\Fixtures\MemberProfile;
use Pollora\Meta\Tests\Fixtures\PageExtras;

it('keeps the schemas by declaring class', function (): void {
    $repository = new MetaSchemaRepository;
    $event = (new MetaSchemaBuilder)->build(Event::class);
    $genre = (new MetaSchemaBuilder)->build(BookGenre::class);

    $repository->add($event);
    $repository->add($genre);
    $repository->add($event);

    expect($repository->forClass(Event::class))->toBe($event)
        ->and($repository->forClass(EventExtras::class))->toBeNull()
        ->and($repository->all())->toBe([$event, $genre]);
});

it('refuses a key another class already declares on the same post type', function (): void {
    $repository = new MetaSchemaRepository;
    $repository->add((new MetaSchemaBuilder)->build(Event::class));

    $repository->add((new MetaSchemaBuilder)->build(EventExtras::class));
})->throws(
    InvalidMetaDefinitionException::class,
    'The meta key "capacity" of post "event" is declared twice, by Pollora\Meta\Tests\Fixtures\Event and by Pollora\Meta\Tests\Fixtures\EventExtras.'
);

it('refuses a key declared twice on objects the schemas share', function (string $first, string $second, string $message): void {
    $repository = new MetaSchemaRepository;
    $repository->add((new MetaSchemaBuilder)->build($first));

    expect(fn () => $repository->add((new MetaSchemaBuilder)->build($second)))->toThrow(InvalidMetaDefinitionException::class, $message);
})->with([
    'a post type in a list' => [ArticleExtras::class, PageExtras::class, 'The meta key "subtitle" of post "page" is declared twice'],
    'users' => [MemberProfile::class, BillingProfile::class, 'The meta key "job_title" of user is declared twice'],
]);

it('accepts the same key on objects the schemas do not share', function (): void {
    $repository = new MetaSchemaRepository;

    $repository->add((new MetaSchemaBuilder)->build(PageExtras::class));
    $repository->add((new MetaSchemaBuilder)->build(Event::class));
    $repository->add((new MetaSchemaBuilder)->build(CategoryExtras::class));
    $repository->add((new MetaSchemaBuilder)->build(BookGenre::class));

    expect($repository->all())->toHaveCount(4);
});

it('finds the schemas an object can carry, and whether a name is declared', function (): void {
    $repository = new MetaSchemaRepository;
    $repository->add($event = (new MetaSchemaBuilder)->build(Event::class));
    $repository->add($article = (new MetaSchemaBuilder)->build(ArticleExtras::class));
    $repository->add($member = (new MetaSchemaBuilder)->build(MemberProfile::class));

    expect($repository->forObject(MetaObjectType::Post, 'event'))->toBe([$event])
        ->and($repository->forObject(MetaObjectType::Post, 'page'))->toBe([$article])
        ->and($repository->forObject(MetaObjectType::Post, null))->toBe([])
        ->and($repository->forObject(MetaObjectType::User, null))->toBe([$member])
        ->and($repository->declares(MetaObjectType::Post, 'soldOut'))->toBeTrue()
        ->and($repository->declares(MetaObjectType::Post, 'sold_out'))->toBeTrue()
        ->and($repository->declares(MetaObjectType::User, 'sold_out'))->toBeFalse()
        ->and($repository->declares(MetaObjectType::Post, 'post_title'))->toBeFalse();
});
