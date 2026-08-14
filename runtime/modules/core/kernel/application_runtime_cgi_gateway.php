<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_process_broker.php';
require_once __DIR__.'/application_runtime_scheduler_protocol.php';

/**
 * Fixed Cloud HTTP gateway. It never loads tenant PHP. Each accepted request
 * is delegated to a fresh php-cgi process with a fresh secret socketpair.
 */
final class DataphyreApplicationRuntimeCgiGateway
{
	private const MAX_CHILDREN=64;
	private const MAX_HEADER_BYTES=65536;
	private const MAX_HEADER_LINE_BYTES=8192;
	private const MAX_HEADERS=128;
	private const MAX_BODY_BYTES=268435456;
	private const MAX_RESPONSE_BYTES=268500992;
	private const MAX_SCHEDULER_CHILD_OUTPUT_BYTES=65536;
	private const REQUEST_TIMEOUT_SECONDS=300;
	private const SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS=2000;

	/** @param array<string,string> $applicationEnvironment */
	public static function run(
		string $role,string $host,int $port,string $router,string $projectRoot,array $applicationEnvironment,
		array $managedBootstrap,
	): int {
		self::validateInvocation($role,$host,$port,$router,$projectRoot);
		$listener=@stream_socket_server('tcp://'.$host.':'.$port,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
		if(!is_resource($listener)) throw new RuntimeException('Application CGI gateway could not bind its listener.');
		stream_set_blocking($listener,false);
		$stopping=false;$children=[];
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
					self::serve(
						$connection,is_string($peer) ? $peer : '',$role,$host,$port,$router,$projectRoot,
						$applicationEnvironment,$managedBootstrap,
					);
				}catch(Throwable){self::respond($connection,502,'Bad Gateway');}
				fclose($connection);exit(0);
			}
		}finally{
			fclose($listener);
			foreach(array_keys($children) as $pid) @posix_kill($pid,SIGTERM);
			$deadline=microtime(true)+5.0;
			while($children!==[] && microtime(true)<$deadline){self::reap($children,false);usleep(10000);}
			foreach(array_keys($children) as $pid) @posix_kill($pid,SIGKILL);
			self::reap($children,true);
			if(is_string($managedBootstrap['private_key'] ?? null)) sodium_memzero($managedBootstrap['private_key']);
		}
		return 0;
	}

	/** @param array<string,string> $applicationEnvironment */
	private static function serve(
		mixed $connection,string $peer,string $role,string $host,int $port,string $router,string $projectRoot,
		array $applicationEnvironment,array $managedBootstrap,
	): void {
		stream_set_blocking($connection,true);stream_set_timeout($connection,15,0);
		[$request,$body]=self::readRequest($connection);
		if($role==='scheduler' && !in_array(self::remoteAddress($peer),['127.0.0.1','::1'],true)){
			self::respond($connection,404,'Not Found');return;
		}
		$schedulerKind=null;
		if($role==='scheduler'){
			$schedulerKind=self::claimSchedulerRequest($request,$body,$applicationEnvironment);
			if($schedulerKind===null){self::respond($connection,404,'Not Found');return;}
		}
		$publicEnvironment=self::cgiEnvironment($request,$body,$peer,$host,$port,$router,$projectRoot);
			$timeoutMilliseconds=self::childTimeoutMilliseconds($role,$request,$body);
			$setpriv='/usr/bin/setpriv';$phpCgi='/usr/local/bin/php-cgi';
			$prepend=__DIR__.'/application_runtime_cgi_environment.php';
			$command=[
			$setpriv,'--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
			$phpCgi,'-d','display_errors=0','-d','log_errors=0','-d','expose_php=0',
			'-d','cgi.force_redirect=0','-d','cgi.discard_path=0',
			'-d','user_ini.filename=','-d','auto_prepend_file='.$prepend,'-d','auto_append_file=',
			'-f',$router,
		];
		$child=DataphyreApplicationRuntimeProcessBroker::spawn(
			$command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],
			$projectRoot,$publicEnvironment,$role,$applicationEnvironment,30000,$managedBootstrap,$body,
		);
			$process=$child['resource'];$pipes=$child['pipes'];$output='';$exitCode=null;$processReaped=false;
			$maximum=$schedulerKind==='callback' || $schedulerKind==='noop'
				? self::MAX_SCHEDULER_CHILD_OUTPUT_BYTES
				: self::MAX_RESPONSE_BYTES;
			try{
			stream_set_blocking($pipes[1],false);$deadline=hrtime(true)+($timeoutMilliseconds*1_000_000);
			while(hrtime(true)<$deadline){
				$chunk=fread($pipes[1],65536);
					if(is_string($chunk) && $chunk!==''){
						$output.=$chunk;
						if(strlen($output)>$maximum) throw new RuntimeException('Application CGI response exceeded its bound.');
				}
				$status=proc_get_status($process);
				if(!is_array($status)) throw new RuntimeException('Application CGI status is unavailable.');
					if(($status['running'] ?? false)!==true){
						$exitCode=(int)($status['exitcode'] ?? -1);
						$output.=(string)stream_get_contents($pipes[1]);
						if(strlen($output)>$maximum) throw new RuntimeException('Application CGI response exceeded its bound.');
					break;
				}
				$read=[$pipes[1]];$write=[];$except=[];@stream_select($read,$write,$except,0,20000);
			}
			if($exitCode===null) throw new RuntimeException('Application CGI request timed out.');
			foreach($pipes as $index=>$pipe){
				if(is_resource($pipe)) @fclose($pipe);
				unset($pipes[$index]);
			}
			@proc_close($process);$processReaped=true;
			if($exitCode!==0) throw new RuntimeException('Application CGI process failed.');
			self::writeCompletedResponse($connection,$schedulerKind,$output,$request['method']==='HEAD');
		}finally{
			foreach($pipes as $pipe) if(is_resource($pipe)) @fclose($pipe);
			if(!$processReaped){
				$status=proc_get_status($process);
				if(is_array($status) && ($status['running'] ?? false)===true){@posix_kill($child['pid'],SIGKILL);}
				@proc_close($process);
			}
			if($output!=='') sodium_memzero($output);
			if($body!=='') sodium_memzero($body);
		}
	}

	/**
	 * Consumes the root-issued request before tenant PHP starts. The child may
	 * execute application code, but it never decides whether a platform receipt
	 * exists and cannot replay the one-time supervisor claim.
	 *
	 * @param array<string,string> $applicationEnvironment
	 */
	private static function claimSchedulerRequest(array $request,string $body,array $applicationEnvironment): ?string
	{
		if(($request['method'] ?? null)!=='POST' || count($applicationEnvironment)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_ENVIRONMENT_ENTRIES){
			return null;
		}
		$path=rawurldecode((string)(parse_url((string)($request['target'] ?? '/'),PHP_URL_PATH) ?: '/'));
		$expected=match($path){
			'/dataphyre/runtime/scheduler/register'=>'registration',
			'/dataphyre/runtime/scheduler/callback'=>'callback',
			'/dataphyre/runtime/scheduler/noop'=>'noop',
			default=>null,
		};
		if($expected===null || strlen($body)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_REQUEST_BYTES) return null;
		try{$candidate=json_decode($body,true,16,JSON_THROW_ON_ERROR);}
		catch(Throwable){return null;}
		$encoded=$applicationEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'] ?? '';
		try{$publicKey=sodium_base642bin($encoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}
		catch(Throwable){$publicKey='';}
		if(!is_array($candidate)
			|| !DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($candidate,$body)
			|| ($candidate['kind'] ?? null)!==$expected
			|| !DataphyreApplicationRuntimeSchedulerProtocol::verify($candidate,$publicKey)){
			return null;
		}
		$context=stream_context_create(['http'=>[
			'method'=>'POST','timeout'=>2,'ignore_errors'=>true,
			'header'=>"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n",
			'content'=>$body,
		]]);
		$response=@file_get_contents(
			'http://127.0.0.1:8082/dataphyre/runtime/scheduler/claim',false,$context,0,
			DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES+1,
		);
		$status=null;
		foreach(($http_response_header ?? []) as $header){
			if(preg_match('/^HTTP\/\S+\s+(\d{3})\b/i',(string)$header,$match)===1){$status=(int)$match[1];break;}
		}
		$decoded=is_string($response) && strlen($response)<=DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES
			? json_decode($response,true)
			: null;
		return $status===200 && is_array($decoded) && array_keys($decoded)===['ok']
			&& ($decoded['ok'] ?? null)===true
			&& json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)===$response
			? $expected
			: null;
	}

	/** Keeps the signed scheduler budget enforceable outside tenant PHP. */
	private static function childTimeoutMilliseconds(string $role,array $request,string $body): int
	{
		if($role!=='scheduler') return self::REQUEST_TIMEOUT_SECONDS*1000;
		$path=rawurldecode((string)(parse_url((string)($request['target'] ?? '/'),PHP_URL_PATH) ?: '/'));
		try{$candidate=json_decode($body,true,16,JSON_THROW_ON_ERROR);}
		catch(Throwable){$candidate=null;}
		if(!is_array($candidate)) return self::SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS;
		$budget=match($path){
			'/dataphyre/runtime/scheduler/register'=>10000,
			'/dataphyre/runtime/scheduler/noop'=>5000,
			'/dataphyre/runtime/scheduler/callback'=>is_int($candidate['budget_milliseconds'] ?? null)
				? $candidate['budget_milliseconds']
				: 0,
			default=>0,
		};
		$budget=max(0,min(300000,$budget));
		return $budget+self::SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS;
	}

	/** @return array{0:array{method:string,target:string,protocol:string,headers:array<string,string>},1:string} */
	private static function readRequest(mixed $connection): array
	{
		$buffer='';$end=false;
		while(($end=strpos($buffer,"\r\n\r\n"))===false){
			$chunk=fread($connection,16384);
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Application request headers are incomplete.');
			$buffer.=$chunk;
			if(strlen($buffer)>self::MAX_HEADER_BYTES) throw new RuntimeException('Application request headers exceeded their bound.');
		}
		$head=substr($buffer,0,$end);$remaining=substr($buffer,$end+4);
		$lines=explode("\r\n",$head);$requestLine=array_shift($lines);
		if(!is_string($requestLine) || strlen($requestLine)>self::MAX_HEADER_LINE_BYTES
			|| preg_match('@^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS) (/[^\x00-\x20\x7f#]{0,4095}) (HTTP/1\.[01])$@D',$requestLine,$matches)!==1
			|| count($lines)>self::MAX_HEADERS){
			throw new RuntimeException('Application request line is invalid.');
		}
		$headers=[];
		foreach($lines as $line){
			if($line==='' || strlen($line)>self::MAX_HEADER_LINE_BYTES
				|| preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D',$line,$match)!==1){
				throw new RuntimeException('Application request header is invalid.');
			}
			$name=strtolower($match[1]);$value=trim($match[2]);
			if(isset($headers[$name]) || $name==='proxy') throw new RuntimeException('Application request header is ambiguous.');
			$headers[$name]=$value;
		}
		if(!isset($headers['host']) || $headers['host']==='' || isset($headers['content-length'],$headers['transfer-encoding'])){
			throw new RuntimeException('Application request framing is invalid.');
		}
		$body='';
		if(isset($headers['content-length'])){
			if(preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$headers['content-length'])!==1
				|| ($length=(int)$headers['content-length'])>self::MAX_BODY_BYTES){
				throw new RuntimeException('Application request body length is invalid.');
			}
			while(strlen($remaining)<$length){
				$chunk=fread($connection,min(65536,$length-strlen($remaining)));
				if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Application request body is incomplete.');
				$remaining.=$chunk;
			}
			if(strlen($remaining)!==$length) throw new RuntimeException('HTTP pipelining is not accepted.');
			$body=$remaining;
		}elseif(isset($headers['transfer-encoding'])){
			if(strtolower($headers['transfer-encoding'])!=='chunked') throw new RuntimeException('Application request transfer encoding is invalid.');
			while(true){
				$decoded=self::decodeChunked($remaining);
				if(is_string($decoded)){$body=$decoded;break;}
				$chunk=fread($connection,65536);
				if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Application chunked request is incomplete.');
				$remaining.=$chunk;
				if(strlen($remaining)>self::MAX_BODY_BYTES+self::MAX_HEADER_BYTES) throw new RuntimeException('Application request body exceeded its bound.');
			}
		}elseif($remaining!==''){
			throw new RuntimeException('Unframed application request body is invalid.');
		}
		return [['method'=>$matches[1],'target'=>$matches[2],'protocol'=>$matches[3],'headers'=>$headers],$body];
	}

	private static function decodeChunked(string $encoded): ?string
	{
		$offset=0;$body='';$length=strlen($encoded);
		while($offset<$length){
			$lineEnd=strpos($encoded,"\r\n",$offset);
			if($lineEnd===false) return null;
			$line=substr($encoded,$offset,$lineEnd-$offset);
			if(preg_match('/^(?:0|[1-9a-f][0-9a-f]{0,7})$/Di',$line)!==1) throw new RuntimeException('Application chunk framing is invalid.');
			$chunkLength=hexdec($line);$offset=$lineEnd+2;
			if($chunkLength===0){
				if($length<$offset+2) return null;
				if(substr($encoded,$offset,2)!=="\r\n" || $length!==$offset+2) throw new RuntimeException('Application chunk trailer is invalid.');
				return $body;
			}
			if($chunkLength>self::MAX_BODY_BYTES-strlen($body)) throw new RuntimeException('Application request body exceeded its bound.');
			if($length<$offset+$chunkLength+2) return null;
			$body.=substr($encoded,$offset,$chunkLength);$offset+=$chunkLength;
			if(substr($encoded,$offset,2)!=="\r\n") throw new RuntimeException('Application chunk delimiter is invalid.');
			$offset+=2;
		}
		return null;
	}

	/** @return array<string,string> */
	private static function cgiEnvironment(
		array $request,string $body,string $peer,string $host,int $port,string $router,string $projectRoot,
	): array {
		$target=$request['target'];$question=strpos($target,'?');
		$path=$question===false ? $target : substr($target,0,$question);
		$query=$question===false ? '' : substr($target,$question+1);
		$environment=[
			'GATEWAY_INTERFACE'=>'CGI/1.1','SERVER_SOFTWARE'=>'Dataphyre-Cloud',
			'SERVER_PROTOCOL'=>$request['protocol'],'REQUEST_METHOD'=>$request['method'],'REQUEST_URI'=>$target,
			'SCRIPT_FILENAME'=>$router,'SCRIPT_NAME'=>$path,'DOCUMENT_ROOT'=>$projectRoot.'/public',
			'QUERY_STRING'=>$query,'REDIRECT_STATUS'=>'200','REMOTE_ADDR'=>self::remoteAddress($peer),
			'REMOTE_PORT'=>self::remotePort($peer),'SERVER_ADDR'=>$host,'SERVER_PORT'=>(string)$port,
			'SERVER_NAME'=>substr($request['headers']['host'],0,255),'CONTENT_LENGTH'=>(string)strlen($body),
		];
		foreach($request['headers'] as $name=>$value){
			if($name==='content-type'){$environment['CONTENT_TYPE']=$value;continue;}
			if(in_array($name,['content-length','transfer-encoding','connection','proxy'],true)) continue;
			$key='HTTP_'.strtoupper(str_replace('-','_',$name));
			if(preg_match('/^HTTP_[A-Z0-9_]{1,119}$/D',$key)===1) $environment[$key]=$value;
		}
		ksort($environment,SORT_STRING);return $environment;
	}

	/** Emits scheduler success only from this trusted parent after the child was reaped. */
	private static function writeCompletedResponse(mixed $connection,?string $schedulerKind,string $output,bool $headOnly): void
	{
		if($schedulerKind==='callback' || $schedulerKind==='noop'){
			$contract=$schedulerKind==='callback' ? 'dataphyre.scheduler_callback.v1' : 'dataphyre.scheduler_noop.v1';
			$body=json_encode(['contract'=>$contract,'ok'=>true],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
			$payload=$headOnly ? '' : $body;
			self::writeAll(
				$connection,
				"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nCache-Control: no-store\r\n".
				'Content-Length: '.strlen($payload)."\r\nConnection: close\r\n\r\n".$payload,
			);
			return;
		}
		self::writeCgiResponse($connection,$output,$headOnly);
	}

	private static function writeCgiResponse(mixed $connection,string $output,bool $headOnly): void
	{
		$end=strpos($output,"\r\n\r\n");
		if($end===false || $end>self::MAX_HEADER_BYTES) throw new RuntimeException('Application CGI response headers are invalid.');
		$head=substr($output,0,$end);$body=substr($output,$end+4);$status=200;$reason='OK';$headers=[];
		foreach(explode("\r\n",$head) as $line){
			if(preg_match('/^Status:\s*(\d{3})(?:\s+([^\r\n]{1,128}))?$/Di',$line,$match)===1){
				$status=(int)$match[1];$reason=trim((string)($match[2] ?? '')) ?: 'Response';continue;
			}
			if(preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D',$line,$match)!==1){
				throw new RuntimeException('Application CGI response header is invalid.');
			}
			$name=strtolower($match[1]);
			if(in_array($name,['status','connection','transfer-encoding','content-length'],true)) continue;
			$headers[]=$match[1].': '.trim($match[2]);
		}
		if($status<100 || $status>599) throw new RuntimeException('Application CGI response status is invalid.');
		$payload=$headOnly ? '' : $body;
		$response="HTTP/1.1 {$status} {$reason}\r\n".implode("\r\n",$headers)
			.(count($headers)>0 ? "\r\n" : '').'Content-Length: '.strlen($payload)."\r\nConnection: close\r\n\r\n".$payload;
		self::writeAll($connection,$response);
	}

	private static function respond(mixed $connection,int $status,string $reason): void
	{
		if(!is_resource($connection)) return;
		$body='{"ok":false}';
		@self::writeAll($connection,"HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
	}

	private static function validateInvocation(string $role,string $host,int $port,string $router,string $projectRoot): void
	{
		$valid=($role==='web' && $host==='127.0.0.1' && $port===8083)
			|| ($role==='scheduler' && $host==='127.0.0.1' && $port===8081);
		if(!$valid || is_link($router) || !is_file($router) || !hash_equals($router,(string)realpath($router))
			|| is_link($projectRoot) || !is_dir($projectRoot) || !hash_equals($projectRoot,(string)realpath($projectRoot))){
			throw new RuntimeException('Application CGI gateway invocation is invalid.');
		}
	}

	/** @param array<int,bool> $children */
	private static function reap(array &$children,bool $blocking): void
	{
		do{
			$pid=pcntl_waitpid(-1,$status,$blocking ? 0 : WNOHANG);
			if($pid>0) unset($children[$pid]);
		}while($pid>0 && !$blocking);
		if($blocking) $children=[];
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

	private static function writeAll(mixed $stream,string $bytes): void
	{
		$offset=0;
		while($offset<strlen($bytes)){
			$written=@fwrite($stream,substr($bytes,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('Application runtime stream write failed.');
			$offset+=$written;
		}
		@fflush($stream);
	}
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	if(PHP_SAPI!=='cli' || ($argc ?? 0)!==6) exit(64);
	[$script,$role,$host,$portRaw,$router,$projectRoot]=$argv;
	if(preg_match('/^[0-9]+$/D',(string)$portRaw)!==1) exit(64);
	try{
		$consumed=DataphyreApplicationRuntimeChildEnvironment::consumeGateway($role.'-gateway');
		$applicationEnvironment=$consumed['values'];$managedBootstrap=$consumed['managed_bootstrap'];
		foreach(array_keys($applicationEnvironment) as $name){@putenv($name);unset($_ENV[$name],$_SERVER[$name]);}
		unset($applicationEnvironment['DATAPHYRE_RUNTIME_POOL'],$applicationEnvironment['DATAPHYRE_RUNTIME_POOL_ROLE']);
		exit(DataphyreApplicationRuntimeCgiGateway::run(
			$role,$host,(int)$portRaw,$router,$projectRoot,$applicationEnvironment,$managedBootstrap,
		));
	}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage()."\n");exit(70);}
}
