<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_realtime_bootstrap.php';

/** Fixed public HTTP ingress and authenticated WebSocket runtime. */
final class DataphyreApplicationRuntimeRealtimeServer {
	private const PROBE_PATH='/dataphyre/runtime/realtime/probe';
	private const PUBLIC_HOST='0.0.0.0';
	private const PUBLIC_PORT=8080;
	private const WEB_HOST='127.0.0.1';
	private const WEB_PORT=8083;
	private const MAX_CONNECTIONS=256;
	private const MAX_HEADER_BYTES=16384;
	private const MAX_HEADER_LINE_BYTES=4096;
	private const MAX_HEADERS=64;
	private const MAX_PROXY_BUFFER_BYTES=1048576;
	private const MAX_WEBSOCKET_BUFFER_BYTES=262144;
	private const MAX_FRAME_BYTES=65536;
	private const MAX_AUTHORIZATION_BYTES=8192;
	private const MAX_EVENTS_PER_POLL=64;
	private const HEADER_TIMEOUT_SECONDS=5.0;
	private const AUTHORIZATION_TIMEOUT_SECONDS=2.0;
	private const EVENT_TIMEOUT_SECONDS=2.0;
	private const EVENT_INTERVAL_SECONDS=0.25;
	private const PING_INTERVAL_SECONDS=30.0;
	private const PONG_TIMEOUT_SECONDS=10.0;

	/** @var array<string,array{authorize:callable,events:callable}> */
	private array $routes;
	/** @var array<int,array<string,mixed>> */
	private array $clients=[];
	private mixed $listener=null;
	private bool $stopping=false;
	private int $applicationRejectionCount=0;

	/** @param array<string,array{authorize:callable,events:callable}> $routes */
	public function __construct(array $routes) {
		$probeSecret=trim((string)(getenv('DATAPHYRE_RUNTIME_REALTIME_PROBE_SECRET') ?: ''));
		if(preg_match('/^[a-f0-9]{64}$/D',$probeSecret)!==1){
			throw new RuntimeException('Realtime runtime probe secret is unavailable.');
		}
		if(isset($routes[self::PROBE_PATH])){
			throw new RuntimeException('Application realtime path conflicts with the fixed framework probe.');
		}
		foreach($routes as $path=>$route){
			$rejected=self::runBoundedCallback($route['authorize'], [[
				'path'=>$path,
				'origin'=>'https://dataphyre.invalid',
				'headers'=>['host'=>'127.0.0.1','origin'=>'https://dataphyre.invalid'],
				'query'=>[],
				'remote_address'=>'127.0.0.1',
			]], self::AUTHORIZATION_TIMEOUT_SECONDS);
			if($rejected!==false){
				throw new RuntimeException('Application realtime authorization accepted the reserved invalid probe origin.');
			}
			$this->applicationRejectionCount++;
		}
		$routes[self::PROBE_PATH]=[
			'authorize'=>static fn(array $handshake): array|false=>(
				($handshake['remote_address'] ?? null)==='127.0.0.1'
				&& ($handshake['origin'] ?? null)==='https://dataphyre.invalid'
				&& hash_equals($probeSecret,(string)($handshake['headers']['x-dataphyre-runtime-probe'] ?? ''))
			) ? ['framework_probe'=>true] : false,
			'events'=>fn(array $authorization,?string $cursor): array=>[
				'cursor'=>'complete',
				'events'=>$cursor===null && ($authorization['framework_probe'] ?? null)===true
					? [[
						'contract'=>'dataphyre.application_realtime_probe.v1',
						'ok'=>true,
						'framework_listener_roundtrip'=>true,
						'application_authorization_rejections'=>true,
						'application_authorization_rejection_count'=>$this->applicationRejectionCount,
					]]
					: [],
			],
		];
		$this->routes=$routes;
	}

