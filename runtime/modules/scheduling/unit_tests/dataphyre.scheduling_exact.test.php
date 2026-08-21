<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\define_test_symbols;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\SchedulingRuntimeProbe',false)){
	define_test_symbols(<<<'PHP'
namespace dataphyre {
final class SchedulingRuntimeProbe {
	public static array $traces=[];
	public static array $writes=[];
	public static array $write_results=[];
	public static array $curl=[];
	public static bool $curl_throws=false;
	public static string|false $curl_result='';
	public static int $curl_status=204;
	public static int $curl_delay_microseconds=0;
	public static array $shutdown=[];
	public static mixed $app_override='';
	public static array $modules=[];
	public static array $pre_init=[];
	public static array $sql_config=[];
	public static function reset(): void {
		self::$traces=[]; self::$writes=[]; self::$write_results=[]; self::$curl=[];
		self::$curl_throws=false; self::$curl_result=''; self::$curl_status=204;
		self::$curl_delay_microseconds=0;
		self::$shutdown=[]; self::$app_override='';
		self::$modules=[]; self::$pre_init=[]; self::$sql_config=[];
	}
}
function tracelog(mixed ...$arguments): void { SchedulingRuntimeProbe::$traces[]=$arguments; }
function curl_init(): object {
	if(SchedulingRuntimeProbe::$curl_throws){throw new \RuntimeException('curl failed');}
	SchedulingRuntimeProbe::$curl[]=['init']; return (object)[];
}
function curl_setopt(object $handle,int $option,mixed $value): bool { SchedulingRuntimeProbe::$curl[]=['setopt',$option,$value]; return true; }
function curl_exec(object $handle): string|false {
	SchedulingRuntimeProbe::$curl[]=['exec'];
	if(SchedulingRuntimeProbe::$curl_delay_microseconds>0){usleep(SchedulingRuntimeProbe::$curl_delay_microseconds);}
	return SchedulingRuntimeProbe::$curl_result;
}
function curl_getinfo(object $handle,int $option): int { SchedulingRuntimeProbe::$curl[]=['getinfo',$option]; return SchedulingRuntimeProbe::$curl_status; }
function curl_close(object $handle): void { SchedulingRuntimeProbe::$curl[]=['close']; }
if(!class_exists(core::class,false)){
	final class core {
		public static int $server_load_level=0;
		public static function get_server_load_level(): void {}
		public static function file_put_contents_forced(string $path,string $contents): int|false {
			SchedulingRuntimeProbe::$writes[]=[$path,$contents];
			$result=SchedulingRuntimeProbe::$write_results!==[] ? array_shift(SchedulingRuntimeProbe::$write_results) : true;
			if($result!==true){return false;}
			$parent=dirname($path);
			if(!is_dir($parent)){mkdir($parent,0775,true);}
			return file_put_contents($path,$contents);
		}
		public static function app_override_request_value(string $value): mixed { return SchedulingRuntimeProbe::$app_override; }
		public static function unavailable(mixed ...$arguments): void { SchedulingRuntimeProbe::$traces[]=['unavailable',$arguments]; }
		public static function dialback(mixed ...$arguments): void { SchedulingRuntimeProbe::$traces[]=['dialback',$arguments]; }
	}
}
final class routing { public static array $bindings=[]; }
final class tracelog {
	public static bool $enable=false;
	public static string $tracelog='<trace>scheduler</trace>';
}
}
namespace {
	function dataphyre_shutdown_log(string $message,\Throwable $failure): void {
		\dataphyre\SchedulingRuntimeProbe::$shutdown[]=[$message,$failure->getMessage()];
	}
	function dp_module_present(string $module): bool { return in_array($module,\dataphyre\SchedulingRuntimeProbe::$modules,true); }
	function dp_define_module_config(string $module,string $constant): void {
		if(!defined($constant)){define($constant,\dataphyre\SchedulingRuntimeProbe::$sql_config);}
	}
	function pre_init_error(string $message,?\Throwable $failure=null): void {
		\dataphyre\SchedulingRuntimeProbe::$pre_init[]=[$message,$failure?->getMessage()];
	}
}
PHP);
}

