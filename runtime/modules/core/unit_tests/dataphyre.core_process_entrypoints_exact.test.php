<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\CoverageParts;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/application_runtime_process_broker.php';
require_once dirname(__DIR__,2).'/testing/tooling/TestKit/CoverageParts.php';
require_once __DIR__.'/fixtures/application_runtime_fixed_port_lock.php';

suite('Core fixed process entrypoints')
	->contract('core.process-entrypoints',1)
	->layer('integration')
	->risk('critical')
	->watches('module:core')
	->through('coverage-carrying-subprocess','fixed-runtime-boundaries')
	->isolation('process')
	->tag('core','process','entrypoint','exact-coverage','security')
	->group('framework-coverage');

/** @param list<string> $executables */
function dataphyre_process_entrypoints_exact_native_runtime(array $executables=[]): bool
{
	if(!function_exists('posix_geteuid') || posix_geteuid()!==0
		|| getenv('DATAPHYRE_TEST_CONTAINER_ROOT')!=='1'
		|| !extension_loaded('dataphyre_environment_fd')
		|| phpversion('dataphyre_environment_fd')!=='1.2.0'
		|| !function_exists('dataphyre_open_inherited_environment_fd')
		|| !function_exists('dataphyre_close_inherited_fd')
		|| !function_exists('dataphyre_close_unlisted_inherited_fds')
		|| !function_exists('dataphyre_managed_pool_request_context')) return false;
	foreach($executables as $executable) if(!is_executable($executable)) return false;
	return true;
}

test('fixed CLI entrypoints execute their real help or fail-closed invocation boundary',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$kernel=dirname(__DIR__).'/kernel';
	$runtimeProject=__DIR__.'/fixtures/application_runtime_project';
	$invalidState=$t->workspace('core-process-entrypoint-invalid-executable');
	$invalidExecutable=$invalidState->file('invalid-executable','not-an-executable');
	if(!chmod($invalidExecutable,0700)) throw new RuntimeException('Invalid executable fixture mode could not be prepared.');
	$cases=[
		'application release help'=>[$kernel.'/application_release_preflight.php',['--help'],0],
		'realtime release child'=>[$kernel.'/application_release_preflight_realtime.php',[],64],
		'managed database identity'=>[$kernel.'/application_runtime_database_identity.php',[],64],
		'managed database missing binding'=>[$kernel.'/application_runtime_database_identity.php',['--purpose=primary'],78],
		'managed database wrong driver'=>[$kernel.'/application_runtime_database_identity.php',['--purpose=primary'],69,[
			'DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>'sha256:'.str_repeat('b',64),
			'DATAPHYRE_DATABASE_DSN'=>'sqlite::memory:','DATAPHYRE_DATABASE_USER'=>'fixture','DATAPHYRE_DATABASE_PASSWORD'=>'fixture',
		]],
		'shared cache probe invalid invocation'=>[dirname($kernel,2).'/cache/kernel/shared_cache_probe.php',[],64],
		'one-shot pid one'=>[$kernel.'/application_runtime_one_shot.php',[],77],
		'one-shot dispatcher'=>[$kernel.'/application_runtime_one_shot_worker.php',[],64],
		'one-shot dispatcher missing broker'=>[$kernel.'/application_runtime_one_shot_worker.php',[
			'database_identity',$kernel.'/application_runtime_database_identity.php',
		],78],
		'pre-exec descriptor closer'=>[$kernel.'/application_runtime_pre_exec.php',[],70],
		'pre-exec invalid target'=>[$kernel.'/application_runtime_pre_exec.php',['/definitely-missing-dataphyre-target'],70],
		'pre-exec failed exec'=>[$kernel.'/application_runtime_pre_exec.php',[$invalidExecutable],70],
		'realtime server invalid invocation'=>[$kernel.'/application_runtime_realtime_server.php',[],64],
		'realtime server missing broker'=>[$kernel.'/application_runtime_realtime_server.php',[
			'realtime','0.0.0.0','8080',$runtimeProject,
		],78],
		'realtime status roundtrip'=>[$kernel.'/application_runtime_realtime_probe.php',['--caller-selected'],64],
		'runtime status contract'=>[$kernel.'/application_runtime_status_probe.php',['--caller-selected'],64],
		'pid one supervisor'=>[$kernel.'/application_runtime_supervisor.php',[],77],
	];
	$results=[];
	foreach($cases as $name=>$case){
		[$target,$arguments,$expectedExit]=$case;
		$environment=is_array($case[3] ?? null) ? $case[3] : [];
		$result=$t->coveredPhpProcess(
			[$target,...$arguments],
			environment:$environment,
			timeout_millis:15000,
			framework_root:$frameworkRoot,
		);
		if($expectedExit===0) $t->processSucceeded($result,$name);
		else $t->processFailed($result,$expectedExit,$name);
		$results[$name]=$result;
	}
	$missingFunction=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_supervisor.php'],
		framework_root:$frameworkRoot,
		php_ini:['disable_functions'=>'pcntl_signal'],
	);
	$t->processFailed($missingFunction,70,$missingFunction->stderr());
	$t->contains('Missing required runtime function pcntl_signal',$missingFunction->stderr());

	$help=$results['application release help']->json();
	$t->same('dataphyre.application_release_preflight.v1',$help['contract']);
	$t->same('help',$help['mode']);
	$realtime=$results['realtime status roundtrip']->json();
	$t->same('dataphyre.application_realtime_probe.v1',$realtime['contract']);
	$t->same(false,$realtime['ok']);
})->tag('cli','pid-one','probe','negative');

test('pre-exec closes inherited descriptors and reports an exact failed exec attempt',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$state=$t->workspace('core-process-entrypoint-covered-pre-exec');$part=$state->path('pre-exec-coverage.json');
	$invalidExecutable=$state->file('invalid-executable','not-an-executable');
	if(!chmod($invalidExecutable,0700)) throw new RuntimeException('Invalid executable fixture mode could not be prepared.');
	[$brokerChannel,$childChannel]=DataphyreApplicationRuntimeChildEnvironment::socketPair();
	$pipes=[];$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="Failed-exec descriptor coverage must control the exact inherited native process boundary."
		PHP_BINARY,$coverageBootstrap,$kernel.'/application_runtime_pre_exec.php',$invalidExecutable,
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD=>$childChannel],
		$pipes,$frameworkRoot,[
			'DATAPHYRE_TEST_COVERAGE_PART'=>$part,
			'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
			'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
			'XDEBUG_MODE'=>'coverage','PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
		],['bypass_shell'=>true,'suppress_errors'=>true]);
	fclose($childChannel);fclose($brokerChannel);
	if(!is_resource($process)) throw new RuntimeException('Covered pre-exec process could not be started.');
	$stdout=(string)stream_get_contents($pipes[1]);$stderr=(string)stream_get_contents($pipes[2]);
	fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
	$t->same(70,$exit,$stderr);$t->contains('Exec format error',$stdout);$t->contains('Exec format error',$stderr);
	$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
	if(!is_array($decoded)) throw new RuntimeException('Covered pre-exec did not return exact coverage evidence.');
	CoverageParts::add($decoded);
})->tag('pre-exec','descriptor-boundary','failed-exec','exact-coverage','negative')
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(),
		'Requires the canonical root test image with environment_fd 1.2.',
	);

test('one-shot dispatcher resolves every fixed supported operation after the real broker handshake',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$worker=$kernel.'/application_runtime_one_shot_worker.php';
	$mismatchedTarget=$kernel.'/application_runtime_database_identity.php';
	$state=$t->workspace('core-process-entrypoint-one-shot-operations');
	foreach(['application_preflight','artisan_migrate','dataphyre_materialize_tables','dataphyre_postgresql_migrate','dataphyre_sqlite_migrate','dataphyre_shared_cache_probe'] as $operation){
		$part=$state->file($operation.'.coverage.json','');
		if(!chown($part,10001) || !chgrp($part,10001) || !chmod($part,0600)){
			throw new RuntimeException('One-shot operation coverage ownership could not be prepared.');
		}
		$child=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$coverageBootstrap,$worker,$operation,$mismatchedTarget,
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[
			'DATAPHYRE_TEST_COVERAGE_PART'=>$part,
			'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
			'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
			'XDEBUG_MODE'=>'coverage','PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
		],'one-shot',['ONE_SHOT_OPERATION_PROBE'=>'accepted'],5000);
		$stdout=(string)stream_get_contents($child['pipes'][1]);$stderr=(string)stream_get_contents($child['pipes'][2]);
		fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
		$t->same(64,$exit,$operation.': '.$stderr);$t->same('',$stdout);$t->same('',$stderr);
		$decoded=json_decode((string)file_get_contents($part),true);
		if(!is_array($decoded)) throw new RuntimeException('One-shot operation did not return exact coverage evidence: '.$operation);
		CoverageParts::add($decoded);
	}
	$helpTargets=[
		'application_preflight'=>$kernel.'/application_release_preflight.php',
		'dataphyre_postgresql_migrate'=>dirname($kernel,2).'/sql/kernel/postgresql_migrate.php',
		'dataphyre_sqlite_migrate'=>dirname($kernel,2).'/sql/kernel/sqlite_migrate.php',
	];
	foreach($helpTargets as $operation=>$target){
		$part=$state->file($operation.'-help.coverage.json','');
		if(!chown($part,10001) || !chgrp($part,10001) || !chmod($part,0600)){
			throw new RuntimeException('One-shot help coverage ownership could not be prepared.');
		}
		$child=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$coverageBootstrap,$worker,$operation,$target,'--help',
		],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[
			'DATAPHYRE_TEST_COVERAGE_PART'=>$part,
			'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
			'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
			'XDEBUG_MODE'=>'coverage','PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
		],'one-shot',['ONE_SHOT_OPERATION_PROBE'=>'accepted'],5000);
		$stdout=(string)stream_get_contents($child['pipes'][1]);$stderr=(string)stream_get_contents($child['pipes'][2]);
		fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
		$t->same(0,$exit,$operation.': '.$stderr);$t->same('',$stderr);
		$payload=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
		$t->same(true,$payload['ok'] ?? null,$operation);
		$t->same('help',$payload['mode'] ?? 'help',$operation);
		$decoded=json_decode((string)file_get_contents($part),true);
		if(!is_array($decoded)) throw new RuntimeException('One-shot help did not return exact coverage evidence: '.$operation);
		CoverageParts::add($decoded);
	}
	$cacheTarget=dirname($kernel,2).'/cache/kernel/shared_cache_probe.php';
	$cachePart=$state->file('dataphyre_shared_cache_probe-detect.coverage.json','');
	if(!chown($cachePart,10001) || !chgrp($cachePart,10001) || !chmod($cachePart,0600)){
		throw new RuntimeException('Shared cache probe coverage ownership could not be prepared.');
	}
	$cache=DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,$coverageBootstrap,$worker,'dataphyre_shared_cache_probe',$cacheTarget,
		'--phase=invalid','--challenge='.str_repeat('b',64),
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[
		'DATAPHYRE_TEST_COVERAGE_PART'=>$cachePart,
		'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
		'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
		'XDEBUG_MODE'=>'coverage','PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
	],'one-shot',[
		'DATAPHYRE_APPLICATION_ID'=>'fixture-app','DATAPHYRE_FRAMEWORK_APPLICATION'=>'FixtureApp',
		'DATAPHYRE_ENVIRONMENT'=>'production','DATAPHYRE_APPLICATION_RELEASE'=>'dep_'.str_repeat('a',40),
		'DATAPHYRE_CACHE_MEMCACHED_HOST'=>'cache.internal','DATAPHYRE_CACHE_MEMCACHED_PORT'=>'11211',
	],5000);
	$stdout=(string)stream_get_contents($cache['pipes'][1]);$stderr=(string)stream_get_contents($cache['pipes'][2]);
	fclose($cache['pipes'][1]);fclose($cache['pipes'][2]);$exit=proc_close($cache['resource']);
	$t->same(64,$exit,$stdout);$t->same('',$stdout);
	$t->hasPathValues([
		'contract'=>'dataphyre.shared_cache_probe.v1','ok'=>false,'error.code'=>'invalid_invocation',
	],json_decode($stderr,true,16,JSON_THROW_ON_ERROR));
	$decoded=json_decode((string)file_get_contents($cachePart),true);
	if(!is_array($decoded)) throw new RuntimeException('Shared cache probe did not return exact coverage evidence.');
	CoverageParts::add($decoded);
})->tag('one-shot','fixed-operations','broker','exact-coverage','negative')
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv']),
		'Requires the canonical root test image with environment_fd 1.2 and setpriv.',
	);

