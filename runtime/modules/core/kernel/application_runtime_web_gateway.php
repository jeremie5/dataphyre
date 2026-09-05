<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_process_broker.php';

final class DataphyreApplicationRuntimeGatewayTimeout extends RuntimeException {}
final class DataphyreApplicationRuntimeGatewayInput extends RuntimeException {}

/** Capability-free HTTP/static/FastCGI gateway for the fixed managed web pool. */
final class DataphyreApplicationRuntimeWebGateway
{
	public const SOCKET='/run/dataphyre/web/php-fpm.sock';
	private const SOCKET_DIRECTORY='/run/dataphyre/web';
	/** @var null|array{dev:int,ino:int} */
	private static ?array $socketIdentity=null;
	/** @var null|array{dev:int,ino:int} */
	private static ?array $socketDirectoryIdentity=null;
	private const MAX_CHILDREN=8;
	private const MAX_HEADER_BYTES=65536;
	private const MAX_HEADER_LINE_BYTES=8192;
	private const MAX_HEADERS=128;
	private const MAX_BODY_BYTES=16777216;
	private const MAX_CHUNK_OVERHEAD_BYTES=65536;
	private const MAX_DYNAMIC_RESPONSE_BODY_BYTES=8388608;
	private const MAX_STATIC_RESPONSE_BYTES=268435456;
	private const SPOOL_MEMORY_BYTES=262144;
	private const MAX_AGGREGATE_SPOOL_BYTES=self::MAX_CHILDREN
		*(self::MAX_BODY_BYTES+self::MAX_DYNAMIC_RESPONSE_BODY_BYTES);
	private const HEADER_TIMEOUT_MILLISECONDS=5000;
	private const BODY_TIMEOUT_MILLISECONDS=30000;
	private const FASTCGI_TIMEOUT_MILLISECONDS=300000;
	private const CLIENT_WRITE_TIMEOUT_MILLISECONDS=30000;

	public static function run(string $host,int $port,string $router,string $projectRoot,string $socketPath=self::SOCKET): int
	{
		self::validateInvocation($host,$port,$router,$projectRoot,$socketPath);
		$listener=@stream_socket_server('tcp://'.$host.':'.$port,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		if(!is_resource($listener)) throw new RuntimeException('Application web gateway could not bind its listener.');
		stream_set_blocking($listener,false);$stopping=false;$children=[];
		$listenerOwnerPid=getmypid();
		pcntl_async_signals(true);
		$stop=static function() use (&$stopping): void {$stopping=true;};
		pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);
		try{
			while(!$stopping){
				self::reap($children,false);
				if(count($children)>=self::MAX_CHILDREN){usleep(10000);continue;}
				$connection=@stream_socket_accept($listener,0.05,$peer);
				if(!is_resource($connection)) continue;
				$pid=pcntl_fork();
				if($pid===-1){self::respond($connection,503,'Service Unavailable');fclose($connection);continue;}
				if($pid>0){$children[$pid]=true;fclose($connection);continue;}
				fclose($listener);pcntl_async_signals(false);
				try{
					self::serve($connection,is_string($peer) ? $peer : '',$host,$port,$router,$projectRoot,$socketPath);
				}catch(DataphyreApplicationRuntimeGatewayInput){self::respond($connection,400,'Bad Request');
				}catch(DataphyreApplicationRuntimeGatewayTimeout){self::respond($connection,504,'Gateway Timeout');
				}catch(Throwable){self::respond($connection,502,'Bad Gateway');}
				fclose($connection);exit(0);
			}
		}finally{
			// A request child can unwind here if its error response also fails.
			// Only the creating process owns the listener and handler inventory.
			if(getmypid()===$listenerOwnerPid){
				fclose($listener);
				foreach(array_keys($children) as $pid) @posix_kill($pid,SIGTERM);
				$deadline=microtime(true)+5.0;
				while($children!==[] && microtime(true)<$deadline){self::reap($children,false);usleep(10000);}
				foreach(array_keys($children) as $pid) @posix_kill($pid,SIGKILL);
				self::reap($children,true);
			}
		}
		return 0;
	}

	private static function serve(
		mixed $connection,string $peer,string $host,int $port,string $router,string $projectRoot,string $socketPath,
	): void {
		[$request,$body,$bodyLength]=self::readRequestStream($connection,self::MAX_BODY_BYTES,self::MAX_HEADER_BYTES,true);
		$response=null;
		try{
			if(self::serveStatic(
				$connection,$request,$projectRoot,
				hrtime(true)+(self::CLIENT_WRITE_TIMEOUT_MILLISECONDS*1_000_000),
			)) return;
			$parameters=self::requestEnvironment($request,$bodyLength,$peer,$host,$port,$router,$projectRoot);
			$response=self::fastCgi($socketPath,$parameters,$body,$bodyLength);
			self::writeCgiStreamResponse(
				$connection,$response['head'],$response['body'],$response['body_length'],
				$request['method']==='HEAD',hrtime(true)+(self::CLIENT_WRITE_TIMEOUT_MILLISECONDS*1_000_000),
			);
		}finally{
			if(is_resource($body)) fclose($body);
			if(is_array($response) && is_resource($response['body'] ?? null)) fclose($response['body']);
		}
	}

