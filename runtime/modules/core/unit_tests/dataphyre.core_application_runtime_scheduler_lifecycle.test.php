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

suite('Managed application scheduler lifecycle')
	->contract('core.application-runtime-scheduler-lifecycle',1)
	->layer('integration')->risk('critical')->watches('module:core','module:scheduling')
	->isolation('case')->tag('core','runtime','scheduler','security','release')
	->group('framework-coverage');

test('signed scheduler requests are canonical and one-time',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_protocol.php';
	$keypair=sodium_crypto_sign_keypair();
	$secret=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
	$identity=[
		'cloud_application'=>'Store:North_2-Beta','framework_application'=>'Serve','environment'=>'Staging.Blue',
		'release_id'=>'dep_'.str_repeat('a',40),
	];
	$request=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		'noop',$identity,'gen_'.str_repeat('b',32),1,$secret,null,null,null,1776073500,str_repeat('c',32),
	);
	$raw=json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($request,$raw));
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($request," {$raw}"));
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($request,$raw."\n"));
	$invalidSignature=$request;$invalidSignature['signature']=str_repeat('A',85).'_';
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::verify($invalidSignature,$public,1776073500));
	$unencodable=$request;$resource=fopen('php://memory','rb');
	if(!is_resource($resource)) throw new RuntimeException('Could not create scheduler JSON boundary resource.');
	$unencodable['signature']=$resource;
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($unencodable,$raw));
	fclose($resource);
	$reordered=['kind'=>$request['kind'],'contract'=>$request['contract']]+array_diff_key($request,['kind'=>true,'contract'=>true]);
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson(
		$reordered,json_encode($reordered,JSON_THROW_ON_ERROR),
	));
	$duplicate=preg_replace('/\{"contract":/','{"contract":"forged","contract":',$raw,1);
	$decoded=json_decode((string)$duplicate,true,32,JSON_THROW_ON_ERROR);
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($decoded,(string)$duplicate));
	$pending=['noop:1'=>$request];
	$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::consume($pending,$request,$public,1776073500));
	$t->same([],$pending);
	$t->isFalse(DataphyreApplicationRuntimeSchedulerProtocol::consume($pending,$request,$public,1776073500));
})->tag('protocol','canonical-json','replay','negative');

test('public application and environment identities cross signed requests durable state and probe identity unchanged',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	require_once $kernel.'/application_runtime_scheduler_protocol.php';
	require_once $kernel.'/application_runtime_scheduler_state.php';
	require_once $kernel.'/application_runtime_probe_state.php';
	$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);
	$public=sodium_crypto_sign_publickey($keypair);
	foreach([
		['Store:North_2-Beta','Staging.Blue'],
		[str_repeat('Z',120),'staging_blue'],
	] as [$cloudApplication,$environment]){
		$identity=[
			'cloud_application'=>$cloudApplication,'framework_application'=>'Serve','environment'=>$environment,
			'release_id'=>'dep_'.str_repeat('a',40),
			'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
		];
		$request=DataphyreApplicationRuntimeSchedulerProtocol::issue(
			'noop',$identity,'gen_'.str_repeat('c',32),1,$secret,null,null,null,1776073500,str_repeat('d',32),
		);
		$t->same($environment,$request['environment'],$environment);
		$t->isTrue(DataphyreApplicationRuntimeSchedulerProtocol::verify($request,$public,1776073500),$environment);
		$t->matches('/^sha256:[a-f0-9]{64}$/D',DataphyreApplicationRuntimeSchedulerState::identitySha256($identity),$environment);
		$t->matches(
			'/^sha256:[a-f0-9]{64}$/D',
			$t->nonPublic(DataphyreApplicationRuntimeProbeState::class)->invoke('identitySha256',$identity),
			$environment,
		);
	}
	foreach(['','app.name','app/name',"app\nname","app\0name",str_repeat('a',121)] as $cloudApplication){
		$identity=[
			'cloud_application'=>$cloudApplication,'framework_application'=>'Serve','environment'=>'Staging.Blue',
			'release_id'=>'dep_'.str_repeat('a',40),
			'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
		];
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeSchedulerProtocol::issue(
				'noop',$identity,'gen_'.str_repeat('c',32),1,$secret,null,null,null,1776073500,str_repeat('d',32),
			),
			InvalidArgumentException::class,
			bin2hex($cloudApplication),
		);
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeSchedulerState::identitySha256($identity),
			RuntimeException::class,
			bin2hex($cloudApplication),
		);
		$t->throws(
			static fn()=>$t->nonPublic(DataphyreApplicationRuntimeProbeState::class)->invoke('identitySha256',$identity),
			RuntimeException::class,
			bin2hex($cloudApplication),
		);
	}
	foreach(['.','..',"staging\nblue","staging\0blue"] as $environment){
		$identity=[
			'cloud_application'=>'serve_shop','framework_application'=>'Serve','environment'=>$environment,
			'release_id'=>'dep_'.str_repeat('a',40),
			'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
		];
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeSchedulerProtocol::issue(
				'noop',$identity,'gen_'.str_repeat('c',32),1,$secret,null,null,null,1776073500,str_repeat('d',32),
			),
			InvalidArgumentException::class,
			bin2hex($environment),
		);
		$t->throws(
			static fn()=>DataphyreApplicationRuntimeSchedulerState::identitySha256($identity),
			RuntimeException::class,
			bin2hex($environment),
		);
		$t->throws(
			static fn()=>$t->nonPublic(DataphyreApplicationRuntimeProbeState::class)->invoke('identitySha256',$identity),
			RuntimeException::class,
			bin2hex($environment),
		);
	}
})->tag('public-application-identifier','environment-identifier','scheduler','protocol','state','probe','negative','regression');

