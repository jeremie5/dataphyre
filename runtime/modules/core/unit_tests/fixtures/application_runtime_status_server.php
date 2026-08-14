<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$ready=(string)($argv[1] ?? '');
$responses=array_slice($argv,2);
if($ready==='' || $responses===[]){
	fwrite(STDERR,"Status server fixture arguments are invalid.\n");
	exit(64);
}
$listener=@stream_socket_server('tcp://127.0.0.1:8082',$errorNumber,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if(!is_resource($listener)){
	fwrite(STDERR,"Status server fixture could not bind: {$errorNumber} {$error}\n");
	exit(69);
}
if(file_put_contents($ready,"ready\n",LOCK_EX)===false){
	fclose($listener);
	exit(70);
}
foreach($responses as $response){
	$body=is_string($response) && is_file($response) ? file_get_contents($response) : false;
	if(!is_string($body)){
		fclose($listener);
		exit(70);
	}
	$connection=@stream_socket_accept($listener,5);
	if(!is_resource($connection)){
		fclose($listener);
		exit(69);
	}
	stream_set_timeout($connection,2,0);
	$request='';
	while(!str_contains($request,"\r\n\r\n") && strlen($request)<=16384){
		$chunk=fread($connection,4096);
		if(!is_string($chunk) || $chunk==='') break;
		$request.=$chunk;
	}
	if(!str_starts_with($request,'GET /dataphyre/runtime/')){
		fclose($connection);
		fclose($listener);
		exit(70);
	}
	$wire="HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
	$offset=0;
	while($offset<strlen($wire)){
		$written=fwrite($connection,substr($wire,$offset));
		if(!is_int($written) || $written<1){
			fclose($connection);
			fclose($listener);
			exit(70);
		}
		$offset+=$written;
	}
	fclose($connection);
}
fclose($listener);
