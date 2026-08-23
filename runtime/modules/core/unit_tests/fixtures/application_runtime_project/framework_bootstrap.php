<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once ROOTPATH['common_dataphyre_runtime'] . 'modules/core/kernel/core.main.php';
$managedSessionEvidence=(string)getenv('DATAPHYRE_RUNTIME_TEST_SESSION_EVIDENCE');
if($managedSessionEvidence!==''){
	$sessionDirectory=rtrim((string)ini_get('session.save_path'),'/\\');
	$sessionFiles=$sessionDirectory==='' ? false : glob($sessionDirectory.'/sess_*');
	file_put_contents($managedSessionEvidence,json_encode([
		'session_status_none'=>session_status()===PHP_SESSION_NONE,
		'session_id_empty'=>session_id()==='',
		'session_files_absent'=>is_array($sessionFiles) && $sessionFiles===[],
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
}
\dataphyre\autoloader::register(ROOTPATH['common_dataphyre_runtime'] . 'modules');
if (\dataphyre\core::load_framework_module('scheduling') !== true) {
    throw new RuntimeException('Scheduling module was not loaded.');
}

if((string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MATERIALIZER')==='1'){
	require_once __DIR__.'/bootstrap_only_sql.php';
}

$bootstrapOnlyMutation=(string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MUTATION');
if($bootstrapOnlyMutation!==''){
	$preflight=&$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture deliberately targets the private preflight proof against substitution."
	$stateRoot=is_array($preflight ?? null) ? (string)($preflight['state_root'] ?? '') : '';
	if(str_starts_with($bootstrapOnlyMutation,'deferred-')){
		$GLOBALS['dataphyre_deferred_sql_table_definitions'][]=static function() use ($bootstrapOnlyMutation): void { // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture injects a deferred tenant callback into the legacy SQL registry."
			if($bootstrapOnlyMutation==='deferred-transport-environment'){
				putenv('DATAPHYRE_RUNTIME_APPLICATION=tenant-forgery');
			}elseif($bootstrapOnlyMutation==='deferred-output-buffer-replace'){
				ob_end_clean();ob_start(static fn(string $chunk): string=>$chunk); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Adversarial bootstrap fixture replaces the framework swallow buffer at the same depth."
			}elseif($bootstrapOnlyMutation==='deferred-cwd'){
				if(!chdir(sys_get_temp_dir())) throw new RuntimeException('Fixture could not mutate its cwd.'); // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Adversarial bootstrap fixture must move to a known native directory outside the project root."
			}elseif($bootstrapOnlyMutation==='deferred-exit'){
				exit(0);
			}else throw new RuntimeException('Unknown deferred bootstrap-only mutation fixture.');
		};
	}elseif($bootstrapOnlyMutation==='bootstrap-exit'){
		exit(0);
	}elseif($bootstrapOnlyMutation==='state-delete'){
		if($stateRoot==='' || !@rmdir($stateRoot)) throw new RuntimeException('Fixture could not delete the preflight state root.');
	}elseif($bootstrapOnlyMutation==='state-replace'){
		if($stateRoot==='' || !@rmdir($stateRoot) || !@mkdir($stateRoot,0700,true)){
			throw new RuntimeException('Fixture could not replace the preflight state root.');
		}
	}elseif($bootstrapOnlyMutation==='private-key'){
		$corruptPrivateKey=\Closure::bind(static function(): void {
			\Dataphyre\InternalApplicationBootstrapOnly::$privateKey=random_bytes(32);
		},null,\Dataphyre\InternalApplicationBootstrapOnly::class);
		if(!$corruptPrivateKey instanceof \Closure) throw new RuntimeException('Fixture could not bind the private-key mutation.');
		$corruptPrivateKey();
	}elseif($bootstrapOnlyMutation==='proof-private-key'){
		$preflight['private_key']=str_repeat('f',64);
	}elseif($bootstrapOnlyMutation==='proof-token'){
		$preflight['token']=str_repeat('e',64);
	}elseif($bootstrapOnlyMutation==='transport-environment'){
		putenv('DATAPHYRE_RUNTIME_APPLICATION=tenant-forgery');
	}elseif($bootstrapOnlyMutation==='transport-argv'){
		$_SERVER['argv'][0]=__FILE__; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Adversarial bootstrap fixture corrupts the native server argv projection."
		$GLOBALS['argv'][0]=__FILE__; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture corrupts the native global argv projection."
	}elseif($bootstrapOnlyMutation==='transport-request'){
		$_GET['tenant-forgery']='1'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Adversarial bootstrap fixture injects request input that registration-only mode must reject."
	}elseif($bootstrapOnlyMutation==='output-buffer-replace'){
		ob_end_clean();ob_start(static fn(string $chunk): string=>$chunk); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Adversarial bootstrap fixture replaces the active framework swallow buffer."
	}else throw new RuntimeException('Unknown bootstrap-only mutation fixture.');
	if($bootstrapOnlyMutation!=='output-buffer-replace') \Dataphyre\InternalApplicationBootstrapOnly::context();
}

if((string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_GLOBAL_POISON')==='1'){
	$GLOBALS['stateRoot']='/app'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial fixture poisons a former script-global state-root name."
	$GLOBALS['projectRoot']='/tenant-forgery'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial fixture poisons a former script-global project-root name."
	$GLOBALS['application']='tenant-forgery'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial fixture poisons a former script-global application name."
	$GLOBALS['environment']='tenant-forgery'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial fixture poisons a former script-global environment name."
	$GLOBALS['successPayload']=['contract'=>'tenant-forgery']; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial fixture poisons a former script-global evidence name."
}

$bootstrapOnlyEvidence=(string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_EVIDENCE');
if($bootstrapOnlyEvidence!==''){
	$bootstrapOnlyRuntime=rtrim((string)getenv('DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'),'/\\');
	require_once $bootstrapOnlyRuntime.'/modules/http/Framework/Request.php';
	require_once $bootstrapOnlyRuntime.'/modules/http/Framework/Response.php';
	require_once $bootstrapOnlyRuntime.'/modules/http/Framework/ResponseEmitter.php';
	require_once $bootstrapOnlyRuntime.'/modules/mvc/Framework/MvcApplication.php';
	require_once $bootstrapOnlyRuntime.'/modules/mvc/Framework/MvcDispatcher.php';
	require_once $bootstrapOnlyRuntime.'/modules/testing/tooling/TypeInventory.php';
	require_once __DIR__.'/bootstrap_only_namespace_hijack.php';
	$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
	if((string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_MUTATION')==='1'){
		\dataphyre\scheduling::use_state_root('/app');
		\dataphyre\scheduling::use_activation_mode('default');
		$corruptSchedulingState=\Closure::bind(static function(): void {
			\dataphyre\scheduling::$state_root='/app/';
			\dataphyre\scheduling::$activation_mode='default';
		},null,\dataphyre\scheduling::class);
		if(!$corruptSchedulingState instanceof \Closure) throw new RuntimeException('Fixture could not bind the scheduler mutation.');
		$corruptSchedulingState();
	}
	$schedulerCallback=(string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_CALLBACK');
	$schedulerAccepted=\dataphyre\scheduling::run(
		'runtime.bootstrap-only',__DIR__.'/scheduled_task.php',3600,30,'64M',[],'',
		static function(callable $callback) use ($schedulerCallback): void {
			if($schedulerCallback!=='') file_put_contents($schedulerCallback,'registered',LOCK_EX);
		},
	);
	$mvcApplication=\Dataphyre\Test\TypeInventory::of(\Dataphyre\Mvc\MvcApplication::class)->withoutConstructor();
	$mvc=new \Dataphyre\Mvc\MvcDispatcher($mvcApplication);
	$request=\Dataphyre\Http\Request::create('GET','/runtime/bootstrap-only');
	$response=$mvc->dispatch($request);
	$statusBefore=http_response_code();
	\Dataphyre\Http\ResponseEmitter::emit(\Dataphyre\Http\Response::make('forbidden',299));
	$statusAfter=http_response_code();
	$unavailableRejected=false;
	try{\dataphyre\core::unavailable(__FILE__,(string)__LINE__,__CLASS__,__FUNCTION__,'fixture','fixture');}
	catch(RuntimeException $exception){$unavailableRejected=str_contains($exception->getMessage(),'Registration-only');}
	$reflectionActivationRejected=false;
	try{
		$replayActivation=\Closure::bind(static function(array $context): void {
			\Dataphyre\InternalApplicationBootstrapOnly::activate($context,static function(): void {});
		},null,\Dataphyre\InternalApplicationBootstrapOnly::class);
		if(!$replayActivation instanceof \Closure) throw new RuntimeException('Fixture could not bind the activation replay.');
		$replayActivation($context);
	}catch(Throwable){$reflectionActivationRejected=true;}
	file_put_contents($bootstrapOnlyEvidence,json_encode([
		'purpose'=>$context['purpose'],'scheduler_accepted'=>$schedulerAccepted,
		'scheduler_directory'=>\dataphyre\scheduling::scheduler_directory('runtime.bootstrap-only'),
		'cwd'=>realpath((string)getcwd()),
		'terminal_writer_callable'=>function_exists('dataphyre_realtime_preflight_write'),
		'mvc_status'=>$response->status,'mvc_body'=>$response->body,
		'emitter_status_unchanged'=>$statusBefore===$statusAfter,'unavailable_rejected'=>$unavailableRejected,
		'reflection_activation_rejected'=>$reflectionActivationRejected,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
	echo 'forbidden-application-output';
}

$bootstrapOnlyShutdown=(string)getenv('DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SHUTDOWN');
if($bootstrapOnlyShutdown!==''){
	register_shutdown_function(static function() use ($bootstrapOnlyShutdown): void {
		$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
		$stateRoot=(string)($context['preflight_state_root'] ?? '');
		file_put_contents($bootstrapOnlyShutdown,json_encode([
			'purpose'=>$context['purpose'],'state_root_present'=>$stateRoot==='' || (is_dir($stateRoot) && !is_link($stateRoot)),
		],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
		echo 'forbidden-shutdown-output';
	});
}

if((string)getenv('DATAPHYRE_RUNTIME_POOL_ROLE')==='health-preflight'){
	\dataphyre\scheduling::run(
		'runtime.health.preflight',
		__DIR__.'/scheduled_task.php',
		3600,
		30,
		'64M',
		[],
		'',
		static function(): void {
			$sideEffectPath=(string)getenv('DATAPHYRE_RUNTIME_TEST_HEALTH_SIDE_EFFECT_PATH');
			if($sideEffectPath!=='') file_put_contents($sideEffectPath,'health-dispatch-registered',LOCK_EX);
		},
	);
}

if ((string) ($_SERVER['DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP'] ?? '') === '1') { // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact-image fixture observes the framework-owned realtime bootstrap boundary."
	\dataphyre\scheduling::run(
		'runtime.realtime.preflight',
		__DIR__ . '/scheduled_task.php',
		3600,
		30,
		'64M',
		[],
		'',
		static function() : void {
			$sideEffectPath=(string)getenv('DATAPHYRE_RUNTIME_TEST_REALTIME_SIDE_EFFECT_PATH');
			if($sideEffectPath!=='') file_put_contents($sideEffectPath,'dispatch-callback-registered',LOCK_EX);
		},
	);
	if((string)getenv('DATAPHYRE_RUNTIME_TEST_INVALID_SCHEDULER_REGISTRATION')==='1'){
		\dataphyre\scheduling::run(
			'runtime.realtime.invalid',
			__DIR__.'/missing.scheduler.php',
			3600,
			30,
			'64M',
			[],
			'',
		);
	}
	$stateMutation=(string)getenv('DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION');
	if($stateMutation!==''){
		$schedulerRoot=rtrim((string)getenv('DATAPHYRE_SCHEDULER_STATE_ROOT'),'/\\').'/cache/scheduling';
		$definitionRoot=$schedulerRoot.'/runtime.realtime.preflight';
		$properties=$definitionRoot.'/properties.json';
		if($stateMutation==='missing-root'){
			@unlink($properties);
			@rmdir($definitionRoot);
			@rmdir($schedulerRoot);
		}elseif($stateMutation==='invalid-entry'){
			@mkdir($schedulerRoot.'/invalid-entry',0700,true);
		}elseif($stateMutation==='extra-state'){
			file_put_contents($definitionRoot.'/running_lock','forbidden',LOCK_EX);
		}elseif($stateMutation==='malformed-definition'){
			file_put_contents($properties,"[]\n",LOCK_EX);
		}elseif($stateMutation==='missing-dependency'){
			$definition=json_decode((string)file_get_contents($properties),true,32,JSON_THROW_ON_ERROR);
			$definition['dependencies']=[$schedulerRoot.'/missing-dependency.php'];
			file_put_contents($properties,json_encode($definition,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
		}elseif($stateMutation==='valid-dependency'){
			$definition=json_decode((string)file_get_contents($properties),true,32,JSON_THROW_ON_ERROR);
			$definition['dependencies']=[__DIR__.'/scheduled_task.php'];
			file_put_contents($properties,json_encode($definition,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
		}
	}
    \dataphyre\realtime::register(
        '/runtime/realtime',
		static function(array $handshake): array|false {
			$expectedToken=(string)(getenv('DATAPHYRE_RUNTIME_TEST_REALTIME_TOKEN') ?: 'runtime-fixture-token');
			$mode=(string)($handshake['query']['mode'] ?? 'valid');
			if(($handshake['origin'] ?? null)!=='https://runtime.test'
				|| ($handshake['query']['token'] ?? null)!==$expectedToken){
				return false;
			}
			return match($mode){
				'authorize_throw'=>throw new RuntimeException('fixture realtime authorization failure'),
				'authorize_unencodable'=>['fixture'=>'authorized','value'=>NAN],
				'authorize_oversized'=>['fixture'=>'authorized','value'=>str_repeat('x',8192)],
				'authorize_list'=>['authorized'],
				'valid','throw','invalid','too_many','invalid_cursor','unencodable','oversized'=>[
					'fixture'=>'authorized','mode'=>$mode,
				],
				default=>false,
			};
        },
		static function(array $authorization,?string $cursor): array {
			if($cursor!==null || ($authorization['fixture'] ?? null)!=='authorized'){
				return ['cursor'=>$cursor ?? 'delivered','events'=>[]];
			}
			return match($authorization['mode'] ?? 'valid'){
				'throw'=>throw new RuntimeException('fixture realtime event failure'),
				'invalid'=>['cursor'=>'invalid'],
				'too_many'=>['cursor'=>'overflow','events'=>array_fill(0,65,['type'=>'overflow'])],
				'invalid_cursor'=>['cursor'=>str_repeat('c',257),'events'=>[]],
				'unencodable'=>['cursor'=>'unencodable','events'=>[['value'=>NAN]]],
				'oversized'=>['cursor'=>'oversized','events'=>[['value'=>str_repeat('x',65536)]]],
				default=>['cursor'=>'delivered','events'=>[['type'=>'runtime.ready','pool'=>'realtime']]],
			};
		},
    );
}

$managedSchedulerBootstrap=defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')
	&& constant('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')==='scheduler';
if ((string) ($_SERVER['DATAPHYRE_RUNTIME_SCHEDULER_TICK'] ?? '') === '1' || $managedSchedulerBootstrap) { // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact-image fixture must observe the framework router's native request boundary."
    $tickPath = (string) getenv('DATAPHYRE_RUNTIME_TEST_TICK_PATH');
    if ($tickPath !== '') {
        file_put_contents($tickPath, (string) getmypid(), LOCK_EX);
    }
	$forgePath = (string) getenv('DATAPHYRE_RUNTIME_TEST_FORGE_PATH');
	if ($forgePath !== '') {
		$forgedSocket=@stream_socket_client(
			'unix:///run/dataphyre/control/runtime.sock',$forgeErrorNumber,$forgeError,0.2,STREAM_CLIENT_CONNECT,
		);
		$forged=is_resource($forgedSocket);
		if(is_resource($forgedSocket)) fclose($forgedSocket);
		file_put_contents($forgePath,$forged ? 'forged' : 'denied',LOCK_EX);
	}
	$activeCallbackBlockPath=(string)getenv('DATAPHYRE_TEST_ACTIVE_CALLBACK_STARTED_PATH');
	if($activeCallbackBlockPath!==''){
		foreach(['runtime.lifecycle.00-blocking','runtime.lifecycle.01-later'] as $schedulerName){
			\dataphyre\scheduling::run(
				$schedulerName,__DIR__.'/scheduled_task.php',3600,300,'64M',[],'',
			);
		}
	}else{
		\dataphyre\scheduling::run(
			'runtime.lifecycle',
			__DIR__ . '/scheduled_task.php',
			3600,
			30,
			'64M',
			[],
			'',
		);
	}
}

if((string)getenv('DATAPHYRE_RUNTIME_TEST_WEB_SLEEP')==='1'
	&& (string)($_GET['action'] ?? '')==='sleep'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact FPM topology proof holds all eight managed workers before killing one."
	usleep(750000);
}

$managedHealthPath=(string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH) ?: '/'); // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact managed-gateway fixture counts only the reserved dynamic health route."
$managedHealthCounter=(string)getenv('DATAPHYRE_RUNTIME_TEST_WEB_HEALTH_COUNTER_PATH');
if(rawurldecode($managedHealthPath)==='/health' && $managedHealthCounter!==''){
	$counter=fopen($managedHealthCounter,'c+');
	if(!is_resource($counter) || !flock($counter,LOCK_EX)) throw new RuntimeException('Managed health counter is unavailable.');
	$current=trim((string)stream_get_contents($counter));
	if(!ctype_digit($current)) $current='0';
	rewind($counter);ftruncate($counter,0);fwrite($counter,(string)((int)$current+1));fflush($counter);
	flock($counter,LOCK_UN);fclose($counter);
}

if((string)($_GET['action'] ?? '')==='oversized-response'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact gateway resource proof generates one byte beyond the fixed dynamic-response bound."
	header('Content-Type: text/plain');echo str_repeat('x',8388609);return;
}
if((string)($_GET['action'] ?? '')==='oversized-response-header'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact FastCGI proof splits one oversized response header across native records."
	header('X-Oversized: '.str_repeat('x',65537));echo 'must-not-pass';return;
}
if((string)($_GET['action'] ?? '')==='oversized-response-header-line'){ // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact FastCGI proof rejects an oversized individual response-header line."
	header('X-Oversized-Line: '.str_repeat('x',8193));echo 'must-not-pass';return;
}

header('Content-Type: application/json; charset=utf-8');
echo '{"status":"healthy","missing_environment_keys":[]}';
