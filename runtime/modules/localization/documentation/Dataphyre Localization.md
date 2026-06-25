# Dataphyre Localization

## Overview
The `localization` module provides localized strings through JSON files, with an optional database-backed definition source for teams that want SQL authoring and materialization. It supports three scopes:

- `global`: shared strings across the application
- `theme`: theme-level strings
- `local`: page-specific strings

At runtime, applications read from JSON locale files. In the default database-backed mode, database changes are synced back into those files through `sync_locales()` or `rebuild_locale()`. In file-backed mode, those JSON files are the editable source of truth and the module does not require SQL.

## Module Shape
The module loads its kernel entry from `localization.main.php` by module folder convention.

An optional framework layer is available through `\dataphyre\core::load_framework_module('localization')`, which exposes `Dataphyre\Localization\...` classes without adding overhead to kernel-only applications.

Global helper functions are exposed by [localization.global.php](../kernel/localization.global.php):

```php
locale('global:WELCOME');
__('theme:FOOTER_COPY');
```

## Initialization
The module auto-initializes when loaded. If `dataphyre/localization` config is present, the constructor now uses it as the base initialization state. Explicit runtime initialization still overrides those values.

Preferred application bootstrap:

```php
\dataphyre\localization::init([
	'translation_callback'=>function(string $language, string $string): string {
		return translate(config('app/base_language'), $language, $string);
	},
	'default_language'=>config('app/base_language'),
	'available_languages'=>$available_languages,
	'available_themes'=>[$user_theme],
	'user_theme'=>$user_theme,
	'user_language'=>$lang,
	'custom_parameters'=>[
		'<{platform_name}>'=>config('app/platform_name'),
		'<{company_name}>'=>config('app/company_name'),
		'<{website_name}>'=>config('app/website_name'),
		'<{cdn_url}>'=>config('app/cdn_url'),
	],
	'enable_theme_locales'=>true,
	'enable_global_locales'=>true,
	'database_backed'=>true,
	'locales_table'=>'app.locales',
	'source_branch'=>null,
	'source_commit'=>null,
	'global_locale_path'=>ROOTPATH['backend'].'global_i18n_locales/%language%/static_locales/global.json',
	'theme_locale_path'=>ROOTPATH['themes'].'%theme%/DYN_i18n_locales/%language%/static_locales/global.json',
	'local_locale_path'=>ROOTPATH['themes'].'%theme%/DYN_i18n_locales/%language%/static_locales%active_page%.json',
]);
```

Optional module config loaded from `config/localization.php` and exposed as `DP_LOCALIZATION_CFG`:

```php
return [
	'default_language'=>'en-CA',
	'locales_table'=>'app.locales',
	'database_backed'=>false,
	'source_branch'=>getenv('APP_GIT_BRANCH') ?: null,
	'source_commit'=>getenv('APP_GIT_COMMIT') ?: null,
	'enable_theme_locales'=>true,
	'enable_global_locales'=>true,
];
```

## Constructor Options

| Key | Description |
|---|---|
| `translation_callback` | Callback used when learning unknown locales into non-default languages |
| `default_language` | Fallback language code |
| `available_languages` | Supported language map |
| `available_themes` | Supported theme list |
| `user_theme` | Active theme for lookups and file writes |
| `user_language` | Active language for lookups |
| `custom_parameters` | Additional locale token replacements |
| `enable_theme_locales` | Enables `theme:` locale lookups |
| `enable_global_locales` | Enables `global:` locale lookups |
| `database_backed` | Uses SQL as the locale definition source when true; uses JSON files directly when false |
| `locales_table` | Backing SQL table |
| `source_branch` | Optional branch name stamped on file-backed locale metadata |
| `source_commit` | Optional commit hash stamped on file-backed locale metadata |
| `source_repository_path` | Optional repository path for automatic branch/commit detection |
| `detect_source_from_git` | Enables read-only local `git` detection when source config/env values are absent |
| `global_locale_path` | JSON output path template for global locales |
| `theme_locale_path` | JSON output path template for theme locales |
| `local_locale_path` | JSON output path template for local/page locales |

## Runtime API

### `locale(string $string_name, ?string $fallback_string = null, ?array $parameters = null, ?string $forced_language = null, ?string $forced_page = null): string`
Resolves a locale key, loads the corresponding JSON file into memory on demand, and falls back to the provided string when the key is unknown.

Examples:

```php
locale('global:WELCOME_TITLE');
locale('theme:FOOTER_COPY');
locale('local:CHECKOUT_TOTAL', 'Checkout total');
```

