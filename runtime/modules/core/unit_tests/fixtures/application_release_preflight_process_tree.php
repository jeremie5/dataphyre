<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || !function_exists('pcntl_fork') || !function_exists('pcntl_signal')) exit(64);
$pidFile=getenv('DATAPHYRE_TEST_PROCESS_TREE_PID_FILE');
if(!is_string($pidFile) || $pidFile==='' || is_link($pidFile)) exit(64);

pcntl_async_signals(true);
pcntl_signal(SIGTERM,SIG_IGN);
$child=pcntl_fork();
if($child<0) exit(70);
if($child===0){
	pcntl_signal(SIGTERM,SIG_IGN);
	$stdout=str_repeat('O',8192);$stderr=str_repeat('E',8192);
	while(true){
		@fwrite(STDOUT,$stdout);
		@fwrite(STDERR,$stderr);
		usleep(1000);
	}
}

file_put_contents($pidFile,getmypid()."\n".$child."\n",LOCK_EX);
$stdout=str_repeat('P',8192);$stderr=str_repeat('Q',8192);
while(true){
	@fwrite(STDOUT,$stdout);
	@fwrite(STDERR,$stderr);
	usleep(1000);
}
