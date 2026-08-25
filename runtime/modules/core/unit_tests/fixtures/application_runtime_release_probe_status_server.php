<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__,2).'/kernel/application_runtime_supervisor.php';

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==2) exit(64);
$ready=$argv[1];
$control=dataphyre_runtime_bind_control_socket();$listener=$control['listener'];
register_shutdown_function(static function() use ($control): void {
	dataphyre_runtime_cleanup_root_socket(
		'/run/dataphyre/control','/run/dataphyre/control/runtime.sock',
		$control['identity'],$control['directory_identity'],
	);
});
if(file_put_contents($ready,"ready\n",LOCK_EX)!==6) exit(70);
$connection=@stream_socket_accept($listener,15);
if(!is_resource($connection)){fclose($listener);exit(69);}
stream_set_timeout($connection,2,0);$request='';
while(!str_contains($request,"\r\n\r\n")){
	$chunk=fread($connection,4096);
	if(!is_string($chunk) || $chunk===''){fclose($connection);fclose($listener);exit(65);}
	$request.=$chunk;
	if(strlen($request)>8192){fclose($connection);fclose($listener);exit(65);}
}
if(!str_starts_with($request,"GET /dataphyre/runtime/status HTTP/1.1\r\n")){
	fclose($connection);fclose($listener);exit(65);
}
$body=json_encode([
	'contract'=>'dataphyre.application_runtime.v7','active'=>true,
	'business_cadence'=>['count'=>1,'last_at'=>'2026-08-15T12:00:00Z','last_result'=>'ok'],
],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$response="HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}";
fwrite($connection,$response);fclose($connection);fclose($listener);exit(0);
