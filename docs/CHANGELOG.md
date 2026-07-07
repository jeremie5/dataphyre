# Changelog

All notable Dataphyre changes are tracked here.

## Unreleased

### Added

- Added `installer/init_consumer.php`, a small Composer consumer initializer
  that prepares `flight_sheet.php`, `index.php`, and the minimal application
  outside `vendor/`.
- Updated Composer consumer validation to exercise the shipped initializer
  before booting the minimal app.

## 2.0.3 - 2026-07-07

### Added

- Added clean Composer vendor-install boot validation that keeps
  `flight_sheet.php`, `index.php`, and `applications/` in the consumer project
  root instead of writing install-local files into `vendor/`.
- Made the public minimal entrypoint and flight sheet templates work in both
  source/embedded installs and Composer consumer projects.

### Fixed

- Added `DATAPHYRE_PROJECT_ROOT` support during bootstrap config resolution so
  Composer vendor installs can boot applications outside the package directory.
- Aligned public release/version metadata for the bootstrap and MCP surfaces.

## 2.0.2 - 2026-07-07

### Added

- Added source CI coverage for Composer consumer installs from prepared public
  exports and release tags.
- Documented the GitHub VCS Composer install path for projects whose default
  Composer repositories do not yet resolve `dataphyre/dataphyre`.

### Fixed

- Fixed PHP 8.1 compatibility in package lint checks by avoiding newer return
  type syntax in shipped PHP files.
- Fixed release validation portability across Windows PowerShell and PowerShell
  7 on Ubuntu.
- Fixed release manifest verification for portable JSON integer types.
- Fixed MCP command validation on PHP 8.1 by preserving child-process exit
  codes when command output is collected.
- Fixed MCP source-checkout path handling so symlinked `common/dataphyre`
  layouts continue to return portable package-relative paths.

## 2.0.1 - 2026-07-07

### Fixed

- Fixed standalone package installs so `DATAPHYRE_PROJECT_ROOT` resolves to the
  Dataphyre install root instead of its parent directory. Embedded
  `common/dataphyre` installs continue to resolve to the directory above
  `common`.
- Added code-defined regression coverage for standalone and embedded bootstrap
  root resolution.
- Sanitized prepared public export metadata when `.gitattributes` contains
  directory-level private-module export rules.
- Corrected getting-started and runtime documentation for standalone and
  embedded root resolution.

## 2.0 - 2026-06-25

### Changed

- Added [Dataphyre 2.0 migration notes](changelog/v2.0.md) summarizing the
  `a682534470207a31460a5c6626b760d792647e3b` to
  `6adbcbc56000c24be4c94199a9beaa2a4d24ecb3` release jump.
- Prepared Dataphyre for a public MIT re-release.
- Normalized Dataphyre-owned PHP headers to MIT/SPDX.
- Clarified the repository layout as an embedded Dataphyre installation with
  `runtime/` as the reusable engine boundary.
- Added a complete module index with status labels for core, optional, adapter,
  legacy, and experimental modules.
- Added public release entries and docs index links for newer runtime modules,
  including Mailer, MCP, MVC, Permission, Reactor, and Storage.
- Added compact public documentation for every released module under
  `runtime/modules/`.
- Added release verification tooling, including
  checks for Composer package contract drift, fragile PHP self-load guards,
  valid JSON fixtures, missing MIT/SPDX headers, and release hygiene markers.
- Added public architecture, package contract, stability, export, and
  third-party notice documentation.
- Added public export verification for local install files, high-confidence
  secret markers, local filesystem/deployment path markers, and app-owned
  runtime or asset ownership markers.
- Removed product-specific, policy-specific, and internal adapter modules from
  public package artifacts.
- Kept local MCP declarations out of public release packages when internal
  integrations are present in a private worktree.
- Added release checks for required `.gitignore` and `.distignore` rules
  covering install-local config, plugin declarations, generated state, modcache
  files, vendor state, and Composer lock state.
- Normalized prepared export metadata so `.gitattributes` does not retain
  private adapter paths in public release packages.
- Added public export checks for private adapter name markers in sanitized
  metadata and documentation.
- Kept GitHub CI, pull request, and issue template metadata out of public
  runtime packages.
- Added GitHub Actions CI for Composer metadata validation, release surface
  checks, public export preparation, public export checks, PHP linting, MCP
  self-test, and MCP live stdio validation.
- Added `RELEASE_MANIFEST.json` to prepared public exports with public module
  inventory, bundled component inventory, file hashes, and a deterministic export
  tree hash, plus public schema documentation and a machine-readable JSON
  Schema.
- Clarified application-agent release boundaries: MCP users default to
  application agents building apps, while publication validation,
  `dataphyre_mcp_verify_all`, release gates, and Dataphyre hot-path benchmarks
  remain project evidence for framework, MCP publication, release-surface, or
  shared production hot-path work.
- Added standalone release manifest verification tooling for prepared public
  exports.
- Added machine-readable release-boundary fields for ordinary app-agent
  entrypoints, verification, extension ownership, escalation, non-ceremony,
  release content, and project evidence, plus matching Composer app-agent
  entrypoint/profile metadata.
- Renamed Dpanel JSON fixtures from `.php` to `.json` and fixed malformed JSON.
- Normalized Stripe unit-test fixture keys so public export secret scanning does
  not flag fake test credentials as live secrets.
- Fixed PHP lint blockers in Access diagnostics and Localization mutation
  helpers.

### Notes

- Dataphyre is now released under the MIT License.
- Product-specific embedded adapters are application-owned and are not core
  runtime dependencies.
- Local MCP plugin declarations can still describe private integrations for
  application-owned tooling without making them public runtime modules.
- Legacy and experimental modules remain labeled in `MODULES.md` until their
  public APIs, schemas, and configuration contracts are fully stable.