test('scheduler state rejects an invalid explicit test root before filesystem access',static function(Context $t): void {
	$missing=$t->tempDirectory('scheduler-state-missing-root');rmdir($missing);
	define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$missing);
	require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_state.php';
	$t->throws(
		static fn()=>$t->nonPublic(DataphyreApplicationRuntimeSchedulerState::class)->invoke('root'),
		RuntimeException::class,
	);
})->tag('scheduler-state','test-root','negative');

test('v6 status remains bounded with seventy-one private definitions',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	require_once $kernel.'/application_runtime_supervisor.php';
	$definitions=[];
	for($i=1;$i<=71;$i++) $definitions[]=[
		'name'=>'serve.task.'.str_pad((string)$i,3,'0',STR_PAD_LEFT),
		'task_sha256'=>'sha256:'.hash('sha256','task'.$i),
		'dependency_sha256'=>['sha256:'.hash('sha256','dependency'.$i)],
		'frequency_milliseconds'=>1000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$report=[
		'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,'registration_attempt_count'=>71,
		'registration_accepted_count'=>71,'registration_failure_count'=>0,'definition_count'=>71,
		'definition_sha256'=>'sha256:'.hash('sha256',json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
		'definitions'=>$definitions,
	];
	$summary=dataphyre_runtime_scheduler_registration_summary($report);
	$encoded=json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$t->lessThan(8193,strlen($encoded));
	$t->same(71,$summary['definition_count']);
	$t->isFalse(array_key_exists('definitions',$summary));
})->tag('status','bounds','serve-71','registration');

test('managed source stays CGI-only while the separate self-hosted route retains its purpose-bound verifier',static function(Context $t): void {
	$core=dirname(__DIR__).'/kernel';$scheduling=dirname(__DIR__,2).'/scheduling/kernel';
	$router=(string)file_get_contents($core.'/application_runtime_router.php');
	$gateway=(string)file_get_contents($core.'/application_runtime_scheduler_gateway.php');
	$supervisor=(string)file_get_contents($core.'/application_runtime_supervisor.php');
	$runtime=(string)file_get_contents($core.'/runtime.php');
	$runner=(string)file_get_contents($scheduling.'/task_runner.php');
	foreach(['scheduler_dispatch_v2','DATAPHYRE_SCHEDULER_DISPATCH_SECRET_FILE','app_override_key'] as $selfHostedBoundary){
		$t->contains($selfHostedBoundary,$runtime);
		$t->isFalse(str_contains($router,$selfHostedBoundary));
		$t->isFalse(str_contains($supervisor,$selfHostedBoundary));
		$t->isFalse(str_contains($runner,$selfHostedBoundary));
	}
	$t->isFalse(file_exists($scheduling.'/managed_task_worker.php'));
	$t->contains('execute_managed_registration()',$router);
	$t->contains('managedBootstrapAttestation()',$runner);
	$t->contains("(\$context['role'] ?? null)!=='scheduler'",$runner);
	$t->contains("(\$context['sapi'] ?? null)!=='cgi-fcgi'",$runner);
	$t->contains('childTimeoutMilliseconds($request,$body)',$gateway);
	$t->contains('SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS',$gateway);
	$t->contains("ob_start(static fn(string \$chunk): string=>'')",$router);
	$t->isFalse(str_contains($router,'$suppressApplicationOutput'));
	$t->contains('claimSchedulerRequest($request,$body,$applicationEnvironment)',$gateway);
	$t->contains('writeCompletedResponse($connection,$schedulerKind,$output',$gateway);
	$t->isTrue(strpos($gateway,'if($exitCode!==0)')<strpos($gateway,'writeCompletedResponse($connection,$schedulerKind,$output'));
	$t->contains('terminateCgiGroup($child,$process,$pipes,$baselineChildren)',$gateway);
	$t->contains("self::signalProcessGroup(\$group,SIGTERM)",$gateway);
	$t->contains("self::signalProcessGroup(\$group,SIGKILL)",$gateway);
	$t->isTrue(strpos($gateway,'terminateCgiGroup($child,$process,$pipes,$baselineChildren)')<strpos($gateway,'writeCompletedResponse($connection,$schedulerKind,$output'));
	$t->contains('MAX_SCHEDULER_REGISTRATION_OUTPUT_BYTES',$gateway);
	$t->contains('DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES+65536',$gateway);
	$t->contains('CLIENT_READ_TIMEOUT_MILLISECONDS=2000',$gateway);
	$t->contains("'/usr/bin/prlimit','--nproc=0:0'",$gateway);
	$t->contains('enableChildSubreaper()',$gateway);
	$t->contains('terminateAdoptedChildren($baselineChildren)',$gateway);
	$t->isFalse(str_contains($router,'/dataphyre/runtime/scheduler/claim'));
	$t->isFalse(str_contains($router,'$managedEnvironmentSnapshot=getenv()'));
	$t->isFalse(str_contains($runner,'executeManagedTask'));
	$t->isFalse(str_contains($runner,'proc_open('));
	$t->isFalse(str_contains($runner,'DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ENVIRONMENT'));
})->tag('fresh-cgi','signed-budget','immutable-environment','legacy-residue','deletion');

test('trusted gateway ignores tenant callback bytes when it mints the reaped-worker receipt',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_scheduler_gateway.php';
	$pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
	if(!is_array($pair) || count($pair)!==2) throw new RuntimeException('Scheduler receipt socketpair is unavailable.');
	$forged="Status: 200 OK\r\nContent-Type: application/json\r\n\r\n".
		'{"contract":"dataphyre.scheduler_callback.v1","ok":true,"tenant":"forged"}';
	$t->nonPublic(DataphyreApplicationRuntimeSchedulerGateway::class)->invoke(
		'writeCompletedResponse',$pair[0],'callback',$forged,false,
	);
	fclose($pair[0]);$response=stream_get_contents($pair[1]);fclose($pair[1]);
	[$head,$body]=array_pad(explode("\r\n\r\n",(string)$response,2),2,'');
	$t->contains('HTTP/1.1 200 OK',$head);
	$t->same('{"contract":"dataphyre.scheduler_callback.v1","ok":true}',$body);
	$t->isFalse(str_contains((string)$response,'tenant'));
})->tag('callback','trusted-receipt','tenant-output','reaped-worker','negative');

