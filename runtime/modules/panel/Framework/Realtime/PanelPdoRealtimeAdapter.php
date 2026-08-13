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
 * Durable shared-SQL realtime publication, replay, and connect-intent nonce
 * adapter. Schema installation is always an explicit host migration.
 */
final class PanelPdoRealtimeAdapter implements PanelRealtimeBroker, PanelRealtimePublisher, PanelRealtimeSubscriptionIntentReplayPolicy {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_realtime';
	private const OPTION_NAMES=['table_prefix','retained_events_per_stream','maximum_streams','maximum_event_bytes','maximum_replay_entries','replay_retention_grace_seconds','transaction_retries'];
	private readonly string $driver;
	private readonly string $prefix;
	private readonly int $retainedEvents;
	private readonly int $maximumStreams;
	private readonly int $maximumEventBytes;
	private readonly int $maximumReplayEntries;
	private readonly int $retentionGraceSeconds;
	private readonly int $transactionRetries;
	private readonly \Closure $clock;
	private bool $manualSqliteWriteTransaction=false;
	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly \PDO $pdo, array $options=[], ?callable $clock=null) {
		foreach(array_keys($options) as $name){ if(!is_string($name) || !in_array($name,self::OPTION_NAMES,true)){ throw new \InvalidArgumentException('Panel PDO realtime adapter options contain an unsupported name.'); } }
		try{ $driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))); }catch(\Throwable $error){ throw new \InvalidArgumentException('Panel PDO realtime adapter could not determine its driver.',0,$error); }
		$this->driver=self::driverName($driver); $this->dialect=self::dialectPlanFor($driver);
		$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->retainedEvents=self::option($options,'retained_events_per_stream',1024,1,100000);
		$this->maximumStreams=self::option($options,'maximum_streams',100000,1,1000000);
		$this->maximumEventBytes=self::option($options,'maximum_event_bytes',196608,1024,1048576);
		$this->maximumReplayEntries=self::option($options,'maximum_replay_entries',100000,1,1000000);
		$this->retentionGraceSeconds=self::option($options,'replay_retention_grace_seconds',60,0,300);
		$this->transactionRetries=self::option($options,'transaction_retries',3,0,10);
		$this->clock=\Closure::fromCallable($clock??static fn():int=>time());
	}

	public function driver(): string { return $this->driver; }

	/** @return list<string> */
	public function schemaStatements(): array { return self::schemaStatementsFor($this->driver,$this->prefix); }

	/**
	 * Returns an auditable, idempotent schema plan without touching a database.
	 * The validated prefix is intentionally absent from public manifests.
	 *
	 * @return list<string>
	 */
	public static function schemaStatementsFor(string $driver, string $prefix=self::DEFAULT_PREFIX): array {
		$driver=self::driverName($driver); $prefix=self::prefix($prefix);
		$meta=$prefix.'_meta'; $streams=$prefix.'_streams'; $events=$prefix.'_events'; $nonces=$prefix.'_nonces';
		if($driver==='mysql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, stream_count BIGINT UNSIGNED NOT NULL DEFAULT 0, nonce_count BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$streams} (stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, head BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (stream_key)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$events} (stream_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, sequence BIGINT UNSIGNED NOT NULL, channel VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, topic VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, event_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, payload_json LONGTEXT NOT NULL, metadata_json LONGTEXT NOT NULL, wire_bytes INT UNSIGNED NOT NULL, PRIMARY KEY (stream_key, sequence), KEY topic_lookup (stream_key, topic, sequence), CONSTRAINT {$events}_stream FOREIGN KEY (stream_key) REFERENCES {$streams} (stream_key) ON DELETE CASCADE) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (nonce_hash), KEY expiry_lookup (expires_at, nonce_hash)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version, stream_count, nonce_count) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0)",
			];
		}
		if($driver==='pgsql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0), stream_count BIGINT NOT NULL DEFAULT 0 CHECK (stream_count >= 0), nonce_count BIGINT NOT NULL DEFAULT 0 CHECK (nonce_count >= 0))",
				"CREATE TABLE IF NOT EXISTS {$streams} (stream_key CHAR(64) PRIMARY KEY, head BIGINT NOT NULL CHECK (head >= 0), updated_at BIGINT NOT NULL CHECK (updated_at >= 0))",
				"CREATE TABLE IF NOT EXISTS {$events} (stream_key CHAR(64) NOT NULL REFERENCES {$streams} (stream_key) ON DELETE CASCADE, sequence BIGINT NOT NULL CHECK (sequence > 0), channel VARCHAR(96) NOT NULL, topic VARCHAR(96) NOT NULL, event_type VARCHAR(96) NOT NULL, occurred_at VARCHAR(64) NOT NULL, payload_json TEXT NOT NULL, metadata_json TEXT NOT NULL, wire_bytes INTEGER NOT NULL CHECK (wire_bytes > 0), PRIMARY KEY (stream_key, sequence))",
				"CREATE INDEX IF NOT EXISTS {$events}_topic_lookup ON {$events} (stream_key, topic, sequence)",
				"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash CHAR(64) PRIMARY KEY, expires_at BIGINT NOT NULL CHECK (expires_at >= 0), created_at BIGINT NOT NULL CHECK (created_at >= 0))",
				"CREATE INDEX IF NOT EXISTS {$nonces}_expiry_lookup ON {$nonces} (expires_at, nonce_hash)",
				"INSERT INTO {$meta} (singleton, schema_version, stream_count, nonce_count) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0) ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return [
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0), stream_count INTEGER NOT NULL DEFAULT 0 CHECK (stream_count >= 0), nonce_count INTEGER NOT NULL DEFAULT 0 CHECK (nonce_count >= 0))",
			"CREATE TABLE IF NOT EXISTS {$streams} (stream_key TEXT NOT NULL PRIMARY KEY CHECK (length(stream_key) = 64), head INTEGER NOT NULL CHECK (head >= 0), updated_at INTEGER NOT NULL CHECK (updated_at >= 0))",
			"CREATE TABLE IF NOT EXISTS {$events} (stream_key TEXT NOT NULL, sequence INTEGER NOT NULL CHECK (sequence > 0), channel TEXT NOT NULL, topic TEXT NOT NULL, event_type TEXT NOT NULL, occurred_at TEXT NOT NULL, payload_json TEXT NOT NULL, metadata_json TEXT NOT NULL, wire_bytes INTEGER NOT NULL CHECK (wire_bytes > 0), PRIMARY KEY (stream_key, sequence), FOREIGN KEY (stream_key) REFERENCES {$streams} (stream_key) ON DELETE CASCADE)",
			"CREATE INDEX IF NOT EXISTS {$events}_topic_lookup ON {$events} (stream_key, topic, sequence)",
			"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash TEXT NOT NULL PRIMARY KEY CHECK (length(nonce_hash) = 64), expires_at INTEGER NOT NULL CHECK (expires_at >= 0), created_at INTEGER NOT NULL CHECK (created_at >= 0))",
			"CREATE INDEX IF NOT EXISTS {$nonces}_expiry_lookup ON {$nonces} (expires_at, nonce_hash)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version, stream_count, nonce_count) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0)",
		];
	}

	/** @return array{type:string,version:int,driver:string,schema_version:int,statements:int,idempotent:bool,destructive:bool} */
	public function installSchema(): array {
		if($this->activeTransaction()){ throw new PanelRealtimeException('broker_transaction_conflict',503,'Panel realtime schema installation requires transaction ownership.',true); }
		try{
			$statements=$this->schemaStatements(); foreach($statements as $sql){ if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO schema statement failed.'); } }
			$this->assertSchema(false);
			return ['type'=>'panel_realtime_schema_installation','version'=>1,'driver'=>$this->driver,'schema_version'=>self::SCHEMA_VERSION,'statements'=>count($statements),'idempotent'=>true,'destructive'=>false];
		}catch(PanelRealtimeException $error){ if($error->publicCode()==='broker_schema_incompatible'){ throw $error; } throw new PanelRealtimeException('broker_migration_failed',503,'Panel realtime schema migration failed.',true); }
		catch(\Throwable){ throw new PanelRealtimeException('broker_migration_failed',503,'Panel realtime schema migration failed.',true); }
	}

	public function publish(PanelRealtimeContext $context, string $channel, string $topic, string $type, mixed $payload, array $metadata=[], ?string $occurredAt=null): PanelRealtimeEvent {
		$channel=PanelRealtimeGuard::identifier($channel,'channel',96); $topic=PanelRealtimeGuard::identifier($topic,'topic',96); $type=PanelRealtimeGuard::identifier($type,'event type',96);
		$streamKey=$context->streamKey($channel); $occurredAt=$occurredAt??gmdate('Y-m-d\TH:i:s\Z',$this->now());
		$probe=new PanelRealtimeEvent(1,$streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata);
		if($probe->wireBytes()>$this->maximumEventBytes){ throw new PanelRealtimeException('event_too_large',422,'Panel realtime event exceeds the broker byte bound.'); }
		return $this->transaction(true,'broker_storage_unavailable',function()use($streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata):PanelRealtimeEvent{
			$this->assertSchema(false); $stream=$this->stream($streamKey,true);
			if($stream===null){
				$meta=$this->assertSchema(true); $stream=$this->stream($streamKey,true);
				if($stream===null){
					if($meta['stream_count']>=$this->maximumStreams){ throw new PanelRealtimeException('broker_capacity',503,'Panel realtime broker capacity is exhausted.',true); }
					$this->execute("INSERT INTO {$this->prefix}_streams (stream_key, head, updated_at) VALUES (:stream_key, 0, :updated_at)",['stream_key'=>$streamKey,'updated_at'=>$this->now()]);
					$this->affected("UPDATE {$this->prefix}_meta SET stream_count = stream_count + 1 WHERE singleton = 1",[],1); $stream=['head'=>0];
				}
			}
			$head=$stream['head']; if($head===PHP_INT_MAX){ throw new PanelRealtimeException('broker_sequence_exhausted',503,'Panel realtime stream sequence is exhausted.'); }
			$sequence=$head+1;
			try{ $event=new PanelRealtimeEvent($sequence,$streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata); }catch(\LengthException){ throw new PanelRealtimeException('event_too_large',422,'Panel realtime event exceeds the broker byte bound.'); }
			if($event->wireBytes()>$this->maximumEventBytes){ throw new PanelRealtimeException('event_too_large',422,'Panel realtime event exceeds the broker byte bound.'); }
			$body=$event->jsonSerialize(); $payloadJson=self::encodeJson($body['payload']); $metadataJson=self::encodeJson($body['metadata']);
			$this->execute("INSERT INTO {$this->prefix}_events (stream_key, sequence, channel, topic, event_type, occurred_at, payload_json, metadata_json, wire_bytes) VALUES (:stream_key, :sequence, :channel, :topic, :event_type, :occurred_at, :payload_json, :metadata_json, :wire_bytes)",['stream_key'=>$streamKey,'sequence'=>$sequence,'channel'=>$channel,'topic'=>$topic,'event_type'=>$type,'occurred_at'=>$occurredAt,'payload_json'=>$payloadJson,'metadata_json'=>$metadataJson,'wire_bytes'=>$event->wireBytes()]);
			$this->affected("UPDATE {$this->prefix}_streams SET head = :sequence, updated_at = :updated_at WHERE stream_key = :stream_key AND head = :head",['sequence'=>$sequence,'updated_at'=>$this->now(),'stream_key'=>$streamKey,'head'=>$head],1);
			$cutoff=$sequence-$this->retainedEvents; if($cutoff>0){ $this->execute("DELETE FROM {$this->prefix}_events WHERE stream_key = :stream_key AND sequence <= :cutoff",['stream_key'=>$streamKey,'cutoff'=>$cutoff]); }
			return $event;
		});
	}

	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		if($afterSequence<0 || $limit<1 || $limit>1000){ throw new \InvalidArgumentException('Panel realtime broker read bounds are invalid.'); }
		$this->cancelled($cancellation);
		return $this->transaction(false,'broker_storage_unavailable',function()use($subscription,$afterSequence,$limit,$cancellation):PanelRealtimeReadResult{
			$this->assertSchema(false); $stream=$this->stream($subscription->streamKey(),false);
			if($stream===null){ return $afterSequence===0 ? new PanelRealtimeReadResult(0,[],0,0,1) : new PanelRealtimeReadResult($afterSequence,[],0,0,1,false,'source_reset'); }
			$head=$stream['head']; $earliestValue=$this->scalar("SELECT MIN(sequence) FROM {$this->prefix}_events WHERE stream_key = :stream_key",['stream_key'=>$subscription->streamKey()]);
			$earliest=$earliestValue===null ? $head+1 : $this->integer($earliestValue,'event sequence',1);
			if($afterSequence>$head){ return new PanelRealtimeReadResult($afterSequence,[],$head,$head,$earliest,false,'source_reset'); }
			if($afterSequence<$earliest-1){ return new PanelRealtimeReadResult($afterSequence,[],$head,$head,$earliest,false,'retention_gap'); }
			$this->cancelled($cancellation);
			$rows=$this->rows("SELECT sequence, channel, topic, event_type, occurred_at, payload_json, metadata_json, wire_bytes FROM {$this->prefix}_events WHERE stream_key = :stream_key AND sequence > :after_sequence ORDER BY sequence ASC LIMIT {$limit}",['stream_key'=>$subscription->streamKey(),'after_sequence'=>$afterSequence]);
			$events=[]; $cursor=$afterSequence; $expected=$afterSequence+1;
			foreach($rows as $row){
				$this->cancelled($cancellation); $event=$this->hydrate($row,$subscription->streamKey());
				if($event->sequence()!==$expected || !hash_equals($subscription->channel(),$event->channel())){ throw $this->corrupt(); }
				$cursor=$event->sequence(); $expected++; if($subscription->accepts($event)){ $events[]=$event; }
			}
			if(count($rows)<$limit && $cursor<$head){ throw $this->corrupt(); }
			return new PanelRealtimeReadResult($afterSequence,$events,$cursor,$head,$earliest,$cursor<$head);
		});
	}

	public function consume(PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool {
		if($intent->purpose()!=='subscribe' || !$subscription->belongsTo($context)){ throw new \InvalidArgumentException('Panel realtime replay policy accepts only matching subscription intents.'); }
		$now=$this->now(); if($intent->expiresAt()<$now){ throw new PanelRealtimeException('intent_expired',401,'Panel realtime intent has expired.'); }
		$nonceHash=hash('sha256',"panel-realtime-subscription-nonce-v1\0".$intent->nonce());
		return $this->transaction(true,'replay_policy_unavailable',function()use($intent,$now,$nonceHash):bool{
			$meta=$this->assertSchema(true);
			$purged=$this->execute("DELETE FROM {$this->prefix}_nonces WHERE expires_at < :now",['now'=>$now])->rowCount();
			if($purged<0 || $purged>$meta['nonce_count']){ throw $this->corrupt(); }
			if($purged>0){ $this->affected("UPDATE {$this->prefix}_meta SET nonce_count = nonce_count - :purged WHERE singleton = 1",['purged'=>$purged],1); $meta['nonce_count']-=$purged; }
			if($this->row("SELECT nonce_hash FROM {$this->prefix}_nonces WHERE nonce_hash = :nonce_hash",['nonce_hash'=>$nonceHash])!==null){ return false; }
			if($meta['nonce_count']>=$this->maximumReplayEntries){ throw new PanelRealtimeException('replay_policy_capacity',503,'Panel realtime replay protection is at capacity.',true); }
			if($intent->expiresAt()>PHP_INT_MAX-$this->retentionGraceSeconds){ throw $this->corrupt(); }
			$this->execute("INSERT INTO {$this->prefix}_nonces (nonce_hash, expires_at, created_at) VALUES (:nonce_hash, :expires_at, :created_at)",['nonce_hash'=>$nonceHash,'expires_at'=>$intent->expiresAt()+$this->retentionGraceSeconds,'created_at'=>$now]);
			$this->affected("UPDATE {$this->prefix}_meta SET nonce_count = nonce_count + 1 WHERE singleton = 1",[],1); return true;
		});
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_realtime_pdo_adapter','version'=>1,'adapter'=>'pdo','driver'=>$this->driver,'durable'=>true,'distributed'=>true,'cross_process'=>true,'shared_database'=>true,'ordered_per_stream'=>true,'atomic_publication'=>true,'replay'=>true,'retention_gap_detection'=>true,'retained_events_per_stream'=>$this->retainedEvents,'maximum_streams'=>$this->maximumStreams,'maximum_event_bytes'=>$this->maximumEventBytes,'subscription_intent_replay_policy'=>true,'single_use_initial_connect'=>true,'maximum_replay_entries'=>$this->maximumReplayEntries,'replay_retention_grace_seconds'=>$this->retentionGraceSeconds,'nonce_digest'=>'sha256_domain_separated','raw_nonces_stored'=>false,'resume_intents_consumed'=>false,'schema_version'=>self::SCHEMA_VERSION,'schema_migration'=>'explicit_idempotent','automatic_schema_mutation'=>false,'transaction_retries'=>$this->transactionRetries,'delivery'=>'at_least_once_across_reconnect','exactly_once'=>false,'connection_details_serialized'=>false,'credentials_serialized'=>false,'table_prefix_serialized'=>false,'sql_serialized'=>false,'live_counts_queried'=>false];
	}

	/** @return array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	public static function dialectPlanFor(string $driver): array {
		$driver=self::driverName($driver);
		return ['write_begin'=>$driver==='sqlite'?'BEGIN IMMEDIATE':null,'read_before'=>$driver==='mysql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'read_after'=>$driver==='pgsql'?['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ']:[],'lock_suffix'=>$driver==='sqlite'?'':' FOR UPDATE'];
	}

	/** @return array{schema_version:int,stream_count:int,nonce_count:int} */
	private function assertSchema(bool $lock): array {
		$row=$this->row("SELECT schema_version, stream_count, nonce_count FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));
		if($row===null){ throw new PanelRealtimeException('broker_schema_required',503,'Panel realtime schema is not installed.'); }
		$version=$this->integer($row['schema_version']??null,'schema version',1); if($version!==self::SCHEMA_VERSION){ throw new PanelRealtimeException('broker_schema_incompatible',503,'Panel realtime schema version is incompatible.'); }
		return ['schema_version'=>$version,'stream_count'=>$this->integer($row['stream_count']??null,'stream count',0),'nonce_count'=>$this->integer($row['nonce_count']??null,'nonce count',0)];
	}

	/** @return array{head:int}|null */
	private function stream(string $streamKey, bool $lock): ?array {
		$row=$this->row("SELECT head FROM {$this->prefix}_streams WHERE stream_key = :stream_key".($lock?$this->dialect['lock_suffix']:''),['stream_key'=>$streamKey]);
		return $row===null ? null : ['head'=>$this->integer($row['head']??null,'stream head',0)];
	}

	/** @param array<string,mixed> $row */
	private function hydrate(array $row, string $streamKey): PanelRealtimeEvent {
		try{
			$sequence=$this->integer($row['sequence']??null,'event sequence',1);
			foreach(['channel','topic','event_type','occurred_at','payload_json','metadata_json'] as $name){ if(!isset($row[$name]) || !is_string($row[$name])){ throw new \UnexpectedValueException('Stored realtime text is invalid.'); } }
			$payload=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR); $metadata=json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR);
			if(!is_array($metadata)){ throw new \UnexpectedValueException('Stored realtime metadata is invalid.'); }
			$event=new PanelRealtimeEvent($sequence,$streamKey,$row['channel'],$row['topic'],$row['event_type'],$row['occurred_at'],$payload,$metadata);
			if($this->integer($row['wire_bytes']??null,'event wire bytes',1)!==$event->wireBytes()){ throw new \UnexpectedValueException('Stored realtime wire size is invalid.'); }
			return $event;
		}catch(PanelRealtimeException $error){ throw $error; }catch(\Throwable){ throw $this->corrupt(); }
	}

	private function transaction(bool $write, string $failureCode, callable $callback): mixed {
		if($this->activeTransaction()){ throw new PanelRealtimeException('broker_transaction_conflict',503,'Panel realtime PDO adapter requires transaction ownership.',true); }
		for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){
			try{
				$this->begin($write); $result=$callback(); $this->commit(); return $result;
			}catch(PanelRealtimeException $error){ $this->rollback(); throw $error; }
			catch(\Throwable $error){ $this->rollback(); if($attempt<$this->transactionRetries && $this->transient($error)){ continue; } throw new PanelRealtimeException($failureCode,503,$failureCode==='replay_policy_unavailable'?'Panel realtime replay protection is unavailable.':'Panel realtime broker storage is unavailable.',true); }
		}
	}

	private function begin(bool $write): void {
		foreach($write?[]:$this->dialect['read_before'] as $sql){ if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO transaction configuration failed.'); } }
		if($write && $this->dialect['write_begin']!==null){ if($this->pdo->exec($this->dialect['write_begin'])===false){ throw new \RuntimeException('PDO transaction begin failed.'); } $this->manualSqliteWriteTransaction=true; }elseif(!$this->pdo->beginTransaction()){ throw new \RuntimeException('PDO transaction begin failed.'); }
		foreach($write?[]:$this->dialect['read_after'] as $sql){ if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO transaction configuration failed.'); } }
	}

	private function commit(): void { if($this->manualSqliteWriteTransaction){ if($this->pdo->exec('COMMIT')===false){ throw new \RuntimeException('PDO commit failed.'); } $this->manualSqliteWriteTransaction=false; return; } if(!$this->pdo->commit()){ throw new \RuntimeException('PDO commit failed.'); } }
	private function rollback(): void { try{ if($this->manualSqliteWriteTransaction){ $this->pdo->exec('ROLLBACK'); }elseif($this->pdo->inTransaction()){ $this->pdo->rollBack(); } }catch(\Throwable){}finally{$this->manualSqliteWriteTransaction=false;} }
	private function activeTransaction(): bool { return $this->manualSqliteWriteTransaction || $this->pdo->inTransaction(); }

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function execute(string $sql, array $parameters=[]): \PDOStatement {
		$statement=$this->pdo->prepare($sql); if(!$statement instanceof \PDOStatement){ throw new \RuntimeException('PDO prepare failed.'); }
		foreach($parameters as $name=>$value){ $type=match(true){$value===null=>\PDO::PARAM_NULL,is_bool($value)=>\PDO::PARAM_BOOL,is_int($value)=>\PDO::PARAM_INT,default=>\PDO::PARAM_STR}; $statement->bindValue(':'.$name,$value,$type); }
		if(!$statement->execute()){ throw new \RuntimeException('PDO execute failed.'); } return $statement;
	}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function affected(string $sql, array $parameters, int $expected): void { if($this->execute($sql,$parameters)->rowCount()!==$expected){ throw $this->corrupt(); } }

	/** @param array<string,null|bool|int|float|string> $parameters @return array<string,mixed>|null */
	private function row(string $sql, array $parameters=[]): ?array {
		$row=$this->execute($sql,$parameters)->fetch(\PDO::FETCH_ASSOC); if($row===false){ return null; } if(!is_array($row) || array_is_list($row)){ throw $this->corrupt(); } return $row;
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */
	private function rows(string $sql, array $parameters=[]): array {
		$rows=$this->execute($sql,$parameters)->fetchAll(\PDO::FETCH_ASSOC); if(!is_array($rows)){ throw $this->corrupt(); } foreach($rows as $row){ if(!is_array($row) || array_is_list($row)){ throw $this->corrupt(); } } return array_values($rows);
	}

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function scalar(string $sql, array $parameters=[]): mixed { $value=$this->execute($sql,$parameters)->fetchColumn(); return $value===false ? null : $value; }

	private function integer(mixed $value, string $label, int $minimum): int {
		if(is_int($value)){ $number=$value; }elseif(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1 && strlen($value)<=strlen((string)PHP_INT_MAX)){ $number=(int)$value; if((string)$number!==$value){ throw $this->corrupt(); } }else{ throw $this->corrupt(); }
		if($number<$minimum){ throw $this->corrupt(); } return $number;
	}

	private function cancelled(?PanelRealtimeCancellation $cancellation): void { if($cancellation?->isCancellationRequested()){ throw new PanelRealtimeException('read_cancelled',408,'Panel realtime broker read was cancelled.'); } }
	private function corrupt(): PanelRealtimeException { return new PanelRealtimeException('broker_storage_corrupt',503,'Panel realtime broker storage failed integrity validation.'); }
	private function now(): int { $value=($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel PDO realtime adapter clock must return a non-negative integer timestamp.'); } return $value; }

	private function transient(\Throwable $error): bool {
		if(!$error instanceof \PDOException){ return false; } $state=strtoupper((string)$error->getCode()); $info=$error->errorInfo; $native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		return in_array($state,['40001','40P01','55P03'],true) || ($this->driver==='sqlite' && ($native==='5' || $native==='6' || str_contains(strtolower($error->getMessage()),'locked'))) || ($this->driver==='mysql' && in_array($native,['1205','1213'],true));
	}

	private static function driverName(string $driver): string { $driver=strtolower(trim($driver)); if(!in_array($driver,['mysql','pgsql','sqlite'],true)){ throw new \InvalidArgumentException('Panel PDO realtime adapter supports mysql, pgsql, and sqlite only.'); } return $driver; }
	private static function prefix(string $prefix): string { $prefix=strtolower(trim($prefix)); if(preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$prefix)!==1){ throw new \InvalidArgumentException('Panel PDO realtime table prefix is invalid.'); } return $prefix; }
	/** @param array<string,mixed> $options */
	private static function option(array $options, string $name, int $default, int $minimum, int $maximum): int { $value=$options[$name]??$default; if(!is_int($value) || $value<$minimum || $value>$maximum){ throw new \InvalidArgumentException("Panel PDO realtime option '{$name}' is outside its supported bound."); } return $value; }
	private static function encodeJson(mixed $value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }
}
