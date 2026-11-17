<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Enums\Control;
use Pollora\Meta\Domain\Enums\MetaObjectType;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaDefinitionException;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Tests\Fixtures\AbstractTyped;
use Pollora\Meta\Tests\Fixtures\AliasedItems;
use Pollora\Meta\Tests\Fixtures\ArticleExtras;
use Pollora\Meta\Tests\Fixtures\BookGenre;
use Pollora\Meta\Tests\Fixtures\CategoryExtras;
use Pollora\Meta\Tests\Fixtures\DocblockItems;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\EventStatus;
use Pollora\Meta\Tests\Fixtures\InvalidArray;
use Pollora\Meta\Tests\Fixtures\InvalidDuplicateKey;
use Pollora\Meta\Tests\Fixtures\InvalidMedia;
use Pollora\Meta\Tests\Fixtures\InvalidNoDefault;
use Pollora\Meta\Tests\Fixtures\InvalidProtectedInRest;
use Pollora\Meta\Tests\Fixtures\InvalidPureEnum;
use Pollora\Meta\Tests\Fixtures\InvalidTermRevisions;
use Pollora\Meta\Tests\Fixtures\InvalidUnion;
use Pollora\Meta\Tests\Fixtures\InvalidUntyped;
use Pollora\Meta\Tests\Fixtures\MemberProfile;
use Pollora\Meta\Tests\Fixtures\NotADeclaration;
use Pollora\Meta\Tests\Fixtures\NullablePartOwner;
use Pollora\Meta\Tests\Fixtures\ObjectTyped;
use Pollora\Meta\Tests\Fixtures\Priority;
use Pollora\Meta\Tests\Fixtures\PromotedPartOwner;
use Pollora\Meta\Tests\Fixtures\RequiredPartOwner;
use Pollora\Meta\Tests\Fixtures\ReviewMeta;
use Pollora\Meta\Tests\Fixtures\UntypedPartOwner;
use Pollora\Meta\Tests\Fixtures\UserRevisions;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';

describe('a post type', function (): void {
    beforeEach(function (): void {
        $this->schema = (new MetaSchemaBuilder)->build(Event::class);
    });

    it('attaches the meta to the post type slug', function (): void {
        expect($this->schema->objectType)->toBe(MetaObjectType::Post)
            ->and($this->schema->subtypes)->toBe(['event'])
            ->and($this->schema->declaringClass)->toBe(Event::class);
    });

    it('keeps only public instance properties carrying #[Meta]', function (): void {
        expect(array_keys($this->schema->definitions))->toBe([
            'startsAt', 'capacity', 'price', 'soldOut', 'status', 'priority', 'subtitle', 'internalRef', 'summary', 'endsAt',
        ]);
    });

    it('derives the key from the property name, in snake_case', function (): void {
        expect($this->schema->definitions['startsAt']->key)->toBe('starts_at')
            ->and($this->schema->definitions['internalRef']->key)->toBe('_event_internal_ref');
    });

    it('derives the value type from the property type', function (string $property, MetaValueType $type, ?string $class): void {
        $definition = $this->schema->definitions[$property];

        expect($definition->valueType)->toBe($type)
            ->and($definition->valueClass)->toBe($class);
    })->with([
        'string' => ['summary', MetaValueType::String, null],
        'int' => ['capacity', MetaValueType::Integer, null],
        'float' => ['price', MetaValueType::Number, null],
        'bool' => ['soldOut', MetaValueType::Boolean, null],
        'date' => ['startsAt', MetaValueType::DateTime, CarbonImmutable::class],
        'string enum' => ['status', MetaValueType::Enum, EventStatus::class],
        'int enum' => ['priority', MetaValueType::Enum, Priority::class],
    ]);

    it('takes the default from the initial value, null for a nullable property', function (): void {
        expect($this->schema->definitions['capacity']->default)->toBe(0)
            ->and($this->schema->definitions['status']->default)->toBe(EventStatus::Draft)
            ->and($this->schema->definitions['startsAt']->default)->toBeNull()
            ->and($this->schema->definitions['startsAt']->nullable)->toBeTrue()
            ->and($this->schema->definitions['capacity']->nullable)->toBeFalse();
    });

    it('carries the attribute options', function (): void {
        $startsAt = $this->schema->definitions['startsAt'];
        $internalRef = $this->schema->definitions['internalRef'];

        expect($startsAt->showInRest)->toBeTrue()
            ->and($startsAt->label)->toBe('Start')
            ->and($startsAt->description)->toBe('When the event starts')
            ->and($internalRef->capability)->toBe('manage_options')
            ->and($this->schema->definitions['subtitle']->revisions)->toBeTrue()
            ->and($this->schema->definitions['summary']->sanitize)->toBe('wp_kses_post');
    });
});

