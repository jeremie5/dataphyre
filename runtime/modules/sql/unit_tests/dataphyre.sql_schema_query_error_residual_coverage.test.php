<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

function dp_sql_residual_state(): TestState {
	return TestState::channel('sql.schema-query-errors');
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG', [
		'default_cluster'=>'primary',
		'tables'=>[
			'orders'=>['cluster'=>'orders-cluster'],
			'tenant.orders'=>['cluster'=>'tenant-cluster'],
		],
	]);
}
if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { final class sql {
		public static function hydrate_table_definition(string $table): bool { \\dp_sql_residual_state()->append("hydrated",$table); return (bool)\\dp_sql_residual_state()->get("hydrate_ok",true); }
		public static function clear_last_query_error(): void { \\dp_sql_residual_state()->put("last_error",null)->increment("clear_count"); }
		public static function last_query_error(): ?array { $error=\\dp_sql_residual_state()->get("last_error"); return is_array($error) ? $error : null; }
		public static function query(mixed ...$arguments): mixed { $state=\\dp_sql_residual_state(); $state->append("queries",$arguments); if($state->get("query_errors",[])!==[]){ $state->put("last_error",$state->shift("query_errors")); } return $state->get("query_results",[])!==[] ? $state->shift("query_results") : true; }
	} }');
}

$dp_sql_schema_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/sql/';
require_once $dp_sql_schema_root.'Framework/DataEnvironment.php';
require_once $dp_sql_schema_root.'unit_tests/sql_framework_test_helpers.php';
require_once $dp_sql_schema_root.'Framework/TransactionException.php';
require_once $dp_sql_schema_root.'Framework/MultipleRecordsFoundException.php';
require_once $dp_sql_schema_root.'Framework/OptimisticLockException.php';
require_once $dp_sql_schema_root.'Framework/RecordNotFoundException.php';

function dp_sql_residual_scenario(Context $t): TestState {
	return $t->state('sql.schema-query-errors',[
		'hydrated'=>[],
		'hydrate_ok'=>true,
		'last_error'=>null,
		'clear_count'=>0,
		'queries'=>[],
		'query_errors'=>[],
		'query_results'=>[],
	]);
}

