# CLI Reference

Dataphyre commands are explicit maintenance and development tools. They do not
run on web requests.

Use `php <script> --help` for PHP scripts and `-Help` for PowerShell scripts.
Command examples are relative to the Dataphyre package root. In an embedded
install that is usually `dataphyre`; in a Composer install it is usually
`vendor/dataphyre/dataphyre`. Set `DATAPHYRE_PROJECT_ROOT` when a runtime
command is executed from a Composer vendor install and needs to find the
consumer project root.

## Install And Package Commands

| Command | Purpose |
| --- | --- |
| `php vendor/dataphyre/dataphyre/installer/init_consumer.php --root=.` | Copy the minimal consumer files into a Composer project. |
| `php dataphyre/installer/install.php init --root=<project>` | Create `dataphyre.project.json` for an installer-managed project. |
| `php dataphyre/installer/install.php install --root=<project> [--source=<path>]` | Copy the configured Dataphyre source into `dataphyre` and refresh the lock. |
| `php dataphyre/installer/install.php update --root=<project> [--source=<path>]` | Update the managed Dataphyre tree and refresh the lock. |
| `php dataphyre/installer/install.php lock --root=<project>` | Recompute `dataphyre.lock` after intentional installer-managed changes. |
| `php dataphyre/installer/install.php verify --root=<project>` | Verify the managed tree against `dataphyre.lock`; `check` is an alias. |
| `php dataphyre/installer/install.php doctor --root=<project>` | Print installer state for diagnostics. |

## Runtime Maintenance Commands

| Command | Purpose |
| --- | --- |
| `php runtime/modules/core/kernel/application_release_preflight.php --project-root=<application-project> --application=<id> --environment=<id>` | Predict whether an application can release by validating bootstrap configuration; dry-running its native PostgreSQL plan or applying its SQL-only SQLite manifest inside a disposable application-data root; booting it against the same state over loopback; probing fixed `GET /health`; and loading deterministic realtime callback, scheduler definition, and registered table-definition inventories in isolated record-only state. The table inventory uses the fixed materializer authority without hydrating schema. The command accepts no scripts, executable paths, custom commands, health paths, process definitions, ports, database paths, or migration modes. |
| `php runtime/modules/routing/kernel/compile_app_routes.php <application>` | Compile routes for an application. |
| `php runtime/modules/mvc/kernel/route_list.php [app] [--json]` | List MVC routes as a table or JSON. |
| `php runtime/modules/mvc/kernel/cache_routes.php [app]` | Write the configured MVC route manifest cache. |
| `php runtime/modules/mvc/kernel/clear_cached_routes.php [app]` | Remove the configured MVC route manifest cache. |
| `php runtime/modules/cache/kernel/shared_cache_probe.php --phase=<detect\|write\|read-delete> --challenge=<64-lowercase-hex>` | Run one fixed phase of the `dataphyre.shared_cache_probe.v1` release proof. Cloud invokes it only through the root PID 1 one-shot broker; callers cannot select a key, value, endpoint, TTL, script, or command. |
| `php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=example_app --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status` | Generate app-owned SQL table artifacts. |
| `php runtime/modules/sql/kernel/materialize_registered_tables.php --project-root=<project> --application=<id> --environment=<id>` | Boot the selected application through Dataphyre's fixed runtime path and idempotently materialize every registered framework/application `TableDefinition`, returning bounded canonical JSON evidence. |
| `php runtime/modules/sql/kernel/postgresql_migrate.php --project-root=<project> --app=<id> --environment=<id> --mode=<bootstrap\|rolling\|maintenance>` | Apply immutable application PostgreSQL migrations through the fixed, shell-free native runner boundary. |
| `php runtime/modules/sql/kernel/sqlite_migrate.php --project-root=<project> --app=<id> --environment=<id> [--dry-run]` | Apply an application's immutable SQL-only SQLite manifest to the host-owned `DATAPHYRE_APPLICATION_DATA_ROOT`. The fixed boundary enforces exact post-open database identity plus 999-migration, 2-MiB-per-file, 8-MiB-aggregate, 250,000-token, and 4,096-statement caps. It accepts no database path, PHP callback, script, shell, transaction control, attachment, extension, virtual table, or writable-schema primitive. |
| `php runtime/modules/sql/kernel/seeds.php <list\|status\|apply\|rollback> --app=<name> [--id=<id>] [--dry-run] [--confirm] [--json]` | Discover, audit, apply, or explicitly roll back versioned application SQL seeds. Destructive reset-all is intentionally unavailable. |
| `php runtime/modules/permission/kernel/permission_check.php --manifest=<path>` | Audit a permission manifest. |

## Testing Commands

