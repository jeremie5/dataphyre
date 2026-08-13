<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Crash-safe, process-safe JSON state store backed by immutable snapshots.
 *
 * Every commit is written to a temporary file and atomically renamed to a new,
 * never-overwritten sequence filename. Readers select the newest valid JSON
 * snapshot, so an interrupted write cannot corrupt the previous committed
 * state. The same snapshots double as an ordered change feed.
 */
final class PanelAtomicSnapshotStore implements PanelSnapshotStore {

	private string $directory;
	private string $schema;
	private int $retain;
	/** @var array<string,mixed> */
	private array $initialPayload;

	/**
	 * @param array<string,mixed> $initialPayload State returned before the first commit.
	 */
	public function __construct(string $directory, string $schema, array $initialPayload=[], int $retain=512) {
		$directory=rtrim(trim($directory), "\\/");
		if($directory==='' || str_contains($directory, "\0")){
			throw new \InvalidArgumentException('Snapshot directory must be a non-empty filesystem path.');
		}
		$this->directory=$directory;
		$this->schema=trim($schema);
		if($this->schema==='' || preg_match('/^[a-zA-Z0-9._-]+$/', $this->schema)!==1){
			throw new \InvalidArgumentException('Snapshot schema may only contain letters, numbers, dots, dashes, and underscores.');
		}
		$this->retain=max(8, $retain);
		$this->initialPayload=$initialPayload;
		$this->ensureDirectory();
	}

	/** @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>} */
	public function snapshot(): array {
		return $this->withLock(LOCK_SH, fn(): array => $this->readLatestUnlocked());
	}

	/** @return array<string,mixed> */
	public function payload(): array {
		return $this->snapshot()['payload'];
	}

	public function cursor(): int {
		return $this->snapshot()['sequence'];
	}

	/**
	 * Mutates a private payload copy and durably commits the result.
	 *
	 * The callback receives its payload by reference and may return any result.
	 * Exceptions leave the previous snapshot untouched.
	 *
	 * @param callable(array<string,mixed>&):mixed $mutation
	 * @param array<string,mixed> $event
	 * @return array{result:mixed,snapshot:array<string,mixed>}
	 */
	public function transaction(callable $mutation, string $type, array $event=[]): array {
		$type=trim($type);
		if($type===''){
			throw new \InvalidArgumentException('Snapshot event type cannot be empty.');
		}
		return $this->withLock(LOCK_EX, function() use ($mutation, $type, $event): array {
			$current=$this->readLatestUnlocked();
			$payload=$current['payload'];
			$result=$mutation($payload);
			$sequence=$current['sequence']+1;
			$committedAt=gmdate('c');
			$change=array_replace($event, [
				'cursor'=>$sequence,
				'type'=>$type,
				'occurred_at'=>$committedAt,
			]);
			$snapshot=[
				'schema'=>$this->schema,
				'sequence'=>$sequence,
				'committed_at'=>$committedAt,
				'payload'=>$payload,
				'event'=>$change,
			];
			$this->writeSnapshotUnlocked($snapshot);
			$this->pruneUnlocked();
			return ['result'=>$result, 'snapshot'=>$snapshot];
		});
	}

