# Localization Module Documentation

## Overview
The `localization` module in Dataphyre enables dynamic multilingual support with powerful configuration and real-time translation learning. It supports three levels of localization:

- **Global**: Strings that apply across the entire platform.
- **Theme**: Theme-specific localized strings.
- **Local**: Page-specific strings.

It also integrates auto-translation and persistent database-backed localization entries with automatic file generation.

---

## Initialization
Place something similar early in your application lifecycle:

```php
new dataphyre\localization([
    'translation_callback' => function($language, $string) {
        return translate(config("app/base_language"), $language, $string);
    },
    'default_language' => config("app/base_language"),
    'available_languages' => $available_languages,
    'user_theme' => $user_theme,
    'user_language' => $lang,
    'custom_parameters' => [
        "<{logo}>" => $logo,
        "<{logo_url}>" => $configurations['app']['logo_image'],
        "<{platform_name}>" => $configurations['app']['platform_name'],
        "<{company_name}>" => $configurations['app']['company_name'],
        "<{website_name}>" => $configurations['app']['website_name'],
        "<{facebook_page_url}>" => $configurations['app']['facebook_page_url'],
        "<{twitter_page_url}>" => $configurations['app']['twitter_page_url'],
        "<{cdn_url}>" => $configurations['app']['cdn_url'],
    ],
    'enable_theme_locales' => true,
    'enable_global_locales' => true,
    'locales_table' => 'shopiro.locales',
    'global_locale_path' => $rootpath['backend'] . "global_i18n_locales/%language%/static_locales/global.json",
    'theme_locale_path' => $rootpath['themes'] . "%theme%/DYN_i18n_locales/%language%/static_locales/global.json",
    'local_locale_path' => $rootpath['themes'] . "%theme%/DYN_i18n_locales/%language%/static_locales%active_page%.json",
]);
```

---

## API

### `locale(string $key, ?string $fallback = null, ?array $parameters = null, ?string $forced_language = null, ?string $forced_page = null): string`
Resolves a localized string from local/theme/global context. Falls back to `$fallback` if not found.

### `validate_language_code(string $lang): string`
Ensures the language code exists in the available language list.

### `get_locales(string $scope, string $path, string $language): array`
Returns the JSON-decoded locale file contents for the given scope and language.

### `locale_parameters(string $string, ?array $parameters = []): string`
Performs dynamic parameter replacement in strings using default and custom values.

### `learn_unknown_locales(): int|string`
Translates and inserts all entries in the `unknown_locales` cache into the database, then regenerates locale files.

### `sync_locales(bool $forced = false): void`
Checks database for changes and automatically regenerates JSON locale files as needed.

### `rebuild_locale(array $type = [], array $lang = [], array $theme = [], array $paths = []): void`
Forcefully regenerates JSON locale files from the database for selected language(s), theme(s), and file type(s).

---

## Features

### Localization Scope Support
- **Global**: Platform-wide shared strings
- **Theme**: Theme-specific (e.g., branding)
- **Local**: Route-specific overrides (e.g., `/checkout`, `/about`)

### Auto Translation
- Uses `translation_callback(language, string)` to translate fallback strings when needed.

### Custom Parameters
- Dynamically inject values into translations using tokens like `<{platform_name}>`, `<{current_year}>`.

### Unknown Locales Learning
- Strings not found in any locale file are added to `unknown_locales`, ready for learning with `learn_unknown_locales()`.

### Caching & Locking
- Multiple lock files ensure no race conditions occur during rebuilds or learning phases.

### Session-Aware Debugging
- If `$_SESSION['show_locale_names']` is set, untranslated keys will be returned directly.

---

## Locale File Paths

| Scope  | JSON File Path Format |
|--------|------------------------|
| Global | `global_i18n_locales/%language%/static_locales/global.json` |
| Theme  | `%theme%/DYN_i18n_locales/%language%/static_locales/global.json` |
| Local  | `%theme%/DYN_i18n_locales/%language%/static_locales%active_page%.json` |

---

## Database Schema (`locales_table`)
Each row represents a string in a specific language and scope.

| Field    | Type     | Description                      |
|----------|----------|----------------------------------|
| `id`     | INT      | Primary key                      |
| `lang`   | VARCHAR  | Language code (e.g., `en`, `fr`) |
| `theme`  | VARCHAR  | Theme name (nullable)            |
| `path`   | TEXT     | Local path (nullable)            |
| `type`   | ENUM     | One of `local`, `theme`, `global`|
| `name`   | TEXT     | Locale key name (uppercase)      |
| `string` | TEXT     | Translated string                |
| `edit_time` | TIMESTAMP | Last updated timestamp       |

---

## Notes
- Locale rebuilds are done using the database and then written to JSON files for performance.
- All locale operations are logged with `tracelog()`.
- Functions gracefully handle missing locale files and invalid JSON.
- Highly scalable for large multilingual applications.