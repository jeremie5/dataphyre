<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once \dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
require_once \dirname(__DIR__,2).'/sql/Framework/RegisteredTableMaterializationCommand.php';
require_once __DIR__.'/application_scheduler_definition_evidence.php';

const DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT='dataphyre.application_realtime_registration.v1';

(static function(): void {
	$write=static function(array $payload): void {
		$line=\json_encode($payload,\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR)."\n";
		if(\strlen($line)>\Dataphyre\Database\RegisteredTableMaterializationCommand::MAX_OUTPUT_BYTES){
			throw new \RuntimeException('Realtime preflight evidence exceeds its fixed bound.');
		}
		$written=\fwrite(\STDOUT,$line);
		if(!\is_int($written) || $written!==\strlen($line)){
			throw new \RuntimeException('Realtime preflight evidence write failed.');
		}
	};
	$failurePayload=static fn(): array=>[
		'contract'=>DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT,'ok'=>false,'route_count'=>0,
		'registration_sha256'=>null,'registered_table_count'=>0,
		'registered_table_materialization_contract'=>\Dataphyre\Database\RegisteredTableMaterializationCommand::CONTRACT,
		'registered_table_set_sha256'=>null,'scheduler_definition_count'=>0,'scheduler_definition_sha256'=>null,
	];
	$schedulerEvidence=static function(string $stateRoot): array {
		$root=\rtrim($stateRoot,'/\\').'/cache/scheduling';
		if(!\is_dir($root)){
			return \DataphyreApplicationSchedulerDefinitionEvidence::inventory([])
				?? throw new \RuntimeException('Empty scheduler evidence is invalid.');
		}
		$definitions=[];$entries=\scandir($root);
		if(!\is_array($entries)
			|| \count($entries)>\DataphyreApplicationSchedulerDefinitionEvidence::MAX_DEFINITIONS+2){
			throw new \RuntimeException('Unable to inspect isolated scheduler definitions.');
		}
		foreach($entries as $name){
			if($name==='.' || $name==='..') continue;
			$directory=$root.'/'.$name;$properties=$directory.'/properties.json';
			if(!\is_dir($directory) || \is_link($directory) || !\is_file($properties) || \is_link($properties)){
				throw new \RuntimeException('Isolated scheduler state contains an invalid definition.');
			}
			$directoryEntries=\array_values(\array_diff(\scandir($directory) ?: [],['.','..']));
			if($directoryEntries!==['properties.json']) throw new \RuntimeException('Realtime preflight created executable scheduler state.');
			$decoded=\json_decode((string)\file_get_contents($properties),true,32,\JSON_THROW_ON_ERROR);
			if(!\is_array($decoded) || \array_keys($decoded)!==[
				'name','file_path','frequency','dependencies','timeout','memory_limit','app_override',
			] || ($decoded['name'] ?? null)!==$name || !\is_string($decoded['file_path'] ?? null)
				|| !\is_file($decoded['file_path']) || \is_link($decoded['file_path'])
				|| (!\is_int($decoded['frequency']) && !\is_float($decoded['frequency']))
				|| !\is_array($decoded['dependencies']) || !\array_is_list($decoded['dependencies'])
				|| (!\is_int($decoded['timeout']) && !\is_float($decoded['timeout']))
				|| !\is_string($decoded['memory_limit']) || \trim($decoded['memory_limit'])===''
				|| !\is_string($decoded['app_override'])) throw new \RuntimeException('Isolated scheduler definition is malformed.');
			$definition=\DataphyreApplicationSchedulerDefinitionEvidence::definition($decoded);
			if(!\is_array($definition)) throw new \RuntimeException('Isolated scheduler definition evidence is invalid.');
			$definitions[]=$definition;
		}
		return \DataphyreApplicationSchedulerDefinitionEvidence::inventory($definitions)
			?? throw new \RuntimeException('Scheduler definition inventory is invalid.');
	};

$arguments=\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
if(\PHP_SAPI!=='cli' || \count($arguments)!==4){
	exit(64);
}
$script=(string)($_SERVER['SCRIPT_FILENAME'] ?? '');
$serverArgument=(string)($arguments[0] ?? '');
$globalArgument=(string)((\is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [])[0] ?? '');
$included=\get_included_files();$firstIncluded=(string)($included[0] ?? '');
foreach([$script,$serverArgument,$globalArgument,$firstIncluded] as $path){
	if($path==='' || \is_link($path) || \realpath($path)!==__FILE__) exit(64);
}
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$arguments[0]=__FILE__;$_SERVER['argv']=$arguments;$GLOBALS['argv']=$arguments;$GLOBALS['argc']=\count($arguments);
[$script,$projectArgument,$applicationArgument,$environmentArgument]=$arguments;
if(\preg_match('/^--project-root=(.+)$/D',(string)$projectArgument,$projectMatch)!==1
	|| \preg_match('/^--application=(.+)$/D',(string)$applicationArgument,$applicationMatch)!==1
	|| \preg_match('/^--environment=(.+)$/D',(string)$environmentArgument,$environmentMatch)!==1){
	exit(64);
}
$projectRoot=\realpath($projectMatch[1]);
$application=\trim($applicationMatch[1]);
$environment=\trim($environmentMatch[1]);
if($projectRoot===false || !\is_dir($projectRoot)
	|| \preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1
	|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)){
	exit(64);
}
$stateRoot=null;$terminalEvidencePending=false;
try{
	if(!@\chdir($projectRoot) || \realpath((string)\getcwd())!==$projectRoot){
		throw new \RuntimeException('Unable to select application project directory.');
	}
	$stateRoot=\rtrim(\sys_get_temp_dir(),'/\\').'/dataphyre-realtime-preflight-'.\bin2hex(\random_bytes(16));
	if(!\mkdir($stateRoot,0700,true) || !\is_dir($stateRoot) || \is_link($stateRoot)){
		throw new RuntimeException('Unable to create isolated realtime preflight state.');
	}
	foreach(['DATAPHYRE_RUNTIME_POOL=realtime-preflight','DATAPHYRE_RUNTIME_POOL_ROLE=realtime-preflight',
		'DATAPHYRE_SCHEDULER_ACTIVATION_MODE=record_only','DATAPHYRE_SCHEDULER_STATE_ROOT='.$stateRoot,
		'DATAPHYRE_RUNTIME_PROJECT_ROOT='.$projectRoot,'DATAPHYRE_RUNTIME_APPLICATION='.$application,
		'DATAPHYRE_RUNTIME_ENVIRONMENT='.$environment] as $assignment){
		if(!\putenv($assignment)) throw new \RuntimeException('Unable to establish realtime preflight environment.');
	}
	$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']=[
		'state_root'=>$stateRoot,
		'private_key'=>\bin2hex(\random_bytes(32)),
		'project_root'=>$projectRoot,
		'token'=>\bin2hex(\random_bytes(32)),
		'scheduler_attempt_count'=>0,
		'scheduler_failure_count'=>0,
	];
	require_once __DIR__.'/application_runtime_realtime_bootstrap.php';
	$terminalEvidencePending=true;
	\register_shutdown_function(static function() use (&$terminalEvidencePending,$write,$failurePayload): void {
		if(!$terminalEvidencePending) return;
		try{DataphyreApplicationRuntimeRealtimeBootstrap::preservePreflightOutputBoundary();}catch(\Throwable){}
		try{$write($failurePayload());$terminalEvidencePending=false;}catch(\Throwable){}
		exit(70);
	});
	$routes=DataphyreApplicationRuntimeRealtimeBootstrap::load();
	$tableEvidence=null;
	for($drain=0;$drain<1024;$drain++){
		$tableEvidence=\Dataphyre\Database\RegisteredTableMaterializationCommand::registeredTableInventoryEvidence();
		$deferred=$GLOBALS['dataphyre_deferred_sql_table_definitions'] ?? [];
		if($deferred===[]) break;
		if(!\is_array($deferred)) throw new \RuntimeException('Deferred SQL table registry is invalid.');
	}
	if(!\is_array($tableEvidence) || ($GLOBALS['dataphyre_deferred_sql_table_definitions'] ?? [])!==[]){
		throw new \RuntimeException('Deferred SQL table registry did not drain.');
	}
	\Dataphyre\InternalApplicationBootstrapOnly::context();
	$routes=\dataphyre\realtime::runtimeRoutes();
	$paths=\array_keys($routes);
	$encoded=\json_encode($paths,\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR);
	$schedulerEvidenceResult=$schedulerEvidence($stateRoot);
	$attemptCount=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']['scheduler_attempt_count'] ?? null;
	$failureCount=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT']['scheduler_failure_count'] ?? null;
	if(!\is_int($attemptCount) || !\is_int($failureCount) || $failureCount!==0
		|| $attemptCount!==$schedulerEvidenceResult['definition_count']){
		throw new \RuntimeException('Application scheduler registration was partial.');
	}
	$successPayload=[
		'contract'=>DATAPHYRE_REALTIME_PREFLIGHT_CONTRACT,
		'ok'=>true,
		'route_count'=>\count($paths),
		'registration_sha256'=>'sha256:'.\hash('sha256',$encoded),
		'registered_table_count'=>$tableEvidence['registered_count'],
		'registered_table_materialization_contract'=>\Dataphyre\Database\RegisteredTableMaterializationCommand::CONTRACT,
		'registered_table_set_sha256'=>'sha256:'.$tableEvidence['table_set_sha256'],
		'scheduler_definition_count'=>$schedulerEvidenceResult['definition_count'],
		'scheduler_definition_sha256'=>$schedulerEvidenceResult['definition_sha256'],
	];
	\Dataphyre\InternalApplicationBootstrapOnly::context();
	DataphyreApplicationRuntimeRealtimeBootstrap::assertPreflightOutputBoundary();
	$write($successPayload);$terminalEvidencePending=false;
	exit(0);
}catch(\Throwable){
	if(\class_exists(DataphyreApplicationRuntimeRealtimeBootstrap::class,false)){
		try{DataphyreApplicationRuntimeRealtimeBootstrap::preservePreflightOutputBoundary();}catch(\Throwable){}
	}
	try{$write($failurePayload());$terminalEvidencePending=false;}catch(\Throwable){}
	exit(70);
}
})();
