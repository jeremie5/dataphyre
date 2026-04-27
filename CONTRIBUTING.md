# Contributing to Dataphyre

Thanks for helping improve Dataphyre.

## Project Shape

Dataphyre is currently laid out like an embedded installation:

- `runtime/` contains the reusable engine and module code.
- `config/`, `plugins/`, `cache/`, `logs/`, and `flight_sheet.php` belong to the
  installation shell around the runtime.

Most framework changes should happen under `runtime/`. Installation-specific
changes should be clearly identified in the pull request.

For support expectations and issue triage, see [SUPPORT.md](SUPPORT.md).

## Local Checks

Before opening a pull request:

1. Run `./tools/release_check`.
2. Run `./tools/lint_php.ps1`.
3. Review Markdown links for docs you touched.
4. Include focused tests or reproduction notes when behavior changes.
5. Keep generated cache and log output out of commits.

For a prepared public export tree, use
`./tools/prepare_export -Output C:\path\to\dataphyre-export`, or run
`./tools/check_export -Root C:\path\to\dataphyre-export` against an
existing sanitized output.

If Composer is available, `composer install` should succeed from the repository
root. The project does not yet require Composer to boot the runtime.

## Pull Requests

Use small, focused pull requests when possible. Include:

- What changed
- Why it changed
- How it was verified
- Any compatibility notes for embedded installations

By contributing, you agree that your contributions are licensed under the same
license as Dataphyre.
