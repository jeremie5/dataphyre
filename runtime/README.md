# Dataphyre Runtime Engine for PHP

Dataphyre is a modular PHP runtime engine designed for applications that need a
small core, explicit boot control, and modules that can be enabled around the
needs of each application.

Dataphyre can run as the core of a standalone application or as an embedded
runtime inside a larger product. Shopiro is the production reference deployment,
but the runtime is not tied to Shopiro.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)

## Highlights

- Modular boot pipeline with install-level and app-level configuration.
- Runtime modules for routing, templating, SQL, caching, security, async work,
  diagnostics, localization, and operational tooling.
- Compatibility with legacy application bootstraps and newer framework-style
  application definitions.
- Server-driven component lifecycles through Reactor and native application
  structure through MVC.
- Diagnostics through Tracelog, Flightdeck, Datadoc, and Dpanel.
- AI development tooling through the Dataphyre MCP server.
- Extensibility through plugins and core dialbacks.

## Requirements

- PHP 8.1 or newer
- Composer for dependency metadata and package workflows

## Runtime Directory

This directory contains the reusable runtime engine inside a Dataphyre
installation:

```text
runtime/
  bootstrap.php      runtime entrypoint
  modules/           first-party runtime modules
  cache/             generated runtime state
  logs/              generated runtime logs
```

The project root around this directory holds install-level config, plugins,
generated state, examples, package metadata, and release documentation. See the
repository-level [README](../docs/README.md) for that public project shape.

Minimal entrypoint:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

For embedded installs, `flight_sheet.php` provides bootstrap settings such as the
default app name, application roots, direct-access controls, and Flightdeck
settings.

## Application-Agent Boundary

Application agents should treat `runtime/` as the reusable Dataphyre engine boundary.
Put application-specific behavior in application code, app definitions, install config,
dialbacks, callbacks, plugins, MCP metadata, application-owned adapters, or
reusable modules before proposing runtime-internal edits.

For a copyable first-run layout, see the repository-level
[getting-started guide](../docs/GETTING_STARTED.md) and
[minimal embedded example](../examples/minimal/README.md).

## Documentation

- [Getting started](../docs/GETTING_STARTED.md)
- [Architecture](../docs/ARCHITECTURE.md)
- [Configuration reference](../docs/CONFIGURATION.md)
- [Package contract](../docs/PACKAGE.md)
- [Stability policy](../docs/STABILITY.md)
- [Minimal embedded example](../examples/minimal/README.md)
- [Module index](../docs/MODULES.md)
- [Support](../docs/SUPPORT.md)

## Documented Modules

### Core & Execution

- [Core](modules/core/documentation/Dataphyre_Core.md) - foundational runtime hooks.
- [HTTP](modules/http/documentation/Dataphyre_HTTP.md) - HTTP request and response primitives.
- [Routing](modules/routing/documentation/Dataphyre_Routing.md) - dynamic route handling.
- [MVC](modules/mvc/documentation/Dataphyre_MVC.md) - native controllers, route groups, view results, middleware, and lightweight models.
- [Templating](modules/templating/documentation/Dataphyre_Templating.md) - rendering, layouts, and data bindings.
- [API](modules/api/documentation/Dataphyre_Api.md) - API routing, OpenAPI metadata, and request handling.
- [Panel](modules/panel/documentation/Dataphyre_Panel.md) - resource definitions for generated internal control surfaces.
- [Reactor](modules/reactor/documentation/Dataphyre_Reactor.md) - server-driven component lifecycle and UI island transport.

### Performance & Async

- [Async](modules/async/documentation/Dataphyre_Async.md) - coroutines, promises, and background tasks.
- [Cache](modules/cache/documentation/Dataphyre_Cache.md) - Memcached-backed runtime cache helpers.
- [Scheduling](modules/scheduling/documentation/Dataphyre_Scheduling.md) - scheduled job orchestration.
- [Time Machine](modules/time_machine/documentation/Dataphyre_Time_Machine.md) - time-aware runtime utilities.

### Security

- [Access](modules/access/documentation/Dataphyre_Access.md) - auth and role/permission management.
- [Permission](modules/permission/documentation/Dataphyre_Permission.md) - semantic authorization rules, roles, audits, and Panel permission catalogs.
- [CASPOW](modules/caspow/documentation/Dataphyre_CASPOW.md) - proof-of-work anti-spam controls.
- [Firewall](modules/firewall/documentation/Dataphyre_Firewall.md) - request filtering and abuse protection.
- [Sanitation](modules/sanitation/documentation/Dataphyre_Sanitation.md) - input filtering and validation helpers.
- [Supercookie](modules/supercookie/documentation/Dataphyre_Supercookie.md) - JSON cookie-backed state helper.

### Data, Search & Localization

- [SQL](modules/sql/documentation/Dataphyre_SQL.md) - database access, repositories, and migration helpers.
- [Storage](modules/storage/documentation/Dataphyre_Storage.md) - file storage disks for local, Vestra, and S3-compatible providers.
- [Fulltext Engine](modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md) - search indexing and tokenization.
- [Currency](modules/currency/documentation/Dataphyre_Currency.md) - currency conversion and formatting.
- [Date Translation](modules/date_translation/documentation/Dataphyre_Date_Translation.md) - localized date strings.
- [Localization](modules/localization/documentation/Dataphyre%20Localization.md) - translation helpers.
- [Geoposition](modules/geoposition/documentation/Dataphyre_Geoposition.md) - geographic lookup utilities.
- [Mailer](modules/mailer/documentation/Dataphyre_Mailer.md) - email delivery, provider failover, queues, templates, webhooks, and suppressions.

### Dev & Operations

- [Datadoc](modules/datadoc/documentation/Dataphyre_Datadoc.md) - function and module documentation tools.
- [Dpanel](modules/dpanel/documentation/Dataphyre_Dpanel.md) - diagnostics and dynamic testing.
- [Flightdeck](modules/flightdeck/documentation/Dataphyre_Flightdeck.md) - developer control surface.
- [MCP](modules/mcp/documentation/Dataphyre_MCP.md) - Dataphyre-aware MCP tools, resources, prompts, and guarded local diagnostics.
- [Issue](modules/issue/documentation/Dataphyre_Issue.md) - issue/report helpers.
- [Tracelog](modules/tracelog/documentation/Dataphyre_Tracelog.md) - execution tracing.

### Service Adapters

- [Stripe](modules/stripe/documentation/Dataphyre_Stripe.md)

## Legacy And Experimental Modules

Some modules are present for compatibility or operational experimentation. They
have compact release notes and stay clearly marked until their public API,
schemas, and configuration contracts are stable:

- [AceIt Engine](modules/aceit_engine/documentation/Dataphyre_AceIt_Engine.md)
## Contributing

See [CONTRIBUTING.md](../docs/CONTRIBUTING.md) for local workflow notes and pull request
guidance.

## Third-Party Libraries

Dataphyre bundles a small number of third-party libraries in module directories,
including Adminer and Stripe PHP. Their upstream license files are kept with the
bundled code.

## License

Dataphyre is released under the MIT License. See [LICENSE](../LICENSE).