it('attaches the meta of a taxonomy to its slug', function (): void {
    $schema = (new MetaSchemaBuilder)->build(BookGenre::class);

    expect($schema->objectType)->toBe(MetaObjectType::Term)
        ->and($schema->subtypes)->toBe(['book-genre'])
        ->and($schema->definitions['color']->key)->toBe('color');
});

it('refuses a class that declares no meta owner', function (): void {
    (new MetaSchemaBuilder)->build(NotADeclaration::class);
})->throws(InvalidMetaDefinitionException::class, 'carries none of #[PostType], #[Taxonomy], #[PostMeta], #[TermMeta], #[UserMeta], #[CommentMeta]');

it('refuses a declaration WordPress cannot register', function (string $class, string $message): void {
    expect(fn (): MetaSchema => (new MetaSchemaBuilder)->build($class))
        ->toThrow(InvalidMetaDefinitionException::class, $message);
})->with([
    'union type' => [InvalidUnion::class, 'needs a single type'],
    'untyped property' => [InvalidUntyped::class, 'needs a single type'],
    'array' => [InvalidArray::class, 'say what the array holds'],
    'pure enum' => [InvalidPureEnum::class, 'needs backing values'],
    'no default, not nullable' => [InvalidNoDefault::class, 'give the property a default value or make it nullable'],
    'protected key in REST' => [InvalidProtectedInRest::class, 'the protected key "_secret" can only be exposed in REST with an explicit capability'],
    'revisions on a term' => [InvalidTermRevisions::class, 'revisions only exist for post types'],
    'revisions on a user' => [UserRevisions::class, 'revisions only exist for post types'],
    'same key twice' => [InvalidDuplicateKey::class, 'the key "shared" is already used by $first'],
    'media on a string' => [InvalidMedia::class, 'media: true holds an attachment ID, so it needs an int property'],
]);

it('attaches the meta of #[PostMeta], #[TermMeta], #[UserMeta] and #[CommentMeta] to their objects', function (string $class, MetaObjectType $objectType, array $subtypes): void {
    $schema = (new MetaSchemaBuilder)->build($class);

    expect($schema->objectType)->toBe($objectType)
        ->and($schema->subtypes)->toBe($subtypes)
        ->and($schema->declaresSubtypes)->toBeFalse()
        ->and($schema->isEmpty())->toBeFalse();
})->with([
    'post types' => [ArticleExtras::class, MetaObjectType::Post, ['post', 'page']],
    'a taxonomy' => [CategoryExtras::class, MetaObjectType::Term, ['category']],
    'users' => [MemberProfile::class, MetaObjectType::User, []],
    'comments' => [ReviewMeta::class, MetaObjectType::Comment, []],
]);

it('knows when the class declares its post type or taxonomy', function (): void {
    expect((new MetaSchemaBuilder)->build(Event::class)->declaresSubtypes)->toBeTrue()
        ->and((new MetaSchemaBuilder)->build(BookGenre::class)->declaresSubtypes)->toBeTrue()
        ->and((new MetaSchemaBuilder)->build(Event::class)->exposesInRest())->toBeTrue()
        ->and((new MetaSchemaBuilder)->build(ReviewMeta::class)->exposesInRest())->toBeFalse();
});

