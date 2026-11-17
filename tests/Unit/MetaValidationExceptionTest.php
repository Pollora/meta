<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Tests\Fixtures\Event;

it('names the meta and joins the messages of the failed rules', function (): void {
    $definition = (new MetaSchemaBuilder)->build(Event::class)->definitions['capacity'];

    $exception = new MetaValidationException($definition, ['Too big.', 'Not even.']);

    expect($exception->getMessage())->toBe('The meta "capacity" is invalid: Too big. Not even.')
        ->and($exception->definition)->toBe($definition)
        ->and($exception->messages)->toBe(['Too big.', 'Not even.']);
});
