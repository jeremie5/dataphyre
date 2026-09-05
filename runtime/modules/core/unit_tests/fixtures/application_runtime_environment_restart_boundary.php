<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(getmypid()!==1 || !is_string($kernel) || !is_file($kernel.'/application_runtime_supervisor.php')) exit(64);
require_once $kernel.'/application_runtime_supervisor.php';
$negative=(string)($argv[2] ?? '');
if($negative==='mode') chmod('/run/dataphyre',0755);
if($negative==='owner') chown('/run/dataphyre',10001);
try{
	DataphyreApplicationRuntimeEnvironment::consume(
		'example-app','ExampleApp','production','Env:Runtime_Channel','dep_'.str_repeat('a',40),
	);
	clearstatcache(true,'/run/dataphyre');
	$consumedMode=fileperms('/run/dataphyre')&07777;
	dataphyre_runtime_prepare_web_socket();
	clearstatcache(true,'/run/dataphyre');
	echo json_encode(['ok'=>true,'consumed_mode'=>$consumedMode,'serving_mode'=>fileperms('/run/dataphyre')&07777],JSON_THROW_ON_ERROR),"\n";
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM,static function(): void {exit(0);});
	while(true) sleep(1);
}catch(RuntimeException $failure){
	echo json_encode(['ok'=>false,'error'=>$failure->getMessage()],JSON_THROW_ON_ERROR),"\n";
	exit(78);
}
