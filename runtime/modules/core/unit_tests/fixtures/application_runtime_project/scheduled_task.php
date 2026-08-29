<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$activeScheduler=class_exists('dataphyre\\scheduling',false)
	? \dataphyre\scheduling::current_scheduler_name()
	: null;
$activeCallbackStartedPath=(string)getenv('DATAPHYRE_TEST_ACTIVE_CALLBACK_STARTED_PATH');
$laterCallbackPath=(string)getenv('DATAPHYRE_TEST_ACTIVE_CALLBACK_LATER_PATH');
if($activeScheduler==='runtime.lifecycle.00-blocking' && $activeCallbackStartedPath!==''){
	$bytes=json_encode([
		'contract'=>'dataphyre.active_callback_started.v1','pid'=>getmypid(),'scheduler'=>$activeScheduler,
	],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
	$handle=@fopen($activeCallbackStartedPath,'x+b');
	if(!is_resource($handle)) throw new RuntimeException('Runtime active-callback marker is unavailable.');
	try{
		if(fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle) || !fsync($handle)
			|| !chmod($activeCallbackStartedPath,0600)){
			throw new RuntimeException('Runtime active-callback marker could not be persisted.');
		}
	}finally{fclose($handle);}
	while(true) usleep(100000);
}
if($activeScheduler==='runtime.lifecycle.01-later' && $laterCallbackPath!==''){
	file_put_contents($laterCallbackPath,'later callback executed',LOCK_EX);
}

$heartbeatPath = (string) getenv('DATAPHYRE_RUNTIME_TEST_HEARTBEAT_PATH');
if ($heartbeatPath === '') {
    throw new RuntimeException('Runtime supervisor heartbeat path is missing.');
}
file_put_contents($heartbeatPath, json_encode([
    'pid' => getmypid(),
    'at' => gmdate('Y-m-d\\TH:i:s\\Z'),
], JSON_THROW_ON_ERROR), LOCK_EX);
