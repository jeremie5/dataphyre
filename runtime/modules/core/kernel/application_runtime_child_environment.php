<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
/**
 * Root-owned, single-use post-exec application environment broker.
 *
 * The environment is never published at a path and never enters execve argv
 * or envp. A trusted root launcher creates one unnamed AF_UNIX socketpair for
 * one final process, maps only the child endpoint to fixed descriptor 198, then sends
 * one envelope bound to the child's immutable Linux process identity. The
 * child acknowledges once, closes the descriptor, and cannot fetch again.
 */
final class DataphyreApplicationRuntimeChildEnvironment
{
	public const CONTRACT='dataphyre.application_child_environment.v3';
	public const ACK_CONTRACT='dataphyre.application_child_environment_ack.v1';
	public const MANAGED_BOOTSTRAP_CONTRACT='dataphyre.managed_runtime_bootstrap.v1';
	public const ONE_SHOT_MATERIALIZER_BOOTSTRAP_CONTRACT='dataphyre.one_shot_materializer_bootstrap.v1';
	public const INHERITED_FD=198;
	public const MAX_BYTES=524288;
	public const MAX_ENTRIES=576;
	private const MAX_ANCESTORS=16;
	private const MAX_ACK_BYTES=512;
	private const POOL_UID=10001;
	private const POOL_GID=10001;
	private static bool $consumed=false;
	private static bool $managedWebPoolRequest=false;
	/** @var null|array{contract:string,role:string,project_root:string,private_key:string} */
	private static ?array $managedBootstrap=null;
	private static ?array $oneShotMaterializerBootstrap=null;

	/** @return array{0:resource,1:resource} */
	public static function socketPair(): array
	{
		$pair=@stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
		if(!is_array($pair) || count($pair)!==2 || !is_resource($pair[0]) || !is_resource($pair[1])){
			throw new RuntimeException('Child environment socketpair is unavailable.');
		}
		foreach($pair as $channel){
			stream_set_blocking($channel,true);
			stream_set_read_buffer($channel,0);
			stream_set_write_buffer($channel,0);
		}
		return [$pair[0],$pair[1]];
	}

	/**
	 * Sends exactly one envelope to one already-execed privilege-dropped child
	 * and requires its exact canonical acknowledgement.
	 *
	 * @param resource $channel
	 * @param array<string,string> $values
	 * @return array{pid:int,start_time_ticks:string,ancestry:list<array{pid:int,start_time_ticks:string}>}
	 */
	public static function broker(
		mixed $channel,
		int $pid,
		int $expectedParentPid,
		string $role,
		array $values,
		int $timeoutMilliseconds=5000,
		?array $managedBootstrap=null,
	): array {
		if(!is_resource($channel) || $pid<2 || $expectedParentPid<1
			|| !function_exists('posix_geteuid') || posix_geteuid()!==0
			|| $timeoutMilliseconds<100 || $timeoutMilliseconds>30000){
			throw new RuntimeException('Child environment broker invocation is invalid.');
		}
		self::validateRole($role);self::validateValues($values);self::validateManagedBootstrap($managedBootstrap,$role,$values);
		$deadline=microtime(true)+($timeoutMilliseconds/1000);
		$target=null;
		do{
			try{
				$candidate=self::target($pid,$expectedParentPid);
				if(self::privilegeBoundary($pid,$role)){$target=$candidate;break;}
			}catch(Throwable){}
			usleep(1000);
		}while(microtime(true)<$deadline);
		if(!is_array($target)) throw new RuntimeException('Child environment target did not reach its privilege boundary.');
		$nonce=bin2hex(random_bytes(32));
		$bytes=self::canonical($role,$nonce,$target,$values,$managedBootstrap);
		stream_set_timeout($channel,max(1,(int)ceil($timeoutMilliseconds/1000)),0);
		try{
			self::writeAll($channel,sprintf("%08x\n",strlen($bytes)).$bytes);
			$ack=fgets($channel,self::MAX_ACK_BYTES+1);
			$expected=self::canonicalAck($nonce,$target['pid'],$target['start_time_ticks']);
			if(!is_string($ack) || strlen($ack)>self::MAX_ACK_BYTES || !hash_equals($expected,$ack)){
				throw new RuntimeException('Child environment acknowledgement is invalid.');
			}
			return $target;
		}finally{
			sodium_memzero($bytes);
			if(isset($ack) && is_string($ack)) sodium_memzero($ack);
			if(is_resource($channel)) @fclose($channel);
		}
	}