if(!defined('APP')){define('APP','test_app');}
if(!defined('IS_PRODUCTION')){define('IS_PRODUCTION',false);}
if(!defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')){define('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH',true);}
require_once dirname(__DIR__).'/kernel/scheduling.main.php';
require_once dirname(__DIR__).'/kernel/task_runner.php';

suite('Scheduling exact runtime behavior')
	->tag('scheduling','runtime','filesystem','coverage')
	->group('framework-coverage')
	->contract('scheduling.runtime.exact',1)
	->layer('integration')
	->risk('critical')
	->watches('module:scheduling')
	->through('validated definitions','frequency locks','bounded dispatch','task-runner state')
	->isolation('case');

test('fixed managed pool role alone owns scheduler activation and ordinary bootstrap stays unconditional',static function(Context $t): void {
	\dataphyre\scheduling::use_activation_mode(null);
	$t->environment([
		'DATAPHYRE_SCHEDULER_ACTIVATION_MODE'=>null,
		'DATAPHYRE_RUNTIME_POOL_ROLE'=>'web',
	]);
	$t->same('record_only',\dataphyre\scheduling::activation_mode());
	$t->isFalse(\dataphyre\scheduling::dispatch_enabled());
	$workspace=$t->workspace('managed-web-registration');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/run.php','<?php return true;');
	$shutdownRegistrations=0;
	$t->isTrue(\dataphyre\scheduling::run(
		'managed.web',$task,0,30,'128M',[],'test-app',
		static function() use (&$shutdownRegistrations): void {$shutdownRegistrations++;},
	));
	$t->same(0,$shutdownRegistrations);
	$t->isTrue(is_file(\dataphyre\scheduling::scheduler_properties_file('managed.web')));
	$t->isFalse(is_file(\dataphyre\scheduling::running_lock_file('managed.web')));
	$t->isFalse(is_file(\dataphyre\scheduling::last_run_file('managed.web')));
	$t->environment(['DATAPHYRE_RUNTIME_POOL_ROLE'=>'realtime']);
	$t->same('record_only',\dataphyre\scheduling::activation_mode());
	$t->isFalse(\dataphyre\scheduling::dispatch_enabled());
	$t->environment(['DATAPHYRE_RUNTIME_POOL_ROLE'=>'scheduler']);
	$t->same('record_only',\dataphyre\scheduling::activation_mode());
	$t->isFalse(\dataphyre\scheduling::dispatch_enabled());
	$t->environment(['DATAPHYRE_RUNTIME_POOL_ROLE'=>null]);
	$t->same('default',\dataphyre\scheduling::activation_mode());
	$t->isTrue(\dataphyre\scheduling::dispatch_enabled());
	\dataphyre\scheduling::use_activation_mode('record_only');
	$t->same('record_only',\dataphyre\scheduling::activation_mode());
	$t->isFalse(\dataphyre\scheduling::dispatch_enabled());
	\dataphyre\scheduling::use_state_root(null);
	\dataphyre\scheduling::use_activation_mode(null);
});

test('scheduler names paths and persisted definitions have a closed normalized shape',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-state');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$t->isTrue(\dataphyre\scheduling::valid_scheduler_name('orders.daily-1'));
	$t->isFalse(\dataphyre\scheduling::valid_scheduler_name('../orders'));
	$t->isFalse(\dataphyre\scheduling::valid_scheduler_name('.'));
	$t->isFalse(\dataphyre\scheduling::valid_scheduler_name('..'));
	$t->isFalse(\dataphyre\scheduling::valid_scheduler_name(str_repeat('a',129)));
	$t->endsWith('/cache/scheduling/',\dataphyre\scheduling::scheduler_directory('../bad'));
	$t->endsWith('/cache/scheduling/orders/properties.json',\dataphyre\scheduling::scheduler_properties_file('orders'));
	$t->endsWith('/cache/scheduling/orders/running_lock',\dataphyre\scheduling::running_lock_file('orders'));
	$t->endsWith('/cache/scheduling/orders/last_run',\dataphyre\scheduling::last_run_file('orders'));
	$t->endsWith('/cache/scheduling/orders/last_success',\dataphyre\scheduling::last_success_file('orders'));

	$t->isFalse(\dataphyre\scheduling::in_task_runner());
	\dataphyre\scheduling::begin_task_runner('orders');
	$t->isTrue(\dataphyre\scheduling::in_task_runner());
	$t->same('orders',\dataphyre\scheduling::current_scheduler_name());
	\dataphyre\scheduling::begin_task_runner('../bad');
	$t->isNull(\dataphyre\scheduling::current_scheduler_name());
	\dataphyre\scheduling::end_task_runner();

	$t->isNull(\dataphyre\scheduling::read_scheduler('../bad'));
	$t->isNull(\dataphyre\scheduling::read_scheduler('missing'));
	$workspace->file('cache/scheduling/blank/properties.json','  ');
	$t->isNull(\dataphyre\scheduling::read_scheduler('blank'));
	$workspace->file('cache/scheduling/malformed/properties.json','{bad json');
	$t->isNull(\dataphyre\scheduling::read_scheduler('malformed'));
	$task=$workspace->file('tasks/run.php','<?php return true;');
	$dependency=$workspace->file('tasks/dependency.php','<?php return true;');
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'file_path'=>$task,'frequency'=>-1,'dependencies'=>[$dependency,$dependency,''],
		'timeout'=>0,'memory_limit'=>'','app_override'=>'shop',
	],JSON_THROW_ON_ERROR));
	$t->hasPathValues([
		'name'=>'orders','file_path'=>$task,'frequency'=>0.0,'dependencies'=>[$dependency],
		'timeout'=>1.0,'memory_limit'=>'128M','app_override'=>'shop',
	],\dataphyre\scheduling::read_scheduler('orders'));
	\dataphyre\scheduling::use_state_root(null);
});

