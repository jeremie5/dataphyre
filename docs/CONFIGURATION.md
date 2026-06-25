# Configuration Reference

Dataphyre has three main configuration layers:

- install bootstrap configuration in `flight_sheet.php`;
- application definitions in each application's `app.php`;
- module configuration overlays under `config/`.

Keep local configuration out of public exports. Public examples use
`*.example.php` filenames so they can be copied into place without exposing
embedded-install state.

## Application-Agent Boundary

Application agents should treat configuration as the first app-owned extension layer.
Prefer `flight_sheet.php`, application `app.php` definitions, module config
overlays, dialbacks, callbacks, plugins, MCP metadata, application-owned adapters,
or reusable modules before proposing Dataphyre runtime-internal edits.

## Flight Sheet

`flight_sheet.php` lives beside `runtime/` in the Dataphyre install root. It
returns an array with optional `bootstrap` and `install` sections.

Start from [flight_sheet.example.php](../flight_sheet.example.php):

```php
<?php

return [
    'bootstrap' => [
        'app' => 'example_app',
        'is_production' => true,
        'application_roots' => [
            __DIR__.'/examples/minimal/applications',
        ],
    ],
];
```

`runtime/bootstrap_config.php` loads this file and merges it with runtime
defaults.

## Bootstrap Keys

| Key | Default | Purpose |
| --- | --- | --- |
| `app` | `example_app` | Selected application name. Dataphyre looks for a matching directory under application roots. |
| `prevent_keyless_direct_access` | `true` | Requires a generated `direct_access_key` for direct requests unless trusted internal traffic rules apply. |
| `allow_app_override` | `true` | Allows app switching with a generated `app_override_key`. Public templates set this to `false`. |
| `is_production` | `true` | Controls production behavior such as whether bootstrap exceptions are shown directly. |
| `max_execution_time` | `30` | Passed to PHP's `set_time_limit()` during bootstrap. |
| `application_roots` | `[]` | Extra application root directories. Relative paths are resolved from Dataphyre's project root. |
| `public_ip_address` | `null` | Optional server address override for proxy or tunnel deployments. |
| `web_server_port` | `null` | Optional port paired with `public_ip_address`. |
| `license` | `false` | Install-provided license metadata. Dataphyre itself is MIT; this is install metadata. |
| `flightdeck` | see below | Developer control surface settings. |

`HTTP_X_DATAPHYRE_APPLICATION` can select the application before `APP` is
defined. `DATAPHYRE_APPLICATION_ROOTS` can append application roots using the
platform path separator.

## Flightdeck Keys

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Enables the Flightdeck developer surface when the module is present. |
| `password` | `null` | Plain password option for local installs. Prefer `password_hash`. |
| `password_hash` | `null` | Password hash for shared or public-facing installs. |
| `session_ttl` | `43200` | Session lifetime in seconds. |
| `rate_limit.window` | `300` | Login rate-limit window in seconds. |
| `rate_limit.max_attempts` | `5` | Max attempts within the rate-limit window. |
| `debugbar.enabled` | `true` | Enables Flightdeck debugbar behavior when available. |
| `debugbar.memory_limit` | `null` | Optional higher PHP memory limit, such as `128M`, applied only to authenticated debugbar requests. |

Public templates disable Flightdeck by default.

## Install Plan

The optional `install` section is consumed by the core `flight_sheet` helper when
Dataphyre verifies or prepares an install. It can create directories and missing
files under shared and app-specific roots.

Supported file actions:

| Type | Behavior |
| --- | --- |
| `literal` | Writes the configured `contents` when the file is missing. |
| `generated_dpvk` | Generates or copies the Dataphyre private key value into the target file. |
| `generated_verified` | Writes the install verification marker. |
| `copy_if_missing` | Copies a source file when the target is missing. |

Keep generated install artifacts such as `cache/verified`, `config/static/dpvk`,
`direct_access_key`, and `app_override_key` out of public source.

## Application Definition

Each application can provide an `app.php` file. It may return an array or a
`\dataphyre\application_definition` instance.

Common array keys:

| Key | Purpose |
| --- | --- |
| `id` | Public application identifier. Defaults to the application directory name. |
| `root_directory` | Application root directory. Defaults to the discovered app directory. |
| `rootpath_file` | Optional file that defines legacy `ROOTPATH` values. |
| `routes_file` | Optional route definition file. |
| `compiled_routes_file` | Optional compiled route manifest. This is tried first. |
| `framework_bootstrap_file` | Framework-style bootstrap file. This is tried after compiled routes. |
| `legacy_bootstrap_file` | Legacy bootstrap file. Used when fallback is enabled. |
| `autoload` | Namespace prefix map for application code. |
| `options.fallback_to_legacy_bootstrap` | Enables or disables legacy bootstrap fallback. |

If `app.php` is missing, Dataphyre falls back to conventions described in
[ARCHITECTURE.md](ARCHITECTURE.md).

## Module Config

Runtime modules read install-level config from `config/<module>.php`. Public
templates live as `config/*.example.php`.

Config files may return module settings directly:

```php
<?php

return [
    'sessions_cookie_name' => 'DPID',
];
```

or wrap them under the module namespace:

```php
<?php

return [
    'dataphyre' => [
        'stripe' => [
            'test_mode' => true,
        ],
    ],
];
```

Dataphyre checks shared and app-specific Dataphyre config roots, then optional
compiled cache overlays under `cache/config/<module>.compiled.php`.

## Public Templates

Reusable public templates:

- [flight_sheet.example.php](../flight_sheet.example.php)
- [index.example.php](../index.example.php)
- [config/README.md](../config/README.md)
- [config/access.example.php](../config/access.example.php)
- [config/mvc.example.php](../config/mvc.example.php)
- [config/storage.example.php](../config/storage.example.php)
- [config/stripe.example.php](../config/stripe.example.php)
- [config/supercookie.example.php](../config/supercookie.example.php)
- [config/tracelog.example.php](../config/tracelog.example.php)
- [examples/minimal](../examples/minimal/README.md)

Local equivalents without `.example` in the filename are install state and are
excluded by `.gitignore` and `.distignore`.

