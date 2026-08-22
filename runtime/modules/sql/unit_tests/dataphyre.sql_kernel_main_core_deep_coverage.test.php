<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\GlobalState;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TestState;
use Dataphyre\Database\DataEnvironment;
use Dataphyre\Database\Transaction;
use function Dataphyre\Test\test;

function dp_sql_kernel_state(): ?TestState {
	return TestState::channelIfActive('sql.kernel-main-core');
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void { dp_sql_kernel_state()?->append('traces',$arguments); }
}
if(!function_exists('dataphyre_shutdown_log')){
	function dataphyre_shutdown_log(string $message,?Throwable $exception=null): void { dp_sql_kernel_state()?->append('shutdown_logs',[$message,$exception]); }
}
if(!function_exists('log_error')){
	function log_error(mixed ...$arguments): void { dp_sql_kernel_state()?->append('error_logs',$arguments); }
}
if(!function_exists('dp_module_present')){
	function dp_module_present(string $module): bool { return (bool)(dp_sql_kernel_state()?->get('modules',[])[$module] ?? false); }
}
if(!function_exists('dataphyre\\debug_backtrace')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
function debug_backtrace(int $options=DEBUG_BACKTRACE_PROVIDE_OBJECT,int $limit=0): array {
	$state=\dp_sql_kernel_state();
	if($state!==null && $state->has('backtrace')){
		$frames=$state->get('backtrace',[]);
		return is_array($frames) ? $frames : [];
	}
	return \debug_backtrace($options,$limit);
}
PHP);
}
if(!class_exists('dataphyre\\core',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function dialback(string $name,mixed ...$arguments): mixed {
		$state=\dp_sql_kernel_state();
		if($state===null){ return null; }
		$state->append('dialback_calls',[$name,$arguments]);
		$dialbacks=$state->get('dialbacks',[]);
		if(!array_key_exists($name,$dialbacks)){ return null; }
		$value=$dialbacks[$name];
		if(is_array($value) && array_is_list($value)){
			$result=array_shift($value);
			$dialbacks[$name]=$value;
			$state->put('dialbacks',$dialbacks);
			return $result;
		}
		return is_callable($value) ? $value(...$arguments) : $value;
	}
	public static function load_framework_module(string $module): bool { return (bool)(\dp_sql_kernel_state()?->get('framework_available',true) ?? true); }
	public static function file_put_contents_forced(string $file,string $contents): int|false {
		$directory=dirname($file);
		if(!is_dir($directory)){ mkdir($directory,0777,true); }
		return file_put_contents($file,$contents);
	}
	public static function force_rmdir(string $directory): bool {
		if(!is_dir($directory)){ return true; }
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
		foreach($iterator as $item){ $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
		return rmdir($directory);
	}
	public static function unavailable(mixed ...$arguments): null { \dp_sql_kernel_state()?->append('unavailable',$arguments); return null; }
	public static function get_password(string $endpoint): string { return ''; }
}
PHP);
}

define('DP_SQL_KERNEL_TEST_DBMS','sqlite');
require_once __DIR__.'/../Framework/Transaction.php';
require_once __DIR__.'/fixtures/sql_kernel_main_coverage_bootstrap.php';

final class DpSqlKernelScenario {
	public function __construct(
		public TestState $fixture,
		public GlobalState $session,
		public NonPublicAccess $sql,
	) {}
}

function dp_sql_kernel_scenario(Context $t): DpSqlKernelScenario {
	$state=$t->state('sql.kernel-main-core',[
		'dialbacks'=>[],
		'dialback_calls'=>[],
		'traces'=>[],
		'error_logs'=>[],
		'shutdown_logs'=>[],
		'unavailable'=>[],
		'modules'=>['cache'=>true],
		'framework_available'=>true,
	]);
	$session=$t->globalMap('_SESSION')->clear();
	$t->global('dataphyre_flightdeck_replay_write_blocks')->replace(0);
	\dataphyre\cache::$values=[];
	\dataphyre\cache::$expires=[];
	\dataphyre\cache::$shared=true;
	\dataphyre\mysql_query_builder::$queued_queries=[];
	\dataphyre\postgresql_query_builder::$queued_queries=[];
	\dataphyre\sqlite_query_builder::$queued_queries=[];
	$sql=$t->nonPublic(\dataphyre\sql::class);
	$sql->replacePropertyForTest('observers',[]);
	$sql->replacePropertyForTest('last_query_error',null);
	$sql->replacePropertyForTest('table_definition_registry',[]);
	$sql->replacePropertyForTest('loaded_table_definition_files',[]);
	$sql->replacePropertyForTest('structure_hydration_retrying',[]);
	$sql->replacePropertyForTest('unavailable_servers',[]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeDepthByCluster',[]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeTransactions',[]);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('flushingCacheInvalidations',false);
	return new DpSqlKernelScenario($state,$session,$sql);
}

function dp_sql_kernel_dialback(TestState $state,string $name,mixed $response): void {
	$state->put('dialbacks',array_replace($state->get('dialbacks',[]),[$name=>$response]));
}

function dp_sql_kernel_forget_dialback(TestState $state,string $name): void {
	$dialbacks=$state->get('dialbacks',[]);
	unset($dialbacks[$name]);
	$state->put('dialbacks',$dialbacks);
}

function dp_sql_kernel_cache_entry(GlobalState $session,string $table,string $hash,mixed $value,?int $storedAt=null): void {
	$cache=$session->get('db_cache',[]);
	$cache[$table][$hash]=[$value,$storedAt ?? time()];
	$session->put('db_cache',$cache);
}

function dp_sql_kernel_cached_value(GlobalState $session,string $table,string $hash): mixed {
	return $session->get('db_cache',[])[$table][$hash][0] ?? null;
}

