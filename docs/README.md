![Dataphyre Logo](../runtime/logo.png)

# Dataphyre

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)

Dataphyre is a modular PHP runtime engine. This repository layout mirrors a live
Dataphyre installation: the reusable engine lives in `runtime/`, while the
top-level folders hold install-specific configuration, plugins, cache state, and
bootstrap settings.

For a first-run path, start with [Getting started](GETTING_STARTED.md) or the
[minimal embedded example](../examples/minimal/README.md). For framework
documentation, see the [runtime README](../runtime/README.md).

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

Prepared public releases include runtime code, public documentation, examples,
and `RELEASE_MANIFEST.json`.

## Extension Boundary

Applications and agents should treat Dataphyre as framework source, not as an
application patch surface. App-specific behavior should use install config,
dialbacks, callbacks, plugin hooks, and reusable modules. Core runtime edits are
for Dataphyre framework development: bug fixes, public API changes, performance
work, and documented runtime capabilities.

## Agentic-First Enterprise Value

Dataphyre is shaped for corporate teams that expect AI agents to inspect, plan,
and verify application work without opening a heavyweight governance process for
every change. The default path is safe metadata, compact app-owned plans,
explicit ownership and redaction decisions, dry-run/package manifests, and
focused verification. Maintainer release gates, unsafe MCP mode, aggregate
validation, and benchmarks stay reserved for framework, release-surface,
security, or shared hot-path claims.

The normal MCP audience is application agents building apps with Dataphyre, not
agents improving Dataphyre itself. Released guidance should keep that path
lightweight: read safe metadata, plan app-owned changes, and verify the affected
app or module before escalating to broader framework-maintenance work.
For ordinary app creation, start MCP work with
`dataphyre_app_builder_plan_generate` using `payload_profile=compact`, then read
`builder_response.first_read.next_action` before opening broader context.
Compact handoffs keep immediate write readiness and focused verification
machine-readable, while larger planning, implementation, verification,
controls, and governance bodies are fetched one page at a time with
`detail_page=planning|implementation|verification|controls|governance`.
Preserve `prewrite_checklist.implementation_obligations`,
`verification_handoff`, and the named detail-page refs across handoffs so
app-owned relationship, field metadata, contract, and focused verification work
is not lost between agents.

## Runtime Surface

Dataphyre keeps a small required boot path and loads most capabilities as
optional modules. The current runtime surface includes HTTP, Routing, MVC,
Templating, SQL, Storage, Cache, Async, Reactor, Panel, Access, Permission,
Mailer, Localization, MCP, Flightdeck, Dpanel, Datadoc, Tracelog, and opt-in
adapters such as Stripe.

For module status, documentation links, and public-release notes, see the
[module index](MODULES.md).

## Getting Started

Requirements:

- PHP 8.1 or newer
- Composer, if you want dependency metadata or future package workflows

Minimal bootstrap path:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

Most applications provide a `flight_sheet.php` at the installation root and one
or more application roots through the bootstrap configuration. See the
[getting-started guide](GETTING_STARTED.md) for the full minimal file shape.

## Documentation

- [Runtime overview](../runtime/README.md)
- [Getting started](GETTING_STARTED.md)
- [Agentic enterprise contract](AGENTIC_ENTERPRISE.md)
- [Architecture](ARCHITECTURE.md)
- [Configuration reference](CONFIGURATION.md)
- [Package contract](PACKAGE.md)
- [Release manifest](RELEASE_MANIFEST.md)
- [Release manifest JSON Schema](RELEASE_MANIFEST.schema.json)
- [Stability policy](STABILITY.md)
- [Third-party notices](THIRD_PARTY_NOTICES.md)
- [Minimal embedded example](../examples/minimal/README.md)
- [Module index](MODULES.md)
- [MCP server](../runtime/modules/mcp/documentation/Dataphyre_MCP.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Support](SUPPORT.md)
- [Security policy](SECURITY.md)
- [License](../LICENSE)

## Release Verification

Prepared public releases ship `RELEASE_MANIFEST.json` with non-sensitive export
counts, public module inventory, bundled component inventory, per-file hashes, a
deterministic tree hash, omitted artifact categories, app-agent boundary fields,
and verification provenance. See the [release manifest schema](RELEASE_MANIFEST.md)
for field definitions.

Application agents building apps should verify the app or module they change
with focused app-owned checks.

## License

Dataphyre is released under the MIT License. Third-party libraries bundled under
specific modules retain their own license files.


