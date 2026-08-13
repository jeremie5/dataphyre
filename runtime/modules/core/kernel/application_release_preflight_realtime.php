<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

const DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT='dataphyre.application_realtime_registration.v1';

function dataphyre_realtime_preflight_remove_tree(string $root): void {
	if($root==='' || !is_dir($root) || is_link($root)) return;
	$iterator=new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach($iterator as $entry){
		$path=$entry->getPathname();
		if($entry->isLink() || $entry->isFile()) @unlink($path);
		elseif($entry->isDir()) @rmdir($path);
	}
	@rmdir($root);
}

/** @param array<string,mixed> $payload */
function dataphyre_realtime_preflight_write(array $payload): void {
	$encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	fwrite(STDOUT,$encoded."\n");
}

/** @return array{definition_count:int,definition_sha256:string} */
function dataphyre_realtime_preflight_scheduler_evidence(string $stateRoot): array {
	$root=rtrim($stateRoot,'/\\').'/cache/scheduling';
	if(!is_dir($root)){
		$encoded=json_encode([],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return ['definition_count'=>0,'definition_sha256'=>'sha256:'.hash('sha256',$encoded)];
	}
	$definitions=[];
	$entries=scandir($root);
	if(!is_array($entries)) throw new RuntimeException('Unable to inspect isolated scheduler definitions.');
	if(count($entries)>258) throw new RuntimeException('Too many scheduler definitions were registered.');
	foreach($entries as $name){
		if($name==='.' || $name==='..') continue;
		$directory=$root.'/'.$name;
		$properties=$directory.'/properties.json';
		if(!is_dir($directory) || is_link($directory) || !is_file($properties) || is_link($properties)){
			throw new RuntimeException('Isolated scheduler state contains an invalid definition.');
		}
		$directoryEntries=array_values(array_diff(scandir($directory) ?: [],['.','..']));
		if($directoryEntries!==['properties.json']){
			throw new RuntimeException('Realtime registration preflight created executable scheduler state.');
		}
		$decoded=json_decode((string)file_get_contents($properties),true,32,JSON_THROW_ON_ERROR);
		if(!is_array($decoded) || array_keys($decoded)!==[
			'name','file_path','frequency','dependencies','timeout','memory_limit','app_override',
		] || ($decoded['name'] ?? null)!==$name || !is_string($decoded['file_path'] ?? null)
			|| !is_file($decoded['file_path']) || is_link($decoded['file_path'])
			|| !is_int($decoded['frequency']) && !is_float($decoded['frequency'])
			|| !is_array($decoded['dependencies']) || !array_is_list($decoded['dependencies'])
			|| !is_int($decoded['timeout']) && !is_float($decoded['timeout'])
			|| !is_string($decoded['memory_limit']) || trim($decoded['memory_limit'])===''
			|| !is_string($decoded['app_override'])){
			throw new RuntimeException('Isolated scheduler definition is malformed.');
		}
		$dependencyHashes=[];
		foreach($decoded['dependencies'] as $dependency){
			if(!is_string($dependency) || !is_file($dependency) || is_link($dependency)){
				throw new RuntimeException('Isolated scheduler dependency is unavailable.');
			}
			$dependencyHash=hash_file('sha256',$dependency);
			if(!is_string($dependencyHash) || preg_match('/^[a-f0-9]{64}$/D',$dependencyHash)!==1){
				throw new RuntimeException('Unable to hash an isolated scheduler dependency.');
			}
			$dependencyHashes[]='sha256:'.$dependencyHash;
		}
		sort($dependencyHashes,SORT_STRING);
		$taskHash=hash_file('sha256',$decoded['file_path']);
		if(!is_string($taskHash) || preg_match('/^[a-f0-9]{64}$/D',$taskHash)!==1){
			throw new RuntimeException('Unable to hash an isolated scheduler task.');
		}
		$definitions[]=[
			'name'=>$name,
			'task_sha256'=>'sha256:'.$taskHash,
			'dependency_sha256'=>$dependencyHashes,
			'frequency'=>(float)$decoded['frequency'],
			'timeout'=>(float)$decoded['timeout'],
			'memory_limit'=>$decoded['memory_limit'],
			'app_override'=>$decoded['app_override'],
		];
	}
	usort($definitions,static fn(array $left,array $right): int=>$left['name']<=>$right['name']);
	if(count($definitions)>256) throw new RuntimeException('Too many scheduler definitions were registered.');
	$encoded=json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	return [
		'definition_count'=>count($definitions),
		'definition_sha256'=>'sha256:'.hash('sha256',$encoded),
	];
}

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==4){
	exit(64);
}
[$script,$projectArgument,$applicationArgument,$environmentArgument]=$argv;
if(preg_match('/^--project-root=(.+)$/D',(string)$projectArgument,$projectMatch)!==1
	|| preg_match('/^--application=(.+)$/D',(string)$applicationArgument,$applicationMatch)!==1
	|| preg_match('/^--environment=(.+)$/D',(string)$environmentArgument,$environmentMatch)!==1){
	exit(64);
}
$projectRoot=realpath($projectMatch[1]);
$application=trim($applicationMatch[1]);
$environment=trim($environmentMatch[1]);
if($projectRoot===false || !is_dir($projectRoot)
	|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1
	|| preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$environment)!==1){
	exit(64);
}
$stateRoot=null;
$stateCleanupComplete=false;
register_shutdown_function(static function() use (&$stateRoot,&$stateCleanupComplete): void {
	if($stateCleanupComplete!==true && is_string($stateRoot)){
		dataphyre_realtime_preflight_remove_tree($stateRoot);
	}
});
try{
	$stateRoot=rtrim(sys_get_temp_dir(),'/\\').'/dataphyre-realtime-preflight-'.bin2hex(random_bytes(16));
	if(!mkdir($stateRoot,0700,true) || !is_dir($stateRoot) || is_link($stateRoot)){
		throw new RuntimeException('Unable to create isolated realtime preflight state.');
	}
	putenv('DATAPHYRE_RUNTIME_POOL=realtime-preflight');
	putenv('DATAPHYRE_RUNTIME_POOL_ROLE=realtime-preflight');
	putenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE=record_only');
	putenv('DATAPHYRE_SCHEDULER_STATE_ROOT='.$stateRoot);
	putenv('DATAPHYRE_RUNTIME_PROJECT_ROOT='.$projectRoot);
	putenv('DATAPHYRE_RUNTIME_APPLICATION='.$application);
	putenv('DATAPHYRE_RUNTIME_ENVIRONMENT='.$environment);
	$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']=[
		'state_root'=>$stateRoot,
		'private_key'=>bin2hex(random_bytes(32)),
		'project_root'=>$projectRoot,
		'token'=>bin2hex(random_bytes(32)),
		'scheduler_attempt_count'=>0,
		'scheduler_failure_count'=>0,
	];
	require_once __DIR__.'/application_runtime_realtime_bootstrap.php';
	$routes=DataphyreApplicationRuntimeRealtimeBootstrap::load();
	$paths=array_keys($routes);
	$encoded=json_encode($paths,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$schedulerEvidence=dataphyre_realtime_preflight_scheduler_evidence($stateRoot);
	$attemptCount=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']['scheduler_attempt_count'] ?? null;
	$failureCount=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']['scheduler_failure_count'] ?? null;
	if(!is_int($attemptCount) || !is_int($failureCount) || $failureCount!==0
		|| $attemptCount!==$schedulerEvidence['definition_count']){
		throw new RuntimeException('Application scheduler registration was partial.');
	}
	dataphyre_realtime_preflight_write([
		'contract'=>DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT,
		'ok'=>true,
		'route_count'=>count($paths),
		'registration_sha256'=>'sha256:'.hash('sha256',$encoded),
		'scheduler_definition_count'=>$schedulerEvidence['definition_count'],
		'scheduler_definition_sha256'=>$schedulerEvidence['definition_sha256'],
	]);
	dataphyre_realtime_preflight_remove_tree($stateRoot);
	if(file_exists($stateRoot) || is_link($stateRoot)) throw new RuntimeException('Unable to remove isolated realtime preflight state.');
	$stateCleanupComplete=true;
	exit(0);
}catch(Throwable){
	if(is_string($stateRoot)) dataphyre_realtime_preflight_remove_tree($stateRoot);
	$stateCleanupComplete=!is_string($stateRoot) || (!file_exists($stateRoot) && !is_link($stateRoot));
	dataphyre_realtime_preflight_write([
		'contract'=>DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT,
		'ok'=>false,
		'route_count'=>0,
		'registration_sha256'=>null,
		'scheduler_definition_count'=>0,
		'scheduler_definition_sha256'=>null,
	]);
	exit(70);
}
