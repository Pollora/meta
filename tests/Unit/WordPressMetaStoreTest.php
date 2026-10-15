<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaStore;

beforeEach(function (): void {
    Functions\when('wp_slash')->alias(fn (string $value): string => addslashes($value));
});

it('reads the raw stored value, without the registered default', function (): void {
    Functions\expect('get_metadata_raw')->once()->with('post', 42, 'capacity', true)->andReturn('250');

    expect((new WordPressMetaStore)->get(MetaObjectType::Post, 42, 'capacity'))->toBe('250');
});

it('writes through update_metadata(), slashed', function (): void {
    Functions\expect('update_metadata')->once()->with('term', 7, 'path', 'C:\\\\dir');

    (new WordPressMetaStore)->update(MetaObjectType::Term, 7, 'path', 'C:\\dir');
});

it('deletes through delete_metadata()', function (): void {
    Functions\expect('delete_metadata')->once()->with('post', 42, 'starts_at');

    (new WordPressMetaStore)->delete(MetaObjectType::Post, 42, 'starts_at');
});