test('sql table schema covers validation casting caches temporal JSON and hydration paths', static function(Context $t): void {
	$state=dp_sql_residual_scenario($t);
	$definition=\Dataphyre\Database\TableDefinition::for('orders')->autoIncrement()->string('name');
	$fromDefinition=\Dataphyre\Database\TableSchema::fromDefinition($definition);
	$t->same(['id', 'name'], $fromDefinition->columnNames());

	$schema=new \Dataphyre\Database\TableSchema(
		'orders',
		['name', 'count', 'price', 'active', 'payload', 'created_at', 'misc'],
		['listing'=>['name', 'count'], 'ignored'=>'not-an-array'],
		'name',
		['name'=>'string', 'count'=>'integer', 'price'=>'double', 'active'=>'boolean', 'payload'=>'json', 'created_at'=>'timestamp']
	);
	$t->same('orders', $schema->table());
	$t->same('name', $schema->primaryKey());
	$t->same(['name', 'count'], $schema->projection('listing'));
	$t->same(['listing'=>['name', 'count']], $schema->projections());
	$t->same('int', $schema->casts()['count']);
	$t->same(['name', 'count'], $schema->columns(['name', 'count', 'name']));
	$t->same(['name', 'count'], $schema->columns(['name', 'count', 'name']));

	foreach([[], ['name']] as $invalidFields){
		try{
			$schema->fields($invalidFields);
		}catch(InvalidArgumentException $exception){
			$t->contains('field', strtolower($exception->getMessage()));
		}
	}
	$written=$schema->fields([
		'name'=>123,
		'count'=>'7',
		'price'=>'2.5',
		'active'=>'off',
		'payload'=>['ok'=>true],
		'created_at'=>new DateTimeImmutable('2026-01-02 03:04:05'),
		'misc'=>'raw',
	]);
	$t->same('123', $written['name']);
	$t->same(7, $written['count']);
	$t->same(2.5, $written['price']);
	$t->isFalse($written['active']);
	$t->same('{"ok":true}', $written['payload']);
	$t->same('2026-01-02 03:04:05', $written['created_at']);

	$t->isTrue($schema->fields(['active'=>true])['active']);
	$t->isTrue($schema->fields(['active'=>1])['active']);
	$t->isTrue($schema->fields(['active'=>'yes'])['active']);
	$t->isTrue($schema->fields(['active'=>new stdClass()])['active']);
	$t->same('already-json', $schema->fields(['payload'=>'already-json'])['payload']);
	$t->same(
		'{"whole":30.0,"fractional":30.25}',
		$schema->fields(['payload'=>['whole'=>30.0, 'fractional'=>30.25]])['payload']
	);
	$t->same(date('Y-m-d H:i:s', 1000), $schema->fields(['created_at'=>1000])['created_at']);
	$t->same('as-is', $schema->fields(['created_at'=>'as-is'])['created_at']);
	try{
		$resource=fopen('php://temp', 'r');
		$schema->fields(['payload'=>$resource]);
	}catch(InvalidArgumentException $exception){
		$t->contains('JSON cast failed', $exception->getMessage());
	}finally{
		if(isset($resource) && is_resource($resource)){ fclose($resource); }
	}

	$casted=$schema->castRow([
		'name'=>9,
		'count'=>'8',
		'price'=>'3.75',
		'active'=>'on',
		'payload'=>'{"a":1}',
		'created_at'=>1000,
	]);
	$t->same('9', $casted['name']);
	$t->same(8, $casted['count']);
	$t->same(3.75, $casted['price']);
	$t->isTrue($casted['active']);
	$t->same(['a'=>1], $casted['payload']);
	$t->same(1000, $casted['created_at']->getTimestamp());

	$rows=[[
		'name'=>null, 'count'=>'1', 'price'=>'1.5', 'active'=>'0', 'payload'=>null, 'created_at'=>null,
	], [
		'name'=>'row', 'payload'=>'{"cached":true}', 'created_at'=>'2026-02-03 04:05:06',
	]];
	$firstRows=$schema->castRows($rows);
	$t->same($firstRows, $schema->castRows($rows));
	$t->same(null, $firstRows[0]['payload']);
	$t->same(['cached'=>true], $schema->castRow(['payload'=>'{"cached":true}'])['payload']);
	$t->same(null, $schema->castRow(['payload'=>'   '])['payload']);
	$t->same('not-json', $schema->castRow(['payload'=>'not-json'])['payload']);
	$t->same(['raw'=>true], $schema->castRow(['payload'=>['raw'=>true]])['payload']);
	$t->same($firstRows[1]['created_at'], $schema->castRow(['created_at'=>'2026-02-03 04:05:06'])['created_at']);
	$date=new DateTimeImmutable('2026-04-05 06:07:08');
	$t->same($date, $schema->castRow(['created_at'=>$date])['created_at']);
	$t->same(null, $schema->castRow(['created_at'=>''])['created_at']);
	$invalidDate=new stdClass();
	$t->same($invalidDate, $schema->castRow(['created_at'=>$invalidDate])['created_at']);

	$noCasts=new \Dataphyre\Database\TableSchema('plain', ['id']);
	$t->same([['id'=>1]], $noCasts->castRows([['id'=>1]]));
	$uncacheable=[['name'=>'x', 'extra'=>new stdClass()]];
	$t->same('x', $schema->castRows($uncacheable)[0]['name']);
	$deepUncacheable=[['nested'=>['object'=>new stdClass()]]];
	$t->same($deepUncacheable, $schema->castRows($deepUncacheable));

	$schemaInternals=$t->nonPublic($schema);
	$schemaInternals->writeProperty('readJsonCache',array_fill_keys(array_map(static fn(int $i): string=>'json-'.$i, range(1, 256)),true));
	$t->same(['flushed'=>true], $schema->castRow(['payload'=>'{"flushed":true}'])['payload']);
	$schemaInternals->writeProperty('readDateTimeCache',array_fill_keys(array_map(static fn(int $i): string=>'date-'.$i, range(1, 128)),$date));
	$t->same('2026', $schema->castRow(['created_at'=>'2026-01-01'])['created_at']->format('Y'));
	$t->same($date,$schemaInternals->invoke('rememberReadDateTime',null,$date));

	$state->put('hydrate_ok',true);
	$t->isTrue($schema->hydrateTable());
	$t->same('orders',$state->get('hydrated')[0]);

	foreach([
		static fn()=>new \Dataphyre\Database\TableSchema('orders', ['bad column']),
		static fn()=>new \Dataphyre\Database\TableSchema('orders', ['id'], [], null, ['id'=>'unsupported']),
	] as $invalid){
		try{
			$invalid();
		}catch(InvalidArgumentException $exception){
			$t->isTrue($exception->getMessage()!=='');
		}
	}
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');

test('sql query spec covers empty predicates condition defaults locks grouping and temporal failures', static function(Context $t): void {
	dp_sql_residual_scenario($t);
	$query=new \Dataphyre\Database\QuerySpec();
	$t->same($query, $query->whereIn('id', []));
	$t->same($query, $query->whereNotIn('id', []));
	$t->same($query, $query->when(false, static fn()=>null));
	$t->same($query, $query->whenNotNull(null, static fn()=>null));
	$t->same($query, $query->whenNotNull(null, static fn()=>null, static fn(\Dataphyre\Database\QuerySpec $spec)=>$spec));
	$t->same($query, $query->whenFilled('', static fn()=>null));
	$t->same($query, $query->whenFilled([], static fn()=>null, static fn(\Dataphyre\Database\QuerySpec $spec)=>$spec));
	$query->groupBy(['status', 'status']);
	$query->whereAll(static function(): void {});
	$query->whereAny(static function(): void {});

	$emptyLock=(new \Dataphyre\Database\QuerySpec())->lockRaw('FOR UPDATE')->compile();
	$t->contains('FOR UPDATE', $emptyLock['params']);
	$nonEmptyLock=(new \Dataphyre\Database\QuerySpec())->whereEq('id', 1)->lockRaw('FOR SHARE')->compile();
	$t->contains('FOR SHARE', $nonEmptyLock['params']);
	$driverLocks=(new \Dataphyre\Database\QuerySpec())->lockRaw(['mysql'=>'FOR UPDATE', 'postgresql'=>'', 'sqlite'=>''])->compile();
	$t->contains('FOR UPDATE', $driverLocks['params']['mysql']);
	$t->same('', trim($driverLocks['params']['postgresql']));
	$noLock=(new \Dataphyre\Database\QuerySpec())->lockRaw(['mysql'=>' ', 'postgresql'=>'', 'sqlite'=>''])->compile();
	$t->same('', $noLock['params']);

	$temporal=(new \Dataphyre\Database\QuerySpec())->whereSince('created_at', new DateTimeImmutable('2026-01-01 00:00:00'))->compile(false);
	$t->same('2026-01-01 00:00:00', $temporal['vars'][0]);
	foreach([
		static fn()=>(new \Dataphyre\Database\QuerySpec())->whereSince('created_at', []),
		static fn()=>(new \Dataphyre\Database\QuerySpec())->inLastMinutes('created_at', 0),
	] as $invalid){
		try{
			$invalid();
		}catch(InvalidArgumentException $exception){
			$t->contains('SQL temporal filter error', $exception->getMessage());
		}
	}
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');

test('sql error diagnostics and table definitions cover residual validation hydration and SQL generation branches', static function(Context $t): void {
	$state=dp_sql_residual_scenario($t);
	$t->contains('No SQL clusters', \Dataphyre\Database\SqlError::unknownCluster('missing', [])->getMessage());
	$t->contains('configured cluster', \Dataphyre\Database\SqlError::unknownCluster('missing', ['primary'], 'dc1')->getMessage());
	$t->contains('explicitly allows', \Dataphyre\Database\SqlError::invalidAggregateColumn('repo', 'count', 'bad', true)->getMessage());
	$t->contains('valid schema column', \Dataphyre\Database\SqlError::invalidAggregateColumn('repo', 'sum', '*', false)->getMessage());
	$t->contains('custom temporal hint', \Dataphyre\Database\SqlError::invalidTemporalValue('query', 1.5, 'custom temporal hint')->getMessage());
	$t->contains('greater than zero', \Dataphyre\Database\SqlError::invalidTemporalWindow('query', 'hours', 0)->getMessage());

	$previous=new RuntimeException('previous');
	$transaction=\Dataphyre\Database\SqlError::transactionException('failed', 'primary', 'retry later', $previous);
	$t->same($previous, $transaction->getPrevious());
	$t->isTrue(\Dataphyre\Database\SqlError::isTransientTransactionException(new RuntimeException('coded', 40001)));
	$t->isTrue(\Dataphyre\Database\SqlError::isTransientTransactionException(new RuntimeException('outer', 0, new RuntimeException('deadlock found'))));
	$t->isFalse(\Dataphyre\Database\SqlError::isTransientTransactionException(new RuntimeException('permanent')));
	$t->contains('custom stale', \Dataphyre\Database\SqlError::optimisticLockConflict('repo', [], ' custom stale ')->getMessage());
	$diagnostic=\Dataphyre\Database\SqlError::mutationErrorMessage('update', [
		''=>'skip',
		'scalar_list'=>[1, 2],
		'nested'=>[['a'=>1], new stdClass()],
		'throwable'=>new RuntimeException('nested-error'),
	]);
	$t->contains('nested-error', $diagnostic);

	$empty=\Dataphyre\Database\TableDefinition::for('empty_table');
	$t->same([], $empty->createQueries());
	$t->isFalse($empty->hydrate());
	foreach([
		static fn()=>$empty->nullable(),
		static fn()=>\Dataphyre\Database\TableDefinition::for('orders')->projection(' ', ['id']),
		static fn()=>\Dataphyre\Database\TableDefinition::for('orders')->string('id')->casts(['missing'=>'int']),
		static fn()=>\Dataphyre\Database\TableDefinition::for('orders')->string('id')->cast('unsupported'),
		static fn()=>\Dataphyre\Database\TableDefinition::for('orders')->legacyColumn('bad legacy!', 'TEXT'),
	] as $invalid){
		try{
			$invalid();
		}catch(LogicException|InvalidArgumentException $exception){
			$t->isTrue($exception->getMessage()!=='');
		}
	}

	$definition=\Dataphyre\Database\TableDefinition::for('tenant.orders')
		->enum('status', ['', 'open', 'closed'])
		->integer('tenant_id')->notNull()
		->string('email')->notNull()
		->primary(['tenant_id', 'email'])
		->unique(['email'])
		->unique(['tenant_id', 'email'], 'uq_tenant_email')
		->index(['email(12)']);
	$queries=$definition->createQueries();
	$t->contains('CREATE SCHEMA', $queries[0]['postgresql']);
	$t->contains('PRIMARY KEY', $queries[1]['mysql']);
	$t->contains('CONSTRAINT', $queries[1]['postgresql']);
	$t->contains('email', $queries[2]['mysql']);
	$t->same(null,$t->nonPublic($definition)->invoke('createSchemaSql','mysql'));

	$state->put('query_results',[false]);
	$t->isFalse($definition->hydrate());
	$state->put('query_results',array_fill(0,count($queries),true));
	$t->isTrue($definition->hydrate());
	$state->put('queries',[])->put('query_results',array_fill(0,count($queries),true));
	\Dataphyre\Database\DataEnvironment::run('sandbox', static function() use ($definition, $t): void {
		$t->isTrue($definition->hydrate());
	}, ['cluster'=>'sandbox-cluster']);
	foreach($state->get('queries') as $call){
		$t->same('sandbox-cluster', $call[0]['dbms_cluster_override'] ?? null);
	}

	$columnDefinition=\Dataphyre\Database\TableDefinition::for('orders')->autoIncrement()->string('name');
	$state->put('query_results',[true]);
	$t->isTrue($columnDefinition->hydrateColumn('name'));
	$state->put('query_results',[false])->put('last_error',null);
	$t->isTrue($columnDefinition->hydrateColumn('name'));
	$state->put('query_results',[false])->put('query_errors',[['message'=>'Duplicate column name']]);
	$t->isTrue($columnDefinition->hydrateColumn('name'));
	$state->put('query_results',[false])->put('query_errors',[['message'=>'permission denied']]);
	$t->isFalse($columnDefinition->hydrateColumn('name'));
	$state->put('queries',[])->put('query_results',[true]);
	\Dataphyre\Database\DataEnvironment::run('sandbox', static function() use ($columnDefinition, $t): void {
		$t->isTrue($columnDefinition->hydrateColumn('name'));
	}, ['cluster'=>'sandbox-cluster']);
	$t->same('sandbox-cluster', $state->get('queries')[0][0]['dbms_cluster_override'] ?? null);
})->tag('sql', 'sql-residual', 'coverage')->group('framework-coverage');
