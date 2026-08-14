<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Dataphyre-owned PID 1 for fixed web, scheduler, and realtime pools. */

require_once __DIR__.'/application_runtime_scheduler_protocol.php';
require_once __DIR__.'/application_runtime_scheduler_state.php';
require_once __DIR__.'/application_runtime_probe_state.php';
require_once __DIR__.'/application_runtime_activation_latch.php';
require_once __DIR__.'/application_runtime_environment.php';
require_once __DIR__.'/application_runtime_process_broker.php';

function dataphyre_runtime_env(string $name, ?string $default=null): string
{
    $value=getenv($name);
    if ($value===false || trim($value)==='') {
        if ($default!==null) return $default;
        throw new RuntimeException("Missing required environment variable {$name}");
    }
    return trim($value);
}

function dataphyre_runtime_integer(string $name, int $default, int $minimum, int $maximum): int
{
    $raw=dataphyre_runtime_env($name, (string)$default);
    if (preg_match('/^[0-9]+$/D', $raw)!==1) throw new RuntimeException("{$name} must be an integer");
    $value=(int)$raw;
    if ($value<$minimum || $value>$maximum) throw new RuntimeException("{$name} is outside its fixed bounds");
    return $value;
}

function dataphyre_runtime_spawn(
	string $router,
	string $projectRoot,
	string $pool,
	string $host,
	int $port,
	array $applicationEnvironment,
	array $managedBootstrap,
): array {
	if(!in_array($pool,['web','scheduler','realtime'],true)
		|| ($pool==='web' && ($host!=='127.0.0.1' || $port!==8083))
		|| ($pool==='scheduler' && ($host!=='127.0.0.1' || $port!==8081))
		|| ($pool==='realtime' && ($host!=='0.0.0.0' || $port!==8080))
		|| is_link($router) || !is_file($router) || !hash_equals($router,(string)realpath($router))
		|| is_link($projectRoot) || !is_dir($projectRoot) || !hash_equals($projectRoot,(string)realpath($projectRoot))){
		throw new RuntimeException('Runtime pool invocation is invalid.');
	}
	if($pool!=='realtime') unset($applicationEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET']);
	$setpriv='/usr/bin/setpriv';
	$phpOptions=[
		'-d','display_errors=0','-d','log_errors=1','-d','expose_php=0',
		'-d','user_ini.filename=','-d','auto_prepend_file=','-d','auto_append_file=',
	];
	if($pool==='realtime'){
		$command=[
			$setpriv,'--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGTERM',
			PHP_BINARY,...$phpOptions,$router,$pool,$host,(string)$port,$projectRoot,
		];
	}else{
		$gateway=__DIR__.'/application_runtime_cgi_gateway.php';
		$command=[
			$setpriv,'--reuid=0','--regid=0','--groups=0','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all,+setuid,+setgid','--pdeathsig=SIGTERM',
			PHP_BINARY,...$phpOptions,$gateway,$pool,$host,(string)$port,$router,$projectRoot,
		];
	}
	$descriptors=[
		0=>['file','/dev/null','r'],
		1=>['file','php://stdout','a'],
		2=>['file','php://stderr','a'],
    ];
	$brokerRole=$pool==='realtime' ? 'realtime' : $pool.'-gateway';
	$spawned=DataphyreApplicationRuntimeProcessBroker::spawn(
		$command,$descriptors,$projectRoot,[],$brokerRole,$applicationEnvironment,10000,$managedBootstrap,
	);
	$identity=dataphyre_runtime_pool_identity($spawned['pid'],$pool,$host,$port);
	return ['resource'=>$spawned['resource'],'pid'=>$spawned['pid'],'pool'=>$pool,'identity'=>$identity];
}

function dataphyre_runtime_status(array $runtime): array
{
    return [
		'contract'=>'dataphyre.application_runtime.v4',
		'cloud_application'=>$runtime['cloud_application'],
		'framework_application'=>$runtime['framework_application'],
		'environment'=>$runtime['environment'],
		'release_id'=>$runtime['release_id'],
		'environment_fingerprint'=>$runtime['environment_fingerprint'],
		'generation'=>$runtime['generation'],
        'supervisor_pid'=>getmypid(),
        'supervisor_uid'=>function_exists('posix_geteuid') ? posix_geteuid() : -1,
        'supervisor_gid'=>function_exists('posix_getegid') ? posix_getegid() : -1,
		'activation_mode'=>$runtime['activation_mode'],
		'active'=>$runtime['active'],
		'scheduler_cycle_in_progress'=>$runtime['scheduler_cycle_in_progress'],
		'web'=>dataphyre_runtime_pool_identity($runtime['web_pid'],'web','127.0.0.1',8083),
		'scheduler'=>dataphyre_runtime_pool_identity($runtime['scheduler_pid'],'scheduler','127.0.0.1',8081),
		'realtime'=>dataphyre_runtime_pool_identity($runtime['realtime_pid'],'realtime','0.0.0.0',8080),
		'scheduler_registration'=>dataphyre_runtime_scheduler_registration_summary($runtime['scheduler_registration']),
		'scheduler_noop_probe'=>$runtime['scheduler_noop_probe'],
		'scheduler_state_identity_sha256'=>$runtime['scheduler_state_identity_sha256'],
		'business_cadence'=>[
            'count'=>$runtime['count'],
            'last_at'=>$runtime['last_at'],
            'last_result'=>$runtime['last_result'],
        ],
    ];
}

/** Keeps full task definitions root-internal while exposing bounded registration evidence. */
function dataphyre_runtime_scheduler_registration_summary(mixed $report): ?array
{
	if($report===null) return null;
	if(!dataphyre_runtime_scheduler_registration_valid($report)){
		throw new RuntimeException('Scheduler registration evidence is invalid.');
	}
	return [
		'contract'=>$report['contract'],'ok'=>$report['ok'],
		'registration_attempt_count'=>$report['registration_attempt_count'],
		'registration_accepted_count'=>$report['registration_accepted_count'],
		'registration_failure_count'=>$report['registration_failure_count'],
		'definition_count'=>$report['definition_count'],
		'definition_sha256'=>$report['definition_sha256'],
	];
}

function dataphyre_runtime_scheduler_registration_valid(mixed $report): bool
{
	if(!is_array($report) || array_keys($report)!==[
		'contract','ok','registration_attempt_count','registration_accepted_count',
		'registration_failure_count','definition_count','definition_sha256','definitions',
	] || ($report['contract'] ?? null)!=='dataphyre.scheduler_registration.v1'
		|| ($report['ok'] ?? null)!==true
		|| !is_string($report['definition_sha256'] ?? null)
		|| preg_match('/^sha256:[a-f0-9]{64}$/D',$report['definition_sha256'])!==1
		|| !is_array($report['definitions'] ?? null) || !array_is_list($report['definitions'])){
		return false;
	}
	foreach([
		'registration_attempt_count','registration_accepted_count','registration_failure_count',
		'definition_count',
	] as $key){
		if(!is_int($report[$key] ?? null) || $report[$key]<0 || $report[$key]>256){
			return false;
		}
	}
	if($report['registration_failure_count']!==0
		|| $report['registration_attempt_count']!==$report['registration_accepted_count']
		|| $report['registration_accepted_count']!==$report['definition_count']) return false;
	if($report['definition_count']!==count($report['definitions'])) return false;
	$previous='';
	foreach($report['definitions'] as $definition){
		try{
			$sha=DataphyreApplicationRuntimeSchedulerState::definitionSha256($definition);
		}catch(Throwable){return false;}
		if(!is_string($definition['name'] ?? null) || strcmp($definition['name'],$previous)<=0) return false;
		$previous=$definition['name'];
	}
	$encoded=json_encode($report['definitions'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	return hash_equals('sha256:'.hash('sha256',$encoded),$report['definition_sha256']);
}

/** Allocates one generation-local, strictly increasing signed-request counter. */
function dataphyre_runtime_next_scheduler_counter(array &$runtime): int
{
	$current=$runtime['request_counter'] ?? null;
	if(!is_int($current) || $current<0 || $current>=PHP_INT_MAX){
		throw new RuntimeException('Scheduler request counter is invalid.');
	}
	return $runtime['request_counter']=($current+1);
}

function dataphyre_runtime_pool_identity(int $pid,string $role,string $listenHost,int $listenPort): array
{
	$expectedPorts=['web'=>['127.0.0.1',8083],'scheduler'=>['127.0.0.1',8081],'realtime'=>['0.0.0.0',8080]];
	if(!isset($expectedPorts[$role]) || $expectedPorts[$role]!==[$listenHost,$listenPort]){
		throw new RuntimeException('Runtime pool role mapping is invalid');
	}
    $status=@file_get_contents('/proc/'.$pid.'/status');
	$uid=null;$gid=null;$supplementaryGids=null;$capEff=null;$noNewPrivileges=null;$parentPid=null;
    if (is_string($status)) {
        if (preg_match('/^Uid:\s+(\d+)\s+/m',$status,$matches)===1) $uid=(int)$matches[1];
        if (preg_match('/^Gid:\s+(\d+)\s+/m',$status,$matches)===1) $gid=(int)$matches[1];
        if (preg_match('/^Groups:\s*([^\r\n]*)$/m',$status,$matches)===1) {
            $supplementaryGids=array_values(array_map('intval',preg_split('/\s+/',trim($matches[1]),-1,PREG_SPLIT_NO_EMPTY) ?: []));
            sort($supplementaryGids,SORT_NUMERIC);
            $supplementaryGids=array_values(array_unique($supplementaryGids));
        }
        if (preg_match('/^CapEff:\s+([a-f0-9]+)\s*$/mi',$status,$matches)===1) $capEff=strtolower($matches[1]);
		if (preg_match('/^NoNewPrivs:\s+([01])\s*$/m',$status,$matches)===1) $noNewPrivileges=$matches[1]==='1';
		if (preg_match('/^PPid:\s+(\d+)\s*$/m',$status,$matches)===1) $parentPid=(int)$matches[1];
    }
	if ($uid===null || $gid===null || $supplementaryGids===null || $capEff===null
		|| $noNewPrivileges===null || $parentPid!==1) {
        throw new RuntimeException('Unable to attest runtime pool identity');
    }
	$gateway=in_array($role,['web','scheduler'],true);
	$expectedUid=$gateway ? 0 : 10001;$expectedGid=$gateway ? 0 : 10001;
	$expectedGroups=[$expectedGid];$expectedCapabilities=$gateway ? '00000000000000c0' : '0000000000000000';
	if($uid!==$expectedUid || $gid!==$expectedGid || $supplementaryGids!==$expectedGroups
		|| str_pad($capEff,16,'0',STR_PAD_LEFT)!==$expectedCapabilities || $noNewPrivileges!==true){
		throw new RuntimeException('Runtime pool privilege boundary is invalid');
	}
	return [
        'running'=>true,
        'pid'=>$pid,
        'uid'=>$uid,
        'gid'=>$gid,
        'supplementary_gids'=>$supplementaryGids,
        'cap_eff'=>str_pad($capEff,16,'0',STR_PAD_LEFT),
		'no_new_privileges'=>$noNewPrivileges,
		'role'=>$role,
		'listen_host'=>$listenHost,
		'listen_port'=>$listenPort,
		'parent_pid'=>$parentPid,
		'execution_model'=>$gateway ? 'one-request-per-process-cgi' : 'single-exec-realtime',
    ];
}

function dataphyre_runtime_read_private_request(mixed $connection): ?array
{
    stream_set_timeout($connection,1,0);
    $line=fgets($connection,2049);
    if (!is_string($line) || preg_match('#^(GET|POST) (/dataphyre/runtime/(?:status|scheduler/claim|realtime/probe)) HTTP/1\.[01]\r?\n$#D',$line,$matches)!==1) {
        return null;
    }
    $headers=[];
    $headerBytes=strlen($line);
	$headersComplete=false;
    while (is_string($header=fgets($connection,2049))) {
        $headerBytes+=strlen($header);
        if ($headerBytes>8192) return null;
        if ($header==="\r\n" || $header==="\n") {$headersComplete=true;break;}
        if (preg_match('/^([A-Za-z0-9-]+):\s*([^\r\n]*)\r?\n$/D',$header,$headerMatch)!==1) return null;
        $name=strtolower($headerMatch[1]);
        if (isset($headers[$name])) return null;
        $headers[$name]=$headerMatch[2];
    }
	if ($headersComplete!==true) return null;
    $body='';
    if ($matches[1]==='POST') {
        $lengthRaw=$headers['content-length'] ?? '';
        if (preg_match('/^[1-9][0-9]{0,3}$/D',$lengthRaw)!==1 || ($length=(int)$lengthRaw)>4096) return null;
        while (strlen($body)<$length) {
            $chunk=fread($connection,$length-strlen($body));
            if (!is_string($chunk) || $chunk==='') return null;
            $body.=$chunk;
        }
    }
    return ['method'=>$matches[1],'path'=>$matches[2],'body'=>$body];
}

function dataphyre_runtime_private_response(mixed $connection, int $status, array $payload): void
{
	$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	if(strlen($body)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES){
		throw new RuntimeException('Private runtime response exceeded its fixed bound.');
	}
    $reason=match($status){200=>'OK',409=>'Conflict',default=>'Not Found'};
	$response="HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	$offset=0;
	while($offset<strlen($response)){
		$written=@fwrite($connection,substr($response,$offset));
		if(!is_int($written) || $written<1) throw new RuntimeException('Private runtime response write failed.');
		$offset+=$written;
	}
}

function dataphyre_runtime_write_websocket_frame(mixed $socket, int $opcode, string $payload): void
{
    if (strlen($payload)>125) throw new RuntimeException('Realtime probe frame exceeded its fixed bound');
    $mask=random_bytes(4);
    $masked=$payload;
    for ($position=0;$position<strlen($payload);$position++) $masked[$position]=$payload[$position]^$mask[$position%4];
    $frame=chr(0x80|$opcode).chr(0x80|strlen($payload)).$mask.$masked;
    $offset=0;
    while ($offset<strlen($frame)) {
        $written=fwrite($socket,substr($frame,$offset));
        if (!is_int($written) || $written<1) throw new RuntimeException('Realtime probe frame write failed');
        $offset+=$written;
    }
}

function dataphyre_runtime_read_websocket_frame(mixed $socket, string &$buffer): array
{
    $read=static function(int $required) use ($socket,&$buffer): void {
        while (strlen($buffer)<$required) {
            $chunk=fread($socket,8192);
            if (!is_string($chunk) || $chunk==='') throw new RuntimeException('Realtime probe frame was incomplete');
            $buffer.=$chunk;
            if (strlen($buffer)>65536) throw new RuntimeException('Realtime probe frame exceeded its fixed bound');
        }
    };
    $read(2);
    $first=ord($buffer[0]);$second=ord($buffer[1]);
    if (($first&0x80)===0 || ($first&0x70)!==0 || ($second&0x80)!==0) {
        throw new RuntimeException('Realtime probe frame metadata was invalid');
    }
    $length=$second&0x7f;$offset=2;
    if ($length===126) {
        $read(4);$length=(int)unpack('nlength',substr($buffer,2,2))['length'];$offset=4;
    } elseif ($length===127) {
        $read(10);$parts=unpack('Nhigh/Nlow',substr($buffer,2,8));
        if (($parts['high'] ?? 1)!==0) throw new RuntimeException('Realtime probe frame was oversized');
        $length=(int)$parts['low'];$offset=10;
    }
    if ($length>65536) throw new RuntimeException('Realtime probe frame was oversized');
    $read($offset+$length);
    $payload=substr($buffer,$offset,$length);
    $buffer=substr($buffer,$offset+$length);
    return ['opcode'=>$first&0x0f,'payload'=>$payload];
}

function dataphyre_runtime_realtime_probe(): array
{
    $failure=[
        'contract'=>'dataphyre.application_realtime_probe.v1',
        'ok'=>false,
        'framework_listener_roundtrip'=>false,
		'application_authorization_rejections'=>false,
		'application_authorization_rejection_count'=>0,
		'registration_sha256'=>null,
		'ping_pong'=>false,
        'close_handshake'=>false,
    ];
    $socket=@stream_socket_client('tcp://127.0.0.1:8080',$errno,$error,2,STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) return $failure;
    try {
        stream_set_timeout($socket,3,0);
        $key=base64_encode(random_bytes(16));
        $request="GET /dataphyre/runtime/realtime/probe HTTP/1.1\r\n".
            "Host: 127.0.0.1:8080\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n".
            "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n".
            "Origin: https://dataphyre.invalid\r\n\r\n";
        $offset=0;
        while ($offset<strlen($request)) {
            $written=fwrite($socket,substr($request,$offset));
            if (!is_int($written) || $written<1) return $failure;
            $offset+=$written;
        }
        $buffer='';
        while (($headerEnd=strpos($buffer,"\r\n\r\n"))===false) {
            $chunk=fread($socket,4096);
            if (!is_string($chunk) || $chunk==='') return $failure;
            $buffer.=$chunk;
            if (strlen($buffer)>16384) return $failure;
        }
        $head=substr($buffer,0,$headerEnd);
        $buffer=substr($buffer,$headerEnd+4);
        $accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
        if (preg_match('/^HTTP\/1\.1 101 Switching Protocols\r\n/D',$head)!==1
            || preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept,'/').'\s*$/mi',$head)!==1) return $failure;
        $eventFrame=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        $event=json_decode($eventFrame['payload'],true);
        if ($eventFrame['opcode']!==0x1 || !is_array($event)
            || array_keys($event)!==[
				'contract','ok','framework_listener_roundtrip','application_authorization_rejections',
				'application_authorization_rejection_count','registration_sha256',
            ]
            || ($event['contract'] ?? null)!=='dataphyre.application_realtime_probe.v1'
            || ($event['ok'] ?? null)!==true || ($event['framework_listener_roundtrip'] ?? null)!==true
            || ($event['application_authorization_rejections'] ?? null)!==true
            || !is_int($event['application_authorization_rejection_count'] ?? null)
			|| $event['application_authorization_rejection_count']<0
			|| $event['application_authorization_rejection_count']>128
			|| !is_string($event['registration_sha256'] ?? null)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$event['registration_sha256'])!==1) return $failure;
        $ping=random_bytes(8);
        dataphyre_runtime_write_websocket_frame($socket,0x9,$ping);
        $pong=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        if ($pong['opcode']!==0xA || !hash_equals($ping,$pong['payload'])) return $failure;
        dataphyre_runtime_write_websocket_frame($socket,0x8,pack('n',1000));
        $close=dataphyre_runtime_read_websocket_frame($socket,$buffer);
        if ($close['opcode']!==0x8) return $failure;
        return $event+['ping_pong'=>true,'close_handshake'=>true];
    } catch (Throwable) {
        return $failure;
    } finally {
        fclose($socket);
    }
}

