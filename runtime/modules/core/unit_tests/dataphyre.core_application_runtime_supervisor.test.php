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
	$gateway=(string)file_get_contents($kernel . '/application_runtime_cgi_gateway.php');
	$childEnvironment=(string)file_get_contents($kernel . '/application_runtime_child_environment.php');
	$latch=(string)file_get_contents($kernel . '/application_runtime_activation_latch.php');
	$protocol=(string)file_get_contents($kernel . '/application_runtime_scheduler_protocol.php');
	$realtime=(string)file_get_contents($kernel . '/realtime.php');
	$realtimeServer=(string)file_get_contents($kernel . '/application_runtime_realtime_server.php');
	$realtimeProbe=(string)file_get_contents($kernel . '/application_runtime_realtime_probe.php');
    $fixture=(string)file_get_contents(__DIR__ . '/fixtures/application_runtime_project/framework_bootstrap.php');

    $t->contains("getmypid() !== 1", $supervisor);
    $t->contains("posix_geteuid() !== 0", $supervisor);
	$t->contains("$" . "webHost='127.0.0.1';$" . "webPort=8083", $supervisor);
	$t->contains("$" . "schedulerHost='127.0.0.1';$" . "schedulerPort=8081", $supervisor);
	$t->contains("$" . "statusHost='127.0.0.1';$" . "statusPort=8082", $supervisor);
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
    $t->contains("stream_socket_server('tcp://'", $supervisor);
    $t->contains('sodium_crypto_sign_keypair()', $supervisor);
    $t->contains('sodium_crypto_sign_detached', $protocol);
	$t->contains('dataphyre.scheduler_request.v1',$protocol);
	foreach(['register','callback','noop'] as $operation){
		$t->contains('/dataphyre/runtime/scheduler/'.$operation,$supervisor);
		$t->contains('/dataphyre/runtime/scheduler/'.$operation,$router);
	}
    $t->isFalse(str_contains($supervisor, 'DATAPHYRE_RUNTIME_TICK_PRIVATE_KEY'));
    $t->isFalse(str_contains($router, '/dataphyre/runtime/status'));
	$t->contains('/dataphyre/runtime/scheduler/claim',$gateway);
	$t->isFalse(str_contains($router,'/dataphyre/runtime/scheduler/claim'));
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::consume',$supervisor);
	$t->contains('dataphyre.application_runtime.v4',$supervisor);
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
	$t->contains('dataphyre.scheduler_callback.v1',$gateway);
	$t->contains('execute_managed_registration()',$router);
	$t->contains('DataphyreApplicationRuntimeSchedulerState::recordSuccess',$supervisor);
	$t->contains('DataphyreApplicationRuntimeSchedulerState::releaseClaim',$supervisor);
	$t->contains("($" . "decoded['contract'] ?? null)==='dataphyre.application_runtime.v4'",$probe);
	foreach(['cloud_application','framework_application','environment','release_id','environment_fingerprint'] as $identity){
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
    $t->contains("'method'=>'POST'",$fixture);
    $t->contains('http://127.0.0.1:8082/dataphyre/runtime/status',$fixture);
    $t->contains("$" . "forgedStatusCode>=200",$fixture);
    $t->isFalse(str_contains($probe, "PHP_SAPI !== 'cli') {"));
	$t->contains("$" . "setpriv='/usr/bin/setpriv'",$supervisor);
	$t->contains("'--no-new-privs'",$supervisor);
	$t->contains("'--reuid=0'",$supervisor);
	$t->contains('application_runtime_cgi_gateway.php',$supervisor);
	$t->isFalse(str_contains($supervisor,'PHP_CLI_SERVER_WORKERS'));
	$t->contains("['web','scheduler','realtime']",$supervisor);
	$t->contains("$" . "pool==='realtime'",$supervisor);
	$t->isFalse(is_file($kernel.'/application_runtime_pool_launcher.php'));
	$t->contains("'/usr/local/bin/php-cgi'",$gateway);
	$t->isFalse(str_contains($gateway,"'-n'"));
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
	$t->contains("'origin'=>'https://dataphyre.invalid'",$realtimeServer);
	$t->contains("'application_authorization_rejections'=>true",$realtimeServer);
	$t->contains("if($" . "pool!=='realtime') unset($" . "applicationEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET'])",$supervisor);
	$t->contains('dataphyre.application_realtime_probe.v1',$realtimeProbe);
	$t->contains("tcp://127.0.0.1:8082",$realtimeProbe);
	$t->contains("GET /dataphyre/runtime/realtime/probe HTTP/1.1",$realtimeProbe);
	$t->isFalse(str_contains($realtimeProbe,'getenv('));
	$t->isFalse(str_contains($realtimeServer, 'shell_exec'));
	$t->isFalse(str_contains($realtimeServer, 'exec('));

	foreach([
		'supervisor_uid','supervisor_gid','supplementary_gids','cap_eff','no_new_privileges',
		'role','listen_host','listen_port','parent_pid',
	] as $evidence) {
        $t->contains($evidence, $supervisor);
        $t->contains($evidence, $probe);
	}
	foreach([$supervisor,$realtimeServer,$realtimeProbe] as $source) $t->contains('registration_sha256',$source);
	$t->contains("($" . "decoded['supervisor_pid'] ?? null)===1",$probe);
	$t->contains("'one-request-per-process-cgi'",$probe);
	$t->contains("'single-exec-realtime'",$probe);
	$t->contains("($" . "value['no_new_privileges'] ?? null)===true",$probe);
	$t->contains("$" . "validPool($" . "decoded['realtime'] ?? null,'realtime','0.0.0.0',8080)",$probe);
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
	$t->throws(static fn()=>dataphyre_runtime_pool_identity(getmypid(),'invalid','127.0.0.1',8083),RuntimeException::class);
	$t->throws(static fn()=>dataphyre_runtime_pool_identity(999999,'web','127.0.0.1',8083),RuntimeException::class);

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

	$serveResponse=static function(string $wire,callable $client) use ($t): mixed {
		$listener=stream_socket_server('tcp://127.0.0.1:0',$errorNumber,$error);
		if(!is_resource($listener)) throw new RuntimeException("Supervisor response fixture could not bind: {$errorNumber} {$error}");
		$address=(string)stream_socket_get_name($listener,false);
		$separator=strrpos($address,':');$port=(int)substr($address,$separator===false ? 0 : $separator+1);
		$pid=pcntl_fork();
		if($pid===-1){fclose($listener);throw new RuntimeException('Supervisor response fixture could not fork.');}
		if($pid===0){
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
		try{return $client($port);}
		finally{pcntl_waitpid($pid,$status);$t->same(0,pcntl_wexitstatus($status));}
	};
	$response=static fn(int $status,string $body): string=>
		"HTTP/1.1 {$status} Fixture\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
	$keypair=sodium_crypto_sign_keypair();$secretKey=sodium_crypto_sign_secretkey($keypair);$publicKey=sodium_crypto_sign_publickey($keypair);
	$identity=[
		'cloud_application'=>'Store:North_2-Beta','framework_application'=>'Fixture','environment'=>'staging',
		'release_id'=>'dep_'.str_repeat('a',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
	];
	$generation='gen_'.str_repeat('c',32);$pending=[];$runtime=['active'=>true];$activation=null;$nextTick=0.0;
	$statusListener=stream_socket_server('tcp://127.0.0.1:0',$errorNumber,$error);
	if(!is_resource($statusListener)) throw new RuntimeException("Supervisor status fixture could not bind: {$errorNumber} {$error}");
	stream_set_blocking($statusListener,false);
	$callbackResult=$serveResponse($response(200,'{"contract":"dataphyre.scheduler_callback.v1","ok":true}'),static fn(int $port): array=>
		dataphyre_runtime_scheduler_request(
			$port,'callback',$identity,$generation,1,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,'fixture.callback','sha256:'.str_repeat('d',64),1000,
		)
	);
	$t->same(['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true],$callbackResult);$t->same([],$pending);
	$reservation=stream_socket_server('tcp://127.0.0.1:0',$errorNumber,$error);
	if(!is_resource($reservation)) throw new RuntimeException('Supervisor unavailable-port fixture could not bind.');
	$reservedAddress=(string)stream_socket_get_name($reservation,false);fclose($reservation);
	$reservedSeparator=strrpos($reservedAddress,':');$unavailablePort=(int)substr($reservedAddress,$reservedSeparator===false ? 0 : $reservedSeparator+1);
	$t->throws(static fn()=>dataphyre_runtime_scheduler_request(
		$unavailablePort,'noop',$identity,$generation,2,$secretKey,$publicKey,$statusListener,$runtime,$pending,
		$activation,$nextTick,
	),RuntimeException::class);
	$t->same([],$pending);
	$t->throws(static fn()=>$serveResponse(
		$response(200,str_repeat('x',DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES+1)),
		static fn(int $port): array=>dataphyre_runtime_scheduler_request(
			$port,'noop',$identity,$generation,3,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,
		),
	),RuntimeException::class);
	$t->throws(static fn()=>$serveResponse(
		$response(500,'{}'),
		static fn(int $port): array=>dataphyre_runtime_scheduler_request(
			$port,'noop',$identity,$generation,4,$secretKey,$publicKey,$statusListener,$runtime,$pending,
			$activation,$nextTick,
		),
	),RuntimeException::class);
	foreach(['noop','callback','registration'] as $index=>$kind){
		$t->throws(static fn()=>$serveResponse(
			$response(200,'{"ok":true}'),
			static fn(int $port): array=>dataphyre_runtime_scheduler_request(
				$port,$kind,$identity,$generation,5+$index,$secretKey,$publicKey,$statusListener,$runtime,$pending,
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
			static function(int $port) use (
				$issued,$statusListener,&$runtime,&$pending,$publicKey,&$activation,&$nextTick,
			): mixed {
				dataphyre_runtime_require_scheduler_replay_rejection(
					$port,$issued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
				);
				return null;
			},
		));
	}
	$invalidIssued=$issued;$invalidIssued['signature']=str_repeat('A',86);
	$t->throws(static fn()=>dataphyre_runtime_require_scheduler_replay_rejection(
		1,$invalidIssued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
	),RuntimeException::class);
	$t->throws(static fn()=>$serveResponse(
		$response(200,'{"ok":true}'),
		static function(int $port) use (
			$issued,$statusListener,&$runtime,&$pending,$publicKey,&$activation,&$nextTick,
		): mixed {
			dataphyre_runtime_require_scheduler_replay_rejection(
				$port,$issued,$statusListener,$runtime,$pending,$publicKey,$activation,$nextTick,
			);
			return null;
		},
	),RuntimeException::class);
	fclose($statusListener);sodium_memzero($secretKey);

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
$evidence=\dataphyre\realtime::runtimeEvidence();
$sealed=false;
try{\dataphyre\realtime::register('/later',static fn(): array=>[],static fn(): array=>[]);}catch(LogicException){$sealed=true;}
echo json_encode(compact('duplicate','invalid','reserved','sealed','evidence'),JSON_THROW_ON_ERROR);
PHP;
	$result=$t->phpProcess(['-r',$script,$kernel]);
	$t->processSucceeded($result);
	$payload=$result->json();
	$t->same(true,$payload['duplicate']);
	$t->same(true,$payload['invalid']);
	$t->same(true,$payload['reserved']);
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

test('realtime release preflight leaves application bytes unchanged and cannot dispatch schedules', static function(Context $t): void {
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
		'cloud_application'=>str_repeat('Z',120),'framework_application'=>'Fixture','environment'=>'staging',
		'release_id'=>'dep_'.str_repeat('b',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('d',64),
	];
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
		8081,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$runtime,$pendingRequests,3,
		$activationRequested,$nextTick,$request,$persist,
	);
	$remaining=max(0.0,$nextTick-microtime(true));
	$t->same([['active'=>true,'in_progress'=>true]],$requests);
	$t->same([['active'=>false,'in_progress'=>true]],$persisted);
	$t->same(false,$runtime['active']);
	$t->same(false,$runtime['scheduler_cycle_in_progress']);
	$t->same(1,$runtime['count']);
	$t->same('ok',$runtime['last_result']);
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
		8081,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$invalidRuntime,$pendingRequests,3,
		$invalidActivation,$invalidNextTick,static fn(): never=>throw new RuntimeException('unreachable'),$persist,
	);
	$t->same('failed',$invalidRuntime['last_result']);$t->same(false,$invalidRuntime['scheduler_cycle_in_progress']);

	$failedDefinition=[
		'name'=>'fixture.failure','task_sha256'=>'sha256:'.str_repeat('a',64),'dependency_sha256'=>[],
		'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$failedRuntime=$cycleRuntime($registrationFor([$failedDefinition]));$failedActivation=null;$failedNextTick=0.0;
	dataphyre_runtime_run_scheduler_cycle(
		8081,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$failedRuntime,$pendingRequests,3,
		$failedActivation,$failedNextTick,static fn(): never=>throw new RuntimeException('fixture callback failure'),$persist,
	);
	$t->same('failed',$failedRuntime['last_result']);$t->same(1,$failedRuntime['count']);

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
		8081,$identity,$raceGeneration,'secret','public',null,$raceRuntime,$pendingRequests,3,
		$raceActivation,$raceNextTick,$predecessorClaim,$persist,
	);
	$t->same(1,$raceRequests);$t->same('ok',$raceRuntime['last_result']);
	DataphyreApplicationRuntimeSchedulerState::releaseClaim(
		$identity,$raceSecond,$identity['release_id'],$raceGeneration,$raceNonce,
	);

	$cleanupDefinition=$failedDefinition;$cleanupDefinition['name']='fixture.cleanup-failure';
	$cleanupRuntime=$cycleRuntime($registrationFor([$cleanupDefinition]));$cleanupActivation=null;$cleanupNextTick=0.0;
	$stateFile=$state->path('state.json');
	$corruptClaim=static function() use ($stateFile,$cleanupDefinition): array {
		$current=json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
		$current['entries'][$cleanupDefinition['name']]['claim_nonce']=str_repeat('0',64);
		file_put_contents($stateFile,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
		chmod($stateFile,0600);
		return ['contract'=>'invalid','ok'=>false];
	};
	dataphyre_runtime_run_scheduler_cycle(
		8081,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$cleanupRuntime,$pendingRequests,3,
		$cleanupActivation,$cleanupNextTick,$corruptClaim,$persist,
	);
	$t->same('failed',$cleanupRuntime['last_result']);$t->same(false,$cleanupRuntime['scheduler_cycle_in_progress']);

	$deactivateDefinition=$failedDefinition;$deactivateDefinition['name']='fixture.deactivate-at-cycle-end';
	$deactivateRuntime=$cycleRuntime($registrationFor([$deactivateDefinition]));$deactivateActivation=null;$deactivateNextTick=0.0;
	$deactivate=static function() use (&$deactivateActivation): array {
		$deactivateActivation=false;
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	dataphyre_runtime_run_scheduler_cycle(
		8081,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$deactivateRuntime,$pendingRequests,3,
		$deactivateActivation,$deactivateNextTick,$deactivate,$persist,
	);
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

test('status probe owns one fixed address and refuses caller-selected arguments', static function(Context $t): void {
    $probe=dirname(__DIR__) . '/kernel/application_runtime_status_probe.php';
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	try{
		$ignoredOverride=$t->phpProcess([$probe], environment:[
			'DATAPHYRE_RUNTIME_STATUS_HOST'=>'::1',
			'DATAPHYRE_RUNTIME_STATUS_PORT'=>'1',
		]);
		$t->processFailed($ignoredOverride,69);
		$t->same('',$ignoredOverride->stdout());
		$t->same('',$ignoredOverride->stderr());
	}finally{dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);}

    $argument=$t->phpProcess([$probe,'--status-port=1'], environment:[
        'DATAPHYRE_RUNTIME_STATUS_HOST'=>'127.0.0.1',
        'DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
    ]);
    $t->processFailed($argument,64);
    $t->same('',$argument->stdout());
    $t->same('',$argument->stderr());
})->tag('negative','probe');
