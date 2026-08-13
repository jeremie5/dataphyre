# Dataphyre Panel distributed operations

Panel's distributed-operation boundary provides at-least-once job execution with
renewable ownership and monotonic fencing. `PanelAtomicLeasedOperationStore` is
the crash-safe local adapter. `PanelPdoLeasedOperationStore` is the first-party
shared-database adapter for MySQL, PostgreSQL, and SQLite.

## Explicit setup

The PDO adapter never creates a connection, changes credentials, installs its
schema, registers a platform service, or starts a worker. The host owns all of
those decisions. PDO exception mode is required so storage failures cannot be
mistaken for successful writes.

```php
use Dataphyre\Panel\PanelPdoLeasedOperationStore;

$pdo=new PDO($dsn,$username,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);

$store=new PanelPdoLeasedOperationStore($pdo,[
    'table_prefix'=>'panel_operations',
    'maximum_record_bytes'=>8 * 1024 * 1024,
    'change_retention'=>4096,
    'transaction_retries'=>3,
    'retry_delay_microseconds'=>2000,
]);
```

Run the returned statements through the application's migration authority, or
call `installSchema()` from an explicitly authorized migration command. Both
paths are idempotent and non-destructive.

```php
foreach(PanelPdoLeasedOperationStore::schemaStatementsFor('pgsql') as $sql){
    $migrationConnection->exec($sql);
}
```

Normal request and worker code should not hold a caller-owned PDO transaction
when invoking the store. The adapter must own begin, commit, rollback, retry,
row locking, and SQLite `BEGIN IMMEDIATE` behavior as one boundary.

## Worker lifecycle

```php
while($reservation=$store->reserveLease('exports','worker-eu-3',60)){
    $lease=$reservation->lease();
    try{
        $reservation=$store->mutateLease(
            $lease,
            static fn($record)=>$record->progress(25,100,'Exporting'),
            60,
        );
        $store->finishLease(
            $reservation->lease(),
            static fn($record)=>$record->complete(['artifact'=>'export.csv']),
        );
    }catch(Throwable $error){
        // Release only if this worker still owns the current lease.
        try{$store->releaseLease($lease);}catch(Throwable){}
        throw $error;
    }
}
```

MySQL and PostgreSQL reservations use locked, skip-locked candidate selection;
SQLite serializes writers with `BEGIN IMMEDIATE`. Acquisition increments a
fence stored on the operation row. Every inspection, mutation, renewal, finish,
and release verifies the worker, fence, expiry, and a domain-separated SHA-256
digest of the bearer token. A stale or forged owner receives
`PanelOperationLeaseLost`.

Expired leases are not silently stolen. `reserveLease()` first performs bounded
recovery, and supervisors may call `recoverExpiredLeases()` directly. Recovery
resolves cancellation and pause requests, retries work with attempts remaining,
and fails an exhausted operation deterministically.

## Optimistic records and retries

All ordinary operation-store methods retain `PanelOperationStore` semantics:

- create is exactly idempotent for the normalized idempotency key;
- save and update increment revisions and reject stale expected revisions;
- records can be filtered and paginated by the portable store criteria; and
- deletion refuses an operation while any lease row remains.

The adapter may retry a transaction after a deadlock, serialization failure, or
SQLite lock failure. Mutation callbacks must therefore be deterministic record
transformations with no external side effects. Perform remote effects through
an idempotent handler boundary, then persist their result under the current
fenced lease.

## Retained metadata feed

`changesSince()` exposes bounded operation metadata events. Events contain only
their cursor, type, operation id, optional worker/fence, and occurrence time;
they never copy operation payloads, results, idempotency keys, or lease tokens.
When retention has removed a known cursor—or a cursor belongs to a replaced
database—the response sets `reset_required` and instructs the consumer to
re-enumerate records through paginated `all()` calls.

## Data and security boundary

Raw lease tokens are never stored. Only a domain-separated digest is retained.
The idempotency lookup column is also a domain-separated digest. The canonical
operation JSON must preserve the complete `PanelOperationRecord`, however, so
it includes caller-supplied payload, metadata, logs, artifacts, results, errors,
and the normalized idempotency key. Applications must not place credentials or
unbounded customer documents in operation records and must apply their normal
database encryption, backup, retention, and access controls.

Public manifests report static capabilities and configured bounds only. They do
not serialize the DSN, credentials, PDO object, table prefix, SQL, live record
counts, operation data, or token material. Storage failures use
`PanelOperationStorageException` with stable codes such as `schema_required`,
`schema_incompatible`, `transaction_conflict`, `storage_unavailable`, and
`storage_corrupt`.

## Certification and host responsibilities

Before activation, run both `PanelAdapterConformanceCatalog::operationStore()`
and `PanelAdapterConformanceCatalog::leasedOperationStore()` with destructive
probe authority against the deployment database. The first-party adapter is
covered on PHP 8.2 and 8.4, across independent connections and processes, and
against live MySQL and PostgreSQL in addition to SQLite.

The host still owns database provisioning and HA, credentials, TLS, schema
rollout, backups, worker processes, process supervision, queue selection,
handler registration, authorization, cancellation policy, observability,
dead-letter policy, and idempotency for external effects. Panel installs none
of those authorities automatically.
