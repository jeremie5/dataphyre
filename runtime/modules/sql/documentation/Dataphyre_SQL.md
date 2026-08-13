# Dataphyre SQL Module

The SQL module is Dataphyre's kernel database engine. It provides DBMS-aware query execution, caching, migration coordination, queue-aware query handling, registry-driven table hydration, and the optional `Dataphyre\Database` framework layer.

The framework layer is built around `execution-aware queries`. A query describes both data intent and execution policy in one explicit place: filters, caching, named cache indexes, invalidation, deferred execution, and observability all live on the same surface instead of being bolted on later.

It also composes with other Dataphyre framework modules explicitly. SQL can hydrate typed money objects from the currency framework and compare monetary values without pushing float-wrapping back into application code.

## Execution-Aware Queries

In Dataphyre SQL, the happy path stays simple:

```php
UserRepository::query()->whereEq('email', $email)->first();
```

But when a read or write needs more, the same query can stay explicit about how it should execute:

- read caching
- named cache indexes
- write invalidation
- queued execution
- execution traces and guardrail warnings

Nothing is hidden behind model magic or a separate job layer. The same query can run immediately, be deferred to a named queue, or emit observable trace events without changing stacks.

## Execution-Aware Demo

This compact example shows the whole model in one place: observe execution, run a cached reporting query, queue a write with named invalidation, then flush the queue.

```php
use Dataphyre\Database\DB;
use Dataphyre\Database\ExecutionTrace;
use Dataphyre\Database\MutationResult;

DB::observe(static function(ExecutionTrace $trace): void{
	error_log('[sql '.$trace->event().'] '.json_encode($trace->toArray(), JSON_UNESCAPED_UNICODE));
});

$summary=OrderRepository::query()
	->cacheNames('reports.orders.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->in_last_days('created_at', 30)
	->countBy('status');

OrderRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->whereEq('status', 'queued')
	->invalidateCacheNames('reports.orders.summary', 'tenant.'.$tenant_id)
	->queueUpdate(['status'=>'processing'], static function(MutationResult $result): void{
		if($result->failed()){
			error_log($result->errorMessage() ?? 'Queued update failed.');
		}
	}, 'reports');

DB::executeQueue('reports');
```

That one flow gives you:

- a cached grouped summary read
- explicit cache names for later invalidation
- a queued write on a named queue
- write-side invalidation policy attached to the write itself
- trace events for cache activity, queue push, queue execution, and invalidation

## Start Here

You do not need to learn the whole framework to start using it well.

Use the first lane that matches your job:

1. `DB::table(...)` when you want the fastest path to writing useful app code.
2. `Repository::query()` when the table deserves a real app-level home.
3. `sql_*` helpers when you are on a hot path or need direct kernel control.

That means the normal onboarding path is:

```php
DB::table('users', 'user_id')
	->whereEq('email', $email)
	->first();
```

Then, when the code deserves structure:

```php
UserRepository::query()
	->whereEq('email', $email)
	->firstRecordOrFail();
```

And when the code path is specialized enough that framework ergonomics are not worth the abstraction:

```php
sql_select(
	'users',
	['user_id', 'email'],
	[['email', '=', $email]],
	1
);
```

`QuerySpec`, custom hydrators, typed records, traces, queues, and invalidation policies are all there when you need them. They are not the starting requirement.

## First Five Minutes

If you are evaluating Dataphyre SQL against Laravel or another framework, this is the shortest honest path:

```php
\dataphyre\core::load_framework_module('sql');

use Dataphyre\Database\DB;

$user=DB::table('users', 'user_id')
	->whereEq('email', $email)
	->first();

$created=DB::table('users', 'user_id')->create([
	'email'=>$email,
	'status'=>'active',
]);

$recent=DB::table('users', 'user_id')
	->whereEq('status', 'active')
	->in_last_days('created_at', 7)
	->latest('created_at')
	->get();
```

That is enough to get productive. Repositories, schemas, record classes, queueing, and observability come next when the code actually benefits from them.

## Kernel Layer

The kernel SQL module is responsible for:

- DBMS-aware query execution for MySQL, PostgreSQL, and SQLite
- query caching
- migration coordination
- table-definition registration and lazy missing-table hydration
- server availability tracking
- query queue and batching support
- the `sql_*` helper functions exposed through `sql.global.php`

Core helpers include:

- `sql_select(...)`
- `sql_count(...)`
- `sql_insert(...)`
- `sql_update(...)`
- `sql_delete(...)`
- `sql_query(...)`
- `sql_upsert(...)`
- `sql_define_table(...)`

These are the lowest-overhead path and fit hot paths or specialized queries.

## Optional Framework Layer

Load it explicitly:

```php
\dataphyre\core::load_framework_module('sql');
```

The framework namespace is:

```php
use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\RepositoryQuery;
use Dataphyre\Database\Record;
use Dataphyre\Database\Relation;
use Dataphyre\Database\ExecutionTrace;
use Dataphyre\Database\TableDefinition;
use Dataphyre\Database\TableSchema;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableQuery;
use Dataphyre\Database\PageResult;
use Dataphyre\Database\MutationResult;
use Dataphyre\Database\MutationBatchResult;
use Dataphyre\Database\DB;
use Dataphyre\Database\ConnectionContext;
use Dataphyre\Database\Transaction;
use Dataphyre\Database\TransactionResult;
use Dataphyre\Database\TransactionException;
use Dataphyre\Database\RecordNotFoundException;
use Dataphyre\Database\MultipleRecordsFoundException;
use Dataphyre\Database\OptimisticLockException;
use Dataphyre\Database\Tools\ScaffoldTableArtifacts;
use Dataphyre\Database\Contracts\RecordHydrator;
use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;
use Dataphyre\Database\Migrations\PostgreSqlSchemaInspector;
```

The framework is explicit on the failure path too. When setup or usage is wrong, exceptions include:

- what failed
- the repository, table, or cluster involved
- the relevant identifier or projection name
- a concrete hint for how to fix it

Mutation results also expose `errorMessage()` and `context()` so non-throwing flows can report useful failures. Batch results expose `errorMessages()` and `firstErrorMessage()` for the same reason. Optimistic write helpers throw `OptimisticLockException` from their `...OrFail(...)` variants when the expected row version is stale.

Framework read helpers follow the kernel read path by default:

- repository and table reads are cache-aware by default
- those reads only cache when the kernel table cache policy allows it
- raw `DB::query(...)` remains uncached by default, matching the kernel raw query path
- app code can opt out with `withoutCaching()` or by passing `false`

The framework also exposes the kernel cache and queue model directly:

- named read-cache indexes can be attached with `cacheName(...)` / `cacheNames(...)`
- named write invalidation indexes can be attached with `invalidateCacheName(...)` / `invalidateCacheNames(...)`
- raw helpers expose `DB::cacheNames(...)`, `DB::mergeCacheNames(...)`, `DB::invalidationNames(...)`, `DB::mergeInvalidationNames(...)`, and `DB::invalidateCache(...)`
- queued operations are available on raw DB access and on query builders through explicit `queue...(...)` methods
- queued callbacks receive framework-shaped results for reads, counts, aggregates, and writes
- queued execution uses the same registered-definition missing-structure retry path as immediate execution
- query builders can carry a default write invalidation target through `invalidateOnWrite(...)`
- repositories can opt into a default write invalidation policy through `defaultWriteInvalidation()`
- named invalidation arrays clear the named cache indexes directly when no table cache policy is provided
- named invalidation indexes remove the concrete cached location/hash entries recorded when `cacheNames(...)` stores a read result
- observability is opt-in through `DB::observe(...)`, `DB::lastTrace()`, `DB::recentTraces(...)`, and `DB::recentTracesByContext(...)`
- when `IS_PRODUCTION === true`, Dataphyre disables public SQL trace buffering and retrieval
- guardrail warnings can be enabled with `DB::enableGuardrails()` and are on by default in `RUN_MODE==='diagnostic'`
- when the templating framework is also loaded, named SQL cache invalidations automatically clear matching templating binding cache names

## Table Definitions And Hydration

Dataphyre tables are defined explicitly. A table definition is the source of truth for creating a missing module-owned table; runtime queries do not infer columns from raw SQL, field payloads, or WHERE clauses.

Register a table definition with:

```php
sql_define_table('dataphyre.sessions', __DIR__.'/access.tables.php', 'sessions');
```

The first argument is the table name used by SQL calls. It is normalized through the same table-location rules as `sql_select(...)`, `sql_insert(...)`, and the other helpers. The second argument is a PHP definition file. The third argument is an optional definition id used when a file contains more than one table definition.

Definition files are lazy. SQL records the table-to-file mapping during module initialization, but it does not load the definition file until the table is needed for hydration. That keeps boot light and lets modules register every table they own without loading every schema into memory.

A definition file can return one of these shapes:

- a `TableDefinition`
- a callable that receives `string $table` and `?string $definition_id`
- an array of ids to `TableDefinition` instances or callables

Example:

```php
<?php

use Dataphyre\Database\TableDefinition;

return [
	'sessions'=>static fn(string $table): TableDefinition => TableDefinition::for($table)
		->string('id', 64)->notNull()->primary()
		->unsignedBigInt('userid')->notNull()
		->text('useragent')->notNull()
		->text('ipaddress')->notNull()
		->boolean('keepalive')->notNull()->default(false)
		->boolean('active')->notNull()->default(true)
		->timestamp('date')->notNull()->defaultCurrent()
		->index(['userid', 'active'], 'idx_access_sessions_userid_active')
		->index('date', 'idx_access_sessions_date'),
];
```

When a registered table is missing, the SQL helper retry path is:

1. Run the requested query normally.
2. If the driver reports a missing table, look up the registered table definition.
3. Load the SQL framework module if the table-definition builder is not already loaded.
4. Run the definition's DBMS-specific `CREATE TABLE IF NOT EXISTS` and index statements.
5. Clear the table cache target and retry the original query once.

This applies to the kernel helper path:

- `sql_select(...)`
- `sql_count(...)`
- `sql_insert(...)`
- `sql_update(...)`
- `sql_delete(...)`
- `sql_upsert(...)`
- `sql_query(...)`

For raw `sql_query(...)`, hydration is available when the failed query text names a registered table. SQL still never guesses columns from the raw query; the registered definition remains authoritative.

Hydration creates missing tables and expected indexes from registered definitions. It also handles the common additive release path: if a registered query fails because a registered column is missing, SQL can add that exact column from the table definition and retry once. Existing tables are not reshaped by guessing or by scanning query payloads. If an existing table needs a changed type, renamed column, removed column, foreign-key rewrite, or data transform, use the migration layer or an explicit maintenance query.

`TableSchema` participates in the same contract. Repositories and `DB::table(...)->usingSchema(...)` use `TableSchema` for validating columns, projections, and primary-key metadata, but `TableSchema::hydrateTable()` delegates creation to the registered table definition. This keeps validation and DDL aligned around module-owned definitions instead of allowing ad hoc table creation.

`DB::table('registered_table')` automatically uses the registered definition's generated `TableSchema` when one exists. This means ad hoc table queries can get the same field validation, primary-key metadata, projections, and casts as repositories without manually passing `usingSchema(...)`.

`TableDefinition` supports the core portable DDL surface:

- `string(...)`, `text(...)`, `longText(...)`, `json(...)`
- `integer(...)`, `bigInt(...)`, `unsignedBigInt(...)`, `float(...)`
- `boolean(...)`
- `timestamp(...)`, `datetime(...)`
- `uuid(...)`
- `enum(...)`
- `autoIncrement(...)`
- `nullable()`, `notNull()`
- `default(...)`, `defaultSql(...)`, `defaultCurrent()`, `onUpdateCurrent()`
- `primary(...)`, `unique(...)`, `index(...)`
- `projection(...)`
- `cast(...)`, `casts(...)`
- `schema()`

Use DBMS-specific column types with `column(...)` when portability needs an explicit override:

```php
TableDefinition::for('reports.daily_totals')
	->string('report_id', 64)->notNull()->primary()
	->column('payload', [
		'mysql'=>'JSON',
		'postgresql'=>'JSONB',
		'sqlite'=>'TEXT',
	])
	->datetime('created_at')->notNull()->defaultCurrent();
```

Typed definition helpers add their natural casts automatically: integer-like columns cast as `int`, `float(...)` casts as `float`, `boolean(...)` casts as `bool`, `json(...)` casts as `json`, and `timestamp(...)` / `datetime(...)` cast as `datetime`. Explicit casts are still available for custom `column(...)` definitions and legacy storage shapes, and are shared by generated schemas, repositories, and `DB::table(...)` queries:

```php
TableDefinition::for('orders')
	->autoIncrement('order_id')
	->string('status', 32)->notNull()
	->json('metadata')
	->boolean('is_paid')
	->datetime('created_at')
	->projection('summary', ['order_id', 'status', 'is_paid']);
```

Supported casts are:

- `string`
- `int`
- `float`
- `bool`
- `json`
- `datetime`

On reads, schema casts convert registered columns into PHP values. On writes, schema casts serialize supported values into storage-safe values. JSON casts encode arrays and objects before writes and decode valid JSON strings after reads. JSON encoding preserves a PHP float's zero fraction, so values such as `30.0` remain `30.0` instead of silently becoming the integer-shaped JSON value `30`. Datetime casts write `DateTimeInterface` values as `Y-m-d H:i:s` and read non-empty database values as `DateTimeImmutable`.

