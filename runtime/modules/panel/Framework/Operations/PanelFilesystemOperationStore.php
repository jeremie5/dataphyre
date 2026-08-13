<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Lock-coordinated JSON operation store with checksummed envelopes and atomic replacement.
 *
 * Every mutation is serialized through a store lock. Readers participate in the same
 * lock protocol, which also makes the Windows backup-and-swap fallback atomic from the
 * adapter's point of view.
 */
final class PanelFilesystemOperationStore implements PanelOperationStore {

	private string $directory;
	private string $lockPath;

	public function __construct(string $directory, private readonly int $permissions=0770) {
		$directory=rtrim($directory, '/\\');
		if($directory===''){ throw new \InvalidArgumentException('Panel operation store directory cannot be blank.'); }
		if(!is_dir($directory) && !@mkdir($directory, $permissions, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create Panel operation store '{$directory}'.");
		}
		$resolved=realpath($directory);
		if($resolved===false || !is_writable($resolved)){
			throw new \RuntimeException("Panel operation store '{$directory}' is not writable.");
		}
		$this->directory=rtrim($resolved, '/\\');
		$this->lockPath=$this->directory.DIRECTORY_SEPARATOR.'.operations.lock';
	}

	public function directory(): string { return $this->directory; }

	public function create(PanelOperationRecord $record): PanelOperationRecord {
		return $this->locked(LOCK_EX, function()use($record): PanelOperationRecord {
			if($record->idempotencyKey()!==null){
				$existing=$this->findIdempotentUnlocked($record->idempotencyKey());
				if($existing!==null){ return $existing; }
			}
			$path=$this->path($record->id());
			if(is_file($path)){ throw new PanelOperationConflict("Panel operation '{$record->id()}' already exists."); }
			$stored=$record->withRevision(1);
			$this->writeEnvelope($path, $stored);
			return $stored;
		});
	}

	public function get(string $id): ?PanelOperationRecord {
		return $this->locked(LOCK_SH, fn(): ?PanelOperationRecord=>$this->readPath($this->path($id)));
	}

	public function save(PanelOperationRecord $record, ?int $expectedRevision=null): PanelOperationRecord {
		return $this->locked(LOCK_EX, function()use($record, $expectedRevision): PanelOperationRecord {
			return $this->saveUnlocked($record, $expectedRevision);
		});
	}

	public function update(string $id, callable $mutator, ?int $expectedRevision=null): PanelOperationRecord {
		return $this->locked(LOCK_EX, function()use($id, $mutator, $expectedRevision): PanelOperationRecord {
			$current=$this->readPath($this->path($id));
			if($current===null){ throw new \OutOfBoundsException("Panel operation '{$id}' does not exist."); }
			if($expectedRevision!==null && $current->revision()!==$expectedRevision){
				throw new PanelOperationConflict("Panel operation '{$id}' revision conflict: expected {$expectedRevision}, found {$current->revision()}.");
			}
			$next=$mutator($current);
			if(!$next instanceof PanelOperationRecord){
				throw new \UnexpectedValueException('Panel operation mutator must return PanelOperationRecord.');
			}
			if($next->id()!==$current->id()){
				throw new \LogicException('Panel operation mutator cannot change the record id.');
			}
			return $this->saveUnlocked($next, $current->revision());
		});
	}

	public function findByIdempotencyKey(string $key): ?PanelOperationRecord {
		$key=trim($key);
		if($key===''){ return null; }
		return $this->locked(LOCK_SH, fn(): ?PanelOperationRecord=>$this->findIdempotentUnlocked($key));
	}

	public function all(array $criteria=[], int $limit=100, int $offset=0): array {
		$limit=max(1, min(10000, $limit));
		$offset=max(0, $offset);
		return $this->locked(LOCK_SH, function()use($criteria, $limit, $offset): array {
			$records=[];
			foreach($this->recordPaths() as $path){
				$record=$this->readPath($path);
				if($record!==null && $this->matches($record, $criteria)){ $records[]=$record; }
			}
			usort($records, static fn(PanelOperationRecord $left, PanelOperationRecord $right): int=>
				[$left->createdAt(), $left->id()] <=> [$right->createdAt(), $right->id()]
			);
			return array_slice($records, $offset, $limit);
		});
	}

	public function delete(string $id): bool {
		return $this->locked(LOCK_EX, function()use($id): bool {
			$path=$this->path($id);
			if(!is_file($path)){ return false; }
			if(!@unlink($path)){ throw new \RuntimeException("Unable to delete Panel operation '{$id}'."); }
			return true;
		});
	}

	/** @return array{records:int, bytes:int, directory:string} */
	public function diagnostics(): array {
		return $this->locked(LOCK_SH, function(): array {
			$paths=$this->recordPaths();
			$bytes=0;
			foreach($paths as $path){ $bytes+=(int)(filesize($path) ?: 0); }
			return ['records'=>count($paths), 'bytes'=>$bytes, 'directory'=>$this->directory];
		});
	}

	private function saveUnlocked(PanelOperationRecord $record, ?int $expectedRevision): PanelOperationRecord {
		$path=$this->path($record->id());
		$current=$this->readPath($path);
		if($current===null){ throw new \OutOfBoundsException("Panel operation '{$record->id()}' does not exist."); }
		$expected=$expectedRevision ?? $record->revision();
		if($current->revision()!==$expected){
			throw new PanelOperationConflict("Panel operation '{$record->id()}' revision conflict: expected {$expected}, found {$current->revision()}.");
		}
		if($record->idempotencyKey()!==null){
			$duplicate=$this->findIdempotentUnlocked($record->idempotencyKey(), $record->id());
			if($duplicate!==null){ throw new PanelOperationConflict('Panel operation idempotency key already belongs to '.$duplicate->id().'.'); }
		}
		$stored=$record->withRevision($current->revision()+1);
		$this->writeEnvelope($path, $stored);
		return $stored;
	}

	private function findIdempotentUnlocked(string $key, ?string $exceptId=null): ?PanelOperationRecord {
		foreach($this->recordPaths() as $path){
			$record=$this->readPath($path);
			if($record!==null && $record->id()!==$exceptId && hash_equals((string)$record->idempotencyKey(), $key)){ return $record; }
		}
		return null;
	}

	/** @param array<string, mixed> $criteria */
	private function matches(PanelOperationRecord $record, array $criteria): bool {
		foreach($criteria as $key=>$expected){
			if(!in_array($key, ['id', 'type', 'queue', 'status', 'idempotency_key', 'worker'], true)){
				throw new \InvalidArgumentException("Unsupported Panel operation store criterion '{$key}'.");
			}
			$actual=match($key){
				'id'=>$record->id(), 'type'=>$record->type(), 'queue'=>$record->queue(), 'status'=>$record->status(),
				'idempotency_key'=>$record->idempotencyKey(), 'worker'=>$record->worker(),
			};
			if(is_array($expected)){
				if(!in_array($actual, $expected, true)){ return false; }
			}elseif($actual!==$expected){ return false; }
		}
		return true;
	}

	private function path(string $id): string {
		if($id==='' || strlen($id)>190 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $id)!==1){
			throw new \InvalidArgumentException('Unsafe Panel operation id.');
		}
		return $this->directory.DIRECTORY_SEPARATOR.rawurlencode($id).'.json';
	}