test('fixed HTTP routers and CGI prepend reject requests before application bootstrap',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$kernel=dirname(__DIR__).'/kernel';
	$preflight=$t->coveredPhpProcess(
		[$kernel.'/application_release_preflight_router.php'],
		framework_root:$frameworkRoot,
	);
	$t->processSucceeded($preflight);
	$t->same('',$preflight->stdout());
	$t->same('',$preflight->stderr());

	$runtime=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_router.php'],
		framework_root:$frameworkRoot,
	);
	$t->processSucceeded($runtime);
	$t->same('{"ok":false}',$runtime->stdout());
	$t->same('',$runtime->stderr());

	$cgi=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_cgi_environment_boundary.php',
		[$kernel],
		framework_root:$frameworkRoot,
	);
	$t->processSucceeded($cgi);
	$t->same([
		'contract'=>'dataphyre.application_runtime_cgi_environment_boundary.v1',
		'rejected'=>true,
		'error'=>'Application CGI role is invalid.',
	],$cgi->json());
	$broker=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_process_broker_boundary.php',
		[$kernel,$frameworkRoot],framework_root:$frameworkRoot,
		php_ini:['disable_functions'=>'dataphyre_close_unlisted_inherited_fds'],
	);
	$t->processSucceeded($broker,$broker->stderr());
	$brokerBoundaryMessage=function_exists('posix_geteuid') && posix_geteuid()===0
		? 'Application process broker descriptor boundary is unavailable.'
		: 'Application process broker invocation is invalid.';
	$t->same([
		'rejected'=>true,'message'=>$brokerBoundaryMessage,
	],$broker->json());
})->tag('router','cgi','negative');

test('realtime release child performs the ordinary framework bootstrap and returns sealed registration evidence',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$runtimeRoot=dirname(__DIR__,3);
	$kernel=dirname(__DIR__).'/kernel';
	$project=__DIR__.'/fixtures/application_runtime_project';
	$state=$t->workspace('core-process-entrypoint-realtime-state');
	$result=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,
		'--application=_Runtime$Probe',
		'--environment=staging_blue',
	],environment:[
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$state->root(),
		'DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'valid-dependency',
	],working_directory:$frameworkRoot,timeout_millis:30000);
	$t->processSucceeded($result,$result->stderr());
	$payload=$result->json();
	$t->same('dataphyre.application_realtime_registration.v1',$payload['contract']);
	$t->same(true,$payload['ok']);
	$t->same(1,$payload['route_count']);
	$t->same(1,$payload['scheduler_definition_count']);
	$t->same(0,$payload['registered_table_count']);
	$t->same('dataphyre.registered_table_materialization.v1',$payload['registered_table_materialization_contract']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['registration_sha256']);
	$t->same('sha256:'.hash('sha256','[]'),$payload['registered_table_set_sha256']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['scheduler_definition_sha256']);
	$t->same('',$result->stderr());
})->tag('realtime','bootstrap','registration','scheduling');

