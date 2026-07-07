# Getting Started

This guide shows the smallest useful Dataphyre install shape. It is written for
an embedded install, where the Dataphyre runtime lives beside one or more
applications rather than owning the whole project.

## Install Shape

Dataphyre resolves paths from the physical location of `runtime/bootstrap.php`.
If the runtime lives at:

```text
project/
  common/
    dataphyre/
      runtime/
```

then Dataphyre treats `project/` as the project root. By default, it will
look for applications in:

```text
project/applications/
<parent-of-project>/applications/
```

You can also provide explicit application roots in `flight_sheet.php`.

## Minimal Files

A minimal install needs these files:

```text
dataphyre/
  flight_sheet.php
  index.php
  runtime/

applications/
  example_app/
    app.php
    framework_bootstrap.php
```

The public example provides templates for those files:

- [Root flight sheet template](../flight_sheet.example.php)
- [Root entrypoint template](../index.example.php)
- [Example README](../examples/minimal/README.md)
- [Flight sheet template](../examples/minimal/flight_sheet.example.php)
- [Entrypoint template](../examples/minimal/index.example.php)
- [Example app definition](../examples/minimal/applications/example_app/app.php)
- [Example app bootstrap](../examples/minimal/applications/example_app/framework_bootstrap.php)

## Flight Sheet

`flight_sheet.php` is the install-level bootstrap sheet. It selects the default
application and can define extra application roots:

```php
<?php

return [
    'bootstrap' => [
        'app' => 'example_app',
        'is_production' => false,
        'prevent_keyless_direct_access' => false,
        'allow_app_override' => false,
        'application_roots' => [
            __DIR__.'/examples/minimal/applications',
        ],
    ],
];
```

The example disables direct-access protection because it is meant for a local
first run. Production installs make an explicit access-control decision before
exposing `runtime/bootstrap.php`.

The live `flight_sheet.php` file is install-local. Keep reusable defaults in
`flight_sheet.example.php` and copy that file into place for a new install.
For available keys, see [Configuration reference](CONFIGURATION.md).

## Entrypoint

The entrypoint only needs to include the runtime bootstrap:

```php
<?php

require __DIR__.'/runtime/bootstrap.php';
```

For a local smoke test from the Dataphyre install root:

```powershell
php -S 127.0.0.1:8080 -t . index.php
```

Open `http://127.0.0.1:8080/`. The minimal example returns a JSON response from
the application bootstrap.

## Application Definition

Each application can provide `app.php`. Dataphyre first builds a conventional
definition, then lets `app.php` override it:

```php
<?php

return [
    'id' => 'example_app',
    'framework_bootstrap_file' => __DIR__.'/framework_bootstrap.php',
    'options' => [
        'fallback_to_legacy_bootstrap' => false,
    ],
];
```

If `app.php` is absent, Dataphyre falls back to conventions and checks for
`rootpaths.php`, `routes.php`, `backend/dataphyre/cache/routes.compiled.php`,
`framework_bootstrap.php`, and `application_bootstrap.php`.

## Next Steps

Once the minimal app boots, replace the example bootstrap with real routing,
module configuration, and application code. Application agents should put
application-specific behavior in app code, install config, dialbacks, callbacks,
plugins, MCP metadata, application-owned adapters, or reusable modules before
proposing Dataphyre runtime-internal edits. Keep install-specific files such as
`flight_sheet.php`, cache state, local keys, and generated logs separate from the
portable runtime boundary in `runtime/`.

### MCP App-Builder Next Action

For ordinary MCP-assisted app creation, call
`dataphyre_app_builder_plan_generate` with `payload_profile=compact`, then read
`builder_response.first_read.next_action`. Open exactly one compact detail page
with `detail_page=planning|implementation|verification|controls|governance`
when the next edit needs more than the first-read summary, and use
`payload_profile=full` only when skeleton bodies or cross-page context are
needed. Keep writes in app-owned files, use focused app or module checks for
proof, and open readiness, enterprise, release, governance, or hot-path audit
surfaces only when the task actually escalates.

Prepared public releases include `RELEASE_MANIFEST.json` with non-sensitive
release provenance.

