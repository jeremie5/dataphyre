<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\BulkMutationOptions;
use Dataphyre\Database\BulkMutationPlanner;
use Dataphyre\Database\TableRepository;
use Dataphyre\Database\TableSchema;
use Dataphyre\Database\Transaction;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION', true);
}
if(!defined('DP_CORE_CFG')){
	define('DP_CORE_CFG', ['datacenter'=>'test']);
}
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG', [
		'default_cluster'=>'primary',
		'default_database_location'=>'',
		'bulk_mutations'=>[
			'max_rows_per_statement'=>128,
			'parameter_limits'=>['postgresql'=>32000, 'sqlite'=>900],
		],
		'tables'=>[
			'bulk_records'=>['cluster'=>'primary'],
			'bulk_multipoint_records'=>['cluster'=>'primary', 'multipoint_writes'=>true],
		],
		'datacenters'=>[
			'test'=>[
				'dbms_clusters'=>[
					'primary'=>['dbms'=>'postgresql'],
				],
			],
		],
	]);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'currency'=>true, 'sql'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

suite('SQL bounded multi-row mutation contracts')
	->contract('sql.bulk-mutations', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:sql')
	->through('repository', 'insert', 'upsert', 'chunking', 'identity', 'cache-invalidation')
	->isolation('case')
	->tag('sql', 'bulk-mutation')
	->group('framework-coverage');

function dp_bulk_mutation_state(): TestState {
	return TestState::channel('sql.bulk-mutations');
}

/** @param list<mixed> $queryResults */
function dp_bulk_mutation_reset(Context $t, array $queryResults=[]): void {
	$t->state('sql.bulk-mutations', [
		'calls'=>[],
		'query_results'=>$queryResults,
		'reverse_returning'=>false,
		'generate_returning_ids'=>false,
	]);
}

/** @return list<array<string,mixed>> */
function dp_bulk_mutation_rows_from_query(string $query, array $vars): array {
	if(preg_match('/\(([^)]+)\)\s+VALUES/i', $query, $matches)!==1){
		return [];
	}
	$columns=array_map(
		static fn(string $column): string=>trim($column, " \t\n\r\0\x0B\"`"),
		explode(',', $matches[1])
	);
	$columnCount=count($columns);
	$rows=[];
	foreach(array_chunk($vars, $columnCount) as $values){
		$rows[]=array_combine($columns, $values);
	}
	return $rows;
}

if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		$state=dp_bulk_mutation_state();
		$state->append('calls', ['query', $arguments]);
		$queued=$state->get('query_results', []);
		if($queued!==[]){
			$result=array_shift($queued);
			$state->put('query_results', $queued);
			return $result;
		}
		$queryMap=$arguments[0] ?? [];
		$query=is_array($queryMap) ? (string)($queryMap['postgresql'] ?? $queryMap['sqlite'] ?? '') : (string)$queryMap;
		if(str_contains(strtoupper($query), 'RETURNING')){
			$rows=dp_bulk_mutation_rows_from_query($query, (array)($arguments[1] ?? []));
			if($state->get('generate_returning_ids', false)===true){
				foreach($rows as $index=>&$row){
					$row['id']=1000+$index;
				}
				unset($row);
			}
			return $state->get('reverse_returning', false)===true ? array_reverse($rows) : $rows;
		}
		return true;
	}
}
if(!function_exists('sql_insert')){
	function sql_insert(mixed ...$arguments): mixed {
		dp_bulk_mutation_state()->append('calls', ['insert', $arguments]);
		$fields=is_array($arguments[1] ?? null) ? $arguments[1] : [];
		return $fields['id'] ?? true;
	}
}
if(!function_exists('sql_upsert')){
	function sql_upsert(mixed ...$arguments): mixed {
		dp_bulk_mutation_state()->append('calls', ['upsert', $arguments]);
		return true;
	}
}
if(!function_exists('sql_begin')){
	function sql_begin(?string $cluster=null): bool {
		dp_bulk_mutation_state()->append('calls', ['begin', [$cluster]]);
		return true;
	}
}
if(!function_exists('sql_commit')){
	function sql_commit(?string $cluster=null): bool {
		dp_bulk_mutation_state()->append('calls', ['commit', [$cluster]]);
		return true;
	}
}
if(!function_exists('sql_rollback')){
	function sql_rollback(?string $cluster=null): bool {
		dp_bulk_mutation_state()->append('calls', ['rollback', [$cluster]]);
		return true;
	}
}