	/** @return list<string> */
	private function recordPaths(): array {
		$paths=glob($this->directory.DIRECTORY_SEPARATOR.'*.json');
		if($paths===false){ throw new \RuntimeException('Unable to enumerate Panel operation store.'); }
		sort($paths, SORT_STRING);
		return array_values($paths);
	}

	private function readPath(string $path): ?PanelOperationRecord {
		if(!is_file($path)){ return null; }
		$contents=@file_get_contents($path);
		if($contents===false){ throw new \RuntimeException("Unable to read Panel operation record '{$path}'."); }
		try{ $envelope=json_decode($contents, true, 128, JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \UnexpectedValueException("Corrupt Panel operation JSON '{$path}'.", 0, $error); }
		if(!is_array($envelope) || ($envelope['version'] ?? null)!==1 || !is_array($envelope['record'] ?? null) || !is_string($envelope['checksum'] ?? null)){
			throw new \UnexpectedValueException("Invalid Panel operation envelope '{$path}'.");
		}
		$canonical=$this->canonicalJson($envelope['record']);
		if(!hash_equals($envelope['checksum'], hash('sha256', $canonical))){
			throw new \UnexpectedValueException("Panel operation checksum mismatch '{$path}'.");
		}
		return PanelOperationRecord::fromArray($envelope['record']);
	}

	private function writeEnvelope(string $path, PanelOperationRecord $record): void {
		$data=$record->jsonSerialize();
		$envelope=['version'=>1, 'checksum'=>hash('sha256', $this->canonicalJson($data)), 'record'=>$data];
		try{ $json=json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \UnexpectedValueException('Unable to encode Panel operation record.', 0, $error); }
		$temp=@tempnam($this->directory, '.operation-');
		if($temp===false){ throw new \RuntimeException('Unable to allocate Panel operation temporary file.'); }
		$backup=$path.'.backup-'.bin2hex(random_bytes(4));
		try{
			$handle=@fopen($temp, 'wb');
			if($handle===false){ throw new \RuntimeException('Unable to open Panel operation temporary file.'); }
			try{
				$remaining=$json."\n";
				while($remaining!==''){
					$written=fwrite($handle, $remaining);
					if($written===false || $written===0){ throw new \RuntimeException('Unable to write complete Panel operation record.'); }
					$remaining=substr($remaining, $written);
				}
				if(!fflush($handle)){ throw new \RuntimeException('Unable to flush Panel operation record.'); }
				if(function_exists('fsync')){ @fsync($handle); }
			}finally{ fclose($handle); }
			@chmod($temp, $this->permissions & 0666);
			if(is_file($path) && !@rename($path, $backup)){
				throw new \RuntimeException('Unable to stage existing Panel operation record for replacement.');
			}
			if(!@rename($temp, $path)){
				if(is_file($backup)){ @rename($backup, $path); }
				throw new \RuntimeException('Unable to atomically publish Panel operation record.');
			}
			if(is_file($backup)){ @unlink($backup); }
		}finally{
			if(is_file($temp)){ @unlink($temp); }
			if(is_file($backup) && !is_file($path)){ @rename($backup, $path); }
		}
	}

	/** @param array<string, mixed> $value */
	private function canonicalJson(array $value): string {
		$sort=function(mixed $item)use(&$sort): mixed {
			if(!is_array($item)){ return $item; }
			if(!array_is_list($item)){ ksort($item, SORT_STRING); }
			foreach($item as $key=>$child){ $item[$key]=$sort($child); }
			return $item;
		};
		return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private function locked(int $mode, callable $callback): mixed {
		$handle=@fopen($this->lockPath, 'c+b');
		if($handle===false){ throw new \RuntimeException('Unable to open Panel operation store lock.'); }
		try{
			if(!flock($handle, $mode)){ throw new \RuntimeException('Unable to acquire Panel operation store lock.'); }
			try{ return $callback(); }
			finally{ flock($handle, LOCK_UN); }
		}finally{ fclose($handle); }
	}
}
