# Minimal Embedded Install

This example is a small smoke-test application for Dataphyre's embedded runtime
shape. It keeps the runtime in the Dataphyre install and places the application
under an explicit application root.

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
`examples/minimal/applications/example_app`, so the application can stay in this
directory for a first run.

The example intentionally sets `is_production` to `false` and disables
direct-access protection. Treat it as a local bootstrap template, not as a
production security profile.

For the broader install flow, see [Getting started](../../GETTING_STARTED.md).