## Observability

The SQL framework exposes real execution traces from the kernel path.

Observed events include:

- cache hits
- cache misses
- cache stores
- cache invalidations
- queue pushes
- queue execution start/end
- immediate query execution
- framework guardrail warnings

Example:

```php
DB::observe(static function(ExecutionTrace $trace): void{
	if($trace->isWarning()){
		error_log('[sql warning] '.$trace->message());
		return;
	}
	error_log(json_encode($trace->toArray(), JSON_UNESCAPED_UNICODE));
});

DB::enableGuardrails();

$rows=MachineRepository::query()
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->get();

$last_trace=DB::lastTrace();
$recent=DB::recentTraces(25);
// for example from Templating::inspect(...)->renderTraceId()
$for_render=DB::recentTracesByContext(['render_trace_id'=>$render_trace_id], 25);
```

Start observing before the operations you want to inspect. Traces are collected from the point observability is enabled onward.

`ExecutionTrace` exposes:

- `source()`
- `event()`
- `operation()`
- `message()`
- `reason()`
- `location()`
- `cluster()`
- `dbms()`
- `queue()`
- `queued()`
- `immediate()`
- `cacheStatus()`
- `cacheType()`
- `cacheNames()`
- `invalidationNames()`
- `resultOk()`
- `context()`
- `contextValue(...)`
- `renderTraceId()`
- `bindingTraceId()`
- `queryFingerprint()`
- `queryIdentityMode()`
- `queryIdentitySource()`
- `queryTargetType()`
- `queryTarget()`
- `queryMode()`
- `timestamp()`
- `toArray()`

The framework does not guess at cache behavior. It reports kernel-observed events when observability is enabled, and extra event-specific fields like invalidation scope or affected entry counts are preserved in `context()`.

For framework-to-framework correlation, `DB::withTraceContext(...)` can attach explicit context to SQL traces during a bounded execution block, and `DB::recentTracesByContext(...)` can pull the matching trace chain back out of the in-memory buffer. The templating framework uses that automatically for official SQL query bindings.

`RepositoryQuery` and `TableQuery` also expose `fingerprintPayload()` and `fingerprint()`. That gives other framework layers one explicit query identity to align with instead of rebuilding identity from raw execution state.

When a missing row should be treated as an actual failure, the framework also supports:

- `firstOrFail(...)`
- `findOrFail(...)`
- `findOneByOrFail(...)`
- `findOneHydratedByOrFail(...)`
- `findOneRecordByOrFail(...)`
- `findHydratedOrFail(...)`
- `firstRecordOrFail(...)`
- `findRecordOrFail(...)`
- `queueFirstOrFail(...)`
- `queueFindOrFail(...)`
- `queueFindOneByOrFail(...)`
- `queueFindOneHydratedByOrFail(...)`
- `queueFindOneRecordByOrFail(...)`
- `queueFirstRecordOrFail(...)`
- `queueFindRecordOrFail(...)`

Those throw `RecordNotFoundException` with the table or repository, active filters, selected columns, and a concrete hint.

When a query should match exactly one row, the framework also supports:

- `sole(...)`
- `soleRecord(...)`
- `soleValue(...)`

Those throw `RecordNotFoundException` when nothing matched, and `MultipleRecordsFoundException` when the query matched more than one row.

Example:

```php
try{
	$machine=MachineRepository::query()
		->whereEq('machine_id', $machine_id)
		->firstRecordOrFail();

	$status=MachineRepository::query()
		->whereEq('tenant_id', $tenant_id)
		->soleValue('status');
}catch(RecordNotFoundException $exception){
	error_log($exception->getMessage());
}catch(MultipleRecordsFoundException $exception){
	error_log($exception->getMessage());
}
```

## Happy Path

The quickest path in is `DB::table(...)`, and the cleaner long-term path is repositories.

Table-first:

```php
$user=DB::table('users', 'user_id')
	->whereEq('email', $email)
	->first();

$active=DB::table('users', 'user_id')
	->whereEq('status', 'active')
	->count();

$updated=DB::table('users', 'user_id')
	->whereEq('status', 'queued')
	->update(['status'=>'active']);

$users_by_email=DB::table('users', 'user_id')
	->cacheName('users.by_email')
	->whereEq('status', 'active')
	->keyBy('email');

DB::table('users', 'user_id')
	->invalidateCacheNames('users.by_email', 'users.summary')
	->whereEq('status', 'queued')
	->update(['status'=>'active']);
```

Repository-first:

```php
$machine=MachineRepository::query()
	->whereEq('machine_id', $machine_id)
	->first();

$machines=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->orderBy('created_at', 'DESC')
	->paginate(1, 25);

$record=MachineRepository::query()
	->whereEq('machine_id', $machine_id)
	->firstRecord();

$required=MachineRepository::query()
	->whereEq('machine_id', $machine_id)
	->firstRecordOrFail();

$status=MachineRepository::query()
	->whereEq('machine_id', $machine_id)
	->soleValue('status');

$names=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->pluck('name');

$machines_by_id=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->keyBy('machine_id');

$queued_name_count=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->countColumn('name');

$unique_statuses=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->countDistinct('status');

$avg_fill=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->avg('fill_ml');

$jobs_by_status=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->countBy('status');

$fill_by_status=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->sumBy('status', 'fill_ml');

$status_rows=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->aggregateRowsBy(['tenant_id', 'status'], 'COUNT');

// Critical projections can distinguish a SQL failure from a valid empty set.
// The policy also propagates into row relations loaded with with(...).
$inventory=MachineRepository::query()
	->failOnReadError()
	->with('components')
	->whereEq('tenant_id', $tenant_id)
	->get();

$updated=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->whereEq('status', 'queued')
	->update(['status'=>'active']);

MachineRepository::query()
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->queueGet(static function(array $rows): void{
		// handle queued rows later
	}, 'reporting');

MachineRepository::query()
	->invalidateCacheNames('machines.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->whereEq('status', 'queued')
	->queueUpdate(
		['status'=>'active'],
		static function(int|bool|null $result): void{
			// handle queued write result later
		},
		'reporting'
);
```

Convenience collection reads intentionally normalize an unavailable SQL result
to `[]`, and convenience first-row reads normalize it to `null`. Use
`failOnReadError()` on `RepositoryQuery` or `TableQuery` when that ambiguity is
unsafe. A valid no-match result remains `[]` or `null`; only a low-level SQL
failure or malformed read payload raises a structured `SQL read error`.
On PostgreSQL-compatible drivers, the legacy kernel may represent a successful
single-row miss as `false`; strict framework reads consult
`sql::last_query_error()` and normalize that sentinel to `null` only when no
query error was recorded. A `false` result accompanied by an error still fails
closed. This distinction also applies to `TableQuery` strict reads.
Repository strictness also propagates through eager row and record relations, so
a failed child query cannot be presented as an empty relation. The equivalent
static repository primitives are `allOrFailOnReadError(...)` and
`firstOrFailOnReadError(...)`.

Queued reads return the same row shapes as their immediate equivalents, including row transforms and repository eager-loaded relations. Collection helpers such as `queuePluck(...)`, `queueKeyBy(...)`, and `queueExists(...)` are derived from the normalized queued read callbacks. Static repository finder helpers also have queued forms, including `queueFindOneBy(...)`, `queueFindOneByOrFail(...)`, `queueFindManyByIds(...)`, and hydrated/keyed variants. `queueFirstOrFail(...)`, `queueFindOrFail(...)`, `queueFindOneRecordByOrFail(...)`, `queueFindRecordOrFail(...)`, `queueValueOrFail(...)`, and `queueSole(...)` raise the same structured SQL exceptions as their immediate equivalents when the queued callback is processed. `queuePaginate(...)` queues the count and page-item reads together, then calls back once with a `PageResult` when both results are available. Queued writes pass the affected-row style result to the callback, so code can handle `0`, a positive row count, or `false` without reaching into the raw driver response. Repository write conveniences also have queued forms, including `queueUpdateById(...)`, `queueIncrementById(...)`, and `queueDeleteById(...)`. Queued optimistic writes such as `queueUpdateWithVersion(...)` and `queueUpdateByIdWithVersion(...)` call back with a `MutationResult` so `stale()` and `throwIfStale()` stay available. Queued batch writes such as `queueCreateMany(...)` and `queueUpsertMany(...)` call back once with a `MutationBatchResult` after every queued row callback has resolved. If a queued statement fails because a registered table or column is missing, Dataphyre hydrates the registered definition once and retries the queue.

## Currency Integration

SQL can hydrate money values explicitly when the currency framework is loaded:

```php
\dataphyre\core::load_framework_module('currency');

use Dataphyre\Currency\Currency;

$order=OrderRepository::query()
	->asMoney('total_amount_minor', 'currency', 'total')
	->whereKey($order_id)
	->firstRecordOrFail();

$total=$order->total;
```

That keeps the raw integer storage shape in SQL while letting application code work with `Dataphyre\Currency\Money` objects naturally.

If a repository always stores the same money fields, it can declare them once and let record hydration apply them automatically:

```php
protected static function moneyColumns(): array {
	return [
		'total_amount_minor'=>[
			'currency_column'=>'currency',
			'target_column'=>'total',
		],
		'base_total_minor'=>[
			'currency'=>'CAD',
			'target_column'=>'base_total_money',
		],
	];
}
```

Those mappings are applied on repository hydration paths like `firstRecord()`, `findRecord()`, `getRecords()`, and `paginateRecords()`. Raw array reads stay raw unless you opt into `asMoney(...)` explicitly on the query.

When rows carry their own currency column, money comparisons stay honest by matching currency first:

```php
$orders=OrderRepository::query()
	->whereMoneyLte('total_amount_minor', Currency::money(100, 'USD'), 'currency')
	->get();
```

When the stored amount column is normalized to one fixed currency, use the fixed-currency helpers:

```php
$rows=LedgerRepository::query()
	->whereMoneyGteIn('base_total_minor', Currency::money(100, 'USD'), 'CAD')
	->get();
```

`whereMoney...(...)` is for minor-amount-plus-currency row storage. `whereMoney...In(...)` is for minor-unit columns stored in one known currency.

SQL can also hydrate the canonical persisted `StoredMoney` shape when rows keep original money, normalized base money, and exchange metadata together:

```php
$order=OrderRepository::query()
	->asStoredMoney('priced_total')
	->whereKey($order_id)
	->firstRecordOrFail();

$stored=$order->priced_total;
$original=$stored->original();
$base=$stored->base();
```

`asStoredMoney()` defaults to the canonical integer minor-unit storage keys:

- `original_amount_minor`
- `original_currency`
- `base_amount_minor`
- `base_currency`
- `exchange_rate`
- `exchange_source`
- `exchange_time`
- `exchange_base_currency`

If the stored shape uses prefixes or custom columns, pass an explicit mapping:

```php
$order=OrderRepository::query()
	->asStoredMoney('price', [
		'original_prefix'=>'price_',
		'base_prefix'=>'price_base_',
		'exchange_prefix'=>'price_exchange_',
])
	->firstRecordOrFail();
```

Prefix mappings derive `amount_minor` columns by default, such as
`price_amount_minor` and `price_base_amount_minor`. Explicit
`original_amount_column` or `base_amount_column` values are still honored for
older schemas, but new storage should use integer minor-unit columns.

Repository hydration can own that mapping too:

```php
protected static function storedMoneyColumns(): array {
	return [
		'priced_total'=>[],
		'price'=>[
			'original_prefix'=>'price_',
			'base_prefix'=>'price_base_',
			'exchange_prefix'=>'price_exchange_',
		],
	];
}
```

Those mappings are applied on repository record hydration paths like `firstRecord()`, `findRecord()`, `getRecords()`, and `paginateRecords()`. Raw array reads stay raw unless you opt into `asStoredMoney(...)` explicitly on the query.

The hydrated `StoredMoney` object preserves the stored original/base pair, exchange quote, source, time, and snapshot base currency. That is enough for deterministic pricing/audit reads from persisted rows. It does not recreate the provider's full historical rate table from one database row, so treat the rebuilt snapshot as metadata-first rather than a substitute for a freshly loaded live/full snapshot.

The same mapping layer works on writes too. Repository and table mutations can accept `Money` and `StoredMoney` objects, then expand them into the configured storage columns before the kernel mutation runs:

```php
OrderRepository::create([
	'order_id'=>$order_id,
	'priced_total'=>$stored_total,
	'display_total'=>$display_money,
]);
```

With repository mappings like:

```php
protected static function moneyColumns(): array {
	return [
		'display_total_minor'=>[
			'currency_column'=>'display_total_currency',
			'target_column'=>'display_total',
		],
	];
}

protected static function storedMoneyColumns(): array {
	return [
		'priced_total'=>[],
	];
}
```

That means:

- `Money` values can be written through repository `moneyColumns()` aliases or explicit query `asMoney(...)` mappings
- `StoredMoney` values can be written through repository `storedMoneyColumns()` aliases or explicit query `asStoredMoney(...)` mappings
- passing a `Money` or `StoredMoney` object with no matching mapping fails early with a clear SQL money integration error instead of leaking an object into the raw mutation layer

