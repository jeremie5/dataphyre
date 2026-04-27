# Changelog

All notable public-release preparation changes are tracked here.

## Unreleased

### Changed

- Prepared Dataphyre for a public MIT re-release.
- Normalized Dataphyre-owned PHP headers to MIT/SPDX.
- Clarified the repository layout as an embedded Dataphyre installation with
  `runtime/` as the reusable engine boundary.
- Added a complete module index with status labels for core, optional, adapter,
  legacy, and experimental modules.
- Added compact public documentation for every module under `runtime/modules/`.
- Added release verification tooling in `tools/release_check`.
- Added public architecture, package contract, stability, export, and
  third-party notice documentation.
- Added GitHub Actions CI for Composer metadata validation, release surface
  checks, and PHP linting.
- Renamed Dpanel JSON fixtures from `.php` to `.json` and fixed malformed JSON.

### Notes

- Dataphyre is now released under the MIT License.
- `private_adapter` and `private_adapter` are documented as embedded-install
  adapters, not core runtime dependencies.
- Legacy and experimental modules remain labeled in `MODULES.md` until their
  public APIs, schemas, and configuration contracts are fully stable.