### `__(...)`
Alias for `locale(...)`.

### `validate_language_code(string $lang): string`
Returns the requested language when it is available, otherwise falls back to the default language.

### `state(): array`
Returns the current kernel localization runtime state.

### `apply_state(array $state): void`
Replaces the current kernel localization runtime state. This is mainly intended for framework-level context management.

### `default_language(): ?string`
Returns the configured default language.

### `user_language(): ?string`
Returns the active runtime language.

### `user_theme(): ?string`
Returns the active runtime theme.

### `available_languages(): ?array`
Returns the configured language map.

### `available_themes(): ?array`
Returns the configured theme list.

### `active_page(?string $forced_page = null): string`
Returns the active normalized local page path.

### `get_locales(string $scope, string $path, string $language): array`
Reads and returns locale JSON data for a specific scope/path/language combination.

For `local` scope, page paths are normalized to the module's expected route form before file resolution.

### `database_backed(): bool`
Returns whether SQL is the locale definition source of truth. When false, lookup, definition reads, saves, deletes, and unknown-locale learning operate directly on the configured JSON files.

### `source_snapshot(): array`
Returns the branch, commit, repository path, and detection timestamp associated with localization edits. Explicit `source_branch`/`source_commit` config wins, then common CI environment variables, then read-only local git detection when enabled.

### `locale_parameters(string $string, ?array $parameters = []): string`
Applies built-in and custom replacements such as:

- `<{website_url}>`
- `<{current_year}>`
- `<{current_date}>`

plus any values supplied in `custom_parameters` or in the call-level `$parameters` array.

## Learning Unknown Locales

### `learn_unknown_locales(): int|string`
Processes the unknown locale cache, translates strings into the configured languages, and clears learned entries from the unknown-locales file. Database-backed mode upserts rows into the locales table; file-backed mode writes the configured JSON dictionaries directly.

Possible return values include:

- integer count of learned locale keys
- `already_learning_locales`
- `no_locales_to_learn`
- `no_language_to_learn`
- `no_translation_callback`
- `invalid_unknown_locales`
- `unknown_locales_unwritable`
- `locale_file_unwritable`

## Sync and Rebuild

### `sync_locales(bool $forced = false): void`
Checks for locale table changes after the last successful sync marker and rebuilds affected JSON files. The module now keeps:

- a check marker for scheduler throttling
- a separate sync watermark for database progress
- a list of synced row IDs for the current watermark timestamp

This avoids advancing the rebuild watermark before rows are actually processed.

### `rebuild_locale(?array $type = [], ?array $lang = [], ?array $theme = [], ?array $paths = [])`
Force-regenerates JSON locale files from the database. In file-backed mode, JSON files are already the source and this is a successful no-op.

Examples:

```php
\dataphyre\localization::rebuild_locale(['global'], ['en-CA']);
\dataphyre\localization::rebuild_locale(['theme'], ['fr-CA'], ['genesis']);
\dataphyre\localization::rebuild_locale(['local'], ['en-CA'], ['genesis'], ['/checkout']);
```

Accepted scope values:

- `global`
- `theme`
- `local`

Wildcard behavior:

- empty `lang` means all available languages
- `'*'` in `lang` means all available languages
- `'*'` in `theme` means all available themes

## Storage and Files

In database-backed mode, the module uses:

- SQL table rows as the source of truth
- generated JSON files for fast read performance
- lock files to avoid overlapping rebuild/learning runs
- cache files for unknown locales and sync progress

In file-backed mode (`database_backed => false`), the module uses:

- JSON files as the source of truth
- `.meta.json` sidecars beside edited locale files to record branch/commit provenance
- unknown-locale cache files for fallback capture and learning
- no SQL table registration, sync scan, or rebuild query path

Typical path templates:

| Scope | Path template |
|---|---|
| Global | `global_i18n_locales/%language%/static_locales/global.json` |
| Theme | `%theme%/DYN_i18n_locales/%language%/static_locales/global.json` |
| Local | `%theme%/DYN_i18n_locales/%language%/static_locales%active_page%.json` |

## Database Contract

In database-backed mode, expected fields in `locales_table`:

| Field | Purpose |
|---|---|
| `id` | Primary key |
| `lang` | Language code |
| `theme` | Theme name for theme/local rows |
| `path` | Page path for local rows |
| `type` | `global`, `theme`, or `local` |
| `name` | Uppercase locale key |
| `string` | Localized string value |
| `edit_time` | Last update timestamp |

