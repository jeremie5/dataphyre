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

suite('Managed scheduler callback multiplexing')
	->contract('core.application-runtime-scheduler-multiplex',1)
	->layer('unit')->risk('critical')->watches('module:core')
	->isolation('case')->tag('core','runtime','scheduler','cadence','release')
	->group('framework-coverage');

test('fixed fan-out gives the 91-definition cadence a bounded measured window',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	require_once $kernel.'/application_runtime_supervisor.php';
	$gateway=(string)file_get_contents($kernel.'/application_runtime_scheduler_gateway.php');
	$supervisor=(string)file_get_contents($kernel.'/application_runtime_supervisor.php');
	$durationMilliseconds=1400;
	$cadenceWindowMilliseconds=5000+1000; // declared five-second work plus the existing one-second evidence grace
	$cycleDuration=static fn(int $definitions,int $capacity): int=>(int)ceil($definitions/$capacity)*$durationMilliseconds;
	$t->same(32,dataphyre_runtime_scheduler_callback_concurrency());
	$t->contains('MAX_CHILDREN=32',$gateway);
	$t->contains('stream_select($read,$write,$except,0,20000)',$supervisor);
	$t->same(12,(int)($cycleDuration(91,8)/$durationMilliseconds),'eight children require twelve callback waves');
	$t->greaterThan($cadenceWindowMilliseconds,$cycleDuration(91,8));
	$t->lessThanOrEqual($cadenceWindowMilliseconds,$cycleDuration(91,32));
	$t->same(3,(int)ceil(91/32),'the fixed topology drains 91 callbacks in three waves');
	$t->contains('usort($due,static function',$supervisor);
	$t->contains("strcmp(\$leftDefinition['name'],\$rightDefinition['name'])",$supervisor);
})->tag('91-definitions','timing','capacity','cadence','deterministic');

test('durable claims outlive broker setup before a successor may reclaim work',static function(Context $t): void {
	$kernel=dirname(__DIR__).'/kernel';
	$root=$t->tempDirectory('scheduler-claim-broker-lease');
	if(!chmod($root,0700)) throw new RuntimeException('Scheduler claim lease root mode could not be prepared.');
	if(!defined('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT')) define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$root);
	require_once $kernel.'/application_runtime_scheduler_state.php';
	$identity=[
		'cloud_application'=>'Store:North_2-Beta','framework_application'=>'Serve','environment'=>'Staging.Blue',
	];
	$definition=[
		'name'=>'fixture.broker-lease','task_sha256'=>'sha256:'.str_repeat('a',64),'dependency_sha256'=>[],
		'frequency_milliseconds'=>5000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
	];
	$release='dep_'.str_repeat('a',40);$firstGeneration='gen_'.str_repeat('b',32);
	$secondGeneration='gen_'.str_repeat('c',32);$now=1776073500;
	$firstNonce=str_repeat('d',64);$secondNonce=str_repeat('e',64);
	$t->isTrue(DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$definition,$release,$firstGeneration,$firstNonce,$now,
	));
	$state=json_decode((string)file_get_contents($root.'/state.json'),true,32,JSON_THROW_ON_ERROR);
	$entry=$state['entries'][$definition['name']] ?? null;
	$t->same($now+2+120,$entry['claim_expires_at'],'two-second task plus the 120-second broker/transport/recovery lease');
	$t->isFalse(DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$definition,$release,$secondGeneration,$secondNonce,$now+100,
	),'a successor cannot reclaim while any independently bounded broker phase may still be alive');
	$t->isTrue(DataphyreApplicationRuntimeSchedulerState::claim(
		$identity,$definition,$release,$secondGeneration,$secondNonce,$entry['claim_expires_at']+1,
	),'recovery begins only after the complete fixed lease expires');
})->tag('durable-claim','broker-boundary','expiry','no-overlap','deterministic');

