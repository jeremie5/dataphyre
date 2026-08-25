<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\ConnectionContext;
use Dataphyre\Database\DB;
use Dataphyre\Database\ExecutionTrace;
use Dataphyre\Database\Transaction;
use Dataphyre\Database\TransactionResult;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

function dp_tx_state(): TestState {
	return TestState::channel('sql.transaction-context');
}

if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		$state=dp_tx_state();
		$state->append('query_calls',$arguments);
		$query=$arguments[0] ?? '';
		$isControl=is_array($query) && isset($query['mysql']) && preg_match('/^(SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)/',(string)$query['mysql'])===1;
		if($isControl && $state->get('control_results',[])!==[]){ return $state->shift('control_results'); }
		$callback=$arguments[array_key_last($arguments)] ?? null;
		$result=$state->get('query_result',true);
		if(count($arguments)>=8 && is_callable($callback)){ $callback($result); return $state->get('queue_result'); }
		return $result;
	}
}
if(!function_exists('sql_begin')){
	function sql_begin(?string $cluster=null): bool { $state=dp_tx_state(); $state->append('kernel_calls',['begin',$cluster]); return $state->get('begin_results',[])!==[] ? (bool)$state->shift('begin_results') : true; }
}
if(!function_exists('sql_commit')){
	function sql_commit(?string $cluster=null): bool { $state=dp_tx_state(); $state->append('kernel_calls',['commit',$cluster]); return $state->get('commit_results',[])!==[] ? (bool)$state->shift('commit_results') : true; }
}
if(!function_exists('sql_rollback')){
	function sql_rollback(?string $cluster=null): bool { $state=dp_tx_state(); $state->append('kernel_calls',['rollback',$cluster]); return $state->get('rollback_results',[])!==[] ? (bool)$state->shift('rollback_results') : true; }
}
if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG',['datacenter'=>'dc']); }
if(!defined('DP_SQL_CFG')){ define('DP_SQL_CFG',['default_cluster'=>'primary','datacenters'=>['dc'=>['dbms_clusters'=>['primary'=>['dbms'=>'sqlite'],'other'=>['dbms'=>'mysql']]]]]); }
framework(['sql']);
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static function add_observer(callable $observer): void {} public static function execute_queue(string $queue="end"): null|bool { return null; } public static function invalidate_cache(array|string $value, array|bool|null $policy=null): bool { \\dp_tx_state()->append("invalidations",[$value,$policy]); return true; } public static function table_schema(string $table): ?\\Dataphyre\\Database\\TableSchema { return null; } public static function table_definition(string $table): ?\\Dataphyre\\Database\\TableDefinition { return null; } }');
}

final class DpTxCallbacks {
	public function objectMethod(Transaction $transaction): string { return $transaction->cluster() ?? 'default'; }
	public static function staticMethod(ConnectionContext $connection): string { return $connection->cluster() ?? 'default'; }
}
final class DpTxInvokable { public function __invoke(Transaction $transaction): string { return $transaction->cluster() ?? 'default'; } }
function dp_tx_named_callback(Transaction $transaction): string { return $transaction->cluster() ?? 'default'; }