	/** @return array{0:array{method:string,target:string,protocol:string,headers:array<string,string>},1:string} */
	public static function readRequest(
		mixed $connection,int $maximumBodyBytes=self::MAX_BODY_BYTES,int $maximumHeaderBytes=self::MAX_HEADER_BYTES,
		bool $allowChunked=true,int $headerTimeoutMilliseconds=self::HEADER_TIMEOUT_MILLISECONDS,
		int $bodyTimeoutMilliseconds=self::BODY_TIMEOUT_MILLISECONDS,
	): array
	{
		[$request,$body,$length]=self::readRequestStream(
			$connection,$maximumBodyBytes,$maximumHeaderBytes,$allowChunked,
			$headerTimeoutMilliseconds,$bodyTimeoutMilliseconds,
		);
		try{
			$bytes=stream_get_contents($body);
			if(!is_string($bytes) || strlen($bytes)!==$length){
				throw new RuntimeException('Application request spool is invalid.');
			}
			return [$request,$bytes];
		}finally{fclose($body);}
	}

	/** @return array{0:array{method:string,target:string,protocol:string,headers:array<string,string>},1:resource,2:int} */
	private static function readRequestStream(
		mixed $connection,int $maximumBodyBytes,int $maximumHeaderBytes,bool $allowChunked,
		int $headerTimeoutMilliseconds=self::HEADER_TIMEOUT_MILLISECONDS,
		int $bodyTimeoutMilliseconds=self::BODY_TIMEOUT_MILLISECONDS,
	): array
	{
		if(!is_resource($connection) || $maximumBodyBytes<0 || $maximumBodyBytes>self::MAX_BODY_BYTES
			|| $maximumHeaderBytes<1024 || $maximumHeaderBytes>self::MAX_HEADER_BYTES
			|| $headerTimeoutMilliseconds<1 || $headerTimeoutMilliseconds>self::HEADER_TIMEOUT_MILLISECONDS
			|| $bodyTimeoutMilliseconds<1 || $bodyTimeoutMilliseconds>self::BODY_TIMEOUT_MILLISECONDS){
			throw new DataphyreApplicationRuntimeGatewayInput('Application request bounds are invalid.');
		}
		stream_set_blocking($connection,false);$buffer='';$end=false;
		$headerDeadline=hrtime(true)+($headerTimeoutMilliseconds*1_000_000);
		while(($end=strpos($buffer,"\r\n\r\n"))===false){
			if(strlen($buffer)>=$maximumHeaderBytes+4){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request headers exceeded their bound.');
			}
			self::waitForStream(
				$connection,true,$headerDeadline,
				new DataphyreApplicationRuntimeGatewayTimeout('Application request headers timed out.'),
			);
			$chunk=fread($connection,min(16384,$maximumHeaderBytes+4-strlen($buffer)));
			if(!is_string($chunk) || $chunk==='') throw new DataphyreApplicationRuntimeGatewayInput('Application request headers are incomplete.');
			$buffer.=$chunk;
			if(strpos($buffer,"\r\n\r\n")===false && strlen($buffer)>$maximumHeaderBytes){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request headers exceeded their bound.');
			}
		}
		$head=substr($buffer,0,$end);$remaining=substr($buffer,$end+4);
		$lines=explode("\r\n",$head);$requestLine=array_shift($lines);
		if(!is_string($requestLine) || strlen($requestLine)>self::MAX_HEADER_LINE_BYTES
			|| preg_match('@^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS) (/[^\x00-\x20\x7f#]{0,4095}) (HTTP/1\.[01])$@D',$requestLine,$matches)!==1
			|| count($lines)>self::MAX_HEADERS){
			throw new DataphyreApplicationRuntimeGatewayInput('Application request line is invalid.');
		}
		$headers=[];
		foreach($lines as $line){
			if($line==='' || strlen($line)>self::MAX_HEADER_LINE_BYTES
				|| preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D',$line,$match)!==1
				|| preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/D',$match[2])===1){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request header is invalid.');
			}
			$name=strtolower($match[1]);$value=trim($match[2]);
			if(isset($headers[$name]) || $name==='proxy') throw new DataphyreApplicationRuntimeGatewayInput('Application request header is ambiguous.');
			$headers[$name]=$value;
		}
		$connectionTokens=self::requestConnectionTokens($headers['connection'] ?? null);
		if(isset($headers['expect'])){
			throw new DataphyreApplicationRuntimeGatewayInput('Application request expectations are not accepted.');
		}
		if(!isset($headers['host']) || $headers['host']==='' || isset($headers['content-length'],$headers['transfer-encoding'])
			|| (isset($headers['transfer-encoding'])
				&& (!$allowChunked || strtolower($headers['transfer-encoding'])!=='chunked'))){
			throw new DataphyreApplicationRuntimeGatewayInput('Application request framing is invalid.');
		}
		$body=self::spool();$length=0;
		try{
		if(isset($headers['content-length'])){
			if(preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$headers['content-length'])!==1
				|| ($length=(int)$headers['content-length'])>$maximumBodyBytes){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request body length is invalid.');
			}
			if(strlen($remaining)>$length) throw new DataphyreApplicationRuntimeGatewayInput('HTTP pipelining is not accepted.');
			self::spoolWrite($body,$remaining);$received=strlen($remaining);
			$bodyDeadline=hrtime(true)+($bodyTimeoutMilliseconds*1_000_000);
			while($received<$length){
				self::waitForStream(
					$connection,true,$bodyDeadline,
					new DataphyreApplicationRuntimeGatewayTimeout('Application request body timed out.'),
				);
				$chunk=fread($connection,min(65536,$length-$received));
				if(!is_string($chunk) || $chunk==='') throw new DataphyreApplicationRuntimeGatewayInput('Application request body is incomplete.');
				self::spoolWrite($body,$chunk);$received+=strlen($chunk);
			}
		}elseif(isset($headers['transfer-encoding'])){
			$length=self::readChunkedBody(
				$connection,$body,$remaining,$maximumBodyBytes,$bodyTimeoutMilliseconds,
			);
		}elseif($remaining!==''){
			throw new DataphyreApplicationRuntimeGatewayInput('Unframed application request body is invalid.');
		}
		rewind($body);
		$hopByHop=array_fill_keys([
			'connection','content-length','keep-alive','proxy-authenticate','proxy-authorization','proxy-connection',
			'te','trailer','transfer-encoding','upgrade',
		],true);
		foreach(array_keys($headers) as $name){
			if(isset($hopByHop[$name]) || isset($connectionTokens[$name])) unset($headers[$name]);
		}
		return [['method'=>$matches[1],'target'=>$matches[2],'protocol'=>$matches[3],'headers'=>$headers],$body,$length];
		}catch(Throwable $failure){fclose($body);throw $failure;}
	}

	/** @return array<string,true> */
	private static function requestConnectionTokens(?string $value): array
	{
		if($value===null) return [];
		if($value==='') throw new DataphyreApplicationRuntimeGatewayInput('Application request connection header is invalid.');
		$tokens=[];
		foreach(explode(',',$value) as $candidate){
			$token=strtolower(trim($candidate));
			if(preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/D',$token)!==1 || $token==='host'){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request connection header is invalid.');
			}
			$tokens[$token]=true;
		}
		return $tokens;
	}

	private static function readChunkedBody(
		mixed $connection,mixed $body,string $buffer,int $maximumBodyBytes,
		int $bodyTimeoutMilliseconds=self::BODY_TIMEOUT_MILLISECONDS,
	): int {
		$decoded=0;$wireBytes=strlen($buffer);$deadline=hrtime(true)+($bodyTimeoutMilliseconds*1_000_000);
		$readMore=static function() use ($connection,$maximumBodyBytes,$deadline,&$buffer,&$wireBytes): void {
			self::waitForStream(
				$connection,true,$deadline,
				new DataphyreApplicationRuntimeGatewayTimeout('Application request body timed out.'),
			);
			$chunk=fread($connection,65536);
			if(!is_string($chunk) || $chunk==='') throw new DataphyreApplicationRuntimeGatewayInput('Application chunked request is incomplete.');
			$wireBytes+=strlen($chunk);
			if($wireBytes>$maximumBodyBytes+self::MAX_CHUNK_OVERHEAD_BYTES){
				throw new DataphyreApplicationRuntimeGatewayInput('Application chunked request exceeded its encoded bound.');
			}
			$buffer.=$chunk;
		};
		while(true){
			while(($lineEnd=strpos($buffer,"\r\n"))===false){
				if(strlen($buffer)>8) throw new DataphyreApplicationRuntimeGatewayInput('Application chunk framing is invalid.');
				$readMore();
			}
			$line=substr($buffer,0,$lineEnd);$buffer=substr($buffer,$lineEnd+2);
			if(preg_match('/^(?:0|[1-9a-f][0-9a-f]{0,7})$/Di',$line)!==1){
				throw new DataphyreApplicationRuntimeGatewayInput('Application chunk framing is invalid.');
			}
			$chunkLength=hexdec($line);
			if($chunkLength===0){
				while(strlen($buffer)<2) $readMore();
				if(substr($buffer,0,2)!=="\r\n" || strlen($buffer)!==2){
					throw new DataphyreApplicationRuntimeGatewayInput('Application chunk trailer is invalid.');
				}
				return $decoded;
			}
			if($chunkLength>$maximumBodyBytes-$decoded){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request body exceeded its bound.');
			}
			$remaining=$chunkLength;
			while($remaining>0){
				if($buffer==='') $readMore();
				$take=min($remaining,strlen($buffer));
				self::spoolWrite($body,substr($buffer,0,$take));$buffer=substr($buffer,$take);
				$remaining-=$take;$decoded+=$take;
			}
			while(strlen($buffer)<2) $readMore();
			if(substr($buffer,0,2)!=="\r\n"){
				throw new DataphyreApplicationRuntimeGatewayInput('Application chunk delimiter is invalid.');
			}
			$buffer=substr($buffer,2);
		}
	}

	/** @return resource */
	private static function spool(): mixed
	{
		$stream=@fopen('php://temp/maxmemory:'.self::SPOOL_MEMORY_BYTES,'w+b');
		if(!is_resource($stream)) throw new RuntimeException('Application runtime spool is unavailable.');
		return $stream;
	}

	private static function spoolWrite(mixed $stream,string $bytes): void
	{
		$offset=0;
		while($offset<strlen($bytes)){
			$written=@fwrite($stream,substr($bytes,$offset,65536));
			if(!is_int($written) || $written<1) throw new RuntimeException('Application runtime spool write failed.');
			$offset+=$written;
		}
	}

	private static function serveStatic(
		mixed $connection,array $request,string $projectRoot,int $writeDeadline,
	): bool
	{
		if(!in_array($request['method'],['GET','HEAD'],true)) return false;
		$rawPath=(string)(parse_url($request['target'],PHP_URL_PATH) ?: '/');
		if(preg_match('/%(?:2f|5c|2e)/i',$rawPath)===1){self::respond($connection,404,'Not Found');return true;}
		$path=rawurldecode($rawPath);
		if($path==='/health') return false;
		if($path==='/' || $path==='') return false;
		if(!str_starts_with($path,'/') || str_contains($path,"\0") || str_contains($path,'\\')){
			self::respond($connection,404,'Not Found');return true;
		}
		$segments=explode('/',ltrim($path,'/'));
		foreach($segments as $segment){
			if($segment==='' || str_starts_with($segment,'.')){self::respond($connection,404,'Not Found');return true;}
		}
		if(preg_match('/\.(?:php[0-9]*|phtml|phar)(?:\.|$)/i',$path)===1){self::respond($connection,404,'Not Found');return true;}
		$publicRoot=$projectRoot.'/public';
		// A public tree is optional. Applications that keep their document shell
		// elsewhere must still reach the framework router; only an existing but
		// unsafe public node is a static-boundary rejection.
		if(!file_exists($publicRoot) && !is_link($publicRoot)) return false;
		if(is_link($publicRoot) || !is_dir($publicRoot) || !hash_equals($publicRoot,(string)realpath($publicRoot))){
			self::respond($connection,404,'Not Found');return true;
		}
		$cursor=$publicRoot;
		foreach($segments as $segment){
			$cursor.='/'.$segment;
			if(is_link($cursor)){self::respond($connection,404,'Not Found');return true;}
			if(!file_exists($cursor)) return false;
		}
		$stat=@lstat($cursor);$resolved=@realpath($cursor);$prefix=$publicRoot.'/';
		if(!is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0100000 || !is_string($resolved)
			|| !str_starts_with($resolved,$prefix) || !hash_equals($resolved,$cursor)){
			self::respond($connection,404,'Not Found');return true;
		}
		$size=$stat['size'] ?? null;
		if(!is_int($size) || $size<0 || $size>self::MAX_STATIC_RESPONSE_BYTES){self::respond($connection,404,'Not Found');return true;}
		$extension=strtolower(pathinfo($resolved,PATHINFO_EXTENSION));
		$mime=match($extension){
			'css'=>'text/css; charset=utf-8','js','mjs'=>'text/javascript; charset=utf-8','json'=>'application/json',
			'html','htm'=>'text/html; charset=utf-8','txt'=>'text/plain; charset=utf-8','xml'=>'application/xml',
			'svg'=>'image/svg+xml','png'=>'image/png','jpg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
			'ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','pdf'=>'application/pdf',
			default=>'application/octet-stream',
		};
		$immutable=preg_match('/(?:^|[._-])[a-f0-9]{8,64}(?:[._-]|$)/i',basename($resolved))===1;
		$handle=@fopen($resolved,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Static response file is unavailable.');
		try{
			$opened=@fstat($handle);
			if(!is_array($opened) || ($opened['dev'] ?? null)!==($stat['dev'] ?? null)
				|| ($opened['ino'] ?? null)!==($stat['ino'] ?? null)
				|| ($opened['size'] ?? null)!==$size){
				throw new RuntimeException('Static response file identity changed.');
			}
			$headers="HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: {$size}\r\n".
				'Cache-Control: '.($immutable ? 'public, max-age=31536000, immutable' : 'no-cache')."\r\n".
				"X-Content-Type-Options: nosniff\r\nConnection: close\r\n\r\n";
			self::writeAll($connection,$headers,$writeDeadline);
			if($request['method']==='HEAD') return true;
			$remaining=$size;
			while($remaining>0){
				$chunk=fread($handle,min(65536,$remaining));
				if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Static response file ended early.');
				self::writeAll($connection,$chunk,$writeDeadline);$remaining-=strlen($chunk);
			}
		}finally{fclose($handle);}
		return true;
	}

	/** @return array<string,string> */
	public static function requestEnvironment(
		array $request,int $bodyLength,string $peer,string $host,int $port,string $router,string $projectRoot,
	): array {
		if($bodyLength<0 || $bodyLength>self::MAX_BODY_BYTES){
			throw new DataphyreApplicationRuntimeGatewayInput('Application request body length is invalid.');
		}
		$target=$request['target'];$question=strpos($target,'?');
		$path=$question===false ? $target : substr($target,0,$question);
		$query=$question===false ? '' : substr($target,$question+1);
		$environment=[
			'GATEWAY_INTERFACE'=>'CGI/1.1','SERVER_SOFTWARE'=>'Dataphyre-Application-Gateway',
			// FPM suppresses a HEAD response body before the gateway can measure the
			// GET representation. Execute it as GET and discard the body at ingress.
			'SERVER_PROTOCOL'=>$request['protocol'],'REQUEST_METHOD'=>$request['method']==='HEAD' ? 'GET' : $request['method'],'REQUEST_URI'=>$target,
			'SCRIPT_FILENAME'=>$router,'SCRIPT_NAME'=>$path,'DOCUMENT_ROOT'=>$projectRoot.'/public',
			'QUERY_STRING'=>$query,'REDIRECT_STATUS'=>'200','REMOTE_ADDR'=>self::remoteAddress($peer),
			'REMOTE_PORT'=>self::remotePort($peer),'SERVER_ADDR'=>$host,'SERVER_PORT'=>(string)$port,
			'SERVER_NAME'=>substr($request['headers']['host'],0,255),'CONTENT_LENGTH'=>(string)$bodyLength,
		];
		foreach($request['headers'] as $name=>$value){
			if($name==='content-type'){$environment['CONTENT_TYPE']=$value;continue;}
			if(in_array($name,[
				'content-length','transfer-encoding','connection','keep-alive','proxy','proxy-authenticate',
				'proxy-authorization','proxy-connection','te','trailer','upgrade',
			],true)) continue;
			$key='HTTP_'.strtoupper(str_replace('-','_',$name));
			if(preg_match('/^HTTP_[A-Z0-9_]{1,119}$/D',$key)!==1 || isset($environment[$key])){
				throw new DataphyreApplicationRuntimeGatewayInput('Application request header mapping is ambiguous.');
			}
			$environment[$key]=$value;
		}
		ksort($environment,SORT_STRING);return $environment;
	}

	/**
	 * @param array<string,string> $parameters
	 * @return array{head:string,body:resource,body_length:int}
	 */
	private static function fastCgi(string $socketPath,array $parameters,mixed $body,int $bodyLength): array
	{
		if(!is_resource($body) || $bodyLength<0 || $bodyLength>self::MAX_BODY_BYTES){
			throw new RuntimeException('FastCGI request body is invalid.');
		}
		if(!self::socketBoundaryValid($socketPath)){
			throw new RuntimeException('Managed PHP web socket identity changed.');
		}
		$socket=@stream_socket_client('unix://'.$socketPath,$errno,$error,2,STREAM_CLIENT_CONNECT);
		if(!is_resource($socket)) throw new RuntimeException('Managed PHP web pool is unavailable.');
		stream_set_blocking($socket,false);
		$deadline=hrtime(true)+(self::FASTCGI_TIMEOUT_MILLISECONDS*1_000_000);$requestId=1;$responseBody=null;
		try{
			$parameterBytes=self::fastCgiParameters($parameters);
			self::fastCgiWrite($socket,self::fastCgiRecord(1,$requestId,pack('nCxxxxx',1,0)),$deadline);
			foreach(str_split($parameterBytes,65535) as $chunk) self::fastCgiWrite($socket,self::fastCgiRecord(4,$requestId,$chunk),$deadline);
			self::fastCgiWrite($socket,self::fastCgiRecord(4,$requestId,''),$deadline);
			rewind($body);$sent=0;
			while($sent<$bodyLength){
				$chunk=fread($body,min(65535,$bodyLength-$sent));
				if(!is_string($chunk) || $chunk==='') throw new RuntimeException('FastCGI request spool ended early.');
				self::fastCgiWrite($socket,self::fastCgiRecord(5,$requestId,$chunk),$deadline);$sent+=strlen($chunk);
			}
			self::fastCgiWrite($socket,self::fastCgiRecord(5,$requestId,''),$deadline);
			$responseBody=self::spool();$responseHead=null;$headBuffer='';$responseLength=0;$stderrLength=0;$completed=false;
			while(!$completed){
				$header=self::fastCgiRead($socket,8,$deadline);
				$fields=unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved',$header);
				if(!is_array($fields) || ($fields['version'] ?? null)!==1 || ($fields['request'] ?? null)!==$requestId
					|| ($fields['reserved'] ?? null)!==0){
					throw new RuntimeException('FastCGI response framing is invalid.');
				}
				$content=self::fastCgiRead($socket,(int)$fields['length'],$deadline);
				if((int)$fields['padding']>0) self::fastCgiRead($socket,(int)$fields['padding'],$deadline);
				if($fields['type']===6){
					if($responseHead===null){
						$headBuffer.=$content;
					[$separator,$end]=self::cgiHeaderSeparator($headBuffer);
					if($end!==null){
						if($end>self::MAX_HEADER_BYTES){
							throw new RuntimeException('Application response headers exceeded their bound.');
						}
							$responseHead=substr($headBuffer,0,$end);
							$initialBody=substr($headBuffer,$end+strlen($separator));$headBuffer='';
							$responseLength=strlen($initialBody);
							if($responseLength>self::MAX_DYNAMIC_RESPONSE_BODY_BYTES){
								throw new RuntimeException('FastCGI response exceeded its bound.');
							}
							self::spoolWrite($responseBody,$initialBody);
						}elseif(strlen($headBuffer)>self::MAX_HEADER_BYTES+4){
							throw new RuntimeException('Application response headers are invalid.');
						}
					}else{
						$responseLength+=strlen($content);
						if($responseLength>self::MAX_DYNAMIC_RESPONSE_BODY_BYTES){
							throw new RuntimeException('FastCGI response exceeded its bound.');
						}
						self::spoolWrite($responseBody,$content);
					}
				}elseif($fields['type']===7) $stderrLength+=strlen($content);
				elseif($fields['type']===3){
					if(strlen($content)!==8) throw new RuntimeException('FastCGI completion record is invalid.');
					$end=unpack('NappStatus/CprotocolStatus',substr($content,0,5));
					if(!is_array($end) || ($end['appStatus'] ?? -1)!==0 || ($end['protocolStatus'] ?? -1)!==0){
						throw new RuntimeException('Managed PHP web request failed.');
					}
					$completed=true;
				}elseif(!in_array($fields['type'],[2,8,9,10,11],true)){
					throw new RuntimeException('FastCGI response type is invalid.');
				}
				if($stderrLength>self::MAX_HEADER_BYTES){
					throw new RuntimeException('FastCGI response exceeded its bound.');
				}
			}
			if(!is_string($responseHead)) throw new RuntimeException('Application response headers are invalid.');
			rewind($responseBody);$result=['head'=>$responseHead,'body'=>$responseBody,'body_length'=>$responseLength];
			$responseBody=null;return $result;
		}finally{
			fclose($socket);
			if(is_resource($responseBody)) fclose($responseBody);
		}
	}

	/** @param array<string,string> $parameters */
	private static function fastCgiParameters(array $parameters): string
	{
		$bytes='';
		foreach($parameters as $name=>$value){
			if(!is_string($name) || !is_string($value) || strlen($name)>127 || strlen($value)>self::MAX_HEADER_LINE_BYTES){
				throw new RuntimeException('FastCGI parameter is invalid.');
			}
			$bytes.=self::fastCgiLength(strlen($name)).self::fastCgiLength(strlen($value)).$name.$value;
			if(strlen($bytes)>self::MAX_HEADER_BYTES) throw new RuntimeException('FastCGI parameters exceeded their bound.');
		}
		return $bytes;
	}

	private static function fastCgiLength(int $length): string
	{
		return $length<128 ? chr($length) : pack('N',$length|0x80000000);
	}

	private static function fastCgiRecord(int $type,int $requestId,string $content): string
	{
		if(strlen($content)>65535) throw new RuntimeException('FastCGI record exceeded its bound.');
		$padding=(8-(strlen($content)%8))%8;
		return pack('CCnnCC',1,$type,$requestId,strlen($content),$padding,0).$content.str_repeat("\0",$padding);
	}

	private static function fastCgiWrite(mixed $stream,string $bytes,int $deadline): void
	{
		$offset=0;
		while($offset<strlen($bytes)){
			self::waitFor($stream,false,$deadline);
			$written=@fwrite($stream,substr($bytes,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('FastCGI request write failed.');
			$offset+=$written;
		}
	}

	private static function fastCgiRead(mixed $stream,int $length,int $deadline): string
	{
		$result='';
		while(strlen($result)<$length){
			self::waitFor($stream,true,$deadline);
			$chunk=@fread($stream,$length-strlen($result));
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('FastCGI response ended early.');
			$result.=$chunk;
		}
		return $result;
	}

	private static function waitFor(mixed $stream,bool $readable,int $deadline): void
	{
		self::waitForStream(
			$stream,$readable,$deadline,
			new DataphyreApplicationRuntimeGatewayTimeout('Managed PHP web request timed out.'),
		);
	}

	private static function waitForStream(mixed $stream,bool $readable,int $deadline,Throwable $timeout): void
	{
		$remaining=$deadline-hrtime(true);
		if($remaining<=0) throw $timeout;
		$read=$readable ? [$stream] : [];$write=$readable ? [] : [$stream];$except=[];
		$seconds=intdiv($remaining,1_000_000_000);$microseconds=intdiv($remaining%1_000_000_000,1000);
		$selected=@stream_select($read,$write,$except,$seconds,$microseconds);
		if($selected===0) throw $timeout;
		if($selected===false) throw new RuntimeException('Application runtime stream polling failed.');
	}

	public static function writeCgiResponse(
		mixed $connection,string $output,bool $headOnly,?int $writeDeadline=null,
	): void
	{
		[$separator,$end]=self::cgiHeaderSeparator($output);
		if($end===null || $end>self::MAX_HEADER_BYTES) throw new RuntimeException('Application response headers are invalid.');
		$head=substr($output,0,$end);$body=substr($output,$end+strlen($separator));
		if(strlen($body)>self::MAX_DYNAMIC_RESPONSE_BODY_BYTES){
			throw new RuntimeException('FastCGI response exceeded its bound.');
		}
		$spool=self::spool();
		try{
			self::spoolWrite($spool,$body);rewind($spool);
			self::writeCgiStreamResponse($connection,$head,$spool,strlen($body),$headOnly,$writeDeadline);
		}finally{fclose($spool);}
	}

	/** @return array{0:string,1:?int} */
	private static function cgiHeaderSeparator(string $output): array
	{
		$crlf=strpos($output,"\r\n\r\n");$lf=strpos($output,"\n\n");
		if($crlf===false && $lf===false) return ['',null];
		if($crlf!==false && ($lf===false || $crlf<=$lf)) return ["\r\n\r\n",$crlf];
		return ["\n\n",$lf];
	}

	/** @return array{status:int,reason:string,headers:list<string>,forbids_payload:bool} */
	private static function normalizedCgiHeaders(string $head): array
	{
		$lines=preg_split('/\r?\n/D',$head) ?: [];
		if(count($lines)>self::MAX_HEADERS) throw new RuntimeException('Application response headers exceeded their bound.');
		$status=200;$reason='OK';$statusSeen=false;$parsed=[];$connectionTokens=[];
		foreach($lines as $line){
			if(strlen($line)>self::MAX_HEADER_LINE_BYTES){
				throw new RuntimeException('Application response header line exceeded its bound.');
			}
			if(preg_match('/^Status:[ \t]*(\d{3})(?:[ \t]+([^\r\n]{1,128}))?$/Di',$line,$match)===1){
				if($statusSeen || preg_match('/[\x00-\x1f\x7f]/D',(string)($match[2] ?? ''))===1){
					throw new RuntimeException('Application response status is invalid.');
				}
				$statusSeen=true;$status=(int)$match[1];$reason=trim((string)($match[2] ?? '')) ?: 'Response';continue;
			}
			if(preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D',$line,$match)!==1
				|| preg_match('/[\x00-\x1f\x7f]/D',$match[2])===1){
				throw new RuntimeException('Application response header is invalid.');
			}
			$name=strtolower($match[1]);$value=trim($match[2]);$parsed[]=[$name,$match[1],$value];
			if($name==='connection' && $value!==''){
				foreach(explode(',',$value) as $token){
					$token=strtolower(trim($token));
					if(preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/D',$token)!==1){
						throw new RuntimeException('Application response connection header is invalid.');
					}
					$connectionTokens[$token]=true;
				}
			}
		}
		if($status<200 || $status>599) throw new RuntimeException('Application response status is invalid.');
		$hopByHop=array_fill_keys([
			'status','connection','content-length','keep-alive','proxy-authenticate','proxy-authorization',
			'proxy-connection','te','trailer','transfer-encoding','upgrade',
		],true);$headers=[];
		foreach($parsed as [$name,$original,$value]){
			if(isset($hopByHop[$name]) || isset($connectionTokens[$name])) continue;
			$headers[]=$original.': '.$value;
		}
		return [
			'status'=>$status,'reason'=>$reason,'headers'=>$headers,
			'forbids_payload'=>in_array($status,[204,205,304],true),
		];
	}

	private static function writeCgiStreamResponse(
		mixed $connection,string $head,mixed $body,int $bodyLength,bool $headOnly,?int $writeDeadline,
	): void {
		if(!is_resource($body) || $bodyLength<0 || $bodyLength>self::MAX_DYNAMIC_RESPONSE_BODY_BYTES){
			throw new RuntimeException('Application response body is invalid.');
		}
		$normalized=self::normalizedCgiHeaders($head);$sendBody=!$headOnly && !$normalized['forbids_payload'];
		$framing="HTTP/1.1 {$normalized['status']} {$normalized['reason']}\r\n".implode("\r\n",$normalized['headers']);
		if($normalized['headers']!==[]) $framing.="\r\n";
		if(!$normalized['forbids_payload']) $framing.='Content-Length: '.$bodyLength."\r\n";
		$framing.="Connection: close\r\n\r\n";
		self::writeAll($connection,$framing,$writeDeadline);
		if(!$sendBody) return;
		rewind($body);$sent=0;
		while($sent<$bodyLength){
			$chunk=fread($body,min(65536,$bodyLength-$sent));
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Application response spool ended early.');
			self::writeAll($connection,$chunk,$writeDeadline);$sent+=strlen($chunk);
		}
	}

	public static function respond(mixed $connection,int $status,string $reason): void
	{
		if(!is_resource($connection)) return;
		$body='{"ok":false}';
		@self::writeAll(
			$connection,"HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}",
			hrtime(true)+2_000_000_000,
		);
	}

	private static function validateInvocation(string $host,int $port,string $router,string $projectRoot,string $socketPath): void
	{
		$socket=@lstat($socketPath);$directory=@lstat(self::SOCKET_DIRECTORY);
		$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());
		if($host!=='127.0.0.1' || $port!==8083 || $socketPath!==self::SOCKET
			|| is_link($router) || !is_file($router) || !hash_equals($router,(string)realpath($router))
			|| is_link($projectRoot) || !is_dir($projectRoot) || !hash_equals($projectRoot,(string)realpath($projectRoot))
			|| !is_array($socket) || (($socket['mode'] ?? 0)&0170000)!==0140000
			|| (($socket['mode'] ?? 0)&0777)!==0600 || ($socket['uid'] ?? -1)!==10001 || ($socket['gid'] ?? -1)!==10001
			|| is_link(self::SOCKET_DIRECTORY) || !is_array($directory)
			|| (($directory['mode'] ?? 0)&0170000)!==0040000 || (($directory['mode'] ?? 0)&0777)!==0711
			|| ($directory['uid'] ?? -1)!==0 || ($directory['gid'] ?? -1)!==0
			|| !hash_equals(self::SOCKET_DIRECTORY,(string)realpath(self::SOCKET_DIRECTORY))
			|| $identity['uid']!==10001 || $identity['gid']!==10001 || $identity['groups']!==[10001]
			|| $identity['cap_inheritable']!=='0000000000000000'
			|| $identity['cap_permitted']!=='0000000000000000' || $identity['cap_eff']!=='0000000000000000'
			|| !in_array($identity['cap_bounding'],['0000000000000000','00000000000000e0'],true)
			|| $identity['cap_ambient']!=='0000000000000000'
			|| $identity['no_new_privileges']!==true
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='web-http-gateway'
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='web-http-gateway'){
			throw new RuntimeException('Application web gateway invocation is invalid.');
		}
		if(!is_int($socket['dev'] ?? null) || !is_int($socket['ino'] ?? null) || $socket['ino']<1
			|| !is_int($directory['dev'] ?? null) || !is_int($directory['ino'] ?? null) || $directory['ino']<1){
			throw new RuntimeException('Application web gateway socket identity is invalid.');
		}
		self::$socketIdentity=['dev'=>$socket['dev'],'ino'=>$socket['ino']];
		self::$socketDirectoryIdentity=['dev'=>$directory['dev'],'ino'=>$directory['ino']];
	}

	private static function socketBoundaryValid(string $socketPath): bool
	{
		$socket=@lstat($socketPath);$directory=@lstat(self::SOCKET_DIRECTORY);
		$socketIdentity=self::$socketIdentity;$directoryIdentity=self::$socketDirectoryIdentity;
		return $socketPath===self::SOCKET && is_array($socketIdentity) && is_array($directoryIdentity)
			&& !is_link($socketPath) && is_array($socket) && (($socket['mode'] ?? 0)&0170000)===0140000
			&& (($socket['mode'] ?? 0)&0777)===0600 && ($socket['uid'] ?? -1)===10001 && ($socket['gid'] ?? -1)===10001
			&& ($socket['dev'] ?? null)===$socketIdentity['dev'] && ($socket['ino'] ?? null)===$socketIdentity['ino']
			&& !is_link(self::SOCKET_DIRECTORY) && is_array($directory)
			&& (($directory['mode'] ?? 0)&0170000)===0040000 && (($directory['mode'] ?? 0)&0777)===0711
			&& ($directory['uid'] ?? -1)===0 && ($directory['gid'] ?? -1)===0
			&& ($directory['dev'] ?? null)===$directoryIdentity['dev'] && ($directory['ino'] ?? null)===$directoryIdentity['ino'];
	}

	/** @param array<int,bool> $children */
	private static function reap(array &$children,bool $blocking): void
	{
		$deadline=$blocking ? microtime(true)+1.0 : 0.0;
		do{
			$reaped=false;
			while(($pid=pcntl_waitpid(-1,$status,WNOHANG))>0){unset($children[$pid]);$reaped=true;}
			if(!$blocking || $children===[]) return;
			if(!$reaped) usleep(10000);
		}while(microtime(true)<$deadline);
		if($children!==[]) throw new RuntimeException('Application web handlers could not be reaped.');
	}

	private static function remoteAddress(string $peer): string
	{
		if(preg_match('/^\[([^]]+)]:(\d+)$/D',$peer,$match)===1) return substr($match[1],0,255);
		if(preg_match('/^(.+):(\d+)$/D',$peer,$match)===1) return substr($match[1],0,255);
		return substr($peer,0,255);
	}

	private static function remotePort(string $peer): string
	{
		return preg_match('/:(\d+)$/D',$peer,$match)===1 ? $match[1] : '0';
	}

	public static function writeAll(mixed $stream,string $bytes,?int $deadline=null): void
	{
		if($deadline!==null) stream_set_blocking($stream,false);
		$offset=0;
		while($offset<strlen($bytes)){
			if($deadline!==null) self::waitForStream(
				$stream,false,$deadline,
				new DataphyreApplicationRuntimeGatewayTimeout('Application response write timed out.'),
			);
			$written=@fwrite($stream,substr($bytes,$offset,65536));
			if(!is_int($written) || $written<1) throw new RuntimeException('Application runtime stream write failed.');
			$offset+=$written;
		}
		@fflush($stream);
	}
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	if(PHP_SAPI!=='cli' || ($argc ?? 0)!==5) exit(64);
	[$script,$host,$portRaw,$router,$projectRoot]=$argv;
	if(preg_match('/^[0-9]+$/D',(string)$portRaw)!==1) exit(64);
	try{
		$consumed=DataphyreApplicationRuntimeChildEnvironment::consumeGateway('web-http-gateway');
		if($consumed['values']!==[
			'DATAPHYRE_RUNTIME_POOL'=>'web-http-gateway',
			'DATAPHYRE_RUNTIME_POOL_ROLE'=>'web-http-gateway',
		] || $consumed['managed_bootstrap']!==null){
			throw new RuntimeException('Web gateway received private application state.');
		}
		exit(DataphyreApplicationRuntimeWebGateway::run($host,(int)$portRaw,$router,$projectRoot));
	}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage()."\n");exit(70);}
}
