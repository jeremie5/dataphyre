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
 * Lock-coordinated authentication store whose entire multi-record transaction
 * is published as one checksummed, atomic snapshot.
 */
final class PanelFilesystemAuthenticationStore implements PanelAuthenticationStore {
	private string $directory;
	private string $lockPath;
	private string $dataPath;
	private bool $inTransaction=false;
	private bool $changed=false;
	/** @var array<string,array<string,PanelAuthenticationRecord>> */ private array $records=[];

	public function __construct(string $directory, private readonly int $permissions=0770) {
		$directory=rtrim($directory, '/\\');
		if($directory==='' || (!is_dir($directory) && !@mkdir($directory, $permissions, true) && !is_dir($directory))){ throw new \RuntimeException('Unable to create Panel authentication store directory.'); }
		$resolved=realpath($directory);
		if($resolved===false || !is_writable($resolved)){ throw new \RuntimeException('Panel authentication store directory is not writable.'); }
		$this->directory=rtrim($resolved, '/\\');
		$this->lockPath=$this->directory.DIRECTORY_SEPARATOR.'.authentication.lock';
		$this->dataPath=$this->directory.DIRECTORY_SEPARATOR.'authentication.store.json';
	}

	public function directory(): string { return $this->directory; }

	public function transaction(callable $callback): mixed {
		if($this->inTransaction){ return $callback($this); }
		$handle=@fopen($this->lockPath, 'c+b');
		if($handle===false || !flock($handle, LOCK_EX)){ if(is_resource($handle)){ fclose($handle); } throw new \RuntimeException('Unable to lock Panel authentication store.'); }
		$this->inTransaction=true; $this->changed=false; $this->records=[];
		try{
			$this->loadSnapshot();
			$result=$callback($this);
			if($this->changed){ $this->writeSnapshot(); }
			return $result; }finally{
			$this->records=[]; $this->changed=false; $this->inTransaction=false;
			flock($handle, LOCK_UN); fclose($handle);
		}
	}

	public function get(string $collection, string $id): ?PanelAuthenticationRecord {
		if(!$this->inTransaction){ return $this->transaction(fn(PanelAuthenticationTransaction $tx): ?PanelAuthenticationRecord=>$tx->get($collection, $id)); }
		return $this->records[$collection][$id] ?? null;
	}

	public function create(PanelAuthenticationRecord $record): PanelAuthenticationRecord {
		if(!$this->inTransaction){ return $this->transaction(fn(PanelAuthenticationTransaction $tx): PanelAuthenticationRecord=>$tx->create($record)); }
		if(isset($this->records[$record->collection()][$record->id()])){ throw new PanelAuthenticationConflict('Authentication record already exists.'); }
		$stored=$record->withRevision(1); $this->records[$stored->collection()][$stored->id()]=$stored; $this->changed=true; return $stored;
	}

	public function save(PanelAuthenticationRecord $record, ?int $expectedRevision=null): PanelAuthenticationRecord {
		if(!$this->inTransaction){ return $this->transaction(fn(PanelAuthenticationTransaction $tx): PanelAuthenticationRecord=>$tx->save($record, $expectedRevision)); }
		$current=$this->get($record->collection(), $record->id()) ?? throw new \OutOfBoundsException('Authentication record does not exist.');
		$expected=$expectedRevision ?? $record->revision();
		if($current->revision()!==$expected){ throw new PanelAuthenticationConflict('Authentication record revision conflict.'); }
		$stored=$record->withRevision($current->revision()+1); $this->records[$stored->collection()][$stored->id()]=$stored; $this->changed=true; return $stored;
	}

	public function all(string $collection, array $criteria=[]): array {
		if(!$this->inTransaction){ return $this->transaction(fn(PanelAuthenticationTransaction $tx): array=>$tx->all($collection, $criteria)); }
		$rows=array_values($this->records[$collection] ?? []);
		$rows=array_values(array_filter($rows, static function(PanelAuthenticationRecord $record)use($criteria): bool {
			foreach($criteria as $key=>$expected){ $actual=$key==='id' ? $record->id() : $record->value((string)$key); if(is_array($expected) ? !in_array($actual, $expected, true) : $actual!==$expected){ return false; } } return true;
		}));
		usort($rows, static fn(PanelAuthenticationRecord $a, PanelAuthenticationRecord $b): int=>[$a->createdAt(),$a->id()]<=>[$b->createdAt(),$b->id()]); return $rows;
	}

