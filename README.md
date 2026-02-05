![Dataphyre Logo](logo.png)

# Dataphyre

Dataphyre is the runtime engine behind Shopiro.ca.

It is a collection of modules created to make a very large PHP application observable, testable, diagnosable, and safe to evolve.

Dataphyre is not an MVC framework. It does not try to abstract PHP. It provides tools to understand and control what an application is doing at runtime.

**License: MIT**

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![Documentation](https://img.shields.io/badge/docs-available-brightgreen)](https://github.com/jeremie5/dataphyre/wiki)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)
[![Contributors](https://img.shields.io/github/contributors/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/graphs/contributors)
[![GitHub stars](https://img.shields.io/github/stars/jeremie5/dataphyre?style=social)](https://github.com/jeremie5/dataphyre/stargazers)

---

## Why Dataphyre Exists

While building Shopiro, several problems appeared that traditional frameworks did not solve well:

* Knowing exactly what happened during a request
* Refactoring safely in a multi-million line codebase
* Managing localization across hundreds of pages and languages
* Making routing expressive without becoming fragile
* Handling SQL, caching, and diagnostics in a predictable way

Dataphyre is the result of solving those problems directly.

---

## Repository Structure

Dataphyre is designed to support shared runtime and app-specific overrides.

```
common_dataphyre/   → shared runtime modules
dataphyre/          → app-specific config and overrides
```

Modules are independent and optional.
Documentation for each module lives inside its folder.

---

## What Dataphyre Provides

Rather than a framework, Dataphyre provides runtime systems for:

* Request tracing and diagnostics
* Dynamic unit test generation
* Localization learning and syncing
* Parameter-driven routing
* SQL abstraction with caching and failover support
* Modular security components
* Async task scheduling

---

## Getting Started

### Prerequisites

* PHP ≥ 8.1
* Composer

### Installation

```
git clone https://github.com/jeremie5/dataphyre.git
cd dataphyre
```

---

## Modules

### Core & Execution

* **[Core](common/dataphyre/modules/core/documentation/Dataphyre_Core.md)** — foundational runtime hooks
* **[Routing](common/dataphyre/modules/routing/documentation/Dataphyre_Routing.md)** — parameter-aware routing system
* **[Templating](common/dataphyre/modules/templating/documentation/Dataphyre_Templating.md)** — layout inheritance, scoped styles, async rendering
* **[Supercookie](common/dataphyre/modules/supercookie/documentation/Dataphyre_Supercookie.md)** — JSON session and state handling

### Performance & Async

* **[Async](common/dataphyre/modules/async/documentation/Dataphyre_Async.md)** — background tasks and scheduling
* **[Cache](common/dataphyre/modules/cache/documentation/Dataphyre_Cache.md)** — Memcached interface
* **[Scheduling](common/dataphyre/modules/scheduling/documentation/Dataphyre_Scheduling.md)** — dependency-aware cron
* **[Perfstats](common/dataphyre/modules/perfstats/documentation/Dataphyre_Perfstats.md)** — runtime performance tracking

### Security

* **[CASPOW](common/dataphyre/modules/caspow/documentation/Dataphyre_CASPOW.md)** — anti-spam proof-of-work
* **[Firewall](common/dataphyre/modules/firewall/documentation/Dataphyre_Firewall.md)** — rate limiting and flood protection
* **[Sanitation](common/dataphyre/modules/sanitation/documentation/Dataphyre_Sanitation.md)** — input filtering
* **[Access](common/dataphyre/modules/access/documentation/Dataphyre_Access.md)** — auth and permissions
* **Googleauthenticator** — TOTP 2FA

### Data & Search

* **[SQL](common/dataphyre/modules/sql/documentation/Dataphyre_SQL.md)** — DB abstraction with queueing and caching
* **[Fulltext Engine](common/dataphyre/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md)** — multi-backend search engine
* **[Currency](common/dataphyre/modules/currency/documentation/Dataphyre_Currency.md)** — currency handling and formatting

### Developer Tools

* **[Tracelog](common/dataphyre/modules/tracelog/documentation/Dataphyre_Tracelog.md)** — full request execution tracing
* **[Dpanel](common/dataphyre/modules/dpanel/documentation/Dataphyre_Dpanel.md)** — dynamic unit testing and diagnostics

---

## Contributing

Contributions are welcome.
See the issues tab or open a new one.

---

## Third-Party Libraries

Dataphyre includes **Adminer** (Apache 2.0) in the `adminer` directory.

---

## License

Dataphyre is MIT-licensed.
The Dataphyre trademark may not be used to endorse derived products.

Which is exactly the perception you need before they open any module file.
