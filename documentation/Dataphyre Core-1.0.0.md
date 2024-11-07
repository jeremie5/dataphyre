## Dataphyre Core

### Overview

The `core.php` file is the essential entry point for initializing the Dataphyre framework. It sets up the core environment, loads necessary modules, handles configurations, manages caching, and establishes the initial runtime context. This file should be included at the start of any project using Dataphyre to ensure the framework's proper initialization.

### Core.php Functionality

1. **Error Configuration**:
   - Disables the display of errors to prevent sensitive information exposure.
   - Sets error reporting level to `E_ALL` to capture all possible errors.

2. **Helper Functions**:
   - **`dp_modcache_save()`**: Saves module cache data to avoid repeated file existence checks.
   - **`dp_module_present()`**: Checks for the presence of a module, either locally or in the common path.
   - **`dp_module_required()`**: Ensures that a specific module dependency is met.
   - **`dpvks()`** and **`dpvk()`**: Manage retrieval of private keys, critical for framework security.

3. **Global Configuration Checks**:
   - Ensures the `rootpath` and configuration variables are correctly set, which is vital for framework stability.

4. **Initialization of Constants**:
   - Sets key constants such as `RUN_MODE`, `REQUEST_IP_ADDRESS`, and `REQUEST_USER_AGENT`.

5. **Module Caching**:
   - Initializes `modcache` from a saved file to optimize module loading by avoiding redundant existence checks.

6. **Required Core Files**:
   - Loads core utility files like `language_additions.php` and `core_functions.php`, which provide foundational functions for Dataphyre.

7. **Session and Cookie Management**:
   - Configures PHP session parameters if sessions are enabled in the configuration.

8. **Timezone and Memory Configurations**:
   - Sets the default timezone and memory limit per Dataphyre’s configurations to ensure consistent environment settings.

9. **Environment Compatibility**:
   - Checks PHP version compatibility, enforcing PHP 8.1.0 or newer to prevent runtime issues due to outdated features.

10. **Conditional HTTPS Enforcement**:
    - Enforces HTTPS usage if the configuration mandates it, securing the application environment.

11. **Optional Modules Loading**:
    - Loads additional modules based on the runtime context (e.g., `tracelog`, `cache`, `async`, `sql`, etc.), each enhancing Dataphyre’s functionality.

12. **Plugin Management**:
    - Searches for plugins in both `common_dataphyre` and `dataphyre` directories, allowing for modular enhancements and feature extensions.

13. **Finalizing Initialization**:
    - Outputs a trace log signaling the end of Dataphyre's initialization, handing control over to the primary application.

### Detailed Function Documentation

#### `dp_modcache_save()`
Saves the current state of the module cache to a file, reducing the need for repeated file checks on subsequent requests.

- **Parameters**: None
- **Returns**: `void`

#### `dp_module_present(string $module)`
Checks if a specified module exists, returning the file path if available or `false` if not.

- **Parameters**: `$module` - The module name to check.
- **Returns**: `string|bool` - Path of the module or `false`.

#### `dp_module_required(string $module, string $required_module)`
Ensures a module’s dependency is present. If not, an initialization error is triggered.

- **Parameters**:
   - `$module` - Name of the dependent module.
   - `$required_module` - Name of the required module.
- **Returns**: `void`


### Usage

Include `core.php` at the start of your Dataphyre-based project:

```php
require_once 'path/to/core.php';
```

Ensure `$rootpath` and `$configurations` variables are set before including `core.php`, as they provide necessary paths and configuration details.

### Example Initialization Script

```php
$rootpath = [
    'dataphyre' => '/path/to/dataphyre/',
    'common_dataphyre' => '/path/to/common/dataphyre/'
];
$configurations = require 'configurations.php';
require_once $rootpath['dataphyre'] . 'core.php';
```

### Core Class

The **Core** class is the foundation of Dataphyre, providing key functionalities including server load management, configuration handling, data encryption, HTTP header management, date formatting, and file operations. This class defines essential utilities and configuration support, ensuring a stable and configurable environment for the framework.

#### Properties

- **`$server_load_level`**: Represents the server's load level on a scale, used to optimize performance.
- **`$server_load_bottleneck`**: Identifies the resource causing the bottleneck, such as CPU or memory.
- **`$used_packaged_config`**: Tracks whether a packaged configuration has been used.
- **`$env`**: Holds environment variables for the application.
- **`$dialbacks`**: Stores callback functions associated with specific events.

