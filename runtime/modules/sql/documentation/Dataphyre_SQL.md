# Dataphyre SQL Module

The SQL module is Dataphyre's kernel database engine. It provides DBMS-aware query execution, caching, migration coordination, queue-aware query handling, and the optional `Dataphyre\Database` framework layer.

The framework layer is built around `execution-aware queries`. A query describes both data intent and execution policy in one explicit place: filters, caching, named cache indexes, invalidation, deferred execution, and observability all live on the same surface instead of being bolted on later.

It also composes with other Dataphyre framework modules explicitly. SQL can hydrate typed money objects from the currency framework and compare monetary values without pushing float-wrapping back into application code.

## Execution-Aware Queries

In Dataphyre SQL, the happy path stays simple:

```php
UserRepository::query()->where_eq('email', $email)->first();
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
	->where_eq('tenant_id', $tenant_id)
	->in_last_days('created_at', 30)
	->countBy('status');

OrderRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->where_eq('status', 'queued')
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
	->where_eq('email', $email)
	->first();
```

Then, when the code deserves structure:

```php
UserRepository::query()
	->where_eq('email', $email)
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
	->where_eq('email', $email)
	->first();

$created=DB::table('users', 'user_id')->create([
	'email'=>$email,
	'status'=>'active',
]);

$recent=DB::table('users', 'user_id')
	->where_eq('status', 'active')
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
use Dataphyre\Database\ExecutionTrace;
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
use Dataphyre\Database\Tools\ScaffoldTableArtifacts;
use Dataphyre\Database\Contracts\RecordHydrator;
```

The framework is explicit on the failure path too. When setup or usage is wrong, exceptions include:

- what failed
- the repository, table, or cluster involved
- the relevant identifier or projection name
- a concrete hint for how to fix it

Mutation results also expose `errorMessage()` and `context()` so non-throwing flows can report useful failures. Batch results expose `errorMessages()` and `firstErrorMessage()` for the same reason.

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
- queued callbacks receive framework-shaped results for scalar helpers like `queueValue(...)` and `queueAggregate(...)`
- query builders can carry a default write invalidation target through `invalidateOnWrite(...)`
- repositories can opt into a default write invalidation policy through `defaultWriteInvalidation()`
- observability is opt-in through `DB::observe(...)`, `DB::lastTrace()`, `DB::recentTraces(...)`, and `DB::recentTracesByContext(...)`
- when `IS_PRODUCTION === true`, Dataphyre disables public SQL trace buffering and retrieval
- guardrail warnings can be enabled with `DB::enableGuardrails()` and are on by default in `RUN_MODE==='diagnostic'`
- when the templating framework is also loaded, named SQL cache invalidations automatically clear matching templating binding cache names

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
	->where_eq('tenant_id', $tenant_id)
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
- `firstRecordOrFail(...)`
- `findRecordOrFail(...)`

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
		->where_eq('machine_id', $machine_id)
		->firstRecordOrFail();

	$status=MachineRepository::query()
		->where_eq('tenant_id', $tenant_id)
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
	->where_eq('email', $email)
	->first();

$active=DB::table('users', 'user_id')
	->where_eq('status', 'active')
	->count();

$updated=DB::table('users', 'user_id')
	->where_eq('status', 'queued')
	->update(['status'=>'active']);

$users_by_email=DB::table('users', 'user_id')
	->cacheName('users.by_email')
	->where_eq('status', 'active')
	->keyBy('email');

DB::table('users', 'user_id')
	->invalidateCacheNames('users.by_email', 'users.summary')
	->where_eq('status', 'queued')
	->update(['status'=>'active']);
```

Repository-first:

```php
$machine=MachineRepository::query()
	->where_eq('machine_id', $machine_id)
	->first();

$machines=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->order_by('created_at', 'DESC')
	->paginate(1, 25);

$record=MachineRepository::query()
	->where_eq('machine_id', $machine_id)
	->firstRecord();

