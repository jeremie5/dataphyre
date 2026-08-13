# Dataphyre Panel Distributed Command Fabric

`PanelPdoCommandFabricStore` is the first-party shared-SQL persistence adapter
for `PanelCommandFabric`. It preserves the fabric's encrypted command journal,
signed terminal receipts, tamper-evident event outbox, subscriber cursors, and
retained metadata feed across PHP processes and restarts.

The adapter supports MySQL, PostgreSQL, and SQLite. It never creates a
connection, installs schema automatically, starts a worker, or owns deployment
credentials. Schema migration is an explicit host operation.

## Guarantees

- one database transaction atomically commits the fabric aggregate and its
  payload-free change record;
- command input and raw idempotency material remain inside the fabric's
  encrypted payload envelope;
- subscriber ownership uses expiring bearer leases whose raw tokens are never
  stored;
- every acquisition advances a monotonic fence;
- subscriber cursor advancement validates the live token and fence in the same
  transaction that writes the cursor;
- a stale worker cannot advance a cursor after another worker takes ownership;
- delivery is at least once: a crash or lease loss after a projector performs
  an effect but before cursor commit causes that event to be delivered again;
- no cross-database ACID or distributed exactly-once guarantee is claimed.

The current `PanelCommandFabricStore` contract mutates one bounded aggregate
snapshot. The PDO adapter therefore serializes writers through one locked state
row. This is a deliberate correctness boundary for moderate command volumes,
not a claim of an infinitely sharded normalized event log.

## Explicit Schema Installation

```php
use Dataphyre\Panel\PanelPdoCommandFabricStore;

$pdo=new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);

$store=new PanelPdoCommandFabricStore($pdo, [
    'table_prefix'=>'panel_command_fabric',
    'change_retention'=>16384,
    'maximum_state_bytes'=>67108864,
]);

// Run through the application's migration authority, never normal request code.
foreach($store->schemaStatements() as $statement){
    $pdo->exec($statement);
}

// Equivalent idempotent convenience operation for a migration command.
$store->installSchema();
```

The store requires ownership of its PDO transaction. Calling schema, read,
write, or lease operations inside a caller-owned transaction fails with
`transaction_conflict`; this prevents accidental partial composition with an
unknown outer transaction.

## Operations OS Wiring

```php
$platform=PanelPlatform::defaults([
    'state_root'=>__DIR__.'/var/panel',
    'operations_os'=>[
        'master_key'=>$hostManagedMasterKey,
        'fabric_store'=>$store,
        'fabric_subscriber_worker'=>'orders-worker-01',
        'fabric_subscriber_lease_ttl_seconds'=>60,
    ],
    'authentication'=>false,
    'media'=>false,
]);
```

`PanelCommandFabric::drainSubscriber()` acquires and releases a lease
automatically when its store implements `PanelLeasedCommandFabricStore`. A
live competing owner produces a successful busy/no-op result. Lease loss during
projection produces a retry result and leaves the durable cursor unchanged.
Projectors must therefore use event-derived downstream idempotency keys.

## Conformance

Run both destructive packs against a dedicated adapter database before
activation:

```php
$runner=new PanelAdapterConformanceRunner();

$state=$runner->run(
    PanelAdapterConformanceCatalog::commandFabricStore(),
    $store,
    ['allow_destructive'=>true],
);

$leases=$runner->run(
    PanelAdapterConformanceCatalog::leasedCommandFabricStore(),
    $store,
    ['allow_destructive'=>true],
);

if(!$state->passed() || !$leases->passed()){
    throw new RuntimeException('Command-fabric adapter certification failed.');
}
```

The packs verify rollback, atomic snapshots, retained change feeds, exclusive
ownership, renewal, bearer secrecy, monotonic fencing, stale-owner rejection,
atomic cursor fencing, and cleanup.

## Deployment Responsibilities

The host remains responsible for database provisioning, TLS and credentials,
schema rollout, backups, HA, lock and statement timeouts, worker supervision,
retention sizing, monitoring, and idempotency of every external projector
effect. A non-SQL broker or normalized high-volume event log must implement the
same public contracts and pass equivalent conformance before activation.