For ad hoc table/repository writes, query-level mappings work too:

```php
DB::table('orders', 'order_id')
	->asStoredMoney('priced_total')
	->whereKey($order_id)
	->update([
		'priced_total'=>$stored_total,
	]);
```

When `asStoredMoney(...)` is used on writes and the value is a plain `Money` object, SQL stores it by asking the currency framework to produce a `StoredMoney` payload first. If the mapping declares `base_currency`, that currency is used for the stored base value; otherwise the current currency framework base currency is used.

You only need `QuerySpec` directly when you want the lower-level builder as a standalone object.

## Scaffolding

The SQL framework includes a small scaffold tool for the common repository path. It generates:

- `framework/Schema/<Entity>TableSchema.php`
- `framework/Repository/<Entity>Repository.php`
- `framework/Record/<Entity>Record.php`

CLI:

```bash
php runtime/modules/sql/kernel/scaffold_table_artifacts.php example_app Machine machines machine_id machine_id,tenant_id,name,status
```

Named options:

```bash
php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=example_app --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status
```

When the application tree is outside the Dataphyre package root, set
`DATAPHYRE_PROJECT_ROOT` to the project directory that contains `applications/`.

Programmatic use:

```php
use Dataphyre\Database\Tools\ScaffoldTableArtifacts;

$result=ScaffoldTableArtifacts::scaffold(
	$project_root,
	'example_app',
	'Machine',
	'machines',
	'machine_id',
	['machine_id', 'tenant_id', 'name', 'status']
);
```

By default the scaffold refuses to overwrite existing files. Pass `--force` on the CLI, or `true` as the final argument programmatically, when you intentionally want to regenerate artifacts.

## PostgreSQL release migrations

Dataphyre owns the reusable PostgreSQL migration state machine. Applications
provide policy and immutable SQL; they do not need to copy manifest parsing,
journaling, drift inspection, advisory locking, rolling-deployment checks, or
down-migration certification into their own release tools.

The complete public contract, manifest-v3 example, expand/contract workflow,
release-provenance rules, and rollback boundary are documented in
[`Dataphyre_PostgreSQL_Migrations.md`](Dataphyre_PostgreSQL_Migrations.md).
The normative machine-readable shape is
[`postgresql-migration-manifest-v3.schema.json`](postgresql-migration-manifest-v3.schema.json).

The application supplies a `PostgreSqlMigrationProfile` with its schema,
journal names, advisory-lock key, grandfathered bootstrap IDs, and timeouts.
`PostgreSqlMigrationManifest::load(...)` then validates a schema-v3 manifest,
confines every referenced SQL file to the selected database component, and
verifies its SHA-256 checksum before any database work begins. It also rejects
transaction-control statements and PostgreSQL concurrent index operations,
which cannot participate in the runner-owned transaction.

```php
use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;

$profile=PostgreSqlMigrationProfile::fromArray([
	'application_id'=>'example_app',
	'schema'=>'example_app',
	'journal_table'=>'schema_migrations',
	'event_table'=>'schema_migration_events',
	'release_digest_column'=>'release_sha256',
	'advisory_lock'=>'example_app.postgresql_migrations',
	'bootstrap_ids'=>['001_legacy_schema_baseline'],
	'bootstrap_cutoff'=>'001_legacy_schema_baseline',
	'manifest_public_path'=>'database/postgresql/manifest.json',
	'lock_timeout'=>'5s',
	'statement_timeout'=>'120s',
]);

$manifest=PostgreSqlMigrationManifest::load(
	$application_root.'/database',
	$profile
);
$runner=new PostgreSqlMigrationRunner($pdo, $profile);

$state=$runner->status($manifest);
$deployment=$runner->deploymentEvidence($manifest, $state, 'rolling');
if($deployment['eligible']===true){
	$result=$runner->apply($manifest, 'rolling');
}
```

Bootstrap migrations retain stable `NNN_name.sql` filenames. Post-bootstrap
migrations use paired `NNN_name.up.sql` and `NNN_name.down.sql` files, or
declare an explicit irreversible reason. A down direction must declare
`lossless` or `data_loss`; data-loss rollback requires an explicit opt-in.
Dataphyre certifies reversible SQL by executing down/up/down inside its
runner-owned transaction. Manifest entries default `change_kind` to `schema`;
explicit `data_only` entries must keep structural fingerprints unchanged while
exactly restoring and repeating their data fingerprints. Lossless migrations
additionally preserve application row counts.

`rolling_expand` accepts only the additive SQL subset checked by
`deploymentEvidence(...)`. Bootstrap and rolling modes select their leading
pending bootstrap/expand or expand-only prefix and defer the first
`rolling_contract` plus its tail. `rolling_contract` records an exact minimum
compatible release and is intentionally rejected from ordinary rolling apply.
After the bootstrap cutoff, `maintenance` mode selects the complete pending
expand/contract suffix transactionally. Selecting maintenance is the
application's assertion that its drain/barrier is already established;
Dataphyre does not verify that operational state.

Maintenance contract evidence requires a caller-verified minimum active release:

```php
$deployment=$runner->deploymentEvidence(
	$manifest,
	$state,
	'maintenance',
	$callerVerifiedMinimumActiveRelease
);
if($deployment['eligible']===true){
	$result=$runner->apply(
		$manifest,
		'maintenance',
		false,
		$releaseIdentity,
		$callerVerifiedMinimumActiveRelease
	);
}
```

Evidence exposes stable `required_minimum_active_release`,
`verified_minimum_active_release`, and `compatibility_floor_satisfied` fields.
It also distinguishes the complete pending inventory from exact
`selected_migrations`, `selected_phases`, and `deferred_migrations`; `apply(...)`
executes only the recomputed selection.
The compatibility argument is rejected outside maintenance. Exact Semantic
Version precedence is implemented by
`PostgreSqlMigrationProfile::compareVersions(...)`; build metadata is ignored,
while prerelease identifiers retain their normative ordering.
Evidence also fails closed when the supplied `status(...)` result reports drift
or omits, duplicates, or assigns a non-deployable status to a manifest entry.

Release-specific authority stays application-owned. For example, a
high-availability application should prove that request responders and
background jobs are drained before calling `rollback(...)`; Dataphyre performs
the database rollback but does not invent or weaken that operational proof.
Likewise, an immutable release launcher should load these classes from its
pinned Dataphyre package rather than whichever framework version is currently
installed on the host.

The profile safely quotes schema/table identifiers, including mixed-case
PostgreSQL names. Its configurable release-digest column defaults to
`release_sha256` and may not collide, case-insensitively, with any fixed event
journal column. The profile implements `JsonSerializable`, making its normalized
public configuration safe to include in validation and planning output.

`manifest_public_path` is a release-relative summary label.
`PostgreSqlMigrationManifest::load($database_root, ...)` always reads
`$database_root/postgresql/manifest.json`; the profile value does not redirect
that filesystem read.

The rolling-expansion scanner is deliberately conservative. In particular,
explicit `CREATE INDEX` is outside the transactional rolling lane. A regular
post-bootstrap index can run transactionally in maintenance mode.
`CREATE INDEX CONCURRENTLY` remains outside the runner because PostgreSQL
requires a separate autocommit protocol.
Schema drift inspection covers supported table, column, primary/foreign-key,
check-constraint, and index syntax; it does not claim semantic inspection of
views, routines, triggers, extensions, privileges, or application invariants.

## Native SQL seeding

Dataphyre SQL treats seed data as versioned application infrastructure rather
than request-service behavior. Application-owned `*.seed.php` files return a
`SeedDefinition` (or a list of definitions); `SeedManager` resolves
dependencies, runs every selected preflight before the first write, applies the
pending batch in one transaction, and records it in a portable SQL ledger. A
matching checksum makes repeat execution a no-op.
Changing an already-applied definition is reported as drift: add a new version
instead of mutating seed history.

Keep reference/demo fixture orchestration in dedicated seeder classes. Runtime
controllers and services should consume persisted data and must not invoke the
seed manager during web requests.

A seed file can delegate to an application seeder while retaining framework
lifecycle metadata:

```php
<?php

use Dataphyre\Database\Seeds\SeedContext;
use Dataphyre\Database\Seeds\SeedDefinition;
use Serve\Database\Seeders\DemoPortfolioSeeder;

return new SeedDefinition(
	id: 'serve.demo-portfolio',
	version: 1,
	up: static fn(SeedContext $context)=>(new DemoPortfolioSeeder())->apply($context),
	preflight: static fn(SeedContext $context)=>(new DemoPortfolioSeeder())->preflight($context),
	description: 'Local multi-concept portfolio fixture',
	profiles: ['demo'],
	content_sources: [
		__DIR__.'/../../src/Database/Seeders/DemoPortfolioSeeder.php',
		__DIR__.'/../../src/Support/DemoFranchisePortfolio.php',
	],
);
```

Seed ids are stable lowercase identifiers. Versions are positive integers.
Dependencies may name an id (which resolves to its latest declared version) or
an exact `id@version`. Versions of the same id are ordered automatically.
Seed callbacks receive `SeedContext`, whose `query(...)` method executes
Dataphyre SQL immediately with bound values and whose attributes can carry
application/tenant bootstrap context. When a cluster is selected, the context,
ledger queries, mutex, and Framework transaction are all bound to that same
cluster. Application seeders that bypass `SeedContext` must reject clusters they
cannot honor.

`SeedContext::query(...)` also infers registered tables targeted by ordinary
`INSERT`, `UPDATE`, `DELETE`, `MERGE`, `TRUNCATE`, and table-DDL statements. Their
Dataphyre cache invalidations are deferred with the atomic seed transaction and
become visible only after commit. Pass an explicit fourth-argument table list
for vendor-specific mutation syntax that cannot be inferred. Passing `false`
opts out and should be reserved for statements proven not to affect a cached
table.

The standalone seed command also loads the flight-sheet-enabled `cache` kernel,
when present, so inferred invalidations advance the same released shared
Memcached generations used by request and worker processes.

Definitions use the `default` execution profile unless declared otherwise.
Non-production fixtures should use `profiles: ['demo']`; they are excluded from
an ordinary apply and require both `--profile=demo` and the explicit
`--allow-demo` acknowledgement. Applications must still fail closed on demo
profiles in production/staging. `content_sources` fingerprints delegated seeder
and fixture code in addition to the definition file, so changing executable
seed behavior is detected as drift. Persistent programmatic definitions must
provide an explicit content checksum when no seed file is available.

The standalone command discovers `applications/<app>/database/seeds` with
`--app`, accepts repeatable `--path` values, or reads
`DP_SQL_CFG['seeds']['paths']`. Applications with custom SQL/autoload setup can
provide a side-effect-free CLI bootstrap file. With `--app=serve`, Dataphyre
automatically loads `applications/serve/database/seeds/bootstrap.php` when it
exists; an explicit `--bootstrap` path overrides the convention:

```bash
php runtime/modules/sql/kernel/seeds.php list --app=serve
php runtime/modules/sql/kernel/seeds.php status --app=serve --json
php runtime/modules/sql/kernel/seeds.php apply --app=serve --dry-run
php runtime/modules/sql/kernel/seeds.php apply --app=serve
php runtime/modules/sql/kernel/seeds.php apply --app=serve --profile=demo --allow-demo --id=serve.demo-portfolio
php runtime/modules/sql/kernel/seeds.php status --app=serve --cluster=primary --ledger-table=dataphyre_seed_ledger
php runtime/modules/sql/kernel/seeds.php rollback --app=serve --id=serve.reversible-reference@1 --dry-run
php runtime/modules/sql/kernel/seeds.php rollback --app=serve --id=serve.reversible-reference@1 --confirm
php runtime/modules/sql/kernel/seeds.php status --path=applications/serve/database/seeds --bootstrap=applications/serve/database/seeds/bootstrap.php
```

The default ledger is `dataphyre_seed_ledger` with the composite identity
`(seed_id, seed_version)`, checksum, batch, and application timestamp. Override
it with `--ledger-table` or `DP_SQL_CFG['seeds']['ledger_table']`. Rollback is
available only when the exact definition supplies a down callback, the checksum
still matches, no applied dependent exists, and the caller passes `--confirm`.
Every mutation takes a row lock in the companion ledger mutex table, validates
the entire applied graph for drift/orphans, and uses Dataphyre Framework
transaction/savepoint semantics. There is deliberately no reset-all command.

Native mutation transactions are supported on MySQL and PostgreSQL. SQLite
status/catalog operations remain portable, but apply/rollback fail before the
seed callback until Dataphyre's SQLite kernel provides one persistent
transaction connection. An embedding with a verified persistent SQLite
transaction executor may supply it explicitly.

Programmatic use is useful for app-specific command wrappers and tests:

```php
use Dataphyre\Database\Seeds\SeedContext;
use Dataphyre\Database\Seeds\SeedFileLoader;
use Dataphyre\Database\Seeds\SeedManager;
use Dataphyre\Database\Seeds\SqlSeedLedger;

$manager=new SeedManager(
	SeedFileLoader::load(__DIR__.'/database/seeds'),
	new SqlSeedLedger('dataphyre_seed_ledger', cluster: 'primary'),
	new SeedContext(attributes: ['application'=>'serve'], cluster: 'primary'),
);

$pending=$manager->planApply();
$result=$manager->apply();
```