function dataphyre_runtime_serve_status(
    mixed $listener,
	array &$runtime,
	array &$pendingRequests,
	string $publicKey
): void {
    while (is_resource($connection=@stream_socket_accept($listener,0))) {
        $request=dataphyre_runtime_read_private_request($connection);
        if (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/status') {
            dataphyre_runtime_private_response($connection,200,dataphyre_runtime_status($runtime));
        } elseif (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/realtime/probe') {
            $probe=dataphyre_runtime_realtime_probe();
            dataphyre_runtime_private_response($connection,($probe['ok'] ?? false)===true ? 200 : 409,$probe);
		} elseif (is_array($request) && $request['method']==='POST' && $request['path']==='/dataphyre/runtime/scheduler/claim') {
			$candidate=json_decode($request['body'],true);
			$consumed=is_array($candidate)
					&& DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($candidate,$request['body'])
					&& DataphyreApplicationRuntimeSchedulerProtocol::consume($pendingRequests,$candidate,$publicKey);
			$payload=['ok'=>$consumed];
			dataphyre_runtime_private_response($connection,$consumed ? 200 : 409,$payload);
        } else {
            dataphyre_runtime_private_response($connection,404,['ok'=>false]);
        }
        fclose($connection);
    }
}

function dataphyre_runtime_scheduler_request(
    int $port,
	string $kind,
	array $identity,
	string $generation,
    int $counter,
    string $secretKey,
    string $publicKey,
    mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	?bool &$activationRequested,
	float &$nextTick,
		?string $schedulerName=null,
		?string $definitionSha256=null,
		?int $budgetMilliseconds=null,
		?array &$issuedEvidence=null,
	): array {
		$issued=DataphyreApplicationRuntimeSchedulerProtocol::issue(
		$kind,$identity,$generation,$counter,$secretKey,$schedulerName,$definitionSha256,$budgetMilliseconds,
		);
		$issuedEvidence=$issued;
	$pendingRequests[$kind.':'.$counter]=$issued;
	$body=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	$path=match($kind){
		'registration'=>'/dataphyre/runtime/scheduler/register',
		'callback'=>'/dataphyre/runtime/scheduler/callback',
		'noop'=>'/dataphyre/runtime/scheduler/noop',
	};
	$request="POST {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n".
		"Content-Type: application/json\r\n".
		"Connection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2,STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
		unset($pendingRequests[$kind.':'.$counter]);
		throw new RuntimeException('Scheduler request connection failed');
    }
    try {
        stream_set_timeout($socket,2,0);
        $offset=0;
        while ($offset<strlen($request)) {
            $written=fwrite($socket,substr($request,$offset));
			if (!is_int($written) || $written<1) throw new RuntimeException('Scheduler request write failed');
            $offset+=$written;
        }
        stream_set_blocking($socket,false);
        $response='';
		$deadline=microtime(true)+(($budgetMilliseconds ?? 3000)/1000)+2.0;
        while (microtime(true)<$deadline) {
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
			dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
            $chunk=fread($socket,8192);
            if (is_string($chunk) && $chunk!=='') {
                $response.=$chunk;
					if (strlen($response)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES) {
						throw new RuntimeException('Scheduler response exceeded its bound');
					}
            }
            if (feof($socket)) break;
            usleep(10000);
        }
		if (!feof($socket)) throw new RuntimeException('Scheduler request timed out');
        [$head,$responseBody]=array_pad(explode("\r\n\r\n",$response,2),2,'');
        $status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
        $decoded=json_decode($responseBody,true);
		if ($status===null || $status<200 || $status>=300 || !is_array($decoded)) {
			throw new RuntimeException('Scheduler request failed with HTTP status '.($status ?? 'unavailable'));
        }
		$validResponse=match($kind){
			'noop'=>($decoded['contract'] ?? null)==='dataphyre.scheduler_noop.v1'
				&& ($decoded['ok'] ?? null)===true,
			'callback'=>($decoded['contract'] ?? null)==='dataphyre.scheduler_callback.v1'
				&& ($decoded['ok'] ?? null)===true,
			'registration'=>dataphyre_runtime_scheduler_registration_valid($decoded),
		};
		if(!$validResponse) throw new RuntimeException('Scheduler response contract is invalid.');
		return $decoded;
    } finally {
        fclose($socket);
		unset($pendingRequests[$kind.':'.$counter]);
    }
}

