<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/meta.png" width="100%" alt="Pollora Meta: typed WordPress meta declared with attributes">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/meta"><img src="https://img.shields.io/packagist/v/pollora/meta" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/meta"><img src="https://img.shields.io/packagist/dt/pollora/meta" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/meta/actions/workflows/tests.yml"><img src="https://github.com/Pollora/meta/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/meta" alt="License"></a>
</p>

Typed WordPress meta, declared with attributes: a property of a class is a meta, its PHP type is the meta's type, its initial value is the default. The package registers it with `register_meta()`, reads and writes it with its PHP type (an `int`, a `bool`, a `CarbonImmutable`, an enum, a list, a data object), checks its rules on every write, and gives the compiled schemas to the tooling and to the UI drivers (ACF, Meta Box) that build the editing fields.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [Typed meta](https://pollora.dev/content/typed-meta/). It runs without Laravel too: in a plugin, a Bedrock site or any PHP project.

## Installation

```bash
composer require pollora/meta
```

Requires PHP 8.3+ and WordPress. It builds on [`pollora/attributes`](https://github.com/Pollora/attributes) (the `#[Meta]` attribute), [`pollora/discovery`](https://github.com/Pollora/discovery) and [`pollora/hook`](https://github.com/Pollora/hook).

## Declaring

```php
use Pollora\Attributes\Meta;
use Pollora\Attributes\PostType;

#[PostType('event')]
class Event
{
    #[Meta(showInRest: true, label: 'Capacity', rules: ['min:0', 'max:5000'])]
    public int $capacity = 0;

    #[Meta(showInRest: true)]
    public bool $soldOut = false;

    #[Meta]
    public ?CarbonImmutable $startsAt = null;
}
```

`#[Meta]` takes `key`, `showInRest`, `label`, `description`, `sanitize`, `capability`, `revisions`, `rules`, `single`, `items`, `control`, `group`, `hints`, `media` and `public`; see the attribute's own documentation in `pollora/attributes`. `#[PostMeta]`, `#[TermMeta]`, `#[UserMeta]` and `#[CommentMeta]` declare the meta of a type you do not own.

## Reading and writing

```php
$event = $meta->of(Event::class, $postId);   // MetaAccessor::of(), Meta::of() in a Pollora application

$event->capacity;                            // int
$event->fill(['capacity' => 250, 'soldOut' => true])->save();
$event->set('capacity', 12)->save();         // set(), fill() and save() return the record
```

A write goes through WordPress's meta API, under the declared key. A value that breaks a rule throws `MetaValidationException` and nothing is written; a stored value that cannot be read as its type reads as the property default.

The rules are checked by a `MetaValidatorInterface`. In a Pollora application it is Laravel's validator (`min:0`, `max:5000`, `in:…`, `email`…); without Laravel, pass your own to `MetaAccessor`, or none: the rules are then not checked.

## Schemas and UI drivers

`MetaAccessor::schemas()` and `schemaFor('post', 'event')` give the compiled `MetaSchema`s. A package that builds editing fields implements `MetaUiDriver` and registers it by name with `MetaAccessor::extend('acf', AcfDriver::class)`; `MetaUiDriverConformance::check($driver)` checks it against the contract from any test framework.

## Wiring it outside Pollora

Discover the classes that declare meta, then build the accessor with the same pieces the framework's provider builds:

```php
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaRegistry;
use Pollora\Meta\Infrastructure\Adapters\WordPressMetaStore;
use Pollora\Meta\Infrastructure\Services\MetaDiscovery;

$caster = new MetaValueCaster;
$builder = new MetaSchemaBuilder;
$schemas = new MetaSchemaRepository;

(new DiscoveryEngine)
    ->addLocation(new DiscoveryLocation('MyPlugin\\', __DIR__.'/src'))
    ->addDiscovery('meta', new MetaDiscovery($builder, $schemas, new WordPressMetaRegistry(new Action, $caster)))
    ->run();

$meta = new MetaAccessor($schemas, $builder, new WordPressMetaStore, $caster);
```

The meta are registered with WordPress on `init`. The Eloquent accessors (`HasTypedMeta`), the Laravel validator, the `pollora:meta:*` commands and the `pollora:doctor` check stay in the framework.

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