test('registration persists once and lock frequency timeout and load decisions are explicit',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-registration');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/run.php','<?php return true;');
	$dependency=$workspace->file('tasks/dependency.php','<?php return true;');
	$t->isFalse(\dataphyre\scheduling::run('../bad',$task,60,60,'128M',[],'shop'));
	$t->isFalse(\dataphyre\scheduling::run('missing',$workspace->path('missing.php'),60,60,'128M',[],'shop'));
	$t->isFalse(\dataphyre\scheduling::run('missing-dependency',$task,60,60,'128M',[$workspace->path('missing.php')],'shop'));

	$internals=$t->nonPublic(\dataphyre\scheduling::class);
	$definition=$internals->invoke('normalize_scheduler_definition','orders',$task,-2,0,'',[$dependency,$dependency],'shop');
	$t->hasPathValues(['frequency'=>0.0,'timeout'=>1.0,'memory_limit'=>'128M','dependencies'=>[$dependency]],$definition);
	\dataphyre\core::$server_load_level=3;
	$t->isNull($internals->invoke('can_run',$definition));
	\dataphyre\core::$server_load_level=0;
	$t->isTrue($internals->invoke('can_run',$definition));

	$lastRun=\dataphyre\scheduling::last_run_file('orders');
	$lock=\dataphyre\scheduling::running_lock_file('orders');
	$workspace->file('cache/scheduling/orders/last_run',(string)(time()-10));
	$workspace->file('cache/scheduling/orders/running_lock',str_repeat('a',64));
	touch($lock,time()-10);
	$definition['timeout']=5.0;
	$t->isTrue($internals->invoke('can_run',$definition));
	$t->isFalse(is_file($lock));
	$workspace->file('cache/scheduling/orders/last_run',(string)time());
	$workspace->file('cache/scheduling/orders/running_lock',str_repeat('b',64));
	$definition['timeout']=60.0;
	$t->isNull($internals->invoke('can_run',$definition));
	@unlink($lock);
	$definition['frequency']=60.0;
	$workspace->file('cache/scheduling/orders/last_success',str_repeat('c',64));
	$t->isFalse($internals->invoke('can_run',$definition));
	$workspace->file('cache/scheduling/orders/running_lock',str_repeat('d',64));
	touch($lock,time()-120);
	$held=fopen($lock,'r+');
	$t->isTrue(is_resource($held) && flock($held,LOCK_EX|LOCK_NB));
	$t->isNull($internals->invoke('can_run',$definition));
	$t->isTrue(is_file($lock));
	flock($held,LOCK_UN);fclose($held);@unlink($lock);
	$workspace->file('cache/scheduling/orders/last_run','invalid');
	$t->isTrue($internals->invoke('can_run',$definition));
	$t->isNull($internals->invoke('read_last_run_timestamp',$workspace->path('missing')));
	$t->isNull($internals->invoke('read_last_run_timestamp',$lastRun));
	$t->isNull($internals->invoke('read_last_run_timestamp',$lastRun,static fn()=>false));

	$registrations=[];
	$registrar=static function(mixed $callback,mixed ...$arguments)use(&$registrations): void {
		$registrations[]=[$callback,$arguments];
	};
	@unlink($lock);
	$workspace->file('cache/scheduling/due/last_run',(string)(time()-100));
	$t->isTrue(\dataphyre\scheduling::run('due',$task,1,30,'256M',[$dependency],'shop',$registrar));
	$t->count(1,$registrations);
	$t->same(['due','shop'],array_slice($registrations[0][1],0,2));
	$t->matches('/^[a-f0-9]{64}$/D',$registrations[0][1][2]);
	$t->isTrue(is_file(\dataphyre\scheduling::running_lock_file('due')));
	$t->same($registrations[0][1][2],trim((string)file_get_contents(\dataphyre\scheduling::running_lock_file('due'))));

	$claimOne=str_repeat('1',64);
	$claimTwo=str_repeat('2',64);
	$workspace->directory('cache/scheduling/atomic-lock');
	$t->isTrue($internals->invoke('acquire_running_lock','atomic-lock',$claimOne));
	$t->isFalse($internals->invoke('acquire_running_lock','atomic-lock',$claimTwo));
	$t->same($claimOne,trim((string)file_get_contents(\dataphyre\scheduling::running_lock_file('atomic-lock'))));
	$unsafeState=$workspace->file('unsafe-state','123');
	$workspace->directory('cache/scheduling/lock-failure');
	symlink($unsafeState,$workspace->path('cache/scheduling/lock-failure/last_run'));
	$t->isFalse(\dataphyre\scheduling::run('lock-failure',$task,0,30,'128M',[],'shop',$registrar));
	$t->isFalse(is_file(\dataphyre\scheduling::running_lock_file('lock-failure')));
	$t->isTrue(is_link(\dataphyre\scheduling::last_run_file('lock-failure')));
	$before=count(\dataphyre\SchedulingRuntimeProbe::$writes);
	$internals->invoke('persist_scheduler_definition',$definition);
	$internals->invoke('persist_scheduler_definition',$definition);
	$t->same($before+1,count(\dataphyre\SchedulingRuntimeProbe::$writes));
	$recursive=[];$recursive['self']=&$recursive;
	$internals->invoke('persist_scheduler_definition',['name'=>'recursive','value'=>$recursive]);
	\dataphyre\scheduling::use_state_root(null);
});

