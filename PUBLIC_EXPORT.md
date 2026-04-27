# Public Export Boundary

Dataphyre is currently embedded in a live product tree. The public release
should keep reusable runtime source, examples, and documentation while excluding
install-local bootstrap files, generated state, and deployment secrets.

## Public Source

These paths are intended for the public repository:

- `runtime/` for the reusable runtime engine and module documentation.
- `examples/` for runnable public templates.
- `tools/` for release checks and maintenance scripts.
- `.github/` for public issue, pull request, and CI metadata.
- Root documentation and package metadata, including `README.md`,
  `GETTING_STARTED.md`, `MODULES.md`, `CHANGELOG.md`, `composer.json`,
  `LICENSE`, and `NOTICE.md`.
- `flight_sheet.example.php` and `index.example.php` as install templates.
- `config/README.md`, `config/*.example.php`, and public language data under
  `config/date_translation/languages/`.
- `plugins/README.md` and empty plugin hook directories.

## Local Install State

These paths are local to an installation and should not be exported as public
source:

- `flight_sheet.php`
- `index.php`
- `direct_access_key`
- `app_override_key`
- `config/*.php`
- `config/*.php-`
- `config/static/*`
- `plugins/pre_init/*.php`
- `plugins/post_init/*.php`
- `cache/*`
- `logs/*`
- `runtime/cache/`
- `runtime/logs/`
- `runtime/sql_migration/plans/`
- `runtime/sql_migration/snapshots/`
- `sql_migration/table_versions.json`
- `vendor/`
- `composer.lock`

The repository includes both `.gitignore` and `.distignore` entries for these
paths. The ignore rules are intentionally conservative because install-specific
files may contain application names, service credentials, local keys, or
environment-only routing.

## Export Verification

To build a sanitized public tree from the embedded working copy:

```powershell
./tools/prepare_export -Output C:\path\to\dataphyre-export
```

The output directory must be outside the source tree and either missing or
empty. The script copies public files, applies `.distignore`, then runs both
export and release checks against the output.

To verify an already-prepared public tree:

```powershell
./tools/check_export -Root C:\path\to\dataphyre-export
```

The script checks required public files, files matched by `.distignore`, and
high-confidence secret markers such as live Stripe keys or private key blocks.

The current embedded Shopiro tree is expected to contain local files. To verify
the script wiring against that tree without failing on expected local state:

```powershell
./tools/check_export -WarnOnly
```

CI runs the public export check directly on the clean checkout and also runs the
export preparation script into a temporary directory.

## Template Flow

For a new public-friendly install:

1. Copy `flight_sheet.example.php` to `flight_sheet.php`.
2. Copy `index.example.php` to `index.php`.
3. Copy only the needed files from `config/*.example.php` to matching local
   `config/*.php` files.
4. Put application-specific plugin hooks under `plugins/pre_init/` and
   `plugins/post_init/`.
5. Run `./tools/prepare_export -Output <prepared-export>` to build
   the sanitized public tree.
6. Run `./tools/check_export -Root <prepared-export>` against the
   sanitized public tree.
7. Run `./tools/release_check` before publishing or tagging a release.

The minimal embedded example in [examples/minimal](examples/minimal/README.md)
shows the same shape with a tiny application bootstrap.
