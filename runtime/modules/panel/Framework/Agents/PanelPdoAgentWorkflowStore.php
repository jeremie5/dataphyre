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
 * Durable shared-SQL agent workflow store with global optimistic revisions,
 * renewable fenced reservations, scope-bound replay, and an integrity-checked
 * audit chain. Schema installation is always an explicit host migration.
 */
final class PanelPdoAgentWorkflowStore implements PanelAgentWorkflowStore,\JsonSerializable {
	private const SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_agent_workflows';
	private const MAX_INTENT_TTL_SECONDS=900;
	private const OPTION_NAMES=[
		'table_prefix',
		'lease_seconds',
		'max_entries',
		'retention_seconds',
		'maximum_result_bytes',
		'maximum_audit_bytes',
		'change_retention',
		'transaction_retries',
		'retry_delay_microseconds',
	];

	private readonly string $driver;
	private readonly string $prefix;
	private readonly int $leaseSeconds;
	private readonly int $maxEntries;
	private readonly int $retentionSeconds;
	private readonly int $maximumResultBytes;
	private readonly int $maximumAuditBytes;
	private readonly int $changeRetention;
	private readonly int $transactionRetries;
	private readonly int $retryDelayMicroseconds;
	private readonly \Closure $clock;
	private readonly \Closure $reservationFactory;
	private bool $manualSqliteWriteTransaction=false;
	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(
		private readonly \PDO $pdo,
		array $options=[],
		?callable $clock=null,
		?callable $reservationFactory=null,
	){
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){
				throw new \InvalidArgumentException('Panel PDO agent workflow options contain an unsupported name.');
			}
		}
		try{
			$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));
			$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);
		}catch(\Throwable $error){
			throw new \InvalidArgumentException('Panel PDO agent workflow store could not inspect its connection.',0,$error);
		}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){
			throw new \InvalidArgumentException('Panel PDO agent workflow store requires PDO exception mode.');
		}
		$this->driver=self::driverName($driver);
		$this->dialect=self::dialectPlanFor($driver);
		$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->leaseSeconds=self::option($options,'lease_seconds',120,30,3600);
		$this->maxEntries=self::option($options,'max_entries',4096,1,100000);
		$this->retentionSeconds=self::option($options,'retention_seconds',86400,self::MAX_INTENT_TTL_SECONDS*4,31536000);
		$this->maximumResultBytes=self::option($options,'maximum_result_bytes',1179648,4096,16777216);
		$this->maximumAuditBytes=self::option($options,'maximum_audit_bytes',131072,1024,1048576);
		$this->changeRetention=self::option($options,'change_retention',4096,8,1000000);
		$this->transactionRetries=self::option($options,'transaction_retries',3,0,10);
		$this->retryDelayMicroseconds=self::option($options,'retry_delay_microseconds',2000,0,100000);
		$this->clock=\Closure::fromCallable($clock??static fn():int=>time());
		$this->reservationFactory=\Closure::fromCallable($reservationFactory??static fn():string=>'agent_reservation_'.bin2hex(random_bytes(12)));
	}

	public function driver():string { return $this->driver; }
	/** @return list<string> */
	public function schemaStatements():array { return self::schemaStatementsFor($this->driver,$this->prefix); }

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array {
		$driver=self::driverName($driver);
		$prefix=self::prefix($prefix);
		$meta=$prefix.'_meta';
		$reservations=$prefix.'_reservations';
		$nonces=$prefix.'_nonces';
		$audit=$prefix.'_audit';
		$cancellations=$prefix.'_cancellations';
		$changes=$prefix.'_changes';
		if($driver==='mysql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, global_revision BIGINT UNSIGNED NOT NULL, audit_sequence BIGINT UNSIGNED NOT NULL, audit_head VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, updated_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$reservations} (id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, lease_revision BIGINT UNSIGNED NOT NULL, lease_expires_at BIGINT UNSIGNED NOT NULL, reservation_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, result_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, result_json LONGTEXT NULL, result_bytes INT UNSIGNED NULL, created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL, completed_at BIGINT UNSIGNED NULL, PRIMARY KEY (id), UNIQUE KEY idempotency_lookup (idempotency_hash), KEY lease_lookup (reservation_status, lease_expires_at, id), KEY plan_lookup (plan_hash, id)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, reservation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (nonce_hash), KEY reservation_lookup (reservation_id), CONSTRAINT {$nonces}_reservation FOREIGN KEY (reservation_id) REFERENCES {$reservations} (id) ON DELETE CASCADE) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$audit} (sequence BIGINT UNSIGNED NOT NULL, event_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, previous_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, receipt_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, receipt_json LONGTEXT NOT NULL, receipt_bytes INT UNSIGNED NOT NULL, occurred_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (sequence), UNIQUE KEY receipt_hash_lookup (receipt_hash), KEY plan_audit_lookup (plan_hash, sequence)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$cancellations} (plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, occurred_at BIGINT UNSIGNED NOT NULL, audit_sequence BIGINT UNSIGNED NOT NULL, receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (plan_hash), UNIQUE KEY cancellation_audit_lookup (audit_sequence), CONSTRAINT {$cancellations}_audit FOREIGN KEY (audit_sequence) REFERENCES {$audit} (sequence)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, entity_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, global_revision BIGINT UNSIGNED NOT NULL, occurred_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY (change_id), KEY entity_lookup (entity_type, entity_id, change_id)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version, global_revision, audit_sequence, audit_head, updated_at) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0, '', 0)",
			];
		}
		if($driver==='pgsql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0), global_revision BIGINT NOT NULL CHECK (global_revision >= 0), audit_sequence BIGINT NOT NULL CHECK (audit_sequence >= 0), audit_head VARCHAR(64) NOT NULL, updated_at BIGINT NOT NULL CHECK (updated_at >= 0))",
				"CREATE TABLE IF NOT EXISTS {$reservations} (id VARCHAR(128) PRIMARY KEY, plan_hash CHAR(64) NOT NULL, scope_fingerprint CHAR(64) NOT NULL, idempotency_hash CHAR(64) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL, lease_revision BIGINT NOT NULL CHECK (lease_revision > 0), lease_expires_at BIGINT NOT NULL CHECK (lease_expires_at > 0), reservation_status VARCHAR(16) NOT NULL, result_digest CHAR(64), result_json TEXT, result_bytes INTEGER, created_at BIGINT NOT NULL CHECK (created_at >= 0), updated_at BIGINT NOT NULL CHECK (updated_at >= 0), completed_at BIGINT)",
				"CREATE INDEX IF NOT EXISTS {$reservations}_lease_lookup ON {$reservations} (reservation_status, lease_expires_at, id)",
				"CREATE INDEX IF NOT EXISTS {$reservations}_plan_lookup ON {$reservations} (plan_hash, id)",
				"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash CHAR(64) PRIMARY KEY, reservation_id VARCHAR(128) NOT NULL REFERENCES {$reservations} (id) ON DELETE CASCADE)",
				"CREATE INDEX IF NOT EXISTS {$nonces}_reservation_lookup ON {$nonces} (reservation_id)",
				"CREATE TABLE IF NOT EXISTS {$audit} (sequence BIGINT PRIMARY KEY CHECK (sequence > 0), event_type VARCHAR(96) NOT NULL, plan_hash CHAR(64) NOT NULL, scope_fingerprint CHAR(64) NOT NULL, receipt_hash CHAR(64) NOT NULL UNIQUE, previous_hash VARCHAR(64) NOT NULL, receipt_digest CHAR(64) NOT NULL, receipt_json TEXT NOT NULL, receipt_bytes INTEGER NOT NULL CHECK (receipt_bytes > 0), occurred_at BIGINT NOT NULL CHECK (occurred_at >= 0))",
				"CREATE INDEX IF NOT EXISTS {$audit}_plan_lookup ON {$audit} (plan_hash, sequence)",
				"CREATE TABLE IF NOT EXISTS {$cancellations} (plan_hash CHAR(64) PRIMARY KEY, occurred_at BIGINT NOT NULL CHECK (occurred_at >= 0), audit_sequence BIGINT NOT NULL UNIQUE REFERENCES {$audit} (sequence), receipt_hash CHAR(64) NOT NULL)",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, event_type VARCHAR(96) NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id VARCHAR(190) NOT NULL, global_revision BIGINT NOT NULL CHECK (global_revision > 0), occurred_at BIGINT NOT NULL CHECK (occurred_at >= 0))",
				"CREATE INDEX IF NOT EXISTS {$changes}_entity_lookup ON {$changes} (entity_type, entity_id, change_id)",
				"INSERT INTO {$meta} (singleton, schema_version, global_revision, audit_sequence, audit_head, updated_at) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0, '', 0) ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return [
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0), global_revision INTEGER NOT NULL CHECK (global_revision >= 0), audit_sequence INTEGER NOT NULL CHECK (audit_sequence >= 0), audit_head TEXT NOT NULL CHECK (length(audit_head) IN (0, 64)), updated_at INTEGER NOT NULL CHECK (updated_at >= 0))",
			"CREATE TABLE IF NOT EXISTS {$reservations} (id TEXT NOT NULL PRIMARY KEY CHECK (length(id) BETWEEN 1 AND 128), plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64), scope_fingerprint TEXT NOT NULL CHECK (length(scope_fingerprint) = 64), idempotency_hash TEXT NOT NULL UNIQUE CHECK (length(idempotency_hash) = 64), request_hash TEXT NOT NULL CHECK (length(request_hash) = 64), lease_revision INTEGER NOT NULL CHECK (lease_revision > 0), lease_expires_at INTEGER NOT NULL CHECK (lease_expires_at > 0), reservation_status TEXT NOT NULL CHECK (reservation_status IN ('pending', 'completed')), result_digest TEXT CHECK (result_digest IS NULL OR length(result_digest) = 64), result_json TEXT, result_bytes INTEGER, created_at INTEGER NOT NULL CHECK (created_at >= 0), updated_at INTEGER NOT NULL CHECK (updated_at >= 0), completed_at INTEGER)",
			"CREATE INDEX IF NOT EXISTS {$reservations}_lease_lookup ON {$reservations} (reservation_status, lease_expires_at, id)",
			"CREATE INDEX IF NOT EXISTS {$reservations}_plan_lookup ON {$reservations} (plan_hash, id)",
			"CREATE TABLE IF NOT EXISTS {$nonces} (nonce_hash TEXT NOT NULL PRIMARY KEY CHECK (length(nonce_hash) = 64), reservation_id TEXT NOT NULL, FOREIGN KEY (reservation_id) REFERENCES {$reservations} (id) ON DELETE CASCADE)",
			"CREATE INDEX IF NOT EXISTS {$nonces}_reservation_lookup ON {$nonces} (reservation_id)",
			"CREATE TABLE IF NOT EXISTS {$audit} (sequence INTEGER NOT NULL PRIMARY KEY CHECK (sequence > 0), event_type TEXT NOT NULL CHECK (length(event_type) BETWEEN 1 AND 96), plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64), scope_fingerprint TEXT NOT NULL CHECK (length(scope_fingerprint) = 64), receipt_hash TEXT NOT NULL UNIQUE CHECK (length(receipt_hash) = 64), previous_hash TEXT NOT NULL CHECK (length(previous_hash) IN (0, 64)), receipt_digest TEXT NOT NULL CHECK (length(receipt_digest) = 64), receipt_json TEXT NOT NULL, receipt_bytes INTEGER NOT NULL CHECK (receipt_bytes > 0), occurred_at INTEGER NOT NULL CHECK (occurred_at >= 0))",
			"CREATE INDEX IF NOT EXISTS {$audit}_plan_lookup ON {$audit} (plan_hash, sequence)",
			"CREATE TABLE IF NOT EXISTS {$cancellations} (plan_hash TEXT NOT NULL PRIMARY KEY CHECK (length(plan_hash) = 64), occurred_at INTEGER NOT NULL CHECK (occurred_at >= 0), audit_sequence INTEGER NOT NULL UNIQUE, receipt_hash TEXT NOT NULL CHECK (length(receipt_hash) = 64), FOREIGN KEY (audit_sequence) REFERENCES {$audit} (sequence))",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, global_revision INTEGER NOT NULL CHECK (global_revision > 0), occurred_at INTEGER NOT NULL CHECK (occurred_at >= 0))",
			"CREATE INDEX IF NOT EXISTS {$changes}_entity_lookup ON {$changes} (entity_type, entity_id, change_id)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version, global_revision, audit_sequence, audit_head, updated_at) VALUES (1, ".self::SCHEMA_VERSION.", 0, 0, '', 0)",
		];
	}

	/** @return array{type:string,version:int,driver:string,schema_version:int,statements:int,idempotent:bool,destructive:bool} */
	public function installSchema():array {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict','Panel PDO agent workflow schema installation requires transaction ownership.',true);
		}
		try{
			$statements=$this->schemaStatements();
			foreach($statements as $sql){
				if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO schema statement failed.'); }
			}
			$this->assertSchema(false);
			return [
				'type'=>'panel_pdo_agent_workflow_schema_installation',
				'version'=>1,
				'driver'=>$this->driver,
				'schema_version'=>self::SCHEMA_VERSION,
				'statements'=>count($statements),
				'idempotent'=>true,
				'destructive'=>false,
			];
		}catch(PanelAgentWorkflowStorageException $error){
			if($error->errorCode()==='schema_incompatible'){ throw $error; }
			throw $this->storage('migration_failed','Panel PDO agent workflow schema migration failed.',true);
		}catch(\Throwable){
			throw $this->storage('migration_failed','Panel PDO agent workflow schema migration failed.',true);
		}
	}

	public function revision():int {
		return $this->transaction(false,function():int {
			$this->assertSchema(false);
			$meta=$this->meta(false);
			$this->auditFromStorage($meta);
			return $meta['revision'];
		});
	}

	/** @return list<PanelAgentAuditReceipt> */
	public function audit():array {
		return $this->transaction(false,function():array {
			$this->assertSchema(false);
			return $this->auditFromStorage($this->meta(false));
		});
	}

	public function lastAuditHash():string {
		return $this->transaction(false,function():string {
			$this->assertSchema(false);
			$meta=$this->meta(false);
			$this->auditFromStorage($meta);
			return $meta['audit_head'];
		});
	}

	public function append(PanelAgentAuditReceipt $receipt,int $expectedRevision):int {
		$this->assertDurableReceipt($receipt);
		return $this->transaction(true,function() use ($receipt,$expectedRevision):int {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$this->auditFromStorage($meta);
			$this->assertRevision($meta,$expectedRevision);
			$this->assertCapacity($meta['audit_sequence'],$this->maxEntries*4,'audit');
			$this->assertNextReceipt($meta,$receipt);
			$this->insertAudit($receipt);
			$revision=$this->nextRevision($meta['revision']);
			$this->updateMeta($meta,$revision,$receipt->sequence(),$receipt->hash());
			$this->recordChange('audit.appended','audit',$receipt->planHash(),$revision);
			return $revision;
		});
	}

	public function lookup(string $planHash,string $scopeFingerprint,string $idempotencyKey,string $requestHash):?PanelAgentExecutionResult {
		$planHash=PanelAgentGuard::digest($planHash,'lookup plan hash');
		$scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'lookup scope');
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256);
		$requestHash=PanelAgentGuard::digest($requestHash,'request hash');
		$keyHash=$this->idempotencyHash($scopeFingerprint,$idempotencyKey);
		$now=$this->now();
		return $this->transaction(false,function() use ($planHash,$scopeFingerprint,$requestHash,$keyHash,$now):?PanelAgentExecutionResult {
			$this->assertSchema(false);
			$meta=$this->meta(false);
			$audit=$this->auditFromStorage($meta);
			$row=$this->reservationByIdempotency($keyHash,false);
			if($row===null){ return null; }
			$reservation=$this->hydrateReservation($row);
			if(!hash_equals($reservation['plan_hash'],$planHash)||!hash_equals($reservation['scope'],$scopeFingerprint)||!hash_equals($reservation['request_hash'],$requestHash)){
				throw new PanelAgentException('idempotency_conflict','The Panel agent idempotency key was used for another request.',409);
			}
			if($reservation['status']!=='completed'){
				return $now>=$reservation['lease_expires_at']?null:throw new PanelAgentException('execution_in_progress','The Panel agent execution is already in progress.',409);
			}
			return $this->decodeResult($reservation,$audit,$meta['revision']);
		});
	}

	public function reserve(string $planHash,string $scopeFingerprint,string $idempotencyKey,string $requestHash,array $nonces,int $expectedRevision):PanelAgentStoreReservation {
		$planHash=PanelAgentGuard::digest($planHash,'reservation plan hash');
		$scopeFingerprint=PanelAgentGuard::digest($scopeFingerprint,'reservation scope');
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256);
		$requestHash=PanelAgentGuard::digest($requestHash,'request hash');
		$keyHash=$this->idempotencyHash($scopeFingerprint,$idempotencyKey);
		$nonceTags=$this->nonceTags($nonces);
		$now=$this->now();
		return $this->transaction(true,function() use ($planHash,$scopeFingerprint,$requestHash,$keyHash,$nonceTags,$expectedRevision,$now):PanelAgentStoreReservation {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$audit=$this->auditFromStorage($meta);
			$existingRow=$this->reservationByIdempotency($keyHash,true);
			if($existingRow!==null){
				$existing=$this->hydrateReservation($existingRow);
				if(!hash_equals($existing['plan_hash'],$planHash)||!hash_equals($existing['scope'],$scopeFingerprint)||!hash_equals($existing['request_hash'],$requestHash)){
					throw new PanelAgentException('idempotency_conflict','The Panel agent idempotency key was used for another request.',409);
				}
				if($existing['status']==='completed'){
					return PanelAgentStoreReservation::replay($this->decodeResult($existing,$audit,$meta['revision']),$meta['revision']);
				}
				if($now<$existing['lease_expires_at']){
					throw new PanelAgentException('execution_in_progress','The Panel agent execution is already in progress.',409);
				}
				$this->assertRevision($meta,$expectedRevision);
				$expected=$existing['nonce_tags'];
				$presented=$nonceTags;
				sort($expected,SORT_STRING);
				sort($presented,SORT_STRING);
				if($expected!==$presented){
					throw new PanelAgentException('intent_replayed','Expired Panel agent execution leases may only be reclaimed with their original signed intents.',409);
				}
				$this->deleteReservation($existing['id']);
			}
			$this->assertRevision($meta,$expectedRevision);
			$cancellation=$this->cancellationRow($planHash,true);
			if($cancellation!==null){
				$this->hydrateCancellation($cancellation,$audit);
				throw new PanelAgentException('plan_cancelled','The Panel agent plan was cancelled.',409);
			}
			$this->assertCapacity($this->tableCount('reservations'),$this->maxEntries,'reservation');
			foreach($nonceTags as $tag){
				if($this->nonceOwner($tag,true)!==null){
					throw new PanelAgentException('intent_replayed','A Panel agent signed intent was already consumed.',409);
				}
			}
			$id=$this->reservationId();
			if($this->reservationRow($id,true)!==null){
				throw new PanelAgentException('reservation_id_collision','Panel agent reservation id allocation failed closed.',503);
			}
			$revision=$this->nextRevision($meta['revision']);
			$expiresAt=$this->plusSeconds($now,$this->leaseSeconds);
			$this->insertReservation($id,$planHash,$scopeFingerprint,$keyHash,$requestHash,$nonceTags,$revision,$expiresAt,$now);
			$this->updateMeta($meta,$revision,$meta['audit_sequence'],$meta['audit_head']);
			$this->recordChange('reservation.acquired','reservation',$id,$revision);
			return PanelAgentStoreReservation::acquired($id,$revision,$expiresAt);
		});
	}

	public function renew(string $reservationId,int $expectedLeaseRevision,int $minimumLeaseSeconds):PanelAgentStoreReservation {
		$reservationId=PanelAgentGuard::identifier($reservationId,'reservation id',128);
		if($minimumLeaseSeconds<30||$minimumLeaseSeconds>3600){
			throw new \InvalidArgumentException('Panel agent minimum lease renewal must be between 30 and 3600 seconds.');
		}
		$now=$this->now();
		return $this->transaction(true,function() use ($reservationId,$expectedLeaseRevision,$minimumLeaseSeconds,$now):PanelAgentStoreReservation {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$this->auditFromStorage($meta);
			$row=$this->reservationRow($reservationId,true);
			if($row===null){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			$reservation=$this->hydrateReservation($row);
			if($reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			if($expectedLeaseRevision!==$reservation['lease_revision']){
				throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409);
			}
			if($now>=$reservation['lease_expires_at']){
				throw new PanelAgentException('reservation_expired','Panel agent execution reservation expired.',409);
			}
			$revision=$this->nextRevision($meta['revision']);
			$expiresAt=$this->plusSeconds($now,max($this->leaseSeconds,$minimumLeaseSeconds));
			$statement=$this->execute("UPDATE {$this->prefix}_reservations SET lease_revision = :revision, lease_expires_at = :expires_at, updated_at = :updated_at WHERE id = :id AND reservation_status = 'pending' AND lease_revision = :expected_revision",[
				'revision'=>$revision,'expires_at'=>$expiresAt,'updated_at'=>$now,'id'=>$reservationId,'expected_revision'=>$expectedLeaseRevision,
			]);
			if($statement->rowCount()!==1){ throw $this->corrupt(); }
			$this->updateMeta($meta,$revision,$meta['audit_sequence'],$meta['audit_head']);
			$this->recordChange('reservation.renewed','reservation',$reservationId,$revision);
			return PanelAgentStoreReservation::acquired($reservationId,$revision,$expiresAt);
		});
	}

	public function complete(string $reservationId,PanelAgentExecutionResult $result,PanelAgentRequestContext $actor,string $auditEvent,string $auditCode,array $auditDetails,int $occurredAt,int $expectedRevision):PanelAgentExecutionResult {
		$reservationId=PanelAgentGuard::identifier($reservationId,'reservation id',128);
		$auditEvent=PanelAgentGuard::identifier($auditEvent,'audit event',96);
		$auditCode=PanelAgentGuard::identifier($auditCode,'audit code',96);
		if($occurredAt<0){ throw new \InvalidArgumentException('Panel agent audit timestamp is invalid.'); }
		$auditDetails=$this->durableDetails($auditDetails);
		$now=$this->now();
		return $this->transaction(true,function() use ($reservationId,$result,$actor,$auditEvent,$auditCode,$auditDetails,$occurredAt,$expectedRevision,$now):PanelAgentExecutionResult {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$this->auditFromStorage($meta);
			$row=$this->reservationRow($reservationId,true);
			if($row===null){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			$reservation=$this->hydrateReservation($row);
			if($reservation['status']!=='pending'){ throw new PanelAgentException('reservation_invalid','Panel agent execution reservation is invalid.',409); }
			if($expectedRevision!==$reservation['lease_revision']){
				throw new PanelAgentException('revision_conflict','Panel agent execution lease revision is invalid.',409);
			}
			if($now>=$reservation['lease_expires_at']){
				throw new PanelAgentException('reservation_expired','Panel agent execution reservation expired.',409);
			}
			if(!hash_equals($reservation['plan_hash'],$result->planHash())||$result->receipt()!==null||$result->storeRevision()!==$expectedRevision||!hash_equals($auditCode,$result->code())||$auditEvent!==($result->ok()?'execution_completed':'execution_failed')){
				throw new PanelAgentException('reservation_result_invalid','Panel agent execution result does not match its reservation.',409);
			}
			if(!hash_equals($reservation['scope'],$actor->scopeFingerprint())){
				throw new PanelAgentException('reservation_scope_mismatch','Panel agent execution actor does not match its reservation.',403);
			}
			$this->assertCapacity($meta['audit_sequence'],$this->maxEntries*4,'audit');
			$revision=$this->nextRevision($meta['revision']);
			$receipt=PanelAgentAuditReceipt::create($meta['audit_sequence']+1,$auditEvent,$actor,$reservation['plan_hash'],$auditCode,$auditDetails,$meta['audit_head'],$occurredAt);
			$completed=$result->withReceipt($receipt,$revision);
			[$resultJson,$resultDigest,$resultBytes]=$this->encodeResult($completed);
			$this->insertAudit($receipt);
			$statement=$this->execute("UPDATE {$this->prefix}_reservations SET reservation_status = 'completed', result_digest = :result_digest, result_json = :result_json, result_bytes = :result_bytes, updated_at = :updated_at, completed_at = :completed_at WHERE id = :id AND reservation_status = 'pending' AND lease_revision = :lease_revision",[
				'result_digest'=>$resultDigest,'result_json'=>$resultJson,'result_bytes'=>$resultBytes,'updated_at'=>$now,'completed_at'=>$now,'id'=>$reservationId,'lease_revision'=>$expectedRevision,
			]);
			if($statement->rowCount()!==1){ throw $this->corrupt(); }
			$this->updateMeta($meta,$revision,$receipt->sequence(),$receipt->hash());
			$this->recordChange('reservation.completed','reservation',$reservationId,$revision);
			return $completed;
		});
	}

	public function cancel(string $planHash,PanelAgentAuditReceipt $receipt,int $expectedRevision):int {
		$planHash=PanelAgentGuard::digest($planHash,'cancelled plan hash');
		$this->assertDurableReceipt($receipt);
		return $this->transaction(true,function() use ($planHash,$receipt,$expectedRevision):int {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$audit=$this->auditFromStorage($meta);
			$this->assertRevision($meta,$expectedRevision);
			$existing=$this->cancellationRow($planHash,true);
			if($existing!==null){
				$this->hydrateCancellation($existing,$audit);
				return $meta['revision'];
			}
			if(!hash_equals($receipt->planHash(),$planHash)||$receipt->event()!=='plan_cancelled'){
				throw new PanelAgentException('audit_chain_invalid','Panel agent cancellation receipt is invalid.',409);
			}
			$this->assertCapacity($this->tableCount('cancellations'),$this->maxEntries,'cancellation');
			$this->assertCapacity($meta['audit_sequence'],$this->maxEntries*4,'audit');
			$this->assertNextReceipt($meta,$receipt);
			$this->insertAudit($receipt);
			$this->execute("INSERT INTO {$this->prefix}_cancellations (plan_hash, occurred_at, audit_sequence, receipt_hash) VALUES (:plan_hash, :occurred_at, :audit_sequence, :receipt_hash)",[
				'plan_hash'=>$planHash,'occurred_at'=>$receipt->occurredAt(),'audit_sequence'=>$receipt->sequence(),'receipt_hash'=>$receipt->hash(),
			]);
			$revision=$this->nextRevision($meta['revision']);
			$this->updateMeta($meta,$revision,$receipt->sequence(),$receipt->hash());
			$this->recordChange('plan.cancelled','plan',$planHash,$revision);
			return $revision;
		});
	}

	public function cancelled(string $planHash):bool {
		$planHash=PanelAgentGuard::digest($planHash,'plan hash');
		return $this->transaction(false,function() use ($planHash):bool {
			$this->assertSchema(false);
			$meta=$this->meta(false);
			$audit=$this->auditFromStorage($meta);
			$row=$this->cancellationRow($planHash,false);
			if($row===null){ return false; }
			$this->hydrateCancellation($row,$audit);
			return true;
		});
	}

	/**
	 * Explicitly removes completed and long-abandoned replay state. Audit rows
	 * remain append-only; cancellation tombstones require an explicit opt-in.
	 *
	 * @return array<string,int|bool>
	 */
	public function collectGarbage(int $limit=1000,bool $pruneCancellations=false):array {
		if($limit<1||$limit>100000){
			throw new \InvalidArgumentException('Panel agent garbage collection limit must be between 1 and 100000.');
		}
		$now=$this->now();
		$threshold=max(0,$now-$this->retentionSeconds);
		return $this->transaction(true,function() use ($limit,$pruneCancellations,$threshold):array {
			$this->assertSchema(false);
			$meta=$this->meta(true);
			$audit=$this->auditFromStorage($meta);
			$candidates=[];
			foreach($this->rows("SELECT * FROM {$this->prefix}_reservations ORDER BY id ASC") as $row){
				$reservation=$this->hydrateReservation($row);
				$at=$reservation['status']==='completed'?$reservation['completed_at']:$reservation['lease_expires_at'];
				if(is_int($at)&&$at<=$threshold){ $candidates[]=['at'=>$at,'id'=>$reservation['id'],'status'=>$reservation['status'],'nonces'=>count($reservation['nonce_tags'])]; }
			}
			usort($candidates,static fn(array $left,array $right):int=>[$left['at'],$left['id']]<=>[$right['at'],$right['id']]);
			$completed=0;
			$abandoned=0;
			$nonces=0;
			$removed=0;
			foreach(array_slice($candidates,0,$limit) as $candidate){
				$this->deleteReservation($candidate['id']);
				$candidate['status']==='completed'?$completed++:$abandoned++;
				$nonces+=$candidate['nonces'];
				$removed++;
			}
			$cancellations=0;
			if($pruneCancellations&&$removed<$limit){
				$activePlans=[];
				foreach($this->rows("SELECT plan_hash FROM {$this->prefix}_reservations") as $row){
					$plan=is_string($row['plan_hash']??null)?PanelAgentGuard::digest($row['plan_hash'],'reservation plan hash'):throw $this->corrupt();
					$activePlans[$plan]=true;
				}
				$cancelCandidates=[];
				foreach($this->rows("SELECT plan_hash, occurred_at, audit_sequence, receipt_hash FROM {$this->prefix}_cancellations") as $row){
					$cancellation=$this->hydrateCancellation($row,$audit);
					if($cancellation['occurred_at']<=$threshold&&!isset($activePlans[$cancellation['plan_hash']])){ $cancelCandidates[]=$cancellation; }
				}
				usort($cancelCandidates,static fn(array $left,array $right):int=>[$left['occurred_at'],$left['plan_hash']]<=>[$right['occurred_at'],$right['plan_hash']]);
				foreach(array_slice($cancelCandidates,0,$limit-$removed) as $candidate){
					if($this->execute("DELETE FROM {$this->prefix}_cancellations WHERE plan_hash = :plan_hash AND audit_sequence = :audit_sequence",['plan_hash'=>$candidate['plan_hash'],'audit_sequence'=>$candidate['audit_sequence']])->rowCount()!==1){ throw $this->corrupt(); }
					$cancellations++;
					$removed++;
				}
			}
			$revision=$meta['revision'];
			if($removed>0){
				$revision=$this->nextRevision($revision);
				$this->updateMeta($meta,$revision,$meta['audit_sequence'],$meta['audit_head']);
				$this->recordChange('workflow.gc','store','global',$revision);
			}
			return [
				'changed'=>$removed>0,
				'revision'=>$revision,
				'completed_reservations'=>$completed,
				'abandoned_reservations'=>$abandoned,
				'nonce_tombstones'=>$nonces,
				'cancellations'=>$cancellations,
				'audit_receipts_retained'=>count($audit),
			];
		});
	}

	/** @return array{cursor:int,oldest_cursor:int,reset_required:bool,changes:list<array<string,mixed>>,snapshot:?array<string,mixed>} */
	public function changesSince(int $cursor=0,int $limit=100):array {
		$cursor=max(0,$cursor);
		$limit=max(1,min(1000,$limit));
		return $this->transaction(false,function() use ($cursor,$limit):array {
			$this->assertSchema(false);
			$bounds=$this->row("SELECT MIN(change_id) AS oldest, MAX(change_id) AS current FROM {$this->prefix}_changes");
			$oldest=$bounds===null||$bounds['oldest']===null?0:$this->integer($bounds['oldest'],0);
			$current=$bounds===null||$bounds['current']===null?0:$this->integer($bounds['current'],0);
			$reset=$cursor>$current||($cursor>0&&$oldest>0&&$cursor<$oldest-1);
			$changes=[];
			if(!$reset){
				foreach($this->rows("SELECT change_id, event_type, entity_type, entity_id, global_revision, occurred_at FROM {$this->prefix}_changes WHERE change_id > :cursor ORDER BY change_id ASC LIMIT {$limit}",['cursor'=>$cursor]) as $row){
					$changes[]=$this->hydrateChange($row);
				}
			}
			$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;
			return [
				'cursor'=>$next,
				'oldest_cursor'=>$oldest,
				'reset_required'=>$reset,
				'changes'=>$changes,
				'snapshot'=>$reset?['type'=>'panel_pdo_agent_workflow_reset','schema_version'=>1,'cursor'=>$current,'resync'=>'audit_and_active_workflows']:null,
			];
		});
	}

	public function cursor():int {
		$feed=$this->changesSince(PHP_INT_MAX,1);
		return (int)($feed['snapshot']['cursor']??$feed['cursor']);
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		return [
			'type'=>'panel_pdo_agent_workflow_store',
			'version'=>1,
			'adapter'=>'pdo',
			'driver'=>$this->driver,
			'durable'=>true,
			'distributed'=>true,
			'cross_process'=>true,
			'shared_database'=>true,
			'optimistic_revisions'=>true,
			'global_revision_fence'=>true,
			'renewable_fenced_reservations'=>true,
			'expired_reclaim'=>true,
			'late_owner_rejection'=>true,
			'scope_bound_idempotency'=>true,
			'idempotency_digest'=>'sha256_domain_separated',
			'nonce_digest'=>'sha256_domain_separated',
			'raw_idempotency_keys_stored'=>false,
			'raw_intent_nonces_stored'=>false,
			'durable_result_lookup'=>true,
			'durable_cancellation'=>true,
			'audit_hash_chain'=>true,
			'integrity_digests'=>true,
			'replay_material_redacted'=>true,
			'explicit_gc'=>true,
			'change_feed'=>true,
			'change_feed_payloads_stored'=>false,
			'change_retention'=>$this->changeRetention,
			'lease_seconds'=>$this->leaseSeconds,
			'max_entries'=>$this->maxEntries,
			'retention_seconds'=>$this->retentionSeconds,
			'maximum_result_bytes'=>$this->maximumResultBytes,
			'maximum_audit_bytes'=>$this->maximumAuditBytes,
			'schema_version'=>self::SCHEMA_VERSION,
			'schema_migration'=>'explicit_idempotent',
			'automatic_schema_mutation'=>false,
			'transaction_ownership_required'=>true,
			'transaction_retries'=>$this->transactionRetries,
			'retry_delay_microseconds'=>$this->retryDelayMicroseconds,
			'at_rest_encryption'=>'host_database',
			'connection_details_serialized'=>false,
			'credentials_serialized'=>false,
			'table_prefix_serialized'=>false,
			'sql_serialized'=>false,
			'live_counts_queried'=>false,
			'adapter_callbacks_invoked'=>false,
			'capabilities'=>[
				'atomic_optimistic_revisions'=>true,
				'cross_process_locking'=>true,
				'shared_database'=>true,
				'renewable_fenced_reservations'=>true,
				'expired_reclaim'=>true,
				'late_owner_rejection'=>true,
				'scope_bound_idempotency'=>true,
				'durable_result_lookup'=>true,
				'durable_cancellation'=>true,
				'audit_hash_chain'=>true,
				'integrity_digests'=>true,
				'replay_material_redacted'=>true,
				'explicit_gc'=>true,
				'change_feed'=>true,
				'adapter_callbacks_invoked'=>false,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array { return $this->manifest(); }

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

	/** @return array{revision:int,audit_sequence:int,audit_head:string,updated_at:int} */
	private function meta(bool $lock):array {
		$row=$this->row("SELECT singleton, schema_version, global_revision, audit_sequence, audit_head, updated_at FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));
		if($row===null){ throw $this->storage('schema_required','Panel PDO agent workflow schema is not installed.'); }
		$singleton=$this->integer($row['singleton']??null,1);
		$version=$this->integer($row['schema_version']??null,1);
		$revision=$this->integer($row['global_revision']??null,0);
		$sequence=$this->integer($row['audit_sequence']??null,0);
		$updatedAt=$this->integer($row['updated_at']??null,0);
		$head=$row['audit_head']??null;
		if($singleton!==1||$version!==self::SCHEMA_VERSION||!is_string($head)||$revision<$sequence){ throw $this->corrupt(); }
		if($sequence===0){ if($head!==''){ throw $this->corrupt(); } }
		elseif(PanelAgentGuard::digest($head,'audit head')!==$head){ throw $this->corrupt(); }
		return ['revision'=>$revision,'audit_sequence'=>$sequence,'audit_head'=>$head,'updated_at'=>$updatedAt];
	}

	/** @param array{revision:int,audit_sequence:int,audit_head:string,updated_at:int} $meta @return list<PanelAgentAuditReceipt> */
	private function auditFromStorage(array $meta):array {
		$rows=$this->rows("SELECT sequence, event_type, plan_hash, scope_fingerprint, receipt_hash, previous_hash, receipt_digest, receipt_json, receipt_bytes, occurred_at FROM {$this->prefix}_audit ORDER BY sequence ASC");
		if(count($rows)!==$meta['audit_sequence']||count($rows)>$this->maxEntries*4){ throw $this->corrupt(); }
		$audit=[];
		$previous='';
		foreach($rows as $index=>$row){
			$receipt=$this->hydrateAuditRow($row);
			if($receipt->sequence()!==$index+1||!$receipt->verify($previous)){ throw $this->corrupt(); }
			$audit[]=$receipt;
			$previous=$receipt->hash();
		}
		if(!hash_equals($meta['audit_head'],$previous)){ throw $this->corrupt(); }
		if($this->tableCount('reservations')>$this->maxEntries||$this->tableCount('cancellations')>$this->maxEntries||$this->tableCount('nonces')>$this->maxEntries*3){ throw $this->corrupt(); }
		return $audit;
	}

	/** @param array<string,mixed> $row */
	private function hydrateAuditRow(array $row):PanelAgentAuditReceipt {
		foreach(['event_type','plan_hash','scope_fingerprint','receipt_hash','previous_hash','receipt_digest','receipt_json'] as $key){
			if(!array_key_exists($key,$row)||!is_string($row[$key])){ throw $this->corrupt(); }
		}
		$sequence=$this->integer($row['sequence']??null,1);
		$bytes=$this->integer($row['receipt_bytes']??null,1);
		$occurredAt=$this->integer($row['occurred_at']??null,0);
		if($bytes!==strlen($row['receipt_json'])||$bytes>$this->maximumAuditBytes||PanelAgentGuard::digest($row['receipt_digest'],'audit receipt digest')!==$row['receipt_digest']||!hash_equals($row['receipt_digest'],hash('sha256',$row['receipt_json']))){ throw $this->corrupt(); }
		try{
			$payload=json_decode($row['receipt_json'],true,64,JSON_THROW_ON_ERROR);
			if(!is_array($payload)||array_is_list($payload)){ throw new \UnexpectedValueException('Invalid receipt payload.'); }
			$receipt=PanelAgentAuditReceipt::fromArray($payload);
			$this->assertDurableReceipt($receipt);
		}catch(\Throwable){ throw $this->corrupt(); }
		if(!hash_equals($row['receipt_json'],PanelAgentGuard::canonicalJson($receipt->jsonSerialize()))||$receipt->sequence()!==$sequence||$receipt->event()!==$row['event_type']||!hash_equals($receipt->planHash(),$row['plan_hash'])||!hash_equals($receipt->scopeFingerprint(),$row['scope_fingerprint'])||!hash_equals($receipt->hash(),$row['receipt_hash'])||!hash_equals($receipt->previousHash(),$row['previous_hash'])||$receipt->occurredAt()!==$occurredAt){ throw $this->corrupt(); }
		return $receipt;
	}

	private function insertAudit(PanelAgentAuditReceipt $receipt):void {
		[$json,$digest,$bytes]=$this->encodeReceipt($receipt);
		try{
			$this->execute("INSERT INTO {$this->prefix}_audit (sequence, event_type, plan_hash, scope_fingerprint, receipt_hash, previous_hash, receipt_digest, receipt_json, receipt_bytes, occurred_at) VALUES (:sequence, :event_type, :plan_hash, :scope_fingerprint, :receipt_hash, :previous_hash, :receipt_digest, :receipt_json, :receipt_bytes, :occurred_at)",[
				'sequence'=>$receipt->sequence(),'event_type'=>$receipt->event(),'plan_hash'=>$receipt->planHash(),'scope_fingerprint'=>$receipt->scopeFingerprint(),'receipt_hash'=>$receipt->hash(),'previous_hash'=>$receipt->previousHash(),'receipt_digest'=>$digest,'receipt_json'=>$json,'receipt_bytes'=>$bytes,'occurred_at'=>$receipt->occurredAt(),
			]);
		}catch(\PDOException $error){
			if($this->duplicate($error)){ throw $this->corrupt(); }
			throw $error;
		}
	}

	/** @return array{0:string,1:string,2:int} */
	private function encodeReceipt(PanelAgentAuditReceipt $receipt):array {
		$this->assertDurableReceipt($receipt);
		$json=PanelAgentGuard::canonicalJson($receipt->jsonSerialize());
		$bytes=strlen($json);
		if($bytes<1||$bytes>$this->maximumAuditBytes){ throw $this->storage('audit_too_large','Panel agent audit receipt exceeds the configured byte bound.'); }
		return [$json,hash('sha256',$json),$bytes];
	}

	/** @return array<string,mixed>|null */
	private function reservationByIdempotency(string $hash,bool $lock):?array {
		return $this->row("SELECT * FROM {$this->prefix}_reservations WHERE idempotency_hash = :idempotency_hash".($lock?$this->dialect['lock_suffix']:''),['idempotency_hash'=>$hash]);
	}

	/** @return array<string,mixed>|null */
	private function reservationRow(string $id,bool $lock):?array {
		return $this->row("SELECT * FROM {$this->prefix}_reservations WHERE id = :id".($lock?$this->dialect['lock_suffix']:''),['id'=>$id]);
	}

	/** @param array<string,mixed> $row @return array{id:string,plan_hash:string,scope:string,key_hash:string,request_hash:string,nonce_tags:list<string>,lease_revision:int,lease_expires_at:int,status:string,result_json:?string,result_digest:?string,result_bytes:?int,created_at:int,updated_at:int,completed_at:?int} */
	private function hydrateReservation(array $row):array {
		foreach(['id','plan_hash','scope_fingerprint','idempotency_hash','request_hash','reservation_status'] as $key){
			if(!isset($row[$key])||!is_string($row[$key])){ throw $this->corrupt(); }
		}
		try{
			$id=PanelAgentGuard::identifier($row['id'],'reservation id',128);
			$plan=PanelAgentGuard::digest($row['plan_hash'],'reservation plan hash');
			$scope=PanelAgentGuard::digest($row['scope_fingerprint'],'reservation scope');
			$keyHash=PanelAgentGuard::digest($row['idempotency_hash'],'idempotency hash');
			$request=PanelAgentGuard::digest($row['request_hash'],'request hash');
		}catch(\Throwable){ throw $this->corrupt(); }
		if($id!==$row['id']||$plan!==$row['plan_hash']||$scope!==$row['scope_fingerprint']||$keyHash!==$row['idempotency_hash']||$request!==$row['request_hash']||!in_array($row['reservation_status'],['pending','completed'],true)){ throw $this->corrupt(); }
		$leaseRevision=$this->integer($row['lease_revision']??null,1);
		$leaseExpiresAt=$this->integer($row['lease_expires_at']??null,1);
		$createdAt=$this->integer($row['created_at']??null,0);
		$updatedAt=$this->integer($row['updated_at']??null,0);
		if($updatedAt<$createdAt){ throw $this->corrupt(); }
		$nonceTags=[];
		foreach($this->rows("SELECT nonce_hash FROM {$this->prefix}_nonces WHERE reservation_id = :reservation_id ORDER BY nonce_hash ASC",['reservation_id'=>$id]) as $nonceRow){
			$tag=$nonceRow['nonce_hash']??null;
			if(!is_string($tag)||PanelAgentGuard::digest($tag,'nonce tag')!==$tag){ throw $this->corrupt(); }
			$nonceTags[]=$tag;
		}
		if($nonceTags===[]||count($nonceTags)>3||count(array_unique($nonceTags))!==count($nonceTags)){ throw $this->corrupt(); }
		$resultJson=$row['result_json']??null;
		$resultDigest=$row['result_digest']??null;
		$resultBytes=$row['result_bytes']??null;
		$completedAt=$row['completed_at']??null;
		if($row['reservation_status']==='pending'){
			if($resultJson!==null||$resultDigest!==null||$resultBytes!==null||$completedAt!==null){ throw $this->corrupt(); }
			$resultBytes=null;
			$completedAt=null;
		}else{
			if(!is_string($resultJson)||!is_string($resultDigest)){ throw $this->corrupt(); }
			$resultBytes=$this->integer($resultBytes,1);
			$completedAt=$this->integer($completedAt,0);
			if($completedAt<$createdAt||$updatedAt!==$completedAt||$resultBytes!==strlen($resultJson)||$resultBytes>$this->maximumResultBytes||PanelAgentGuard::digest($resultDigest,'result digest')!==$resultDigest||!hash_equals($resultDigest,hash('sha256',$resultJson))){ throw $this->corrupt(); }
		}
		return [
			'id'=>$id,'plan_hash'=>$plan,'scope'=>$scope,'key_hash'=>$keyHash,'request_hash'=>$request,'nonce_tags'=>$nonceTags,
			'lease_revision'=>$leaseRevision,'lease_expires_at'=>$leaseExpiresAt,'status'=>$row['reservation_status'],'result_json'=>$resultJson,'result_digest'=>$resultDigest,'result_bytes'=>$resultBytes,
			'created_at'=>$createdAt,'updated_at'=>$updatedAt,'completed_at'=>$completedAt,
		];
	}

	/** @param array{id:string,plan_hash:string,scope:string,key_hash:string,request_hash:string,nonce_tags:list<string>,lease_revision:int,lease_expires_at:int,status:string,result_json:?string,result_digest:?string,result_bytes:?int,created_at:int,updated_at:int,completed_at:?int} $reservation @param list<PanelAgentAuditReceipt> $audit */
	private function decodeResult(array $reservation,array $audit,int $stateRevision):PanelAgentExecutionResult {
		if($reservation['status']!=='completed'||!is_string($reservation['result_json'])){ throw $this->corrupt(); }
		try{
			$payload=json_decode($reservation['result_json'],true,64,JSON_THROW_ON_ERROR);
		}catch(\Throwable){ throw $this->corrupt(); }
		$keys=is_array($payload)?array_keys($payload):[];
		sort($keys,SORT_STRING);
		if($keys!==['code','metadata','ok','plan_hash','receipt','replayed','steps','store_revision','type','version']||$payload['type']!=='panel_agent_execution_result'||$payload['version']!==1||!is_bool($payload['ok'])||!is_string($payload['code'])||!is_string($payload['plan_hash'])||!is_array($payload['steps'])||!array_is_list($payload['steps'])||$payload['replayed']!==false||!is_int($payload['store_revision'])||$payload['store_revision']<1||$payload['store_revision']>$stateRevision||!is_array($payload['receipt'])||!is_array($payload['metadata'])||($payload['metadata']!==[]&&array_is_list($payload['metadata']))){ throw $this->corrupt(); }
		try{ $receipt=PanelAgentAuditReceipt::fromArray($payload['receipt']); }
		catch(\Throwable){ throw $this->corrupt(); }
		$storedReceipt=$audit[$receipt->sequence()-1]??null;
		if(!$storedReceipt instanceof PanelAgentAuditReceipt||!hash_equals($storedReceipt->hash(),$receipt->hash())||!hash_equals($reservation['plan_hash'],$payload['plan_hash'])||!hash_equals($reservation['plan_hash'],$receipt->planHash())||!hash_equals($reservation['scope'],$receipt->scopeFingerprint())||!hash_equals($payload['code'],$receipt->code())||$receipt->event()!==($payload['ok']?'execution_completed':'execution_failed')){ throw $this->corrupt(); }
		$result=PanelAgentExecutionResult::make($payload['ok'],$payload['code'],$payload['plan_hash'],$payload['steps'],$payload['store_revision'],null,$payload['metadata'])->withReceipt($receipt,$payload['store_revision']);
		if(!hash_equals($reservation['result_json'],$this->encodeResult($result)[0])||$result->storeRevision()<=$reservation['lease_revision']){ throw $this->corrupt(); }
		return $result;
	}

	/** @return array{0:string,1:string,2:int} */
	private function encodeResult(PanelAgentExecutionResult $result):array {
		$payload=$result->jsonSerialize();
		$payload['receipt']=$result->receipt()?->jsonSerialize();
		try{
			PanelAgentGuard::assertJson($payload,$this->maximumResultBytes);
			$json=PanelAgentGuard::canonicalJson($payload);
		}catch(\LengthException){
			throw $this->storage('result_too_large','Panel agent execution result exceeds the configured byte bound.');
		}catch(\Throwable){
			throw $this->storage('result_invalid','Panel agent execution result could not be encoded.');
		}
		$bytes=strlen($json);
		if($bytes<1||$bytes>$this->maximumResultBytes){ throw $this->storage('result_too_large','Panel agent execution result exceeds the configured byte bound.'); }
		return [$json,hash('sha256',$json),$bytes];
	}

	/** @param list<string> $nonceTags */
	private function insertReservation(string $id,string $planHash,string $scope,string $keyHash,string $requestHash,array $nonceTags,int $revision,int $expiresAt,int $now):void {
		try{
			$this->execute("INSERT INTO {$this->prefix}_reservations (id, plan_hash, scope_fingerprint, idempotency_hash, request_hash, lease_revision, lease_expires_at, reservation_status, result_digest, result_json, result_bytes, created_at, updated_at, completed_at) VALUES (:id, :plan_hash, :scope_fingerprint, :idempotency_hash, :request_hash, :lease_revision, :lease_expires_at, 'pending', NULL, NULL, NULL, :created_at, :updated_at, NULL)",[
				'id'=>$id,'plan_hash'=>$planHash,'scope_fingerprint'=>$scope,'idempotency_hash'=>$keyHash,'request_hash'=>$requestHash,'lease_revision'=>$revision,'lease_expires_at'=>$expiresAt,'created_at'=>$now,'updated_at'=>$now,
			]);
		}catch(\PDOException $error){
			if($this->duplicate($error)){ throw new PanelAgentException('reservation_id_collision','Panel agent reservation allocation conflicted with existing replay state.',503); }
			throw $error;
		}
		foreach($nonceTags as $tag){
			try{ $this->execute("INSERT INTO {$this->prefix}_nonces (nonce_hash, reservation_id) VALUES (:nonce_hash, :reservation_id)",['nonce_hash'=>$tag,'reservation_id'=>$id]); }
			catch(\PDOException $error){ if($this->duplicate($error)){ throw new PanelAgentException('intent_replayed','A Panel agent signed intent was already consumed.',409); } throw $error; }
		}
	}

	private function deleteReservation(string $id):void {
		$this->execute("DELETE FROM {$this->prefix}_nonces WHERE reservation_id = :reservation_id",['reservation_id'=>$id]);
		if($this->execute("DELETE FROM {$this->prefix}_reservations WHERE id = :id",['id'=>$id])->rowCount()!==1){ throw $this->corrupt(); }
	}

	private function nonceOwner(string $tag,bool $lock):?string {
		$row=$this->row("SELECT reservation_id FROM {$this->prefix}_nonces WHERE nonce_hash = :nonce_hash".($lock?$this->dialect['lock_suffix']:''),['nonce_hash'=>$tag]);
		if($row===null){ return null; }
		$id=$row['reservation_id']??null;
		if(!is_string($id)){ throw $this->corrupt(); }
		try{ $canonical=PanelAgentGuard::identifier($id,'reservation id',128); }
		catch(\Throwable){ throw $this->corrupt(); }
		if($canonical!==$id){ throw $this->corrupt(); }
		return $id;
	}

	/** @return array<string,mixed>|null */
	private function cancellationRow(string $planHash,bool $lock):?array {
		return $this->row("SELECT plan_hash, occurred_at, audit_sequence, receipt_hash FROM {$this->prefix}_cancellations WHERE plan_hash = :plan_hash".($lock?$this->dialect['lock_suffix']:''),['plan_hash'=>$planHash]);
	}

	/** @param array<string,mixed> $row @param list<PanelAgentAuditReceipt> $audit @return array{plan_hash:string,occurred_at:int,audit_sequence:int,receipt_hash:string} */
	private function hydrateCancellation(array $row,array $audit):array {
		$plan=$row['plan_hash']??null;
		$hash=$row['receipt_hash']??null;
		if(!is_string($plan)||!is_string($hash)){ throw $this->corrupt(); }
		try{ $plan=PanelAgentGuard::digest($plan,'cancelled plan hash'); $hash=PanelAgentGuard::digest($hash,'cancellation receipt hash'); }
		catch(\Throwable){ throw $this->corrupt(); }
		$occurredAt=$this->integer($row['occurred_at']??null,0);
		$sequence=$this->integer($row['audit_sequence']??null,1);
		$receipt=$audit[$sequence-1]??null;
		if(!$receipt instanceof PanelAgentAuditReceipt||$receipt->event()!=='plan_cancelled'||!hash_equals($receipt->planHash(),$plan)||!hash_equals($receipt->hash(),$hash)||$receipt->occurredAt()!==$occurredAt){ throw $this->corrupt(); }
		return ['plan_hash'=>$plan,'occurred_at'=>$occurredAt,'audit_sequence'=>$sequence,'receipt_hash'=>$hash];
	}

	/** @param array{revision:int,audit_sequence:int,audit_head:string,updated_at:int} $meta */
	private function updateMeta(array $meta,int $revision,int $auditSequence,string $auditHead):void {
		$statement=$this->execute("UPDATE {$this->prefix}_meta SET global_revision = :revision, audit_sequence = :audit_sequence, audit_head = :audit_head, updated_at = :updated_at WHERE singleton = 1 AND global_revision = :expected_revision AND audit_sequence = :expected_audit_sequence AND audit_head = :expected_audit_head",[
			'revision'=>$revision,'audit_sequence'=>$auditSequence,'audit_head'=>$auditHead,'updated_at'=>$this->now(),'expected_revision'=>$meta['revision'],'expected_audit_sequence'=>$meta['audit_sequence'],'expected_audit_head'=>$meta['audit_head'],
		]);
		if($statement->rowCount()!==1){ throw $this->corrupt(); }
	}

	private function recordChange(string $event,string $entityType,string $entityId,int $revision):void {
		$event=PanelAgentGuard::identifier($event,'change event',96);
		$entityType=PanelAgentGuard::identifier($entityType,'change entity type',32);
		$entityId=PanelAgentGuard::boundedString($entityId,'change entity id',190);
		$parameters=['event_type'=>$event,'entity_type'=>$entityType,'entity_id'=>$entityId,'global_revision'=>$revision,'occurred_at'=>$this->now()];
		$sql="INSERT INTO {$this->prefix}_changes (event_type, entity_type, entity_id, global_revision, occurred_at) VALUES (:event_type, :entity_type, :entity_id, :global_revision, :occurred_at)";
		if($this->driver==='pgsql'){
			$value=$this->execute($sql.' RETURNING change_id',$parameters)->fetchColumn();
		}else{
			$this->execute($sql,$parameters);
			$value=$this->pdo->lastInsertId();
		}
		$cursor=$this->integer($value,1);
		$cutoff=$cursor-$this->changeRetention;
		if($cutoff>0){ $this->execute("DELETE FROM {$this->prefix}_changes WHERE change_id <= :cutoff",['cutoff'=>$cutoff]); }
	}

	/** @param array<string,mixed> $row @return array{cursor:int,type:string,entity_type:string,entity_id:string,revision:int,occurred_at:int} */
	private function hydrateChange(array $row):array {
		foreach(['event_type','entity_type','entity_id'] as $key){
			if(!isset($row[$key])||!is_string($row[$key])||$row[$key]===''){ throw $this->corrupt(); }
		}
		try{
			$event=PanelAgentGuard::identifier($row['event_type'],'change event',96);
			$type=PanelAgentGuard::identifier($row['entity_type'],'change entity type',32);
			$id=PanelAgentGuard::boundedString($row['entity_id'],'change entity id',190);
		}catch(\Throwable){ throw $this->corrupt(); }
		if($event!==$row['event_type']||$type!==$row['entity_type']||$id!==$row['entity_id']){ throw $this->corrupt(); }
		return ['cursor'=>$this->integer($row['change_id']??null,1),'type'=>$event,'entity_type'=>$type,'entity_id'=>$id,'revision'=>$this->integer($row['global_revision']??null,1),'occurred_at'=>$this->integer($row['occurred_at']??null,0)];
	}

	private function assertSchema(bool $lock):void {
		try{
			$row=$this->row("SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''));
		}catch(\PDOException $error){
			if($this->missingRelation($error)){ throw $this->storage('schema_required','Panel PDO agent workflow schema is not installed.'); }
			throw $error;
		}
		if($row===null){ throw $this->storage('schema_required','Panel PDO agent workflow schema is not installed.'); }
		$version=$this->integer($row['schema_version']??null,1);
		if($version!==self::SCHEMA_VERSION){ throw $this->storage('schema_incompatible','Panel PDO agent workflow schema version is incompatible.'); }
	}

	private function transaction(bool $write,callable $callback):mixed {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict','Panel PDO agent workflow store requires transaction ownership.',true);
		}
		for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){
			try{
				$this->beginTransaction($write);
				$result=$callback();
				$this->commitTransaction();
				return $result;
			}catch(\PDOException $error){
				$this->rollbackTransaction();
				if($attempt<$this->transactionRetries&&$this->transient($error)){
					if($this->retryDelayMicroseconds>0){ usleep(min(100000,$this->retryDelayMicroseconds*($attempt+1))); }
					continue;
				}
				if($this->missingRelation($error)){ throw $this->storage('schema_required','Panel PDO agent workflow schema is not installed.'); }
				throw $this->storage('storage_unavailable','Panel PDO agent workflow storage is unavailable.',true);
			}catch(\Throwable $error){
				$this->rollbackTransaction();
				throw $error;
			}
		}
	}

	private function beginTransaction(bool $write):void {
		foreach($write?[]:$this->dialect['read_before'] as $sql){
			if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO transaction configuration failed.'); }
		}
		if($write&&$this->dialect['write_begin']!==null){
			if($this->pdo->exec($this->dialect['write_begin'])===false){ throw new \RuntimeException('PDO transaction begin failed.'); }
			$this->manualSqliteWriteTransaction=true;
		}elseif(!$this->pdo->beginTransaction()){
			throw new \RuntimeException('PDO transaction begin failed.');
		}
		foreach($write?[]:$this->dialect['read_after'] as $sql){
			if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO transaction configuration failed.'); }
		}
	}

	private function commitTransaction():void {
		if($this->manualSqliteWriteTransaction){
			if($this->pdo->exec('COMMIT')===false){ throw new \RuntimeException('PDO commit failed.'); }
			$this->manualSqliteWriteTransaction=false;
			return;
		}
		if(!$this->pdo->commit()){ throw new \RuntimeException('PDO commit failed.'); }
	}

	private function rollbackTransaction():void {
		try{
			if($this->manualSqliteWriteTransaction){ $this->pdo->exec('ROLLBACK'); }
			elseif($this->pdo->inTransaction()){ $this->pdo->rollBack(); }
		}catch(\Throwable){}
		finally{ $this->manualSqliteWriteTransaction=false; }
	}

	private function activeTransaction():bool { return $this->manualSqliteWriteTransaction||$this->pdo->inTransaction(); }

	/** @param array<string,null|bool|int|float|string> $parameters */
	private function execute(string $sql,array $parameters=[]):\PDOStatement {
		$statement=$this->pdo->prepare($sql);
		if(!$statement instanceof \PDOStatement){ throw new \RuntimeException('PDO prepare failed.'); }
		foreach($parameters as $name=>$value){
			$type=match(true){
				$value===null=>\PDO::PARAM_NULL,
				is_bool($value)=>\PDO::PARAM_BOOL,
				is_int($value)=>\PDO::PARAM_INT,
				default=>\PDO::PARAM_STR,
			};
			$statement->bindValue(':'.$name,$value,$type);
		}
		if(!$statement->execute()){ throw new \RuntimeException('PDO execute failed.'); }
		return $statement;
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return array<string,mixed>|null */
	private function row(string $sql,array $parameters=[]):?array {
		$row=$this->execute($sql,$parameters)->fetch(\PDO::FETCH_ASSOC);
		if($row===false){ return null; }
		if(!is_array($row)||array_is_list($row)){ throw $this->corrupt(); }
		return $row;
	}

	/** @param array<string,null|bool|int|float|string> $parameters @return list<array<string,mixed>> */
	private function rows(string $sql,array $parameters=[]):array {
		$rows=$this->execute($sql,$parameters)->fetchAll(\PDO::FETCH_ASSOC);
		if(!is_array($rows)){ throw $this->corrupt(); }
		foreach($rows as $row){ if(!is_array($row)||array_is_list($row)){ throw $this->corrupt(); } }
		return array_values($rows);
	}

	private function tableCount(string $suffix):int {
		if(!in_array($suffix,['reservations','nonces','audit','cancellations'],true)){ throw new \LogicException('Unsupported Panel agent workflow table count.'); }
		$row=$this->row("SELECT COUNT(*) AS aggregate_count FROM {$this->prefix}_{$suffix}");
		return $row===null?throw $this->corrupt():$this->integer($row['aggregate_count']??null,0);
	}

	private function integer(mixed $value,int $minimum):int {
		if(is_int($value)){
			$number=$value;
		}elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=strlen((string)PHP_INT_MAX)){
			$number=(int)$value;
			if((string)$number!==$value){ throw $this->corrupt(); }
		}else{
			throw $this->corrupt();
		}
		if($number<$minimum){ throw $this->corrupt(); }
		return $number;
	}

	/** @param array<string,mixed> $details @return array<string,mixed> */
	private function durableDetails(array $details):array {
		$details=PanelAgentGuard::redact($details);
		foreach($details as $key=>$value){
			$normalized=is_string($key)?strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$key)??$key)??'','_')):'';
			if(in_array($normalized,['idempotency','idempotency_key','nonce','nonces','intent','signed_intent','plan_intent','approval_intent','approval_intents','confirmation_evidence','bearer_proof','lease_token'],true)){
				$details[$key]=PanelSensitiveDataSanitizer::REDACTED;
			}elseif(is_array($value)){
				$details[$key]=$this->durableDetails($value);
			}
		}
		return $details;
	}

	private function assertDurableReceipt(PanelAgentAuditReceipt $receipt):void {
		if(!hash_equals(PanelAgentGuard::canonicalJson($receipt->details()),PanelAgentGuard::canonicalJson($this->durableDetails($receipt->details())))){
			throw new PanelAgentException('audit_details_unsafe','Panel agent audit receipt contains unsafe replay material.',409);
		}
	}

	/** @param list<string> $nonces @return list<string> */
	private function nonceTags(array $nonces):array {
		if($nonces===[]||count($nonces)>3||count(array_unique($nonces))!==count($nonces)){
			throw new PanelAgentException('nonce_invalid','Panel agent execution nonces are invalid.',409);
		}
		$tags=[];
		foreach($nonces as $nonce){
			if(!is_string($nonce)||preg_match('/^[a-f0-9]{32}$/D',$nonce)!==1){ throw new PanelAgentException('nonce_invalid','Panel agent execution nonce is invalid.',409); }
			$tags[]=hash('sha256',"panel-agent-nonce-v1\0{$nonce}");
		}
		return $tags;
	}

	private function idempotencyHash(string $scope,string $key):string { return hash('sha256',"panel-agent-idempotency-v1\0{$scope}\0{$key}"); }

	private function reservationId():string {
		$id=($this->reservationFactory)();
		if(!is_string($id)){ throw new \UnexpectedValueException('Panel agent reservation factory must return a string.'); }
		try{ return PanelAgentGuard::identifier($id,'reservation id',128); }
		catch(\Throwable $error){ throw new \UnexpectedValueException('Panel agent reservation factory returned an invalid id.',0,$error); }
	}

	/** @param array{revision:int,audit_sequence:int,audit_head:string,updated_at:int} $meta */
	private function assertRevision(array $meta,int $expected):void {
		if($meta['revision']!==$expected){ throw new PanelAgentException('revision_conflict','Panel agent store revision is stale.',409); }
	}

	/** @param array{revision:int,audit_sequence:int,audit_head:string,updated_at:int} $meta */
	private function assertNextReceipt(array $meta,PanelAgentAuditReceipt $receipt):void {
		if($receipt->sequence()!==$meta['audit_sequence']+1||!$receipt->verify($meta['audit_head'])){
			throw new PanelAgentException('audit_chain_invalid','Panel agent audit receipt does not extend the current chain.',409);
		}
	}

	private function assertCapacity(int $count,int $limit,string $kind):void {
		if($count>=$limit){ throw new PanelAgentException('store_capacity_exceeded',"Panel agent durable {$kind} capacity was exhausted.",503); }
	}

	private function nextRevision(int $current):int {
		if($current===PHP_INT_MAX){ throw $this->storage('revision_exhausted','Panel agent workflow revision capacity is exhausted.'); }
		return $current+1;
	}

	private function now():int {
		$value=($this->clock)();
		if(!is_int($value)||$value<0){ throw new \UnexpectedValueException('Panel agent workflow store clock must return a non-negative integer timestamp.'); }
		return $value;
	}

	private function plusSeconds(int $timestamp,int $seconds):int { return $timestamp>PHP_INT_MAX-$seconds?PHP_INT_MAX:$timestamp+$seconds; }

	private function transient(\PDOException $error):bool {
		$state=strtoupper((string)$error->getCode());
		$info=$error->errorInfo;
		$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		return in_array($state,['40001','40P01','55P03'],true)
			||($this->driver==='sqlite'&&($native==='5'||$native==='6'||str_contains(strtolower($error->getMessage()),'locked')))
			||($this->driver==='mysql'&&in_array($native,['1205','1213'],true));
	}

	private function duplicate(\PDOException $error):bool {
		$state=strtoupper((string)$error->getCode());
		$info=$error->errorInfo;
		$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		return in_array($state,['23000','23505'],true)
			||in_array($native,['19','1062','2067'],true)
			||str_contains(strtolower($error->getMessage()),'unique constraint');
	}

	private function missingRelation(\PDOException $error):bool {
		$state=strtoupper((string)$error->getCode());
		$info=$error->errorInfo;
		$native=is_array($info)&&isset($info[1])?(string)$info[1]:'';
		$message=strtolower($error->getMessage());
		return in_array($state,['42P01','42S02'],true)||in_array($native,['1146'],true)||str_contains($message,'no such table');
	}

	private function corrupt():PanelAgentWorkflowStorageException { return $this->storage('storage_corrupt','Panel PDO agent workflow storage failed integrity validation.'); }
	private function storage(string $code,string $message,bool $retryable=false):PanelAgentWorkflowStorageException { return new PanelAgentWorkflowStorageException($code,$message,$retryable); }

	private static function driverName(string $driver):string {
		$driver=strtolower(trim($driver));
		if(!in_array($driver,['mysql','pgsql','sqlite'],true)){ throw new \InvalidArgumentException('Panel PDO agent workflow store supports mysql, pgsql, and sqlite only.'); }
		return $driver;
	}

	private static function prefix(string $prefix):string {
		$prefix=strtolower(trim($prefix));
		if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D',$prefix)!==1){ throw new \InvalidArgumentException('Panel PDO agent workflow table prefix is invalid.'); }
		return $prefix;
	}

	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){ throw new \InvalidArgumentException("Panel PDO agent workflow option '{$name}' is outside its supported bound."); }
		return $value;
	}
}
