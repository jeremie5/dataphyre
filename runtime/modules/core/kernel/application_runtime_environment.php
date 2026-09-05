<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
require_once dirname(__DIR__).'/Framework/PublicApplicationIdentifier.php';

/** Reads the container-lifetime, root-only application environment channel used by PID 1. */
final class DataphyreApplicationRuntimeEnvironment
{
	public const CHANNEL='/run/dataphyre/application-environment.json';
	public const APPLICATION_DATA_ROOT='/var/lib/dataphyre/application';
	public const APPLICATION_LOG_ROOT='/var/log/dataphyre';
	public const SCHEDULER_STATE_ROOT='/var/lib/dataphyre/scheduler-state';
	private const MAX_BYTES=262144;
	private const MAX_ENTRIES=512;
	private const FIXED_LOG_DIRECTORY='/var/log/dataphyre';
	private const FIXED_LOG_DRIVER='jsonl';
	private const FIXED_LOG_FORMAT='dataphyre.application-log.v1';
	private const FIXED_LOG_PATH='/var/log/dataphyre/application.jsonl';
	private const FIXED_RUNTIME_ROOT='/opt/dataphyre/runtime';
	private const FIXED_PROJECT_ROOT='/app';
	private const FIXED_APPLICATION_ROOT='/app';
	private const FIXED_RUNTIME_PROJECT_ROOT='/app';
	private const DATABASE_BINDING_FIELDS=['DSN','HOST','PORT','NAME','USER','PASSWORD'];

