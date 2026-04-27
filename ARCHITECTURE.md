# Architecture

Dataphyre is organized as an embedded runtime. The reusable framework code lives
under `runtime/`; the parent directory is an install shell that supplies
bootstrap settings, module configuration, plugins, cache state, and logs.

## Runtime Boundary

The runtime boundary is intentionally narrow:

```text
runtime/
  bootstrap.php
  bootstrap_config.php
  modules/
```

`runtime/bootstrap.php` is the public entrypoint. It resolves install settings,
defines boot constants, applies direct-access controls, loads the core kernel
bootstrap, and delegates application execution to `\dataphyre\runtime::boot()`.

The install shell stays outside the reusable runtime:

```text
flight_sheet.php       local install bootstrap sheet
config/                install-level module config overlays
plugins/               install-level hook files
cache/                 generated state
logs/                  generated logs
```

For the public release split, see [Public export boundary](PUBLIC_EXPORT.md).

## Boot Flow

The request boot path is:

1. The web entrypoint requires `runtime/bootstrap.php`.
2. `bootstrap_config::resolve()` loads `flight_sheet.php`, merges defaults, and
   normalizes configured application roots.
3. `runtime/bootstrap.php` defines constants such as `DATAPHYRE_PROJECT_ROOT`,
   `DATAPHYRE_RUNTIME_ROOT`, `DATAPHYRE_APPLICATION_ROOTS`, `IS_PRODUCTION`,
   `LICENSE`, and `APP`.
4. Direct-access and application-override guards are applied when enabled.
5. `runtime/modules/core/kernel/bootstrap.php` loads the small core kernel
   classes used for application discovery.
6. `\dataphyre\runtime::boot()` locates the selected application and loads its
   application definition.
7. Dataphyre executes the first available application boot path:
   `compiled_routes_file`, then `framework_bootstrap_file`, then
   `legacy_bootstrap_file` when legacy fallback is enabled.

The implementation entry points are:

- [runtime/bootstrap.php](runtime/bootstrap.php)
- [runtime/bootstrap_config.php](runtime/bootstrap_config.php)
- [runtime/modules/core/kernel/runtime.php](runtime/modules/core/kernel/runtime.php)
- [runtime/modules/core/kernel/application_definition.php](runtime/modules/core/kernel/application_definition.php)
- [runtime/modules/core/kernel/app_locator.php](runtime/modules/core/kernel/app_locator.php)

## Application Discovery

Dataphyre chooses the application from `bootstrap.app` in `flight_sheet.php`,
unless `allow_app_override` or `HTTP_X_DATAPHYRE_APPLICATION` changes it during
bootstrap.

Applications are searched under:

```text
<project-root>/applications/
<parent-of-project-root>/applications/
<configured application roots>
```

Relative roots in `flight_sheet.php` are resolved from the project root. The
environment variable `DATAPHYRE_APPLICATION_ROOTS` can append more roots using
the platform path separator.

## Application Definition

An application may provide `app.php`. The file must return an array or a
`\dataphyre\application_definition` instance. When it returns an array, Dataphyre
first builds the conventional definition, then applies the overrides.

Conventional application files are:

```text
rootpaths.php
routes.php
backend/dataphyre/cache/routes.compiled.php
framework_bootstrap.php
application_bootstrap.php
framework/
```

If `framework/` exists, Dataphyre registers an application namespace prefix of
`<app-name>\framework\`.

The preferred public example uses an explicit `app.php` pointing to
`framework_bootstrap.php`. See [Getting started](GETTING_STARTED.md) and the
[minimal embedded example](examples/minimal/README.md).

## Module Layout

Runtime modules live under `runtime/modules/<module>/`. A module may include:

```text
kernel/          legacy/runtime entry files
Framework/       framework-facing classes
documentation/   module docs
unit_tests/      fixtures or smoke-test assets
third_party/     bundled upstream code, when needed
```

The complete public module status table is in [MODULES.md](MODULES.md).

## Configuration Layers

Module configuration is resolved by helper functions in the core kernel. For a
module named `stripe`, Dataphyre checks install-level files named
`config/stripe.php` under both shared and app-specific Dataphyre roots, then a
compiled cache overlay at `cache/config/stripe.compiled.php` when present.

Config files may either return the module config directly or wrap it under:

```php
return [
    'dataphyre' => [
        'stripe' => [
            // module options
        ],
    ],
];
```

Public config examples live as `config/*.example.php`. Local `config/*.php`
files are install state and should stay out of the public export.

For the concrete bootstrap, application, and module config keys, see
[Configuration reference](CONFIGURATION.md).

## Plugins

Install plugins are loaded from `plugins/pre_init/*.php` and
`plugins/post_init/*.php`. They are meant for local integration glue, not
portable runtime features. Reusable behavior should live in a runtime module.

## Generated State

Dataphyre creates and reads generated state during boot, including verification
markers, cache overlays, load-level files, local keys, and logs. Those files are
not source code and are excluded by `.gitignore` and `.distignore`.

The public export scripts enforce that boundary:

- [tools/prepare_export](tools/prepare_export)
- [tools/check_export](tools/check_export)
- [tools/release_check](tools/release_check)
