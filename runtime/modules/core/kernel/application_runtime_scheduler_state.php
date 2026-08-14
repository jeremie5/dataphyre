<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';

/** Root-only durable cadence state; application pools never receive this path. */
final class DataphyreApplicationRuntimeSchedulerState
{
	private const CONTRACT='dataphyre.scheduler_state.v1';
	private const ROOT='/var/lib/dataphyre/scheduler-state';
	private const MAX_BYTES=262144;
	private const CLAIM_GRACE_SECONDS=15;

	private static function root(): string
	{
		if(defined('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT')){
			$value=constant('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT');
			if(is_string($value) && $value!=='' && !is_link($value) && is_dir($value)) return rtrim($value,'/');
			throw new RuntimeException('Scheduler state test root is invalid.');
		}
		return self::ROOT;
	}

	private static function file(): string {return self::root().'/state.json';}
	private static function lock(): string {return self::root().'/state.lock';}

	/** Reconciles only a complete, already-validated registration before cadence selection. */
	public static function reconcile(array $identity,array $definitions,?int $now=null): void
	{
		$now ??= time();if($now<1) throw new RuntimeException('Scheduler reconciliation time is invalid.');
		$expected=[];
		foreach($definitions as $definition){
			self::assertDefinition($definition);$name=$definition['name'];
			if(isset($expected[$name])) throw new RuntimeException('Scheduler registration contains duplicate names.');
			$expected[$name]=self::definitionSha256($definition);
		}
		if(count($expected)>512) throw new RuntimeException('Scheduler registration exceeded its active definition bound.');
		self::locked(static function() use ($identity,$expected,$now): void {
			$state=self::read($identity);$changed=false;
			foreach($state['entries'] as $name=>$entry){
				if(!isset($expected[$name]) || !hash_equals($expected[$name],$entry['definition_sha256'])){
					if(is_int($entry['claim_expires_at'] ?? null) && $entry['claim_expires_at']>=$now) continue;
					unset($state['entries'][$name]);$changed=true;
				}
			}
			if($changed) self::write($state);
		});
	}

	/** @param array<string,string> $identity @param list<array<string,mixed>> $definitions @return list<array<string,mixed>> */
	public static function due(array $identity,array $definitions,int $now): array
	{
		if($now<1) throw new RuntimeException('Scheduler due time is invalid.');
		return array_map(
			static fn(array $scheduled): array=>$scheduled['definition'],
			self::dueSchedule($identity,$definitions,$now*1000),
		);
	}

	/**
	 * Returns due definitions with the exact wall-clock instant each one became due.
	 *
	 * The supervisor needs this timestamp to distinguish a callback that eventually
	 * returned from one that actually met its declared cadence. It remains root-only
	 * runtime evidence and does not change the durable state-file contract.
	 *
	 * @param array<string,string> $identity
	 * @param list<array<string,mixed>> $definitions
	 * @return list<array{definition:array<string,mixed>,due_at_milliseconds:int,first_execution:bool}>
	 */
	public static function dueSchedule(array $identity,array $definitions,int $nowMilliseconds): array
	{
		if($nowMilliseconds<1000) throw new RuntimeException('Scheduler due time is invalid.');
		return self::locked(static function() use ($identity,$definitions,$nowMilliseconds): array {
			$state=self::read($identity);
			$due=[];$nowSeconds=intdiv($nowMilliseconds,1000);
			foreach($definitions as $definition){
				self::assertDefinition($definition);
				$name=$definition['name'];
				$entry=$state['entries'][$name] ?? null;
				$claimed=is_array($entry) && is_int($entry['claim_expires_at'] ?? null)
					&& $entry['claim_expires_at']>=$nowSeconds;
				$definitionSha=self::definitionSha256($definition);
				$last=is_array($entry) && ($entry['definition_sha256'] ?? null)===$definitionSha
					? ($entry['last_success_at'] ?? null)
					: null;
				$firstExecution=!is_int($last) || $last<1;
				$dueAt=$firstExecution
					? $nowMilliseconds
					: ($last*1000)+(int)$definition['frequency_milliseconds'];
				if(!$claimed && $nowMilliseconds>=$dueAt){
					$due[]=[
						'definition'=>$definition,
						'due_at_milliseconds'=>$dueAt,
						'first_execution'=>$firstExecution,
					];
				}
			}
			return $due;
		});
	}