if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql {
		public static function resolve_cluster(?string $cluster=null): string { return $cluster ?? "primary"; }
		public static function table(string $table, ?string &$dbms=null): string { $dbms=null; return $table; }
		public static function clear_last_query_error(): void {}
		public static function hydrate_missing_structure_from_definition(?string $table=null): bool { return false; }
		public static function hydrate_table_definition(string $table): bool { return false; }
		public static function invalidate_cache(string|array $table): bool { return true; }
	}');
}

$dp_bulk_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_bulk_modules_root.'/core/kernel/autoloader.php';
require_once $dp_bulk_modules_root.'/core/kernel/helper_functions.php';
require_once $dp_bulk_modules_root.'/core/kernel/core_functions.php';
\dataphyre\autoloader::register($dp_bulk_modules_root);
\dataphyre\autoloader::register_framework_modules(['currency', 'sql']);

final class DpBulkMutationRepository extends TableRepository {
	protected static function table(): string { return 'bulk_records'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema(
			'bulk_records',
			['id', 'external_key', 'tenant_id', 'sku', 'name', 'active', 'metadata'],
			[],
			'id',
			['id'=>'integer', 'tenant_id'=>'integer', 'active'=>'boolean', 'metadata'=>'json']
		);
	}
	protected static function defaultWriteInvalidation(): bool|array|null { return true; }
}

final class DpBulkMultipointMutationRepository extends TableRepository {
	protected static function table(): string { return 'bulk_multipoint_records'; }
	protected static function schema(): ?TableSchema {
		return new TableSchema('bulk_multipoint_records', ['id', 'name'], [], 'id', ['id'=>'integer']);
	}
}

test('bulk mutation options validate and preserve explicit metadata', static function(Context $t): void {
	$options=BulkMutationOptions::upserts(['tenant_id', 'sku', 'sku'], ['name'], 50, 500);
	$t->same(['tenant_id', 'sku'], $options->conflictColumns());
	$t->same(['name'], $options->updateColumns());
	$t->same(50, $options->maxRowsPerStatement());
	$t->same(500, $options->maxParameters());
	$t->same(null, $options->correlationColumn());
	$inserts=BulkMutationOptions::inserts(128, 32000, 'external_key');
	$t->same('external_key', $inserts->correlationColumn());
	$t->throws(static fn()=>new BulkMutationOptions(maxRowsPerStatement: 0), InvalidArgumentException::class);
	$t->throws(static fn()=>new BulkMutationOptions(maxParameters: -1), InvalidArgumentException::class);
	$t->throws(static fn()=>BulkMutationOptions::upserts([]), InvalidArgumentException::class);
	$t->throws(static fn()=>BulkMutationOptions::upserts(['unsafe-name']), InvalidArgumentException::class);
	$t->throws(static fn()=>BulkMutationOptions::inserts(correlationColumn: 'unsafe-name'), InvalidArgumentException::class);
});

