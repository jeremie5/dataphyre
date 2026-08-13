# Dataphyre Panel distributed agent workflows

`PanelPdoAgentWorkflowStore` is Panel's first-party shared-database adapter for
agent-safe workflow coordination. It preserves the `PanelAgentWorkflowStore`
contract across PHP processes and application nodes using MySQL, PostgreSQL, or
SQLite supplied by the host.

The adapter provides durable optimistic revisions, renewable fenced execution
reservations, scope-bound idempotent replay, single-use signed-intent nonces,
durable cancellation, a verified append-only audit chain, explicit retention,
and a bounded payload-free change feed. It does not provide an LLM, tool
executor, worker, route, identity system, database, connection, or schema
authority.

## Explicit setup

PDO exception mode is mandatory. Panel never constructs the connection and
never installs or changes schema during ordinary reads or writes.

```php
use Dataphyre\Panel\PanelPdoAgentWorkflowStore;

$pdo=new PDO($dsn,$username,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);

$workflowStore=new PanelPdoAgentWorkflowStore($pdo,[
    'table_prefix'=>'panel_agent_workflows',
    'lease_seconds'=>120,
    'max_entries'=>4096,
    'retention_seconds'=>86400,
    'maximum_result_bytes'=>1179648,
    'maximum_audit_bytes'=>131072,
    'change_retention'=>4096,
    'transaction_retries'=>3,
    'retry_delay_microseconds'=>2000,
]);
```

Generate SQL for the deployment dialect and run it through the application's
migration authority:

```php
foreach(PanelPdoAgentWorkflowStore::schemaStatementsFor(
    'pgsql',
    'panel_agent_workflows',
) as $sql){
    $migrationConnection->exec($sql);
}
```

An explicitly authorized deployment command may call `installSchema()`
instead. Both setup paths are idempotent and non-destructive. They reject an
active caller transaction and an incompatible existing schema rather than
silently repairing, dropping, or coercing it.

The prefix must be a lowercase SQL identifier beginning with a letter and at
most 28 characters. Unknown options, unsupported drivers, invalid bounds, or a
PDO connection without exception mode fail during construction.

| Option | Default | Supported bound |
| --- | ---: | ---: |
| `lease_seconds` | 120 | 30 to 3,600 |
| `max_entries` | 4,096 | 1 to 100,000 |
| `retention_seconds` | 86,400 | 3,600 to 31,536,000 |
| `maximum_result_bytes` | 1,179,648 | 4,096 to 16,777,216 |
| `maximum_audit_bytes` | 131,072 | 1,024 to 1,048,576 |
| `change_retention` | 4,096 | 8 to 1,000,000 |
| `transaction_retries` | 3 | 0 to 10 |
| `retry_delay_microseconds` | 2,000 | 0 to 100,000 |

## Transaction and consistency model

The store owns every transaction it opens. Normal request and worker code must
not wrap a store call in a caller-owned PDO transaction. MySQL and PostgreSQL
use row locks around the singleton metadata fence; SQLite writers use
`BEGIN IMMEDIATE`. Read transactions use repeatable-read behavior where the
dialect requires it.

All mutations advance one global optimistic revision. The same singleton row
also commits the current audit sequence and audit head. This makes revision,
reservation, cancellation, completion, garbage collection, and audit-chain
updates one fail-closed order across the deployment, but it intentionally
serializes mutation commits. Deployments needing independently scalable shards
should use separate store instances and table prefixes at an application-owned
security boundary rather than weakening the global contract.

Transactions are retried only for recognized serialization, deadlock, or lock
failures, and only within the adapter's bounded retry policy. Validation,
authorization, schema, corruption, and ordinary application failures are never
converted into retries.

## Reservation and replay lifecycle

`reserve()` atomically checks the expected global revision, cancellation state,
scope-bound idempotency digest, and all signed-intent nonce digests before it
creates a pending reservation. The returned lease revision is also its fencing
value. `renew()` advances the global revision and the lease fence; a stale
worker cannot renew or complete under an older fence.

An expired reservation can be reclaimed only with the exact original plan,
scope, request, idempotency key, and nonce set. The old pending row and nonce
ownership are replaced in the same transaction. Completion stores one bounded
canonical execution result and its matching audit receipt. Later equivalent
requests receive the durable result without invoking the executor again.

