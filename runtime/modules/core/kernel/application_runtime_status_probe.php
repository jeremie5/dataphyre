<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
require_once dirname(__DIR__).'/Framework/PublicApplicationIdentifier.php';

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==1 || !function_exists('posix_geteuid') || posix_geteuid()!==0) exit(64);
$socket=@stream_socket_client('unix:///run/dataphyre/control/runtime.sock',$errno,$error,1.5,STREAM_CLIENT_CONNECT);
if(!is_resource($socket)) exit(69);
try{
	stream_set_timeout($socket,2,0);$request="GET /dataphyre/runtime/status HTTP/1.1\r\nHost: dataphyre-control\r\nConnection: close\r\n\r\n";
	if(fwrite($socket,$request)!==strlen($request)) exit(69);
	stream_socket_shutdown($socket,STREAM_SHUT_WR);$response='';
	while(!feof($socket)){
		$chunk=fread($socket,8192);if(!is_string($chunk) || $chunk==='') break;$response.=$chunk;
		if(strlen($response)>16384) exit(69);
	}
}finally{fclose($socket);}
[$head,$payload]=array_pad(explode("\r\n\r\n",$response,2),2,'');
$statusCode=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
if(strlen($payload)>8336 || $statusCode!==200) exit(69);
try{$decoded=json_decode($payload,true,32,JSON_THROW_ON_ERROR);}
catch(Throwable){exit(65);}
if(!is_array($decoded)
	|| json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)!==$payload){
	exit(65);
}

$zero='0000000000000000';$gatewayCaps='00000000000000e0';
$validInactiveCapabilities=static fn(mixed $value): bool=>is_array($value)
	&& ($value['cap_inheritable'] ?? null)===$zero
	&& ($value['cap_permitted'] ?? null)===$zero
	&& ($value['cap_eff'] ?? null)===$zero
	&& in_array(($value['cap_bounding'] ?? null),[$zero,$gatewayCaps],true)
	&& ($value['cap_ambient'] ?? null)===$zero
	&& ($value['no_new_privileges'] ?? null)===true;
$validRealtimePool=static fn(mixed $value): bool=>is_array($value)
	&& array_keys($value)===[
		'running','pid','start_time_ticks','uid','gid','supplementary_gids','cap_inheritable','cap_permitted','cap_eff',
		'cap_bounding','cap_ambient','no_new_privileges',
		'role','listen_host','listen_port','parent_pid','execution_model',
	]
	&& ($value['running'] ?? null)===true
	&& is_int($value['pid'] ?? null) && $value['pid']>1 && $value['pid']<=2147483647
	&& is_string($value['start_time_ticks'] ?? null) && preg_match('/^[1-9][0-9]{0,31}$/D',$value['start_time_ticks'])===1
	&& ($value['uid'] ?? null)===10001 && ($value['gid'] ?? null)===10001
	&& ($value['supplementary_gids'] ?? null)===[10001]
	&& $validInactiveCapabilities($value)
	&& ($value['role'] ?? null)==='realtime'
	&& ($value['listen_host'] ?? null)==='0.0.0.0' && ($value['listen_port'] ?? null)===8080
	&& ($value['parent_pid'] ?? null)===1
	&& ($value['execution_model'] ?? null)==='single-exec-realtime';

