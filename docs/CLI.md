# CLI Reference

Dataphyre commands are explicit maintenance and development tools. They do not
run on web requests.

Use `php <script> --help` for PHP scripts and `-Help` for PowerShell scripts.
Set `DATAPHYRE_PROJECT_ROOT` when a runtime command is executed from a Composer
vendor install and needs to find the consumer project root.

## Install And Package Commands

| Command | Purpose |
| --- | --- |
| `php vendor/dataphyre/dataphyre/installer/init_consumer.php --root=.` | Copy the minimal consumer files into a Composer project. |
| `php common/dataphyre/installer/install.php init --root=<project>` | Create `dataphyre.project.json` for an installer-managed project. |
| `php common/dataphyre/installer/install.php install --root=<project> [--source=<path>]` | Copy the configured Dataphyre source into `common/dataphyre` and refresh the lock. |
| `php common/dataphyre/installer/install.php update --root=<project> [--source=<path>]` | Update the managed Dataphyre tree and refresh the lock. |
| `php common/dataphyre/installer/install.php lock --root=<project>` | Recompute `dataphyre.lock` after intentional installer-managed changes. |
| `php common/dataphyre/installer/install.php verify --root=<project>` | Verify the managed tree against `dataphyre.lock`. |
| `php common/dataphyre/installer/install.php doctor --root=<project>` | Print installer state for diagnostics. |

## Runtime Maintenance Commands

| Command | Purpose |
| --- | --- |
| `php runtime/modules/routing/kernel/compile_app_routes.php <application>` | Compile routes for an application. |
| `php runtime/modules/mvc/kernel/route_list.php [app] [--json]` | List MVC routes as a table or JSON. |
| `php runtime/modules/mvc/kernel/cache_routes.php [app]` | Write the configured MVC route manifest cache. |
| `php runtime/modules/mvc/kernel/clear_cached_routes.php [app]` | Remove the configured MVC route manifest cache. |
| `php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=example_app --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status` | Generate app-owned SQL table artifacts. |
| `php runtime/modules/permission/kernel/permission_check.php --manifest=<path>` | Audit a permission manifest. |

## Route-Free Verification Commands

| Command | Purpose |
| --- | --- |
| `php runtime/modules/panel/kernel/panel_regression.php --example` | Run the route-free Panel regression example suite. |
| `php runtime/modules/panel/kernel/panel_field_catalog_check.php` | Check Panel field, renderer, theme, and asset catalog behavior. |
| `php runtime/modules/mvc/kernel/mvc_regression.php` | Run the route-free MVC, Routing, controller, middleware, and module integration regression harness. |

## Contributor Source-Checkout Tools

These tools live under `dev/tools/public/` and support contributors working on
Dataphyre itself. They are not framework runtime API.

| Command | Purpose |
| --- | --- |
| `./dev/tools/public/lint_php.ps1 [-Php <path>]` | Lint real PHP files while skipping generated state and fixtures. |
| `php dev/tools/public/mcp_config.php` | Print a local MCP client config. |
| `php dev/tools/public/mcp_live_validate.php [--php <path>]` | Validate the MCP server over stdio. |
| `php dev/tools/public/mcp_self_test.php` | Run the MCP self-test suite. |
| `./dev/tools/public/check_trace_dialback_usage.ps1` | Check trace and dialback naming and coverage rules. |
| `./dev/tools/public/report_trace_dialback_coverage.ps1 [-ModuleName <module>]` | Report trace/dialback coverage candidates. |
| `php dev/tools/public/benchmark_hot_paths.php [scenario] [iterations] [warmup]` | Run focused hot-path benchmarks for Dataphyre framework changes. |
| `./dev/tools/public/benchmark_hot_paths_matrix.ps1 [-Profiles baseline,opcache,opcache-jit]` | Run benchmark scenarios across PHP/opcache/JIT profiles. |