test('dispatch URLs and both internal HTTP transports stay local bounded and failure-safe',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$internals=$t->nonPublic(\dataphyre\scheduling::class);
	$server=$t->globalMap('_SERVER')->replace([]);
	$workspace=$t->workspace('scheduling-dispatch-failure');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/run.php','<?php return true;');
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[],
		'timeout'=>30,'memory_limit'=>'128M','app_override'=>'',
	],JSON_THROW_ON_ERROR));
	$t->isNull($internals->invoke('scheduler_dispatch_url','orders','shop'));
	$server->replace(['SELF_ADDR'=>'127.0.0.1:1']);
	\dataphyre\SchedulingRuntimeProbe::$app_override='tenant-one';
	$t->same('http://127.0.0.1:1/dataphyre/scheduler/orders?app_override=tenant-one',$internals->invoke('scheduler_dispatch_url','orders','shop'));
	$server->put('HTTPS','on');
	\dataphyre\SchedulingRuntimeProbe::$app_override=false;
	$t->same('https://127.0.0.1:1/dataphyre/scheduler/orders',$internals->invoke('scheduler_dispatch_url','orders','shop'));
	$server->put('HTTPS','off')->put('REQUEST_SCHEME','https');
	\dataphyre\SchedulingRuntimeProbe::$app_override='';
	$t->same('https://127.0.0.1:1/dataphyre/scheduler/orders',$internals->invoke('scheduler_dispatch_url','orders','shop'));
	$t->same('https://127.0.0.1:1/dataphyre/scheduler/orders',$internals->invoke('scheduler_dispatch_url','orders',''));
	$signature=str_repeat('a',64);
	$claim=str_repeat('c',64);
	$complete=static function() use ($workspace,$claim): void {
		$workspace->file('cache/scheduling/orders/last_run',(string)time());
		$workspace->file('cache/scheduling/orders/last_success',$claim);
		@unlink($workspace->path('cache/scheduling/orders/running_lock'));
	};
	$signed=[];
	$signer=static function(string $context,int $issuedAt) use (&$signed,$signature): string {
		$signed=[$context,$issuedAt];
		return $signature;
	};

	$server->replace([]);
	$internals->invoke('dispatch_registered_scheduler','orders','shop',$claim,true,$signer);
	$server->replace(['SELF_ADDR'=>'127.0.0.1:1']);
	$complete();
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true,$signer);
	$t->containsRows([['init'],['exec'],['getinfo',CURLINFO_RESPONSE_CODE],['close']],\dataphyre\SchedulingRuntimeProbe::$curl);
	$t->same('orders|'.$claim.'|30000|'.$signed[1],$signed[0]);
	$t->containsRows([['setopt',CURLOPT_HTTPHEADER,[
		'X-Traffic-Source: internal_traffic',
		'X-Dataphyre-Scheduler-Claim: '.$claim,
		'X-Dataphyre-Scheduler-Budget-Ms: 30000',
		'X-Dataphyre-Scheduler-Issued-At: '.$signed[1],
		'X-Dataphyre-Scheduler-Key: '.$signature,
	]]],\dataphyre\SchedulingRuntimeProbe::$curl);
	$complete();
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,false,$signer);
	$before=count(\dataphyre\SchedulingRuntimeProbe::$curl);
	$internals->invoke('dispatch_registered_scheduler','orders','',str_repeat('x',64),true,$signer);
	$t->same($before,count(\dataphyre\SchedulingRuntimeProbe::$curl));
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true,static fn(string $context,int $issuedAt): false=>false);
	$t->same($before,count(\dataphyre\SchedulingRuntimeProbe::$curl));
	$t->isFalse(function_exists('dp_shared_request_key'));
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true);
	$t->same($before,count(\dataphyre\SchedulingRuntimeProbe::$curl));
	define_test_symbols('function dp_shared_request_key(string $secret,string $purpose,string $context=""): string|false { return str_repeat("b",64); }');
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true);
	$t->greaterThan($before,count(\dataphyre\SchedulingRuntimeProbe::$curl));
	\dataphyre\SchedulingRuntimeProbe::$curl_throws=true;
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true,$signer);
	$t->containsRows([['Fatal error on Dataphyre Scheduling shutdown callback','curl failed']],\dataphyre\SchedulingRuntimeProbe::$shutdown);
	\dataphyre\SchedulingRuntimeProbe::$curl_throws=false;
	\dataphyre\SchedulingRuntimeProbe::$curl_status=503;
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true,$signer);
	$t->isFalse(is_file(\dataphyre\scheduling::running_lock_file('orders')));
	$t->containsRows([['Fatal error on Dataphyre Scheduling shutdown callback','Scheduler callback failed with HTTP status 503']],\dataphyre\SchedulingRuntimeProbe::$shutdown);
	$differentClaim=str_repeat('d',64);
	$workspace->file('cache/scheduling/orders/running_lock',$differentClaim);
	$internals->invoke('dispatch_registered_scheduler','orders','',$claim,true,$signer);
	$t->same($differentClaim,trim((string)file_get_contents(\dataphyre\scheduling::running_lock_file('orders'))));
	\dataphyre\scheduling::use_state_root(null);
});

