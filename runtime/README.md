# Dataphyre Runtime

This directory contains the reusable Dataphyre runtime engine: the bootstrap,
module kernels, framework classes, module documentation, and generated runtime
state used by an installation.

Dataphyre can run as the core of a standalone application or as an embedded
runtime inside a larger product. The runtime is portable; the application and
installation shell around it are expected to vary.

## Requirements

- PHP 8.1 or newer
- Composer for dependency metadata and package workflows

## Directory Shape

```text
runtime/
  bootstrap.php      Runtime entrypoint
  modules/           First-party runtime modules
  cache/             Generated runtime state
  logs/              Generated runtime logs
```

Minimal entrypoint:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

Most installations provide `flight_sheet.php` beside `runtime/` in the
Dataphyre install root for bootstrap settings such as the default application,
application roots, direct-access controls, and Flightdeck settings.

## Runtime Boundary

`runtime/` is framework code. Application behavior should live in application
code, app definitions, install config, callbacks, dialbacks, plugins,
application-owned adapters, or reusable modules before runtime internals are
changed.

Runtime changes are appropriate for framework bugs, public API changes, shared
module behavior, performance work, diagnostics, and documented runtime
capabilities.

## Module Surface

Dataphyre keeps the required boot path small and loads most behavior through
modules:

- Core and execution: Core, HTTP, Routing, MVC, Templating, API, Panel, Reactor.
- Data and storage: SQL, Storage, Cache, Fulltext Engine.
- Security: Access, Permission, CASPOW, Firewall, Sanitation, Supercookie.
- Async and operations: Async, Scheduling, Time Machine, Flightdeck, Dpanel,
  Datadoc, Tracelog, Issue.
- Localization and communication: Localization, Date Translation, Currency,
  Geoposition, Mailer.
- Integrations: MCP, Stripe, Vestra Fabric where installed.

For the current public module list and module documentation links, see the
[module index](../docs/MODULES.md).

## Extension Points

Use the smallest extension surface that owns the behavior:

- Application code for business workflows and product logic.
- Install config for environment-specific module settings.
- Callbacks and dialbacks for explicit runtime hooks.
- Plugins for installation-wide pre-init and post-init behavior.
- Reusable modules for shared framework capabilities.

## Documentation

- [Dataphyre overview](../docs/README.md)
- [Getting started](../docs/GETTING_STARTED.md)
- [Architecture](../docs/ARCHITECTURE.md)
- [Configuration reference](../docs/CONFIGURATION.md)
- [Extension points](../docs/EXTENSION_POINTS.md)
- [MCP server](modules/mcp/documentation/Dataphyre_MCP.md)
- [Testing](../docs/TESTING.md)
- [Module index](../docs/MODULES.md)
- [Stability policy](../docs/STABILITY.md)
- [Support](../docs/SUPPORT.md)

## Third-Party Libraries

Some modules bundle third-party libraries such as Adminer and Stripe PHP. Their
upstream license files are kept with the bundled code.

## License

Dataphyre is released under the MIT License. See [LICENSE](../LICENSE).