test('bounded callback state keeps failures and TERM cleanup claim-local',static function(Context $t): void {
	$run=static function(int $definitions,int $capacity,array $failed,?int $termAfter=null): array {
		$queue=range(1,$definitions);$active=[];$claims=[];$success=[];$failures=[];$maxActive=0;$launched=0;$terminated=false;
		while($queue!==[] || $active!==[]){
			while($queue!==[] && count($active)<$capacity && ($termAfter===null || $launched<$termAfter)){
				$id=array_shift($queue);$active[$id]=true;$claims[$id]=true;$launched++;$maxActive=max($maxActive,count($active));
			}
			if($termAfter!==null && $launched>=$termAfter){
				$terminated=true;
				// A local socket can be closed, but the durable claim remains held
				// until EOF/process death proves that the gateway cannot overlap.
				foreach(array_keys($active) as $id){unset($active[$id]);$failures[]=$id;}
				break;
			}
			foreach(array_keys($active) as $id){
				unset($active[$id],$claims[$id]);
				if(in_array($id,$failed,true)) $failures[]=$id; else $success[]=$id;
			}
		}
		return [
			'launched'=>$launched,'max_active'=>$maxActive,'success'=>$success,'failures'=>$failures,
			'claims'=>$claims,'terminated'=>$terminated,'unlaunched'=>$queue,
		];
	};
	$partial=$run(91,32,[17]);
	$t->same(32,$partial['max_active']);
	$t->same(90,count($partial['success']));
	$t->same([17],$partial['failures']);
	$t->same([],$partial['claims'],'a failed callback releases only its own claim');
	$t->same([],$partial['unlaunched']);
	$term=$run(91,32,[],35);
	$t->same(true,$term['terminated']);
	$t->same(3,count($term['claims']),'TERM retains each unproven in-flight claim until its durable expiry');
	$t->same(35,count($term['success'])+count($term['failures']));
	$t->greaterThan(0,count($term['unlaunched']));
})->tag('saturation','partial-failure','term','claim-cleanup','deterministic');

