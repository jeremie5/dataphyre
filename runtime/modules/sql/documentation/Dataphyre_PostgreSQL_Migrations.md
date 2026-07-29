# Dataphyre PostgreSQL Migrations

Dataphyre SQL provides an application-neutral PostgreSQL migration framework.
Applications own their schema policy, immutable SQL, release identity, database
connection, and rollout authorization. Dataphyre owns manifest validation,
transaction boundaries, advisory locking, journals, drift inspection,
expand/contract checks, and reversible-migration certification.

The public classes are:

- `PostgreSqlMigrationProfile`
- `PostgreSqlMigrationManifest`
- `PostgreSqlSchemaInspector`
- `PostgreSqlMigrationRunner`

They live in `Dataphyre\Database\Migrations`.

## Public API at a glance

| Type | Primary methods | Responsibility |
|---|---|---|
| `PostgreSqlMigrationProfile` | `fromArray(...)`, `compareVersions(...)`, normalized accessors, `jsonSerialize()` | Validate policy and exact Semantic Version precedence. |
| `PostgreSqlMigrationManifest` | `load(...)`, `entries()`, `publicSummary()`, `sqlSafetyIssues(...)` | Load, confine, checksum, and normalize immutable migration history; classify transaction-incompatible SQL through a pure shared contract. |
| `PostgreSqlMigrationRunner` | `status(...)`, `deploymentEvidence(...)`, `apply(...)`, `rollback(...)` | Inspect and mutate one PostgreSQL schema under a transaction-scoped advisory lock. |
| `PostgreSqlSchemaInspector` | `expectedSchema(...)`, `schemaIssues(...)`, fingerprint and certification methods | Derive the supported schema model, inspect catalogs, and certify reversible SQL. |

The runner also exposes pure helpers for rolling-SQL classification, journal
projection, rollback-tail selection, and rollback-safety checks. Normal
application mutation should go through `apply(...)` and `rollback(...)`;
directly calling inspector certification requires the caller to own the
surrounding PostgreSQL transaction.

Invalid profile or method arguments raise `InvalidArgumentException`. Invalid
manifests, drift, ineligible deployment state, failed SQL, and failed
certification raise `RuntimeException` (with the underlying failure chained
where available). Treat both as fail-closed release failures.

## Directory contract

An application passes its `database` directory to
`PostgreSqlMigrationManifest::load(...)`:

```text
database/
└── postgresql/
    ├── manifest.json
    ├── 001_schema_baseline.sql
    ├── 002_add_job_priority.up.sql
    └── 002_add_job_priority.down.sql
```

Bootstrap entries retain `NNN_name.sql`. Later entries use
`NNN_name.up.sql`, with an optional paired `NNN_name.down.sql`. The loader
rejects unlisted SQL, symbolic links, paths outside this directory, checksum
drift, transaction-control statements, concurrent index operations, and psql
meta-commands.

Transaction ownership is lexical and comment/string-aware. Statement-start
`BEGIN`, `START TRANSACTION`, `COMMIT`, `END`, `ABORT`, `ROLLBACK`, `SAVEPOINT`,
`RELEASE`, `PREPARE TRANSACTION`, `SET TRANSACTION`, and
`SET SESSION CHARACTERISTICS AS TRANSACTION` are rejected with or without a
trailing semicolon. `CREATE INDEX CONCURRENTLY`, `DROP INDEX CONCURRENTLY`, and
`REINDEX ... CONCURRENTLY` are also rejected because they cannot run inside the
runner-owned transaction. Dataphyre also rejects PostgreSQL administrative
statements that cannot participate in that transaction: `VACUUM`,
`CREATE DATABASE`, `DROP DATABASE`, `ALTER SYSTEM`, tablespace creation or
deletion, `CLUSTER`, `CHECKPOINT`, `DISCARD ALL`, concurrent materialized-view
refresh, and database/system/tablespace forms of `REINDEX`. The loader and MCP
manifest tooling use the same pure `sqlSafetyIssues(...)` classification.