#### Key Methods

1. **`dialback(string $event_name, ...$data): mixed`**
   - Executes registered callbacks for a specified event.
   - **Parameters**: `$event_name` (name of the event), `$data` (data passed to callbacks).
   - **Returns**: The result of the executed callback or null if no callback is registered.

2. **`register_dialback(string $event_name, callable $dialback_function)`**
   - Registers a callback function for a specific event.
   - **Parameters**: `$event_name` (name of the event), `$dialback_function` (the callback function).
   - **Returns**: `true` if registration succeeds, `false` if the function does not exist.

3. **`set_http_headers()`**
   - Sets security and control headers for HTTP responses, including XSS protection and strict transport security.

4. **`get_server_load_level(): string`**
   - Calculates and returns the server's load level based on CPU and memory usage.
   - **Returns**: The server load level as a string.

5. **`delayed_requests_lock()` and `delayed_requests_unlock()`**
   - Creates and removes a lock file to manage delayed requests.

6. **`check_delayed_requests_lock()`**
   - Checks for an existing lock file and waits if found. If the lock persists beyond 15 seconds, it invokes the `unavailable` method.

7. **`minified_font(): string`**
   - Returns a minified CSS snippet for embedding the "Phyro-Bold" font.

8. **`csv_to_db(string $input_file_path, string $output_table, array $fields): bool`**
   - Loads CSV data into a specified database table.
   - **Parameters**: `$input_file_path` (path to CSV file), `$output_table` (table name), `$fields` (table fields).
   - **Returns**: `true` on success, `false` if file is unreadable.

9. **`csv_to_sqlite(string $input_file_path, string $output_file_path): bool`**
   - Converts a CSV file into an SQLite database table.
   - **Returns**: `true` on success, `false` if the input file is unreadable.

10. **`get_password(string $string): string`**
    - Encrypts a string using the framework's private key.
    - **Parameters**: `$string` (plain text password).
    - **Returns**: Encrypted string.

11. **`high_precision_server_date(string $format): string`**
    - Retrieves the current date and time with high precision.
    - **Parameters**: `$format` (date format).
    - **Returns**: The formatted date string.

12. **`format_date(string $date, string $format, bool $translation): string`**
    - Formats a date according to specified parameters.
    - **Parameters**: `$date`, `$format` (output format), `$translation` (translate the date if true).
    - **Returns**: Formatted date string.

13. **`convert_to_user_date(string|int $date, string $user_timezone, string $format, bool $translation): string`**
    - Converts a server date to a user-specific date in the desired timezone.
    - **Returns**: The formatted user date.

14. **`convert_to_server_date(string|int $date, string $user_timezone, string $format): string`**
    - Converts a user-specific date to the server timezone.
    - **Returns**: The server date.

15. **`add_config(string|array $config, mixed $value=null): bool`**
    - Adds or updates configuration settings.
    - **Returns**: `true` if the operation is successful.

16. **`get_config(string $index): mixed`**
    - Retrieves a configuration value by index.
    - **Returns**: Configuration value or `null` if not found.

17. **`set_env(string|array $index, mixed $value)`**
    - Sets environment variables for the application.

18. **`get_env(mixed $index): mixed`**
    - Retrieves environment variables.
    - **Returns**: Value or `false` if not set.

19. **`random_hex_color(array $red_range, array $green_range, array $blue_range, bool $add_dash): string`**
    - Generates a random hexadecimal color within specified RGB ranges.
    - **Returns**: Hex color string.

20. **`unavailable(string $file, string $line, string $class, string $function, string $error_description, string $error_type)`**
    - Handles critical errors and logs the issue. Outputs error details or redirects based on configuration.

21. **`url_self(bool $full): string`**
    - Returns the current URL.
    - **Parameters**: `$full` (include the query string if true).
    - **Returns**: URL string.

22. **`url_updated_querystring(string $url, array|null $value, array|null|bool $remove): string`**
    - Updates the query string of a given URL.
    - **Returns**: Modified URL.

23. **`url_self_updated_querystring(array|null $value, array|null|bool $remove): string`**
    - Updates the query string of the current URL.

24. **`buffer_minify(mixed $buffer): mixed`**
    - Minimizes HTML output by removing comments, whitespace, and other unnecessary characters.

