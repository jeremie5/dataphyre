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
 * Explicit-schema, scope-isolated shared-SQL snapshot and change-feed store.
 *
 * One portable table set can host many independent Panel aggregates. Scope
 * names are reduced to SHA-256 fingerprints before they reach durable state or
 * manifests. Writers lock one scope row, atomically advance its revision, and
 * append the matching retained event. The caller's mutation callback is never
 * replayed within one transaction() call, including after conflicts or
 * uncertain commits.
 */
final class PanelPdoSnapshotStore implements PanelSnapshotStore {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_snapshot';
	private const OPTION_NAMES=[
		'table_prefix',
		'maximum_payload_bytes',
		'maximum_event_bytes',
		'change_retention',
		'transaction_retries',
		'retry_delay_microseconds',
	];

	private readonly string $driver;
	private readonly string $prefix;
	private readonly string $scopeFingerprint;
	private readonly string $schema;
	private readonly string $schemaFingerprint;
	private readonly int $maximumPayloadBytes;
	private readonly int $maximumEventBytes;
	private readonly int $changeRetention;
	private readonly int $transactionRetries;
	private readonly int $retryDelayMicroseconds;
	private readonly \Closure $clock;
	private readonly string $initialPayloadJson;
	private readonly int $initialPayloadBytes;
	private readonly string $initialPayloadDigest;
	private bool $manualSqliteWriteTransaction=false;
	private int $savepointSequence=0;

	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	private readonly array $dialect;