| Command | Purpose |
| --- | --- |
| `php bin/dataphyre-test run --scope=framework --isolate=auto --parallel=4` | Run first-party JSON and PHP tests with adaptive file batching and bounded workers. |
| `php bin/dataphyre-test run --owner=panel --timeout=60 --memory=1G` | Raise the per-worker defaults (12 seconds, 256M) for an intentionally broad module or coverage gate. |
| `php bin/dataphyre-test list --owner=<module> --kind=code --cases --json` | Inspect a module's code-defined test inventory without executing cases. |
| `php bin/dataphyre-test run --changed=main --why-selected` | Run affected tests and explain every selection decision. |
| `php bin/dataphyre-test run --owner=<module> --source-epoch` | Reject an ordinary focused run if its product-source inventory changes while workers execute. Exact and closed-world coverage enable this automatically. |
| `DATAPHYRE_TEST_CONTAINER_ROOT=1 sh bin/dataphyre-test-docker run --path=dataphyre.core_application_runtime_secret_broker.test.php --source-epoch --no-test-cache` | Run the fixed exact-image proof for root-supervisor secret delivery and the drop to capability-free tenant UID 10001. This narrowly scoped mode rejects writable source mounts, disables external networking, and uses an ephemeral cache; ordinary Docker tests remain unprivileged. |
| `php bin/dataphyre-mutate plan --path=<source-path>` | Build a deterministic mutation plan without changing committed source. |
| `php bin/dataphyre-mutate run --path=<source-path> --limit=<count>` | Run token-level mutants against the smallest owning test suite with recovery journaling. |

`dataphyre-test` accepts `--help` or `-h` before or after a subcommand and
returns help immediately without discovering or running tests.

See [Testing](TESTING.md) for lifecycle metadata, closed-world coverage, module
bootstraps, artifacts, and Windows-focused partial-run guidance.

## Documentation Commands

| Command | Purpose |
| --- | --- |
| `php source-checkout-maintainer-tool --root=. --workspace --output=<path> --version=<semver> --title="Dataphyre Documentation"` | Preview a deterministic Datadoc release assembled from project manuals, every module documentation directory, and bounded support files without loading application or module code. |
| `php source-checkout-maintainer-tool --root=. --source=<manuals> --mount=<prefix>=<directory> --output=<path> --version=<semver> --title=<title>` | Preview a manually composed, root-confined Markdown corpus with repeatable producer mounts. |
| `php source-checkout-maintainer-tool <preview-options> --write` | Atomically publish the exact immutable SemVer release after staged integrity verification; writing is never implicit. |

Workspace and manual source modes are mutually exclusive. Datadoc excludes the
publication output from discovery, rejects recursive symlinks and unsafe or
case-ambiguous paths, and emits a stable JSON result or error envelope. See the
[Datadoc static portal contract](../runtime/modules/datadoc/documentation/Dataphyre_Datadoc_Static_Portal.md)
for the framework API, corpus bounds, release protocol, and deployment boundary.

## Route-Free Verification Commands

| Command | Purpose |
| --- | --- |
| `php runtime/modules/panel/kernel/panel_regression.php --example` | Run the route-free Panel regression example suite. |
| `php runtime/modules/panel/kernel/panel_field_catalog_check.php` | Check Panel field, renderer, theme, and asset catalog behavior. |
| `php source-checkout-maintainer-tool inclusive-quality --name=<name> --url=<root-relative-or-http-url>` | Emit the deterministic locale/input/display/AT quality matrix. |
| `php source-checkout-maintainer-tool inclusive-quality-validate --matrix=<matrix.json>` | Reconstruct and authenticate a bounded matrix plus its canonical browser case mapping. |
| `php source-checkout-maintainer-tool inclusive-quality-gate --matrix=<matrix.json> --capabilities=<capabilities.json> --evidence=<evidence.json>` | Gate sourced, artifact-backed, matrix-bound evidence while reporting automated and adapter/manual results separately. |
| `php runtime/modules/mvc/kernel/mvc_regression.php` | Run the route-free MVC, Routing, controller, middleware, and module integration regression harness. |

## MCP Server Command

The MCP server is a stdio process started by an MCP client, not a normal
interactive command:

| Command | Purpose |
| --- | --- |
| `php runtime/modules/mcp/kernel/dataphyre_mcp.php` | Start the Dataphyre MCP stdio server. |
| `php runtime/modules/mcp/kernel/dataphyre_mcp.php --allow-unsafe` | Start the server with unsafe-gated tools explicitly enabled. |

For client JSON examples and safety boundaries, see
[MCP server](../runtime/modules/mcp/documentation/Dataphyre_MCP.md).
