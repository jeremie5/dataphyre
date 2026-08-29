#!/usr/local/bin/php
<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Exact-image PID-1 fixture: acknowledge the FPM envelope, then never create its socket. */

require_once '/workspace/dataphyre/runtime/modules/core/kernel/application_runtime_child_environment.php';

DataphyreApplicationRuntimeChildEnvironment::consumeInherited('web-pool');
if(!function_exists('posix_geteuid') || posix_geteuid()!==10001 || posix_getegid()!==10001){
	exit(70);
}
$marker='/var/log/dataphyre/blocked-fpm-started.json';
$handle=@fopen($marker,'x+b');
if(!is_resource($handle)) exit(70);
try{
	$bytes=json_encode([
		'contract'=>'dataphyre.blocked_fpm_startup_fixture.v1','pid'=>getmypid(),
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
	if(fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle) || !fsync($handle) || !chmod($marker,0600)) exit(70);
}finally{fclose($handle);}

while(true) usleep(100000);