	/**
	 * @param array<string,mixed> $initialPayload
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		private readonly \PDO $pdo,
		string $scope,
		string $schema,
		array $initialPayload=[],
		array $options=[],
		?callable $clock=null,
	){
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name, self::OPTION_NAMES, true)){
				throw new \InvalidArgumentException('Panel PDO snapshot options contain an unsupported name.');
			}
		}
		try{
			$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));
			$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);
		}catch(\Throwable $error){
			throw new \InvalidArgumentException('Panel PDO snapshot store could not inspect its connection.', 0, $error);
		}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){
			throw new \InvalidArgumentException('Panel PDO snapshot store requires PDO exception mode.');
		}
		$scope=trim($scope);
		if($scope===''||strlen($scope)>1024||str_contains($scope, "\0")){
			throw new \InvalidArgumentException('Panel PDO snapshot scope is invalid.');
		}
		$schema=trim($schema);
		if(strlen($schema)>160||preg_match('/^[a-zA-Z0-9._-]+$/D', $schema)!==1){
			throw new \InvalidArgumentException('Panel PDO snapshot schema is invalid.');
		}

		$this->driver=self::driverName($driver);
		$this->dialect=self::dialectPlanFor($driver);
		$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->scopeFingerprint=hash('sha256', $scope);
		$this->schema=$schema;
		$this->schemaFingerprint=hash('sha256', $schema);
		$this->maximumPayloadBytes=self::option($options, 'maximum_payload_bytes', 67108864, 1024, 268435456);
		$this->maximumEventBytes=self::option($options, 'maximum_event_bytes', 65536, 256, 1048576);
		$this->changeRetention=self::option($options, 'change_retention', 16384, 8, 1000000);
		$this->transactionRetries=self::option($options, 'transaction_retries', 3, 0, 10);
		$this->retryDelayMicroseconds=self::option($options, 'retry_delay_microseconds', 2000, 0, 100000);
		$this->clock=\Closure::fromCallable($clock??static fn():string=>gmdate('c'));
		[$this->initialPayloadJson,$this->initialPayloadBytes,$this->initialPayloadDigest]=$this->encodePayload($initialPayload);
	}

	public function driver():string{return $this->driver;}
	public function scopeFingerprint():string{return $this->scopeFingerprint;}

	/** @return list<string> */
	public function schemaStatements():array{return self::schemaStatementsFor($this->driver, $this->prefix);}

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array {
		$driver=self::driverName($driver);
		$prefix=self::prefix($prefix);
		$meta=$prefix.'_meta';
		$state=$prefix.'_state';
		$changes=$prefix.'_changes';

		if($driver==='mysql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$state} (scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, schema_name VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, storage_revision BIGINT UNSIGNED NOT NULL, committed_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, payload_json LONGTEXT NOT NULL, payload_bytes INT UNSIGNED NOT NULL, payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (scope_hash)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$changes} (scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, change_sequence BIGINT UNSIGNED NOT NULL, event_type VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, event_json MEDIUMTEXT NOT NULL, event_bytes INT UNSIGNED NOT NULL, event_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (scope_hash, change_sequence)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			];
		}
		if($driver==='pgsql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
				"CREATE TABLE IF NOT EXISTS {$state} (scope_hash CHAR(64) PRIMARY KEY, schema_name VARCHAR(160) NOT NULL, storage_revision BIGINT NOT NULL CHECK (storage_revision >= 0), committed_at VARCHAR(64) NULL, payload_json TEXT NOT NULL, payload_bytes INTEGER NOT NULL CHECK (payload_bytes > 0), payload_digest CHAR(64) NOT NULL)",
				"CREATE TABLE IF NOT EXISTS {$changes} (scope_hash CHAR(64) NOT NULL, change_sequence BIGINT NOT NULL CHECK (change_sequence > 0), event_type VARCHAR(160) NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), event_digest CHAR(64) NOT NULL, occurred_at VARCHAR(64) NOT NULL, PRIMARY KEY (scope_hash, change_sequence))",
				"INSERT INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.") ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return [
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$state} (scope_hash TEXT NOT NULL PRIMARY KEY CHECK (length(scope_hash) = 64), schema_name TEXT NOT NULL, storage_revision INTEGER NOT NULL CHECK (storage_revision >= 0), committed_at TEXT NULL, payload_json TEXT NOT NULL, payload_bytes INTEGER NOT NULL CHECK (payload_bytes > 0), payload_digest TEXT NOT NULL CHECK (length(payload_digest) = 64))",
			"CREATE TABLE IF NOT EXISTS {$changes} (scope_hash TEXT NOT NULL CHECK (length(scope_hash) = 64), change_sequence INTEGER NOT NULL CHECK (change_sequence > 0), event_type TEXT NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), event_digest TEXT NOT NULL CHECK (length(event_digest) = 64), occurred_at TEXT NOT NULL, PRIMARY KEY (scope_hash, change_sequence))",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
		];
	}

	/** @return array<string,mixed> */
	public function installSchema():array {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict', 'Panel PDO snapshot schema installation requires transaction ownership.', true);
		}
		try{
			foreach($this->schemaStatements() as $sql){
				if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO schema statement failed.');}
			}
			$this->databaseTransaction(true, function():void {
				$this->assertSchema(true);
				$this->initializeScope();
				$this->decodeState($this->stateRow(true));
			});
			return [
				'type'=>'panel_pdo_snapshot_schema_installation',
				'version'=>1,
				'driver'=>$this->driver,
				'schema_version'=>self::SCHEMA_VERSION,
				'scope_fingerprint'=>$this->scopeFingerprint,
				'schema_fingerprint'=>$this->schemaFingerprint,
				'statements'=>count($this->schemaStatements()),
				'idempotent'=>true,
				'destructive'=>false,
				'connection_details_serialized'=>false,
				'table_prefix_serialized'=>false,
				'sql_serialized'=>false,
			];
		}catch(PanelSnapshotStorageException $error){
			if(in_array($error->errorCode(), ['schema_incompatible','scope_conflict'], true)){throw $error;}
			throw $this->storage('migration_failed', 'Panel PDO snapshot schema migration failed.', true, $error);
		}catch(\Throwable $error){
			throw $this->storage('migration_failed', 'Panel PDO snapshot schema migration failed.', true, $error);
		}
	}

	/** @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>} */
	public function snapshot():array {
		return $this->databaseTransaction(false, function():array {
			$this->assertSchema(false);
			return $this->snapshotFromState($this->decodeState($this->stateRow(false)));
		});
	}

	/** @return array<string,mixed> */
	public function payload():array{return $this->snapshot()['payload'];}
	public function cursor():int{return $this->snapshot()['sequence'];}

	/**
	 * The mutation callback is invoked at most once per method call. Retries are
	 * limited to transaction acquisition and the locked state read before the
	 * callback begins. A post-callback conflict or uncertain commit is returned
	 * as a retryable error for the caller to reconcile explicitly.
	 *
	 * @param callable(array<string,mixed>&):mixed $mutation
	 * @param array<string,mixed> $event
	 * @return array{result:mixed,snapshot:array<string,mixed>}
	 */
	public function transaction(callable $mutation,string $type,array $event=[]):array {
		$type=self::eventType($type);
		$event=$this->eventMetadata($event);
		$this->encodeEvent(array_replace($event, [
			'cursor'=>PHP_INT_MAX,
			'type'=>$type,
			'occurred_at'=>'9999-12-31T23:59:59.999999Z',
		]));
		if($this->activeTransaction()){
			return $this->savepointMutation($mutation, $type, $event);
		}

		$decoded=null;
		$lastError=null;
		for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){
			try{
				$this->begin(true);
				$this->assertSchema(true);
				$decoded=$this->decodeState($this->stateRow(true));
				break;
			}catch(PanelSnapshotStorageException $error){
				$this->rollback();
				throw $error;
			}catch(\PDOException $error){
				$this->rollback();
				if($attempt<$this->transactionRetries&&$this->transient($error)){
					$this->retryDelay($attempt);
					continue;
				}
				$lastError=$error;
				break;
			}catch(\Throwable $error){
				$this->rollback();
				throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $error);
			}
		}
		if(!is_array($decoded)){
			throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $lastError);
		}

		try{
			$payload=$decoded['payload'];
			$result=$mutation($payload);
		}catch(\Throwable $error){
			$this->rollback();
			throw $error;
		}

		try{
			$snapshot=$this->persistMutation($decoded, $payload, $type, $event);
		}catch(PanelSnapshotStorageException $error){
			$this->rollback();
			throw $error;
		}catch(\Throwable $error){
			$this->rollback();
			throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $error);
		}

		try{
			$this->commit();
		}catch(\Throwable $error){
			$this->rollback();
			throw $this->storage('commit_uncertain', 'Panel PDO snapshot commit outcome is uncertain.', true, $error);
		}
		return ['result'=>$result, 'snapshot'=>$snapshot];
	}

	/** @return array{cursor:int,oldest_cursor:int,reset_required:bool,reset_reason:?string,changes:list<array<string,mixed>>,snapshot:?array<string,mixed>} */
	public function changesSince(int $cursor=0,int $limit=100):array {
		$cursor=max(0, $cursor);
		$limit=max(1, min(1000, $limit));
		return $this->databaseTransaction(false, function() use ($cursor,$limit):array {
			$this->assertSchema(false);
			$decoded=$this->decodeState($this->stateRow(false));
			$current=$decoded['sequence'];
			$bounds=$this->row(
				"SELECT COALESCE(MIN(change_sequence), 0) AS oldest_cursor, COALESCE(MAX(change_sequence), 0) AS latest_cursor FROM {$this->prefix}_changes WHERE scope_hash = :scope_hash",
				['scope_hash'=>$this->scopeFingerprint],
			);
			if($bounds===null){throw $this->corrupt();}
			$oldest=$this->integer($bounds['oldest_cursor']??null, 0);
			$latest=$this->integer($bounds['latest_cursor']??null, 0);
			if(($current===0&&($oldest!==0||$latest!==0))||($current>0&&($oldest<1||$latest!==$current))){
				throw $this->corrupt();
			}
			$stale=$cursor>0&&$oldest>0&&$cursor<$oldest-1;
			$future=$cursor>$current;
			$reset=$stale||$future;
			$changes=[];
			if(!$reset){
				foreach($this->rows(
					"SELECT change_sequence, event_type, event_json, event_bytes, event_digest, occurred_at FROM {$this->prefix}_changes WHERE scope_hash = :scope_hash AND change_sequence > :cursor ORDER BY change_sequence ASC LIMIT :limit",
					['scope_hash'=>$this->scopeFingerprint, 'cursor'=>$cursor, 'limit'=>$limit],
				) as $row){
					$changes[]=$this->hydrateChange($row);
				}
			}
			$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;
			return [
				'cursor'=>$next,
				'oldest_cursor'=>$oldest,
				'reset_required'=>$reset,
				'reset_reason'=>$future?'future_cursor':($stale?'retention_window':null),
				'changes'=>$changes,
				'snapshot'=>$reset?$this->snapshotFromState($decoded):null,
			];
		});
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		return [
			'type'=>'panel_pdo_snapshot_store',
			'version'=>1,
			'adapter'=>'pdo_shared_sql',
			'driver'=>$this->driver,
			'durable'=>true,
			'distributed'=>true,
			'cross_process'=>true,
			'shared_database'=>true,
			'scope_isolation'=>'sha256_primary_key',
			'scope_fingerprint'=>$this->scopeFingerprint,
			'schema_fingerprint'=>$this->schemaFingerprint,
			'atomic_state_commits'=>true,
			'state_write_serialization'=>'locked_scope_row',
			'maximum_payload_bytes'=>$this->maximumPayloadBytes,
			'maximum_event_bytes'=>$this->maximumEventBytes,
			'change_retention'=>$this->changeRetention,
			'schema_version'=>self::SCHEMA_VERSION,
			'schema_migration'=>'explicit_idempotent',
			'automatic_schema_mutation'=>false,
			'transaction_retries'=>$this->transactionRetries,
			'mutation_callback_delivery'=>'at_most_once_per_call',
			'commit_uncertainty'=>'explicit_retryable_error',
			'host_transactions_preserved'=>true,
			'connection_details_serialized'=>false,
			'credentials_serialized'=>false,
			'scope_name_serialized'=>false,
			'schema_name_serialized'=>false,
			'table_prefix_serialized'=>false,
			'sql_serialized'=>false,
			'provider_errors_serialized'=>false,
			'live_counts_queried'=>false,
			'capabilities'=>[
				'atomic_commits'=>true,
				'distributed_catalogue'=>true,
				'ordered_cursor'=>true,
				'bounded_change_feed'=>true,
				'stale_cursor_reset'=>true,
				'future_cursor_reset'=>true,
				'payload_size_integrity'=>true,
				'payload_sha256_integrity'=>true,
				'canonical_json_integrity'=>true,
				'event_size_integrity'=>true,
				'event_sha256_integrity'=>true,
				'host_savepoints'=>true,
				'owned_transaction_retries'=>true,
				'callback_replay'=>false,
				'secret_safe_manifest'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
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

	private function initializeScope():void {
		$sql="INSERT INTO {$this->prefix}_state (scope_hash, schema_name, storage_revision, committed_at, payload_json, payload_bytes, payload_digest) VALUES (:scope_hash, :schema_name, 0, NULL, :payload_json, :payload_bytes, :payload_digest)";
		$sql=match($this->driver){
			'mysql'=>'INSERT IGNORE'.substr($sql, 6),
			'pgsql'=>$sql.' ON CONFLICT (scope_hash) DO NOTHING',
			default=>'INSERT OR IGNORE'.substr($sql, 6),
		};
		$this->execute($sql, [
			'scope_hash'=>$this->scopeFingerprint,
			'schema_name'=>$this->schema,
			'payload_json'=>$this->initialPayloadJson,
			'payload_bytes'=>$this->initialPayloadBytes,
			'payload_digest'=>$this->initialPayloadDigest,
		]);
	}

	private function assertSchema(bool $lock):void {
		try{
			$row=$this->row("SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));
		}catch(\PDOException $error){
			if($this->missingRelation($error)){
				throw $this->storage('schema_required', 'Panel PDO snapshot schema is not installed.');
			}
			throw $error;
		}
		if($row===null){
			throw $this->storage('schema_required', 'Panel PDO snapshot schema is not installed.');
		}
		if($this->integer($row['schema_version']??null, 1)!==self::SCHEMA_VERSION){
			throw $this->storage('schema_incompatible', 'Panel PDO snapshot schema version is incompatible.');
		}
	}

	/** @return array<string,mixed> */
	private function stateRow(bool $lock):array {
		$row=$this->row(
			"SELECT schema_name, storage_revision, committed_at, payload_json, payload_bytes, payload_digest FROM {$this->prefix}_state WHERE scope_hash = :scope_hash".($lock?$this->dialect['lock_suffix']:''),
			['scope_hash'=>$this->scopeFingerprint],
		);
		if($row===null){
			throw $this->storage('scope_required', 'Panel PDO snapshot scope is not installed.');
		}
		return $row;
	}

	/** @param array<string,mixed> $row @return array{sequence:int,committed_at:?string,payload:array<string,mixed>} */
	private function decodeState(array $row):array {
		try{
			foreach(['schema_name','payload_json','payload_digest'] as $key){
				if(!isset($row[$key])||!is_string($row[$key])){
					throw new \UnexpectedValueException('Stored snapshot state is invalid.');
				}
			}
			if(!hash_equals($this->schema, $row['schema_name'])){
				throw $this->storage('scope_conflict', 'Panel PDO snapshot scope belongs to another schema.');
			}
			$sequence=$this->integer($row['storage_revision']??null, 0);
			$bytes=$this->integer($row['payload_bytes']??null, 1);
			if(
				$bytes!==strlen($row['payload_json'])
				||$bytes>$this->maximumPayloadBytes
				||preg_match('/^[a-f0-9]{64}$/D', $row['payload_digest'])!==1
				||!hash_equals($row['payload_digest'], hash('sha256', $row['payload_json']))
			){
				throw new \UnexpectedValueException('Stored snapshot state integrity is invalid.');
			}
			$payload=json_decode($row['payload_json'], true, 128, JSON_THROW_ON_ERROR);
			if(!is_array($payload)||($payload!==[]&&array_is_list($payload))){
				throw new \UnexpectedValueException('Stored snapshot payload shape is invalid.');
			}
			[$canonical]=$this->encodePayload($payload);
			if(!hash_equals($row['payload_json'], $canonical)){
				throw new \UnexpectedValueException('Stored snapshot payload is not canonical.');
			}
			$committedAt=$row['committed_at']??null;
			if($sequence===0){
				if($committedAt!==null){throw new \UnexpectedValueException('Initial snapshot instant is invalid.');}
			}elseif(!is_string($committedAt)||$committedAt===''||PanelOperationsGuard::instant($committedAt)!==$committedAt){
				throw new \UnexpectedValueException('Stored snapshot instant is invalid.');
			}
			return ['sequence'=>$sequence, 'committed_at'=>$committedAt, 'payload'=>$payload];
		}catch(PanelSnapshotStorageException $error){
			if($error->errorCode()==='scope_conflict'){throw $error;}
			throw $this->corrupt($error);
		}catch(\Throwable $error){
			throw $this->corrupt($error);
		}
	}

	/**
	 * @param array{sequence:int,committed_at:?string,payload:array<string,mixed>} $decoded
	 * @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>}
	 */
	private function snapshotFromState(array $decoded):array {
		$event=null;
		if($decoded['sequence']>0){
			$row=$this->row(
				"SELECT change_sequence, event_type, event_json, event_bytes, event_digest, occurred_at FROM {$this->prefix}_changes WHERE scope_hash = :scope_hash AND change_sequence = :change_sequence",
				['scope_hash'=>$this->scopeFingerprint, 'change_sequence'=>$decoded['sequence']],
			);
			if($row===null){throw $this->corrupt();}
			$event=$this->hydrateChange($row);
		}
		return [
			'schema'=>$this->schema,
			'sequence'=>$decoded['sequence'],
			'committed_at'=>$decoded['committed_at'],
			'payload'=>$decoded['payload'],
			'event'=>$event,
		];
	}

	/**
	 * @param array{sequence:int,committed_at:?string,payload:array<string,mixed>} $decoded
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $metadata
	 * @return array{schema:string,sequence:int,committed_at:string,payload:array<string,mixed>,event:array<string,mixed>}
	 */
	private function persistMutation(array $decoded,array $payload,string $type,array $metadata):array {
		[$payloadJson,$payloadBytes,$payloadDigest]=$this->encodePayload($payload);
		$sequence=$decoded['sequence']+1;
		$occurredAt=$this->now();
		$event=array_replace($metadata, [
			'cursor'=>$sequence,
			'type'=>$type,
			'occurred_at'=>$occurredAt,
		]);
		[$eventJson,$eventBytes,$eventDigest]=$this->encodeEvent($event);
		$updated=$this->execute(
			"UPDATE {$this->prefix}_state SET storage_revision = :next_revision, committed_at = :committed_at, payload_json = :payload_json, payload_bytes = :payload_bytes, payload_digest = :payload_digest WHERE scope_hash = :scope_hash AND storage_revision = :expected_revision",
			[
				'next_revision'=>$sequence,
				'committed_at'=>$occurredAt,
				'payload_json'=>$payloadJson,
				'payload_bytes'=>$payloadBytes,
				'payload_digest'=>$payloadDigest,
				'scope_hash'=>$this->scopeFingerprint,
				'expected_revision'=>$decoded['sequence'],
			],
		);
		if($updated->rowCount()!==1){
			throw $this->storage('write_conflict', 'Panel PDO snapshot state changed concurrently.', true);
		}
		$this->execute(
			"INSERT INTO {$this->prefix}_changes (scope_hash, change_sequence, event_type, event_json, event_bytes, event_digest, occurred_at) VALUES (:scope_hash, :change_sequence, :event_type, :event_json, :event_bytes, :event_digest, :occurred_at)",
			[
				'scope_hash'=>$this->scopeFingerprint,
				'change_sequence'=>$sequence,
				'event_type'=>$type,
				'event_json'=>$eventJson,
				'event_bytes'=>$eventBytes,
				'event_digest'=>$eventDigest,
				'occurred_at'=>$occurredAt,
			],
		);
		$cutoff=$sequence-$this->changeRetention;
		if($cutoff>0){
			$this->execute(
				"DELETE FROM {$this->prefix}_changes WHERE scope_hash = :scope_hash AND change_sequence <= :cutoff",
				['scope_hash'=>$this->scopeFingerprint, 'cutoff'=>$cutoff],
			);
		}
		$canonicalPayload=json_decode($payloadJson, true, 128, JSON_THROW_ON_ERROR);
		$canonicalEvent=json_decode($eventJson, true, 64, JSON_THROW_ON_ERROR);
		if(!is_array($canonicalPayload)||!is_array($canonicalEvent)){throw $this->corrupt();}
		return [
			'schema'=>$this->schema,
			'sequence'=>$sequence,
			'committed_at'=>$occurredAt,
			'payload'=>$canonicalPayload,
			'event'=>$canonicalEvent,
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function hydrateChange(array $row):array {
		try{
			foreach(['event_type','event_json','event_digest','occurred_at'] as $key){
				if(!isset($row[$key])||!is_string($row[$key])){
					throw new \UnexpectedValueException('Stored snapshot event is invalid.');
				}
			}
			$sequence=$this->integer($row['change_sequence']??null, 1);
			$bytes=$this->integer($row['event_bytes']??null, 1);
			if(
				$bytes!==strlen($row['event_json'])
				||$bytes>$this->maximumEventBytes
				||preg_match('/^[a-f0-9]{64}$/D', $row['event_digest'])!==1
				||!hash_equals($row['event_digest'], hash('sha256', $row['event_json']))
			){
				throw new \UnexpectedValueException('Stored snapshot event integrity is invalid.');
			}
			$event=json_decode($row['event_json'], true, 64, JSON_THROW_ON_ERROR);
			if(!is_array($event)||$event===[]||array_is_list($event)){
				throw new \UnexpectedValueException('Stored snapshot event shape is invalid.');
			}
			[$canonical]=$this->encodeEvent($event);
			if(!hash_equals($row['event_json'], $canonical)){
				throw new \UnexpectedValueException('Stored snapshot event is not canonical.');
			}
			$type=self::eventType($row['event_type']);
			$occurredAt=PanelOperationsGuard::instant($row['occurred_at']);
			if(
				($event['cursor']??null)!==$sequence
				||($event['type']??null)!==$type
				||($event['occurred_at']??null)!==$occurredAt
			){
				throw new \UnexpectedValueException('Stored snapshot event bindings are invalid.');
			}
			return $event;
		}catch(\Throwable $error){
			throw $this->corrupt($error);
		}
	}

	/** @param array<string,mixed> $payload @return array{string,int,string} */
	private function encodePayload(array $payload):array {
		if($payload!==[]&&array_is_list($payload)){
			throw $this->storage('payload_invalid', 'Snapshot payload must be an object-like map.');
		}
		try{$json=self::canonicalJson($payload, true);}
		catch(\Throwable $error){
			throw $this->storage('payload_invalid', 'Snapshot payload could not be encoded.', false, $error);
		}
		$bytes=strlen($json);
		if($bytes<2||$bytes>$this->maximumPayloadBytes){
			throw $this->storage('payload_too_large', 'Snapshot payload exceeds the configured byte bound.');
		}
		return [$json,$bytes,hash('sha256',$json)];
	}

	/** @param array<string,mixed> $event @return array{string,int,string} */
	private function encodeEvent(array $event):array {
		if($event===[]||array_is_list($event)){
			throw $this->storage('event_invalid', 'Snapshot event must be an object-like map.');
		}
		try{$json=self::canonicalJson($event, true);}
		catch(\Throwable $error){
			throw $this->storage('event_invalid', 'Snapshot event could not be encoded.', false, $error);
		}
		$bytes=strlen($json);
		if($bytes>$this->maximumEventBytes){
			throw $this->storage('event_too_large', 'Snapshot event exceeds the configured byte bound.');
		}
		return [$json,$bytes,hash('sha256',$json)];
	}

	/** @param array<string,mixed> $event @return array<string,mixed> */
	private function eventMetadata(array $event):array {
		if($event!==[]&&array_is_list($event)){
			throw new \InvalidArgumentException('Snapshot event metadata must be an object-like map.');
		}
		foreach(['cursor','type','occurred_at'] as $reserved){unset($event[$reserved]);}
		try{
			$json=self::canonicalJson($event, true);
			$decoded=json_decode($json, true, 64, JSON_THROW_ON_ERROR);
		}catch(\Throwable $error){
			throw new \InvalidArgumentException('Snapshot event metadata must contain JSON-native values.', 0, $error);
		}
		if(!is_array($decoded)){throw new \InvalidArgumentException('Snapshot event metadata is invalid.');}
		return $decoded;
	}

	/** @return array{result:mixed,snapshot:array<string,mixed>} */
	private function savepointMutation(callable $mutation,string $type,array $event):array {
		$name=$this->beginSavepoint();
		try{
			$this->assertSchema(true);
			$decoded=$this->decodeState($this->stateRow(true));
			$payload=$decoded['payload'];
			try{$result=$mutation($payload);}
			catch(\Throwable $error){
				$this->rollbackSavepoint($name);
				throw $error;
			}
			$snapshot=$this->persistMutation($decoded, $payload, $type, $event);
			try{$this->releaseSavepoint($name);}
			catch(\Throwable $error){
				$this->rollbackSavepoint($name);
				throw $this->storage('commit_uncertain', 'Panel PDO snapshot savepoint outcome is uncertain.', true, $error);
			}
			return ['result'=>$result, 'snapshot'=>$snapshot];
		}catch(PanelSnapshotStorageException $error){
			$this->rollbackSavepoint($name);
			throw $error;
		}catch(\PDOException $error){
			$this->rollbackSavepoint($name);
			throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $error);
		}catch(\Throwable $error){
			$this->rollbackSavepoint($name);
			throw $error;
		}
	}

	private function databaseTransaction(bool $write,callable $callback):mixed {
		if($this->activeTransaction()){
			$name=$this->beginSavepoint();
			try{
				$result=$callback();
				$this->releaseSavepoint($name);
				return $result;
			}catch(PanelSnapshotStorageException $error){
				$this->rollbackSavepoint($name);
				throw $error;
			}catch(\Throwable $error){
				$this->rollbackSavepoint($name);
				throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $error);
			}
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
			}catch(PanelSnapshotStorageException $error){
				$this->rollback();
				throw $error;
			}catch(\PDOException $error){
				$this->rollback();
				if($stage!=='commit'&&$attempt<$this->transactionRetries&&$this->transient($error)){
					$this->retryDelay($attempt);
					continue;
				}
				$lastError=$error;
				break;
			}catch(\Throwable $error){
				$this->rollback();
				if($stage==='commit'){
					throw $this->storage('commit_uncertain', 'Panel PDO snapshot commit outcome is uncertain.', true, $error);
				}
				throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $error);
			}
		}
		throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true, $lastError);
	}

	private function beginSavepoint():string {
		$name='dp_snapshot_'.(++$this->savepointSequence);
		if($this->pdo->exec("SAVEPOINT {$name}")===false){
			throw $this->storage('storage_unavailable', 'Panel PDO snapshot storage is unavailable.', true);
		}
		return $name;
	}

	private function releaseSavepoint(string $name):void {
		if($this->pdo->exec("RELEASE SAVEPOINT {$name}")===false){
			throw new \RuntimeException('PDO savepoint release failed.');
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
			if(!$statement->bindValue(':'.$name, $value, $type)){throw new \RuntimeException('PDO bind failed.');}
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
			throw new \UnexpectedValueException('Panel PDO snapshot clock returned an invalid instant.');
		}
		return PanelOperationsGuard::instant($value);
	}

	private function retryDelay(int $attempt):void {
		if($this->retryDelayMicroseconds>0){
			usleep(min(100000, $this->retryDelayMicroseconds*($attempt+1)));
		}
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
			||$native==='1146'
			||str_contains($message, 'no such table');
	}

	private function corrupt(?\Throwable $previous=null):PanelSnapshotStorageException {
		return $this->storage('storage_corrupt', 'Panel PDO snapshot storage failed integrity validation.', false, $previous);
	}

	private function storage(string $code,string $message,bool $retryable=false,?\Throwable $previous=null):PanelSnapshotStorageException {
		return new PanelSnapshotStorageException($code, $message, $retryable, $previous);
	}

	private static function eventType(string $type):string {
		$type=trim($type);
		if($type===''||strlen($type)>160||preg_match('/[\x00-\x1F\x7F]/', $type)===1){
			throw new \InvalidArgumentException('Snapshot event type is invalid.');
		}
		return $type;
	}

	private static function driverName(string $driver):string {
		$driver=strtolower(trim($driver));
		if(!in_array($driver, ['mysql','pgsql','sqlite'], true)){
			throw new \InvalidArgumentException('Panel PDO snapshot store supports mysql, pgsql, and sqlite only.');
		}
		return $driver;
	}

	private static function prefix(string $prefix):string {
		$prefix=strtolower(trim($prefix));
		if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D', $prefix)!==1){
			throw new \InvalidArgumentException('Panel PDO snapshot table prefix is invalid.');
		}
		return $prefix;
	}

	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){
			throw new \InvalidArgumentException("Panel PDO snapshot option '{$name}' is outside its supported bound.");
		}
		return $value;
	}

	private static function canonicalJson(mixed $value,bool $rootObject=false):string {
		$canonical=self::canonicalValue($value, 0);
		if($rootObject&&$canonical===[]){$canonical=(object)[];}
		return json_encode($canonical, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function canonicalValue(mixed $value,int $depth):mixed {
		if($depth>96){throw new \UnexpectedValueException('Snapshot JSON nesting exceeds its bound.');}
		if($value===null||is_bool($value)||is_int($value)||is_string($value)){return $value;}
		if(is_float($value)){
			if(!is_finite($value)){throw new \UnexpectedValueException('Snapshot JSON contains a non-finite number.');}
			return $value;
		}
		if(!is_array($value)){throw new \UnexpectedValueException('Snapshot JSON contains a non-native value.');}
		if(array_is_list($value)){
			return array_map(static fn(mixed $item):mixed=>self::canonicalValue($item, $depth+1), $value);
		}
		$result=[];
		foreach($value as $key=>$item){
			if(!is_string($key)){throw new \UnexpectedValueException('Snapshot JSON object keys must be strings.');}
			$result[$key]=self::canonicalValue($item, $depth+1);
		}
		ksort($result, SORT_STRING);
		return $result;
	}
}
