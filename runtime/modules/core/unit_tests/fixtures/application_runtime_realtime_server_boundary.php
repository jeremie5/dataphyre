<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
$project=realpath((string)($argv[2] ?? ''));
$runtimeRoot=realpath((string)($argv[3] ?? ''));
$stateRoot=realpath((string)($argv[4] ?? ''));
if(!is_string($kernel) || !is_string($project) || !is_string($runtimeRoot) || !is_string($stateRoot)) exit(64);
require_once $kernel.'/application_runtime_realtime_server.php';

$authorize=static fn(array $handshake): false=>false;
$events=static fn(array $authorization,?string $cursor): array=>['cursor'=>$cursor,'events'=>[]];
$probeConflict=false;$reservedOrigin=false;
try{
	new DataphyreApplicationRuntimeRealtimeServer([
		'/dataphyre/runtime/realtime/probe'=>['authorize'=>$authorize,'events'=>$events],
	]);
}catch(RuntimeException){$probeConflict=true;}
try{
	new DataphyreApplicationRuntimeRealtimeServer([
		'/application'=>['authorize'=>static fn(array $handshake): bool=>true,'events'=>$events],
	]);
}catch(RuntimeException){$reservedOrigin=true;}

putenv('DATAPHYRE_RUNTIME_POOL=web');
$wrongPool=DataphyreApplicationRuntimeRealtimeServer::main();
putenv('DATAPHYRE_RUNTIME_POOL=realtime');
putenv('DATAPHYRE_RUNTIME_REALTIME_HOST=127.0.0.1');
putenv('DATAPHYRE_RUNTIME_REALTIME_PORT=8080');
putenv('DATAPHYRE_RUNTIME_WEB_HOST=127.0.0.1');
putenv('DATAPHYRE_RUNTIME_WEB_PORT=8083');
$wrongAddress=DataphyreApplicationRuntimeRealtimeServer::main();

putenv('DATAPHYRE_RUNTIME_REALTIME_HOST=0.0.0.0');
putenv('DATAPHYRE_RUNTIME_PROJECT_ROOT='.$project.'/missing');
putenv('DATAPHYRE_RUNTIME_APPLICATION=_Runtime$Probe');
putenv('DATAPHYRE_RUNTIME_ENVIRONMENT=staging_blue');
$invalidBootstrap=DataphyreApplicationRuntimeRealtimeServer::main();

putenv('DATAPHYRE_RUNTIME_PROJECT_ROOT='.$project);
putenv('DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT='.$runtimeRoot);
putenv('DATAPHYRE_RUNTIME_TEST_STATE_ROOT='.$stateRoot);
putenv('DATAPHYRE_SCHEDULER_STATE_ROOT='.$stateRoot);
putenv('DATAPHYRE_RUNTIME_TEST_REALTIME_TOKEN=runtime-fixture-token');
$listener=@stream_socket_server('tcp://0.0.0.0:8080',$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
$bindFailure=DataphyreApplicationRuntimeRealtimeServer::main();
if(is_resource($listener)) fclose($listener);

$parseRejections=[];
foreach([
	"TRACE / HTTP/1.1\r\nHost: example.test\r\n\r\n",
	"GET / HTTP/1.1\r\n\r\n",
	"GET / HTTP/1.1\r\nHost: example.test\r\nContent-Length: invalid\r\n\r\n",
	"GET / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: gzip\r\n\r\n",
] as $request){
	$parseRejections[]=DataphyreApplicationRuntimeRealtimeServer::parseRequest($request)===null;
}

echo json_encode(compact(
	'probeConflict','reservedOrigin','wrongPool','wrongAddress','invalidBootstrap','bindFailure','parseRejections',
),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
