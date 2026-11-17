<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;

abstract class AbstractNote
{
    public string $text = '';
}

final class UntypedPart
{
    public $loose = null;
}

final class RequiredPart
{
    public string $label;
}

final class NullablePart
{
    public ?string $label;
}

final class PromotedPart
{
    public function __construct(public ?string $label, public int $size = 3, $plain = 1) {}
}

#[UserMeta]
class AbstractTyped
{
    #[Meta]
    public ?AbstractNote $note = null;
}

#[UserMeta]
class ObjectTyped
{
    #[Meta]
    public ?object $thing = null;
}

#[UserMeta]
class UntypedPartOwner
{
    #[Meta]
    public ?UntypedPart $part = null;
}

#[UserMeta]
class RequiredPartOwner
{
    #[Meta]
    public ?RequiredPart $part = null;
}

#[UserMeta]
class NullablePartOwner
{
    #[Meta]
    public ?NullablePart $part = null;
}

#[UserMeta]
class PromotedPartOwner
{
    #[Meta]
    public ?PromotedPart $part = null;
}

#[UserMeta]
class DocblockItems
{
    /** @var list<int> */
    #[Meta]
    public array $fromList = [];

    /** @var array<bool> */
    #[Meta]
    public array $fromArray = [];

    /** @var array<int, float> */
    #[Meta]
    public array $fromIndexedArray = [];

    /** @var string[] */
    #[Meta]
    public array $fromBrackets = [];
}

#[UserMeta]
class AliasedItems
{
    #[Meta(items: 'string')]
    public array $string = [];

    #[Meta(items: 'int')]
    public array $int = [];

    #[Meta(items: 'integer')]
    public array $integer = [];

    #[Meta(items: 'float')]
    public array $float = [];

    #[Meta(items: 'bool')]
    public array $bool = [];

    #[Meta(items: 'boolean')]
    public array $boolean = [];

    #[Meta(items: '\\'.EventStatus::class)]
    public array $slashed = [];

    #[Meta(items: 'string', rules: [3 => 'max:5', 7 => 'min:1'])]
    public array $ruled = [];
}
