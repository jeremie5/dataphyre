<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==4) exit(64);
[$script,$kernel,$mode,$pidPath]=$argv;
if(!is_string($kernel) || !is_string($mode) || !is_string($pidPath)
	|| !is_file($kernel.'/application_runtime_child_environment.php')
	|| file_put_contents($pidPath,(string)getmypid(),LOCK_EX)===false){
	exit(70);
}
if($mode==='exit') exit(23);
if($mode==='stall'){sleep(30);exit(70);}
if($mode==='raw-fork-exit'){
	$descendant=pcntl_fork();
	if($descendant===-1) exit(79);
	if($descendant===0){
		pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);pcntl_signal(SIGINT,SIG_IGN);pcntl_signal(SIGHUP,SIG_IGN);
		foreach([STDIN,STDOUT,STDERR] as $stream) if(is_resource($stream)) fclose($stream);
		file_put_contents($pidPath.'.descendant',json_encode([
			'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'process_group_id'=>posix_getpgid(0),
		],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
		while(true) usleep(100000);
	}
	$deadline=microtime(true)+2.0;
	while(!is_file($pidPath.'.descendant') && microtime(true)<$deadline) usleep(10000);
	exit(is_file($pidPath.'.descendant') ? 0 : 80);
}
if(in_array($mode,['ack-exit','ack-stall','ack-fork-exit'],true)){
	require_once $kernel.'/application_runtime_child_environment.php';
	try{DataphyreApplicationRuntimeChildEnvironment::consumeInherited('one-shot');}
	catch(Throwable){exit(78);}
	if($mode==='ack-exit'){usleep(100000);exit(0);}
	if($mode==='ack-fork-exit'){
		$descendant=pcntl_fork();
		if($descendant===-1) exit(79);
		if($descendant===0){
			pcntl_async_signals(true);pcntl_signal(SIGTERM,SIG_IGN);pcntl_signal(SIGINT,SIG_IGN);pcntl_signal(SIGHUP,SIG_IGN);
			foreach([STDIN,STDOUT,STDERR] as $stream) if(is_resource($stream)) fclose($stream);
			file_put_contents($pidPath.'.descendant',json_encode([
				'pid'=>getmypid(),'parent_pid'=>posix_getppid(),'process_group_id'=>posix_getpgid(0),
			],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
			while(true) usleep(100000);
		}
		$deadline=microtime(true)+2.0;
		while(!is_file($pidPath.'.descendant') && microtime(true)<$deadline) usleep(10000);
		exit(is_file($pidPath.'.descendant') ? 0 : 80);
	}
	sleep(30);exit(70);
}
if($mode!=='consume') exit(64);

$input=stream_get_contents(STDIN);
if(!is_string($input)) exit(70);
require_once $kernel.'/application_runtime_child_environment.php';
try{$values=DataphyreApplicationRuntimeChildEnvironment::consumeInherited('one-shot');}
catch(Throwable){exit(78);}
echo json_encode([
	'contract'=>'dataphyre.application_runtime_process_broker_input_probe.v1',
	'length'=>strlen($input),'sha256'=>hash('sha256',$input),
	'environment_received'=>($values['BROKER_INPUT_PROBE'] ?? null)==='accepted',
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
if($input!=='') sodium_memzero($input);
