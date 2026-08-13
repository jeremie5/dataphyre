<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\ConnectionContext;
use Dataphyre\Database\DataEnvironment;
use Dataphyre\Database\DB;
use Dataphyre\Database\ExecutionTrace;
use Dataphyre\Database\TableQuery;
use Dataphyre\Database\TableSchema;
use Dataphyre\Database\Transaction;
use Dataphyre\Database\TransactionResult;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

function dp_database_db_state(): TestState {
	return TestState::channel('sql.database-db');
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void { dp_database_db_state()->append('trace_logs',$arguments); }
}
if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		$state=dp_database_db_state();
		$state->append('query_calls',$arguments);
		$callback=$arguments[array_key_last($arguments)] ?? null;
		$result=$state->get('query_result',[['value'=>'row']]);
		if(count($arguments)>=8 && is_callable($callback)){
			$callback($result);
			return $state->get('queue_registration');
		}
		return $result;
	}
}
if(!function_exists('sql_begin')){ function sql_begin(?string $cluster=null): bool { $state=dp_database_db_state(); $state->append('transaction_calls',['begin',$cluster]); return (bool)$state->get('transaction_ok',true); } }
if(!function_exists('sql_commit')){ function sql_commit(?string $cluster=null): bool { $state=dp_database_db_state(); $state->append('transaction_calls',['commit',$cluster]); return (bool)$state->get('transaction_ok',true); } }
if(!function_exists('sql_rollback')){ function sql_rollback(?string $cluster=null): bool { $state=dp_database_db_state(); $state->append('transaction_calls',['rollback',$cluster]); return (bool)$state->get('transaction_ok',true); } }

if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG',['datacenter'=>'dc-one']); }
if(!defined('DP_SQL_CFG')){
	define('DP_SQL_CFG',[
		'default_cluster'=>'primary',
		'guardrails'=>true,
		'datacenters'=>['dc-one'=>['dbms_clusters'=>[
			'primary'=>['dbms'=>'mysql'],
			'analytics'=>['dbms'=>'postgresql'],
			''=>['dbms'=>'sqlite'],
		]]],
	]);
}
framework(['sql']);

