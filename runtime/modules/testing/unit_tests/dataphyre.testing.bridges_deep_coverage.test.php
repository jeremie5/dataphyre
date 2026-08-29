<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\DataphyreModuleBridge;
use Dataphyre\Test\DataphyreMvcTestHarness;
use Dataphyre\Test\DataphyreSqlKernelHarness;
use Dataphyre\Test\StorageEventRecorder;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!function_exists('Dataphyre\Test\class_exists')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Test;
final class DpTestKitBridgeSeams {
	private const CHANNEL='testing.bridges';
	private static function state(): ?TestState {
		return TestState::channelIfActive(self::CHANNEL);
	}
	public static function missingClass(string $class): bool {
		return in_array($class, self::state()?->get('missing_classes', []) ?? [], true);
	}
	public static function has(string $key): bool {
		return self::state()?->has($key) ?? false;
	}
	public static function value(string $key, mixed $default=null): mixed {
		return self::state()?->get($key, $default) ?? $default;
	}
	public static function recordDefinition(string $constant, mixed $value): void {
		$state=self::state();
		$definitions=$state?->get('definitions', []) ?? [];
		$definitions[$constant]=$value;
		$state?->put('definitions', $definitions);
	}
}
function class_exists(string $class,bool $autoload=true): bool {
	$class=ltrim($class,'\\');
	if(DpTestKitBridgeSeams::missingClass($class)){
		return false;
	}
	if($class==='SQLite3' && DpTestKitBridgeSeams::has('sqlite_class')){
		return (bool)DpTestKitBridgeSeams::value('sqlite_class');
	}
	return \class_exists($class,$autoload);
}
function extension_loaded(string $extension): bool {
	if($extension==='sqlite3' && DpTestKitBridgeSeams::has('sqlite_extension')){
		return (bool)DpTestKitBridgeSeams::value('sqlite_extension');
	}
	return \extension_loaded($extension);
}
function defined(string $constant): bool {
	if(in_array($constant,DpTestKitBridgeSeams::value('undefined_constants',[]),true)){
		return false;
	}
	return \defined($constant);
}
function define(string $constant,mixed $value,bool $case_insensitive=false): bool {
	if(in_array($constant,DpTestKitBridgeSeams::value('undefined_constants',[]),true)){
		DpTestKitBridgeSeams::recordDefinition($constant,$value);
		return true;
	}
	return \define($constant,$value,$case_insensitive);
}
PHP);
}

function dp_testkit_bridge_runtime(): string {
	return rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\');
}

suite('Framework bridge and harness contracts')
	->contract('testing.framework-bridges', 1)
	->layer('integration')
	->risk('high')
	->watches('module:testing', 'module:storage', 'module:permission', 'module:sql', 'module:mvc', 'module:reactor')
	->through('module-bridge', 'framework-loader', 'test-harness')
	->isolation('case')
	->tag('testing', 'bridges')
	->group('framework-coverage');