function dp_sql_kernel_has_cache_entry(GlobalState $session,string $table,string $hash): bool {
	return isset($session->get('db_cache',[])[$table][$hash]);
}

test('sql kernel main core deep coverage normalizes trace metadata and isolates observers',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$sql=$scenario->sql;
	$t->same([],$sql->invokeWithArguments('trace_cache_names',[false]));
	$t->same([],$sql->invokeWithArguments('trace_cache_names',[null]));
	$t->same(['one'],$sql->invokeWithArguments('trace_cache_names',[' one ']));
	$t->same(['one','two'],$sql->invokeWithArguments('trace_cache_names',[[true,' one ','lazy','',new stdClass(),'one','two']]));
	$t->same([],$sql->invokeWithArguments('trace_invalidation_names',[false]));
	$t->same([],$sql->invokeWithArguments('trace_invalidation_names',[null]));
	$t->same([],$sql->invokeWithArguments('trace_invalidation_names',[true]));
	$t->same(['one','two'],$sql->invokeWithArguments('trace_invalidation_names',[[true,' one ','','one','two']]));
	$t->same(null,$sql->invokeWithArguments('trace_queue_name',[null]));
	$t->same(null,$sql->invokeWithArguments('trace_queue_name',[' ']));
	$t->same('end',$sql->invokeWithArguments('trace_queue_name',[' end ']));
	$t->same(0.0,$sql->invokeWithArguments('trace_elapsed_ms',[microtime(true)+1]));
	$t->isTrue($sql->invokeWithArguments('trace_elapsed_ms',[microtime(true)-0.001])>=0.0);
	$t->same(2,$sql->invokeWithArguments('trace_result_count',[[['id'=>1],['id'=>2]]]));
	$t->same(1,$sql->invokeWithArguments('trace_result_count',[['id'=>1]]));
	$t->same(7,$sql->invokeWithArguments('trace_result_count',[7]));
	$t->same(0,$sql->invokeWithArguments('trace_result_count',[false]));
	$t->same(0,$sql->invokeWithArguments('trace_result_count',[null]));
	$t->same(null,$sql->invokeWithArguments('trace_result_count',['value']));

	$frame=$sql->invokeWithArguments('trace_frame',[['file'=>'/tmp/example.php','line'=>12,'class'=>'Demo','type'=>'::','function'=>'run']]);
	$t->same('Demo::run',$frame['call']);
	$t->contains('example.php:12',$frame['label']);
	$t->same('',$sql->invokeWithArguments('trace_frame',[[]])['label']);
	$scenario->fixture->put('backtrace',[
		['file'=>'/runtime/modules/sql/kernel/sql.main.php','line'=>10,'function'=>'query'],
		['file'=>'/runtime/modules/flightdeck/kernel/debug.php','line'=>20,'function'=>'inspect'],
	]);
	$t->contains('debug.php:20',$sql->invokeWithArguments('trace_caller',[])['label']);
	$scenario->fixture->forget('backtrace');
	$t->same('SELECT id FROM users WHERE id=?',$sql->invokeWithArguments('trace_statement',['select',' users ',' id ',' WHERE id=? ']));
	$t->same('SELECT id FROM users',$sql->invokeWithArguments('trace_statement',['select','users','id',null]));
	$t->same('SELECT COUNT(*) FROM users WHERE active=1',$sql->invokeWithArguments('trace_statement',['count','users',null,'WHERE active=1']));
	$t->same('SELECT COUNT(*) FROM users',$sql->invokeWithArguments('trace_statement',['count','users']));
	$t->same('INSERT INTO users (name,email) VALUES (...)',$sql->invokeWithArguments('trace_statement',['insert','users','name,email']));
	$t->same('UPDATE users SET name=? WHERE id=?',$sql->invokeWithArguments('trace_statement',['update','users','name=?','WHERE id=?']));
	$t->same('UPDATE users SET name=?',$sql->invokeWithArguments('trace_statement',['update','users','name=?']));
	$t->same('DELETE FROM users WHERE id=?',$sql->invokeWithArguments('trace_statement',['delete','users',null,'WHERE id=?']));
	$t->same('DELETE FROM users',$sql->invokeWithArguments('trace_statement',['delete','users']));
	$t->same('RAW',$sql->invokeWithArguments('trace_statement',['query','raw',' RAW ']));

	$values=$sql->invokeWithArguments('trace_values',[['password'=>'secret','items'=>[1,2],'object'=>new stdClass(),'name'=>'Ada','count'=>2,'nothing'=>null]]);
	$t->same('redacted',$values['password']['type']);
	$t->same(2,$values['items']['count']);
	$t->same('stdClass',$values['object']['class']);
	$t->same(3,$values['name']['bytes']);
	$t->same('int',$values['count']['type']);
	$t->same('null',$values['nothing']['type']);
	$t->same([],$sql->invokeWithArguments('trace_payload',['select','users',['vars'=>['id'=>1]]]));

	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	\dataphyre\sql::add_observer(static function(): void {throw new RuntimeException('observer failure');});
	$sql->invokeWithArguments('emit_observer_event',[['event'=>'manual']]);
	$t->same('manual',$events[0]['event']);
	$t->isTrue(isset($events[0]['timestamp']));
	$payload=$sql->invokeWithArguments('trace_payload',['select','users',['select'=>'id','params'=>'WHERE id=?','vars'=>['token'=>'x','id'=>1]]]);
	$t->same('SELECT id FROM users WHERE id=?',$payload['statement']);
	$t->same('redacted',$payload['vars']['token']['type']);
	$t->notEmpty($payload['caller']);
	$t->notEmpty($payload['stack']);
	$prebuilt=$sql->invokeWithArguments('trace_payload',['query','raw',['statement'=>'already','caller'=>['file'=>'x'],'stack'=>[['file'=>'y']]]]);
	$t->same('already',$prebuilt['statement']);
	$t->same('x',$prebuilt['caller']['file']);
	$t->notEmpty($sql->invokeWithArguments('trace_caller'));
	$t->notEmpty($sql->invokeWithArguments('trace_stack'));
	$workspace=$t->workspace('dataphyre-sql-trace');
	require_once $workspace->copy(__DIR__.'/fixtures/modules/flightdeck/sql_kernel_trace_proxy.php','modules/flightdeck/sql_kernel_trace_proxy.php');
	require_once __DIR__.'/fixtures/modules/sql/sql_kernel_trace_proxy.php';
	require_once __DIR__.'/fixtures/sql_kernel_trace_recursive_proxy.php';
	$t->notEmpty(dp_sql_kernel_flightdeck_trace_caller($sql));
	$t->notEmpty(dp_sql_kernel_flightdeck_trace_stack($sql));
	$t->notEmpty(dp_sql_kernel_internal_trace_caller(25,$sql));
	$t->same(8,count(dp_sql_kernel_recursive_trace_stack(12,$sql)));
	\dataphyre\sql::clear_observers();
	$sql->invokeWithArguments('emit_observer_event',[['event'=>'ignored']]);
	$t->count(1,$events);
})->tag('sql','kernel','main','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql kernel main core deep coverage classifies writes tables assertions and server state',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$session=$scenario->session;
	$sql=$scenario->sql;
	$t->isTrue(\dataphyre\sql::query_has_write(' /* lead */ -- comment'."\n".'WITH rows AS (SELECT 1) UPDATE users SET active=1'));
	$t->isTrue(\dataphyre\sql::query_has_write('WITH changed AS (UPDATE users SET active=1 RETURNING *) SELECT * FROM changed'));
	$t->isFalse(\dataphyre\sql::query_has_write('WITH rows AS (SELECT 1) SELECT * FROM rows'));
	$t->isTrue(\dataphyre\sql::query_has_write('PRAGMA table_info(users)'));
	$t->isFalse(\dataphyre\sql::query_has_write('SELECT * FROM users'));
	$dbms='';
	$t->same('users',\dataphyre\sql::table('sqlite:users',$dbms));
	$t->same('sqlite',$dbms);
	$t->same('users',\dataphyre\sql::table('users',$dbms));
	$t->same(null,$dbms);
	$t->same('value',\dataphyre\sql::assert('value','message'));
	$t->throws(static fn()=>\dataphyre\sql::assert(false,'failed'),RuntimeException::class);
	$browserSessionOutages=[
		'browser-poison'=>microtime(true),
		'legacy-old'=>'2000-01-01 00:00:00',
	];
	$session->put('unavailable_servers',$browserSessionOutages);
	$t->isTrue(\dataphyre\sql::is_server_available('browser-poison'));
	$t->isTrue(\dataphyre\sql::flag_server_unavailable('request-local'));
	$t->isFalse(\dataphyre\sql::is_server_available('request-local'));
	$t->same($browserSessionOutages,$session->get('unavailable_servers'));
	$sql->replacePropertyForTest('unavailable_servers',[]);
	$t->isTrue(\dataphyre\sql::is_server_available('request-local'));
	$sql->replacePropertyForTest('unavailable_servers',['expired'=>microtime(true)-6.0]);
	$t->isTrue(\dataphyre\sql::is_server_available('expired'));
	$t->same([],$sql->readProperty('unavailable_servers'));
	$t->isTrue(\dataphyre\sql::is_server_available('unknown'));
	dp_sql_kernel_dialback($state,'CALL_SQL_FLAG_SERVER_UNAVAILABLE',false);
	$t->isFalse(\dataphyre\sql::flag_server_unavailable('dialback'));
	dp_sql_kernel_dialback($state,'CALL_SQL_IS_SERVER_AVAILABLE',true);
	$t->isTrue(\dataphyre\sql::is_server_available('dialback'));
})->tag('sql','kernel','main','deep-coverage')->group('framework-coverage');