test('managed callback waits beyond the legacy 150ms window using the bounded scheduler timeout',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-managed-slow-callback');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/slow.php','<?php return true;');
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[],
		'timeout'=>2,'memory_limit'=>'128M','app_override'=>'',
	],JSON_THROW_ON_ERROR));
	$claim=str_repeat('c',64);
	$workspace->file('cache/scheduling/orders/last_run',(string)time());
	$workspace->file('cache/scheduling/orders/last_success',$claim);
	$t->globalMap('_SERVER')->replace(['SELF_ADDR'=>'127.0.0.1:8081']);
	\dataphyre\SchedulingRuntimeProbe::$curl_delay_microseconds=250000;
	$internals=$t->nonPublic(\dataphyre\scheduling::class);
	$started=microtime(true);
	$t->isTrue($internals->invoke(
		'dispatch_registered_scheduler','orders','',$claim,true,
		static fn(string $context,int $issuedAt): string=>str_repeat('b',64),
	));
	$t->greaterThan(0.2,microtime(true)-$started);
	$t->containsRows([
		['setopt',CURLOPT_TIMEOUT_MS,3000],
		['setopt',CURLOPT_CONNECTTIMEOUT_MS,150],
	],\dataphyre\SchedulingRuntimeProbe::$curl);
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[],
		'timeout'=>900,'memory_limit'=>'128M','app_override'=>'',
	],JSON_THROW_ON_ERROR));
	\dataphyre\SchedulingRuntimeProbe::$curl_delay_microseconds=0;
	$workspace->file('cache/scheduling/orders/last_run',(string)time());
	$workspace->file('cache/scheduling/orders/last_success',$claim);
	$t->isTrue($internals->invoke(
		'dispatch_registered_scheduler','orders','',$claim,true,
		static fn(string $context,int $issuedAt): string=>str_repeat('b',64),
	));
	$t->containsRows([
		['setopt',CURLOPT_TIMEOUT_MS,296000],
	],\dataphyre\SchedulingRuntimeProbe::$curl);
	\dataphyre\scheduling::use_state_root(null);
})->tag('signed-cadence','lifecycle','slow-callback','timeout');