test('status and realtime probes accept one canonical supervisor roundtrip over the fixed loopback port',static function(Context $t): void {
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	$frameworkRoot=dirname(__DIR__,4);
	$kernel=dirname(__DIR__).'/kernel';
	$state=$t->workspace('core-process-entrypoint-status-server');
	$cloudApplication='serve';
	$frameworkApplication='Serve';
	$environment='staging_blue';
	$releaseId='dep_'.str_repeat('a',40);
	$environmentFingerprint='hmac-sha256:'.str_repeat('b',64);
	$generation='gen_'.str_repeat('c',32);
	$pool=static function(int $pid,string $role,string $host,int $port): array {
		$privileged=in_array($role,['web','scheduler'],true);
		return [
			'running'=>true,'pid'=>$pid,'uid'=>$privileged ? 0 : 10001,'gid'=>$privileged ? 0 : 10001,
			'supplementary_gids'=>[$privileged ? 0 : 10001],
			'cap_eff'=>$privileged ? '00000000000000c0' : '0000000000000000',
			'no_new_privileges'=>true,'role'=>$role,'listen_host'=>$host,'listen_port'=>$port,
			'parent_pid'=>1,'execution_model'=>$privileged ? 'one-request-per-process-cgi' : 'single-exec-realtime',
		];
	};
	$stateIdentity=[
		'contract'=>'dataphyre.scheduler_state.v1','cloud_application'=>$cloudApplication,
		'framework_application'=>$frameworkApplication,'environment'=>$environment,
	];
	$noopIdentity=[
		'cloud_application'=>$cloudApplication,'framework_application'=>$frameworkApplication,
		'environment'=>$environment,'release_id'=>$releaseId,'environment_fingerprint'=>$environmentFingerprint,
	];
	$status=[
		'contract'=>'dataphyre.application_runtime.v4','cloud_application'=>$cloudApplication,
		'framework_application'=>$frameworkApplication,'environment'=>$environment,'release_id'=>$releaseId,
		'environment_fingerprint'=>$environmentFingerprint,'generation'=>$generation,
		'supervisor_pid'=>1,'supervisor_uid'=>0,'supervisor_gid'=>0,'activation_mode'=>'active','active'=>true,
		'scheduler_cycle_in_progress'=>false,
		'web'=>$pool(101,'web','127.0.0.1',8083),
		'scheduler'=>$pool(102,'scheduler','127.0.0.1',8081),
		'realtime'=>$pool(103,'realtime','0.0.0.0',8080),
		'scheduler_registration'=>[
			'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,
			'registration_attempt_count'=>1,'registration_accepted_count'=>1,'registration_failure_count'=>0,
			'definition_count'=>1,'definition_sha256'=>'sha256:'.str_repeat('d',64),
		],
		'scheduler_noop_probe'=>[
			'contract'=>'dataphyre.scheduler_noop_probe.v1','ok'=>true,'generation'=>$generation,
			'request_counter'=>1,'claim_consumed'=>true,'worker_receipt'=>true,'worker_reaped'=>true,
			'replay_suppressed'=>true,'count'=>1,'last_at'=>'2026-08-13T12:00:00Z','previous_readback'=>false,
			'state_identity_sha256'=>'sha256:'.hash(
				'sha256',"dataphyre.scheduler_noop_probe_identity.v1\0".json_encode($noopIdentity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
			),
		],
		'scheduler_state_identity_sha256'=>'sha256:'.hash(
			'sha256',"dataphyre.scheduler_state_identity.v1\0".json_encode($stateIdentity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
		),
		'business_cadence'=>['count'=>1,'last_at'=>'2026-08-13T12:00:00Z','last_result'=>'ok'],
	];
	$realtime=[
		'contract'=>'dataphyre.application_realtime_probe.v1','ok'=>true,
		'framework_listener_roundtrip'=>true,'application_authorization_rejections'=>true,
		'application_authorization_rejection_count'=>1,'registration_sha256'=>'sha256:'.str_repeat('e',64),
		'ping_pong'=>true,'close_handshake'=>true,
	];
	$realtimeFailed=$realtime;
	$realtimeFailed['ok']=false;
	$invalidRegistration=$status;
	$invalidRegistration['scheduler_registration']['ok']=false;
	$statusPath=$state->file('status.json',json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$realtimePath=$state->file('realtime.json',json_encode($realtime,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	$malformedPath=$state->file('malformed.json','{');
	$noncanonicalPath=$state->file('noncanonical.json','{"bad": true}');
	$invalidRegistrationPath=$state->file(
		'invalid-registration.json',json_encode($invalidRegistration,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
	);
	$invalidRealtimePath=$state->file('invalid-realtime.json','{}');
	$oversizedRealtimePath=$state->file('oversized-realtime.json',str_repeat('x',9000));
	$realtimeFailedPath=$state->file(
		'realtime-failed.json',json_encode($realtimeFailed,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
	);
	$ready=$state->path('ready');
	$server=$t->startPhpProcess([
		__DIR__.'/fixtures/application_runtime_status_server.php',$ready,
		$statusPath,$realtimePath,$malformedPath,$noncanonicalPath,$invalidRegistrationPath,
		$invalidRealtimePath,$oversizedRealtimePath,$realtimeFailedPath,
	],timeout_millis:15000);
	$deadline=microtime(true)+5.0;
	while(!is_file($ready) && microtime(true)<$deadline) usleep(10000);
	$t->isTrue(is_file($ready),'the fixed loopback fixture is listening');

	$statusResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_status_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($statusResult,$statusResult->stderr());
	$t->same(json_encode($status,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),trim($statusResult->stdout()));

	$realtimeResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_realtime_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($realtimeResult,$realtimeResult->stderr());
	$t->same($realtime,$realtimeResult->json());

	$malformedResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_status_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($malformedResult,65);
	$t->same('',$malformedResult->stdout());

	$noncanonicalResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_status_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($noncanonicalResult,65);
	$t->same('',$noncanonicalResult->stdout());

	$invalidRegistrationResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_status_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($invalidRegistrationResult,65);
	$t->same('',$invalidRegistrationResult->stdout());

	$invalidRealtimeResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_realtime_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($invalidRealtimeResult,70);
	$t->same(false,$invalidRealtimeResult->json()['ok']);

	$oversizedRealtimeResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_realtime_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($oversizedRealtimeResult,70);
	$t->same(false,$oversizedRealtimeResult->json()['ok']);

	$realtimeFailedResult=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_realtime_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($realtimeFailedResult,70);
	$t->same($realtimeFailed,$realtimeFailedResult->json());
	$t->processSucceeded($server->wait(10000));

	$unavailable=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_status_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($unavailable,69);
	$t->same('',$unavailable->stdout());
	$unavailableRealtime=$t->coveredPhpProcess(
		[$kernel.'/application_runtime_realtime_probe.php'],
		timeout_millis:10000,framework_root:$frameworkRoot,
	);
	$t->processFailed($unavailableRealtime,69);
	$t->same(false,$unavailableRealtime->json()['ok']);
	dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);
})->tag('status','realtime','loopback','positive','canonical');

test('realtime release child rejects invalid invocation partial registration and malformed isolated scheduler state',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$runtimeRoot=dirname(__DIR__,3);
	$kernel=dirname(__DIR__).'/kernel';
	$project=__DIR__.'/fixtures/application_runtime_project';
	$invalidSyntax=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,'application=_Runtime$Probe','--environment=staging',
	],working_directory:$frameworkRoot);
	$t->processFailed($invalidSyntax,64);
	$invalidEnvironment=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,'--application=_Runtime$Probe','--environment=..',
	],working_directory:$frameworkRoot);
	$t->processFailed($invalidEnvironment,64);
	$unwritableTemporaryRoot=$t->phpProcess([
		$kernel.'/application_release_preflight_realtime.php',
		'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
	],working_directory:$frameworkRoot,environment:['TMPDIR'=>'/proc']);
	$t->processFailed($unwritableTemporaryRoot,70);

	$cases=[
		'partial-registration'=>['DATAPHYRE_RUNTIME_TEST_INVALID_SCHEDULER_REGISTRATION'=>'1'],
		'missing-root'=>['DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'missing-root'],
		'invalid-entry'=>['DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'invalid-entry'],
		'extra-state'=>['DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'extra-state'],
		'malformed-definition'=>['DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'malformed-definition'],
		'missing-dependency'=>['DATAPHYRE_RUNTIME_TEST_SCHEDULER_STATE_MUTATION'=>'missing-dependency'],
	];
	foreach($cases as $name=>$mutation){
		$state=$t->workspace('core-process-entrypoint-realtime-negative-'.$name);
		$result=$t->phpProcess([
			$kernel.'/application_release_preflight_realtime.php',
			'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
		],environment:[
			'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
			'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$state->root(),
			...$mutation,
		],working_directory:$frameworkRoot,timeout_millis:30000);
		$t->processFailed($result,70,$name);
		$t->same([
			'contract'=>'dataphyre.application_realtime_registration.v1','ok'=>false,
			'route_count'=>0,'registration_sha256'=>null,
			'registered_table_count'=>0,
			'registered_table_materialization_contract'=>'dataphyre.registered_table_materialization.v1',
			'registered_table_set_sha256'=>null,
			'scheduler_definition_count'=>0,'scheduler_definition_sha256'=>null,
		],$result->json(),$name);
		$t->same('',$result->stderr(),$name);
	}
})->tag('realtime','scheduling','isolated-state','negative','cleanup');

test('activation latch persists exact root-owned state and rejects corrupt or linked replacements',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$kernel=dirname(__DIR__).'/kernel';
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_activation_latch_boundary.php',
		[$kernel],timeout_millis:15000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($result,$result->stderr());
	$payload=$result->json();
	$runningAsRoot=function_exists('posix_geteuid') && posix_geteuid()===0;
	if(!$runningAsRoot){
		$t->same(false,$payload['supported']);
		return;
	}
	$t->same([
		'supported'=>true,'initial'=>false,'active'=>true,'inactive'=>false,
		'invalid_contents_rejected'=>true,'link_rejected_on_restore'=>true,'link_rejected_on_persist'=>true,
		'hardlink_rejected'=>true,'directory_mode_rejected'=>true,
		'file_mode'=>0600,'file_uid'=>0,'file_gid'=>0,'cleaned'=>true,
	],$payload);
	$faults=[
		'temporary-unavailable'=>['chmod','Runtime activation temporary file could not be created.'],
		'write-unavailable'=>['fwrite','Runtime activation latch write failed.'],
		'sync-unavailable'=>['fsync','Runtime activation latch could not be synchronized.'],
		'identity-unavailable'=>['fstat','Runtime activation temporary file identity is invalid.'],
		'replacement-unavailable'=>['rename','Runtime activation latch replacement failed.'],
		'mkdir-unavailable'=>['mkdir','Runtime activation directory could not be created.'],
	];
	foreach($faults as $mode=>[$disabledFunction,$message]){
		$fault=$t->coveredPhpFixture(
			__DIR__.'/fixtures/application_runtime_activation_latch_boundary.php',
			[$kernel,$mode],timeout_millis:15000,framework_root:$frameworkRoot,
			php_ini:['disable_functions'=>$disabledFunction],
		);
		$t->processSucceeded($fault,$mode.': '.$fault->stderr());
		$t->same([
			'supported'=>true,'mode'=>$mode,'failure_class'=>RuntimeException::class,
			'failure_message'=>$message,'temporary_count'=>0,'cleaned'=>true,
		],$fault->json(),$mode);
	}
})->tag('activation','durability','filesystem','root','positive','negative');

test('health preflight router enters the ordinary application bootstrap with record-only scheduling',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$runtimeRoot=dirname(__DIR__,3);
	$kernel=dirname(__DIR__).'/kernel';
	$project=__DIR__.'/fixtures/application_runtime_project';
	$state=$t->workspace('core-process-entrypoint-health-router');
	$sentinel=$state->file('sentinel.txt','unchanged');
	$before=hash_file('sha256',$sentinel);
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_release_preflight_router_boundary.php',
		[$kernel,$project,$state->root(),$runtimeRoot],
		timeout_millis:30000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($result,$result->stderr());
	$t->same(['status'=>'healthy','missing_environment_keys'=>[]],$result->json());
	$t->same($before,hash_file('sha256',$sentinel));
	$t->same('',$result->stderr());
})->tag('health','router','bootstrap','record-only','positive');

test('realtime registry rejects caller access duplicates limits and post-seal mutation in one covered process',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$kernel=dirname(__DIR__).'/kernel';
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_realtime_registry_boundary.php',
		[$kernel],framework_root:$frameworkRoot,
	);
	$t->processSucceeded($result,$result->stderr());
	$payload=$result->json();
	$t->same(true,$payload['wrongPool']);
	$t->same(true,$payload['invalid']);
	$t->same(true,$payload['duplicate']);
	$t->same(true,$payload['limit']);
	$t->same(true,$payload['sealed']);
	$t->same(128,$payload['evidence']['route_count']);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',$payload['evidence']['registration_sha256']);
})->tag('realtime','registry','limit','sealed','positive','negative');

test('realtime server main fails closed across fixed pool address bootstrap and bind boundaries',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);
	$runtimeRoot=dirname(__DIR__,3);
	$kernel=dirname(__DIR__).'/kernel';
	$project=__DIR__.'/fixtures/application_runtime_project';
	$state=$t->workspace('core-process-entrypoint-realtime-main');
	$result=$t->coveredPhpFixture(
		__DIR__.'/fixtures/application_runtime_realtime_server_boundary.php',
		[$kernel,$project,$runtimeRoot,$state->root()],
		timeout_millis:30000,framework_root:$frameworkRoot,
	);
	$t->processSucceeded($result,$result->stderr());
	$t->same([
		'probeConflict'=>true,
		'reservedOrigin'=>true,
		'wrongPool'=>64,
		'wrongAddress'=>64,
		'invalidBootstrap'=>70,
		'bindFailure'=>70,
		'parseRejections'=>[true,true,true,true],
	],$result->json());
	$t->contains('Fixed realtime runtime addresses are unavailable.',$result->stderr());
	$t->contains('Realtime application context is invalid.',$result->stderr());
	$t->contains('Unable to bind fixed public ingress.',$result->stderr());
})->tag('realtime','main','bind','bootstrap','fixed-address','negative');

test('realtime callback dependency branches remain bounded when optional signal functions are unavailable',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$kernel=dirname(__DIR__).'/kernel';
	$scanDirectory=(string)getenv('PHP_INI_SCAN_DIR');
	$missingIni=$t->workspace('core-realtime-missing-dependency-ini');
	$missingIni->file('disable.ini',"disable_functions=pcntl_alarm\n");
	$missing=$t->coveredPhpProcess([
		__DIR__.'/fixtures/application_runtime_realtime_dependency_boundary.php',$kernel,'missing',
	],environment:['PHP_INI_SCAN_DIR'=>$scanDirectory.PATH_SEPARATOR.$missingIni->root()],framework_root:$frameworkRoot);
	$t->processSucceeded($missing,$missing->stderr());
	$t->same(['fast'=>'fast','slowRejected'=>true,'main'=>70],$missing->json());
	$t->contains('Realtime runtime dependency is unavailable.',$missing->stderr());
	$handlerIni=$t->workspace('core-realtime-handler-dependency-ini');
	$handlerIni->file('disable.ini',"disable_functions=pcntl_signal_get_handler\n");
	$handler=$t->coveredPhpProcess([
		__DIR__.'/fixtures/application_runtime_realtime_dependency_boundary.php',$kernel,'handler',
	],environment:['PHP_INI_SCAN_DIR'=>$scanDirectory.PATH_SEPARATOR.$handlerIni->root()],framework_root:$frameworkRoot);
	$t->processSucceeded($handler,$handler->stderr());
	$t->same(['fast'=>'fast','slowRejected'=>false,'main'=>null],$handler->json());
	$t->same('',$handler->stderr());
})->tag('realtime','callback','dependency','timeout','negative');

