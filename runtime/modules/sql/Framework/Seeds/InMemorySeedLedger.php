<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

use RuntimeException;
use Throwable;

/** Deterministic in-memory ledger for tests, previews, and embedded tooling. */
final class InMemorySeedLedger implements SeedLedger {
	/** @var array<string,array<string,mixed>> */
	private array $records=[];

	/** @param array<int|string,array<string,mixed>> $records */
	public function __construct(array $records=[]) {
		foreach($records as $record){
			$this->recordApplied($record);
		}
	}

	public function ensureSchema(): void {}

	public function all(): array {
		$records=$this->records;
		ksort($records, SORT_NATURAL);
		return $records;
	}

	public function nextBatch(): int {
		$batch=0;
		foreach($this->records as $record){
			$batch=max($batch, (int)($record['batch'] ?? 0));
		}
		return $batch+1;
	}

	public function recordApplied(array $record): void {
		$id=strtolower(trim((string)($record['id'] ?? $record['seed_id'] ?? '')));
		$version=(int)($record['version'] ?? $record['seed_version'] ?? 0);
		$checksum=strtolower(trim((string)($record['checksum'] ?? '')));
		if($id==='' || $version<1 || preg_match('/^[a-f0-9]{64}$/', $checksum)!==1){
			throw new RuntimeException('Seed ledger records require id, positive version, and SHA-256 checksum.');
		}
		$key=$id.'@'.$version;
		if(isset($this->records[$key])){
			throw new RuntimeException('Seed ledger already contains '.$key.'.');
		}
		$this->records[$key]=[
			'id'=>$id,
			'version'=>$version,
			'checksum'=>$checksum,
			'batch'=>(int)($record['batch'] ?? 0),
			'applied_at'=>(string)($record['applied_at'] ?? gmdate('c')),
		];
	}

	public function remove(string $id, int $version): void {
		unset($this->records[strtolower(trim($id)).'@'.$version]);
	}

	public function transaction(callable $callback): mixed {
		$snapshot=$this->records;
		try{
			return $callback();
		}catch(Throwable $throwable){
			$this->records=$snapshot;
			throw $throwable;
		}
	}
}