	/** Atomically reserves one due definition across predecessor/recovery supervisors. */
	public static function claim(
		array $identity,
		array $definition,
		string $releaseId,
		string $generation,
		string $claimNonce,
		int $claimedAt,
	): bool {
		self::assertDefinition($definition);
		if(preg_match('/^dep_[a-f0-9]{40}$/D',$releaseId)!==1
			|| preg_match('/^gen_[a-f0-9]{32}$/D',$generation)!==1
			|| preg_match('/^[a-f0-9]{64}$/D',$claimNonce)!==1 || $claimedAt<1){
			throw new RuntimeException('Scheduler claim identity is invalid.');
		}
		return self::locked(static function() use (
			$identity,$definition,$releaseId,$generation,$claimNonce,$claimedAt,
		): bool {
			$state=self::read($identity);$name=$definition['name'];$definitionSha=self::definitionSha256($definition);
			$entry=$state['entries'][$name] ?? null;
			if(is_array($entry) && ($entry['claim_expires_at'] ?? null)>=$claimedAt) return false;
			$last=is_array($entry) && ($entry['definition_sha256'] ?? null)===$definitionSha
				? ($entry['last_success_at'] ?? null)
				: null;
			if(is_int($last) && (($claimedAt-$last)*1000)<(int)$definition['frequency_milliseconds']) return false;
			$state['entries'][$name]=[
				'definition_sha256'=>$definitionSha,
				'last_success_at'=>is_int($last) ? $last : null,
				'release_id'=>$releaseId,'generation'=>$generation,
				'claim_nonce'=>$claimNonce,
				'claim_expires_at'=>$claimedAt
					+(int)ceil(((int)$definition['timeout_milliseconds'])/1000)
					+self::CLAIM_GRACE_SECONDS,
			];
			if(count($state['entries'])>512){
				throw new RuntimeException('Scheduler state active definition bound was exceeded.');
			}
			ksort($state['entries'],SORT_STRING);self::write($state);return true;
		});
	}

	/** @param array<string,string> $identity @param array<string,mixed> $definition */
	public static function recordSuccess(
		array $identity,
		array $definition,
		string $releaseId,
		string $generation,
		int $completedAt,
		?string $claimNonce=null,
	): void {
		self::assertDefinition($definition);
		if(preg_match('/^dep_[a-f0-9]{40}$/D',$releaseId)!==1
			|| preg_match('/^gen_[a-f0-9]{32}$/D',$generation)!==1 || $completedAt<1){
			throw new RuntimeException('Scheduler completion identity is invalid.');
		}
		if(!is_string($claimNonce) || preg_match('/^[a-f0-9]{64}$/D',$claimNonce)!==1){
			throw new RuntimeException('Scheduler completion claim is invalid.');
		}
		self::locked(static function() use ($identity,$definition,$releaseId,$generation,$completedAt,$claimNonce): void {
			$state=self::read($identity);
			$existing=$state['entries'][$definition['name']] ?? null;
			if(!is_array($existing) || !is_string($existing['claim_nonce'] ?? null)
				|| !hash_equals($claimNonce,$existing['claim_nonce'])
				|| ($existing['release_id'] ?? null)!==$releaseId || ($existing['generation'] ?? null)!==$generation
				|| ($existing['definition_sha256'] ?? null)!==self::definitionSha256($definition)){
				throw new RuntimeException('Scheduler completion claim no longer owns the definition.');
			}
			$state['entries'][$definition['name']]=[
				'definition_sha256'=>self::definitionSha256($definition),
				'last_success_at'=>$completedAt,
				'release_id'=>$releaseId,
				'generation'=>$generation,
				'claim_nonce'=>null,
				'claim_expires_at'=>null,
			];
			ksort($state['entries'],SORT_STRING);
			self::write($state);
		});
	}

	/** Releases a failed claim immediately; a process crash is recovered by its fixed expiry. */
	public static function releaseClaim(
		array $identity,
		array $definition,
		string $releaseId,
		string $generation,
		string $claimNonce,
	): void {
		self::assertDefinition($definition);
		if(preg_match('/^dep_[a-f0-9]{40}$/D',$releaseId)!==1
			|| preg_match('/^gen_[a-f0-9]{32}$/D',$generation)!==1
			|| preg_match('/^[a-f0-9]{64}$/D',$claimNonce)!==1){
			throw new RuntimeException('Scheduler release claim identity is invalid.');
		}
		self::locked(static function() use ($identity,$definition,$releaseId,$generation,$claimNonce): void {
			$state=self::read($identity);$name=$definition['name'];$entry=$state['entries'][$name] ?? null;
			if(!is_array($entry) || !is_string($entry['claim_nonce'] ?? null)
				|| !hash_equals($claimNonce,$entry['claim_nonce'])
				|| ($entry['release_id'] ?? null)!==$releaseId || ($entry['generation'] ?? null)!==$generation){
				throw new RuntimeException('Scheduler release claim no longer owns the definition.');
			}
			$entry['claim_nonce']=null;$entry['claim_expires_at']=null;$state['entries'][$name]=$entry;
			self::write($state);
		});
	}

	/** @param array<string,string> $identity */
	public static function stateSha256(array $identity): string
	{
		return self::locked(static fn(): string=>'sha256:'.hash('sha256',self::canonical(self::read($identity))));
	}

