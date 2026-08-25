<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/application_runtime_fixed_port_lock.php';

suite('Application runtime supervisor contract')
    ->contract('core.application-runtime-supervisor', 1)
    ->layer('integration')
    ->risk('critical')
    ->watches('module:core')
	->through('pid-one', 'privilege-boundary', 'signed-cadence', 'status-probe', 'realtime-ingress')
    ->isolation('case')
    ->tag('core', 'runtime', 'supervisor', 'security', 'release')
    ->group('framework-coverage');

test('supervisor owns private status and signs cadence before privilege-dropped pools boot', static function(Context $t): void {
    $kernel=dirname(__DIR__) . '/kernel';
    $supervisor=(string)file_get_contents($kernel . '/application_runtime_supervisor.php');
    $router=(string)file_get_contents($kernel . '/application_runtime_router.php');
    $probe=(string)file_get_contents($kernel . '/application_runtime_status_probe.php');
	$webGateway=(string)file_get_contents($kernel . '/application_runtime_web_gateway.php');
	$schedulerGateway=(string)file_get_contents($kernel . '/application_runtime_scheduler_gateway.php');
	$fpmConfig=(string)file_get_contents($kernel . '/application_runtime_php_fpm.conf');
	$childEnvironment=(string)file_get_contents($kernel . '/application_runtime_child_environment.php');
	$latch=(string)file_get_contents($kernel . '/application_runtime_activation_latch.php');
	$protocol=(string)file_get_contents($kernel . '/application_runtime_scheduler_protocol.php');
	$realtime=(string)file_get_contents($kernel . '/realtime.php');
	$realtimeServer=(string)file_get_contents($kernel . '/application_runtime_realtime_server.php');
	$realtimeProbe=(string)file_get_contents($kernel . '/application_runtime_realtime_probe.php');
	$releaseProbe=(string)file_get_contents($kernel . '/application_runtime_release_probe.php');
    $fixture=(string)file_get_contents(__DIR__ . '/fixtures/application_runtime_project/framework_bootstrap.php');

    $t->contains("getmypid() !== 1", $supervisor);
    $t->contains("posix_geteuid() !== 0", $supervisor);
	$t->contains("$" . "webHost='127.0.0.1';$" . "webPort=8083", $supervisor);
	$t->contains("$" . "schedulerSocket=DataphyreApplicationRuntimeSchedulerGateway::SOCKET", $supervisor);
	$t->contains("$" . "controlSocket='/run/dataphyre/control/runtime.sock'", $supervisor);
	$t->contains("$" . "realtimeHost='0.0.0.0';$" . "realtimePort=8080", $supervisor);
	foreach([
		'DATAPHYRE_RUNTIME_WEB_HOST','DATAPHYRE_RUNTIME_WEB_PORT',
		'DATAPHYRE_RUNTIME_SCHEDULER_HOST','DATAPHYRE_RUNTIME_SCHEDULER_PORT',
		'DATAPHYRE_RUNTIME_STATUS_HOST','DATAPHYRE_RUNTIME_STATUS_PORT',
		'DATAPHYRE_RUNTIME_REALTIME_HOST','DATAPHYRE_RUNTIME_REALTIME_PORT',
	] as $setting){
		$t->isFalse(str_contains($supervisor,"dataphyre_runtime_env('{$setting}'"));
		$t->isFalse(str_contains($supervisor,"dataphyre_runtime_integer('{$setting}'"));
	}
	$t->contains("stream_socket_server('unix://'", $supervisor);
	$t->isFalse(str_contains($supervisor,'tcp://127.0.0.1:8081'));
	$t->isFalse(str_contains($supervisor,'tcp://127.0.0.1:8082'));
    $t->contains('sodium_crypto_sign_keypair()', $supervisor);
    $t->contains('sodium_crypto_sign_detached', $protocol);
	$t->contains('dataphyre.scheduler_request.v2',$protocol);
	foreach(['register','callback','noop'] as $operation){
		$t->contains('/dataphyre/runtime/scheduler/'.$operation,$supervisor);
		$t->contains('/dataphyre/runtime/scheduler/'.$operation,$router);
	}
    $t->isFalse(str_contains($supervisor, 'DATAPHYRE_RUNTIME_TICK_PRIVATE_KEY'));
    $t->isFalse(str_contains($router, '/dataphyre/runtime/status'));
	$t->contains('/dataphyre/runtime/scheduler/claim',$schedulerGateway);
	$t->isFalse(str_contains($router,'/dataphyre/runtime/scheduler/claim'));
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::consume',$supervisor);
	$t->contains('dataphyre.application_runtime.v7',$supervisor);
	$t->contains("'scheduler_cycle_in_progress'=>",$supervisor);
	$t->contains("require_once __DIR__.'/application_runtime_activation_latch.php'",$supervisor);
	$t->contains('DataphyreApplicationRuntimeActivationLatch::restore()',$supervisor);
	$t->contains("[DataphyreApplicationRuntimeActivationLatch::class,'persist']",$supervisor);
	$t->contains('$persister($requested)',$supervisor);
	$t->contains('array &$runtime',$supervisor);
	$t->contains('?bool &$activationRequested',$supervisor);
	$t->contains('dataphyre_runtime_run_scheduler_cycle(',$supervisor);
	$t->contains('if($requested!==true) $nextTick=$startedAt+$interval',$supervisor);
	$t->contains("private const FILE=self::DIRECTORY.'/activation'",$latch);
	$t->contains("private const ROOT='/var/lib/dataphyre'",$latch);
	$t->contains("fopen($"."temporary,'x+b')",$latch);
	$t->contains('fsync($handle)',$latch);
	$t->contains('rename($temporary,self::FILE)',$latch);
	$t->contains("($"."stat['nlink'] ?? 0)===1",$latch);
	$t->contains("($"."stat['uid'] ?? -1)===0",$latch);
	$t->contains("($"."stat['gid'] ?? -1)===0",$latch);
	$t->contains('$permissions!==0700',$latch);
	$t->contains("===0600",$latch);
	$t->isFalse(str_contains($latch,'getenv('));
	$t->contains('dataphyre.scheduler_registration.v1',$supervisor);
	$t->contains('dataphyre.scheduler_callback.v1',$schedulerGateway);
	$t->contains('execute_managed_registration()',$router);
	$t->contains('DataphyreApplicationRuntimeSchedulerState::recordSuccess',$supervisor);
	$t->contains('DataphyreApplicationRuntimeSchedulerState::releaseClaim',$supervisor);
	$t->contains('DataphyreManagedRuntimeGracefulShutdown',$supervisor);
	$t->contains('dataphyre_runtime_require_not_stopping',$supervisor);
	$termHandler=strpos($supervisor,'pcntl_signal(SIGTERM,$stop)');
	$intHandler=strpos($supervisor,'pcntl_signal(SIGINT,$stop)');
	$usrOneHandler=strpos($supervisor,'pcntl_signal(SIGUSR1,');
	$usrTwoHandler=strpos($supervisor,'pcntl_signal(SIGUSR2,');
	$firstStartupBoundary=strpos($supervisor,'DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment();');
	$firstRoleSpawn=strpos($supervisor,"$" . "children['web']=dataphyre_runtime_spawn(");
	$t->isTrue(
		is_int($termHandler) && is_int($intHandler) && is_int($usrOneHandler) && is_int($usrTwoHandler)
		&& is_int($firstStartupBoundary) && is_int($firstRoleSpawn)
		&& max($termHandler,$intHandler,$usrOneHandler,$usrTwoHandler)<$firstStartupBoundary
		&& $firstStartupBoundary<$firstRoleSpawn,
		'all four PID 1 signal dispositions are installed before startup work and child creation',
	);
	$t->same(1,substr_count($supervisor,'pcntl_signal(SIGTERM,$stop)'));
	$t->same(1,substr_count($supervisor,'pcntl_signal(SIGINT,$stop)'));
	$t->isFalse(str_contains($supervisor,"@chown('/run/dataphyre'"));
	$t->isFalse(str_contains($supervisor,"@chgrp('/run/dataphyre'"));
	$t->isFalse(str_contains($supervisor,'@chown($directory'));
	$t->contains('dataphyre_runtime_assign_web_socket_group($directory)',$supervisor);
	$t->contains('dataphyre_runtime_lock_web_socket($observedSocketIdentity,$observedDirectoryIdentity)',$supervisor);
	$lockBoundary=strpos($supervisor,'function dataphyre_runtime_lock_web_socket(');
	$revokeMode=strpos($supervisor,'!@chmod($directory,0700)',$lockBoundary===false ? 0 : $lockBoundary);
	$restoreGroup=strpos($supervisor,'!@chgrp($directory,0)',$revokeMode===false ? 0 : $revokeMode);
	$openMode=strpos($supervisor,'!@chmod($directory,0711)',$restoreGroup===false ? 0 : $restoreGroup);
	$t->isTrue(is_int($lockBoundary) && is_int($revokeMode) && is_int($restoreGroup) && is_int($openMode)
		&& $lockBoundary<$revokeMode && $revokeMode<$restoreGroup && $restoreGroup<$openMode);
	$t->contains("($" . "decoded['contract'] ?? null)==='dataphyre.application_runtime.v7'",$probe);
	foreach(['deployment_application','framework_application','environment','release_id','environment_fingerprint'] as $identity){
		$t->contains("'{$identity}'=>",$supervisor);
		$t->contains("$" . "decoded['{$identity}']",$probe);
	}
	$t->contains("'environment_fingerprint'=>$" . "applicationEnvelope['environment_fingerprint']",$supervisor);
	$t->contains("is_bool($" . "decoded['scheduler_cycle_in_progress'] ?? null)",$probe);
	$t->contains('$validRegistration',$probe);
    $t->contains('unset($pending[$key])', $protocol);
    $obsoleteDescriptor=implode('_',['DATAPHYRE','RUNTIME','STATUS','FD']);
    $obsoleteStream=implode('', ['php://','fd/']);
    foreach([$supervisor,$router,$probe,$fixture] as $source) {
        $t->isFalse(str_contains($source,$obsoleteDescriptor));
        $t->isFalse(str_contains($source,$obsoleteStream));
    }
	$t->contains('unix:///run/dataphyre/control/runtime.sock',$fixture);
	$t->contains("$" . "forged=is_resource($" . "forgedSocket)",$fixture);
    $t->isFalse(str_contains($probe, "PHP_SAPI !== 'cli') {"));
	$t->contains("$" . "setpriv='/usr/bin/setpriv'",$supervisor);
	$t->contains("'--no-new-privs'",$supervisor);
	$t->contains("'--reuid=0'",$supervisor);
	$t->contains("'--bounding-set=-all,+kill,+setuid,+setgid'",$supervisor);
	$t->same(1,substr_count($supervisor,"'--bounding-set=-all,+kill,+setuid,+setgid'"));
	$t->contains("'--bounding-set=-all','--pdeathsig=SIGTERM'",$supervisor);
	$t->contains('dataphyre_runtime_inactive_capability_boundary',$supervisor);
	$t->contains("in_array(\$pool,['web','web-http-gateway','scheduler'],true)",$supervisor);
	$t->contains("@posix_kill(-\$group,\$signal)",$supervisor);
	$t->contains('application_runtime_web_gateway.php',$supervisor);
	$t->contains('application_runtime_scheduler_gateway.php',$supervisor);
	$t->contains('application_runtime_php_fpm.conf',$supervisor);
	$t->isFalse(is_file($kernel.'/application_runtime_cgi_gateway.php'));
	$t->isFalse(str_contains($supervisor,'PHP_CLI_SERVER_WORKERS'));
	$t->contains("['realtime','scheduler','web']",$supervisor);
	$t->contains("$" . "pool==='realtime'",$supervisor);
	$webSpawn=strpos($supervisor,"$" . "children['web']=dataphyre_runtime_spawn(");
	$webLock=strpos($supervisor,'dataphyre_runtime_wait_for_web_pool(',$webSpawn===false ? 0 : $webSpawn);
	$realtimeSpawn=strpos($supervisor,"$" . "children['realtime']=dataphyre_runtime_spawn(");
	$t->isTrue(is_int($webSpawn) && is_int($webLock) && is_int($realtimeSpawn)
		&& $webSpawn<$webLock && $webLock<$realtimeSpawn,'FPM is locked and attested before realtime can bootstrap');
	$t->isFalse(is_file($kernel.'/application_runtime_pool_launcher.php'));
	$t->contains("'/usr/local/bin/php-cgi'",$schedulerGateway);
	$t->isFalse(str_contains($webGateway,"'/usr/local/bin/php-cgi'"));
	$t->contains("'execution_model'=>'persistent-php-fpm'",$supervisor);
	$t->contains('listen = /run/dataphyre/web/php-fpm.sock',$fpmConfig);
	$t->contains('error_log = /var/log/dataphyre/php-fpm-error.log',$fpmConfig);
	$t->isFalse(str_contains($fpmConfig,'/proc/self/fd/2'));
	$t->contains('pm.max_children = 8',$fpmConfig);
	$t->contains('pm.max_requests = 500',$fpmConfig);
	$t->contains('application_runtime_fpm_environment.php',$fpmConfig);
	$t->contains('dataphyre.application_runtime_release_probe.v1',$releaseProbe);
	$t->contains('WARMUP_REQUEST_COUNT=3',$releaseProbe);
	$t->contains('WARM_REQUEST_COUNT=20',$releaseProbe);
	$t->contains('WARM_P95_BUDGET_MILLISECONDS=750',$releaseProbe);
	$t->contains('CONCURRENT_REQUEST_COUNT=8',$releaseProbe);
	$t->contains('CONCURRENT_BUDGET_MILLISECONDS=3000',$releaseProbe);
	$t->contains("$" . "cadence['count']<1 || $" . "cadence['last_result']!=='ok'",$releaseProbe);
	$t->isFalse(str_contains($schedulerGateway,"'-n'"));
	$t->contains('public const INHERITED_FD=198',$childEnvironment);
	$t->contains('dataphyre_open_inherited_environment_fd',$childEnvironment);
	$t->contains('final class realtime', $realtime);
	$t->contains('public static function register(string $path, callable $authorize, callable $events)', $realtime);
	$t->contains("private const PUBLIC_PORT=8080", $realtimeServer);
	$t->contains("private const WEB_PORT=8083", $realtimeServer);
	$t->contains('MAX_CONNECTIONS=256', $realtimeServer);
	$t->contains('MAX_FRAME_BYTES=65536', $realtimeServer);
	$t->contains('MAX_PROXY_BUFFER_BYTES=1048576', $realtimeServer);
	$t->contains("$" . "client['pong_deadline']", $realtimeServer);
	$t->contains("$" . "this->routes[$" . "path]['authorize']", $realtimeServer);
	$t->contains("PROBE_PATH='/dataphyre/runtime/realtime/probe'",$realtimeServer);
	$t->contains("LIVENESS_PATH='/.dataphyre/live'",$realtimeServer);
	$t->contains("'origin'=>'https://dataphyre.invalid'",$realtimeServer);
	$t->contains("'application_authorization_rejections'=>true",$realtimeServer);
	$t->contains("if($" . "pool!=='realtime') unset($" . "applicationEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET'])",$supervisor);
	$t->contains('dataphyre.application_realtime_probe.v1',$realtimeProbe);
	$t->contains("unix:///run/dataphyre/control/runtime.sock",$realtimeProbe);
	$t->contains("GET /dataphyre/runtime/realtime/probe HTTP/1.1",$realtimeProbe);
	$t->isFalse(str_contains($realtimeProbe,'getenv('));
	$t->isFalse(str_contains($realtimeServer, 'shell_exec'));
	$t->isFalse(str_contains($realtimeServer, 'exec('));

	foreach([
		'supervisor_uid','supervisor_gid','supplementary_gids','cap_inheritable','cap_permitted',
		'cap_eff','cap_bounding','cap_ambient','no_new_privileges',
		'role','listen_host','listen_port','parent_pid','start_time_ticks','process_group_id',
	] as $evidence) {
        $t->contains($evidence, $supervisor);
        $t->contains($evidence, $probe);
	}
	foreach([$supervisor,$realtimeServer,$realtimeProbe] as $source) $t->contains('registration_sha256',$source);
	$t->contains("($" . "decoded['supervisor_pid'] ?? null)===1",$probe);
	$t->contains("'persistent-php-fpm'",$probe);
	$t->contains("'one-request-per-process-cgi'",$probe);
	$t->contains("'single-exec-realtime'",$probe);
	$t->contains("($" . "value['no_new_privileges'] ?? null)===true",$probe);
	$t->contains("$" . "validRealtimePool($" . "decoded['realtime'] ?? null)",$probe);
})->tag('source-contract');

