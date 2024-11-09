# Dataphyre - The Ultimate PHP Framework

Dataphyre is a cutting-edge, highly scalable PHP framework designed to handle applications of any size, from small prototypes to world-scale platforms. Whether you're building a simple web app or an enterprise-grade distributed system, Dataphyre is engineered to grow with your needs—without requiring significant refactoring or architectural changes.

Experience the power of Dataphyre firsthand on [Shopiro](https://shopiro.ca). Discover how our framework drives a seamless and scalable marketplace, optimized for both efficiency and performance. Explore Dataphyre's features and see how it can transform your development process!

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-dual-important)](https://github.com/jeremie5/dataphyre/LICENSE)
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

## Modules Overview
- [**Core**](/common/dataphyre/modules/core/documentation/Dataphyre_Core.md): Core functionalities, language augmentations and backbone of the Dataphyre framework.
- [**Access**](/common/dataphyre/modules/access/documentation/Dataphyre_Access.md): Manage authentication and authorization.
- **Aceit Engine**: The Dataphyre AceIt Engine is a sophisticated A/B testing and experimentation framework for web applications. It enables developers to set up experiments with flexible parameters, dynamically assign users to groups, and track user interactions for in-depth analysis. With custom metrics and automated reporting, it supports data-driven decision-making to enhance user experience and optimize application performance. Its modular design allows seamless integration with other Dataphyre modules, making it adaptable for various experimentation needs.
- [**Async**](/common/dataphyre/modules/async/documentation/Dataphyre_Async.md): A robust framework for background job processing and scheduling, offering seamless integration of Promises, Coroutines, and advanced task management features for efficient asynchronous programming.
- **Cache**: A distributed caching system seamlessly integrated with Dataphyre, designed to enhance performance by efficiently storing and retrieving key-value pairs in memory, reducing database load and accelerating dynamic web applications.
- **Cdn** [**client**](/common/dataphyre/modules/cdn/documentation/Dataphyre_CDN_Client.md)/[**server**](/common/dataphyre/modules/cdn_server/documentation/Dataphyre_CDN_Server.md): Content Delivery Network integration for efficient resource distribution.
- [**CASPOW**](/common/dataphyre/modules/caspow/documentation/Dataphyre_CASPOW.md): The Dataphyre CASPOW (Cryptographic Anti-Spam Proof Of Work) Module enhances web application security by requiring clients to complete a computational challenge, mitigating spam and DDoS attacks. It generates customizable cryptographic challenges using SHA-256, SHA-384, or SHA-512, incorporating unique salts and adjustable difficulty based on client device type. The module verifies client responses to ensure legitimacy, making it effective for preventing automated submissions and ensuring valid requests.
- [**Currency**](/common/dataphyre/modules/currency/documentation/Dataphyre_Currency.md): The Dataphyre Currency Module is a PHP solution for managing currency conversion and formatting in web applications. It supports multiple currencies with features like automatic exchange rate fetching, localized formatting, and customizable base/display currencies. Ideal for e-commerce and financial applications, it ensures accurate, user-friendly currency handling for a global audience.
- [**Datadoc**](/common/dataphyre/modules/datadoc/documentation/Dataphyre_Datadoc.md): The Datadoc module in Dataphyre automates the generation and management of project documentation directly from PHP source code. Utilizing advanced parsing techniques, it organizes and updates documentation to reflect changes in the codebase. Key features include automated documentation generation, code tokenization, synchronization with source code, and security controls. This module is ideal for maintaining internal documentation, generating API references, and facilitating project handovers.
- [**Date Translation**](/common/dataphyre/modules/date_translation/documentation/Dataphyre_Date_Translation.md): Data translation layers for internationalization support.
- **Dpanel**: Project diagnosis tool.
- [**Firewall**](/common/dataphyre/modules/firewall/documentation/Dataphyre_Firewall.md): The Dataphyre Firewall module is a robust security solution designed to safeguard web applications from threats like request flooding and bot traffic. Key features include dynamic request flooding prevention, rate limiting to control incoming requests, and captcha integration to distinguish legitimate users from bots. The module is highly configurable, allowing customization of settings to meet specific security needs, and it integrates seamlessly with other Dataphyre components for enhanced functionality. With diagnostic logging for monitoring and troubleshooting, the Firewall module is essential for maintaining a secure and reliable web environment.
- [**Fulltext Engine**](/common/dataphyre/modules/fulltext_engine/documentation/Dataphyre_Fulltext_Engine.md): The Dataphyre Fulltext Engine Module enables advanced full-text search capabilities in applications. Supporting multiple backends like SQLite, SQL, Elasticsearch, and Vespa, it offers features such as tokenization, stemming, stopword removal, and various search algorithms to enhance match accuracy. Users can create and manage indexes, execute flexible search queries, and obtain relevance-scored results tailored to specific use cases. With language support and integration options for external engines, this module facilitates the development of robust, multi-lingual search experiences.
- [**Geoposition**](/common/dataphyre/modules/geoposition/documentation/Dataphyre_Geoposition.md): The Geoposition Dataphyre Module provides robust geolocation functionalities, including postal code formatting and validation, position retrieval based on postal codes or subdivisions, and distance calculations. It enables applications to standardize and verify postal codes, fetch geographical coordinates, and compute distances using Haversine and Vincenty algorithms. This module enhances location-based services and analyses, making it ideal for applications requiring precise geolocation data.
- **Googleauthenticator**: Integration of Google Authenticator for two-factor authentication.
- **Perfstats**: Performance statistics and analytics.
- [**Profanity**](/common/dataphyre/modules/profanity/documentation/Dataphyre_Profanity.md): The Dataphyre Profanity Module detects and evaluates profanity in user inputs using customizable, context-sensitive rulesets across multiple languages. Key features include unscrubbing obfuscated text, scoring profane words, and integrating with the fulltext engine for effective content moderation. This module helps maintain a respectful digital environment by filtering inappropriate content and analyzing user feedback.
- [**Routing**](/common/dataphyre/modules/routing/documentation/Dataphyre_Routing.md): The Dataphyre Routing Module offers a robust solution for managing application routes, allowing developers to define URL patterns linked to specific actions or files. It supports dynamic route handling, custom responses for unmatched routes, and route validation to enhance security and user experience. The module simplifies URL management, enabling SEO-friendly and user-friendly URLs, while processing parameters for flexibility. By efficiently routing requests and providing clear feedback, it ensures an organized approach to request handling in web applications.
- [**Sanitation**](/common/dataphyre/modules/sanitation/documentation/Dataphyre_Sanitation.md): The Dataphyre Sanitation Module ensures data integrity and security by sanitizing and anonymizing sensitive or user-provided data. It prevents vulnerabilities such as cross-site scripting (XSS) and SQL injection attacks through functionalities like email anonymization, data sanitization for various formats (URLs, phone numbers), and advanced pattern matching. This module enhances data privacy, prevents injection attacks, and maintains data quality while being easily extendable and efficient for high-volume applications.
- [**Scheduling**](/common/dataphyre/modules/scheduling/documentation/Dataphyre_Scheduling.md): The Dataphyre Scheduling Module automates and manages tasks within applications, ensuring reliable execution of scripts, functions, and commands based on predefined schedules and dependencies. Key functionalities include detailed task management, dependency handling, resource consideration, and execution locks to prevent conflicts. This module supports flexible task scheduling—one-time, periodic, or conditional—and integrates seamlessly with the Dataphyre ecosystem. Practical applications include automated data processing, system health checks, user communications, and background job processing. By enhancing automation and reliability, this module plays a crucial role in maintaining efficient application operations.
- [**SQL**](/common/dataphyre/modules/sql/documentation/Dataphyre_SQL.md): The SQL module in Dataphyre simplifies secure database interactions across multiple systems like MySQL and PostgreSQL. It offers dynamic query building, caching strategies, and robust error handling, enabling efficient data management with minimal complexity.
- [**Stripe**](/common/dataphyre/modules/stripe/documentation/Dataphyre_Stripe.md): The Dataphyre Stripe Module provides a comprehensive interface for integrating Stripe's payment processing into applications. Key features include dynamic API key management, customer and payment method handling, payment processing with intents and webhooks, and account and transfer management for marketplaces. This module simplifies complex API interactions, ensuring secure and reliable transactions while supporting error handling and logging. Ideal for e-commerce platforms, it enables seamless payment processing, customer management, and real-time updates on transaction statuses.
- **Supercookie**: The Supercookie Class in the Dataphyre framework manages a single cookie that can store data in JSON format. This class enhances security and data integrity by utilizing secure flags and an intuitive interface for setting, getting, and deleting values. It simplifies cookie management while optimizing browser limits, ensuring robust session and state management in web applications.
- [**Timemachine**](/common/dataphyre/modules/timemachine/documentation/Dataphyre_Time_Machine.md): The Time Machine module in the Dataphyre framework enables robust tracking and management of user changes, allowing for easy rollback to previous data states. Key features include automatic change logging, rollback support for error correction, and a purge mechanism for managing old logs. Security measures protect sensitive information, while user control options offer flexibility in change management. This module is essential for maintaining data integrity, ensuring compliance through audit trails, and facilitating quick error recovery.
- [**Tracelog**](/common/dataphyre/modules/tracelog/documentation/Dataphyre_Tracelog.md): The tracelog module in Dataphyre provides powerful debugging and performance monitoring capabilities. It dynamically logs system activities, errors, and performance metrics, allowing developers to track function calls, analyze execution flow, and identify bottlenecks. With configurable output options and support for visualizing execution traces, this module enhances application stability and optimizes performance.
- [**Templating**](/common/dataphyre/modules/templating/documentation/Dataphyre_Templating.md): Dataphyre's templating module allows rendering templates dynamically, with modular support for caching, debugging, SEO, and accessibility.

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