## Operational Notes

- Unknown locale keys are recorded by name, not duplicated by repeated reads.
- `sync_locales()` rebuilds each affected scope/language/theme/path combination once per pass, even if multiple rows changed.
- Local locale rebuilds resolve file paths from normalized page routes instead of reusing the theme name accidentally.
- If `$_SESSION['show_locale_names']` is set, `locale()` returns the locale key instead of the translated string.
- Locale reads and rebuild operations are instrumented with `tracelog()`.

## Optional Framework Layer

Load it explicitly:

```php
\dataphyre\core::load_framework_module('localization');
```

Facade:

```php
use Dataphyre\Localization\Localization;

$title=Localization::globalString('WELCOME_TITLE');
$checkout=Localization::local('CHECKOUT_TOTAL', 'Checkout total', null, 'fr-CA', '/checkout');
```

Context object:

```php
$translator=Localization::context(language: 'fr-CA', theme: 'genesis', page: '/checkout');

$title=$translator->globalString('WELCOME_TITLE');
$total=$translator->local('CHECKOUT_TOTAL', 'Checkout total');
```

Typed catalog reads:

```php
$catalog=Localization::locales('local', '/checkout', 'en-CA', 'genesis');

if($catalog->has('CHECKOUT_TOTAL')){
	$value=$catalog->get('CHECKOUT_TOTAL');
}
```

Existence and nullable reads:

```php
if(Localization::has('global:WELCOME_TITLE')){
	$title=Localization::translateOrNull('global:WELCOME_TITLE');
}
```

Choice / plural helper:

```php
$summary=Localization::choice(
	$count,
	'global:CART_ITEM_SINGULAR',
	'global:CART_ITEM_PLURAL',
	'global:CART_ITEM_ZERO'
);
```

The choice helper injects both `<{0}>` and `<{count}>` automatically unless you override them explicitly in the parameters array.

Write-side maintenance helpers:

```php
$learn=Localization::learnUnknown();
$unknown=Localization::unknownLocales();
$missing_title=Localization::unknownLocale('WELCOME_TITLE');
$clear_one=Localization::clearUnknown('WELCOME_TITLE');
$clear_all=Localization::clearUnknown();
$sync=Localization::sync(true);
$rebuild=Localization::rebuildSelection(
	Dataphyre\Localization\LocalizationRebuildSelection::local(
		['en-CA'],
		['genesis'],
		['/checkout']
	)
);
```

Unknown locale inspection is exposed through `UnknownLocaleCatalog` and `UnknownLocaleEntry`, so applications can review the pending locale-learning queue without reading the raw cache file directly.

Source locale definition inspection and authoring are exposed through `LocaleDefinitionCatalog` and `LocaleDefinition`, so framework code can work with the configured definition source:

```php
$definitions=Localization::definitions(
	['type'=>'local', 'lang'=>'en-CA', 'theme'=>'genesis', 'path'=>'/checkout'],
	100,
	0
);

$existing=Localization::definition('global', 'en-CA', 'WELCOME_TITLE');

$save=Localization::saveDefinition(
	'local',
	'en-CA',
	'CHECKOUT_TOTAL',
	'Checkout total',
	'genesis',
	'/checkout'
);

$delete=Localization::deleteDefinition(
	'local',
	'en-CA',
	'CHECKOUT_TOTAL',
	'genesis',
	'/checkout'
);
```

Save and delete operations can rebuild the generated locale JSON immediately by leaving `$rebuild=true`, or skip that rebuild explicitly when batching changes.

Batch authoring uses `LocaleDefinitionMutation` and returns `LocaleDefinitionBatchResult`:

```php
$batch_save=Localization::saveDefinitions([
	Dataphyre\Localization\LocaleDefinitionMutation::global('en-CA', 'WELCOME_TITLE', 'Welcome'),
	Dataphyre\Localization\LocaleDefinitionMutation::local('en-CA', 'genesis', '/checkout', 'CHECKOUT_TOTAL', 'Checkout total'),
], true);

$batch_delete=Localization::deleteDefinitions([
	Dataphyre\Localization\LocaleDefinitionMutation::local('en-CA', 'genesis', '/checkout', 'CHECKOUT_TOTAL'),
], true);
```

Batch saves and deletes rebuild each affected locale target once, instead of repeating rebuild work per individual mutation.

Maintenance helpers return `LocalizationMaintenanceResult` objects so application code can inspect `status()`, `ok()`, `noop()`, `count()`, `forced()`, and `selection()` without decoding raw kernel return values.
