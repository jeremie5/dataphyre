# Public Export Boundary

Dataphyre is currently embedded in a live product tree. The public release keeps
reusable runtime source, examples, and documentation while excluding
install-local bootstrap files, generated state, and deployment secrets.

## Public Source

These paths are intended for the public repository:

- `runtime/` for the reusable runtime engine and module documentation.
- `examples/` for runnable public templates.
- `docs/` for project documentation, including `README.md`,
  `GETTING_STARTED.md`, `MODULES.md`, `CHANGELOG.md`, and `NOTICE.md`.
- Root package metadata, including `composer.json` and `LICENSE`.
- `flight_sheet.example.php` and `index.example.php` as install templates.
- `config/README.md`, `config/*.example.php`, and public language data under
  `config/date_translation/languages/`.
- `plugins/README.md` and empty plugin hook directories.

Public exports keep only the reusable Dataphyre package surface. Product,
policy, moderation, reporting, and other application-owned modules belong in
private application repositories or local ignored module folders.

Applications that need moderation or event-reporting behavior should provide
their own app-owned modules, adapters, callbacks, or policy services.

## Local Install State

These paths are local to an installation and stay out of source control:

- `flight_sheet.php`
- `index.php`
- `direct_access_key`
- `app_override_key`
- `config/*.php`
- `config/*.php-`
- `config/static/*`
- `plugins/pre_init/*.php`
- `plugins/post_init/*.php`
- `plugins/mcp/*`
- `cache/`
- `logs/`
- `runtime/cache/`
- `runtime/logs/`
- `runtime/sql_migration/plans/`
- `runtime/sql_migration/snapshots/`
- `sql_migration/table_versions.json`
- `vendor/`
- `composer.lock`

Install-local MCP plugin declarations under `plugins/mcp/*.json` are local
integration metadata. They are not part of the public package contract.

## Source Checkout Support

The public repository may include contributor-facing files that are useful when
working on Dataphyre itself but are not part of the runtime contract:

- `dev/` for public contributor checks, review notes, standards, and release
  review documents.
- `.github/` for issue templates, pull request templates, and CI workflows.

These files are source-checkout support. Applications must not call them at
runtime, and framework users should treat `runtime/`, `config/*.example.php`,
`plugins/`, `installer/`, examples, and docs as the package surface.

Benchmark evidence for Dataphyre production hot-path changes is a maintainer
workflow requirement for the changed framework code. Benchmark records are not
a framework API and ordinary application agents should not run or preserve them.

## Package And Archive Boundary

Package/archive builders can use `.gitattributes` export-ignore and
`.distignore` to omit source-checkout support files such as `dev/`, `.github/`,
local state, caches, logs, vendors, and generated files from prepared artifacts.
That archive boundary is separate from the public Git source checkout.

The ignore rules are intentionally conservative because install-specific files
may contain application names, service credentials, local keys, or
environment-only routing.

## Export Verification

Build release packages from a clean source tree and validate the resulting file
set before publishing.

Prepared public trees include `RELEASE_MANIFEST.json`, a non-sensitive export
attestation with the manifest schema, package name, generation tool, source-file
copy/skip counts, public module inventory, bundled third-party component
inventory, per-file byte counts and SHA-256 hashes, a deterministic
`export_tree_sha256`, excluded artifact categories, and verification entries.
The manifest intentionally avoids source paths, host names, user names,
credentials, tenant identifiers, and private adapter names.

Release validation checks required public files, files matched by package
ignore rules, high-confidence secret markers such as live Stripe keys or
private key blocks, local filesystem/deployment path markers, and ownership
markers that must stay out of the public package.

Public CI runs Composer validation, PHP lint, MCP self-test, and live stdio
validation.

## Template Flow

For a new public-friendly install:

1. Copy `flight_sheet.example.php` to `flight_sheet.php`.
2. Copy `index.example.php` to `index.php`.
3. Copy only the needed files from `config/*.example.php` to matching local
   `config/*.php` files.
4. Put application-specific plugin hooks under `plugins/pre_init/` and
   `plugins/post_init/`.
5. Build or package from a clean source checkout.
6. Confirm the prepared artifact contains the expected public files and, when a
   manifest is generated, `RELEASE_MANIFEST.json`.
7. Run release validation before publishing or tagging a release.

The minimal embedded example in [examples/minimal](../examples/minimal/README.md)
shows the same shape with a tiny application bootstrap.

