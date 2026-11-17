<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Attributes\Meta;
use Pollora\Attributes\UserMeta;
use Pollora\Colt\Model\Post;

#[UserMeta]
abstract class AbstractOwner
{
    #[Meta]
    public ?string $phone = null;
}

#[UserMeta]
enum OwnerEnum: string
{
    case One = 'one';
}

class ChildOfOther extends AbstractOwner {}

class ModelWithoutPostType extends Post {}

class ModelWithEmptyPostType extends Post
{
    protected $postType = '';
}