test('managed callback runs only in the fresh scheduler CGI with an outer signed wall-clock bound',static function(Context $t): void {
	$core=dirname(__DIR__,2).'/core/kernel';
	$runner=(string)file_get_contents(dirname(__DIR__).'/kernel/task_runner.php');
	$router=(string)file_get_contents($core.'/application_runtime_router.php');
	$gateway=(string)file_get_contents($core.'/application_runtime_scheduler_gateway.php');
	$t->isFalse(file_exists(dirname(__DIR__).'/kernel/managed_task_worker.php'));
	$t->isFalse(str_contains($runner,'executeManagedTask'));
	$t->isFalse(str_contains($runner,'proc_open('));
	$t->contains('managedBootstrapAttestation()',$runner);
	$t->contains('runtime_definition($scheduler_name)',$runner);
	$t->contains("'sha256:'.hash('sha256',json_encode(\$evidence",$runner);
	$t->contains('executeTask($definition',$runner);
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::verify',$router);
	$t->contains('/dataphyre/runtime/scheduler/claim',$gateway);
	$t->isTrue(strpos($gateway,'claimSchedulerRequest($request,$body')<strpos($gateway,'DataphyreApplicationRuntimeProcessBroker::spawn'));
	$t->contains("'/dataphyre/runtime/scheduler/callback'",$gateway);
	$t->contains("? \$candidate['budget_milliseconds']",$gateway);
	$t->contains('SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS',$gateway);
})->tag('fresh-cgi','process-boundary','claim','signed-budget','security','deletion');

test('task runner rejects unavailable invalid and missing scheduler requests before execution',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-task-runner-invalid');
	\dataphyre\scheduling::use_state_root($workspace->root());
	\dataphyre\routing::$bindings=['scheduler'=>'orders'];
	$terminated=0;
	$terminator=static function()use(&$terminated): void {$terminated++;};
	$t->same('Invalid scheduler',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		$terminator,null,['scheduling_available'=>false],
	))->output());
	\dataphyre\routing::$bindings=['scheduler'=>'../bad'];
	$t->same('Invalid scheduler',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch($terminator))->output());
	\dataphyre\routing::$bindings=['scheduler'=>'missing'];
	$preInit=[];
	$preInitHandler=static function(string $message)use(&$preInit): void {$preInit[]=$message;};
	$output=$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		$terminator,
		null,
		['pre_init_error'=>$preInitHandler],
	))->output();
	$t->same('Requested scheduler does not exist',$output);
	$t->contains('scheduler does not exist',$preInit[0]);
	$task=$workspace->file('tasks/pending.php','<?php return true;');
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[],
		'timeout'=>30,'memory_limit'=>'128M','app_override'=>'',
	],JSON_THROW_ON_ERROR));
	\dataphyre\routing::$bindings=['scheduler'=>'orders'];
	$claim=str_repeat('e',64);
	$t->same('Scheduler not pending',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		$terminator,null,['scheduler_claim'=>$claim,'pre_init_error'=>$preInitHandler],
	))->output());
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	$runnerInternals=$t->nonPublic(dataphyre_scheduling_task_runner::class);
	$held=$runnerInternals->invoke('claimRunningLock',\dataphyre\scheduling::running_lock_file('orders'),$claim,[]);
	$t->isTrue(is_resource($held));
	$t->same('Scheduler not pending',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		$terminator,null,['scheduler_claim'=>$claim,'pre_init_error'=>$preInitHandler],
	))->output());
	flock($held,LOCK_UN);
	fclose($held);
	@unlink(\dataphyre\scheduling::running_lock_file('orders'));
	$t->same(5,$terminated);
	$t->containsRows([['dialback']],array_map(
		static fn(array $trace): array=>[(string)($trace[0] ?? '')],
		\dataphyre\SchedulingRuntimeProbe::$traces,
	));
	\dataphyre\scheduling::use_state_root(null);
});

