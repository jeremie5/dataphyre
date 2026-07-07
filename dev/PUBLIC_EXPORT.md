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

Public exports also redact runtime pieces that are still embedded-product
specific, policy-sensitive, or unsuitable for a public package:

- legacy asset distribution module source
- legacy fallback/replay module source
- InternalModule event reporting source
- PolicyModule moderation source and bundled lexical moderation datasets

Applications that need moderation or event-reporting behavior should provide
their own app-owned modules, adapters, callbacks, or policy services.

## Local Install State

These paths are local to an installation and stay out of public source:

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
- `runtime/modules/policy_module/`
- `runtime/modules/internal_module/`
- `runtime/sql_migration/plans/`
- `runtime/sql_migration/snapshots/`
- `sql_migration/table_versions.json`
- `dev/`
- `tools/`
- `.github/`
- `vendor/`
- `composer.lock`

The public export preparation script additionally omits the redacted runtime
paths listed above, including bundled moderation datasets, so public release
archives do not ship explicit policy_module terms or embedded-product runtime
modules.

Install-local MCP plugin declarations under `plugins/mcp/*.json` are also
omitted from public exports. Internal worktrees can use those files to describe
redacted modules to Dataphyre MCP without making those modules part of the
public package contract. `dev/tools/release_check` validates those
declarations when redacted modules are present locally.

Contributor tooling and benchmark records live under `dev/`. They are source
development artifacts, not framework runtime files, public API, or release
payload.

GitHub issue templates, pull request templates, and CI workflows are
source-checkout contributor metadata. They are omitted from prepared public
exports because they can reference maintainer-only release tooling, MCP
publication validation, or local source-checkout paths that are not useful for
framework users or application agents.

The repository includes both `.gitignore` and `.distignore` entries for these
paths. The ignore rules are intentionally conservative because install-specific
files may contain application names, service credentials, local keys, or
environment-only routing.

## Export Verification

To build a sanitized public tree from the embedded working copy:

```powershell
./dev/tools/prepare_export -Output C:\path\to\dataphyre-export
```

The output directory must be outside the source tree and either missing or
empty. The script copies public files, applies `.distignore`, removes public
metadata entries that point at redacted runtime paths, then runs both export and
release checks against the output.

Prepared public trees include `RELEASE_MANIFEST.json`, a non-sensitive export
attestation with the manifest schema, package name, generation tool, source-file
copy/skip counts, public module inventory, bundled third-party component
inventory, per-file byte counts and SHA-256 hashes, a deterministic
`export_tree_sha256`, excluded artifact categories, and verification scripts.
The manifest intentionally avoids source paths, host names, user names,
credentials, tenant identifiers, and private adapter names.

To verify an already-prepared public tree:

```powershell
./dev/tools/check_export -Root C:\path\to\dataphyre-export
```

To verify only the prepared export manifest and its file/module/component
attestation:

```powershell
./dev/tools/verify_manifest -Root C:\path\to\dataphyre-export
```

The script checks required public files, files matched by `.distignore`,
high-confidence secret markers such as live Stripe keys or private key blocks,
local filesystem/deployment path markers, and release ownership markers that
must stay out of the public package. Those ownership markers cover legacy
fallback runtimes, server-side asset runtimes, old Dataphyre asset routes,
Shopiro-owned asset hosts/applications, and legacy asset helper classes.
The checker also scans prepared exports for obvious policy_module markers.

The current embedded Shopiro tree is expected to contain local files. To verify
the script wiring against that tree without failing on expected local state:

```powershell
./dev/tools/check_export -WarnOnly -WarningLimit 200
```

CI runs the public export checker in warn-only mode on the source checkout, then
runs the export preparation script into a temporary directory where the prepared
export is checked strictly. CI also runs MCP self-test and live stdio validation
so internal MCP metadata remains compatible with the public export boundary.

## Template Flow

For a new public-friendly install:

1. Copy `flight_sheet.example.php` to `flight_sheet.php`.
2. Copy `index.example.php` to `index.php`.
3. Copy only the needed files from `config/*.example.php` to matching local
   `config/*.php` files.
4. Put application-specific plugin hooks under `plugins/pre_init/` and
   `plugins/post_init/`.
5. Run `./dev/tools/prepare_export -Output <prepared-export>` to build
   the sanitized public tree.
6. Run `./dev/tools/check_export -Root <prepared-export>` against the
   sanitized public tree.
7. Confirm the prepared tree contains `RELEASE_MANIFEST.json`.
8. Run `./dev/tools/release_check` before publishing or tagging a release.

The minimal embedded example in [examples/minimal](../examples/minimal/README.md)
shows the same shape with a tiny application bootstrap.