`load($databaseRoot, ...)` always reads
`$databaseRoot/postgresql/manifest.json`. The profile's
`manifest_public_path` is the release-relative label returned in summaries; it
does not redirect the filesystem read.

## Supported schema introspection grammar

Dataphyre's status-time schema contract deliberately models a bounded,
application-neutral PostgreSQL DDL grammar:

- schema-qualified tables, columns, primary keys, nullability, checks, and
  foreign keys
- ordinary and partial indexes
- modeled table, column, check-constraint, and index removals

The final statement in a migration may omit its semicolon. SQL outside this
grammar can still be valid transactional migration SQL, but it is not silently
promoted into a schema-drift claim. Views, functions, triggers, policies,
custom types, privileges, renames, and other specialized objects need
application-specific verification. A reversible migration whose change is not
observable by Dataphyre's structural fingerprint fails rollback certification
instead of being certified from incomplete evidence.

Bootstrap entries are adopted history and are deliberately outside exact
catalog certification. Dataphyre validates their immutable bytes, replays them
once inside its transaction, and records every checksum through the bootstrap
cutoff. Exact schema-drift certification begins with journal-native
`rolling_expand` and `rolling_contract` entries. Applications adopting legacy
history must therefore keep bootstrap SQL replay-safe and retain
application-owned migration smoke checks; the framework does not pretend its
bounded DDL parser can reconstruct a pre-journal schema perfectly.

## Compared with Laravel-style migrations

The lifecycle is intentionally familiar: an ordered ledger records applied
migrations, pending `up` directions move the schema forward, and declared
`down` directions can move an applied tail back.

Dataphyre uses immutable SQL plus a checksummed manifest instead of mutable PHP
migration classes. That difference makes the exact database program statically
inspectable before application boot, ties every applied direction to immutable
bytes, and keeps release coordination outside request processes. Each migration
also declares a rollout phase and rollback safety; a `down` file is not treated
as proof that mixed-release operation or rollback is operationally safe.

As with Laravel, application code still owns its domain schema and migration
content. Unlike a typical `migrate` command, this framework does not choose a
connection, discover application classes, infer a maintenance window, or
authorize a production rollout.

## Profile

Create one immutable profile in application-owned release code:

```php
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;

$profile=PostgreSqlMigrationProfile::fromArray([
	'application_id'=>'example_app',
	'schema'=>'ExampleApp',
	'journal_table'=>'SchemaMigrations',
	'event_table'=>'SchemaMigrationEvents',
	'release_digest_column'=>'release_sha256',
	'advisory_lock'=>'example_app.postgresql_migrations',
	'bootstrap_ids'=>['001_schema_baseline'],
	'bootstrap_cutoff'=>'001_schema_baseline',
	'manifest_public_path'=>'database/postgresql/manifest.json',
	'lock_timeout'=>'5s',
	'statement_timeout'=>'120s',
]);
```

`application_id`, schema/table identifiers, lock key, bootstrap boundary, and
timeouts are policy, not global Dataphyre defaults. SQL identifiers accept
PostgreSQL mixed case and are always emitted or passed to `to_regclass(...)`
with safe double quoting.

`manifest_public_path` is metadata for public summaries and release evidence.
The loader's on-disk directory contract remains fixed as described above.

`release_digest_column` defaults to `release_sha256`. An application adapter
may choose another neutral storage name. It cannot collide, case-insensitively,
with the event journal's fixed columns: `event_id`, `operation_id`,
`migration_id`, `direction`, `up_checksum_sha256`, `down_checksum_sha256`,
`release_version`, or `occurred_at`.

The profile implements `JsonSerializable`; `json_encode($profile)` returns the
same normalized public configuration as `jsonSerialize()`.

## Manifest v3

