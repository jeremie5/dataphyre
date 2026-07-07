# Changelog

All notable public-release preparation changes are tracked here.

## 2.0 - 2026-06-25

### Changed

- Added [Dataphyre 2.0 migration notes](changelog/v2.0.md) summarizing the
  `457e55322e606d962355f315f6b3e66acd7f17f3` to
  `e962dbbb9dbdeff3a895945ec4fcf27fb38d66ad` release jump.
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
- Redacted bundled lexical moderation datasets from prepared public exports and
  added public export checks for obvious policy_module markers.
- Redacted legacy asset distribution and fallback/replay modules from prepared
  public exports.
- Redacted InternalModule and PolicyModule from prepared public exports.
- Added private `plugins/mcp/*.json` declarations so internal MCP tooling can
  describe redacted modules in local worktrees without adding them to the public
  release index or prepared export.
- Added release checks that require locally present redacted modules to have
  internal MCP declarations with `release: redacted` and `visibility`.
- Added release checks for required `.gitignore` and `.distignore` rules
  covering install-local config, plugin declarations, generated state, modcache
  files, vendor state, and Composer lock state.
- Normalized prepared export metadata so `.gitattributes` does not retain
  private adapter paths after redacted module directories are omitted.
- Added public export checks for private adapter name markers in sanitized
  metadata and documentation.
- Moved GitHub CI, pull request, and issue template metadata to
  source-only export exclusions so public packages do not expose
  maintainer tool commands.
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
  remain maintainer evidence for framework, MCP publication, release-surface, or
  shared production hot-path work.
- Added standalone release manifest verification tooling for prepared public
  exports.
- Added machine-readable release-boundary fields for ordinary app-agent
  entrypoints, verification, extension ownership, escalation, non-ceremony,
  omitted artifacts, and maintainer evidence, plus matching Composer app-agent
  entrypoint/profile metadata.
- Renamed Dpanel JSON fixtures from `.php` to `.json` and fixed malformed JSON.
- Normalized Stripe unit-test fixture keys so public export secret scanning does
  not flag fake test credentials as live secrets.
- Fixed PHP lint blockers in Access diagnostics and Localization mutation
  helpers.

### Notes

- Dataphyre is now released under the MIT License.
- Product-specific embedded adapters are redacted from public release packages
  and are not core runtime dependencies.
- Internal developer tooling can still discover redacted modules through
  install-local MCP plugin declarations that are omitted from public exports.
- Legacy and experimental modules remain labeled in `MODULES.md` until their
  public APIs, schemas, and configuration contracts are fully stable.




