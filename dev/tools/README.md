# Public Contributor Tools

The tracked tools in `dev/tools/public/` are for contributors working on
Dataphyre from a Git worktree. They are not framework runtime API and
applications should not call them.

Most tools accept `--help` or `-Help`:

- `lint_php.ps1` lints real PHP files while skipping generated state and
  vendored directories.
- `mcp_config.php` prints a portable local MCP client config.
- `mcp_live_validate.php` validates the MCP server over stdio.
- `mcp_self_test.php` runs the MCP self-test suite.
- `check_trace_dialback_usage.ps1` checks trace and dialback naming rules over
  either release-owned Git source or an explicit recursive filesystem audit.
- `check_trace_dialback_usage_self_test.ps1` proves both source-set boundaries
  with tracked, untracked, ignored, and extracted-tree traps.
- `report_trace_dialback_coverage.ps1` reports framework extension coverage.
- `benchmark_hot_paths.php` and `benchmark_hot_paths_matrix.ps1` support
  maintainer hot-path proof for Dataphyre framework changes.

Datadoc ships a producer-neutral JSON-oriented portal tool directly under
`dev/tools/`:

- `datadoc_docs.php` either discovers the complete Dataphyre documentation
  workspace (`--workspace`) or composes an explicit root-confined Markdown
  corpus (`--source` plus repeatable `--mount`), then previews or atomically
  writes one immutable SemVer release through Datadoc's dependency-free static
  portal engine. Source is never executed, publication output is excluded from
  rediscovery, recursive symlinks are rejected, exact existing trees are
  idempotent, and `--write` is the only publication authority. Product modules
  provide content or thin adapters; they do not own a competing renderer.

Panel also ships six JSON-oriented producer and development tools directly
under `dev/tools/`:

- `panel_developer.php` inspects and diffs manifests, generates resource
  blueprints, and emits responsive or inclusive-quality matrices. Its
  `inclusive-quality-validate` command authenticates the canonical browser case
  map; `inclusive-quality-gate` evaluates sourced capabilities and
  matrix-digest-bound evidence while keeping automated proof separate from
  adapter/manual declarations.
- `panel_scaffold.php` previews or transactionally writes resource, page,
  provider, plugin, theme, test, and suite scaffolds. It is preview-only unless
  `--write` is explicit and confines every target to `--root`.
- `panel_docs.php` tokenizes source without executing it and builds a
  deterministic, versioned API reference, cookbook, and package compatibility
  publication. Its optional static shell delegates to Datadoc's universal
  renderer through Panel's thin compatibility adapter. It is preview-only
  unless `--write` is explicit; source inputs and output remain confined to
  `--root`. Writes publish one immutable, exact-tree-verified SemVer directory
  atomically; replacement and skip policies are intentionally unavailable.
- `panel_package_compatibility.php` expands bounded PHP, Panel, Reactor, module,
  theme, and feature axes into a deterministic package conformance report. It
  reads only root-confined JSON inputs, performs no transport or install work,
  and exits nonzero when compatibility policy fails.
- `panel_filament_migrate.php` statically inventories Filament 3/4/5 resources
  without executing application source. It previews callback-free Panel drafts
  by default and writes only through the transactional scaffold writer.
- `panel_release_evidence.php` issues and verifies expiring source-bound HMAC
  evidence over an exact artifact tree. Signing keys are read from a file and
  never enter arguments, bundle JSON, or verification output.

Set `DATAPHYRE_PHP` or pass a `-Php`/`--php` argument when the desired PHP
binary is not on `PATH`.
