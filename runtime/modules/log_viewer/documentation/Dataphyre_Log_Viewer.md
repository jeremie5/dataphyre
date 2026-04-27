# Dataphyre Log Viewer

## Status

`log_viewer` is an optional legacy diagnostic module. Flightdeck is the preferred
public diagnostic surface, but this viewer remains useful for installations that
need a small standalone log tail.

## Runtime Shape

The route target is:

```text
runtime/modules/log_viewer/kernel/log_viewer.php
```

The file dispatches immediately through `dataphyre_log_viewer::dispatch()`.

## Behavior

- Reads the latest `.html` or `.log` file under `ROOTPATH['dataphyre'].'logs'`.
- Renders an HTML page for browser requests.
- Accepts POST requests with `ajax=1` to poll for new log entries.
- Supports Dataphyre HTML log rows separated by `<!--ENDLOG-->`.
- Supports plain `.log` files by rendering escaped lines.
- Tails large files from the end to avoid loading entire logs on initial render.

## Operational Notes

- Public routing should protect this module. It can expose failure details.
- Newer installs should prefer Flightdeck surfaces for authenticated diagnostic
  access.
- The module is intentionally self-contained and does not provide a framework
  class API.