$validSchedulerPool=static fn(mixed $value): bool=>is_array($value)
	&& array_keys($value)===[
		'running','pid','start_time_ticks','uid','gid','supplementary_gids','cap_inheritable','cap_permitted','cap_eff',
		'cap_bounding','cap_ambient','no_new_privileges','role','transport','socket_path_sha256',
		'socket_device','socket_inode','socket_uid','socket_gid','socket_mode',
		'socket_directory_device','socket_directory_inode','socket_directory_uid','socket_directory_gid','socket_directory_mode',
		'parent_pid','execution_model',
	]
	&& ($value['running'] ?? null)===true && is_int($value['pid'] ?? null) && $value['pid']>1 && $value['pid']<=2147483647
	&& is_string($value['start_time_ticks'] ?? null) && preg_match('/^[1-9][0-9]{0,31}$/D',$value['start_time_ticks'])===1
	&& ($value['uid'] ?? null)===0 && ($value['gid'] ?? null)===0 && ($value['supplementary_gids'] ?? null)===[0]
	&& ($value['cap_inheritable'] ?? null)===$zero && ($value['cap_permitted'] ?? null)===$gatewayCaps
	&& ($value['cap_eff'] ?? null)===$gatewayCaps && ($value['cap_bounding'] ?? null)===$gatewayCaps
	&& ($value['cap_ambient'] ?? null)===$zero && ($value['no_new_privileges'] ?? null)===true
	&& ($value['role'] ?? null)==='scheduler' && ($value['transport'] ?? null)==='unix'
	&& ($value['socket_path_sha256'] ?? null)==='sha256:'.hash('sha256','/run/dataphyre/scheduler/gateway.sock')
	&& is_int($value['socket_device'] ?? null) && $value['socket_device']>=0 && $value['socket_device']<=PHP_INT_MAX
	&& is_int($value['socket_inode'] ?? null) && $value['socket_inode']>0 && $value['socket_inode']<=PHP_INT_MAX
	&& ($value['socket_uid'] ?? null)===0 && ($value['socket_gid'] ?? null)===0 && ($value['socket_mode'] ?? null)==='0600'
	&& is_int($value['socket_directory_device'] ?? null) && $value['socket_directory_device']>=0
	&& $value['socket_directory_device']<=PHP_INT_MAX && is_int($value['socket_directory_inode'] ?? null)
	&& $value['socket_directory_inode']>0 && $value['socket_directory_inode']<=PHP_INT_MAX
	&& ($value['socket_directory_uid'] ?? null)===0
	&& ($value['socket_directory_gid'] ?? null)===0 && ($value['socket_directory_mode'] ?? null)==='0700'
	&& ($value['parent_pid'] ?? null)===1 && ($value['execution_model'] ?? null)==='one-request-per-process-cgi';

$validControl=static fn(mixed $value): bool=>is_array($value) && array_keys($value)===[
	'transport','socket_path_sha256','socket_device','socket_inode','socket_uid','socket_gid','socket_mode',
	'socket_directory_device','socket_directory_inode','socket_directory_uid','socket_directory_gid','socket_directory_mode',
]
	&& ($value['transport'] ?? null)==='unix'
	&& ($value['socket_path_sha256'] ?? null)==='sha256:'.hash('sha256','/run/dataphyre/control/runtime.sock')
	&& is_int($value['socket_device'] ?? null) && $value['socket_device']>=0 && $value['socket_device']<=PHP_INT_MAX
	&& is_int($value['socket_inode'] ?? null) && $value['socket_inode']>0 && $value['socket_inode']<=PHP_INT_MAX
	&& ($value['socket_uid'] ?? null)===0 && ($value['socket_gid'] ?? null)===0 && ($value['socket_mode'] ?? null)==='0600'
	&& is_int($value['socket_directory_device'] ?? null) && $value['socket_directory_device']>=0
	&& $value['socket_directory_device']<=PHP_INT_MAX && is_int($value['socket_directory_inode'] ?? null)
	&& $value['socket_directory_inode']>0 && $value['socket_directory_inode']<=PHP_INT_MAX
	&& ($value['socket_directory_uid'] ?? null)===0
	&& ($value['socket_directory_gid'] ?? null)===0 && ($value['socket_directory_mode'] ?? null)==='0700';

$validWebProcess=static fn(mixed $value,string $role,int $parentPid,int $processGroupId): bool=>is_array($value)
	&& array_keys($value)===[
		'running','pid','start_time_ticks','uid','gid','supplementary_gids',
		'cap_inheritable','cap_permitted','cap_eff','cap_bounding','cap_ambient',
		'no_new_privileges','role','parent_pid','process_group_id',
	]
	&& ($value['running'] ?? null)===true
	&& is_int($value['pid'] ?? null) && $value['pid']>1 && $value['pid']<=2147483647
	&& is_string($value['start_time_ticks'] ?? null) && preg_match('/^[1-9][0-9]{0,31}$/D',$value['start_time_ticks'])===1
	&& ($value['uid'] ?? null)===10001 && ($value['gid'] ?? null)===10001
	&& ($value['supplementary_gids'] ?? null)===[10001]
	&& $validInactiveCapabilities($value)
	&& ($value['role'] ?? null)===$role && ($value['parent_pid'] ?? null)===$parentPid
	&& ($value['process_group_id'] ?? null)===$processGroupId;

