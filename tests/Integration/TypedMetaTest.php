<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaAuditor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaInventory;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaRegistry;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaStore;
use Pollora\Meta\Infrastructure\Adapters\WordPressRestMetaValidation;
use Pollora\Meta\Infrastructure\Services\MetaDiscovery;
use Pollora\Meta\Tests\Fixtures\Conference;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\MemberProfile;
use Pollora\Meta\Tests\Fixtures\RatedEvent;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;

require_once ABSPATH.'wp-admin/includes/user.php';
require_once dirname(__DIR__).'/Fixtures/validator.php';

/*
 * The typed meta against a real WordPress and MySQL: what register_meta() accepts and refuses, what the REST API does
 * with the schemas and with the sanitizers, the capability checks, the meta tables and the SQL of the audit. The unit
 * suite doubles every one of these functions.
 */

beforeEach(function (): void {
    $this->notices = [];
    add_filter('doing_it_wrong_trigger_error', '__return_false');
    add_action('doing_it_wrong_run', function (string $function, string $message): void {
        $this->notices[] = $function.': '.$message;
    }, 10, 2);

    foreach (['event', 'conference', 'rated_event'] as $postType) {
        // `revisions`: register_meta() refuses a meta that asks for revisions on a post type that does not support them.
        register_post_type($postType, ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'revisions']]);
    }

    $this->schemas = new MetaSchemaRepository;
    $this->builder = new MetaSchemaBuilder;
    $this->caster = new MetaValueCaster;
    $this->registry = new WordPressMetaRegistry(new Action, $this->caster);
    $this->store = new WordPressMetaStore;
    $this->meta = new MetaAccessor($this->schemas, $this->builder, $this->store, $this->caster);
    $this->posts = [];
    $this->users = [];

    // The REST server builds its routes, and the meta of each controller, once: rebuild them for each test.
    $GLOBALS['wp_rest_server'] = null;
});