if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static array $observers=[]; public static function add_observer(callable $observer): void { self::$observers[]=$observer; } public static function table_schema(string $table): ?\\Dataphyre\\Database\\TableSchema { return \\dp_database_db_state()->get("table_schemas",[])[$table] ?? null; } public static function table_definition(string $table): ?\\Dataphyre\\Database\\TableDefinition { return null; } public static function execute_queue(string $queue="end"): null|bool { \\dp_database_db_state()->append("execute_queues",$queue); return \\dp_database_db_state()->get("execute_queue_result"); } public static function invalidate_cache(array|string $value): bool { \\dp_database_db_state()->append("invalidations",$value); return (bool)\\dp_database_db_state()->get("invalidation_result",true); } }');
}
if(!class_exists('Dataphyre\\Runtime',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre; final class Runtime { public static bool $tracing=true; public static function tracingEnabled(): bool { return self::$tracing; } }');
}
if(!class_exists('Dataphyre\\Templating\\Templating',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\\Templating; final class Templating { public static array $calls=[]; public static function clearBindingCache(string ...$names): int { if(\\dp_database_db_state()->get("template_bridge_throw",false)===true){ throw new \\RuntimeException("template bridge"); } self::$calls[]=$names; return count($names); } }');
}
if(!class_exists('Dataphyre\\Api\\Api',false)){
	\Dataphyre\Test\define_test_symbols('namespace Dataphyre\\Api; final class Api { public static array $calls=[]; public static function clearEndpointCache(string ...$names): int { if(\\dp_database_db_state()->get("api_bridge_throw",false)===true){ throw new \\RuntimeException("api bridge"); } self::$calls[]=$names; return count($names); } }');
}

final class DpDbStringable implements Stringable { public function __construct(private string $value){} public function __toString(): string{return $this->value;} }

function dp_database_db_scenario(Context $t): TestState {
	$state=$t->state('sql.database-db',[
		'query_calls'=>[],
		'query_result'=>[['value'=>'row']],
		'queue_registration'=>null,
		'transaction_calls'=>[],
		'transaction_ok'=>true,
		'execute_queues'=>[],
		'execute_queue_result'=>null,
		'invalidations'=>[],
		'invalidation_result'=>true,
		'trace_logs'=>[],
		'template_bridge_throw'=>false,
		'api_bridge_throw'=>false,
		'table_schemas'=>['known'=>new TableSchema('known',['id','name'],[],'id')],
	]);
	\Dataphyre\Runtime::$tracing=true;
	\Dataphyre\Templating\Templating::$calls=[];
	\Dataphyre\Api\Api::$calls=[];
	DB::clearObservers();
	DB::clearTraceBuffer();
	DB::disableGuardrails();
	return $state;
}

test('database DB deep coverage normalizes cache policies clusters schemas and connection metadata',static function(Context $t): void {
	dp_database_db_scenario($t);
	$t->same([true],DB::defaultReadCaching());
	$t->same([true,'one','two'],DB::cacheNames(' one ','','two','one'));
	$t->same([true,'one','two'],DB::cacheNames(' one ','','two','one'));
	DB::cacheNames('other');
	$t->same([true,'one','two'],DB::cacheNames(' one ','','two','one'));
	$t->same(['one','two'],DB::invalidationNames(' one ','','two','one'));
	$t->same(['one','two'],DB::invalidationNames(' one ','','two','one'));
	DB::invalidationNames('other');
	$t->same(['one','two'],DB::invalidationNames(' one ','','two','one'));
	$t->isFalse(DB::mergeCacheNames(false,'name'));
	$t->same([true,'name'],DB::mergeCacheNames(null,' name ',' '));
	$t->same(['existing','name'],DB::mergeCacheNames('existing','name','existing'));
	$t->same([true,'name'],DB::mergeCacheNames([true],'name','name'));
	$t->same([true],DB::mergeCacheNames([],''));
	$t->same(true,DB::mergeInvalidationNames(true,''));
	$t->same(false,DB::mergeInvalidationNames(null,''));
	$t->same(['old','new'],DB::mergeInvalidationNames(['old'],' new ','old'));

	$t->same('primary',DB::defaultCluster());
	$t->same(['primary','analytics'],DB::clusters());
	$t->isTrue(DB::hasCluster(' primary '));
	$t->isFalse(DB::hasCluster(' '));
	$t->isFalse(DB::hasCluster('missing'));
	$t->same('dc-one',DB::datacenter());
	$t->same('mysql',DB::clusterDbms());
	$t->same('postgresql',DB::clusterDbms(' analytics '));
	$t->same('mysql',DB::clusterDbms(''));
	$t->same('primary',DB::connection(' primary ')->cluster());
	$t->same(null,DB::connection(' ')->cluster());
	$t->same('analytics',DB::cluster('analytics')->cluster());
	DataEnvironment::run('sandbox', static function() use ($t): void {
		$t->same('analytics',DB::connection()->cluster());
		$t->same('postgresql',DB::clusterDbms());
		$t->same('primary',DB::connection('primary')->cluster());
	}, ['cluster'=>'analytics']);
	$t->throws(static fn()=>DB::connection('missing'),Throwable::class);
	$t->instanceOf(TableQuery::class,DB::table('known'));
	$t->instanceOf(TableQuery::class,DB::table('unknown','id'));
	$t->instanceOf(TableQuery::class,DB::table(new TableSchema('direct',['id'],[],'id')));
	$t->same(null,DB::definition('missing'));
	$t->instanceOf(TableSchema::class,DB::schema('known'));
})->tag('database','db','deep-coverage')->group('framework-coverage');

test('database DB deep coverage delegates raw queued and transactional operations',static function(Context $t): void {
	$state=dp_database_db_scenario($t);
	$state->put('query_result','scalar');
	$t->same('scalar',DB::query('SELECT 1'));
	$t->same('scalar',DB::value('SELECT 1'));
	$t->same(null,DB::row('SELECT 1'));
	$t->same([],DB::rows('SELECT 1'));
	$state->put('query_result',['id'=>1]);
	$t->same(['id'=>1],DB::row('SELECT 1'));
	$state->put('query_result',[['id'=>1]]);
	$t->same([['id'=>1]],DB::rows('SELECT 1'));
	$received=[];
	DB::queueQuery('SELECT 1',static function(mixed $value)use(&$received): void{$received[]=$value;});
	DB::queueValue('SELECT 1',static function(mixed $value)use(&$received): void{$received[]=$value;});
	DB::queueRow('SELECT 1',static function(mixed $value)use(&$received): void{$received[]=$value;});
	DB::queueRows('SELECT 1',static function(mixed $value)use(&$received): void{$received[]=$value;});
	$t->same(4,count($received));
	$t->same(null,DB::executeQueue('later'));
	$t->isTrue(DB::invalidateCache(['one']));

	$begun=DB::begin('primary');
	$t->instanceOf(Transaction::class,$begun);
	$t->isTrue(DB::commit($begun)->committed());
	$rolled=DB::begin('primary');
	$t->isTrue(DB::rollback($rolled)->rolledBack());
	$t->same('value',DB::transaction(static fn(): string=>'value','primary'));
	$t->instanceOf(TransactionResult::class,DB::attemptTransaction(static fn(): string=>'attempt','primary'));
	$t->same('retry',DB::transactionWithRetries(static fn(): string=>'retry','primary',2));
	$t->instanceOf(TransactionResult::class,DB::attemptTransactionWithRetries(static fn(): string=>'retry-attempt','primary',2));
})->tag('database','db','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('database DB deep coverage buffers filters and scopes observable traces',static function(Context $t): void {
	$state=dp_database_db_scenario($t);
	$observed=[];
	DB::observe(static function(ExecutionTrace $trace)use(&$observed): void{$observed[]=$trace;});
	DB::observe(static function(): void{throw new RuntimeException('observer failure');});
	$t->same([],DB::currentTraceContext());
	$value=DB::withTraceContext([' request_id '=>'r1','blank'=>new stdClass(),'stringable'=>new DpDbStringable('s'),'zero'=>0,'null'=>null,' '=>'drop'],static function(): string {
		return DB::withTraceContext(['request_id'=>'inner','job'=>'j1'],static function(): string {
			DB::recordKernelTrace(['event'=>'query','operation'=>'select','context'=>['local'=>'yes'],'result_ok'=>true]);
			return 'scoped';
		});
	});
	$t->same('scoped',$value);
	$t->same([],DB::currentTraceContext());
	$t->same('inner',DB::lastTrace()?->context()['request_id'] ?? null);
	$t->same(1,count(DB::recentTraces(0)));
	$t->same(1,count(DB::recentTracesByContext(['request_id'=>'inner'],10)));
	DB::recordKernelTrace(['event'=>'query','context'=>['request_id'=>'inner']]);
	$t->same(1,count(DB::recentTracesByContext(['request_id'=>'inner'],1)));
	$t->same(0,count(DB::recentTracesByContext(['request_id'=>'missing'],10)));
	$t->same(DB::recentTraces(5),DB::recentTracesByContext([],5));
	$t->notEmpty($observed);
	$t->notEmpty($state->get('trace_logs'));
	$t->same('direct',DB::withTraceContext([],static fn(): string=>'direct'));

	DB::setTraceBufferLimit(1);
	DB::recordKernelTrace(['event'=>'one']);
	DB::recordKernelTrace(['event'=>'two']);
	$t->same(1,count(DB::recentTraces(10)));
	DB::clearTraceBuffer();
	$t->same(null,DB::lastTrace());
	$dbInternals=$t->nonPublic(DB::class);
	$dbInternals->writeProperty('traceBuffer',['invalid']);
	$t->same([],DB::recentTracesByContext(['id'=>1],5));
	$dbInternals->writeProperty('traceBuffer',[ExecutionTrace::fromArray(['event'=>'one']),ExecutionTrace::fromArray(['event'=>'two'])]);
	DB::setTraceBufferLimit(1);
	$t->same(1,count(DB::recentTraces()));
	DB::clearObservers();

	\Dataphyre\Runtime::$tracing=false;
	$called=false;
	DB::observe(static function()use(&$called): void{$called=true;});
	$t->same('off',DB::withTraceContext(['id'=>1],static fn(): string=>'off'));
	$t->same([],DB::currentTraceContext());
	$t->same(null,DB::lastTrace());
	$t->same([],DB::recentTraces());
	$t->same([],DB::recentTracesByContext(['id'=>1]));
	DB::recordKernelTrace(['event'=>'disabled']);
	$t->isFalse($called);
	$dbInternals->writeProperty('internalTraceObservers',[static function(): void{throw new RuntimeException('internal observer failure');}]);
	DB::recordKernelTrace(['event'=>'internal-only']);
	$t->notEmpty($state->get('trace_logs'));
	$dbInternals->writeProperty('internalTraceObservers',[]);
})->tag('database','db','deep-coverage')->group('framework-coverage');

test('database DB deep coverage synchronizes optional caches and guardrail trace branches',static function(Context $t): void {
	$state=dp_database_db_scenario($t);
	DB::bootRuntimeBridges();
	DB::bootRuntimeBridges();
	DB::recordKernelTrace(['event'=>'other','invalidation_names'=>['one']]);
	DB::recordKernelTrace(['event'=>'cache_invalidate','invalidation_names'=>[]]);
	DB::recordKernelTrace(['event'=>'cache_invalidate','invalidation_names'=>['one','two']]);
	$t->notEmpty(\Dataphyre\Templating\Templating::$calls);
	$t->notEmpty(\Dataphyre\Api\Api::$calls);
	$state->put('template_bridge_throw',true);
	$state->put('api_bridge_throw',true);
	DB::recordKernelTrace(['event'=>'cache_invalidate','invalidation_names'=>['broken']]);
	$t->notEmpty($state->get('trace_logs'));

	DB::disableGuardrails();
	$t->isFalse(DB::guardrailsEnabled());
	DB::reportGuardrailWarning('disabled');
	DB::enableGuardrails();
	$t->isTrue(DB::guardrailsEnabled());
	DB::reportGuardrailWarning('enabled',['operation'=>'update']);
	$t->same('guardrail_warning',DB::lastTrace()?->event());
	DB::enableGuardrails(false);
	$t->isFalse(DB::guardrailsEnabled());
	$t->nonPublic(DB::class)->writeProperty('guardrailsEnabled',null);
	$t->isFalse(DB::guardrailsEnabled());
})->tag('database','db','deep-coverage')->group('framework-coverage');

test('database DB deep coverage directly covers normalization matching and bridge callback guards',static function(Context $t): void {
	dp_database_db_scenario($t);
	$dbInternals=$t->nonPublic(DB::class);
	$normalized=$dbInternals->invoke('normalizeTraceContext',[' '=>1,'scalar'=>1,'null'=>null,'stringable'=>new DpDbStringable('object'),'array'=>[],'opaque'=>new stdClass()]);
	$t->same(['scalar'=>1,'null'=>null,'stringable'=>'object'],$normalized);
	$trace=ExecutionTrace::fromArray(['event'=>'query','context'=>['id'=>1,'same'=>'yes']]);
	$t->isTrue($dbInternals->invoke('traceMatchesContext',$trace,['id'=>1]));
	$t->isFalse($dbInternals->invoke('traceMatchesContext',$trace,['id'=>'1']));
	$t->isFalse($dbInternals->invoke('traceMatchesContext',$trace,['missing'=>1]));
	$t->same(null,$dbInternals->invoke('normalizeCluster',null));
	$t->same(null,$dbInternals->invoke('normalizeCluster',' '));
	$t->same('one',$dbInternals->invoke('normalizeCluster',' one '));
	$dbInternals->invoke('syncTemplatingBindingCaches',ExecutionTrace::fromArray(['event'=>'other']));
	$dbInternals->invoke('syncTemplatingBindingCaches',ExecutionTrace::fromArray(['event'=>'cache_invalidate','invalidation_names'=>[]]));
	$dbInternals->invoke('syncApiEndpointCaches',ExecutionTrace::fromArray(['event'=>'other']));
	$dbInternals->invoke('syncApiEndpointCaches',ExecutionTrace::fromArray(['event'=>'cache_invalidate','invalidation_names'=>[]]));
})->tag('database','db','deep-coverage')->group('framework-coverage');
