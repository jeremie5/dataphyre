# Dataphyre Templating Module Documentation

The `templating` module in Dataphyre offers a flexible and powerful templating system. This module supports advanced features such as caching, debugging, SEO, component management, event handling, and more. Each feature can be included or omitted as needed.

## Table of Contents
1. [Introduction](#introduction)
2. [Namespace and Requirements](#namespace-and-requirements)
3. [Main Features](#main-features)
4. [Class Structure](#class-structure)
5. [Template Rendering Functions](#template-rendering-functions)
6. [Helper Functions](#helper-functions)
7. [Hooks and Extensions](#hooks-and-extensions)
8. [Error and Debugging](#error-and-debugging)
9. [Caching System](#caching-system)
10. [Component and Partial Management](#component-and-partial-management)
11. [SEO and Accessibility](#seo-and-accessibility)
12. [Template Inheritance and Layouts](#template-inheritance-and-layouts)
13. [Additional Helper Methods](#additional-helper-methods)
14. [Example Usage](#example-usage)
15. [Advanced Usage and Customization](#advanced-usage-and-customization)
16. [Template Security and Best Practices](#template-security-and-best-practices)
17. [Troubleshooting and Common Issues](#troubleshooting-and-common-issues)
18. [Real-World Example](#real-world-example)
19. [Summary](#summary)
20. [Additional Resources and Tips](#additional-resources-and-tips)
21. [Template Syntax Reference](#template-syntax-reference)
22. [Best Practices for Performance and Scalability](#best-practices-for-performance-and-scalability)
23. [Example Project: Building a Product Page with Dynamic Components](#example-project-building-a-product-page-with-dynamic-components)
24. [Extending the Templating Module](#extending-the-templating-module)
25. [Development Workflow Tips](#development-workflow-tips)
26. [Performance Optimization](#performance-optimization)
27. [Security Best Practices](#security-best-practices)
28. [Documentation and Maintenance](#documentation-and-maintenance)
29. [Final Summary](#final-summary)

---

### Introduction

The `templating` class in Dataphyre allows rendering templates dynamically, with modular support for caching, debugging, SEO, and accessibility. It uses a PHP-based syntax to manage variables, conditions, and components, aiming for a streamlined integration with other Dataphyre modules.

### Namespace and Requirements

- **Namespace**: `namespace dataphyre;`
- **Required Modules**: `async`, `caching`, `debugging`, `seo_accessibility`, `component_management`, `conditional_parsing`, `event_system`, `form_handling`, `render_helpers`, `rendering`, `parsing`.

### Main Features

- **Caching**: Caches templates to enhance rendering speed.
- **Debugging**: Provides utilities for profiling and debugging.
- **SEO & Accessibility**: Enhances SEO metadata and ensures accessibility compliance.
- **Component Management**: Supports modular components with lazy loading and scoped styles.
- **Conditional Parsing**: Processes loops, conditionals, and inline conditions.
- **Event Handling**: Event hooks to trigger actions at various stages (e.g., `before_render`, `after_render`).
- **Form Handling**: Parses and renders form fields.
- **Helper Functions**: Utilities for asset management, slot parsing, and partials.
- **Rendering**: Manages full, asynchronous, and fallback rendering modes.
- **Parsing**: Supports template inheritance, dynamic imports, scoped variables, and PHP blocks.

### Class Structure

```php
class templating {
    use caching;
    use debugging;
    use seo_and_accessibility;
    use component_management;
    use conditional_parsing;
    use event_system;
    use form_handling;
    use render_helpers;
    use rendering;
    use parsing;
}
```

Each `use` statement includes specific functionality:

- **caching**: Handles template caching mechanisms.
- **debugging**: Contains debugging tools for template profiling.
- **seo_and_accessibility**: Adds SEO tags.
- **component_management**: Parses and loads components.
- **conditional_parsing**: Manages loops and conditionals in templates.
- **event_system**: Manages event triggers and hooks.
- **form_handling**: Parses form templates.
- **render_helpers**: Includes utilities for assets and helper functions.
- **rendering**: Manages template rendering.
- **parsing**: Parses template structure, including inheritance and dynamic imports.

### Template Rendering Functions

#### Main Render Functions
- `render($template_file, $data=[], $theme_values=[], $slots=[])`: Renders a template with provided data, theme values, and slot content.
- `render_with_fallback($template_file, $data=[], $fallback_file='fallback.tpl')`: Renders with a fallback template if the primary fails.
- `full_render($template_file, $data=[], $theme_values=[], $slots=[])`: Full render mode for complete rendering.
- `async_render($template_file, $data=[])`: Provides asynchronous rendering support.

#### Parsing and Binding Functions
- `bind_data($template, &$data)`: Binds variables to template placeholders.
- `parse_loops($template, $data)`: Handles `{{loop}}` structures.
- `parse_conditionals($template, $data)`: Manages conditional tags (`if`, `else`).
- `parse_partials($template, $data)`: Includes partial templates.
- `parse_lazy_load_components($template, $data)`: Allows lazy loading of components.

### Helper Functions

- `adapt($values, $spacing=false)`: Adapts values based on the user theme.
- `apply_helpers($template)`: Applies registered helper functions.
- `resolve_dependencies($template)`: Loads required CSS/JS assets.
- `trim_whitespace($template)`: Trims whitespace around tags.
- `replace_placeholders($template, $data)`: Replaces placeholders with data values.
  
### Hooks and Extensions

#### Event System
- `register_event_hook($event, $callback)`: Registers an event hook for `before_render`, `after_render`, or `on_error`.
- `trigger_event($event, ...$args)`: Triggers an event and executes attached callbacks.

#### Custom Extensions
- `register_extension($name, $extension)`: Registers custom extensions.
- `apply_functions($template, $custom_functions=[])`: Applies custom functions in templates.
- `apply_filters($template, $filters=[])`: Applies filters to transform template content.

### 8. Error and Debugging (continued)

The `templating` module in Dataphyre provides robust debugging capabilities to track rendering issues, undefined variables, and performance bottlenecks.

#### Key Debugging Functions

- **`debug_render($template_file, $data)`**: Renders templates in debug mode, showing detailed insights on variable bindings and data structure.
- **`render_performance_metrics()`**: Logs performance metrics related to template rendering, useful for identifying slow-performing templates.
- **`handle_undefined_variables($template, $data)`**: Replaces any undefined variable references in the template with a placeholder (`[Undefined]`), ensuring templates render without causing fatal errors.

### Caching System

The caching mechanism in Dataphyre’s templating module improves rendering efficiency by storing template fragments, conditional results, and parsed components. This feature reduces processing time for frequently used templates or sections.

#### Key Caching Functions

- **`load_from_cache($template_file)`**: Retrieves the cached version of a template, if available.
- **`save_to_cache($template_content, $template_file)`**: Saves rendered content to cache, enabling quicker retrieval for future requests.
- **`conditional_cache($template, $data, $condition)`**: Caches templates based on specific conditions, useful for templates with variable content.
- **`store_in_cache($cache_key, $content, $duration)`**: Stores arbitrary content in the cache with a set duration.
- **`get_from_cache($cache_key)`**: Retrieves content from cache based on the specified cache key.

### Component and Partial Management

Component management in the templating module allows users to define reusable UI elements or fragments and include them dynamically. This feature is ideal for larger templates that require modularization for maintainability.

#### Component Parsing Functions

- **`parse_components($template, $data)`**: Replaces `{{component 'name'}}` tags with the actual component content.
- **`lazy_load_components($template, $data)`**: Loads components only when required, improving rendering speed and efficiency.
- **`parse_scoped_styles($template, $component_name)`**: Applies scoped styles within components, ensuring CSS isolation and compatibility across components.

#### Partial and Slot Parsing

- **`parse_partials($template, $data)`**: Loads and renders partial templates as defined by `{{include 'partial.tpl'}}`.
- **`parse_slots($template, $data, $slots=[])`**: Allows the use of slots for content injection, enhancing flexibility for nested or parent-child template structures.

### SEO and Accessibility

The SEO and accessibility module provides tools to ensure templates comply with best practices for search engine optimization and accessibility.

- **`parse_seo_tags($template, $data)`**: Inserts SEO metadata such as meta descriptions, keywords, and Open Graph tags.
- **`apply_transformations($template, $custom_functions=[], $filters=[])`**: Applies transformations to content for accessibility adjustments, such as image alt text or ARIA roles.

### Template Inheritance and Layouts

Dataphyre’s templating system supports layout inheritance to reduce redundancy. Developers can extend base layouts by defining blocks that child templates override as needed.

- **`parse_layout_inheritance($template)`**: Allows templates to extend a base layout using `{{extends 'layout.tpl'}}`.
- **`parse_blocks($template)`**: Parses block sections within a layout, enabling flexible template design.

#### Block and Inheritance Syntax
- **Extending a Layout**: `{{extends 'layout.tpl'}}` to extend a base layout.
- **Defining Blocks**: `{{block 'content'}}...{{endblock}}` to define content blocks that child templates can override.

### Additional Helper Methods

The templating module also provides utility functions for advanced template handling and content manipulation:

- **`add_to_global_context($key, $value)`**: Adds data to the global context, making it accessible across all templates.
- **`with_context($data, $block)`**: Temporarily merges data into the global context within a specific block.
- **`for_each_scoped($items, $callback)`**: Iterates over a list of items within a scoped context, making iteration variables (`index`, `first`, `last`) accessible.

### Example Usage

Here’s an example illustrating the templating module’s capabilities:

```php
$template = templating::load_template_file('homepage.tpl');
$data = [
    'title' => 'Welcome to Dataphyre',
    'user' => ['name' => 'John Doe'],
    'items' => [
        ['name' => 'Product 1', 'price' => '29.99'],
        ['name' => 'Product 2', 'price' => '39.99']
    ]
];
$output = templating::render($template, $data);
echo $output;
```

In this example:
- `load_template_file` retrieves the `homepage.tpl` template.
- `render` then compiles the template with data, processing components, conditionals, and any defined hooks.

### Advanced Usage and Customization

The `templating` module supports advanced customization through event hooks, context management, and transformation functions, enabling developers to tailor templates to specific needs.

#### Using Event Hooks
Event hooks allow developers to add custom behavior before and after template rendering, as well as in case of errors.

- **Registering Hooks**:
  ```php
  templating::register_event_hook('before_render', function($data) {
      // Perform actions before rendering
  });
  ```
- **Available Events**:
  - `before_render`: Triggered before the rendering starts, allowing data manipulation or pre-processing.
  - `after_render`: Triggered after rendering, useful for logging or additional processing.
  - `on_error`: Executes if an error occurs during rendering.

#### Context Management
The `templating` module’s context management system provides scoped and temporary data variables, enhancing the organization of nested templates.

- **Global Context**: Shared across all templates and accessible through `add_to_global_context`.
  ```php
  templating::add_to_global_context('theme', 'dark');
  ```
- **Scoped Context**: Data is passed temporarily within blocks, such as when iterating over items with `for_each_scoped`.

---

#### Custom Transformations and Filters
Custom transformations apply to template variables to modify output values, offering a more dynamic and reusable template experience.

- **Define a Custom Transformation**:
  ```php
  templating::apply_transformations($template, [
      'slugify' => fn($text) => strtolower(preg_replace('/\W+/', '-', trim($text))),
  ]);
  ```
- **Using Filters**:
  Filters allow inline manipulation of variables directly in the template:
  ```php
  {{ title | slugify }}
  ```

#### Registering Custom Tags
Custom tags provide a way to define reusable template commands with specific functionality.

- **Define a Custom Tag**:
  ```php
  templating::register_tag('greet', function($args, $data) {
      $name = $args[0] ?? 'World';
      return "Hello, $name!";
  });
  ```

- **Using Custom Tags in Templates**:
  ```php
  {{ greet 'Dataphyre' }}
  ```

- **Example Output**:
  ```html
  Hello, Dataphyre!
  ```

#### Registering Custom Filters
Custom filters extend the templating engine's functionality by allowing developers to manipulate data directly within the template.

- **Define a Custom Filter**:
  ```php
  templating::register_filter('reverse', function($value) {
      return strrev($value);
  });
  ```

- **Using Custom Filters in Templates**:
  ```php
  {{ name | reverse }}
  ```

- **Example Usage**:
  ```php
  $data = ['name' => 'Dataphyre'];
  $template = "{{ name | reverse }}";
  $output = templating::render($template, $data);
  echo $output;
  ```

- **Example Output**:
  ```html
  eryhpahtaD
  ```

#### Integrating Tags and Filters Together
Tags and filters can work seamlessly together to create powerful, dynamic templates.

- **Register Both a Tag and a Filter**:
  ```php
  templating::register_tag('shout', function($args, $data) {
      $text = $args[0] ?? '';
      return strtoupper($text);
  });

  templating::register_filter('exclaim', function($value) {
      return $value . '!!!';
  });
  ```

- **Using Them in a Template**:
  ```php
  {{ shout 'hello' | exclaim }}
  ```

- **Example Output**:
  ```html
  HELLO!!!
  ```

---

### Template Security and Best Practices

To ensure secure and optimized templates:

- **Sanitize User Data**: Always use escaping methods, such as `htmlspecialchars`, when binding data directly to templates to prevent XSS attacks.
- **Cache Carefully**: Be cautious with dynamic content in cached templates. For example, avoid caching sensitive information or content that frequently changes.
- **Limit PHP Execution**: While `{{php}}` tags are available for inline PHP execution, limit their use to avoid complexity and potential security issues.

### Troubleshooting and Common Issues

#### Undefined Variables
If a variable is missing in a template, `handle_undefined_variables` replaces it with `[Undefined]`. Use debugging functions to trace issues:
```php
templating::debug_render('template_file.tpl', $data);
```

#### Performance Optimization
For performance bottlenecks:
- Use `render_performance_metrics` to profile template rendering times.
- Enable caching selectively for high-frequency templates.
  
#### Component Not Found
If a component template file is missing, `parse_components` logs the error:
```php
tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Component not found: $component_name");
```

### Real-World Example

Below is a comprehensive example that leverages various features of the `templating` module:

```php
// Define template file
$template_file = 'product_page.tpl';

// Define data with nested variables
$data = [
    'title' => 'Dataphyre Templating Module',
    'description' => 'A flexible and modular PHP templating engine.',
    'features' => [
        ['name' => 'Caching', 'enabled' => true],
        ['name' => 'SEO Support', 'enabled' => true],
        ['name' => 'Event Hooks', 'enabled' => false],
    ],
    'theme' => 'dark',
];

// Register an event hook for before rendering
templating::register_event_hook('before_render', function(&$data) {
    $data['title'] = strtoupper($data['title']);
});

// Render the template
$output = templating::render($template_file, $data);

// Output the final rendered template
echo $output;
```

In this example:
1. **Data Definition**: The `$data` array provides content for variables like `title`, `description`, and a list of features.
2. **Event Hook**: An event hook modifies the title to uppercase before rendering.
3. **Template Rendering**: The template file is rendered with all provided data and hook transformations.

### Summary

The `templating` module in Dataphyre is designed for flexibility and performance, combining a structured, modular approach with powerful rendering, debugging, and customization features. With careful use of caching, component management, and custom hooks, developers can build secure and scalable applications efficiently.

### Additional Resources and Tips

To maximize the efficiency and maintainability of templates in Dataphyre, here are a few additional practices and resources:

#### Structuring Templates for Reusability
For large projects, break down templates into reusable partials and components:
- **Components**: Define standalone, reusable UI elements (e.g., `navbar.tpl`, `footer.tpl`) and include them across templates with `parse_components`.
- **Partials**: Use partials to create small, modular template fragments. These can include common sections like headers, footers, or buttons.
  
#### Leveraging Caching for Dynamic Content
Dynamic data, such as user-specific content, may require fine-tuned caching:
- **Conditional Caching**: Use `conditional_cache` to cache fragments selectively based on data values or request parameters.
- **Cache Invalidation**: Implement logic to clear outdated cache entries to ensure users always receive updated content.

#### Extending Template Functionality with Helpers and Extensions
Helper functions are powerful for commonly needed transformations:
- **Examples of Helpers**:
  - Date formatting: `date_format` formats dates based on locale settings.
  - Text manipulation: Use `slugify` or custom filters to format strings.

To add a custom helper:
```php
templating::register_extension('truncate', function($text, $limit) {
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
});
```
Use the helper in templates as `{{ description | truncate(100) }}`.

### Template Syntax Reference

Dataphyre’s template syntax combines HTML-like elements with custom tags. Here’s a quick reference:

#### Variables
```php
{{ variable_name }}          // Renders the value of variable_name
{{ user.name }}              // Accesses nested data (user's name)
{{ variable | filter }}      // Applies a filter to the variable
```

#### Conditionals
```php
{{ if user.isLoggedIn }}
    Welcome back, {{ user.name }}!
{{ else }}
    Please log in.
{{ endif }}
```

#### Loops
```php
{{ loop items }}
    <div>{{ item.name }} - {{ item.price }}</div>
{{ endloop }}
```

#### Inline PHP
```php
{{ php echo strtoupper($variable); }}
```

#### Components and Partials
```php
{{ component 'header' }}
{{ include 'sidebar.tpl' }}
```

#### Asset Management
```php
{{ requireCSS "styles" }}  // Loads styles.css
{{ requireJS "scripts" }}   // Loads scripts.js
```

### Best Practices for Performance and Scalability

To ensure the Dataphyre templating module performs optimally as the project scales:

- **Enable Dev Mode**: Set `is_dev_mode` to `true` for faster troubleshooting and logging.
- **Optimize with Lazy Loading**: Use `lazy_load_components` for components not immediately visible on load.
- **Minimize PHP in Templates**: Avoid excessive `{{ php ... }}` blocks to prevent complexity and potential performance hits.
- **Use Profiling Tools**: `render_performance_metrics` can identify slow-loading templates, enabling targeted optimizations.
- **Monitor Cache Utilization**: Excessive caching can lead to outdated content. Regularly evaluate cache settings and clear old cache files when necessary.

### Example Project: Building a Product Page with Dynamic Components

Let’s illustrate the capabilities of Dataphyre’s templating with a mockup for a product page, using components, conditional caching, and events.

#### Project Structure
1. **Template Files**:
    - `product_page.tpl`: The main template for the product page.
    - `components/reviews.tpl`: A component displaying user reviews.
    - `partials/sidebar.tpl`: A sidebar with product categories.

2. **Product Data**:
    ```php
    $data = [
        'product' => [
            'name' => 'Super Widget',
            'price' => '49.99',
            'description' => 'The best widget around!',
        ],
        'user' => ['isLoggedIn' => true, 'name' => 'Alice'],
        'reviews' => [
            ['author' => 'John', 'text' => 'Great widget!'],
            ['author' => 'Jane', 'text' => 'Really useful.']
        ]
    ];
    ```

#### Implementing the Template

- **product_page.tpl**
  ```html
  <h1>{{ product.name }}</h1>
  <p>Price: ${{ product.price }}</p>
  <p>{{ product.description }}</p>
  
  {{ component 'reviews' }}
  
  {{ if user.isLoggedIn }}
      <button>Add to Cart</button>
  {{ else }}
      <p>Please log in to add this item to your cart.</p>
  {{ endif }}
  
  {{ include 'partials/sidebar.tpl' }}
  ```

- **components/reviews.tpl**
  ```html
  <div class="reviews">
      {{ loop reviews }}
          <p><strong>{{ item.author }}</strong>: {{ item.text }}</p>
      {{ endloop }}
  </div>
  ```

With this setup:
- **Dynamic Components**: `reviews.tpl` loads within `product_page.tpl`, with data for each review.
- **Conditional Logic**: Only logged-in users see the "Add to Cart" button.
- **Partial Inclusion**: Sidebar content loads via `partials/sidebar.tpl`.

### Extending the Templating Module

For advanced projects, Dataphyre’s templating module can be extended to meet specific application needs. Below are ways to add custom functionality, create specialized hooks, and handle unique data-processing requirements.

#### Adding Custom Filters
Filters allow data to be processed within the template. You can register custom filters for transformations such as formatting text, manipulating dates, or applying specific business logic.

- **Example: Adding a Custom Filter to Format Currency**
  ```php
  templating::register_extension('format_currency', function($amount) {
      return '$' . number_format($amount, 2);
  });
  ```
  - Usage in template: `{{ product.price | format_currency }}` would render as `$49.99`.

#### Customizing Event Handling
Events in Dataphyre can be customized to support additional application-specific behaviors or to monitor template changes in development environments.

- **Creating a Custom Event**
  ```php
  templating::register_event_hook('custom_event', function($data) {
      // Custom logic, such as logging or modifying $data
  });
  ```

- **Triggering Custom Events**
  To trigger a registered custom event:
  ```php
  templating::trigger_event('custom_event', $data);
  ```

This feature is useful for injecting actions into various parts of the rendering lifecycle, such as logging changes in data or outputting special messages under specific conditions.

#### Extending Parsing Capabilities
For highly dynamic templates, you can extend parsing to interpret additional tags or control structures.

- **Example: Adding Support for Markdown Parsing**
  ```php
  templating::register_extension('markdown', function($text) {
      return MarkdownParser::parse($text); // Assuming MarkdownParser is implemented
  });
  ```
  - Usage in template: `{{ product.description | markdown }}` converts markdown to HTML.

### Development Workflow Tips

Efficient development and testing workflows are critical when working with a modular templating system. Here are some recommendations:

- **Enable Dev Mode for Instant Feedback**: In development, enable `is_dev_mode` in the constructor to bypass caching and enable detailed error messages.
  ```php
  $templating = new templating(true); // true enables dev mode
  ```

- **Unit Testing with Template Mocks**: Create mock data and templates to test complex conditions or template logic. This approach is essential when testing loops, conditionals, or component management.

- **Automate Cache Clearing in Development**: Automate the process of clearing cached templates when files change. This step ensures that new changes are reflected immediately without manually purging the cache.

### Performance Optimization

As applications scale, optimizing template rendering is crucial to ensure fast load times and efficient resource use. Here are additional performance tips:

- **Use `async_render` for High-traffic Pages**: Rendering templates asynchronously for data that doesn’t require synchronous display (e.g., loading large lists or content sections) can improve overall page responsiveness.
- **Minimize Large Data Passes**: Avoid passing large data sets to templates, especially in loops. Pre-process and filter data in the controller or backend logic, then pass only necessary information to the template.
- **Precompile Frequently Used Components**: Components that rarely change, like footers or navigation menus, can be precompiled and stored as static HTML. 

### Security Best Practices

The templating module provides built-in protections, but further steps can enhance security:

- **Escaping User Data**: Always escape user-generated content using `htmlspecialchars` or equivalent to prevent XSS.
- **Avoid Inline PHP Execution**: Limit use of `{{php}}` blocks. For essential PHP code, validate all inputs and avoid executing unknown data directly.
- **Securely Handle Sensitive Information**: Avoid passing sensitive data directly to templates. If necessary, ensure they’re not cached or exposed in client-side code.

### Documentation and Maintenance

To maintain a clean and understandable template structure, regularly document custom filters, helpers, and component functions. A well-documented template repository allows other developers to collaborate easily, ensures the project’s scalability, and enables smoother handoffs.

- **Documentation Example for Custom Helper**:
  ```php
  /**
   * Helper Function: format_currency
   * Description: Formats numbers as currency in USD.
   * Usage: {{ product.price | format_currency }}
   */
  ```

Regular updates to this documentation ensure consistency and aid future development or debugging processes.

### Summary

Dataphyre’s templating module is a comprehensive and customizable solution for PHP-based applications. With its support for reusable components, caching, SEO, and event handling, it allows developers to build scalable, maintainable applications efficiently. 

By following best practices, optimizing performance, and adhering to security standards, you can leverage this module to create high-quality, dynamic web applications. For additional support, refer to community forums, open-source documentation, or contact the Dataphyre support team.