test('sql kernel main core deep coverage prunes session cache across limits expiry and memory pressure',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$session=$scenario->session;
	\dataphyre\sql::session_cache_gc();
	$t->same([],$session->get('db_cache'));
	$cache=[];
	for($index=0;$index<501;$index++){
		$cache['table_'.$index]=[['value',time()]];
	}
	$session->put('db_cache',$cache);
	\dataphyre\sql::session_cache_gc();
	$t->isTrue(count($session->get('db_cache'))<=500);
	$dense=[];
	for($index=0;$index<130;$index++){
		$dense['hash_'.$index]=[['id'=>$index],time()];
	}
	$dense['expired']=[['old'=>true],time()-700];
	$session->put('db_cache',['dense'=>$dense]);
	\dataphyre\sql::session_cache_gc();
	$dense=$session->get('db_cache')['dense'];
	$t->isTrue(count($dense)<=128);
	$t->isFalse(isset($dense['expired']));
	$t->isTrue($session->get('db_cache_count',0)>=0);
	$state->put('memory_pressure_padding',str_repeat('x',13*1024*1024));
	$session->put('db_cache',['memory'=>[
		'old'=>[['id'=>1],time()-10],
		'new'=>[['id'=>2],time()],
	]]);
	\dataphyre\sql::session_cache_gc();
	$state->forget('memory_pressure_padding');
	$t->isTrue(count($session->get('db_cache')['memory'])<2);
})->tag('sql','kernel','main','cache','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql kernel main core deep coverage reads stores and invalidates every cache policy',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$session=$scenario->session;
	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	$default=\dataphyre\sql::get_table_cache_policy('ordinary');
	$t->same('session',$default['type']);
	$t->same(false,\dataphyre\sql::get_table_cache_policy('no_cache'));
	$t->same('30 minute',\dataphyre\sql::get_table_cache_policy('session_override')['max_lifespan']);
	$t->same('fs',\dataphyre\sql::get_table_cache_policy('fs_override')['type']);
	dp_sql_kernel_dialback($state,'CALL_SQL_GET_TABLE_CACHE_POLICY',['type'=>'fs','max_lifespan'=>'1 hour','hash_type'=>'md5']);
	$t->same('fs',\dataphyre\sql::get_table_cache_policy('dialback')['type']);
	dp_sql_kernel_forget_dialback($state,'CALL_SQL_GET_TABLE_CACHE_POLICY');

	$sessionPolicy=['type'=>'session','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	dp_sql_kernel_cache_entry($session,'items','hit',['id'=>1]);
	$t->same(['id'=>1],\dataphyre\sql::get_query_cached_result('items','hit',$sessionPolicy));
	dp_sql_kernel_cache_entry($session,'items','expired',['id'=>2],time()-120);
	$t->same(null,\dataphyre\sql::get_query_cached_result('items','expired',$sessionPolicy));
	$t->same(null,\dataphyre\sql::get_query_cached_result('items','missing',$sessionPolicy));
	$t->same(null,\dataphyre\sql::get_query_cached_result('items','disabled',false));
	$t->same(null,\dataphyre\sql::get_query_cached_result('no_cache','default-policy'));

	$sharedPolicy=['type'=>'shared_cache','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	\dataphyre\cache::$values['table_version_shared']=2;
	\dataphyre\cache::$values['shared_match']=[2,['id'=>3]];
	$t->same(['id'=>3],\dataphyre\sql::get_query_cached_result('shared','match',$sharedPolicy));
	\dataphyre\cache::$values['shared_false']=[2,'false'];
	$t->same(false,\dataphyre\sql::get_query_cached_result('shared','false',$sharedPolicy));
	\dataphyre\cache::$values['shared_old']=[1,['id'=>4]];
	$t->same(null,\dataphyre\sql::get_query_cached_result('shared','old',$sharedPolicy));
	\dataphyre\cache::$values['shared_racing']=[2,['id'=>5]];
	\dataphyre\cache::$getCallbacks['shared_racing']=static function(): void {
		\dataphyre\cache::$values['table_version_shared']=3;
	};
	$t->same(null,\dataphyre\sql::get_query_cached_result('shared','racing',$sharedPolicy));
	unset(\dataphyre\cache::$getCallbacks['shared_racing']);
	$t->same(null,\dataphyre\sql::get_query_cached_result('shared','none',$sharedPolicy));
	$state->put('modules',['cache'=>false]);
	$t->same(null,\dataphyre\sql::get_query_cached_result('shared','absent',$sharedPolicy));
	$t->same([],$state->get('unavailable'));
	$state->put('modules',['cache'=>true]);
	\dataphyre\cache::$shared=false;
	$t->same(null,\dataphyre\sql::get_query_cached_result('shared','backend-down',$sharedPolicy));
	$t->isFalse(\dataphyre\sql::cache_query_result('shared','backend-down',['id'=>1],[true],$sharedPolicy));
	$t->isFalse(\dataphyre\sql::invalidate_cache('shared',$sharedPolicy));
	\dataphyre\cache::$shared=true;

	$fsPolicy=['type'=>'fs','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	$fsRoot=rtrim((string)ROOTPATH['sql_cache'],'/\\').'/dp_sql_kernel_fs';
	\dataphyre\core::file_put_contents_forced($fsRoot.'/fresh',json_encode([['id'=>5],time()],JSON_THROW_ON_ERROR));
	$t->same(['id'=>5],\dataphyre\sql::get_query_cached_result('dp_sql_kernel_fs','fresh',$fsPolicy));
	\dataphyre\core::file_put_contents_forced($fsRoot.'/expired',json_encode([['id'=>6],time()-120],JSON_THROW_ON_ERROR));
	$t->same(null,\dataphyre\sql::get_query_cached_result('dp_sql_kernel_fs','expired',$fsPolicy));
	$t->same(null,\dataphyre\sql::get_query_cached_result('dp_sql_kernel_fs','missing',$fsPolicy));

	$session->put('db_cache_count',0);
	$t->isFalse(\dataphyre\sql::cache_query_result('','hash',['id'=>1],[true],$sessionPolicy));
	$t->isFalse(\dataphyre\sql::cache_query_result('items','',['id'=>1],[true],$sessionPolicy));
	$t->isTrue(\dataphyre\sql::cache_query_result('items','stored',['id'=>7],[true,'group-a'],$sessionPolicy));
	$t->same(['id'=>7],dp_sql_kernel_cached_value($session,'items','stored'));
	$session->put('db_cache_count',3);
	$t->isTrue(\dataphyre\sql::cache_query_result('rolled','stored',['id'=>8],[true],$sessionPolicy));
	$t->isTrue(\dataphyre\sql::cache_query_result('shared','stored',false,['group-b'],$sharedPolicy));
	$t->same('false',\dataphyre\cache::$values['shared_stored'][1]);
	$t->isTrue(\dataphyre\sql::cache_query_result('dp_sql_kernel_fs','stored',['id'=>9],['group-c'],$fsPolicy));
	$t->isTrue(is_file($fsRoot.'/stored'));
	$t->isFalse(\dataphyre\sql::cache_query_result('items','unknown',['id'=>1],[true],['type'=>'unknown','max_lifespan'=>'1 minute','hash_type'=>'md5']));
	$t->isFalse(\dataphyre\sql::cache_query_result('items','disabled',['id'=>1],[true],false));
	$t->isTrue(\dataphyre\sql::cache_query_result('items','implicit',['id'=>12],[true]));

	$session->put('db_cache_count',1);
	dp_sql_kernel_cache_entry($session,'items','clear',['id'=>10]);
	$t->isTrue(\dataphyre\sql::invalidate_cache('items',$sessionPolicy));
	$t->isFalse(dp_sql_kernel_has_cache_entry($session,'items','clear'));
	$t->isTrue(\dataphyre\sql::invalidate_cache('shared',$sharedPolicy));
	$t->same(4,\dataphyre\cache::$values['table_version_shared']);
	\dataphyre\cache::$values['table_version_shared_override']=4;
	$t->isTrue(\dataphyre\sql::invalidate_cache(['shared_override']));
	$t->same(5,\dataphyre\cache::$values['table_version_shared_override']);
	$t->isTrue(\dataphyre\sql::invalidate_cache('dp_sql_kernel_fs',$fsPolicy));
	$t->isFalse(is_dir($fsRoot));
	$t->isFalse(\dataphyre\sql::invalidate_cache('items',['type'=>'unknown','max_lifespan'=>'1 minute','hash_type'=>'md5']));
	$t->isFalse(\dataphyre\sql::invalidate_cache(['items'],$sessionPolicy));

	$namedFsRoot=rtrim((string)ROOTPATH['sql_cache'],'/\\').'/named_fs';
	\dataphyre\core::file_put_contents_forced($namedFsRoot.'/hash-fs','payload');
	$session->put('db_cache_count',3);
	dp_sql_kernel_cache_entry($session,'named_session','hash-session',['id'=>11]);
	\dataphyre\cache::$values['named_shared_hash-shared']='payload';
	$session->put('db_cache_invalidation_index',['bundle'=>[
		['shared_cache','named_shared','hash-shared'],
		['session','named_session','hash-session'],
		['fs','named_fs','hash-fs'],
	]]);
	$t->isTrue(\dataphyre\sql::invalidate_cache(['bundle','empty']));
	$t->isFalse(isset(\dataphyre\cache::$values['named_shared_hash-shared']));
	$t->isFalse(dp_sql_kernel_has_cache_entry($session,'named_session','hash-session'));
	$t->isFalse(is_file($namedFsRoot.'/hash-fs'));
	$fallbackPath=$t->nonPublic(\dataphyre\sql::class)->invoke('filesystem_cache_path','fallback','hash','');
	$portableFallbackPath=$t->portablePath($fallbackPath);
	$t->contains('runtime/modules/sql/kernel/../../../cache/sql/fallback/hash',$portableFallbackPath);
	$t->notContains('runtime/modules/cache/sql',$portableFallbackPath);
	$t->isTrue(count($events)>=12);
	\dataphyre\core::force_rmdir($namedFsRoot);
})->sandboxesRootpath('sql_cache')->tag('sql','kernel','main','cache','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql kernel namespaces session shared filesystem and named caches by data environment',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$session=$scenario->session;
	$sessionPolicy=['type'=>'session','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	$sharedPolicy=['type'=>'shared_cache','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	$fsPolicy=['type'=>'fs','max_lifespan'=>'1 minute','hash_type'=>'md5'];
	$session->put('db_cache_count',0);
	$t->throws(
		static fn()=>DataEnvironment::push('sandbox',['cluster'=>'missing']),
		RuntimeException::class
	);
	$blankCluster=DataEnvironment::push('sandbox',['cluster'=>'']);
	$t->same(null,DataEnvironment::clusterOverride());
	DataEnvironment::pop($blankCluster);

	DataEnvironment::run('sandbox', static function() use ($t,$session,$sessionPolicy,$sharedPolicy,$fsPolicy): void {
		$t->same('main',\dataphyre\sql::resolve_cluster('fallback'));
		$t->isTrue(\dataphyre\sql::cache_query_result('orders','session',['mode'=>'sandbox'],['orders-list'],$sessionPolicy));
		$t->same(['mode'=>'sandbox'],$session->get('db_cache',[])['sandbox::orders']['session'][0] ?? null);
		$t->same(['mode'=>'sandbox'],\dataphyre\sql::get_query_cached_result('orders','session',$sessionPolicy));

		$t->isTrue(\dataphyre\sql::cache_query_result('orders','shared',['mode'=>'sandbox'],[true],$sharedPolicy));
		$t->isTrue(isset(\dataphyre\cache::$values['sandbox::orders_shared']));
		$t->same(['mode'=>'sandbox'],\dataphyre\sql::get_query_cached_result('orders','shared',$sharedPolicy));

		$t->isTrue(\dataphyre\sql::cache_query_result('orders','fs',['mode'=>'sandbox'],[true],$fsPolicy));
		$path=rtrim((string)ROOTPATH['sql_cache'],'/\\').'/sandbox/orders/fs';
		$t->isTrue(is_file($path));
		$t->same(['mode'=>'sandbox'],\dataphyre\sql::get_query_cached_result('orders','fs',$fsPolicy));

		$t->isTrue(\dataphyre\sql::invalidate_cache(['orders-list']));
		$t->isFalse(isset($session->get('db_cache',[])['sandbox::orders']['session']));
		$t->isTrue(\dataphyre\sql::invalidate_cache('orders',$sharedPolicy));
		$t->same(1,\dataphyre\cache::$values['table_version_sandbox::orders'] ?? 0);
		$t->isTrue(\dataphyre\sql::invalidate_cache('orders',$fsPolicy));
		$t->isFalse(is_dir(dirname($path)));
	},['cluster'=>'main']);

	$t->isFalse(isset($session->get('db_cache',[])['orders']['session']));
	$t->isFalse(isset(\dataphyre\cache::$values['orders_shared']));
})->sandboxesRootpath('sql_cache')->tag('sql','kernel','cache','data-environment')->group('framework-coverage');

test('sql kernel main core deep coverage records and classifies driver error families',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$sql=$scenario->sql;
	\dataphyre\sql::clear_last_query_error();
	$t->same(null,\dataphyre\sql::last_query_error());
	$t->isFalse(\dataphyre\sql::last_query_failed_because_table_missing());
	$t->isFalse(\dataphyre\sql::last_query_failed_because_column_missing());
	$messages=[
		'Base table or view not found',
		"Table users doesn't exist",
		'undefined table users',
		'undefined_table users',
		'no such table: users',
		'relation "users" does not exist',
		'SQLSTATE[42S02] users',
		'SQLSTATE[42P01] users',
		'driver 1146 users',
		'driver 42S02 users',
		'driver 42P01 users',
	];
	foreach($messages as $message){
		\dataphyre\sql::log_query_error('sqlite','main','SELECT * FROM users',[],new RuntimeException($message));
		$t->isTrue(\dataphyre\sql::last_query_failed_because_table_missing());
	}
	$t->isTrue(\dataphyre\sql::last_query_failed_because_table_missing('users'));
	$t->isFalse(\dataphyre\sql::last_query_failed_because_table_missing('orders'));
	$t->isTrue(\dataphyre\sql::last_query_failed_because_table_missing(' '));

	$columnMessages=[
		"Unknown column 'users.name'",
		'undefined column name',
		'undefined_column name',
		'no such column: users.name',
		'column "name" does not exist',
		'SQLSTATE[42S22] name',
		'SQLSTATE[42703] name',
		'driver 1054 name',
		'driver 42S22 name',
		'driver 42703 name',
	];
	foreach($columnMessages as $message){
		\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException($message));
		$t->isTrue(\dataphyre\sql::last_query_failed_because_column_missing());
	}
	$t->isTrue(\dataphyre\sql::last_query_failed_because_column_missing('users'));
	$t->isFalse(\dataphyre\sql::last_query_failed_because_column_missing('orders'));
	$t->isTrue(\dataphyre\sql::last_query_failed_because_column_missing(''));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException("Unknown column 'users.name'"));
	$t->same('name',$sql->invokeWithArguments('missing_column_from_last_query_error'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException('column "users.title" does not exist'));
	$t->same('title',$sql->invokeWithArguments('missing_column_from_last_query_error'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException('unrelated failure'));
	$t->isFalse(\dataphyre\sql::last_query_failed_because_table_missing());
	$t->isFalse(\dataphyre\sql::last_query_failed_because_column_missing());
	$t->same(null,$sql->invokeWithArguments('missing_column_from_last_query_error'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT 1',null,null);
	$t->same('Unknown error',\dataphyre\sql::last_query_error()['message']);
	$t->notEmpty($state->get('error_logs'));
})->tag('sql','kernel','main','errors','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('sql kernel main core deep coverage resolves definitions deferred manifests and hydration retries',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$sql=$scenario->sql;
	$arrayFile=__DIR__.'/fixtures/sql_kernel_table_definitions_array.php';
	$callableFile=__DIR__.'/fixtures/sql_kernel_table_definition_callable.php';
	$scalarFile=__DIR__.'/fixtures/sql_kernel_table_definition_scalar.php';
	$emptyFile=__DIR__.'/fixtures/sql_kernel_empty_definitions.php';
	$t->isFalse(\dataphyre\sql::define_table('users',''));
	$t->isTrue(\dataphyre\sql::define_table('users',$arrayFile,'users'));
	$t->instanceOf(Dataphyre\Database\TableDefinition::class,\dataphyre\sql::table_definition('users'));
	$t->instanceOf(Dataphyre\Database\TableSchema::class,\dataphyre\sql::table_schema('users'));
	$t->isTrue(\dataphyre\sql::define_table('callable_table',$callableFile));
	$t->instanceOf(Dataphyre\Database\TableDefinition::class,\dataphyre\sql::table_definition('callable_table'));
	$t->isTrue(\dataphyre\sql::define_table('candidate_table',$arrayFile,'callable'));
	$t->instanceOf(Dataphyre\Database\TableDefinition::class,\dataphyre\sql::table_definition('candidate_table'));
	$t->isTrue(\dataphyre\sql::define_table('scalar_table',$scalarFile));
	$t->same(null,\dataphyre\sql::table_definition('scalar_table'));
	$t->isTrue(\dataphyre\sql::define_table('empty_table',$emptyFile));
	$t->same(null,\dataphyre\sql::table_definition('empty_table'));
	$t->isTrue(\dataphyre\sql::define_table('missing_file',__DIR__.'/fixtures/absent_definition.php'));
	$t->same(null,\dataphyre\sql::table_definition('missing_file'));
	$t->same(null,\dataphyre\sql::table_definition('unregistered'));
	$state->put('framework_available',false);
	$t->same(null,\dataphyre\sql::table_definition('users'));
	$state->put('framework_available',true);
	$deferredDefinitions=$t->globalMap('dataphyre_deferred_sql_table_definitions');
	$deferredDefinitions->replace([
		static fn()=>\dataphyre\sql::define_table('deferred_table',$callableFile),
		'ignored',
	]);
	$t->instanceOf(Dataphyre\Database\TableDefinition::class,\dataphyre\sql::table_definition('deferred_table'));
	$t->same([],array_values($deferredDefinitions->map()));
	$t->same([
		'callable_table','candidate_table','deferred_table','empty_table','missing_file','scalar_table','users',
	],\dataphyre\sql::registered_table_definitions());
	$t->isTrue($sql->invokeWithArguments('register_runtime_table_definition',['dataphyre.sessions']));
	$t->isTrue($sql->invokeWithArguments('register_runtime_table_definition',['dataphyre.sessions']));
	$t->isFalse($sql->invokeWithArguments('register_runtime_table_definition',['not.in.manifest']));
	$t->same(null,$sql->invokeWithArguments('runtime_module_table_file',['missing/module.tables.php']));
	$runtimeManifest=$sql->invokeWithArguments('runtime_table_definition_manifest');
	$t->notEmpty($runtimeManifest);
	$t->hasKey('dataphyre.sentinel_events',$runtimeManifest);
	$t->same([
		'file'=>'sentinel/kernel/sentinel.tables.php',
		'definition_id'=>'events',
	],$runtimeManifest['dataphyre.sentinel_events']);
	$t->isFalse($sql->invokeWithArguments('register_runtime_table_definition',['missing_manifest_table',[
		'missing_manifest_table'=>['file'=>'missing/module.tables.php','definition_id'=>'missing'],
	]]));
	$t->same(null,$sql->invokeWithArguments('registered_table_from_last_query_error'));
	$t->same([],$sql->invokeWithArguments('last_query_table_candidates'));
	$t->same(null,$sql->invokeWithArguments('missing_column_from_last_query_error'));

	dp_sql_kernel_dialback($state,'CALL_SQL_SIMPLE_SELECT',true);
	$t->isTrue(\dataphyre\sql::hydrate_table_definition('users'));
	$t->isTrue(\dataphyre\sql::hydrate_missing_table_from_call('users','*',null,null)===false);
	\dataphyre\sql::log_query_error('sqlite','main','SELECT * FROM users',[],new RuntimeException('no such table: users'));
	$t->isTrue(\dataphyre\sql::hydrate_missing_table_from_definition('users'));
	$t->same(null,\dataphyre\sql::last_query_error());
	\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException("Unknown column 'users.name'"));
	$t->isTrue(\dataphyre\sql::hydrate_missing_structure_from_definition('users'));
	$t->same(null,\dataphyre\sql::last_query_error());
	\dataphyre\sql::log_query_error('sqlite','main','SELECT missing FROM users',[],new RuntimeException("Unknown column 'users.missing'"));
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition('users'));
	\dataphyre\sql::clear_last_query_error();
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition('users'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT * FROM unknown_runtime_table',[],new RuntimeException('no such table: unknown_runtime_table'));
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition(null));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT field FROM users',[],new RuntimeException('SQLSTATE[42703] undefined column field'));
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition('users'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT missing FROM unregistered_columns',[],new RuntimeException("Unknown column 'unregistered_columns.missing'"));
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition('unregistered_columns'));
	$t->isFalse(\dataphyre\sql::hydrate_table_definition('unregistered_table'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT * FROM users JOIN orders ON 1=1',[],new RuntimeException('no such table: users'));
	$t->same('users',$sql->invokeWithArguments('registered_table_from_last_query_error'));
	$t->notEmpty($sql->invokeWithArguments('last_query_table_candidates'));
	$sql->writeProperty('last_query_error',[
		'message'=>'SQLSTATE[42S02]: Base table `users` not found',
		'query'=>'SELECT * FROM [users]',
	]);
	$t->same('users',$sql->invokeWithArguments('registered_table_from_last_query_error'));

	\dataphyre\sql::log_query_error('sqlite','main','SELECT * FROM users',[],new RuntimeException('no such table: users'));
	$t->same('retried',$sql->invokeWithArguments('retry_operation_after_structure_hydration',['select','users',static fn(): string=>'retried']));
	$sql->writeProperty('structure_hydration_retrying',['select:users'=>true]);
	$t->isFalse($sql->invokeWithArguments('retry_operation_after_structure_hydration',['select','users',static fn(): string=>'not-run']));
	$sql->writeProperty('structure_hydration_retrying',[]);
	\dataphyre\sql::clear_last_query_error();
	$t->isFalse($sql->invokeWithArguments('retry_operation_after_structure_hydration',['select','users',static fn(): string=>'not-run']));

	dp_sql_kernel_dialback($state,'CALL_SQL_SIMPLE_SELECT',static function(): never {throw new RuntimeException('hydrate failure');});
	$t->isFalse(\dataphyre\sql::hydrate_table_definition('users'));
	\dataphyre\sql::log_query_error('sqlite','main','SELECT name FROM users',[],new RuntimeException("Unknown column 'users.name'"));
	$t->isFalse(\dataphyre\sql::hydrate_missing_structure_from_definition('users'));
	$t->notEmpty(\dataphyre\sql::last_query_error());
})->tag('sql','kernel','main','definitions','deep-coverage')->group('framework-coverage')->maxMillis(15000);

test('sql kernel main core deep coverage executes shutdown cache wrapper success and failure handling',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$session=$scenario->session;
	$sql=$scenario->sql;
	$session->put('db_cache',[]);
	$sql->invokeWithArguments('session_cache_shutdown');
	$t->same([],$session->get('db_cache'));
	$session->put('db_cache',['broken'=>'not-an-array']);
	$sql->invokeWithArguments('session_cache_shutdown');
	$t->same([],$state->get('shutdown_logs'));
	$t->same([],$session->get('db_cache'));
})->tag('sql','kernel','main','shutdown','deep-coverage')->group('framework-coverage');

test('sql kernel transaction controls bypass the raw query cache on every cycle',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$controls=[];
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_SELECT',static function(mixed ...$arguments)use(&$controls): bool {
		$controls[]=$arguments;
		return true;
	});
	$t->isTrue(\dataphyre\sql::transaction(static fn(): bool=>true));
	$t->isTrue(\dataphyre\sql::transaction(static fn(): bool=>true));
	$t->isTrue(\dataphyre\sql::begin('primary'));
	$t->isTrue(\dataphyre\sql::rollback('primary'));
	$t->same(6,count($controls));
	foreach($controls as $arguments){
		$t->same(false,$arguments[4]??null);
	}
	$t->same(
		['BEGIN TRANSACTION','COMMIT','BEGIN TRANSACTION','COMMIT','BEGIN TRANSACTION','ROLLBACK'],
		array_map(static fn(array $arguments): string=>(string)($arguments[0]['sqlite']??''),$controls),
	);
})->tag('sql','kernel','transaction','cache','regression')->group('framework-coverage');