test('testing module bridge reports missing storage permission sql mvc and reactor frameworks',static function(Context $t): void {
	$seams=$t->state('testing.bridges');
	$bridge=new DataphyreModuleBridge($t->workspace('testing-bridge-missing')->path('runtime'));
	$cases=[
		['Dataphyre\Storage\StorageManager',static fn()=>$bridge->storage()],
		['Dataphyre\Permission\Permission',static fn()=>$bridge->permission()],
		['Dataphyre\Database\QuerySpec','Dataphyre\Database\TableSchema','Dataphyre\Database\TableDefinition',static fn()=>$bridge->sqlFramework()],
		['Dataphyre\Mvc\Mvc','Dataphyre\Http\Request','Dataphyre\Http\Response',static fn()=>$bridge->mvc()],
		['Dataphyre\Reactor\Reactor',static fn()=>$bridge->reactor()],
	];
	foreach($cases as $case){
		$callback=array_pop($case);
		$seams->put('missing_classes',$case);
		$t->throws($callback,RuntimeException::class);
	}
	$seams->put('missing_classes',[])->put('sqlite_extension',false)->put('sqlite_class',false);
	$t->throws(static fn()=>$bridge->sqlKernel(),RuntimeException::class);
	$seams->forget('sqlite_extension')->forget('sqlite_class');

	$plainManager=new stdClass();
	$t->instanceOf(StorageEventRecorder::class,$bridge->storageEvents($plainManager));
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing module bridge reload guards exercise real storage permission sql and reactor modules',static function(Context $t): void {
	$bridge=new DataphyreModuleBridge(dp_testkit_bridge_runtime());
	$private=$t->nonPublic($bridge);
	$storage=$bridge->storage();
	$t->notSame($storage,$bridge->storage());
	$recorder=$bridge->storageEvents($storage);
	$storage->put('coverage.txt','ok');
	$t->notEmpty($recorder->events());

	$permission=$bridge->permission(['roles'=>['tester'=>['coverage.view']]]);
	$t->same($permission,$bridge->permission());
	$sql=$bridge->sqlFramework();
	$t->same('Dataphyre\Test\DataphyreSqlFrameworkBridge',$sql::class);
	$t->same($sql::class,$bridge->sqlFramework()::class);
	$reactor=$bridge->reactor();
	$t->isTrue(is_object($reactor));
	$t->isTrue(is_object($bridge->reactor()));

	$private->invoke('loadStorage');
	$private->invoke('loadPermission');
	$private->invoke('loadSqlFramework');
	$private->invoke('loadReactor');
	$t->same(rtrim(str_replace('\\','/',dirname(dp_testkit_bridge_runtime(),3)),'/'),$private->invoke('projectRoot'));
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing mvc bridge and harness cover loader autoload config dispatch app and json errors',static function(Context $t): void {
	$bridge=new DataphyreModuleBridge(dp_testkit_bridge_runtime());
	$private=$t->nonPublic($bridge);
	$harness=$bridge->mvc();
	$t->instanceOf(DataphyreMvcTestHarness::class,$harness);
	$t->instanceOf(DataphyreMvcTestHarness::class,$bridge->mvc());
	$private->invoke('loadMvcFramework');
	$private->invoke('loadHttpFramework');
	$private->invoke('loadRoutingFramework');
	$private->invoke('loadTemplatingResponseTypes');
	$bridgeSource=(string)file_get_contents(dirname(__DIR__).'/tooling/TestKit/DataphyreModuleBridge.php');
	$t->contains("'ThrottleStore.php'",$bridgeSource);
	$t->contains("'SharedCacheThrottleStore.php'",$bridgeSource);
	$t->contains("'LocalThrottleStore.php'",$bridgeSource);
	$t->isTrue(interface_exists('Dataphyre\\Mvc\\ThrottleStore',false));
	$t->isTrue(class_exists('Dataphyre\\Mvc\\SharedCacheThrottleStore',false));
	$t->isTrue(class_exists('Dataphyre\\Mvc\\LocalThrottleStore',false));
	$t->isTrue(class_exists('Dataphyre\\Mvc\\ThrottleMiddleware',false));
	$t->instanceOf('Dataphyre\\Mvc\\ThrottleStore',new \Dataphyre\Mvc\LocalThrottleStore());
	$t->instanceOf('Dataphyre\\Mvc\\SharedCacheThrottleStore',new \Dataphyre\Mvc\SharedCacheThrottleStore());
	$t->instanceOf('Dataphyre\\Mvc\\ThrottleMiddleware',new \Dataphyre\Mvc\ThrottleMiddleware());

	$workspace=$t->workspace('testing-bridge-mvc');
	$base=$workspace->root();
	$workspace->file('Demo/Loaded.php','<?php namespace Coverage\Demo; final class Loaded {}');
	$harness->autoload('Coverage',$base);
	$t->isFalse(class_exists('Other\Ignored'));
	$t->isTrue(class_exists('Coverage\Demo\Loaded'));

	$t->throws(static fn()=>$harness->registerFromConfig('missing',$base.'/missing.php'),RuntimeException::class);
	$workspace->file('invalid.php','<?php return "invalid";');
	$t->throws(static fn()=>$harness->registerFromConfig('invalid',$base.'/invalid.php'),RuntimeException::class);
	$workspace->file('valid.php','<?php return ["base_path"=>"/coverage","routes"=>[]];');
	$harness->registerFromConfig('coverage',$base.'/valid.php',['manifest_cache'=>true]);
	$t->isTrue(is_object($harness->app('coverage')));

	$t->throws(static fn()=>$harness->json(new stdClass()),InvalidArgumentException::class);
	$invalidResponse=new \Dataphyre\Http\Response('not-json',200,[]);
	$t->throws(static fn()=>$harness->json($invalidResponse),RuntimeException::class);
	$validResponse=new \Dataphyre\Http\Response(json_encode(['ok'=>true]),200,[]);
	$t->same(['ok'=>true],$harness->json($validResponse));
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing mvc harness stub covers register config app dispatch request options and autoload misses',static function(Context $t): void {
	if(!class_exists('Coverage\StubMvcMarker',false)){
		\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Coverage { final class StubMvcMarker {} }
namespace Dataphyre\Http {
	class Request {
		public static function create(mixed ...$arguments): object { return (object)['arguments'=>$arguments]; }
	}
	class Response {
		public function __construct(public int $status=200,public array $headers=[],public string $body='') {}
	}
}
namespace dataphyre\routing { class compiled_route_dispatcher {} }
namespace Dataphyre\Mvc {
	class Mvc {
		public static array $registered=[];
		public static function flush(): void { self::$registered=[]; }
		public static function register(string $name,array $config): void { self::$registered[$name]=$config; }
		public static function app(string $name): object { return (object)['name'=>$name,'config'=>self::$registered[$name] ?? []]; }
		public static function host(string $name): object {
			return new class($name) {
				public function __construct(private string $name) {}
				public function dispatch(object $request): object { return (object)['app'=>$this->name,'request'=>$request]; }
			};
		}
	}
}
PHP);
	}
	$workspace=$t->workspace('testing-bridge-mvc-stub');
	$bridge=new DataphyreModuleBridge($workspace->path('stub-runtime'));
	$harness=$bridge->mvc();
	$harness->register('stub',['routes'=>[]]);
	$t->same(false,\Dataphyre\Mvc\Mvc::$registered['stub']['manifest_cache']);

	$base=$workspace->directory('config');
	$workspace->file('config/config.php','<?php return ["routes"=>[],"manifest_cache"=>true];');
	$harness->registerFromConfig('from-config',$base.'/config.php');
	$t->same(false,\Dataphyre\Mvc\Mvc::$registered['from-config']['manifest_cache']);
	$app=$harness->app('stub');
	$t->same('stub',$app->name);
	$response=$harness->dispatch('stub','POST','/items',[
		'query'=>['q'=>'x'],'body'=>['id'=>1],'cookies'=>['sid'=>'x'],'server'=>['HTTPS'=>'on'],
		'headers'=>['X-Test'=>'yes'],'route_parameters'=>['id'=>'1'],'attributes'=>['tenant'=>'a'],'files'=>['upload'=>'x'],
	]);
	$t->same('stub',$response->app);
	$t->same('POST',$response->request->arguments[0]);
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing sql kernel bridge and harness cover stubbed extension operations conflicts and stubs',static function(Context $t): void {
	$seams=$t->state('testing.bridges',['sqlite_extension'=>true,'sqlite_class'=>true]);
	if(!class_exists('dataphyre\sql',false)){
		\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre {
	class sql { public static function last_query_error(): ?array { return ['code'=>'stub']; } }
}
namespace {
	function sql_query(mixed ...$args): array { return ['query'=>$args]; }
	function sql_insert(mixed ...$args): int { return 1; }
	function sql_select(mixed ...$args): array { return [['id'=>1]]; }
	function sql_count(mixed ...$args): int { return 2; }
	function sql_update(mixed ...$args): int { return 3; }
	function sql_delete(mixed ...$args): int { return 4; }
}
PHP);
	}
	$workspace=$t->workspace('testing-bridge-sql-stub');
	$base=$workspace->root();
	$path=$workspace->path('nested/kernel.sqlite');
	$bridge=new DataphyreModuleBridge($workspace->path('stub-runtime'));
	$private=$t->nonPublic($bridge);
	$harness=$bridge->sqlKernel($path);
	$t->instanceOf(DataphyreSqlKernelHarness::class,$harness);
	$t->same(str_replace('\\','/',$path),$harness->databasePath());
	$t->isTrue($harness->createTable('create table x'));
	$t->same(1,$harness->insert('items',['id'=>1]));
	$t->same([['id'=>1]],$harness->select('*','items'));
	$t->same(2,$harness->count('items'));
	$t->same(3,$harness->update('items',['name'=>'x']));
	$t->same(4,$harness->delete('items'));
	$t->same(['code'=>'stub'],$harness->lastError());

	if(!defined('DP_CORE_CFG')){
		define('DP_CORE_CFG',['datacenter'=>'test']);
	}
	if(!defined('DP_SQL_CFG')){
		define('DP_SQL_CFG',['datacenters'=>['test'=>['dbms_clusters'=>['sql'=>['database_name'=>'one.sqlite']]]]]);
	}
	$t->throws(static fn()=>$private->invoke('loadSqlKernel','two.sqlite'),RuntimeException::class);

	$blocked=$workspace->file('blocked','file');
	$t->throws(static fn()=>$bridge->sqlKernel($blocked.'/kernel.sqlite'),RuntimeException::class);
	$seams->forget('sqlite_extension')->forget('sqlite_class');
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing sql kernel stub definition covers missing entry constants paths and compatibility shims',static function(Context $t): void {
	$workspace=$t->workspace('testing-bridge-kernel-missing');
	$base=$workspace->root();
	$bridge=new DataphyreModuleBridge($base);
	$private=$t->nonPublic($bridge);
	$seams=$t->state('testing.bridges',['undefined_constants'=>['ROOTPATH','RUN_MODE'],'definitions'=>[]]);
	$private->invoke('defineSqlKernelTestStubs',$base.'/db.sqlite');
	$t->hasKey('ROOTPATH',$seams->get('definitions'));
	$t->hasKey('RUN_MODE',$seams->get('definitions'));
	$t->throws(static fn()=>$private->invoke('loadSqlKernel',$base.'/db.sqlite'),RuntimeException::class);
	$seams->put('undefined_constants',[]);
})->tag('testing','bridge','coverage')->group('framework-coverage');

test('testing sql kernel loader requires the real kernel entry and initializes invalid session state',static function(Context $t): void {
	$workspace=$t->workspace('testing-bridge-kernel-real');
	$database=$workspace->path('kernel.sqlite');
	$session=$t->global('_SESSION')->replace('invalid-session-state');
	$bridge=new DataphyreModuleBridge(dp_testkit_bridge_runtime());
	$t->nonPublic($bridge)->invoke('loadSqlKernel',$database);
	$t->isTrue(class_exists('dataphyre\sql',false));
	$t->same([],$session->value());
})->tag('testing','bridge','coverage')->group('framework-coverage');