$web=$decoded['web'] ?? null;$gateway=is_array($web) ? ($web['http_gateway'] ?? null) : null;
$master=is_array($web) ? ($web['fpm_master'] ?? null) : null;$workers=is_array($web) ? ($web['workers'] ?? null) : null;
$validGateway=is_array($gateway) && array_keys($gateway)===[
	'running','pid','start_time_ticks','uid','gid','supplementary_gids',
	'cap_inheritable','cap_permitted','cap_eff','cap_bounding','cap_ambient','no_new_privileges',
	'role','listen_host','listen_port','parent_pid','process_group_id',
]
	&& $validWebProcess([
		'running'=>$gateway['running'] ?? null,'pid'=>$gateway['pid'] ?? null,
		'start_time_ticks'=>$gateway['start_time_ticks'] ?? null,'uid'=>$gateway['uid'] ?? null,
		'gid'=>$gateway['gid'] ?? null,'supplementary_gids'=>$gateway['supplementary_gids'] ?? null,
		'cap_inheritable'=>$gateway['cap_inheritable'] ?? null,'cap_permitted'=>$gateway['cap_permitted'] ?? null,
		'cap_eff'=>$gateway['cap_eff'] ?? null,'cap_bounding'=>$gateway['cap_bounding'] ?? null,
		'cap_ambient'=>$gateway['cap_ambient'] ?? null,'no_new_privileges'=>$gateway['no_new_privileges'] ?? null,
		'role'=>$gateway['role'] ?? null,'parent_pid'=>$gateway['parent_pid'] ?? null,
		'process_group_id'=>$gateway['process_group_id'] ?? null,
	],'web-http-gateway',1,(int)($gateway['pid'] ?? 0))
	&& ($gateway['listen_host'] ?? null)==='127.0.0.1' && ($gateway['listen_port'] ?? null)===8083;
