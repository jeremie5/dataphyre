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
 * Durable shared-SQL migration store. Each scope is an independently locked,
 * integrity-checked document so unrelated tenants can migrate concurrently.
 * Schema installation is always an explicit host migration.
 */
final class PanelPdoMigrationStore implements PanelMigrationStore,\JsonSerializable {
	private const SCHEMA_VERSION=1;
	private const DOCUMENT_SCHEMA_VERSION=1;
	private const DEFAULT_PREFIX='panel_migrations';
	private const OPTION_NAMES=[
		'table_prefix',
		'maximum_scope_bytes',
		'change_retention',
		'transaction_retries',
		'retry_delay_microseconds',
	];

	private readonly string $driver;
	private readonly string $prefix;
	private readonly int $maximumScopeBytes;
	private readonly int $changeRetention;
	private readonly int $transactionRetries;
	private readonly int $retryDelayMicroseconds;
	private readonly \Closure $clock;
	private readonly \Closure $tokenFactory;
	private readonly \Closure $runFactory;
	private bool $manualSqliteWriteTransaction=false;
	/** @var array{write_begin:?string,read_before:list<string>,read_after:list<string>,lock_suffix:string} */
	private readonly array $dialect;

	/** @param array<string,mixed> $options */
	public function __construct(
		private readonly \PDO $pdo,
		array $options=[],
		?callable $clock=null,
		?callable $tokenFactory=null,
		?callable $runFactory=null,
	){
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){
				throw new \InvalidArgumentException('Panel PDO migration options contain an unsupported name.');
			}
		}
		try{
			$driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)));
			$errorMode=$pdo->getAttribute(\PDO::ATTR_ERRMODE);
		}catch(\Throwable $error){
			throw new \InvalidArgumentException('Panel PDO migration store could not inspect its connection.',0,$error);
		}
		if($errorMode!==\PDO::ERRMODE_EXCEPTION){
			throw new \InvalidArgumentException('Panel PDO migration store requires PDO exception mode.');
		}
		$this->driver=self::driverName($driver);
		$this->dialect=self::dialectPlanFor($driver);
		$this->prefix=self::prefix((string)($options['table_prefix']??self::DEFAULT_PREFIX));
		$this->maximumScopeBytes=self::option($options,'maximum_scope_bytes',67108864,4096,268435456);
		$this->changeRetention=self::option($options,'change_retention',4096,8,1000000);
		$this->transactionRetries=self::option($options,'transaction_retries',3,0,10);
		$this->retryDelayMicroseconds=self::option($options,'retry_delay_microseconds',2000,0,100000);
		$this->clock=\Closure::fromCallable($clock??static fn():string=>gmdate(DATE_ATOM));
		$this->tokenFactory=\Closure::fromCallable($tokenFactory??static fn():string=>bin2hex(random_bytes(32)));
		$this->runFactory=\Closure::fromCallable($runFactory??static fn():string=>'migration-'.bin2hex(random_bytes(12)));
	}

	public function driver():string { return $this->driver; }
	/** @return list<string> */
	public function schemaStatements():array { return self::schemaStatementsFor($this->driver,$this->prefix); }

	/** @return list<string> */
	public static function schemaStatementsFor(string $driver,string $prefix=self::DEFAULT_PREFIX):array {
		$driver=self::driverName($driver);
		$prefix=self::prefix($prefix);
		$meta=$prefix.'_meta';
		$scopes=$prefix.'_scopes';
		$runs=$prefix.'_run_index';
		$changes=$prefix.'_changes';
		if($driver==='mysql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton TINYINT UNSIGNED NOT NULL, schema_version INT UNSIGNED NOT NULL, PRIMARY KEY (singleton), CONSTRAINT {$meta}_singleton CHECK (singleton = 1)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$scopes} (scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, tenant_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, fence BIGINT UNSIGNED NOT NULL DEFAULT 0, document_revision BIGINT UNSIGNED NOT NULL, document_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, document_json LONGTEXT NOT NULL, document_bytes INT UNSIGNED NOT NULL, lease_owner VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, lease_fence BIGINT UNSIGNED NULL, lease_expires_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, updated_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (scope_key), KEY scope_lookup (scope_name, tenant_id), KEY lease_expiry_lookup (lease_expires_at, scope_key)) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$runs} (run_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, plan_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, started_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (run_id), KEY plan_lookup (plan_digest, started_at, run_id), CONSTRAINT {$runs}_scope FOREIGN KEY (scope_key) REFERENCES {$scopes} (scope_key) ON DELETE CASCADE) ENGINE=InnoDB",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, scope_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, tenant_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, run_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL, fence BIGINT UNSIGNED NULL, occurred_at VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, PRIMARY KEY (change_id), KEY scope_change_lookup (scope_key, change_id)) ENGINE=InnoDB",
				"INSERT IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
			];
		}
		if($driver==='pgsql'){
			return [
				"CREATE TABLE IF NOT EXISTS {$meta} (singleton SMALLINT PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
				"CREATE TABLE IF NOT EXISTS {$scopes} (scope_key CHAR(64) PRIMARY KEY, scope_name VARCHAR(190) NOT NULL, tenant_id VARCHAR(190), fence BIGINT NOT NULL DEFAULT 0 CHECK (fence >= 0), document_revision BIGINT NOT NULL CHECK (document_revision > 0), document_digest CHAR(64) NOT NULL, document_json TEXT NOT NULL, document_bytes INTEGER NOT NULL CHECK (document_bytes > 0), lease_owner VARCHAR(190), lease_fence BIGINT, lease_expires_at VARCHAR(64), updated_at VARCHAR(64) NOT NULL)",
				"CREATE INDEX IF NOT EXISTS {$scopes}_scope_lookup ON {$scopes} (scope_name, tenant_id)",
				"CREATE INDEX IF NOT EXISTS {$scopes}_lease_expiry_lookup ON {$scopes} (lease_expires_at, scope_key)",
				"CREATE TABLE IF NOT EXISTS {$runs} (run_id VARCHAR(190) PRIMARY KEY, scope_key CHAR(64) NOT NULL REFERENCES {$scopes} (scope_key) ON DELETE CASCADE, plan_digest CHAR(64) NOT NULL, started_at VARCHAR(64) NOT NULL)",
				"CREATE INDEX IF NOT EXISTS {$runs}_plan_lookup ON {$runs} (plan_digest, started_at, run_id)",
				"CREATE TABLE IF NOT EXISTS {$changes} (change_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, event_type VARCHAR(96) NOT NULL, scope_key CHAR(64) NOT NULL, scope_name VARCHAR(190) NOT NULL, tenant_id VARCHAR(190), run_id VARCHAR(190), fence BIGINT, occurred_at VARCHAR(64) NOT NULL)",
				"CREATE INDEX IF NOT EXISTS {$changes}_scope_change_lookup ON {$changes} (scope_key, change_id)",
				"INSERT INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.") ON CONFLICT (singleton) DO NOTHING",
			];
		}
		return [
			"CREATE TABLE IF NOT EXISTS {$meta} (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))",
			"CREATE TABLE IF NOT EXISTS {$scopes} (scope_key TEXT NOT NULL PRIMARY KEY CHECK (length(scope_key) = 64), scope_name TEXT NOT NULL CHECK (length(scope_name) BETWEEN 1 AND 190), tenant_id TEXT CHECK (tenant_id IS NULL OR length(tenant_id) BETWEEN 1 AND 190), fence INTEGER NOT NULL DEFAULT 0 CHECK (fence >= 0), document_revision INTEGER NOT NULL CHECK (document_revision > 0), document_digest TEXT NOT NULL CHECK (length(document_digest) = 64), document_json TEXT NOT NULL, document_bytes INTEGER NOT NULL CHECK (document_bytes > 0), lease_owner TEXT CHECK (lease_owner IS NULL OR length(lease_owner) BETWEEN 1 AND 190), lease_fence INTEGER, lease_expires_at TEXT, updated_at TEXT NOT NULL)",
			"CREATE INDEX IF NOT EXISTS {$scopes}_scope_lookup ON {$scopes} (scope_name, tenant_id)",
			"CREATE INDEX IF NOT EXISTS {$scopes}_lease_expiry_lookup ON {$scopes} (lease_expires_at, scope_key)",
			"CREATE TABLE IF NOT EXISTS {$runs} (run_id TEXT NOT NULL PRIMARY KEY CHECK (length(run_id) BETWEEN 1 AND 190), scope_key TEXT NOT NULL, plan_digest TEXT NOT NULL CHECK (length(plan_digest) = 64), started_at TEXT NOT NULL, FOREIGN KEY (scope_key) REFERENCES {$scopes} (scope_key) ON DELETE CASCADE)",
			"CREATE INDEX IF NOT EXISTS {$runs}_plan_lookup ON {$runs} (plan_digest, started_at, run_id)",
			"CREATE TABLE IF NOT EXISTS {$changes} (change_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, scope_key TEXT NOT NULL, scope_name TEXT NOT NULL, tenant_id TEXT, run_id TEXT, fence INTEGER, occurred_at TEXT NOT NULL)",
			"CREATE INDEX IF NOT EXISTS {$changes}_scope_change_lookup ON {$changes} (scope_key, change_id)",
			"INSERT OR IGNORE INTO {$meta} (singleton, schema_version) VALUES (1, ".self::SCHEMA_VERSION.")",
		];
	}

	/** @return array{type:string,version:int,driver:string,schema_version:int,statements:int,idempotent:bool,destructive:bool} */
	public function installSchema():array {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict','Panel PDO migration schema installation requires transaction ownership.',true);
		}
		try{
			$statements=$this->schemaStatements();
			foreach($statements as $sql){
				if($this->pdo->exec($sql)===false){ throw new \RuntimeException('PDO schema statement failed.'); }
			}
			$this->assertSchema(false);
			return [
				'type'=>'panel_pdo_migration_schema_installation',
				'version'=>1,
				'driver'=>$this->driver,
				'schema_version'=>self::SCHEMA_VERSION,
				'statements'=>count($statements),
				'idempotent'=>true,
				'destructive'=>false,
			];
		}catch(PanelMigrationStorageException $error){
			if($error->errorCode()==='schema_incompatible'){ throw $error; }
			throw $this->storage('migration_failed','Panel PDO migration schema migration failed.',true);
		}catch(\Throwable){
			throw $this->storage('migration_failed','Panel PDO migration schema migration failed.',true);
		}
	}

	public function state(string $scope,?string $tenant=null):PanelMigrationState {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		$tenant=PanelMigrationIntegrity::tenant($tenant);
		return $this->transaction(false,function() use ($scope,$tenant):PanelMigrationState {
			$this->assertSchema(false);
			$row=$this->scopeRow($scope,$tenant,false);
			if($row===null){ return $this->defaultState($scope,$tenant); }
			[$document]=$this->hydrateScopeRow($row);
			return $this->stateFromDocument($document);
		});
	}

	/** @param array<string,mixed> $data @param list<string> $applied */
	public function seed(string $scope,?string $tenant,PanelMigrationVersion $version,array $data=[],array $applied=[]):PanelMigrationState {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		$tenant=PanelMigrationIntegrity::tenant($tenant);
		return $this->writeScope($scope,$tenant,'migration.state_seeded',function(array &$document,int &$fence) use ($scope,$tenant,$version,$data,$applied):array {
			if($document['lease']!==null){
				throw new PanelMigrationConflict('Cannot seed Panel migration state while its scope is leased.');
			}
			$current=$this->stateFromDocument($document);
			$next=PanelMigrationState::make($scope,$tenant,$version,$data,$applied,$current->revision()+1);
			$document['state']=$next->stored();
			return $this->changed($next);
		});
	}

	public function acquire(string $scope,?string $tenant,string $owner='migration-worker',int $ttlSeconds=60):?PanelMigrationLease {
		$this->recoverExpired(1000);
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		$tenant=PanelMigrationIntegrity::tenant($tenant);
		$owner=PanelMigrationIntegrity::identifier($owner,'lease owner');
		$token=$this->token();
		$now=$this->now();
		$expires=$this->plusSeconds($now,$this->ttl($ttlSeconds));
		return $this->writeScope($scope,$tenant,'migration.lease_acquired',function(array &$document,int &$fence) use ($scope,$tenant,$owner,$token,$now,$expires):array {
			if($document['lease']!==null){ return $this->unchanged(null); }
			if($fence===PHP_INT_MAX){ throw $this->storage('fence_exhausted','Panel migration lease fence is exhausted.'); }
			$fence++;
			$lease=PanelMigrationLease::make($scope,$tenant,$owner,$token,$fence,$now,$expires);
			$document['lease']=$this->leaseState($lease);
			return $this->changed($lease);
		});
	}

	public function renew(PanelMigrationLease $lease,int $ttlSeconds=60):PanelMigrationLease {
		$now=$this->now();
		$expires=$this->plusSeconds($now,$this->ttl($ttlSeconds));
		return $this->writeScope($lease->scope(),$lease->tenant(),'migration.lease_renewed',function(array &$document,int &$fence) use ($lease,$now,$expires):array {
			$state=$this->validateLease($document,$fence,$lease,$now);
			$state['renewed_at']=$now;
			$state['expires_at']=$expires;
			$document['lease']=$state;
			return $this->changed($lease->renewed($now,$expires));
		});
	}

	public function begin(PanelMigrationLease $lease,PanelMigrationPlan $plan,mixed $actor=null):PanelMigrationReport {
		$now=$this->now();
		return $this->writeScope($lease->scope(),$lease->tenant(),'migration.run_begun',function(array &$document,int &$fence,string $scopeKey) use ($lease,$plan,$actor,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$this->assertPlanScope($lease,$plan);
			$state=$this->stateFromDocument($document);
			$idempotency=$plan->idempotencyKey();
			$existing=$document['idempotency'][$idempotency]??null;
			if(is_string($existing)&&isset($document['runs'][$existing])&&is_array($document['runs'][$existing])){
				$run=&$document['runs'][$existing];
				$this->assertRunIndex($existing,$scopeKey,$run);
				if(($run['status']??null)==='rolled_back'){
					unset($document['idempotency'][$idempotency]);
				}else{
					$this->assertRunState($run,$state,$plan);
					if(($run['status']??null)==='completed'){
						return $this->unchanged($this->reportFromRun($run));
					}
					if(!in_array($run['status']??null,['running','paused','failed'],true)){
						throw new PanelMigrationConflict('Panel migration run cannot be resumed from its current lifecycle state.');
					}
					$run['status']='running';
					$run['resumes']=max(0,(int)($run['resumes']??0))+1;
					$run['updated_at']=$now;
					$run['errors']=[];
					return $this->changed($this->reportFromRun($run),$existing);
				}
			}
			if(
				$state->revision()!==$plan->stateRevision()
				||!hash_equals($state->digest(),$plan->stateDigest())
				||!$state->version()->equals($plan->source())
			){
				throw new PanelMigrationStalePlan($plan->digest());
			}
			$runId=$this->reserveRunId($scopeKey,$plan->digest(),$now);
			$snapshotId=$runId.'.backup';
			$document['snapshots'][$snapshotId]=[
				'id'=>$snapshotId,
				'run_id'=>$runId,
				'scope'=>$plan->scope(),
				'tenant'=>$plan->tenant(),
				'revision'=>$state->revision(),
				'state_digest'=>$state->digest(),
				'created_at'=>$now,
				'state'=>$state->stored(),
			];
			$run=[
				'run_id'=>$runId,
				'plan_digest'=>$plan->digest(),
				'idempotency_key'=>$idempotency,
				'scope'=>$plan->scope(),
				'tenant'=>$plan->tenant(),
				'source'=>$plan->source()->jsonSerialize(),
				'target'=>$plan->target()->jsonSerialize(),
				'steps'=>$plan->steps(),
				'status'=>'running',
				'step_index'=>0,
				'rollback_index'=>null,
				'checkpoints'=>[],
				'batch_count'=>0,
				'processed'=>0,
				'receipts'=>[],
				'errors'=>[],
				'resumes'=>0,
				'snapshot_id'=>$snapshotId,
				'expected_revision'=>$state->revision(),
				'expected_digest'=>$state->digest(),
				'actor_fingerprint'=>substr(PanelMigrationIntegrity::digest(PanelMigrationIntegrity::redact($actor)),0,16),
				'started_at'=>$now,
				'updated_at'=>$now,
				'completed_at'=>null,
				'rollback_mode'=>null,
			];
			$document['runs'][$runId]=$run;
			$document['idempotency'][$idempotency]=$runId;
			return $this->changed($this->reportFromRun($run),$runId);
		});
	}

	public function applyBatch(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,PanelMigrationDefinition $definition,mixed $actor=null):PanelMigrationReport {
		return $this->batch($lease,$runId,$plan,$definition,$actor,false);
	}

	public function beginRollback(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		$now=$this->now();
		return $this->writeScope($lease->scope(),$lease->tenant(),'migration.rollback_begun',function(array &$document,int &$fence) use ($lease,$runId,$plan,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$run=&$this->requiredRun($document,$runId);
			$this->assertRunPlan($run,$plan);
			$state=$this->stateFromDocument($document);
			$this->assertRunState($run,$state,$plan);
			if(($run['status']??null)==='rolled_back'){
				return $this->unchanged($this->reportFromRun($run));
			}
			if(!is_int($run['rollback_index']??null)){
				$step=max(0,(int)($run['step_index']??0));
				$steps=$plan->steps();
				$current=$steps[$step]??null;
				$hasPartial=is_array($current)&&isset($run['checkpoints']['up:'.(string)$current['id']]);
				$run['rollback_index']=$hasPartial?$step:$step-1;
			}
			$run['status']='rolling_back';
			$run['updated_at']=$now;
			return $this->changed($this->reportFromRun($run),$runId);
		});
	}

	public function applyCompensation(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,PanelMigrationDefinition $definition,mixed $actor=null):PanelMigrationReport {
		return $this->batch($lease,$runId,$plan,$definition,$actor,true);
	}

	public function complete(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport {
		return $this->finish($lease,$runId,$plan,false);
	}

	public function completeRollback(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport {
		return $this->finish($lease,$runId,$plan,true);
	}

	public function fail(PanelMigrationLease $lease,string $runId,\Throwable $error):PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		$now=$this->now();
		return $this->writeScope($lease->scope(),$lease->tenant(),'migration.run_failed',function(array &$document,int &$fence) use ($lease,$runId,$error,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$run=&$this->requiredRun($document,$runId);
			if(in_array($run['status']??null,['completed','rolled_back'],true)){
				throw new PanelMigrationConflict('A terminal Panel migration run cannot be failed.');
			}
			$run['status']='failed';
			$run['errors']=[PanelMigrationIntegrity::redact($error)];
			$run['updated_at']=$now;
			return $this->changed($this->reportFromRun($run),$runId);
		});
	}

	public function restoreSnapshot(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan):PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		$now=$this->now();
		return $this->writeScope($lease->scope(),$lease->tenant(),'migration.snapshot_restored',function(array &$document,int &$fence) use ($lease,$runId,$plan,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$run=&$this->requiredRun($document,$runId);
			$this->assertRunPlan($run,$plan);
			$snapshot=$document['snapshots'][(string)($run['snapshot_id']??'')]??null;
			if(!is_array($snapshot)||!is_array($snapshot['state']??null)){
				throw new PanelMigrationConflict('Panel migration backup snapshot is unavailable.');
			}
			$backup=PanelMigrationState::fromStored($snapshot['state']);
			if(!hash_equals((string)($snapshot['state_digest']??''),$backup->digest())){
				throw new PanelMigrationConflict('Panel migration backup snapshot failed integrity verification.');
			}
			$current=$this->stateFromDocument($document);
			$restored=PanelMigrationState::make(
				$backup->scope(),
				$backup->tenant(),
				$backup->version(),
				$backup->data(),
				$backup->applied(),
				$current->revision()+1,
			);
			$document['state']=$restored->stored();
			$run['expected_revision']=$restored->revision();
			$run['expected_digest']=$restored->digest();
			$run['status']='rolled_back';
			$run['rollback_mode']='snapshot_restore';
			$run['completed_at']=$now;
			$run['updated_at']=$now;
			return $this->changed($this->reportFromRun($run),$runId);
		});
	}

	public function release(PanelMigrationLease $lease):void {
		$now=$this->now();
		$this->writeScope($lease->scope(),$lease->tenant(),'migration.lease_released',function(array &$document,int &$fence) use ($lease,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$document['lease']=null;
			return $this->changed(null);
		});
	}

	/** @return list<PanelMigrationReport> */
	public function recoverExpired(int $limit=100):array {
		$limit=max(1,min(10000,$limit));
		$now=$this->now();
		$scopes=$this->transaction(false,function() use ($limit,$now):array {
			$this->assertSchema(false);
			return $this->rows(
				"SELECT scope_name, tenant_id FROM {$this->prefix}_scopes WHERE lease_expires_at IS NOT NULL AND lease_expires_at <= :expires_at ORDER BY scope_key ASC LIMIT {$limit}",
				['expires_at'=>$now],
			);
		});
		$reports=[];
		foreach($scopes as $row){
			$scope=$row['scope_name']??null;
			$tenant=$row['tenant_id']??null;
			if(!is_string($scope)||($tenant!==null&&!is_string($tenant))){ throw $this->corrupt(); }
			$changed=$this->writeScope($scope,$tenant,'migration.lease_recovered',function(array &$document,int &$fence) use ($scope,$tenant,$now):array {
				$lease=$document['lease'];
				if(!is_array($lease)||strcmp((string)($lease['expires_at']??''),$now)>0){
					return $this->unchanged([]);
				}
				$document['lease']=null;
				$changed=[];
				foreach($document['runs'] as &$run){
					if(
						is_array($run)
						&&($run['scope']??null)===$scope
						&&($run['tenant']??null)===$tenant
						&&in_array($run['status']??null,['running','rolling_back'],true)
					){
						$run['status']='paused';
						$run['updated_at']=$now;
						$run['errors']=[['type'=>'lease_expired','message'=>'Execution paused after its exclusive lease expired.']];
						$changed[]=$this->reportFromRun($run);
					}
				}
				unset($run);
				return $this->changed($changed);
			});
			$reports=array_merge($reports,$changed);
		}
		return $reports;
	}

	public function report(string $runId):?PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		return $this->transaction(false,function() use ($runId):?PanelMigrationReport {
			$this->assertSchema(false);
			$index=$this->runIndexRow($runId,false);
			return $index===null?null:$this->reportFromIndex($index);
		});
	}

	public function reportByPlan(string $planDigest):?PanelMigrationReport {
		if(preg_match('/^[a-f0-9]{64}$/D',$planDigest)!==1){ return null; }
		return $this->transaction(false,function() use ($planDigest):?PanelMigrationReport {
			$this->assertSchema(false);
			$index=$this->row(
				"SELECT run_id, scope_key, plan_digest, started_at FROM {$this->prefix}_run_index WHERE plan_digest = :plan_digest ORDER BY started_at ASC, run_id ASC LIMIT 1",
				['plan_digest'=>$planDigest],
			);
			return $index===null?null:$this->reportFromIndex($index);
		});
	}

	public function snapshot(string $runId):?PanelMigrationSnapshot {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		return $this->transaction(false,function() use ($runId):?PanelMigrationSnapshot {
			$this->assertSchema(false);
			$index=$this->runIndexRow($runId,false);
			if($index===null){ return null; }
			[$document]=$this->documentFromIndex($index);
			$run=$document['runs'][$runId]??null;
			if(!is_array($run)){ throw $this->corrupt(); }
			$snapshot=$document['snapshots'][(string)($run['snapshot_id']??'')]??null;
			return is_array($snapshot)?$this->snapshotFromArray($snapshot):null;
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
				foreach($this->rows(
					"SELECT change_id, event_type, scope_key, scope_name, tenant_id, run_id, fence, occurred_at FROM {$this->prefix}_changes WHERE change_id > :cursor ORDER BY change_id ASC LIMIT {$limit}",
					['cursor'=>$cursor],
				) as $row){
					$changes[]=$this->hydrateChange($row);
				}
			}
			$next=$changes!==[]?(int)$changes[array_key_last($changes)]['cursor']:$current;
			return [
				'cursor'=>$next,
				'oldest_cursor'=>$oldest,
				'reset_required'=>$reset,
				'changes'=>$changes,
				'snapshot'=>$reset?[
					'type'=>'panel_pdo_migration_reset',
					'schema_version'=>1,
					'cursor'=>$current,
					'resync'=>'host_scope_inventory',
				]:null,
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
			'type'=>'panel_pdo_migration_store',
			'version'=>1,
			'adapter'=>'pdo',
			'driver'=>$this->driver,
			'durable'=>true,
			'distributed'=>true,
			'cross_process'=>true,
			'shared_database'=>true,
			'scope_parallelism'=>true,
			'atomic_batches'=>true,
			'handler_transaction_connection'=>'constructor_pdo',
			'handler_transaction_retries'=>false,
			'exclusive_leases'=>true,
			'lease_renewal'=>true,
			'lease_recovery'=>true,
			'fencing'=>true,
			'lease_token_digest'=>'sha256_domain_separated',
			'raw_lease_tokens_stored'=>false,
			'resumable_checkpoints'=>true,
			'idempotency'=>true,
			'plan_idempotency_keys_stored'=>true,
			'integrity_digests'=>true,
			'backup_snapshots'=>true,
			'state_payloads_stored'=>true,
			'snapshot_payloads_stored'=>true,
			'at_rest_encryption'=>'host_database',
			'compensation'=>true,
			'snapshot_restore'=>true,
			'change_feed'=>true,
			'change_feed_payloads_stored'=>false,
			'change_retention'=>$this->changeRetention,
			'maximum_scope_bytes'=>$this->maximumScopeBytes,
			'schema_version'=>self::SCHEMA_VERSION,
			'document_schema_version'=>self::DOCUMENT_SCHEMA_VERSION,
			'schema_migration'=>'explicit_idempotent',
			'automatic_schema_mutation'=>false,
			'transaction_ownership_required'=>true,
			'metadata_transaction_retries'=>$this->transactionRetries,
			'retry_delay_microseconds'=>$this->retryDelayMicroseconds,
			'connection_details_serialized'=>false,
			'credentials_serialized'=>false,
			'table_prefix_serialized'=>false,
			'sql_serialized'=>false,
			'live_counts_queried'=>false,
			'capabilities'=>[
				'atomic_batches'=>true,
				'cross_process_lock'=>true,
				'shared_database'=>true,
				'scope_parallelism'=>true,
				'exclusive_leases'=>true,
				'fencing'=>true,
				'lease_recovery'=>true,
				'resumable_checkpoints'=>true,
				'idempotency'=>true,
				'integrity_digests'=>true,
				'backup_snapshots'=>true,
				'compensation'=>true,
				'snapshot_restore'=>true,
				'change_feed'=>true,
				'raw_tokens_at_rest'=>false,
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

	private function batch(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,PanelMigrationDefinition $definition,mixed $actor,bool $rollback):PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		$now=$this->now();
		$event=$rollback?'migration.compensation_batch':'migration.batch';
		return $this->writeScope($lease->scope(),$lease->tenant(),$event,function(array &$document,int &$fence) use ($lease,$runId,$plan,$definition,$actor,$rollback,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$run=&$this->requiredRun($document,$runId);
			$this->assertRunPlan($run,$plan);
			$requiredStatus=$rollback?'rolling_back':'running';
			if(($run['status']??null)!==$requiredStatus){
				throw new PanelMigrationConflict("Panel migration run is not {$requiredStatus}.");
			}
			$state=$this->stateFromDocument($document);
			$this->assertRunState($run,$state,$plan);
			$index=$rollback?(int)($run['rollback_index']??-1):(int)($run['step_index']??0);
			$step=$plan->steps()[$index]??null;
			if(
				!is_array($step)
				||(string)($step['id']??'')!==$definition->id()
				||!hash_equals((string)($step['definition_digest']??''),$definition->digest())
			){
				throw new PanelMigrationConflict('Panel migration definition does not match the current planned step.');
			}
			$checkpointKey=($rollback?'down:':'up:').$definition->id();
			$stored=is_array($run['checkpoints'][$checkpointKey]??null)?$run['checkpoints'][$checkpointKey]:[];
			$context=new PanelMigrationContext(
				$plan->scope(),
				$plan->tenant(),
				$definition->id(),
				$rollback?'down':'up',
				$state->data(),
				$stored['cursor']??null,
				$definition->batchSize(),
				is_array($stored['checkpoint']??null)?$stored['checkpoint']:[],
				false,
				$actor,
			);
			$batch=$rollback?$definition->compensate($context):$definition->migrate($context);
			if($batch->processed()>$definition->batchSize()){
				throw new \UnexpectedValueException('Panel migration handler exceeded its declared batch size.');
			}
			$applied=$state->applied();
			$version=$state->version();
			if($batch->done()){
				if($rollback){
					$version=$definition->from();
					$applied=array_values(array_filter($applied,static fn(string $id):bool=>$id!==$definition->id()));
					$run['rollback_index']=$index-1;
				}else{
					$version=$definition->to();
					if(!in_array($definition->id(),$applied,true)){
						$applied[]=$definition->id();
						sort($applied,SORT_STRING);
					}
					$run['step_index']=$index+1;
				}
				unset($run['checkpoints'][$checkpointKey]);
			}else{
				$run['checkpoints'][$checkpointKey]=[
					'cursor'=>$batch->nextCursor(),
					'checkpoint'=>PanelMigrationIntegrity::redact($batch->checkpoint()),
					'processed'=>max(0,(int)($stored['processed']??0))+$batch->processed(),
				];
			}
			$next=$state->evolved($version,$batch->data(),$applied);
			$document['state']=$next->stored();
			$run['expected_revision']=$next->revision();
			$run['expected_digest']=$next->digest();
			$run['batch_count']=max(0,(int)($run['batch_count']??0))+1;
			$run['processed']=max(0,(int)($run['processed']??0))+$batch->processed();
			$run['updated_at']=$now;
			$receipt=[
				'migration_id'=>$definition->id(),
				'direction'=>$rollback?'down':'up',
				'batch'=>$batch->jsonSerialize(),
				'state_revision'=>$next->revision(),
				'state_digest'=>$next->digest(),
				'occurred_at'=>$now,
			];
			$run['receipts'][]=PanelMigrationIntegrity::redact($receipt);
			if(count($run['receipts'])>2000){ $run['receipts']=array_slice($run['receipts'],-2000); }
			return $this->changed($this->reportFromRun($run),$runId);
		},false);
	}

	private function finish(PanelMigrationLease $lease,string $runId,PanelMigrationPlan $plan,bool $rollback):PanelMigrationReport {
		$runId=PanelMigrationIntegrity::identifier($runId,'run id');
		$now=$this->now();
		$event=$rollback?'migration.rollback_completed':'migration.run_completed';
		return $this->writeScope($lease->scope(),$lease->tenant(),$event,function(array &$document,int &$fence) use ($lease,$runId,$plan,$rollback,$now):array {
			$this->validateLease($document,$fence,$lease,$now);
			$run=&$this->requiredRun($document,$runId);
			$this->assertRunPlan($run,$plan);
			$state=$this->stateFromDocument($document);
			$this->assertRunState($run,$state,$plan);
			if($rollback){
				if((int)($run['rollback_index']??-1)>=0||!$state->version()->equals($plan->source())){
					throw new PanelMigrationConflict('Panel migration compensation is not complete.');
				}
				$run['status']='rolled_back';
				$run['rollback_mode']='compensation';
			}else{
				if((int)($run['step_index']??0)!==count($plan->steps())||!$state->version()->equals($plan->target())){
					throw new PanelMigrationConflict('Panel migration plan is not fully applied.');
				}
				$run['status']='completed';
			}
			$run['completed_at']=$now;
			$run['updated_at']=$now;
			return $this->changed($this->reportFromRun($run),$runId);
		});
	}

	/**
	 * @param callable(array<string,mixed>&,int&,string,string):array{changed:bool,result:mixed,run_id:?string} $callback
	 */
	private function writeScope(string $scope,?string $tenant,string $event,callable $callback,bool $allowRetry=true):mixed {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		$tenant=PanelMigrationIntegrity::tenant($tenant);
		return $this->transaction(true,function() use ($scope,$tenant,$event,$callback):mixed {
			$this->assertSchema(true);
			$scopeKey=$this->scopeKey($scope,$tenant);
			$now=$this->now();
			$this->ensureScopeRow($scope,$tenant,$scopeKey,$now);
			$row=$this->scopeRowByKey($scopeKey,true);
			if($row===null){ throw $this->corrupt(); }
			[$document,$fence,$documentRevision]=$this->hydrateScopeRow($row);
			$mutation=$callback($document,$fence,$scopeKey,$now);
			if(
				!is_array($mutation)
				||!isset($mutation['changed'])
				||!is_bool($mutation['changed'])
				||!array_key_exists('result',$mutation)
				||!array_key_exists('run_id',$mutation)
				||($mutation['run_id']!==null&&!is_string($mutation['run_id']))
			){
				throw new \UnexpectedValueException('Panel PDO migration scope mutation returned an invalid envelope.');
			}
			if($mutation['changed']){
				$this->persistScope($scope,$tenant,$scopeKey,$document,$fence,$documentRevision,$now);
				$this->recordChange($event,$scopeKey,$scope,$tenant,$mutation['run_id'],$fence>0?$fence:null,$now);
			}
			return $mutation['result'];
		},$allowRetry);
	}

	/** @return array{changed:true,result:mixed,run_id:?string} */
	private function changed(mixed $result,?string $runId=null):array {
		return ['changed'=>true,'result'=>$result,'run_id'=>$runId];
	}

	/** @return array{changed:false,result:mixed,run_id:null} */
	private function unchanged(mixed $result):array {
		return ['changed'=>false,'result'=>$result,'run_id'=>null];
	}

	private function ensureScopeRow(string $scope,?string $tenant,string $scopeKey,string $now):void {
		$document=$this->defaultDocument($scope,$tenant);
		[$json,$bytes,$digest]=$this->encodeDocument($document,$scope,$tenant,0);
		$parameters=[
			'scope_key'=>$scopeKey,
			'scope_name'=>$scope,
			'tenant_id'=>$tenant,
			'fence'=>0,
			'document_revision'=>1,
			'document_digest'=>$digest,
			'document_json'=>$json,
			'document_bytes'=>$bytes,
			'updated_at'=>$now,
		];
		$columns='scope_key, scope_name, tenant_id, fence, document_revision, document_digest, document_json, document_bytes, lease_owner, lease_fence, lease_expires_at, updated_at';
		$values=':scope_key, :scope_name, :tenant_id, :fence, :document_revision, :document_digest, :document_json, :document_bytes, NULL, NULL, NULL, :updated_at';
		$sql=match($this->driver){
			'mysql'=>"INSERT IGNORE INTO {$this->prefix}_scopes ({$columns}) VALUES ({$values})",
			'pgsql'=>"INSERT INTO {$this->prefix}_scopes ({$columns}) VALUES ({$values}) ON CONFLICT (scope_key) DO NOTHING",
			default=>"INSERT OR IGNORE INTO {$this->prefix}_scopes ({$columns}) VALUES ({$values})",
		};
		$this->execute($sql,$parameters);
	}

	/** @param array<string,mixed> $document */
	private function persistScope(string $scope,?string $tenant,string $scopeKey,array $document,int $fence,int $documentRevision,string $now):void {
		[$json,$bytes,$digest,$document]=$this->encodeDocument($document,$scope,$tenant,$fence,true);
		$lease=$document['lease'];
		$leaseOwner=is_array($lease)?(string)$lease['owner']:null;
		$leaseFence=is_array($lease)?(int)$lease['fence']:null;
		$leaseExpires=is_array($lease)?(string)$lease['expires_at']:null;
		$statement=$this->execute(
			"UPDATE {$this->prefix}_scopes SET fence = :fence, document_revision = :next_revision, document_digest = :document_digest, document_json = :document_json, document_bytes = :document_bytes, lease_owner = :lease_owner, lease_fence = :lease_fence, lease_expires_at = :lease_expires_at, updated_at = :updated_at WHERE scope_key = :scope_key AND document_revision = :expected_revision",
			[
				'fence'=>$fence,
				'next_revision'=>$documentRevision+1,
				'document_digest'=>$digest,
				'document_json'=>$json,
				'document_bytes'=>$bytes,
				'lease_owner'=>$leaseOwner,
				'lease_fence'=>$leaseFence,
				'lease_expires_at'=>$leaseExpires,
				'updated_at'=>$now,
				'scope_key'=>$scopeKey,
				'expected_revision'=>$documentRevision,
			],
		);
		if($statement->rowCount()!==1){
			throw new PanelMigrationConflict('Panel migration scope changed concurrently.');
		}
	}

	/** @return array<string,mixed> */
	private function defaultDocument(string $scope,?string $tenant):array {
		return [
			'schema_version'=>self::DOCUMENT_SCHEMA_VERSION,
			'state'=>$this->defaultState($scope,$tenant)->stored(),
			'lease'=>null,
			'runs'=>[],
			'snapshots'=>[],
			'idempotency'=>[],
		];
	}

	private function defaultState(string $scope,?string $tenant):PanelMigrationState {
		return PanelMigrationState::make($scope,$tenant,PanelMigrationVersion::make('0.0.0',0));
	}

	/** @param array<string,mixed> $document */
	private function stateFromDocument(array $document):PanelMigrationState {
		$stored=$document['state']??null;
		if(!is_array($stored)){ throw $this->corrupt(); }
		try{ return PanelMigrationState::fromStored($stored); }
		catch(\Throwable){ throw $this->corrupt(); }
	}

	/** @param array<string,mixed> $row @return array{0:array<string,mixed>,1:int,2:int} */
	private function hydrateScopeRow(array $row):array {
		foreach(['scope_key','scope_name','document_digest','document_json','updated_at'] as $key){
			if(!isset($row[$key])||!is_string($row[$key])){ throw $this->corrupt(); }
		}
		$tenant=$row['tenant_id']??null;
		if($tenant!==null&&!is_string($tenant)){ throw $this->corrupt(); }
		try{
			$scope=PanelMigrationIntegrity::identifier($row['scope_name'],'scope');
			$tenant=PanelMigrationIntegrity::tenant($tenant);
		}catch(\Throwable){ throw $this->corrupt(); }
		if(!hash_equals($this->scopeKey($scope,$tenant),$row['scope_key'])){ throw $this->corrupt(); }
		$fence=$this->integer($row['fence']??null,0);
		$revision=$this->integer($row['document_revision']??null,1);
		$bytes=$this->integer($row['document_bytes']??null,1);
		if(
			$bytes!==strlen($row['document_json'])
			||$bytes>$this->maximumScopeBytes
			||preg_match('/^[a-f0-9]{64}$/D',$row['document_digest'])!==1
			||!hash_equals($row['document_digest'],hash('sha256',$row['document_json']))
		){
			throw $this->corrupt();
		}
		try{
			$document=json_decode($row['document_json'],true,128,JSON_THROW_ON_ERROR);
		}catch(\Throwable){ throw $this->corrupt(); }
		if(!is_array($document)||array_is_list($document)){ throw $this->corrupt(); }
		$document=$this->validateDocument($document,$scope,$tenant,$fence);
		$lease=$document['lease'];
		$mirrorOwner=$row['lease_owner']??null;
		$mirrorFence=$row['lease_fence']??null;
		$mirrorExpires=$row['lease_expires_at']??null;
		if($lease===null){
			if($mirrorOwner!==null||$mirrorFence!==null||$mirrorExpires!==null){ throw $this->corrupt(); }
		}else{
			if(
				!is_string($mirrorOwner)
				||!is_string($mirrorExpires)
				||$mirrorOwner!==$lease['owner']
				||$this->integer($mirrorFence,1)!==$lease['fence']
				||$mirrorExpires!==$lease['expires_at']
			){
				throw $this->corrupt();
			}
		}
		return [$document,$fence,$revision];
	}

	/** @param array<string,mixed> $document @return array<string,mixed> */
	private function validateDocument(array $document,string $scope,?string $tenant,int $fence):array {
		if(($document['schema_version']??null)!==self::DOCUMENT_SCHEMA_VERSION){ throw $this->corrupt(); }
		foreach(['runs','snapshots','idempotency'] as $key){
			if(!isset($document[$key])){ $document[$key]=[]; }
			if(!is_array($document[$key])){ throw $this->corrupt(); }
		}
		$state=$this->stateFromDocument($document);
		if($state->scope()!==$scope||$state->tenant()!==$tenant){ throw $this->corrupt(); }
		$lease=$document['lease']??null;
		if($lease!==null){
			if(!is_array($lease)){ throw $this->corrupt(); }
			$document['lease']=$this->validateLeaseState($lease,$scope,$tenant,$fence);
		}
		foreach($document['runs'] as $runId=>$run){
			if(!is_string($runId)||!is_array($run)){ throw $this->corrupt(); }
			try{ PanelMigrationIntegrity::identifier($runId,'run id'); }
			catch(\Throwable){ throw $this->corrupt(); }
			if(
				($run['run_id']??null)!==$runId
				||($run['scope']??null)!==$scope
				||($run['tenant']??null)!==$tenant
				||!is_string($run['plan_digest']??null)
				||preg_match('/^[a-f0-9]{64}$/D',(string)$run['plan_digest'])!==1
				||!is_string($run['expected_digest']??null)
				||preg_match('/^[a-f0-9]{64}$/D',(string)$run['expected_digest'])!==1
			){
				throw $this->corrupt();
			}
			try{ $this->reportFromRun($run); }
			catch(\Throwable){ throw $this->corrupt(); }
		}
		foreach($document['snapshots'] as $snapshotId=>$snapshot){
			if(!is_string($snapshotId)||!is_array($snapshot)||($snapshot['id']??null)!==$snapshotId||!is_array($snapshot['state']??null)){
				throw $this->corrupt();
			}
			try{
				$metadata=$this->snapshotFromArray($snapshot);
				$backup=PanelMigrationState::fromStored($snapshot['state']);
			}catch(\Throwable){ throw $this->corrupt(); }
			if(
				$metadata->scope()!==$scope
				||$metadata->tenant()!==$tenant
				||$backup->scope()!==$scope
				||$backup->tenant()!==$tenant
				||!hash_equals($metadata->stateDigest(),$backup->digest())
			){
				throw $this->corrupt();
			}
		}
		foreach($document['idempotency'] as $key=>$runId){
			if(
				!is_string($key)
				||preg_match('/^panel-migration:[a-f0-9]{64}$/D',$key)!==1
				||!is_string($runId)
				||!isset($document['runs'][$runId])
			){
				throw $this->corrupt();
			}
		}
		return $document;
	}

	/** @param array<string,mixed> $document @return array{0:string,1:int,2:string}|array{0:string,1:int,2:string,3:array<string,mixed>} */
	private function encodeDocument(array $document,string $scope,?string $tenant,int $fence,bool $includeDocument=false):array {
		try{
			$document=$this->validateDocument($document,$scope,$tenant,$fence);
			$json=PanelMigrationIntegrity::canonicalJson($document);
		}catch(PanelMigrationStorageException $error){ throw $error; }
		catch(\Throwable){ throw $this->storage('document_invalid','Panel migration scope document could not be encoded.'); }
		$bytes=strlen($json);
		if($bytes<1||$bytes>$this->maximumScopeBytes){
			throw $this->storage('document_too_large','Panel migration scope document exceeds the configured byte bound.');
		}
		$result=[$json,$bytes,hash('sha256',$json)];
		if($includeDocument){ $result[]=$document; }
		return $result;
	}

	/** @param array<string,mixed> $lease @return array<string,mixed> */
	private function validateLeaseState(array $lease,string $scope,?string $tenant,int $fence):array {
		foreach(['scope','owner','token_hash','acquired_at','renewed_at','expires_at'] as $key){
			if(!isset($lease[$key])||!is_string($lease[$key])){ throw $this->corrupt(); }
		}
		$leaseTenant=$lease['tenant']??null;
		if($leaseTenant!==null&&!is_string($leaseTenant)){ throw $this->corrupt(); }
		try{
			$owner=PanelMigrationIntegrity::identifier($lease['owner'],'lease owner');
			$leaseTenant=PanelMigrationIntegrity::tenant($leaseTenant);
			$leaseFence=$this->integer($lease['fence']??null,1);
			PanelMigrationLease::make($scope,$tenant,$owner,str_repeat('x',32),$leaseFence,$lease['acquired_at'],$lease['expires_at'],$lease['renewed_at']);
		}catch(\Throwable){ throw $this->corrupt(); }
		if(
			$lease['scope']!==$scope
			||$leaseTenant!==$tenant
			||$leaseFence!==$fence
			||preg_match('/^[a-f0-9]{64}$/D',$lease['token_hash'])!==1
		){
			throw $this->corrupt();
		}
		return [
			'scope'=>$scope,
			'tenant'=>$tenant,
			'owner'=>$owner,
			'token_hash'=>$lease['token_hash'],
			'fence'=>$leaseFence,
			'acquired_at'=>$lease['acquired_at'],
			'renewed_at'=>$lease['renewed_at'],
			'expires_at'=>$lease['expires_at'],
		];
	}

	/** @param array<string,mixed> $document @return array<string,mixed> */
	private function validateLease(array $document,int $fence,PanelMigrationLease $lease,string $now):array {
		$state=$document['lease']??null;
		if(!is_array($state)){ throw new PanelMigrationLeaseLost($lease->scopeKey()); }
		$valid=(string)($state['owner']??'')===$lease->owner()
			&&(int)($state['fence']??0)===$lease->fence()
			&&$fence===$lease->fence()
			&&isset($state['token_hash'])
			&&is_string($state['token_hash'])
			&&hash_equals($state['token_hash'],$this->tokenHash($lease->token()));
		if(!$valid){
			throw new PanelMigrationLeaseLost($lease->scopeKey(),'Panel migration lease was superseded.');
		}
		if(strcmp((string)($state['expires_at']??''),$now)<=0){
			throw new PanelMigrationLeaseLost($lease->scopeKey(),'Panel migration lease expired.');
		}
		return $state;
	}

	/** @return array<string,mixed> */
	private function leaseState(PanelMigrationLease $lease):array {
		return [
			'scope'=>$lease->scope(),
			'tenant'=>$lease->tenant(),
			'owner'=>$lease->owner(),
			'token_hash'=>$this->tokenHash($lease->token()),
			'fence'=>$lease->fence(),
			'acquired_at'=>$lease->acquiredAt(),
			'renewed_at'=>$lease->renewedAt(),
			'expires_at'=>$lease->expiresAt(),
		];
	}

	/** @param array<string,mixed> $document @return array<string,mixed> */
	private function &requiredRun(array &$document,string $runId):array {
		if(!isset($document['runs'][$runId])||!is_array($document['runs'][$runId])){
			throw new \OutOfBoundsException("Panel migration run '{$runId}' does not exist.");
		}
		return $document['runs'][$runId];
	}

	/** @param array<string,mixed> $run */
	private function assertRunPlan(array $run,PanelMigrationPlan $plan):void {
		if(!hash_equals((string)($run['plan_digest']??''),$plan->digest())){
			throw new PanelMigrationStalePlan($plan->digest(),'Panel migration run belongs to a different plan.');
		}
	}

	private function assertPlanScope(PanelMigrationLease $lease,PanelMigrationPlan $plan):void {
		if($lease->scope()!==$plan->scope()||$lease->tenant()!==$plan->tenant()){
			throw new PanelMigrationConflict('Panel migration plan and lease scopes do not match.');
		}
	}

	/** @param array<string,mixed> $run */
	private function assertRunState(array $run,PanelMigrationState $state,PanelMigrationPlan $plan):void {
		$this->assertRunPlan($run,$plan);
		if(
			(int)($run['expected_revision']??-1)!==$state->revision()
			||!hash_equals((string)($run['expected_digest']??''),$state->digest())
		){
			throw new PanelMigrationStalePlan($plan->digest(),'Panel migration state changed after the last checkpoint.');
		}
	}

	/** @param array<string,mixed> $run */
	private function reportFromRun(array $run):PanelMigrationReport {
		$snapshotId=(string)($run['snapshot_id']??'');
		return PanelMigrationReport::make([
			'run_id'=>$run['run_id']??null,
			'plan_digest'=>$run['plan_digest']??null,
			'idempotency_key'=>$run['idempotency_key']??null,
			'scope'=>$run['scope']??null,
			'tenant'=>$run['tenant']??null,
			'source'=>$run['source']??null,
			'target'=>$run['target']??null,
			'status'=>$run['status']??'failed',
			'step_index'=>(int)($run['step_index']??0),
			'total_steps'=>count(is_array($run['steps']??null)?$run['steps']:[]),
			'rollback_index'=>$run['rollback_index']??null,
			'batch_count'=>(int)($run['batch_count']??0),
			'processed'=>(int)($run['processed']??0),
			'checkpoint_count'=>count(is_array($run['checkpoints']??null)?$run['checkpoints']:[]),
			'state_revision'=>(int)($run['expected_revision']??0),
			'state_digest'=>$run['expected_digest']??null,
			'receipts'=>$run['receipts']??[],
			'errors'=>$run['errors']??[],
			'resumes'=>(int)($run['resumes']??0),
			'snapshot_id'=>$snapshotId,
			'actor_fingerprint'=>$run['actor_fingerprint']??null,
			'rollback_mode'=>$run['rollback_mode']??null,
			'started_at'=>$run['started_at']??null,
			'updated_at'=>$run['updated_at']??null,
			'completed_at'=>$run['completed_at']??null,
		]);
	}

	/** @param array<string,mixed> $snapshot */
	private function snapshotFromArray(array $snapshot):PanelMigrationSnapshot {
		return new PanelMigrationSnapshot(
			(string)($snapshot['id']??''),
			(string)($snapshot['run_id']??''),
			(string)($snapshot['scope']??''),
			isset($snapshot['tenant'])?(string)$snapshot['tenant']:null,
			(int)($snapshot['revision']??-1),
			(string)($snapshot['state_digest']??''),
			(string)($snapshot['created_at']??''),
		);
	}

	private function reserveRunId(string $scopeKey,string $planDigest,string $startedAt):string {
		for($attempt=0;$attempt<10;$attempt++){
			$value=($this->runFactory)();
			if(!is_string($value)){ throw new \UnexpectedValueException('Panel migration run id factory must return a string.'); }
			$id=PanelMigrationIntegrity::identifier($value,'run id');
			try{
				$this->execute(
					"INSERT INTO {$this->prefix}_run_index (run_id, scope_key, plan_digest, started_at) VALUES (:run_id, :scope_key, :plan_digest, :started_at)",
					['run_id'=>$id,'scope_key'=>$scopeKey,'plan_digest'=>$planDigest,'started_at'=>$startedAt],
				);
				return $id;
			}catch(\PDOException $error){
				if($this->duplicate($error)){ continue; }
				throw $error;
			}
		}
		throw new PanelMigrationConflict('Panel migration run id factory could not produce a unique id.');
	}

	/** @param array<string,mixed> $run */
	private function assertRunIndex(string $runId,string $scopeKey,array $run):void {
		$index=$this->runIndexRow($runId,true);
		if(
			$index===null
			||($index['scope_key']??null)!==$scopeKey
			||($index['plan_digest']??null)!==($run['plan_digest']??null)
		){
			throw $this->corrupt();
		}
	}

	/** @param array<string,mixed> $index */
	private function reportFromIndex(array $index):PanelMigrationReport {
		[$document]=$this->documentFromIndex($index);
		$runId=$index['run_id']??null;
		if(!is_string($runId)||!isset($document['runs'][$runId])||!is_array($document['runs'][$runId])){
			throw $this->corrupt();
		}
		$run=$document['runs'][$runId];
		if(($run['plan_digest']??null)!==($index['plan_digest']??null)){ throw $this->corrupt(); }
		return $this->reportFromRun($run);
	}

	/** @param array<string,mixed> $index @return array{0:array<string,mixed>,1:int,2:int} */
	private function documentFromIndex(array $index):array {
		foreach(['run_id','scope_key','plan_digest','started_at'] as $key){
			if(!isset($index[$key])||!is_string($index[$key])){ throw $this->corrupt(); }
		}
		if(preg_match('/^[a-f0-9]{64}$/D',$index['scope_key'])!==1||preg_match('/^[a-f0-9]{64}$/D',$index['plan_digest'])!==1){
			throw $this->corrupt();
		}
		$row=$this->scopeRowByKey($index['scope_key'],false);
		if($row===null){ throw $this->corrupt(); }
		return $this->hydrateScopeRow($row);
	}

	/** @return array<string,mixed>|null */
	private function scopeRow(string $scope,?string $tenant,bool $lock):?array {
		return $this->scopeRowByKey($this->scopeKey($scope,$tenant),$lock);
	}

	/** @return array<string,mixed>|null */
	private function scopeRowByKey(string $scopeKey,bool $lock):?array {
		return $this->row(
			"SELECT scope_key, scope_name, tenant_id, fence, document_revision, document_digest, document_json, document_bytes, lease_owner, lease_fence, lease_expires_at, updated_at FROM {$this->prefix}_scopes WHERE scope_key = :scope_key".($lock?$this->dialect['lock_suffix']:''),
			['scope_key'=>$scopeKey],
		);
	}

	/** @return array<string,mixed>|null */
	private function runIndexRow(string $runId,bool $lock):?array {
		return $this->row(
			"SELECT run_id, scope_key, plan_digest, started_at FROM {$this->prefix}_run_index WHERE run_id = :run_id".($lock?$this->dialect['lock_suffix']:''),
			['run_id'=>$runId],
		);
	}

	private function recordChange(string $event,string $scopeKey,string $scope,?string $tenant,?string $runId,?int $fence,string $occurredAt):void {
		$parameters=[
			'event_type'=>$event,
			'scope_key'=>$scopeKey,
			'scope_name'=>$scope,
			'tenant_id'=>$tenant,
			'run_id'=>$runId,
			'fence'=>$fence,
			'occurred_at'=>$occurredAt,
		];
		$sql="INSERT INTO {$this->prefix}_changes (event_type, scope_key, scope_name, tenant_id, run_id, fence, occurred_at) VALUES (:event_type, :scope_key, :scope_name, :tenant_id, :run_id, :fence, :occurred_at)";
		if($this->driver==='pgsql'){
			$value=$this->execute($sql.' RETURNING change_id',$parameters)->fetchColumn();
		}else{
			$this->execute($sql,$parameters);
			$value=$this->pdo->lastInsertId();
		}
		$cursor=$this->integer($value,1);
		$cutoff=$cursor-$this->changeRetention;
		if($cutoff>0){
			$this->execute("DELETE FROM {$this->prefix}_changes WHERE change_id <= :cutoff",['cutoff'=>$cutoff]);
		}
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function hydrateChange(array $row):array {
		foreach(['event_type','scope_key','scope_name','occurred_at'] as $key){
			if(!isset($row[$key])||!is_string($row[$key])||$row[$key]===''){ throw $this->corrupt(); }
		}
		$tenant=$row['tenant_id']??null;
		if($tenant!==null&&!is_string($tenant)){ throw $this->corrupt(); }
		try{
			$scope=PanelMigrationIntegrity::identifier($row['scope_name'],'scope');
			$tenant=PanelMigrationIntegrity::tenant($tenant);
		}catch(\Throwable){ throw $this->corrupt(); }
		if(!hash_equals($this->scopeKey($scope,$tenant),$row['scope_key'])){ throw $this->corrupt(); }
		$change=[
			'cursor'=>$this->integer($row['change_id']??null,1),
			'type'=>$row['event_type'],
			'scope'=>$scope,
			'tenant'=>$tenant,
			'occurred_at'=>$row['occurred_at'],
		];
		if(($row['run_id']??null)!==null){
			if(!is_string($row['run_id'])){ throw $this->corrupt(); }
			try{ $change['run_id']=PanelMigrationIntegrity::identifier($row['run_id'],'run id'); }
			catch(\Throwable){ throw $this->corrupt(); }
		}
		if(($row['fence']??null)!==null){ $change['fence']=$this->integer($row['fence'],1); }
		return $change;
	}

	private function assertSchema(bool $lock):void {
		try{
			$row=$this->row(
				"SELECT schema_version FROM {$this->prefix}_meta WHERE singleton = 1".($lock?$this->dialect['lock_suffix']:''),
			);
		}catch(\PDOException $error){
			if($this->missingRelation($error)){
				throw $this->storage('schema_required','Panel PDO migration schema is not installed.');
			}
			throw $error;
		}
		if($row===null){ throw $this->storage('schema_required','Panel PDO migration schema is not installed.'); }
		$version=$this->integer($row['schema_version']??null,1);
		if($version!==self::SCHEMA_VERSION){
			throw $this->storage('schema_incompatible','Panel PDO migration schema version is incompatible.');
		}
	}

	private function transaction(bool $write,callable $callback,bool $allowRetry=true):mixed {
		if($this->activeTransaction()){
			throw $this->storage('transaction_conflict','Panel PDO migration store requires transaction ownership.',true);
		}
		for($attempt=0;$attempt<=$this->transactionRetries;$attempt++){
			try{
				$this->beginTransaction($write);
				$result=$callback();
				$this->commitTransaction();
				return $result;
			}catch(\PDOException $error){
				$this->rollbackTransaction();
				if($allowRetry&&$attempt<$this->transactionRetries&&$this->transient($error)){
					if($this->retryDelayMicroseconds>0){
						usleep(min(100000,$this->retryDelayMicroseconds*($attempt+1)));
					}
					continue;
				}
				throw $this->storage('storage_unavailable','Panel PDO migration storage is unavailable.',true);
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

	private function activeTransaction():bool {
		return $this->manualSqliteWriteTransaction||$this->pdo->inTransaction();
	}

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
		foreach($rows as $row){
			if(!is_array($row)||array_is_list($row)){ throw $this->corrupt(); }
		}
		return array_values($rows);
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

	private function scopeKey(string $scope,?string $tenant):string {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');
		$tenant=PanelMigrationIntegrity::tenant($tenant);
		return hash('sha256',"panel-migration-scope-v1\0{$scope}\0".($tenant??''));
	}

	private function tokenHash(string $token):string {
		return hash('sha256',"panel-migration-lease-token-v1\0{$token}");
	}

	private function token():string {
		$token=($this->tokenFactory)();
		if(!is_string($token)){
			throw new \UnexpectedValueException('Panel migration lease token factory must return a string.');
		}
		if(strlen($token)<32||strlen($token)>512||str_contains($token,"\0")){
			throw new \UnexpectedValueException('Panel migration lease token factory returned an unsafe bearer proof.');
		}
		return $token;
	}

	private function now():string {
		$value=($this->clock)();
		try{
			if($value instanceof \DateTimeInterface){ $date=\DateTimeImmutable::createFromInterface($value); }
			elseif(is_int($value)){ $date=new \DateTimeImmutable('@'.$value); }
			else{ $date=new \DateTimeImmutable((string)$value); }
			return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
		}catch(\Throwable){
			throw new \UnexpectedValueException('Panel PDO migration clock returned an invalid time.');
		}
	}

	private function plusSeconds(string $time,int $seconds):string {
		return (new \DateTimeImmutable($time))->modify('+'.$seconds.' seconds')->format(DATE_ATOM);
	}

	private function ttl(int $seconds):int { return max(5,min(3600,$seconds)); }

	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){
			throw new \InvalidArgumentException("Panel PDO migration {$name} option is out of bounds.");
		}
		return $value;
	}

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
		return in_array($state,['42P01','42S02'],true)
			||in_array($native,['1146'],true)
			||str_contains($message,'no such table');
	}

	private function corrupt():PanelMigrationStorageException {
		return $this->storage('storage_corrupt','Panel PDO migration storage failed integrity validation.');
	}

	private function storage(string $code,string $message,bool $retryable=false):PanelMigrationStorageException {
		return new PanelMigrationStorageException($code,$message,$retryable);
	}

	private static function driverName(string $driver):string {
		$driver=strtolower(trim($driver));
		if(!in_array($driver,['mysql','pgsql','sqlite'],true)){
			throw new \InvalidArgumentException('Panel PDO migration store supports mysql, pgsql, and sqlite only.');
		}
		return $driver;
	}

	private static function prefix(string $prefix):string {
		$prefix=strtolower(trim($prefix));
		if(preg_match('/^[a-z][a-z0-9_]{0,27}$/D',$prefix)!==1){
			throw new \InvalidArgumentException('Panel PDO migration table prefix is invalid.');
		}
		return $prefix;
	}
}