$required=MachineRepository::query()
	->where_eq('machine_id', $machine_id)
	->firstRecordOrFail();

$status=MachineRepository::query()
	->where_eq('machine_id', $machine_id)
	->soleValue('status');

$names=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->pluck('name');

$machines_by_id=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->keyBy('machine_id');

$queued_name_count=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->countColumn('name');

$unique_statuses=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->countDistinct('status');

$avg_fill=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->avg('fill_ml');

$jobs_by_status=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->countBy('status');

$fill_by_status=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->sumBy('status', 'fill_ml');

$status_rows=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->aggregateRowsBy(['tenant_id', 'status'], 'COUNT');

$updated=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->where_eq('status', 'queued')
	->update(['status'=>'active']);

MachineRepository::query()
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->where_eq('tenant_id', $tenant_id)
	->queueGet(static function(array $rows): void{
		// handle queued rows later
	}, 'reporting');

MachineRepository::query()
	->invalidateCacheNames('machines.summary', 'tenant.'.$tenant_id)
	->where_eq('tenant_id', $tenant_id)
	->where_eq('status', 'queued')
	->queueUpdate(
		['status'=>'active'],
		static function(int|bool|null $result): void{
			// handle queued write result later
		},
		'reporting'
	);
```

## Currency Integration

SQL can hydrate money values explicitly when the currency framework is loaded:

```php
\dataphyre\core::load_framework_module('currency');

use Dataphyre\Currency\Currency;

$order=OrderRepository::query()
	->asMoney('total_amount', 'currency')
	->where_key($order_id)
	->firstRecordOrFail();

