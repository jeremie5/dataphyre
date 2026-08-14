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

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==1) exit(64);
$host='127.0.0.1';$port=8082;

$context=stream_context_create(['http'=>[
	'method'=>'GET','timeout'=>1.5,'ignore_errors'=>true,'header'=>"Connection: close\r\n",
]]);
$payload=@file_get_contents('http://'.$host.':'.$port.'/dataphyre/runtime/status',false,$context);
$statusCode=null;
foreach(($http_response_header ?? []) as $header){
	if(preg_match('/^HTTP\/\S+\s+(\d{3})\b/i',$header,$matches)===1){$statusCode=(int)$matches[1];break;}
}
if(!is_string($payload) || strlen($payload)>8192 || $statusCode!==200) exit(69);
try{$decoded=json_decode($payload,true,32,JSON_THROW_ON_ERROR);}
catch(Throwable){exit(65);}
if(!is_array($decoded)
	|| json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)!==$payload){
	exit(65);
}

$validPool=static fn(mixed $value,string $role,string $listenHost,int $listenPort): bool=>is_array($value)
	&& array_keys($value)===[
		'running','pid','uid','gid','supplementary_gids','cap_eff','no_new_privileges',
		'role','listen_host','listen_port','parent_pid','execution_model',
	]
	&& ($value['running'] ?? null)===true
	&& is_int($value['pid'] ?? null) && $value['pid']>1 && $value['pid']<=2147483647
	&& ($value['uid'] ?? null)===(in_array($role,['web','scheduler'],true) ? 0 : 10001)
	&& ($value['gid'] ?? null)===(in_array($role,['web','scheduler'],true) ? 0 : 10001)
	&& ($value['supplementary_gids'] ?? null)===[in_array($role,['web','scheduler'],true) ? 0 : 10001]
	&& ($value['cap_eff'] ?? null)===(in_array($role,['web','scheduler'],true) ? '00000000000000c0' : '0000000000000000')
	&& ($value['no_new_privileges'] ?? null)===true
	&& ($value['role'] ?? null)===$role
	&& ($value['listen_host'] ?? null)===$listenHost
	&& ($value['listen_port'] ?? null)===$listenPort
	&& ($value['parent_pid'] ?? null)===1
	&& ($value['execution_model'] ?? null)===(in_array($role,['web','scheduler'],true)
		? 'one-request-per-process-cgi' : 'single-exec-realtime');

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
		'activation_mode','active','scheduler_cycle_in_progress','web','scheduler','realtime',
		'scheduler_registration','scheduler_noop_probe','scheduler_state_identity_sha256','business_cadence',
	]
	&& ($decoded['contract'] ?? null)==='dataphyre.application_runtime.v4'
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
	&& $validPool($decoded['web'] ?? null,'web','127.0.0.1',8083)
	&& $validPool($decoded['scheduler'] ?? null,'scheduler','127.0.0.1',8081)
	&& $validPool($decoded['realtime'] ?? null,'realtime','0.0.0.0',8080)
	&& count(array_unique([
		$decoded['web']['pid'],$decoded['scheduler']['pid'],$decoded['realtime']['pid'],
	],SORT_NUMERIC))===3
	&& $validRegistration($registration)
	&& is_array($noop) && array_keys($noop)===[
		'contract','ok','generation','request_counter','claim_consumed','worker_receipt','worker_reaped',
		'replay_suppressed','count','last_at','previous_readback','state_identity_sha256',
	]
	&& ($noop['contract'] ?? null)==='dataphyre.scheduler_noop_probe.v1'
	&& ($noop['ok'] ?? null)===true && ($noop['generation'] ?? null)===$decoded['generation']
	&& is_int($noop['request_counter'] ?? null) && $noop['request_counter']>=1
	&& ($noop['claim_consumed'] ?? null)===true && ($noop['worker_receipt'] ?? null)===true
	&& ($noop['worker_reaped'] ?? null)===true && ($noop['replay_suppressed'] ?? null)===true
	&& is_int($noop['count'] ?? null) && $noop['count']>=1
	&& $validTimestamp($noop['last_at'] ?? null) && is_bool($noop['previous_readback'] ?? null)
	&& is_string($noop['state_identity_sha256'] ?? null)
	&& hash_equals($expectedNoopSha,$noop['state_identity_sha256'])
	&& is_string($decoded['scheduler_state_identity_sha256'] ?? null)
	&& hash_equals($expectedStateSha,$decoded['scheduler_state_identity_sha256'])
	&& is_array($cadence) && array_keys($cadence)===['count','last_at','last_result']
	&& is_int($count) && $count>=0
	&& (($count===0 && $lastAt===null && $lastResult==='never')
		|| ($count>0 && $validTimestamp($lastAt) && in_array($lastResult,['ok','failed'],true)));
if(!$valid) exit(65);
fwrite(STDOUT,$payload."\n");