	/**
	 * Consumes descriptor 198 before any tenant bootstrap, projects the values
	 * process-locally, acknowledges, zeroizes the transport, and closes it.
	 *
	 * @return array<string,string>
	 */
	public static function consumeInherited(string $role,int $fd=self::INHERITED_FD): array
	{
		$consumed=self::consumeEnvelope($role,$fd);
		if(in_array($role,['web','scheduler','realtime'],true)){
			self::establishManagedBootstrap($consumed['managed_bootstrap'],$role,$consumed['values']);
		}
		return $consumed['values'];
	}

	/**
	 * Activates one request in the fixed native FPM pool after RINIT restored the
	 * sealed application environment. The master-only envelope is never reopened.
	 *
	 * @return array<string,string>
	 */
	public static function activateManagedWebPoolRequest(): array
	{
		if(self::$managedWebPoolRequest || PHP_SAPI!=='fpm-fcgi'
			|| !function_exists('dataphyre_managed_pool_request_context')){
			throw new RuntimeException('Managed web-pool request boundary is unavailable.');
		}
		$native=dataphyre_managed_pool_request_context();
		if(!is_array($native)
			|| array_keys($native)!==[
				'contract','role','project_root','master_pid','worker_pid','managed_bootstrap','environment',
			]
			|| ($native['contract'] ?? null)!=='dataphyre.managed_php_web_request.v1'
			|| ($native['role'] ?? null)!=='web'
			|| !is_string($native['project_root'] ?? null)
			|| !is_int($native['master_pid'] ?? null) || $native['master_pid']<2
			|| !is_int($native['worker_pid'] ?? null) || $native['worker_pid']!==getmypid()
			|| posix_getppid()!==$native['master_pid']
			|| !is_array($native['managed_bootstrap'] ?? null)
			|| !is_array($native['environment'] ?? null)){
			throw new RuntimeException('Managed web-pool request context is invalid.');
		}
		$values=$native['environment'];
		$managedBootstrap=&$native['managed_bootstrap'];
		self::validateValues($values);
		self::validateManagedBootstrap($managedBootstrap,'web-pool',$values);
		$root=realpath($native['project_root']);
		if(!is_string($root) || is_link($native['project_root'])
			|| !hash_equals($root,$native['project_root'])
			|| !hash_equals($root,(string)(getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: ''))
			|| !self::privilegeBoundary(getmypid(),'web-pool')
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='web'
			|| (string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='web'){
			throw new RuntimeException('Managed web-pool request identity is invalid.');
		}
		foreach($values as $name=>$value){
			$projected=getenv($name);
			if(!is_string($projected) || !hash_equals($value,$projected)){
				throw new RuntimeException('Managed web-pool request environment is invalid.');
			}
			$_ENV[$name]=$value;$_SERVER[$name]=$value;
		}
		$_ENV['DATAPHYRE_RUNTIME_POOL']='web';$_SERVER['DATAPHYRE_RUNTIME_POOL']='web';
		$_ENV['DATAPHYRE_RUNTIME_POOL_ROLE']='web';$_SERVER['DATAPHYRE_RUNTIME_POOL_ROLE']='web';
		if(!chdir($root)) throw new RuntimeException('Managed web-pool request working directory is invalid.');
		umask(0027);
		self::$managedWebPoolRequest=true;
		self::establishManagedBootstrap($managedBootstrap,'web',$values,true);
		$values['DATAPHYRE_RUNTIME_POOL']='web';$values['DATAPHYRE_RUNTIME_POOL_ROLE']='web';
		ksort($values,SORT_STRING);
		return $values;
	}

	/** Binds the consumed broker transport to the worker-validated materializer. */
	public static function establishOneShotMaterializerBootstrap(string $operation,string $target): void {
		$caller=\realpath((string)((\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0] ?? [])['file'] ?? ''));
		$worker=\realpath(__DIR__.'/application_runtime_one_shot_worker.php');
		$expected=\realpath(\dirname(__DIR__,3).'/modules/sql/kernel/materialize_registered_tables.php');
		$resolved=\realpath($target);$projectPath=(string)(\getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '');$projectRoot=\realpath($projectPath);
		$application=(string)(\getenv('DATAPHYRE_FRAMEWORK_APPLICATION') ?: '');$environment=(string)(\getenv('DATAPHYRE_ENVIRONMENT') ?: '');
		$release=(string)(\getenv('DATAPHYRE_APPLICATION_RELEASE') ?: '');
		$serverArgument=(string)((\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [])[0] ?? '');
		$globalArgument=(string)((\is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [])[0] ?? '');
		if(!\is_string($caller) || !\is_string($worker) || !\hash_equals($worker,$caller)
			|| $operation!=='dataphyre_materialize_tables'
			|| !\is_string($expected) || !\is_string($resolved) || \is_link($target) || !\hash_equals($expected,$resolved)
			|| !\hash_equals($target,$resolved) || !\hash_equals($resolved,$serverArgument) || !\hash_equals($resolved,$globalArgument)
			|| !\hash_equals($resolved,(string)($_SERVER['SCRIPT_FILENAME'] ?? ''))
			|| self::$oneShotMaterializerBootstrap!==null || !\is_string($projectRoot) || !\is_dir($projectRoot)
			|| \is_link($projectPath) || !\hash_equals($projectPath,$projectRoot)
			|| \preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)
			|| \preg_match('/^dep_[a-f0-9]{40}$/D',$release)!==1
			|| !self::privilegeBoundary(\getmypid(),'one-shot')
			|| !\in_array(PHP_SAPI,['cli','phpdbg'],true)
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='one-shot'
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='one-shot'){
			throw new RuntimeException('One-shot materializer bootstrap boundary is invalid.');
		}
		self::$oneShotMaterializerBootstrap=['contract'=>self::ONE_SHOT_MATERIALIZER_BOOTSTRAP_CONTRACT,
			'role'=>'one-shot','operation'=>$operation,'target'=>$resolved,'project_root_raw'=>$projectPath,'project_root'=>$projectRoot,
			'application'=>$application,'environment'=>$environment,'release_id'=>$release,'argv0'=>$serverArgument,'sapi'=>PHP_SAPI,
		];
	}
	/** Returns non-secret proof of the root-brokered, worker-bound materializer operation. */
	public static function oneShotMaterializerBootstrapAttestation(): ?array {
		$caller=\realpath((string)((\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0] ?? [])['file'] ?? ''));
		$expectedCaller=\realpath(\dirname(__DIR__).'/Framework/InternalApplicationBootstrapOnly.php');
		if(!\is_string($caller) || !\is_string($expectedCaller) || !\hash_equals($expectedCaller,$caller))
			throw new RuntimeException('One-shot materializer attestation caller is invalid.');
		$proof=self::$oneShotMaterializerBootstrap;
		$script=(string)($_SERVER['SCRIPT_FILENAME'] ?? '');
		$argument=(string)((\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [])[0] ?? '');
		$globalArgument=(string)((\is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [])[0] ?? '');
		$projectPath=(string)(\getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '');
		if(!\is_array($proof) || !\hash_equals($proof['target'],$script) || !\hash_equals($proof['target'],$argument)
			|| !\hash_equals($proof['target'],$globalArgument) || !\hash_equals($proof['target'],(string)(\realpath($script) ?: ''))
			|| !\hash_equals($proof['argv0'],$argument) || !\hash_equals($proof['project_root_raw'],$projectPath)
			|| !\hash_equals($proof['project_root'],(string)(\realpath($projectPath) ?: ''))
			|| !\hash_equals($proof['application'],(string)(\getenv('DATAPHYRE_FRAMEWORK_APPLICATION') ?: ''))
			|| !\hash_equals($proof['environment'],(string)(\getenv('DATAPHYRE_ENVIRONMENT') ?: ''))
			|| !\hash_equals($proof['release_id'],(string)(\getenv('DATAPHYRE_APPLICATION_RELEASE') ?: ''))
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='one-shot'
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='one-shot'
			|| !self::privilegeBoundary(\getmypid(),'one-shot')) throw new RuntimeException('One-shot materializer attestation is invalid.');
		return $proof;
	}
	/**
	 * Consumes the root gateway envelope without activating application bootstrap.
	 * The returned typed context may only be rebrokered to a fresh final CGI child.
	 *
	 * @return array{values:array<string,string>,managed_bootstrap:array<string,string>}
	 */
	public static function consumeGateway(string $role,int $fd=self::INHERITED_FD): array
	{
		if(!in_array($role,['web-gateway','scheduler-gateway'],true) || PHP_SAPI!=='cli'){
			throw new RuntimeException('Managed runtime gateway role is invalid.');
		}
		$consumed=self::consumeEnvelope($role,$fd);
		return ['values'=>$consumed['values'],'managed_bootstrap'=>$consumed['managed_bootstrap']];
	}

	/** @return array{values:array<string,string>,managed_bootstrap:?array<string,string>} */
	private static function consumeEnvelope(string $role,int $fd): array
	{
		if(self::$consumed || $fd!==self::INHERITED_FD){
			throw new RuntimeException('Child environment channel was already consumed.');
		}
		self::$consumed=true;self::validateRole($role);
		if(!function_exists('dataphyre_open_inherited_environment_fd')){
			throw new RuntimeException('Child environment native descriptor support is unavailable.');
		}
		$channel=dataphyre_open_inherited_environment_fd();
		if(!is_resource($channel)) throw new RuntimeException('Child environment channel is unavailable.');
		stream_set_blocking($channel,true);stream_set_timeout($channel,5,0);
		$bytes='';$ack='';
		try{
			$header=fgets($channel,10);
			if(!is_string($header) || preg_match('/^[a-f0-9]{8}\n$/D',$header)!==1){
				throw new RuntimeException('Child environment framing is invalid.');
			}
			$length=hexdec(substr($header,0,8));
			if($length<1 || $length>self::MAX_BYTES) throw new RuntimeException('Child environment exceeded its bound.');
			$bytes=self::readExact($channel,$length);
			$decoded=self::decode($bytes,$role);
			$target=$decoded['target'];
			$current=self::target(getmypid(),posix_getppid());
			if($target!==$current || !self::privilegeBoundary(getmypid(),$role)){
				throw new RuntimeException('Child environment process binding is invalid.');
			}
			$values=$decoded['values'];
			foreach($values as $name=>$value){
				if(!putenv($name.'='.$value)) throw new RuntimeException('Child environment projection failed.');
				$_ENV[$name]=$value;$_SERVER[$name]=$value;
			}
			$values['DATAPHYRE_RUNTIME_POOL']=$role;
			$values['DATAPHYRE_RUNTIME_POOL_ROLE']=$role;
			foreach(['DATAPHYRE_RUNTIME_POOL','DATAPHYRE_RUNTIME_POOL_ROLE'] as $name){
				if(!putenv($name.'='.$role)) throw new RuntimeException('Child environment role projection failed.');
				$_ENV[$name]=$role;$_SERVER[$name]=$role;
			}
			$ack=self::canonicalAck($decoded['nonce'],$target['pid'],$target['start_time_ticks']);
			self::writeAll($channel,$ack);
			ksort($values,SORT_STRING);
			return ['values'=>$values,'managed_bootstrap'=>$decoded['managed_bootstrap']];
		}finally{
			if($bytes!=='') sodium_memzero($bytes);
			if($ack!=='') sodium_memzero($ack);
			self::closeNativeDescriptor($fd);
			if(is_resource($channel)) @fclose($channel);
		}
	}

	/** CGI startup obtains the same fixed inherited descriptor without reusing process state. */
	public static function consumeCgi(string $role): array
	{
		return self::consumeInherited($role);
	}

	/** @param array<string,string> $values */
	public static function canonical(
		string $role,string $nonce,array $target,array $values,?array $managedBootstrap=null,
	): string
	{
		self::validateRole($role);self::validateNonce($nonce);self::validateTarget($target);self::validateValues($values);
		self::validateManagedBootstrap($managedBootstrap,$role,$values);
		ksort($values,SORT_STRING);
		$bytes=json_encode([
			'contract'=>self::CONTRACT,'role'=>$role,'nonce'=>$nonce,
			'target'=>$target,'managed_bootstrap'=>$managedBootstrap,'values'=>(object)$values,
		],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR)."\n";
		if(strlen($bytes)>self::MAX_BYTES) throw new RuntimeException('Child environment exceeded its bound.');
		return $bytes;
	}

	/** @return array{pid:int,start_time_ticks:string,ancestry:list<array{pid:int,start_time_ticks:string}>} */
	public static function target(int $pid,int $expectedParentPid): array
	{
		if($pid<2 || $expectedParentPid<1) throw new RuntimeException('Child environment target is invalid.');
		$self=self::processIdentity($pid);
		if($self['parent_pid']!==$expectedParentPid) throw new RuntimeException('Child environment parent identity is invalid.');
		$ancestry=[];$cursor=$expectedParentPid;
		for($depth=0;$depth<self::MAX_ANCESTORS;$depth++){
			$identity=self::processIdentity($cursor);
			$ancestry[]=['pid'=>$cursor,'start_time_ticks'=>$identity['start_time_ticks']];
			if($cursor===1) break;
			$cursor=$identity['parent_pid'];
		}
		if(($ancestry[array_key_last($ancestry)]['pid'] ?? null)!==1){
			throw new RuntimeException('Child environment ancestry is invalid.');
		}
		return ['pid'=>$pid,'start_time_ticks'=>$self['start_time_ticks'],'ancestry'=>$ancestry];
	}

	/** @return array{parent_pid:int,start_time_ticks:string,uid:int,gid:int,groups:list<int>,cap_eff:string,no_new_privileges:bool} */
	public static function processIdentity(int $pid): array
	{
		if($pid<1) throw new RuntimeException('Process identity is invalid.');
		$stat=@file_get_contents('/proc/'.$pid.'/stat');$status=@file_get_contents('/proc/'.$pid.'/status');
		$close=is_string($stat) ? strrpos($stat,') ') : false;
		if(!is_string($stat) || !is_string($status) || $close===false) throw new RuntimeException('Process identity is unavailable.');
		return self::parseProcessIdentity($stat,$status,$close);
	}

	/** @return array{parent_pid:int,start_time_ticks:string,uid:int,gid:int,groups:list<int>,cap_eff:string,no_new_privileges:bool} */
	private static function parseProcessIdentity(string $stat,string $status,int $close): array
	{
		$fields=preg_split('/\s+/',trim(substr($stat,$close+2))) ?: [];
		$matches=[];
		if(!isset($fields[1],$fields[19]) || preg_match('/^[0-9]+$/D',$fields[1])!==1
			|| preg_match('/^[0-9]+$/D',$fields[19])!==1
			|| preg_match('/^Uid:\s+(\d+)\s+/m',$status,$matches)!==1){
			throw new RuntimeException('Process identity is invalid.');
		}
		$uid=(int)$matches[1];
		if(preg_match('/^Gid:\s+(\d+)\s+/m',$status,$matches)!==1) throw new RuntimeException('Process identity is invalid.');
		$gid=(int)$matches[1];
		if(preg_match('/^Groups:\s*([^\r\n]*)$/m',$status,$matches)!==1) throw new RuntimeException('Process identity is invalid.');
		$groups=array_values(array_map('intval',preg_split('/\s+/',trim($matches[1]),-1,PREG_SPLIT_NO_EMPTY) ?: []));
		sort($groups,SORT_NUMERIC);$groups=array_values(array_unique($groups));
		if(preg_match('/^CapEff:\s+([a-f0-9]+)\s*$/mi',$status,$matches)!==1) throw new RuntimeException('Process identity is invalid.');
		$capEff=str_pad(strtolower($matches[1]),16,'0',STR_PAD_LEFT);
		if(preg_match('/^NoNewPrivs:\s+([01])\s*$/m',$status,$matches)!==1) throw new RuntimeException('Process identity is invalid.');
		return [
			'parent_pid'=>(int)$fields[1],'start_time_ticks'=>$fields[19],'uid'=>$uid,'gid'=>$gid,
			'groups'=>$groups,'cap_eff'=>$capEff,'no_new_privileges'=>$matches[1]==='1',
		];
	}

	/** @return array{role:string,nonce:string,target:array,managed_bootstrap:?array<string,string>,values:array<string,string>} */
	private static function decode(string $bytes,string $expectedRole): array
	{
		if($bytes==='' || strlen($bytes)>self::MAX_BYTES || !str_ends_with($bytes,"\n") || substr_count($bytes,"\n")!==1){
			throw new RuntimeException('Child environment framing is invalid.');
		}
		try{$decoded=json_decode($bytes,true,12,JSON_THROW_ON_ERROR);}
		catch(Throwable){throw new RuntimeException('Child environment JSON is invalid.');}
		if(!is_array($decoded) || array_keys($decoded)!==['contract','role','nonce','target','managed_bootstrap','values']
			|| ($decoded['contract'] ?? null)!==self::CONTRACT || ($decoded['role'] ?? null)!==$expectedRole
			|| !is_string($decoded['nonce'] ?? null) || !is_array($decoded['target'] ?? null)
			|| !is_array($decoded['values'] ?? null)){
			throw new RuntimeException('Child environment contract is invalid.');
		}
		self::validateNonce($decoded['nonce']);self::validateTarget($decoded['target']);self::validateValues($decoded['values']);
		self::validateManagedBootstrap($decoded['managed_bootstrap'],$expectedRole,$decoded['values']);
		if(!hash_equals(self::canonical(
			$expectedRole,$decoded['nonce'],$decoded['target'],$decoded['values'],$decoded['managed_bootstrap'],
		),$bytes)){
			throw new RuntimeException('Child environment is not canonical.');
		}
		return [
			'role'=>$expectedRole,'nonce'=>$decoded['nonce'],'target'=>$decoded['target'],
			'managed_bootstrap'=>$decoded['managed_bootstrap'],'values'=>$decoded['values'],
		];
	}

	/**
	 * Creates the reserved transport context from a PID-1-generated 32-byte key.
	 * It is deliberately separate from tenant/application values.
	 *
	 * @return array{contract:string,role:string,project_root:string,private_key:string}
	 */
	public static function managedBootstrapContext(string $role,string $projectRoot,string $privateKey): array
	{
		if(!in_array($role,['web','scheduler','realtime'],true) || strlen($privateKey)!==32){
			throw new RuntimeException('Managed runtime bootstrap seed is invalid.');
		}
		$root=realpath($projectRoot);
		if(!is_string($root) || !is_dir($root) || is_link($projectRoot) || !hash_equals($root,$projectRoot)){
			throw new RuntimeException('Managed runtime bootstrap project root is invalid.');
		}
		return [
			'contract'=>self::MANAGED_BOOTSTRAP_CONTRACT,'role'=>$role,'project_root'=>$root,
			'private_key'=>sodium_bin2base64($privateKey,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
		];
	}

	/** Returns non-secret proof that this process consumed a bound managed context. */
	public static function managedBootstrapAttestation(): ?array
	{
		if(self::$managedBootstrap===null) return null;
		self::assertActiveManagedBootstrap();
		return [
			'contract'=>self::$managedBootstrap['contract'],'role'=>self::$managedBootstrap['role'],
			'project_root'=>self::$managedBootstrap['project_root'],'sapi'=>PHP_SAPI,
		];
	}

	/**
	 * Supplies the process-held key only to the existing dpvks() core surface.
	 * This adds no new key-reading surface for application code.
	 */
	public static function managedBootstrapPrivateKeyForCore(): ?string
	{
		if(self::$managedBootstrap===null) return null;
		$trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0] ?? [];
		$caller=realpath((string)($trace['file'] ?? ''));
		$helper=realpath(__DIR__.'/helper_functions.php');
		if(!is_string($caller) || !is_string($helper) || !hash_equals($helper,$caller)){
			throw new RuntimeException('Managed runtime private key caller is invalid.');
		}
		self::assertActiveManagedBootstrap();
		return self::$managedBootstrap['private_key'];
	}

	/** @param null|array<string,mixed> $context @param array<string,string> $values */
	private static function validateManagedBootstrap(?array $context,string $transportRole,array $values): void
	{
		$expectedRole=match($transportRole){
			'web','web-pool','web-gateway'=>'web','scheduler','scheduler-gateway'=>'scheduler','realtime'=>'realtime',
			default=>null,
		};
		if($expectedRole===null){
			if($context!==null) throw new RuntimeException('Managed runtime bootstrap is not allowed for this role.');
			return;
		}
		if(!is_array($context) || array_keys($context)!==['contract','role','project_root','private_key']
			|| ($context['contract'] ?? null)!==self::MANAGED_BOOTSTRAP_CONTRACT
			|| ($context['role'] ?? null)!==$expectedRole
			|| !is_string($context['project_root'] ?? null)
			|| !is_string($context['private_key'] ?? null)
			|| preg_match('/^[A-Za-z0-9_-]{43}$/D',$context['private_key'])!==1
			|| !is_string($values['DATAPHYRE_RUNTIME_PROJECT_ROOT'] ?? null)
			|| !hash_equals($context['project_root'],$values['DATAPHYRE_RUNTIME_PROJECT_ROOT'])){
			throw new RuntimeException('Managed runtime bootstrap contract is invalid.');
		}
		$root=realpath($context['project_root']);
		if(!is_string($root) || !is_dir($root) || is_link($context['project_root'])
			|| !hash_equals($root,$context['project_root'])){
			throw new RuntimeException('Managed runtime bootstrap project root is invalid.');
		}
		try{$key=sodium_base642bin($context['private_key'],SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}
		catch(Throwable){$key='';}
		$valid=strlen($key)===32;
		if($key!=='') sodium_memzero($key);
		if(!$valid) throw new RuntimeException('Managed runtime bootstrap private key is invalid.');
	}

	/** @param array<string,mixed> $context @param array<string,string> $values */
	private static function establishManagedBootstrap(
		array &$context,string $role,array $values,bool $persistentWebPool=false,
	): void
	{
		if(self::$managedBootstrap!==null) throw new RuntimeException('Managed runtime bootstrap was already established.');
		self::validateManagedBootstrap($context,$role,$values);
		$expectedSapi=$persistentWebPool && $role==='web'
			? 'fpm-fcgi'
			: (in_array($role,['web','scheduler'],true) ? 'cgi-fcgi' : 'cli');
		$expectedPort=match($role){'web'=>'8083','scheduler'=>'8081',default=>null};
		if(PHP_SAPI!==$expectedSapi
			|| !hash_equals($role,(string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: ''))
			|| !hash_equals($role,(string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: ''))
			|| ($expectedPort!==null && !hash_equals($expectedPort,(string)($_SERVER['SERVER_PORT'] ?? '')))){
			throw new RuntimeException('Managed runtime bootstrap execution boundary is invalid.');
		}
		$keyEncoded=&$context['private_key'];
		$privateKey=sodium_base642bin($keyEncoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');
		self::$managedBootstrap=[
			'contract'=>self::MANAGED_BOOTSTRAP_CONTRACT,'role'=>$role,
			'project_root'=>$context['project_root'],'private_key'=>$privateKey,
		];
		sodium_memzero($keyEncoded);unset($context['private_key']);
	}

	private static function assertActiveManagedBootstrap(): void
	{
		$context=self::$managedBootstrap;
		if(!is_array($context) || strlen($context['private_key'] ?? '')!==32
			|| !in_array($context['role'] ?? null,['web','scheduler','realtime'],true)
			|| !hash_equals($context['role'],(string)(getenv('DATAPHYRE_RUNTIME_POOL') ?: ''))
			|| !hash_equals($context['role'],(string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: ''))
			|| !hash_equals($context['project_root'],(string)(realpath(
				(string)(getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: ''),
			)))
			|| ($context['role']==='web' && self::$managedWebPoolRequest
				? PHP_SAPI!=='fpm-fcgi'
				: (in_array($context['role'],['web','scheduler'],true) ? PHP_SAPI!=='cgi-fcgi' : PHP_SAPI!=='cli'))){
			throw new RuntimeException('Managed runtime bootstrap attestation is invalid.');
		}
	}

	private static function privilegeBoundary(int $pid,string $role): bool
	{
		try{$identity=self::processIdentity($pid);}catch(Throwable){return false;}
		if(in_array($role,['web-gateway','scheduler-gateway'],true)){
			return $identity['uid']===0 && $identity['gid']===0 && $identity['groups']===[0]
				&& $identity['cap_eff']==='00000000000000c0' && $identity['no_new_privileges']===true;
		}
		return $identity['uid']===self::POOL_UID && $identity['gid']===self::POOL_GID
			&& $identity['groups']===[self::POOL_GID]
			&& $identity['cap_eff']==='0000000000000000' && $identity['no_new_privileges']===true;
	}

	private static function canonicalAck(string $nonce,int $pid,string $startTimeTicks): string
	{
		return json_encode([
			'contract'=>self::ACK_CONTRACT,'nonce'=>$nonce,'pid'=>$pid,'start_time_ticks'=>$startTimeTicks,
		],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
	}

	/** @param array<string,mixed> $target */
	private static function validateTarget(array $target): void
	{
		if(array_keys($target)!==['pid','start_time_ticks','ancestry'] || !is_int($target['pid'] ?? null)
			|| $target['pid']<2 || !is_string($target['start_time_ticks'] ?? null)
			|| preg_match('/^[0-9]+$/D',$target['start_time_ticks'])!==1 || !is_array($target['ancestry'] ?? null)
			|| !array_is_list($target['ancestry']) || $target['ancestry']===[]
			|| count($target['ancestry'])>self::MAX_ANCESTORS){
			throw new RuntimeException('Child environment target is invalid.');
		}
		foreach($target['ancestry'] as $index=>$ancestor){
			if(!is_array($ancestor) || array_keys($ancestor)!==['pid','start_time_ticks']
				|| !is_int($ancestor['pid'] ?? null) || $ancestor['pid']<1
				|| !is_string($ancestor['start_time_ticks'] ?? null)
				|| preg_match('/^[0-9]+$/D',$ancestor['start_time_ticks'])!==1
				|| ($index>0 && $ancestor['pid']===$target['ancestry'][$index-1]['pid'])){
				throw new RuntimeException('Child environment ancestry is invalid.');
			}
		}
		if(($target['ancestry'][array_key_last($target['ancestry'])]['pid'] ?? null)!==1){
			throw new RuntimeException('Child environment ancestry is incomplete.');
		}
	}

	/** @param array<string,mixed> $values */
	private static function validateValues(array $values): void
	{
		if(count($values)>self::MAX_ENTRIES || array_is_list($values) && $values!==[]){
			throw new RuntimeException('Child environment entry count is invalid.');
		}
		foreach($values as $name=>$value){
			if(!is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,119}$/D',$name)!==1
				|| !is_string($value) || strlen($value)>65536 || preg_match('/[\x00-\x1f\x7f]/D',$value)===1){
				throw new RuntimeException('Child environment entry is invalid.');
			}
		}
	}

	private static function validateRole(string $role): void
	{
		if(!in_array($role,['web','web-pool','scheduler','realtime','one-shot','web-gateway','scheduler-gateway'],true)){
			throw new RuntimeException('Child environment role is invalid.');
		}
	}

	private static function validateNonce(string $nonce): void
	{
		if(preg_match('/^[a-f0-9]{64}$/D',$nonce)!==1) throw new RuntimeException('Child environment nonce is invalid.');
	}

	/**
	 * PHP stream wrappers duplicate php://fd descriptors. The Cloud image ships
	 * this fixed extension so the inherited native capability can be closed too.
	 */
	private static function closeNativeDescriptor(int $fd): void
	{
		if(!function_exists('dataphyre_close_inherited_fd') || dataphyre_close_inherited_fd($fd)!==true){
			throw new RuntimeException('Child environment native descriptor could not be closed.');
		}
	}

	/** @param resource $stream */
	private static function writeAll(mixed $stream,string $bytes): void
	{
		$offset=0;
		while($offset<strlen($bytes)){
			$written=@fwrite($stream,substr($bytes,$offset));
			if(!is_int($written) || $written<1) throw new RuntimeException('Child environment write failed.');
			$offset+=$written;
		}
		if(!@fflush($stream)) throw new RuntimeException('Child environment flush failed.');
	}

	/** @param resource $stream */
	private static function readExact(mixed $stream,int $length): string
	{
		$result='';
		while(strlen($result)<$length){
			$chunk=@fread($stream,$length-strlen($result));
			if(!is_string($chunk) || $chunk==='') throw new RuntimeException('Child environment read failed.');
			$result.=$chunk;
		}
		return $result;
	}
}