$validWorkers=is_array($workers) && array_is_list($workers) && count($workers)===8;
$workerPids=[];
if($validWorkers && is_array($master)){
	foreach($workers as $worker){
		if(!$validWebProcess($worker,'web-worker',(int)($master['pid'] ?? 0),(int)($master['pid'] ?? 0))){$validWorkers=false;break;}
		$workerPids[]=$worker['pid'];
	}
	$sorted=$workerPids;sort($sorted,SORT_NUMERIC);
	$validWorkers=$validWorkers && $sorted===$workerPids && count(array_unique($workerPids,SORT_NUMERIC))===8;
}
$generationPayload=is_array($master) ? json_encode([
	'contract'=>'dataphyre.managed_php_web_generation.v1',
	'environment_fingerprint'=>$decoded['environment_fingerprint'] ?? null,'generation'=>$decoded['generation'] ?? null,
	'master_pid'=>$master['pid'] ?? null,'master_start_time_ticks'=>$master['start_time_ticks'] ?? null,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) : '';
$validWeb=is_array($web) && array_keys($web)===[
	'execution_model','http_gateway','fpm_master','workers','socket_path_sha256',
	'socket_device','socket_inode','socket_uid','socket_gid','socket_mode',
	'socket_directory_device','socket_directory_inode','socket_directory_uid','socket_directory_gid','socket_directory_mode',
	'native_envelope_generation_sha256','recycle_policy',
]
	&& ($web['execution_model'] ?? null)==='persistent-php-fpm' && $validGateway
	&& is_array($master) && $validWebProcess($master,'web-pool',1,(int)($master['pid'] ?? 0)) && $validWorkers
	&& ($web['socket_path_sha256'] ?? null)==='sha256:'.hash('sha256','/run/dataphyre/web/php-fpm.sock')
	&& is_int($web['socket_device'] ?? null) && $web['socket_device']>=0 && $web['socket_device']<=PHP_INT_MAX
	&& is_int($web['socket_inode'] ?? null) && $web['socket_inode']>0 && $web['socket_inode']<=PHP_INT_MAX
	&& ($web['socket_uid'] ?? null)===10001 && ($web['socket_gid'] ?? null)===10001 && ($web['socket_mode'] ?? null)==='0600'
	&& is_int($web['socket_directory_device'] ?? null) && $web['socket_directory_device']>=0
	&& $web['socket_directory_device']<=PHP_INT_MAX && is_int($web['socket_directory_inode'] ?? null)
	&& $web['socket_directory_inode']>0 && $web['socket_directory_inode']<=PHP_INT_MAX
	&& ($web['socket_directory_uid'] ?? null)===0
	&& ($web['socket_directory_gid'] ?? null)===0 && ($web['socket_directory_mode'] ?? null)==='0711'
	&& ($web['native_envelope_generation_sha256'] ?? null)==='sha256:'.hash(
		'sha256',"dataphyre.managed_php_web_generation.v1\0".$generationPayload,
	)
	&& ($web['recycle_policy'] ?? null)===[
		'process_manager'=>'static','max_children'=>8,'max_requests'=>500,
		'request_terminate_timeout_seconds'=>300,
	];

$validRegistration=static function(mixed $value): bool {
	if(!is_array($value) || array_keys($value)!==[
		'contract','ok','registration_attempt_count','registration_accepted_count',
		'registration_failure_count','definition_count','definition_sha256',
	] || ($value['contract'] ?? null)!=='dataphyre.scheduler_registration.v1'
		|| ($value['ok'] ?? null)!==true
		|| !is_string($value['definition_sha256'] ?? null)
		|| preg_match('/^sha256:[a-f0-9]{64}$/D',$value['definition_sha256'])!==1){
		return false;
	}
	foreach([
		'registration_attempt_count','registration_accepted_count','registration_failure_count','definition_count',
	] as $key){
		if(!is_int($value[$key] ?? null) || $value[$key]<0 || $value[$key]>256) return false;
	}
	return $value['registration_failure_count']===0
		&& $value['registration_attempt_count']===$value['registration_accepted_count']
		&& $value['registration_accepted_count']===$value['definition_count'];
};

$validTimestamp=static fn(mixed $value): bool=>is_string($value)
	&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)===1;