## `TableQuery`

`TableQuery` is the zero-setup application query builder returned by `DB::table(...)`.

It extends `QuerySpec`, so the same filter and paging helpers work immediately, and it executes directly.

Execution helpers include:

- `usingSchema(...)`
- `usingPrimaryKey(...)`
- `whereKey(...)`
- `select(...)`
- `projection(...)`
- `cache(...)`
- `cacheName(...)`
- `cacheNames(...)`
- `withoutCaching()`
- `invalidateOnWrite(...)`
- `invalidateCacheName(...)`
- `invalidateCacheNames(...)`
- `withoutInvalidation()`
- `requireWhereForWrite(...)`
- `allowUnscopedWrite()`
- `with(...)`
- `withRecords(...)`
- `withRelation(...)`
- `withRelationRecords(...)`
- `withCount(...)`
- `withRelationCount(...)`
- `withAggregate(...)`
- `withRelationAggregate(...)`
- `withSum(...)`
- `withAvg(...)`
- `withMin(...)`
- `withMax(...)`
- `withWhereHas(...)`
- `whereHas(...)`
- `whereDoesntHave(...)`
- `forUpdate()`
- `sharedLock()`
- `lockRaw(...)`
- `withoutLocking()`
- `usingHydrator(...)`
- `asRecords()`
- `usingRecordClass(...)`
- `asMoney(...)`
- `asMoneyIn(...)`
- `asStoredMoney(...)`
- `spec()`
- `get(...)`
- `all(...)`
- `first(...)`
- `firstOrFail(...)`
- `value(...)`
- `valueOrFail(...)`
- `pluck(...)`
- `keyBy(...)`
- `sole(...)`
- `soleRecord(...)`
- `soleValue(...)`
- `exists(...)`
- `count(...)`
- `aggregate(...)`
- `sum(...)`
- `avg(...)`
- `min(...)`
- `max(...)`
- `countColumn(...)`
- `countDistinct(...)`
- `aggregateRowsBy(...)`
- `countBy(...)`
- `countDistinctBy(...)`
- `sumBy(...)`
- `avgBy(...)`
- `minBy(...)`
- `maxBy(...)`
- `paginate(...)`
- `getHydrated(...)`
- `firstHydrated(...)`
- `paginateHydrated(...)`
- `getRecords(...)`
- `firstRecord(...)`
- `firstRecordOrFail(...)`
- `paginateRecords(...)`
- `chunk(...)`
- `each(...)`
- `chunkRecords(...)`
- `eachRecord(...)`
- `chunkById(...)`
- `eachById(...)`
- `chunkRecordsById(...)`
- `eachRecordById(...)`
- `find(...)`
- `findOrFail(...)`
- `findHydrated(...)`
- `findHydratedOrFail(...)`
- `findRecord(...)`
- `findRecordOrFail(...)`
- `whereMoneyEq(...)`
- `whereMoneyGt(...)`
- `whereMoneyGte(...)`
- `whereMoneyLt(...)`
- `whereMoneyLte(...)`
- `whereMoneyEqIn(...)`
- `whereMoneyGtIn(...)`
- `whereMoneyGteIn(...)`
- `whereMoneyLtIn(...)`
- `whereMoneyLteIn(...)`
- `create(...)`
- `createMany(...)`
- `firstOrCreate(...)`
- `updateOrCreate(...)`
- `update(...)`
- `updateWithVersion(...)`
- `updateWithVersionOrFail(...)`
- `increment(...)`
- `decrement(...)`
- `delete(...)`
- `upsert(...)`
- `upsertMany(...)`
- `queueGet(...)`
- `queueFirst(...)`
- `queueFirstOrFail(...)`
- `queueGetHydrated(...)`
- `queueGetRecords(...)`
- `queueFirstHydrated(...)`
- `queueFirstRecord(...)`
- `queueFirstRecordOrFail(...)`
- `queueFind(...)`
- `queueFindOrFail(...)`
- `queueFindHydrated(...)`
- `queueFindHydratedOrFail(...)`
- `queueFindRecord(...)`
- `queueFindRecordOrFail(...)`
- `queuePluck(...)`
- `queueKeyBy(...)`
- `queueValue(...)`
- `queueValueOrFail(...)`
- `queueSole(...)`
- `queueSoleRecord(...)`
- `queueSoleValue(...)`
- `queueExists(...)`
- `queueCount(...)`
- `queueAggregate(...)`
- `queueSum(...)`
- `queueAvg(...)`
- `queueMin(...)`
- `queueMax(...)`
- `queueCountColumn(...)`
- `queueCountDistinct(...)`
- `queueAggregateRowsBy(...)`
- `queueCountBy(...)`
- `queueCountDistinctBy(...)`
- `queueSumBy(...)`
- `queueAvgBy(...)`
- `queueMinBy(...)`
- `queueMaxBy(...)`
- `queuePaginate(...)`
- `queuePaginateHydrated(...)`
- `queuePaginateRecords(...)`
- `queueCreate(...)`
- `queueCreateMany(...)`
- `queueUpdate(...)`
- `queueUpdateWithVersion(...)`
- `queueUpdateWithVersionOrFail(...)`
- `queueIncrement(...)`
- `queueDecrement(...)`
- `queueDelete(...)`
- `queueUpsert(...)`
- `queueUpsertMany(...)`

Example:

```php
$machines=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->projection('summary')
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->paginate(1, 25);

$machine_record=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->whereKey($machine_id)
	->asRecords()
	->firstRecordOrFail();

$report_rows=DB::table('machines', 'machine_id')
	->usingHydrator(static fn(array $row): object => (object)$row)
	->whereEq('status', 'active')
	->getHydrated();

$machine=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->invalidateCacheName('machines.summary')
	->firstOrCreate(
		['machine_id'=>'MACHINE_123'],
		['tenant_id'=>$tenant_id, 'name'=>'Mixer A', 'status'=>'active']
	);

DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->whereKey('MACHINE_123')
	->increment('view_count');

$saved=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->whereKey('MACHINE_123')
	->updateWithVersion(['status'=>'queued'], $expected_version);

if($saved->affectedRows()===0){
	// stale version or no matching row
}

$locked_machine=DB::transaction(static function(): array{
	return DB::table('machines', 'machine_id')
		->usingSchema(MachineTableSchema::schema())
		->whereKey('MACHINE_123')
		->forUpdate()
		->firstOrFail();
});

DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->whereEq('tenant_id', $tenant_id)
	->orderBy('machine_id')
	->chunk(250, static function(array $rows): void{
		// process one page of rows
	});

DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->whereEq('tenant_id', $tenant_id)
	->eachById(static function(array $row): void{
		// process one stable row
	}, 250);
```

## `RepositoryQuery`

`RepositoryQuery` is the repository-scoped query builder returned by `TableRepository::query()`.

It extends `QuerySpec`, so all `where_*`, `order_by`, and paging helpers work, and it executes directly against its repository.

`TableRepository::query()` starts from the repository's `spec()`, so tenant,
soft-delete, ordering, and other repository-wide constraints also apply to
fluent calls and relation-generated queries. It also captures the repository's
effective `defaultReadCaching()` and `defaultWriteInvalidation()` policies.
Named `cacheName(...)` / `cacheNames(...)` and `invalidateCacheName(...)` /
`invalidateCacheNames(...)` calls merge with repository-default names, including
for queued operations. Explicit `cache(...)`, `withoutCaching()`,
`invalidateOnWrite(...)`, and `withoutInvalidation()` calls replace those
repository defaults.

Execution helpers include:

- `whereKey(...)`
- `select(...)`
- `projection(...)`
- `cache(...)`
- `cacheName(...)`
- `cacheNames(...)`
- `withoutCaching()`
- `invalidateOnWrite(...)`
- `invalidateCacheName(...)`
- `invalidateCacheNames(...)`
- `withoutInvalidation()`
- `requireWhereForWrite(...)`
- `allowUnscopedWrite()`
- `forUpdate()`
- `sharedLock()`
- `lockRaw(...)`
- `withoutLocking()`
- `usingHydrator(...)`
- `asRecords()`
- `usingRecordClass(...)`
- `asMoney(...)`
- `asMoneyIn(...)`
- `asStoredMoney(...)`
- `spec()`
- `get(...)`
- `all(...)`
- `first(...)`
- `firstOrFail(...)`
- `value(...)`
- `valueOrFail(...)`
- `pluck(...)`
- `keyBy(...)`
- `sole(...)`
- `soleRecord(...)`
- `soleValue(...)`
- `exists(...)`
- `count(...)`
- `aggregate(...)`
- `sum(...)`
- `avg(...)`
- `min(...)`
- `max(...)`
- `countColumn(...)`
- `countDistinct(...)`
- `aggregateRowsBy(...)`
- `countBy(...)`
- `countDistinctBy(...)`
- `sumBy(...)`
- `avgBy(...)`
- `minBy(...)`
- `maxBy(...)`
- `paginate(...)`
- `getHydrated(...)`
- `firstHydrated(...)`
- `paginateHydrated(...)`
- `getRecords(...)`
- `firstRecord(...)`
- `firstRecordOrFail(...)`
- `paginateRecords(...)`
- `chunk(...)`
- `each(...)`
- `chunkRecords(...)`
- `eachRecord(...)`
- `chunkById(...)`
- `eachById(...)`
- `chunkRecordsById(...)`
- `eachRecordById(...)`
- `find(...)`
- `findOrFail(...)`
- `findHydrated(...)`
- `findHydratedOrFail(...)`
- `findRecord(...)`
- `findRecordOrFail(...)`
- `whereMoneyEq(...)`
- `whereMoneyGt(...)`
- `whereMoneyGte(...)`
- `whereMoneyLt(...)`
- `whereMoneyLte(...)`
- `whereMoneyEqIn(...)`
- `whereMoneyGtIn(...)`
- `whereMoneyGteIn(...)`
- `whereMoneyLtIn(...)`
- `whereMoneyLteIn(...)`
- `create(...)`
- `createMany(...)`
- `firstOrCreate(...)`
- `updateOrCreate(...)`
- `upsert(...)`
- `upsertMany(...)`
- `update(...)`
- `updateWithVersion(...)`
- `updateWithVersionOrFail(...)`
- `increment(...)`
- `decrement(...)`
- `delete(...)`
- `queueGet(...)`
- `queueFirst(...)`
- `queueFirstOrFail(...)`
- `queueGetHydrated(...)`
- `queueGetRecords(...)`
- `queueFirstHydrated(...)`
- `queueFirstRecord(...)`
- `queueFirstRecordOrFail(...)`
- `queueFind(...)`
- `queueFindOrFail(...)`
- `queueFindHydrated(...)`
- `queueFindHydratedOrFail(...)`
- `queueFindRecord(...)`
- `queueFindRecordOrFail(...)`
- `queuePluck(...)`
- `queueKeyBy(...)`
- `queueValue(...)`
- `queueValueOrFail(...)`
- `queueSole(...)`
- `queueSoleRecord(...)`
- `queueSoleValue(...)`
- `queueExists(...)`
- `queueCount(...)`
- `queueAggregate(...)`
- `queueSum(...)`
- `queueAvg(...)`
- `queueMin(...)`
- `queueMax(...)`
- `queueCountColumn(...)`
- `queueCountDistinct(...)`
- `queueAggregateRowsBy(...)`
- `queueCountBy(...)`
- `queueCountDistinctBy(...)`
- `queueSumBy(...)`
- `queueAvgBy(...)`
- `queueMinBy(...)`
- `queueMaxBy(...)`
- `queuePaginate(...)`
- `queuePaginateHydrated(...)`
- `queuePaginateRecords(...)`
- `queueCreate(...)`
- `queueCreateMany(...)`
- `queueUpdate(...)`
- `queueUpdateWithVersion(...)`
- `queueUpdateWithVersionOrFail(...)`
- `queueIncrement(...)`
- `queueDecrement(...)`
- `queueDelete(...)`
- `queueUpsert(...)`
- `queueUpsertMany(...)`

Example:

```php
$query=MachineRepository::query()
	->projection('summary')
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at');

$fingerprint=$query->fingerprint();
$rows=$query->get();
$records=$query->getRecords();

// Batch-load named repository relations into each returned parent row.
$orders=OrderRepository::query()
	->with('customer')
	->withRecords('lines')
	->withCount('lines')
	->whereHas('lines', static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'))
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->getRecords();

MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->queueCount(static function(int $count): void{
		error_log('Queued machine count: '.$count);
	}, 'reporting');

MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->queuePluck('name', static function(array $names_by_id): void{
		cache_machine_names($names_by_id);
	}, 'machine_id', 'reporting');

MachineRepository::query()
	->whereKey('MACHINE_123')
	->decrement('available_units', 2);

MachineRepository::query()
	->whereKey('MACHINE_123')
	->updateWithVersionOrFail(['status'=>'queued'], $expected_version);

MachineRepository::query()
	->invalidateCacheNames('machines.summary', 'tenant.'.$tenant_id)
	->queueCreateMany(
		$machine_rows,
		static function(MutationBatchResult $batch): void{
			if($batch->failed()){
				error_log($batch->firstErrorMessage() ?? 'Queued machine create batch failed.');
			}
		},
		'imports'
	);

$locked=MachineRepository::query()
	->whereKey('MACHINE_123')
	->forUpdate()
	->firstOrFail();
```