	public function delete(string $collection, string $id): bool {
		if(!$this->inTransaction){ return $this->transaction(fn(PanelAuthenticationTransaction $tx): bool=>$tx->delete($collection, $id)); }
		if(!isset($this->records[$collection][$id])){ return false; }
		unset($this->records[$collection][$id]); $this->changed=true; return true;
	}

	/** @return array{records:int,bytes:int,directory:string} */
	public function diagnostics(): array {
		$count=$this->transaction(fn(PanelAuthenticationTransaction $tx): int=>array_sum(array_map(static fn(array $records): int=>count($records), $this->records)));
		return ['records'=>$count, 'bytes'=>is_file($this->dataPath) ? (int)(filesize($this->dataPath) ?: 0) : 0, 'directory'=>$this->directory];
	}

	private function loadSnapshot(): void {
		if(!is_file($this->dataPath)){ return; }
		$contents=@file_get_contents($this->dataPath); if($contents===false){ throw new \RuntimeException('Unable to read Panel authentication snapshot.'); }
		try{ $envelope=json_decode($contents, true, 256, JSON_THROW_ON_ERROR); }
		catch(\JsonException $error){ throw new \UnexpectedValueException('Corrupt Panel authentication JSON.', 0, $error); }
		if(!is_array($envelope) || ($envelope['version'] ?? null)!==1 || !is_array($envelope['records'] ?? null) || !is_string($envelope['checksum'] ?? null)){ throw new \UnexpectedValueException('Invalid Panel authentication snapshot envelope.'); }
		$canonical=$this->canonical($envelope['records']); if(!hash_equals($envelope['checksum'], hash('sha256', $canonical))){ throw new \UnexpectedValueException('Panel authentication snapshot checksum mismatch.'); }
		foreach($envelope['records'] as $payload){
			if(!is_array($payload)){ throw new \UnexpectedValueException('Invalid Panel authentication snapshot record.'); }
			$record=PanelAuthenticationRecord::restore($payload); $this->records[$record->collection()][$record->id()]=$record;
		}
	}

	private function writeSnapshot(): void {
		$payload=[];
		ksort($this->records, SORT_STRING);
		foreach($this->records as $records){ ksort($records, SORT_STRING); foreach($records as $record){ $payload[]=$record->storagePayload(); } }
		$envelope=['version'=>1, 'checksum'=>hash('sha256', $this->canonical($payload)), 'records'=>$payload];
		$json=json_encode($envelope, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
		$temp=@tempnam($this->directory, '.auth-'); if($temp===false){ throw new \RuntimeException('Unable to allocate Panel authentication temporary file.'); }
		$backup=$this->dataPath.'.backup-'.bin2hex(random_bytes(4));
		try{
			$handle=@fopen($temp,'wb'); if($handle===false){ throw new \RuntimeException('Unable to write Panel authentication temporary file.'); }
			try{ $remaining=$json; while($remaining!==''){ $written=fwrite($handle,$remaining); if($written===false || $written===0){ throw new \RuntimeException('Unable to write complete Panel authentication snapshot.'); } $remaining=substr($remaining,$written); } if(!fflush($handle)){ throw new \RuntimeException('Unable to flush Panel authentication snapshot.'); } if(function_exists('fsync')){ @fsync($handle); } } finally { fclose($handle); }
			@chmod($temp,$this->permissions & 0666);
			if(is_file($this->dataPath) && !@rename($this->dataPath,$backup)){ throw new \RuntimeException('Unable to stage Panel authentication snapshot.'); }
			if(!@rename($temp,$this->dataPath)){ if(is_file($backup)){ @rename($backup,$this->dataPath); } throw new \RuntimeException('Unable to publish Panel authentication snapshot.'); }
			if(is_file($backup)){ @unlink($backup); }
		}finally{ if(is_file($temp)){ @unlink($temp); } if(is_file($backup) && !is_file($this->dataPath)){ @rename($backup,$this->dataPath); } }
	}

	/** @param array<mixed> $data */
	private function canonical(array $data): string {
		$sort=function(mixed $value)use(&$sort): mixed { if(!is_array($value)){ return $value; } if(!array_is_list($value)){ ksort($value,SORT_STRING); } foreach($value as $key=>$child){ $value[$key]=$sort($child); } return $value; };
		return json_encode($sort($data), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}
}
