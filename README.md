# Dataphyre

Dataphyre is a modular PHP runtime engine. This repository layout mirrors a live
Dataphyre installation: the reusable engine lives in `runtime/`, while the
top-level folders hold install-specific configuration, plugins, cache state, and
bootstrap settings.

For a first-run path, start with [Getting started](GETTING_STARTED.md) or the
[minimal embedded example](examples/minimal/README.md). For framework
documentation, see the [runtime README](runtime/README.md) and the
[documentation index](runtime/documentation/README.md).

## Repository Layout

```text
runtime/                  Dataphyre runtime engine, modules, docs, and public metadata
config/                   Install-level module configuration and public examples
plugins/                  Install-level pre-init and post-init extension hooks
cache/                    Generated runtime state
logs/                     Generated runtime logs
flight_sheet.example.php  Public install bootstrap template
flight_sheet.php          Local install bootstrap sheet, ignored for public export
```

## Runtime Boundary

`runtime/` is the portable core. It contains `bootstrap.php`, module kernels,
framework classes, and module-level documentation. Keeping this boundary explicit
lets Dataphyre run embedded inside a larger application while still presenting a
clear public project shape.

The parent directory is the installation shell. It is expected to vary per
application and may contain local config, generated state, and deployment
settings.

For the public/export split, see [Public export boundary](PUBLIC_EXPORT.md).

## Getting Started

Requirements:

- PHP 8.1 or newer
- Composer, if you want dependency metadata or future package tooling

Minimal bootstrap path:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

Most applications should provide a `flight_sheet.php` at the installation root
and one or more application roots through the bootstrap configuration. See the
[getting-started guide](GETTING_STARTED.md) for the full minimal file shape.

## Documentation

- [Runtime overview](runtime/README.md)
- [Getting started](GETTING_STARTED.md)
- [Architecture](ARCHITECTURE.md)
- [Configuration reference](CONFIGURATION.md)
- [Package contract](PACKAGE.md)
- [Stability policy](STABILITY.md)
- [Public export boundary](PUBLIC_EXPORT.md)
- [Third-party notices](THIRD_PARTY_NOTICES.md)
- [Minimal embedded example](examples/minimal/README.md)
- [Module index](MODULES.md)
- [Documentation index](runtime/documentation/README.md)
- [Release checklist](RELEASE_CHECKLIST.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Support](SUPPORT.md)
- [Security policy](SECURITY.md)
- [License](LICENSE)

## Verification

Run the release surface checks with PowerShell:

```powershell
./tools/release_check
```

The script checks module documentation coverage, local Markdown links, JSON
fixtures, stale license wording, and MIT/SPDX headers for Dataphyre-owned PHP
files.

After preparing a sanitized public export tree, run:

```powershell
./tools/check_export -Root C:\path\to\dataphyre-export
```

The export check uses `.distignore` to catch local install files and scans for
high-confidence secret markers.

To build that sanitized tree from the embedded working copy:

```powershell
./tools/prepare_export -Output C:\path\to\dataphyre-export
```

The prepare script copies the public surface, applies `.distignore`, then runs
the public export and release checks against the output.

Lint real PHP files with:

```powershell
./tools/lint_php.ps1
```

CI runs the same release checks, public export checks, export preparation smoke
test, Composer metadata validation, and PHP linting on the public checkout.

## License

Dataphyre is released under the MIT License. Third-party libraries bundled under
specific modules retain their own license files.