`firstOrCreate(...)` and `updateOrCreate(...)` use explicit lookup attributes. Those attributes should identify a unique row; if a create is needed, lookup attributes win over values with the same key so the inserted row still matches the lookup. After a write, the helper reloads the row without read caching.

`increment(...)` and `decrement(...)` are SQL-side atomic counter writes. They validate the target column through the schema when one is available, require a finite non-negative amount, generate a database-specific `SET column = column +/- ?` expression, and return `MutationResult`. The queued variants use the same counter expression and queue semantics as `queueUpdate(...)`.

`updateWithVersion(...)` performs an atomic optimistic write by appending `WHERE version = ?` and bumping the version column in the same SQL update. `queueUpdateWithVersion(...)` performs the same guarded update on a queue and passes a `MutationResult` to its callback. `affectedRows() === 0` means the expected version did not match or the row was not found; on a single-row update, `affectedRows() === 1` means the write matched and the next version was stored.

Use `stale()` on the returned or queued `MutationResult` when stale writes are a normal branch. Use `throwIfStale()`, `updateWithVersionOrFail(...)`, or `queueUpdateWithVersionOrFail(...)` when a stale write should raise `OptimisticLockException`.

`requireWhereForWrite(...)` makes `update(...)`, `delete(...)`, `increment(...)`, `decrement(...)`, `updateWithVersion(...)`, and their queued variants refuse to run unless the query has at least one `WHERE` fragment. Use `allowUnscopedWrite()` when a table-wide mutation is intentional.

`forUpdate()` and `sharedLock()` add row-locking clauses to read queries. MySQL and PostgreSQL receive database-native locking clauses; SQLite receives no locking suffix because it does not support row-level `SELECT ... FOR UPDATE`. Lock clauses are intentionally ignored for write helpers, count queries, and aggregate queries.

Use `chunk(...)` / `each(...)` for large array result sets and `chunkRecords(...)` / `eachRecord(...)` for hydrated records. Offset chunks are useful for read-only scans. Use `chunkById(...)`, `eachById(...)`, `chunkRecordsById(...)`, or `eachRecordById(...)` for long-running jobs that may update rows while scanning. Keyset chunks use the primary key by default, or an explicit key column, and process rows with `WHERE key > cursor ORDER BY key ASC` unless `DESC` is requested.

## `QuerySpec`

`QuerySpec` is the lower-level lightweight query builder underneath repository queries.

Supported filters include:

- `whereEq(...)`
- `where_not_eq(...)`
- `where_gt(...)`
- `where_gte(...)`
- `where_lt(...)`
- `where_lte(...)`
- `whereIn(...)`
- `whereNotIn(...)`
- `whereLike(...)`
- `whereNotLike(...)`
- `where_between(...)`
- `where_since(...)`
- `where_until(...)`
- `where_after(...)`
- `where_before(...)`
- `where_within(...)`
- `in_last_minutes(...)`
- `in_last_hours(...)`
- `in_last_days(...)`
- `whereNull(...)`
- `whereNotNull(...)`
- `whereRaw(...)`
- `where_all(fn(QuerySpec $group) => ...)`
- `where_any(fn(QuerySpec $group) => ...)`
- `when(...)`
- `unless(...)`
- `when_not_null(...)`
- `when_filled(...)`
- `tap(...)`
- `has_where()`
- `require_where_for_write(...)`
- `allow_unscoped_write()`

Ordering and paging helpers include:

- `orderBy(...)`
- `order_by_raw(...)`
- `orderByAsc(...)`
- `orderByDesc(...)`
- `latest(...)`
- `oldest(...)`
- `groupBy(...)`
- `group_by_raw(...)`
- `having_raw(...)`
- `limit(...)`
- `offset(...)`
- `forPage(...)`
- `clear_ordering()`
- `clear_grouping()`
- `clear_limit()`
- `clear_offset()`
- `clear_paging()`
- `without_ordering()`
- `without_grouping()`
- `without_paging()`
- `for_update()`
- `shared_lock()`
- `lock_raw(...)`
- `clear_locking()`
- `without_locking()`

Example:

```php
$spec=(new QuerySpec())
	->whereEq('tenant_id', $tenant_id)
	->in_last_days('created_at', 30)
	->when_filled($status, static function(QuerySpec $query, string $status): void{
		$query->whereEq('status', $status);
	})
	->when_not_null($machine_id, static function(QuerySpec $query, string $machine_id): void{
		$query->whereEq('machine_id', $machine_id);
	})
	->where_any(static function(QuerySpec $group): void{
		$group->whereEq('status', 'active')
			->whereEq('status', 'queued');
	})
	->latest('created_at')
	->forPage(2, 25);
```

Grouped reads can stay fluent when you need a compact aggregate query:

```php
$spec=(new QuerySpec())
	->whereEq('tenant_id', $tenant_id)
	->groupBy('status')
	->having_raw('COUNT(*) >= ?', [5])
	->order_by_raw('COUNT(*) DESC');
```

`having_raw(...)` keeps its bindings separate from `where_*` bindings internally, so parameters are passed to the SQL driver in compiled SQL order.

Write-scope helpers are carried inside the query spec. `require_where_for_write()` marks a spec so repository/table mutations that receive it must have a `WHERE` clause; `allow_unscoped_write()` explicitly opts out. Repository classes can make this the default for every write by overriding `requireWriteWhere()`.

Locking helpers are also carried inside the query spec and are included in query fingerprints and execution state. Use `for_update()` for exclusive row locks, `shared_lock()` for shared row locks, and `lock_raw(...)` for database-specific suffixes such as skip-locked reads. When a locked spec is reused for a write, count, or aggregate query, the lock suffix is left out of the compiled SQL.

Temporal helpers accept:

- non-empty SQL datetime strings like `'2026-04-01 00:00:00'`
- unix timestamp integers
- `DateTimeInterface` instances

Relative window helpers like `in_last_days(...)` use UTC when they generate the boundary timestamp.

Conditional helpers are deliberately lightweight:

- `when(...)` applies a callback only when the condition is truthy
- `unless(...)` applies a callback only when the condition is falsy
- `when_not_null(...)` is for optional scalar/filter values where `0` and `false` count as present
- `when_filled(...)` is for optional strings or arrays where empty values should be ignored
- `tap(...)` lets you branch into custom query adjustments without breaking the fluent chain

## `TableSchema`

`TableSchema` provides:

- validated table name
- validated column list
- named projections
- optional primary key metadata
- field-payload validation for repository writes
- a bridge to registered table-definition hydration
- explicit read/write casts when created from a `TableDefinition`

Example:

```php
$schema=new TableSchema(
	'machines',
	['machine_id', 'tenant_id', 'name', 'status', 'created_at'],
	[
		'summary'=>['machine_id', 'tenant_id', 'name', 'status'],
	],
	'machine_id'
);

$summary_columns=$schema->projection('summary');
$primary_key=$schema->primaryKey();
```

Resolve named projections with `TableRepository::projectionNamed(...)` or
`TableSchema::projection(...)`, then pass the returned list to `TableRepository`
or `TableQuery` read helpers. Explicit column lists use the same path. The
Framework validates and compiles those lists to a comma-separated SQL selector
before calling the kernel. This is distinct from the low-level
`sql_select(...)` array form, which is reserved for DBMS-specific selector maps
keyed by `mysql`, `postgresql`, or `sqlite`.

`TableSchema` does not create tables from its column list. Missing-table and missing-column hydration are resolved through the SQL table-definition registry, so the same module-owned definition is used for kernel helpers, repositories, and ad hoc `DB::table(...)` queries.

## `TableRepository`

`TableRepository` is an opt-in repository base over the kernel SQL helpers.

Protected low-level helpers:

- `selectMany(...)`
- `selectOne(...)`
- `countWhere(...)`
- `insertOne(...)`
- `updateWhere(...)`
- `update_counter_where(...)`
- `update_version_where(...)`
- `deleteWhere(...)`

Public convenience helpers:

- `query()`
- `tableName()`
- `projectionNamed(...)`
- `relationNamed(...)`
- `primaryKey()`
- `requiresWriteWhere()`
- `all(...)`
- `queueAll(...)`
- `queueAllHydrated(...)`
- `queueAllRecords(...)`
- `first(...)`
- `queueFirst(...)`
- `queueFirstOrFail(...)`
- `queueFirstHydrated(...)`
- `queueFirstRecord(...)`
- `queueFirstRecordOrFail(...)`
- `firstOrFail(...)`
- `value(...)`
- `valueOrFail(...)`
- `queueValueOrFail(...)`
- `pluck(...)`
- `queuePluck(...)`
- `keyBy(...)`
- `queueKeyBy(...)`
- `sole(...)`
- `queueSole(...)`
- `soleRecord(...)`
- `queueSoleRecord(...)`
- `soleValue(...)`
- `queueSoleValue(...)`
- `exists(...)`
- `queueExists(...)`
- `count(...)`
- `queueCount(...)`
- `aggregate(...)`
- `queueAggregate(...)`
- `sum(...)`
- `queueSum(...)`
- `avg(...)`
- `queueAvg(...)`
- `min(...)`
- `queueMin(...)`
- `max(...)`
- `queueMax(...)`
- `countColumn(...)`
- `queueCountColumn(...)`
- `countDistinct(...)`
- `queueCountDistinct(...)`
- `aggregateRowsBy(...)`
- `queueAggregateRowsBy(...)`
- `countBy(...)`
- `queueCountBy(...)`
- `countDistinctBy(...)`
- `queueCountDistinctBy(...)`
- `sumBy(...)`
- `queueSumBy(...)`
- `avgBy(...)`
- `queueAvgBy(...)`
- `minBy(...)`
- `queueMinBy(...)`
- `maxBy(...)`
- `queueMaxBy(...)`
- `paginate(...)`
- `queuePaginate(...)`
- `find(...)`
- `queueFind(...)`
- `queueFindOrFail(...)`
- `findOneBy(...)`
- `findOneByOrFail(...)`
- `queueFindOneBy(...)`
- `queueFindOneByOrFail(...)`
- `findManyBy(...)`
- `queueFindManyBy(...)`
- `findManyByIds(...)`
- `queueFindManyByIds(...)`
- `findKeyedByIds(...)`
- `queueFindKeyedByIds(...)`
- `hydrateRow(...)`
- `hydrateRows(...)`
- `allHydrated(...)`
- `firstHydrated(...)`
- `allRecords(...)`
- `firstRecord(...)`
- `firstRecordOrFail(...)`
- `findOneHydratedBy(...)`
- `findOneHydratedByOrFail(...)`
- `queueFindOneHydratedBy(...)`
- `queueFindOneHydratedByOrFail(...)`
- `findOneRecordBy(...)`
- `findOneRecordByOrFail(...)`
- `queueFindOneRecordBy(...)`
- `queueFindOneRecordByOrFail(...)`
- `findManyHydratedBy(...)`
- `queueFindManyHydratedBy(...)`
- `findManyHydratedByIds(...)`
- `queueFindManyHydratedByIds(...)`
- `findKeyedHydratedByIds(...)`
- `queueFindKeyedHydratedByIds(...)`
- `findHydrated(...)`
- `findHydratedOrFail(...)`
- `queueFindHydrated(...)`
- `queueFindHydratedOrFail(...)`
- `findOrFail(...)`
- `findRecord(...)`
- `queueFindRecord(...)`
- `findRecordOrFail(...)`
- `queueFindRecordOrFail(...)`
- `paginateHydrated(...)`
- `queuePaginateHydrated(...)`
- `paginateRecords(...)`
- `queuePaginateRecords(...)`
- `chunk(...)`
- `each(...)`
- `chunkRecords(...)`
- `eachRecord(...)`
- `chunkById(...)`
- `eachById(...)`
- `chunkRecordsById(...)`
- `eachRecordById(...)`
- `create(...)`
- `queueCreate(...)`
- `createMany(...)`
- `queueCreateMany(...)`
- `firstOrCreate(...)`
- `updateOrCreate(...)`
- `update(...)`
- `updateWithVersion(...)`
- `updateWithVersionOrFail(...)`
- `queueUpdate(...)`
- `queueUpdateWithVersion(...)`
- `queueUpdateWithVersionOrFail(...)`
- `increment(...)`
- `decrement(...)`
- `queueIncrement(...)`
- `queueDecrement(...)`
- `updateBy(...)`
- `queueUpdateBy(...)`
- `updateById(...)`
- `queueUpdateById(...)`
- `updateByWithVersion(...)`
- `queueUpdateByWithVersion(...)`
- `updateByIdWithVersion(...)`
- `queueUpdateByIdWithVersion(...)`
- `updateByWithVersionOrFail(...)`
- `queueUpdateByWithVersionOrFail(...)`
- `updateByIdWithVersionOrFail(...)`
- `queueUpdateByIdWithVersionOrFail(...)`
- `incrementBy(...)`
- `queueIncrementBy(...)`
- `incrementById(...)`
- `queueIncrementById(...)`
- `decrementBy(...)`
- `queueDecrementBy(...)`
- `decrementById(...)`
- `queueDecrementById(...)`
- `delete(...)`
- `queueDelete(...)`
- `deleteBy(...)`
- `queueDeleteBy(...)`
- `deleteById(...)`
- `queueDeleteById(...)`
- `upsert(...)`
- `queueUpsert(...)`
- `upsertMany(...)`
- `queueUpsertMany(...)`

