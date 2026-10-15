<?php

declare(strict_types=1);

namespace Pollora\Meta\Infrastructure\Services;

use Pollora\Attributes\CommentMeta;
use Pollora\Attributes\PostMeta;
use Pollora\Attributes\PostType;
use Pollora\Attributes\Taxonomy;
use Pollora\Attributes\TermMeta;
use Pollora\Attributes\UserMeta;
use Pollora\Colt\Model\Post as ColtPost;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Contracts\MetaRegistryInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Discovers the `#[Meta]` properties of `#[PostType]`, `#[Taxonomy]`, `#[PostMeta]`,
 * `#[TermMeta]`, `#[UserMeta]` and `#[CommentMeta]` classes.
 *
 *  1. **discover()** — keeps the classes carrying one of those attributes, and the
 *     post models (classes extending a Colt post model).
 *  2. **apply()** — builds each class's schema, stores it for `Meta::of()` and
 *     queues it for `register_meta()`; binds each post model to its `$postType`.
 *
 * A declaration that cannot be registered is logged with the class and property
 * named, and the other classes still register.
 */
final class MetaDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    private const array OWNER_ATTRIBUTES = [PostType::class, Taxonomy::class, PostMeta::class, TermMeta::class, UserMeta::class, CommentMeta::class];

    public function __construct(
        private readonly MetaSchemaBuilder $builder,
        private readonly MetaSchemaRepository $schemas,
        private readonly MetaRegistryInterface $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        if (! $structure instanceof DiscoveredClass || $structure->isAbstract) {
            return;
        }

        $class = $structure->namespace.'\\'.$structure->name;

        foreach ($structure->attributes as $attribute) {
            if (in_array($attribute->class, self::OWNER_ATTRIBUTES, true)) {
                $this->getItems()->add($location, ['class' => $class]);

                return;
            }
        }

        if ($structure->extends !== null && is_subclass_of($class, ColtPost::class)) {
            $this->getItems()->add($location, ['class' => $class, 'model' => true]);
        }
    }

    public function apply(): void
    {
        foreach ($this->getItems() as $item) {
            /** @var class-string $class */
            $class = $item['class'];

            if ($item['model'] ?? false) {
                $this->registerPostModel($class);

                continue;
            }

            try {
                $schema = $this->builder->build($class);

                if ($schema->isEmpty()) {
                    continue;
                }

                $this->schemas->add($schema);
                $this->registry->register($schema);
            } catch (\Throwable $throwable) {
                $this->schemas->fail($class, $throwable->getMessage());
                $this->logger?->error(sprintf('Failed to register the meta of %s: %s', $class, $throwable->getMessage()), ['exception' => $throwable]);
            }
        }
    }

    /**
     * Makes `Post::find()` return the model bound to the post type
     * (`protected $postType = 'event'`), so its typed meta are there.
     *
     * @param  class-string  $class
     */
    private function registerPostModel(string $class): void
    {
        $postType = (new ReflectionClass($class))->getDefaultProperties()['postType'] ?? null;

        if (is_string($postType) && $postType !== '') {
            ColtPost::registerPostType($postType, $class);
        }
    }

    public function getIdentifier(): string
    {
        return 'meta';
    }
}
