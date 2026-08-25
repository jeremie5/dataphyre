<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function dataphyre_test_application_runtime_status_v7_max_payload(): string
{
	$integer=PHP_INT_MAX;$ticks=str_repeat('9',32);$zero='0000000000000000';$inactiveCeiling='00000000000000e0';
	$deployment=str_repeat('a',120);$framework=str_repeat('f',128);$environment=str_repeat('e',128);
	$release='dep_'.str_repeat('f',40);$fingerprint='hmac-sha256:'.str_repeat('f',64);$generation='gen_'.str_repeat('f',32);
	$socket=static fn(string $path,string $mode,string $directoryMode,int $uid,int $gid): array=>[
		'transport'=>'unix','socket_path_sha256'=>'sha256:'.hash('sha256',$path),
		'socket_device'=>$integer,'socket_inode'=>$integer,'socket_uid'=>$uid,'socket_gid'=>$gid,'socket_mode'=>$mode,
		'socket_directory_device'=>$integer,'socket_directory_inode'=>$integer,
		'socket_directory_uid'=>0,'socket_directory_gid'=>0,'socket_directory_mode'=>$directoryMode,
	];
	$webProcess=static fn(int $pid,string $role,int $parentPid,int $processGroupId): array=>[
		'running'=>true,'pid'=>$pid,'start_time_ticks'=>$ticks,'uid'=>10001,'gid'=>10001,'supplementary_gids'=>[10001],
		'cap_inheritable'=>$zero,'cap_permitted'=>$zero,'cap_eff'=>$zero,'cap_bounding'=>$inactiveCeiling,'cap_ambient'=>$zero,
		'no_new_privileges'=>true,'role'=>$role,'parent_pid'=>$parentPid,'process_group_id'=>$processGroupId,
	];
	$gatewayPid=2147483636;$masterPid=2147483637;
	$gateway=[
		'running'=>true,'pid'=>$gatewayPid,'start_time_ticks'=>$ticks,'uid'=>10001,'gid'=>10001,'supplementary_gids'=>[10001],
		'cap_inheritable'=>$zero,'cap_permitted'=>$zero,'cap_eff'=>$zero,'cap_bounding'=>$inactiveCeiling,'cap_ambient'=>$zero,
		'no_new_privileges'=>true,'role'=>'web-http-gateway','listen_host'=>'127.0.0.1','listen_port'=>8083,
		'parent_pid'=>1,'process_group_id'=>$gatewayPid,
	];
	$master=$webProcess($masterPid,'web-pool',1,$masterPid);$workers=[];
	for($pid=2147483638;$pid<=2147483645;$pid++) $workers[]=$webProcess($pid,'web-worker',$masterPid,$masterPid);
	$generationPayload=json_encode([
		'contract'=>'dataphyre.managed_php_web_generation.v1','environment_fingerprint'=>$fingerprint,
		'generation'=>$generation,'master_pid'=>$masterPid,'master_start_time_ticks'=>$ticks,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$identity=[
		'contract'=>'dataphyre.scheduler_state.v2','deployment_application'=>$deployment,
		'framework_application'=>$framework,'environment'=>$environment,
	];
	$noopIdentity=[
		'deployment_application'=>$deployment,'framework_application'=>$framework,'environment'=>$environment,
		'release_id'=>$release,'environment_fingerprint'=>$fingerprint,
	];
	$control=$socket('/run/dataphyre/control/runtime.sock','0600','0700',0,0);
	$schedulerSocket=$socket('/run/dataphyre/scheduler/gateway.sock','0600','0700',0,0);
	$webSocket=$socket('/run/dataphyre/web/php-fpm.sock','0600','0711',10001,10001);
	$payload=json_encode([
		'contract'=>'dataphyre.application_runtime.v7','deployment_application'=>$deployment,'framework_application'=>$framework,
		'environment'=>$environment,'release_id'=>$release,'environment_fingerprint'=>$fingerprint,'generation'=>$generation,
		'supervisor_pid'=>1,'supervisor_uid'=>0,'supervisor_gid'=>0,'activation_mode'=>'active','active'=>false,
		'scheduler_cycle_in_progress'=>false,'control'=>$control,
		'web'=>[
			'execution_model'=>'persistent-php-fpm','http_gateway'=>$gateway,'fpm_master'=>$master,'workers'=>$workers,
			'socket_path_sha256'=>$webSocket['socket_path_sha256'],'socket_device'=>$integer,'socket_inode'=>$integer,
			'socket_uid'=>10001,'socket_gid'=>10001,'socket_mode'=>'0600',
			'socket_directory_device'=>$integer,'socket_directory_inode'=>$integer,
			'socket_directory_uid'=>0,'socket_directory_gid'=>0,'socket_directory_mode'=>'0711',
			'native_envelope_generation_sha256'=>'sha256:'.hash(
				'sha256',"dataphyre.managed_php_web_generation.v1\0".$generationPayload,
			),
			'recycle_policy'=>[
				'process_manager'=>'static','max_children'=>8,'max_requests'=>500,
				'request_terminate_timeout_seconds'=>300,
			],
		],
		'scheduler'=>[
			'running'=>true,'pid'=>2147483646,'start_time_ticks'=>$ticks,'uid'=>0,'gid'=>0,'supplementary_gids'=>[0],
			'cap_inheritable'=>$zero,'cap_permitted'=>'00000000000000e0','cap_eff'=>'00000000000000e0',
			'cap_bounding'=>'00000000000000e0','cap_ambient'=>$zero,'no_new_privileges'=>true,'role'=>'scheduler',
			...$schedulerSocket,'parent_pid'=>1,'execution_model'=>'one-request-per-process-cgi',
		],
		'realtime'=>[
			'running'=>true,'pid'=>2147483647,'start_time_ticks'=>$ticks,'uid'=>10001,'gid'=>10001,'supplementary_gids'=>[10001],
			'cap_inheritable'=>$zero,'cap_permitted'=>$zero,'cap_eff'=>$zero,'cap_bounding'=>$inactiveCeiling,'cap_ambient'=>$zero,
			'no_new_privileges'=>true,'role'=>'realtime','listen_host'=>'0.0.0.0','listen_port'=>8080,
			'parent_pid'=>1,'execution_model'=>'single-exec-realtime',
		],
		'scheduler_registration'=>[
			'contract'=>'dataphyre.scheduler_registration.v1','ok'=>true,'registration_attempt_count'=>256,
			'registration_accepted_count'=>256,'registration_failure_count'=>0,'definition_count'=>256,
			'definition_sha256'=>'sha256:'.str_repeat('f',64),
		],
		'scheduler_noop_probe'=>[
			'contract'=>'dataphyre.scheduler_noop_probe.v2','ok'=>true,'generation'=>$generation,
			'request_counter'=>$integer,'claim_consumed'=>true,'worker_receipt'=>true,'worker_reaped'=>true,
			'replay_suppressed'=>true,'count'=>$integer,'last_at'=>'9999-12-31T23:59:59Z','previous_readback'=>false,
			'state_identity_sha256'=>'sha256:'.hash(
				'sha256',"dataphyre.scheduler_noop_probe_identity.v2\0".json_encode(
					$noopIdentity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR,
				),
			),
		],
		'scheduler_state_identity_sha256'=>'sha256:'.hash(
			'sha256',"dataphyre.scheduler_state_identity.v2\0".json_encode(
				$identity,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR,
			),
		),
		'business_cadence'=>[
			'count'=>$integer,'last_at'=>'9999-12-31T23:59:59Z','last_result'=>'failed',
		],
	],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	if(strlen($payload)!==8341) throw new LogicException('Canonical v7 status payload length drifted.');
	return $payload;
}
