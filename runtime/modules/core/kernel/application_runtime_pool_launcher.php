<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed privilege-dropping launcher for Dataphyre-owned PHP runtime pools. */

if (PHP_SAPI!=='cli' || $argc!==7 || !function_exists('pcntl_exec') || !function_exists('posix_setuid')) exit(64);
[$script,$pool,$host,$port,$router,$uidRaw,$gidRaw]=$argv;
if (!in_array($pool,['web','scheduler','realtime'],true)
	|| ($pool==='web' && ($host!=='127.0.0.1' || $port!=='8083'))
	|| ($pool==='scheduler' && ($host!=='127.0.0.1' || $port!=='8081'))
	|| ($pool==='realtime' && ($host!=='0.0.0.0' || $port!=='8080'))
	|| preg_match('/^[0-9]+$/D',$port)!==1 || (int)$port<1 || (int)$port>65535
	|| !is_file($router)
    || preg_match('/^[0-9]+$/D',$uidRaw)!==1 || preg_match('/^[0-9]+$/D',$gidRaw)!==1) exit(64);
$uid=(int)$uidRaw;$gid=(int)$gidRaw;
if ($uid<1 || $gid<1 || $uid>2147483647 || $gid>2147483647) exit(64);
if (function_exists('posix_initgroups') && !posix_initgroups('dataphyre',$gid)) exit(77);
if (!posix_setgid($gid) || !posix_setuid($uid) || posix_geteuid()!==$uid || posix_getegid()!==$gid) exit(77);
if ($pool==='scheduler') putenv('PHP_CLI_SERVER_WORKERS=3');
else putenv('PHP_CLI_SERVER_WORKERS');
if($pool==='realtime'){
	if(basename($router)!=='application_runtime_realtime_server.php') exit(64);
	pcntl_exec(PHP_BINARY,[$router],getenv());
}else{
	if(basename($router)!=='application_runtime_router.php') exit(64);
	pcntl_exec(PHP_BINARY,['-S',$host.':'.$port,$router],getenv());
}
exit(70);