test('multiplexed receipts remain strict and lifecycle cleanup is explicit',static function(Context $t): void {
	require_once dirname(__DIR__).'/kernel/application_runtime_supervisor.php';
	$body=json_encode(['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true],JSON_THROW_ON_ERROR);
	$t->same(
		['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true],
		dataphyre_runtime_scheduler_decode_callback_response(
			"HTTP/1.1 200 OK\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body,
		),
	);
	$t->throws(static fn()=>dataphyre_runtime_scheduler_decode_callback_response(
		"HTTP/1.1 200 OK\r\nContent-Length: 17\r\n\r\n{\"contract\":\"bad\"}",
	),RuntimeException::class);
	$source=(string)file_get_contents(dirname(__DIR__).'/kernel/application_runtime_supervisor.php');
	foreach([
		'DataphyreApplicationRuntimeSchedulerState::claim(',
		'DataphyreApplicationRuntimeSchedulerState::recordSuccess(',
		'DataphyreApplicationRuntimeSchedulerState::releaseClaim(',
		'DataphyreManagedRuntimeGracefulShutdown|DataphyreManagedRuntimeGenerationUnavailable',
		'dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister)',
		'dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey)',
		'$cleanup();throw $failure;',
	] as $needle) $t->contains($needle,$source);
})->tag('receipt','strict','activation','status','cleanup','deterministic');

test('real multiplex transport isolates failure, USR2 drain, and TERM claim cleanup',static function(Context $t): void {
	if(!function_exists('posix_geteuid') || posix_geteuid()!==0){
		$t->skip('The exact socket boundary requires the root test container.');
	}
	$kernel=dirname(__DIR__).'/kernel';
	$stateRoot=$t->tempDirectory('scheduler-multiplex-state');
	if(!chmod($stateRoot,0700)) throw new RuntimeException('Scheduler multiplex state root mode could not be prepared.');
	if(!defined('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT')) define('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT',$stateRoot);
	require_once $kernel.'/application_runtime_supervisor.php';
	$socketPath=DataphyreApplicationRuntimeSchedulerGateway::SOCKET;$socketDirectory=dirname($socketPath);
	if(!is_dir($socketDirectory) && !mkdir($socketDirectory,0700,true)) throw new RuntimeException('Scheduler socket directory could not be created.');
	$identity=[
		'cloud_application'=>'Store:North_2-Beta','framework_application'=>'Serve','environment'=>'Staging.Blue',
		'release_id'=>'dep_'.str_repeat('a',40),'environment_fingerprint'=>'hmac-sha256:'.str_repeat('b',64),
	];

	$fakeGateway=static function(
		int $expected,string $controlPath,string $publicKey,?string $failedName=null,int $delayMilliseconds=5,
	) use ($socketPath): int {
		@unlink($socketPath);
		$listener=stream_socket_server('unix://'.$socketPath,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		if(!is_resource($listener)) throw new RuntimeException('Fake scheduler gateway could not bind: '.$error);
		stream_set_blocking($listener,false);$server=pcntl_fork();
		if($server===-1){fclose($listener);@unlink($socketPath);throw new RuntimeException('Fake gateway fork failed.');}
		if($server===0){
			$accepted=0;$children=[];$deadline=microtime(true)+8.0;
			while($accepted<$expected && microtime(true)<$deadline){
				$connection=@stream_socket_accept($listener,0.02);if(!is_resource($connection)) continue;
				$accepted++;$child=pcntl_fork();
				if($child===0){
					stream_set_timeout($connection,2);$wire=stream_get_contents($connection);
					[, $body]=array_pad(explode("\r\n\r\n",is_string($wire) ? $wire : '',2),2,'');
					$candidate=json_decode($body,true);$name=is_array($candidate) ? ($candidate['scheduler_name'] ?? null) : null;
					$control=@stream_socket_client('unix://'.$controlPath,$controlErrno,$controlError,2,STREAM_CLIENT_CONNECT);
					if(!is_resource($control)) exit(3);
					$claimRequest="POST /dataphyre/runtime/scheduler/claim HTTP/1.1\r\nHost: dataphyre-control\r\n".
						"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
					fwrite($control,$claimRequest);stream_socket_shutdown($control,STREAM_SHUT_WR);stream_get_contents($control);fclose($control);
					if($delayMilliseconds>0) usleep($delayMilliseconds*1000);
					if($failedName!==null && $name===$failedName){
						fwrite($connection,"HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
					}else{
						$reply=json_encode(['contract'=>'dataphyre.scheduler_callback.v1','ok'=>true],JSON_THROW_ON_ERROR);
						fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Length: ".strlen($reply)."\r\nConnection: close\r\n\r\n".$reply);
					}
					fclose($connection);exit(0);
				}
				$children[]=$child;fclose($connection);
			}
			foreach($children as $child) pcntl_waitpid($child,$status);
			fclose($listener);exit($accepted===$expected ? 0 : 2);
		}
		fclose($listener);return $server;
	};
	$run=static function(string $prefix,int $count,?string $failedName=null,?int $activationAt=null,?int $stopAt=null) use (
		$stateRoot,$socketPath,$identity,$fakeGateway,
	): array {
		$definitions=[];$due=[];$now=(int)floor(microtime(true)*1000);
		for($i=1;$i<=$count;$i++){
			$name=$prefix.'-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);
			$definition=[
				'name'=>$name,'task_sha256'=>'sha256:'.hash('sha256',$name),'dependency_sha256'=>[],
				'frequency_milliseconds'=>5000,'timeout_milliseconds'=>2000,'memory_limit'=>'128M',
			];
			$definitions[]=$definition;$due[]=['definition'=>$definition,'due_at_milliseconds'=>$now,'first_execution'=>true];
		}
		DataphyreApplicationRuntimeSchedulerState::reconcile($identity,$definitions);
		$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
		$controlPath=$stateRoot.'/control.sock';@unlink($controlPath);
		$controlListener=stream_socket_server('unix://'.$controlPath,$controlErrno,$controlError,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		if(!is_resource($controlListener)) throw new RuntimeException('Fake control listener could not bind: '.$controlError);
		stream_set_blocking($controlListener,false);
		$server=$fakeGateway($count,$controlPath,$public,$failedName,$stopAt===null ? 5 : 500);
		$runtime=['active'=>true,'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,'scheduler_cadence_failed'=>false];
		$pending=[];$activation=null;$nextTick=0.0;$stopRequested=false;$clockCalls=0;
		$clock=static function() use (&$clockCalls,&$activation,&$stopRequested,$activationAt,$stopAt,$now): int {
			$clockCalls++;
			if($activationAt!==null && $clockCalls>=$activationAt) $activation=false;
			if($stopAt!==null && $clockCalls>=$stopAt) $stopRequested=true;
			return $now+$clockCalls;
		};
		$result=null;$failure=null;
		try{
			try{
				$result=dataphyre_runtime_run_scheduler_multiplexed_callbacks(
					$socketPath,$identity,'gen_'.str_repeat('c',32),$secret,$public,$controlListener,$runtime,$pending,
					$activation,$nextTick,1,$due,null,$clock,$stopRequested,
				);
			}catch(Throwable $caught){$failure=$caught;}
		}finally{
			$deadline=microtime(true)+3.0;
			do{$status=null;$wait=pcntl_waitpid($server,$status,WNOHANG);if($wait===$server) break;usleep(10000);}while(microtime(true)<$deadline);
			if(!isset($wait) || $wait!==$server){@posix_kill($server,SIGKILL);pcntl_waitpid($server,$status);}
			@unlink($socketPath);
			fclose($controlListener);@unlink($controlPath);
		}
		if(!is_file($stateRoot.'/state.json')) throw new RuntimeException(
			'multiplex state missing: '.($failure?->getMessage() ?? 'unknown failure'),0,$failure,
		);
		$state=json_decode((string)file_get_contents($stateRoot.'/state.json'),true,32,JSON_THROW_ON_ERROR);
		$owned=[];
		foreach(($state['entries'] ?? []) as $name=>$entry) if(str_starts_with((string)$name,$prefix.'-')) $owned[$name]=$entry;
		return ['result'=>$result,'failure'=>$failure,'pending'=>$pending,'runtime'=>$runtime,'entries'=>$owned];
	};

	$partial=$run('fixture-partial',8,'fixture-partial-04');
	$t->isNull($partial['failure']);$t->same(true,$partial['result']['cycle_failed']);
	$t->same(7,count($partial['result']['observations']));$t->same([],$partial['pending']);
	foreach($partial['entries'] as $entry) $t->same(null,$entry['claim_nonce']);

	$drain=$run('fixture-usr2',91,null,32);
	$t->isNull($drain['failure']);$t->same(false,$drain['runtime']['active']);
	$t->same(32,count($drain['result']['observations']),'USR2 drains only already-dispatched callbacks');
	$t->same(32,count($drain['entries']));
	foreach($drain['entries'] as $entry) $t->same(null,$entry['claim_nonce']);

	$term=$run('fixture-term',91,null,null,32);
	$t->instanceOf(DataphyreManagedRuntimeGracefulShutdown::class,$term['failure']);
	$t->same([],$term['pending']);$t->same(32,count($term['entries']));
	foreach($term['entries'] as $entry){
		$t->notNull($entry['claim_nonce'],'TERM retains the claim until the gateway child is proven dead');
		$t->greaterThan(time(),$entry['claim_expires_at']);
	}
})->tag('multiplex','partial-failure','usr2','term','claim-cleanup','exact-image')->maxMillis(30000);
