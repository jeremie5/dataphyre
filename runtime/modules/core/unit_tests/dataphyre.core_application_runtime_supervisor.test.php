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
    $launcher=(string)file_get_contents($kernel . '/application_runtime_pool_launcher.php');
    $router=(string)file_get_contents($kernel . '/application_runtime_router.php');
    $probe=(string)file_get_contents($kernel . '/application_runtime_status_probe.php');
	$protocol=(string)file_get_contents($kernel . '/application_runtime_tick_protocol.php');
	$realtime=(string)file_get_contents($kernel . '/realtime.php');
	$realtimeServer=(string)file_get_contents($kernel . '/application_runtime_realtime_server.php');
	$realtimeProbe=(string)file_get_contents($kernel . '/application_runtime_realtime_probe.php');
    $fixture=(string)file_get_contents(__DIR__ . '/fixtures/application_runtime_project/framework_bootstrap.php');

    $t->contains("getmypid() !== 1", $supervisor);
    $t->contains("posix_geteuid() !== 0", $supervisor);
	$t->contains("DATAPHYRE_RUNTIME_STATUS_PORT',8082", $supervisor);
	$t->contains("DATAPHYRE_RUNTIME_REALTIME_PORT',8080", $supervisor);
	$t->contains("DATAPHYRE_RUNTIME_WEB_PORT',8083", $supervisor);
    $t->contains("stream_socket_server('tcp://'", $supervisor);
    $t->contains('sodium_crypto_sign_keypair()', $supervisor);
    $t->contains('sodium_crypto_sign_detached', $protocol);
    $t->contains('dataphyre.application_runtime_tick.v1', $protocol);
    foreach([
        'X-Dataphyre-Runtime-Tick-Timestamp',
        'X-Dataphyre-Runtime-Tick-Nonce',
        'X-Dataphyre-Runtime-Tick-Counter',
        'X-Dataphyre-Runtime-Tick-Signature',
    ] as $header) {
        $t->contains($header, $supervisor);
        $t->contains(strtoupper(str_replace('-', '_', $header)), $router);
    }
    $t->contains("unset($" . "childEnvironment['DATAPHYRE_RUNTIME_STATUS_HOST']", $supervisor);
    $t->isFalse(str_contains($supervisor, 'DATAPHYRE_RUNTIME_TICK_PRIVATE_KEY'));
    $t->isFalse(str_contains($router, '/dataphyre/runtime/status'));
    $t->contains('/dataphyre/runtime/tick/claim', $router);
    $t->contains('DataphyreApplicationRuntimeTickProtocol::consume', $supervisor);
    $t->contains('unset($pending[$counter])', $protocol);
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
    $t->contains("posix_initgroups('dataphyre',$" . 'gid)', $launcher);
    $t->contains('posix_setgid', $launcher);
    $t->contains('posix_setuid', $launcher);
    $t->contains('pcntl_exec', $launcher);
	$t->contains("putenv('PHP_CLI_SERVER_WORKERS=3')", $launcher);
	$t->contains("else putenv('PHP_CLI_SERVER_WORKERS')", $launcher);
	$t->contains("['web','scheduler','realtime']", $launcher);
	$t->contains("pcntl_exec(PHP_BINARY,[$" . "router]", $launcher);
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
	$t->contains("if($" . "pool!=='realtime') unset($" . "childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET'])",$supervisor);
	$t->contains('dataphyre.application_realtime_probe.v1',$realtimeProbe);
	$t->contains("tcp://127.0.0.1:8082",$realtimeProbe);
	$t->contains("GET /dataphyre/runtime/realtime/probe HTTP/1.1",$realtimeProbe);
	$t->isFalse(str_contains($realtimeProbe,'getenv('));
	$t->isFalse(str_contains($realtimeServer, 'shell_exec'));
	$t->isFalse(str_contains($realtimeServer, 'exec('));

    foreach(['supervisor_uid','supervisor_gid','supplementary_gids','cap_eff','no_new_privileges'] as $evidence) {
        $t->contains($evidence, $supervisor);
        $t->contains($evidence, $probe);
    }
    $t->contains("$" . "decoded['supervisor_pid'] === 1", $probe);
    $t->contains("($" . "value['supplementary_gids'] ?? null) === [10001]", $probe);
    $t->contains("($" . "value['cap_eff'] ?? null) === '0000000000000000'", $probe);
	$t->contains("($" . "value['no_new_privileges'] ?? null) === true", $probe);
	$t->contains("$" . "validPool($" . "decoded['realtime'])", $probe);
})->tag('source-contract');

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

test('one supervisor-issued signed cadence claim is accepted exactly once', static function(Context $t): void {
    require_once dirname(__DIR__) . '/kernel/application_runtime_tick_protocol.php';
    $keypair=sodium_crypto_sign_keypair();
    $secretKey=sodium_crypto_sign_secretkey($keypair);
    $publicKey=sodium_crypto_sign_publickey($keypair);
    $tick=DataphyreApplicationRuntimeTickProtocol::issue(
        '_Runtime$Probe','preview-pr-123',7,$secretKey,1776073500,str_repeat('a',32),
    );
    $pending=['7'=>$tick];
    $t->isTrue(DataphyreApplicationRuntimeTickProtocol::verify($tick,$publicKey,1776073500));
    $t->isTrue(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tick,$publicKey,1776073500));
    $t->same([],$pending);
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tick,$publicKey,1776073500));
    $tampered=$tick;
    $tampered['counter']='8';
    $pending=['8'=>$tampered];
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tampered,$publicKey,1776073500));
    $t->same(['8'=>$tampered],$pending);
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::verify($tick,$publicKey,1776073531));
})->tag('signed-cadence','replay','negative');

test('scheduler router rejects unsigned tenant ticks before application bootstrap', static function(Context $t): void {
    $kernel=dirname(__DIR__) . '/kernel';
    $project=__DIR__ . '/fixtures/application_runtime_project';
    $script=<<<'PHP'
$_SERVER=[
    'REMOTE_ADDR'=>'127.0.0.1',
    'REQUEST_URI'=>'/dataphyre/runtime/tick',
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
        'DATAPHYRE_RUNTIME_TICK_PUBLIC_KEY'=>str_repeat('A',43),
    ]);
    $t->processSucceeded($result);
    $t->same(['status'=>404,'body'=>''],$result->json());
})->tag('negative','signed-cadence');

test('status probe refuses noncanonical hosts and caller-selected arguments', static function(Context $t): void {
    $probe=dirname(__DIR__) . '/kernel/application_runtime_status_probe.php';
    $wrongHost=$t->phpProcess([$probe], environment:[
        'DATAPHYRE_RUNTIME_STATUS_HOST'=>'::1',
        'DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
    ]);
    $t->processFailed($wrongHost,64);
    $t->same('',$wrongHost->stdout());
    $t->same('',$wrongHost->stderr());

    $argument=$t->phpProcess([$probe,'--status-port=1'], environment:[
        'DATAPHYRE_RUNTIME_STATUS_HOST'=>'127.0.0.1',
        'DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
    ]);
    $t->processFailed($argument,64);
    $t->same('',$argument->stdout());
    $t->same('',$argument->stderr());
})->tag('negative','probe');
