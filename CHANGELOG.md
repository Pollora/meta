# Changelog

All notable changes to `pollora/meta` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A meta declared with `#[Meta(revisions: true)]` on a post type that does not support `revisions` was silently not registered (`register_meta()` returns `false`, and its `_doing_it_wrong()` notice is hidden without `WP_DEBUG`). Registration now throws an `InvalidMetaDefinitionException` naming the class, the property and the post type, before any meta of the declaration is registered (#32)

## [0.1.2] - 2026-11-23

### Changed
- Accepts `pollora/discovery` ^0.3 (`^0.1 || ^0.2 || ^0.3 || ^1.0`)

## [0.1.1] - 2026-11-19

### Changed

- The tests run against `pollora/colt` 11 (`require-dev` `^11.0`, it was `^10.0.1`). The package does not require Colt: it only registers a Colt model when one is installed
- Accepts `pollora/discovery` 0.2 (`^0.1 || ^0.2 || ^1.0`)

## [0.1.0] - 2026-10-15

### Added

- The typed meta classes of the Pollora framework as a package of their own, class names unchanged: `MetaAccessor` (`of()`, `schemas()`, `schemaFor()`, `extend()`), the fluent `MetaRecord` (`set()`, `fill()` and `save()` return the record), `MetaSchemaBuilder`, `MetaSchemaRepository`, `MetaAuditor`, `MetaUiDrivers`, the value caster, the WordPress adapters (`register_meta()`, the meta API, the REST validation of rules, the inventory of stored rows), `MetaDiscovery` and `MetaUiDriverConformance`
- The ports that bind them to an environment: `MetaStoreInterface`, `MetaRegistryInterface`, `MetaInventoryInterface`, `MetaValidatorInterface` and `MetaUiDriver`. The framework implements the validator with Laravel's
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. A UI driver is built by any PSR-11 container