The normative machine-readable shape is
[`postgresql-migration-manifest-v3.schema.json`](postgresql-migration-manifest-v3.schema.json).
Dataphyre's loader additionally enforces constraints that JSON Schema cannot
express safely: sequence numbers must be contiguous and ordered, IDs and
filenames must agree, the bootstrap cutoff must occur exactly once, file bytes
must match SHA-256, and every SQL file must be listed.

`source` is application-owned provenance. Its keys use portable nonnumeric
identifiers (`A-Z`, `a-z`, or `_` first; then letters, numbers, `_`, `.`, or
`-`) so JSON Schema, PHP associative decoding, release tooling, and MCP report
the same object shape. Values remain uninterpreted by Dataphyre.

```json
{
  "schema_version": 3,
  "algorithm": "sha256",
  "bootstrap_cutoff": "001_schema_baseline",
  "source": {
    "generator": "application release tooling"
  },
  "migrations": [
    {
      "id": "001_schema_baseline",
      "phase": "bootstrap",
      "up": {
        "path": "001_schema_baseline.sql",
        "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
      },
      "down": null,
      "irreversible_reason": "The supported database history starts at this baseline.",
      "minimum_compatible_release": null,
      "description": "Create the supported baseline schema."
    },
    {
      "id": "002_add_job_priority",
      "phase": "rolling_expand",
      "up": {
        "path": "002_add_job_priority.up.sql",
        "sha256": "123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0"
      },
      "down": {
        "path": "002_add_job_priority.down.sql",
        "sha256": "23456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef01",
        "safety": "lossless"
      },
      "minimum_compatible_release": null,
      "description": "Add a nullable priority column for mixed-release operation."
    }
  ]
}
```

The checksum values above illustrate shape only. Compute each from the exact SQL
file bytes; never copy example checksums into a real manifest.

Treat the manifest as append-only release history:

1. Keep every existing ID, description, phase, path, and checksum unchanged.
2. Append the next contiguous `NNN_lowercase_name` entry and its new SQL files.
3. Compute checksums from the exact bytes that will ship.
4. Validate the complete directory before opening a database connection.
5. Publish the manifest and all referenced SQL in the same immutable release.

The three-digit sequence is part of the current v3 contract. Starting a new
history or renumbering an existing one is not an upgrade mechanism.

Phases have explicit deployment meaning:

- `bootstrap` is the grandfathered immutable prefix. It is irreversible.
- `rolling_expand` is additive and must remain compatible with old and new
  application processes during a rolling deployment.
- `rolling_contract` removes an old shape only after its exact
  `minimum_compatible_release` is universally active and the application has
  established a maintenance drain/barrier.

For a missing down direction, declare a non-empty `irreversible_reason`. For a
down file, declare `safety` as `lossless` or `data_loss`. Data-loss rollback
requires explicit caller opt-in.

## Load and inspect

Manifest validation happens before database work:

```php
use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;

$manifest=PostgreSqlMigrationManifest::load($applicationRoot.'/database', $profile);
$runner=new PostgreSqlMigrationRunner($pdo, $profile);

$state=$runner->status($manifest);
$evidence=$runner->deploymentEvidence($manifest, $state, 'rolling');
```

The runner accepts only a PostgreSQL PDO connection. `status(...)` compares the
ordered journal, immutable checksums, the journal-native expected schema, and
the live PostgreSQL catalog. `deploymentEvidence(...)` explains whether the
pending prefix is eligible for the selected deployment mode. Pass the complete,
unchanged `status(...)` result: nonzero drift, missing manifest entries,
duplicate/unmanifested state entries, and statuses other than `pending` or
`applied` make deployment evidence ineligible.

Deployment modes are intentionally narrow:

- `bootstrap` installs a fresh database's leading immutable bootstrap and
  `rolling_expand` prefix. It stops before the first `rolling_contract` and
  defers that contract plus the remaining tail for maintenance.
