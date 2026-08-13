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
 * Shared-SQL command journal/outbox with atomic state commits, bounded change
 * feed, and fenced subscriber leases. Schema installation is always explicit.
 *
 * The current store contract mutates one validated aggregate snapshot. This
 * adapter therefore serializes writers through a locked state row; it provides
 * multi-process correctness, not unbounded event-log sharding.
 */
final class PanelPdoCommandFabricStore implements PanelLeasedCommandFabricStore,\JsonSerializable {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_command_fabric';
	private const OPTION_NAMES=['table_prefix','maximum_state_bytes','maximum_change_bytes','change_retention','transaction_retries','retry_delay_microseconds'];
	private readonly string $driver;private readonly string $prefix;private readonly int $maximumStateBytes;private readonly int $maximumChangeBytes;private readonly int $changeRetention;private readonly int $transactionRetries;private readonly int $retryDelayMicroseconds;private readonly \Closure $clock;private readonly \Closure $tokenFactory;private bool $manualSqliteWriteTransaction=false;
	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly \PDO $pdo,array $options=[],?callable $clock=null,?callable $tokenFactory=null){
		foreach(array_keys($options)as$name){if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){throw new \InvalidArgumentException('Panel PDO command-fabric options contain an unsupported name.');}}
		try{$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);}catch(\Throwable $error){throw new \InvalidArgumentException('Panel PDO command-fabric store could not inspect its connection.',0,$error);}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){throw new \InvalidArgumentException('Panel PDO command-fabric store requires PDO exception mode.');}
		$this->driver=self::driverName($driver);$this->dialect=self::dialectPlanFor($driver);$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->maximumStateBytes=self::option($options,'maximum_state_bytes',67108864,65536,268435456);$this->maximumChangeBytes=self::option($options,'maximum_change_bytes',65536,1024,1048576);$this->changeRetention=self::option($options,'change_retention',16384,8,1000000);$this->transactionRetries=self::option($options,'transaction_retries',3,0,10);$this->retryDelayMicroseconds=self::option($options,'retry_delay_microseconds',2000,0,100000);
		$this->clock=\Closure::fromCallable($clock??static fn():string=>gmdate('c'));$this->tokenFactory=\Closure::fromCallable($tokenFactory??static fn():string=>bin2hex(random_bytes(32)));
	}

	public function driver():string{return$this->driver;}
	/** @return list<string> */public function schemaStatements():array{return self::schemaStatementsFor($this->driver,$this->prefix);}

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array {
		$driver=self::driverName($driver);$prefix=self::prefix($prefix);$meta=$prefix.'_meta';$state=$prefix.'_state';$changes=$prefix.'_changes';$leases=$prefix.'_subscriber_leases';
		$initial=PanelOperationsGuard::json(PanelCommandFabricState::initial());$bytes=strlen($initial);$digest=hash('sha256',$initial);$initial=str_replace("'","''",$initial);$epoch='1970-01-01T00:00:00.000000Z';
		if($driver==='mysql'){return[
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
			"CREATE TABLE IF NOT EXISTS {$state} (singleton TINYINT UNSIGNED NOT NULL, storage_revision BIGINT UNSIGNED NOT NULL, state_revision BIGINT UNSIGNED NOT NULL, state_json LONGTEXT NOT NULL, state_bytes INT UNSIGNED NOT NULL, state_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, updated_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$state}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_json MEDIUMTEXT NOT NULL, event_bytes INT UNSIGNED NOT NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (change_id)) ENGINE=InnoDB",
			"CREATE TABLE IF NOT EXISTS {$leases} (subscriber VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, worker VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, fence BIGINT UNSIGNED NOT NULL DEFAULT 0, acquired_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, renewed_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, expires_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, PRIMARY KEY (subscriber), KEY expiry_lookup (expires_at, subscriber)) ENGINE=InnoDB",
			"INSERT IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			"INSERT IGNORE INTO {$state} (singleton, storage_revision, state_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}')",
		];}
		if($driver==='pgsql'){return[
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$state} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), storage_revision BIGINT NOT NULL CHECK (storage_revision >= 0), state_revision BIGINT NOT NULL CHECK (state_revision >= 0), state_json TEXT NOT NULL, state_bytes INTEGER NOT NULL CHECK (state_bytes > 0), state_digest CHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL)",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, event_type VARCHAR(160) NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), occurred_at VARCHAR(64) NOT NULL)",
			"CREATE TABLE IF NOT EXISTS {$leases} (subscriber VARCHAR(128) PRIMARY KEY, worker VARCHAR(190), token_hash CHAR(64), fence BIGINT NOT NULL DEFAULT 0 CHECK (fence >= 0), acquired_at VARCHAR(64), renewed_at VARCHAR(64), expires_at VARCHAR(64))",
			"CREATE INDEX IF NOT EXISTS {$leases}_expiry_lookup ON {$leases} (expires_at, subscriber)",
			"INSERT INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.") ON CONFLICT (singleton) DO NOTHING",
			"INSERT INTO {$state} (singleton, storage_revision, state_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}') ON CONFLICT (singleton) DO NOTHING",
		];}
		return[
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$state} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), storage_revision INTEGER NOT NULL CHECK (storage_revision >= 0), state_revision INTEGER NOT NULL CHECK (state_revision >= 0), state_json TEXT NOT NULL, state_bytes INTEGER NOT NULL CHECK (state_bytes > 0), state_digest TEXT NOT NULL CHECK (length(state_digest) = 64), updated_at TEXT NOT NULL)",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, event_json TEXT NOT NULL, event_bytes INTEGER NOT NULL CHECK (event_bytes > 0), occurred_at TEXT NOT NULL)",
			"CREATE TABLE IF NOT EXISTS {$leases} (subscriber TEXT NOT NULL PRIMARY KEY, worker TEXT, token_hash TEXT, fence INTEGER NOT NULL DEFAULT 0 CHECK (fence >= 0), acquired_at TEXT, renewed_at TEXT, expires_at TEXT)",
			"CREATE INDEX IF NOT EXISTS {$leases}_expiry_lookup ON {$leases} (expires_at, subscriber)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			"INSERT OR IGNORE INTO {$state} (singleton, storage_revision, state_revision, state_json, state_bytes, state_digest, updated_at) VALUES (1, 0, 0, '{$initial}', {$bytes}, '{$digest}', '{$epoch}')",
		];
	}

	/** @return array<string,mixed> */
	public function installSchema():array {
		if($this->activeTransaction()){throw$this->storage('transaction_conflict','Panel PDO command-fabric schema installation requires transaction ownership.',true);}
		try{foreach($this->schemaStatements()as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO schema statement failed.');}}$this->databaseTransaction(false,function():void{$this->assertSchema(false);$this->stateRow(false);});return['type'=>'panel_pdo_command_fabric_schema_installation','version'=>1,'driver'=>$this->driver,'schema_version'=>self::SCHEMA_VERSION,'statements'=>count($this->schemaStatements()),'idempotent'=>true,'destructive'=>false];}
		catch(PanelCommandFabricStorageException $error){if($error->errorCode()==='schema_incompatible'){throw$error;}throw$this->storage('migration_failed','Panel PDO command-fabric schema migration failed.',true,$error);}
		catch(\Throwable $error){throw$this->storage('migration_failed','Panel PDO command-fabric schema migration failed.',true,$error);}
	}

	public function payload():array{return$this->databaseTransaction(false,function():array{$this->assertSchema(false);return$this->decodeState($this->stateRow(false))['state'];});}

	public function transaction(callable $mutation,string $type,array $event=[]):array {
		$type=PanelOperationsGuard::name($type,'command fabric change type',160);$metadata=PanelOperationsGuard::safeMetadata($event,256);
		return$this->databaseTransaction(true,function()use($mutation,$type,$metadata):array{
			$this->assertSchema(false);$row=$this->stateRow(true);$decoded=$this->decodeState($row);$state=$decoded['state'];$result=$mutation($state);$state=PanelCommandFabricState::validate($state);[$json,$bytes,$digest]=$this->encodeState($state);$nextStorage=$decoded['storage_revision']+1;if($nextStorage<1){throw$this->corrupt();}$occurred=$this->now();
			$updated=$this->execute("UPDATE {$this->prefix}_state SET storage_revision = :next_storage, state_revision = :state_revision, state_json = :state_json, state_bytes = :state_bytes, state_digest = :state_digest, updated_at = :updated_at WHERE singleton = 1 AND storage_revision = :expected_storage",['next_storage'=>$nextStorage,'state_revision'=>$state['revision'],'state_json'=>$json,'state_bytes'=>$bytes,'state_digest'=>$digest,'updated_at'=>$occurred,'expected_storage'=>$decoded['storage_revision']]);
			if($updated->rowCount()!==1){throw$this->storage('write_conflict','Panel PDO command-fabric state changed concurrently.',true);}
			$change=$this->recordChange($type,$metadata,$occurred);return['result'=>$result,'snapshot'=>['sequence'=>$change['cursor'],'payload'=>$state,'event'=>$change]];
		});
	}

	public function changesSince(int $cursor=0,int $limit=100):array {
		$cursor=max(0,$cursor);$limit=max(1,min(1000,$limit));
		return$this->databaseTransaction(false,function()use($cursor,$limit):array{
			$this->assertSchema(false);$bounds=$this->row("SELECT MIN(change_id) AS oldest, MAX(change_id) AS current FROM {$this->prefix}_changes");$oldest=$bounds===null||$bounds['oldest']===null?0:$this->integer($bounds['oldest'],0);$current=$bounds===null||$bounds['current']===null?0:$this->integer($bounds['current'],0);$reset=$cursor>$current||($oldest>0&&$cursor<$oldest-1);$changes=[];
			if(!$reset){foreach($this->rows("SELECT change_id, event_type, event_json, event_bytes, occurred_at FROM {$this->prefix}_changes WHERE change_id > :cursor ORDER BY change_id ASC LIMIT {$limit}",['cursor'=>$cursor])as$row){$changes[]=$this->hydrateChange($row);}}
			$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;$snapshot=null;if($reset){$snapshot=['payload'=>$this->decodeState($this->stateRow(false))['state'],'sequence'=>$current];}
			return['cursor'=>$next,'oldest_cursor'=>$oldest,'reset_required'=>$reset,'changes'=>$changes,'snapshot'=>$snapshot];
		});
	}

	public function currentTime():string{return$this->now();}

	public function acquireSubscriberLease(string $subscriber,string $worker='worker',int $ttlSeconds=60):?PanelCommandFabricSubscriberLease {
		$subscriber=PanelOperationsGuard::name($subscriber,'command fabric subscriber',128);$worker=$this->worker($worker);$ttl=$this->ttl($ttlSeconds);$now=$this->now();$token=$this->token();$expires=$this->plusSeconds($now,$ttl);
		return$this->databaseTransaction(true,function()use($subscriber,$worker,$now,$token,$expires):?PanelCommandFabricSubscriberLease{
			$this->assertSchema(false);$row=$this->leaseRow($subscriber,true);if($row===null){$this->execute("INSERT INTO {$this->prefix}_subscriber_leases (subscriber, worker, token_hash, fence, acquired_at, renewed_at, expires_at) VALUES (:subscriber, NULL, NULL, 0, NULL, NULL, NULL)",['subscriber'=>$subscriber]);$row=$this->leaseRow($subscriber,true);if($row===null){throw$this->corrupt();}}
			$state=$this->leaseState($row);if($state['worker']!==null&&strcmp((string)$state['expires_at'],$now)>0){return null;}if($state['fence']===PHP_INT_MAX){throw$this->storage('fence_exhausted','Command fabric subscriber lease fence is exhausted.');}$fence=$state['fence']+1;$hash=$this->tokenHash($token);
			$statement=$this->execute("UPDATE {$this->prefix}_subscriber_leases SET worker = :worker, token_hash = :token_hash, fence = :next_fence, acquired_at = :acquired_at, renewed_at = :renewed_at, expires_at = :expires_at WHERE subscriber = :subscriber AND fence = :expected_fence",['worker'=>$worker,'token_hash'=>$hash,'next_fence'=>$fence,'acquired_at'=>$now,'renewed_at'=>$now,'expires_at'=>$expires,'subscriber'=>$subscriber,'expected_fence'=>$state['fence']]);if($statement->rowCount()!==1){throw$this->storage('lease_conflict','Command fabric subscriber lease changed concurrently.',true);}
			return PanelCommandFabricSubscriberLease::make($subscriber,$worker,$token,$fence,$now,$expires,$now);
		});
	}

	public function inspectSubscriberLease(PanelCommandFabricSubscriberLease $lease):PanelCommandFabricSubscriberLease {
		$now=$this->now();return$this->databaseTransaction(false,function()use($lease,$now):PanelCommandFabricSubscriberLease{$this->assertSchema(false);$state=$this->validatedLease($lease,false,true,$now);return PanelCommandFabricSubscriberLease::make($lease->subscriber(),$lease->worker(),$lease->token(),$lease->fence(),$state['acquired_at'],$state['expires_at'],$state['renewed_at']);});
	}

	public function renewSubscriberLease(PanelCommandFabricSubscriberLease $lease,int $ttlSeconds=60):PanelCommandFabricSubscriberLease {
		$ttl=$this->ttl($ttlSeconds);$now=$this->now();$expires=$this->plusSeconds($now,$ttl);
		return$this->databaseTransaction(true,function()use($lease,$now,$expires):PanelCommandFabricSubscriberLease{$this->assertSchema(false);$state=$this->validatedLease($lease,true,true,$now);$statement=$this->execute("UPDATE {$this->prefix}_subscriber_leases SET renewed_at = :renewed_at, expires_at = :expires_at WHERE subscriber = :subscriber AND fence = :fence AND token_hash = :token_hash",['renewed_at'=>$now,'expires_at'=>$expires,'subscriber'=>$lease->subscriber(),'fence'=>$lease->fence(),'token_hash'=>$state['token_hash']]);if($statement->rowCount()!==1){throw new PanelCommandFabricLeaseLost($lease->subscriber());}return PanelCommandFabricSubscriberLease::make($lease->subscriber(),$lease->worker(),$lease->token(),$lease->fence(),$state['acquired_at'],$expires,$now);});
	}

	public function advanceSubscriberCursor(PanelCommandFabricSubscriberLease $lease,int $sequence):void {
		if($sequence<0){throw new \InvalidArgumentException('Command fabric subscriber cursor cannot be negative.');}
		$now=$this->now();
		$this->databaseTransaction(true,function()use($lease,$sequence,$now):void{
			$this->assertSchema(false);
			$row=$this->stateRow(true);
			$decoded=$this->decodeState($row);
			$this->validatedLease($lease,true,true,$now);
			$state=$decoded['state'];
			$current=(int)($state['subscriber_cursors'][$lease->subscriber()]??0);
			if($sequence>$state['sequence']){throw new \UnexpectedValueException('Subscriber cursor exceeds the event sequence.');}
			if($sequence<=$current){return;}
			$state['subscriber_cursors'][$lease->subscriber()]=$sequence;
			$state['revision']++;
			$state=PanelCommandFabricState::validate($state);
			[$json,$bytes,$digest]=$this->encodeState($state);
			$nextStorage=$decoded['storage_revision']+1;
			if($nextStorage<1){throw$this->corrupt();}
			$updated=$this->execute(
				"UPDATE {$this->prefix}_state SET storage_revision = :next_storage, state_revision = :state_revision, state_json = :state_json, state_bytes = :state_bytes, state_digest = :state_digest, updated_at = :updated_at WHERE singleton = 1 AND storage_revision = :expected_storage",
				['next_storage'=>$nextStorage,'state_revision'=>$state['revision'],'state_json'=>$json,'state_bytes'=>$bytes,'state_digest'=>$digest,'updated_at'=>$now,'expected_storage'=>$decoded['storage_revision']],
			);
			if($updated->rowCount()!==1){throw$this->storage('write_conflict','Panel PDO command-fabric state changed concurrently.',true);}
			$this->recordChange('subscriber_advanced',['subscriber'=>$lease->subscriber(),'event_sequence'=>$sequence,'lease_fence'=>$lease->fence()],$now);
		});
	}

	public function releaseSubscriberLease(PanelCommandFabricSubscriberLease $lease):void {
		$this->databaseTransaction(true,function()use($lease):void{$this->assertSchema(false);$state=$this->validatedLease($lease,true,false,$this->now());$statement=$this->execute("UPDATE {$this->prefix}_subscriber_leases SET worker = NULL, token_hash = NULL, acquired_at = NULL, renewed_at = NULL, expires_at = NULL WHERE subscriber = :subscriber AND fence = :fence AND token_hash = :token_hash",['subscriber'=>$lease->subscriber(),'fence'=>$lease->fence(),'token_hash'=>$state['token_hash']]);if($statement->rowCount()!==1){throw new PanelCommandFabricLeaseLost($lease->subscriber());}});
	}

	public function activeSubscriberLeaseManifests():array {
		$now=$this->now();return$this->databaseTransaction(false,function()use($now):array{$this->assertSchema(false);$items=[];foreach($this->rows("SELECT subscriber, worker, token_hash, fence, acquired_at, renewed_at, expires_at FROM {$this->prefix}_subscriber_leases WHERE worker IS NOT NULL AND expires_at > :expires_at ORDER BY subscriber ASC",['expires_at'=>$now])as$row){$state=$this->leaseState($row);if($state['worker']===null){throw$this->corrupt();}$items[]=['subscriber'=>$state['subscriber'],'worker'=>$state['worker'],'fence'=>$state['fence'],'acquired_at'=>$state['acquired_at'],'renewed_at'=>$state['renewed_at'],'expires_at'=>$state['expires_at']];}return$items;});
	}

	public function cursor():int{$feed=$this->changesSince(PHP_INT_MAX,1);return(int)($feed['snapshot']['sequence']??$feed['cursor']);}
	public function manifest():array{return[
		'type'=>'panel_pdo_command_fabric_store','version'=>1,'adapter'=>'pdo','driver'=>$this->driver,'durable'=>true,'distributed'=>true,'cross_process'=>true,'shared_database'=>true,
		'atomic_state_commits'=>true,'state_write_serialization'=>'locked_single_row','normalized_event_log'=>false,'bounded_aggregate_state'=>true,'maximum_state_bytes'=>$this->maximumStateBytes,
		'change_feed'=>true,'change_retention'=>$this->changeRetention,'fenced_subscriber_leases'=>true,'lease_token_digest'=>'sha256_domain_separated','raw_lease_tokens_stored'=>false,
		'schema_version'=>self::SCHEMA_VERSION,'schema_migration'=>'explicit_idempotent','automatic_schema_mutation'=>false,'transaction_ownership_required'=>true,'transaction_retries'=>$this->transactionRetries,
		'delivery'=>'at_least_once','exactly_once'=>false,'connection_details_serialized'=>false,'credentials_serialized'=>false,'table_prefix_serialized'=>false,'sql_serialized'=>false,'live_counts_queried'=>false,
		'capabilities'=>['atomic_transactions'=>true,'change_feed'=>true,'retention_reset'=>true,'fenced_subscriber_leases'=>true,'atomic_fenced_cursor'=>true,'secret_safe_manifest'=>true],
	];}
	public function jsonSerialize():array{return$this->manifest();}

	/** @return array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	public static function dialectPlanFor(string $driver):array {$driver=self::driverName($driver);return['write_begin'=>$driver==='sqlite'?'BEGIN IMMEDIATE':null,'read_before'=>$driver==='mysql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'read_after'=>$driver==='pgsql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'lock_suffix'=>$driver==='sqlite'?'':' FOR UPDATE'];}

	private function assertSchema(bool $lock):void {
		try{$row=$this->row("SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));}catch(\PDOException $error){if($this->missingRelation($error)){throw$this->storage('schema_required','Panel PDO command-fabric schema is not installed.');}throw$error;}
		if($row===null){throw$this->storage('schema_required','Panel PDO command-fabric schema is not installed.');}$version=$this->integer($row['schema_version']??null,1);if($version!==self::SCHEMA_VERSION){throw$this->storage('schema_incompatible','Panel PDO command-fabric schema version is incompatible.');}
	}

	/** @return array<string,mixed> */
	private function stateRow(bool $lock):array {$row=$this->row("SELECT storage_revision, state_revision, state_json, state_bytes, state_digest, updated_at FROM {$this->prefix}_state WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));if($row===null){throw$this->corrupt();}return$row;}

	/** @param array<string,mixed> $row @return array{storage_revision:int,state:array<string,mixed>} */
	private function decodeState(array $row):array {
		try{foreach(['state_json','state_digest','updated_at']as$key){if(!isset($row[$key])||!is_string($row[$key])){throw new \UnexpectedValueException('Stored command-fabric state is invalid.');}}$storage=$this->integer($row['storage_revision']??null,0);$revision=$this->integer($row['state_revision']??null,0);$bytes=$this->integer($row['state_bytes']??null,1);if($bytes!==strlen($row['state_json'])||$bytes>$this->maximumStateBytes||preg_match('/^[a-f0-9]{64}$/D',$row['state_digest'])!==1||!hash_equals($row['state_digest'],hash('sha256',$row['state_json']))){throw new \UnexpectedValueException('Stored command-fabric state integrity is invalid.');}PanelOperationsGuard::instant($row['updated_at']);$state=json_decode($row['state_json'],true,128,JSON_THROW_ON_ERROR);if(!is_array($state)||array_is_list($state)){throw new \UnexpectedValueException('Stored command-fabric state shape is invalid.');}$state=PanelCommandFabricState::validate($state);if($state['revision']!==$revision){throw new \UnexpectedValueException('Stored command-fabric state revision is inconsistent.');}return['storage_revision'=>$storage,'state'=>$state];}
		catch(PanelCommandFabricStorageException $error){throw$error;}catch(\Throwable $error){throw$this->corrupt($error);}
	}

	/** @param array<string,mixed> $state @return array{string,int,string} */
	private function encodeState(array $state):array {try{$json=PanelOperationsGuard::json($state);}catch(\Throwable $error){throw$this->storage('state_invalid','Command-fabric state could not be encoded.',false,$error);}$bytes=strlen($json);if($bytes<1||$bytes>$this->maximumStateBytes){throw$this->storage('state_too_large','Command-fabric state exceeds the configured byte bound.');}return[$json,$bytes,hash('sha256',$json)];}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	private function recordChange(string $type,array $metadata,string $occurredAt):array {
		$json=PanelOperationsGuard::json($metadata);$bytes=strlen($json);if($bytes>$this->maximumChangeBytes){throw$this->storage('change_too_large','Command-fabric change metadata exceeds the configured byte bound.');}
		$sql="INSERT INTO {$this->prefix}_changes (event_type, event_json, event_bytes, occurred_at) VALUES (:event_type, :event_json, :event_bytes, :occurred_at)";$params=['event_type'=>$type,'event_json'=>$json,'event_bytes'=>$bytes,'occurred_at'=>$occurredAt];
		if($this->driver==='pgsql'){$value=$this->execute($sql.' RETURNING change_id',$params)->fetchColumn();}else{$this->execute($sql,$params);$value=$this->pdo->lastInsertId();}$cursor=$this->integer($value,1);$cutoff=$cursor-$this->changeRetention;if($cutoff>0){$this->execute("DELETE FROM {$this->prefix}_changes WHERE change_id <= :cutoff",['cutoff'=>$cutoff]);}
		return array_replace($metadata,['cursor'=>$cursor,'type'=>$type,'occurred_at'=>$occurredAt]);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function hydrateChange(array $row):array {try{foreach(['event_type','event_json','occurred_at']as$key){if(!isset($row[$key])||!is_string($row[$key])){throw new \UnexpectedValueException('Stored command-fabric change is invalid.');}}$cursor=$this->integer($row['change_id']??null,1);$bytes=$this->integer($row['event_bytes']??null,0);if($bytes!==strlen($row['event_json'])||$bytes>$this->maximumChangeBytes){throw new \UnexpectedValueException('Stored command-fabric change size is invalid.');}$type=PanelOperationsGuard::name($row['event_type'],'command fabric change type',160);$occurred=PanelOperationsGuard::instant($row['occurred_at']);$metadata=json_decode($row['event_json'],true,32,JSON_THROW_ON_ERROR);if(!is_array($metadata)||($metadata!==[]&&array_is_list($metadata))){throw new \UnexpectedValueException('Stored command-fabric change metadata is invalid.');}return array_replace(PanelOperationsGuard::safeMetadata($metadata,256),['cursor'=>$cursor,'type'=>$type,'occurred_at'=>$occurred]);}catch(\Throwable $error){throw$this->corrupt($error);}}

	/** @return array<string,mixed>|null */private function leaseRow(string $subscriber,bool $lock):?array{return$this->row("SELECT subscriber, worker, token_hash, fence, acquired_at, renewed_at, expires_at FROM {$this->prefix}_subscriber_leases WHERE subscriber = :subscriber".($lock?$this->dialect['lock_suffix']:''),['subscriber'=>$subscriber]);}

	/** @param array<string,mixed> $row @return array{subscriber:string,worker:?string,token_hash:?string,fence:int,acquired_at:?string,renewed_at:?string,expires_at:?string} */
	private function leaseState(array $row):array {
		$subscriber=$row['subscriber']??null;if(!is_string($subscriber)){throw$this->corrupt();}PanelOperationsGuard::name($subscriber,'command fabric subscriber',128);$fence=$this->integer($row['fence']??null,0);$worker=$row['worker']??null;$hash=$row['token_hash']??null;$acquired=$row['acquired_at']??null;$renewed=$row['renewed_at']??null;$expires=$row['expires_at']??null;
		if($worker===null){if($hash!==null||$acquired!==null||$renewed!==null||$expires!==null){throw$this->corrupt();}return['subscriber'=>$subscriber,'worker'=>null,'token_hash'=>null,'fence'=>$fence,'acquired_at'=>null,'renewed_at'=>null,'expires_at'=>null];}
		if(!is_string($worker)||!is_string($hash)||!is_string($acquired)||!is_string($renewed)||!is_string($expires)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw$this->corrupt();}PanelOperationsGuard::identifier($worker,'command fabric subscriber worker',190);$acquired=PanelOperationsGuard::instant($acquired);$renewed=PanelOperationsGuard::instant($renewed);$expires=PanelOperationsGuard::instant($expires);if($fence<1||strcmp($renewed,$acquired)<0||strcmp($expires,$renewed)<=0){throw$this->corrupt();}return['subscriber'=>$subscriber,'worker'=>$worker,'token_hash'=>$hash,'fence'=>$fence,'acquired_at'=>$acquired,'renewed_at'=>$renewed,'expires_at'=>$expires];
	}

	/** @return array{subscriber:string,worker:string,token_hash:string,fence:int,acquired_at:string,renewed_at:string,expires_at:string} */
	private function validatedLease(PanelCommandFabricSubscriberLease $lease,bool $lock,bool $live,string $now):array {
		$row=$this->leaseRow($lease->subscriber(),$lock);if($row===null){throw new PanelCommandFabricLeaseLost($lease->subscriber());}$state=$this->leaseState($row);if($state['worker']===null||$state['worker']!==$lease->worker()||$state['fence']!==$lease->fence()||!hash_equals((string)$state['token_hash'],$this->tokenHash($lease->token()))){throw new PanelCommandFabricLeaseLost($lease->subscriber(),'Command fabric subscriber lease was superseded by another worker.');}if($live&&strcmp((string)$state['expires_at'],$now)<=0){throw new PanelCommandFabricLeaseLost($lease->subscriber(),'Command fabric subscriber lease expired.');}return$state;
	}

	private function databaseTransaction(bool $write,callable $callback):mixed {
		if($this->activeTransaction()){throw$this->storage('transaction_conflict','Panel PDO command-fabric store requires transaction ownership.',true);}
		$lastError=null;for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){$inside=false;try{$this->begin($write);$inside=true;$result=$callback();$inside=false;$this->commit();return$result;}catch(PanelCommandFabricStorageException|PanelCommandFabricLeaseLost $error){$this->rollback();throw$error;}catch(\PDOException $error){$this->rollback();if($attempt<$this->transactionRetries&&$this->transient($error)){if($this->retryDelayMicroseconds>0){usleep(min(100000,$this->retryDelayMicroseconds*($attempt+1)));}continue;}$lastError=$error;break;}catch(\Throwable $error){$this->rollback();if($inside){throw$error;}throw$this->storage('storage_unavailable','Panel PDO command-fabric storage is unavailable.',true,$error);}}
		throw$this->storage('storage_unavailable','Panel PDO command-fabric storage is unavailable.',true,$lastError);
	}

	private function begin(bool $write):void {foreach($write?[]:$this->dialect['read_before']as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}}if($write&&$this->dialect['write_begin']!==null){if($this->pdo->exec($this->dialect['write_begin'])===false){throw new \RuntimeException('PDO transaction begin failed.');}$this->manualSqliteWriteTransaction=true;}elseif(!$this->pdo->beginTransaction()){throw new \RuntimeException('PDO transaction begin failed.');}foreach($write?[]:$this->dialect['read_after']as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}}}
	private function commit():void {if($this->manualSqliteWriteTransaction){if($this->pdo->exec('COMMIT')===false){throw new \RuntimeException('PDO commit failed.');}$this->manualSqliteWriteTransaction=false;return;}if(!$this->pdo->commit()){throw new \RuntimeException('PDO commit failed.');}}
	private function rollback():void {try{if($this->manualSqliteWriteTransaction){$this->pdo->exec('ROLLBACK');}elseif($this->pdo->inTransaction()){$this->pdo->rollBack();}}catch(\Throwable){}finally{$this->manualSqliteWriteTransaction=false;}}
	private function activeTransaction():bool{return$this->manualSqliteWriteTransaction||$this->pdo->inTransaction();}

	/** @param array<string,null|bool|int|float|string> $parameters */private function execute(string $sql,array $parameters=[]):\PDOStatement {$statement=$this->pdo->prepare($sql);if(!$statement instanceof \PDOStatement){throw new \RuntimeException('PDO prepare failed.');}foreach($parameters as$name=>$value){$type=match(true){$value===null=>\PDO::PARAM_NULL,is_bool($value)=>\PDO::PARAM_BOOL,is_int($value)=>\PDO::PARAM_INT,default=>\PDO::PARAM_STR};$statement->bindValue(':'.$name,$value,$type);}if(!$statement->execute()){throw new \RuntimeException('PDO execute failed.');}return$statement;}
	/** @param array<string,null|bool|int|float|string> $parameters @return array<string,mixed>|null */private function row(string $sql,array $parameters=[]):?array {$row=$this->execute($sql,$parameters)->fetch(\PDO::FETCH_ASSOC);if($row===false){return null;}if(!is_array($row)||array_is_list($row)){throw$this->corrupt();}return$row;}
	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */private function rows(string $sql,array $parameters=[]):array {$rows=$this->execute($sql,$parameters)->fetchAll(\PDO::FETCH_ASSOC);if(!is_array($rows)){throw$this->corrupt();}foreach($rows as$row){if(!is_array($row)||array_is_list($row)){throw$this->corrupt();}}return array_values($rows);}
	private function integer(mixed $value,int $minimum):int {if(is_int($value)){$number=$value;}elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){$number=(int)$value;if((string)$number!==$value){throw$this->corrupt();}}else{throw$this->corrupt();}if($number<$minimum){throw$this->corrupt();}return$number;}

	private function now():string {$value=($this->clock)();if(!is_string($value)&&!is_int($value)&&!$value instanceof \DateTimeInterface){throw new \UnexpectedValueException('Panel PDO command-fabric clock returned an invalid instant.');}return PanelOperationsGuard::instant($value);}
	private function token():string {$token=($this->tokenFactory)();if(!is_string($token)||strlen($token)<32||strlen($token)>512||str_contains($token,"\0")){throw new \UnexpectedValueException('Panel PDO command-fabric token factory returned an unsafe bearer proof.');}return$token;}
	private function tokenHash(string $token):string{return hash('sha256',"panel-command-fabric-subscriber-lease-v1\0".$token);}
	private function plusSeconds(string $time,int $seconds):string{return(new \DateTimeImmutable($time))->modify('+'.$seconds.' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');}
	private function ttl(int $seconds):int{return max(5,min(3600,$seconds));}
	private function worker(string $worker):string{return PanelOperationsGuard::identifier($worker,'command fabric subscriber worker',190);}
	private function transient(\PDOException $error):bool {$state=strtoupper((string)$error->getCode());$info=$error->errorInfo;$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';$message=strtolower($error->getMessage());return in_array($state,['40001','40P01','55P03'],true)||($this->driver==='sqlite'&&($native==='5'||$native==='6'||str_contains($message,'locked')))||($this->driver==='mysql'&&in_array($native,['1205','1213'],true));}
	private function missingRelation(\PDOException $error):bool {$state=strtoupper((string)$error->getCode());$info=$error->errorInfo;$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';$message=strtolower($error->getMessage());return in_array($state,['42P01','42S02'],true)||in_array($native,['1146'],true)||str_contains($message,'no such table');}
	private function corrupt(?\Throwable $previous=null):PanelCommandFabricStorageException{return$this->storage('storage_corrupt','Panel PDO command-fabric storage failed integrity validation.',false,$previous);}
	private function storage(string $code,string $message,bool $retryable=false,?\Throwable $previous=null):PanelCommandFabricStorageException{return new PanelCommandFabricStorageException($code,$message,$retryable,$previous);}
	private static function driverName(string $driver):string {$driver=strtolower(trim($driver));if(!in_array($driver,['mysql','pgsql','sqlite'],true)){throw new \InvalidArgumentException('Panel PDO command-fabric store supports mysql, pgsql, and sqlite only.');}return$driver;}
	private static function prefix(string $prefix):string {$prefix=strtolower(trim($prefix));if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D',$prefix)!==1){throw new \InvalidArgumentException('Panel PDO command-fabric table prefix is invalid.');}return$prefix;}
	/** @param array<string,mixed> $options */private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {$value=$options[$name]??$default;if(!is_int($value)||$value<$minimum||$value>$maximum){throw new \InvalidArgumentException("Panel PDO command-fabric option '{$name}' is outside its supported bound.");}return$value;}
}