	/** @param array<string,string> $identity */
	public static function identitySha256(array $identity): string
	{
		$state=self::emptyState($identity);
		unset($state['entries']);
		return 'sha256:'.hash(
			'sha256',
			"dataphyre.scheduler_state_identity.v1\0".json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
		);
	}

	/** @param array<string,mixed> $definition */
	public static function definitionSha256(array $definition): string
	{
		self::assertDefinition($definition);
		return 'sha256:'.hash('sha256',json_encode($definition,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
	}

	/** @param array<string,string> $identity @return array<string,mixed> */
	private static function read(array $identity): array
	{
		$empty=self::emptyState($identity);
		$file=self::file();clearstatcache(true,$file);
		if(!file_exists($file) && !is_link($file)) return $empty;
		if(is_link($file)) throw new RuntimeException('Scheduler state cannot be a symbolic link.');
		$handle=@fopen($file,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Scheduler state is unavailable.');
		try{
			$handleStat=@fstat($handle);$pathStat=@lstat($file);
			$bytes=stream_get_contents($handle,self::MAX_BYTES+1);$extra=fread($handle,1);
			if(!self::validFile($handleStat) || !self::validFile($pathStat)
				|| ($handleStat['dev'] ?? null)!==($pathStat['dev'] ?? null)
				|| ($handleStat['ino'] ?? null)!==($pathStat['ino'] ?? null)
				|| !is_string($bytes) || $bytes==='' || strlen($bytes)>self::MAX_BYTES || $extra!==''){
				throw new RuntimeException('Scheduler state identity is invalid.');
			}
		}finally{@fclose($handle);}
		try{$state=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);}
		catch(Throwable){throw new RuntimeException('Scheduler state JSON is invalid.');}
		if(!is_array($state) || array_keys($state)!==[
			'contract','cloud_application','framework_application','environment','entries',
		] || ($state['contract'] ?? null)!==self::CONTRACT
			|| ($state['cloud_application'] ?? null)!==$empty['cloud_application']
			|| ($state['framework_application'] ?? null)!==$empty['framework_application']
			|| ($state['environment'] ?? null)!==$empty['environment']
			|| !is_array($state['entries']) || count($state['entries'])>512){
			throw new RuntimeException('Scheduler state contract is invalid.');
		}
		$normalized=[];
		foreach($state['entries'] as $name=>$entry){
			if(!is_string($name) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$name)!==1
				|| !is_array($entry) || array_keys($entry)!==[
					'definition_sha256','last_success_at','release_id','generation','claim_nonce','claim_expires_at',
				]
				|| !is_string($entry['definition_sha256'] ?? null)
				|| preg_match('/^sha256:[a-f0-9]{64}$/D',$entry['definition_sha256'])!==1
				|| !(is_null($entry['last_success_at'] ?? null)
					|| is_int($entry['last_success_at']) && $entry['last_success_at']>=1)
				|| !is_string($entry['release_id'] ?? null)
				|| preg_match('/^dep_[a-f0-9]{40}$/D',$entry['release_id'])!==1
				|| !is_string($entry['generation'] ?? null)
				|| preg_match('/^gen_[a-f0-9]{32}$/D',$entry['generation'])!==1
				|| !((is_null($entry['claim_nonce'] ?? null) && is_null($entry['claim_expires_at'] ?? null))
					|| (is_string($entry['claim_nonce']) && preg_match('/^[a-f0-9]{64}$/D',$entry['claim_nonce'])===1
						&& is_int($entry['claim_expires_at']) && $entry['claim_expires_at']>=1))){
				throw new RuntimeException('Scheduler state entry is invalid.');
			}
			$normalized[$name]=$entry;
		}
		ksort($normalized,SORT_STRING);$state['entries']=$normalized;
		if(!hash_equals(self::canonical($state),$bytes)) throw new RuntimeException('Scheduler state is not canonical.');
		return $state;
	}

	/** @param array<string,string> $identity @return array<string,mixed> */
	private static function emptyState(array $identity): array
	{
		$cloud=$identity['cloud_application'] ?? '';
		$framework=$identity['framework_application'] ?? '';
		$environment=$identity['environment'] ?? '';
		if(preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D',$cloud)!==1
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$framework)!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)){
			throw new RuntimeException('Scheduler state identity is invalid.');
		}
		return [
			'contract'=>self::CONTRACT,
			'cloud_application'=>$cloud,
			'framework_application'=>$framework,
			'environment'=>$environment,
			'entries'=>[],
		];
	}

	/** @param array<string,mixed> $state */
	private static function write(array $state): void
	{
		$bytes=self::canonical($state);
		if(strlen($bytes)>self::MAX_BYTES) throw new RuntimeException('Scheduler state exceeded its bound.');
		$file=self::file();$temporary=dirname($file).'/.state.'.bin2hex(random_bytes(16)).'.tmp';
		$handle=null;
		try{
			$handle=@fopen($temporary,'x+b');
			if(!is_resource($handle) || !@chmod($temporary,0600)) throw new RuntimeException('Scheduler state temporary file is unavailable.');
			$offset=0;
			while($offset<strlen($bytes)){
				$written=@fwrite($handle,substr($bytes,$offset));
				if(!is_int($written) || $written<1) throw new RuntimeException('Scheduler state write failed.');
				$offset+=$written;
			}
			if(!@fflush($handle) || !function_exists('fsync') || !@fsync($handle)) throw new RuntimeException('Scheduler state sync failed.');
			@fclose($handle);$handle=null;
			if(!@rename($temporary,$file)) throw new RuntimeException('Scheduler state replacement failed.');
			self::syncDirectory(dirname($file));
		}catch(Throwable $failure){
			if(is_resource($handle)) @fclose($handle);
			$stat=@lstat($temporary);if(self::validFile($stat)) @unlink($temporary);
			throw $failure;
		}
	}

	/** @param array<string,mixed> $state */
	private static function canonical(array $state): string
	{
		return json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
	}

	/** @param array<string,mixed> $definition */
	private static function assertDefinition(array $definition): void
	{
		if(array_keys($definition)!==[
			'name','task_sha256','dependency_sha256','frequency_milliseconds','timeout_milliseconds','memory_limit',
		] || !is_string($definition['name'] ?? null)
			|| preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$definition['name'])!==1
			|| !is_string($definition['task_sha256'] ?? null)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$definition['task_sha256'])!==1
			|| !is_array($definition['dependency_sha256'] ?? null) || !array_is_list($definition['dependency_sha256'])
			|| count($definition['dependency_sha256'])>128
			|| !is_int($definition['frequency_milliseconds'] ?? null)
			|| $definition['frequency_milliseconds']<0 || $definition['frequency_milliseconds']>2147483647
			|| !is_int($definition['timeout_milliseconds'] ?? null)
			|| $definition['timeout_milliseconds']<1000 || $definition['timeout_milliseconds']>300000
			|| !is_string($definition['memory_limit'] ?? null)
			|| preg_match('/^[1-9][0-9]{0,5}[KMG]$/D',$definition['memory_limit'])!==1){
			throw new RuntimeException('Scheduler definition is invalid.');
		}
		foreach($definition['dependency_sha256'] as $sha){
			if(!is_string($sha) || preg_match('/^sha256:[a-f0-9]{64}$/D',$sha)!==1){
				throw new RuntimeException('Scheduler dependency evidence is invalid.');
			}
		}
	}

	private static function validFile(mixed $stat): bool
	{
		[$uid,$gid]=self::expectedOwner();
		return is_array($stat) && (($stat['mode'] ?? 0)&0170000)===0100000
			&& (($stat['mode'] ?? 0)&0777)===0600 && ($stat['uid'] ?? -1)===$uid
			&& ($stat['gid'] ?? -1)===$gid && ($stat['nlink'] ?? 0)===1;
	}

	/** Production is root-owned; an explicit test-root constant follows its directory owner. */
	private static function expectedOwner(): array
	{
		if(!defined('DATAPHYRE_INTERNAL_SCHEDULER_STATE_TEST_ROOT')) return [0,0];
		$stat=@lstat(self::root());
		return [(int)$stat['uid'],(int)$stat['gid']];
	}

	private static function syncDirectory(string $path): void
	{
		$handle=@fopen($path,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Scheduler state directory is unavailable.');
		try{if(!function_exists('fsync') || !@fsync($handle)) throw new RuntimeException('Scheduler state directory sync failed.');}
		finally{@fclose($handle);}
	}

	private static function locked(callable $operation): mixed
	{
		$lock=self::lock();
		if(is_link($lock)) throw new RuntimeException('Scheduler state lock cannot be a symbolic link.');
		$handle=@fopen($lock,'c+b');
		if(!is_resource($handle) || !@chmod($lock,0600)) throw new RuntimeException('Scheduler state lock is unavailable.');
		try{
			$handleStat=@fstat($handle);$pathStat=@lstat($lock);
			if(!self::validFile($handleStat) || !self::validFile($pathStat)
				|| ($handleStat['dev'] ?? null)!==($pathStat['dev'] ?? null)
				|| ($handleStat['ino'] ?? null)!==($pathStat['ino'] ?? null)
				|| !@flock($handle,LOCK_EX)){
				throw new RuntimeException('Scheduler state lock identity is invalid.');
			}
			return $operation();
		}finally{
			@flock($handle,LOCK_UN);@fclose($handle);
		}
	}
}
