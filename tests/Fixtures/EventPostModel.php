<?php

declare(strict_types=1);

namespace Pollora\Meta\Tests\Fixtures;

use Pollora\Colt\Model\Post;

class EventPostModel extends Post
{
    protected $postType = 'fixture_event';
}