test('sql kernel extracts registered raw mutation targets for cache invalidation',static function(Context $t): void {
	dp_sql_kernel_scenario($t);
	$t->same(
		['shared_override','no_cache','fs_override'],
		\dataphyre\sql::query_write_targets(<<<'SQL'
WITH changed AS (
	UPDATE "shared_override" SET value='DELETE FROM fs_override' RETURNING id
)
INSERT OR IGNORE INTO `no_cache` (id) SELECT id FROM changed
ON CONFLICT (id) DO UPDATE SET id=EXCLUDED.id;
DELETE FROM [fs_override] WHERE id=1;
-- UPDATE session_override SET value=1
SQL, true)
	);
	$t->same(['serve.orders'],\dataphyre\sql::query_write_targets('MERGE INTO "serve"."orders" target USING source ON true WHEN MATCHED THEN UPDATE SET status=1'));
	$t->same([],\dataphyre\sql::query_write_targets('SELECT \'INSERT INTO shared_override\'',true));
})->tag('sql','kernel','raw','cache','invalidation','regression')->group('framework-coverage');

test('sql kernel defers invalidation and bypasses read caching while a framework transaction is active',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$transaction=new Transaction('primary');
	$t->nonPublic($transaction)->writeProperty('active',true);
	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeTransactions',[$transaction]);

	$t->same(false,$scenario->sql->invokeWithArguments('transaction_read_caching',[[true,'orders.summary']]));
	$t->same('primary',$scenario->sql->invokeWithArguments('resolve_query_cluster',['analytics']));
	$t->isTrue(\dataphyre\sql::invalidate_cache(['orders.summary']));
	$pending=$t->nonPublic($transaction)->readProperty('pendingCacheInvalidations');
	$t->same(1,count($pending));
	$t->same(['orders.summary'],array_values($pending)[0]['target'] ?? null);

	$t->nonPublic(Transaction::class)->replacePropertyForTest('activeTransactions',[]);
	$t->same(['orders.summary'],$scenario->sql->invokeWithArguments('transaction_read_caching',[['orders.summary']]));
})->tag('sql','kernel','transaction','cache','invalidation','regression')->group('framework-coverage');

