<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/application_runtime_web_gateway.php';
require_once __DIR__.'/application_runtime_scheduler_protocol.php';

/** Root claim gateway that preserves one fresh privilege-dropped php-cgi child per scheduler request. */
final class DataphyreApplicationRuntimeSchedulerGatewayInterrupted extends RuntimeException {}

final class DataphyreApplicationRuntimeSchedulerGateway
{
	public const SOCKET='/run/dataphyre/scheduler/gateway.sock';
	private const SOCKET_DIRECTORY='/run/dataphyre/scheduler';
	private const CONTROL_SOCKET='/run/dataphyre/control/runtime.sock';
	// The supervisor multiplexes one signed socket per due definition.  The
	// fixed bound is deliberately internal: it matches the measured callback
	// burst required by the framework's cadence contract without becoming a
	// deployment knob or a user-visible runtime concept.
	private const MAX_CHILDREN=32;
	private const MAX_SCHEDULER_CHILD_OUTPUT_BYTES=65536;
	private const MAX_SCHEDULER_REGISTRATION_OUTPUT_BYTES=
		DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES+65536;
	private const SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS=2000;
	private const CLIENT_READ_TIMEOUT_MILLISECONDS=2000;
	private const CLIENT_WRITE_TIMEOUT_MILLISECONDS=2000;