function dp_tx_scenario(Context $t): TestState {
	$state=$t->state('sql.transaction-context',[
		'query_calls'=>[],
		'kernel_calls'=>[],
		'control_results'=>[],
		'begin_results'=>[],
		'commit_results'=>[],
		'rollback_results'=>[],
		'query_result'=>true,
		'queue_result'=>null,
		'invalidations'=>[],
	]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeDepthByCluster',[]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeTransactions',[]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('flushingCacheInvalidations',false);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('fiberStates',null);
	return $state;
}

test('transaction context trace deep coverage handles top level nested lifecycle and state accessors',static function(Context $t): void {
	dp_tx_scenario($t);
	$outer=new Transaction(' primary ');
	$t->same('primary',$outer->cluster());
	$t->same('primary',$outer->connection()->cluster());
	$t->isFalse($outer->isActive());
	$t->isFalse($outer->begun());
	$t->isFalse($outer->committed());
	$t->isFalse($outer->rolledBack());
	$t->isFalse($outer->isNested());
	$t->same(null,$outer->savepointName());
	$t->same(0,Transaction::activeDepth('primary'));
	$outer->begin();
	$t->same(1,Transaction::activeDepth(' primary '));
	$t->throws(static fn()=>$outer->begin(),Throwable::class);
	$inner=new Transaction('primary');
	$inner->begin();
	$t->isTrue($inner->isNested());
	$t->notEmpty($inner->savepointName());
	$t->same(2,Transaction::activeDepth('primary'));
	$inner->commit();
	$t->isTrue($inner->committed());
	$t->same(1,Transaction::activeDepth('primary'));
	$innerRollback=new Transaction('primary');
	$innerRollback->begin()->rollback();
	$t->isTrue($innerRollback->rolledBack());
	$outer->commit();
	$t->same(0,Transaction::activeDepth('primary'));
	$t->throws(static fn()=>$outer->commit(),Throwable::class);
	$t->throws(static fn()=>$outer->rollback(),Throwable::class);
	$default=new Transaction(' ');
	$t->same(null,$default->cluster());
	$t->same(0,Transaction::activeDepth(null));
})->tag('sql','transaction','context','trace','deep-coverage')->group('framework-coverage');

test('transaction context exposes cluster affinity and isolates bookkeeping between fibers',static function(Context $t): void {
	dp_tx_scenario($t);
	$t->same(null,Transaction::activeCluster());

	$outer=new Transaction('primary');
	$outer->begin();
	$t->same('primary',Transaction::activeCluster());
	$inner=new Transaction('other');
	$inner->begin();
	$t->same('other',Transaction::activeCluster());
	$inner->rollback();
	$t->same('primary',Transaction::activeCluster());
	$outer->rollback();

	$default=new Transaction();
	$default->begin();
	$t->same('primary',Transaction::activeCluster());
	$default->rollback();

	DB::transaction(static function() use ($t): void {
		DB::query('SELECT 1');
		$calls=dp_tx_state()->get('query_calls');
		$query=$calls[array_key_last($calls)][0] ?? [];
		$t->same('other',$query['dbms_cluster_override'] ?? null);
	},'other');

	$primaryFiber=new Fiber(static function(): void {
		$transaction=new Transaction('primary');
		$transaction->begin();
		Fiber::suspend([Transaction::activeCluster(),Transaction::activeDepth('primary')]);
		$transaction->rollback();
	});
	$otherFiber=new Fiber(static function(): void {
		$transaction=new Transaction('other');
		$transaction->begin();
		Fiber::suspend([Transaction::activeCluster(),Transaction::activeDepth('other')]);
		$transaction->rollback();
	});
	$t->same(['primary',1],$primaryFiber->start());
	$t->same(null,Transaction::activeCluster());
	$t->same(['other',1],$otherFiber->start());
	$t->same(0,Transaction::activeDepth('primary'));
	$t->same(0,Transaction::activeDepth('other'));
	$primaryFiber->resume();
	$otherFiber->resume();
	$t->isTrue($primaryFiber->isTerminated());
	$t->isTrue($otherFiber->isTerminated());
})->tag('sql','transaction','cluster','fiber','regression')->group('framework-coverage');

test('transaction context defers cache invalidation until outer commit and discards rolled back work',static function(Context $t): void {
	$state=dp_tx_scenario($t);
	$t->isFalse(Transaction::hasActiveTransaction());
	$t->isFalse(Transaction::deferCacheInvalidation('fixture.orders'));

	$outer=new Transaction('primary');
	$outer->begin();
	$t->isTrue(Transaction::hasActiveTransaction());
	$t->isTrue(Transaction::deferCacheInvalidation('fixture.orders',['type'=>'shared_cache']));
	$t->isTrue(Transaction::deferCacheInvalidation('fixture.orders',['type'=>'shared_cache']));

	$committedSavepoint=new Transaction('primary');
	$committedSavepoint->begin();
	$t->isTrue(Transaction::deferCacheInvalidation(['orders.summary','tenant.42']));
	$committedSavepoint->commit();
	$t->same([],$state->get('invalidations'));

	$rolledBackSavepoint=new Transaction('primary');
	$rolledBackSavepoint->begin();
	$t->isTrue(Transaction::deferCacheInvalidation('fixture.customers'));
	$rolledBackSavepoint->rollback();
	$t->same([],$state->get('invalidations'));

	$outer->commit();
	$t->isFalse(Transaction::hasActiveTransaction());
	$t->same([
		['fixture.orders',['type'=>'shared_cache']],
		[['orders.summary','tenant.42'],null],
	],$state->get('invalidations'));

	$state->put('invalidations',[]);
	$rolledBack=new Transaction('primary');
	$rolledBack->begin();
	$t->isTrue(Transaction::deferCacheInvalidation('fixture.orders'));
	$rolledBack->rollback();
	$t->same([],$state->get('invalidations'));

	$scopedEnvironment=new Transaction('primary');
	$scopedEnvironment->begin();
	\Dataphyre\Database\DataEnvironment::run(
		'sandbox',
		static fn()=>Transaction::deferCacheInvalidation('fixture.sandbox-orders'),
		['cluster'=>'primary','cache_namespace'=>'fixture-sandbox'],
	);
	$pending=$t->nonPublic($scopedEnvironment)->readProperty('pendingCacheInvalidations');
	$captured=reset($pending);
	$t->same([
		'name'=>'sandbox','cluster'=>'primary','cache_namespace'=>'fixture-sandbox',
	],$captured['environment'] ?? null);
	$scopedEnvironment->rollback();

	$outerCluster=new Transaction('primary');
	$outerCluster->begin();
	$independentCluster=new Transaction('other');
	$independentCluster->begin();
	$t->isFalse($independentCluster->isNested());
	$t->isTrue(Transaction::deferCacheInvalidation('analytics.events'));
	$independentCluster->commit();
	$t->same([['analytics.events',null]],$state->get('invalidations'));
	$outerCluster->rollback();
	$t->same([['analytics.events',null]],$state->get('invalidations'));

	$state->put('invalidations',[]);
	$outerPrimary=new Transaction('primary');
	$outerPrimary->begin();
	$outerOther=new Transaction('other');
	$outerOther->begin();
	$innerPrimary=new Transaction('primary');
	$innerPrimary->begin();
	$t->isTrue($innerPrimary->isNested());
	$t->isTrue(Transaction::deferCacheInvalidation('fixture.customers'));
	$innerPrimary->commit();
	$outerOther->commit();
	$t->same([],$state->get('invalidations'));
	$outerPrimary->commit();
	$t->same([['fixture.customers',null]],$state->get('invalidations'));
})->tag('sql','transaction','cache','invalidation','regression')->group('framework-coverage');

test('transaction context trace deep coverage reports begin commit rollback and savepoint failures',static function(Context $t): void {
	$state=dp_tx_scenario($t);
	$state->put('begin_results',[false]);
	$t->throws(static fn()=>(new Transaction())->begin(),Throwable::class);
	$state->put('begin_results',[true]);$commit=new Transaction();$commit->begin();$state->put('commit_results',[false]);
	$t->throws(static fn()=>$commit->commit(),Throwable::class);
	$state->put('commit_results',[true]);$commit->commit();
	$state->put('begin_results',[true]);$rollback=new Transaction();$rollback->begin();$state->put('rollback_results',[false]);
	$t->throws(static fn()=>$rollback->rollback(),Throwable::class);
	$state->put('rollback_results',[true]);$rollback->rollback();

	$outer=new Transaction();$outer->begin();
	$state->put('control_results',[false]);
	$t->throws(static fn()=>(new Transaction())->begin(),Throwable::class);
	$state->put('control_results',[true,false]);
	$nestedCommit=new Transaction();$nestedCommit->begin();
	$t->throws(static fn()=>$nestedCommit->commit(),Throwable::class);
	$state->put('control_results',[true]);$nestedCommit->commit();
	$state->put('control_results',[true,false]);
	$nestedRollback=new Transaction();$nestedRollback->begin();
	$t->throws(static fn()=>$nestedRollback->rollback(),Throwable::class);
	$state->put('control_results',[true,true]);$nestedRollback->rollback();
	$state->put('control_results',[true,true,false]);
	$releaseFailure=new Transaction();$releaseFailure->begin();
	$t->throws(static fn()=>$releaseFailure->rollback(),Throwable::class);
	$state->put('control_results',[true]);$releaseFailure->commit();
	$outer->rollback();
})->tag('sql','transaction','context','trace','deep-coverage')->group('framework-coverage');

test('transaction context trace deep coverage runs callbacks injection attempts retries and rollback wrapping',static function(Context $t): void {
	$state=dp_tx_scenario($t);
	$t->same('zero',(new Transaction('primary'))->run(static fn(): string=>'zero'));
	$t->same('primary',(new Transaction('primary'))->run(static fn(Transaction $transaction): string=>$transaction->cluster() ?? ''));
	$t->same('primary',(new Transaction('primary'))->run(static fn(ConnectionContext $connection): string=>$connection->cluster() ?? ''));
	$t->same('primary',(new Transaction('primary'))->run(static fn(Transaction|ConnectionContext $helper): string=>$helper instanceof Transaction ? (string)$helper->cluster() : 'connection'));
	$t->same('primary',(new Transaction('primary'))->run(static fn($helper): string=>(string)$helper->cluster()));
	$t->same('primary',(new Transaction('primary'))->run(static fn($helper): string=>(string)$helper->cluster(),new ConnectionContext('primary'),true));
	$t->same(2,(new Transaction('primary'))->run(static fn(...$helpers): int=>count($helpers)));
	$t->same('default',(new Transaction())->run(static fn($one,$two,?string $three=null,string $four='default'): string=>$four));
	$t->same('null',(new Transaction())->run(static fn($one,$two,?string $three): string=>$three===null ? 'null' : 'not-null'));
	$t->throws(static fn()=>(new Transaction())->run(static fn($one,$two,string $three): string=>$three),ArgumentCountError::class);
	$t->same('primary',(new Transaction('primary'))->run([new DpTxCallbacks(),'objectMethod']));
	$t->same('primary',(new Transaction('primary'))->run(DpTxCallbacks::class.'::staticMethod'));
	$t->same('primary',(new Transaction('primary'))->run(new DpTxInvokable()));
	$t->same('primary',(new Transaction('primary'))->run('dp_tx_named_callback'));
	$manual=new Transaction();
	$t->same('manual',$manual->run(static function(Transaction $transaction): string{$transaction->commit();return 'manual';}));

	$failure=(new Transaction())->attempt(static function(): never{throw new RuntimeException('failure');});
	$t->instanceOf(TransactionResult::class,$failure);
	$t->isFalse($failure->ok());
	$t->isTrue($failure->rolledBack());
	$t->isTrue((new Transaction())->attempt(static fn(): string=>'ok')->ok());

	$attempt=0;
	$value=(new Transaction())->runWithRetries(static function()use(&$attempt): string{if(++$attempt<2){throw new RuntimeException('retry');}return 'retried';},2,static fn(): bool=>true,0);
	$t->same('retried',$value);
	$attempt=0;
	$result=(new Transaction())->attemptWithRetries(static function()use(&$attempt): string{if(++$attempt<2){throw new RuntimeException('retry');}return 'retried';},2,static fn(): bool=>true,0);
	$t->isTrue($result->ok());
	$t->same(2,$result->attempts());
	$t->isFalse((new Transaction())->attemptWithRetries(static function(): never{throw new RuntimeException('no');},2,static fn(): bool=>false)->ok());
	$t->isFalse((new Transaction())->attemptWithRetries(static function(): never{throw new RuntimeException('final');},2,static fn(): bool=>true)->ok());
	$t->throws(static fn()=>(new Transaction())->runWithRetries(static function(): never{throw new RuntimeException('no');},2,static fn(): bool=>false),RuntimeException::class);

	$exception=new RuntimeException('x');
	$transactionInternals=$t->nonPublic(new Transaction());
	$t->isTrue($transactionInternals->invoke('shouldRetry',$exception,1,2,static fn(): bool=>true));
	$t->isFalse($transactionInternals->invoke('shouldRetry',$exception,1,2,null));
	$transactionInternals->invoke('sleepBeforeRetry',0,1);
	$transactionInternals->invoke('sleepBeforeRetry',1,1);
	$t->instanceOf(Transaction::class,$transactionInternals->invoke('transactionForAttempt',1));
	$t->instanceOf(Transaction::class,$transactionInternals->invoke('transactionForAttempt',2));

	$state->put('rollback_results',[false]);
	$t->throws(static fn()=>(new Transaction())->run(static function(): never{throw new RuntimeException('callback');}),Throwable::class);
})->tag('sql','transaction','context','trace','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('transaction context trace deep coverage exercises connection context queries queues and transaction aliases',static function(Context $t): void {
	$state=dp_tx_scenario($t);
	$connection=new ConnectionContext(' primary ');
	$t->same('primary',$connection->cluster());
	$t->same('sqlite',$connection->dbms());
	$begun=$connection->begin();
	$t->instanceOf(Transaction::class,$begun);
	$begun->rollback();
	$t->same('value',$connection->transaction(static fn(): string=>'value'));
	$t->isTrue($connection->attemptTransaction(static fn(): string=>'value')->ok());
	$t->same('retry',$connection->transactionWithRetries(static fn(): string=>'retry',2));
	$t->isTrue($connection->attemptTransactionWithRetries(static fn(): string=>'retry',2)->ok());
	$state->put('query_result','scalar');
	$t->same('scalar',$connection->query('SELECT 1'));
	$t->same('scalar',$connection->value('SELECT 1'));
	$t->same(null,$connection->row('SELECT 1'));
	$t->same([],$connection->rows('SELECT 1'));
	$state->put('query_result',['id'=>1]);$t->same(['id'=>1],$connection->row('SELECT 1'));
	$state->put('query_result',[['id'=>1]]);$t->same([['id'=>1]],$connection->rows('SELECT 1'));
	$received=[];
	$connection->queueQuery('SELECT 1',static function($value)use(&$received): void{$received[]=$value;});
	$connection->queueValue('SELECT 1',static function($value)use(&$received): void{$received[]=$value;});
	$connection->queueRow('SELECT 1',static function($value)use(&$received): void{$received[]=$value;});
	$connection->queueRows(['mysql'=>'SELECT 1'],static function($value)use(&$received): void{$received[]=$value;});
	$t->same(4,count($received));
	$default=new ConnectionContext(' ');
	$t->same(null,$default->cluster());
	$state->put('query_result','plain');
	$t->same('plain',$default->query('plain'));
})->tag('sql','transaction','context','trace','deep-coverage')->group('framework-coverage');

