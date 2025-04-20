![Dataphyre Logo](logo.png)

# The Ultimate PHP Framework

> Why reinvent the wheel when you can just make the wheel faster.

Dataphyre is a blazing-fast, modular PHP framework built for real-world scale. Whether you're building a **simple web app** or a **global distributed system**, Dataphyre adapts to your needs—no refactoring, no bloat, no limits.

### **Proven at Scale**  
Meet [**Shopiro**](https://shopiro.ca), a global marketplace powered by Dataphyre. With product pages processed in an incredible **30ms**—faster than the blink of an eye— Shopiro proves what’s possible when cutting-edge performance meets world-class scalability.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
![GitHub License](https://img.shields.io/github/license/jeremie5/dataphyre)
[![Documentation](https://img.shields.io/badge/docs-available-brightgreen)](https://github.com/jeremie5/dataphyre/wiki)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)
[![Contributors](https://img.shields.io/github/contributors/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/graphs/contributors)
[![GitHub stars](https://img.shields.io/github/stars/jeremie5/dataphyre?style=social)](https://github.com/jeremie5/dataphyre/stargazers)

### Key Features
- **Infinite Scalability**: Built from the ground up to handle any level of scale, no matter how complex.
- **Modular Ecosystem**: Comprehensive modules for everything from authentication to caching, asynchronous tasks, and a powerful templating system.
- **Templating System**: Dynamic, efficient rendering with support for caching, debugging, SEO, accessibility, component management, and conditional parsing. Templating in Dataphyre enables:
  - **Layout Inheritance**: Easily extend base layouts to maintain consistency and flexibility.
  - **Lazy Loading and Scoped Styles**: Optimize component loading times and style management.
  - **SEO and Accessibility**: Ensure your templates meet modern SEO and accessibility standards effortlessly.
  - **Flexible Rendering Options**: Full, async, and fallback render modes, along with custom slots, dynamic imports, and partials for complex templates.
- **Advanced Security**: Includes unique tools like **Caspow** (Cryptographic Anti-Spam Proof of Work) to secure your platform against spam and malicious bots.
- **Asynchronous Processing**: High-performance async task handling for background jobs, scheduled tasks, and more.

## Disclaimer
Dataphyre isn’t just a framework—it’s an ideology. Contributions are welcome, but must align with our core values: performance first, no bloat, no compromise on scalability.

## Getting Started

### Prerequisites

Before you start, make sure you have the following installed:

- **PHP** ≥ 8.1 (JIT recommended)
- **Composer** for dependency management

Make sure to verify prerequisites for each Dataphyre module you will add to your project.

### Installation

1. Clone the repository to your local environment:

   ```bash
   git clone https://github.com/jeremie5/dataphyre.git
   ```

2. Navigate into the project directory:

   ```bash
   cd dataphyre
   ```
---

## **Module Ecosystem**
Explore Dataphyre's powerful modules, designed to handle complex application needs efficiently.

### **Core Framework**
- **[Core](/common/dataphyre/modules/core/documentation/Dataphyre_Core.md):** The backbone of Dataphyre, providing essential language augmentations and core functionalities.

### **Performance and Scalability**
- **[Async](/common/dataphyre/modules/async/documentation/Dataphyre_Async.md):** High-performance background job processing and task scheduling with Promises and Coroutines.
- **[Cache](/common/dataphyre/modules/cache/documentation/Dataphyre_Cache.md):** Distributed caching to minimize database load and accelerate web applications.

### **Security**
- **[CASPOW](/common/dataphyre/modules/caspow/documentation/Dataphyre_CASPOW.md):** Mitigate spam and DDoS attacks using cryptographic challenges with customizable difficulty.
- **[Firewall](/common/dataphyre/modules/firewall/documentation/Dataphyre_Firewall.md):** Prevent flooding, rate-limit requests, and integrate CAPTCHA for robust application security.
- **[Sanitation](/common/dataphyre/modules/sanitation/documentation/Dataphyre_Sanitation.md):** Safeguard data integrity and prevent injection attacks with advanced sanitization techniques.
- **Googleauthenticator:** Easily integrate two-factor authentication via Google Authenticator.
- **[Access](/common/dataphyre/modules/access/documentation/Dataphyre_Access.md):** Securely manage user authentication and authorization across your application.

### **Automation and Analytics**
- **[Perfstats](/common/dataphyre/modules/perfstats/documentation/Dataphyre_Perfstats.md):** Real-time performance analytics to monitor and optimize your application.
- **[Scheduling](/common/dataphyre/modules/scheduling/documentation/Dataphyre_Scheduling.md):** Automate and manage complex tasks with flexible schedules and dependency handling.

### **Search and Data Handling**
- **[Fulltext Engine](/common/dataphyre/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md):** Advanced search capabilities with tokenization, stemming, and multi-backend support (e.g., Elasticsearch).
- **[SQL](/common/dataphyre/modules/sql/documentation/Dataphyre_SQL.md):** Simplified and secure database interactions with dynamic query building and robust error handling.
- **[Currency](/common/dataphyre/modules/currency/documentation/Dataphyre_Currency.md):** Streamline global currency handling with automatic exchange rates and localized formatting.

### **Development Tools**
- **[Tracelog](/common/dataphyre/modules/tracelog/documentation/Dataphyre_Tracelog.md):** Debug and monitor your application with detailed execution traces and visualizations.
- **[Dpanel](/common/dataphyre/modules/dpanel/documentation/Dataphyre_Dpanel.md):** Diagnose, unit test, and validate your Dataphyre modules and project(s) using dynamic JSON test cases with deep structure validation, performance checks, and automated dependency resolution.

### **User Experience**
- **[Templating](/common/dataphyre/modules/templating/documentation/Dataphyre_Templating.md):** Dynamic template rendering with caching, SEO, and accessibility built in.
- **[Routing](/common/dataphyre/modules/routing/documentation/Dataphyre_Routing.md):** Flexible routing with dynamic URL patterns, custom responses, and parameter handling.

### **Content and State Management**
- **[Supercookie](/common/dataphyre/modules/supercookie/documentation/Dataphyre_Supercookie.md):** Manage session and state data with a secure, JSON-based cookie system.

---

## Contributing

We welcome contributions to Dataphyre! Please check the issues tab for current open tasks or feel free to open new issues. When contributing, please follow our [code of conduct](CODE_OF_CONDUCT.md).

## Third-Party Libraries

Dataphyre also integrates Adminer, a lightweight database management tool, for seamless SQL interaction and debugging. Adminer is open-source software licensed under the Apache License 2.0, and its compact nature makes it a reliable choice for managing databases within Dataphyre SQL. A copy of the license can be found in the `adminer` directory.

## License

Dataphyre is MIT-licensed.
Some proprietary modules (e.g., Dataphyre CDN, A/B Testing, Stripe, NLP moderation, Fraud analysis & reporting) are not included in this repo and power Shopiro. 
These may be released later under a SaaS or separate license. Community alternatives are encouraged!