$registration=$decoded['scheduler_registration'] ?? null;
$noop=$decoded['scheduler_noop_probe'] ?? null;
$cadence=$decoded['business_cadence'] ?? null;
$identity=[
	'contract'=>'dataphyre.scheduler_state.v1',
	'cloud_application'=>$decoded['cloud_application'] ?? null,
	'framework_application'=>$decoded['framework_application'] ?? null,
	'environment'=>$decoded['environment'] ?? null,
];
$expectedStateSha='sha256:'.hash(
	'sha256',
	"dataphyre.scheduler_state_identity.v1\0".json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
);
$noopIdentity=[
	'cloud_application'=>$decoded['cloud_application'] ?? null,
	'framework_application'=>$decoded['framework_application'] ?? null,
	'environment'=>$decoded['environment'] ?? null,
	'release_id'=>$decoded['release_id'] ?? null,
	'environment_fingerprint'=>$decoded['environment_fingerprint'] ?? null,
];
$expectedNoopSha='sha256:'.hash(
	'sha256',
	"dataphyre.scheduler_noop_probe_identity.v1\0".json_encode($noopIdentity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
);
$count=is_array($cadence) ? ($cadence['count'] ?? null) : null;
$lastAt=is_array($cadence) ? ($cadence['last_at'] ?? null) : null;
$lastResult=is_array($cadence) ? ($cadence['last_result'] ?? null) : null;
$valid=is_array($decoded)
	&& array_keys($decoded)===[
		'contract','cloud_application','framework_application','environment','release_id',
		'environment_fingerprint','generation','supervisor_pid','supervisor_uid','supervisor_gid',
		'activation_mode','active','scheduler_cycle_in_progress','control','web','scheduler','realtime',
		'scheduler_registration','scheduler_noop_probe','scheduler_state_identity_sha256','business_cadence',
	]
	&& ($decoded['contract'] ?? null)==='dataphyre.application_runtime.v6'
	&& is_string($decoded['cloud_application'] ?? null)
	&& \Dataphyre\PublicApplicationIdentifier::valid($decoded['cloud_application'])
	&& is_string($decoded['framework_application'] ?? null)
	&& preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$decoded['framework_application'])===1
	&& is_string($decoded['environment'] ?? null)
	&& \Dataphyre\ApplicationEnvironmentIdentifier::valid($decoded['environment'])
	&& is_string($decoded['release_id'] ?? null)
	&& preg_match('/^dep_[a-f0-9]{40}$/D',$decoded['release_id'])===1
	&& is_string($decoded['environment_fingerprint'] ?? null)
	&& preg_match('/^hmac-sha256:[a-f0-9]{64}$/D',$decoded['environment_fingerprint'])===1
	&& is_string($decoded['generation'] ?? null)
	&& preg_match('/^gen_[a-f0-9]{32}$/D',$decoded['generation'])===1
	&& ($decoded['supervisor_pid'] ?? null)===1
	&& ($decoded['supervisor_uid'] ?? null)===0 && ($decoded['supervisor_gid'] ?? null)===0
	&& in_array($decoded['activation_mode'] ?? null,['active','signal'],true)
	&& is_bool($decoded['active'] ?? null)
	&& is_bool($decoded['scheduler_cycle_in_progress'] ?? null)
	&& $validControl($decoded['control'] ?? null)
	&& $validWeb
	&& $validSchedulerPool($decoded['scheduler'] ?? null)
	&& $validRealtimePool($decoded['realtime'] ?? null)
	&& count(array_unique([
		$gateway['pid'],$master['pid'],...$workerPids,$decoded['scheduler']['pid'],$decoded['realtime']['pid'],
	],SORT_NUMERIC))===12
	&& $validRegistration($registration)
	&& is_array($noop) && array_keys($noop)===[
		'contract','ok','generation','request_counter','claim_consumed','worker_receipt','worker_reaped',
		'replay_suppressed','count','last_at','previous_readback','state_identity_sha256',
	]
	&& ($noop['contract'] ?? null)==='dataphyre.scheduler_noop_probe.v1'
	&& ($noop['ok'] ?? null)===true && ($noop['generation'] ?? null)===$decoded['generation']
	&& is_int($noop['request_counter'] ?? null) && $noop['request_counter']>=1 && $noop['request_counter']<=PHP_INT_MAX
	&& ($noop['claim_consumed'] ?? null)===true && ($noop['worker_receipt'] ?? null)===true
	&& ($noop['worker_reaped'] ?? null)===true && ($noop['replay_suppressed'] ?? null)===true
	&& is_int($noop['count'] ?? null) && $noop['count']>=1 && $noop['count']<=PHP_INT_MAX
	&& $validTimestamp($noop['last_at'] ?? null) && is_bool($noop['previous_readback'] ?? null)
	&& is_string($noop['state_identity_sha256'] ?? null)
	&& hash_equals($expectedNoopSha,$noop['state_identity_sha256'])
	&& is_string($decoded['scheduler_state_identity_sha256'] ?? null)
	&& hash_equals($expectedStateSha,$decoded['scheduler_state_identity_sha256'])
	&& is_array($cadence) && array_keys($cadence)===['count','last_at','last_result']
	&& is_int($count) && $count>=0 && $count<=PHP_INT_MAX
	&& (($count===0 && $lastAt===null && $lastResult==='never')
		|| ($count>0 && $validTimestamp($lastAt) && in_array($lastResult,['ok','failed'],true)));
if(!$valid) exit(65);
fwrite(STDOUT,$payload."\n");