$total=$order->total_amount;
```

That keeps the raw storage shape in SQL while letting application code work with `Dataphyre\Currency\Money` objects naturally.

If a repository always stores the same money fields, it can declare them once and let record hydration apply them automatically:

```php
protected static function moneyColumns(): array {
	return [
		'total_amount'=>'currency',
		'base_total'=>[
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
	->whereMoneyLte('total_amount', Currency::money(100, 'USD'), 'currency')
	->get();
```

When the stored amount column is normalized to one fixed currency, use the fixed-currency helpers:

```php
$rows=LedgerRepository::query()
	->whereMoneyGteIn('base_total', Currency::money(100, 'USD'), 'CAD')
	->get();
```

`whereMoney...(...)` is for amount-plus-currency row storage. `whereMoney...In(...)` is for columns stored in one known currency.

SQL can also hydrate the canonical persisted `StoredMoney` shape when rows keep original money, normalized base money, and exchange metadata together:

```php
$order=OrderRepository::query()
	->asStoredMoney('priced_total')
	->where_key($order_id)
	->firstRecordOrFail();

$stored=$order->priced_total;
$original=$stored->original();
$base=$stored->base();
```

`asStoredMoney()` defaults to the canonical storage keys:

- `original_amount`
- `original_currency`
- `base_amount`
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
		'display_total_amount'=>[
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
	->where_key($order_id)
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
php runtime/modules/sql/kernel/scaffold_table_artifacts.php volumetrix Machine machines machine_id machine_id,tenant_id,name,status
```

Named options:

```bash
php runtime/modules/sql/kernel/scaffold_table_artifacts.php --application=volumetrix --entity=Machine --table=machines --primary-key=machine_id --columns=machine_id,tenant_id,name,status
```

Programmatic use:

```php
use Dataphyre\Database\Tools\ScaffoldTableArtifacts;

$result=ScaffoldTableArtifacts::scaffold(
	$project_root,
	'volumetrix',
	'Machine',
	'machines',
	'machine_id',
	['machine_id', 'tenant_id', 'name', 'status']
);
```

By default the scaffold refuses to overwrite existing files. Pass `--force` on the CLI, or `true` as the final argument programmatically, when you intentionally want to regenerate artifacts.

## `TableQuery`

`TableQuery` is the zero-setup application query builder returned by `DB::table(...)`.

It extends `QuerySpec`, so the same filter and paging helpers work immediately, and it executes directly.

Execution helpers include:

- `usingSchema(...)`
- `usingPrimaryKey(...)`
- `where_key(...)`
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
- `find(...)`
- `findOrFail(...)`
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
- `update(...)`
- `delete(...)`
- `upsert(...)`
- `queueGet(...)`
- `queueFirst(...)`
- `queueValue(...)`
- `queueCount(...)`
- `queueAggregate(...)`
- `queueCreate(...)`
- `queueUpdate(...)`
- `queueDelete(...)`
- `queueUpsert(...)`

Example:

```php
$machines=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->projection('summary')
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->where_eq('tenant_id', $tenant_id)
	->latest('created_at')
	->paginate(1, 25);

$machine_record=DB::table('machines', 'machine_id')
	->usingSchema(MachineTableSchema::schema())
	->where_key($machine_id)
	->asRecords()
	->firstRecordOrFail();

$report_rows=DB::table('machines', 'machine_id')
	->usingHydrator(static fn(array $row): object => (object)$row)
	->where_eq('status', 'active')
	->getHydrated();
```

## `RepositoryQuery`

`RepositoryQuery` is the repository-scoped query builder returned by `TableRepository::query()`.

It extends `QuerySpec`, so all `where_*`, `order_by`, and paging helpers work, and it executes directly against its repository.

Execution helpers include:

- `where_key(...)`
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
- `find(...)`
- `findOrFail(...)`
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
- `update(...)`
- `delete(...)`
- `queueGet(...)`
- `queueFirst(...)`
- `queueValue(...)`
- `queueCount(...)`
- `queueAggregate(...)`
- `queueUpdate(...)`
- `queueDelete(...)`

Example:

```php
$query=MachineRepository::query()
	->projection('summary')
	->cacheNames('machines.summary', 'tenant.'.$tenant_id)
	->where_eq('tenant_id', $tenant_id)
	->latest('created_at');

$fingerprint=$query->fingerprint();
$rows=$query->get();
$records=$query->getRecords();

MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->queueCount(static function(int $count): void{
		error_log('Queued machine count: '.$count);
	}, 'reporting');
```

## `QuerySpec`

`QuerySpec` is the lower-level lightweight query builder underneath repository queries.

Supported filters include:

- `where_eq(...)`
- `where_not_eq(...)`
- `where_gt(...)`
- `where_gte(...)`
- `where_lt(...)`
- `where_lte(...)`
- `where_in(...)`
- `where_like(...)`
- `where_not_like(...)`
- `where_between(...)`
- `where_since(...)`
- `where_until(...)`
- `where_after(...)`
- `where_before(...)`
- `where_within(...)`
- `in_last_minutes(...)`
- `in_last_hours(...)`
- `in_last_days(...)`
- `where_null(...)`
- `where_not_null(...)`
- `where_raw(...)`
- `where_all(fn(QuerySpec $group) => ...)`
- `where_any(fn(QuerySpec $group) => ...)`
- `when(...)`
- `unless(...)`
- `when_not_null(...)`
- `when_filled(...)`
- `tap(...)`

Ordering and paging helpers include:

- `order_by(...)`
- `order_by_asc(...)`
- `order_by_desc(...)`
- `latest(...)`
- `oldest(...)`
- `limit(...)`
- `offset(...)`
- `for_page(...)`
- `clear_ordering()`
- `clear_limit()`
- `clear_offset()`
- `clear_paging()`
- `without_ordering()`
- `without_paging()`

Example:

```php
$spec=(new QuerySpec())
	->where_eq('tenant_id', $tenant_id)
	->in_last_days('created_at', 30)
	->when_filled($status, static function(QuerySpec $query, string $status): void{
		$query->where_eq('status', $status);
	})
	->when_not_null($machine_id, static function(QuerySpec $query, string $machine_id): void{
		$query->where_eq('machine_id', $machine_id);
	})
	->where_any(static function(QuerySpec $group): void{
		$group->where_eq('status', 'active')
			->where_eq('status', 'queued');
	})
	->latest('created_at')
	->for_page(2, 25);
```

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

## `TableRepository`

`TableRepository` is an opt-in repository base over the kernel SQL helpers.

Protected low-level helpers:

- `select_many(...)`
- `select_one(...)`
- `count_where(...)`
- `insert_one(...)`
- `update_where(...)`
- `delete_where(...)`

Public convenience helpers:

- `query()`
- `projectionNamed(...)`
- `primaryKey()`
- `all(...)`
- `queueAll(...)`
- `first(...)`
- `queueFirst(...)`
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
- `queueCount(...)`
- `aggregate(...)`
- `queueAggregate(...)`
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
- `find(...)`
- `findOneBy(...)`
- `findManyBy(...)`
- `findManyByIds(...)`
- `findKeyedByIds(...)`
- `hydrateRow(...)`
- `hydrateRows(...)`
- `allHydrated(...)`
- `firstHydrated(...)`
- `allRecords(...)`
- `firstRecord(...)`
- `firstRecordOrFail(...)`
- `findOneHydratedBy(...)`
- `findManyHydratedBy(...)`
- `findManyHydratedByIds(...)`
- `findKeyedHydratedByIds(...)`
- `findHydrated(...)`
- `findOrFail(...)`
- `findRecord(...)`
- `findRecordOrFail(...)`
- `paginateHydrated(...)`
- `paginateRecords(...)`
- `create(...)`
- `queueCreate(...)`
- `createMany(...)`
- `update(...)`
- `queueUpdate(...)`
- `updateBy(...)`
- `updateById(...)`
- `delete(...)`
- `queueDelete(...)`
- `deleteBy(...)`
- `deleteById(...)`
- `upsert(...)`
- `queueUpsert(...)`
- `upsertMany(...)`

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
	->where_eq('tenant_id', $tenant_id)
	->order_by('created_at', 'DESC');

$rows=MachineRepository::all(MachineTableSchema::schema()->projection('summary'), $spec);
$first=MachineRepository::first('*', $spec);
$exists=MachineRepository::exists($spec);
$page=MachineRepository::paginate('*', $spec, 1, 50);
$machine=MachineRepository::find('MACHINE_123');
$created=MachineRepository::create([
	'machine_id'=>'MACHINE_123',
	'tenant_id'=>'TENANT_1',
	'name'=>'Mixer A',
	'status'=>'active',
]);
$updated=MachineRepository::updateById('MACHINE_123', [
	'status'=>'maintenance',
]);
$deleted=MachineRepository::deleteById('MACHINE_123');

$queued=MachineRepository::query()
	->projection('summary')
	->where_eq('tenant_id', $tenant_id)
	->where_eq('status', 'queued')
	->get();

$queued_records=MachineRepository::query()
	->projection('summary')
	->where_eq('tenant_id', $tenant_id)
	->where_eq('status', 'queued')
	->getRecords();

$active=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->count();

$average_fill=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->avg('fill_ml');

$jobs_by_status=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->countBy('status');

$fill_by_status=MachineRepository::query()
	->where_eq('tenant_id', $tenant_id)
	->sumBy('status', 'fill_ml');
```

## `Record`

If you use the built-in record path, the framework returns `Dataphyre\Database\Record` objects without any custom setup.

`Record` exposes:

- `repositoryClass()`
- `schema()`
- `primaryKeyName()`
- `id()`
- `has(...)`
- `get(...)`
- `money(...)`
- `storedMoney(...)`
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
```

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
	MachineRepository::query()->where_eq('tenant_id', $tenant_id),
	1,
	25,
	MachineSummaryHydrator::class
);

$record=MachineRepository::query()
	->where_eq('machine_id', 'MACHINE_123')
	->asRecords()
	->firstRecord();
```

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
	->where_eq('tenant_id', $tenant_id)
	->latest('created_at')
	->paginate(2, 25);

$items=$page->items();
$names=$page->pluck('name');
$by_id=$page->keyBy('machine_id');
$summaries=$page->map(static fn(array $row): string => $row['name'].' ('.$row['status'].')');
```

## `MutationResult` and `MutationBatchResult`

Write-side repository helpers return typed results instead of raw kernel return values.

`MutationResult` exposes:

- `operation()`
- `ok()`
- `failed()`
- `rawResult()`
- `affectedRows()`
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

## `DB`, `ConnectionContext`, `Transaction`, and `TransactionResult`

The framework also provides a low-magic transaction layer over the kernel transaction primitives.

`DB::transaction(...)` throws on transaction begin/commit/rollback failures and rethrows callback exceptions. `DB::attemptTransaction(...)` gives the same orchestration path without forcing exception-based control flow.

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
- `attemptTransaction(...)`
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
- `isActive()`
- `begun()`
- `committed()`
- `rolledBack()`
- `begin()`
- `commit()`
- `rollback()`
- `run(...)`
- `attempt(...)`

`TransactionResult` exposes:

- `cluster()`
- `ok()`
- `failed()`
- `begun()`
- `committed()`
- `rolledBack()`
- `value()`
- `exception()`
- `errorMessage()`
- `errorClass()`

Examples:

```php
$users=DB::table('users', 'user_id')
	->where_eq('tenant_id', $tenant_id)
	->order_by('created_at', 'DESC')
	->paginate(1, 25);

$user_record=DB::table('users', 'user_id')
	->where_key($user_id)
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

$attempt=DB::attemptTransaction(static function(){
	return MachineRepository::deleteById('MACHINE_404');
});

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
- `QuerySpec` validates identifiers and allows explicit `where_raw(...)` escapes when needed.

## Laravel Mapping

| Laravel | Dataphyre SQL Framework |
| --- | --- |
| `User::find($id)` | `UserRepository::find($id)` |
| `DB::table('users')->where('email', $email)->first()` | `DB::table('users', 'user_id')->where_eq('email', $email)->first()` |
| `User::where('email', $email)->first()` | `UserRepository::query()->where_eq('email', $email)->first()` |
| `User::where('tenant_id', $tenant_id)->get()` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->get()` |
| `User::where('tenant_id', $tenant_id)->paginate(25)` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->paginate(1, 25)` |
| `User::where('tenant_id', $tenant_id)->sum('balance')` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->sum('balance')` |
| `User::where('tenant_id', $tenant_id)->count('email')` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->countColumn('email')` |
| `User::where('tenant_id', $tenant_id)->distinct()->count('status')` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->countDistinct('status')` |
| `User::where('created_at', '>=', $cutoff)->latest()->get()` | `UserRepository::query()->in_last_days('created_at', 30)->latest()->get()` |
| `User::when($status, fn($query)=>$query->where('status', $status))->get()` | `UserRepository::query()->when_filled($status, fn(QuerySpec $query, string $status)=>$query->where_eq('status', $status))->get()` |
| `User::where('tenant_id', $tenant_id)->groupBy('status')->selectRaw('status, count(*) as aggregate_value')->pluck('aggregate_value', 'status')` | `UserRepository::query()->where_eq('tenant_id', $tenant_id)->countBy('status')` |
| `User::where('status', 'queued')->update([...])` | `UserRepository::query()->where_eq('status', 'queued')->update([...])` |
| `User::where('status', 'queued')->delete()` | `UserRepository::query()->where_eq('status', 'queued')->delete()` |
| `DB::transaction(fn()=>...)` | `DB::transaction(fn()=>...)` |

This gives Dataphyre SQL a stronger application layer without turning the kernel into an ORM. The framework remains a low-magic repository/query system over the existing high-performance execution engine.