	/**
	 * Returns ordered events after a cursor.
	 *
	 * A stale cursor is explicitly marked as requiring a reset when retention has
	 * removed history it would need. Callers can then hydrate the included current
	 * snapshot before resuming from the returned cursor.
	 *
	 * @return array{cursor:int,oldest_cursor:int,reset_required:bool,reset_reason:?string,changes:array<int,array<string,mixed>>,snapshot:?array<string,mixed>}
	 */
	public function changesSince(int $cursor=0, int $limit=100): array {
		$cursor=max(0, $cursor);
		$limit=max(1, min(1000, $limit));
		return $this->withLock(LOCK_SH, function() use ($cursor, $limit): array {
			$files=$this->snapshotFilesUnlocked();
			$files=array_values(array_filter($files, fn(string $file): bool => $this->decodeSnapshotFile($file)!==null));
			$current=$this->readLatestUnlocked($files);
			$oldest=$files!==[] ? $this->sequenceFromFilename($files[0]) : 0;
			$stale=$files!==[] && $cursor>0 && $cursor<max(0, $oldest-1);
			$future=$cursor>$current['sequence'];
			$reset=$stale||$future;
			$changes=[];
			if(!$reset){
				foreach($files as $file){
					$sequence=$this->sequenceFromFilename($file);
					if($sequence<=$cursor){
						continue;
					}
					$snapshot=$this->decodeSnapshotFile($file);
					if($snapshot!==null && is_array($snapshot['event'] ?? null)){
						$changes[]=$snapshot['event'];
					}
					if(count($changes)>=$limit){
						break;
					}
				}
			}
			$next=$changes!==[] ? (int)($changes[array_key_last($changes)]['cursor'] ?? $cursor) : $current['sequence'];
			return [
				'cursor'=>$next,
				'oldest_cursor'=>$oldest,
				'reset_required'=>$reset,
				'reset_reason'=>$future?'future_cursor':($stale?'retention_window':null),
				'changes'=>$changes,
				'snapshot'=>$reset ? $current : null,
			];
		});
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$snapshot=$this->snapshot();
		return [
			'type'=>'panel_atomic_snapshot_store',
			'schema'=>$this->schema,
			'cursor'=>$snapshot['sequence'],
			'committed_at'=>$snapshot['committed_at'],
			'retention'=>$this->retain,
			'durable'=>true,
			'distributed'=>false,
			'directory_serialized'=>false,
			'capabilities'=>[
				'atomic_commits'=>true,
				'cross_process_locking'=>true,
				'crash_recovery'=>true,
				'cursor_feed'=>true,
				'stale_cursor_reset'=>true,
				'future_cursor_reset'=>true,
				'callback_replay'=>false,
				'secret_safe_manifest'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function ensureDirectory(): void {
		if(is_link($this->directory)){
			throw new \RuntimeException('Snapshot directory cannot be a symbolic link.');
		}
		if(!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)){
			throw new \RuntimeException('Unable to create snapshot directory: '.$this->directory);
		}
		if(!is_writable($this->directory)){
			throw new \RuntimeException('Snapshot directory is not writable: '.$this->directory);
		}
	}

	/** @template T @param callable():T $callback @return T */
	private function withLock(int $mode, callable $callback): mixed {
		$this->ensureDirectory();
		$handle=@fopen($this->directory.DIRECTORY_SEPARATOR.'.lock', 'c+b');
		if(!is_resource($handle)){
			throw new \RuntimeException('Unable to open snapshot lock file.');
		}
		try {
			if(!flock($handle, $mode)){
				throw new \RuntimeException('Unable to acquire snapshot lock.');
			}
			return $callback(); } finally {
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}
	}

	/**
	 * @param array<int,string>|null $files
	 * @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>}
	 */
	private function readLatestUnlocked(?array $files=null): array {
		$files=$files ?? $this->snapshotFilesUnlocked();
		for($index=count($files)-1; $index>=0; $index--){
			$snapshot=$this->decodeSnapshotFile($files[$index]);
			if($snapshot!==null){
				return $snapshot;
			}
		}
		return [
			'schema'=>$this->schema,
			'sequence'=>0,
			'committed_at'=>null,
			'payload'=>$this->initialPayload,
			'event'=>null,
		];
	}

	/** @return array<int,string> */
	private function snapshotFilesUnlocked(): array {
		$files=glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
		$files=array_values(array_filter($files, fn(string $file): bool => preg_match('/^[0-9]{20}\.json$/', basename($file))===1));
		sort($files, SORT_STRING);
		return $files;
	}

	/** @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>}|null */
	private function decodeSnapshotFile(string $file): ?array {
		try {
			$raw=@file_get_contents($file);
			if(!is_string($raw) || $raw===''){
				return null;
			}
			$data=json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
			if(!is_array($data) || ($data['schema'] ?? null)!==$this->schema || !is_array($data['payload'] ?? null)){
				return null;
			}
			$sequence=(int)($data['sequence'] ?? -1);
			if($sequence<1 || $sequence!==$this->sequenceFromFilename($file)){
				return null;
			}
			return [
				'schema'=>$this->schema,
				'sequence'=>$sequence,
				'committed_at'=>isset($data['committed_at']) ? (string)$data['committed_at'] : null,
				'payload'=>$data['payload'],
				'event'=>is_array($data['event'] ?? null) ? $data['event'] : null,
			];
		}
		catch(\Throwable){
			return null;
		}
	}

	/** @param array<string,mixed> $snapshot */
	private function writeSnapshotUnlocked(array $snapshot): void {
		$sequence=(int)$snapshot['sequence'];
		$final=$this->directory.DIRECTORY_SEPARATOR.sprintf('%020d.json', $sequence);
		if(file_exists($final)){
			throw new \RuntimeException('Snapshot sequence already exists: '.$sequence);
		}
		$json=json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$temp=$this->directory.DIRECTORY_SEPARATOR.'.'.sprintf('%020d', $sequence).'.'.bin2hex(random_bytes(8)).'.tmp';
		$handle=@fopen($temp, 'xb');
		if(!is_resource($handle)){
			throw new \RuntimeException('Unable to create temporary snapshot file.');
		}
		try {
			$offset=0;
			$length=strlen($json);
			while($offset<$length){
				$written=fwrite($handle, substr($json, $offset));
				if($written===false || $written===0){
					throw new \RuntimeException('Unable to write complete snapshot.');
				}
				$offset+=$written;
			}
			if(!fflush($handle)){
				throw new \RuntimeException('Unable to flush snapshot.');
			}
			if(function_exists('fsync')){
				@fsync($handle);
			}
		}
		finally {
			@fclose($handle);
		}
		if(!@rename($temp, $final)){
			@unlink($temp);
			throw new \RuntimeException('Unable to atomically commit snapshot.');
		}
	}

	private function pruneUnlocked(): void {
		$files=$this->snapshotFilesUnlocked();
		foreach($files as $index=>$file){
			if($this->decodeSnapshotFile($file)===null){
				@unlink($file);
				unset($files[$index]);
			}
		}
		$files=array_values($files);
		$remove=max(0, count($files)-$this->retain);
		for($index=0; $index<$remove; $index++){
			@unlink($files[$index]);
		}
	}

	private function sequenceFromFilename(string $file): int {
		return (int)pathinfo($file, PATHINFO_FILENAME);
	}
}
