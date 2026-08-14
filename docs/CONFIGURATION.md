# Configuration Reference

Dataphyre has three main configuration layers:

- install bootstrap configuration in `flight_sheet.php`;
- application definitions in each application's `app.php`;
- module configuration overlays under `config/`.

Keep local configuration outside shared package artifacts. Public examples use
`*.example.php` filenames so they can be copied into place without exposing
embedded-install state.

## Application-Agent Boundary

Application agents should treat configuration as the first app-owned extension layer.
Prefer `flight_sheet.php`, application `app.php` definitions, module config
overlays, dialbacks, callbacks, plugins, MCP metadata, application-owned adapters,
or reusable modules before proposing Dataphyre runtime-internal edits.

## Flight Sheet

`flight_sheet.php` lives in the Dataphyre project root. For source or standalone
installs that is the directory beside `runtime/`. For canonical embedded
installs it is the parent of `<project>/dataphyre`. For Composer
vendor installs, the entrypoint can set `$_SERVER['DATAPHYRE_PROJECT_ROOT']` so
the project root is the consumer project directory instead of
`vendor/dataphyre/dataphyre`.

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
        'modules' => [
            'enabled' => ['http', 'mvc', 'routing'],
            'disabled' => ['flightdeck'],
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
| `application_roots` | `[]` | Extra application root directories. Relative paths are resolved from Dataphyre's project root: the install root for standalone installs, the parent of `<project>/dataphyre` for embedded installs, or the explicit `$_SERVER['DATAPHYRE_PROJECT_ROOT']` for Composer vendor installs. |
| `modules.enabled` | `[]` | Authoritative allow-list for the selected application. Omitted names stay disabled even when their module directories exist. |
| `modules.disabled` | `[]` | Explicit deny-list. Denials override matching enabled entries. |
| `public_ip_address` | `null` | Optional server address override for proxy or tunnel deployments. |
| `web_server_port` | `null` | Optional port paired with `public_ip_address`. |
| `license` | `false` | Install-provided license metadata. Dataphyre itself is MIT; this is install metadata. |
| `flightdeck` | see below | Developer control surface settings. |

`HTTP_X_DATAPHYRE_APPLICATION` can select the application before `APP` is
defined. `DATAPHYRE_APPLICATION_ROOTS` can append application roots using the
platform path separator. CLI helper scripts may also read a process environment
variable named `DATAPHYRE_PROJECT_ROOT`; the web/runtime bootstrap override is
the `$_SERVER['DATAPHYRE_PROJECT_ROOT']` value set by the entrypoint before
including `runtime/bootstrap.php`.

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

## Module Enablement

`bootstrap.modules` is normalized once while the selected flight sheet is
loaded. Enabled and disabled names are stored as associative lookup sets, so
kernel presence checks and Framework loading can reject omitted or disabled
modules before reading module paths.

The enabled list is authoritative and the disabled list wins. There are no
implicit runtime or debug overrides. `config/modules.php`, `APP_MODULES`, and
dash-prefixed application module directories no longer enable or disable
modules. `core` is the sole exception: it is a reserved bootstrap dependency
and is implicitly enabled so Dataphyre can enforce the rest of the policy.

Directory presence only makes an already-enabled module resolvable. If an
enabled module is missing its kernel or Framework surface, the corresponding
presence/load check still returns false.

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

The public application identifier is an opaque deployment identity with the
exact ASCII grammar `[A-Za-z0-9:_-]{1,120}`. It is distinct from the framework
application name and must not be reused directly as a filesystem path, host
name, or registry component; infrastructure adapters should derive their own
bounded safe component when one is needed.

If `app.php` is missing, Dataphyre falls back to conventions described in
[ARCHITECTURE.md](ARCHITECTURE.md).

### Legacy ROOTPATH Mapping

Applications that still provide `rootpaths.php` should point the historical
shared-root keys at the canonical embedded directory:

```php
define('ROOTPATH', [
    'root' => __DIR__.'/',
    'common_root' => DATAPHYRE_PROJECT_ROOT,
    'common_dataphyre' => DATAPHYRE_PROJECT_ROOT.'dataphyre/',
    'common_dataphyre_runtime' => DATAPHYRE_PROJECT_ROOT.'dataphyre/runtime/',
    'dataphyre' => __DIR__.'/backend/dataphyre/',
    'applications' => DATAPHYRE_PROJECT_ROOT.'applications/',
]);
```

The `common_dataphyre*` key names remain part of the legacy runtime API; they no
longer imply a physical `common/` directory. New path construction must not
insert `common` between the project root and `dataphyre`.

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
