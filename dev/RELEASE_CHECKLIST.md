# Public Release Checklist

Use this checklist before publishing a Dataphyre release. It intentionally avoids
repository setup commands so it can be used whether Dataphyre is embedded in a
larger project or prepared as a standalone export.

## Public Surface

- [ ] `docs/README.md` explains the install shell and `runtime/` boundary.
- [ ] `docs/GETTING_STARTED.md` and `examples/minimal/` describe a runnable embedded
  install path.
- [ ] `docs/ARCHITECTURE.md` matches the actual bootstrap, application discovery, and
  configuration flow.
- [ ] `docs/CONFIGURATION.md` matches `flight_sheet.example.php`, app definitions,
  and `config/*.example.php`.
- [ ] `docs/PACKAGE.md` and `composer.json` describe the same Composer/runtime
  contract.
- [ ] `docs/STABILITY.md` defines the public compatibility policy for the release.
- [ ] `dev/PUBLIC_EXPORT.md`, `.gitignore`, `.gitattributes`, and `.distignore`
  agree on source-control boundaries, install-local state, and package/archive
  boundaries.
- [ ] `runtime/README.md` reflects the released module tree.
- [ ] `docs/MODULES.md` lists every public module and clearly marks adapters,
  legacy modules, and experimental modules. Application-owned modules stay out
  of public module documentation and package artifacts.
- [ ] `LICENSE` and `docs/NOTICE.md` match the intended public license.
- [ ] `docs/THIRD_PARTY_NOTICES.md` lists bundled third-party and service client code.
- [ ] Community files are present under `docs/`: `CONTRIBUTING.md`, `SUPPORT.md`,
  `SECURITY.md`, and `CODE_OF_CONDUCT.md`.
- [ ] GitHub issue templates, pull request templates, and CI workflows are
  contributor support rather than runtime package API.

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
- [ ] Framework-level changes satisfy `dev/QUALITY_GATES.md`: reusable concept,
  inspectable contract, provenance, verification, and small runtime surface.
- [ ] Agentic/corporate-ready claims satisfy `docs/AGENTIC_ENTERPRISE.md`:
  extension boundary, MCP safety, release hygiene, and Dataphyre-only hot-path
  benchmark scope.
- [ ] MCP public docs and guidelines name the live coverage, readiness, safety,
  enterprise-audit, and aggregate verification surfaces.
- [ ] Product-specific public modules are clearly marked as adapters or
  embedded-install integrations.
- [ ] Legacy and experimental modules remain clearly marked in `docs/MODULES.md`.
- [ ] Any changed module status matches `docs/STABILITY.md` and release notes.
- [ ] Generated fixtures use accurate file extensions.

## Verification

- [ ] Added or changed production hot-path code follows `dev/PERFORMANCE.md`:
  shared framework behavior, lean implementation, benchmark evidence when
  performance-sensitive, and targeted behavior verification.
- [ ] Package validation passes against the prepared package tree.
- [ ] Prepared package artifact contains `RELEASE_MANIFEST.json` when a manifest
  is generated, with non-sensitive export counts, public module inventory,
  bundled component inventory, per-file hashes, deterministic tree hash, omitted
  artifact categories, and verification entries.
- [ ] `./dev/tools/public/lint_php.ps1` passes for real PHP files when running a
  narrower PHP-only check.
- [ ] Composer metadata validates.
- [ ] Composer metadata does not advertise unsupported autoload behavior.
- [ ] Markdown local links pass.
- [ ] JSON fixtures parse successfully.
- [ ] Public CI validates Composer metadata, PHP linting, and MCP validation.
- [ ] Package validation completes before publication.

## Embedded Install Review

- [ ] Install-level config remains separate from `runtime/`.
- [ ] Cache and log output remain generated state, not public source.
- [ ] `flight_sheet.php` is reviewed for the release context.
- [ ] Local `config/*.php`, plugin hooks, keys, cache, and logs are excluded or
  replaced with public `*.example.php` templates.
- [ ] Local `plugins/mcp/*.json` declarations are excluded from package
  artifacts.
- [ ] Prepared package artifact was built from a clean Git worktree.
- [ ] Export verification has no forbidden local files, high-confidence secret
  markers, or app-owned runtime/asset ownership markers.
- [ ] Public docs explain which files are install-specific.

## Release Notes

- [ ] Note that Dataphyre is now MIT licensed.
- [ ] Note any renamed fixtures or documentation path changes.
- [ ] List modules whose public status changed.
- [ ] Call out any modules that remain legacy or experimental.