	/** @return array{environment_id:string,release_id:string,environment_fingerprint:string,values:array<string,string>} */
	public static function consume(
		string $deploymentApplication,
		string $frameworkApplication,
		string $environment,
		string $environmentId,
		string $releaseId,
	): array
	{
		if(getmypid()!==1 || !function_exists('posix_geteuid') || posix_geteuid()!==0){
			throw new RuntimeException('Application environment may only be consumed by root PID 1.');
		}
		if(!\Dataphyre\PublicApplicationIdentifier::valid($deploymentApplication)
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$frameworkApplication)!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)
			|| !\Dataphyre\PublicApplicationIdentifier::valid($environmentId)){
			throw new RuntimeException('Application environment identity is invalid.');
		}
		self::relockChannelDirectory();
		if(is_link(self::CHANNEL)) throw new RuntimeException('Application environment channel cannot be a symbolic link.');
		self::assertFixedMount(self::CHANNEL,'ro');
		$handle=@fopen(self::CHANNEL,'rb') ?: throw new RuntimeException('Application environment channel is unavailable.');
		try{
			$handleStat=@fstat($handle);
			$pathStat=@lstat(self::CHANNEL);
			if(!self::exactFile($handleStat) || !self::exactFile($pathStat)
				|| ($handleStat['dev'] ?? null)!==($pathStat['dev'] ?? null)
				|| ($handleStat['ino'] ?? null)!==($pathStat['ino'] ?? null)){
				throw new RuntimeException('Application environment channel identity is invalid.');
			}
			$bytes=stream_get_contents($handle,self::MAX_BYTES+1);
			if(!is_string($bytes) || $bytes==='' || strlen($bytes)>self::MAX_BYTES){
				throw new RuntimeException('Application environment channel exceeded its bound.');
			}
		}finally{
			@fclose($handle);
		}
		return self::decodeEnvelope(
			$bytes,$deploymentApplication,$frameworkApplication,$environment,$environmentId,$releaseId,
		);
	}

	/** @param array<string,string> $values @return array<string,string> */
	public static function childEnvironment(
		array $values,
		string $deploymentApplication,
		string $frameworkApplication,
		string $environment,
		string $environmentId,
		string $releaseId,
		?string $applicationDataRoot=null,
	): array
	{
		if(!\Dataphyre\PublicApplicationIdentifier::valid($deploymentApplication)){
			throw new RuntimeException('Public application identifier is invalid.');
		}
		if(!\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)){
			throw new RuntimeException('Application environment identifier is invalid.');
		}
		if(!\Dataphyre\PublicApplicationIdentifier::valid($environmentId)){
			throw new RuntimeException('Application environment instance identifier is invalid.');
		}
		$result=$values;
		$result['DATAPHYRE_APPLICATION_ID']=$deploymentApplication;
		$result['DATAPHYRE_FRAMEWORK_APPLICATION']=$frameworkApplication;
		$result['DATAPHYRE_ENVIRONMENT']=$environment;
		$result['DATAPHYRE_APPLICATION_ENVIRONMENT']=$environment;
		$result['DATAPHYRE_APPLICATION_ENVIRONMENT_ID']=$environmentId;
		$result['DATAPHYRE_APPLICATION_RELEASE']=$releaseId;
		$result['DATAPHYRE_RUNTIME_ROOT']=self::FIXED_RUNTIME_ROOT;
		$result['DATAPHYRE_PROJECT_ROOT']=self::FIXED_PROJECT_ROOT;
		$result['DATAPHYRE_APPLICATION_ROOT']=self::FIXED_APPLICATION_ROOT;
		$result['DATAPHYRE_RUNTIME_PROJECT_ROOT']=self::FIXED_RUNTIME_PROJECT_ROOT;
		$result['DATAPHYRE_APPLICATION_LOG_DIRECTORY']=self::FIXED_LOG_DIRECTORY;
		$result['DATAPHYRE_APPLICATION_LOG_DRIVER']=self::FIXED_LOG_DRIVER;
		$result['DATAPHYRE_APPLICATION_LOG_FORMAT']=self::FIXED_LOG_FORMAT;
		$result['DATAPHYRE_APPLICATION_LOG_PATH']=self::FIXED_LOG_PATH;
		if($applicationDataRoot!==null){
			if(!hash_equals(self::APPLICATION_DATA_ROOT,$applicationDataRoot)){
				throw new RuntimeException('Application data root projection is invalid.');
			}
			$result['DATAPHYRE_APPLICATION_DATA_ROOT']=$applicationDataRoot;
		}
		ksort($result,SORT_STRING);
		return $result;
	}

	/**
	 * Projects one complete typed managed PostgreSQL binding onto the canonical
	 * database environment consumed by fixed framework migration operations.
	 *
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public static function projectManagedDatabasePurpose(array $values,string $purpose): array
	{
		if(preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$purpose)!==1){
			throw new RuntimeException('Managed database purpose is invalid.');
		}
		$token=strtoupper($purpose);
		$prefix=$purpose==='primary' ? 'DATAPHYRE_DATABASE' : 'DATAPHYRE_DATABASE_'.$token;
		$markerName='DATAPHYRE_DATABASE_BINDING_'.$token.'_SHA256';
		$marker=$values[$markerName] ?? null;
		if(!is_string($marker) || preg_match('/^sha256:[a-f0-9]{64}$/D',$marker)!==1){
			throw new RuntimeException('Managed database purpose marker is invalid.');
		}
		foreach(self::DATABASE_BINDING_FIELDS as $field){
			$value=$values[$prefix.'_'.$field] ?? null;
			if(!is_string($value) || $value==='' || strlen($value)>65536
				|| preg_match('/[\x00-\x1f\x7f]/D',$value)===1){
				throw new RuntimeException('Managed database purpose binding is incomplete.');
			}
			$values['DATAPHYRE_DATABASE_'.$field]=$value;
		}
		$values['DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256']=$marker;
		foreach(array_keys($values) as $name){
			if(!is_string($name)) continue;
			if(preg_match('/^DATAPHYRE_DATABASE_BINDING_[A-Z][A-Z0-9_]{0,31}_SHA256$/D',$name)===1
				&& $name!=='DATAPHYRE_DATABASE_BINDING_PRIMARY_SHA256'){
				unset($values[$name]);
				continue;
			}
			if(preg_match('/^DATAPHYRE_DATABASE_[A-Z][A-Z0-9_]{0,31}_(?:DSN|HOST|PORT|NAME|USER|PASSWORD)$/D',$name)===1){
				unset($values[$name]);
			}
		}
		ksort($values,SORT_STRING);
		return $values;
	}

	/** Proves the native root process did not start with tenant-selected loader controls. */
	/** @param null|array<string,string> $environment Test-only exact process-environment seam. */
	public static function assertCleanRootEnvironment(?array $environment=null): void
	{
		$allowed=[
			'DATAPHYRE_APPLICATION_ID','DATAPHYRE_APPLICATION_ENVIRONMENT_ID',
			'DATAPHYRE_FRAMEWORK_APPLICATION','DATAPHYRE_ENVIRONMENT',
			'DATAPHYRE_APPLICATION_RELEASE',
			'DATAPHYRE_ONE_SHOT_OPERATION','DATAPHYRE_ONE_SHOT_DATABASE_PURPOSE',
			'DATAPHYRE_ONE_SHOT_SEED_PROFILE','DATAPHYRE_ONE_SHOT_SEED_ALLOW_DEMO',
			'DATAPHYRE_ONE_SHOT_CACHE_PHASE','DATAPHYRE_ONE_SHOT_CACHE_CHALLENGE',
				'DATAPHYRE_RUNTIME_ACTIVATION_MODE',
			'DATAPHYRE_RUNTIME_SCHEDULER_INTERVAL_SECONDS',
			'DATAPHYRE_RUNTIME_WEB_HOST','DATAPHYRE_RUNTIME_WEB_PORT',
			'DATAPHYRE_RUNTIME_REALTIME_HOST','DATAPHYRE_RUNTIME_REALTIME_PORT',
			'DATAPHYRE_RUNTIME_PROJECT_ROOT','PATH','LANG','LC_ALL','TZ','HOSTNAME','TERM',
			'PHPIZE_DEPS','PHP_INI_DIR','PHP_CFLAGS','PHP_CPPFLAGS','PHP_LDFLAGS','GPG_KEYS','PHP_VERSION',
			'DEBIAN_FRONTEND','DATAPHYRE_RUNTIME_ROOT','DATAPHYRE_PROJECT_ROOT','DATAPHYRE_APPLICATION_ROOT',
			'PHP_URL','PHP_ASC_URL','PHP_URL_SIG','PHP_SHA256',
		];
		$source=$environment ?? getenv();
		if(!is_array($source)) throw new RuntimeException('Root process environment is unavailable.');
		foreach($source as $name=>$value){
			if(!is_string($name) || !is_string($value) || str_contains($value,"\0")
				|| ($name==='HOME' ? !hash_equals('/root',$value) : !in_array($name,$allowed,true))){
				throw new RuntimeException('Root process environment contains an unapproved entry.');
			}
		}
		if((string)ini_get('auto_prepend_file')!=='' || (string)ini_get('auto_append_file')!==''){
			throw new RuntimeException('Root PHP startup files are not disabled.');
		}
	}

	/**
	 * Selects the fixed application-data path only when Cloud mounted it as a
	 * distinct read-write filesystem. The path is never tenant-configurable.
	 *
	 * @param array<string,callable> $runtime Test-only filesystem seams.
	 */
	public static function mountedApplicationDataRoot(int $poolUid,array $runtime=[]): ?string
	{
		if($poolUid<1) throw new RuntimeException('Application data owner is invalid.');
		return self::mountedDirectory(
			self::APPLICATION_DATA_ROOT,$poolUid,$poolUid,0750,false,'Application data',$runtime,
		);
	}

	/**
	 * Proves the fixed application-log path is a distinct private read-write
	 * mount owned by the privilege-dropped application pool.
	 *
	 * @param array<string,callable> $runtime Test-only filesystem seams.
	 */
	public static function mountedApplicationLogRoot(int $poolUid,array $runtime=[]): string
	{
		if($poolUid<1) throw new RuntimeException('Application log owner is invalid.');
		$result=self::mountedDirectory(
			self::APPLICATION_LOG_ROOT,$poolUid,$poolUid,0750,true,'Application log',$runtime,
		);
		if($result===null) throw new RuntimeException('Application log mount is unavailable.');
		return $result;
	}

	/** @param array<string,callable> $runtime Test-only filesystem seams. */
	public static function mountedSchedulerStateRoot(array $runtime=[]): string
	{
		$result=self::mountedDirectory(
			self::SCHEDULER_STATE_ROOT,0,0,0700,true,'Scheduler state',$runtime,
		);
		if($result===null) throw new RuntimeException('Scheduler state mount is unavailable.');
		return $result;
	}

	/** @param array<string,callable> $runtime */
	private static function mountedDirectory(
		string $path,
		int $poolUid,
		int $poolGid,
		int $mode,
		bool $required,
		string $label,
		array $runtime,
	): ?string
	{
		if($poolUid<0 || $poolGid<0) throw new RuntimeException($label.' owner is invalid.');
		$lstat=$runtime['lstat'] ?? static fn(string $path): array|false=>@lstat($path);
		$realpath=$runtime['realpath'] ?? static fn(string $path): string|false=>@realpath($path);
		$isLink=$runtime['is_link'] ?? static fn(string $path): bool=>is_link($path);
		foreach([$lstat,$realpath,$isLink] as $operation){
			if(!is_callable($operation)) throw new RuntimeException('Application data inspection seam is invalid.');
		}
		$matches=self::mountModes($path,$label,$runtime);
		if($matches===[]) {
			if($required) throw new RuntimeException($label.' mount is unavailable.');
			return null;
		}
		if(count($matches)!==1 || !in_array('rw',explode(',',$matches[0]),true)){
			throw new RuntimeException($label.' mount identity is invalid.');
		}
		$stat=$lstat($path);
		$resolved=$realpath($path);
		if($isLink($path) || !is_array($stat)
			|| (($stat['mode'] ?? 0)&0170000)!==0040000
			|| (($stat['mode'] ?? 0)&0777)!==$mode
			|| ($stat['uid'] ?? -1)!==$poolUid
			|| ($stat['gid'] ?? -1)!==$poolGid
			|| ($stat['nlink'] ?? 0)<2
			|| !is_string($resolved) || !hash_equals($path,$resolved)){
			throw new RuntimeException($label.' directory identity is invalid.');
		}
		return $path;
	}

	private static function reserved(string $name): bool
	{
		return str_starts_with($name,'DATAPHYRE_APPLICATION_')
			|| str_starts_with($name,'DATAPHYRE_CLOUD_')
			|| str_starts_with($name,'DATAPHYRE_FRAMEWORK_')
			|| str_starts_with($name,'DATAPHYRE_INTERNAL_')
			|| str_starts_with($name,'DATAPHYRE_ONE_SHOT_')
			|| str_starts_with($name,'DATAPHYRE_PREFLIGHT_')
			|| str_starts_with($name,'DATAPHYRE_RUNTIME_')
			|| str_starts_with($name,'DATAPHYRE_SCHEDULER_')
			|| in_array($name,['DATAPHYRE_PHP_BINARY','DATAPHYRE_PROJECT_ROOT'],true)
			|| $name==='DATAPHYRE_ENVIRONMENT'
			|| str_starts_with($name,'PHP_') || str_starts_with($name,'LD_') || str_starts_with($name,'DYLD_')
			|| in_array($name,[
				'PHPRC','PATH','HOME','SHELL','ENV','BASH_ENV','CDPATH','TMPDIR','TMP','TEMP',
				'GCONV_PATH','LOCPATH','OPENSSL_CONF','OPENSSL_MODULES','SSL_CERT_FILE','SSL_CERT_DIR',
				'PERL5OPT','RUBYOPT','NODE_OPTIONS','PYTHONPATH','PYTHONHOME',
			],true);
	}

	/** @return array{environment_id:string,release_id:string,environment_fingerprint:string,values:array<string,string>} */
	private static function decodeEnvelope(
		string $bytes,string $deploymentApplication,string $frameworkApplication,string $environment,
		string $environmentId,string $releaseId,
	): array {
		try{$decoded=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);}
		catch(Throwable){throw new RuntimeException('Application environment channel JSON is invalid.');}
		if(!is_array($decoded) || array_keys($decoded)!==[
			'contract','deployment_application','framework_application','environment','environment_id','release_id',
			'environment_fingerprint','values',
		]
			|| ($decoded['contract'] ?? null)!=='dataphyre.application_environment.v3'
			|| !is_string($decoded['deployment_application'] ?? null)
			|| !hash_equals($deploymentApplication,$decoded['deployment_application'])
			|| !is_string($decoded['framework_application'] ?? null)
			|| !hash_equals($frameworkApplication,$decoded['framework_application'])
			|| !is_string($decoded['environment'] ?? null) || !hash_equals($environment,$decoded['environment'])
			|| !is_string($decoded['environment_id'] ?? null)
			|| !hash_equals($environmentId,$decoded['environment_id'])
			|| !\Dataphyre\PublicApplicationIdentifier::valid($decoded['environment_id'])
			|| !is_string($decoded['release_id'] ?? null) || !hash_equals($releaseId,$decoded['release_id'])
			|| preg_match('/^dep_[a-f0-9]{40}$/D',$decoded['release_id'])!==1
			|| !is_string($decoded['environment_fingerprint'] ?? null)
			|| preg_match('/^hmac-sha256:[a-f0-9]{64}$/D',$decoded['environment_fingerprint'])!==1
			|| !is_array($decoded['values']) || count($decoded['values'])>self::MAX_ENTRIES){
			throw new RuntimeException('Application environment channel contract is invalid.');
		}
		$result=[];
		foreach($decoded['values'] as $name=>$value){
			if(!is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,119}$/D',$name)!==1
				|| !is_string($value) || strlen($value)>65536 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/D',$value)===1
				|| self::reserved($name)){
				throw new RuntimeException('Application environment entry is invalid.');
			}
			$result[$name]=$value;
		}
		ksort($result,SORT_STRING);
		$canonical=self::canonicalEnvelope(
			$deploymentApplication,$frameworkApplication,$environment,$environmentId,$releaseId,
			$decoded['environment_fingerprint'],$result,
		);
		if(!hash_equals($canonical,$bytes)){
			throw new RuntimeException('Application environment channel is not canonical.');
		}
		sodium_memzero($bytes);
		return [
			'environment_id'=>$environmentId,
			'release_id'=>$releaseId,
			'environment_fingerprint'=>$decoded['environment_fingerprint'],
			'values'=>$result,
		];
	}

	/** @param array<string,string> $values */
	private static function canonicalEnvelope(
		string $deploymentApplication,
		string $frameworkApplication,
		string $environment,
		string $environmentId,
		string $releaseId,
		string $environmentFingerprint,
		array $values,
	): string {
		ksort($values,SORT_STRING);
		return json_encode([
			'contract'=>'dataphyre.application_environment.v3',
			'deployment_application'=>$deploymentApplication,
			'framework_application'=>$frameworkApplication,
			'environment'=>$environment,
			'environment_id'=>$environmentId,
			'release_id'=>$releaseId,
			'environment_fingerprint'=>$environmentFingerprint,
			'values'=>(object)$values,
		],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR)."\n";
	}

	private static function mountInfoValue(string $value): string
	{
		return (string)preg_replace_callback('/\\\\([0-7]{3})/D',static fn(array $match): string=>chr(octdec($match[1])),$value);
	}

	/** @param array<string,callable> $runtime */
	private static function mountModes(string $path,string $label,array $runtime=[]): array
	{
		$read=$runtime['read_file'] ?? static fn(string $candidate): string|false=>@file_get_contents($candidate);
		if(!is_callable($read)) throw new RuntimeException($label.' mount inspection seam is invalid.');
		$mountInfo=$read('/proc/self/mountinfo');
		if(!is_string($mountInfo) || $mountInfo==='' || strlen($mountInfo)>1048576){
			throw new RuntimeException($label.' mount inventory is unavailable.');
		}
		$matches=[];
		foreach(preg_split('/\r?\n/D',$mountInfo) ?: [] as $line){
			if($line==='') continue;
			if(strlen($line)>4096) throw new RuntimeException($label.' mount inventory is invalid.');
			$separator=strpos($line,' - ');
			if($separator===false) throw new RuntimeException($label.' mount inventory is invalid.');
			$fields=explode(' ',substr($line,0,$separator));
			if(count($fields)<6) throw new RuntimeException($label.' mount inventory is invalid.');
			if(hash_equals($path,self::mountInfoValue($fields[4]))) $matches[]=$fields[5];
		}
		return $matches;
	}

	/** @param array<string,callable> $runtime */
	private static function assertFixedMount(string $path,string $requiredMode,array $runtime=[]): void
	{
		$matches=self::mountModes($path,'Application environment',$runtime);
		if(count($matches)!==1 || !in_array($requiredMode,explode(',',$matches[0]),true)){
			throw new RuntimeException('Application environment channel mount identity is invalid.');
		}
	}

	/** Restore the root-only startup boundary after the supervisor's socket traversal mode. */
	private static function relockChannelDirectory(array $runtime=[]): void
	{
		$path=dirname(self::CHANNEL);
		$lstat=$runtime['lstat'] ?? static fn(string $candidate): array|false=>@lstat($candidate);
		$chmod=$runtime['chmod'] ?? static fn(string $candidate,int $mode): bool=>@chmod($candidate,$mode);
		if(!is_callable($lstat) || !is_callable($chmod)){
			throw new RuntimeException('Application environment directory inspection seam is invalid.');
		}
		clearstatcache(true,$path);
		$stat=$lstat($path);
		$mode=is_array($stat) ? (($stat['mode'] ?? 0)&07777) : -1;
		if(!in_array($mode,[0700,0711],true)){
			throw new RuntimeException('Application environment directory identity is invalid.');
		}
		$before=self::exactDirectory($path,$mode,$runtime);
		if($mode===0711 && !$chmod($path,0700)){
			throw new RuntimeException('Application environment directory could not be relocked.');
		}
		clearstatcache(true,$path);
		$after=self::exactDirectory($path,0700,$runtime);
		if(($before['dev'] ?? null)!==($after['dev'] ?? null)
			|| ($before['ino'] ?? null)!==($after['ino'] ?? null)){
			throw new RuntimeException('Application environment directory identity changed.');
		}
	}

	/** @return array<string,int> */
	/** @param array<string,callable> $runtime Test-only filesystem seams. */
	private static function exactDirectory(string $path,int $mode,array $runtime=[]): array
	{
		$lstat=$runtime['lstat'] ?? static fn(string $candidate): array|false=>@lstat($candidate);
		$realpath=$runtime['realpath'] ?? static fn(string $candidate): string|false=>@realpath($candidate);
		$isLink=$runtime['is_link'] ?? static fn(string $candidate): bool=>is_link($candidate);
		foreach([$lstat,$realpath,$isLink] as $operation){
			if(!is_callable($operation)) throw new RuntimeException('Application environment directory inspection seam is invalid.');
		}
		$stat=$lstat($path);
		$resolved=$realpath($path);
		if($isLink($path) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
			|| (($stat['mode'] ?? 0)&0777)!==$mode || ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
			|| ($stat['nlink'] ?? 0)<1 || !is_string($resolved) || $resolved!==$path){
			throw new RuntimeException('Application environment directory identity is invalid.');
		}
		return $stat;
	}

	private static function exactFile(mixed $stat): bool
	{
		return is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0100000
			&& (($stat['mode'] ?? 0)&0777)===0400 && ($stat['uid'] ?? -1)===0 && ($stat['gid'] ?? -1)===0
			&& ($stat['nlink'] ?? 0)===1;
	}
}
