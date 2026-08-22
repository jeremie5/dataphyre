<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==3) exit(64);
require_once dirname(__DIR__,2).'/kernel/application_runtime_child_environment.php';
$role=(string)$argv[1];$expectedSha=(string)$argv[2];
try{
	$values=DataphyreApplicationRuntimeChildEnvironment::consumeInherited($role);
	$secret=$values['PROBE_SECRET'] ?? null;
	$refetchRejected=false;
	try{DataphyreApplicationRuntimeChildEnvironment::consumeInherited($role);}
	catch(Throwable){$refetchRejected=true;}
	$descriptorNames=@scandir('/proc/self/fd');
	$descriptorClosed=is_array($descriptorNames)
		&& !in_array((string)DataphyreApplicationRuntimeChildEnvironment::INHERITED_FD,$descriptorNames,true);
	$environ=(string)@file_get_contents('/proc/self/environ');
	$cmdline=(string)@file_get_contents('/proc/self/cmdline');
	$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());
	$result=[
		'ok'=>is_string($secret) && hash_equals($expectedSha,hash('sha256',$secret)),
		'refetch_rejected'=>$refetchRejected,'descriptor_closed'=>$descriptorClosed,
		'secret_absent_from_proc'=>is_string($secret) && !str_contains($environ,$secret) && !str_contains($cmdline,$secret),
		'pre_exec_closer_rejected'=>dataphyre_close_unlisted_inherited_fds()===false,
		'no_new_privileges'=>$identity['no_new_privileges'],
		'cap_inheritable'=>$identity['cap_inheritable'],'cap_permitted'=>$identity['cap_permitted'],
		'cap_eff'=>$identity['cap_eff'],'cap_bounding'=>$identity['cap_bounding'],
		'cap_ambient'=>$identity['cap_ambient'],
		'uid'=>$identity['uid'],'gid'=>$identity['gid'],'groups'=>$identity['groups'],
	];
	if(is_string($secret)) sodium_memzero($secret);
	echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit(0);
}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage());exit(78);}