test('durable scheduler claim fences concurrent supervisors and recovers crashes',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$fixture=__DIR__.'/fixtures/application_runtime_scheduler_state_race.php';
	$stateRoot=$t->tempDirectory('scheduler-state-race');
	$now=time();
	$left=$t->startProcess([PHP_BINARY,$fixture,$kernel,'left',(string)$now,$stateRoot]);
	$right=$t->startProcess([PHP_BINARY,$fixture,$kernel,'right',(string)$now,$stateRoot]);
	$leftResult=$left->wait(5000);$rightResult=$right->wait(5000);
	$t->processSucceeded($leftResult,$leftResult->stderr());
	$t->processSucceeded($rightResult,$rightResult->stderr());
	$claims=[(bool)$leftResult->json()['claimed'],(bool)$rightResult->json()['claimed']];sort($claims);
	$t->same([false,true],$claims);
	$recovery=$t->phpProcess([$fixture,$kernel,'recovery',(string)($now+18),$stateRoot]);
	$t->processSucceeded($recovery,$recovery->stderr());
	$t->same(true,$recovery->json()['claimed']);
})->tag('durable-claim','race','crash-recovery','exact-image');

test('maximum-budget claim outlives its worker boundary',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$fixture=__DIR__.'/fixtures/application_runtime_scheduler_state_boundary.php';
	$stateRoot=$t->tempDirectory('scheduler-state-boundary');
	$result=$t->phpProcess([$fixture,$kernel,$stateRoot]);
	$t->processSucceeded($result,$result->stderr());$payload=$result->json();
	$t->same(true,$payload['first']);
	$t->same(false,$payload['atWorkerBoundary']);
	$t->same(false,$payload['atTransportMargin']);
	$t->same(true,$payload['afterExpiry']);
})->tag('durable-claim','maximum-budget','expiry');

