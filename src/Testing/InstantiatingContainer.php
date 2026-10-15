<?php

declare(strict_types=1);

namespace Pollora\Meta\Testing;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The container MetaUiDriverConformance gives to the drivers it builds: it
 * makes a class with no constructor argument, and has no other service.
 *
 * @internal
 */
final class InstantiatingContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        if (! class_exists($id)) {
            throw new class(sprintf('No entry "%s".', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return new $id;
    }

    public function has(string $id): bool
    {
        return class_exists($id);
    }
}
