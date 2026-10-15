<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaAuditor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Tests\Fixtures\ArrayMetaInventory;
use Pollora\Meta\Tests\Fixtures\ArticleExtras;
use Pollora\Meta\Tests\Fixtures\Event;

beforeEach(function (): void {
    $this->schemas = new MetaSchemaRepository;
    $this->schemas->add((new MetaSchemaBuilder)->build(Event::class));
    $this->schemas->add((new MetaSchemaBuilder)->build(ArticleExtras::class));

    $this->auditor = fn (array $rows): MetaAuditor => new MetaAuditor($this->schemas, new ArrayMetaInventory($rows), new MetaValueCaster);
});

it('names the stored values a #[Meta] cannot read as its type', function (): void {
    $findings = ($this->auditor)(['post' => ['event' => [
        10 => ['capacity' => ['250'], 'status' => ['draft']],
        11 => ['capacity' => ['a lot'], 'status' => ['postponed']],
        12 => ['capacity' => ['many']],
    ]]])->unreadable(100);

    expect($findings)->toHaveCount(2)
        ->and($findings[0])->toMatchArray(['class' => Event::class, 'property' => 'capacity', 'key' => 'capacity', 'owner' => 'post "event"', 'checked' => 3, 'unreadable' => 2, 'objects' => [11, 12]])
        ->and($findings[0]['example'])->toContain("'a lot'")
        ->and($findings[1])->toMatchArray(['property' => 'status', 'unreadable' => 1, 'objects' => [11]]);
});

it('finds nothing when every stored value reads as its type', function (): void {
    expect(($this->auditor)(['post' => ['event' => [10 => ['capacity' => ['250'], 'sold_out' => ['1']]]]])->unreadable(100))->toBe([]);
});

it('lists the keys stored on a declared post type that no #[Meta] declares', function (): void {
    $findings = ($this->auditor)(['post' => [
        'event' => [10 => ['capacity' => ['250'], 'old_capacity' => ['200'], '_edit_lock' => ['1']], 11 => ['old_capacity' => ['10']]],
        'post' => [20 => ['legacy_field' => ['x']]],
    ]])->undeclared(100);

    // "post" is targeted by #[PostMeta], not declared by the project: its other keys belong to others
    expect($findings)->toBe([['owner' => 'post "event"', 'key' => 'old_capacity', 'objects' => 2]]);
});
