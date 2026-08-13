<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$failure=[
	'contract'=>'dataphyre.application_realtime_probe.v1',
	'ok'=>false,
	'framework_listener_roundtrip'=>false,
	'application_authorization_rejections'=>false,
	'application_authorization_rejection_count'=>0,
	'ping_pong'=>false,
	'close_handshake'=>false,
];
$write=static function(array $payload): void {
	fwrite(STDOUT,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
};
if(!in_array(PHP_SAPI,['cli','phpdbg'],true) || ($argc ?? 0)!==1){
	$write($failure);
	exit(64);
}
$socket=@stream_socket_client('tcp://127.0.0.1:8082',$errno,$error,2,STREAM_CLIENT_CONNECT);
if(!is_resource($socket)){
	$write($failure);
	exit(69);
}
try{
	stream_set_timeout($socket,5,0);
	$request="GET /dataphyre/runtime/realtime/probe HTTP/1.1\r\nHost: 127.0.0.1:8082\r\nConnection: close\r\n\r\n";
	$offset=0;
	while($offset<strlen($request)){
		$written=fwrite($socket,substr($request,$offset));
		if(!is_int($written) || $written<1){
			$write($failure);
			exit(70);
		}
		$offset+=$written;
	}
	$response='';
	while(!feof($socket)){
		$chunk=fread($socket,4096);
		if(!is_string($chunk) || $chunk==='') break;
		$response.=$chunk;
		if(strlen($response)>8192){
			$write($failure);
			exit(70);
		}
	}
}finally{
	fclose($socket);
}
[$head,$body]=array_pad(explode("\r\n\r\n",$response,2),2,'');
$status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
$payload=json_decode($body,true);
$valid=is_array($payload)
	&& array_keys($payload)===[
		'contract','ok','framework_listener_roundtrip','application_authorization_rejections',
		'application_authorization_rejection_count','ping_pong','close_handshake',
	]
	&& ($payload['contract'] ?? null)==='dataphyre.application_realtime_probe.v1'
	&& is_bool($payload['ok'] ?? null)
	&& is_bool($payload['framework_listener_roundtrip'] ?? null)
	&& is_bool($payload['application_authorization_rejections'] ?? null)
	&& is_int($payload['application_authorization_rejection_count'] ?? null)
	&& $payload['application_authorization_rejection_count']>=0
	&& $payload['application_authorization_rejection_count']<=128
	&& is_bool($payload['ping_pong'] ?? null)
	&& is_bool($payload['close_handshake'] ?? null);
if(!$valid){
	$write($failure);
	exit(70);
}
$write($payload);
$passed=$status===200
	&& $payload['ok']===true
	&& $payload['framework_listener_roundtrip']===true
	&& $payload['application_authorization_rejections']===true
	&& $payload['ping_pong']===true
	&& $payload['close_handshake']===true;
exit($passed ? 0 : 70);