test('supervisor helpers enforce their exact bounded environment private HTTP and frame contracts',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_supervisor.php';
	putenv('DATAPHYRE_RUNTIME_EXACT_HELPER');
	$t->same('fallback',dataphyre_runtime_env('DATAPHYRE_RUNTIME_EXACT_HELPER','fallback'));
	$t->throws(static fn()=>dataphyre_runtime_env('DATAPHYRE_RUNTIME_EXACT_HELPER'),RuntimeException::class);
	putenv('DATAPHYRE_RUNTIME_EXACT_HELPER= 17 ');
	$t->same('17',dataphyre_runtime_env('DATAPHYRE_RUNTIME_EXACT_HELPER'));
	$t->same(17,dataphyre_runtime_integer('DATAPHYRE_RUNTIME_EXACT_HELPER',1,1,20));
	putenv('DATAPHYRE_RUNTIME_EXACT_HELPER=invalid');
	$t->throws(static fn()=>dataphyre_runtime_integer('DATAPHYRE_RUNTIME_EXACT_HELPER',1,1,20),RuntimeException::class);
	putenv('DATAPHYRE_RUNTIME_EXACT_HELPER=21');
	$t->throws(static fn()=>dataphyre_runtime_integer('DATAPHYRE_RUNTIME_EXACT_HELPER',1,1,20),RuntimeException::class);

	$t->throws(static fn()=>dataphyre_runtime_spawn(
		__FILE__,dirname(__DIR__,4),'invalid','127.0.0.1',8083,[],[],
	),RuntimeException::class);
	$t->same(null,dataphyre_runtime_scheduler_registration_summary(null));
	$t->throws(static fn()=>dataphyre_runtime_scheduler_registration_summary([]),RuntimeException::class);
	$definition=static fn(string $name): array=>[
		'name'=>$name,'task_sha256'=>'sha256:'.str_repeat('a',64),'dependency_sha256'=>[],
		'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$definitions=[$definition('fixture.first'),$definition('fixture.second')];
	$report=static function(array $definitions) : array {
		$count=count($definitions);
		return [
			'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,
			'registration_attempt_count'=>$count,'registration_accepted_count'=>$count,'registration_failure_count'=>0,
			'definition_count'=>$count,
			'definition_sha256'=>'sha256:'.hash('sha256',json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
			'definitions'=>$definitions,
		];
	};
	$validReport=$report($definitions);
	$t->isTrue(dataphyre_runtime_scheduler_registration_valid($validReport));
	foreach([
		['contract'=>'wrong']+$validReport,
		array_replace($validReport,['registration_attempt_count'=>-1]),
		array_replace($validReport,['registration_failure_count'=>1]),
		array_replace($validReport,['definition_count'=>1]),
		$report([['invalid'=>true]]),
		$report([$definition('fixture.second'),$definition('fixture.first')]),
		array_replace($validReport,['definition_sha256'=>'sha256:'.str_repeat('f',64)]),
	] as $invalidReport){$t->isFalse(dataphyre_runtime_scheduler_registration_valid($invalidReport));}
	$runtime=['request_counter'=>0];$t->same(1,dataphyre_runtime_next_scheduler_counter($runtime));
	foreach([-1,PHP_INT_MAX,'1'] as $counter){
		$invalidRuntime=['request_counter'=>$counter];
		$t->throws(static function() use (&$invalidRuntime): void {dataphyre_runtime_next_scheduler_counter($invalidRuntime);},RuntimeException::class);
	}
	$t->throws(static fn()=>dataphyre_runtime_pool_identity(getmypid(),'1','invalid','127.0.0.1',8083),RuntimeException::class);
	$t->throws(static fn()=>dataphyre_runtime_pool_identity(999999,'1','web','127.0.0.1',8083),RuntimeException::class);

	$readRequest=static function(string $wire): ?array {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		fwrite($pair[1],$wire);stream_socket_shutdown($pair[1],STREAM_SHUT_WR);
		try{return dataphyre_runtime_read_private_request($pair[0]);}
		finally{fclose($pair[0]);fclose($pair[1]);}
	};
	$t->same(null,$readRequest("TRACE / HTTP/1.1\r\n\r\n"));
	$t->same(null,$readRequest("GET /dataphyre/runtime/status HTTP/1.1\r\nBad Header\r\n\r\n"));
	$t->same(null,$readRequest("GET /dataphyre/runtime/status HTTP/1.1\r\nX-One: a\r\nX-One: b\r\n\r\n"));
	$t->same(null,$readRequest("GET /dataphyre/runtime/status HTTP/1.1\r\nX-One: a\r\n"));
	$largeHeaders="GET /dataphyre/runtime/status HTTP/1.1\r\n";
	for($i=0;$i<5;$i++) $largeHeaders.='X-'.$i.': '.str_repeat('x',1800)."\r\n";
	$t->same(null,$readRequest($largeHeaders."\r\n"));
	$t->same(null,$readRequest("POST /dataphyre/runtime/scheduler/claim HTTP/1.1\r\nContent-Length: 0\r\n\r\n"));
	$t->same(null,$readRequest("POST /dataphyre/runtime/scheduler/claim HTTP/1.1\r\nContent-Length: 3\r\n\r\nx"));
	$t->same(
		['method'=>'GET','path'=>'/dataphyre/runtime/status','body'=>''],
		$readRequest("GET /dataphyre/runtime/status HTTP/1.1\r\n\r\n"),
	);
	$t->same(
		['method'=>'POST','path'=>'/dataphyre/runtime/scheduler/claim','body'=>'{}'],
		$readRequest("POST /dataphyre/runtime/scheduler/claim HTTP/1.1\r\nContent-Length: 2\r\n\r\n{}"),
	);
	$dripPair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$dripPid=pcntl_fork();
	if($dripPid===-1) throw new RuntimeException('Private request deadline fixture could not fork.');
	if($dripPid===0){
		fclose($dripPair[0]);
		foreach(str_split("GET /dataphyre/runtime/status HTTP/1.1\r\n") as $byte){
			if(@fwrite($dripPair[1],$byte)!==1) break;usleep(100000);
		}
		fclose($dripPair[1]);exit(0);
	}
	fclose($dripPair[1]);$dripStarted=hrtime(true);
	$t->same(null,dataphyre_runtime_read_private_request($dripPair[0]));
	$dripElapsed=(hrtime(true)-$dripStarted)/1_000_000_000;fclose($dripPair[0]);pcntl_waitpid($dripPid,$dripStatus);
	$t->isTrue($dripElapsed>=0.20 && $dripElapsed<0.75,'private request absolute deadline elapsed '.$dripElapsed.' seconds');

	$respond=static function(int $status,array $payload): string {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		dataphyre_runtime_private_response($pair[0],$status,$payload);stream_socket_shutdown($pair[0],STREAM_SHUT_WR);
		$response=(string)stream_get_contents($pair[1]);fclose($pair[0]);fclose($pair[1]);return $response;
	};
	$t->contains('200 OK',$respond(200,['ok'=>true]));
	$t->contains('409 Conflict',$respond(409,['ok'=>false]));
	$t->contains('404 Not Found',$respond(404,['ok'=>false]));
	$t->throws(static fn()=>dataphyre_runtime_private_response(fopen('php://temp','w+'),200,['body'=>str_repeat('x',530000)]),RuntimeException::class);
	$readOnly=fopen('/dev/null','rb');
	$t->throws(static fn()=>dataphyre_runtime_private_response($readOnly,200,['ok'=>true]),Throwable::class);
	fclose($readOnly);
	$blockedPair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$blockedSocket=socket_import_stream($blockedPair[0]);
	if($blockedSocket instanceof Socket) socket_set_option($blockedSocket,SOL_SOCKET,SO_SNDBUF,4096);
	$blockedStarted=hrtime(true);
	$t->throws(
		static fn()=>dataphyre_runtime_private_response($blockedPair[0],200,['body'=>str_repeat('x',400000)]),
		DataphyreManagedRuntimeControlPeerFailure::class,
	);
	$blockedElapsed=(hrtime(true)-$blockedStarted)/1_000_000_000;fclose($blockedPair[0]);fclose($blockedPair[1]);
	$t->isTrue($blockedElapsed>=0.20 && $blockedElapsed<0.75,'private response absolute deadline elapsed '.$blockedElapsed.' seconds');
	$burst=$t->workspace('private-control-burst');$burstSocket=$burst->path('control.sock');
	$burstListener=stream_socket_server('unix://'.$burstSocket,$burstErrorNumber,$burstError,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
	if(!is_resource($burstListener)) throw new RuntimeException("Private burst fixture could not bind: {$burstErrorNumber} {$burstError}");
	$burstClients=[];
	for($index=0;$index<6;$index++){
		$client=stream_socket_client('unix://'.$burstSocket,$burstErrorNumber,$burstError,1,STREAM_CLIENT_CONNECT);
		if(!is_resource($client)) throw new RuntimeException('Private burst fixture could not connect.');
		fwrite($client,"TRACE / HTTP/1.1\r\n\r\n");$burstClients[]=$client;
	}
	$burstRuntime=[];$burstPending=[];dataphyre_runtime_serve_status($burstListener,$burstRuntime,$burstPending,str_repeat('x',32));
	$queued=[];while(is_resource($queuedConnection=@stream_socket_accept($burstListener,0))){$queued[]=$queuedConnection;}
	$t->count(2,$queued,'one status poll accepted only its fixed four-peer budget');
	foreach([...$burstClients,...$queued] as $client) fclose($client);fclose($burstListener);@unlink($burstSocket);

	$frameStream=fopen('php://temp','w+');
	dataphyre_runtime_write_websocket_frame($frameStream,0x9,'ping');rewind($frameStream);
	$t->same(9,ord((string)fread($frameStream,1))&0x0f);fclose($frameStream);
	$t->throws(static fn()=>dataphyre_runtime_write_websocket_frame(fopen('php://temp','w+'),0x1,str_repeat('x',126)),RuntimeException::class);
	$readFrame=static function(string $bytes,string $initial=''): array {
		$stream=fopen('php://temp','w+');fwrite($stream,$bytes);rewind($stream);$buffer=$initial;
		try{return dataphyre_runtime_read_websocket_frame($stream,$buffer);}
		finally{fclose($stream);}
	};
	$t->same(['opcode'=>1,'payload'=>'ok'],$readFrame(chr(0x81).chr(2).'ok'));
	$t->same(126,strlen($readFrame(chr(0x82).chr(126).pack('n',126).str_repeat('x',126))['payload']));
	foreach([
		chr(0x01).chr(0),
		chr(0x81).chr(0x80),
		chr(0x82).chr(127).pack('N2',1,0),
		chr(0x82).chr(127).pack('N2',0,65537),
	] as $invalidFrame){$t->throws(static fn()=>$readFrame($invalidFrame),RuntimeException::class);}
	$t->throws(static fn()=>$readFrame('',chr(0x81)),RuntimeException::class);
	$t->throws(static fn()=>$readFrame(
		str_repeat('x',65536),chr(0x82).chr(127).pack('N2',0,65536),
	),RuntimeException::class);
	if(!is_dir('/run/dataphyre') && !mkdir('/run/dataphyre',0700)){
		throw new RuntimeException('Supervisor helper runtime parent could not be created.');
	}
	if(!chown('/run/dataphyre',0) || !chgrp('/run/dataphyre',0) || !chmod('/run/dataphyre',0700)){
		throw new RuntimeException('Supervisor helper runtime parent could not be prepared.');
	}
	$bindWebSocket=static function(): array {
		$preparation=dataphyre_runtime_prepare_web_socket();$socket='/run/dataphyre/web/php-fpm.sock';
		$previousUmask=umask(0077);
		try{$listener=stream_socket_server('unix://'.$socket,$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);}
		finally{umask($previousUmask);}
		if(!is_resource($listener) || !chown($socket,10001) || !chgrp($socket,10001) || !chmod($socket,0600)){
			throw new RuntimeException('Web cleanup transition fixture could not bind.');
		}
		$stat=lstat($socket);
		return [
			$listener,['dev'=>$stat['dev'],'ino'=>$stat['ino']],
			$preparation['directory'],$preparation['parent'],
		];
	};
	foreach([0000,0100,0200,0300,0400,0500,0600,0700] as $partialMode){
		if(!mkdir('/run/dataphyre/web',0700) || !chown('/run/dataphyre/web',0)
			|| !chgrp('/run/dataphyre/web',0) || !chmod('/run/dataphyre/web',$partialMode)){
			throw new RuntimeException('Web restrictive-umask restart fixture could not be prepared.');
		}
		$preparation=dataphyre_runtime_prepare_web_socket();$prepared=lstat('/run/dataphyre/web');
		$t->same(0730,($prepared['mode'] ?? 0)&0777);$t->same(0,$prepared['uid']);$t->same(10001,$prepared['gid']);
		dataphyre_runtime_cleanup_web_socket(null,$preparation['directory'],$preparation['parent']);
		$t->isFalse(file_exists('/run/dataphyre/web'));
	}
	if(!mkdir('/run/dataphyre/web',0700) || !chown('/run/dataphyre/web',10001)
		|| !chgrp('/run/dataphyre/web',10001) || !chmod('/run/dataphyre/web',0700)){
		throw new RuntimeException('Legacy tenant-owned web directory fixture could not be prepared.');
	}
	$legacyDirectory=lstat('/run/dataphyre/web');
	$t->throws(static fn()=>dataphyre_runtime_prepare_web_socket(),RuntimeException::class);
	$unchangedLegacyDirectory=lstat('/run/dataphyre/web');
	$t->same($legacyDirectory['ino'],$unchangedLegacyDirectory['ino']);
	$t->same(10001,$unchangedLegacyDirectory['uid'],'tenant-owned legacy state is rejected without mutation');
	if(!chown('/run/dataphyre/web',0) || !chgrp('/run/dataphyre/web',0) || !rmdir('/run/dataphyre/web')){
		throw new RuntimeException('Legacy tenant-owned web directory fixture could not be removed.');
	}
	foreach([[0,10001,0730],[0,10001,0700],[0,0,0700],[0,0,0711]] as [$uid,$gid,$mode]){
		[$listener,$socketIdentity,$directoryIdentity,$parentIdentity]=$bindWebSocket();
		if(!chown('/run/dataphyre/web',$uid) || !chgrp('/run/dataphyre/web',$gid) || !chmod('/run/dataphyre/web',$mode)){
			throw new RuntimeException('Web cleanup transition state could not be prepared.');
		}
		dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);
		$t->isFalse(file_exists('/run/dataphyre/web/php-fpm.sock'));
		$t->isFalse(file_exists('/run/dataphyre/web'));fclose($listener);
		$t->same(0700,(lstat('/run/dataphyre')['mode'] ?? 0)&0777);
	}
	[$listener,$socketIdentity,$directoryIdentity,$parentIdentity]=$bindWebSocket();
	dataphyre_runtime_cleanup_web_socket(
		$socketIdentity,['dev'=>$directoryIdentity['dev'],'ino'=>$directoryIdentity['ino']+1],$parentIdentity,
	);
	$t->isTrue(file_exists('/run/dataphyre/web/php-fpm.sock'));
	$t->isTrue(file_exists('/run/dataphyre/web'));
	dataphyre_runtime_cleanup_web_socket(
		['dev'=>$socketIdentity['dev'],'ino'=>$socketIdentity['ino']+1],$directoryIdentity,$parentIdentity,
	);
	$t->isTrue(file_exists('/run/dataphyre/web/php-fpm.sock'));
	$t->isTrue(file_exists('/run/dataphyre/web'));
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);fclose($listener);
	$t->isFalse(file_exists('/run/dataphyre/web/php-fpm.sock'));
	$t->isFalse(file_exists('/run/dataphyre/web'));

	$parentOriginal='/run/dataphyre-parent-original';$parentSentinel='/run/dataphyre-parent-sentinel';
	$parentStat=lstat('/run/dataphyre');$parentIdentity=['dev'=>$parentStat['dev'],'ino'=>$parentStat['ino']];
	if(!rename('/run/dataphyre',$parentOriginal) || !mkdir($parentSentinel,0755) || !symlink($parentSentinel,'/run/dataphyre')){
		throw new RuntimeException('Web cleanup parent symlink fixture could not be prepared.');
	}
	dataphyre_runtime_cleanup_web_socket(null,null,$parentIdentity);
	$t->same(0755,(lstat($parentSentinel)['mode'] ?? 0)&0777,'cleanup does not follow a substituted parent symlink');
	if(!unlink('/run/dataphyre') || !rename($parentOriginal,'/run/dataphyre') || !rmdir($parentSentinel)){
		throw new RuntimeException('Web cleanup parent symlink fixture could not be restored.');
	}
	if(!rename('/run/dataphyre',$parentOriginal) || !mkdir('/run/dataphyre',0711)
		|| !chown('/run/dataphyre',0) || !chgrp('/run/dataphyre',0) || !chmod('/run/dataphyre',0711)){
		throw new RuntimeException('Web cleanup parent replacement fixture could not be prepared.');
	}
	dataphyre_runtime_cleanup_web_socket(null,null,$parentIdentity);
	$t->same(0711,(lstat('/run/dataphyre')['mode'] ?? 0)&0777,'cleanup does not mutate a replacement parent inode');
	if(!rmdir('/run/dataphyre') || !rename($parentOriginal,'/run/dataphyre')){
		throw new RuntimeException('Web cleanup parent replacement fixture could not be restored.');
	}

	[$listener,$socketIdentity,$directoryIdentity,$parentIdentity]=$bindWebSocket();
	if(!rename('/run/dataphyre/web','/run/dataphyre/web-original') || !mkdir('/run/dataphyre/web',0711)
		|| !chown('/run/dataphyre/web',0) || !chgrp('/run/dataphyre/web',0) || !chmod('/run/dataphyre/web',0711)){
		throw new RuntimeException('Web cleanup directory replacement fixture could not be prepared.');
	}
	$replacementDirectory=lstat('/run/dataphyre/web');
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);
	$unchangedReplacement=lstat('/run/dataphyre/web');
	$t->same($replacementDirectory['ino'],$unchangedReplacement['ino']);
	$t->same(0711,($unchangedReplacement['mode'] ?? 0)&0777,'cleanup does not mutate a replacement web directory');
	if(!rmdir('/run/dataphyre/web') || !rename('/run/dataphyre/web-original','/run/dataphyre/web')){
		throw new RuntimeException('Web cleanup directory replacement fixture could not be restored.');
	}
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);fclose($listener);

	[$listener,$socketIdentity,$directoryIdentity,$parentIdentity]=$bindWebSocket();
	if(!rename('/run/dataphyre/web/php-fpm.sock','/run/dataphyre/web/php-fpm.original.sock')){
		throw new RuntimeException('Web cleanup socket replacement fixture could not preserve the original socket.');
	}
	$replacementListener=stream_socket_server(
		'unix:///run/dataphyre/web/php-fpm.sock',$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
	);
	if(!is_resource($replacementListener) || !chown('/run/dataphyre/web/php-fpm.sock',10001)
		|| !chgrp('/run/dataphyre/web/php-fpm.sock',10001) || !chmod('/run/dataphyre/web/php-fpm.sock',0600)){
		throw new RuntimeException('Web cleanup socket replacement fixture could not bind.');
	}
	$replacementSocket=lstat('/run/dataphyre/web/php-fpm.sock');
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);
	$unchangedSocket=lstat('/run/dataphyre/web/php-fpm.sock');
	$t->same($replacementSocket['ino'],$unchangedSocket['ino'],'cleanup does not unlink a replacement web socket');
	fclose($replacementListener);unlink('/run/dataphyre/web/php-fpm.sock');
	if(!rename('/run/dataphyre/web/php-fpm.original.sock','/run/dataphyre/web/php-fpm.sock')){
		throw new RuntimeException('Web cleanup socket replacement fixture could not be restored.');
	}
	dataphyre_runtime_cleanup_web_socket($socketIdentity,$directoryIdentity,$parentIdentity);fclose($listener);
	$t->isFalse(file_exists('/run/dataphyre/web'));

	$serveResponse=static function(string $wire,callable $client) use ($t): mixed {
		$directory='/run/dataphyre/scheduler';$socket=DataphyreApplicationRuntimeSchedulerGateway::SOCKET;
		$directoryIdentity=dataphyre_runtime_prepare_root_socket($directory,$socket);$previousUmask=umask(0077);
		try{$listener=stream_socket_server('unix://'.$socket,$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);}
		finally{umask($previousUmask);}
		if(!is_resource($listener) || !chmod($socket,0600)) throw new RuntimeException('Supervisor response fixture could not bind.');
		$stat=lstat($socket);$socketIdentity=['dev'=>$stat['dev'],'ino'=>$stat['ino']];
		$pid=pcntl_fork();
		if($pid===-1){fclose($listener);throw new RuntimeException('Supervisor response fixture could not fork.');}
		if($pid===0){
			register_shutdown_function(static function() use ($directory,$socket,$socketIdentity,$directoryIdentity): void {
				dataphyre_runtime_cleanup_root_socket($directory,$socket,$socketIdentity,$directoryIdentity);
			});
			$connection=@stream_socket_accept($listener,3);
			if(is_resource($connection)){
				stream_set_timeout($connection,2,0);$request='';
				while(!str_contains($request,"\r\n\r\n")){
					$chunk=fread($connection,8192);if(!is_string($chunk) || $chunk==='') break;$request.=$chunk;
				}
				$headerEnd=strpos($request,"\r\n\r\n");
				if($headerEnd!==false && preg_match('/\r\nContent-Length:\s*([0-9]+)\r\n/i',$request,$matches)===1){
					$required=$headerEnd+4+(int)$matches[1];
					while(strlen($request)<$required){$chunk=fread($connection,8192);if(!is_string($chunk)||$chunk==='') break;$request.=$chunk;}
				}
				$offset=0;while($offset<strlen($wire)){$written=@fwrite($connection,substr($wire,$offset));if(!is_int($written)||$written<1) break;$offset+=$written;}
				fclose($connection);
			}
			fclose($listener);exit(0);
		}
		fclose($listener);
		try{return $client($socket);}
		finally{pcntl_waitpid($pid,$status);$t->same(0,pcntl_wexitstatus($status));}
	};
	$response=static fn(int $status,string $body): string=>
		"HTTP/1.1 {$status} Fixture\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
	$keypair=sodium_crypto_sign_keypair();$secretKey=sodium_crypto_sign_secretkey($keypair);$publicKey=sodium_crypto_sign_publickey($keypair);
	$identity=[
		'deployment_application'=>'Store:North_2-Beta','framework_application'=>'Fixture','environment'=>'staging',
		'release_id'=>'dep_'.str_repeat('a',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
	];
	$generation='gen_'.str_repeat('c',32);$pending=[];$runtime=['active'=>true];$activation=null;$nextTick=0.0;
	$statusState=$t->workspace('supervisor-helper-status');$statusSocket=$statusState->path('control.sock');
	$statusListener=stream_socket_server('unix://'.$statusSocket,$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
	if(!is_resource($statusListener)) throw new RuntimeException("Supervisor status fixture could not bind: {$errorNumber} {$error}");
	stream_set_blocking($statusListener,false);
	$callbackResult=$serveResponse($response(200,'{"contract":"dataphyre.scheduler_callback.v1","ok":true}'),static fn(string $socket): array=>
		dataphyre_runtime_scheduler_request(
			$socket,'callback',$identity,$generation,1,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,'fixture.callback','sha256:'.str_repeat('d',64),1000,
		)
	);
	$t->same(['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true],$callbackResult);$t->same([],$pending);
	$stopRequested=true;$stopIssued=null;
	$observedWebSocket=null;$observedWebDirectory=null;$observedSchedulerSocket=null;
	$t->throws(static function() use (&$stopRequested,&$observedWebSocket,&$observedWebDirectory): void {
		dataphyre_runtime_wait_for_web_pool(999999,$observedWebSocket,$observedWebDirectory,$stopRequested);
	},DataphyreManagedRuntimeGracefulShutdown::class);
	$t->throws(static function() use (&$stopRequested,&$observedSchedulerSocket): void {
		dataphyre_runtime_wait_for_scheduler_socket(999999,$observedSchedulerSocket,$stopRequested);
	},DataphyreManagedRuntimeGracefulShutdown::class);
	$t->same(null,$observedWebSocket);$t->same(null,$observedWebDirectory);$t->same(null,$observedSchedulerSocket);
	$t->throws(static function() use (
		$identity,$generation,$secretKey,$publicKey,$statusListener,&$runtime,&$pending,&$activation,&$nextTick,
		&$stopIssued,&$stopRequested,
	): void {
		dataphyre_runtime_scheduler_request(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,'noop',$identity,$generation,2,$secretKey,$publicKey,
			$statusListener,$runtime,$pending,$activation,$nextTick,null,null,null,$stopIssued,$stopRequested,
		);
	},DataphyreManagedRuntimeGracefulShutdown::class);
	$t->same(null,$stopIssued);$t->same([],$pending);$stopRequested=false;
	$t->throws(static fn()=>dataphyre_runtime_scheduler_request(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,'noop',$identity,$generation,3,$secretKey,$publicKey,$statusListener,$runtime,$pending,
		$activation,$nextTick,
	),RuntimeException::class);
	$t->same([],$pending);
	$t->throws(static fn()=>$serveResponse(
		$response(200,str_repeat('x',DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES+1)),
		static fn(string $socket): array=>dataphyre_runtime_scheduler_request(
			$socket,'noop',$identity,$generation,3,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,
		),
	),RuntimeException::class);
	$t->throws(static fn()=>$serveResponse(
		$response(500,'{}'),
		static fn(string $socket): array=>dataphyre_runtime_scheduler_request(
			$socket,'noop',$identity,$generation,4,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,
		),
	),RuntimeException::class);
	foreach(['noop','callback','registration'] as $index=>$kind){
		$t->throws(static fn()=>$serveResponse(
			$response(200,'{"ok":true}'),
			static fn(string $socket): array=>dataphyre_runtime_scheduler_request(
				$socket,$kind,$identity,$generation,5+$index,$secretKey,$publicKey,$statusListener,$runtime,$pending,
				$activation,$nextTick,
				$kind==='callback' ? 'fixture.callback' : null,
				$kind==='callback' ? 'sha256:'.str_repeat('d',64) : null,
				$kind==='callback' ? 1000 : null,
			),
		),RuntimeException::class);
	}
	foreach(['registration','callback'] as $index=>$kind){
		$issued=DataphyreApplicationRuntimeSchedulerProtocol::issue(
			$kind,$identity,$generation,10+$index,$secretKey,
			$kind==='callback' ? 'fixture.callback' : null,
			$kind==='callback' ? 'sha256:'.str_repeat('d',64) : null,
			$kind==='callback' ? 1000 : null,
		);
		$t->same(null,$serveResponse(
			$response(404,'{"ok":false}'),
			static function(string $socket) use (
				$issued,$statusListener,&$runtime,&$pending,$publicKey,&$activation,&$nextTick,
			): mixed {
				dataphyre_runtime_require_scheduler_replay_rejection(
					$socket,$issued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
				);
				return null;
			},
		));
	}
	$invalidIssued=$issued;$invalidIssued['signature']=str_repeat('A',86);
	$t->throws(static fn()=>dataphyre_runtime_require_scheduler_replay_rejection(
		'/invalid-scheduler.sock',$invalidIssued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
	),RuntimeException::class);
	$t->throws(static fn()=>$serveResponse(
		$response(200,'{"ok":true}'),
		static function(string $socket) use (
			$issued,$statusListener,&$runtime,&$pending,$publicKey,&$activation,&$nextTick,
		): mixed {
			dataphyre_runtime_require_scheduler_replay_rejection(
				$socket,$issued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
			);
			return null;
		},
	),RuntimeException::class);
	fclose($statusListener);@unlink($statusSocket);sodium_memzero($secretKey);

	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	try{
		$listener=stream_socket_server('tcp://127.0.0.1:8080',$errorNumber,$error);
		if(!is_resource($listener)) throw new RuntimeException("Realtime failure fixture could not bind: {$errorNumber} {$error}");
		$pid=pcntl_fork();
		if($pid===-1){fclose($listener);throw new RuntimeException('Realtime failure fixture could not fork.');}
		if($pid===0){
			$connection=@stream_socket_accept($listener,3);$request='';
			if(is_resource($connection)){
				while(!str_contains($request,"\r\n\r\n")){$chunk=fread($connection,4096);if(!is_string($chunk)||$chunk==='') break;$request.=$chunk;}
				preg_match('/^Sec-WebSocket-Key:\s*([^\r\n]+)\r?$/mi',$request,$matches);
				$accept=base64_encode(sha1(trim((string)($matches[1] ?? '')).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
				fwrite($connection,"HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n\x01\x00");
				fclose($connection);
			}
			fclose($listener);exit(0);
		}
		fclose($listener);$probe=dataphyre_runtime_realtime_probe();pcntl_waitpid($pid,$status);
		$t->same(false,$probe['ok']);$t->same(0,pcntl_wexitstatus($status));
	}finally{dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);}
})->tag('supervisor','helpers','private-http','websocket','bounds','positive','negative');

test('web socket bind and restart stay inside the exact e0 supervisor capability ceiling',static function(Context $t): void {
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	try{
		$result=$t->process([
			'/usr/bin/setpriv','--reuid=0','--regid=0','--groups=0','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all,+kill,+setuid,+setgid',
			PHP_BINARY,__DIR__.'/fixtures/application_runtime_web_socket_capability_probe.php',dirname(__DIR__).'/kernel',
		],working_directory:dirname(__DIR__,4),timeout_millis:15000);
		$t->processSucceeded($result,$result->stderr());$evidence=$result->json();
		$t->same('dataphyre.web_socket_capability_probe.v1',$evidence['contract']);$t->same(true,$evidence['ok']);
		$t->same('00000000000000e0',$evidence['capabilities']['cap_effective']);
		$t->same('00000000000000e0',$evidence['capabilities']['cap_bounding']);
		$t->same(['uid'=>0,'gid'=>10001,'mode'=>0730],$evidence['prepared']);
		$t->same(['uid'=>10001,'gid'=>10001,'mode'=>0600],$evidence['socket']);
		$t->same(['uid'=>0,'gid'=>0,'mode'=>0711],$evidence['locked']);
		$t->same(['unlink'=>false,'rename'=>false,'replacement_bound'=>false],$evidence['tenant_mutation']);
		$t->same(true,$evidence['cleanup']);$t->same(true,$evidence['restart']);
		$t->same(true,$evidence['effective_gid_restored']);
	}finally{dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);}
})->tag('supervisor','web','socket','capabilities','restart','exact-image')->maxMillis(20000)
	->skipUnless(
		function_exists('posix_geteuid') && posix_geteuid()===0
			&& getenv('DATAPHYRE_TEST_CONTAINER_ROOT')==='1' && is_executable('/usr/bin/setpriv'),
		'Requires the canonical root test image with the exact e0 supervisor capability ceiling.',
	);

test('realtime registry accepts exact application callbacks and seals deterministic evidence', static function(Context $t): void {
	$kernel=dirname(__DIR__) . '/kernel';
	$script=<<<'PHP'
require $argv[1].'/realtime.php';
putenv('DATAPHYRE_RUNTIME_POOL=realtime-preflight');
\dataphyre\realtime::register('/events/live',static fn(array $handshake): array|false=>['subject'=>'fixture'],static fn(array $authorization,?string $cursor): array=>['cursor'=>'1','events'=>[['ok'=>true]]]);
$duplicate=false;
try{\dataphyre\realtime::register('/events/live',static fn(): array=>[],static fn(): array=>[]);}catch(InvalidArgumentException){$duplicate=true;}
$invalid=false;
try{\dataphyre\realtime::register('/events/../admin',static fn(): array=>[],static fn(): array=>[]);}catch(InvalidArgumentException){$invalid=true;}
$reserved=false;
try{\dataphyre\realtime::register('/dataphyre/runtime/realtime/probe',static fn(): array=>[],static fn(): array=>[]);}catch(InvalidArgumentException){$reserved=true;}
$livenessReserved=false;
try{\dataphyre\realtime::register('/.dataphyre/live',static fn(): array=>[],static fn(): array=>[]);}catch(InvalidArgumentException){$livenessReserved=true;}
$evidence=\dataphyre\realtime::runtimeEvidence();
$sealed=false;
try{\dataphyre\realtime::register('/later',static fn(): array=>[],static fn(): array=>[]);}catch(LogicException){$sealed=true;}
echo json_encode(compact('duplicate','invalid','reserved','livenessReserved','sealed','evidence'),JSON_THROW_ON_ERROR);
PHP;
	$result=$t->phpProcess(['-r',$script,$kernel]);
	$t->processSucceeded($result);
	$payload=$result->json();
	$t->same(true,$payload['duplicate']);
	$t->same(true,$payload['invalid']);
	$t->same(true,$payload['reserved']);
	$t->same(true,$payload['livenessReserved']);
	$t->same(true,$payload['sealed']);
	$t->same(1,$payload['evidence']['route_count']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['evidence']['registration_sha256']);
})->tag('realtime','registration','security');

test('realtime ingress parser rejects ambiguous HTTP framing before proxying', static function(Context $t): void {
	require_once dirname(__DIR__) . '/kernel/application_runtime_realtime_server.php';
	$valid=DataphyreApplicationRuntimeRealtimeServer::parseRequest("GET /health HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n");
	$t->same('GET',$valid['method']);
	$t->same('/health',$valid['target']);
	$t->same(null,DataphyreApplicationRuntimeRealtimeServer::parseRequest("GET / HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n"));
	$t->same(null,DataphyreApplicationRuntimeRealtimeServer::parseRequest("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\n"));
	$t->same(null,DataphyreApplicationRuntimeRealtimeServer::parseRequest("GET http://example.test/ HTTP/1.1\r\nHost: a\r\n\r\n"));
	$t->same(null,DataphyreApplicationRuntimeRealtimeServer::parseRequest("GET / HTTP/1.1\r\nHost: a\r\nProxy: injected\r\n\r\n"));
	$raw="POST /submit HTTP/1.1\r\nHost: example.test\r\nConnection: keep-alive, x-remove\r\nX-Remove: private\r\nX-Dataphyre-Application: attacker\r\nX-Dataphyre-Runtime-Tick-Signature: attacker\r\nContent-Length: 4\r\n\r\ndata";
	$parsed=DataphyreApplicationRuntimeRealtimeServer::parseRequest($raw);
	$proxy=$t->nonPublic(DataphyreApplicationRuntimeRealtimeServer::class)->invoke('proxyRequest',$parsed,$raw);
	$t->contains("connection: close\r\n",$proxy);
	$t->contains("content-length: 4\r\n",$proxy);
	$t->endsWith("\r\n\r\ndata",$proxy);
	$t->isFalse(str_contains(strtolower($proxy),'x-dataphyre-application'));
	$t->isFalse(str_contains(strtolower($proxy),'x-dataphyre-runtime-tick-signature'));
	$t->isFalse(str_contains(strtolower($proxy),'x-remove'));
})->tag('realtime','http-proxy','negative','security');

test('realtime startup executes and requires every application invalid-origin rejection', static function(Context $t): void {
	require_once dirname(__DIR__) . '/kernel/application_runtime_realtime_server.php';
	$t->environment(['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET'=>str_repeat('a',64)]);
	$acceptedCalls=0;
	$rejected=false;
	try{
		new DataphyreApplicationRuntimeRealtimeServer([
			'/accepted'=>[
				'authorize'=>static function(array $handshake) use (&$acceptedCalls): array {
					$acceptedCalls++;
					return ['unsafe'=>true];
				},
				'events'=>static fn(): array=>['cursor'=>null,'events'=>[]],
			],
		]);
	}catch(RuntimeException){$rejected=true;}
	$t->same(1,$acceptedCalls);
	$t->same(true,$rejected);
	$rejectionCalls=0;
	new DataphyreApplicationRuntimeRealtimeServer([
		'/rejected'=>[
			'authorize'=>static function(array $handshake) use (&$rejectionCalls): false {
				$rejectionCalls++;
				return false;
			},
			'events'=>static fn(): array=>['cursor'=>null,'events'=>[]],
		],
	]);
	$t->same(1,$rejectionCalls);
})->tag('realtime','authorization','origin','exact-image','negative','security');

test('realtime release preflight is immutable and matches managed runtime scheduler evidence', static function(Context $t): void {
	$kernel=dirname(__DIR__) . '/kernel';
	$project=__DIR__ . '/fixtures/application_runtime_project';
	$snapshot=static function(string $root): array {
		$entries=[];
		$iterator=new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach($iterator as $entry){
			$path=$entry->getPathname();
			$relative=str_replace('\\','/',substr($path,strlen(rtrim($root,'/\\'))+1));
			$entries[$relative]=$entry->isLink()
				? ['type'=>'link','target'=>readlink($path)]
				: ($entry->isDir()
					? ['type'=>'directory']
					: ['type'=>'file','bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)]);
		}
		ksort($entries,SORT_STRING);
		return $entries;
	};
	$projectBefore=$snapshot($project);
	$state=$t->workspace('application-realtime-preflight-state');
	$sideEffect=$state->path('dispatch-side-effect.txt');
	$applicationSchedulerRoot=$state->path('cache/scheduling');
	$sentinel=$state->file('application-state-sentinel.txt','unchanged');
	$before=hash_file('sha256',$sentinel);
	$result=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,
		'--application=_Runtime$Probe',
		'--environment=staging',
	],environment:[
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>dirname(__DIR__,3),
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$state->root(),
		'DATAPHYRE_RUNTIME_TEST_REALTIME_SIDE_EFFECT_PATH'=>$sideEffect,
		'DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'valid-dependency',
	]);
	$t->processSucceeded($result);
	$payload=$result->json();
	$t->same('dataphyre.application_realtime_registration.v1',$payload['contract']);
	$t->same(true,$payload['ok']);
	$t->same(1,$payload['route_count']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['registration_sha256']);
	$t->same(0,$payload['registered_table_count']);
	$t->same('dataphyre.registered_table_materialization.v1',$payload['registered_table_materialization_contract']);
	$t->same('sha256:'.hash('sha256','[]'),$payload['registered_table_set_sha256']);
	$t->same(1,$payload['scheduler_definition_count']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['scheduler_definition_sha256']);
	$t->same($projectBefore,$snapshot($project));
	$t->same($before,hash_file('sha256',$sentinel));
	$t->same(['application-state-sentinel.txt'],array_values(array_diff(scandir($state->root()) ?: [],['.','..'])));
	$t->isFalse(file_exists($applicationSchedulerRoot.'/runtime.realtime.preflight/properties.json'));
	$t->isFalse(file_exists($applicationSchedulerRoot.'/runtime.realtime.preflight/running_lock'));
	$t->isFalse(file_exists($applicationSchedulerRoot.'/runtime.realtime.preflight/last_run'));
	$t->isFalse(file_exists($sideEffect));
	$t->same('',trim($result->stderr()));

	$runtimeScript=<<<'PHP'
namespace dataphyre {
	function tracelog(mixed ...$arguments): void {}
}
namespace {
	define('APP','runtime-canonical-evidence-fixture');
	define('DATAPHYRE_INTERNAL_SCHEDULER_REGISTRATION',true);
	define('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE','scheduler');
	require $argv[1].'/modules/scheduling/kernel/scheduling.main.php';
	$task=realpath($argv[2]);
	$boundaryDefinition=[
		'name'=>'runtime.boundary','file_path'=>$task,'frequency'=>1,'dependencies'=>[$task],
		'timeout'=>1,'memory_limit'=>'-1','app_override'=>'legacy-override',
	];
	$compatible=\DataphyreApplicationSchedulerDefinitionEvidence::definition($boundaryDefinition);
	$dot=$boundaryDefinition;$dot['name']='.';
	$dotDot=$boundaryDefinition;$dotDot['name']='..';
	$canonicalBoundaries=is_array($compatible) && $compatible['memory_limit']==='-1'
		&& \DataphyreApplicationSchedulerDefinitionEvidence::definition($dot)===null
		&& \DataphyreApplicationSchedulerDefinitionEvidence::definition($dotDot)===null
		&& \DataphyreApplicationSchedulerDefinitionEvidence::inventory([$compatible,$compatible])===null;
	$accepted=is_string($task) && \dataphyre\scheduling::run(
		'runtime.realtime.preflight',$task,3600,30,'64M',[$task],'ignored-in-managed-runtime',
	);
	$report=\dataphyre\scheduling::runtime_registration_report();
	if(!$canonicalBoundaries || $accepted!==true || ($report['ok'] ?? null)!==true) exit(70);
	echo json_encode([
		'canonical_boundaries'=>$canonicalBoundaries,
		'definition_count'=>$report['definition_count'] ?? null,
		'definition_sha256'=>$report['definition_sha256'] ?? null,
		'definition_keys'=>array_keys($report['definitions'][0] ?? []),
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
PHP;
	$runtimeWorkspace=$t->workspace('application-runtime-canonical-scheduler-evidence');
	$runtimeTask=$runtimeWorkspace->file(
		'relocated/scheduled_task.php',
		(string)file_get_contents($project.'/scheduled_task.php'),
	);
	$runtime=$t->phpProcess([
		'-r',$runtimeScript,dirname(__DIR__,3),$runtimeTask,
	]);
	$t->processSucceeded($runtime,$runtime->stderr());
	$runtimePayload=$runtime->json();
	$t->same(true,$runtimePayload['canonical_boundaries']);
	$t->same(1,$runtimePayload['definition_count']);
	$t->same([
		'name','task_sha256','dependency_sha256','frequency_milliseconds','timeout_milliseconds','memory_limit',
	],$runtimePayload['definition_keys']);
	$t->same($payload['scheduler_definition_sha256'],$runtimePayload['definition_sha256']);
	$t->same('',trim($runtime->stderr()));
})->tag('realtime','preflight','scheduling','record-only','negative','security');

test('realtime release preflight rejects an ignored partial scheduler registration', static function(Context $t): void {
	$kernel=dirname(__DIR__) . '/kernel';
	$project=__DIR__ . '/fixtures/application_runtime_project';
	$state=$t->workspace('application-realtime-partial-scheduler-state');
	$sentinel=$state->file('sentinel.txt','unchanged');
	$before=hash_file('sha256',$sentinel);
	$result=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,
		'--application=_Runtime$Probe',
		'--environment=staging',
	],environment:[
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>dirname(__DIR__,3),
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$state->root(),
		'DATAPHYRE_RUNTIME_TEST_INVALID_SCHEDULER_REGISTRATION'=>'1',
	]);
	$t->processFailed($result,70);
	$t->same([
		'contract'=>'dataphyre.application_realtime_registration.v1',
		'ok'=>false,
		'route_count'=>0,
		'registration_sha256'=>null,
		'registered_table_count'=>0,
		'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
		'registered_table_set_sha256'=>null,
		'scheduler_definition_count'=>0,
		'scheduler_definition_sha256'=>null,
	],$result->json());
	$t->same($before,hash_file('sha256',$sentinel));
	$t->same(['sentinel.txt'],array_values(array_diff(scandir($state->root()) ?: [],['.','..'])));
	$t->same('',trim($result->stderr()));
})->tag('realtime','preflight','scheduling','partial-registration','negative','security');

test('health release preflight boots read-only and cannot dispatch application schedules', static function(Context $t): void {
	$core=dirname(__DIR__);
	$project=__DIR__.'/fixtures/application_runtime_project';
	$state=$t->workspace('application-health-preflight-state');
	$sentinel=$state->file('sentinel.txt','unchanged');
	$sideEffect=$state->path('health-dispatch-side-effect.txt');
	$before=hash_file('sha256',$sentinel);
	$t->environment([
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>dirname(__DIR__,3),
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$state->root(),
		'DATAPHYRE_RUNTIME_TEST_HEALTH_SIDE_EFFECT_PATH'=>$sideEffect,
	]);
	require_once $core.'/Framework/ApplicationReleasePreflightCommand.php';
	$result=$t->nonPublic(\Dataphyre\Release\ApplicationReleasePreflightCommand::class)->invoke(
		'runHealth',$project,'_Runtime$Probe','staging','/health',5,
	);
	$t->same(true,$result['ok']);
	$t->same('healthy',$result['code']);
	$t->same(200,$result['http_status']);
	$t->same($before,hash_file('sha256',$sentinel));
	$t->same(['sentinel.txt'],array_values(array_diff(scandir($state->root()) ?: [],['.','..'])));
	$t->isFalse(file_exists($sideEffect));
})->tag('health','preflight','scheduling','record-only','negative','security');

test('deactivation requested during a tick remains inactive and cannot trigger an immediate second cadence', static function(Context $t): void {
	$state=$t->workspace('core-runtime-supervisor-cycle-state');
	if(!chmod($state->root(),0700)) throw new RuntimeException('Supervisor cycle state root mode could not be prepared.');
	define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$state->root());
	require_once dirname(__DIR__).'/kernel/application_runtime_supervisor.php';
	$definitions=[];
	foreach(['fixture.first','fixture.second'] as $name) $definitions[]=[
		'name'=>$name,'task_sha256'=>'sha256:'.str_repeat('a',64),'dependency_sha256'=>[],
		'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$registration=[
		'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,
		'registration_attempt_count'=>2,'registration_accepted_count'=>2,'registration_failure_count'=>0,
		'definition_count'=>2,'definition_sha256'=>'sha256:'.hash('sha256',json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
		'definitions'=>$definitions,
	];
	$runtime=[
		'active'=>true,'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,
		'scheduler_cycle_in_progress'=>false,'scheduler_registration'=>$registration,
	];
	$pendingRequests=[];$activationRequested=null;$nextTick=0.0;
	$identity=[
		'deployment_application'=>str_repeat('Z',120),'framework_application'=>'Fixture','environment'=>'staging',
		'release_id'=>'dep_'.str_repeat('b',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('d',64),
	];
	$stateFile=$state->path('state.json');
	$requests=[];$persisted=[];
	$persist=static function(bool $active) use (&$persisted,&$runtime): void {
		$persisted[]=['active'=>$active,'in_progress'=>$runtime['scheduler_cycle_in_progress']];
	};
	$request=static function() use (&$requests,&$runtime,&$activationRequested): array {
		$requests[]=['active'=>$runtime['active'],'in_progress'=>$runtime['scheduler_cycle_in_progress']];
		$activationRequested=false;
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$runtime,$pendingRequests,3,
		$activationRequested,$nextTick,$request,$persist,
	);
	$remaining=max(0.0,$nextTick-microtime(true));
	$t->same([['active'=>true,'in_progress'=>true]],$requests);
	$t->same([['active'=>false,'in_progress'=>true]],$persisted);
	$t->same(false,$runtime['active']);
	$t->same(false,$runtime['scheduler_cycle_in_progress']);
	$t->same(1,$runtime['count']);
	$t->same('never',$runtime['last_result'],'a resumed cycle must not certify a partial drain');
	$t->greaterThan(0.5,$remaining);

	$registrationFor=static function(array $cycleDefinitions): array {
		$count=count($cycleDefinitions);
		return [
			'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,
			'registration_attempt_count'=>$count,'registration_accepted_count'=>$count,'registration_failure_count'=>0,
			'definition_count'=>$count,
			'definition_sha256'=>'sha256:'.hash('sha256',json_encode($cycleDefinitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
			'definitions'=>$cycleDefinitions,
		];
	};
	$cycleRuntime=static fn(array $cycleRegistration): array=>[
		'active'=>true,'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,
		'scheduler_cycle_in_progress'=>false,'scheduler_registration'=>$cycleRegistration,
	];

	$invalidRuntime=$cycleRuntime([]);$invalidActivation=null;$invalidNextTick=0.0;
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$invalidRuntime,$pendingRequests,3,
		$invalidActivation,$invalidNextTick,static fn(): never=>throw new RuntimeException('unreachable'),$persist,
	);
	$t->same('failed',$invalidRuntime['last_result']);$t->same(false,$invalidRuntime['scheduler_cycle_in_progress']);

	$failedDefinition=[
		'name'=>'fixture.failure-first','task_sha256'=>'sha256:'.str_repeat('a',64),'dependency_sha256'=>[],
		'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$failedSecond=$failedDefinition;$failedSecond['name']='fixture.failure-second';$failedRequests=0;
	$failedRuntime=$cycleRuntime($registrationFor([$failedDefinition,$failedSecond]));$failedActivation=null;$failedNextTick=0.0;
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$failedRuntime,$pendingRequests,3,
		$failedActivation,$failedNextTick,static function() use (&$failedRequests): array {
			$failedRequests++;
			if($failedRequests===1) throw new RuntimeException('fixture callback failure');
			return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
		},$persist,
	);
	$t->same(2,$failedRequests);$t->same('failed',$failedRuntime['last_result']);$t->same(1,$failedRuntime['count']);

	$stopFirst=$failedDefinition;$stopFirst['name']='fixture.graceful-stop-first';
	$stopSecond=$failedDefinition;$stopSecond['name']='fixture.graceful-stop-second';$stopRequests=0;$stopRequested=false;
	$stopRuntime=$cycleRuntime($registrationFor([$stopFirst,$stopSecond]));$stopActivation=null;$stopNextTick=0.0;
	$stopCallback=static function() use (&$stopRequests,&$stopRequested): never {
		$stopRequests++;$stopRequested=true;dataphyre_runtime_require_not_stopping($stopRequested);
		throw new RuntimeException('unreachable after graceful stop');
	};
	$gracefulFailure=null;
	try{
		dataphyre_runtime_run_scheduler_cycle(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$stopRuntime,$pendingRequests,3,
			$stopActivation,$stopNextTick,$stopCallback,$persist,stopRequested:$stopRequested,
		);
	}catch(DataphyreManagedRuntimeGracefulShutdown $failure){$gracefulFailure=$failure;}
	$t->same('Managed runtime shutdown requested.',$gracefulFailure?->getMessage());
	$t->same(1,$stopRequests);$t->same(0,$stopRuntime['count']);$t->same(null,$stopRuntime['last_at']);
	$t->same('never',$stopRuntime['last_result']);$t->same(false,$stopRuntime['scheduler_cycle_in_progress']);
	$stoppedState=json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
	$stoppedEntry=$stoppedState['entries'][$stopFirst['name']] ?? null;
	$t->same(null,$stoppedEntry['claim_nonce'] ?? null);$t->same(null,$stoppedEntry['claim_expires_at'] ?? null);
	$t->same(null,$stoppedEntry['last_success_at'] ?? null);
	$t->isFalse(isset($stoppedState['entries'][$stopSecond['name']]),'graceful stop prevents a second due callback claim');

	$raceFirst=$failedDefinition;$raceFirst['name']='fixture.race-first';
	$raceSecond=$failedDefinition;$raceSecond['name']='fixture.race-second';
	$raceRuntime=$cycleRuntime($registrationFor([$raceFirst,$raceSecond]));$raceActivation=null;$raceNextTick=0.0;
	$raceGeneration='gen_'.str_repeat('c',32);$raceNonce=str_repeat('e',64);$raceRequests=0;
	$predecessorClaim=static function() use (&$raceRequests,$identity,$raceSecond,$raceGeneration,$raceNonce): array {
		$raceRequests++;
		if(!DataphyreApplicationRuntimeSchedulerState::claim(
			$identity,$raceSecond,$identity['release_id'],$raceGeneration,$raceNonce,time(),
		)) throw new RuntimeException('Predecessor race fixture could not claim the second definition.');
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,$raceGeneration,'secret','public',null,$raceRuntime,$pendingRequests,3,
		$raceActivation,$raceNextTick,$predecessorClaim,$persist,
	);
	$t->same(1,$raceRequests);$t->same('never',$raceRuntime['last_result'],'a concurrent claim leaves the cycle unmeasured');
	DataphyreApplicationRuntimeSchedulerState::releaseClaim(
		$identity,$raceSecond,$identity['release_id'],$raceGeneration,$raceNonce,
	);

	$cleanupDefinition=$failedDefinition;$cleanupDefinition['name']='fixture.cleanup-failure-first';
	$cleanupSecond=$failedDefinition;$cleanupSecond['name']='fixture.cleanup-failure-second';$cleanupRequests=0;
	$cleanupRuntime=$cycleRuntime($registrationFor([$cleanupDefinition,$cleanupSecond]));$cleanupActivation=null;$cleanupNextTick=0.0;
	$corruptClaim=static function() use ($stateFile,$cleanupDefinition,&$cleanupRequests): never {
		$cleanupRequests++;
		$current=json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
		$current['entries'][$cleanupDefinition['name']]['claim_nonce']=str_repeat('0',64);
		file_put_contents($stateFile,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
		chmod($stateFile,0600);
		throw new RuntimeException('application callback detail must remain private');
	};
	$cleanupFailure=null;
	try{
		dataphyre_runtime_run_scheduler_cycle(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$cleanupRuntime,$pendingRequests,3,
			$cleanupActivation,$cleanupNextTick,$corruptClaim,$persist,
		);
	}catch(DataphyreManagedRuntimeGenerationUnavailable $failure){$cleanupFailure=$failure;}
	$t->same(1,$cleanupRequests);$t->same(false,$cleanupRuntime['scheduler_cycle_in_progress']);$t->same(0,$cleanupRuntime['count']);
	$t->same('Managed runtime scheduler claim cleanup failed.',$cleanupFailure?->getMessage());
	$t->same('application callback detail must remain private',$cleanupFailure?->getPrevious()?->getMessage());

	$generationDefinition=$failedDefinition;$generationDefinition['name']='fixture.generation-loss-first';
	$generationSecond=$failedDefinition;$generationSecond['name']='fixture.generation-loss-second';$generationRequests=0;
	$generationRuntime=$cycleRuntime($registrationFor([$generationDefinition,$generationSecond]));
	$generationActivation=null;$generationNextTick=0.0;
	$loseGeneration=static function() use ($stateFile,$generationDefinition,&$generationRequests): never {
		$generationRequests++;
		$current=json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
		$current['entries'][$generationDefinition['name']]['claim_nonce']=str_repeat('0',64);
		file_put_contents($stateFile,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
		chmod($stateFile,0600);
		throw new DataphyreManagedRuntimeGenerationUnavailable('generation-specific private detail');
	};
	$generationFailure=null;
	try{
		dataphyre_runtime_run_scheduler_cycle(
			DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$generationRuntime,$pendingRequests,3,
			$generationActivation,$generationNextTick,$loseGeneration,$persist,
		);
	}catch(DataphyreManagedRuntimeGenerationUnavailable $failure){$generationFailure=$failure;}
	$t->same(1,$generationRequests);$t->same(false,$generationRuntime['scheduler_cycle_in_progress']);$t->same(0,$generationRuntime['count']);
	$t->same('Managed runtime generation failed and its current scheduler claim cleanup also failed.',$generationFailure?->getMessage());
	$t->same('generation-specific private detail',$generationFailure?->getPrevious()?->getMessage());

	$ownedChildren=[];
	try{
		foreach(['web','scheduler','realtime','web-http-gateway'] as $role){
			$command=$role==='realtime' ? ['/bin/sleep','30'] : ['/usr/bin/setsid','/bin/sleep','30'];
			$pipes=[];$resource=proc_open( // dataphyre-test-architecture: exempt[raw-process-control] reason="Owned-role generation-loss proof must kill and inspect an exact direct child while its scheduler callback is in progress."
				$command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,
			);
			if(!is_resource($resource)) throw new RuntimeException('Owned-role fixture could not spawn.');
			$status=proc_get_status($resource);$pid=$status['pid'] ?? null;
			if(!is_int($pid) || $pid<2) throw new RuntimeException('Owned-role fixture identity is invalid.');
			$deadline=microtime(true)+1.0;
			do{
				$childIdentity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);
				$group=$role==='realtime' ? null : @posix_getpgid($pid);
				if($role==='realtime' || $group===$pid) break;
				usleep(10000);
			}while(microtime(true)<$deadline);
			if($role!=='realtime' && $group!==$pid) throw new RuntimeException('Owned-role fixture process group is invalid.');
			$ownedChildren[$role]=[
				'resource'=>$resource,'pid'=>$pid,'pool'=>$role,
				'start_time_ticks'=>$childIdentity['start_time_ticks'],'process_group_id'=>$role==='realtime' ? null : $pid,
			];
		}
		$ownedFirst=$failedDefinition;$ownedFirst['name']='fixture.owned-generation-first';
		$ownedSecond=$failedDefinition;$ownedSecond['name']='fixture.owned-generation-second';$ownedRequests=0;
		$ownedRuntime=$cycleRuntime($registrationFor([$ownedFirst,$ownedSecond]));$ownedActivation=null;$ownedNextTick=0.0;
		$killSchedulerThenConnect=static function(
			string $socketPath,string $kind,array $requestIdentity,string $requestGeneration,int $counter,
			string $secretKey,string $publicKey,mixed $statusListener,array &$requestRuntime,array &$requestPending,
			?bool &$requestActivation,float &$requestNextTick,?string $schedulerName=null,
			?string $definitionSha256=null,?int $budgetMilliseconds=null,?array &$issuedEvidence=null,
			?bool &$stopRequested=null,
		) use (&$ownedChildren,&$ownedRequests): array {
			$ownedRequests++;$scheduler=$ownedChildren['scheduler'];@posix_kill(-$scheduler['pid'],SIGKILL);
			$deadline=microtime(true)+1.0;
			do{
				$status=proc_get_status($scheduler['resource']);
				if(($status['running'] ?? true)!==true) break;
				usleep(10000);
			}while(microtime(true)<$deadline);
			$requestRuntime['managed_generation']=true;$requestRuntime['owned_children']=$ownedChildren;
			return dataphyre_runtime_scheduler_request(
				$socketPath,$kind,$requestIdentity,$requestGeneration,$counter,$secretKey,$publicKey,
				$statusListener,$requestRuntime,$requestPending,$requestActivation,$requestNextTick,
				$schedulerName,$definitionSha256,$budgetMilliseconds,$issuedEvidence,$stopRequested,
			);
		};
		$ownedFailure=null;
		try{
			dataphyre_runtime_run_scheduler_cycle(
				DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$ownedRuntime,$pendingRequests,3,
				$ownedActivation,$ownedNextTick,$killSchedulerThenConnect,$persist,
			);
		}catch(DataphyreManagedRuntimeGenerationUnavailable $failure){$ownedFailure=$failure;}
		$t->same(1,$ownedRequests);$t->same(false,$ownedRuntime['scheduler_cycle_in_progress']);$t->same(0,$ownedRuntime['count']);
		$t->same('scheduler runtime pool exited unexpectedly.',$ownedFailure?->getMessage());
		$ownedState=json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
		$t->same(null,$ownedState['entries'][$ownedFirst['name']]['claim_nonce'] ?? null);
		$t->same(null,$ownedState['entries'][$ownedFirst['name']]['last_success_at'] ?? null);
		$t->isFalse(isset($ownedState['entries'][$ownedSecond['name']]),'gateway death prevents a second due callback attempt');
	}finally{
		foreach($ownedChildren as $child) dataphyre_runtime_signal_child($child,SIGKILL);
		foreach($ownedChildren as $child) if(is_resource($child['resource'])) proc_close($child['resource']);
	}

	$deactivateDefinition=$failedDefinition;$deactivateDefinition['name']='fixture.deactivate-at-cycle-end';
	$deactivateRuntime=$cycleRuntime($registrationFor([$deactivateDefinition]));$deactivateActivation=null;$deactivateNextTick=0.0;
	$deactivateCalls=0;
	$deactivate=static function() use (&$deactivateActivation,&$deactivateCalls): array {
		$deactivateCalls++;
		$deactivateActivation=false;
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$deactivateRuntime,$pendingRequests,3,
		$deactivateActivation,$deactivateNextTick,$deactivate,$persist,
	);
	$t->same(1,$deactivateCalls);
	$t->same(false,$deactivateRuntime['active']);$t->same('ok',$deactivateRuntime['last_result']);
	$t->greaterThan(0.5,max(0.0,$deactivateNextTick-microtime(true)));
})->tag('signed-cadence','signal','deactivation','lifecycle','regression');

test('scheduler router rejects direct execution without its one-shot environment before application bootstrap', static function(Context $t): void {
    $kernel=dirname(__DIR__) . '/kernel';
    $project=__DIR__ . '/fixtures/application_runtime_project';
    $script=<<<'PHP'
$_SERVER=[
    'REMOTE_ADDR'=>'127.0.0.1',
    'REQUEST_URI'=>'/dataphyre/runtime/scheduler/callback',
    'REQUEST_METHOD'=>'POST',
];
ob_start();
require $argv[1];
$body=ob_get_clean();
echo json_encode(['status'=>http_response_code(),'body'=>$body],JSON_THROW_ON_ERROR);
PHP;
    $result=$t->phpProcess(['-r',$script,$kernel . '/application_runtime_router.php'], environment:[
        'DATAPHYRE_RUNTIME_POOL'=>'scheduler',
        'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
        'DATAPHYRE_RUNTIME_APPLICATION'=>'_Runtime$Probe',
        'DATAPHYRE_RUNTIME_ENVIRONMENT'=>'preview-pr-123',
        'DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'=>str_repeat('A',43),
    ]);
    $t->processSucceeded($result);
    $t->same(['status'=>404,'body'=>'{"ok":false}'],$result->json());
})->tag('negative','single-use-environment','scheduler');

test('status probe accepts the exact v7 maximum and rejects one additional byte', static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';$probe=$kernel.'/application_runtime_status_probe.php';
	require_once $kernel.'/application_runtime_supervisor.php';
	require_once __DIR__.'/fixtures/application_runtime_status_v7_max_payload.php';
	if(!is_dir('/run/dataphyre') && !mkdir('/run/dataphyre',0700)){
		throw new RuntimeException('Status boundary runtime parent could not be created.');
	}
	if(!chown('/run/dataphyre',0) || !chgrp('/run/dataphyre',0) || !chmod('/run/dataphyre',0700)){
		throw new RuntimeException('Status boundary runtime parent could not be prepared.');
	}
	$runProbe=static function(string $body) use ($t,$probe): mixed {
		$control=dataphyre_runtime_bind_control_socket();$listener=$control['listener'];$pid=pcntl_fork();
		if($pid===-1){fclose($listener);throw new RuntimeException('Status boundary server could not fork.');}
		if($pid===0){
			register_shutdown_function(static function() use ($control): void {
				dataphyre_runtime_cleanup_root_socket(
					'/run/dataphyre/control','/run/dataphyre/control/runtime.sock',
					$control['identity'],$control['directory_identity'],
				);
			});
			$connection=@stream_socket_accept($listener,3);$request='';
			if(is_resource($connection)){
				stream_set_timeout($connection,2,0);
				while(!str_contains($request,"\r\n\r\n")){
					$chunk=fread($connection,4096);if(!is_string($chunk) || $chunk==='') break;$request.=$chunk;
				}
				$response="HTTP/1.1 200 OK\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
				$offset=0;while($offset<strlen($response)){
					$written=@fwrite($connection,substr($response,$offset));
					if(!is_int($written) || $written<1) break;$offset+=$written;
				}
				fclose($connection);
			}
			fclose($listener);exit(0);
		}
		fclose($listener);
		try{return $t->phpProcess([$probe]);}
		finally{
			pcntl_waitpid($pid,$status);
			$t->same(0,pcntl_wexitstatus($status),'status boundary server exits cleanly');
		}
	};
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	try{
		$maximum=dataphyre_test_application_runtime_status_v7_max_payload();
		$t->same(true,\Dataphyre\ApplicationEnvironmentIdentifier::valid(str_repeat('e',128)));
		$t->same(false,\Dataphyre\ApplicationEnvironmentIdentifier::valid(str_repeat('e',129)));
		$t->same(8341,strlen($maximum));
		$accepted=$runProbe($maximum);$t->processSucceeded($accepted);
		$t->same($maximum."\n",$accepted->stdout());$t->same(8342,strlen($accepted->stdout()));
		$t->same('',$accepted->stderr());
		$rejected=$runProbe($maximum.' ');$t->processFailed($rejected,69);
		$t->same('',$rejected->stdout());$t->same('',$rejected->stderr());
	}finally{dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);}
})->tag('status-probe','v7','maximum','boundary','positive','negative');

test('status probe owns one fixed socket and refuses caller-selected arguments', static function(Context $t): void {
    $probe=dirname(__DIR__) . '/kernel/application_runtime_status_probe.php';
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	try{
		$unavailable=$t->phpProcess([$probe]);
		$t->processFailed($unavailable,69);
		$t->same('',$unavailable->stdout());
		$t->same('',$unavailable->stderr());
	}finally{dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);}

    $argument=$t->phpProcess([$probe,'--status-port=1']);
    $t->processFailed($argument,64);
    $t->same('',$argument->stdout());
    $t->same('',$argument->stderr());
})->tag('negative','probe');
