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
 * Durable shared-SQL operation store with optimistic revisions, renewable
 * leases, monotonic fencing, expiry recovery, and a bounded metadata feed.
 * Schema installation is always an explicit host migration.
 */
final class PanelPdoLeasedOperationStore implements PanelLeasedOperationStore,\JsonSerializable {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_operations';
	private const OPTION_NAMES=['table_prefix','maximum_record_bytes','change_retention','transaction_retries','retry_delay_microseconds'];
	private readonly string $driver;
	private readonly string $prefix;
	private readonly int $maximumRecordBytes;
	private readonly int $changeRetention;
	private readonly int $transactionRetries;
	private readonly int $retryDelayMicroseconds;
	private readonly \Closure $clock;
	private readonly \Closure $tokenFactory;
	private bool $manualSqliteWriteTransaction=false;
	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string,reserve_suffix:string} */
	private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly \PDO $pdo,array $options=[],?callable $clock=null,?callable $tokenFactory=null){
		foreach(array_keys($options)as$name){if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){throw new \InvalidArgumentException('Panel PDO leased-operation options contain an unsupported name.');}}
		try{$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);}catch(\Throwable $error){throw new \InvalidArgumentException('Panel PDO leased-operation store could not inspect its connection.',0,$error);}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){throw new \InvalidArgumentException('Panel PDO leased-operation store requires PDO exception mode.');}
		$this->driver=self::driverName($driver);$this->dialect=self::dialectPlanFor($driver);$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->maximumRecordBytes=self::option($options,'maximum_record_bytes',8388608,4096,67108864);$this->changeRetention=self::option($options,'change_retention',4096,8,1000000);$this->transactionRetries=self::option($options,'transaction_retries',3,0,10);$this->retryDelayMicroseconds=self::option($options,'retry_delay_microseconds',2000,0,100000);
		$this->clock=\Closure::fromCallable($clock??static fn():string=>gmdate(DATE_ATOM));$this->tokenFactory=\Closure::fromCallable($tokenFactory??static fn():string=>bin2hex(random_bytes(32)));
	}

	public function driver():string{return$this->driver;}
	/** @return list<string> */ public function schemaStatements():array{return self::schemaStatementsFor($this->driver,$this->prefix);}

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array{
		$driver=self::driverName($driver);$prefix=self::prefix($prefix);$meta=$prefix.'_meta';$operations=$prefix.'_operations';$leases=$prefix.'_leases';$changes=$prefix.'_changes';
		if($driver==='mysql'){
			return[
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$operations} (id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, operation_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, queue_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, operation_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, revision BIGINT UNSIGNED NOT NULL, available_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, worker VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, fence BIGINT UNSIGNED NOT NULL DEFAULT 0, record_json LONGTEXT NOT NULL, record_bytes INT UNSIGNED NOT NULL, PRIMARY KEY (id), UNIQUE KEY idempotency_lookup (idempotency_hash), KEY reserve_lookup (queue_name, operation_status, available_at, created_at, id), KEY worker_lookup (worker, operation_status)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$leases} (operation_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, worker VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, fence BIGINT UNSIGNED NOT NULL, acquired_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, renewed_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, expires_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (operation_id), KEY expiry_lookup (expires_at, operation_id), CONSTRAINT {$leases}_operation FOREIGN KEY (operation_id) REFERENCES {$operations} (id) ON DELETE CASCADE) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, operation_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, worker VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, fence BIGINT UNSIGNED NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (change_id), KEY operation_lookup (operation_id, change_id)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			];
		}
		if($driver==='pgsql'){
			return[
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
				"CREATE TABLE IF NOT EXISTS {$operations} (id VARCHAR(190) PRIMARY KEY, operation_type VARCHAR(100) NOT NULL, queue_name VARCHAR(100) NOT NULL, operation_status VARCHAR(32) NOT NULL, idempotency_hash CHAR(64) UNIQUE, revision BIGINT NOT NULL CHECK (revision > 0), available_at VARCHAR(64) NOT NULL, created_at VARCHAR(64) NOT NULL, worker VARCHAR(190), fence BIGINT NOT NULL DEFAULT 0 CHECK (fence >= 0), record_json TEXT NOT NULL, record_bytes INTEGER NOT NULL CHECK (record_bytes > 0))",
				"CREATE INDEX IF NOT EXISTS {$operations}_reserve_lookup ON {$operations} (queue_name, operation_status, available_at, created_at, id)",
				"CREATE INDEX IF NOT EXISTS {$operations}_worker_lookup ON {$operations} (worker, operation_status)",
				"CREATE TABLE IF NOT EXISTS {$leases} (operation_id VARCHAR(190) PRIMARY KEY REFERENCES {$operations} (id) ON DELETE CASCADE, worker VARCHAR(190) NOT NULL, token_hash CHAR(64) NOT NULL, fence BIGINT NOT NULL CHECK (fence > 0), acquired_at VARCHAR(64) NOT NULL, renewed_at VARCHAR(64) NOT NULL, expires_at VARCHAR(64) NOT NULL)",
				"CREATE INDEX IF NOT EXISTS {$leases}_expiry_lookup ON {$leases} (expires_at, operation_id)",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, event_type VARCHAR(96) NOT NULL, operation_id VARCHAR(190) NOT NULL, worker VARCHAR(190), fence BIGINT, occurred_at VARCHAR(64) NOT NULL)",
				"CREATE INDEX IF NOT EXISTS {$changes}_operation_lookup ON {$changes} (operation_id, change_id)",
				"INSERT INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.") ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return[
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$operations} (id TEXT NOT NULL PRIMARY KEY CHECK (length(id) BETWEEN 1 AND 190), operation_type TEXT NOT NULL CHECK (length(operation_type) BETWEEN 1 AND 100), queue_name TEXT NOT NULL CHECK (length(queue_name) BETWEEN 1 AND 100), operation_status TEXT NOT NULL CHECK (length(operation_status) BETWEEN 1 AND 32), idempotency_hash TEXT UNIQUE CHECK (idempotency_hash IS NULL OR length(idempotency_hash) = 64), revision INTEGER NOT NULL CHECK (revision > 0), available_at TEXT NOT NULL, created_at TEXT NOT NULL, worker TEXT CHECK (worker IS NULL OR length(worker) BETWEEN 1 AND 190), fence INTEGER NOT NULL DEFAULT 0 CHECK (fence >= 0), record_json TEXT NOT NULL, record_bytes INTEGER NOT NULL CHECK (record_bytes > 0))",
			"CREATE INDEX IF NOT EXISTS {$operations}_reserve_lookup ON {$operations} (queue_name, operation_status, available_at, created_at, id)",
			"CREATE INDEX IF NOT EXISTS {$operations}_worker_lookup ON {$operations} (worker, operation_status)",
			"CREATE TABLE IF NOT EXISTS {$leases} (operation_id TEXT NOT NULL PRIMARY KEY, worker TEXT NOT NULL, token_hash TEXT NOT NULL CHECK (length(token_hash) = 64), fence INTEGER NOT NULL CHECK (fence > 0), acquired_at TEXT NOT NULL, renewed_at TEXT NOT NULL, expires_at TEXT NOT NULL, FOREIGN KEY (operation_id) REFERENCES {$operations} (id) ON DELETE CASCADE)",
			"CREATE INDEX IF NOT EXISTS {$leases}_expiry_lookup ON {$leases} (expires_at, operation_id)",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, operation_id TEXT NOT NULL, worker TEXT, fence INTEGER, occurred_at TEXT NOT NULL)",
			"CREATE INDEX IF NOT EXISTS {$changes}_operation_lookup ON {$changes} (operation_id, change_id)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
		];
	}

	/** @return array{type:string,version:int,driver:string,schema_version:int,statements:int,idempotent:bool,destructive:bool} */
	public function installSchema():array{
		if($this->activeTransaction()){throw$this->storage('transaction_conflict','Panel PDO leased-operation schema installation requires transaction ownership.',true);}
		try{$statements=$this->schemaStatements();foreach($statements as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO schema statement failed.');}}$this->assertSchema(false);return['type'=>'panel_pdo_leased_operation_schema_installation','version'=>1,'driver'=>$this->driver,'schema_version'=>self::SCHEMA_VERSION,'statements'=>count($statements),'idempotent'=>true,'destructive'=>false];}
		catch(PanelOperationStorageException $error){if($error->errorCode()==='schema_incompatible'){throw$error;}throw$this->storage('migration_failed','Panel PDO leased-operation schema migration failed.',true);}
		catch(\Throwable){throw$this->storage('migration_failed','Panel PDO leased-operation schema migration failed.',true);}
	}

	public function create(PanelOperationRecord $record):PanelOperationRecord{
		return$this->transaction(true,function()use($record):PanelOperationRecord{$this->assertSchema(true);$key=$record->idempotencyKey();if($key!==null){$existing=$this->idempotencyRecord($key,true);if($existing!==null){return$existing;}}if($this->operationRow($record->id(),true)!==null){throw new PanelOperationConflict("Panel operation '{$record->id()}' already exists.");}$stored=$record->withRevision(1);$this->insertRecord($stored,0);$this->recordChange('operation.created',$stored->id());return$stored;});
	}

	public function currentTime():string{return$this->now();}
	public function get(string $id):?PanelOperationRecord{return$this->transaction(false,function()use($id):?PanelOperationRecord{$this->assertSchema(false);$row=$this->operationRow($id,false);return$row===null?null:$this->hydrateRecord($row)[0];});}

	public function save(PanelOperationRecord $record,?int $expectedRevision=null):PanelOperationRecord{
		return$this->transaction(true,function()use($record,$expectedRevision):PanelOperationRecord{$this->assertSchema(false);$row=$this->operationRow($record->id(),true);if($row===null){throw new \OutOfBoundsException("Panel operation '{$record->id()}' does not exist.");}[$current,$fence]=$this->hydrateRecord($row);$this->assertRevision($current,$expectedRevision??$record->revision());$stored=$this->persist($current,$record,$fence);$this->recordChange('operation.saved',$stored->id(),$stored->worker(),$fence?:null);return$stored;});
	}

	public function update(string $id,callable $mutator,?int $expectedRevision=null):PanelOperationRecord{
		return$this->transaction(true,function()use($id,$mutator,$expectedRevision):PanelOperationRecord{$this->assertSchema(false);$row=$this->operationRow($id,true);if($row===null){throw new \OutOfBoundsException("Panel operation '{$id}' does not exist.");}[$current,$fence]=$this->hydrateRecord($row);if($expectedRevision!==null){$this->assertRevision($current,$expectedRevision);}$next=$mutator($current);$this->assertMutation($current,$next);$stored=$this->persist($current,$next,$fence);$this->recordChange('operation.updated',$stored->id(),$stored->worker(),$fence?:null);return$stored;});
	}

	public function findByIdempotencyKey(string $key):?PanelOperationRecord{$key=trim($key);if($key===''){return null;}return$this->transaction(false,function()use($key):?PanelOperationRecord{$this->assertSchema(false);return$this->idempotencyRecord($key,false);});}

	public function all(array $criteria=[],int $limit=100,int $offset=0):array{
		$limit=max(1,min(10000,$limit));$offset=max(0,$offset);[$where,$parameters,$impossible]=$this->criteria($criteria);if($impossible){return[];}
		return$this->transaction(false,function()use($criteria,$limit,$offset,$where,$parameters):array{$this->assertSchema(false);$sql="SELECT * FROM {$this->prefix}_operations".($where!==''?' WHERE '.$where:'')." ORDER BY created_at ASC, id ASC LIMIT {$limit} OFFSET {$offset}";$records=[];foreach($this->rows($sql,$parameters)as$row){$record=$this->hydrateRecord($row)[0];if($this->matches($record,$criteria)){$records[]=$record;}}return$records;});
	}

	public function delete(string $id):bool{
		return$this->transaction(true,function()use($id):bool{$this->assertSchema(false);$row=$this->operationRow($id,true);if($row===null){return false;}[$record,$fence]=$this->hydrateRecord($row);if($this->leaseRow($id,true)!==null){throw new PanelOperationConflict("Panel operation '{$id}' has an active worker lease.");}$statement=$this->execute("DELETE FROM {$this->prefix}_operations WHERE id = :id AND revision = :revision AND fence = :fence",['id'=>$id,'revision'=>$record->revision(),'fence'=>$fence]);if($statement->rowCount()!==1){throw$this->corrupt();}$this->recordChange('operation.deleted',$id,$record->worker(),$fence?:null);return true;});
	}

	public function acquireLease(string $id,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation{
		$worker=$this->worker($worker);$ttl=$this->ttl($ttlSeconds);$now=$this->now();$token=$this->token();$expires=$this->plusSeconds($now,$ttl);
		return$this->transaction(true,function()use($id,$worker,$token,$now,$expires):?PanelOperationReservation{$this->assertSchema(false);$row=$this->operationRow($id,true);if($row===null){return null;}[$current,$fence]=$this->hydrateRecord($row);return$this->acquireRecord($current,$fence,$worker,$token,$now,$expires);});
	}

	public function reserveLease(?string $queue=null,string $worker='worker',int $ttlSeconds=60):?PanelOperationReservation{
		$this->recoverExpiredLeases(1000);$queue=$queue!==null&&trim($queue)!==''?$this->queue($queue):null;$worker=$this->worker($worker);$ttl=$this->ttl($ttlSeconds);$now=$this->now();$token=$this->token();$expires=$this->plusSeconds($now,$ttl);$statuses=[PanelOperationStatus::QUEUED,PanelOperationStatus::RETRY_WAIT];
		return$this->transaction(true,function()use($queue,$worker,$now,$token,$expires,$statuses):?PanelOperationReservation{$this->assertSchema(false);$parameters=['queued'=>$statuses[0],'retry_wait'=>$statuses[1],'available_at'=>$now];$where='o.operation_status IN (:queued, :retry_wait) AND o.available_at <= :available_at';if($queue!==null){$where.=' AND o.queue_name = :queue_name';$parameters['queue_name']=$queue;}$sql="SELECT o.* FROM {$this->prefix}_operations o WHERE {$where} AND NOT EXISTS (SELECT 1 FROM {$this->prefix}_leases l WHERE l.operation_id = o.id) ORDER BY o.available_at ASC, o.created_at ASC, o.id ASC LIMIT 1".$this->dialect['reserve_suffix'];$row=$this->row($sql,$parameters);if($row===null){return null;}[$current,$fence]=$this->hydrateRecord($row);return$this->acquireRecord($current,$fence,$worker,$token,$now,$expires);});
	}

	public function inspectLease(PanelOperationLease $lease):PanelOperationReservation{
		$now=$this->now();return$this->transaction(false,function()use($lease,$now):PanelOperationReservation{$this->assertSchema(false);[$record,$state]=$this->validatedLease($lease,$now,false);return new PanelOperationReservation($record,$this->leaseFromState($lease,$state));});
	}

	public function mutateLease(PanelOperationLease $lease,callable $mutator,?int $renewSeconds=null):PanelOperationReservation{
		$now=$this->now();$ttl=$renewSeconds===null?null:$this->ttl($renewSeconds);return$this->transaction(true,function()use($lease,$mutator,$now,$ttl):PanelOperationReservation{$this->assertSchema(false);[$current,$state,$fence]=$this->validatedLease($lease,$now,true);$next=$mutator($current);$this->assertMutation($current,$next);if(!PanelOperationStatus::active($next->status())||$next->worker()!==$lease->worker()){throw new \LogicException('Leased mutations must retain the active worker; use finishLease for lifecycle completion.');}$stored=$this->persist($current,$next,$fence);if($ttl!==null){$state['renewed_at']=$now;$state['expires_at']=$this->plusSeconds($now,$ttl);$this->updateLeaseWindow($lease,$state);}$this->recordChange('operation.lease_mutated',$stored->id(),$lease->worker(),$lease->fence());return new PanelOperationReservation($stored,$this->leaseFromState($lease,$state));});
	}

	public function renewLease(PanelOperationLease $lease,int $ttlSeconds=60):PanelOperationReservation{$now=$this->now();return$this->mutateLease($lease,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->heartbeat($now),$ttlSeconds);}

	public function finishLease(PanelOperationLease $lease,callable $mutator):PanelOperationRecord{
		$now=$this->now();return$this->transaction(true,function()use($lease,$mutator,$now):PanelOperationRecord{$this->assertSchema(false);[$current,,$fence]=$this->validatedLease($lease,$now,true);$next=$mutator($current);$this->assertMutation($current,$next);if(PanelOperationStatus::active($next->status())||$next->worker()!==null){throw new \LogicException('Finishing a worker lease must leave a non-active record without a worker.');}$stored=$this->persist($current,$next,$fence);$this->deleteLease($lease->operationId(),$lease->fence());$this->recordChange('operation.lease_finished',$stored->id(),$lease->worker(),$lease->fence());return$stored;});
	}

	public function releaseLease(PanelOperationLease $lease,?int $delaySeconds=null):PanelOperationRecord{$now=$this->now();return$this->finishLease($lease,static function(PanelOperationRecord $current)use($delaySeconds,$now):PanelOperationRecord{if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){return$current->cancel($now);}if($current->status()===PanelOperationStatus::PAUSE_REQUESTED){return$current->markPaused($now);}return$current->canRetry()?$current->retry($delaySeconds,$now):$current->fail('Worker released its final attempt.',$now);});}

	public function recoverExpiredLeases(int $limit=100):array{
		$limit=max(1,min(10000,$limit));$now=$this->now();$ids=$this->transaction(false,function()use($now,$limit):array{$this->assertSchema(false);$rows=$this->rows("SELECT operation_id FROM {$this->prefix}_leases WHERE expires_at <= :expires_at ORDER BY operation_id ASC LIMIT {$limit}",['expires_at'=>$now]);return array_map(static fn(array $row):string=>(string)($row['operation_id']??''),$rows);});$recovered=[];
		foreach($ids as$id){$record=$this->recoverExpiredLease($id,$now);if($record!==null){$recovered[]=$record;}}return$recovered;
	}

	public function activeLeaseManifests():array{
		$now=$this->now();return$this->transaction(false,function()use($now):array{$this->assertSchema(false);$rows=$this->rows("SELECT l.operation_id, l.worker AS lease_worker, l.token_hash, l.fence AS lease_fence, l.acquired_at, l.renewed_at, l.expires_at, o.* FROM {$this->prefix}_leases l INNER JOIN {$this->prefix}_operations o ON o.id = l.operation_id WHERE l.expires_at > :expires_at ORDER BY l.operation_id ASC, l.fence ASC",['expires_at'=>$now]);$out=[];foreach($rows as$row){[$record,$fence]=$this->hydrateRecord($row);$state=$this->leaseStateFromRow($row);if(!PanelOperationStatus::active($record->status())||$record->worker()!==$state['worker']||$fence!==$state['fence']){throw$this->corrupt();}$out[]=['operation_id'=>$record->id(),'worker'=>$state['worker'],'fence'=>$state['fence'],'acquired_at'=>$state['acquired_at'],'renewed_at'=>$state['renewed_at'],'expires_at'=>$state['expires_at'],'record_revision'=>$record->revision()];}return$out;});
	}

	/** @return array{cursor:int,oldest_cursor:int,reset_required:bool,changes:list<array<string,mixed>>,snapshot:?array<string,mixed>} */
	public function changesSince(int $cursor=0,int $limit=100):array{
		$cursor=max(0,$cursor);$limit=max(1,min(1000,$limit));return$this->transaction(false,function()use($cursor,$limit):array{$this->assertSchema(false);$bounds=$this->row("SELECT MIN(change_id) AS oldest, MAX(change_id) AS current FROM {$this->prefix}_changes");$oldest=$bounds===null||$bounds['oldest']===null?0:$this->integer($bounds['oldest'],0);$current=$bounds===null||$bounds['current']===null?0:$this->integer($bounds['current'],0);$reset=$cursor>$current||($cursor>0&&$oldest>0&&$cursor<$oldest-1);$changes=[];if(!$reset){foreach($this->rows("SELECT change_id, event_type, operation_id, worker, fence, occurred_at FROM {$this->prefix}_changes WHERE change_id > :cursor ORDER BY change_id ASC LIMIT {$limit}",['cursor'=>$cursor])as$row){$changes[]=$this->hydrateChange($row);}}$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;return['cursor'=>$next,'oldest_cursor'=>$oldest,'reset_required'=>$reset,'changes'=>$changes,'snapshot'=>$reset?['type'=>'panel_pdo_leased_operation_reset','schema_version'=>1,'cursor'=>$current,'resync'=>'enumerate_all']:null];});
	}

	public function cursor():int{$feed=$this->changesSince(PHP_INT_MAX,1);return(int)($feed['snapshot']['cursor']??$feed['cursor']);}
	/** @return array<string,mixed> */
	public function manifest():array{return['type'=>'panel_pdo_leased_operation_store','version'=>1,'adapter'=>'pdo','driver'=>$this->driver,'durable'=>true,'distributed'=>true,'cross_process'=>true,'shared_database'=>true,'optimistic_revisions'=>true,'idempotent_create'=>true,'idempotency_lookup_digest'=>'sha256_domain_separated','raw_idempotency_key_in_record'=>true,'leases'=>true,'lease_renewal'=>true,'expiry_recovery'=>true,'fencing'=>true,'reservation_skip_locked'=>$this->driver!=='sqlite','lease_token_digest'=>'sha256_domain_separated','raw_lease_tokens_stored'=>false,'change_feed'=>true,'change_feed_payloads_stored'=>false,'change_retention'=>$this->changeRetention,'maximum_record_bytes'=>$this->maximumRecordBytes,'schema_version'=>self::SCHEMA_VERSION,'schema_migration'=>'explicit_idempotent','automatic_schema_mutation'=>false,'transaction_ownership_required'=>true,'transaction_retries'=>$this->transactionRetries,'retry_delay_microseconds'=>$this->retryDelayMicroseconds,'delivery'=>'at_least_once','exactly_once'=>false,'connection_details_serialized'=>false,'credentials_serialized'=>false,'table_prefix_serialized'=>false,'sql_serialized'=>false,'live_counts_queried'=>false];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return$this->manifest();}

	/** @return array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string,reserve_suffix:string} */
	public static function dialectPlanFor(string $driver):array{$driver=self::driverName($driver);return['write_begin'=>$driver==='sqlite'?'BEGIN IMMEDIATE':null,'read_before'=>$driver==='mysql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'read_after'=>$driver==='pgsql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'lock_suffix'=>$driver==='sqlite'?'':' FOR UPDATE','reserve_suffix'=>$driver==='sqlite'?'':' FOR UPDATE SKIP LOCKED'];}

	private function acquireRecord(PanelOperationRecord $current,int $fence,string $worker,string $token,string $now,string $expires):?PanelOperationReservation{
		if($this->leaseRow($current->id(),true)!==null){return null;}if(!in_array($current->status(),[PanelOperationStatus::QUEUED,PanelOperationStatus::RETRY_WAIT],true)||strcmp($current->availableAt(),$now)>0){return null;}if(!$current->canRetry()&&$current->attempt()>0){return null;}if($fence===PHP_INT_MAX){throw$this->storage('fence_exhausted','Panel operation lease fence is exhausted.');}$nextFence=$fence+1;$lease=PanelOperationLease::make($current->id(),$worker,$token,$nextFence,$now,$expires);$started=$this->persist($current,$current->start($worker,$now),$fence,$nextFence);$this->execute("INSERT INTO {$this->prefix}_leases (operation_id, worker, token_hash, fence, acquired_at, renewed_at, expires_at) VALUES (:operation_id, :worker, :token_hash, :fence, :acquired_at, :renewed_at, :expires_at)",['operation_id'=>$current->id(),'worker'=>$worker,'token_hash'=>$this->tokenHash($token),'fence'=>$nextFence,'acquired_at'=>$lease->acquiredAt(),'renewed_at'=>$lease->renewedAt(),'expires_at'=>$lease->expiresAt()]);$this->recordChange('operation.lease_acquired',$current->id(),$worker,$nextFence);return new PanelOperationReservation($started,$lease);
	}

	private function recoverExpiredLease(string $id,string $now):?PanelOperationRecord{
		return$this->transaction(true,function()use($id,$now):?PanelOperationRecord{$this->assertSchema(false);$row=$this->operationRow($id,true);if($row===null){throw$this->corrupt();}[$current,$fence]=$this->hydrateRecord($row);$lease=$this->leaseRow($id,true);if($lease===null||strcmp((string)($lease['expires_at']??''),$now)>0){return null;}$state=$this->leaseStateFromRow($lease);if($state['fence']!==$fence){throw$this->corrupt();}$this->deleteLease($id,$state['fence']);if(!PanelOperationStatus::active($current->status())){$this->recordChange('operation.lease_recovered',$id,$state['worker'],$state['fence']);return null;}if($current->worker()!==$state['worker']){throw$this->corrupt();}if($current->status()===PanelOperationStatus::CANCEL_REQUESTED){$next=$current->cancel($now);}elseif($current->status()===PanelOperationStatus::PAUSE_REQUESTED){$next=$current->markPaused($now);}elseif($current->canRetry()){$next=$current->retry(0,$now);}else{$next=$current->fail('Worker lease expired and no retry attempts remain.',$now);}$stored=$this->persist($current,$next,$fence);$this->recordChange('operation.lease_recovered',$id,$state['worker'],$state['fence']);return$stored;});
	}

	/** @return array{0:PanelOperationRecord,1:array{worker:string,token_hash:string,fence:int,acquired_at:string,renewed_at:string,expires_at:string},2:int} */
	private function validatedLease(PanelOperationLease $lease,string $now,bool $lock):array{
		$row=$this->operationRow($lease->operationId(),$lock);if($row===null){throw new PanelOperationLeaseLost($lease->operationId());}[$record,$fence]=$this->hydrateRecord($row);$leaseRow=$this->leaseRow($lease->operationId(),$lock);if($leaseRow===null){throw new PanelOperationLeaseLost($lease->operationId());}$state=$this->leaseStateFromRow($leaseRow);$matches=$state['worker']===$lease->worker()&&$state['fence']===$lease->fence()&&$fence===$lease->fence()&&hash_equals($state['token_hash'],$this->tokenHash($lease->token()));if(!$matches){throw new PanelOperationLeaseLost($lease->operationId(),'Operation lease was superseded by another worker.');}if(strcmp($state['expires_at'],$now)<=0){throw new PanelOperationLeaseLost($lease->operationId(),'Operation lease expired.');}if(!PanelOperationStatus::active($record->status())||$record->worker()!==$lease->worker()){throw new PanelOperationLeaseLost($lease->operationId(),'Operation record no longer belongs to this worker.');}return[$record,$state,$fence];
	}

	/** @param array{worker:string,token_hash:string,fence:int,acquired_at:string,renewed_at:string,expires_at:string} $state */
	private function leaseFromState(PanelOperationLease $lease,array $state):PanelOperationLease{return PanelOperationLease::make($lease->operationId(),$lease->worker(),$lease->token(),$lease->fence(),$state['acquired_at'],$state['expires_at'],$state['renewed_at']);}
	/** @param array{worker:string,token_hash:string,fence:int,acquired_at:string,renewed_at:string,expires_at:string} $state */
	private function updateLeaseWindow(PanelOperationLease $lease,array $state):void{$statement=$this->execute("UPDATE {$this->prefix}_leases SET renewed_at = :renewed_at, expires_at = :expires_at WHERE operation_id = :operation_id AND fence = :fence AND token_hash = :token_hash",['renewed_at'=>$state['renewed_at'],'expires_at'=>$state['expires_at'],'operation_id'=>$lease->operationId(),'fence'=>$lease->fence(),'token_hash'=>$state['token_hash']]);if($statement->rowCount()===1){return;}$current=$this->leaseRow($lease->operationId(),true);if($current===null){throw new PanelOperationLeaseLost($lease->operationId());}$actual=$this->leaseStateFromRow($current);if($actual!==$state){throw new PanelOperationLeaseLost($lease->operationId());}}
	private function deleteLease(string $id,int $fence):void{if($this->execute("DELETE FROM {$this->prefix}_leases WHERE operation_id = :operation_id AND fence = :fence",['operation_id'=>$id,'fence'=>$fence])->rowCount()!==1){throw new PanelOperationLeaseLost($id);}}

	/** @param array<string,mixed> $row @return array{worker:string,token_hash:string,fence:int,acquired_at:string,renewed_at:string,expires_at:string} */
	private function leaseStateFromRow(array $row):array{
		$worker=$row['lease_worker']??$row['worker']??null;$fence=$row['lease_fence']??$row['fence']??null;foreach(['token_hash','acquired_at','renewed_at','expires_at']as$key){if(!isset($row[$key])||!is_string($row[$key])){throw$this->corrupt();}}if(!is_string($worker)||$worker===''||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$worker)!==1||preg_match('/^[a-f0-9]{64}$/D',$row['token_hash'])!==1){throw$this->corrupt();}$state=['worker'=>$worker,'token_hash'=>$row['token_hash'],'fence'=>$this->integer($fence,1),'acquired_at'=>$row['acquired_at'],'renewed_at'=>$row['renewed_at'],'expires_at'=>$row['expires_at']];try{PanelOperationLease::make((string)($row['operation_id']??$row['id']??''),$worker,str_repeat('x',32),$state['fence'],$state['acquired_at'],$state['expires_at'],$state['renewed_at']);}catch(\Throwable){throw$this->corrupt();}return$state;
	}

	/** @return array<string,mixed>|null */ private function operationRow(string $id,bool $lock):?array{return$this->row("SELECT * FROM {$this->prefix}_operations WHERE id = :id".($lock?$this->dialect['lock_suffix']:''),['id'=>$id]);}
	/** @return array<string,mixed>|null */ private function leaseRow(string $id,bool $lock):?array{return$this->row("SELECT operation_id, worker, token_hash, fence, acquired_at, renewed_at, expires_at FROM {$this->prefix}_leases WHERE operation_id = :operation_id".($lock?$this->dialect['lock_suffix']:''),['operation_id'=>$id]);}

	private function idempotencyRecord(string $key,bool $lock):?PanelOperationRecord{
		$row=$this->row("SELECT * FROM {$this->prefix}_operations WHERE idempotency_hash = :idempotency_hash".($lock?$this->dialect['lock_suffix']:''),['idempotency_hash'=>$this->idempotencyHash($key)]);if($row===null){return null;}$record=$this->hydrateRecord($row)[0];if($record->idempotencyKey()===null||!hash_equals($record->idempotencyKey(),$key)){throw$this->corrupt();}return$record;
	}

	private function insertRecord(PanelOperationRecord $record,int $fence):void{
		[$json,$bytes]=$this->encodeRecord($record);try{$this->execute("INSERT INTO {$this->prefix}_operations (id, operation_type, queue_name, operation_status, idempotency_hash, revision, available_at, created_at, worker, fence, record_json, record_bytes) VALUES (:id, :operation_type, :queue_name, :operation_status, :idempotency_hash, :revision, :available_at, :created_at, :worker, :fence, :record_json, :record_bytes)",$this->recordParameters($record,$fence,$json,$bytes));}catch(\PDOException $error){if($this->duplicate($error)){throw new PanelOperationConflict("Panel operation '{$record->id()}' conflicts with an existing id or idempotency key.");}throw$error;}
	}

	private function persist(PanelOperationRecord $current,PanelOperationRecord $next,int $fence,?int $nextFence=null):PanelOperationRecord{
		$this->assertMutation($current,$next);$newKey=$next->idempotencyKey();if($newKey!==null){$owner=$this->idempotencyRecord($newKey,true);if($owner!==null&&$owner->id()!==$current->id()){throw new PanelOperationConflict("Panel operation idempotency key already belongs to {$owner->id()}.");}}$stored=$next->withRevision($current->revision()+1);[$json,$bytes]=$this->encodeRecord($stored);$nextFence??=$fence;$parameters=$this->recordParameters($stored,$nextFence,$json,$bytes)+['expected_revision'=>$current->revision(),'expected_fence'=>$fence];try{$statement=$this->execute("UPDATE {$this->prefix}_operations SET operation_type = :operation_type, queue_name = :queue_name, operation_status = :operation_status, idempotency_hash = :idempotency_hash, revision = :revision, available_at = :available_at, created_at = :created_at, worker = :worker, fence = :fence, record_json = :record_json, record_bytes = :record_bytes WHERE id = :id AND revision = :expected_revision AND fence = :expected_fence",$parameters);}catch(\PDOException $error){if($this->duplicate($error)){throw new PanelOperationConflict('Panel operation idempotency key belongs to another record.');}throw$error;}if($statement->rowCount()!==1){throw new PanelOperationConflict("Panel operation '{$current->id()}' changed concurrently.");}return$stored;
	}

	/** @return array{0:string,1:int} */ private function encodeRecord(PanelOperationRecord $record):array{try{$json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}catch(\Throwable){throw$this->storage('record_invalid','Panel operation record could not be encoded.');}$bytes=strlen($json);if($bytes<1||$bytes>$this->maximumRecordBytes){throw$this->storage('record_too_large','Panel operation record exceeds the configured byte bound.');}return[$json,$bytes];}
	/** @return array<string,null|int|string> */ private function recordParameters(PanelOperationRecord $record,int $fence,string $json,int $bytes):array{return['id'=>$record->id(),'operation_type'=>$record->type(),'queue_name'=>$record->queue(),'operation_status'=>$record->status(),'idempotency_hash'=>$record->idempotencyKey()===null?null:$this->idempotencyHash($record->idempotencyKey()),'revision'=>$record->revision(),'available_at'=>$record->availableAt(),'created_at'=>$record->createdAt(),'worker'=>$record->worker(),'fence'=>$fence,'record_json'=>$json,'record_bytes'=>$bytes];}

	/** @param array<string,mixed> $row @return array{0:PanelOperationRecord,1:int} */
	private function hydrateRecord(array $row):array{
		foreach(['id','operation_type','queue_name','operation_status','available_at','created_at','record_json']as$key){if(!isset($row[$key])||!is_string($row[$key])){throw$this->corrupt();}}$bytes=$this->integer($row['record_bytes']??null,1);if($bytes!==strlen($row['record_json'])||$bytes>$this->maximumRecordBytes){throw$this->corrupt();}$revision=$this->integer($row['revision']??null,1);$fence=$this->integer($row['fence']??null,0);try{$data=json_decode($row['record_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data)){throw new \UnexpectedValueException('Invalid record envelope.');}$record=PanelOperationRecord::fromArray($data);}catch(\Throwable){throw$this->corrupt();}$worker=$row['worker']??null;$hash=$row['idempotency_hash']??null;if($worker!==null&&!is_string($worker)){throw$this->corrupt();}if($hash!==null&&(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)){throw$this->corrupt();}$expectedHash=$record->idempotencyKey()===null?null:$this->idempotencyHash($record->idempotencyKey());if($record->id()!==$row['id']||$record->type()!==$row['operation_type']||$record->queue()!==$row['queue_name']||$record->status()!==$row['operation_status']||$record->revision()!==$revision||$record->availableAt()!==$row['available_at']||$record->createdAt()!==$row['created_at']||$record->worker()!==$worker||$expectedHash!==$hash){throw$this->corrupt();}return[$record,$fence];
	}

	private function recordChange(string $type,string $id,?string $worker=null,?int $fence=null):void{
		$parameters=['event_type'=>$type,'operation_id'=>$id,'worker'=>$worker,'fence'=>$fence,'occurred_at'=>$this->now()];$sql="INSERT INTO {$this->prefix}_changes (event_type, operation_id, worker, fence, occurred_at) VALUES (:event_type, :operation_id, :worker, :fence, :occurred_at)";if($this->driver==='pgsql'){$value=$this->execute($sql.' RETURNING change_id',$parameters)->fetchColumn();}else{$this->execute($sql,$parameters);$value=$this->pdo->lastInsertId();}$cursor=$this->integer($value,1);$cutoff=$cursor-$this->changeRetention;if($cutoff>0){$this->execute("DELETE FROM {$this->prefix}_changes WHERE change_id <= :cutoff",['cutoff'=>$cutoff]);}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function hydrateChange(array $row):array{
		foreach(['event_type','operation_id','occurred_at']as$key){if(!isset($row[$key])||!is_string($row[$key])||$row[$key]===''){throw$this->corrupt();}}$change=['cursor'=>$this->integer($row['change_id']??null,1),'type'=>$row['event_type'],'operation_id'=>$row['operation_id'],'occurred_at'=>$row['occurred_at']];if(($row['worker']??null)!==null){if(!is_string($row['worker'])){throw$this->corrupt();}$change['worker']=$row['worker'];}if(($row['fence']??null)!==null){$change['fence']=$this->integer($row['fence'],1);}return$change;
	}

	/** @param array<string,mixed> $criteria @return array{0:string,1:array<string,null|int|string>,2:bool} */
	private function criteria(array $criteria):array{
		$mapping=['id'=>'id','type'=>'operation_type','queue'=>'queue_name','status'=>'operation_status','idempotency_key'=>'idempotency_hash','worker'=>'worker'];$clauses=[];$parameters=[];$counter=0;foreach($criteria as$key=>$expected){if(!is_string($key)||!isset($mapping[$key])){throw new \InvalidArgumentException("Unsupported operation criterion '{$key}'.");}$values=is_array($expected)?array_values($expected):[$expected];if($values===[]){return['1 = 0',[],true];}$nonnull=[];$hasNull=false;foreach($values as$value){if($value===null){$hasNull=true;continue;}if(!is_string($value)){return['1 = 0',[],true];}$counter++;$name='criterion_'.$counter;$parameters[$name]=$key==='idempotency_key'?$this->idempotencyHash($value):$value;$nonnull[]=':'.$name;}$parts=[];if($nonnull!==[]){$parts[]=$mapping[$key].' IN ('.implode(', ',$nonnull).')';}if($hasNull){$parts[]=$mapping[$key].' IS NULL';}if($parts===[]){return['1 = 0',[],true];}$clauses[]='('.implode(' OR ',$parts).')';}return[implode(' AND ',$clauses),$parameters,false];
	}

	/** @param array<string,mixed> $criteria */
	private function matches(PanelOperationRecord $record,array $criteria):bool{foreach($criteria as$key=>$expected){$actual=match($key){'id'=>$record->id(),'type'=>$record->type(),'queue'=>$record->queue(),'status'=>$record->status(),'idempotency_key'=>$record->idempotencyKey(),'worker'=>$record->worker(),default=>throw new \InvalidArgumentException("Unsupported operation criterion '{$key}'.")};if(is_array($expected)){if(!in_array($actual,$expected,true)){return false;}}elseif($actual!==$expected){return false;}}return true;}
	private function assertRevision(PanelOperationRecord $record,int $expected):void{if($record->revision()!==$expected){throw new PanelOperationConflict("Panel operation '{$record->id()}' revision conflict: expected {$expected}, found {$record->revision()}.");}}
	private function assertMutation(PanelOperationRecord $current,mixed $next):void{if(!$next instanceof PanelOperationRecord){throw new \UnexpectedValueException('Panel operation mutator must return PanelOperationRecord.');}if($next->id()!==$current->id()){throw new \LogicException('Panel operation mutator cannot change the record id.');}}

	private function assertSchema(bool $lock):void{
		try{$row=$this->row("SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));}catch(\PDOException $error){if($this->missingRelation($error)){throw$this->storage('schema_required','Panel PDO leased-operation schema is not installed.');}throw$error;}if($row===null){throw$this->storage('schema_required','Panel PDO leased-operation schema is not installed.');}$version=$this->integer($row['schema_version']??null,1);if($version!==self::SCHEMA_VERSION){throw$this->storage('schema_incompatible','Panel PDO leased-operation schema version is incompatible.');}
	}

	private function transaction(bool $write,callable $callback):mixed{
		if($this->activeTransaction()){throw$this->storage('transaction_conflict','Panel PDO leased-operation store requires transaction ownership.',true);}for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){try{$this->begin($write);$result=$callback();$this->commit();return$result;}catch(\PDOException $error){$this->rollback();if($attempt<$this->transactionRetries&&$this->transient($error)){if($this->retryDelayMicroseconds>0){usleep(min(100000,$this->retryDelayMicroseconds*($attempt+1)));}continue;}throw$this->storage('storage_unavailable','Panel PDO leased-operation storage is unavailable.',true);}catch(\Throwable $error){$this->rollback();throw$error;}}throw$this->storage('storage_unavailable','Panel PDO leased-operation storage is unavailable.',true);
	}

	private function begin(bool $write):void{foreach($write?[]:$this->dialect['read_before']as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}}if($write&&$this->dialect['write_begin']!==null){if($this->pdo->exec($this->dialect['write_begin'])===false){throw new \RuntimeException('PDO transaction begin failed.');}$this->manualSqliteWriteTransaction=true;}elseif(!$this->pdo->beginTransaction()){throw new \RuntimeException('PDO transaction begin failed.');}foreach($write?[]:$this->dialect['read_after']as$sql){if($this->pdo->exec($sql)===false){throw new \RuntimeException('PDO transaction configuration failed.');}}}
	private function commit():void{if($this->manualSqliteWriteTransaction){if($this->pdo->exec('COMMIT')===false){throw new \RuntimeException('PDO commit failed.');}$this->manualSqliteWriteTransaction=false;return;}if(!$this->pdo->commit()){throw new \RuntimeException('PDO commit failed.');}}
	private function rollback():void{try{if($this->manualSqliteWriteTransaction){$this->pdo->exec('ROLLBACK');}elseif($this->pdo->inTransaction()){$this->pdo->rollBack();}}catch(\Throwable){}finally{$this->manualSqliteWriteTransaction=false;}}
	private function activeTransaction():bool{return$this->manualSqliteWriteTransaction||$this->pdo->inTransaction();}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function execute(string $sql,array $parameters=[]):\PDOStatement{$statement=$this->pdo->prepare($sql);if(!$statement instanceof \PDOStatement){throw new \RuntimeException('PDO prepare failed.');}foreach($parameters as$name=>$value){$type=match(true){$value===null=>\PDO::PARAM_NULL,is_bool($value)=>\PDO::PARAM_BOOL,is_int($value)=>\PDO::PARAM_INT,default=>\PDO::PARAM_STR};$statement->bindValue(':'.$name,$value,$type);}if(!$statement->execute()){throw new \RuntimeException('PDO execute failed.');}return$statement;}
	/** @param array<string,null|bool|int|float|string> $parameters @return array<string,mixed>|null */ private function row(string $sql,array $parameters=[]):?array{$row=$this->execute($sql,$parameters)->fetch(\PDO::FETCH_ASSOC);if($row===false){return null;}if(!is_array($row)||array_is_list($row)){throw$this->corrupt();}return$row;}
	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */ private function rows(string $sql,array $parameters=[]):array{$rows=$this->execute($sql,$parameters)->fetchAll(\PDO::FETCH_ASSOC);if(!is_array($rows)){throw$this->corrupt();}foreach($rows as$row){if(!is_array($row)||array_is_list($row)){throw$this->corrupt();}}return array_values($rows);}
	private function integer(mixed $value,int $minimum):int{if(is_int($value)){$number=$value;}elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){$number=(int)$value;if((string)$number!==$value){throw$this->corrupt();}}else{throw$this->corrupt();}if($number<$minimum){throw$this->corrupt();}return$number;}

	private function now():string{$value=($this->clock)();try{if($value instanceof \DateTimeInterface){$date=\DateTimeImmutable::createFromInterface($value);}elseif(is_int($value)){$date=new \DateTimeImmutable('@'.$value);}else{$date=new \DateTimeImmutable((string)$value);}return$date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);}catch(\Throwable){throw new \UnexpectedValueException('Panel PDO leased-operation clock returned an invalid time.');}}
	private function token():string{$token=($this->tokenFactory)();if(!is_string($token)){throw new \UnexpectedValueException('Panel operation lease token factory must return a string.');}if(strlen($token)<32||strlen($token)>512||str_contains($token,"\0")){throw new \UnexpectedValueException('Panel operation lease token factory returned an unsafe bearer proof.');}return$token;}
	private function plusSeconds(string $time,int $seconds):string{return(new \DateTimeImmutable($time))->modify('+'.$seconds.' seconds')->format(DATE_ATOM);}
	private function ttl(int $seconds):int{return max(5,min(3600,$seconds));}
	private function worker(string $worker):string{$worker=trim($worker);if($worker===''||strlen($worker)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$worker)!==1){throw new \InvalidArgumentException('Panel operation worker id must be a safe identifier.');}return$worker;}
	private function queue(string $queue):string{$queue=strtolower(trim($queue));$queue=preg_replace('/[^a-z0-9]+/','_',$queue)??'';return trim($queue,'_')?:'default';}
	private function idempotencyHash(string $key):string{return hash('sha256',"panel-operation-idempotency-v1\0".$key);}
	private function tokenHash(string $token):string{return hash('sha256',"panel-operation-lease-token-v1\0".$token);}

	private function transient(\PDOException $error):bool{$state=strtoupper((string)$error->getCode());$info=$error->errorInfo;$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';return in_array($state,['40001','40P01','55P03'],true)||($this->driver==='sqlite'&&($native==='5'||$native==='6'||str_contains(strtolower($error->getMessage()),'locked')))||($this->driver==='mysql'&&in_array($native,['1205','1213'],true));}
	private function duplicate(\PDOException $error):bool{$state=strtoupper((string)$error->getCode());$info=$error->errorInfo;$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';return in_array($state,['23000','23505'],true)||in_array($native,['19','1062','2067'],true)||str_contains(strtolower($error->getMessage()),'unique constraint');}
	private function missingRelation(\PDOException $error):bool{$state=strtoupper((string)$error->getCode());$info=$error->errorInfo;$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';$message=strtolower($error->getMessage());return in_array($state,['42P01','42S02'],true)||in_array($native,['1146'],true)||str_contains($message,'no such table');}
	private function corrupt():PanelOperationStorageException{return$this->storage('storage_corrupt','Panel PDO leased-operation storage failed integrity validation.');}
	private function storage(string $code,string $message,bool $retryable=false):PanelOperationStorageException{return new PanelOperationStorageException($code,$message,$retryable);}
	private static function driverName(string $driver):string{$driver=strtolower(trim($driver));if(!in_array($driver,['mysql','pgsql','sqlite'],true)){throw new \InvalidArgumentException('Panel PDO leased-operation store supports mysql, pgsql, and sqlite only.');}return$driver;}
	private static function prefix(string $prefix):string{$prefix=strtolower(trim($prefix));if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D',$prefix)!==1){throw new \InvalidArgumentException('Panel PDO leased-operation table prefix is invalid.');}return$prefix;}
	/** @param array<string,mixed> $options */ private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int{$value=$options[$name]??$default;if(!is_int($value)||$value<$minimum||$value>$maximum){throw new \InvalidArgumentException("Panel PDO leased-operation option '{$name}' is outside its supported bound.");}return$value;}
}