test('planner produces bounded statements without reordering unlike-shaped rows', static function(Context $t): void {
	$rows=[
		0=>['id'=>1, 'name'=>'A'],
		1=>['name'=>'B', 'id'=>2],
		2=>['id'=>3, 'name'=>'C'],
		3=>['id'=>4, 'name'=>'D', 'active'=>true],
		4=>['id'=>5, 'name'=>'E'],
	];
	$plan=BulkMutationPlanner::inserts('postgresql', 'public.records', $rows, 'id', 10, 4);
	$t->same(4, count($plan));
	$t->same([0, 1], $plan[0]['indexes']);
	$t->same([2], $plan[1]['indexes']);
	$t->same([3], $plan[2]['indexes']);
	$t->same([4], $plan[3]['indexes']);
	$t->contains('INSERT INTO "public"."records" ("id", "name") VALUES (?, ?), (?, ?)', $plan[0]['sql']);
	$t->contains('RETURNING "id"', $plan[0]['sql']);
	$t->isFalse(str_contains($plan[0]['sql'], 'RETURNING *'));
	$t->same([1, 'A', 2, 'B'], $plan[0]['vars']);
	$t->same(null, BulkMutationPlanner::inserts('mysql', 'records', $rows, 'id', 10, 10));
	$t->same(null, BulkMutationPlanner::inserts('postgresql', 'records', [['name'=>'A']], 'id', 10, 10));
	$generatedIdentity=BulkMutationPlanner::inserts(
		'postgresql',
		'records',
		[
			['external_key'=>'row-a', 'name'=>'A'],
			['external_key'=>'row-b', 'name'=>'B'],
		],
		'id',
		10,
		10,
		'external_key'
	);
	$t->same(1, count($generatedIdentity));
	$t->same('external_key', $generatedIdentity[0]['correlation_column']);
	$t->contains('RETURNING "id", "external_key"', $generatedIdentity[0]['sql']);
	$t->isFalse(str_contains($generatedIdentity[0]['sql'], 'RETURNING *'));
	$t->same(null, BulkMutationPlanner::inserts(
		'postgresql',
		'records',
		[
			['external_key'=>'duplicate', 'name'=>'A'],
			['external_key'=>'duplicate', 'name'=>'B'],
		],
		'id',
		10,
		10,
		'external_key'
	));

	$sqlite=BulkMutationPlanner::inserts('sqlite', 'records', array_slice($rows, 0, 3, true), null, 2, 900);
	$t->same(2, count($sqlite));
	$t->contains('INSERT OR IGNORE', $sqlite[0]['sql']);
});

test('planner compiles explicit portable upsert conflict targets', static function(Context $t): void {
	$rows=[
		['tenant_id'=>1, 'sku'=>'A', 'name'=>'Alpha'],
		['tenant_id'=>1, 'sku'=>'B', 'name'=>'Beta'],
	];
	$plan=BulkMutationPlanner::upserts('postgresql', 'inventory', $rows, ['tenant_id', 'sku'], ['name'], 250, 32000);
	$t->same(1, count($plan));
	$t->contains('ON CONFLICT ("tenant_id", "sku") DO UPDATE SET "name"=excluded."name"', $plan[0]['sql']);
	$t->same(null, BulkMutationPlanner::upserts('mysql', 'inventory', $rows, ['tenant_id'], ['name'], 250, 32000));
	$t->same(null, BulkMutationPlanner::upserts('postgresql', 'inventory', $rows, ['missing'], ['name'], 250, 32000));
});

test('planned SQLite insert and composite upsert statements execute natively', static function(Context $t): void {
	if(!class_exists('SQLite3')){
		throw new RuntimeException('The SQL verification runtime requires the SQLite3 extension.');
	}
	$database=new SQLite3(':memory:');
	try{
		$t->isTrue($database->exec(
			'CREATE TABLE inventory (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sku TEXT NOT NULL, name TEXT NOT NULL, UNIQUE (tenant_id, sku))'
		));
		$insertPlan=BulkMutationPlanner::inserts('sqlite', 'inventory', [
			['id'=>1, 'tenant_id'=>7, 'sku'=>'A', 'name'=>'Alpha'],
			['id'=>2, 'tenant_id'=>7, 'sku'=>'B', 'name'=>'Beta'],
			['id'=>3, 'tenant_id'=>7, 'sku'=>'C', 'name'=>'Gamma'],
		], null, 2, 900);
		$t->same(2, count($insertPlan));
		foreach($insertPlan as $statement){
			$prepared=$database->prepare($statement['sql']);
			$t->isTrue($prepared!==false);
			foreach($statement['vars'] as $index=>$value){
				$prepared->bindValue($index+1, $value);
			}
			$result=$prepared->execute();
			$t->isTrue($result!==false);
			$result->finalize();
			$prepared->close();
		}
		$t->same(3, (int)$database->querySingle('SELECT COUNT(*) FROM inventory'));

		$upsertPlan=BulkMutationPlanner::upserts('sqlite', 'inventory', [
			['id'=>4, 'tenant_id'=>7, 'sku'=>'B', 'name'=>'Beta 2'],
			['id'=>5, 'tenant_id'=>7, 'sku'=>'D', 'name'=>'Delta'],
		], ['tenant_id', 'sku'], ['name'], 250, 900);
		$t->same(1, count($upsertPlan));
		$prepared=$database->prepare($upsertPlan[0]['sql']);
		$t->isTrue($prepared!==false);
		foreach($upsertPlan[0]['vars'] as $index=>$value){
			$prepared->bindValue($index+1, $value);
		}
		$result=$prepared->execute();
		$t->isTrue($result!==false);
		$result->finalize();
		$prepared->close();
		$t->same('Beta 2', $database->querySingle("SELECT name FROM inventory WHERE tenant_id=7 AND sku='B'"));
		$t->same(4, (int)$database->querySingle('SELECT COUNT(*) FROM inventory'));
	} finally {
		$database->close();
	}
});

