# Public Release Checklist

Use this checklist before publishing a Dataphyre release. It intentionally avoids
repository setup commands so it can be used whether Dataphyre is embedded in a
larger project or prepared as a standalone export.

## Public Surface

- [ ] Root `README.md` explains the install shell and `runtime/` boundary.
- [ ] `GETTING_STARTED.md` and `examples/minimal/` describe a runnable embedded
  install path.
- [ ] `ARCHITECTURE.md` matches the actual bootstrap, application discovery, and
  configuration flow.
- [ ] `CONFIGURATION.md` matches `flight_sheet.example.php`, app definitions,
  and `config/*.example.php`.
- [ ] `PACKAGE.md` and `composer.json` describe the same Composer/runtime
  contract.
- [ ] `STABILITY.md` defines the public compatibility policy for the release.
- [ ] `PUBLIC_EXPORT.md`, `.gitignore`, and `.distignore` agree on local
  install files that should stay out of the public release.
- [ ] `runtime/README.md` reflects the actual module tree.
- [ ] `MODULES.md` lists every module and clearly marks adapters, legacy modules,
  and experimental modules.
- [ ] `LICENSE` and `NOTICE.md` match the intended public license.
- [ ] `THIRD_PARTY_NOTICES.md` lists bundled third-party and service client code.
- [ ] Community files are present: `CONTRIBUTING.md`, `SUPPORT.md`,
  `SECURITY.md`, and `CODE_OF_CONDUCT.md`.

## Licensing

- [ ] Dataphyre-owned PHP files use MIT/SPDX headers.
- [ ] Old proprietary, dual-license, or all-rights-reserved wording is absent
  from Dataphyre-owned files.
- [ ] Bundled third-party libraries keep their upstream license files.
- [ ] Third-party code paths are marked vendored in `.gitattributes`.
- [ ] Bundled component updates include a matching third-party notice review.

## Module Readiness

- [ ] Core boot path is documented.
- [ ] Service adapters are documented as opt-in integrations.
- [ ] Shopiro-specific modules are clearly marked as adapters or embedded-install
  integrations.
- [ ] Legacy and experimental modules remain clearly marked in `MODULES.md`.
- [ ] Any changed module status matches `STABILITY.md` and release notes.
- [ ] Generated fixtures use accurate file extensions.

## Verification

- [ ] `./tools/release_check` passes.
- [ ] `./tools/prepare_export -Output <prepared-export>` builds a
  sanitized public tree.
- [ ] `./tools/check_export -Root <prepared-export>` passes against
  the sanitized public tree.
- [ ] `./tools/lint_php.ps1` passes for real PHP files.
- [ ] Composer metadata validates.
- [ ] Composer metadata does not advertise unsupported autoload behavior.
- [ ] Markdown local links pass.
- [ ] JSON fixtures parse successfully.
- [ ] CI workflow validates Composer metadata, release checks, public export
  checks, sanitized export preparation, and PHP linting.

## Embedded Install Review

- [ ] Install-level config remains separate from `runtime/`.
- [ ] Cache and log output remain generated state, not public source.
- [ ] `flight_sheet.php` is reviewed for the release context.
- [ ] Local `config/*.php`, plugin hooks, keys, cache, and logs are excluded or
  replaced with public `*.example.php` templates.
- [ ] Prepared export was built outside the embedded working copy.
- [ ] Export verification has no forbidden local files or high-confidence secret
  markers.
- [ ] Public docs explain which files are install-specific.

## Release Notes

- [ ] Note that Dataphyre is now MIT licensed.
- [ ] Note any renamed fixtures or documentation path changes.
- [ ] List modules whose public status changed.
- [ ] Call out any modules that remain legacy or experimental.