Example repository:

```php
final class MachineRepository extends TableRepository {

	protected static function table(): string {
		return static::schema()->table();
	}

	protected static function schema(): ?TableSchema {
		return MachineTableSchema::schema();
	}

	protected static function defaultWriteInvalidation(): bool|array|null {
		return DB::invalidationNames('machines.summary', 'tenant.machines');
	}

	protected static function requireWriteWhere(): bool {
		return true;
	}

	protected static function moneyColumns(): array {
		return [
			'budget_amount'=>[
				'currency'=>'CAD',
				'target_column'=>'budget_money',
			],
		];
	}

	protected static function storedMoneyColumns(): array {
		return [
			'budget_snapshot'=>[
				'original_prefix'=>'budget_',
				'base_prefix'=>'budget_base_',
				'exchange_prefix'=>'budget_exchange_',
			],
		];
	}
}
```

Example usage:

```php
$spec=(new QuerySpec())
	->whereEq('tenant_id', $tenant_id)
	->orderBy('created_at', 'DESC');

$rows=MachineRepository::all(MachineTableSchema::schema()->projection('summary'), $spec);
$first=MachineRepository::first('*', $spec);
$exists=MachineRepository::exists($spec);
$page=MachineRepository::paginate('*', $spec, 1, 50);
$machine=MachineRepository::find('MACHINE_123');
$machine_by_name=MachineRepository::findOneByOrFail('name', 'Mixer A');
$machine_record=MachineRepository::findOneRecordByOrFail('machine_id', 'MACHINE_123');
$created=MachineRepository::create([
	'machine_id'=>'MACHINE_123',
	'tenant_id'=>'TENANT_1',
	'name'=>'Mixer A',
	'status'=>'active',
]);
$ensured=MachineRepository::firstOrCreate(
	['machine_id'=>'MACHINE_124'],
	['tenant_id'=>'TENANT_1', 'name'=>'Mixer B', 'status'=>'active']
);
$synced=MachineRepository::updateOrCreate(
	['machine_id'=>'MACHINE_125'],
	['tenant_id'=>'TENANT_1', 'name'=>'Mixer C', 'status'=>'maintenance']
);
$updated=MachineRepository::updateById('MACHINE_123', [
	'status'=>'maintenance',
]);
$claimed=MachineRepository::updateByIdWithVersion('MACHINE_123', [
	'status'=>'queued',
], $expected_version);
$claimed=MachineRepository::updateByIdWithVersionOrFail('MACHINE_124', [
	'status'=>'queued',
], $expected_version);
$viewed=MachineRepository::incrementById('MACHINE_123', 'view_count');
$deleted=MachineRepository::deleteById('MACHINE_123');

MachineRepository::queueUpdateById('MACHINE_126', [
	'status'=>'processing',
], static function(mixed $result): void{
	// handle queued update result later
});
MachineRepository::queueUpdateByIdWithVersion('MACHINE_127', [
	'status'=>'processing',
], $expected_version, static function(MutationResult $result): void{
	if($result->stale()){
		return;
	}
});
MachineRepository::queueIncrementById('MACHINE_128', 'view_count', static function(mixed $result): void{
	// handle queued counter result later
});
MachineRepository::queueDeleteById('MACHINE_129', static function(mixed $result): void{
	// handle queued delete result later
});
MachineRepository::queueFindOneByOrFail('machine_id', 'MACHINE_130', static function(array $row): void{
	// handle queued lookup later
});

$queued=MachineRepository::query()
	->projection('summary')
	->whereEq('tenant_id', $tenant_id)
	->whereEq('status', 'queued')
	->get();

$queued_records=MachineRepository::query()
	->projection('summary')
	->whereEq('tenant_id', $tenant_id)
	->whereEq('status', 'queued')
	->getRecords();

MachineRepository::queueAllRecords(
	['machine_id', 'status'],
	MachineRepository::query()->whereEq('status', 'queued')->spec(),
	static function(array $records): void{
		// handle queued records later
	},
	'reporting'
);

$active=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->count();

$average_fill=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->avg('fill_ml');

$jobs_by_status=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->countBy('status');

$fill_by_status=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->sumBy('status', 'fill_ml');

MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->orderBy('machine_id')
	->eachRecord(static function(Record $machine): void{
		// process one record
	}, 250);

MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->eachRecordById(static function(Record $machine): void{
		// process one stable record
	}, 250);
```

## `Record`

If you use the built-in record path, the framework returns `Dataphyre\Database\Record` objects without any custom setup.

`Record` exposes:

- `repositoryClass()`
- `schema()`
- `primaryKeyName()`
- `id()`
- `currentVersion(...)`
- `has(...)`
- `get(...)`
- `money(...)`
- `storedMoney(...)`
- `relation(...)`
- `relationRecords(...)`
- `related(...)`
- `relatedRecords(...)`
- `with(...)`
- `withRelation(...)`
- `refresh(...)`
- `update(...)`
- `updateAndRefresh(...)`
- `updateWithVersion(...)`
- `updateWithVersionOrFail(...)`
- `updateWithCurrentVersion(...)`
- `updateWithCurrentVersionOrFail(...)`
- `updateWithVersionAndRefresh(...)`
- `updateWithCurrentVersionAndRefresh(...)`
- `delete(...)`
- `only(...)`
- `except(...)`
- `toArray()`

It also supports:

- property access: `$record->name`
- array access: `$record['name']`
- iteration and JSON serialization

Example:

```php
$machine=MachineRepository::findRecord('MACHINE_123');

$machine_id=$machine?->id();
$name=$machine?->name;
$status=$machine?['status'];
$subset=$machine?->only(['machine_id', 'name']);

$updated=$machine?->updateWithCurrentVersionAndRefresh([
	'status'=>'queued',
]);
```

Records returned by a repository can also load explicit repository relations by method name or by passing a `Relation` object:

```php
$order=OrderRepository::findRecord($order_id);

$customer=$order?->relation('customer');
$line_records=$order?->relationRecords('lines');
```

Named record relations are resolved through the record's repository class. The repository method must be public, static, take no required parameters, and return a `Relation`.

Records are immutable. `with(...)` and `withRelation(...)` return a new record with extra presentation data attached:

```php
$order_with_customer=$order?->withRelation('customer', $customer);
```

Repository-backed records can delegate explicit write operations back to their repository:

```php
$machine=MachineRepository::findRecord('MACHINE_123');

$updated=$machine?->updateAndRefresh([
	'status'=>'maintenance',
]);

$deleted=$updated?->delete();
```

`update(...)` and `delete(...)` return `MutationResult`. `updateAndRefresh(...)` returns a newly hydrated record after a successful write. `refresh(...)` reloads the current record by primary key without mutating the existing object.

## Convention-First Record Classes

If a repository does not explicitly declare `recordClass()` or `hydrator()`, Dataphyre looks for a matching record class automatically before falling back to the built-in `Record`.

Supported conventions are:

- `App\Framework\Repository\UserRepository` -> `App\Framework\Record\UserRecord`
- `App\Framework\Repository\UserRepository` -> `App\Framework\Repository\UserRecord`

That means a repository can stay clean while returning app-specific typed records from `firstRecord()`, `getRecords()`, and `findRecord()`.

Example record:

```php
final class MachineRecord extends Record {

	public function machineId(): ?string {
		$value=$this->get('machine_id');
		return $value!==null ? (string)$value : null;
	}

	public function isActive(): bool {
		return $this->status()==='active';
	}
}
```

## Record Hydration

The framework can stay array-first, or a repository can opt into typed record hydration.

Hydrators implement:

```php
interface RecordHydrator {
	public function hydrate(array $row, ?TableSchema $schema=null): mixed;
}
```

If no hydrator is declared, Dataphyre first tries the record-class conventions above, then falls back to built-in `Record` objects automatically. A repository can override `protected static function hydrator(): mixed` or `protected static function recordClass(): ?string` to declare its default typed record behavior.

Repositories can also override `protected static function moneyColumns(): array` to apply money casting automatically during hydration. That keeps monetary mapping close to the repository, while raw `get()` and `first()` calls return plain arrays unless you opt into `asMoney(...)` on the query.

The same repository mappings also drive write-side expansion for `create(...)`, `update(...)`, `upsert(...)`, and their queued variants. That keeps money storage shape owned by the repository instead of repeated in application code.

You can supply a hydrator instance, a hydrator class name, a record class name, or a callback.

Example:

```php
final class MachineSummaryHydrator implements RecordHydrator {

	public function hydrate(array $row, ?TableSchema $schema=null): mixed {
		return (object)[
			'id'=>$row[$schema?->primaryKey() ?? 'machine_id'] ?? null,
			'name'=>$row['name'] ?? null,
			'status'=>$row['status'] ?? null,
		];
	}
}

$machine=MachineRepository::findHydrated(
	'MACHINE_123',
	'*',
	new MachineSummaryHydrator()
);

$page=MachineRepository::paginateHydrated(
	'*',
	MachineRepository::query()->whereEq('tenant_id', $tenant_id),
	1,
	25,
	MachineSummaryHydrator::class
);

$record=MachineRepository::query()
	->whereEq('machine_id', 'MACHINE_123')
	->asRecords()
	->firstRecord();

MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->with('location')
	->queueGetRecords(static function(array $records): void{
		// handle hydrated records with eager-loaded relations later
	}, 'reporting');
```

## Relationships

Repository relationships are explicit query objects, not hidden record magic. A repository defines relation factories and application code chooses whether to load one parent, many parents, arrays, or hydrated records.

Example:

```php
final class OrderRepository extends TableRepository {

	public static function customer(): Relation {
		return static::belongsTo(CustomerRepository::class, 'customer_id');
	}

	public static function lines(): Relation {
		return static::hasMany(OrderLineRepository::class, 'order_id');
	}
}

$order=OrderRepository::findOrFail($order_id);
$customer=OrderRepository::customer()->get($order);
$lines=OrderRepository::lines()->get($order);
```

When the parent is a repository-backed `Record`, the same relations can be loaded from the record:

```php
$order=OrderRepository::findRecord($order_id);
$customer=$order?->relation('customer');
$line_records=$order?->relationRecords('lines');
```

Relationship types:

- `belongsTo(RelatedRepository::class, foreign_key, owner_key: related primary key)`
- `hasOne(RelatedRepository::class, foreign_key, local_key: current repository primary key)`
- `hasMany(RelatedRepository::class, foreign_key, local_key: current repository primary key)`

For lists, use query eager loading to batch relation queries and attach the result to each parent:

```php
$orders=OrderRepository::query()
	->with('customer')
	->withRecords('lines')
	->withCount('lines')
	->withSum('lines', 'line_total', 'lines_total')
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->getRecords();
```

`with(...)` attaches related rows as arrays. `withRecords(...)` attaches related rows through the related repository hydrator. The query automatically includes the parent key and related lookup key needed for attachment, even when the selected columns are otherwise narrow. Eager loading is applied to `get(...)`, `first(...)`, pagination, chunking, and hydrated record helpers.

Relation eager loading can be constrained with a related repository query callback:

```php
$orders=OrderRepository::query()
	->with('lines', ['line_id', 'status'], null, static function(RepositoryQuery $line): RepositoryQuery{
		return $line->whereEq('status', 'open')->oldest('created_at');
	})
	->get();
```

Array relation options can also provide `constraint`:

```php
$orders=OrderRepository::query()
	->with([
		'lines'=>[
			'columns'=>['line_id', 'status'],
			'constraint'=>static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'),
		],
	])
	->get();
```

`withCount(...)` attaches a grouped relation count without loading the related rows:

```php
$orders=OrderRepository::query()
	->withCount('lines')
	->withCount(['notes'=>'note_count'])
	->withCount('lines', 'open_line_count', null, static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'))
	->whereEq('tenant_id', $tenant_id)
	->get();

$line_count=$orders[0]['lines_count'] ?? 0;
```

Named counts default to `<relation>_count`. Pass an alias for presentation fields that need a different name. Counts are attached to arrays, objects, and immutable `Record` instances the same way eager relations are.

Use relation aggregates when the screen needs totals or summary values without loading the children:

```php
$orders=OrderRepository::query()
	->withSum('lines', 'line_total', 'lines_total')
	->withSum('lines', 'line_total', 'paid_lines_total', null, static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('paid', true))
	->withAvg('reviews', 'rating', 'average_rating')
	->withMax('shipments', 'created_at', 'latest_shipment_at')
	->get();

$total=$orders[0]['lines_total'] ?? null;
```

`withAggregate(...)` is the lower-level form for `COUNT`, `SUM`, `AVG`, `MIN`, and `MAX`. Convenience helpers are available as `withSum(...)`, `withAvg(...)`, `withMin(...)`, and `withMax(...)`. Aggregate aliases default to `<relation>_<function>_<column>`; missing related rows attach `null` for non-count aggregates.