This is coordination for at-least-once worker delivery; it is not a universal
exactly-once effect guarantee. Every executor must pass Panel's deterministic
step idempotency key into the real downstream operation. Database fencing cannot
roll back an HTTP request, payment, email, shell command, or write committed
through another connection.

## Stored data and integrity

The normalized schema contains six logical tables:

- singleton revision, audit-head, and schema metadata;
- active or completed reservations;
- one-way signed-intent nonce digests;
- canonical audit receipts and their chain metadata;
- durable plan-cancellation tombstones; and
- retained payload-free change records.

Raw idempotency keys and raw intent nonces are never stored. Their lookup values
are domain-separated SHA-256 digests. Connection details, credentials, table
prefixes, SQL, live counts, callbacks, and replay material are omitted from the
public manifest.

Canonical execution results and audit receipts are stored because replay and
verification require them. They are bounded and pass the agent runtime's
redaction boundary, but they can still contain application business data. The
host must apply database TLS, encryption at rest, least-privilege grants,
backups, retention, monitoring, and data-classification policy. Do not place
credentials, raw prompts, or unnecessarily large customer documents in tool
results or audit details.

Every read that depends on workflow state verifies the audit sequence, receipt
digests, previous hashes, head, cardinality bounds, and touched row invariants.
Stored results are decoded only when their byte count, SHA-256 digest, canonical
encoding, plan, scope, code, receipt, and revision relationships agree. A
corrupt row fails closed instead of being skipped or repaired.

The SHA-256 audit chain detects modification or deletion when a trusted copy of
its head is retained elsewhere. It is not a signature and cannot stop a database
administrator from rewriting the complete chain and its metadata. Export or
anchor audit heads into a separately controlled system when that threat is in
scope.

## Retention and change feed

Call `collectGarbage()` from an operator-owned scheduled task. It removes only
completed or long-abandoned reservations older than the configured retention
window, together with their nonce rows. Audit receipts remain append-only.
Cancellation tombstones are retained unless the caller explicitly opts into
their pruning, and an active reservation always prevents cancellation pruning
for its plan.

`changesSince()` returns bounded cursor, event type, entity type/id, global
revision, and timestamp metadata. It never copies results, audit details,
idempotency keys, or nonces. Retention gaps and cursors from another database
return `reset_required=true`; consumers must rebuild from the verified audit
chain and application-owned active-workflow views. The feed is an invalidation
and observation aid, not a queue or execution authority.

## Stable failures

Durable adapter failures use `PanelAgentWorkflowStorageException`. Its
`errorCode()` and `retryable()` values are safe for bounded operational handling;
the wrapped driver message, DSN, SQL, and credentials are not exposed. Stable
codes include:

- `schema_required`, `schema_incompatible`, and `migration_failed`;
- `transaction_conflict`, `storage_unavailable`, and `storage_corrupt`;
- `result_invalid`, `result_too_large`, and `audit_too_large`; and
- `revision_exhausted`.

Protocol conflicts such as stale revisions, replayed intents, reused
idempotency keys, expired reservations, cancellation, scope mismatch, and store
capacity remain typed `PanelAgentException` failures. A retryable storage error
does not authorize an unbounded retry loop or a repeated external effect.

## Deployment certification

Run the destructive adapter conformance pack against a disposable deployment
scope before activating a database or driver configuration:

```php
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;

$report=(new PanelAdapterConformanceRunner())->run(
    PanelAdapterConformanceCatalog::agentWorkflowStore(),
    $disposableWorkflowStore,
    ['allow_destructive'=>true],
);

if(!$report->passed()){
    throw new RuntimeException('Agent workflow store conformance failed.');
}
```

The first-party adapter is exercised on PHP 8.2 and 8.4, against independent
connections, in a real two-process SQLite reservation race, and with live MySQL
8.4 and PostgreSQL 17 databases. Focused closed-world coverage includes every
executable line of the adapter and its stable storage exception.

The host still owns database provisioning and high availability, credentials,
TLS, schema rollout, backup/restore drills, audit-head anchoring, capacity and
lock monitoring, worker supervision, the secure pending-material repository,
tool registration, authorization, confirmation ceremonies, cancellation
policy, downstream timeouts, and external-effect idempotency. Panel installs
none of those authorities automatically.