test('repository createMany bounds query count and correlates RETURNING rows by primary key', static function(Context $t): void {
	dp_bulk_mutation_reset($t);
	dp_bulk_mutation_state()->put('reverse_returning', true);
	$batch=DpBulkMutationRepository::createMany([
		['id'=>10, 'name'=>'A'],
		'skip',
		['name'=>'B', 'id'=>20],
		['id'=>30, 'name'=>'C'],
	], null, BulkMutationOptions::inserts(2, 10));
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$t->same(2, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
	$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='insert')));
	$t->same(4, $batch->requested());
	$t->same(3, $batch->processed());
	$t->same([10, 20, 30], array_map(static fn($result)=>$result->insertedId(), $batch->results()));
	$t->isTrue($batch->failed());
	foreach(array_filter($calls, static fn(array $call): bool=>$call[0]==='query') as $call){
		$t->same(['bulk_records'], $call[1][5]);
	}
});

test('repository createMany preserves insertion order for sparse numeric input keys', static function(Context $t): void {
	dp_bulk_mutation_reset($t);
	$batch=DpBulkMutationRepository::createMany([
		9=>['id'=>90, 'name'=>'First'],
		2=>['id'=>20, 'name'=>'Second'],
	], false, BulkMutationOptions::inserts());
	$t->same([90, 20], array_map(static fn($result)=>$result->insertedId(), $batch->results()));
	$t->isTrue($batch->ok());
});

test('repository createMany correlates generated identities through an explicit stable column', static function(Context $t): void {
	dp_bulk_mutation_reset($t);
	dp_bulk_mutation_state()->put('generate_returning_ids', true);
	dp_bulk_mutation_state()->put('reverse_returning', true);
	$batch=DpBulkMutationRepository::createMany([
		['external_key'=>'row-a', 'name'=>'A'],
		['external_key'=>'row-b', 'name'=>'B'],
	], false, BulkMutationOptions::inserts(correlationColumn: 'external_key'));
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$t->same(1, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
	$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='insert')));
	$t->same([1000, 1001], array_map(static fn($result)=>$result->insertedId(), $batch->results()));
	$t->same(['row-a', 'row-b'], array_map(static fn($result)=>$result->rawResult()['external_key'], $batch->results()));
	$t->isTrue($batch->ok());
});

test('PostgreSQL insert conflicts remain failed children after RETURNING correlation', static function(Context $t): void {
	dp_bulk_mutation_reset($t, [[['id'=>2, 'name'=>'B']]]);
	$batch=DpBulkMutationRepository::createMany([
		['id'=>1, 'name'=>'A'],
		['id'=>2, 'name'=>'B'],
	], false, BulkMutationOptions::inserts());
	$t->isTrue($batch->results()[0]->failed());
	$t->same(2, $batch->results()[1]->insertedId());
	$t->same(1, $batch->successful());
	$t->same(1, $batch->failedCount());
});

test('failed bulk insert statement replays rows through the compatible legacy path', static function(Context $t): void {
	dp_bulk_mutation_reset($t, [false]);
	$batch=DpBulkMutationRepository::createMany([
		['id'=>1, 'name'=>'A'],
		['id'=>2, 'name'=>'B'],
	], false, BulkMutationOptions::inserts(10, 20));
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$t->same(1, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
	$t->same(2, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='insert')));
	$t->same([1, 2], array_map(static fn($result)=>$result->insertedId(), $batch->results()));
	$t->isTrue($batch->ok());
});

