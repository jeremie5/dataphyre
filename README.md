![Dataphyre Logo](logo.png)

# Dataphyre Runtime Engine for PHP

> *It knows where it is... because it knows where it isn't.*

Dataphyre is more than a framework — it’s a **modular runtime engine** designed for extreme performance, zero bloat, and real-world scalability. Whether you’re launching a side project or operating a global-scale application, Dataphyre adapts to your needs without refactoring, and without compromise.

---

### 🚀 Proven at Scale

Meet [**Shopiro**](https://shopiro.ca), a global marketplace powered by Dataphyre. With product pages processed in an incredible **30ms**—faster than the blink of an eye—Shopiro proves what’s possible when cutting-edge performance meets world-class scalability.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![Documentation](https://img.shields.io/badge/docs-available-brightgreen)](https://github.com/jeremie5/dataphyre/wiki)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)
[![Contributors](https://img.shields.io/github/contributors/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/graphs/contributors)
[![GitHub stars](https://img.shields.io/github/stars/jeremie5/dataphyre?style=social)](https://github.com/jeremie5/dataphyre/stargazers)

---

## Highlights

- **Live migration diffs**  
  Detect schema changes from your dev database and emit safe, versioned YAML migrations per table.

- **Dynamic unit test generation**  
  Automatically generate test cases from real function calls and type shapes — zero boilerplate.

- **Runtime diagnostics**  
  Inject trace logs, introspect module state, and verify boot sequences with granular tools.

- **Modular boot structure**  
  Shared roots, app-specific overrides, and truly separated module scopes (`common_dataphyre`, `dataphyre`, per-app).

- **Ready for scale**  
  Dataphyre is built to power applications that process billions of requests per day — with no third-party runtime overhead.

---

## Why Dataphyre?

Unlike traditional frameworks, Dataphyre **builds with you**, not against you.  
You don't need to scaffold your app around rigid conventions or dependencies — just write your code.  
The runtime will optimize the rest.

---

## Key Features

- **Infinite Scalability**: Handle any scale without re-architecture.
- **Modular Ecosystem**: Rich modules for caching, templating, jobs, security, and more.
- **Templating System**: Powerful features include:
  - **Layout Inheritance**
  - **Lazy Loading + Scoped Styles**
  - **SEO & Accessibility Compliance**
  - **Flexible Rendering Modes** (sync/async/partial)

- **Advanced Security**:
  - **CASPOW** (anti-spam POW)
  - **Firewall**, **Sanitation**, **2FA**
  - **Role-based Access**

- **Asynchronous Processing**:
  - Promises, coroutines, async tasks, scheduled jobs

---

## Getting Started

### Prerequisites

- **PHP** ≥ 8.1 (JIT recommended)  
- **Composer** for dependency management

### Installation

```bash
git clone https://github.com/jeremie5/dataphyre.git
cd dataphyre
```

---

## Module Ecosystem

### Core & Execution
- **[Core](common/dataphyre/modules/core/documentation/Dataphyre_Core.md)** — Language augments and foundational runtime hooks.
- **[Routing](common/dataphyre/modules/routing/documentation/Dataphyre_Routing.md)** — Clean, dynamic route handling with parameterized logic.
- **[Templating](common/dataphyre/modules/templating/documentation/Dataphyre_Templating.md)** — SEO-focused, async-capable rendering with scoped styles and slotting.
- **[Supercookie](common/dataphyre/modules/supercookie/documentation/Dataphyre_Supercookie.md)** — Secure, flexible JSON session & state system.

### Performance & Async
- **[Async](common/dataphyre/modules/async/documentation/Dataphyre_Async.md)** — Coroutines, promises, and scheduled background tasks.
- **[Cache](common/dataphyre/modules/cache/documentation/Dataphyre_Cache.md)** — Multi-layer distributed cache layer with smart invalidation.
- **[Scheduling](common/dataphyre/modules/scheduling/documentation/Dataphyre_Scheduling.md)** — Dependency-aware cron for precise task orchestration.
- **[Perfstats](common/dataphyre/modules/perfstats/documentation/Dataphyre_Perfstats.md)** — In-app performance analytics and runtime stat tracking.

### Security
- **[CASPOW](common/dataphyre/modules/caspow/documentation/Dataphyre_CASPOW.md)** — Cryptographic anti-spam proof-of-work system.
- **[Firewall](common/dataphyre/modules/firewall/documentation/Dataphyre_Firewall.md)** — Rate limiting, flood protection, CAPTCHA integration.
- **[Sanitation](common/dataphyre/modules/sanitation/documentation/Dataphyre_Sanitation.md)** — Advanced input filtering with injection-proof logic.
- **[Access](common/dataphyre/modules/access/documentation/Dataphyre_Access.md)** — Auth and role/permission management.
- **Googleauthenticator** — Drop-in two-factor authentication via TOTP.

### Data & Search
- **[SQL](common/dataphyre/modules/sql/documentation/Dataphyre_SQL.md)** — Unified DB access layer with queueing, caching, and failover support.
- **[Fulltext Engine](common/dataphyre/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md)** — Advanced search with stemming, tokenization, and multi-backend indexing.
- **[Currency](common/dataphyre/modules/currency/documentation/Dataphyre_Currency.md)** — Currency conversion, formatting, and exchange rate syncing.

### Dev Tools
- **[Tracelog](common/dataphyre/modules/tracelog/documentation/Dataphyre_Tracelog.md)** — In-depth execution tracing with contextual argument tracking.
- **[Dpanel](common/dataphyre/modules/dpanel/documentation/Dataphyre_Dpanel.md)** — Dynamic unit testing with type-shape validation and performance benchmarking.

---

## Contributing

We welcome contributions to Dataphyre!  
Check the [issues tab](https://github.com/jeremie5/dataphyre/issues) or open a new one.  
Please follow our [code of conduct](CODE_OF_CONDUCT.md).

---

## Third-Party Libraries

Dataphyre integrates [**Adminer**](https://www.adminer.org), a lightweight database manager licensed under Apache 2.0.  
License included in the `adminer` directory.

---

## License

Dataphyre is MIT-licensed.  
Some proprietary modules (e.g., Dataphyre CDN, A/B Testing, Stripe, NLP moderation, fraud analysis, sentinel error reporting) are **not** included and power **Shopiro**.  
These may be released later under SaaS or separate licenses. Community alternatives are encouraged!
