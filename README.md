# Dataphyre - The Ultimate PHP Framework

**Dataphyre** is a cutting-edge, highly scalable PHP framework designed to handle applications of any size, from small prototypes to world-scale platforms. Whether you're building a simple web app or an enterprise-grade distributed system, Dataphyre is engineered to grow with your needs—without requiring significant refactoring or architecture changes.

## Key Features

- **Infinite Scalability**: Built from the ground up to support automatic scaling, server provisioning, and real-time data synchronization.
- **Modular Ecosystem**: Comprehensive modules for everything from authentication to caching, full-text search, async tasks, and more.
- **Native CDN Support**: Built-in support for a cost-efficient CDN system that scales with your application, eliminating reliance on expensive external solutions.
- **Advanced Security**: Includes unique tools like Caspow (Cryptographic Anti-Spam Proof of Work) and Fraudar (fraud detection) to secure your platform.
- **Asynchronous Processing**: Powerful async task handling for background jobs, scheduled tasks, and more—perfect for high-performance applications.
- **Full-Text Search Engine**: Includes a robust native search engine, with the ability to pair with Elasticsearch or Vespa for even more advanced search capabilities.
- **Free for Personal Use**: Dataphyre is licensed freely for personal projects. Commercial applications will require a yearly license based on revenue.

## Disclaimer
Dataphyre was designed with a single application in mind and is provided "as is." Users are free to improve the framework, provided that these improvements do not alter the established path and mindset of the framework. Please note that some parts of the framework may be poorly documented, and there may be elements that reflect bad practices. Users are encouraged to use discretion and contribute improvements where possible.

## Getting Started

### Prerequisites

Before you start, make sure you have the following installed:

- **PHP** (>= 8.0)
- **Composer** for dependency management

Make sure to verify prerequisites for each Dataphyre module you will add to your project.

### Installation

1. Clone the repository to your local environment:

   ```bash
   git clone https://github.com/yourusername/dataphyre.git
   ```

2. Navigate into the project directory:

   ```bash
   cd dataphyre
   ```

## Modules Overview

- **Access**: Manage authentication and authorization, including support for multi-factor authentication (MFA).
- **Aceit Engine**: Advanced experimentation and A/B testing framework.
- **Async**: Powerful background job processing and scheduling.
- **Cache**: Distributed caching system with native integration for improved performance across modules.
- **Cdnclient**: Content Delivery Network integration for efficient resource distribution.
- **Cdnserver**: Content Delivery Network integration for efficient resource distribution.
- **Caspow**: Proof-of-work automated abuse mitigation.
- **Core**: Core functionalities and backbone of the Dataphyre framework.
- **Currency**: Currency management and conversion utilities.
- **Datadoc**: Code documentation engine.
- **Datetranslation**: Data translation layers for internationalization support.
- **Dpanel**: Dynamic panel components for enhanced user interfaces.
- **Firewall**: Firewall services to protect and secure Dataphyre applications.
- **Fraudar**: Native fraud detection for e-commerce and financial applications.
- **Fulltextengine**: Built-in full-text search engine, scalable with optional support for Elasticsearch or Vespa.
- **Geoposition**: Accurate geolocation services with routing and location-based search support.
- **Googleauthenticator**: Integration of Google Authenticator for two-factor authentication.
- **Perfstats**: Performance statistics and analytics.
- **Profanity**: Profanity filtering and content moderation.
- **Routing**: Route management and URL mapping for web navigation.
- **Sanitation**: Data cleansing and sanitation for secure input handling.
- **Scheduling**: Task scheduling and cron job management.
- **Sql**: SQL database interactions and abstractions.
- **Stripe**: Stripe payment gateway integration for financial transactions.
- **Supercookie**: Enhanced cookie management for persistent state handling.
- **Timemachine**: Historical data tracking and rollback capabilities.
- **Tracelog**: Real-time application logging and monitoring for debugging and performance optimization.

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