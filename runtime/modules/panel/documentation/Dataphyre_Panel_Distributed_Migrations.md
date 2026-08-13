# Dataphyre Panel distributed migrations

Panel's migration runtime executes versioned state transitions under an
exclusive scope/tenant lease. `PanelAtomicMigrationStore` is the crash-safe
local adapter. `PanelPdoMigrationStore` is the first-party shared-database
adapter for MySQL, PostgreSQL, and SQLite.

## Explicit setup

The PDO adapter never creates a connection, obtains credentials, installs its
schema automatically, registers itself with a platform, or starts a migration
worker. The host owns those authorities. PDO exception mode is mandatory so a
storage failure cannot be mistaken for a committed checkpoint.

```php
use Dataphyre\Panel\PanelPdoMigrationStore;
use Dataphyre\Panel\PanelPlatform;

$pdo=new PDO($dsn,$username,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);

$store=new PanelPdoMigrationStore($pdo,[
    'table_prefix'=>'panel_migrations',
    'maximum_scope_bytes'=>64 * 1024 * 1024,
    'change_retention'=>4096,
    'transaction_retries'=>3,
    'retry_delay_microseconds'=>2000,
]);

$platform=PanelPlatform::defaults([
    'state_root'=>$stateRoot,
    'migrations'=>[
        'store'=>$store,
        'definitions'=>$migrationDefinitions,
        'authorize'=>$migrationAuthorizer,
    ],
]);
```

Run the returned SQL through the application's migration authority, or call
`installSchema()` from an explicitly authorized deployment command. Both paths
are idempotent and non-destructive.

```php
foreach(PanelPdoMigrationStore::schemaStatementsFor('pgsql') as $sql){
    $migrationConnection->exec($sql);
}
```

Normal request and worker code must not wrap the store in a caller-owned PDO
transaction. The adapter owns transaction isolation, row locks, commit,
rollback, bounded metadata retries, and SQLite `BEGIN IMMEDIATE` as one
fail-closed boundary.

## Handler and checkpoint atomicity

Migration handlers execute inside the same transaction opened on the exact PDO
object passed to `PanelPdoMigrationStore`. A handler that changes application
tables must use that PDO connection—or a repository pinned to it—when the
application expects its side effects and Panel's state/checkpoint update to
commit atomically.

```php
$definition=PanelMigrationDefinition::make(
    'orders.normalize_email',
    'orders',
    $from,
    $to,
    static function(PanelMigrationContext $context) use ($pdo): PanelMigrationBatch {
        $statement=$pdo->prepare(
            'UPDATE orders SET normalized = 1 WHERE id > :cursor ORDER BY id LIMIT 500'
        );
        $statement->execute(['cursor'=>(int)($context->cursor() ?? 0)]);

        return PanelMigrationBatch::complete($context->data(),$statement->rowCount());
    },
);
```

The adapter may retry metadata-only transactions after a serialization failure,
deadlock, or lock conflict. It deliberately does not retry a transaction after
calling an up/down handler. A handler exception rolls back both its same-PDO
effects and the checkpoint, then propagates to the caller. This prevents an
ambiguous driver failure from invoking application code twice. External effects
still require the application's own idempotency boundary; do not put HTTP,
email, payment, or queue publication inside a migration handler and assume a
database transaction can undo it.

## Scope ownership, recovery, and parallelism

Each scope/tenant pair has one independently locked document row. Unrelated
scopes can migrate concurrently. Acquisition increments a monotonic fence and
stores only a domain-separated SHA-256 digest of the lease token. Renew, batch,
complete, rollback, restore, fail, and release verify the owner, token digest,
fence, and expiry. A stale or forged worker receives `PanelMigrationLeaseLost`.

Expired leases are recovered explicitly as unowned; active runs become
`paused`. The next worker receives a larger fence and resumes the exact
idempotent plan from its last committed state digest and checkpoint. The global
run index contains only run ID, scope key, plan digest, and start time. Scope
payloads stay isolated in their independently locked rows.

MySQL and PostgreSQL use row locks. SQLite serializes writes with
`BEGIN IMMEDIATE` and is suitable for multiple local processes sharing one
normal local filesystem. Do not place SQLite on a network filesystem for a
multi-node deployment; use MySQL, PostgreSQL, or another conforming store.

## Stored data and security boundary

The canonical scope document necessarily contains:

- current migration state and applied-definition IDs;
- run checkpoints, receipts, lifecycle status, and redacted errors;
- idempotency bindings; and
- integrity-checked pre-run backup state used for snapshot recovery.

State and backup payloads are therefore sensitive at-rest data. Apply the same
database encryption, access control, backup, replication, retention, and
disaster-recovery policy used for the migrated source. Keep credentials out of
state, cursors, checkpoints, actor metadata, and receipts even though
credential-shaped values are recursively redacted where the contract permits.

Raw lease tokens are never stored. Public manifests expose static capabilities
and configured bounds only. They do not serialize the PDO object, DSN,
credentials, table prefix, SQL, live counts, state, snapshots, or token hashes.
Failures use `PanelMigrationStorageException` with stable codes including
`schema_required`, `schema_incompatible`, `migration_failed`,
`transaction_conflict`, `storage_unavailable`, `storage_corrupt`,
`document_invalid`, `document_too_large`, and `fence_exhausted`.

## Retained metadata feed

`changesSince()` exposes bounded metadata events for host invalidation,
supervision, or inventory refresh. Events include cursor, type, scope, optional
tenant/run/fence, and occurrence time. They never copy migration state,
snapshots, checkpoints, receipts, errors, actor data, or lease material.

When retention removed a known cursor—or a cursor belongs to a replaced
database—the response sets `reset_required`. Consumers must discard incremental
assumptions and rebuild their inventory by querying the authoritative host scope
catalogue and reports. The feed intentionally does not provide a payload
snapshot because duplicating scope state into a global log would weaken the
storage boundary.

## Certification and host responsibilities

Before activation, run
`PanelAdapterConformanceCatalog::migrationStore()` with destructive probe
authority against a disposable namespace on the deployment database. The
first-party adapter is covered on PHP 8.2 and 8.4, across independent
connections and PHP processes, with exact closed-world source coverage, and
against live MySQL 8.4 and PostgreSQL 17 in addition to SQLite.

The host still owns database provisioning and HA, credentials and TLS, schema
rollout, query/lock/statement timeouts, backups, monitoring, migration
definitions, authorization, capability grants, worker scheduling and
supervision, external-effect idempotency, state retention, and disaster
recovery. Panel installs none of those authorities automatically.