/** Sends an already-consumed signed request again and requires the listener to reject it. */
function dataphyre_runtime_require_scheduler_replay_rejection(
	int $port,
	array $issued,
	mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	string $publicKey,
	?bool &$activationRequested,
	float &$nextTick,
): void {
	if(!DataphyreApplicationRuntimeSchedulerProtocol::verify($issued,$publicKey)
		|| !in_array($issued['kind'] ?? null,['registration','callback','noop'],true)){
		throw new RuntimeException('Scheduler replay evidence is invalid.');
	}
	$body=json_encode($issued,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$path=match($issued['kind']){
		'registration'=>'/dataphyre/runtime/scheduler/register',
		'callback'=>'/dataphyre/runtime/scheduler/callback',
		'noop'=>'/dataphyre/runtime/scheduler/noop',
	};
	$request="POST {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n".
		"Content-Type: application/json\r\n".
		"Connection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
	$socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2,STREAM_CLIENT_CONNECT);
	if(!is_resource($socket)) throw new RuntimeException('Scheduler replay connection failed.');
	try{
		stream_set_timeout($socket,2,0);
		$offset=0;
		while($offset<strlen($request)){
			$written=@fwrite($socket,substr($request,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('Scheduler replay write failed.');
			$offset+=$written;
		}
		stream_set_blocking($socket,false);
		$response='';$deadline=microtime(true)+3.0;
		while(microtime(true)<$deadline){
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
			dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
			$chunk=@fread($socket,8192);
			if(is_string($chunk) && $chunk!==''){
				$response.=$chunk;
				if(strlen($response)>16384) throw new RuntimeException('Scheduler replay response exceeded its bound.');
			}
			if(feof($socket)) break;
			usleep(10000);
		}
		if(!feof($socket)) throw new RuntimeException('Scheduler replay request timed out.');
		[$head]=array_pad(explode("\r\n\r\n",$response,2),2,'');
		if(preg_match('/^HTTP\/1\.[01]\s+404\b/D',$head)!==1){
			throw new RuntimeException('Scheduler replay was not rejected.');
		}
	}finally{fclose($socket);}
}

/** Runs one active cadence without allowing a deactivation to schedule a second tick. */
function dataphyre_runtime_run_scheduler_cycle(
	int $port,
	array $identity,
	string $generation,
	string $secretKey,
	string $publicKey,
	mixed $statusListener,
	array &$runtime,
	array &$pendingRequests,
	int $interval,
	?bool &$activationRequested,
	float &$nextTick,
	?callable $requestRunner=null,
	?callable $activationPersister=null,
): void {
	$startedAt=microtime(true);
	$runtime['scheduler_cycle_in_progress']=true;
	try{
		$cycleFailed=false;
		$requestRunner ??= 'dataphyre_runtime_scheduler_request';
		$registration=$runtime['scheduler_registration'];
		if(!dataphyre_runtime_scheduler_registration_valid($registration)){
			throw new RuntimeException('Scheduler registration evidence is invalid.');
		}
		DataphyreApplicationRuntimeSchedulerState::reconcile($identity,$registration['definitions']);
		$due=DataphyreApplicationRuntimeSchedulerState::due($identity,$registration['definitions'],time());
		foreach($due as $definition){
			dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
			if($runtime['active']!==true) break;
			$definitionSha=DataphyreApplicationRuntimeSchedulerState::definitionSha256($definition);
			$claimNonce=bin2hex(random_bytes(32));
			if(!DataphyreApplicationRuntimeSchedulerState::claim(
				$identity,$definition,$identity['release_id'],$generation,$claimNonce,time(),
			)) continue;
			try{
				$requestRunner(
					$port,'callback',$identity,$generation,dataphyre_runtime_next_scheduler_counter($runtime),$secretKey,$publicKey,
					$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,
					$definition['name'],$definitionSha,$definition['timeout_milliseconds'],
				);
				DataphyreApplicationRuntimeSchedulerState::recordSuccess(
					$identity,$definition,$identity['release_id'],$generation,time(),$claimNonce,
				);
			}catch(Throwable){
				DataphyreApplicationRuntimeSchedulerState::releaseClaim(
					$identity,$definition,$identity['release_id'],$generation,$claimNonce,
				);
				$cycleFailed=true;
			}
		}
		$runtime['last_result']=$cycleFailed ? 'failed' : 'ok';
	}catch(Throwable){
		$runtime['last_result']='failed';
	}finally{
		$runtime['scheduler_cycle_in_progress']=false;
	}
	$runtime['count']++;
	$runtime['last_at']=gmdate('Y-m-d\TH:i:s\Z');
	$nextTick=$startedAt+$interval;
	if($activationRequested!==null){
		$requested=$activationRequested;
		dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick,$activationPersister);
		if($requested!==true) $nextTick=$startedAt+$interval;
	}
}

/** Persists a pending signal transition before exposing it in status or cadence. */
function dataphyre_runtime_apply_activation_request(
	array &$runtime,
	?bool &$activationRequested,
	float &$nextTick,
	?callable $persister=null,
): void {
	if($activationRequested===null) return;
	$requested=$activationRequested;
	$activationRequested=null;
	$persister ??= [DataphyreApplicationRuntimeActivationLatch::class,'persist'];
	$persister($requested);
	$runtime['active']=$requested;
	if($requested) $nextTick=microtime(true);
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))!==__FILE__) return;
foreach([
	'pcntl_signal','posix_kill','sodium_crypto_sign_keypair',
	'dataphyre_open_inherited_environment_fd','dataphyre_close_inherited_fd',
	'dataphyre_close_unlisted_inherited_fds',
] as $requiredFunction){
	if(!function_exists($requiredFunction)){
		fwrite(STDERR,"Missing required runtime function {$requiredFunction}\n");
		exit(70);
	}
}
if (getmypid() !== 1 || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
	fwrite(STDERR,"The Dataphyre runtime supervisor must be container root PID 1\n");
	exit(77);
}