Use `whereHas(...)` and `whereDoesntHave(...)` to filter parents by related rows before pagination or eager loading:

```php
$orders=OrderRepository::query()
	->whereHas('lines')
	->whereDoesntHave('refunds')
	->get();

$orders_with_open_lines=OrderRepository::query()
	->whereHas('lines', static function(RepositoryQuery $line): RepositoryQuery{
		return $line->whereEq('status', 'open');
	})
	->get();
```

Relation filters compile to `EXISTS` / `NOT EXISTS` subqueries. The optional callback receives a query for the related repository, so child-side filters can use the normal query helpers.

Use `withWhereHas(...)` when the same child constraint should both filter parents and eager-load the matching children:

```php
$orders=OrderRepository::query()
	->withWhereHas('lines', static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'))
	->get();
```

For lower-level control, use relation eager loading directly:

```php
$orders=OrderRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->get();

$customers_by_order=OrderRepository::customer()->eager($orders);
$lines_by_order=OrderRepository::lines()->eager($orders, '*', null, static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'));
$line_counts_by_order=OrderRepository::lines()->eagerCount($orders, null, static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('status', 'open'));
$line_totals_by_order=OrderRepository::lines()->eagerAggregate($orders, 'SUM', 'line_total', null, false, static fn(RepositoryQuery $line): RepositoryQuery => $line->whereEq('paid', true));
```

The returned eager map preserves the original parent array keys. `belongsTo(...)` and `hasOne(...)` map each parent key to one related row or `null`; `hasMany(...)` maps each parent key to a list of related rows.

Use `getRecords(...)` and `eagerRecords(...)` when the relation should use the related repository hydrator:

```php
$customer_record=OrderRepository::customer()->getRecords($order);
$line_records_by_order=OrderRepository::lines()->eagerRecords($orders);
```

Use `attach(...)` and `attachRecords(...)` when you want a parent list back with the eager-loaded relation added under a field name:

```php
$orders=OrderRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->getRecords();

$orders=OrderRepository::customer()->attachRecords($orders, 'customer');
$orders=OrderRepository::lines()->attachRecords($orders, 'lines');
$orders=OrderRepository::lines()->attachCount($orders, 'lines_count');
$orders=OrderRepository::lines()->attachAggregate($orders, 'lines_total', 'SUM', 'line_total');
```

For arrays, the relation is added as an array key. For `Record` objects, a new immutable record is returned with the relation available through property access, array access, and JSON serialization.

## `PageResult`

`PageResult` wraps paginated repository results and exposes:

- `items()`
- `first()`
- `total()`
- `page()`
- `perPage()`
- `lastPage()`
- `hasMorePages()`
- `hasPreviousPage()`
- `firstItemIndex()`
- `lastItemIndex()`
- `values()`
- `pluck(...)`
- `keyBy(...)`
- `map(...)`

It also implements `Countable`, `IteratorAggregate`, and `JsonSerializable`.

Example:

```php
$page=MachineRepository::query()
	->whereEq('tenant_id', $tenant_id)
	->latest('created_at')
	->paginate(2, 25);

$items=$page->items();
$names=$page->pluck('name');
$by_id=$page->keyBy('machine_id');
$summaries=$page->map(static fn(array $row): string => $row['name'].' ('.$row['status'].')');
```

## `MutationResult` and `MutationBatchResult`

Write-side repository helpers return typed results instead of raw kernel return values.

Counter writes use the same result type. `increment(...)` and `decrement(...)` include the target `column` and `amount` in the mutation context.

For `update(...)`, `delete(...)`, counter writes, `updateWithVersion(...)`, and queued optimistic updates, `affectedRows()` reports the database engine's affected-row count when the driver provides one. Zero affected rows is still a successful execution; it means the statement matched no rows or made no change.

For inserts, `insertedId()` accepts scalar driver identities and PostgreSQL-style
`RETURNING` row arrays. Array results are resolved only through the
`primary_key` column in the mutation context; Dataphyre does not guess from the
first returned column, and the complete row remains available through
`rawResult()`.

`MutationResult` exposes:

- `operation()`
- `ok()`
- `failed()`
- `rawResult()`
- `affectedRows()`
- `stale()`
- `throwIfFailed(...)`
- `throwIfStale(...)`
- `throwIfFailedOrStale(...)`
- `insertedId()`
- `errorMessage()`
- `context()`

`MutationBatchResult` exposes:

- `operation()`
- `results()`
- `requested()`
- `processed()`
- `successful()`
- `failedCount()`
- `ok()`
- `failed()`
- `noop()`
- `errorMessages()`
- `firstErrorMessage()`

Example:

```php
$created=MachineRepository::create([
	'machine_id'=>'MACHINE_125',
	'tenant_id'=>'TENANT_1',
	'name'=>'Mixer C',
	'status'=>'active',
]);

$updated=MachineRepository::updateById('MACHINE_123', [
	'status'=>'maintenance',
]);

$batch=MachineRepository::upsertMany([
	[
		'machine_id'=>'MACHINE_123',
		'tenant_id'=>'TENANT_1',
		'name'=>'Mixer A',
		'status'=>'active',
	],
	[
		'machine_id'=>'MACHINE_124',
		'tenant_id'=>'TENANT_1',
		'name'=>'Mixer B',
		'status'=>'queued',
	],
]);

if($created->failed()){
	error_log($created->errorMessage() ?? 'Create failed.');
}

$affected=$updated->affectedRows();
$inserted_id=$created->insertedId();
$processed=$batch->processed();
$ok=$batch->ok();
$first_batch_error=$batch->firstErrorMessage();
$batch_errors=$batch->errorMessages();
```

### Bounded multi-row mutations

Synchronous `createMany(...)` and `upsertMany(...)` use bounded multi-value SQL
statements when the active DBMS can preserve the existing per-row
`MutationBatchResult` contract. This removes the former one-round-trip-per-row
behavior without adding an implicit transaction or changing requested/processed
accounting. `TableRepository`, `RepositoryQuery`, and `TableQuery` expose the
same optional final `BulkMutationOptions` argument:

```php
use Dataphyre\Database\BulkMutationOptions;

$created=MachineRepository::createMany(
	$rows,
	clearCache: true,
	options: BulkMutationOptions::inserts(maxRowsPerStatement: 128),
);

$created_with_generated_ids=EventRepository::createMany(
	$rowsWithUniqueExternalKeys,
	clearCache: true,
	options: BulkMutationOptions::inserts(correlationColumn: 'external_key'),
);

$inventory=InventoryRepository::upsertMany(
	$rows,
	clearCache: true,
	options: BulkMutationOptions::upserts(
		conflictColumns: ['tenant_id', 'sku'],
		updateColumns: ['name', 'quantity', 'updated_at'],
	),
);
```

The planner validates and casts every row through the normal repository/schema
field path before it emits SQL. Consecutive rows with the same canonical column
set share a statement; a changed shape starts a new group so omitted columns
still receive their database defaults and input mutation order is not
rearranged. Each group is split by both a row ceiling and a bind-parameter
ceiling. Defaults are 128 rows, 32,000 PostgreSQL/YSQL parameters, and 900
SQLite parameters. Applications can lower or tune the bounds globally or for a
registered table:

```php
'bulk_mutations'=>[
	'enabled'=>true,
	'max_rows_per_statement'=>128,
	'parameter_limits'=>[
		'postgresql'=>32000,
		'sqlite'=>900,
	],
],

'tables'=>[
	'inventory'=>[
		'bulk_mutations'=>[
			'max_rows_per_statement'=>64,
			'max_parameters'=>8000,
		],
	],
],
```

