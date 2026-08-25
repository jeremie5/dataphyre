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

/** Release-local evidence for the fixed scheduler listener no-op roundtrip. */
final class DataphyreApplicationRuntimeProbeState
{
	private const CONTRACT='dataphyre.scheduler_noop_probe.v2';
	private const DIRECTORY='/var/lib/dataphyre/runtime-control';
	private const FILE=self::DIRECTORY.'/scheduler-probe.json';

	/** @param array<string,string> $identity @return array<string,mixed> */
	public static function record(array $identity,int $now): array
	{
		if($now<1) throw new RuntimeException('Scheduler probe timestamp is invalid.');
		$identitySha=self::identitySha256($identity);
		$previous=self::read();
		if($previous!==null && !hash_equals($identitySha,$previous['state_identity_sha256'])){
			throw new RuntimeException('Scheduler probe identity changed inside one container.');
		}
		$count=($previous['count'] ?? 0)+1;
		$state=[
			'contract'=>self::CONTRACT,
			'state_identity_sha256'=>$identitySha,
			'count'=>$count,
			'last_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
		];
		self::write($state);
		return [
			'contract'=>self::CONTRACT,
			'count'=>$count,
			'last_at'=>$state['last_at'],
			'previous_readback'=>$previous!==null,
			'state_identity_sha256'=>$identitySha,
		];
	}

	/** @param array<string,string> $identity */
	private static function identitySha256(array $identity): string
	{
		$canonical=[
			'deployment_application'=>$identity['deployment_application'] ?? '',
			'framework_application'=>$identity['framework_application'] ?? '',
			'environment'=>$identity['environment'] ?? '',
			'release_id'=>$identity['release_id'] ?? '',
			'environment_fingerprint'=>$identity['environment_fingerprint'] ?? '',
		];
		if(!is_string($canonical['deployment_application'])
			|| !\Dataphyre\PublicApplicationIdentifier::valid($canonical['deployment_application'])
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$canonical['framework_application'])!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($canonical['environment'])
			|| preg_match('/^dep_[a-f0-9]{40}$/D',$canonical['release_id'])!==1
			|| preg_match('/^hmac-sha256:[a-f0-9]{64}$/D',$canonical['environment_fingerprint'])!==1){
			throw new RuntimeException('Scheduler probe identity is invalid.');
		}
		return 'sha256:'.hash(
			'sha256',
			"dataphyre.scheduler_noop_probe_identity.v2\0".json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
		);
	}

	/** @return ?array<string,mixed> */
	private static function read(): ?array
	{
		self::prepareDirectory();
		if(!file_exists(self::FILE) && !is_link(self::FILE)) return null;
		if(is_link(self::FILE)) throw new RuntimeException('Scheduler probe state cannot be a symbolic link.');
		$handle=@fopen(self::FILE,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Scheduler probe state is unavailable.');
		try{
			$handleStat=@fstat($handle);$pathStat=@lstat(self::FILE);
			$bytes=stream_get_contents($handle,1025);$extra=fread($handle,1);
			if(!self::validFile($handleStat) || !self::validFile($pathStat)
				|| ($handleStat['dev'] ?? null)!==($pathStat['dev'] ?? null)
				|| ($handleStat['ino'] ?? null)!==($pathStat['ino'] ?? null)
				|| !is_string($bytes) || $bytes==='' || strlen($bytes)>1024 || $extra!==''){
				throw new RuntimeException('Scheduler probe state identity is invalid.');
			}
		}finally{@fclose($handle);}
		try{$state=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);}
		catch(Throwable){throw new RuntimeException('Scheduler probe state JSON is invalid.');}
		if(!is_array($state) || array_keys($state)!==['contract','state_identity_sha256','count','last_at']
			|| ($state['contract'] ?? null)!==self::CONTRACT
			|| !is_string($state['state_identity_sha256'] ?? null)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$state['state_identity_sha256'])!==1
			|| !is_int($state['count'] ?? null) || $state['count']<1
			|| !is_string($state['last_at'] ?? null)
			|| preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$state['last_at'])!==1
			|| !hash_equals(self::canonical($state),$bytes)){
			throw new RuntimeException('Scheduler probe state contract is invalid.');
		}
		return $state;
	}

	/** @param array<string,mixed> $state */
	private static function write(array $state): void
	{
		self::prepareDirectory();
		$bytes=self::canonical($state);
		$temporary=self::DIRECTORY.'/.scheduler-probe.'.bin2hex(random_bytes(16)).'.tmp';
		$handle=null;
		try{
			$handle=@fopen($temporary,'x+b');
			if(!is_resource($handle) || !@chmod($temporary,0600)) throw new RuntimeException('Scheduler probe temporary file is unavailable.');
			if(@fwrite($handle,$bytes)!==strlen($bytes) || !@fflush($handle)
				|| !function_exists('fsync') || !@fsync($handle)){
				throw new RuntimeException('Scheduler probe state write failed.');
			}
			@fclose($handle);$handle=null;
			if(!@rename($temporary,self::FILE)) throw new RuntimeException('Scheduler probe state replacement failed.');
			$directory=@fopen(self::DIRECTORY,'rb');
			if(!is_resource($directory)) throw new RuntimeException('Scheduler probe directory is unavailable.');
			try{if(!@fsync($directory)) throw new RuntimeException('Scheduler probe directory sync failed.');}
			finally{@fclose($directory);}
		}catch(Throwable $failure){
			if(is_resource($handle)) @fclose($handle);
			$stat=@lstat($temporary);if(self::validFile($stat)) @unlink($temporary);
			throw $failure;
		}
	}

	private static function prepareDirectory(): void
	{
		if(!is_dir('/var/lib/dataphyre') && !@mkdir('/var/lib/dataphyre',0755)) throw new RuntimeException('Scheduler probe root is unavailable.');
		if(!is_dir(self::DIRECTORY) && !@mkdir(self::DIRECTORY,0700)) throw new RuntimeException('Scheduler probe directory is unavailable.');
		foreach([['/var/lib/dataphyre',0755],[self::DIRECTORY,0700]] as [$path,$mode]){
			$stat=@lstat($path);$resolved=@realpath($path);
			if(is_link($path) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
				|| (($stat['mode'] ?? 0)&0777)!==$mode || ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
				|| !is_string($resolved) || $resolved!==$path){
				throw new RuntimeException('Scheduler probe directory identity is invalid.');
			}
		}
	}

	/** @param array<string,mixed> $state */
	private static function canonical(array $state): string
	{
		return json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
	}

	private static function validFile(mixed $stat): bool
	{
		return is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0100000
			&& (($stat['mode'] ?? 0)&0777)===0600 && ($stat['uid'] ?? -1)===0
			&& ($stat['gid'] ?? -1)===0 && ($stat['nlink'] ?? 0)===1;
	}
}