	/** @param array<string,string> $applicationEnvironment */
	public static function run(
		string $socketPath,string $router,string $projectRoot,array $applicationEnvironment,array $managedBootstrap,
	): int {
		self::validateInvocation($socketPath,$router,$projectRoot);
		self::enableChildSubreaper();
		$previousUmask=umask(0077);
		try{$listener=@stream_socket_server('unix://'.$socketPath,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);}
		finally{umask($previousUmask);}
		if(!is_resource($listener)) throw new RuntimeException('Application scheduler gateway could not bind its listener.');
		$socket=@lstat($socketPath);$socketIdentity=is_array($socket)
			&& (($socket['mode'] ?? 0)&0170000)===0140000 && ($socket['uid'] ?? -1)===0 && ($socket['gid'] ?? -1)===0
			&& is_int($socket['dev'] ?? null) && is_int($socket['ino'] ?? null) && $socket['ino']>0
			? ['dev'=>$socket['dev'],'ino'=>$socket['ino']] : null;
		if(!is_array($socketIdentity) || !@chmod($socketPath,0600)){
			fclose($listener);self::cleanupSocket($socketPath,$socketIdentity);
			throw new RuntimeException('Application scheduler gateway socket boundary is invalid.');
		}
		$socket=@lstat($socketPath);
		if(!is_array($socket) || (($socket['mode'] ?? 0)&0170000)!==0140000 || (($socket['mode'] ?? 0)&0777)!==0600
			|| ($socket['uid'] ?? -1)!==0 || ($socket['gid'] ?? -1)!==0
			|| ($socket['dev'] ?? null)!==$socketIdentity['dev'] || ($socket['ino'] ?? null)!==$socketIdentity['ino']){
			fclose($listener);self::cleanupSocket($socketPath,$socketIdentity);
			throw new RuntimeException('Application scheduler gateway socket attestation failed.');
		}
		stream_set_blocking($listener,false);$stopping=false;$children=[];
		pcntl_async_signals(true);
		$stop=static function() use (&$stopping): void {$stopping=true;};
		pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);
		try{
			while(!$stopping){
				self::reap($children,false);
				self::terminateAdoptedChildren(array_keys($children));
				if(count($children)>=self::MAX_CHILDREN){usleep(10000);continue;}
				$connection=@stream_socket_accept($listener,0.05);
				if(!is_resource($connection)) continue;
				$pid=pcntl_fork();
				if($pid===-1){DataphyreApplicationRuntimeWebGateway::respond($connection,503,'Service Unavailable');fclose($connection);continue;}
				if($pid>0){$children[$pid]=true;fclose($connection);continue;}
				fclose($listener);pcntl_async_signals(true);
				$interrupt=static function(): void {
					throw new DataphyreApplicationRuntimeSchedulerGatewayInterrupted('Scheduler handler interrupted.');
				};
				pcntl_signal(SIGTERM,$interrupt);pcntl_signal(SIGINT,$interrupt);
				try{
					self::enableChildSubreaper();
					self::serve(
						$connection,'127.0.0.1:0','127.0.0.1',8081,$router,$projectRoot,
						$applicationEnvironment,$managedBootstrap,
					);
				}catch(DataphyreApplicationRuntimeSchedulerGatewayInterrupted){}
				catch(Throwable){DataphyreApplicationRuntimeWebGateway::respond($connection,502,'Bad Gateway');}
				if(is_resource($connection)){fclose($connection);$connection=null;}
				exit(0);
			}
		}finally{
			fclose($listener);
			foreach(array_keys($children) as $pid) @posix_kill($pid,SIGTERM);
			$deadline=microtime(true)+5.0;
			while($children!==[] && microtime(true)<$deadline){self::reap($children,false);usleep(10000);}
			foreach(array_keys($children) as $pid) @posix_kill($pid,SIGKILL);
			self::reap($children,true);
			self::terminateAdoptedChildren([]);
			self::cleanupSocket($socketPath,$socketIdentity);
			if(is_string($managedBootstrap['private_key'] ?? null)) sodium_memzero($managedBootstrap['private_key']);
		}
		return 0;
	}

	/** @param array<string,string> $applicationEnvironment */
	private static function serve(
		mixed $connection,string $peer,string $host,int $port,string $router,string $projectRoot,
		array $applicationEnvironment,array $managedBootstrap,
	): void {
		[$request,$body]=self::readSchedulerRequest($connection);
		$schedulerKind=self::claimSchedulerRequest($request,$body,$applicationEnvironment);
		if($schedulerKind===null){DataphyreApplicationRuntimeWebGateway::respond($connection,404,'Not Found');return;}
		$publicEnvironment=DataphyreApplicationRuntimeWebGateway::requestEnvironment(
			$request,strlen($body),$peer,$host,$port,$router,$projectRoot,
		);
		$timeoutMilliseconds=self::childTimeoutMilliseconds($request,$body);
		$command=[
			'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
			// The root gateway already has the exact e0 bounding set. CAP_SETPCAP
			// is intentionally absent, so the child retains only that inert bound
			// while UID 10001 + NNP clear every permitted/effective capability.
			'--inh-caps=-all','--ambient-caps=-all','--pdeathsig=SIGKILL',
			'/usr/bin/prlimit','--nproc=0:0',
			'/usr/local/bin/php-cgi','-d','display_errors=0','-d','log_errors=0','-d','expose_php=0',
			'-d','cgi.force_redirect=0','-d','cgi.discard_path=0','-d','user_ini.filename=',
			'-d','auto_prepend_file='.__DIR__.'/application_runtime_cgi_environment.php','-d','auto_append_file=',
			'-f',$router,
		];
		$previousMask=[];
		if(!pcntl_sigprocmask(SIG_BLOCK,[SIGTERM,SIGINT],$previousMask)){
			throw new RuntimeException('Application scheduler cleanup signals could not be blocked.');
		}
		$baselineChildren=self::directChildren();
		try{
			$child=DataphyreApplicationRuntimeProcessBroker::spawn(
				$command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],
				$projectRoot,$publicEnvironment,'scheduler',$applicationEnvironment,30000,$managedBootstrap,$body,true,
			);
		}catch(Throwable $failure){
			try{self::terminateAdoptedChildren($baselineChildren);}
			catch(Throwable $cleanupFailure){
				@pcntl_sigprocmask(SIG_SETMASK,$previousMask);
				throw new RuntimeException('Application scheduler adopted-child cleanup failed.',0,$cleanupFailure);
			}
			@pcntl_sigprocmask(SIG_SETMASK,$previousMask);throw $failure;
		}
		$process=$child['resource'];$pipes=$child['pipes'];$output='';$exitCode=null;
		$maximum=$schedulerKind==='callback' || $schedulerKind==='noop'
			? self::MAX_SCHEDULER_CHILD_OUTPUT_BYTES
			: self::MAX_SCHEDULER_REGISTRATION_OUTPUT_BYTES;
		try{
			try{
				if(!pcntl_sigprocmask(SIG_SETMASK,$previousMask)){
					throw new RuntimeException('Application scheduler cleanup signals could not be restored.');
				}
				stream_set_blocking($pipes[1],false);$deadline=hrtime(true)+($timeoutMilliseconds*1_000_000);
				while(hrtime(true)<$deadline){
					$chunk=fread($pipes[1],65536);
					if(is_string($chunk) && $chunk!==''){
						$output.=$chunk;
						if(strlen($output)>$maximum) throw new RuntimeException('Application scheduler response exceeded its bound.');
					}
					$status=proc_get_status($process);
					if(!is_array($status)) throw new RuntimeException('Application scheduler status is unavailable.');
					if(($status['running'] ?? false)!==true){
						$exitCode=(int)($status['exitcode'] ?? -1);
						while(true){
							$chunk=fread($pipes[1],65536);
							if(!is_string($chunk) || $chunk==='') break;
							$output.=$chunk;
							if(strlen($output)>$maximum) throw new RuntimeException('Application scheduler response exceeded its bound.');
						}
						break;
					}
					$read=[$pipes[1]];$write=[];$except=[];@stream_select($read,$write,$except,0,20000);
				}
				if($exitCode===null) throw new RuntimeException('Application scheduler request timed out.');
			}finally{
				@pcntl_sigprocmask(SIG_BLOCK,[SIGTERM,SIGINT]);
				self::terminateCgiGroup($child,$process,$pipes,$baselineChildren);
				@pcntl_sigprocmask(SIG_SETMASK,$previousMask);
			}
			if($exitCode!==0) throw new RuntimeException('Application scheduler process failed.');
			self::writeCompletedResponse($connection,$schedulerKind,$output,$request['method']==='HEAD');
		}finally{
			if($output!=='') sodium_memzero($output);
			if($body!=='') sodium_memzero($body);
		}
	}

	/** @return array{0:array{method:string,target:string,protocol:string,headers:array<string,string>},1:string} */
	private static function readSchedulerRequest(mixed $connection): array
	{
		return DataphyreApplicationRuntimeWebGateway::readRequest(
			$connection,DataphyreApplicationRuntimeSchedulerProtocol::MAX_REQUEST_BYTES,
			DataphyreApplicationRuntimeSchedulerProtocol::MAX_REQUEST_BYTES,false,
			self::CLIENT_READ_TIMEOUT_MILLISECONDS,self::CLIENT_READ_TIMEOUT_MILLISECONDS,
		);
	}

	/** @param array<int,resource> $pipes */
	/** @param list<int> $baselineChildren */
	private static function terminateCgiGroup(array $child,mixed $process,array &$pipes,array $baselineChildren): void
	{
		$pid=$child['pid'] ?? null;$group=$child['process_group_id'] ?? null;$failure=null;
		foreach($pipes as $index=>$pipe){if(is_resource($pipe)) @fclose($pipe);unset($pipes[$index]);}
		try{
			if(!is_int($pid) || $pid<2 || !is_int($group) || $group!==$pid){
				throw new RuntimeException('Application scheduler process group is invalid.');
			}
			self::signalProcessGroup($group,SIGTERM);
			$deadline=microtime(true)+0.25;
			while(self::runnableProcessGroupMembers($group)!==[] && microtime(true)<$deadline) usleep(10000);
			if(self::runnableProcessGroupMembers($group)!==[]){
				self::signalProcessGroup($group,SIGKILL);$deadline=microtime(true)+0.25;
				while(self::runnableProcessGroupMembers($group)!==[] && microtime(true)<$deadline) usleep(10000);
			}
			if(self::runnableProcessGroupMembers($group)!==[]){
				throw new RuntimeException('Application scheduler process group survived cleanup.');
			}
		}catch(Throwable $caught){$failure=$caught;
			if(is_int($group) && $group>1) @posix_kill(-$group,SIGKILL);
			if(is_int($pid) && $pid>1) @posix_kill($pid,SIGKILL);
		}
		@proc_close($process);
		try{self::terminateAdoptedChildren($baselineChildren);}catch(Throwable $caught){if($failure===null) $failure=$caught;}
		if($failure!==null) throw $failure;
	}

	private static function enableChildSubreaper(): void
	{
		if(!function_exists('dataphyre_enable_scheduler_child_subreaper')
			|| dataphyre_enable_scheduler_child_subreaper()!==true){
			throw new RuntimeException('Application scheduler child-subreaper boundary is unavailable.');
		}
	}

	/** @param list<int> $allowed */
	private static function terminateAdoptedChildren(array $allowed): void
	{
		$allowedMap=[];
		foreach($allowed as $pid){
			if(!is_int($pid) || $pid<2) throw new RuntimeException('Application scheduler child allowlist is invalid.');
			$allowedMap[$pid]=true;
		}
		$termDeadline=microtime(true)+0.25;$killDeadline=$termDeadline+0.5;
		do{
			$targets=[];
			foreach(self::directChildren() as $pid) if(!isset($allowedMap[$pid])) $targets[]=$pid;
			foreach($targets as $pid) @pcntl_waitpid($pid,$status,WNOHANG);
			$targets=[];
			foreach(self::directChildren() as $pid) if(!isset($allowedMap[$pid])) $targets[]=$pid;
			if($targets===[]) return;
			$signal=microtime(true)<$termDeadline ? SIGTERM : SIGKILL;
			foreach($targets as $pid) self::signalDirectChild($pid,$signal);
			usleep(10000);
		}while(microtime(true)<$killDeadline);
		foreach(self::directChildren() as $pid){
			if(!isset($allowedMap[$pid])) throw new RuntimeException('Application scheduler adopted child survived cleanup.');
		}
	}

	/** @return list<int> */
	private static function directChildren(): array
	{
		$bytes=@file_get_contents('/proc/thread-self/children');
		if(!is_string($bytes) || strlen($bytes)>32768){
			throw new RuntimeException('Application scheduler child inventory is unavailable.');
		}
		$pids=[];
		foreach(preg_split('/\s+/',trim($bytes),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $candidate){
			if(preg_match('/^[1-9][0-9]{0,9}$/D',$candidate)!==1 || ($pid=(int)$candidate)<2){
				throw new RuntimeException('Application scheduler child inventory is invalid.');
			}
			$pids[]=$pid;
		}
		if(count($pids)>1024 || count($pids)!==count(array_unique($pids))){
			throw new RuntimeException('Application scheduler child inventory exceeded its bound.');
		}
		sort($pids,SORT_NUMERIC);return $pids;
	}

	private static function signalDirectChild(int $pid,int $signal): void
	{
		if($pid<2 || !in_array($signal,[SIGTERM,SIGKILL],true)) return;
		try{$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity($pid);}catch(Throwable){return;}
		if(($identity['parent_pid'] ?? null)!==getmypid()) return;
		@posix_kill($pid,$signal);
	}

	private static function signalProcessGroup(int $group,int $signal): void
	{
		if($group<2 || !in_array($signal,[SIGTERM,SIGKILL],true)){
			throw new RuntimeException('Application scheduler process-group signal is invalid.');
		}
		if(function_exists('posix_clear_last_error')) posix_clear_last_error();
		if(@posix_kill(-$group,$signal)) return;
		$error=posix_get_last_error();
		if($error!==3) throw new RuntimeException('Application scheduler process group could not be signaled.');
	}

	/** @return list<int> */
	private static function runnableProcessGroupMembers(int $group): array
	{
		$entries=@scandir('/proc');
		if(!is_array($entries) || count($entries)>65536){
			throw new RuntimeException('Application scheduler process inventory is unavailable.');
		}
		$members=[];
		foreach($entries as $entry){
			if(!ctype_digit($entry) || ($pid=(int)$entry)<2) continue;
			$stat=@file_get_contents('/proc/'.$entry.'/stat');$separator=is_string($stat) ? strrpos($stat,') ') : false;
			if(!is_int($separator)) continue;
			$fields=explode(' ',substr($stat,$separator+2));
			if((int)($fields[2] ?? 0)===$group && !in_array($fields[0] ?? '',['Z','X'],true)) $members[]=$pid;
		}
		sort($members,SORT_NUMERIC);return $members;
	}

	/** @param array<string,string> $applicationEnvironment */
	private static function claimSchedulerRequest(array $request,string $body,array $applicationEnvironment): ?string
	{
		if(($request['method'] ?? null)!=='POST' || count($applicationEnvironment)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_ENVIRONMENT_ENTRIES) return null;
		$path=rawurldecode((string)(parse_url((string)($request['target'] ?? '/'),PHP_URL_PATH) ?: '/'));
		$expected=match($path){
			'/dataphyre/runtime/scheduler/register'=>'registration',
			'/dataphyre/runtime/scheduler/callback'=>'callback',
			'/dataphyre/runtime/scheduler/noop'=>'noop',
			default=>null,
		};
		if($expected===null || strlen($body)>DataphyreApplicationRuntimeSchedulerProtocol::MAX_REQUEST_BYTES) return null;
		try{$candidate=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
		$encoded=$applicationEnvironment['DATAPHYRE_RUNTIME_SCHEDULER_PUBLIC_KEY'] ?? '';
		try{$publicKey=sodium_base642bin($encoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}catch(Throwable){$publicKey='';}
		if(!is_array($candidate) || !DataphyreApplicationRuntimeSchedulerProtocol::matchesCanonicalJson($candidate,$body)
			|| ($candidate['kind'] ?? null)!==$expected || !DataphyreApplicationRuntimeSchedulerProtocol::verify($candidate,$publicKey)) return null;
		$socket=@stream_socket_client('unix://'.self::CONTROL_SOCKET,$errno,$error,2,STREAM_CLIENT_CONNECT);
		if(!is_resource($socket)) return null;
		try{
			$request="POST /dataphyre/runtime/scheduler/claim HTTP/1.1\r\nHost: dataphyre-control\r\n".
				"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}";
			$deadline=hrtime(true)+2_000_000_000;
			DataphyreApplicationRuntimeWebGateway::writeAll($socket,$request,$deadline);
			stream_socket_shutdown($socket,STREAM_SHUT_WR);stream_set_blocking($socket,false);$wire='';
			while(!feof($socket)){
				$remaining=$deadline-hrtime(true);if($remaining<=0) return null;
				$read=[$socket];$write=[];$except=[];
				$selected=@stream_select($read,$write,$except,intdiv($remaining,1_000_000_000),intdiv($remaining%1_000_000_000,1000));
				if($selected===false) return null;
				if($selected===0) continue;
				$chunk=@fread($socket,8192);if($chunk===false) return null;
				$wire.=$chunk;if(strlen($wire)>8192) return null;
			}
		}finally{if(is_resource($socket)){fclose($socket);$socket=null;}}
		[$head,$response]=array_pad(explode("\r\n\r\n",$wire,2),2,'');
		$status=preg_match('/^HTTP\/1\.[01]\s+(\d{3})\b/D',$head,$match)===1 ? (int)$match[1] : null;
		$decoded=strlen($response)<=DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES
			? json_decode($response,true) : null;
		return $status===200 && is_array($decoded) && array_keys($decoded)===['ok'] && ($decoded['ok'] ?? null)===true
			&& json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)===$response
			? $expected : null;
	}

	private static function childTimeoutMilliseconds(array $request,string $body): int
	{
		$path=rawurldecode((string)(parse_url((string)($request['target'] ?? '/'),PHP_URL_PATH) ?: '/'));
		try{$candidate=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){$candidate=null;}
		if(!is_array($candidate)) return self::SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS;
		$budget=match($path){
			'/dataphyre/runtime/scheduler/register'=>10000,'/dataphyre/runtime/scheduler/noop'=>5000,
			'/dataphyre/runtime/scheduler/callback'=>is_int($candidate['budget_milliseconds'] ?? null) ? $candidate['budget_milliseconds'] : 0,
			default=>0,
		};
		return max(0,min(300000,$budget))+self::SCHEDULER_TRANSPORT_MARGIN_MILLISECONDS;
	}

	private static function writeCompletedResponse(mixed $connection,string $schedulerKind,string $output,bool $headOnly): void
	{
		$writeDeadline=hrtime(true)+(self::CLIENT_WRITE_TIMEOUT_MILLISECONDS*1_000_000);
		if($schedulerKind==='callback' || $schedulerKind==='noop'){
			$contract=$schedulerKind==='callback' ? 'dataphyre.scheduler_callback.v1' : 'dataphyre.scheduler_noop.v1';
			$body=json_encode(['contract'=>$contract,'ok'=>true],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
			$payload=$headOnly ? '' : $body;
			DataphyreApplicationRuntimeWebGateway::writeAll(
				$connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nCache-Control: no-store\r\n".
				'Content-Length: '.strlen($payload)."\r\nConnection: close\r\n\r\n".$payload,
				$writeDeadline,
			);return;
		}
		DataphyreApplicationRuntimeWebGateway::writeCgiResponse($connection,$output,$headOnly,$writeDeadline);
	}

	private static function validateInvocation(string $socketPath,string $router,string $projectRoot): void
	{
		$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());$directory=@lstat(self::SOCKET_DIRECTORY);
		if($socketPath!==self::SOCKET || file_exists($socketPath) || is_link($socketPath)
			|| !is_array($directory) || (($directory['mode'] ?? 0)&0170000)!==0040000
			|| (($directory['mode'] ?? 0)&0777)!==0700 || ($directory['uid'] ?? -1)!==0 || ($directory['gid'] ?? -1)!==0
			|| is_link(self::SOCKET_DIRECTORY) || !hash_equals(self::SOCKET_DIRECTORY,(string)realpath(self::SOCKET_DIRECTORY))
			|| is_link($router) || !is_file($router) || !hash_equals($router,(string)realpath($router))
			|| is_link($projectRoot) || !is_dir($projectRoot) || !hash_equals($projectRoot,(string)realpath($projectRoot))
			|| $identity['uid']!==0 || $identity['gid']!==0 || $identity['groups']!==[0]
			|| $identity['cap_inheritable']!=='0000000000000000'
			|| $identity['cap_permitted']!=='00000000000000e0' || $identity['cap_eff']!=='00000000000000e0'
			|| $identity['cap_bounding']!=='00000000000000e0' || $identity['cap_ambient']!=='0000000000000000'
			|| $identity['no_new_privileges']!==true
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='scheduler-gateway'
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='scheduler-gateway'){
			throw new RuntimeException('Application scheduler gateway invocation is invalid.');
		}
	}

	/** @param null|array{dev:int,ino:int} $identity */
	private static function cleanupSocket(string $socketPath,?array $identity): void
	{
		if($socketPath!==self::SOCKET || !is_array($identity)) return;
		$socket=@lstat($socketPath);
		if(is_array($socket) && !is_link($socketPath) && (($socket['mode'] ?? 0)&0170000)===0140000
			&& in_array(($socket['mode'] ?? 0)&0777,[0600,0700],true)
			&& ($socket['uid'] ?? -1)===0 && ($socket['gid'] ?? -1)===0
			&& ($socket['dev'] ?? null)===$identity['dev'] && ($socket['ino'] ?? null)===$identity['ino']){
			@unlink($socketPath);
		}
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
		if($children!==[]) throw new RuntimeException('Application scheduler handlers could not be reaped.');
	}

	private static function remoteAddress(string $peer): string
	{
		if(preg_match('/^\[([^]]+)]:(\d+)$/D',$peer,$match)===1) return substr($match[1],0,255);
		if(preg_match('/^(.+):(\d+)$/D',$peer,$match)===1) return substr($match[1],0,255);
		return substr($peer,0,255);
	}
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	if(PHP_SAPI!=='cli' || ($argc ?? 0)!==4) exit(64);
	[$script,$socketPath,$router,$projectRoot]=$argv;
	try{
		$consumed=DataphyreApplicationRuntimeChildEnvironment::consumeGateway('scheduler-gateway');
		exit(DataphyreApplicationRuntimeSchedulerGateway::run(
			$socketPath,$router,$projectRoot,$consumed['values'],$consumed['managed_bootstrap'],
		));
	}catch(Throwable $failure){fwrite(STDERR,$failure->getMessage()."\n");exit(70);}
}