test('task runner executes dependencies modules task files and registered cleanup as one lifecycle',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-task-runner-success');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$dependency=$workspace->file('tasks/dependency.php','<?php define("SCHEDULING_DEPENDENCY_LOADED",true);');
	$task=$workspace->file('tasks/task.php','<?php define("SCHEDULING_TASK_LOADED",true);');
	$scheduler=[
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[$dependency],
		'timeout'=>2,'memory_limit'=>'192M','app_override'=>'',
	];
	$workspace->file('cache/scheduling/orders/properties.json',json_encode($scheduler,JSON_THROW_ON_ERROR));
	$claim=str_repeat('a',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	\dataphyre\routing::$bindings=['scheduler'=>'orders'];
	\dataphyre\SchedulingRuntimeProbe::$modules=['tracelog','sql','cache'];
	\dataphyre\SchedulingRuntimeProbe::$sql_config=['caching'=>['default_policy'=>['type'=>'session','max_lifespan'=>'5 minute']]];
	$callbacks=[];
	$abortPolicy=[];
	$registrar=static function(callable $callback)use(&$callbacks): void {$callbacks[]=$callback;};
	$abortHandler=static function(bool $ignore) use (&$abortPolicy): int { $abortPolicy[]=$ignore; return 0; };
	$output=$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		static fn()=>null,
		$registrar,
		['ignore_user_abort'=>$abortHandler,'scheduler_claim'=>$claim],
	))->output();
	$t->same([true],$abortPolicy);
	$t->containsAll(['Including '.$dependency,'Running '.$task],$output);
	$t->isTrue(defined('SCHEDULING_DEPENDENCY_LOADED'));
	$t->isTrue(defined('SCHEDULING_TASK_LOADED'));
	$t->isTrue(\dataphyre\tracelog::$enable);
	$t->isTrue(\dataphyre\scheduling::in_task_runner());
	$t->count(1,$callbacks);
	$t->contains('<trace>scheduler</trace>',$t->captureOutput($callbacks[0])->output());
	$t->isFalse(\dataphyre\scheduling::in_task_runner());
	$t->isFalse(is_file(\dataphyre\scheduling::running_lock_file('orders')));
	$t->same((string)time(),trim((string)file_get_contents(\dataphyre\scheduling::last_run_file('orders'))));
	$t->same($claim,trim((string)file_get_contents(\dataphyre\scheduling::last_success_file('orders'))));
	$t->contains('<trace>scheduler</trace>',(string)file_get_contents($workspace->path('cache/scheduling/orders/tracelog.html')));
	$t->same('shared_cache',DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE['type']);
	dataphyre_scheduling_task_runner::dispatch_entrypoint(true);
	dataphyre_scheduling_task_runner::dispatch_entrypoint(true,null,null,['scheduling_loaded'=>false]);
	$loaded=[];
	dataphyre_scheduling_task_runner::dispatch_entrypoint(true, null, null, [
		'scheduling_loaded'=>false,
		'framework_loader'=>static function(string $module) use (&$loaded): bool { $loaded[]=$module; return true; },
	]);
	$t->same(['scheduling'],$loaded);
	\dataphyre\scheduling::use_state_root(null);
});

test('mounted task runner uses native shutdown registration and a safe SQL cache fallback',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-task-runner-entrypoint');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/entrypoint.php','<?php define("SCHEDULING_ENTRYPOINT_LOADED",true);');
	$workspace->file('cache/scheduling/orders/properties.json',json_encode([
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>[],
		'timeout'=>1,'memory_limit'=>'128M','app_override'=>'',
	],JSON_THROW_ON_ERROR));
	$claim=str_repeat('b',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	\dataphyre\SchedulingRuntimeProbe::$modules=['sql'];
	\dataphyre\SchedulingRuntimeProbe::$sql_config=[];
	$runtime=[
		'scheduler_name'=>'orders',
		'scheduler_claim'=>$claim,
		'writer'=>static fn(string $path,mixed $value,int $flags=0): int=>1,
		'is_file'=>static fn(string $path): bool=>is_file($path),
		'file_exists'=>static fn(string $path): bool=>false,
		'unlink'=>static fn(string $path): bool=>true,
		'shutdown_logger'=>static fn(string $message,Throwable $failure): null=>null,
	];
	$output=$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch_entrypoint(
		false,
		null,
		null,
		$runtime,
	))->output();
	$t->contains('Running '.$task,$output);
	$t->isTrue(defined('SCHEDULING_ENTRYPOINT_LOADED'));
	$t->same('fs',DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE['type']);
	$t->same('30 minute',DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE['max_lifespan']);
	$t->isTrue(\dataphyre\scheduling::in_task_runner());
	\dataphyre\scheduling::end_task_runner();
	\dataphyre\scheduling::use_state_root(null);
});

