# Minimal Embedded Install

This example is a small smoke-test application for Dataphyre's embedded/source
runtime shape and Composer vendor installs. It keeps application code outside
the reusable runtime and points the bootstrap at that app root.

## Files

```text
examples/minimal/
  flight_sheet.example.php
  index.example.php
  applications/
    example_app/
      app.php
      framework_bootstrap.php
```

## Try It Locally

From a Dataphyre install root:

1. Copy `examples/minimal/flight_sheet.example.php` to `flight_sheet.php`.
2. Copy `examples/minimal/index.example.php` to `index.php`.
3. Run `php -S 127.0.0.1:8080 -t . index.php`.
4. Open `http://127.0.0.1:8080/`.

The example flight sheet points directly at
`examples/minimal/applications/example_app` when run from a Dataphyre source
tree, or at `applications/example_app` when copied into a Composer consumer
project.

The example intentionally sets `is_production` to `false` and disables
direct-access protection. Treat it as a local bootstrap template, not as a
production security profile.

## Application Boundary

Application agents should treat this example as app-owned code around the
Dataphyre runtime. Put application-specific behavior in the application
bootstrap, install config, dialbacks, callbacks, plugins, MCP metadata,
application-owned adapters, or reusable modules before proposing Dataphyre
runtime-internal edits.

For the broader install flow, see [Getting started](../../docs/GETTING_STARTED.md).