test('multipoint write tables retain the endpoint-aware per-row kernel path', static function(Context $t): void {
	dp_bulk_mutation_reset($t);
	$batch=DpBulkMultipointMutationRepository::createMany([
		['id'=>1, 'name'=>'A'],
		['id'=>2, 'name'=>'B'],
	], false, BulkMutationOptions::inserts());
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
	$t->same(2, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='insert')));
	$t->isTrue($batch->ok());
});

test('failed statements inside a Framework transaction do not issue doomed row retries', static function(Context $t): void {
	dp_bulk_mutation_reset($t, [false]);
	$transaction=(new Transaction('primary'))->begin();
	try{
		$batch=DpBulkMutationRepository::createMany([
			['id'=>1, 'name'=>'A'],
			['id'=>2, 'name'=>'B'],
		], false, BulkMutationOptions::inserts(10, 20));
		$calls=dp_bulk_mutation_state()->get('calls', []);
		$t->same(1, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
		$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='insert')));
		$t->same(2, $batch->failedCount());
		$t->contains('roll back the transaction', (string)$batch->firstErrorMessage());
	} finally {
		$transaction->rollback();
	}
});

test('repository upsertMany uses an explicit composite target and keeps failure fallback targeted', static function(Context $t): void {
	dp_bulk_mutation_reset($t, [false, true, false]);
	$options=BulkMutationOptions::upserts(['tenant_id', 'sku'], ['name'], 10, 100);
	$batch=DpBulkMutationRepository::upsertMany([
		['id'=>1, 'tenant_id'=>7, 'sku'=>'A', 'name'=>'Alpha'],
		['id'=>2, 'tenant_id'=>7, 'sku'=>'B', 'name'=>'Beta'],
	], null, null, true, $options);
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$queryCalls=array_values(array_filter($calls, static fn(array $call): bool=>$call[0]==='query'));
	$t->same(3, count($queryCalls));
	$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='upsert')));
	$t->contains('ON CONFLICT ("tenant_id", "sku")', $queryCalls[0][1][0]['postgresql']);
	$t->same(2, $batch->processed());
	$t->same(1, $batch->successful());
	$t->isTrue($batch->failed());
});

test('custom legacy upsert expressions keep the per-row execution contract', static function(Context $t): void {
	dp_bulk_mutation_reset($t);
	$batch=DpBulkMutationRepository::upsertMany([
		['id'=>1, 'name'=>'A'],
		['id'=>2, 'name'=>'B'],
	], 'WHERE id=?', [1], false);
	$calls=dp_bulk_mutation_state()->get('calls', []);
	$t->same(0, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='query')));
	$t->same(2, count(array_filter($calls, static fn(array $call): bool=>$call[0]==='upsert')));
	$t->isTrue($batch->ok());
	$t->throws(
		static fn()=>DpBulkMutationRepository::upsertMany(
			[['id'=>1, 'tenant_id'=>1, 'sku'=>'A', 'name'=>'A']],
			'WHERE id=?',
			[1],
			false,
			BulkMutationOptions::upserts(['tenant_id', 'sku'])
		),
		LogicException::class
	);
});

test('query-count proof keeps one thousand compatible rows to eight default-sized statements', static function(Context $t): void {
	$rows=[];
	for($index=1; $index<=1000; $index++){
		$rows[]=['id'=>$index, 'name'=>'record-'.$index];
	}
	$started=hrtime(true);
	$plan=BulkMutationPlanner::inserts('postgresql', 'records', $rows, 'id', BulkMutationPlanner::DEFAULT_MAX_ROWS, 32000);
	$elapsed=(hrtime(true)-$started)/1_000_000;
	$t->same(8, count($plan));
	$t->same(2000, array_sum(array_map(static fn(array $statement): int=>count($statement['vars']), $plan)));
	$t->isTrue($elapsed<250.0, 'Pure bulk planning should remain below 250ms for 1,000 two-column rows.');
})->maxMillis(1000);
