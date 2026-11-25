<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaInventory;

// WordPress is not loaded in the unit suite: stand in for its database class so that a fake can extend it.
if (! class_exists('wpdb')) {
    class wpdb {}
}

beforeEach(function (): void {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $this->queries = [];
    $this->results = [];
    // Only what the adapter uses: table names, prepare() and get_results()
    $GLOBALS['wpdb'] = new class($this) extends wpdb
    {
        public string $postmeta = 'wp_postmeta';

        public string $posts = 'wp_posts';

        public string $termmeta = 'wp_termmeta';

        public string $term_taxonomy = 'wp_term_taxonomy';

        public string $usermeta = 'wp_usermeta';

        public string $commentmeta = 'wp_commentmeta';

        public function __construct(private readonly object $test) {}

        public function prepare(string $query, mixed ...$args): string
        {
            $this->test->queries[] = [$query, $args];

            return $query;
        }

        public function get_results(string $query): array
        {
            return $this->test->results;
        }
    };
    Functions\when('is_serialized')->alias(fn (string $value): bool => str_starts_with($value, 'a:') || str_starts_with($value, 'O:'));
});

afterEach(function (): void {
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

it('reads the values of a key on the post types given, revisions left out, never into objects', function (): void {
    $this->results = [
        (object) ['object_id' => '5', 'meta_value' => '250'],
        (object) ['object_id' => '5', 'meta_value' => 'a:1:{i:0;s:4:"rock";}'],
        (object) ['object_id' => '6', 'meta_value' => 'O:8:"stdClass":0:{}'],
    ];

    $values = (new WordPressMetaInventory)->values(MetaObjectType::Post, ['event', 'concert'], 'capacity', 100);
    [$query, $args] = $this->queries[0];

    expect($values[5])->toBe(['250', ['rock']])
        ->and($values[6][0])->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and($query)->toContain("FROM wp_postmeta m INNER JOIN wp_posts o ON o.ID = m.post_id WHERE m.meta_key = %s AND o.post_type <> 'revision' AND o.post_type IN (%s, %s)")
        ->and($args)->toBe(['capacity', 'event', 'concert', 100]);
});

it('counts the keys stored on a taxonomy, protected ones left out', function (): void {
    $this->results = [(object) ['meta_key' => 'color', 'objects' => '3']];

    $keys = (new WordPressMetaInventory)->keys(MetaObjectType::Term, ['genre'], 50);
    [$query, $args] = $this->queries[0];

    expect($keys)->toBe(['color' => 3])
        ->and($query)->toContain('FROM wp_termmeta m INNER JOIN wp_term_taxonomy o ON o.term_id = m.term_id WHERE m.meta_key NOT LIKE %s AND o.taxonomy IN (%s)')
        ->and($args)->toBe(['\_%', 'genre', 50]);
});

it('reads user meta without a subtype', function (): void {
    (new WordPressMetaInventory)->values(MetaObjectType::User, [], 'job_title', 10);

    expect($this->queries[0][0])->toContain('SELECT m.user_id AS object_id, m.meta_value FROM wp_usermeta m WHERE m.meta_key = %s ORDER BY m.umeta_id');
});

it('asks for at least one row, whatever the limit', function (int $limit, int $expected): void {
    $inventory = new WordPressMetaInventory;

    $inventory->values(MetaObjectType::User, [], 'job_title', $limit);
    $inventory->keys(MetaObjectType::User, [], $limit);

    expect(end($this->queries[0][1]))->toBe($expected)
        ->and(end($this->queries[1][1]))->toBe($expected);
})->with([
    'zero' => [0, 1],
    'negative' => [-5, 1],
    'one' => [1, 1],
    'several' => [7, 7],
]);

it('does not filter on a subtype, nor join, when none is given', function (): void {
    $inventory = new WordPressMetaInventory;

    $inventory->values(MetaObjectType::Post, [], 'k', 10);
    $inventory->values(MetaObjectType::Term, [], 'k', 10);
    $inventory->values(MetaObjectType::Comment, [], 'k', 10);

    expect($this->queries[0][0])->toBe("SELECT m.post_id AS object_id, m.meta_value FROM wp_postmeta m INNER JOIN wp_posts o ON o.ID = m.post_id WHERE m.meta_key = %s AND o.post_type <> 'revision' ORDER BY m.meta_id LIMIT %d")
        ->and($this->queries[0][1])->toBe(['k', 10])
        ->and($this->queries[1][0])->toBe('SELECT m.term_id AS object_id, m.meta_value FROM wp_termmeta m WHERE m.meta_key = %s ORDER BY m.meta_id LIMIT %d')
        ->and($this->queries[1][1])->toBe(['k', 10])
        ->and($this->queries[2][0])->toBe('SELECT m.comment_id AS object_id, m.meta_value FROM wp_commentmeta m WHERE m.meta_key = %s ORDER BY m.meta_id LIMIT %d')
        ->and($this->queries[2][1])->toBe(['k', 10]);
});

it('reads a stored value that WordPress considers serialized despite surrounding whitespace', function (): void {
    Functions\when('is_serialized')->alias(fn (string $value): bool => str_starts_with(trim($value), 'a:'));
    $this->results = [(object) ['object_id' => '5', 'meta_value' => " a:1:{i:0;s:4:\"rock\";}\n"]];

    $values = (new WordPressMetaInventory)->values(MetaObjectType::User, [], 'genres', 10);

    expect($values)->toBe([5 => [['rock']]]);
});

it('gives the rows of an object by its integer ID', function (): void {
    $this->results = [
        (object) ['object_id' => '5', 'meta_value' => 'a'],
        (object) ['object_id' => '7', 'meta_value' => null],
    ];

    expect((new WordPressMetaInventory)->values(MetaObjectType::User, [], 'k', 10))->toBe([5 => ['a'], 7 => [null]]);
});

it('refuses to read the meta tables when WordPress has not created $wpdb', function (): void {
    $GLOBALS['wpdb'] = null;

    expect(fn (): array => (new WordPressMetaInventory)->values(MetaObjectType::User, [], 'k', 10))->toThrow(LogicException::class, '$wpdb');
});