test('transaction context trace deep coverage exposes every normalized execution trace field and helper',static function(Context $t): void {
	dp_tx_scenario($t);
	$trace=ExecutionTrace::fromArray([
		'source'=>' framework ','event'=>' guardrail_warning ','operation'=>' select ','message'=>' message ','reason'=>' reason ','location'=>' file:1 ',
		'cluster'=>' primary ','dbms'=>' sqlite ','queue'=>' end ','queued'=>true,'cache_status'=>' hit ','cache_type'=>' memory ',
		'cache_names'=>[' one ','','one','two'],'invalidation_names'=>' invalid ','result_ok'=>true,
		'context'=>['render_trace_id'=>' render ','binding_trace_id'=>' binding ','query_fingerprint'=>' fp ','query_identity_mode'=>' inherit ',
			'query_identity_source'=>' fingerprint ','query_target_type'=>' table ','query_target'=>' users ','query_mode'=>' rows '],
		'timestamp'=>'123.5','extra'=>'value',
	]);
	$t->same('framework',$trace->source());$t->same('guardrail_warning',$trace->event());$t->same('select',$trace->operation());
	$t->same('message',$trace->message());$t->same('reason',$trace->reason());$t->same('file:1',$trace->location());
	$t->same('primary',$trace->cluster());$t->same('sqlite',$trace->dbms());$t->same('end',$trace->queue());
	$t->isTrue($trace->queued());$t->isFalse($trace->immediate());$t->same('hit',$trace->cacheStatus());$t->same('memory',$trace->cacheType());
	$t->same(['one','two'],$trace->cacheNames());$t->same([],$trace->invalidationNames());$t->same(true,$trace->resultOk());
	$t->same('value',$trace->contextValue('extra'));$t->same('fallback',$trace->contextValue('missing','fallback'));$t->same('fallback',$trace->contextValue(' ','fallback'));
	$t->same('render',$trace->renderTraceId());$t->same('binding',$trace->bindingTraceId());$t->same('fp',$trace->queryFingerprint());
	$t->same('inherit',$trace->queryIdentityMode());$t->same('fingerprint',$trace->queryIdentitySource());$t->same('table',$trace->queryTargetType());
	$t->same('users',$trace->queryTarget());$t->same('rows',$trace->queryMode());$t->same(123.5,$trace->timestamp());$t->isTrue($trace->isWarning());
	$t->same($trace->toArray(),$trace->jsonSerialize());$t->notEmpty($trace->context());

	$defaults=ExecutionTrace::fromArray(['source'=>' ','event'=>' ','result_ok'=>'yes','cache_names'=>[1,new stdClass(),' ok '],'context'=>['render_trace_id'=>1]]);
	$t->same('kernel',$defaults->source());$t->same('unknown',$defaults->event());$t->same(null,$defaults->resultOk());
	$t->same(['ok'],$defaults->cacheNames());$t->same(null,$defaults->renderTraceId());$t->isFalse($defaults->isWarning());
})->tag('sql','transaction','context','trace','deep-coverage')->group('framework-coverage');
