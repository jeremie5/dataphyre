![Dataphyre Logo](logo.png)

# Dataphyre Runtime Engine for PHP

Dataphyre is a modular PHP runtime engine designed for applications that need a
small core, explicit boot control, and modules that can be enabled around the
needs of each application.

Dataphyre can run as the core of a standalone application or as an embedded
runtime inside a larger product. Shopiro is the production reference deployment,
but the runtime is not tied to Shopiro.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![Documentation](https://img.shields.io/badge/docs-runtime-brightgreen)](documentation/README.md)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)

## Highlights

- Modular boot pipeline with install-level and app-level configuration.
- Runtime modules for routing, templating, SQL, caching, security, async work,
  diagnostics, localization, and operational tooling.
- Compatibility with legacy application bootstraps and newer framework-style
  application definitions.
- Diagnostics through Tracelog, Flightdeck, Datadoc, and Dpanel.
- Extensibility through plugins and core dialbacks.

## Requirements

- PHP 8.1 or newer
- Composer for dependency metadata and package workflows

## Installation Shape

The public repository mirrors a Dataphyre installation:

```text
dataphyre/
  runtime/          reusable runtime engine
  config/           install-level module config
  plugins/          install-level hooks
  cache/            generated runtime state
  logs/             generated runtime logs
  flight_sheet.php  install bootstrap sheet
```

The runtime itself starts at `runtime/bootstrap.php`. The parent directory is an
installation shell that can be different for each application.

Minimal entrypoint:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

For embedded installs, `flight_sheet.php` provides bootstrap settings such as the
default app name, application roots, direct-access controls, and Flightdeck
settings.

For a copyable first-run layout, see the repository-level
[getting-started guide](../GETTING_STARTED.md) and
[minimal embedded example](../examples/minimal/README.md). The install-local
export split is documented in [Public export boundary](../PUBLIC_EXPORT.md).

## Documentation

- [Documentation index](documentation/README.md)
- [Getting started](../GETTING_STARTED.md)
- [Architecture](../ARCHITECTURE.md)
- [Configuration reference](../CONFIGURATION.md)
- [Package contract](../PACKAGE.md)
- [Stability policy](../STABILITY.md)
- [Public export boundary](../PUBLIC_EXPORT.md)
- [Minimal embedded example](../examples/minimal/README.md)
- [Module index](../MODULES.md)
- [Release checklist](../RELEASE_CHECKLIST.md)
- [Support](../SUPPORT.md)
- [Extensibility](documentation/Dataphyre%20Extensibility.md)
- [Failure modes](documentation/Dataphyre%20Failure%20Modes.md)
- [MVC architecture](documentation/Dataphyre%20MVC%20Architecture.md)
- [Private keys and security](documentation/Dataphyre%20Private%20Keys%20And%20Security.md)

## Documented Modules

### Core & Execution

- [Core](modules/core/documentation/Dataphyre_Core.md) - foundational runtime hooks.
- [Routing](modules/routing/documentation/Dataphyre_Routing.md) - dynamic route handling.
- [Templating](modules/templating/documentation/Dataphyre_Templating.md) - rendering, layouts, and data bindings.
- [API](modules/api/documentation/Dataphyre_Api.md) - API routing, OpenAPI metadata, and request handling.
- [HTTP](modules/http/documentation/Dataphyre_HTTP.md) - HTTP request and response primitives.

### Performance & Async

- [Async](modules/async/documentation/Dataphyre_Async.md) - coroutines, promises, and background tasks.
- [Cache](modules/cache/documentation/Dataphyre_Cache.md) - Memcached-backed runtime cache helpers.
- [Scheduling](modules/scheduling/documentation/Dataphyre_Scheduling.md) - scheduled job orchestration.
- [Time Machine](modules/time_machine/documentation/Dataphyre_Time_Machine.md) - time-aware runtime utilities.

### Security

- [Access](modules/access/documentation/Dataphyre_Access.md) - auth and role/permission management.
- [CASPOW](modules/caspow/documentation/Dataphyre_CASPOW.md) - proof-of-work anti-spam controls.
- [Firewall](modules/firewall/documentation/Dataphyre_Firewall.md) - request filtering and abuse protection.
- [Sanitation](modules/sanitation/documentation/Dataphyre_Sanitation.md) - input filtering and validation helpers.
- [Supercookie](modules/supercookie/documentation/Dataphyre_Supercookie.md) - JSON cookie-backed state helper.

### Data, Search & Localization

- [SQL](modules/sql/documentation/Dataphyre_SQL.md) - database access, repositories, and migration helpers.
- [Fulltext Engine](modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md) - search indexing and tokenization.
- [Currency](modules/currency/documentation/Dataphyre_Currency.md) - currency conversion and formatting.
- [Date Translation](modules/date_translation/documentation/Dataphyre_Date_Translation.md) - localized date strings.
- [Localization](modules/localization/documentation/Dataphyre%20Localization.md) - translation helpers.
- [Geoposition](modules/geoposition/documentation/Dataphyre_Geoposition.md) - geographic lookup utilities.

### Dev & Operations

- [Datadoc](modules/datadoc/documentation/Dataphyre_Datadoc.md) - function and module documentation tools.
- [Dpanel](modules/dpanel/documentation/Dataphyre_Dpanel.md) - diagnostics and dynamic testing.
- [Flightdeck](modules/flightdeck/documentation/Dataphyre_Flightdeck.md) - developer control surface.
- [Issue](modules/issue/documentation/Dataphyre_Issue.md) - issue/report helpers.
- [Log Viewer](modules/log_viewer/documentation/Dataphyre_Log_Viewer.md) - legacy standalone log tail.
- [InternalModule](***REMOVED***/documentation/Dataphyre_InternalModule.md) - experimental event reporting.
- [Tracelog](modules/tracelog/documentation/Dataphyre_Tracelog.md) - execution tracing.

### Service Adapters

- [CJ Dropshipping adapter](modules/private_adapter/documentation/Dataphyre_PrivateAdapter.md)
- [PrivateAdapter adapter](modules/private_adapter/documentation/Dataphyre_PrivateAdapter.md)
- [PolicyModule](modules/policy_module/documentation/Dataphyre_PolicyModule.md)
- [Stripe](modules/stripe/documentation/Dataphyre_Stripe.md)

## Legacy And Experimental Modules

Some modules are present for compatibility or operational experimentation. They
have compact release notes but should stay clearly marked until their public API,
schemas, and configuration contracts are stable:

- [AceIt Engine](modules/aceit_engine/documentation/Dataphyre_AceIt_Engine.md)
- [InternalModule](***REMOVED***/documentation/Dataphyre_InternalModule.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local workflow notes and pull request
guidance.

## Third-Party Libraries

Dataphyre bundles a small number of third-party libraries in module directories,
including Adminer and Stripe PHP. Their upstream license files are kept with the
bundled code.

## License

Dataphyre is released under the MIT License. See [LICENSE](LICENSE).
