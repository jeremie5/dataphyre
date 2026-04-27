# Package Contract

Dataphyre publishes Composer metadata so the project can be identified,
validated, and consumed as a PHP package. The runtime does not yet expose a
complete Composer autoload surface.

## Runtime Entrypoint

Boot Dataphyre explicitly:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

The bootstrap file resolves `flight_sheet.php`, locates the configured
application, registers Dataphyre's runtime autoloader, and then hands control to
the selected application.

## Composer Autoload

`composer.json` intentionally avoids a PSR-4 `autoload` section for now.
Dataphyre's current class surface mixes:

- legacy kernel files with filenames that do not always map to class names;
- module-local namespaces that are registered from discovered module roots;
- framework namespaces under `runtime/modules/*/Framework`;
- application-level namespace prefixes declared by each application definition.

Adding a partial Composer autoload map would make package consumers think the
runtime can be used by requiring `vendor/autoload.php` alone. That is not true
yet. The stable contract is still the explicit runtime bootstrap.

## Public Metadata

The Composer package metadata declares:

- package name: `dataphyre/dataphyre`
- license: MIT
- runtime requirement: PHP 8.1 or newer
- support links for source, issues, security policy, and documentation

The package is currently best treated as an embedded runtime distribution rather
than a drop-in library dependency.

See [Stability policy](STABILITY.md) for the public compatibility promise.

## Future Autoload Work

A future package release can add Composer autoloading once the public class
surface is normalized. That work should include:

- aligning kernel class names and filenames;
- deciding which legacy globals remain public API;
- exposing framework namespaces consistently;
- keeping module discovery behavior compatible with embedded installs;
- adding tests that boot through both Composer and direct runtime entrypoints.

Until then, use [Getting started](GETTING_STARTED.md) and
[Architecture](ARCHITECTURE.md) as the source of truth for boot behavior.
