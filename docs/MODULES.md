# Dataphyre Modules

This file maps the current module surface for the public release. Dataphyre can
run embedded in a larger application, so not every module is part of the core
boot path. Most modules are optional and are loaded only when present and enabled
by the installation.

For compatibility guarantees attached to these status labels, see
[STABILITY.md](STABILITY.md).

## Status Legend

- `core`: required runtime infrastructure.
- `optional`: first-party module that can be enabled by an installation.
- `adapter`: integration with a service, vendor, or product-specific API.
- `legacy`: carried forward for compatibility or historical installs.
- `experimental`: present, but needs docs, tests, or public API hardening before
  it is treated as stable.

## Module Index

| Module | Status | Runtime critical | Docs | Purpose |
|---|---|---:|---|---|
| `access` | optional | No | [docs](../runtime/modules/access/documentation/Dataphyre_Access.md) | Authentication, authorization, guards, user providers, and OAuth/JWT helpers. |
| `panel` | optional | No | [docs](../runtime/modules/panel/documentation/Dataphyre_Panel.md) | Resource definitions for generated internal control surfaces built on SQL, Access, and Templating. |
| `aceit_engine` | legacy | No | [docs](../runtime/modules/aceit_engine/documentation/Dataphyre_AceIt_Engine.md) | Legacy experimentation module. It does not use the current `kernel/<module>.main.php` or `Framework/` discovery shape. |
| `api` | optional | No | [docs](../runtime/modules/api/documentation/Dataphyre_Api.md) | API route definitions, security schemes, OpenAPI generation, and Swagger UI support. |
| `async` | optional | No | [docs](../runtime/modules/async/documentation/Dataphyre_Async.md) | Coroutines, promises, process dispatch, queues, and async task orchestration. |
| `caspow` | optional | No | [docs](../runtime/modules/caspow/documentation/Dataphyre_CASPOW.md) | Proof-of-work anti-spam and abuse-control challenge system. |
| `core` | core | Yes | [docs](../runtime/modules/core/documentation/Dataphyre_Core.md) | Runtime bootstrap, module discovery, config, helpers, app location, autoloading, and framework loading. |
| `currency` | optional | No | [docs](../runtime/modules/currency/documentation/Dataphyre_Currency.md) | Money values, exchange rates, conversion, and formatting helpers. |
| `datadoc` | optional | No | [docs](../runtime/modules/datadoc/documentation/Dataphyre_Datadoc.md) | Runtime and source documentation surface. |
| `date_translation` | optional | No | [docs](../runtime/modules/date_translation/documentation/Dataphyre_Date_Translation.md) | Localized date strings and language resources. |
| `dpanel` | optional | No | [docs](../runtime/modules/dpanel/documentation/Dataphyre_Dpanel.md) | Diagnostics, dependency checks, and dynamic unit-test tooling. |
| `firewall` | optional | No | [docs](../runtime/modules/firewall/documentation/Dataphyre_Firewall.md) | Request filtering, abuse controls, rate limits, and security routing support. |
| `flightdeck` | optional | No | [docs](../runtime/modules/flightdeck/documentation/Dataphyre_Flightdeck.md) | Developer control surface for diagnostics and runtime tools. |
| `fulltext_engine` | optional | No | [docs](../runtime/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md) | Search indexing, tokenization, stemming, scoring, and external search adapters. |
| `geoposition` | optional | No | [docs](../runtime/modules/geoposition/documentation/Dataphyre_Geoposition.md) | Geographic lookup and location utilities. |
| `http` | optional | No | [docs](../runtime/modules/http/documentation/Dataphyre_HTTP.md) | Framework HTTP request/response primitives. |
| `issue` | optional | No | [docs](../runtime/modules/issue/documentation/Dataphyre_Issue.md) | Issue/report helpers. |
| `localization` | optional | No | [docs](../runtime/modules/localization/documentation/Dataphyre%20Localization.md) | Locale lookup, generated locale files, and database-backed translation maintenance. |
| `mailer` | optional | No | [docs](../runtime/modules/mailer/documentation/Dataphyre_Mailer.md) | Native email delivery, provider failover, templates, queues, scheduling, webhooks, suppressions, and delivery health snapshots. |
| `mcp` | optional | No | [docs](../runtime/modules/mcp/documentation/Dataphyre_MCP.md) | Local Model Context Protocol server for Dataphyre-aware AI tooling, docs, diagnostics, and route-free verification. |
| `mvc` | optional | No | [docs](../runtime/modules/mvc/documentation/Dataphyre_MVC.md) | Native MVC application layer that composes HTTP, Routing, Templating, and SQL into controllers, route groups, view results, middleware, and lightweight models. |
| `permission` | optional | No | [docs](../runtime/modules/permission/documentation/Dataphyre_Permission.md) | Semantic authorization rules, roles, audits, permission catalogs, Panel authorization, policy gate integration, and optional Laravel adapter support. |
| `reactor` | optional | No | [docs](../runtime/modules/reactor/documentation/Dataphyre_Reactor.md) | Server-driven component lifecycle, signed snapshots, actions, effects, model binding, nested components, and route-agnostic UI islands. |
| `routing` | optional | No | [docs](../runtime/modules/routing/documentation/Dataphyre_Routing.md) | Dynamic and compiled routing support. |
| `sanitation` | optional | No | [docs](../runtime/modules/sanitation/documentation/Dataphyre_Sanitation.md) | Input bags, sanitizer presets, validation, and sanitization results. |
| `scheduling` | optional | No | [docs](../runtime/modules/scheduling/documentation/Dataphyre_Scheduling.md) | Framework task definitions, period helpers, scheduler routes, task runner, and scheduled job orchestration. |
| `sql` | optional | No | [docs](../runtime/modules/sql/documentation/Dataphyre_SQL.md) | Database access, query helpers, records, repositories, transactions, and table scaffolding. |
| `storage` | optional | No | [docs](../runtime/modules/storage/documentation/Dataphyre_Storage.md) | Secure file storage abstraction for local disks, S3-compatible object storage, and thin Vestra reference aliases. |
| `stripe` | adapter | No | [docs](../runtime/modules/stripe/documentation/Dataphyre_Stripe.md) | Stripe integration wrapper around the bundled Stripe PHP client. |
| `supercookie` | optional | No | [docs](../runtime/modules/supercookie/documentation/Dataphyre_Supercookie.md) | JSON cookie-backed session/state helper. |
| `templating` | optional | No | [docs](../runtime/modules/templating/documentation/Dataphyre_Templating.md) | Templates, manifests, rendering, bindings, layouts, and asset policy support. |
| `time_machine` | optional | No | [docs](../runtime/modules/time_machine/documentation/Dataphyre_Time_Machine.md) | Time-aware diagnostics and runtime time helpers. |
| `tracelog` | optional | No | [docs](../runtime/modules/tracelog/documentation/Dataphyre_Tracelog.md) | Execution tracing, handoff traces, and trace viewer support. |
| `vestra` | adapter | No | [docs](../runtime/modules/vestra/documentation/Dataphyre_Vestra_Client.md) | Vestra Fabric client for object references, tenant-aware asset URLs, HTML ingestion, and application usage accounting. |

## Public Release Notes

- `core` is the runtime-critical module loaded by `runtime/bootstrap.php`.
- Most optional modules are discovered by convention from `runtime/modules/<module>/`.
- Application-level modules can override or disable common modules in an embedded
  install.
- Product-specific install/plugin integrations are redacted from public
  Dataphyre release packages and are not baseline dependencies.
- `stripe` and similar modules are adapters. They are documented as opt-in
  integrations with their own configuration.
- Legacy and experimental modules stay clearly marked until their public API,
  schemas, and configuration contracts are stable.

