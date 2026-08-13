<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Dataphyre-owned PID 1 for fixed web, scheduler, and realtime pools. */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "application_runtime_supervisor.php is CLI-only\n");
    exit(64);
}
require_once __DIR__.'/application_runtime_tick_protocol.php';
foreach (['pcntl_signal', 'posix_kill', 'sodium_crypto_sign_keypair'] as $requiredFunction) {
    if (!function_exists($requiredFunction)) {
        fwrite(STDERR, "Missing required runtime function {$requiredFunction}\n");
        exit(70);
    }
}
if (getmypid() !== 1 || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "The Dataphyre runtime supervisor must be container root PID 1\n");
    exit(77);
}

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
    string $launcher,
    string $router,
    string $projectRoot,
    string $pool,
    string $host,
    int $port,
    int $uid,
    int $gid,
    array $environment
): array {
    $command=[PHP_BINARY, $launcher, $pool, $host, (string)$port, $router, (string)$uid, (string)$gid];
    $descriptors=[
        0=>['file','/dev/null','r'],
        1=>['file','php://stdout','a'],
        2=>['file','php://stderr','a'],
    ];
    $childEnvironment=$environment;
    $childEnvironment['DATAPHYRE_RUNTIME_POOL']=$pool;
    $childEnvironment['DATAPHYRE_RUNTIME_POOL_ROLE']=$pool;
	if($pool!=='realtime') unset($childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET']);
    $process=proc_open($command,$descriptors,$pipes,$projectRoot,$childEnvironment,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException("Unable to start {$pool} runtime pool");
    $status=proc_get_status($process);
    if (!is_array($status) || ($status['running'] ?? false)!==true || (int)($status['pid'] ?? 0)<2) {
        proc_close($process);
        throw new RuntimeException("{$pool} runtime pool exited during startup");
    }
    $deadline=microtime(true)+3.0;
    do {
        $identity=dataphyre_runtime_pool_identity((int)$status['pid']);
        if ($identity['uid']===$uid && $identity['gid']===$gid
            && $identity['supplementary_gids']===[$gid]
            && $identity['cap_eff']==='0000000000000000'
            && $identity['no_new_privileges']===true) {
            break;
        }
        usleep(10000);
        $status=proc_get_status($process);
        if (!is_array($status) || ($status['running'] ?? false)!==true) {
            proc_close($process);
            throw new RuntimeException("{$pool} runtime pool exited before dropping privileges");
        }
    } while (microtime(true)<$deadline);
    if ($identity['uid']!==$uid || $identity['gid']!==$gid
        || $identity['supplementary_gids']!==[$gid]
        || $identity['cap_eff']!=='0000000000000000'
        || $identity['no_new_privileges']!==true) {
        @posix_kill((int)$status['pid'],SIGKILL);
        proc_close($process);
        throw new RuntimeException("{$pool} runtime pool did not prove its privilege boundary");
    }
    return ['resource'=>$process,'pid'=>(int)$status['pid'],'pool'=>$pool];
}

function dataphyre_runtime_status(array $runtime): array
{
    return [
        'contract'=>'dataphyre.application_runtime.v1',
        'supervisor_pid'=>getmypid(),
        'supervisor_uid'=>function_exists('posix_geteuid') ? posix_geteuid() : -1,
        'supervisor_gid'=>function_exists('posix_getegid') ? posix_getegid() : -1,
        'activation_mode'=>$runtime['activation_mode'],
		'active'=>$runtime['active'],
		'web'=>dataphyre_runtime_pool_identity($runtime['web_pid']),
		'scheduler'=>dataphyre_runtime_pool_identity($runtime['scheduler_pid']),
		'realtime'=>dataphyre_runtime_pool_identity($runtime['realtime_pid']),
        'cadence'=>[
            'count'=>$runtime['count'],
            'last_at'=>$runtime['last_at'],
            'last_result'=>$runtime['last_result'],
        ],
    ];
}

function dataphyre_runtime_pool_identity(int $pid): array
{
    $status=@file_get_contents('/proc/'.$pid.'/status');
    $uid=null;$gid=null;$supplementaryGids=null;$capEff=null;$noNewPrivileges=null;
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
    }
    if ($uid===null || $gid===null || $supplementaryGids===null || $capEff===null || $noNewPrivileges===null) {
        throw new RuntimeException('Unable to attest runtime pool identity');
    }
    return [
        'running'=>true,
        'pid'=>$pid,
        'uid'=>$uid,
        'gid'=>$gid,
        'supplementary_gids'=>$supplementaryGids,
        'cap_eff'=>str_pad($capEff,16,'0',STR_PAD_LEFT),
        'no_new_privileges'=>$noNewPrivileges,
    ];
}

function dataphyre_runtime_read_private_request(mixed $connection): ?array
{
    stream_set_timeout($connection,1,0);
    $line=fgets($connection,2049);
    if (!is_string($line) || preg_match('#^(GET|POST) (/dataphyre/runtime/(?:status|tick/claim|realtime/probe)) HTTP/1\.[01]\r?\n$#D',$line,$matches)!==1) {
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
    $body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $reason=match($status){200=>'OK',409=>'Conflict',default=>'Not Found'};
    fwrite($connection,"HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
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

function dataphyre_runtime_realtime_probe(string $secret): array
{
    $failure=[
        'contract'=>'dataphyre.application_realtime_probe.v1',
        'ok'=>false,
        'framework_listener_roundtrip'=>false,
        'application_authorization_rejections'=>false,
        'application_authorization_rejection_count'=>0,
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
            "Origin: https://dataphyre.invalid\r\nX-Dataphyre-Runtime-Probe: {$secret}\r\n\r\n";
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
                'application_authorization_rejection_count',
            ]
            || ($event['contract'] ?? null)!=='dataphyre.application_realtime_probe.v1'
            || ($event['ok'] ?? null)!==true || ($event['framework_listener_roundtrip'] ?? null)!==true
            || ($event['application_authorization_rejections'] ?? null)!==true
            || !is_int($event['application_authorization_rejection_count'] ?? null)
            || $event['application_authorization_rejection_count']<0
            || $event['application_authorization_rejection_count']>128) return $failure;
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
    array $runtime,
    array &$pendingTicks,
    string $publicKey,
    string $realtimeProbeSecret
): void {
    while (is_resource($connection=@stream_socket_accept($listener,0))) {
        $request=dataphyre_runtime_read_private_request($connection);
        if (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/status') {
            dataphyre_runtime_private_response($connection,200,dataphyre_runtime_status($runtime));
        } elseif (is_array($request) && $request['method']==='GET' && $request['path']==='/dataphyre/runtime/realtime/probe') {
            $probe=dataphyre_runtime_realtime_probe($realtimeProbeSecret);
            dataphyre_runtime_private_response($connection,($probe['ok'] ?? false)===true ? 200 : 409,$probe);
        } elseif (is_array($request) && $request['method']==='POST' && $request['path']==='/dataphyre/runtime/tick/claim') {
            $candidate=json_decode($request['body'],true);
            $consumed=is_array($candidate)
                && DataphyreApplicationRuntimeTickProtocol::consume($pendingTicks,$candidate,$publicKey);
            dataphyre_runtime_private_response($connection,$consumed ? 200 : 409,['ok'=>$consumed]);
        } else {
            dataphyre_runtime_private_response($connection,404,['ok'=>false]);
        }
        fclose($connection);
    }
}

function dataphyre_runtime_tick(
    int $port,
    string $application,
    string $environment,
    int $counter,
    string $secretKey,
    string $publicKey,
    mixed $statusListener,
    array $runtime,
    array &$pendingTicks,
    string $realtimeProbeSecret
): void {
    $tick=DataphyreApplicationRuntimeTickProtocol::issue($application,$environment,$counter,$secretKey);
    $pendingTicks[$tick['counter']]=$tick;
    $headers=[
        'Content-Type: application/json',
        'Connection: close',
        'X-Dataphyre-Runtime-Tick-Timestamp: '.$tick['timestamp'],
        'X-Dataphyre-Runtime-Tick-Nonce: '.$tick['nonce'],
        'X-Dataphyre-Runtime-Tick-Counter: '.$tick['counter'],
        'X-Dataphyre-Runtime-Tick-Signature: '.$tick['signature'],
    ];
    $body='{}';
    $request="POST /dataphyre/runtime/tick HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n".
        implode("\r\n",$headers)."\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,2,STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        unset($pendingTicks[$tick['counter']]);
        throw new RuntimeException('Scheduler tick connection failed');
    }
    try {
        stream_set_timeout($socket,2,0);
        $offset=0;
        while ($offset<strlen($request)) {
            $written=fwrite($socket,substr($request,$offset));
            if (!is_int($written) || $written<1) throw new RuntimeException('Scheduler tick request write failed');
            $offset+=$written;
        }
        stream_set_blocking($socket,false);
        $response='';
        $deadline=microtime(true)+30.0;
        while (microtime(true)<$deadline) {
            dataphyre_runtime_serve_status($statusListener,$runtime,$pendingTicks,$publicKey,$realtimeProbeSecret);
            $chunk=fread($socket,8192);
            if (is_string($chunk) && $chunk!=='') {
                $response.=$chunk;
                if (strlen($response)>65536) throw new RuntimeException('Scheduler tick response exceeded its bound');
            }
            if (feof($socket)) break;
            usleep(10000);
        }
        if (!feof($socket)) throw new RuntimeException('Scheduler tick timed out');
        [$head,$responseBody]=array_pad(explode("\r\n\r\n",$response,2),2,'');
        $status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$matches)===1 ? (int)$matches[1] : null;
        $decoded=json_decode($responseBody,true);
        if ($status===null || $status<200 || $status>=300 || !is_array($decoded) || ($decoded['ok'] ?? null)!==true) {
            throw new RuntimeException('Scheduler tick failed with HTTP status '.($status ?? 'unavailable'));
        }
    } finally {
        fclose($socket);
        unset($pendingTicks[$tick['counter']]);
    }
}

$children=[];
$statusListener=null;
$exitCode=0;
try {
    $projectRoot=realpath(dataphyre_runtime_env('DATAPHYRE_RUNTIME_PROJECT_ROOT'));
    if ($projectRoot===false || !is_dir($projectRoot)) throw new RuntimeException('Runtime project root is invalid');
    $application=dataphyre_runtime_env('DATAPHYRE_RUNTIME_APPLICATION');
    $environment=dataphyre_runtime_env('DATAPHYRE_RUNTIME_ENVIRONMENT');
    if (preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1) {
        throw new RuntimeException('Invalid runtime application');
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$environment)!==1) {
        throw new RuntimeException('Invalid runtime environment');
    }
    $activationMode=strtolower(dataphyre_runtime_env('DATAPHYRE_RUNTIME_ACTIVATION_MODE','active'));
    if (!in_array($activationMode,['active','signal'],true)) throw new RuntimeException('Invalid runtime activation mode');
	$webHost=dataphyre_runtime_env('DATAPHYRE_RUNTIME_WEB_HOST','127.0.0.1');
	$webPort=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_WEB_PORT',8083,1,65535);
	$schedulerHost=dataphyre_runtime_env('DATAPHYRE_RUNTIME_SCHEDULER_HOST','127.0.0.1');
	$statusHost=dataphyre_runtime_env('DATAPHYRE_RUNTIME_STATUS_HOST','127.0.0.1');
	$realtimeHost=dataphyre_runtime_env('DATAPHYRE_RUNTIME_REALTIME_HOST','0.0.0.0');
	if ($webHost!=='127.0.0.1' || $schedulerHost!=='127.0.0.1' || $statusHost!=='127.0.0.1'
		|| $realtimeHost!=='0.0.0.0') throw new RuntimeException('Runtime hosts must match the fixed ingress boundary');
	$schedulerPort=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_SCHEDULER_PORT',8081,1,65535);
	$statusPort=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_STATUS_PORT',8082,1,65535);
	$realtimePort=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_REALTIME_PORT',8080,1,65535);
	if ($webPort!==8083 || $schedulerPort!==8081 || $statusPort!==8082 || $realtimePort!==8080) {
		throw new RuntimeException('Runtime ports must match the fixed Dataphyre contract');
	}
	if (count(array_unique([$webPort,$schedulerPort,$statusPort,$realtimePort]))!==4) throw new RuntimeException('Runtime ports must be distinct');
    $interval=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_SCHEDULER_INTERVAL_SECONDS',1,1,60);
    $uid=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_POOL_UID',10001,1,2147483647);
    $gid=dataphyre_runtime_integer('DATAPHYRE_RUNTIME_POOL_GID',10001,1,2147483647);
	$router=__DIR__.'/application_runtime_router.php';
	$realtimeServer=__DIR__.'/application_runtime_realtime_server.php';
	$launcher=__DIR__.'/application_runtime_pool_launcher.php';
	if (!is_file($router) || !is_file($realtimeServer) || !is_file($launcher)) throw new RuntimeException('Runtime launcher files are missing');

    $keypair=sodium_crypto_sign_keypair();
    $secretKey=sodium_crypto_sign_secretkey($keypair);
    $publicKey=sodium_crypto_sign_publickey($keypair);
    $realtimeProbeSecret=bin2hex(random_bytes(32));
    $childEnvironment=getenv();
    $childEnvironment=is_array($childEnvironment) ? $childEnvironment : [];
	$childEnvironment['DATAPHYRE_RUNTIME_PROJECT_ROOT']=$projectRoot;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_HOST']=$webHost;
	$childEnvironment['DATAPHYRE_RUNTIME_WEB_PORT']=(string)$webPort;
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_HOST']=$schedulerHost;
	$childEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PORT']=(string)$schedulerPort;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_HOST']=$realtimeHost;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PORT']=(string)$realtimePort;
	$childEnvironment['DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET']=$realtimeProbeSecret;
    $childEnvironment['DATAPHYRE_SCHEDULER_SELF_ADDRESS']=$schedulerHost.':'.$schedulerPort;
    $childEnvironment['DATAPHYRE_SCHEDULER_SELF_SCHEME']='http';
    $childEnvironment['DATAPHYRE_RUNTIME_TICK_PUBLIC_KEY']=sodium_bin2base64($publicKey,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    unset($childEnvironment['DATAPHYRE_RUNTIME_STATUS_HOST'],$childEnvironment['DATAPHYRE_RUNTIME_STATUS_PORT']);

    $statusListener=@stream_socket_server('tcp://'.$statusHost.':'.$statusPort,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
    if (!is_resource($statusListener)) throw new RuntimeException('Unable to bind supervisor status listener');
    stream_set_blocking($statusListener,false);
	$children[]=dataphyre_runtime_spawn($launcher,$router,$projectRoot,'web',$webHost,$webPort,$uid,$gid,$childEnvironment);
	$children[]=dataphyre_runtime_spawn($launcher,$router,$projectRoot,'scheduler',$schedulerHost,$schedulerPort,$uid,$gid,$childEnvironment);
	$children[]=dataphyre_runtime_spawn($launcher,$realtimeServer,$projectRoot,'realtime',$realtimeHost,$realtimePort,$uid,$gid,$childEnvironment);

    $runtime=[
        'activation_mode'=>$activationMode,
        'active'=>$activationMode==='active',
		'web_pid'=>$children[0]['pid'],
		'scheduler_pid'=>$children[1]['pid'],
		'realtime_pid'=>$children[2]['pid'],
        'count'=>0,'last_at'=>null,'last_result'=>'never',
    ];
    $stopping=false;
    $nextTick=microtime(true);
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1,static function() use (&$runtime,&$nextTick): void {$runtime['active']=true;$nextTick=microtime(true);});
    pcntl_signal(SIGUSR2,static function() use (&$runtime): void {$runtime['active']=false;});
    $stop=static function() use (&$stopping): void {$stopping=true;};
    pcntl_signal(SIGTERM,$stop);
    pcntl_signal(SIGINT,$stop);
    $lastLogged=null;
    $pendingTicks=[];
    while (!$stopping) {
        foreach ($children as $child) {
            $childStatus=proc_get_status($child['resource']);
            if (!is_array($childStatus) || ($childStatus['running'] ?? false)!==true) {
                $exitCode=70;
                throw new RuntimeException($child['pool'].' runtime pool exited unexpectedly');
            }
        }
        dataphyre_runtime_serve_status($statusListener,$runtime,$pendingTicks,$publicKey,$realtimeProbeSecret);
        $now=microtime(true);
        if ($runtime['active'] && $now>=$nextTick) {
            $counter=$runtime['count']+1;
            try {
                dataphyre_runtime_tick(
                    $schedulerPort,$application,$environment,$counter,$secretKey,$publicKey,
                    $statusListener,$runtime,$pendingTicks,$realtimeProbeSecret,
                );
                $runtime['last_result']='ok';
            }
            catch (Throwable) {$runtime['last_result']='failed';}
            $runtime['count']=$counter;
            $runtime['last_at']=gmdate('Y-m-d\TH:i:s\Z');
            $nextTick=$now+$interval;
        }
        $logKey=json_encode([$runtime['active'],$runtime['count'],$runtime['last_result']],JSON_THROW_ON_ERROR);
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
}
exit($exitCode);
