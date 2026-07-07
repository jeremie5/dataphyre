# Contributing to Dataphyre

Thanks for helping improve Dataphyre.

## Project Shape

Dataphyre is currently laid out like an embedded installation:

- `runtime/` contains the reusable engine and module code.
- `config/`, `plugins/`, `cache/`, `logs/`, and `flight_sheet.php` belong to the
  installation shell around the runtime.
Most framework changes happen under `runtime/`. Installation-specific changes
are clearly identified in the pull request.

Most MCP users are application agents building applications with Dataphyre, not
Dataphyre maintainers. Contributor checks are for framework work,
release-surface work, MCP publication work, or shared hot-path changes.

When building an application on Dataphyre, do not modify framework internals just
to make that application work. Use configuration, dialbacks, callbacks, plugins,
MCP metadata, application-owned adapters, or a reusable runtime module. Core
edits should be reserved for Dataphyre framework development and should
describe the framework-level behavior they change.

Dataphyre-owned PHP code follows Dataphyre Coding Standard 1.

Framework-level changes should satisfy reusable concept, inspectable contract,
provenance, verification, and small-runtime-surface expectations.

Added or changed production hot-path code should keep shared framework behavior
lean, record benchmark proof when performance-sensitive, and keep
application-specific tuning outside Dataphyre internals.

For support expectations and issue triage, see [SUPPORT.md](SUPPORT.md).

## Local Checks

Before opening a Dataphyre framework, release, or MCP pull request, review
Markdown links for docs you touched, include focused tests or reproduction notes
when behavior changes, and keep generated cache and log output out of commits.

Prepared public exports include `RELEASE_MANIFEST.json` so consumers can inspect
non-sensitive release provenance.

If Composer is available, `composer install` succeeds from the repository root.
The project does not yet require Composer to boot the runtime.

## Pull Requests

Use small, focused pull requests when possible. Include:

- What changed
- Why it changed
- How it was verified
- Any compatibility notes for embedded installations

By contributing, you agree that your contributions are licensed under the same
license as Dataphyre.
