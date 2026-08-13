<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Explicit-schema shared-SQL collaboration store.
 *
 * Collaboration currently mutates one bounded aggregate state. Writers are
 * therefore serialized through a locked singleton row while an ordered,
 * retained change feed supports distributed readers. Host transactions are
 * preserved through savepoints and are never retried by this adapter.
 */
final class PanelPdoCollaborationStore implements PanelCollaborationStore,\JsonSerializable {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_collaboration';
	private const OPTION_NAMES=[
		'table_prefix',
		'maximum_state_bytes',
		'maximum_change_bytes',
		'change_retention',
		'transaction_retries',
		'retry_delay_microseconds',
	];

	private readonly string $driver;
	private readonly string $prefix;
	private readonly int $maximumStateBytes;
	private readonly int $maximumChangeBytes;
	private readonly int $changeRetention;
	private readonly int $transactionRetries;
	private readonly int $retryDelayMicroseconds;
	private readonly \Closure $clock;
	private bool $manualSqliteWriteTransaction=false;
	private int $savepointSequence=0;

	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(
		private readonly \PDO $pdo,
		array $options=[],
		?callable $clock=null,
	){
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name, self::OPTION_NAMES, true)){
				throw new \InvalidArgumentException('Panel PDO collaboration options contain an unsupported name.');
			}
		}
		try{
			$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));
			$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);
		}catch(\Throwable $error){
			throw new \InvalidArgumentException('Panel PDO collaboration store could not inspect its connection.', 0, $error);
		}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){
			throw new \InvalidArgumentException('Panel PDO collaboration store requires PDO exception mode.');
		}
		$this->driver=self::driverName($driver);
		$this->dialect=self::dialectPlanFor($driver);
		$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->maximumStateBytes=self::option($options, 'maximum_state_bytes', 67108864, 65536, 268435456);
		$this->maximumChangeBytes=self::option($options, 'maximum_change_bytes', 65536, 1024, 1048576);
		$this->changeRetention=self::option($options, 'change_retention', 16384, 8, 1000000);
		$this->transactionRetries=self::option($options, 'transaction_retries', 3, 0, 10);
		$this->retryDelayMicroseconds=self::option($options, 'retry_delay_microseconds', 2000, 0, 100000);
		$this->clock=\Closure::fromCallable($clock??static fn():string=>gmdate('c'));
	}

	public function driver():string{return $this->driver;}

	/** @return list<string> */
	public function schemaStatements():array{return self::schemaStatementsFor($this->driver, $this->prefix);}

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array {
		$driver=self::driverName($driver);
		$prefix=self::prefix($prefix);
		$meta=$prefix.'_meta';
		$state=$prefix.'_state';
		$changes=$prefix.'_changes';
		$initial=self::json(PanelCollaborationStateEngine::initialState());
		$bytes=strlen($initial);
		$digest=hash('sha256', $initial);
		$initial=str_replace("'", "''", $initial);
		$epoch='1970-01-01T00:00:00.000000Z';

		if($driver==='mysql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$state} (singleton TINYINT UNSIGNED NOT NULL, storage_revision BIGINT UNSIGNED NOT NULL, state_json LONGTEXT NOT NULL, state_bytes INT UNSIGNED NOT NULL, state_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, updated_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$state}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_json MEDIUMTEXT NOT NULL, event_bytes INT UNSIGNED NOT NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (change_id)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
				"INSERT IGNORE INTO {$state} (singleton, storage_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}')",
			];
		}
		if($driver==='pgsql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
				"CREATE TABLE IF NOT EXISTS {$state} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), storage_revision BIGINT NOT NULL CHECK (storage_revision >= 0), state_json TEXT NOT NULL, state_bytes INTEGER NOT NULL CHECK (state_bytes > 0), state_digest CHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, event_type VARCHAR(160) NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), occurred_at VARCHAR(64) NOT NULL)",
				"INSERT INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.") ON CONFLICT (singleton) DO NOTHING",
				"INSERT INTO {$state} (singleton, storage_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}') ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return [
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$state} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), storage_revision INTEGER NOT NULL CHECK (storage_revision >= 0), state_json TEXT NOT NULL, state_bytes INTEGER NOT NULL CHECK (state_bytes > 0), state_digest TEXT NOT NULL CHECK (length(state_digest) = 64), updated_at TEXT NOT NULL)",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), occurred_at TEXT NOT NULL)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			"INSERT OR IGNORE INTO {$state} (singleton, storage_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}')",
		];
	}

	/** @return array<string,mixed> */
	public function installSchema():array {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict', 'Panel PDO collaboration schema installation requires transaction ownership.', true);
		}
		try{
			foreach($this->schemaStatements() as $sql){
				if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO schema statement failed.');}
			}
			$this->databaseTransaction(false, function():void {
				$this->assertSchema(false);
				$this->decodeState($this->stateRow(false));
			});
			return [
				'type'=>'panel_pdo_collaboration_schema_installation',
				'version'=>1,
				'driver'=>$this->driver,
				'schema_version'=>self::SCHEMA_VERSION,
				'statements'=>count($this->schemaStatements()),
				'idempotent'=>true,
				'destructive'=>false,
			];
		}catch(PanelCollaborationStorageException $error){
			if($error->errorCode()==='schema_incompatible'){throw $error;}
			throw $this->storage('migration_failed', 'Panel PDO collaboration schema migration failed.', true, $error);
		}catch(\Throwable $error){
			throw $this->storage('migration_failed', 'Panel PDO collaboration schema migration failed.', true, $error);
		}
	}

	/** @return array<string,mixed> */
	public function state():array {
		return $this->databaseTransaction(false, function():array {
			$this->assertSchema(false);
			return $this->decodeState($this->stateRow(false))['state'];
		});
	}

	public function transaction(callable $mutation,string $type,array $event=[]):mixed {
		$type=PanelOperationsGuard::name($type, 'collaboration change type', 160);
		$metadata=PanelCollaborationStateEngine::sanitize($event);
		if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata))){
			throw new \InvalidArgumentException('Panel collaboration change metadata must be an object.');
		}
		return $this->databaseTransaction(true, function() use ($mutation,$type,$metadata):mixed {
			$this->assertSchema(true);
			$decoded=$this->decodeState($this->stateRow(true));
			$before=$decoded['state'];
			$state=$before;
			$result=$mutation($state);
			PanelCollaborationStateEngine::assertReceiptAppendOnly($before, $state);
			$this->assertState($state);
			[$json,$bytes,$digest]=$this->encodeState($state);
			$occurredAt=$this->now();
			$updated=$this->execute(
				"UPDATE {$this->prefix}_state SET storage_revision = :next_revision, state_json = :state_json, state_bytes = :state_bytes, state_digest = :state_digest, updated_at = :updated_at WHERE singleton = 1 AND storage_revision = :expected_revision",
				[
					'next_revision'=>$decoded['storage_revision']+1,
					'state_json'=>$json,
					'state_bytes'=>$bytes,
					'state_digest'=>$digest,
					'updated_at'=>$occurredAt,
					'expected_revision'=>$decoded['storage_revision'],
				],
			);
			if($updated->rowCount()!==1){
				throw $this->storage('write_conflict', 'Panel PDO collaboration state changed concurrently.', true);
			}
			$this->recordChange($type, $metadata, $occurredAt);
			return $result;
		});
	}

	public function cursor():int {
		return $this->databaseTransaction(false, function():int {
			$this->assertSchema(false);
			return $this->currentCursor();
		});
	}

	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0,int $limit=100):array {
		$cursor=max(0, $cursor);
		$limit=max(1, min(1000, $limit));
		return $this->databaseTransaction(false, function() use ($cursor,$limit):array {
			$this->assertSchema(false);
			$bounds=$this->row("SELECT COALESCE(MIN(change_id), 0) AS oldest_cursor, COALESCE(MAX(change_id), 0) AS current_cursor FROM {$this->prefix}_changes");
			if($bounds===null){throw $this->corrupt();}
			$oldest=$this->integer($bounds['oldest_cursor']??null, 0);
			$current=$this->integer($bounds['current_cursor']??null, 0);
			$stale=$cursor>0&&$oldest>0&&$cursor<$oldest-1;
			$future=$cursor>$current;
			$reset=$stale||$future;
			$changes=[];
			if(!$reset){
				foreach($this->rows(
					"SELECT change_id, event_type, event_json, event_bytes, occurred_at FROM {$this->prefix}_changes WHERE change_id > :cursor ORDER BY change_id ASC LIMIT :limit",
					['cursor'=>$cursor, 'limit'=>$limit],
				) as $row){
					$changes[]=$this->hydrateChange($row);
				}
			}
			$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;
			$snapshot=null;
			if($reset){
				$snapshot=PanelCollaborationStateEngine::publicState($this->decodeState($this->stateRow(false))['state']);
			}
			return [
				'cursor'=>$next,
				'oldest_cursor'=>$oldest,
				'reset_required'=>$reset,
				'reset_reason'=>$future?'future_cursor':($stale?'retention_window':null),
				'changes'=>$changes,
				'snapshot'=>$snapshot,
			];
		});
	}

	/** @return array<string,mixed> */
	public function manifest(array $meta=[]):array {
		return [
			'type'=>'panel_collaboration_store',
			'version'=>1,
			'adapter'=>'pdo_shared_sql',
			'driver'=>$this->driver,
			'durable'=>true,
			'distributed'=>true,
			'cross_process'=>true,
			'shared_database'=>true,
			'atomic_state_commits'=>true,
			'state_write_serialization'=>'locked_single_row',
			'bounded_aggregate_state'=>true,
			'maximum_state_bytes'=>$this->maximumStateBytes,
			'maximum_change_bytes'=>$this->maximumChangeBytes,
			'change_retention'=>$this->changeRetention,
			'schema_version'=>self::SCHEMA_VERSION,
			'schema_migration'=>'explicit_idempotent',
			'automatic_schema_mutation'=>false,
			'transaction_retries'=>$this->transactionRetries,
			'host_transactions_preserved'=>true,
			'delivery'=>'at_least_once',
			'exactly_once'=>false,
			'connection_details_serialized'=>false,
			'credentials_serialized'=>false,
			'table_prefix_serialized'=>false,
			'sql_serialized'=>false,
			'live_counts_queried'=>false,
			'capabilities'=>[
				'atomic'=>'shared_database',
				'ordered_cursor'=>true,
				'bounded_change_feed'=>true,
				'stale_cursor_reset'=>true,
				'future_cursor_reset'=>true,
				'state_size_integrity'=>true,
				'state_sha256_integrity'=>true,
				'receipt_chain_integrity'=>true,
				'host_savepoints'=>true,
				'owned_transaction_retries'=>true,
				'secret_safe_manifest'=>true,
			],
			'meta'=>PanelCollaborationStateEngine::sanitize($meta),
		];
	}

	public function jsonSerialize():array{return $this->manifest();}

	/** @return array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	public static function dialectPlanFor(string $driver):array {
		$driver=self::driverName($driver);
		return [
			'write_begin'=>$driver==='sqlite'?'BEGIN IMMEDIATE':null,
			'read_before'=>$driver==='mysql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],
			'read_after'=>$driver==='pgsql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],
			'lock_suffix'=>$driver==='sqlite'?'':' FOR UPDATE',
		];
	}

	private function assertSchema(bool $lock):void {
		try{
			$row=$this->row("SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));
		}catch(\PDOException $error){
			if($this->missingRelation($error)){
				throw $this->storage('schema_required', 'Panel PDO collaboration schema is not installed.');
			}
			throw $error;
		}
		if($row===null){
			throw $this->storage('schema_required', 'Panel PDO collaboration schema is not installed.');
		}
		if($this->integer($row['schema_version']??null, 1)!==self::SCHEMA_VERSION){
			throw $this->storage('schema_incompatible', 'Panel PDO collaboration schema version is incompatible.');
		}
	}

	/** @return array<string,mixed> */
	private function stateRow(bool $lock):array {
		$row=$this->row(
			"SELECT storage_revision, state_json, state_bytes, state_digest, updated_at FROM {$this->prefix}_state WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''),
		);
		if($row===null){throw $this->corrupt();}
		return $row;
	}

	/** @param array<string,mixed> $row @return array{storage_revision:int,state:array<string,mixed>} */
	private function decodeState(array $row):array {
		try{
			foreach(['state_json','state_digest','updated_at'] as $key){
				if(!isset($row[$key])||!is_string($row[$key])){
					throw new \UnexpectedValueException('Stored collaboration state is invalid.');
				}
			}
			$storageRevision=$this->integer($row['storage_revision']??null, 0);
			$bytes=$this->integer($row['state_bytes']??null, 1);
			if(
				$bytes!==strlen($row['state_json'])
				||$bytes>$this->maximumStateBytes
				||preg_match('/^[a-f0-9]{64}$/D', $row['state_digest'])!==1
				||!hash_equals($row['state_digest'], hash('sha256', $row['state_json']))
			){
				throw new \UnexpectedValueException('Stored collaboration state integrity is invalid.');
			}
			PanelOperationsGuard::instant($row['updated_at']);
			$state=json_decode($row['state_json'], true, 128, JSON_THROW_ON_ERROR);
			if(!is_array($state)||array_is_list($state)){
				throw new \UnexpectedValueException('Stored collaboration state shape is invalid.');
			}
			$this->assertState($state);
			return ['storage_revision'=>$storageRevision, 'state'=>$state];
		}catch(PanelCollaborationStorageException $error){
			throw $error;
		}catch(\Throwable $error){
			throw $this->corrupt($error);
		}
	}

	/** @param array<string,mixed> $state @return array{string,int,string} */
	private function encodeState(array $state):array {
		try{$json=self::json($state);}
		catch(\Throwable $error){
			throw $this->storage('state_invalid', 'Collaboration state could not be encoded.', false, $error);
		}
		$bytes=strlen($json);
		if($bytes<1||$bytes>$this->maximumStateBytes){
			throw $this->storage('state_too_large', 'Collaboration state exceeds the configured byte bound.');
		}
		return [$json, $bytes, hash('sha256', $json)];
	}

	/** @param array<string,mixed> $state */
	private function assertState(array $state):void {
		$expected=array_keys(PanelCollaborationStateEngine::initialState());
		$actual=array_keys($state);
		sort($expected, SORT_STRING);
		sort($actual, SORT_STRING);
		if($actual!==$expected){
			throw new \UnexpectedValueException('Collaboration state root shape is invalid.');
		}
		foreach(['threads','comments','thread_comments','assignments','watchers','subscriptions','presence','typing','receipts','meta'] as $key){
			if(!is_array($state[$key])){throw new \UnexpectedValueException('Collaboration state collection is invalid.');}
		}
		if(!is_array($state['receipt_order'])||!array_is_list($state['receipt_order'])){
			throw new \UnexpectedValueException('Collaboration receipt order is invalid.');
		}
		if(!is_int($state['receipt_sequence'])||$state['receipt_sequence']<0){
			throw new \UnexpectedValueException('Collaboration receipt sequence is invalid.');
		}
		if(count($state['receipts'])!==count($state['receipt_order'])||$state['receipt_sequence']!==count($state['receipt_order'])){
			throw new \UnexpectedValueException('Collaboration receipt inventory is inconsistent.');
		}
		foreach($state['receipt_order'] as $id){
			if(!is_string($id)||!array_key_exists($id, $state['receipts'])){
				throw new \UnexpectedValueException('Collaboration receipt inventory is invalid.');
			}
		}
		$verification=PanelCollaborationStateEngine::verifyReceipts($state);
		if(($verification['valid']??false)!==true||($verification['count']??-1)!==count($state['receipt_order'])){
			throw new \UnexpectedValueException('Collaboration receipt chain is invalid.');
		}
	}

	/** @param array<string,mixed> $metadata */
	private function recordChange(string $type,array $metadata,string $occurredAt):void {
		$json=self::json($metadata);
		$bytes=strlen($json);
		if($bytes>$this->maximumChangeBytes){
			throw $this->storage('change_too_large', 'Collaboration change metadata exceeds the configured byte bound.');
		}
		$sql="INSERT INTO {$this->prefix}_changes (event_type, event_json, event_bytes, occurred_at) VALUES (:event_type, :event_json, :event_bytes, :occurred_at)";
		$parameters=['event_type'=>$type, 'event_json'=>$json, 'event_bytes'=>$bytes, 'occurred_at'=>$occurredAt];
		if($this->driver==='pgsql'){
			$value=$this->execute($sql.' RETURNING change_id', $parameters)->fetchColumn();
		}else{
			$this->execute($sql, $parameters);
			$value=$this->pdo->lastInsertId();
		}
		$cursor=$this->integer($value, 1);
		$cutoff=$cursor-$this->changeRetention;
		if($cutoff>0){
			$this->execute("DELETE FROM {$this->prefix}_changes WHERE change_id <= :cutoff", ['cutoff'=>$cutoff]);
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function hydrateChange(array $row):array {
		try{
			foreach(['event_type','event_json','occurred_at'] as $key){
				if(!isset($row[$key])||!is_string($row[$key])){
					throw new \UnexpectedValueException('Stored collaboration change is invalid.');
				}
			}
			$cursor=$this->integer($row['change_id']??null, 1);
			$bytes=$this->integer($row['event_bytes']??null, 0);
			if($bytes!==strlen($row['event_json'])||$bytes>$this->maximumChangeBytes){
				throw new \UnexpectedValueException('Stored collaboration change size is invalid.');
			}
			$type=PanelOperationsGuard::name($row['event_type'], 'collaboration change type', 160);
			$occurredAt=PanelOperationsGuard::instant($row['occurred_at']);
			$metadata=json_decode($row['event_json'], true, 32, JSON_THROW_ON_ERROR);
			if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata))){
				throw new \UnexpectedValueException('Stored collaboration change metadata is invalid.');
			}
			$metadata=PanelCollaborationStateEngine::sanitize($metadata);
			if(!is_array($metadata)){throw new \UnexpectedValueException('Stored collaboration change metadata is invalid.');}
			return array_replace($metadata, ['cursor'=>$cursor, 'type'=>$type, 'occurred_at'=>$occurredAt]);
		}catch(\Throwable $error){
			throw $this->corrupt($error);
		}
	}

	private function currentCursor():int {
		$row=$this->row("SELECT COALESCE(MAX(change_id), 0) AS current_cursor FROM {$this->prefix}_changes");
		if($row===null){throw $this->corrupt();}
		return $this->integer($row['current_cursor']??null, 0);
	}

	private function databaseTransaction(bool $write,callable $callback):mixed {
		if($this->activeTransaction()){
			return $this->savepoint($callback);
		}
		$lastError=null;
		for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){
			$stage='begin';
			try{
				$this->begin($write);
				$stage='callback';
				$result=$callback();
				$stage='commit';
				$this->commit();
				return $result;
			}catch(PanelCollaborationStorageException $error){
				$this->rollback();
				throw $error;
			}catch(\PDOException $error){
				$this->rollback();
				if($stage!=='commit'&&$attempt<$this->transactionRetries&&$this->transient($error)){
					if($this->retryDelayMicroseconds>0){
						usleep(min(100000, $this->retryDelayMicroseconds*($attempt+1)));
					}
					continue;
				}
				$lastError=$error;
				break;
			}catch(\Throwable $error){
				$this->rollback();
				if($stage==='callback'){throw $error;}
				throw $this->storage('storage_unavailable', 'Panel PDO collaboration storage is unavailable.', true, $error);
			}
		}
		throw $this->storage('storage_unavailable', 'Panel PDO collaboration storage is unavailable.', true, $lastError);
	}

	private function savepoint(callable $callback):mixed {
		$name='dp_collaboration_'.(++$this->savepointSequence);
		try{
			if($this->pdo->exec("SAVEPOINT {$name}")===false){throw new \RuntimeException('PDO savepoint begin failed.');}
			$result=$callback();
			if($this->pdo->exec("RELEASE SAVEPOINT {$name}")===false){throw new \RuntimeException('PDO savepoint release failed.');}
			return $result;
		}catch(PanelCollaborationStorageException $error){
			$this->rollbackSavepoint($name);
			throw $error;
		}catch(\PDOException $error){
			$this->rollbackSavepoint($name);
			throw $this->storage('storage_unavailable', 'Panel PDO collaboration storage is unavailable.', true, $error);
		}catch(\Throwable $error){
			$this->rollbackSavepoint($name);
			throw $error;
		}
	}

	private function rollbackSavepoint(string $name):void {
		try{
			$this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
			$this->pdo->exec("RELEASE SAVEPOINT {$name}");
		}catch(\Throwable){}
	}

	private function begin(bool $write):void {
		foreach($write?[]:$this->dialect['read_before'] as $sql){
			if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}
		}
		if($write&&$this->dialect['write_begin']!==null){
			if($this->pdo->exec($this->dialect['write_begin'])===false){throw new \RuntimeException('PDO transaction begin failed.');}
			$this->manualSqliteWriteTransaction=true;
		}elseif(!$this->pdo->beginTransaction()){
			throw new \RuntimeException('PDO transaction begin failed.');
		}
		foreach($write?[]:$this->dialect['read_after'] as $sql){
			if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}
		}
	}

	private function commit():void {
		if($this->manualSqliteWriteTransaction){
			if($this->pdo->exec('COMMIT')===false){throw new \RuntimeException('PDO commit failed.');}
			$this->manualSqliteWriteTransaction=false;
			return;
		}
		if(!$this->pdo->commit()){throw new \RuntimeException('PDO commit failed.');}
	}

	private function rollback():void {
		try{
			if($this->manualSqliteWriteTransaction){$this->pdo->exec('ROLLBACK');}
			elseif($this->pdo->inTransaction()){$this->pdo->rollBack();}
		}catch(\Throwable){}
		finally{$this->manualSqliteWriteTransaction=false;}
	}

	private function activeTransaction():bool{return $this->manualSqliteWriteTransaction||$this->pdo->inTransaction();}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function execute(string $sql,array $parameters=[]):\PDOStatement {
		$statement=$this->pdo->prepare($sql);
		if(!$statement instanceof \PDOStatement){throw new \RuntimeException('PDO prepare failed.');}
		foreach($parameters as $name=>$value){
			$type=match(true){
				$value===null=>\PDO::PARAM_NULL,
				is_bool($value)=>\PDO::PARAM_BOOL,
				is_int($value)=>\PDO::PARAM_INT,
				default=>\PDO::PARAM_STR,
			};
			$statement->bindValue(':'.$name, $value, $type);
		}
		if(!$statement->execute()){throw new \RuntimeException('PDO execute failed.');}
		return $statement;
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return array<string,mixed>|null */
	private function row(string $sql,array $parameters=[]):?array {
		$row=$this->execute($sql, $parameters)->fetch(\PDO::FETCH_ASSOC);
		if($row===false){return null;}
		if(!is_array($row)||array_is_list($row)){throw $this->corrupt();}
		return $row;
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */
	private function rows(string $sql,array $parameters=[]):array {
		$rows=$this->execute($sql, $parameters)->fetchAll(\PDO::FETCH_ASSOC);
		if(!is_array($rows)){throw $this->corrupt();}
		foreach($rows as $row){
			if(!is_array($row)||array_is_list($row)){throw $this->corrupt();}
		}
		return array_values($rows);
	}

	private function integer(mixed $value,int $minimum):int {
		if(is_int($value)){$number=$value;}
		elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D', $value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){
			$number=(int)$value;
			if((string)$number!==$value){throw $this->corrupt();}
		}else{throw $this->corrupt();}
		if($number<$minimum){throw $this->corrupt();}
		return $number;
	}

	private function now():string {
		$value=($this->clock)();
		if(!is_string($value)&&!is_int($value)&&!$value instanceof \DateTimeInterface){
			throw new \UnexpectedValueException('Panel PDO collaboration clock returned an invalid instant.');
		}
		return PanelOperationsGuard::instant($value);
	}

	private function transient(\PDOException $error):bool {
		$state=strtoupper((string)$error->getCode());
		$info=$error->errorInfo;
		$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		$message=strtolower($error->getMessage());
		return in_array($state, ['40001','40P01','55P03'], true)
			||($this->driver==='sqlite'&&($native==='5'||$native==='6'||str_contains($message, 'locked')))
			||($this->driver==='mysql'&&in_array($native, ['1205','1213'], true));
	}

	private function missingRelation(\PDOException $error):bool {
		$state=strtoupper((string)$error->getCode());
		$info=$error->errorInfo;
		$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		$message=strtolower($error->getMessage());
		return in_array($state, ['42P01','42S02'], true)
			||in_array($native, ['1146'], true)
			||str_contains($message, 'no such table');
	}

	private function corrupt(?\Throwable $previous=null):PanelCollaborationStorageException {
		return $this->storage('storage_corrupt', 'Panel PDO collaboration storage failed integrity validation.', false, $previous);
	}

	private function storage(string $code,string $message,bool $retryable=false,?\Throwable $previous=null):PanelCollaborationStorageException {
		return new PanelCollaborationStorageException($code, $message, $retryable, $previous);
	}

	private static function driverName(string $driver):string {
		$driver=strtolower(trim($driver));
		if(!in_array($driver, ['mysql','pgsql','sqlite'], true)){
			throw new \InvalidArgumentException('Panel PDO collaboration store supports mysql, pgsql, and sqlite only.');
		}
		return $driver;
	}

	private static function prefix(string $prefix):string {
		$prefix=strtolower(trim($prefix));
		if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D', $prefix)!==1){
			throw new \InvalidArgumentException('Panel PDO collaboration table prefix is invalid.');
		}
		return $prefix;
	}

	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){
			throw new \InvalidArgumentException("Panel PDO collaboration option '{$name}' is outside its supported bound.");
		}
		return $value;
	}

	private static function json(mixed $value):string {
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}
}