test('realtime server drains proxy streams and enforces maintenance bounds on live socket pairs',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_realtime_server.php';
	$server=new DataphyreApplicationRuntimeRealtimeServer([]);
	$internals=$t->nonPublic($server);
	$client=static function($stream,array $overrides=[]): array {
		return $overrides+[
			'stream'=>$stream,'peer'=>'127.0.0.1:12345','phase'=>'proxy','created_at'=>microtime(true),
			'header_buffer'=>'','frame_buffer'=>'','write_buffer'=>'','to_backend'=>'','backend'=>null,
			'close_after_write'=>false,'authorization'=>[],'events'=>null,'cursor'=>null,
			'next_event_at'=>microtime(true)+60,'last_ping_at'=>microtime(true),'pong_deadline'=>null,
		];
	};
	[$clientStream,$clientPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	[$backendStream,$backendPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	foreach([$clientStream,$clientPeer,$backendStream,$backendPeer] as $stream) stream_set_blocking($stream,false);
	$id=(int)$clientStream;
	$internals->writeProperty('clients',[
		$id=>$client($clientStream,['backend'=>$backendStream,'to_backend'=>'private-request']),
	]);
	$internals->invoke('writeBackend',$id);
	$t->same('private-request',(string)fread($backendPeer,1024));
	fwrite($backendPeer,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
	$internals->invoke('readBackend',$id);
	$t->contains('200 OK',$internals->readProperty('clients')[$id]['write_buffer']);
	$internals->invoke('writeClient',$id);
	$t->contains('200 OK',(string)fread($clientPeer,4096));
	fclose($backendPeer);
	$internals->invoke('readBackend',$id);
	$t->isFalse(isset($internals->readProperty('clients')[$id]));
	fclose($clientPeer);
	[$failedBackend,$failedBackendPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	[$failedClient,$failedClientPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$failedClient;fclose($failedBackendPeer);
	$internals->writeProperty('clients',[
		$id=>$client($failedClient,['backend'=>$failedBackend,'to_backend'=>'cannot-write']),
	]);
	$internals->invoke('writeBackend',$id);
	$t->isFalse(isset($internals->readProperty('clients')[$id]));
	fclose($failedClientPeer);

	[$cycleClient,$cycleClientPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	[$cycleBackend,$cycleBackendPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	[$cycleListener,$cycleListenerPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	foreach([$cycleClient,$cycleClientPeer,$cycleBackend,$cycleBackendPeer,$cycleListener,$cycleListenerPeer] as $stream){
		stream_set_blocking($stream,false);
	}
	$id=(int)$cycleClient;
	$internals->writeProperty('listener',$cycleListener);
	$internals->writeProperty('clients',[
		$id=>$client($cycleClient,[
			'backend'=>$cycleBackend,'to_backend'=>'cycle-to-backend','write_buffer'=>'cycle-to-client',
		]),
	]);
	fwrite($cycleClientPeer,'cycle-client-body');fwrite($cycleBackendPeer,'cycle-backend-response');
	$internals->invoke('cycle');
	$t->same('cycle-to-backendcycle-client-body',(string)fread($cycleBackendPeer,1024));
	$t->same('cycle-to-clientcycle-backend-response',(string)fread($cycleClientPeer,1024));
	$cycleState=$internals->readProperty('clients')[$id];
	$t->same('',$cycleState['to_backend']);
	$t->same('',$cycleState['write_buffer']);
	$internals->writeProperty('clients',[]);$internals->writeProperty('listener',null);
	foreach([$cycleClient,$cycleClientPeer,$cycleBackend,$cycleBackendPeer,$cycleListener,$cycleListenerPeer] as $stream){
		if(is_resource($stream)) fclose($stream);
	}

	[$proxyStream,$proxyPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$proxyStream;
	$internals->writeProperty('clients',[$id=>$client($proxyStream)]);
	fwrite($proxyPeer,'client-body');
	$internals->invoke('readClient',$id);
	$t->same('client-body',$internals->readProperty('clients')[$id]['to_backend']);
	fclose($proxyPeer);$internals->invoke('readClient',$id);
	$t->isFalse(isset($internals->readProperty('clients')[$id]));

	[$frameStream,$framePeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$frameStream;
	$internals->writeProperty('clients',[
		$id=>$client($frameStream,['phase'=>'websocket','frame_buffer'=>str_repeat('x',262144)]),
	]);
	fwrite($framePeer,'x');$internals->invoke('readClient',$id);
	$t->same(true,$internals->readProperty('clients')[$id]['close_after_write']);
	fclose($framePeer);fclose($frameStream);
	[$incompleteFrame,$incompleteFramePeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$incompleteFrame;
	$internals->writeProperty('clients',[
		$id=>$client($incompleteFrame,['phase'=>'websocket','frame_buffer'=>"\x81"]),
	]);
	$internals->invoke('consumeFrames',$id);
	$t->same("\x81",$internals->readProperty('clients')[$id]['frame_buffer']);
	fclose($incompleteFramePeer);fclose($incompleteFrame);
	$internals->writeProperty('clients',[]);$internals->invoke('consumeFrames',$id);

	[$queueStream,$queuePeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$queueStream;
	$internals->writeProperty('clients',[$id=>$client($queueStream,['phase'=>'websocket'])]);
	$t->same(true,$internals->invoke('queueWebsocket',$id,0x2,str_repeat('q',65536)));
	$clients=$internals->readProperty('clients');
	$clients[$id]['write_buffer']=str_repeat('w',262144);$internals->writeProperty('clients',$clients);
	$t->same(false,$internals->invoke('queueWebsocket',$id,0x1,'overflow'));
	$internals->invoke('websocketClose',$id,1009);
	$t->isFalse(isset($internals->readProperty('clients')[$id]));
	fclose($queuePeer);

	$now=microtime(true);$pairs=[];$clients=[];
	foreach(['header','deadline','ping'] as $kind){
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pairs[]=$pair;$id=(int)$pair[0];
		$overrides=match($kind){
			'header'=>['phase'=>'headers','created_at'=>$now-6],
			'deadline'=>['phase'=>'websocket','pong_deadline'=>$now-1],
			default=>['phase'=>'websocket','last_ping_at'=>$now-31,'next_event_at'=>$now+60],
		};
		$clients[$id]=$client($pair[0],$overrides);
	}
	$internals->writeProperty('clients',$clients);
	$internals->invoke('maintainClients',$now);
	$maintained=$internals->readProperty('clients');
	$t->same('closing',$maintained[(int)$pairs[0][0]]['phase']);
	$t->same(true,$maintained[(int)$pairs[1][0]]['close_after_write']);
	$t->isTrue(is_float($maintained[(int)$pairs[2][0]]['pong_deadline']));
	foreach($pairs as $pair) foreach($pair as $stream) if(is_resource($stream)) fclose($stream);

	[$invalidProxy,$invalidProxyPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$invalidProxy;
	$internals->writeProperty('clients',[$id=>$client($invalidProxy,['phase'=>'headers'])]);
	$internals->invoke('startProxy',$id,[
		'method'=>'GET','target'=>'/','protocol'=>'HTTP/1.1','headers'=>['host'=>'example.test'],
	]);
	$t->same('closing',$internals->readProperty('clients')[$id]['phase']);
	$t->same('unbracketed-peer',$internals->invoke('remoteAddress','unbracketed-peer'));
	fclose($invalidProxy);fclose($invalidProxyPeer);
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	$webListener=stream_socket_server('tcp://127.0.0.1:8083',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
	if(!is_resource($webListener)) throw new RuntimeException('Could not bind fixed web backend: '.$errno.' '.$error);
	[$successfulProxy,$successfulProxyPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$id=(int)$successfulProxy;
	$internals->writeProperty('clients',[$id=>$client($successfulProxy,['phase'=>'headers','header_buffer'=>
		"GET /health HTTP/1.1\r\nHost: example.test\r\nConnection: close\r\n\r\n",
	])]);
	$internals->invoke('startProxy',$id,[
		'method'=>'GET','target'=>'/health','protocol'=>'HTTP/1.1','headers'=>['host'=>'example.test','connection'=>'close'],
	]);
	$accepted=stream_socket_accept($webListener,1);
	if(!is_resource($accepted)) throw new RuntimeException('Fixed web backend did not accept the proxy connection.');
	$proxied=$internals->readProperty('clients')[$id];
	$t->same('proxy',$proxied['phase']);$t->isTrue(is_resource($proxied['backend']));
	$t->contains("GET /health HTTP/1.1\r\n",$proxied['to_backend']);$t->same('',$proxied['header_buffer']);
	$internals->invoke('closeClient',$id);
	fclose($accepted);fclose($webListener);fclose($successfulProxyPeer);
	dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);
	$t->throws(
		static fn()=>$internals->invoke('runBoundedCallback',static function(): void {usleep(1100000);},[],0.5),
		RuntimeException::class,
	);
	$t->throws(static fn()=>$internals->invoke('callbackDeadlineExceeded'),RuntimeException::class);

	[$listener,$listenerPeer]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	$internals->writeProperty('listener',$listener);
	$internals->writeProperty('clients',[999999=>$client(null)]);
	$internals->invoke('cycle');
	$t->same([],$internals->readProperty('clients'));
	fclose($listener);fclose($listenerPeer);
})->tag('realtime','proxy','socketpair','maintenance','bounds','positive','negative');

test('process broker seals standard input before acknowledgement and reaps early or stalled children',static function(Context $t): void {
	$frameworkRoot=(string)realpath(dirname(__DIR__,4));
	$kernel=(string)realpath(dirname(__DIR__).'/kernel');
	$fixture=(string)realpath(__DIR__.'/fixtures/application_runtime_process_broker_input.php');
	$state=$t->workspace('core-process-entrypoint-broker-input');
	$spawn=static function(string $mode,string $pidPath,string $input,int $timeout) use ($frameworkRoot,$kernel,$fixture): array {
		return DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$fixture,$kernel,$mode,$pidPath,
		],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[],'one-shot',[
			'BROKER_INPUT_PROBE'=>'accepted',
		],$timeout,null,$input);
	};
	$pidPath=$state->file('consume.pid','');
	if(!chown($pidPath,10001) || !chgrp($pidPath,10001) || !chmod($pidPath,0600)){
		throw new RuntimeException('Broker input probe ownership could not be prepared.');
	}
	$input=random_bytes(131072);
	$child=$spawn('consume',$pidPath,$input,5000);
	$t->isFalse(isset($child['pipes'][0]),'sealed standard input is not returned to the caller');
	$stdout=(string)stream_get_contents($child['pipes'][1]);$stderr=(string)stream_get_contents($child['pipes'][2]);
	fclose($child['pipes'][1]);fclose($child['pipes'][2]);
	$t->same(0,proc_close($child['resource']),$stderr);
	$t->same([
		'contract'=>'dataphyre.application_runtime_process_broker_input_probe.v1',
		'length'=>strlen($input),'sha256'=>hash('sha256',$input),'environment_received'=>true,
	],json_decode($stdout,true,8,JSON_THROW_ON_ERROR));
	$t->same('',$stderr);
	sodium_memzero($input);

	foreach(['exit'=>[str_repeat('e',1048576),1000],'stall'=>[str_repeat('s',1048576),1000]] as $mode=>[$failureInput,$timeout]){
		$failurePidPath=$state->file($mode.'.pid','');
		if(!chown($failurePidPath,10001) || !chgrp($failurePidPath,10001) || !chmod($failurePidPath,0600)){
			throw new RuntimeException('Broker failure probe ownership could not be prepared.');
		}
		$error=null;
		try{$spawn($mode,$failurePidPath,$failureInput,$timeout);}
		catch(RuntimeException $failure){$error=$failure;}
		$t->isTrue($error instanceof RuntimeException,$mode.' child was rejected');
		$failurePid=(int)trim((string)file_get_contents($failurePidPath));
		$t->isTrue($failurePid>1,$mode.' child published its exact pid');
		$deadline=microtime(true)+2.0;
		while(is_dir('/proc/'.$failurePid) && microtime(true)<$deadline) usleep(10000);
		$t->isFalse(is_dir('/proc/'.$failurePid),$mode.' child was killed and reaped');
		if($mode==='stall') $t->contains('standard input timed out',(string)$error?->getMessage());
		if($failureInput!=='') sodium_memzero($failureInput);
	}

	$inputWriter=$t->nonPublic(DataphyreApplicationRuntimeProcessBroker::class);
	$limit=$inputWriter->readConstant('MAX_STANDARD_INPUT_BYTES');
	$t->same(268435456,$limit);
	$oversized=str_repeat('o',$limit+1);$oversizeError=null;
	try{$spawn('consume',$pidPath,$oversized,5000);}
	catch(RuntimeException $failure){$oversizeError=$failure;}
	$t->isTrue($oversizeError instanceof RuntimeException);
	$t->same('Application process broker invocation is invalid.',$oversizeError?->getMessage());
	sodium_memzero($oversized);
	$t->throws(static fn()=>DataphyreApplicationRuntimeProcessBroker::spawn(
		['/bin/true'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],
		$frameworkRoot,[],'one-shot',['BROKER_INPUT_PROBE'=>'accepted'],1000,null,'input',
	),RuntimeException::class);
	$t->throws(static fn()=>DataphyreApplicationRuntimeProcessBroker::spawn(
		['/bin/true'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],
		$frameworkRoot,['invalid-name'=>'value'],'one-shot',['BROKER_INPUT_PROBE'=>'accepted'],1000,
	),RuntimeException::class);
	$missingPipes=[];$missingInputError=null;
	try{$inputWriter->capture('writeStandardInput',null,$missingPipes,'x',1000);}
	catch(Throwable $failure){$missingInputError=$failure;}
	$t->same('Application process standard input is unavailable.',$missingInputError?->getMessage());
	foreach(['ack-exit','ack-stall'] as $mode){
		$probePidPath=$state->file($mode.'.pid','');
		if(!chown($probePidPath,10001) || !chgrp($probePidPath,10001) || !chmod($probePidPath,0600)){
			throw new RuntimeException('Broker acknowledged probe ownership could not be prepared.');
		}
		$probe=DataphyreApplicationRuntimeProcessBroker::spawn([
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			PHP_BINARY,$fixture,$kernel,$mode,$probePidPath,
		],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[],'one-shot',[
			'BROKER_INPUT_PROBE'=>'accepted',
		],1000);
		if($mode==='ack-exit'){
			$deadline=microtime(true)+2.0;
			do{$status=proc_get_status($probe['resource']);if(($status['running'] ?? false)!==true) break;usleep(10000);}
			while(microtime(true)<$deadline);
		}
		$unwritable=fopen('/dev/null','rb');$fakePipes=[0=>$unwritable];$writeError=null;
		try{$inputWriter->capture('writeStandardInput',$probe['resource'],$fakePipes,'x',1000);}
		catch(Throwable $failure){$writeError=$failure;}
		$t->isTrue($writeError instanceof RuntimeException,$mode.' private input failure was rejected');
		if($mode==='ack-exit') $t->contains('exited before accepting',$writeError?->getMessage() ?? '');
		else $t->contains('standard input write failed',$writeError?->getMessage() ?? '');
		foreach($probe['pipes'] as $pipe) if(is_resource($pipe)) fclose($pipe);
		$status=proc_get_status($probe['resource']);
		if(($status['running'] ?? false)===true) posix_kill($probe['pid'],SIGKILL);
		proc_close($probe['resource']);
	}
})->tag('process-broker','stdin','pre-ack','timeout','early-exit','reap','positive','negative')->maxMillis(15000)
	->memoryLimit('512M')
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv']),
		'Requires the canonical root test image with environment_fd 1.2 and setpriv.',
	);

test('instrumented exact CGI scheduler child covers the signed scheduler router',static function(Context $t): void {
	$frameworkRoot=(string)realpath(dirname(__DIR__,4));
	$runtimeRoot=(string)realpath(dirname(__DIR__,3));
	$kernel=(string)realpath(dirname(__DIR__).'/kernel');
	$router=$kernel.'/application_runtime_router.php';
	$project=(string)realpath(__DIR__.'/fixtures/application_runtime_project');
	$prepend=(string)realpath(__DIR__.'/fixtures/application_runtime_cgi_coverage_prepend.php');
	require_once $kernel.'/application_runtime_scheduler_protocol.php';
	$state=$t->workspace('core-process-entrypoint-scheduler-router-cgi');
	$stateRoot=$state->path('runtime-state');
	if(!mkdir($stateRoot,0700,true) || !chown($stateRoot,10001) || !chgrp($stateRoot,10001)){
		throw new RuntimeException('Scheduler router CGI state ownership could not be prepared.');
	}
	$heartbeat=$state->file('heartbeat.json','');
	if(!chown($heartbeat,10001) || !chgrp($heartbeat,10001) || !chmod($heartbeat,0600)){
		throw new RuntimeException('Scheduler router CGI heartbeat ownership could not be prepared.');
	}
	$keypair=sodium_crypto_sign_keypair();$secretKey=sodium_crypto_sign_secretkey($keypair);
	$publicKey=sodium_crypto_sign_publickey($keypair);$privateKey=random_bytes(32);
	$publicKeyEncoded=sodium_bin2base64($publicKey,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	$identity=[
		'cloud_application'=>'runtime-probe','framework_application'=>'_Runtime$Probe',
		'environment'=>'staging_blue','release_id'=>'dep_'.str_repeat('a',40),
	];
	$generation='gen_'.str_repeat('b',32);
	$applicationEnvironment=[
		'DATAPHYRE_APPLICATION_ID'=>$identity['cloud_application'],
		'DATAPHYRE_FRAMEWORK_APPLICATION'=>$identity['framework_application'],
		'DATAPHYRE_ENVIRONMENT'=>$identity['environment'],
		'DATAPHYRE_APPLICATION_ENVIRONMENT'=>$identity['environment'],
		'DATAPHYRE_APPLICATION_RELEASE'=>$identity['release_id'],
		'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
		'DATAPHYRE_RUNTIME_APPLICATION'=>$identity['framework_application'],
		'DATAPHYRE_RUNTIME_ENVIRONMENT'=>$identity['environment'],
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$stateRoot,
		'DATAPHYRE_SCHEDULER_STATE_ROOT'=>$stateRoot,
		'DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'=>$publicKeyEncoded,
		'DATAPHYRE_RUNTIME_SCHEDULER_TICK'=>'1',
		'DATAPHYRE_RUNTIME_TEST_HEARTBEAT_PATH'=>$heartbeat,
	];
	$scanDirectory=(string)getenv('PHP_INI_SCAN_DIR');$requestIndex=0;
	$run=static function(
		string $method,string $target,string $body,array $values
	) use (
		$t,$frameworkRoot,$kernel,$router,$project,$prepend,$privateKey,$scanDirectory,$state,&$requestIndex
	): array {
		$part=$state->file('scheduler-router-cgi-coverage-'.(++$requestIndex).'.json','');
		if(!chown($part,10001) || !chgrp($part,10001) || !chmod($part,0600)){
			throw new RuntimeException('Scheduler router CGI coverage ownership could not be prepared.');
		}
		$port=8081;
		$path=(string)(parse_url($target,PHP_URL_PATH) ?: '/');
		$query=(string)(parse_url($target,PHP_URL_QUERY) ?: '');
		$public=[
			'CONTENT_LENGTH'=>(string)strlen($body),'GATEWAY_INTERFACE'=>'CGI/1.1',
			'DOCUMENT_ROOT'=>$project.'/public','QUERY_STRING'=>$query,'REDIRECT_STATUS'=>'200',
			'REMOTE_ADDR'=>'127.0.0.1','REMOTE_PORT'=>'41000','REQUEST_METHOD'=>$method,
			'REQUEST_URI'=>$target,'SCRIPT_FILENAME'=>$router,'SCRIPT_NAME'=>$path,
			'SERVER_ADDR'=>'127.0.0.1','SERVER_NAME'=>'runtime.test','SERVER_PORT'=>(string)$port,
			'SERVER_PROTOCOL'=>'HTTP/1.1','SERVER_SOFTWARE'=>'Dataphyre-Cloud',
			'DATAPHYRE_TEST_COVERAGE_PART'=>$part,
			'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
			'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,'XDEBUG_MODE'=>'coverage',
		];
		if($body!=='') $public['CONTENT_TYPE']='application/json';
		if($scanDirectory!=='') $public['PHP_INI_SCAN_DIR']=$scanDirectory;
		ksort($public,SORT_STRING);
		$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('scheduler',$project,$privateKey);
		try{
			$child=DataphyreApplicationRuntimeProcessBroker::spawn([
				'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
				'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
				'/usr/local/bin/php-cgi','-d','display_errors=0','-d','log_errors=1','-d','expose_php=0',
				'-d','cgi.force_redirect=0','-d','cgi.discard_path=0','-d','user_ini.filename=',
				'-d','auto_prepend_file='.$prepend,'-d','auto_append_file=','-f',$router,
			],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$project,$public,'scheduler',$values,10000,$managed,$body);
			$stdout=(string)stream_get_contents($child['pipes'][1]);$stderr=(string)stream_get_contents($child['pipes'][2]);
			fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
		}finally{sodium_memzero($managed['private_key']);}
		$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
		if(!is_array($decoded)) throw new RuntimeException('Scheduler router CGI did not return exact coverage: '.$stderr);
		\Dataphyre\Test\CoverageParts::add($decoded);
		return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
	};
	$encode=static fn(array $candidate): string=>json_encode(
		$candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR,
	);
	$issue=static function(string $kind,int $counter,?string $name=null,?string $definitionSha=null) use (
		$identity,$generation,$secretKey
	): array {
		return DataphyreApplicationRuntimeSchedulerProtocol::issue(
			$kind,$identity,$generation,$counter,$secretKey,$name,$definitionSha,$kind==='callback' ? 5000 : null,
		);
	};

	$registration=$run(
		'POST','/dataphyre/runtime/scheduler/register',$encode($issue('registration',1)),
		$applicationEnvironment,
	);
	$t->same(0,$registration['exit'],$registration['stderr']);
	[, $registrationBody]=array_pad(explode("\r\n\r\n",$registration['stdout'],2),2,'');
	$report=json_decode($registrationBody,true,32,JSON_THROW_ON_ERROR);
	$t->same(true,$report['ok']);$t->same(1,$report['definition_count']);
	$definition=$report['definitions'][0];
	$definitionSha='sha256:'.hash('sha256',json_encode($definition,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

	$callback=$run(
		'POST','/dataphyre/runtime/scheduler/callback',
		$encode($issue('callback',2,$definition['name'],$definitionSha)),$applicationEnvironment,
	);
	$t->same(0,$callback['exit'],$callback['stderr']);
	$t->isTrue(filesize($heartbeat)>0,'the signed callback executed its exact task');
	$noop=$run(
		'POST','/dataphyre/runtime/scheduler/noop',$encode($issue('noop',3)),$applicationEnvironment,
	);
	$t->same(0,$noop['exit'],$noop['stderr']);

	$invalid=$issue('noop',4);$invalid['signature']=str_repeat('A',86);
	$rejected=$run(
		'POST','/dataphyre/runtime/scheduler/noop',$encode($invalid),$applicationEnvironment,
	);
	$t->same(0,$rejected['exit'],$rejected['stderr']);
	$t->contains('Status: 404',$rejected['stdout']);
	$invalidKeyEnvironment=$applicationEnvironment;
	$invalidKeyEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY']='%%%';
	$invalidKey=$run(
		'POST','/dataphyre/runtime/scheduler/noop',$encode($issue('noop',5)),$invalidKeyEnvironment,
	);
	$t->same(0,$invalidKey['exit'],$invalidKey['stderr']);
	$t->contains('Status: 404',$invalidKey['stdout']);
	$wrongMethod=$run('GET','/dataphyre/runtime/scheduler/noop','',$applicationEnvironment);
	$t->same(0,$wrongMethod['exit'],$wrongMethod['stderr']);
	$t->contains('Status: 404',$wrongMethod['stdout']);
	sodium_memzero($secretKey);sodium_memzero($privateKey);
})->tag('cgi','router','scheduler','signed','callback','coverage-carrying')->maxMillis(30000)
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv','/usr/local/bin/php-cgi']),
		'Requires the canonical root test image with environment_fd 1.2, setpriv, and matching PHP CGI.',
	);

test('CGI gateway helpers enforce signed scheduler claims framing budgets and trusted responses',static function(Context $t): void {
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	$kernel=dirname(__DIR__).'/kernel';
	require_once $kernel.'/application_runtime_cgi_gateway.php';
	$internals=$t->nonPublic(DataphyreApplicationRuntimeCgiGateway::class);
	$readRequest=static function(string $wire) use ($internals): array {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		fwrite($pair[1],$wire);stream_socket_shutdown($pair[1],STREAM_SHUT_WR);
		try{return $internals->invoke('readRequest',$pair[0]);}
		finally{fclose($pair[0]);fclose($pair[1]);}
	};
	[$contentRequest,$contentBody]=$readRequest(
		"POST /submit?mode=exact HTTP/1.1\r\nHost: example.test\r\nContent-Type: text/plain\r\n".
		"Content-Length: 5\r\nX-Public: yes\r\nConnection: close\r\n\r\nhello",
	);
	$t->same('hello',$contentBody);
	$t->same('POST',$contentRequest['method']);
	[$chunkRequest,$chunkBody]=$readRequest(
		"POST /chunk HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\n\r\n".
		"5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n",
	);
	$t->same('hello world',$chunkBody);$t->same('/chunk',$chunkRequest['target']);
	$t->same(null,$internals->invoke('decodeChunked',''));
	$readFragmented=static function(array $parts) use ($internals): array {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
		if($pid===-1){fclose($pair[0]);fclose($pair[1]);throw new RuntimeException('Fragmented CGI request writer could not fork.');}
		if($pid===0){
			fclose($pair[0]);
			foreach($parts as $part){fwrite($pair[1],$part);fflush($pair[1]);usleep(10000);}
			fclose($pair[1]);exit(0);
		}
		fclose($pair[1]);
		try{return $internals->invoke('readRequest',$pair[0]);}
		finally{fclose($pair[0]);pcntl_waitpid($pid,$status);}
	};
	[, $fragmentedBody]=$readFragmented([
		"POST /fragment HTTP/1.1\r\nHost: example.test\r\nContent-Length: 5\r\n\r\nhe",'llo',
	]);
	$t->same('hello',$fragmentedBody);
	[, $fragmentedChunkBody]=$readFragmented([
		"POST /fragment HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhe", "llo\r\n0\r\n\r\n",
	]);
	$t->same('hello',$fragmentedChunkBody);
	foreach([
		"POST / HTTP/1.1\r\nHost: example.test\r\nContent-Length: 2\r\n\r\nabc",
		"POST / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: gzip\r\n\r\n",
		"POST / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\n\r\nz\r\n",
		"POST / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\n\r\n1\r\naX\r\n0\r\n\r\n",
	] as $invalidWire){
		$t->throws(static fn()=>$readRequest($invalidWire),RuntimeException::class);
	}

	$environment=$internals->invoke(
		'cgiEnvironment',$contentRequest,$contentBody,'[::1]:12345','127.0.0.1',8083,__FILE__,dirname(__DIR__,4),
	);
	$t->same('::1',$environment['REMOTE_ADDR']);$t->same('12345',$environment['REMOTE_PORT']);
	$t->same('text/plain',$environment['CONTENT_TYPE']);$t->same('yes',$environment['HTTP_X_PUBLIC']);
	$t->same('mode=exact',$environment['QUERY_STRING']);
	$t->same(false,array_key_exists('HTTP_CONNECTION',$environment));
	$router=(string)realpath(__DIR__.'/fixtures/application_runtime_cgi_probe.php');
	$project=(string)realpath(dirname(__DIR__,4));
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('scheduler',$project,random_bytes(32));
	$schedulerServe=static function(string $peer) use ($internals,$router,$project,$managed): string {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		fwrite($pair[1],"GET /not-a-scheduler-claim HTTP/1.1\r\nHost: 127.0.0.1:8081\r\nConnection: close\r\n\r\n");
		stream_socket_shutdown($pair[1],STREAM_SHUT_WR);
		$internals->invoke(
			'serve',$pair[0],$peer,'scheduler','127.0.0.1',8081,$router,$project,[], $managed,
		);
		stream_socket_shutdown($pair[0],STREAM_SHUT_WR);$response=(string)stream_get_contents($pair[1]);
		fclose($pair[0]);fclose($pair[1]);return $response;
	};
	$t->matches('/^HTTP\/1\.1 404\b/D',$schedulerServe('192.0.2.10:12345'));
	$t->matches('/^HTTP\/1\.1 404\b/D',$schedulerServe('127.0.0.1:12345'));

	$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);
	$public=sodium_crypto_sign_publickey($keypair);
	$identity=[
		'cloud_application'=>'serve','framework_application'=>'Serve','environment'=>'staging_blue',
		'release_id'=>'dep_'.str_repeat('a',40),
	];
	$publicEnvironment=['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'=>sodium_bin2base64(
		$public,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
	)];
	foreach([
		'registration'=>DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'registration',$identity,'gen_'.str_repeat('b',32),1,$secret,timestamp:time()-100,nonce:str_repeat('c',32),
		),
		'noop'=>DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'noop',$identity,'gen_'.str_repeat('b',32),2,$secret,timestamp:time()-100,nonce:str_repeat('d',32),
		),
		'callback'=>DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'callback',$identity,'gen_'.str_repeat('b',32),3,$secret,'serve.task',
			'sha256:'.str_repeat('e',64),12345,time()-100,str_repeat('f',32),
		),
	] as $kind=>$candidate){
		$body=json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		$endpoint=$kind==='registration' ? 'register' : $kind;
		$request=['method'=>'POST','target'=>'/dataphyre/runtime/scheduler/'.$endpoint,'protocol'=>'HTTP/1.1','headers'=>[]];
		$t->same(null,$internals->invoke('claimSchedulerRequest',$request,$body,$publicEnvironment),$kind);
		$timeout=$internals->invoke('childTimeoutMilliseconds','scheduler',$request,$body);
		$t->same(match($kind){'registration'=>12000,'noop'=>7000,default=>14345},$timeout,$kind);
	}
	$t->same(null,$internals->invoke(
		'claimSchedulerRequest',['method'=>'GET','target'=>'/'], '',$publicEnvironment,
	));
	$t->same(null,$internals->invoke(
		'claimSchedulerRequest',['method'=>'POST','target'=>'/unknown'], '{}',$publicEnvironment,
	));
	$t->same(null,$internals->invoke(
		'claimSchedulerRequest',['method'=>'POST','target'=>'/dataphyre/runtime/scheduler/noop'], '{',$publicEnvironment,
	));
	$t->same(null,$internals->invoke(
		'claimSchedulerRequest',['method'=>'POST','target'=>'/dataphyre/runtime/scheduler/noop'],
		json_encode(DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'noop',$identity,'gen_'.str_repeat('b',32),4,$secret,timestamp:time(),nonce:str_repeat('1',32),
		),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
		['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'=>'invalid'],
	));
	$startClaimServer=static function(): int {
		$listener=stream_socket_server('tcp://127.0.0.1:8082',$errorNumber,$error);
		if(!is_resource($listener)) throw new RuntimeException("Scheduler claim fixture could not listen: {$errorNumber} {$error}");
		$pid=pcntl_fork();
		if($pid===-1){fclose($listener);throw new RuntimeException('Scheduler claim fixture could not fork.');}
		if($pid===0){
			$connection=stream_socket_accept($listener,5);
			if(!is_resource($connection)){fclose($listener);exit(2);}
			stream_set_timeout($connection,5,0);$wire='';
			do{$chunk=fread($connection,16384);if(!is_string($chunk) || $chunk==='') break;$wire.=$chunk;}
			while(!str_contains($wire,"\r\n\r\n"));
			[$head,$body]=array_pad(explode("\r\n\r\n",$wire,2),2,'');
			preg_match('/\r\nContent-Length:\s*([0-9]+)\r\n/i',"\r\n{$head}\r\n",$lengthMatch);
			$length=(int)($lengthMatch[1] ?? 0);
			while(strlen($body)<$length){
				$chunk=fread($connection,$length-strlen($body));
				if(!is_string($chunk) || $chunk==='') break;$body.=$chunk;
			}
			$response='{"ok":true}';
			fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n{$response}");
			fclose($connection);fclose($listener);exit(strlen($body)===$length ? 0 : 3);
		}
		fclose($listener);return $pid;
	};
	$claimServerPid=$startClaimServer();
	$acceptedCandidate=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'noop',$identity,'gen_'.str_repeat('b',32),6,$secret,timestamp:time(),nonce:str_repeat('3',32),
	);
	$acceptedBody=json_encode($acceptedCandidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$t->same('noop',$internals->invoke(
		'claimSchedulerRequest',
		['method'=>'POST','target'=>'/dataphyre/runtime/scheduler/noop','protocol'=>'HTTP/1.1','headers'=>[]],
		$acceptedBody,$publicEnvironment,
	));
	pcntl_waitpid($claimServerPid,$claimStatus);
	$t->same(0,pcntl_wexitstatus($claimStatus));
	$unavailableCandidate=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'noop',$identity,'gen_'.str_repeat('b',32),7,$secret,timestamp:time(),nonce:str_repeat('4',32),
	);
	$unavailableBody=json_encode($unavailableCandidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$t->same(null,$internals->invoke(
		'claimSchedulerRequest',
		['method'=>'POST','target'=>'/dataphyre/runtime/scheduler/noop','protocol'=>'HTTP/1.1','headers'=>[]],
		$unavailableBody,$publicEnvironment,
	));

	// Spawning the capability-free CGI child is deliberately root-broker-only.
	// The canonical root/Xdebug lane exercises the response bound; ordinary
	// unprivileged framework runs retain the pure framing/claim coverage above.
	if(dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv','/usr/local/bin/php-cgi'])){
		$oversizedCandidate=DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'noop',$identity,'gen_'.str_repeat('b',32),8,$secret,timestamp:time(),nonce:str_repeat('5',32),
		);
		$oversizedBody=json_encode($oversizedCandidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		$oversizedClaimPid=$startClaimServer();$oversizedPair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		$oversizedWire="POST /dataphyre/runtime/scheduler/noop HTTP/1.1\r\nHost: 127.0.0.1:8081\r\nContent-Type: application/json\r\n".
			'Content-Length: '.strlen($oversizedBody)."\r\nConnection: close\r\n\r\n{$oversizedBody}";
		fwrite($oversizedPair[1],$oversizedWire);stream_socket_shutdown($oversizedPair[1],STREAM_SHUT_WR);
		$oversizedError=null;
		try{$internals->invoke(
			'serve',$oversizedPair[0],'127.0.0.1:41000','scheduler','127.0.0.1',8081,$router,$project,
			$publicEnvironment+[
				'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
				'DATAPHYRE_RUNTIME_TEST_CGI_OUTPUT_BYTES'=>'70000',
			],$managed,
		);}catch(Throwable $failure){$oversizedError=$failure;}
		fclose($oversizedPair[0]);fclose($oversizedPair[1]);pcntl_waitpid($oversizedClaimPid,$oversizedClaimStatus);
		$t->contains('response exceeded its bound',$oversizedError?->getMessage() ?? '');
		$t->same(0,pcntl_wexitstatus($oversizedClaimStatus));
	}
	$t->same(2000,$internals->invoke(
		'childTimeoutMilliseconds','scheduler',['target'=>'/unknown'],'{',
	));
	$unknownCandidate=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'noop',$identity,'gen_'.str_repeat('b',32),5,$secret,timestamp:time()-100,nonce:str_repeat('2',32),
	);
	$t->same(2000,$internals->invoke(
		'childTimeoutMilliseconds','scheduler',['target'=>'/unknown'],
		json_encode($unknownCandidate,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
	));
	$t->same(300000,$internals->invoke(
		'childTimeoutMilliseconds','web',['target'=>'/'],'',
	));

	$completed=static function(?string $kind,string $output,bool $headOnly) use ($internals): string {
		$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		$internals->invoke('writeCompletedResponse',$pair[0],$kind,$output,$headOnly);
		stream_socket_shutdown($pair[0],STREAM_SHUT_WR);$response=(string)stream_get_contents($pair[1]);
		fclose($pair[0]);fclose($pair[1]);return $response;
	};
	$t->contains('dataphyre.scheduler_callback.v1',$completed('callback','tenant-forgery',false));
	$noopHead=$completed('noop','tenant-forgery',true);
	$t->contains('Content-Length: 0',$noopHead);$t->notContains('dataphyre.scheduler_noop.v1',$noopHead);
	$cgi=$completed(null,"Status: 201 Created\r\nContent-Type: text/plain\r\nConnection: forged\r\n\r\ncreated",false);
	$t->contains('HTTP/1.1 201 Created',$cgi);$t->contains('Content-Type: text/plain',$cgi);
	$t->notContains('Connection: forged',$cgi);$t->contains("\r\n\r\ncreated",$cgi);
	$headOnly=$completed(null,"Content-Type: text/plain\r\n\r\ncreated",true);
	$t->contains('Content-Length: 0',$headOnly);$t->notContains("\r\n\r\ncreated",$headOnly);
	foreach([
		"missing-separator",
		"Bad Header\r\n\r\nbody",
		"Status: 999 Invalid\r\n\r\nbody",
	] as $invalidResponse){
		$t->throws(static fn()=>$completed(null,$invalidResponse,false),RuntimeException::class);
	}

	$t->same(null,$internals->invoke('validateInvocation','web','127.0.0.1',8083,$router,$project));
	$t->same(null,$internals->invoke('validateInvocation','scheduler','127.0.0.1',8081,$router,$project));
	$t->throws(
		static fn()=>$internals->invoke('validateInvocation','web','0.0.0.0',8083,$router,$project),
		RuntimeException::class,
	);
	$t->same('peer-without-port',$internals->invoke('remoteAddress','peer-without-port'));
	$t->same('0',$internals->invoke('remotePort','peer-without-port'));
	$t->same(null,$internals->invoke('respond',null,503,'Unavailable'));
	$t->throws(static fn()=>$internals->invoke('writeAll',null,'x'),TypeError::class);
	dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);
})->tag('cgi','gateway','scheduler','framing','trusted-response','positive','negative');

test('covered one-shot dispatcher consumes its bound channel and selects only the fixed database target',static function(Context $t): void {
	$frameworkRoot=(string)realpath(dirname(__DIR__,4));
	$kernel=(string)realpath(dirname(__DIR__).'/kernel');
	$worker=$kernel.'/application_runtime_one_shot_worker.php';
	$target=$kernel.'/application_runtime_database_identity.php';
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$state=$t->workspace('core-process-entrypoint-covered-one-shot');
	$coveragePart=$state->file('one-shot-coverage.json','');
	if(!@chown($coveragePart,10001) || !@chgrp($coveragePart,10001) || !@chmod($coveragePart,0600)){
		throw new RuntimeException('One-shot coverage transport ownership could not be prepared.');
	}
	$publicEnvironment=[
		'DATAPHYRE_TEST_COVERAGE_PART'=>$coveragePart,
		'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
		'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
		'XDEBUG_MODE'=>'coverage',
	];
	$scanDirectory=(string)getenv('PHP_INI_SCAN_DIR');
	if($scanDirectory!=='') $publicEnvironment['PHP_INI_SCAN_DIR']=$scanDirectory;
	$process=DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,$coverageBootstrap,$worker,'database_identity',$target,'--purpose=primary',
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,$publicEnvironment,'one-shot',[
		'DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'=>'sha256:'.str_repeat('c',64),
		'DATAPHYRE_DATABASE_DSN'=>'sqlite::memory:','DATAPHYRE_DATABASE_USER'=>'fixture',
		'DATAPHYRE_DATABASE_PASSWORD'=>'fixture',
	]);
	$stdout=(string)stream_get_contents($process['pipes'][1]);
	$stderr=(string)stream_get_contents($process['pipes'][2]);
	fclose($process['pipes'][1]);fclose($process['pipes'][2]);
	$t->same(69,proc_close($process['resource']),$stderr);
	$t->same('',$stdout);
	$t->same('',$stderr);
	$part=is_file($coveragePart) ? json_decode((string)file_get_contents($coveragePart),true) : null;
	$t->isTrue(is_array($part),'the one-shot dispatcher returned an exact Xdebug coverage part');
	$t->same('xdebug',$part['engine'] ?? null);
	$t->greaterThan(10,$part['files']['runtime/modules/core/kernel/application_runtime_one_shot_worker.php']['covered'] ?? 0);
	\Dataphyre\Test\CoverageParts::add($part);
})->tag('one-shot','database','secret-broker','exact-image','coverage-carrying','positive')->maxMillis(15000)
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv']),
		'Requires the canonical root test image with environment_fd 1.2 and setpriv.',
	);

test('covered realtime pool performs application and framework WebSocket roundtrips through its inherited environment',static function(Context $t): void {
	$fixedPortLock=dataphyre_application_runtime_fixed_port_lock();
	$frameworkRoot=(string)realpath(dirname(__DIR__,4));
	$runtimeRoot=(string)realpath(dirname(__DIR__,3));
	$kernel=(string)realpath(dirname(__DIR__).'/kernel');
	$server=$kernel.'/application_runtime_realtime_server.php';
	$project=(string)realpath(__DIR__.'/fixtures/application_runtime_project');
	$coverageBootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
	$state=$t->workspace('core-process-entrypoint-covered-realtime');
	$stateRoot=$state->path('runtime-state');
	if(!mkdir($stateRoot,0700,true) || !chown($stateRoot,10001) || !chgrp($stateRoot,10001)){
		throw new RuntimeException('Realtime state ownership could not be prepared.');
	}
	$coveragePart=$state->file('realtime-coverage.json','');
	if(!chown($coveragePart,10001) || !chgrp($coveragePart,10001) || !chmod($coveragePart,0600)){
		throw new RuntimeException('Realtime coverage transport ownership could not be prepared.');
	}
	$publicEnvironment=[
		'DATAPHYRE_TEST_COVERAGE_PART'=>$coveragePart,
		'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
		'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
		'XDEBUG_MODE'=>'coverage',
	];
	$scanDirectory=(string)getenv('PHP_INI_SCAN_DIR');
	if($scanDirectory!=='') $publicEnvironment['PHP_INI_SCAN_DIR']=$scanDirectory;
	$token='realtime-token-'.bin2hex(random_bytes(16));
	$privateKey=random_bytes(32);
	$managed=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext('realtime',$project,$privateKey);
	$applicationEnvironment=[
		'DATAPHYRE_RUNTIME_REALTIME_HOST'=>'0.0.0.0',
		'DATAPHYRE_RUNTIME_REALTIME_PORT'=>'8080',
		'DATAPHYRE_RUNTIME_WEB_HOST'=>'127.0.0.1',
		'DATAPHYRE_RUNTIME_WEB_PORT'=>'8083',
		'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
		'DATAPHYRE_RUNTIME_APPLICATION'=>'_Runtime$Probe',
		'DATAPHYRE_RUNTIME_ENVIRONMENT'=>'staging_blue',
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$stateRoot,
		'DATAPHYRE_SCHEDULER_STATE_ROOT'=>$stateRoot,
		'DATAPHYRE_RUNTIME_TEST_REALTIME_TOKEN'=>$token,
	];
	$process=DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,$coverageBootstrap,$server,'realtime','0.0.0.0','8080',$project,
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,$publicEnvironment,'realtime',
		$applicationEnvironment,10000,$managed,
	);
	$failure=null;$stdout='';$stderr='';$buffers=[];
	try{
		$connect=static function() use (&$buffers) {
			$socket=null;$deadline=microtime(true)+10.0;$errorNumber=0;$error='';
			do{
				$socket=@stream_socket_client('tcp://127.0.0.1:8080',$errorNumber,$error,0.1);
				if(is_resource($socket)) break;
				usleep(10000);
			}while(microtime(true)<$deadline);
			if(!is_resource($socket)) throw new RuntimeException("Realtime server unavailable: {$errorNumber} {$error}");
			stream_set_timeout($socket,5,0);$buffers[(int)$socket]='';return $socket;
		};
		$writeAll=static function($socket,string $bytes): void {
			$offset=0;
			while($offset<strlen($bytes)){
				$written=fwrite($socket,substr($bytes,$offset));
				if(!is_int($written) || $written<1) throw new RuntimeException('Realtime client write failed.');
				$offset+=$written;
			}
		};
		$readBytes=static function($socket,int $length) use (&$buffers): string {
			$id=(int)$socket;$buffers[$id] ??='';
			while(strlen($buffers[$id])<$length){
				$chunk=fread($socket,max(1,$length-strlen($buffers[$id])));
				if(!is_string($chunk) || $chunk===''){
					$meta=stream_get_meta_data($socket);
					throw new RuntimeException('Realtime client read failed: '.json_encode($meta,JSON_THROW_ON_ERROR));
				}
				$buffers[$id].=$chunk;
			}
			$value=substr($buffers[$id],0,$length);$buffers[$id]=substr($buffers[$id],$length);return $value;
		};
		$readHead=static function($socket) use (&$buffers): string {
			$id=(int)$socket;$buffers[$id] ??='';$deadline=microtime(true)+5.0;
			while(($end=strpos($buffers[$id],"\r\n\r\n"))===false){
				$chunk=fread($socket,8192);
				if(is_string($chunk) && $chunk!==''){$buffers[$id].=$chunk;continue;}
				if(microtime(true)>=$deadline) throw new RuntimeException('Realtime HTTP response timed out.');
				usleep(1000);
			}
			$head=substr($buffers[$id],0,$end);$buffers[$id]=substr($buffers[$id],$end+4);return $head;
		};
		$maskedFrame=static function(int $opcode,string $payload): string {
			$length=strlen($payload);$mask=random_bytes(4);$masked=$payload;
			for($index=0;$index<$length;$index++) $masked[$index]=$masked[$index]^$mask[$index%4];
			if($length<=125) return chr(0x80|$opcode).chr(0x80|$length).$mask.$masked;
			if($length<=65535) return chr(0x80|$opcode).chr(0xfe).pack('n',$length).$mask.$masked;
			return chr(0x80|$opcode).chr(0xff).pack('N2',0,$length).$mask.$masked;
		};
		$readFrame=static function($socket) use ($readBytes): array {
			$head=$readBytes($socket,2);$first=ord($head[0]);$second=ord($head[1]);$length=$second&0x7f;
			if(($second&0x80)!==0) throw new RuntimeException('Realtime server frame was unexpectedly masked.');
			if($length===126) $length=unpack('nlength',$readBytes($socket,2))['length'];
			elseif($length===127){$parts=unpack('Nhigh/Nlow',$readBytes($socket,8));$length=(int)$parts['low'];}
			return ['fin'=>($first&0x80)!==0,'opcode'=>$first&0x0f,'payload'=>$readBytes($socket,$length)];
		};
		$handshake=static function(string $target,string $origin) use ($connect,$writeAll,$readHead): array {
			$socket=$connect();$key=base64_encode(random_bytes(16));
			$writeAll($socket,"GET {$target} HTTP/1.1\r\nHost: 127.0.0.1:8080\r\nUpgrade: websocket\r\n".
				"Connection: keep-alive, Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n".
				"Origin: {$origin}\r\n\r\n");
			return [$socket,$readHead($socket)];
		};

		[$probe,$probeHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$probeHead);
		$probeEvent=$readFrame($probe);
		$t->same(0x1,$probeEvent['opcode']);
		$probePayload=json_decode($probeEvent['payload'],true,16,JSON_THROW_ON_ERROR);
		$t->same('dataphyre.application_realtime_probe.v1',$probePayload['contract']);
		$t->same(true,$probePayload['framework_listener_roundtrip']);
		$t->same(true,$probePayload['application_authorization_rejections']);
		$writeAll($probe,$maskedFrame(0x9,'probe-ping'));
		$t->same(['fin'=>true,'opcode'=>0xa,'payload'=>'probe-ping'],$readFrame($probe));
		$writeAll($probe,$maskedFrame(0x8,pack('n',1000)));
		$t->same(0x8,$readFrame($probe)['opcode']);fclose($probe);

		[$application,$applicationHead]=$handshake('/runtime/realtime?token='.rawurlencode($token),'https://runtime.test');
		$t->matches('/^HTTP\/1\.1 101\b/D',$applicationHead);
		$applicationEvent=json_decode($readFrame($application)['payload'],true,16,JSON_THROW_ON_ERROR);
		$t->same(['type'=>'runtime.ready','pool'=>'realtime'],$applicationEvent);
		$writeAll($application,$maskedFrame(0x1,'unsupported-client-data'));
		$applicationClose=$readFrame($application);
		$t->same(0x8,$applicationClose['opcode']);
		$t->same(1003,unpack('ncode',$applicationClose['payload'])['code']);fclose($application);

		foreach([
			'throw'=>1011,
			'invalid'=>1011,
			'too_many'=>1011,
			'invalid_cursor'=>1011,
			'unencodable'=>1011,
			'oversized'=>1009,
		] as $mode=>$expectedCode){
			[$eventFailure,$eventFailureHead]=$handshake(
				'/runtime/realtime?token='.rawurlencode($token).'&mode='.$mode,'https://runtime.test',
			);
			$t->matches('/^HTTP\/1\.1 101\b/D',$eventFailureHead,$mode);
			$eventFailureClose=$readFrame($eventFailure);
			$t->same(0x8,$eventFailureClose['opcode'],$mode);
			$t->same($expectedCode,unpack('ncode',$eventFailureClose['payload'])['code'],$mode);
			fclose($eventFailure);
		}

		foreach([
			['/runtime/realtime?token=wrong','https://runtime.test',401],
			['/runtime/realtime?token='.rawurlencode($token).'&mode=authorize_throw','https://runtime.test',401],
			['/runtime/realtime?token='.rawurlencode($token).'&mode=authorize_unencodable','https://runtime.test',401],
			['/runtime/realtime?token='.rawurlencode($token).'&mode=authorize_oversized','https://runtime.test',401],
			['/runtime/realtime?token='.rawurlencode($token).'&mode=authorize_list','https://runtime.test',401],
			['/missing-realtime-route','https://runtime.test',404],
			['/runtime/realtime?token='.rawurlencode($token).'&token=duplicate','https://runtime.test',400],
			['/runtime/realtime?token='.rawurlencode($token),'ftp://runtime.test',400],
		] as [$target,$origin,$status]){
			[$rejected,$head]=$handshake($target,$origin);
			$t->matches('/^HTTP\/1\.1 '.$status.'\b/D',$head);fclose($rejected);
		}

		$malformed=$connect();
		$writeAll($malformed,"GET /runtime/realtime HTTP/1.1\r\nHost: 127.0.0.1:8080\r\nBad Header\r\n\r\n");
		$t->matches('/^HTTP\/1\.1 400\b/D',$readHead($malformed));fclose($malformed);
		$tooLarge=$connect();
		$writeAll($tooLarge,"GET /runtime/realtime HTTP/1.1\r\nHost: 127.0.0.1:8080\r\nX-Large: ".str_repeat('x',17000));
		$t->matches('/^HTTP\/1\.1 431\b/D',$readHead($tooLarge));fclose($tooLarge);
		$closedWithoutHeaders=$connect();fclose($closedWithoutHeaders);usleep(50000);
		$backendCheck=@stream_socket_client('tcp://127.0.0.1:8083',$backendErrorNumber,$backendError,0.05);
		if(is_resource($backendCheck)){
			fclose($backendCheck);
		}else{
			$ordinary=$connect();
			$writeAll($ordinary,"GET /health HTTP/1.1\r\nHost: public.example\r\nConnection: keep-alive, x-remove\r\n".
				"X-Remove: private\r\nX-Dataphyre-Runtime-Forged: denied\r\nX-Public: kept\r\n\r\n");
			$t->matches('/^HTTP\/1\.1 503\b/D',$readHead($ordinary));fclose($ordinary);
		}

		[$unmasked,$unmaskedHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$unmaskedHead);$readFrame($unmasked);
		$writeAll($unmasked,chr(0x89).chr(1).'x');
		$unmaskedClose=$readFrame($unmasked);
		$t->same(1002,unpack('ncode',$unmaskedClose['payload'])['code']);fclose($unmasked);

		[$oversized,$oversizedHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$oversizedHead);$readFrame($oversized);
		$writeAll($oversized,chr(0x82).chr(0xff).pack('N2',1,0).random_bytes(4));
		$oversizedClose=$readFrame($oversized);
		$t->same(1009,unpack('ncode',$oversizedClose['payload'])['code']);fclose($oversized);

		[$singleByteClose,$singleByteCloseHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$singleByteCloseHead);$readFrame($singleByteClose);
		$writeAll($singleByteClose,$maskedFrame(0x8,'x'));
		$t->same(1002,unpack('ncode',$readFrame($singleByteClose)['payload'])['code']);fclose($singleByteClose);

		[$mediumFrame,$mediumFrameHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$mediumFrameHead);$readFrame($mediumFrame);
		$writeAll($mediumFrame,$maskedFrame(0x2,str_repeat('m',126)));
		$t->same(1003,unpack('ncode',$readFrame($mediumFrame)['payload'])['code']);fclose($mediumFrame);

		[$largeFrame,$largeFrameHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$largeFrameHead);$readFrame($largeFrame);
		$writeAll($largeFrame,$maskedFrame(0x2,str_repeat('l',65536)));
		$t->same(1003,unpack('ncode',$readFrame($largeFrame)['payload'])['code']);fclose($largeFrame);

		[$pong,$pongHead]=$handshake('/dataphyre/runtime/realtime/probe','https://dataphyre.invalid');
		$t->matches('/^HTTP\/1\.1 101\b/D',$pongHead);$readFrame($pong);
		$writeAll($pong,$maskedFrame(0xa,'server-ping-receipt'));
		$writeAll($pong,$maskedFrame(0x8,pack('n',1000)));
		$t->same(0x8,$readFrame($pong)['opcode']);fclose($pong);

		$lingering=$connect();
	}catch(Throwable $caught){$failure=$caught;
	}finally{
		@posix_kill($process['pid'],SIGTERM);$deadline=microtime(true)+10.0;
		do{
			$status=proc_get_status($process['resource']);
			if(!is_array($status) || ($status['running'] ?? false)!==true) break;
			usleep(10000);
		}while(microtime(true)<$deadline);
		$status=proc_get_status($process['resource']);
		if(is_array($status) && ($status['running'] ?? false)===true) @posix_kill($process['pid'],SIGKILL);
		foreach($process['pipes'] as $pipe) if(is_resource($pipe)) stream_set_blocking($pipe,false);
		$stdout=is_resource($process['pipes'][1] ?? null) ? (string)stream_get_contents($process['pipes'][1]) : '';
		$stderr=is_resource($process['pipes'][2] ?? null) ? (string)stream_get_contents($process['pipes'][2]) : '';
		foreach($process['pipes'] as $pipe) if(is_resource($pipe)) fclose($pipe);
		proc_close($process['resource']);
		if(isset($lingering) && is_resource($lingering)) fclose($lingering);
		sodium_memzero($privateKey);sodium_memzero($managed['private_key']);
		dataphyre_application_runtime_fixed_port_unlock($fixedPortLock);
	}
	if($failure!==null) throw new RuntimeException($failure->getMessage().' stdout='.$stdout.' stderr='.$stderr,0,$failure);
	$t->same('',$stdout);$t->same('',$stderr);
	$part=is_file($coveragePart) ? json_decode((string)file_get_contents($coveragePart),true) : null;
	$t->isTrue(is_array($part),'the realtime server returned an exact Xdebug coverage part');
	$t->same('xdebug',$part['engine'] ?? null);
	$t->greaterThan(200,$part['files']['runtime/modules/core/kernel/application_runtime_realtime_server.php']['covered'] ?? 0);
	\Dataphyre\Test\CoverageParts::add($part);
})->tag('realtime','websocket','secret-broker','exact-image','coverage-carrying','positive','negative')->maxMillis(45000)
	->skipUnless(
		dataphyre_process_entrypoints_exact_native_runtime(['/usr/bin/setpriv']),
		'Requires the canonical root test image with environment_fd 1.2 and setpriv.',
	);