test('task runner reports dependency task and cleanup failures without leaking active state',static function(Context $t): void {
	\dataphyre\SchedulingRuntimeProbe::reset();
	$workspace=$t->workspace('scheduling-task-runner-failures');
	\dataphyre\scheduling::use_state_root($workspace->root());
	$task=$workspace->file('tasks/task.php','<?php return true;');
	$scheduler=[
		'name'=>'orders','file_path'=>$task,'frequency'=>0,'dependencies'=>['missing.php'],
		'timeout'=>1,'memory_limit'=>'128M','app_override'=>'',
	];
	$workspace->file('cache/scheduling/orders/properties.json',json_encode($scheduler,JSON_THROW_ON_ERROR));
	$claim=str_repeat('c',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	\dataphyre\routing::$bindings=['scheduler'=>'orders'];
	$callbacks=[];
	$errors=[];
	$registrar=static function(callable $callback)use(&$callbacks): void {$callbacks[]=$callback;};
	$preInitHandler=static function(string $message,?Throwable $failure=null)use(&$errors): void {
		$errors[]=[$message,$failure?->getMessage()];
	};
	$output=$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		static fn()=>null,
		$registrar,
		[
			'is_file'=>static fn(string $path): bool=>!str_ends_with($path,'missing.php') && is_file($path),
			'pre_init_error'=>$preInitHandler,
			'scheduler_claim'=>$claim,
		],
	))->output();
	$t->contains('Execution error',$output);
	$t->contains('Scheduler dependency does not exist',$errors[0][1]);
	$t->count(1,$callbacks);
	$callbacks[0]();
	$t->isFalse(is_file(\dataphyre\scheduling::last_success_file('orders')));

	$scheduler['dependencies']=[];
	$scheduler['file_path']='';
	$workspace->file('cache/scheduling/orders/properties.json',json_encode($scheduler,JSON_THROW_ON_ERROR));
	$claim=str_repeat('d',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	$errors=[];
	$preInitHandler=static function(string $message,?Throwable $failure=null)use(&$errors): void {
		$errors[]=[$message,$failure?->getMessage()];
	};
	$t->contains('Execution error',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		static fn()=>null,
		static fn(callable $callback)=>null,
		['pre_init_error'=>$preInitHandler,'scheduler_claim'=>$claim],
	))->output());
	$t->contains('Scheduler file does not exist',$errors[0][1]);

	$failingTask=$workspace->file('tasks/throws.php','<?php throw new RuntimeException("task failed");');
	$scheduler['file_path']=$failingTask;
	$workspace->file('cache/scheduling/orders/properties.json',json_encode($scheduler,JSON_THROW_ON_ERROR));
	$claim=str_repeat('e',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	$errors=[];
	$callbacks=[];
	$t->contains('Execution error',$t->captureOutput(static fn()=>dataphyre_scheduling_task_runner::dispatch(
		static fn()=>null,
		$registrar,
		['pre_init_error'=>$preInitHandler,'scheduler_claim'=>$claim],
	))->output());
	$t->contains('task failed',$errors[0][1]);
	$t->count(1,$callbacks);
	$callbacks[0]();
	$t->isFalse(is_file(\dataphyre\scheduling::last_success_file('orders')));
	$t->isFalse(is_file(\dataphyre\scheduling::running_lock_file('orders')));

	\dataphyre\scheduling::begin_task_runner('orders');
	$shutdown=[];
	$claim=str_repeat('f',64);
	$workspace->file('cache/scheduling/orders/running_lock',$claim);
	$runnerInternals=$t->nonPublic(dataphyre_scheduling_task_runner::class);
	$handle=$runnerInternals->invoke('claimRunningLock',\dataphyre\scheduling::running_lock_file('orders'),$claim,[]);
	dataphyre_scheduling_task_runner::finalize($workspace->path('cache/scheduling/orders'),'orders',[
		'writer'=>static fn()=>throw new RuntimeException('write failed'),
		'shutdown_logger'=>static function(string $message,Throwable $failure)use(&$shutdown): void {$shutdown[]=[$message,$failure->getMessage()];},
	],$handle,$claim,true);
	$t->containsRows([['Fatal error on Dataphyre Scheduling (task runner) shutdown callback','write failed']],$shutdown);

	$workspace->file('cache/scheduling/orders/running_lock','locked');
	dataphyre_scheduling_task_runner::finalize($workspace->path('cache/scheduling/orders'),'orders',[
		'module_present'=>static fn()=>false,
		'writer'=>static fn()=>1,
		'is_file'=>static fn()=>true,
		'unlink'=>static fn()=>false,
		'file_exists'=>static fn()=>true,
		'timestamp'=>123,
	]);
	$t->isFalse(\dataphyre\scheduling::in_task_runner());
	$t->containsRows([['unavailable']],array_map(static fn(array $trace): array=>[(string)($trace[0] ?? '')],\dataphyre\SchedulingRuntimeProbe::$traces));
	\dataphyre\scheduling::use_state_root(null);
});
