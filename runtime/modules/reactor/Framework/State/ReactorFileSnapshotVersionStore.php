<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Private-filesystem snapshot reservation/CAS ledger.
 *
 * Updates publish immutable generations through a same-directory temporary file
 * and atomic rename, so an interrupted write cannot truncate the last committed
 * generation. A private global advisory lock serializes reads and publication.
 * Production operators must place the directory on a filesystem whose flock
 * and rename guarantees are shared by every Reactor worker that can receive the
 * same snapshot; this adapter cannot prove remote/network filesystem semantics.
 */
final class ReactorFileSnapshotVersionStore implements ReactorSnapshotVersionStore {
	private const RECORD_KEYS=['component','expires_at','generation','reservation_expires_at','reservation_id','scope_hash','version'];
	private const MAX_RESERVATION_SECONDS=300;
	private readonly string $directory;
	private readonly string $lockFile;

	public function __construct(string $directory, private readonly int $maxEntries=10000, private readonly int $prunePerOperation=64, private readonly bool $sharedFilesystemAttested=false) {
		if($maxEntries<1 || $maxEntries>1000000){ throw new \InvalidArgumentException('Reactor file snapshot maxEntries must be an integer from 1 to 1000000.'); }
		if($prunePerOperation<1 || $prunePerOperation>1000){ throw new \InvalidArgumentException('Reactor file snapshot prunePerOperation must be an integer from 1 to 1000.'); }
		$directory=rtrim(trim($directory), '/\\');
		if($directory===''){ throw new \InvalidArgumentException('Reactor snapshot store directory is required.'); }
		self::assertNoSymlinkPath($directory);
		if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)){
			throw new \RuntimeException('Reactor snapshot store directory could not be created.');
		}
		self::assertNoSymlinkPath($directory);
		$real=realpath($directory);
		if($real===false || !is_dir($real) || is_link($real) || !is_writable($real)){ throw new \RuntimeException('Reactor snapshot store directory is not a safe writable directory.'); }
		@chmod($real, 0700);
		clearstatcache(true, $real);
		$permissions=fileperms($real);
		if(DIRECTORY_SEPARATOR==='/' && ($permissions===false || ($permissions & 0077)!==0)){ throw new \RuntimeException('Reactor snapshot store directory must not grant group or world permissions.'); }
		$this->directory=rtrim($real, '/\\');
		$this->lockFile=$this->directory.DIRECTORY_SEPARATOR.'.reactor-snapshot-store.lock';
		if(is_link($this->lockFile)){ throw new \RuntimeException('Reactor snapshot store lock file may not be a symlink.'); }
	}

	public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		if(!self::validIdentity($snapshotId, $scopeHash, $component, $version, $expiresAt)){ return false; }
		return $this->withLock(function()use($snapshotId,$scopeHash,$component,$version,$expiresAt): bool {
			$this->pruneExpired();
			$current=$this->loadLatest($snapshotId);
			if($current===false){ return false; }
			$expected=['scope_hash'=>$scopeHash,'component'=>$component,'version'=>$version,'expires_at'=>$expiresAt,'reservation_id'=>'','reservation_expires_at'=>0];
			if(is_array($current)){
				$comparison=$current;
				unset($comparison['generation']);
				return $comparison===$expected;
			}
			if($this->instanceCount()>=$this->maxEntries){ return false; }
			return $this->publish($snapshotId, $expected+['generation'=>1]);
		}, false);
	}

	public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string {
		if(!self::validIdentity($snapshotId, $scopeHash, $component, $expectedVersion, $reservationExpiresAt) || !self::validReservation($reservationId)){ return self::UNAVAILABLE; }
		return $this->withLock(function()use($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId,$reservationExpiresAt): string {
			$current=$this->loadLatest($snapshotId);
			if($current===false){ return self::UNAVAILABLE; }
			if($current===null){ return self::MISSING; }
			if($current['scope_hash']!==$scopeHash || $current['component']!==$component){ return self::MISMATCH; }
			$now=time();
			if($current['expires_at']<=$now){
				$this->removeGenerations($snapshotId);
				return self::EXPIRED;
			}
			if($expectedVersion<$current['version']){ return self::STALE; }
			if($expectedVersion>$current['version']){ return self::FUTURE; }
			if($reservationExpiresAt<=$now || $reservationExpiresAt>min($current['expires_at'], $now+self::MAX_RESERVATION_SECONDS)){ return self::UNAVAILABLE; }
			if($current['reservation_id']!=='' && $current['reservation_expires_at']>$now){ return self::BUSY; }
			$current['generation']++;
			$current['reservation_id']=$reservationId;
			$current['reservation_expires_at']=$reservationExpiresAt;
			return $this->publish($snapshotId, $current) ? self::CLAIMED : self::UNAVAILABLE;
		}, self::UNAVAILABLE);
	}

	public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string {
		if(!self::validIdentity($snapshotId, $scopeHash, $component, $expectedVersion, $nextExpiresAt) || $nextVersion!==$expectedVersion+1 || !self::validReservation($reservationId)){ return self::UNAVAILABLE; }
		return $this->withLock(function()use($snapshotId,$scopeHash,$component,$expectedVersion,$nextVersion,$nextExpiresAt,$reservationId): string {
			$current=$this->loadLatest($snapshotId);
			if($current===false){ return self::UNAVAILABLE; }
			if($current===null){ return self::MISSING; }
			if($current['scope_hash']!==$scopeHash || $current['component']!==$component){ return self::MISMATCH; }
			if($current['expires_at']<=time()){
				$this->removeGenerations($snapshotId);
				return self::EXPIRED;
			}
			if($current['version']!==$expectedVersion){ return $current['version']>$expectedVersion ? self::STALE : self::FUTURE; }
			if($current['reservation_id']!==$reservationId){ return self::BUSY; }
			if($current['reservation_expires_at']<=time()){
				$current['generation']++;
				$current['reservation_id']='';
				$current['reservation_expires_at']=0;
				return $this->publish($snapshotId, $current) ? self::RESERVATION_EXPIRED : self::UNAVAILABLE;
			}
			$current['generation']++;
			$current['version']=$nextVersion;
			$current['expires_at']=$nextExpiresAt;
			$current['reservation_id']='';
			$current['reservation_expires_at']=0;
			return $this->publish($snapshotId, $current) ? self::CLAIMED : self::UNAVAILABLE;
		}, self::UNAVAILABLE);
	}

	public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool {
		if(!self::validReservation($reservationId)){ return false; }
		return $this->withLock(function()use($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId): bool {
			$current=$this->loadLatest($snapshotId);
			if(!is_array($current) || $current['scope_hash']!==$scopeHash || $current['component']!==$component || $current['version']!==$expectedVersion || $current['reservation_id']!==$reservationId){ return false; }
			$current['generation']++;
			$current['reservation_id']='';
			$current['reservation_expires_at']=0;
			return $this->publish($snapshotId, $current);
		}, false);
	}

	public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool {
		return $this->withLock(function()use($snapshotId,$scopeHash,$component,$version): bool {
			$current=$this->loadLatest($snapshotId);
			if(!is_array($current) || $current['scope_hash']!==$scopeHash || $current['component']!==$component || $current['version']!==$version || $current['reservation_id']!==''){ return false; }
			$this->removeGenerations($snapshotId);
			$remaining=$this->generationFiles($snapshotId);
			return is_array($remaining) && $remaining===[];
		}, false);
	}

	public function manifest(): array {
		return [
			'adapter'=>'file',
			'atomic_compare_and_swap'=>true,
			'atomic_batch_register'=>false,
			'reservation_finalize_abort'=>true,
			'coordination_scope'=>'workers_sharing_verified_flock_and_atomic_rename_filesystem',
			'production_safe'=>$this->sharedFilesystemAttested,
			'production_precondition'=>'all_workers_share_a_private_filesystem_with_verified_cross_worker_flock_and_atomic_rename',
			'shared_filesystem_attested'=>$this->sharedFilesystemAttested,
			'private_directory_permissions_enforced'=>DIRECTORY_SEPARATOR==='/',
			'persists_component_state'=>false,
			'one_time_claim_guarantee'=>'completed_dispatches_on_the_same_verified_locking_filesystem',
			'crash_window'=>'expired_reservations_are_retryable; host action idempotency is still required',
			'partial_mount_rollback'=>'best_effort_revoke_not_atomic_across_multiple_snapshot_instances',
			'expiry_boundary'=>'expires_at_lte_now_is_expired',
			'max_reservation_seconds'=>self::MAX_RESERVATION_SECONDS,
			'write_strategy'=>'immutable_generation_temp_plus_atomic_rename',
			'symlinks_allowed'=>false,
			'expired_pruning'=>'bounded_opportunistic',
			'max_entries'=>$this->maxEntries,
			'prune_per_operation'=>$this->prunePerOperation,
			'path_serialized'=>false,
		];
	}

	/** @template T @param callable():T $callback @param T $failure @return T */
	private function withLock(callable $callback, mixed $failure): mixed {
		if(is_link($this->lockFile) || !$this->confined($this->lockFile, false)){ return $failure; }
		$handle=@fopen($this->lockFile, 'c+b');
		if(!is_resource($handle)){ return $failure; }
		try{
			$stat=fstat($handle);
			if(!is_array($stat) || (((int)$stat['mode']) & 0170000)!==0100000 || is_link($this->lockFile)){ return $failure; }
			if(!flock($handle, LOCK_EX)){ return $failure; }
			try{ return $callback(); }
			finally{ flock($handle, LOCK_UN); }
		}
		catch(\Throwable){ return $failure; }
		finally{ fclose($handle); }
	}

	/** @return array<string,mixed>|false|null */
	private function loadLatest(string $snapshotId): array|false|null {
		$files=$this->generationFiles($snapshotId);
		if($files===false){ return false; }
		if($files===[]){ return null; }
		$latest=null;
		$seen=[];
		foreach($files as $file){
			if(is_link($file) || !$this->confined($file, true) || !is_file($file) || filesize($file)>4096){ return false; }
			try{ $record=json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR); }
			catch(\Throwable){ return false; }
			if(!is_array($record) || !self::validRecord($record, $snapshotId)){ return false; }
			$generation=$record['generation'];
			if(isset($seen[$generation])){ return false; }
			$seen[$generation]=true;
			if($latest===null || $generation>$latest['generation']){ $latest=$record; }
		}
		return $latest;
	}

	/** @return list<string>|false */
	private function generationFiles(string $snapshotId): array|false {
		if(preg_match('/^[a-f0-9]{32}$/D', $snapshotId)!==1){ return false; }
		$matches=glob($this->directory.DIRECTORY_SEPARATOR.$snapshotId.'.*.json', GLOB_NOSORT);
		if($matches===false || count($matches)>32){ return false; }
		$files=[];
		foreach($matches as $file){
			if(preg_match('/^'.preg_quote($snapshotId, '/').'\.[0-9]{12}\.[a-f0-9]{16}\.json$/D', basename($file))!==1){ return false; }
			$files[]=$file;
		}
		return $files;
	}

	/** @param array<string,mixed> $record */
	private function publish(string $snapshotId, array $record): bool {
		ksort($record);
		try{ $encoded=json_encode($record, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
		catch(\Throwable){ return false; }
		if(strlen($encoded)>4096){ return false; }
		try{ $nonce=bin2hex(random_bytes(8)); }
		catch(\Throwable){ return false; }
		$name=$snapshotId.'.'.str_pad((string)$record['generation'], 12, '0', STR_PAD_LEFT).'.'.$nonce.'.json';
		$destination=$this->directory.DIRECTORY_SEPARATOR.$name;
		$temp=$this->directory.DIRECTORY_SEPARATOR.'.tmp-'.$nonce.'-'.bin2hex(random_bytes(4));
		if(!$this->confined($destination, false) || !$this->confined($temp, false) || file_exists($destination) || is_link($destination)){ return false; }
		$handle=@fopen($temp, 'x+b');
		if(!is_resource($handle)){ return false; }
		$ok=false;
		try{
			@chmod($temp, 0600);
			$written=fwrite($handle, $encoded);
			$ok=$written===strlen($encoded) && fflush($handle);
			if($ok && function_exists('fsync')){ $ok=fsync($handle); }
		}
		finally{ fclose($handle); }
		if(!$ok || is_link($temp) || !$this->confined($temp, true) || !@rename($temp, $destination)){
			if(is_file($temp) && !is_link($temp)){ @unlink($temp); }
			return false;
		}
		$this->pruneGenerations($snapshotId, 2);
		return true;
	}

	private function pruneGenerations(string $snapshotId, int $keep): void {
		$files=$this->generationFiles($snapshotId);
		if(!is_array($files) || count($files)<=$keep){ return; }
		usort($files, static fn(string $a,string $b): int=>strcmp(basename($b), basename($a)));
		foreach(array_slice($files, $keep) as $file){ if(!is_link($file) && $this->confined($file, true)){ @unlink($file); } }
	}

	private function removeGenerations(string $snapshotId): void {
		$files=$this->generationFiles($snapshotId);
		if(!is_array($files)){ return; }
		foreach($files as $file){ if(!is_link($file) && $this->confined($file, true)){ @unlink($file); } }
	}

	private function pruneExpired(): void {
		$ids=[];
		$iterator=new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS);
		foreach($iterator as $entry){
			if(count($ids)>=$this->prunePerOperation){ break; }
			$name=$entry->getFilename();
			if(preg_match('/^([a-f0-9]{32})\.[0-9]{12}\.[a-f0-9]{16}\.json$/D', $name, $match)===1){ $ids[$match[1]]=true; }
		}
		foreach(array_keys($ids) as $id){
			$record=$this->loadLatest($id);
			if(is_array($record) && $record['expires_at']<=time() && ($record['reservation_id']==='' || $record['reservation_expires_at']<=time())){ $this->removeGenerations($id); }
		}
	}

	private function instanceCount(): int {
		$ids=[];
		$iterator=new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS);
		foreach($iterator as $entry){
			if(preg_match('/^([a-f0-9]{32})\.[0-9]{12}\.[a-f0-9]{16}\.json$/D', $entry->getFilename(), $match)===1){
				$ids[$match[1]]=true;
				if(count($ids)>=$this->maxEntries){ break; }
			}
		}
		return count($ids);
	}

	private function confined(string $path, bool $mustExist): bool {
		if(is_link($path)){ return false; }
		$parent=realpath(dirname($path));
		if($parent===false || rtrim($parent, '/\\')!==$this->directory){ return false; }
		if(!$mustExist){ return true; }
		$real=realpath($path);
		return $real!==false && str_starts_with($real, $this->directory.DIRECTORY_SEPARATOR);
	}

	private static function assertNoSymlinkPath(string $path): void {
		$cursor=$path;
		while($cursor!=='' && $cursor!==dirname($cursor)){
			if(file_exists($cursor) && is_link($cursor)){ throw new \RuntimeException('Reactor snapshot store paths may not contain symlinks.'); }
			$cursor=dirname($cursor);
		}
	}

	private static function validIdentity(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		return preg_match('/^[a-f0-9]{32}$/D', $snapshotId)===1 && preg_match('/^[a-f0-9]{64}$/D', $scopeHash)===1
			&& ReactorName::normalize($component)===$component && $component!=='' && $version>=0 && $expiresAt>0;
	}

	private static function validReservation(string $reservationId): bool { return preg_match('/^[a-f0-9]{32}$/D', $reservationId)===1; }

	/** @param array<string,mixed> $record */
	private static function validRecord(array $record, string $snapshotId): bool {
		$keys=array_keys($record);
		sort($keys);
		$expected=self::RECORD_KEYS;
		sort($expected);
		return $keys===$expected
			&& self::validIdentity($snapshotId, is_string($record['scope_hash'] ?? null) ? $record['scope_hash'] : '', is_string($record['component'] ?? null) ? $record['component'] : '', is_int($record['version'] ?? null) ? $record['version'] : -1, is_int($record['expires_at'] ?? null) ? $record['expires_at'] : 0)
			&& is_int($record['generation'] ?? null) && $record['generation']>=1 && $record['generation']<=999999999999
			&& is_string($record['reservation_id'] ?? null) && ($record['reservation_id']==='' || self::validReservation($record['reservation_id']))
			&& is_int($record['reservation_expires_at'] ?? null) && $record['reservation_expires_at']>=0;
	}
}