- `rolling` applies the leading pending `rolling_expand` prefix that passes the
  conservative compatibility scanner. It stops before the first contract and
  defers that contract plus its tail.
- `maintenance` applies pending `rolling_expand` and `rolling_contract` entries
  transactionally after the bootstrap cutoff. Selecting it is the caller's
  assertion that the application-owned drain/barrier has already succeeded;
  Dataphyre cannot verify that operational fact.

Deployment evidence keeps the complete pending inventory in
`pending_migrations`/`pending_phases` and reports the exact mode-specific work
as `selected_migrations`/`selected_phases`; `deferred_migrations` names the
ordered tail left for another mode. A mode is ineligible when pending work
remains but it selects nothing, so a contract at the pending head produces an
actionable compatibility-finalization error instead of a successful no-op.

For maintenance containing a contract phase, pass the caller-verified minimum
active application release as the fourth argument:

```php
$maintenance=$runner->deploymentEvidence(
	$manifest,
	$state,
	'maintenance',
	$callerVerifiedMinimumActiveRelease
);
```

The evidence always exposes `required_minimum_active_release` and
`verified_minimum_active_release`; `compatibility_floor_satisfied` is a boolean
for maintenance evidence and `null` for other modes. Each pending contract must
be at or below the verified floor. Omitting an applicable floor, supplying an
invalid Semantic Version, or supplying the compatibility argument outside
maintenance fails closed.

Schema inspection covers application-schema tables and columns, primary,
foreign-key, and check constraints, and indexes represented by the supported
SQL parser. It does not claim semantic coverage for views, routines, triggers,
extensions, privileges, or application data invariants. Keep such objects
behind explicit application-owned verification until their syntax is part of
the inspector's public contract.

## Apply and release provenance

Apply only after the application-owned release coordinator accepts the
deployment evidence:

```php
$identity=[
	'release_version'=>'2.4.0',
	'release_sha256'=>hash('sha256', $immutableReleaseManifestBytes),
];

$result=$runner->apply($manifest, 'rolling', false, $identity);
```

The release identity is generic. Both values must be present together or absent
together. The digest is stored in the profile's configured event column.
Adapters may translate an external release vocabulary at their boundary, but
the Dataphyre contract remains `release_version` plus `release_sha256`.

Dataphyre owns the transaction and uses a transaction-scoped PostgreSQL
advisory lock. Migration SQL must not contain `BEGIN`, `COMMIT`, `ROLLBACK`, or
psql meta-commands. Supply a connection that is not already inside a
transaction. Every committed direction appends an immutable event with an
operation ID and checksums.

Maintenance apply accepts the verified floor as its final optional argument and
recomputes evidence after taking the advisory lock:

```php
$result=$runner->apply(
	$manifest,
	'maintenance',
	false,
	$identity,
	$callerVerifiedMinimumActiveRelease
);
```

The caller remains responsible for deriving that floor from its authoritative
fleet state. Dataphyre compares exact Semantic Version precedence: major,
minor, patch, and prerelease identifiers participate; `+build` metadata does
not. `PostgreSqlMigrationProfile::compareVersions(...)` exposes that same
fail-closed comparison contract to application coordinators.

`apply(...)` executes only the IDs in the recomputed `selected_migrations`
evidence and leaves the ordered deferred tail pending. The `dryRun` argument
still executes that selected SQL, journal operations, and post-apply inspection
inside the Dataphyre-owned transaction; it then rolls that transaction back. It
is a database rehearsal, not a static SQL preview.
Use the MCP scaffold and manifest tools when no database access or SQL execution
is acceptable. PostgreSQL effects that are not transactionally reversible, or
effects performed by external routines, remain the SQL author's responsibility
even during a dry run.

## Rollback

Before rollback, derive the contiguous applied tail and inspect its declared
safety. Dataphyre refuses history gaps and checksum drift. Reversible SQL is
certified inside the runner-owned transaction with a down/up/down sequence:

- structural fingerprints must match after repeated down
- `lossless` migrations must preserve row evidence after both down executions
  and reconstruct the original row fingerprints after the intervening up
- any `PDO::exec(...)` failure aborts certification

`rollback($manifest, $targetId, ...)` keeps `$targetId` applied and removes only
the newer contiguous applied tail, newest first. It does not mean “execute the
down direction of `$targetId`.” Crossing an irreversible entry fails closed,
and a `data_loss` entry requires the explicit `$acceptDataLoss` argument.

The application remains responsible for maintenance windows, traffic draining,
worker coordination, backups, and authorization. Dataphyre does not infer that
a cluster is safe to roll back.

## Mixed releases and schema compatibility

Use expand/contract:

1. Add nullable structures accepted by the conservative `rolling_expand`
   scanner.
2. Deploy code that can read both old and new shapes.
3. Backfill through application-owned jobs when needed.
4. Move all consumers to the new shape.
5. Add `rolling_contract` with the exact minimum compatible release.
6. Drain and barrier the application fleet, derive its verified minimum active
   release, and execute the suffix in `maintenance` mode.

Do not expect a framework to make an incompatible schema change safe for an old
binary automatically. Compatibility comes from explicit expand/contract SQL and
application code that tolerates the overlap.

The transactional rolling scanner rejects explicit `CREATE INDEX`. A regular
post-bootstrap `CREATE INDEX` can instead run in `maintenance` mode under the
Dataphyre-owned transaction after the application has drained traffic.
`CREATE INDEX CONCURRENTLY` cannot run in that transaction because PostgreSQL
requires an autocommit protocol; coordinate it outside this framework. Such an
external operation is not journaled or certified by Dataphyre. Do not label an
index operation safe merely because adding an index is logically additive.

## Multiple application servers

Migration state lives in PostgreSQL, not cookies, sessions, or server-local
files. Multiple application servers can inspect the same manifest and journal;
the shared transaction-scoped advisory lock serializes mutation attempts that
use the same profile and PostgreSQL cluster.

Every node must nevertheless ship identical immutable manifest/SQL bytes and
use the same profile policy. The application release coordinator remains
responsible for fleet-wide version floors, traffic drains, worker barriers,
staggered rollout decisions, and ensuring all nodes address the same intended
database before it authorizes mutation.

## MCP support

The Dataphyre MCP server exposes the same neutral contract without connecting
to a database or writing files:

- `dataphyre_sql_migration_catalog`
- `dataphyre_sql_migration_describe`
- `dataphyre_sql_migration_manifest_validate`
- `dataphyre_sql_migration_scaffold_plan`

Use the `dataphyre_sql_migration_workflow` prompt, the
`dataphyre-sql-migrations` skill, and the `dataphyre://sql-migrations` resource
for discovery. The manifest-schema resource is
`dataphyre://sql-migrations/manifest-v3-schema`.

A typical no-write MCP sequence is:

1. Call `dataphyre_sql_migration_catalog`, optionally filtered by `kind`.
2. Call `dataphyre_sql_migration_describe` with one returned stable ID.
3. Call `dataphyre_sql_migration_scaffold_plan` with the neutral profile,
   migration ID, phase, description, and exact SQL strings. Its `ready` result
   reuses the loader's transaction-control, concurrent-index, and psql-command
   safety checks for both directions. With existing history, it also rejects a
   post-cutoff bootstrap entry; without `database_root`, only a self-contained
   initial `001` bootstrap matching `bootstrap_cutoff` can be certified ready.
4. Write the returned plan only through the caller's normal application edit
   workflow.
5. Call `dataphyre_sql_migration_manifest_validate` with the repo-relative
   application database root and the same profile.

None of these MCP tools performs live `status`, `apply`, or `rollback`. Those operations
require a caller-supplied PDO connection and remain outside the MCP server's
read-only/no-write safety boundary.