	public static function main(): int {
		if(PHP_SAPI!=='cli' || (string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='realtime'){
			return 64;
		}
		if((string)(getenv('DATAPHYRE_RUNTIME_REALTIME_HOST') ?: '')!==self::PUBLIC_HOST
			|| (string)(getenv('DATAPHYRE_RUNTIME_REALTIME_PORT') ?: '')!==(string)self::PUBLIC_PORT
			|| (string)(getenv('DATAPHYRE_RUNTIME_WEB_HOST') ?: '')!==self::WEB_HOST
			|| (string)(getenv('DATAPHYRE_RUNTIME_WEB_PORT') ?: '')!==(string)self::WEB_PORT){
			fwrite(STDERR, "Fixed realtime runtime addresses are unavailable.\n");
			return 64;
		}
		foreach(['pcntl_alarm','pcntl_async_signals','pcntl_signal','pcntl_signal_get_handler','stream_select','stream_socket_server'] as $function){
			if(!function_exists($function)){
				fwrite(STDERR, "Realtime runtime dependency is unavailable.\n");
				return 70;
			}
		}
		try{
			pcntl_async_signals(true);
			$routes=DataphyreApplicationRuntimeRealtimeBootstrap::load();
			$server=new self($routes);
			return $server->run();
		}catch(Throwable $failure){
			fwrite(STDERR, "Realtime runtime failed: ".$failure->getMessage()."\n");
			return 70;
		}
	}

	private function run(): int {
		$errno=0;
		$error='';
		$this->listener=@stream_socket_server(
			'tcp://'.self::PUBLIC_HOST.':'.self::PUBLIC_PORT,
			$errno,
			$error,
			STREAM_SERVER_BIND|STREAM_SERVER_LISTEN
		);
		if(!is_resource($this->listener)){
			throw new RuntimeException('Unable to bind fixed public ingress.');
		}
		stream_set_blocking($this->listener, false);
		pcntl_async_signals(true);
		$stop=function(): void {$this->stopping=true;};
		pcntl_signal(SIGTERM, $stop);
		pcntl_signal(SIGINT, $stop);
		while(!$this->stopping){
			$this->cycle();
		}
		foreach(array_keys($this->clients) as $id){
			$this->closeClient($id);
		}
		fclose($this->listener);
		$this->listener=null;
		return 0;
	}

	private function cycle(): void {
		$read=[];
		$write=[];
		$backendOwners=[];
		if(count($this->clients)<self::MAX_CONNECTIONS){
			$read[]=$this->listener;
		}
		foreach($this->clients as $id=>$client){
			if(!is_resource($client['stream'])){
				$this->closeClient($id);
				continue;
			}
			if($client['phase']==='headers'
				|| $client['phase']==='websocket'
				|| ($client['phase']==='proxy' && strlen($client['to_backend'])<self::MAX_PROXY_BUFFER_BYTES)){
				$read[]=$client['stream'];
			}
			if($client['write_buffer']!==''){
				$write[]=$client['stream'];
			}
			if(is_resource($client['backend'] ?? null)){
				$backendId=(int)$client['backend'];
				$backendOwners[$backendId]=$id;
				if(strlen($client['write_buffer'])<self::MAX_PROXY_BUFFER_BYTES){
					$read[]=$client['backend'];
				}
				if($client['to_backend']!==''){
					$write[]=$client['backend'];
				}
			}
		}
		$except=[];
		$selected=@stream_select($read, $write, $except, 0, 50000);
		if($selected===false){
			if(!$this->stopping){
				usleep(10000);
			}
			return;
		}
		foreach($read as $stream){
			if($stream===$this->listener){
				$this->acceptClients();
				continue;
			}
			$streamId=(int)$stream;
			if(isset($backendOwners[$streamId])){
				$this->readBackend($backendOwners[$streamId]);
			}elseif(isset($this->clients[$streamId])){
				$this->readClient($streamId);
			}
		}
		foreach($write as $stream){
			$streamId=(int)$stream;
			if(isset($backendOwners[$streamId])){
				$this->writeBackend($backendOwners[$streamId]);
			}elseif(isset($this->clients[$streamId])){
				$this->writeClient($streamId);
			}
		}
		$this->maintainClients(microtime(true));
	}

	private function acceptClients(): void {
		while(count($this->clients)<self::MAX_CONNECTIONS
			&& is_resource($stream=@stream_socket_accept($this->listener, 0, $peer))){
			stream_set_blocking($stream, false);
			$id=(int)$stream;
			$this->clients[$id]=[
				'stream'=>$stream,
				'peer'=>is_string($peer) ? substr($peer, 0, 255) : '',
				'phase'=>'headers',
				'created_at'=>microtime(true),
				'header_buffer'=>'',
				'frame_buffer'=>'',
				'write_buffer'=>'',
				'to_backend'=>'',
				'backend'=>null,
				'close_after_write'=>false,
				'authorization'=>[],
				'events'=>null,
				'cursor'=>null,
				'next_event_at'=>microtime(true),
				'last_ping_at'=>microtime(true),
				'pong_deadline'=>null,
			];
		}
	}

	private function readClient(int $id): void {
		if(!isset($this->clients[$id])) return;
		$client=&$this->clients[$id];
		$limit=$client['phase']==='proxy' ? 65536 : 16384;
		$chunk=@fread($client['stream'], $limit);
		if(!is_string($chunk) || $chunk===''){
			if(feof($client['stream'])) $this->closeClient($id);
			return;
		}
		if($client['phase']==='headers'){
			$client['header_buffer'].=$chunk;
			if(strlen($client['header_buffer'])>self::MAX_HEADER_BYTES){
				$this->reject($id, 431, 'Request Header Fields Too Large');
				return;
			}
			if(str_contains($client['header_buffer'], "\r\n\r\n")){
				$this->dispatchInitialRequest($id);
			}
			return;
		}
		if($client['phase']==='proxy'){
			$client['to_backend'].=$chunk;
			if(strlen($client['to_backend'])>self::MAX_PROXY_BUFFER_BYTES) $this->closeClient($id);
			return;
		}
		if($client['phase']==='websocket'){
			$client['frame_buffer'].=$chunk;
			if(strlen($client['frame_buffer'])>self::MAX_WEBSOCKET_BUFFER_BYTES){
				$this->websocketClose($id, 1009);
				return;
			}
			$this->consumeFrames($id);
		}
	}

	private function dispatchInitialRequest(int $id): void {
		$client=&$this->clients[$id];
		$request=self::parseRequest($client['header_buffer']);
		if($request===null){
			$this->reject($id, 400, 'Bad Request');
			return;
		}
		$isUpgrade=isset($request['headers']['upgrade'])
			|| isset($request['headers']['sec-websocket-key'])
			|| isset($request['headers']['sec-websocket-version'])
			|| self::headerToken($request['headers']['connection'] ?? '', 'upgrade');
		if($isUpgrade){
			$this->upgradeWebsocket($id, $request);
			return;
		}
		$this->startProxy($id, $request);
	}

	/** @param array{method:string,target:string,protocol:string,headers:array<string,string>} $request */
	private function upgradeWebsocket(int $id, array $request): void {
		$headers=$request['headers'];
		$key=$headers['sec-websocket-key'] ?? '';
		$decodedKey=base64_decode($key, true);
		$origin=trim($headers['origin'] ?? '');
		$originParts=$origin!=='' ? parse_url($origin) : false;
		$path=parse_url($request['target'], PHP_URL_PATH);
		$queryRaw=parse_url($request['target'], PHP_URL_QUERY);
		$query=self::parseQuery(is_string($queryRaw) ? $queryRaw : '');
		$validOrigin=is_array($originParts)
			&& in_array(strtolower((string)($originParts['scheme'] ?? '')), ['http','https'], true)
			&& is_string($originParts['host'] ?? null)
			&& $originParts['host']!==''
			&& strlen($origin)<=2048;
		$valid=$request['method']==='GET'
			&& $request['protocol']==='HTTP/1.1'
			&& strtolower(trim($headers['upgrade'] ?? ''))==='websocket'
			&& self::headerToken($headers['connection'] ?? '', 'upgrade')
			&& ($headers['sec-websocket-version'] ?? '')==='13'
			&& is_string($decodedKey) && strlen($decodedKey)===16
			&& $validOrigin
			&& is_string($path) && $path!=='' && !str_contains($path, '%')
			&& $query!==null;
		if(!$valid){
			$this->reject($id, 400, 'Bad Request');
			return;
		}
		if(!isset($this->routes[$path])){
			$this->reject($id, 404, 'Not Found');
			return;
		}
		try{
			$authorization=self::runBoundedCallback($this->routes[$path]['authorize'], [[
				'path'=>$path,
				'origin'=>$origin,
				'headers'=>$headers,
				'query'=>$query,
				'remote_address'=>$this->remoteAddress((string)$this->clients[$id]['peer']),
			]], self::AUTHORIZATION_TIMEOUT_SECONDS);
		}catch(Throwable){
			$authorization=false;
		}
		$authorization=self::normalizeAuthorization($authorization);
		if($authorization===null){
			$this->reject($id, 401, 'Unauthorized');
			return;
		}
		$accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
		$client=&$this->clients[$id];
		$client['write_buffer']="HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
		$client['phase']='websocket';
		$client['header_buffer']='';
		$client['authorization']=$authorization;
		$client['events']=$this->routes[$path]['events'];
		$client['next_event_at']=microtime(true);
	}

	/** @param array{method:string,target:string,protocol:string,headers:array<string,string>} $request */
	private function startProxy(int $id, array $request): void {
		$client=&$this->clients[$id];
		$proxyRequest=self::proxyRequest($request,$client['header_buffer']);
		if($proxyRequest===null){
			$this->reject($id,400,'Bad Request');
			return;
		}
		$errno=0;
		$error='';
		$backend=@stream_socket_client(
			'tcp://'.self::WEB_HOST.':'.self::WEB_PORT,
			$errno,
			$error,
			0.25,
			STREAM_CLIENT_CONNECT
		);
		if(!is_resource($backend)){
			$this->reject($id, 503, 'Service Unavailable');
			return;
		}
		stream_set_blocking($backend, false);
		$client['backend']=$backend;
		$client['phase']='proxy';
		$client['to_backend']=$proxyRequest;
		$client['header_buffer']='';
	}

	private function readBackend(int $id): void {
		if(!isset($this->clients[$id]) || !is_resource($this->clients[$id]['backend'] ?? null)) return;
		$client=&$this->clients[$id];
		$chunk=@fread($client['backend'], 65536);
		if(!is_string($chunk) || $chunk===''){
			if(feof($client['backend'])){
				fclose($client['backend']);
				$client['backend']=null;
				$client['phase']='closing';
				$client['close_after_write']=true;
				if($client['write_buffer']==='') $this->closeClient($id);
			}
			return;
		}
		$client['write_buffer'].=$chunk;
		if(strlen($client['write_buffer'])>self::MAX_PROXY_BUFFER_BYTES) $this->closeClient($id);
	}

	private function writeBackend(int $id): void {
		if(!isset($this->clients[$id]) || !is_resource($this->clients[$id]['backend'] ?? null)) return;
		$client=&$this->clients[$id];
		$written=@fwrite($client['backend'], $client['to_backend']);
		if(is_int($written) && $written>0){
			$client['to_backend']=substr($client['to_backend'], $written);
		}elseif(feof($client['backend'])){
			$this->closeClient($id);
		}
	}

	private function writeClient(int $id): void {
		if(!isset($this->clients[$id])) return;
		$client=&$this->clients[$id];
		$written=@fwrite($client['stream'], $client['write_buffer']);
		if(is_int($written) && $written>0){
			$client['write_buffer']=substr($client['write_buffer'], $written);
		}
		if($client['write_buffer']==='' && $client['close_after_write']===true){
			$this->closeClient($id);
		}
	}

	private function maintainClients(float $now): void {
		foreach(array_keys($this->clients) as $id){
			if(!isset($this->clients[$id])) continue;
			$client=&$this->clients[$id];
			if($client['phase']==='headers' && $now-$client['created_at']>self::HEADER_TIMEOUT_SECONDS){
				$this->reject($id, 408, 'Request Timeout');
				continue;
			}
			if($client['phase']!=='websocket' || $client['close_after_write']===true) continue;
			if(is_float($client['pong_deadline']) && $now>$client['pong_deadline']){
				$this->websocketClose($id, 1002);
				continue;
			}
			if($now-$client['last_ping_at']>=self::PING_INTERVAL_SECONDS){
				$this->queueWebsocket($id, 0x9, random_bytes(8));
				$client['last_ping_at']=$now;
				$client['pong_deadline']=$now+self::PONG_TIMEOUT_SECONDS;
			}
			if($now>=$client['next_event_at']){
				$this->pollEvents($id, $now);
			}
		}
	}

	private function pollEvents(int $id, float $now): void {
		if(!isset($this->clients[$id]) || !is_callable($this->clients[$id]['events'])) return;
		$client=&$this->clients[$id];
		$client['next_event_at']=$now+self::EVENT_INTERVAL_SECONDS;
		try{
			$result=self::runBoundedCallback(
				$client['events'],
				[$client['authorization'],$client['cursor']],
				self::EVENT_TIMEOUT_SECONDS,
			);
		}catch(Throwable){
			$this->websocketClose($id, 1011);
			return;
		}
		if(!is_array($result) || count($result)!==2
			|| !array_key_exists('cursor', $result) || !array_key_exists('events', $result)
			|| !is_array($result['events']) || !array_is_list($result['events'])
			|| count($result['events'])>self::MAX_EVENTS_PER_POLL
			|| !(is_null($result['cursor']) || is_string($result['cursor']))
			|| (is_string($result['cursor']) && (strlen($result['cursor'])>256 || preg_match('//u', $result['cursor'])!==1))){
			$this->websocketClose($id, 1011);
			return;
		}
		foreach($result['events'] as $event){
			try{
				$payload=json_encode($event, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
			}catch(Throwable){
				$this->websocketClose($id, 1011);
				return;
			}
			if(strlen($payload)>self::MAX_FRAME_BYTES || !$this->queueWebsocket($id, 0x1, $payload)){
				$this->websocketClose($id, 1009);
				return;
			}
		}
		$client['cursor']=$result['cursor'];
	}

	private function consumeFrames(int $id): void {
		while(isset($this->clients[$id])){
			$client=&$this->clients[$id];
			$buffer=$client['frame_buffer'];
			if(strlen($buffer)<2) return;
			$first=ord($buffer[0]);
			$second=ord($buffer[1]);
			$fin=($first&0x80)!==0;
			$opcode=$first&0x0f;
			$masked=($second&0x80)!==0;
			$length=$second&0x7f;
			$offset=2;
			if($length===126){
				if(strlen($buffer)<4) return;
				$length=unpack('nlength', substr($buffer,2,2))['length'];
				$offset=4;
			}elseif($length===127){
				if(strlen($buffer)<10) return;
				$parts=unpack('Nhigh/Nlow', substr($buffer,2,8));
				if(($parts['high'] ?? 1)!==0){
					$this->websocketClose($id,1009);
					return;
				}
				$length=(int)$parts['low'];
				$offset=10;
			}
			$control=$opcode>=0x8;
			if(($first&0x70)!==0 || !$masked || $length>self::MAX_FRAME_BYTES
				|| ($control && (!$fin || $length>125)) || !in_array($opcode,[0x0,0x1,0x2,0x8,0x9,0xa],true)){
				$this->websocketClose($id, 1002);
				return;
			}
			if(strlen($buffer)<$offset+4+$length) return;
			$mask=substr($buffer,$offset,4);
			$payload=substr($buffer,$offset+4,$length);
			for($position=0;$position<$length;$position++){
				$payload[$position]=$payload[$position]^$mask[$position%4];
			}
			$client['frame_buffer']=substr($buffer,$offset+4+$length);
			if($opcode===0x8){
				if($length===1){
					$this->websocketClose($id,1002);
				}else{
					$this->queueWebsocket($id,0x8,$payload);
					$client['close_after_write']=true;
				}
				return;
			}
			if($opcode===0x9){
				$this->queueWebsocket($id,0xa,$payload);
				continue;
			}
			if($opcode===0xa){
				$client['pong_deadline']=null;
				continue;
			}
			$this->websocketClose($id, 1003);
			return;
		}
	}

	private function queueWebsocket(int $id, int $opcode, string $payload): bool {
		if(!isset($this->clients[$id]) || strlen($payload)>self::MAX_FRAME_BYTES) return false;
		$length=strlen($payload);
		if($length<=125){
			$frame=chr(0x80|$opcode).chr($length).$payload;
		}elseif($length<=65535){
			$frame=chr(0x80|$opcode).chr(126).pack('n',$length).$payload;
		}else{
			$frame=chr(0x80|$opcode).chr(127).pack('N2',0,$length).$payload;
		}
		if(strlen($this->clients[$id]['write_buffer'])+strlen($frame)>self::MAX_WEBSOCKET_BUFFER_BYTES){
			return false;
		}
		$this->clients[$id]['write_buffer'].=$frame;
		return true;
	}

	private function websocketClose(int $id, int $code): void {
		if(!isset($this->clients[$id])) return;
		if(!$this->queueWebsocket($id,0x8,pack('n',$code))){
			$this->closeClient($id);
			return;
		}
		$this->clients[$id]['close_after_write']=true;
	}

	private function reject(int $id, int $status, string $reason): void {
		if(!isset($this->clients[$id])) return;
		$this->clients[$id]['write_buffer']="HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
		$this->clients[$id]['close_after_write']=true;
		$this->clients[$id]['phase']='closing';
		$this->clients[$id]['header_buffer']='';
	}

	private function closeClient(int $id): void {
		if(!isset($this->clients[$id])) return;
		$client=$this->clients[$id];
		unset($this->clients[$id]);
		if(is_resource($client['backend'] ?? null)) fclose($client['backend']);
		if(is_resource($client['stream'] ?? null)) fclose($client['stream']);
	}

	/**
	 * Rebuilds one ordinary HTTP request for the private web pool. Headers that
	 * select framework-owned application/runtime identity are never accepted from
	 * public ingress, and the private hop is always one request per connection.
	 *
	 * @param array{method:string,target:string,protocol:string,headers:array<string,string>} $request
	 */
	private static function proxyRequest(array $request, string $buffer): ?string {
		$end=strpos($buffer,"\r\n\r\n");
		if($end===false) return null;
		$connectionTokens=[];
		foreach(explode(',',strtolower($request['headers']['connection'] ?? '')) as $token){
			$token=trim($token);
			if($token!=='') $connectionTokens[$token]=true;
		}
		$fixedPrivateHeaders=[
			'connection'=>true,
			'keep-alive'=>true,
			'proxy-authenticate'=>true,
			'proxy-authorization'=>true,
			'te'=>true,
			'trailer'=>true,
			'upgrade'=>true,
			'x-dataphyre-application'=>true,
			'x-dataphyre-environment'=>true,
			'x-traffic-source'=>true,
		];
		$lines=[sprintf('%s %s %s',$request['method'],$request['target'],$request['protocol'])];
		foreach($request['headers'] as $name=>$value){
			if(isset($fixedPrivateHeaders[$name]) || isset($connectionTokens[$name])
				|| str_starts_with($name,'x-dataphyre-runtime-')) continue;
			$lines[]=$name.': '.$value;
		}
		$lines[]='connection: close';
		return implode("\r\n",$lines)."\r\n\r\n".substr($buffer,$end+4);
	}

	private static function runBoundedCallback(callable $callback, array $arguments, float $timeout): mixed {
		$previous=pcntl_signal_get_handler(SIGALRM);
		$started=microtime(true);
		pcntl_signal(SIGALRM,static function(): never {
			throw new RuntimeException('Realtime application callback exceeded its fixed deadline.');
		});
		pcntl_alarm(max(1,(int)ceil($timeout)));
		try{
			$result=$callback(...$arguments);
			if(microtime(true)-$started>$timeout){
				throw new RuntimeException('Realtime application callback exceeded its fixed deadline.');
			}
			return $result;
		}finally{
			pcntl_alarm(0);
			pcntl_signal(SIGALRM,$previous);
		}
	}

	/** @return null|array{method:string,target:string,protocol:string,headers:array<string,string>} */
	public static function parseRequest(string $buffer): ?array {
		$end=strpos($buffer, "\r\n\r\n");
		if($end===false || $end+4>self::MAX_HEADER_BYTES) return null;
		$head=substr($buffer,0,$end);
		$lines=explode("\r\n",$head);
		$requestLine=array_shift($lines);
		if(!is_string($requestLine) || strlen($requestLine)>self::MAX_HEADER_LINE_BYTES
			|| preg_match('@^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS) (/[^\x00-\x20\x7f#]{0,4095}) (HTTP/1\.[01])$@D',$requestLine,$matches)!==1){
			return null;
		}
		$headers=[];
		if(count($lines)>self::MAX_HEADERS) return null;
		foreach($lines as $line){
			if($line==='' || strlen($line)>self::MAX_HEADER_LINE_BYTES
				|| preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D',$line,$match)!==1){
				return null;
			}
			$name=strtolower($match[1]);
			$value=trim($match[2]);
			if(isset($headers[$name]) || $name==='proxy') return null;
			$headers[$name]=$value;
		}
		if(!isset($headers['host']) || $headers['host']===''
			|| (isset($headers['content-length']) && isset($headers['transfer-encoding']))
			|| (isset($headers['content-length']) && preg_match('/^(?:0|[1-9][0-9]{0,15})$/D',$headers['content-length'])!==1)
			|| (isset($headers['transfer-encoding']) && strtolower($headers['transfer-encoding'])!=='chunked')){
			return null;
		}
		return ['method'=>$matches[1],'target'=>$matches[2],'protocol'=>$matches[3],'headers'=>$headers];
	}

	private static function headerToken(string $value, string $expected): bool {
		foreach(explode(',', strtolower($value)) as $token){
			if(trim($token)===$expected) return true;
		}
		return false;
	}

	/** @return null|array<string,string> */
	private static function parseQuery(string $raw): ?array {
		if(strlen($raw)>4096) return null;
		if($raw==='') return [];
		$pairs=explode('&',$raw);
		if(count($pairs)>32) return null;
		$query=[];
		foreach($pairs as $pair){
			[$key,$value]=array_pad(explode('=',$pair,2),2,'');
			$key=rawurldecode($key);
			$value=rawurldecode($value);
			if($key==='' || strlen($key)>128 || strlen($value)>2048
				|| preg_match('/[\x00-\x1f\x7f]/',$key.$value)===1 || isset($query[$key])) return null;
			$query[$key]=$value;
		}
		ksort($query,SORT_STRING);
		return $query;
	}

	/** @return null|array<string,mixed> */
	private static function normalizeAuthorization(mixed $authorization): ?array {
		if($authorization===false || !is_array($authorization) || ($authorization!==[] && array_is_list($authorization))){
			return null;
		}
		try{
			$encoded=json_encode($authorization,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
			if(strlen($encoded)>self::MAX_AUTHORIZATION_BYTES) return null;
			$decoded=json_decode($encoded,true,16,JSON_THROW_ON_ERROR);
			return is_array($decoded) && ($decoded===[] || !array_is_list($decoded)) ? $decoded : null;
		}catch(Throwable){
			return null;
		}
	}

	private function remoteAddress(string $peer): string {
		if(preg_match('/^\[([^\]]+)\]:\d+$/D',$peer,$matches)===1) return $matches[1];
		if(preg_match('/^(.+):\d+$/D',$peer,$matches)===1) return substr($matches[1],0,255);
		return substr($peer,0,255);
	}
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(DataphyreApplicationRuntimeRealtimeServer::main());
}
