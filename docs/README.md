![Dataphyre Logo](../runtime/logo.png)

# Dataphyre

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)

Dataphyre is a modular PHP framework and runtime for building internal tools,
SaaS control planes, admin panels, APIs, server-rendered apps, and agent-built
business systems. It combines a small portable runtime with first-party modules
for routing, MVC, Panel resources, SQL, Storage, permissions, diagnostics,
Reactor components, and MCP-powered application planning.

Dataphyre is built for teams that want AI agents to help create and maintain
applications without turning every feature into a framework edit. Agents can
inspect safe metadata, generate app-owned scaffold plans, follow explicit
extension boundaries, and verify the application surface they changed.

## Why Dataphyre

- Build application surfaces directly: Panel resources, APIs, MVC routes,
  server-driven Reactor islands, SQL-backed records, storage-backed assets, and
  permission-aware workflows.
- Give agents practical rails: the MCP server can inspect modules, routes,
  schemas, docs, diagnostics, and scaffold plans without dispatching arbitrary
  application code by default.
- Keep ownership obvious: product behavior belongs in application code, config,
  callbacks, dialbacks, plugins, app-owned adapters, or reusable modules before
  runtime internals.
- Use only the runtime pieces you need: enable HTTP, Routing, MVC, API, Panel,
  SQL, Storage, Cache, Access, Permission, Mailer, Localization, Reactor,
  diagnostics, MCP, and integrations as the application grows.
- Run embedded or standalone: use Dataphyre as the core of a product, or embed
  the runtime inside a larger PHP application.

## What Is Included

```text
runtime/                  Dataphyre runtime engine and first-party modules
docs/                     Public documentation
config/                   Install-level configuration examples
plugins/                  Install-level extension hooks
examples/minimal/         Minimal embedded application example
flight_sheet.example.php  Public bootstrap template
```

## Start Here

- [Getting started](GETTING_STARTED.md)
- [Runtime README](../runtime/README.md)
- [Module index](MODULES.md)
- [Configuration reference](CONFIGURATION.md)
- [CLI reference](CLI.md)
- [Extension points](EXTENSION_POINTS.md)
- [Testing](TESTING.md)
- [Minimal embedded example](../examples/minimal/README.md)

## Documentation

- [Architecture](ARCHITECTURE.md)
- [Package contract](PACKAGE.md)
- [CLI reference](CLI.md)
- [Stability policy](STABILITY.md)
- [Testing](TESTING.md)
- [MCP server](../runtime/modules/mcp/documentation/Dataphyre_MCP.md)
- [Security policy](SECURITY.md)
- [Support](SUPPORT.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Third-party notices](THIRD_PARTY_NOTICES.md)
- [License](../LICENSE)

## License

Dataphyre is released under the MIT License. Third-party libraries bundled under
specific modules retain their own license files.
