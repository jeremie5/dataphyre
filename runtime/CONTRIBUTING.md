# Contributing to Dataphyre

Thanks for helping improve Dataphyre.

## Project Shape

The runtime directory contains the reusable engine and module code. In a full
installation, this directory is wrapped by install-level config, plugins, cache
state, logs, and a `flight_sheet.php` bootstrap sheet.

Most framework changes should happen under `runtime/` or one of its module
directories.

## Local Checks

Before opening a pull request:

1. Run PHP linting across changed PHP files.
2. Review Markdown links for docs you touched.
3. Include focused tests or reproduction notes when behavior changes.
4. Keep generated cache and log output out of commits.

If Composer is available, `composer install` should succeed from the repository
root. Composer is metadata-only for now; Dataphyre does not require Composer to
boot.

## Pull Requests

Use small, focused pull requests when possible. Include:

- What changed
- Why it changed
- How it was verified
- Any compatibility notes for embedded installations

By contributing, you agree that your contributions are licensed under the same
license as Dataphyre. See [LICENSE](LICENSE).
