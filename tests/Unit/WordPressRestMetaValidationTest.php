<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressRestMetaValidation;
use Pollora\Meta\Tests\Fixtures\MemberProfile;
use Pollora\Meta\Tests\Fixtures\RatedEvent;
use Pollora\Meta\Tests\Fixtures\RatedGenre;
use Pollora\Meta\Tests\Fixtures\RatedMember;
use Pollora\Meta\Tests\Fixtures\RatedReview;

require_once dirname(__DIR__).'/Fixtures/validator.php';

foreach (['WP_REST_Posts_Controller' => 'post_type', 'WP_REST_Terms_Controller' => 'taxonomy'] as $class => $property) {
    if (! class_exists($class)) {
        eval("class {$class} { public function __construct(protected string \${$property} = '') {} }");
    }
}

foreach (['WP_REST_Users_Controller', 'WP_REST_Comments_Controller'] as $class) {
    if (! class_exists($class)) {
        eval("class {$class} {}");
    }
}

// The same stand-in as the other tests, whichever loads first.
if (! class_exists('WP_REST_Request')) {
    eval('class WP_REST_Request { public function __construct(private string $method = "", private string $route = "") {} public function get_route(): string { return $this->route; } }');
}

/**
 * A REST write of the given meta, as WordPress hands it to the filter.
 *
 * @param  array<string, mixed>|null  $meta
 */
function restMetaWrite(?array $meta, string $method = 'POST'): WP_REST_Request
{
    $request = Mockery::mock(WP_REST_Request::class);
    $request->shouldReceive('get_param')->with('meta')->andReturn($meta);
    $request->shouldReceive('get_method')->andReturn($method);

    return $request;
}

beforeEach(function (): void {
    $schemas = new MetaSchemaRepository;
    $schemas->add((new MetaSchemaBuilder)->build(RatedEvent::class));
    $schemas->add((new MetaSchemaBuilder)->build(MemberProfile::class));

    $this->validation = new WordPressRestMetaValidation($schemas, new MetaValueCaster, metaValidator());
    $this->posts = ['callback' => [new WP_REST_Posts_Controller('rated_event'), 'update_item']];
});

it('refuses with a 400 a write that breaks a rule, before anything is stored', function (): void {
    expect($this->validation->validate(null, $this->posts, restMetaWrite(['capacity' => 6000])))->toBeInstanceOf(WP_Error::class);
});

it('lets through a valid write, a meta without rules, and a value of the wrong type for WordPress to refuse', function (): void {
    expect($this->validation->validate(null, $this->posts, restMetaWrite(['capacity' => 300, 'free' => -5])))->toBeNull()
        ->and($this->validation->validate(null, $this->posts, restMetaWrite(['capacity' => 'many'])))->toBeNull();
});

it('only looks at writes with meta to a core object route', function (): void {
    expect($this->validation->validate(null, $this->posts, restMetaWrite(['capacity' => 6000], 'GET')))->toBeNull()
        ->and($this->validation->validate(null, $this->posts, restMetaWrite(null)))->toBeNull()
        ->and($this->validation->validate(null, ['callback' => [new WP_REST_Posts_Controller('page'), 'update_item']], restMetaWrite(['capacity' => 6000])))->toBeNull()
        ->and($this->validation->validate(null, ['callback' => 'some_function'], restMetaWrite(['capacity' => 6000])))->toBeNull()
        ->and($this->validation->validate(null, ['callback' => [new WP_REST_Users_Controller, 'update_item']], restMetaWrite(['capacity' => 6000])))->toBeNull();
});

it('keeps an error an earlier filter returned', function (): void {
    $error = new WP_Error;

    expect($this->validation->validate($error, $this->posts, restMetaWrite(['capacity' => 6000])))->toBe($error);
});

require_once dirname(__DIR__).'/Fixtures/RatedOthers.php';

it('answers a 400 naming each meta that broke a rule', function (): void {
    $error = $this->validation->validate(null, $this->posts, restMetaWrite(['capacity' => 6000, 'free' => -5, 'contact' => 'nobody']));

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->code)->toBe('rest_invalid_param')
        ->and($error->message)->toBe('Invalid parameter(s): meta')
        ->and($error->data)->toBe([
            'status' => 400,
            'params' => [
                'meta.capacity' => 'The Capacity field must not be greater than 5000.',
                'meta.contact' => 'The contact field must be a valid email address.',
            ],
        ]);
});

it('checks a meta that follows one the request leaves out', function (): void {
    $error = $this->validation->validate(null, $this->posts, restMetaWrite(['contact' => 'nobody']));

    expect($error->data['params'])->toBe(['meta.contact' => 'The contact field must be a valid email address.']);
});

describe('on each core route', function (): void {
    beforeEach(function (): void {
        $schemas = new MetaSchemaRepository;

        foreach ([RatedGenre::class, RatedMember::class, RatedReview::class] as $class) {
            $schemas->add((new MetaSchemaBuilder)->build($class));
        }

        $this->validation = new WordPressRestMetaValidation($schemas, new MetaValueCaster, metaValidator());
    });

    it('validates the meta of a taxonomy, a user and a comment', function (object $controller, string $key, string $message): void {
        $error = $this->validation->validate(null, ['callback' => [$controller, 'update_item']], restMetaWrite([$key => 99]));

        expect($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->data['params'])->toBe(["meta.{$key}" => $message]);
    })->with([
        'a term' => [fn () => new WP_REST_Terms_Controller('rated_genre'), 'stars', 'The stars field must not be greater than 3.'],
        'a user' => [fn () => new WP_REST_Users_Controller, 'level', 'The level field must not be greater than 10.'],
        'a comment' => [fn () => new WP_REST_Comments_Controller, 'score', 'The score field must not be greater than 5.'],
    ]);

    it('leaves alone a route it does not know, and a taxonomy that declares nothing', function (mixed $callback, string $key): void {
        expect($this->validation->validate(null, ['callback' => $callback], restMetaWrite([$key => 99])))->toBeNull();
    })->with([
        'unknown, user key' => ['some_function', 'level'],
        'unknown, comment key' => ['some_function', 'score'],
        'another object, user key' => [[new stdClass, 'update_item'], 'level'],
        'another object, comment key' => [[new stdClass, 'update_item'], 'score'],
        'a user route, comment key' => [[new WP_REST_Users_Controller, 'update_item'], 'score'],
        'a comment route, user key' => [[new WP_REST_Comments_Controller, 'update_item'], 'level'],
        'another taxonomy' => [[new WP_REST_Terms_Controller('other'), 'update_item'], 'stars'],
    ]);
});
