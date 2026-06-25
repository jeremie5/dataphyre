# Support

Dataphyre is public under the MIT License and is maintained as an open-source
runtime. Support is best-effort unless a separate commercial agreement exists.

## Where To Ask

- Use GitHub issues for reproducible bugs, documentation problems, and focused
  feature proposals.
- Use the bug report template for runtime behavior, module behavior, or
  embedded-install boot failures.
- Use the documentation template when a guide, module page, or release document
  is missing or confusing.
- Send security vulnerabilities privately to `security@dataphyre.com`; do not
  post them in public issues.

## What To Include

For runtime or module bugs, include:

- Dataphyre version or commit/export date.
- PHP version and operating system.
- Web server or CLI context.
- Module or area involved.
- Minimal `flight_sheet.php` shape with secrets removed.
- Short reproduction steps.
- Logs or traces with credentials, tokens, cookies, private hostnames, and local
  keys removed.

Application agents should keep support evidence focused and redacted. Use
MCP diagnostic summaries, route/config/schema metadata, minimal app-owned
reproductions, and focused application or module checks before sharing raw logs
or broad workspace state.

Do not treat `dataphyre_mcp_verify_all`,
or Dataphyre hot-path benchmarks as ordinary support requirements. Those are
for Dataphyre framework changes, release-surface claims, or maintainer-requested
evidence.

For embedded-install issues, also include the application discovery shape:

```text
dataphyre/
  runtime/
  flight_sheet.php

applications/
  your_app/
    app.php
```

## Support Boundaries

The stable public runtime contract is documented in [STABILITY.md](STABILITY.md).
Module status is documented in [MODULES.md](MODULES.md).

- `core` runtime issues have the highest priority.
- `optional` modules are supported through their documentation and reproducible
  issues.
- `adapter` modules may depend on upstream service behavior and credentials that
  maintainers cannot inspect.
- `legacy` modules are maintained for compatibility but may not receive new
  features.
- `experimental` modules are visible for testing and feedback but are not a
  compatibility promise.

## Private Data

Do not include credentials, API keys, private customer data, production cookies,
`config/static/dpvk`, `direct_access_key`, `app_override_key`, or full
production `flight_sheet.php` files in public issues. Also remove tenant names,
billing identifiers, signed URLs, private hostnames, local filesystem paths, and
customer-specific product names before sharing diagnostics.

When in doubt, replace sensitive values with placeholders and describe the shape
of the configuration instead.