afterEach(function (): void {
    // Also the posts a refused REST write left behind: WordPress inserts the post, then validates its meta.
    $created = get_posts(['post_type' => ['event', 'conference', 'rated_event'], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);

    foreach (array_unique([...$this->posts, ...$created]) as $id) {
        wp_delete_post($id, true);
    }

    foreach ($this->users as $id) {
        wp_delete_user($id);
    }

    foreach (['post' => ['event', 'conference', 'rated_event'], 'user' => ['']] as $type => $subtypes) {
        foreach ($subtypes as $subtype) {
            foreach (array_keys(get_registered_meta_keys($type, $subtype)) as $key) {
                unregister_meta_key($type, $key, $subtype);
            }
        }
    }

    foreach (['event', 'conference', 'rated_event'] as $postType) {
        unregister_post_type($postType);
    }

    remove_all_filters('rest_request_before_callbacks');
    remove_all_filters('doing_it_wrong_trigger_error');
    remove_all_actions('doing_it_wrong_run');
    wp_set_current_user(0);
    $GLOBALS['wp_rest_server'] = null;
});

/**
 * Register a declaring class with WordPress, the way the project's provider does.
 */
function registerTypedMeta(object $test, string $class): void
{
    $schema = $test->schemas->forClass($class) ?? $test->builder->build($class);
    $test->schemas->add($schema);
    $test->registry->register($schema);
}

function typedMetaPost(object $test, string $postType, string $title = 'Typed meta'): int
{
    $id = wp_insert_post(['post_type' => $postType, 'post_title' => $title, 'post_status' => 'publish']);
    $test->posts[] = $id;

    return $id;
}

function typedMetaUser(object $test, string $role): int
{
    $id = wp_insert_user(['user_login' => 'meta_'.$role.'_'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => $role]);
    $test->users[] = $id;

    return $id;
}

/**
 * Send a request through the real REST server.
 *
 * @param  array<string, mixed>  $params
 */
function typedMetaRest(string $method, string $route, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request($method, $route);
    $request->set_header('content-type', 'application/json');
    $request->set_body(wp_json_encode($params));

    return rest_do_request($request);
}

it('registers every kind of typed meta with WordPress, with no notice about its schema', function (): void {
    // Through discovery, as the framework does: the declaration, the schema and the registration in one path.
    $discovery = new MetaDiscovery($this->builder, $this->schemas, $this->registry);

    foreach ([Event::class, Conference::class, MemberProfile::class] as $class) {
        $discovery->discover(new DiscoveryLocation('Pollora\\Meta\\Tests\\', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass($class)));
    }

    $discovery->apply();

    $event = get_registered_meta_keys('post', 'event');
    $conference = get_registered_meta_keys('post', 'conference');

    expect($this->schemas->failures())->toBe([])
        ->and($this->notices)->toBe([])
        ->and($event['capacity']['type'])->toBe('integer')
        ->and($event['capacity']['single'])->toBeTrue()
        ->and($event['capacity']['default'])->toBe(0)
        ->and($event['capacity']['show_in_rest'])->toBe(['schema' => ['type' => 'integer']])
        ->and($event['price']['show_in_rest'])->toBeFalse()
        ->and($event['status']['show_in_rest']['schema']['enum'])->toBe(['draft', 'published'])
        ->and($event['subtitle']['revisions_enabled'])->toBeTrue()
        ->and($conference['room_ids']['type'])->toBe('array')
        ->and($conference['room_ids']['show_in_rest']['schema']['items'])->toBe(['type' => 'integer'])
        ->and($conference['speakers']['single'])->toBeFalse()
        ->and($conference['schedule']['type'])->toBe('object')
        ->and(registered_meta_key_exists('user', 'newsletter_opt_in'))->toBeTrue();
});

it('lets a post type that exposes typed meta in REST carry them, and only that one', function (): void {
    expect(post_type_supports('event', 'custom-fields'))->toBeFalse();

    registerTypedMeta($this, Event::class);

    expect(post_type_supports('event', 'custom-fields'))->toBeTrue()
        ->and(post_type_supports('conference', 'custom-fields'))->toBeFalse();
});

it('writes and reads typed meta through the REST API, with the types of the declaration', function (): void {
    registerTypedMeta($this, Conference::class);
    wp_set_current_user(1);

    $schedule = ['starts_at' => '2026-12-01T09:00:00', 'duration_minutes' => 90, 'status' => 'published', 'room' => 'B2', 'public' => false];
    $created = typedMetaRest('POST', '/wp/v2/conference', [
        'title' => 'PollConf',
        'status' => 'publish',
        'meta' => ['room_ids' => [3, 5], 'schedule' => $schedule, 'speakers' => ['Ada', 'Grace']],
    ]);
    $id = (int) ($created->get_data()['id'] ?? 0);
    $this->posts[] = $id;

    expect($created->get_status())->toBe(201)
        ->and($created->get_data()['meta']['room_ids'])->toBe([3, 5])
        ->and($created->get_data()['meta']['schedule']['duration_minutes'])->toBe(90);

    // What the database holds reads back as typed PHP values.
    wp_cache_flush();
    $conference = $this->meta->of(Conference::class, $id);

    expect($conference->roomIds)->toBe([3, 5])
        ->and($conference->schedule->durationMinutes)->toBe(90)
        ->and($conference->schedule->status)->toBe(EventStatus::Published)
        ->and($conference->speakers)->toBe(['Ada', 'Grace']);
});

it('refuses through REST a value of the wrong type or outside the enum', function (): void {
    registerTypedMeta($this, Event::class);
    wp_set_current_user(1);

    $wrongType = typedMetaRest('POST', '/wp/v2/event', ['title' => 'Bad', 'status' => 'publish', 'meta' => ['capacity' => 'many']]);
    $wrongEnum = typedMetaRest('POST', '/wp/v2/event', ['title' => 'Bad', 'status' => 'publish', 'meta' => ['status' => 'archived']]);

    expect($wrongType->get_status())->toBe(400)
        ->and($wrongEnum->get_status())->toBe(400);
});

it('applies the rules of a typed meta to a REST write, before anything is stored', function (): void {
    registerTypedMeta($this, RatedEvent::class);
    $validation = new WordPressRestMetaValidation($this->schemas, $this->caster, metaValidator());
    add_filter('rest_request_before_callbacks', $validation->validate(...), 10, 3);
    wp_set_current_user(1);

    $refused = typedMetaRest('POST', '/wp/v2/rated_event', ['title' => 'Too big', 'status' => 'publish', 'meta' => ['capacity' => 6000]]);
    $accepted = typedMetaRest('POST', '/wp/v2/rated_event', ['title' => 'Fine', 'status' => 'publish', 'meta' => ['capacity' => 300]]);
    $this->posts[] = (int) ($accepted->get_data()['id'] ?? 0);

    expect($refused->get_status())->toBe(400)
        ->and($refused->get_data()['code'])->toBe('rest_invalid_param')
        ->and($refused->get_data()['data']['params'])->toHaveKey('meta.capacity')
        ->and($refused->get_data()['data']['params']['meta.capacity'])->toContain('not be greater than 5000')
        ->and($accepted->get_status())->toBe(201)
        ->and(get_posts(['post_type' => 'rated_event', 'title' => 'Too big', 'post_status' => 'any']))->toBe([]);
});

it('keeps the capability of a typed meta in the real capability check', function (): void {
    registerTypedMeta($this, Event::class);
    $id = typedMetaPost($this, 'event');
    $editor = typedMetaUser($this, 'editor');
    $admin = typedMetaUser($this, 'administrator');

    // `_event_internal_ref` is a protected key (underscore): WordPress denies it unless the auth callback allows it.
    expect(user_can($editor, 'edit_post_meta', $id, '_event_internal_ref'))->toBeFalse()
        ->and(user_can($admin, 'edit_post_meta', $id, '_event_internal_ref'))->toBeTrue()
        ->and(user_can($editor, 'edit_post_meta', $id, 'capacity'))->toBeTrue();
});

it('sanitizes what is stored with the callbacks it registered', function (): void {
    registerTypedMeta($this, Event::class);
    $id = typedMetaPost($this, 'event');

    update_post_meta($id, 'subtitle', '<b>Hello</b> <script>alert(1)</script>');
    update_post_meta($id, 'summary', '<p>Kept</p><script>alert(1)</script>');

    expect(get_post_meta($id, 'subtitle', true))->toBe('Hello')
        ->and(get_post_meta($id, 'summary', true))->toBe('<p>Kept</p>alert(1)');
});

it('stores strings with quotes, backslashes and unicode, and arrays, as they were given', function (): void {
    registerTypedMeta($this, Conference::class);
    $id = typedMetaPost($this, 'conference');
    $text = "O'Reilly \\ \"quoted\" \\n café ☕";

    $this->store->update(MetaObjectType::Post, $id, 'summary', $text);
    $this->store->update(MetaObjectType::Post, $id, 'room_ids', [1, 2]);
    $this->store->replaceAll(MetaObjectType::Post, $id, 'speakers', ['Ada', "D'Arcy", 'Grace']);
    wp_cache_flush();

    expect($this->store->get(MetaObjectType::Post, $id, 'summary'))->toBe($text)
        ->and($this->store->get(MetaObjectType::Post, $id, 'room_ids'))->toBe([1, 2])
        ->and($this->store->getAll(MetaObjectType::Post, $id, 'speakers'))->toBe(['Ada', "D'Arcy", 'Grace']);

    $this->store->replaceAll(MetaObjectType::Post, $id, 'speakers', ['Only']);
    $this->store->delete(MetaObjectType::Post, $id, 'summary');
    wp_cache_flush();

    expect($this->store->getAll(MetaObjectType::Post, $id, 'speakers'))->toBe(['Only'])
        ->and($this->store->get(MetaObjectType::Post, $id, 'summary'))->toBeNull();
});

it('reads the meta tables with the SQL of the audit, on MySQL', function (): void {
    registerTypedMeta($this, Event::class);
    $first = typedMetaPost($this, 'event');
    $second = typedMetaPost($this, 'event');
    $other = typedMetaPost($this, 'conference');
    update_post_meta($first, 'capacity', 100);
    // Written to the table directly: through the meta API the registered sanitizer would have turned it into an integer.
    $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->postmeta, ['post_id' => $second, 'meta_key' => 'capacity', 'meta_value' => 'not a number']);
    update_post_meta($first, 'legacy_field', ['a' => 1]);
    update_post_meta($first, '_hidden', 'x');
    update_post_meta($other, 'capacity', 7);

    $inventory = new WordPressMetaInventory;
    $keys = $inventory->keys(MetaObjectType::Post, ['event'], 50);
    $values = $inventory->values(MetaObjectType::Post, ['event'], 'capacity', 50);

    expect($keys)->toHaveKeys(['capacity', 'legacy_field'])
        ->and($keys)->not->toHaveKey('_hidden')
        ->and($keys['capacity'])->toBe(2)
        ->and($values)->toBe([$first => ['100'], $second => ['not a number']])
        ->and($inventory->values(MetaObjectType::Post, ['event'], 'legacy_field', 50))->toBe([$first => [['a' => 1]]]);

    $auditor = new MetaAuditor($this->schemas, $inventory, $this->caster);
    $unreadable = $auditor->unreadable(50);
    $undeclared = $auditor->undeclared(50);

    expect(array_column($unreadable, 'key'))->toContain('capacity')
        ->and($unreadable[array_search('capacity', array_column($unreadable, 'key'), true)]['objects'])->toBe([$second])
        ->and(array_column($undeclared, 'key'))->toContain('legacy_field');
});
