<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaRegistry;
use Pollora\Meta\Tests\Fixtures\ArticleExtras;
use Pollora\Meta\Tests\Fixtures\BookGenre;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\MemberProfile;
use Pollora\Meta\Tests\Fixtures\ReviewMeta;

/**
 * Registers the Event schema right away and returns the register_meta() calls, by key.
 *
 * @return array<string, array{0: string, 1: array<string, mixed>}>
 */
function registeredEventMeta(string $class = Event::class): array
{
    $calls = [];
    Functions\when('did_action')->justReturn(1);
    Functions\when('add_post_type_support')->justReturn();
    Functions\when('register_meta')->alias(function (string $objectType, string $key, array $args) use (&$calls): bool {
        $calls[$key] = [$objectType, $args];

        return true;
    });

    (new WordPressMetaRegistry(Mockery::mock(Action::class), new MetaValueCaster))
        ->register((new MetaSchemaBuilder)->build($class));

    return $calls;
}

it('waits for init, after post types and taxonomies', function (): void {
    Functions\when('did_action')->justReturn(0);
    Functions\when('add_post_type_support')->justReturn();

    Functions\expect('register_meta')->never();
    $action = Mockery::mock(Action::class);
    $action->shouldReceive('add')->once()->with('init', Mockery::type(Closure::class), 20)->andReturnUsing(function (string $hook, Closure $callback) use ($action): Action {
        Functions\expect('register_meta')->times(10);
        $callback();

        return $action;
    });

    (new WordPressMetaRegistry($action, new MetaValueCaster))->register((new MetaSchemaBuilder)->build(Event::class));
});

it('registers each meta on its post type, single, with its type', function (): void {
    [$objectType, $args] = registeredEventMeta()['capacity'];

    expect($objectType)->toBe('post')
        ->and($args['object_subtype'])->toBe('event')
        ->and($args['type'])->toBe('integer')
        ->and($args['single'])->toBeTrue()
        ->and($args['default'])->toBe(0)
        ->and($args['show_in_rest'])->toBe(['schema' => ['type' => 'integer']]);
});

it('registers term meta on the taxonomy', function (): void {
    [$objectType, $args] = registeredEventMeta(BookGenre::class)['color'];

    expect($objectType)->toBe('term')
        ->and($args['object_subtype'])->toBe('book-genre');
});

it('keeps a meta out of REST unless asked', function (): void {
    expect(registeredEventMeta()['price'][1]['show_in_rest'])->toBeFalse();
});

it('passes label and description only when given', function (): void {
    $meta = registeredEventMeta();

    expect($meta['starts_at'][1])->toMatchArray(['label' => 'Start', 'description' => 'When the event starts'])
        ->and($meta['capacity'][1])->not->toHaveKeys(['label', 'description']);
});

it('leaves out the default of a nullable meta', function (): void {
    expect(registeredEventMeta()['starts_at'][1])->not->toHaveKey('default');
});

it('sanitizes strings with sanitize_text_field() and other types with the caster', function (): void {
    $meta = registeredEventMeta();

    expect($meta['subtitle'][1]['sanitize_callback'])->toBe('sanitize_text_field')
        ->and(($meta['capacity'][1]['sanitize_callback'])('42abc'))->toBe('')
        ->and(($meta['sold_out'][1]['sanitize_callback'])('yes'))->toBe('1');
});

it('uses the declared sanitize callback instead', function (): void {
    expect(registeredEventMeta()['summary'][1]['sanitize_callback'])->toBe('wp_kses_post');
});

it('checks the declared capability for writes', function (): void {
    $auth = registeredEventMeta()['_event_internal_ref'][1]['auth_callback'];
    Functions\expect('user_can')->once()->with(3, 'manage_options')->andReturn(false);

    expect($auth(true, '_event_internal_ref', 42, 3))->toBeFalse();
});

it('leaves write checks to WordPress without a capability', function (): void {
    expect(registeredEventMeta()['capacity'][1])->not->toHaveKey('auth_callback');
});

it('enables revisions when asked', function (): void {
    $meta = registeredEventMeta();

    expect($meta['subtitle'][1]['revisions_enabled'])->toBeTrue()
        ->and($meta['capacity'][1])->not->toHaveKey('revisions_enabled');
});

it('registers the meta on each post type of the list, and for every user without a subtype', function (): void {
    $calls = [];
    Functions\when('did_action')->justReturn(1);
    Functions\when('add_post_type_support')->justReturn();
    Functions\when('register_meta')->alias(function (string $objectType, string $key, array $args) use (&$calls): bool {
        $calls[] = [$objectType, $key, $args['object_subtype']];

        return true;
    });
    $registry = new WordPressMetaRegistry(Mockery::mock(Action::class), new MetaValueCaster);

    $registry->register((new MetaSchemaBuilder)->build(ArticleExtras::class));
    $registry->register((new MetaSchemaBuilder)->build(MemberProfile::class));
    $registry->register((new MetaSchemaBuilder)->build(ReviewMeta::class));

    expect($calls)->toBe([
        ['post', 'subtitle', 'post'],
        ['post', 'subtitle', 'page'],
        ['user', 'newsletter_opt_in', ''],
        ['user', 'job_title', ''],
        ['comment', 'rating', ''],
    ]);
});

it('adds custom-fields to a declared post type exposing a meta in REST, and to no other', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\when('register_meta')->justReturn(true);
    Functions\expect('add_post_type_support')->once()->with('event', 'custom-fields');
    $registry = new WordPressMetaRegistry(Mockery::mock(Action::class), new MetaValueCaster);

    $registry->register((new MetaSchemaBuilder)->build(Event::class));
    $registry->register((new MetaSchemaBuilder)->build(ArticleExtras::class));
    $registry->register((new MetaSchemaBuilder)->build(BookGenre::class));
});
