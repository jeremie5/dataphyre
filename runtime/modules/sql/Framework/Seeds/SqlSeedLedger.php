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

/** Dataphyre-SQL-backed seed ledger with portable MySQL/PostgreSQL/SQLite DDL. */
final class SqlSeedLedger implements SeedLedger {
	/** @var callable(string|array<string,string>,?array<int,mixed>,bool):mixed|null */
	private $query_executor;
	/** @var callable(callable):mixed|null */
	private $transaction_executor;
	private string $lock_table;
	private ?\Dataphyre\Database\ConnectionContext $connection=null;
	private int $mutation_depth=0;

	/**
	 * @param callable(string|array<string,string>,?array<int,mixed>,bool):mixed|null $query_executor
	 * @param callable(callable):mixed|null $transaction_executor
	 */
	public function __construct(
		private string $table='dataphyre_seed_ledger',
		private ?string $cluster=null,
		?callable $query_executor=null,
		?callable $transaction_executor=null,
		private ?string $dbms=null,
	) {
		$this->table=trim($table);
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $this->table)!==1){
			throw new RuntimeException('Seed ledger table must be a portable unqualified SQL identifier.');
		}
		$this->cluster=$cluster!==null && trim($cluster)!=='' ? trim($cluster) : null;
		$this->dbms=$dbms!==null && trim($dbms)!=='' ? strtolower(trim($dbms)) : null;
		$this->query_executor=$query_executor;
		$this->transaction_executor=$transaction_executor;
		$this->lock_table=$this->lockTableName($this->table);
	}

	public function ensureSchema(): void {
		$table=$this->table;
		$this->query([
			'mysql'=>"CREATE TABLE IF NOT EXISTS `$table` (seed_id VARCHAR(191) NOT NULL, seed_version BIGINT NOT NULL, checksum CHAR(64) NOT NULL, batch BIGINT NOT NULL, applied_at VARCHAR(40) NOT NULL, PRIMARY KEY (seed_id, seed_version))",
			'postgresql'=>"CREATE TABLE IF NOT EXISTS \"$table\" (seed_id VARCHAR(191) NOT NULL, seed_version BIGINT NOT NULL, checksum CHAR(64) NOT NULL, batch BIGINT NOT NULL, applied_at VARCHAR(40) NOT NULL, PRIMARY KEY (seed_id, seed_version))",
			'sqlite'=>"CREATE TABLE IF NOT EXISTS \"$table\" (seed_id TEXT NOT NULL, seed_version INTEGER NOT NULL, checksum TEXT NOT NULL, batch INTEGER NOT NULL, applied_at TEXT NOT NULL, PRIMARY KEY (seed_id, seed_version))",
		]);
		$lock_table=$this->lock_table;
		$this->query([
			'mysql'=>"CREATE TABLE IF NOT EXISTS `$lock_table` (lock_id SMALLINT NOT NULL PRIMARY KEY, revision BIGINT NOT NULL)",
			'postgresql'=>"CREATE TABLE IF NOT EXISTS \"$lock_table\" (lock_id SMALLINT NOT NULL PRIMARY KEY, revision BIGINT NOT NULL)",
			'sqlite'=>"CREATE TABLE IF NOT EXISTS \"$lock_table\" (lock_id INTEGER NOT NULL PRIMARY KEY, revision INTEGER NOT NULL)",
		]);
		$this->query([
			'mysql'=>"INSERT IGNORE INTO `$lock_table` (lock_id, revision) VALUES (1, 0)",
			'postgresql'=>"INSERT INTO \"$lock_table\" (lock_id, revision) VALUES (1, 0) ON CONFLICT (lock_id) DO NOTHING",
			'sqlite'=>"INSERT OR IGNORE INTO \"$lock_table\" (lock_id, revision) VALUES (1, 0)",
		]);
	}

	public function all(): array {
		$table=$this->table;
		$rows=$this->query([
			'mysql'=>"SELECT seed_id, seed_version, checksum, batch, applied_at FROM `$table` ORDER BY seed_id, seed_version",
			'postgresql'=>"SELECT seed_id, seed_version, checksum, batch, applied_at FROM \"$table\" ORDER BY seed_id, seed_version",
			'sqlite'=>"SELECT seed_id, seed_version, checksum, batch, applied_at FROM \"$table\" ORDER BY seed_id, seed_version",
		], null, true);
		if($rows===null){
			return [];
		}
		if(!is_array($rows)){
			throw new RuntimeException('Seed ledger query returned an invalid row collection.');
		}
		if(isset($rows['seed_id'])){
			$rows=[$rows];
		}
		$records=[];
		foreach($rows as $row){
			if(!is_array($row)){
				throw new RuntimeException('Seed ledger query returned an invalid row.');
			}
			$id=strtolower(trim((string)($row['seed_id'] ?? '')));
			$version=(int)($row['seed_version'] ?? 0);
			if($id==='' || $version<1){
				throw new RuntimeException('Seed ledger contains an invalid identity.');
			}
			$records[$id.'@'.$version]=[
				'id'=>$id,
				'version'=>$version,
				'checksum'=>strtolower((string)($row['checksum'] ?? '')),
				'batch'=>(int)($row['batch'] ?? 0),
				'applied_at'=>(string)($row['applied_at'] ?? ''),
			];
		}
		return $records;
	}

	public function nextBatch(): int {
		$batch=0;
		foreach($this->all() as $record){
			$batch=max($batch, (int)($record['batch'] ?? 0));
		}
		return $batch+1;
	}

	public function recordApplied(array $record): void {
		$this->assertMutationLock();
		$id=strtolower(trim((string)($record['id'] ?? '')));
		$version=(int)($record['version'] ?? 0);
		$checksum=strtolower(trim((string)($record['checksum'] ?? '')));
		$batch=(int)($record['batch'] ?? 0);
		$applied_at=(string)($record['applied_at'] ?? gmdate('c'));
		if($id==='' || $version<1 || preg_match('/^[a-f0-9]{64}$/', $checksum)!==1 || $batch<1){
			throw new RuntimeException('Cannot persist an incomplete seed ledger record.');
		}
		if(isset($this->all()[$id.'@'.$version])){
			throw new RuntimeException('Seed ledger already contains '.$id.'@'.$version.'.');
		}
		$table=$this->table;
		$this->query([
			'mysql'=>"INSERT INTO `$table` (seed_id, seed_version, checksum, batch, applied_at) VALUES (?, ?, ?, ?, ?)",
			'postgresql'=>"INSERT INTO \"$table\" (seed_id, seed_version, checksum, batch, applied_at) VALUES ($1, $2, $3, $4, $5)",
			'sqlite'=>"INSERT INTO \"$table\" (seed_id, seed_version, checksum, batch, applied_at) VALUES (?, ?, ?, ?, ?)",
		], [$id, $version, $checksum, $batch, $applied_at]);
	}

	public function remove(string $id, int $version): void {
		$this->assertMutationLock();
		$id=strtolower(trim($id));
		if(!isset($this->all()[$id.'@'.$version])){
			throw new RuntimeException('Seed ledger cannot remove a missing record: '.$id.'@'.$version);
		}
		$table=$this->table;
		$this->query([
			'mysql'=>"DELETE FROM `$table` WHERE seed_id=? AND seed_version=?",
			'postgresql'=>"DELETE FROM \"$table\" WHERE seed_id=$1 AND seed_version=$2",
			'sqlite'=>"DELETE FROM \"$table\" WHERE seed_id=? AND seed_version=?",
		], [$id, $version]);
	}

	public function transaction(callable $callback): mixed {
		$guarded=function() use ($callback): mixed {
			$this->acquireMutationLock();
			$this->mutation_depth++;
			try{
				return $callback();
			}finally{
				$this->mutation_depth--;
			}
		};
		if($this->transaction_executor!==null){
			return ($this->transaction_executor)($guarded);
		}
		if($this->dbms()==='sqlite'){
			throw new RuntimeException('Atomic native seed apply/rollback is unavailable on SQLite until the Dataphyre SQLite kernel provides one persistent transaction connection. Supply a verified transaction executor or use MySQL/PostgreSQL.');
		}
		return $this->connection()->transaction($guarded);
	}

	private function query(string|array $query, ?array $vars=null, bool $associative=false): mixed {
		$query=$this->clusterAwareQuery($query);
		if($this->query_executor!==null){
			$result=($this->query_executor)($query, $vars, $associative);
		}else{
			$result=$this->connection()->query($query, $vars, $associative, false, false, false);
		}
		if($result===false){
			throw new RuntimeException('Dataphyre SQL rejected a seed-ledger query.');
		}
		return $result;
	}

	private function acquireMutationLock(): void {
		$table=$this->lock_table;
		$lock=$this->query([
			'mysql'=>"SELECT lock_id FROM `$table` WHERE lock_id=1 FOR UPDATE",
			'postgresql'=>"SELECT lock_id FROM \"$table\" WHERE lock_id=1 FOR UPDATE",
			'sqlite'=>"SELECT lock_id FROM \"$table\" WHERE lock_id=1",
		], null, true);
		if(!is_array($lock) || $lock===[]){
			throw new RuntimeException('Seed ledger mutation lock row is missing.');
		}
	}

	private function assertMutationLock(): void {
		if($this->mutation_depth<1){
			throw new RuntimeException('Seed ledger records may only be mutated inside the serialized ledger transaction.');
		}
	}

	private function dbms(): string {
		if($this->dbms!==null){
			return $this->dbms;
		}
		$dbms=$this->connection()->dbms();
		return is_string($dbms) && trim($dbms)!=='' ? strtolower(trim($dbms)) : 'unknown';
	}

	private function connection(): \Dataphyre\Database\ConnectionContext {
		if($this->connection!==null){
			return $this->connection;
		}
		$this->assertSqlRuntime();
		if(!class_exists('\Dataphyre\Database\ConnectionContext')){
			throw new RuntimeException('Dataphyre SQL Framework ConnectionContext is required for seed transactions.');
		}
		return $this->connection=new \Dataphyre\Database\ConnectionContext($this->cluster);
	}

	private function lockTableName(string $table): string {
		if(strlen($table)<=58){
			return $table.'_lock';
		}
		return substr($table, 0, 49).'_'.substr(hash('sha256', $table), 0, 8).'_lock';
	}

	private function clusterAwareQuery(string|array $query): string|array {
		if($this->cluster===null){
			return $query;
		}
		if(is_array($query)){
			$query['dbms_cluster_override']=$this->cluster;
			return $query;
		}
		return [
			'mysql'=>$query,
			'postgresql'=>$query,
			'sqlite'=>$query,
			'dbms_cluster_override'=>$this->cluster,
		];
	}

	private function assertSqlRuntime(): void {
		if(!class_exists('\dataphyre\sql') || !method_exists('\dataphyre\sql', 'query')){
			throw new RuntimeException('Dataphyre SQL must be booted before using SqlSeedLedger.');
		}
	}
}
