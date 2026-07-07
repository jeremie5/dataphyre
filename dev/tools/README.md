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
- `check_trace_dialback_usage.ps1` checks trace and dialback naming rules.
- `report_trace_dialback_coverage.ps1` reports framework extension coverage.
- `benchmark_hot_paths.php` and `benchmark_hot_paths_matrix.ps1` support
  maintainer hot-path proof for Dataphyre framework changes.

Set `DATAPHYRE_PHP` or pass a `-Php`/`--php` argument when the desired PHP
binary is not on `PATH`.