$children=[];
$statusListener=null;
$exitCode=0;
try {
	DataphyreApplicationRuntimeEnvironment::assertCleanRootEnvironment();
	$cloudApplication=dataphyre_runtime_env('DATAPHYRE_APPLICATION_ID');
	$application=dataphyre_runtime_env('DATAPHYRE_FRAMEWORK_APPLICATION');
	$environment=dataphyre_runtime_env('DATAPHYRE_ENVIRONMENT');
	$releaseId=dataphyre_runtime_env('DATAPHYRE_APPLICATION_RELEASE');
	$applicationEnvelope=DataphyreApplicationRuntimeEnvironment::consume(
		$cloudApplication,$application,$environment,$releaseId,
	);
	$applicationEnvironment=$applicationEnvelope['values'];
    $projectRoot=realpath(dataphyre_runtime_env('DATAPHYRE_RUNTIME_PROJECT_ROOT'));
    if ($projectRoot===false || !is_dir($projectRoot)) throw new RuntimeException('Runtime project root is invalid');
    $activationMode=strtolower(dataphyre_runtime_env('DATAPHYRE_RUNTIME_ACTIVATION_MODE','active'));
    if (!in_array($activationMode,['active','signal'],true)) throw new RuntimeException('Invalid runtime activation mode');
	$webHost='127.0.0.1';$webPort=8083;
	$schedulerHost='127.0.0.1';$schedulerPort=8081;
	$statusHost='127.0.0.1';$statusPort=8082;
	$realtimeHost='0.0.0.0';$realtimePort=8080;
	$interval=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_SCHEDULER_INTERVAL_SECONDS',1,1,60);
	$uid=10001;$gid=10001;
	DataphyreApplicationRuntimeEnvironment::mountedApplicationLogRoot($uid);
	DataphyreApplicationRuntimeEnvironment::mountedSchedulerStateRoot();
	$applicationDataRoot=DataphyreApplicationRuntimeEnvironment::mountedApplicationDataRoot($uid);
	$router=__DIR__.'/application_runtime_router.php';
	$realtimeServer=__DIR__.'/application_runtime_realtime_server.php';

    $keypair=sodium_crypto_sign_keypair();
    $secretKey=sodium_crypto_sign_secretkey($keypair);
    $publicKey=sodium_crypto_sign_publickey($keypair);
	$childEnvironment=DataphyreApplicationRuntimeEnvironment::childEnvironment(
		$applicationEnvironment,$cloudApplication,$application,$environment,$releaseId,$applicationDataRoot,
	);
	$childEnvironment['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$projectRoot;
	$childEnvironment['DATAPHYRE_RUNTIME_APPLICATION']=$application;
	$childEnvironment['DATAPHYRE_RUNTIME_ENVIRONMENT']=$environment;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_HOST']=$webHost;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_PORT']=(string)$webPort;
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_HOST']=$schedulerHost;
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PORT']=(string)$schedulerPort;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_HOST']=$realtimeHost;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PORT']=(string)$realtimePort;
    $childEnvironment['DATAPHYRE_SCHEDULER_SELF_ADDRESS']=$schedulerHost.':'.$schedulerPort;
    $childEnvironment['DATAPHYRE_SCHEDULER_SELF_SCHEME']='http';
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY']=sodium_bin2base64($publicKey,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	ksort($childEnvironment,SORT_STRING);

	$statusListener=@stream_socket_server('tcp://'.$statusHost.':'.$statusPort,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
	if (!is_resource($statusListener)) throw new RuntimeException('Unable to bind supervisor status listener');
	stream_set_blocking($statusListener,false);
	$managedPrivateKey=random_bytes(32);
	$managedBootstraps=[];
	try{
		foreach(['realtime','scheduler','web'] as $role){
			$managedBootstraps[$role]=DataphyreApplicationRuntimeChildEnvironment::managedBootstrapContext(
				$role,$projectRoot,$managedPrivateKey,
			);
		}
		$children[]=dataphyre_runtime_spawn(
			$realtimeServer,$projectRoot,'realtime',$realtimeHost,$realtimePort,
			$childEnvironment,$managedBootstraps['realtime'],
		);
		$children[]=dataphyre_runtime_spawn(
			$router,$projectRoot,'scheduler',$schedulerHost,$schedulerPort,
			$childEnvironment,$managedBootstraps['scheduler'],
		);
		$children[]=dataphyre_runtime_spawn(
			$router,$projectRoot,'web',$webHost,$webPort,
			$childEnvironment,$managedBootstraps['web'],
		);
	}finally{
		sodium_memzero($managedPrivateKey);
		foreach($managedBootstraps as &$managedBootstrap){
			if(is_string($managedBootstrap['private_key'] ?? null)) sodium_memzero($managedBootstrap['private_key']);
		}
		unset($managedBootstrap,$managedBootstraps,$managedPrivateKey);
	}

	$identity=[
		'cloud_application'=>$cloudApplication,
		'framework_application'=>$application,
		'environment'=>$environment,
		'release_id'=>$releaseId,
		'environment_fingerprint'=>$applicationEnvelope['environment_fingerprint'],
	];
	$generation='gen_'.bin2hex(random_bytes(16));
    $runtime=[
		'cloud_application'=>$cloudApplication,
		'framework_application'=>$application,
		'environment'=>$environment,
		'release_id'=>$releaseId,
		'environment_fingerprint'=>$applicationEnvelope['environment_fingerprint'],
		'generation'=>$generation,
        'activation_mode'=>$activationMode,
		'active'=>$activationMode==='active'
			? true
			: DataphyreApplicationRuntimeActivationLatch::restore(),
		'web_pid'=>$children[2]['pid'],
		'scheduler_pid'=>$children[1]['pid'],
		'realtime_pid'=>$children[0]['pid'],
		'count'=>0,'last_at'=>null,'last_result'=>'never','request_counter'=>0,
		'scheduler_cycle_in_progress'=>false,'scheduler_registration'=>null,
		'scheduler_noop_probe'=>null,
		'scheduler_state_identity_sha256'=>DataphyreApplicationRuntimeSchedulerState::identitySha256($identity),
    ];
    $stopping=false;
    $nextTick=microtime(true);
	$activationRequested=null;
    pcntl_async_signals(true);
	pcntl_signal(SIGUSR1,static function() use ($activationMode,&$activationRequested): void {
		if($activationMode==='signal') $activationRequested=true;
	});
	pcntl_signal(SIGUSR2,static function() use ($activationMode,&$activationRequested): void {
		if($activationMode==='signal') $activationRequested=false;
	});
    $stop=static function() use (&$stopping): void {$stopping=true;};
    pcntl_signal(SIGTERM,$stop);
    pcntl_signal(SIGINT,$stop);
    $lastLogged=null;
	$pendingRequests=[];
	$noopCounter=dataphyre_runtime_next_scheduler_counter($runtime);
	$noopIssued=null;
	dataphyre_runtime_scheduler_request(
		$schedulerPort,'noop',$identity,$generation,$noopCounter,$secretKey,$publicKey,
		$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,null,null,null,$noopIssued,
	);
	if(!is_array($noopIssued)) throw new RuntimeException('Scheduler no-op issue evidence is unavailable.');
	dataphyre_runtime_require_scheduler_replay_rejection(
		$schedulerPort,$noopIssued,$statusListener,$runtime,$pendingRequests,$publicKey,$activationRequested,$nextTick,
	);
	$probeState=DataphyreApplicationRuntimeProbeState::record($identity,time());
	$runtime['scheduler_noop_probe']=[
		'contract'=>'dataphyre.scheduler_noop_probe.v1','ok'=>true,
		'generation'=>$generation,'request_counter'=>$noopCounter,
		'claim_consumed'=>true,'worker_receipt'=>true,'worker_reaped'=>true,'replay_suppressed'=>true,
		'count'=>$probeState['count'],'last_at'=>$probeState['last_at'],
		'previous_readback'=>$probeState['previous_readback'],
		'state_identity_sha256'=>$probeState['state_identity_sha256'],
	];
	$registration=dataphyre_runtime_scheduler_request(
		$schedulerPort,'registration',$identity,$generation,dataphyre_runtime_next_scheduler_counter($runtime),
		$secretKey,$publicKey,$statusListener,$runtime,$pendingRequests,$activationRequested,$nextTick,
	);
	$runtime['scheduler_registration']=$registration;
    while (!$stopping) {
		dataphyre_runtime_apply_activation_request($runtime,$activationRequested,$nextTick);
        foreach ($children as $child) {
            $childStatus=proc_get_status($child['resource']);
            if (!is_array($childStatus) || ($childStatus['running'] ?? false)!==true) {
                $exitCode=70;
                throw new RuntimeException($child['pool'].' runtime pool exited unexpectedly');
            }
        }
		dataphyre_runtime_serve_status($statusListener,$runtime,$pendingRequests,$publicKey);
		$now=microtime(true);
		if ($runtime['active'] && $now>=$nextTick) {
			dataphyre_runtime_run_scheduler_cycle(
				$schedulerPort,$identity,$generation,$secretKey,$publicKey,$statusListener,
				$runtime,$pendingRequests,$interval,$activationRequested,$nextTick,
			);
        }
		$logKey=json_encode([
			$runtime['active'],$runtime['scheduler_cycle_in_progress'],$runtime['count'],$runtime['last_result'],
		],JSON_THROW_ON_ERROR);
        if ($logKey!==$lastLogged) {
            fwrite(STDOUT,json_encode(dataphyre_runtime_status($runtime),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            fflush(STDOUT);
            $lastLogged=$logKey;
        }
        usleep(50000);
    }
} catch (Throwable $failure) {
    fwrite(STDERR,$failure->getMessage()."\n");
    if ($exitCode===0) $exitCode=70;
} finally {
    if (is_resource($statusListener)) fclose($statusListener);
    foreach ($children as $child) @posix_kill($child['pid'],SIGTERM);
    $deadline=microtime(true)+5.0;
    foreach ($children as $child) {
        while (microtime(true)<$deadline) {
            $status=proc_get_status($child['resource']);
            if (!is_array($status) || ($status['running'] ?? false)!==true) break;
            usleep(50000);
        }
        $status=proc_get_status($child['resource']);
        if (is_array($status) && ($status['running'] ?? false)===true) @posix_kill($child['pid'],SIGKILL);
        proc_close($child['resource']);
    }
	@chown('/run/dataphyre',0);@chgrp('/run/dataphyre',0);@chmod('/run/dataphyre',0700);
}
exit($exitCode);
