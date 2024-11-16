![Dataphyre Logo](logo.png)

# The Ultimate PHP Framework

> Why reinvent the wheel when you can just make the wheel faster?

Dataphyre is a **cutting-edge, highly scalable PHP framework** built to handle anything from small prototypes to enterprise-grade, world-scale platforms. Whether you're building a **simple web app** or a **global distributed system**, Dataphyre adapts to your needs—no refactoring, no bloat, no limits.

### **Proven at Scale**  
Experience the power of Dataphyre through [**Shopiro**](https://shopiro.ca)—a global marketplace that processes product pages in **just 24ms** and serves users in **128 languages**. Built entirely on Dataphyre, Shopiro showcases what’s possible when performance meets simplicity.

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-dual-important)](https://github.com/jeremie5/dataphyre/blob/main/LICENSE.md)
[![Documentation](https://img.shields.io/badge/docs-available-brightgreen)](https://github.com/jeremie5/dataphyre/wiki)
[![GitHub issues](https://img.shields.io/github/issues/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/issues)
[![Contributors](https://img.shields.io/github/contributors/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre/graphs/contributors)
[![Code Size](https://img.shields.io/github/languages/code-size/jeremie5/dataphyre)](https://github.com/jeremie5/dataphyre)
[![GitHub stars](https://img.shields.io/github/stars/jeremie5/dataphyre?style=social)](https://github.com/jeremie5/dataphyre/stargazers)

### Key Features
- **Infinite Scalability**: Built from the ground up to handle any level of scale, no matter how complex.
- **Modular Ecosystem**: Comprehensive modules for everything from authentication to caching, full-text search, asynchronous tasks, and now, a powerful templating system.
- **Templating System**: Dynamic, efficient rendering with support for caching, debugging, SEO, accessibility, component management, and conditional parsing. Templating in Dataphyre enables:
  - **Layout Inheritance**: Easily extend base layouts to maintain consistency and flexibility.
  - **Lazy Loading and Scoped Styles**: Optimize component loading times and style management.
  - **SEO and Accessibility**: Ensure your templates meet modern SEO and accessibility standards effortlessly.
  - **Flexible Rendering Options**: Full, async, and fallback render modes, along with custom slots, dynamic imports, and partials for complex templates.
- **Native CDN Support**: Built-in support for a cost-efficient CDN system that scales with your application, eliminating reliance on expensive external solutions.
- **Advanced Security**: Includes unique tools like **Caspow** (Cryptographic Anti-Spam Proof of Work) to secure your platform against spam and malicious bots.
- **Asynchronous Processing**: High-performance async task handling for background jobs, scheduled tasks, and more.
- **Full-Text Search Engine**: Robust native search engine with the flexibility to integrate with Elasticsearch or Vespa for even more advanced search capabilities.
- **Free for Personal Use**: Dataphyre is licensed freely for personal projects, while commercial applications require a yearly license based on revenue.

## Disclaimer
Dataphyre was designed with a single application in mind and is provided "as is." Users are free to improve the framework, provided that these improvements do not alter the established path and mindset of the framework. Please note that some parts of the framework may be poorly documented, and there may be elements that reflect bad practices. Users are encouraged to use discretion and contribute improvements where possible. Feel free to reach out on Discord, create an issue or interact through Discussions.

## Getting Started

### Prerequisites

Before you start, make sure you have the following installed:

- **PHP** (>= 8.1)
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

## **Modules Overview**
Explore Dataphyre's powerful modules, designed to handle complex application needs efficiently.

### **Core Framework**
- **[Core](/common/dataphyre/modules/core/documentation/Dataphyre_Core.md):** The backbone of Dataphyre, providing essential language augmentations and core functionalities.

### **Performance and Scalability**
- **[Async](/common/dataphyre/modules/async/documentation/Dataphyre_Async.md):** High-performance background job processing and task scheduling with Promises and Coroutines.
- **[Cache](/common/dataphyre/modules/cache/documentation/Dataphyre_Cache.md):** Distributed caching to minimize database load and accelerate web applications.
- **[Cdn Client/Server](/common/dataphyre/modules/cdn/documentation/Dataphyre_CDN_Client.md):** Efficient content delivery with integrated CDN support for client and server-side resources.

### **Security**
- **[CASPOW](/common/dataphyre/modules/caspow/documentation/Dataphyre_CASPOW.md):** Mitigate spam and DDoS attacks using cryptographic challenges with customizable difficulty.
- **[Firewall](/common/dataphyre/modules/firewall/documentation/Dataphyre_Firewall.md):** Prevent flooding, rate-limit requests, and integrate CAPTCHA for robust application security.
- **[Sanitation](/common/dataphyre/modules/sanitation/documentation/Dataphyre_Sanitation.md):** Safeguard data integrity and prevent injection attacks with advanced sanitization techniques.
- **Googleauthenticator:** Easily integrate two-factor authentication via Google Authenticator.
- **[Access](/common/dataphyre/modules/access/documentation/Dataphyre_Access.md):** Securely manage user authentication and authorization across your application.

### **Automation and Analytics**
- **[Aceit Engine](/common/dataphyre/modules/aceit/documentation/Dataphyre_Aceit.md):** A/B testing and experimentation framework to optimize user experiences through data-driven insights.
- **[Perfstats](/common/dataphyre/modules/perfstats/documentation/Dataphyre_Perfstats.md):** Real-time performance analytics to monitor and optimize your application.
- **[Scheduling](/common/dataphyre/modules/scheduling/documentation/Dataphyre_Scheduling.md):** Automate and manage complex tasks with flexible schedules and dependency handling.

### **Search and Data Handling**
- **[Fulltext Engine](/common/dataphyre/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md):** Advanced search capabilities with tokenization, stemming, and multi-backend support (e.g., Elasticsearch).
- **[SQL](/common/dataphyre/modules/sql/documentation/Dataphyre_SQL.md):** Simplified and secure database interactions with dynamic query building and robust error handling.
- **[Currency](/common/dataphyre/modules/currency/documentation/Dataphyre_Currency.md):** Streamline global currency handling with automatic exchange rates and localized formatting.

### **Development Tools**
- **[Datadoc](/common/dataphyre/modules/datadoc/documentation/Dataphyre_Datadoc.md):** Auto-generate documentation directly from your source code for streamlined project management.
- **[Tracelog](/common/dataphyre/modules/tracelog/documentation/Dataphyre_Tracelog.md):** Debug and monitor your application with detailed execution traces and visualizations.

### **User Experience**
- **[Templating](/common/dataphyre/modules/templating/documentation/Dataphyre_Templating.md):** Dynamic template rendering with caching, SEO, and accessibility built in.
- **[Routing](/common/dataphyre/modules/routing/documentation/Dataphyre_Routing.md):** Flexible routing with dynamic URL patterns, custom responses, and parameter handling.
- **[Geoposition](/common/dataphyre/modules/geoposition/documentation/Dataphyre_Geoposition.md):** Add geolocation features like postal code validation, distance calculations, and coordinate retrieval.

### **Content and State Management**
- **[Profanity](/common/dataphyre/modules/profanity/documentation/Dataphyre_Profanity.md):** Detect and filter inappropriate content with multilingual support.
- **[Supercookie](/common/dataphyre/modules/supercookie/documentation/Dataphyre_Supercookie.md):** Manage session and state data with a secure, JSON-based cookie system.
- **[Timemachine](/common/dataphyre/modules/timemachine/documentation/Dataphyre_Time_Machine.md):** Track and roll back user changes to maintain data integrity and support error recovery.

### **E-Commerce and Transactions**
- **[Stripe](/common/dataphyre/modules/stripe/documentation/Dataphyre_Stripe.md):** Seamless integration with Stripe for secure payment processing, webhooks, and customer management.

---

## Contributing

We welcome contributions to Dataphyre! Please check the issues tab for current open tasks or feel free to open new issues. When contributing, please follow our [code of conduct](CODE_OF_CONDUCT.md).

## Third-Party Libraries

Dataphyre's Stripe module includes the [Stripe PHP library](https://github.com/stripe/stripe-php), which is used for payment processing. This library is licensed under the MIT License. A copy of the license can be found in the `stripe-php` directory.

### Acknowledgments

- Stripe for providing the PHP library, which enables seamless payment processing in Dataphyre.

## License

Dataphyre is licensed under a **dual license**:
- **Free for personal use**: Use Dataphyre in non-commercial, personal projects without charge.
- **Commercial license**: For revenue-generating applications, a paid yearly license is required.

For commercial licensing inquiries, please contact us at `licensing@dataphyre.com`.