test('durable scheduler state executes its complete claim success reconciliation and corruption contract',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$root=$t->tempDirectory('scheduler-state-exact');
	if(class_exists('DataphyreApplicationRuntimeSchedulerState',false)){
		throw new RuntimeException('Scheduler state exact case was not process-isolated.');
	}
	define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$root);
	require_once $kernel.'/application_runtime_scheduler_state.php';
	$internals=$t->nonPublic(DataphyreApplicationRuntimeSchedulerState::class);
	$identity=['cloud_application'=>'serve_shop','framework_application'=>'Serve','environment'=>'Staging.Blue'];
	$release='dep_'.str_repeat('a',40);$generation='gen_'.str_repeat('b',32);
	$definition=static fn(string $name='serve.task',int $frequency=1000): array=>[
		'name'=>$name,'task_sha256'=>'sha256:'.hash('sha256','task-'.$name),
		'dependency_sha256'=>['sha256:'.hash('sha256','dependency-'.$name)],
		'frequency_milliseconds'=>$frequency,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$task=$definition();$nonce=str_repeat('c',64);$now=1776073500;
	$t->matches('/^sha256:[a-f0-9]{64}$/D',DataphyreApplicationRuntimeSchedulerState::identitySha256($identity));
	$t->matches('/^sha256:[a-f0-9]{64}$/D',DataphyreApplicationRuntimeSchedulerState::stateSha256($identity));
	$t->same([$task],DataphyreApplicationRuntimeSchedulerState::due($identity,[$task],$now));
	$t->same([[
		'definition'=>$task,'due_at_milliseconds'=>$now*1000,'first_execution'=>true,
	]],DataphyreApplicationRuntimeSchedulerState::dueSchedule($identity,[$task],$now*1000));
	$t->isTrue(DataphyreApplicationRuntimeSchedulerState::claim($identity,$task,$release,$generation,$nonce,$now));
	$t->isFalse(DataphyreApplicationRuntimeSchedulerState::claim($identity,$task,$release,$generation,str_repeat('d',64),$now));
	$t->same([],DataphyreApplicationRuntimeSchedulerState::due($identity,[$task],$now));
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::recordSuccess(
		$identity,$task,$release,$generation,$now+1,str_repeat('d',64),
	),RuntimeException::class);
	DataphyreApplicationRuntimeSchedulerState::recordSuccess($identity,$task,$release,$generation,$now+1,$nonce);
	$t->same([],DataphyreApplicationRuntimeSchedulerState::due($identity,[$task],$now+1));
	$t->same([$task],DataphyreApplicationRuntimeSchedulerState::due($identity,[$task],$now+2));
	$t->same([[
		'definition'=>$task,'due_at_milliseconds'=>($now+2)*1000,'first_execution'=>false,
	]],DataphyreApplicationRuntimeSchedulerState::dueSchedule($identity,[$task],($now+2)*1000));
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeSchedulerState::dueSchedule($identity,[$task],999),
		RuntimeException::class,
	);
	$secondNonce=str_repeat('e',64);
	$t->isTrue(DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$task,$release,$generation,$secondNonce,$now+2,
	));
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::releaseClaim(
		$identity,$task,$release,$generation,str_repeat('f',64),
	),RuntimeException::class);
	DataphyreApplicationRuntimeSchedulerState::releaseClaim($identity,$task,$release,$generation,$secondNonce);
	$t->isTrue(DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$task,$release,$generation,str_repeat('f',64),$now+2,
	));
	DataphyreApplicationRuntimeSchedulerState::reconcile($identity,[],$now+3);
	$t->same([],DataphyreApplicationRuntimeSchedulerState::due($identity,[],$now+3));
	DataphyreApplicationRuntimeSchedulerState::reconcile($identity,[$task],$now+3);
	DataphyreApplicationRuntimeSchedulerState::reconcile($identity,[],$now+100);
	$t->matches('/^sha256:[a-f0-9]{64}$/D',DataphyreApplicationRuntimeSchedulerState::definitionSha256($task));

	$duplicate=$definition('duplicate');
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeSchedulerState::reconcile($identity,[$duplicate,$duplicate],$now),
		RuntimeException::class,
	);
	$tooMany=[];
	for($index=0;$index<513;$index++) $tooMany[]=$definition('task.'.str_pad((string)$index,3,'0',STR_PAD_LEFT));
	$t->throws(
		static fn()=>DataphyreApplicationRuntimeSchedulerState::reconcile($identity,$tooMany,$now),
		RuntimeException::class,
	);
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::reconcile($identity,[$task],0),RuntimeException::class);
	foreach([
		['bad-release',$generation,$nonce,$now],[$release,'bad-generation',$nonce,$now],
		[$release,$generation,'bad-nonce',$now],[$release,$generation,$nonce,0],
	] as [$candidateRelease,$candidateGeneration,$candidateNonce,$candidateNow]){
		$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::claim(
			$identity,$task,$candidateRelease,$candidateGeneration,$candidateNonce,$candidateNow,
		),RuntimeException::class);
	}
	foreach([
		['bad-release',$generation,$now,$nonce],[$release,'bad-generation',$now,$nonce],
		[$release,$generation,0,$nonce],[$release,$generation,$now,'bad-nonce'],
	] as [$candidateRelease,$candidateGeneration,$completedAt,$candidateNonce]){
		$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::recordSuccess(
			$identity,$task,$candidateRelease,$candidateGeneration,$completedAt,$candidateNonce,
		),RuntimeException::class);
	}
	foreach([
		['bad-release',$generation,$nonce],[$release,'bad-generation',$nonce],[$release,$generation,'bad-nonce'],
	] as [$candidateRelease,$candidateGeneration,$candidateNonce]){
		$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::releaseClaim(
			$identity,$task,$candidateRelease,$candidateGeneration,$candidateNonce,
		),RuntimeException::class);
	}

	$invalidDefinitions=[];
	foreach([
		'name'=>'bad name','task_sha256'=>'bad','dependency_sha256'=>['bad'],
		'frequency_milliseconds'=>-1,'timeout_milliseconds'=>999,'memory_limit'=>'0M',
	] as $field=>$invalidValue){$candidate=$task;$candidate[$field]=$invalidValue;$invalidDefinitions[]=$candidate;}
	$candidate=$task;$candidate['dependency_sha256']=array_fill(0,129,'sha256:'.str_repeat('a',64));$invalidDefinitions[]=$candidate;
	$candidate=$task;$candidate['extra']=true;$invalidDefinitions[]=$candidate;
	foreach($invalidDefinitions as $candidate){
		$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::definitionSha256($candidate),RuntimeException::class);
	}

	$stateFile=$root.'/state.json';$lockFile=$root.'/state.lock';
	$empty=[
		'contract'=>'dataphyre.scheduler_state.v1','cloud_application'=>$identity['cloud_application'],
		'framework_application'=>$identity['framework_application'],'environment'=>$identity['environment'],'entries'=>[],
	];
	$writeState=static function(string $bytes) use ($stateFile): void {
		if(is_link($stateFile) || is_file($stateFile)) unlink($stateFile);
		if(is_dir($stateFile)) rmdir($stateFile);
		file_put_contents($stateFile,$bytes,LOCK_EX);chmod($stateFile,0600);
	};
	$full=$empty;
	for($index=0;$index<512;$index++){
		$full['entries']['existing.'.str_pad((string)$index,3,'0',STR_PAD_LEFT)]=[
			'definition_sha256'=>'sha256:'.hash('sha256','existing-'.$index),'last_success_at'=>null,
			'release_id'=>$release,'generation'=>$generation,'claim_nonce'=>null,'claim_expires_at'=>null,
		];
	}
	$writeState(json_encode($full,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$definition('overflow'),$release,$generation,$nonce,$now,
	),RuntimeException::class);
	$writeState("{\n");
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	$wrong=$empty;$wrong['contract']='wrong';
	$writeState(json_encode($wrong,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	$invalidEntry=$empty;
	$invalidEntry['entries']['serve.task']=[
		'definition_sha256'=>'sha256:'.str_repeat('a',64),'last_success_at'=>1,
		'release_id'=>$release,'generation'=>$generation,'claim_nonce'=>$nonce,'claim_expires_at'=>0,
	];
	$writeState(json_encode($invalidEntry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	$writeState(json_encode($empty,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	$writeState(json_encode($empty,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");chmod($stateFile,0644);
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	unlink($stateFile);symlink(__FILE__,$stateFile);
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	unlink($stateFile);

	if(is_file($lockFile) || is_link($lockFile)) unlink($lockFile);
	symlink(__FILE__,$lockFile);
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	unlink($lockFile);file_put_contents($lockFile,'');chmod($lockFile,0600);link($lockFile,$root.'/state.lock.alias');
	$t->throws(static fn()=>DataphyreApplicationRuntimeSchedulerState::stateSha256($identity),RuntimeException::class);
	unlink($root.'/state.lock.alias');unlink($lockFile);
	mkdir($stateFile,0700);
	$t->throws(static fn()=>$internals->invoke('write',$empty),RuntimeException::class);
	if(is_dir($stateFile)) rmdir($stateFile);
	$oversized=$empty;$oversized['padding']=str_repeat('x',262144);
	$t->throws(static fn()=>$internals->invoke('write',$oversized),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('syncDirectory',$root.'/missing'),RuntimeException::class);
	$t->throws(static fn()=>$internals->invoke('syncDirectory','/proc'),RuntimeException::class);
	$t->same(json_encode($empty,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",$internals->invoke('canonical',$empty));
})->tag('durable-state','claim','success','release','reconcile','corruption','exact-coverage');

test('serial cold callbacks fail cadence even when every worker receipt succeeds',static function(Context $t): void {
	$root=$t->tempDirectory('scheduler-cadence-timing');
	if(!chmod($root,0700)) throw new RuntimeException('Scheduler cadence state root mode could not be prepared.');
	define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$root);
	require_once dirname(__DIR__).'/kernel/application_runtime_supervisor.php';
	$definition=static fn(string $name,int $frequency): array=>[
		'name'=>$name,'task_sha256'=>'sha256:'.hash('sha256',$name),'dependency_sha256'=>[],
		'frequency_milliseconds'=>$frequency,'timeout_milliseconds'=>300000,'memory_limit'=>'128M',
	];
	$registration=static function(array $definitions): array {
		return [
			'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,
			'registration_attempt_count'=>count($definitions),'registration_accepted_count'=>count($definitions),
			'registration_failure_count'=>0,'definition_count'=>count($definitions),
			'definition_sha256'=>'sha256:'.hash(
				'sha256',json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
			),
			'definitions'=>$definitions,
		];
	};
	$runtime=static fn(array $schedulerRegistration): array=>[
		'active'=>true,'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,
		'scheduler_cadence_failed'=>false,'scheduler_cycle_in_progress'=>false,
		'scheduler_registration'=>$schedulerRegistration,
	];
	$identity=[
		'cloud_application'=>'fixture','framework_application'=>'Fixture','environment'=>'staging',
		'release_id'=>'dep_'.str_repeat('a',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
	];
	$definitions=[
		$definition('fixture.every-05-seconds',5000),
		$definition('fixture.every-10-seconds',10000),
		$definition('fixture.every-15-seconds',15000),
	];
	$slowRuntime=$runtime($registration($definitions));$pending=[];$activation=null;$nextTick=0.0;
	$nowMilliseconds=1776073500000;$requests=0;$reports=[];
	$clock=static function() use (&$nowMilliseconds): int {return $nowMilliseconds;};
	$slowCallback=static function() use (&$nowMilliseconds,&$requests): array {
		$requests++;$nowMilliseconds+=22000;
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	$reporter=static function(array $evidence) use (&$reports): void {$reports[]=$evidence;};
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$slowRuntime,$pending,1,
		$activation,$nextTick,$slowCallback,null,$clock,$reporter,
	);
	$t->same(3,$requests,'all three trusted callback receipts still completed');
	$t->same('failed',$slowRuntime['last_result'],'eventual success cannot certify missed cadence');
	$t->same(1,$slowRuntime['count']);
	$t->count(1,$reports);
	$t->same([
		'ok'=>false,'observation_count'=>3,'late_start_count'=>2,'late_completion_count'=>3,
		'overdue_again_count'=>2,'max_start_lateness_milliseconds'=>28000,
		'max_completion_lateness_milliseconds'=>50000,'max_recurrence_lateness_milliseconds'=>38000,
	],$reports[0]);
	$t->same(true,$slowRuntime['scheduler_cadence_failed']);
	$slowRuntime['scheduler_registration']=$registration([]);$reports=[];
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('c',32),'secret','public',null,$slowRuntime,$pending,1,
		$activation,$nextTick,static fn(): never=>throw new RuntimeException('empty cycle dispatched'),
		null,$clock,$reporter,
	);
	$t->same('failed',$slowRuntime['last_result'],'an empty cycle cannot erase measured topology failure');
	$t->same(2,$slowRuntime['count']);
	$t->same([],$reports);

	$fastDefinitions=[
		$definition('fixture.fast-05-seconds',5000),
		$definition('fixture.fast-10-seconds',10000),
		$definition('fixture.fast-15-seconds',15000),
	];
	$fastRuntime=$runtime($registration($fastDefinitions));$activation=null;$nextTick=0.0;
	$nowMilliseconds=1776074000000;$requests=0;$reports=[];
	$fastCallback=static function() use (&$nowMilliseconds,&$requests): array {
		$requests++;$nowMilliseconds+=200;
		return ['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true];
	};
	dataphyre_runtime_run_scheduler_cycle(
		DataphyreApplicationRuntimeSchedulerGateway::SOCKET,$identity,'gen_'.str_repeat('d',32),'secret','public',null,$fastRuntime,$pending,1,
		$activation,$nextTick,$fastCallback,null,$clock,$reporter,
	);
	$t->same(3,$requests);
	$t->same('ok',$fastRuntime['last_result']);
	$t->same([],$reports);
})->tag('scheduler','cadence','lateness','real-worker-topology','release','regression');

test('one failed definition does not starve later due callbacks',static function(Context $t): void {
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_supervisor.php');
	$t->contains('$cycleFailed=false',$source);
	$t->contains('$cycleFailed=true',$source);
	$t->contains("\$runtime['scheduler_cadence_failed']=true",$source);
	$t->contains("\$runtime['last_result']=\$cycleFailed ||",$source);
	$t->contains('releaseClaim(',$source);
})->tag('callback-failure','continue','starvation','regression');

test('deactivation stops claiming new definitions after one draining callback',static function(Context $t): void {
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_supervisor.php');
	$loop=strstr($source,'foreach($due as $scheduled){');
	$t->isTrue(is_string($loop));
	$t->contains('dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);',$loop);
	$t->contains("if(\$runtime['active']!==true) break;",$loop);
	$t->isTrue(strpos($loop,"if(\$runtime['active']!==true) break;")<strpos($loop,'SchedulerState::claim('));
})->tag('deactivation','quiescence','drain-one','regression');