25. **`encrypt_data(?string $string, ?array $salting_data): string`**
    - Encrypts data using AES-256-CBC with salting.
    - **Returns**: Encrypted string.

26. **`decrypt_data(?string $string, ?array $salting_data, callable|string|null $deprecation_callback): string`**
    - Decrypts AES-256-CBC encrypted data.
    - **Returns**: Decrypted string.

27. **`csrf(string $form_name, mixed $token): string|bool`**
    - Generates or validates CSRF tokens.
    - **Returns**: Token if created, `true` if validation succeeds, or `false`.

28. **`file_put_contents_forced(string $dir, string $contents): int|bool`**
    - Writes content to a file, creating necessary directories.

29. **`attempt_json_syntax_correction(string $json): string`**
    - Attempts to correct common syntax errors in JSON strings.

30. **`force_rmdir(string $path): void`**
    - Forcefully removes a directory and its contents.

31. **`convert_storage_unit(int|float $size): string`**
    - Converts byte size to human-readable format.

32. **`splash(): string`**
    - Returns a "Dataphyre" ASCII art splash for display in the CLI.

The **Core** class encapsulates critical functions to ensure a consistent environment, handle configuration management, and support secure data operations, contributing significantly to the Dataphyre framework’s stability and usability.

### Language Additions

The **Language Additions** provide a series of utility functions that extend PHP’s native functionality to enhance productivity and simplify common operations. Each function is wrapped in a conditional to prevent redeclaration, ensuring compatibility with other parts of the Dataphyre framework and third-party libraries.

#### Functions

1. **`current_datetime(): string`**
   - Returns the current date and time in the format `Y-m-d H:i:s`.
   - **Usage**: Provides a standardized way to obtain the current date and time.

2. **`array_replace_values(array $array, mixed $old_value, mixed $new_value): array`**
   - Replaces occurrences of a specified value within an array.
   - **Parameters**: `$array` (target array), `$old_value` (value to be replaced), `$new_value` (replacement value).
   - **Returns**: Modified array with values replaced.

3. **`prefix_array_keys(array $array, string $prefix, int $start_at=0): array`**
   - Adds a prefix to each key in an array.
   - **Parameters**: `$array` (target array), `$prefix` (prefix to add), `$start_at` (starting index for numeric keys).
   - **Returns**: Array with prefixed keys.

4. **`is_cli(): bool`**
   - Checks if the script is running in the command-line interface (CLI) environment.
   - **Returns**: `true` if CLI, `false` otherwise.

5. **`uuid(): string`**
   - Generates a UUID (version 4) in the format `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.
   - **Returns**: A randomly generated UUID.

6. **`is_uuid(string $string): bool`**
   - Validates whether a given string is a valid version 4 UUID.
   - **Returns**: `true` if the string is a valid UUID, `false` otherwise.

7. **`is_base64(string $string): bool`**
   - Validates whether a string is in Base64 encoding.
   - **Returns**: `true` if valid Base64, `false` otherwise.

8. **`is_timestamp(int $timestamp): bool`**
   - Checks if a given integer represents a valid Unix timestamp.
   - **Returns**: `true` if valid, `false` otherwise.

9. **`ellipsis(string $string, int $length, string $direction='right'): string`**
   - Truncates a string to a specified length, appending an ellipsis.
   - **Parameters**: `$string` (target string), `$length` (max length), `$direction` (truncation direction: `'left'`, `'center'`, or `'right'`).
   - **Returns**: Truncated string with ellipsis.

10. **`array_average(array $array): int`**
    - Calculates the average value of numeric elements in an array.
    - **Returns**: Average as an integer.

11. **`array_shuffle(array $array): array`**
    - Randomly shuffles the elements of an array while maintaining key-value associations.
    - **Returns**: Shuffled array.

12. **`array_count(mixed $array): int`**
    - Safely counts elements in an array, returning `0` for invalid inputs.
    - **Returns**: Element count or `0` if input is invalid.

13. **`copy_folder(string $src, string $dst): void`**
    - Recursively copies files and directories from a source to a destination.
    - **Parameters**: `$src` (source directory), `$dst` (destination directory).

### Summary

These additions streamline tasks such as date management, array manipulation, UUID generation, CLI checks, and data validation, providing consistent, reusable tools across the Dataphyre framework.