it('marks an attachment ID and a meta anyone may see', function (): void {
    $class = new #[UserMeta] class
    {
        #[Meta(media: true, public: true)]
        public ?int $avatarId = null;

        #[Meta]
        public ?string $phone = null;
    };
    $definitions = (new MetaSchemaBuilder)->build($class::class)->definitions;

    expect($definitions['avatarId']->media)->toBeTrue()
        ->and($definitions['avatarId']->public)->toBeTrue()
        ->and($definitions['avatarId']->control)->toBe(Control::Media)
        ->and($definitions['phone']->media)->toBeFalse()
        ->and($definitions['phone']->public)->toBeFalse();
});

require_once dirname(__DIR__).'/Fixtures/BuilderEdges.php';

describe('the type of an array item', function (): void {
    it('is read from the docblock, in each notation', function (string $property, MetaValueType $type): void {
        $definition = (new MetaSchemaBuilder)->build(DocblockItems::class)->definitions[$property];

        expect($definition->item()->valueType)->toBe($type);
    })->with([
        'list<int>' => ['fromList', MetaValueType::Integer],
        'array<bool>' => ['fromArray', MetaValueType::Boolean],
        'array<int, float>' => ['fromIndexedArray', MetaValueType::Number],
        'string[]' => ['fromBrackets', MetaValueType::String],
    ]);

    it('accepts each alias, and a class name with a leading backslash', function (string $property, MetaValueType $type, ?string $class): void {
        $item = (new MetaSchemaBuilder)->build(AliasedItems::class)->definitions[$property]->item();

        expect($item->valueType)->toBe($type)
            ->and($item->valueClass)->toBe($class)
            ->and($item->nullable)->toBeFalse()
            ->and($item->default)->toBeNull();
    })->with([
        'string' => ['string', MetaValueType::String, null],
        'int' => ['int', MetaValueType::Integer, null],
        'integer' => ['integer', MetaValueType::Integer, null],
        'float' => ['float', MetaValueType::Number, null],
        'bool' => ['bool', MetaValueType::Boolean, null],
        'boolean' => ['boolean', MetaValueType::Boolean, null],
        'a class with a leading backslash' => ['slashed', MetaValueType::Enum, EventStatus::class],
    ]);

    it('keeps the rules of the meta as a list', function (): void {
        $definition = (new MetaSchemaBuilder)->build(AliasedItems::class)->definitions['ruled'];

        expect($definition->rules)->toBe(['max:5', 'min:1']);
    });
});

describe('a data object', function (): void {
    it('refuses a type that is neither a class nor a scalar', function (string $class, string $message): void {
        expect(fn (): MetaSchema => (new MetaSchemaBuilder)->build($class))
            ->toThrow(InvalidMetaDefinitionException::class, $message);
    })->with([
        'object' => [ObjectTyped::class, 'the type object is not supported'],
        'an abstract class' => [AbstractTyped::class, 'the type Pollora\Meta\Tests\Fixtures\AbstractNote is not supported'],
        'an untyped property' => [UntypedPartOwner::class, 'Pollora\Meta\Tests\Fixtures\UntypedPart::$loose needs a single type.'],
        'a property without default' => [RequiredPartOwner::class, 'Pollora\Meta\Tests\Fixtures\RequiredPart::$label needs a default value or a nullable type.'],
    ]);

    it('accepts a nullable property without default', function (): void {
        $part = (new MetaSchemaBuilder)->build(NullablePartOwner::class)->definitions['part'];

        expect($part->properties['label']->nullable)->toBeTrue()
            ->and($part->properties['label']->default)->toBeNull();
    });

    it('takes the defaults of its promoted constructor parameters only', function (): void {
        $properties = (new MetaSchemaBuilder)->build(PromotedPartOwner::class)->definitions['part']->properties;

        expect($properties['size']->default)->toBe(3)
            ->and($properties['label']->default)->toBeNull();
    });
});