These bounds are statement limits, not a promise that every call becomes one
statement. For example, the focused contract proves that 1,000 compatible
two-column PostgreSQL rows compile to eight statements at the 128-row default,
instead of 1,000 statements. YugabyteDB likewise recommends starting with 128
rows and tuning for the workload, explicitly recommends multi-row inserts and
multi-row `INSERT ... ON CONFLICT`, and notes that batching reduces distributed
round trips. See the YugabyteDB
[YSQL data-modeling and performance guidance](https://docs.yugabyte.com/stable/develop/best-practices-develop/data-modeling-perf/)
and [YSQL `INSERT` contract](https://docs.yugabyte.com/stable/api/ysql/the-sql-language/statements/dml_insert/).

Compatibility and failure behavior are explicit:

- PostgreSQL and YugabyteDB inserts use multi-row `VALUES ... ON CONFLICT DO
  NOTHING RETURNING <primary key>, <correlation column>`. By default, every row
  must supply a distinct scalar
  repository primary key, and returned rows are correlated by that key rather
  than unspecified result order. Tables with database-generated primary keys
  can instead set `correlationColumn` to a distinct scalar input column, such as
  an application-generated UUID; the column must be present with a distinct
  scalar value in every input row and must round-trip without trigger-side
  rewriting. A database uniqueness constraint is recommended for durable
  application keys. The returned primary key still becomes each child's
  `insertedId()`. Without either safe correlation shape,
  Dataphyre stays on the legacy per-row path rather than inventing identities.
  Conflicting rows remain failed child results just as the single-row helper
  reports them. PostgreSQL documents that `RETURNING` contains only rows
  actually inserted or updated in its
  [`INSERT` reference](https://www.postgresql.org/docs/current/sql-insert.html).
- PostgreSQL/YSQL and SQLite upserts batch only the portable
  `ON CONFLICT (<explicit columns>) DO UPDATE SET ...` shape. Conflict columns
  come from `BulkMutationOptions::upserts(...)` or, when options are omitted,
  the repository/table primary key. Explicit conflict metadata fails closed if
  the active path cannot represent it; it is never silently discarded.
- SQLite inserts use its existing `INSERT OR IGNORE` success semantics, so child
  insert identities remain `null`. SQLite documents multi-row `VALUES`, omitted
  column defaults, `UPSERT`, and `RETURNING` in its
  [`INSERT` reference](https://sqlite.org/lang_insert.html).
- MySQL keeps the compatible per-row path. Its multi-row insert result exposes
  only the first generated auto-increment identity, and `ON DUPLICATE KEY UPDATE`
  cannot select one exact conflict target. Dataphyre therefore does not invent
  child identities or claim that a composite target was honored. MySQL's
  documentation describes both the
  [first-identity behavior](https://dev.mysql.com/doc/refman/26.7/en/example-auto-increment.html)
  and the performance benefit of
  [multi-value inserts](https://dev.mysql.com/doc/refman/26.7/en/insert-optimization.html);
  a future driver result contract can unlock that optimization safely.
- Custom legacy `$updateParams` / `$updateVars` keep their per-row execution
  path. Queued `queueCreateMany(...)` and `queueUpsertMany(...)` also remain
  per-row because their callback delivery/order contract is different from the
  synchronous batch result. Tables configured with `multipoint_writes` retain
  the endpoint-aware per-row kernel path; raw bulk SQL is never substituted for
  that replication contract.

A successful multi-row statement is atomic at the database statement boundary;
Dataphyre does not wrap the whole batch in a new transaction. If one bulk insert
statement is rejected, its rows replay through the legacy single-row helper so
partial failure and identity results remain observable. A rejected bulk upsert
replays as single-row statements compiled with the same explicit conflict
target. Previously successful chunks remain committed outside a caller-owned
transaction, matching the old batch's row-by-row progress. An error aborts an
active PostgreSQL/YSQL transaction, so Dataphyre deliberately does not issue
doomed per-row retries inside a Framework transaction: every child in that
statement is reported failed and the owning transaction must roll back. Normal
cluster affinity, cache bypass, and commit-time invalidation rules still apply.

Cache invalidation occurs once per physical statement, and `clearCache: true`
is resolved to the real table rather than the raw-query namespace. Named
invalidations remain unchanged. SQL observers receive one execution event per
physical statement with `framework_operation`, `bulk_mutation`, and
`bulk_rows` trace context; a rejected statement's isolated retries carry
`bulk_mutation_fallback`. Consumers that previously counted one observer event
per input row must use `bulk_rows` or batch results instead.

## `DB`, `ConnectionContext`, `Transaction`, and `TransactionResult`

The framework also provides a low-magic transaction layer over the kernel transaction primitives.

`DB::transaction(...)` throws on transaction begin/commit/rollback failures and rethrows callback exceptions. `DB::attemptTransaction(...)` gives the same orchestration path without forcing exception-based control flow. `transactionWithRetries(...)` and `attemptTransactionWithRetries(...)` retry transient SQL failures such as deadlocks, lock timeouts, serialization failures, and SQLite busy/locked errors.

YugabyteDB can surface a retryable distributed conflict after its internal
best-effort retries without preserving SQLSTATE through every driver or YSQL
Connection Manager path. `SqlError::isTransientTransactionException(...)`
therefore also recognizes YugabyteDB's concrete message signatures: `All
transparent retries exhausted`, `Restart read required`, transaction expiry or
abort by a conflict, higher-priority transaction conflicts, `Value write after
transaction start`, and `kConflict`. Classification remains deliberately
narrow: generic `try again`, generic exhausted-retry prose, ordinary mentions
of read-restart policy, constraint violations, and other deterministic failures
do not become retryable. See YugabyteDB's [transaction retry
guidance](https://docs.yugabyte.com/stable/develop/learn/transactions/transactions-retries-ysql/),
[concurrency-control error examples](https://docs.yugabyte.com/stable/architecture/transactions/concurrency-control/),
and [`Restart read required` contract](https://docs.yugabyte.com/stable/architecture/transactions/read-restart-error/).

Transaction callbacks can be zero-argument callbacks, or they can ask for a `Transaction` and/or `ConnectionContext` parameter. Typed callback parameters are matched by type. Untyped callbacks receive the transaction first from `DB::transaction(...)` / `Transaction::run(...)`, and the connection first from `ConnectionContext::transaction(...)`.

Nested framework transactions use database savepoints. The outermost transaction calls `BEGIN` / `COMMIT` / `ROLLBACK`; inner transactions on the same cluster call `SAVEPOINT`, `RELEASE SAVEPOINT`, and `ROLLBACK TO SAVEPOINT`. This allows repository methods to wrap their own write flows without accidentally committing or rolling back an outer unit of work.

An active framework transaction also establishes cluster affinity for ordinary `DB` raw helpers and ORM/table operations. A callback opened through `DB::transaction(..., 'analytics')` therefore cannot silently route a repository call back to that table's configured/default cluster. Pass an explicit connection context only when deliberately opening an independent transaction on another cluster. Transaction depth, active-cluster routing, cache bypass, and deferred invalidations are isolated per Fiber so concurrent execution contexts do not inherit one another's unit of work.

When the caller omits a cluster, Dataphyre resolves the transaction bookkeeping key from `DP_SQL_CFG['default_cluster']`; the implicit transaction and an explicit reference to that same configured cluster therefore share one active depth and connection identity. This normalization matters for nested repository transactions and `transactionAdvisoryLock(...)`: both must recognize the default-cluster transaction already in progress rather than rejecting the lock or opening an unrelated unit of work. If no configured default exists, Dataphyre retains an internal default sentinel until connection discovery can resolve one.

Framework transactions are cache-safe by construction. Reads issued while a transaction is active bypass Dataphyre's read caches so they observe prior writes without publishing uncommitted rows. Successful writes queue their table or named-cache invalidations on the active transaction: released savepoints merge invalidations into their parent, rolled-back work discards them, and only a successful outer commit flushes them. Commit-time cache backend failures are logged after the database commit and are never converted into a retryable transaction failure, because replaying an already-committed callback could duplicate durable side effects. Raw kernel transactions that bypass `Dataphyre\Database\Transaction` cannot receive this coordination and must not combine transactional writes with shared-cache reads or invalidation.

`shared_cache` is used only while Dataphyre Cache reports a healthy shared Memcached backend. Its request-local outage fallback is deliberately treated as a cache miss: SQL neither reads nor publishes local entries under a shared policy. A shared read verifies the table generation both before and after fetching its payload, so an invalidation racing the two Memcached operations becomes a miss instead of leaking one stale response. Registered table names in a multi-target invalidation also advance their shared table-version tokens, so raw multi-table writes invalidate entries created by other workers rather than relying only on a request-local named-key index. If Memcached is unavailable when a post-commit version increment is due, Dataphyre emits a failed-invalidation observer event; keep shared-cache lifespans bounded because an already-committed database callback cannot be safely replayed.

`DB` exposes:

- `connection(...)`
- `table(...)`
- `cluster(...)`
- `cacheNames(...)`
- `mergeCacheNames(...)`
- `invalidationNames(...)`
- `mergeInvalidationNames(...)`
- `defaultReadCaching()`
- `defaultCluster()`
- `clusters()`
- `hasCluster(...)`
- `clusterDbms(...)`
- `begin(...)`
- `transaction(...)`
- `transactionAdvisoryLock(...)`
- `attemptTransaction(...)`
- `transactionWithRetries(...)`
- `attemptTransactionWithRetries(...)`
- `commit(...)`
- `rollback(...)`
- `query(...)`
- `value(...)`
- `row(...)`
- `rows(...)`
- `queueQuery(...)`
- `queueValue(...)`
- `queueRow(...)`
- `queueRows(...)`
- `executeQueue(...)`
- `invalidateCache(...)`
- `observe(...)`
- `clearObservers()`
- `lastTrace()`
- `recentTraces(...)`
- `clearTraceBuffer()`
- `setTraceBufferLimit(...)`
- `enableGuardrails(...)`
- `disableGuardrails()`
- `guardrailsEnabled()`

`ConnectionContext` exposes:

- `cluster()`
- `dbms()`
- `begin()`
- `transaction(...)`
- `attemptTransaction(...)`
- `transactionWithRetries(...)`
- `attemptTransactionWithRetries(...)`
- `query(...)`
- `value(...)`
- `row(...)`
- `rows(...)`
- `queueQuery(...)`
- `queueValue(...)`
- `queueRow(...)`
- `queueRows(...)`

`Transaction` exposes:

- `cluster()`
- `connection()`
- `isNested()`
- `savepointName()`
- `activeDepth(...)`
- `activeCluster()`
- `hasActiveTransaction()`
- `isActive()`
- `begun()`
- `committed()`
- `rolledBack()`
- `begin()`
- `commit()`
- `rollback()`
- `run(...)`
- `attempt(...)`
- `runWithRetries(...)`
- `attemptWithRetries(...)`

`TransactionResult` exposes:

- `cluster()`
- `ok()`
- `failed()`
- `begun()`
- `committed()`
- `rolledBack()`
- `attempts()`
- `value()`
- `exception()`
- `errorMessage()`
- `errorClass()`

Examples:

```php
$users=DB::table('users', 'user_id')
	->whereEq('tenant_id', $tenant_id)
	->orderBy('created_at', 'DESC')
	->paginate(1, 25);

$user_record=DB::table('users', 'user_id')
	->whereKey($user_id)
	->firstRecord();

$analytics=DB::connection('analytics');

$total=$analytics->value('SELECT COUNT(*) FROM events WHERE tenant_id=?', [$tenant_id]);
$recent=$analytics->rows(
	'SELECT event_id, name, created_at FROM events WHERE tenant_id=? ORDER BY created_at DESC LIMIT 50',
	[$tenant_id]
);

$result=DB::transaction(static function(){
	MachineRepository::create([
		'machine_id'=>'MACHINE_200',
		'tenant_id'=>'TENANT_1',
		'name'=>'Mixer C',
		'status'=>'active',
	]);
	return MachineRepository::updateById('MACHINE_123', [
		'status'=>'queued',
	]);
});

$nested=DB::transaction(static function(Transaction $outer){
	return DB::transaction(static function(Transaction $inner){
		MachineRepository::incrementById('MACHINE_123', 'view_count');
		return $inner->isNested();
	});
});

$serialized=DB::transaction(static function(): MutationResult{
	DB::transactionAdvisoryLock('tenant:42:inventory:SKU-100');
	return InventoryRepository::query()
		->whereEq('tenant_id', 42)
		->whereEq('sku', 'SKU-100')
		->increment('reserved_quantity');
});

$attempt=DB::attemptTransaction(static function(){
	return MachineRepository::deleteById('MACHINE_404');
});

$retry_result=DB::attemptTransactionWithRetries(
	static function(): MutationResult{
		return MachineRepository::query()
			->whereKey('MACHINE_123')
			->forUpdate()
			->increment('view_count');
	},
	null,
	3,
	null,
	25
);

$connection_attempt=$analytics->attemptTransaction(static function(ConnectionContext $connection){
	return $connection->value('SELECT COUNT(*) FROM events');
});

$analytics_result=$analytics->transaction(static function(ConnectionContext $connection){
	return $connection->row(
		'SELECT event_id, name FROM events WHERE tenant_id=? ORDER BY created_at DESC LIMIT 1',
		[$tenant_id]
	);
});

$attempt_ok=$attempt->ok();
$attempt_value=$attempt->value();
$attempt_error=$attempt->errorMessage();
$retry_attempts=$retry_result->attempts();

$tx=DB::begin();
try{
	MachineRepository::updateById('MACHINE_123', ['status'=>'maintenance']);
	DB::commit($tx);
}catch(\Throwable $exception){
	if($tx->isActive()){
		DB::rollback($tx);
	}
	throw $exception;
}

$manual=(new Transaction('default'))->attempt(static function(Transaction $transaction){
	if(!$transaction->begun()){
		$transaction->begin();
	}

	MachineRepository::updateById('MACHINE_123', ['status'=>'active']);

	return 'ok';
});

$manual_ok=$manual->ok();
$manual_cluster=$manual->cluster();
```

`DB::transactionAdvisoryLock(...)` exposes only PostgreSQL-compatible
transaction-level advisory locks. It must run inside an active Dataphyre
transaction, hashes the prepared string key with PostgreSQL's
`hashtextextended(..., 0)`, and releases the lock with the outermost transaction.
Dataphyre intentionally does not expose session advisory locks: session state can
leak to unrelated clients when a pool or YugabyteDB YSQL Connection Manager
reuses a server connection.

The helper fails explicitly for non-PostgreSQL DBMSs and PostgreSQL-compatible
servers it cannot identify. For YugabyteDB, the process performs a cached server
version preflight and requires v2025.1 or later before the lock query. Cluster
operators must also keep advisory locks enabled and include this preflight in
release qualification; YugabyteDB documents distributed advisory locks as
available and enabled by default starting in v2025.1. See YugabyteDB's
[explicit locking documentation](https://docs.yugabyte.com/stable/explore/transactions/explicit-locking/).

Database discovery remains safe during partial bootstrap: before optional
`DP_SQL_CFG` or `DP_CORE_CFG` constants exist, `DB::clusters()` returns an empty
catalog and the default-cluster, DBMS, and datacenter helpers return `null`.

## Design Notes

- The happy path is repository-first and executable directly.
- The framework layer is optional and explicit.
- The kernel path stays available for the tightest loops and specialized queries.
- Framework repository/table reads inherit the kernel read-cache default instead of silently opting out.
- Framework cache and queue orchestration can be observed through opt-in execution traces instead of hidden state.
- Repository count operations strip ordering and paging automatically before counting.
- Repository write helpers validate field names through `TableSchema` when a schema is declared.
- Empty framework write payloads fail early instead of leaking into kernel mutation calls.
- Primary-key aware helpers stay opt-in through `TableSchema` metadata instead of assuming every table has an `id` column.
- Framework transactions wrap the existing kernel primitives instead of replacing them.
- Cluster-aware framework query helpers only affect raw `sql_query(...)` flows and transaction scope; table repository routing follows kernel table configuration.
- Framework transactions model one explicit transaction scope at a time; nested/savepoint behavior is left to kernel-level custom work.
- Record hydration is explicit and opt-in; repositories return raw arrays unless a hydrator path is chosen.
- The default typed record path is the built-in `Record` object, so apps can move beyond arrays without custom hydrator boilerplate.
- Repo-specific record classes can be discovered by convention, so common typed-record setups do not require explicit repository wiring.
- Guardrail warnings are meant to catch suspicious data-access patterns like named cached reads followed by writes with no invalidation policy.
- `QuerySpec` validates identifiers and allows explicit `whereRaw(...)` escapes when needed.

## Laravel Mapping

| Laravel | Dataphyre SQL Framework |
| --- | --- |
| `User::find($id)` | `UserRepository::find($id)` |
| `DB::table('users')->where('email', $email)->first()` | `DB::table('users', 'user_id')->whereEq('email', $email)->first()` |
| `User::where('email', $email)->first()` | `UserRepository::query()->whereEq('email', $email)->first()` |
| `User::where('tenant_id', $tenant_id)->get()` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->get()` |
| `User::where('tenant_id', $tenant_id)->paginate(25)` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->paginate(1, 25)` |
| `User::where('tenant_id', $tenant_id)->sum('balance')` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->sum('balance')` |
| `User::where('tenant_id', $tenant_id)->count('email')` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->countColumn('email')` |
| `User::where('tenant_id', $tenant_id)->distinct()->count('status')` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->countDistinct('status')` |
| `User::where('created_at', '>=', $cutoff)->latest()->get()` | `UserRepository::query()->in_last_days('created_at', 30)->latest()->get()` |
| `User::when($status, fn($query)=>$query->where('status', $status))->get()` | `UserRepository::query()->when_filled($status, fn(QuerySpec $query, string $status)=>$query->whereEq('status', $status))->get()` |
| `User::where('tenant_id', $tenant_id)->groupBy('status')->selectRaw('status, count(*) as aggregate_value')->pluck('aggregate_value', 'status')` | `UserRepository::query()->whereEq('tenant_id', $tenant_id)->countBy('status')` |
| `User::where('status', 'queued')->update([...])` | `UserRepository::query()->whereEq('status', 'queued')->update([...])` |
| `User::where('status', 'queued')->delete()` | `UserRepository::query()->whereEq('status', 'queued')->delete()` |
| `DB::transaction(fn()=>...)` | `DB::transaction(fn()=>...)` |

This gives Dataphyre SQL a stronger application layer without turning the kernel into an ORM. The framework remains a low-magic repository/query system over the existing high-performance execution engine.
