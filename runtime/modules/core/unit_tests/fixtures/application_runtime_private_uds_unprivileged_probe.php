<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==1 || !function_exists('posix_geteuid')
	|| posix_geteuid()!==10001 || posix_getegid()!==10001) exit(64);

$schedulerAccepted=0;$controlAccepted=0;
for($attempt=0;$attempt<64;$attempt++){
	$scheduler=@stream_socket_client(
		'unix:///run/dataphyre/scheduler/gateway.sock',$errorNumber,$error,0.05,STREAM_CLIENT_CONNECT,
	);
	if(is_resource($scheduler)){$schedulerAccepted++;fclose($scheduler);}
	$control=@stream_socket_client(
		'unix:///run/dataphyre/control/runtime.sock',$errorNumber,$error,0.05,STREAM_CLIENT_CONNECT,
	);
	if(is_resource($control)){$controlAccepted++;fclose($control);}
}
fwrite(STDOUT,json_encode([
	'contract'=>'dataphyre.private_uds_unprivileged_probe.v1','scheduler_attempt_count'=>64,
	'scheduler_accepted_count'=>$schedulerAccepted,'control_attempt_count'=>64,
	'control_accepted_count'=>$controlAccepted,
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