test('transaction commit safely invalidates mixed cached and uncached registered tables',static function(Context $t): void {
	$scenario=dp_sql_kernel_scenario($t);
	$state=$scenario->fixture;
	$session=$scenario->session;
	dp_sql_kernel_dialback($state,'CALL_SQL_DB_SELECT',static fn(): bool=>true);

	\dataphyre\cache::$values['table_version_shared_override']=8;
	$session->put('db_cache_count',2);
	dp_sql_kernel_cache_entry($session,'named_cached','cached-hash',['id'=>1]);
	dp_sql_kernel_cache_entry($session,'named_uncached','uncached-hash',['id'=>2]);
	$session->put('db_cache_invalidation_index',[
		'shared_override'=>[['session','named_cached','cached-hash']],
		'no_cache'=>[['session','named_uncached','uncached-hash']],
	]);

	$warnings=[];
	set_error_handler(static function(int $severity,string $message)use(&$warnings): bool {
		if(($severity & (E_WARNING|E_NOTICE|E_USER_WARNING|E_USER_NOTICE))===0){
			return false;
		}
		$warnings[]=$message;
		return true;
	});
	$transaction=new Transaction('primary');
	try{
		$transaction->begin();
		$t->isTrue(\dataphyre\sql::invalidate_cache(['shared_override','no_cache']));
		$t->same(8,\dataphyre\cache::$values['table_version_shared_override']);
		$t->isTrue(dp_sql_kernel_has_cache_entry($session,'named_cached','cached-hash'));
		$t->isTrue(dp_sql_kernel_has_cache_entry($session,'named_uncached','uncached-hash'));
		$transaction->commit();

		$t->same(9,\dataphyre\cache::$values['table_version_shared_override']);
		$t->isFalse(dp_sql_kernel_has_cache_entry($session,'named_cached','cached-hash'));
		$t->isFalse(dp_sql_kernel_has_cache_entry($session,'named_uncached','uncached-hash'));
		$t->same([],$session->get('db_cache_invalidation_index',[]));
		$t->same(0,$session->get('db_cache_count'));

		$session->put('db_cache_count',1);
		dp_sql_kernel_cache_entry($session,'named_uncached','direct-hash',['id'=>3]);
		$session->put('db_cache_invalidation_index',[
			'no_cache'=>[['session','named_uncached','direct-hash']],
		]);
		$t->isTrue(\dataphyre\sql::invalidate_cache('no_cache',false));
		$t->isFalse(dp_sql_kernel_has_cache_entry($session,'named_uncached','direct-hash'));
		$t->same([],$session->get('db_cache_invalidation_index',[]));
		$t->same(0,$session->get('db_cache_count'));
	}finally{
		if($transaction->isActive()){
			$transaction->rollback();
		}
		restore_error_handler();
	}
	$t->same([],$warnings);
})->tag('sql','kernel','transaction','cache','invalidation','uncached','regression')->group('framework-coverage');
