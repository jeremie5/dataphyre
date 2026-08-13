<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelMigrationBatch;
use Dataphyre\Panel\PanelMigrationConflict;
use Dataphyre\Panel\PanelMigrationContext;
use Dataphyre\Panel\PanelMigrationDefinition;
use Dataphyre\Panel\PanelMigrationLease;
use Dataphyre\Panel\PanelMigrationLeaseLost;
use Dataphyre\Panel\PanelMigrationPlan;
use Dataphyre\Panel\PanelMigrationPlanner;
use Dataphyre\Panel\PanelMigrationRegistry;
use Dataphyre\Panel\PanelMigrationSnapshot;
use Dataphyre\Panel\PanelMigrationStalePlan;
use Dataphyre\Panel\PanelMigrationState;
use Dataphyre\Panel\PanelMigrationStorageException;
use Dataphyre\Panel\PanelMigrationVersion;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPdoMigrationStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_pdo_migrations_connection(string $path,int $busyMilliseconds=5000):PDO {
	$pdo=new PDO('sqlite:'.$path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA foreign_keys = ON');
	$pdo->exec('PRAGMA busy_timeout = '.max(0,$busyMilliseconds));
	return $pdo;
}

/** @param array<string,mixed> $options @return array{path:string,pdo:PDO,store:PanelPdoMigrationStore,advance:Closure,clock:Closure} */
function dp_panel_pdo_migrations_fixture(Context $t,string $name,array $options=[]):array {
	$path=$t->tempDirectory('panel-pdo-migrations-'.$name).DIRECTORY_SEPARATOR.'migrations.sqlite';
	$pdo=dp_panel_pdo_migrations_connection($path);
	$now='2026-07-14T12:00:00+00:00';
	$token=0;
	$run=0;
	$clock=static function() use (&$now):string { return $now; };
	$advance=static function(int $seconds) use (&$now):void {
		$now=(new DateTimeImmutable($now))->modify('+'.$seconds.' seconds')->format(DATE_ATOM);
	};
	$tokens=static function() use (&$token):string {
		$token++;
		return str_pad('pdo-migration-token-'.$token,64,'x');
	};
	$runs=static function() use (&$run):string {
		$run++;
		return 'pdo-migration-run-'.$run;
	};
	$store=new PanelPdoMigrationStore($pdo,$options,$clock,$tokens,$runs);
	return compact('path','pdo','store','advance','clock');
}

function dp_panel_pdo_migration_error(Context $t,callable $callback,string $code):PanelMigrationStorageException {
	try{ $callback(); }
	catch(PanelMigrationStorageException $error){
		$t->same($code,$error->errorCode());
		return $error;
	}
	throw new RuntimeException("Expected PanelMigrationStorageException {$code}.");
}

/** @return array{0:PanelMigrationDefinition,1:PanelMigrationRegistry} */
function dp_panel_pdo_migration_edge(string $scope,?callable $up=null,?callable $down=null,string $id='upgrade'):array {
	$up??=static function(PanelMigrationContext $context):PanelMigrationBatch {
		$data=$context->data();
		$data['migrated']=true;
		return PanelMigrationBatch::complete($data,1,['api_token'=>'redacted']);
	};
	$down??=static function(PanelMigrationContext $context):PanelMigrationBatch {
		$data=$context->data();
		unset($data['migrated']);
		return PanelMigrationBatch::complete($data,1);
	};
	$definition=PanelMigrationDefinition::make(
		$scope.'.'.$id,
		$scope,
		PanelMigrationVersion::make('1.0.0',1),
		PanelMigrationVersion::make('2.0.0',2),
		$up,
		['down'=>$down,'batch_size'=>10,'capabilities'=>[$scope.'.write']],
	);
	return [$definition,new PanelMigrationRegistry([$definition])];
}

suite('Panel durable shared-SQL migration store')
	->contract('panel.migrations.pdo-store',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','scope-transactions','leases','fencing','snapshots','compensation','cross-process')
	->isolation('case')
	->tag('panel','migrations','pdo','distributed','security')
	->group('framework-coverage');

test('pdo migration schema plans are explicit portable idempotent and connection-redacted',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'schema',[
		'table_prefix'=>'mig_schema',
		'maximum_scope_bytes'=>16384,
		'change_retention'=>32,
		'transaction_retries'=>2,
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$missing=dp_panel_pdo_migration_error($t,static fn()=>$store->state('missing'),'schema_required');
	$t->isFalse($missing->retryable());
	$first=$store->installSchema();
	$second=$store->installSchema();
	$t->same('sqlite',$store->driver());
	$t->same(9,$first['statements']);
	$t->same($first,$second);
	$t->isTrue($first['idempotent']);
	$t->isFalse($first['destructive']);
	$t->same($store->schemaStatements(),PanelPdoMigrationStore::schemaStatementsFor('sqlite','mig_schema'));

	$sqlite=PanelPdoMigrationStore::schemaStatementsFor('sqlite','mig_plan');
	$mysql=PanelPdoMigrationStore::schemaStatementsFor('mysql','mig_plan');
	$pgsql=PanelPdoMigrationStore::schemaStatementsFor('pgsql','mig_plan');
	$t->same(9,count($sqlite));
	$t->same(5,count($mysql));
	$t->same(9,count($pgsql));
	$t->contains('AUTOINCREMENT',$sqlite[6]);
	$t->contains('ENGINE=InnoDB',$mysql[0]);
	$t->contains('GENERATED BY DEFAULT AS IDENTITY',$pgsql[6]);
	$t->same('BEGIN IMMEDIATE',PanelPdoMigrationStore::dialectPlanFor('sqlite')['write_begin']);
	$t->contains('REPEATABLE READ',PanelPdoMigrationStore::dialectPlanFor('pgsql')['read_after'][0]);
	$t->same(' FOR UPDATE',PanelPdoMigrationStore::dialectPlanFor('mysql')['lock_suffix']);

	$manifest=$store->manifest();
	$encoded=json_encode($store,JSON_THROW_ON_ERROR);
	$t->same('panel_pdo_migration_store',$manifest['type']);
	$t->isTrue($manifest['distributed']);
	$t->isTrue($manifest['scope_parallelism']);
	$t->isTrue($manifest['atomic_batches']);
	$t->isFalse($manifest['handler_transaction_retries']);
	$t->isFalse($manifest['raw_lease_tokens_stored']);
	$t->isTrue($manifest['change_feed']);
	foreach([$fixture['path'],'mig_schema','sqlite:','password','document_json','token_hash'] as $secret){
		$t->notContains($secret,$encoded);
	}
	$t->same('0.0.0',$store->state('empty')->version()->semantic());
})->tag('panel','migrations','pdo','schema','manifest')->maxMillis(5000);

test('pdo migration store passes conformance and preserves a complete compensating lifecycle across connections',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'lifecycle',['table_prefix'=>'mig_lifecycle']);
	$store=$fixture['store'];
	$store->installSchema();
	$conformance=(new PanelAdapterConformanceRunner())->run(
		PanelAdapterConformanceCatalog::migrationStore(),
		$store,
		['allow_destructive'=>true,'scope'=>'pdo_migration_conformance'],
	);
	$t->isTrue($conformance->passed());
	$t->same(1,$conformance->summary()['passed']);

	$scope='orders';
	$tenant='tenant-a';
	$seed=$store->seed($scope,$tenant,PanelMigrationVersion::make('1.0.0',1),['original'=>true,'password'=>'state-secret']);
	[$definition,$registry]=dp_panel_pdo_migration_edge($scope);
	$plan=(new PanelMigrationPlanner($registry))->plan($seed,PanelMigrationVersion::make('2.0.0',2),'2026-07-14T12:00:00Z');
	$lease=$store->acquire($scope,$tenant,'worker-a',30);
	$t->isTrue($lease instanceof PanelMigrationLease);
	$t->same(1,$lease?->fence());
	$begun=$store->begin($lease,$plan,['operator'=>'alice','api_token'=>'never-report']);
	$runId=(string)$begun->runId();
	$t->isTrue($runId!=='');
	$resumed=$store->begin($lease,$plan,'alice');
	$t->same(1,$resumed->jsonSerialize()['resumes']);
	$running=$store->applyBatch($lease,$runId,$plan,$definition,'alice');
	$t->same(1,$running->jsonSerialize()['step_index']);
	$completed=$store->complete($lease,$runId,$plan);
	$t->same('completed',$completed->status());
	$t->same(true,$store->state($scope,$tenant)->data()['migrated']);
	$t->same($runId,$store->reportByPlan($plan->digest())?->runId());
	$t->same($runId,$store->report($runId)?->runId());
	$t->same(null,$store->report('missing-run'));
	$t->same(null,$store->reportByPlan('bad'));
	$snapshot=$store->snapshot($runId);
	$t->isTrue($snapshot instanceof PanelMigrationSnapshot);
	$t->same($seed->digest(),$snapshot?->stateDigest());
	$t->notContains('state-secret',json_encode($snapshot,JSON_THROW_ON_ERROR));

	$other=new PanelPdoMigrationStore(
		dp_panel_pdo_migrations_connection($fixture['path']),
		['table_prefix'=>'mig_lifecycle'],
		$fixture['clock'],
		static fn():string=>str_repeat('z',48),
		static fn():string=>'other-run',
	);
	$t->same('completed',$other->report($runId)?->status());
	$t->same('2.0.0',$other->state($scope,$tenant)->version()->semantic());
	$t->same('completed',$other->begin($lease,$plan)->status());

	$store->beginRollback($lease,$runId,$plan);
	$store->applyCompensation($lease,$runId,$plan,$definition,'alice');
	$rolled=$store->completeRollback($lease,$runId,$plan);
	$t->same('rolled_back',$rolled->status());
	$t->same('compensation',$rolled->jsonSerialize()['rollback_mode']);
	$t->same(['original'=>true,'password'=>'state-secret'],$other->state($scope,$tenant)->data());
	$store->release($lease);

	$raw=(string)$fixture['pdo']->query('SELECT document_json FROM mig_lifecycle_scopes WHERE scope_name = \'orders\'')->fetchColumn();
	$t->notContains($lease->token(),$raw);
	$t->notContains('pdo-migration-token-',$raw);
	$t->contains('[REDACTED]',$raw);
	$t->same($store->manifest(),$store->jsonSerialize());
})->tag('panel','migrations','pdo','conformance','durability','compensation')->maxMillis(15000);

test('platform discovery exposes the PDO adapter and accepts one explicit host-owned migration store graph',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'platform',['table_prefix'=>'mig_platform']);
	$store=$fixture['store'];
	$store->installSchema();
	[$definition]=dp_panel_pdo_migration_edge('platform-scope');
	$disabled=[
		'operations'=>false,
		'distributed_operations'=>false,
		'data'=>false,
		'data_surfaces'=>false,
		'realtime'=>false,
		'workflows'=>false,
		'automation'=>false,
		'agent_workflows'=>false,
		'authentication'=>false,
		'iam'=>false,
		'studio'=>false,
		'notifications'=>false,
		'media'=>false,
		'localization'=>false,
		'preferences'=>false,
		'collaboration'=>false,
		'relations'=>false,
		'security'=>false,
		'development'=>false,
		'extensions'=>false,
		'platform'=>false,
	];
	$platform=PanelPlatform::defaults([
		'state_root'=>$t->tempDirectory('panel-pdo-migration-platform'),
		'migrations'=>['store'=>$store,'definitions'=>[$definition],'authorize'=>static fn():bool=>true],
	]+$disabled);
	$t->same($store,$platform->migrationStore());
	$t->same($store,$platform->migrationRunner()->store());
	$t->same($definition,$platform->migrationRegistry()->get($definition->id()));
	$manifest=$platform->manifest()->jsonSerialize();
	$t->isTrue($manifest['domains']['migrations']['features']['pdo_store']);
	$t->isTrue($manifest['domains']['migrations']['features']['storage_error']);
	$t->isTrue($manifest['domains']['migrations']['configured']);
	$t->throws(static fn()=>PanelPlatform::defaults([
		'state_root'=>$t->tempDirectory('panel-pdo-migration-platform-invalid'),
		'migrations'=>['store'=>new stdClass()],
	]+$disabled),InvalidArgumentException::class);
})->tag('panel','migrations','pdo','platform','discovery')->maxMillis(7000);

test('migration handlers and checkpoints share the constructor PDO transaction without unsafe handler replay',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'handler-transaction',[
		'table_prefix'=>'mig_handler',
		'transaction_retries'=>10,
	]);
	$pdo=$fixture['pdo'];
	$store=$fixture['store'];
	$store->installSchema();
	$pdo->exec('CREATE TABLE migration_side_effects (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)');
	$seed=$store->seed('catalog',null,PanelMigrationVersion::make('1.0.0',1),['rows'=>1]);
	$failedCalls=0;
	$failed=PanelMigrationDefinition::make(
		'catalog.upgrade',
		'catalog',
		PanelMigrationVersion::make('1.0.0',1),
		PanelMigrationVersion::make('2.0.0',2),
		static function(PanelMigrationContext $context) use ($pdo,&$failedCalls):PanelMigrationBatch {
			$failedCalls++;
			$pdo->exec("INSERT INTO migration_side_effects (marker) VALUES ('rolled-back')");
			throw new RuntimeException('handler rollback probe');
		},
		['batch_size'=>10],
	);
	$successful=PanelMigrationDefinition::make(
		'catalog.upgrade',
		'catalog',
		PanelMigrationVersion::make('1.0.0',1),
		PanelMigrationVersion::make('2.0.0',2),
		static function(PanelMigrationContext $context) use ($pdo):PanelMigrationBatch {
			$pdo->exec("INSERT INTO migration_side_effects (marker) VALUES ('committed')");
			$data=$context->data();
			$data['committed']=true;
			return PanelMigrationBatch::complete($data,1);
		},
		['batch_size'=>10],
	);
	$t->same($failed->digest(),$successful->digest());
	$plan=(new PanelMigrationPlanner(new PanelMigrationRegistry([$successful])))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$lease=$store->acquire('catalog',null,'transaction-worker',30);
	$runId=(string)$store->begin($lease,$plan)->runId();
	$t->throws(static fn()=>$store->applyBatch($lease,$runId,$plan,$failed),RuntimeException::class);
	$t->same(1,$failedCalls);
	$t->same(0,(int)$pdo->query('SELECT COUNT(*) FROM migration_side_effects')->fetchColumn());
	$t->same($seed->digest(),$store->state('catalog')->digest());
	$t->same(0,$store->report($runId)?->jsonSerialize()['batch_count']);
	$store->applyBatch($lease,$runId,$plan,$successful);
	$store->complete($lease,$runId,$plan);
	$t->same(1,(int)$pdo->query('SELECT COUNT(*) FROM migration_side_effects')->fetchColumn());
	$t->same('committed',(string)$pdo->query('SELECT marker FROM migration_side_effects')->fetchColumn());
	$t->same(true,$store->state('catalog')->data()['committed']);
	$store->release($lease);
})->tag('panel','migrations','pdo','transactions','handler','rollback')->maxMillis(7000);

test('independent connections renew recover and fence migration ownership without exposing bearer proofs',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'leases',['table_prefix'=>'mig_leases']);
	$first=$fixture['store'];
	$first->installSchema();
	$second=new PanelPdoMigrationStore(
		dp_panel_pdo_migrations_connection($fixture['path']),
		['table_prefix'=>'mig_leases'],
		$fixture['clock'],
		static fn():string=>str_repeat('s',48),
		static fn():string=>'second-run',
	);
	$seed=$first->seed('inventory','tenant-a',PanelMigrationVersion::make('1.0.0',1),['items'=>[]]);
	[$definition,$registry]=dp_panel_pdo_migration_edge('inventory');
	$plan=(new PanelMigrationPlanner($registry))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$lease=$first->acquire('inventory','tenant-a','worker-a',5);
	$t->same(null,$second->acquire('inventory','tenant-a','worker-b',5));
	$t->throws(static fn()=>$second->seed('inventory','tenant-a',PanelMigrationVersion::make('1.0.0',1),[]),PanelMigrationConflict::class);
	$renewed=$first->renew($lease,5);
	$runId=(string)$first->begin($renewed,$plan)->runId();
	$forged=PanelMigrationLease::make('inventory','tenant-a','worker-a',str_repeat('f',48),$renewed->fence(),$renewed->acquiredAt(),$renewed->expiresAt(),$renewed->renewedAt());
	$t->throws(static fn()=>$second->renew($forged),PanelMigrationLeaseLost::class);
	$fixture['advance'](6);
	$recovered=$second->recoverExpired();
	$t->same(1,count($recovered));
	$t->same('paused',$recovered[0]->status());
	$t->same([],$second->recoverExpired());
	$t->throws(static fn()=>$first->release($renewed),PanelMigrationLeaseLost::class);
	$next=$second->acquire('inventory','tenant-a','worker-b',5);
	$t->same(2,$next?->fence());
	$t->same(1,$second->begin($next,$plan)->jsonSerialize()['resumes']);
	$t->same($runId,$second->report($runId)?->runId());
	$second->release($next);
	$raw=(string)$fixture['pdo']->query('SELECT document_json FROM mig_leases_scopes')->fetchColumn();
	$t->notContains($renewed->token(),$raw);
	$t->notContains(str_repeat('s',48),$raw);
})->tag('panel','migrations','pdo','leases','recovery','fencing')->maxMillis(8000);

test('independent php workers acquire exactly one shared migration scope lease',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'process-race',[
		'table_prefix'=>'mig_process',
		'transaction_retries'=>10,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	$panelRoot=dirname(__DIR__);
	$code=<<<'PHP'
$files=['PanelMigrationIntegrity.php','PanelMigrationVersion.php','PanelMigrationState.php','PanelMigrationLease.php','PanelMigrationSnapshot.php','PanelMigrationReport.php','PanelMigrationBatch.php','PanelMigrationContext.php','PanelMigrationDefinition.php','PanelMigrationPlan.php','PanelMigrationConflict.php','PanelMigrationLeaseLost.php','PanelMigrationStalePlan.php','PanelMigrationStore.php','PanelMigrationStorageException.php','PanelPdoMigrationStore.php'];
foreach($files as $source){require $argv[1].'/Framework/Migrations/'.$source;}
$pdo=new PDO('sqlite:'.$argv[2]);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys = ON');$pdo->exec('PRAGMA busy_timeout = 5000');
$token=str_pad('process-migration-token-'.$argv[3],64,'x');
$store=new \Dataphyre\Panel\PanelPdoMigrationStore($pdo,['table_prefix'=>'mig_process','transaction_retries'=>10],null,static fn():string=>$token);
echo $store->acquire('shared-scope','tenant-a','worker-'.$argv[3],30)===null?'0':'1';
PHP;
	$workers=[];
	foreach(['a','b','c'] as $worker){
		$workers[]=$t->startPhpProcess(['-r',$code,$panelRoot,$fixture['path'],$worker],timeout_millis:20000);
	}
	$winners=0;
	foreach($workers as $process){
		$result=$process->wait();
		if(!$result->succeeded()){ throw new RuntimeException('Migration worker failed: '.$result->stderr().' '.$result->stdout()); }
		$t->same('',trim($result->stderr()));
		$winners+=(int)trim($result->stdout());
	}
	$t->same(1,$winners);
	$row=$fixture['pdo']->query("SELECT fence, lease_fence, document_json FROM mig_process_scopes WHERE scope_name = 'shared-scope'")->fetch(PDO::FETCH_ASSOC);
	$t->same(1,(int)$row['fence']);
	$t->same(1,(int)$row['lease_fence']);
	foreach(['a','b','c'] as $worker){ $t->notContains('process-migration-token-'.$worker,(string)$row['document_json']); }
})->tag('panel','migrations','pdo','cross-process','race','fencing')->maxMillis(30000);

test('bounded migration change metadata reports retained gaps and future resets without state payloads',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'changes',[
		'table_prefix'=>'mig_changes',
		'change_retention'=>8,
	]);
	$store=$fixture['store'];
	$store->installSchema();
	for($i=1;$i<=6;$i++){
		$lease=$store->acquire('change-scope',null,'worker-'.$i,30);
		$store->release($lease);
	}
	$t->same(12,$store->cursor());
	$fresh=$store->changesSince(0,2);
	$t->same(2,count($fresh['changes']));
	$t->isFalse($fresh['reset_required']);
	$t->isTrue($fresh['oldest_cursor']>1);
	$stale=$store->changesSince(1,100);
	$t->isTrue($stale['reset_required']);
	$t->same('host_scope_inventory',$stale['snapshot']['resync']);
	$t->same([],$stale['changes']);
	$future=$store->changesSince(99,10);
	$t->isTrue($future['reset_required']);
	$t->same(12,$future['cursor']);
	$current=$store->changesSince(12,10);
	$t->isFalse($current['reset_required']);
	$t->same([],$current['changes']);
	$raw=(string)$fixture['pdo']->query("SELECT GROUP_CONCAT(event_type || scope_name || COALESCE(run_id, '')) FROM mig_changes_changes")->fetchColumn();
	$t->notContains('document_json',$raw);
	$t->notContains('pdo-migration-token',$raw);
	$t->same(['cursor','type','scope','tenant','occurred_at','fence'],array_keys($fresh['changes'][0]));
})->tag('panel','migrations','pdo','change-feed','retention','privacy')->maxMillis(7000);

test('pdo migration store fails closed on schema drift corruption contention oversize documents and caller transactions',static function(Context $t):void {
	$missing=dp_panel_pdo_migrations_fixture($t,'missing',['table_prefix'=>'mig_missing']);
	dp_panel_pdo_migration_error($t,static fn()=>$missing['store']->state('x'),'schema_required');

	$broken=dp_panel_pdo_migrations_fixture($t,'broken',['table_prefix'=>'mig_broken']);
	$broken['pdo']->exec('CREATE TABLE mig_broken_meta (singleton INTEGER PRIMARY KEY)');
	dp_panel_pdo_migration_error($t,static fn()=>$broken['store']->installSchema(),'migration_failed');

	$drift=dp_panel_pdo_migrations_fixture($t,'drift',['table_prefix'=>'mig_drift']);
	$drift['store']->installSchema();
	$drift['pdo']->exec('UPDATE mig_drift_meta SET schema_version = 9');
	dp_panel_pdo_migration_error($t,static fn()=>$drift['store']->state('x'),'schema_incompatible');
	dp_panel_pdo_migration_error($t,static fn()=>$drift['store']->installSchema(),'schema_incompatible');

	$nested=dp_panel_pdo_migrations_fixture($t,'nested',['table_prefix'=>'mig_nested']);
	$nested['store']->installSchema();
	$nested['pdo']->beginTransaction();
	$conflict=dp_panel_pdo_migration_error($t,static fn()=>$nested['store']->state('x'),'transaction_conflict');
	$t->isTrue($conflict->retryable());
	dp_panel_pdo_migration_error($t,static fn()=>$nested['store']->installSchema(),'transaction_conflict');
	$nested['pdo']->rollBack();

	$locked=dp_panel_pdo_migrations_fixture($t,'locked',[
		'table_prefix'=>'mig_locked',
		'transaction_retries'=>1,
		'retry_delay_microseconds'=>1,
	]);
	$locked['store']->installSchema();
	$locker=dp_panel_pdo_migrations_connection($locked['path'],0);
	$blockedPdo=dp_panel_pdo_migrations_connection($locked['path'],0);
	$blocked=new PanelPdoMigrationStore($blockedPdo,['table_prefix'=>'mig_locked','transaction_retries'=>1,'retry_delay_microseconds'=>1]);
	$locker->exec('BEGIN IMMEDIATE');
	$unavailable=dp_panel_pdo_migration_error($t,static fn()=>$blocked->acquire('locked-scope',null),'storage_unavailable');
	$t->isTrue($unavailable->retryable());
	$locker->exec('ROLLBACK');

	$corrupt=dp_panel_pdo_migrations_fixture($t,'corrupt',['table_prefix'=>'mig_corrupt']);
	$corrupt['store']->installSchema();
	$lease=$corrupt['store']->acquire('corrupt-scope',null);
	$corrupt['store']->release($lease);
	$corrupt['pdo']->exec('UPDATE mig_corrupt_scopes SET document_bytes = document_bytes + 1');
	dp_panel_pdo_migration_error($t,static fn()=>$corrupt['store']->state('corrupt-scope'),'storage_corrupt');

	$digest=dp_panel_pdo_migrations_fixture($t,'digest',['table_prefix'=>'mig_digest']);
	$digest['store']->installSchema();
	$lease=$digest['store']->acquire('digest-scope',null);
	$digest['store']->release($lease);
	$digest['pdo']->exec("UPDATE mig_digest_scopes SET document_digest = '".str_repeat('0',64)."'");
	dp_panel_pdo_migration_error($t,static fn()=>$digest['store']->state('digest-scope'),'storage_corrupt');

	$oversize=dp_panel_pdo_migrations_fixture($t,'oversize',['table_prefix'=>'mig_oversize','maximum_scope_bytes'=>4096]);
	$oversize['store']->installSchema();
	dp_panel_pdo_migration_error($t,static fn()=>$oversize['store']->seed('large',null,PanelMigrationVersion::make('1.0.0',1),['payload'=>str_repeat('x',5000)]),'document_too_large');
})->tag('panel','migrations','pdo','fail-closed','corruption','locking')->maxMillis(12000);

test('pdo migration store validates configuration clocks factories fences and typed storage failures',static function(Context $t):void {
	$t->throws(static fn()=>PanelPdoMigrationStore::schemaStatementsFor('oracle'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoMigrationStore::schemaStatementsFor('sqlite','bad-prefix'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoMigrationStore::dialectPlanFor('sqlsrv'),InvalidArgumentException::class);
	$pdo=new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	foreach([
		['unknown'=>1],
		['table_prefix'=>'1bad'],
		['maximum_scope_bytes'=>1],
		['change_retention'=>7],
		['transaction_retries'=>11],
		['retry_delay_microseconds'=>100001],
	] as $options){
		$t->throws(static fn()=>new PanelPdoMigrationStore($pdo,$options),InvalidArgumentException::class);
	}
	$silent=new PDO('sqlite::memory:');
	$silent->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
	$t->throws(static fn()=>new PanelPdoMigrationStore($silent),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelMigrationStorageException('Bad Code','bad'),InvalidArgumentException::class);
	$typed=new PanelMigrationStorageException('typed_failure','Typed',true);
	$t->same('typed_failure',$typed->errorCode());
	$t->isTrue($typed->retryable());

	$badClock=new PanelPdoMigrationStore($pdo,['table_prefix'=>'mig_bad_clock'],static fn():string=>'not-a-time');
	$badClock->installSchema();
	$t->throws(static fn()=>$badClock->acquire('x',null),UnexpectedValueException::class);
	$integerClock=new PanelPdoMigrationStore($pdo,['table_prefix'=>'mig_integer_clock'],static fn():int=>0,static fn():string=>str_repeat('i',48));
	$integerClock->installSchema();
	$t->same('1970-01-01T00:00:00+00:00',$integerClock->acquire('integer',null)?->acquiredAt());
	$dateClock=new PanelPdoMigrationStore($pdo,['table_prefix'=>'mig_date_clock'],static fn():DateTimeImmutable=>new DateTimeImmutable('2026-01-01T00:00:00-05:00'),static fn():string=>str_repeat('d',48));
	$dateClock->installSchema();
	$t->same('2026-01-01T05:00:00+00:00',$dateClock->acquire('date',null)?->acquiredAt());

	foreach([
		static fn():array=>[],
		static fn():string=>'short',
		static fn():string=>str_repeat('x',513),
		static fn():string=>str_repeat('x',31)."\0",
	] as $index=>$factory){
		$fixture=dp_panel_pdo_migrations_fixture($t,'bad-token-'.$index,['table_prefix'=>'mig_bad_token_'.$index]);
		$store=new PanelPdoMigrationStore($fixture['pdo'],['table_prefix'=>'mig_bad_token_'.$index],$fixture['clock'],$factory);
		$store->installSchema();
		$t->throws(static fn()=>$store->acquire('x',null),UnexpectedValueException::class);
	}

	$runFixture=dp_panel_pdo_migrations_fixture($t,'bad-run',['table_prefix'=>'mig_bad_run']);
	$runStore=new PanelPdoMigrationStore($runFixture['pdo'],['table_prefix'=>'mig_bad_run'],$runFixture['clock'],static fn():string=>str_repeat('r',48),static fn():array=>[]);
	$runStore->installSchema();
	$seed=$runStore->seed('run-scope',null,PanelMigrationVersion::make('1.0.0',1));
	[$definition,$registry]=dp_panel_pdo_migration_edge('run-scope');
	$plan=(new PanelMigrationPlanner($registry))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$lease=$runStore->acquire('run-scope',null);
	$t->throws(static fn()=>$runStore->begin($lease,$plan),UnexpectedValueException::class);

	$duplicate=dp_panel_pdo_migrations_fixture($t,'duplicate-run',['table_prefix'=>'mig_duplicate_run']);
	$duplicateStore=new PanelPdoMigrationStore($duplicate['pdo'],['table_prefix'=>'mig_duplicate_run'],$duplicate['clock'],static fn():string=>str_repeat('q',48),static fn():string=>'fixed-run');
	$duplicateStore->installSchema();
	foreach(['first','second'] as $scope){
		$state=$duplicateStore->seed($scope,null,PanelMigrationVersion::make('1.0.0',1));
		[$edge,$edgeRegistry]=dp_panel_pdo_migration_edge($scope);
		$scopePlan=(new PanelMigrationPlanner($edgeRegistry))->plan($state,PanelMigrationVersion::make('2.0.0',2));
		$scopeLease=$duplicateStore->acquire($scope,null);
		if($scope==='first'){
			$duplicateStore->begin($scopeLease,$scopePlan);
		}else{
			$t->throws(static fn()=>$duplicateStore->begin($scopeLease,$scopePlan),PanelMigrationConflict::class);
		}
		$duplicateStore->release($scopeLease);
	}

	$fence=dp_panel_pdo_migrations_fixture($t,'fence',['table_prefix'=>'mig_fence']);
	$fence['store']->installSchema();
	$owned=$fence['store']->acquire('fence-scope',null);
	$fence['store']->release($owned);
	$fence['pdo']->exec('UPDATE mig_fence_scopes SET fence = '.PHP_INT_MAX);
	dp_panel_pdo_migration_error($t,static fn()=>$fence['store']->acquire('fence-scope',null),'fence_exhausted');
})->tag('panel','migrations','pdo','validation','factories','exact-coverage')->maxMillis(15000);

test('failed runs restore their durable backup and reject stale plans missing runs and expired leases',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'failure-restore',['table_prefix'=>'mig_failure_restore']);
	$store=$fixture['store'];
	$store->installSchema();
	$seed=$store->seed('restore-scope',null,PanelMigrationVersion::make('1.0.0',1),['original'=>true]);
	[$definition,$registry]=dp_panel_pdo_migration_edge('restore-scope');
	$plan=(new PanelMigrationPlanner($registry))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$lease=$store->acquire('restore-scope',null,'restore-worker',30);
	$runId=(string)$store->begin($lease,$plan)->runId();

	[$alternative,$alternativeRegistry]=dp_panel_pdo_migration_edge('restore-scope',id:'alternate');
	$alternativePlan=(new PanelMigrationPlanner($alternativeRegistry))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$t->throws(static fn()=>$store->complete($lease,$runId,$alternativePlan),PanelMigrationStalePlan::class);

	$otherSeed=$store->seed('other-scope',null,PanelMigrationVersion::make('1.0.0',1));
	[$otherDefinition,$otherRegistry]=dp_panel_pdo_migration_edge('other-scope');
	$otherPlan=(new PanelMigrationPlanner($otherRegistry))->plan($otherSeed,PanelMigrationVersion::make('2.0.0',2));
	$t->throws(static fn()=>$store->begin($lease,$otherPlan),PanelMigrationConflict::class);
	$t->throws(static fn()=>$store->fail($lease,'missing-run',new RuntimeException('missing')),OutOfBoundsException::class);

	$failed=$store->fail($lease,$runId,new RuntimeException('password=supersecret'));
	$t->same('failed',$failed->status());
	$t->contains('password=[REDACTED]',(string)$failed->jsonSerialize()['errors'][0]['message']);
	$t->same('running',$store->begin($lease,$plan)->status());
	$store->applyBatch($lease,$runId,$plan,$definition);
	$t->same(true,$store->state('restore-scope')->data()['migrated']);
	$restored=$store->restoreSnapshot($lease,$runId,$plan);
	$t->same('rolled_back',$restored->status());
	$t->same('snapshot_restore',$restored->jsonSerialize()['rollback_mode']);
	$t->same(['original'=>true],$store->state('restore-scope')->data());
	$t->throws(static fn()=>$store->fail($lease,$runId,new RuntimeException('terminal')),PanelMigrationConflict::class);

	$document=json_decode((string)$fixture['pdo']->query("SELECT document_json FROM mig_failure_restore_scopes WHERE scope_name = 'restore-scope'")->fetchColumn(),true,128,JSON_THROW_ON_ERROR);
	$state=$store->state('restore-scope');
	$stale=PanelMigrationState::make('restore-scope',null,$state->version(),$state->data(),$state->applied(),$state->revision()+1);
	$t->throws(static fn()=>$t->nonPublic($store)->invoke('assertRunState',$document['runs'][$runId],$stale,$plan),PanelMigrationStalePlan::class);
	$store->release($lease);

	$expired=$store->acquire('expired-scope',null,'expiring-worker',5);
	$fixture['advance'](6);
	$t->throws(static fn()=>$store->renew($expired,5),PanelMigrationLeaseLost::class);
})->tag('panel','migrations','pdo','failure','snapshot-restore','stale-plan','expiry')->maxMillis(10000);

test('scope documents indexes mirrors and change rows fail closed under adversarial corruption',static function(Context $t):void {
	$fixture=dp_panel_pdo_migrations_fixture($t,'integrity',['table_prefix'=>'mig_integrity']);
	$store=$fixture['store'];
	$store->installSchema();
	$seed=$store->seed('integrity-scope',null,PanelMigrationVersion::make('1.0.0',1),['safe'=>true]);
	[$definition,$registry]=dp_panel_pdo_migration_edge('integrity-scope');
	$plan=(new PanelMigrationPlanner($registry))->plan($seed,PanelMigrationVersion::make('2.0.0',2));
	$lease=$store->acquire('integrity-scope',null,'integrity-worker',30);
	$runId=(string)$store->begin($lease,$plan)->runId();
	$row=$fixture['pdo']->query("SELECT scope_key, scope_name, tenant_id, fence, document_revision, document_digest, document_json, document_bytes, lease_owner, lease_fence, lease_expires_at, updated_at FROM mig_integrity_scopes WHERE scope_name = 'integrity-scope'")->fetch(PDO::FETCH_ASSOC);
	if(!is_array($row)){ throw new RuntimeException('Integrity scope fixture row is unavailable.'); }
	$document=json_decode($row['document_json'],true,128,JSON_THROW_ON_ERROR);
	$scopeKey=(string)$row['scope_key'];
	$fence=(int)$row['fence'];
	$internals=$t->nonPublic($store);

	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('stateFromDocument',['state'=>[]]),'storage_corrupt');
	$badScope=$row;
	$badScope['scope_name']='bad scope';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateScopeRow',$badScope),'storage_corrupt');
	$badJson=$row;
	$badJson['document_json']='{';
	$badJson['document_bytes']=1;
	$badJson['document_digest']=hash('sha256','{');
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateScopeRow',$badJson),'storage_corrupt');
	$badMirror=$row;
	$badMirror['lease_owner']='superseded-worker';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateScopeRow',$badMirror),'storage_corrupt');

	$invalidRunKey=$internals->invoke('defaultDocument','integrity-scope',null);
	$invalidRunKey['runs']['bad run']=[];
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$invalidRunKey,'integrity-scope',null,0),'storage_corrupt');
	$invalidRun=$internals->invoke('defaultDocument','integrity-scope',null);
	$invalidRun['runs']['run-good']=[
		'run_id'=>'different-run',
		'scope'=>'integrity-scope',
		'tenant'=>null,
		'plan_digest'=>str_repeat('a',64),
		'expected_digest'=>str_repeat('b',64),
	];
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$invalidRun,'integrity-scope',null,0),'storage_corrupt');
	$invalidReport=$document;
	$invalidReport['runs'][$runId]['status']='unknown';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$invalidReport,'integrity-scope',null,$fence),'storage_corrupt');

	$malformedSnapshot=$internals->invoke('defaultDocument','integrity-scope',null);
	$malformedSnapshot['snapshots']['snapshot-good']='invalid';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$malformedSnapshot,'integrity-scope',null,0),'storage_corrupt');
	$invalidSnapshot=$internals->invoke('defaultDocument','integrity-scope',null);
	$invalidSnapshot['snapshots']['snapshot-good']=['id'=>'snapshot-good','state'=>[]];
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$invalidSnapshot,'integrity-scope',null,0),'storage_corrupt');
	$wrongSnapshotScope=$document;
	$wrongSnapshotScope['snapshots'][$runId.'.backup']['scope']='another-scope';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$wrongSnapshotScope,'integrity-scope',null,$fence),'storage_corrupt');
	$invalidIdempotency=$internals->invoke('defaultDocument','integrity-scope',null);
	$invalidIdempotency['idempotency']['bad-key']='missing-run';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateDocument',$invalidIdempotency,'integrity-scope',null,0),'storage_corrupt');

	$invalidEncoding=$internals->invoke('defaultDocument','integrity-scope',null);
	$invalidEncoding['state']=[];
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('encodeDocument',$invalidEncoding,'integrity-scope',null,0),'storage_corrupt');
	$opaqueEncoding=$internals->invoke('defaultDocument','integrity-scope',null);
	$handle=fopen('php://memory','r');
	$opaqueEncoding['opaque']=$handle;
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('encodeDocument',$opaqueEncoding,'integrity-scope',null,0),'document_invalid');
	fclose($handle);

	$leaseState=[
		'scope'=>'integrity-scope',
		'tenant'=>null,
		'owner'=>'bad owner',
		'token_hash'=>str_repeat('a',64),
		'fence'=>1,
		'acquired_at'=>'2026-07-14T12:00:00+00:00',
		'renewed_at'=>'2026-07-14T12:00:00+00:00',
		'expires_at'=>'2026-07-14T12:01:00+00:00',
	];
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateLeaseState',$leaseState,'integrity-scope',null,1),'storage_corrupt');
	$leaseState['owner']='integrity-worker';
	$leaseState['scope']='another-scope';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('validateLeaseState',$leaseState,'integrity-scope',null,1),'storage_corrupt');

	$t->throws(
		static fn()=>$internals->invoke('persistScope','integrity-scope',null,$scopeKey,$document,$fence,(int)$row['document_revision']+100,'2026-07-14T12:00:00+00:00'),
		PanelMigrationConflict::class,
	);
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('assertRunIndex','absent-run',$scopeKey,$document['runs'][$runId]),'storage_corrupt');
	$store->seed('empty-index-scope',null,PanelMigrationVersion::make('1.0.0',1));
	$emptyScopeKey=$internals->invoke('scopeKey','empty-index-scope',null);
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('reportFromIndex',[
		'run_id'=>'ghost-run',
		'scope_key'=>$emptyScopeKey,
		'plan_digest'=>str_repeat('c',64),
		'started_at'=>'2026-07-14T12:00:00+00:00',
	]),'storage_corrupt');
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('documentFromIndex',[
		'run_id'=>'ghost-run',
		'scope_key'=>'invalid',
		'plan_digest'=>str_repeat('c',64),
		'started_at'=>'2026-07-14T12:00:00+00:00',
	]),'storage_corrupt');

	$change=[
		'change_id'=>1,
		'event_type'=>'migration.probe',
		'scope_key'=>$scopeKey,
		'scope_name'=>'integrity-scope',
		'tenant_id'=>null,
		'run_id'=>null,
		'fence'=>1,
		'occurred_at'=>'2026-07-14T12:00:00+00:00',
	];
	$badChangeScope=$change;
	$badChangeScope['scope_name']='bad scope';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateChange',$badChangeScope),'storage_corrupt');
	$numericRun=$change;
	$numericRun['run_id']=7;
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateChange',$numericRun),'storage_corrupt');
	$invalidRunId=$change;
	$invalidRunId['run_id']='bad run';
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('hydrateChange',$invalidRunId),'storage_corrupt');
	dp_panel_pdo_migration_error($t,static fn()=>$internals->invoke('integer','01',0),'storage_corrupt');
	$store->release($lease);
})->tag('panel','migrations','pdo','integrity','corruption','indexes','exact-coverage')->maxMillis(12000);

test('driver-specific SQL and PDO failure seams remain deterministic and fail closed',static function(Context $t):void {
	$inspection=$t->scriptedPdo()->failDriverWith(new RuntimeException('connection inspection probe'));
	$t->throws(static fn()=>new PanelPdoMigrationStore($inspection),InvalidArgumentException::class);

	$installation=dp_panel_pdo_migrations_fixture($t,'schema-storage-failure',['table_prefix'=>'mig_schema_storage']);
	$installation['pdo']->exec('CREATE TABLE mig_schema_storage_meta (singleton INTEGER NOT NULL PRIMARY KEY CHECK (singleton = 1), schema_version INTEGER NOT NULL CHECK (schema_version > 0))');
	$installation['pdo']->exec('CREATE TRIGGER mig_schema_storage_ignore BEFORE INSERT ON mig_schema_storage_meta BEGIN SELECT RAISE(IGNORE); END');
	dp_panel_pdo_migration_error($t,static fn()=>$installation['store']->installSchema(),'migration_failed');

	$mysqlPdo=$t->scriptedPdo('mysql');
	$mysqlStore=new PanelPdoMigrationStore($mysqlPdo,['table_prefix'=>'mig_mysql_probe']);
	$mysqlInternals=$t->nonPublic($mysqlStore);
	$mysqlKey=$mysqlInternals->invoke('scopeKey','mysql-scope',null);
	$mysqlInternals->invoke('ensureScopeRow','mysql-scope',null,$mysqlKey,'2026-07-14T12:00:00+00:00');
	$t->contains('INSERT IGNORE',implode("\n",$mysqlPdo->preparedSql()));

	$pgsqlPdo=$t->scriptedPdo('pgsql')->queueRows([])->queueScalar(1);
	$pgsqlStore=new PanelPdoMigrationStore($pgsqlPdo,['table_prefix'=>'mig_pgsql_probe']);
	$pgsqlInternals=$t->nonPublic($pgsqlStore);
	$pgsqlKey=$pgsqlInternals->invoke('scopeKey','pgsql-scope',null);
	$pgsqlInternals->invoke('ensureScopeRow','pgsql-scope',null,$pgsqlKey,'2026-07-14T12:00:00+00:00');
	$pgsqlInternals->invoke('recordChange','migration.probe',$pgsqlKey,'pgsql-scope',null,null,null,'2026-07-14T12:00:00+00:00');
	$t->contains('ON CONFLICT',implode("\n",$pgsqlPdo->preparedSql()));
	$t->contains('RETURNING change_id',implode("\n",$pgsqlPdo->preparedSql()));

	$reservationFailure=$t->scriptedPdo()->queuePrepareFailure(new PDOException('connection unavailable'));
	$reservationStore=new PanelPdoMigrationStore($reservationFailure,['table_prefix'=>'mig_reservation_failure'],null,static fn():string=>str_repeat('r',48),static fn():string=>'reserved-run');
	$t->throws(static fn()=>$t->nonPublic($reservationStore)->invoke('reserveRunId',str_repeat('a',64),str_repeat('b',64),'2026-07-14T12:00:00+00:00'),PDOException::class);

	$schemaFailure=$t->scriptedPdo()->queuePrepareFailure(new PDOException('transport failure'));
	$schemaStore=new PanelPdoMigrationStore($schemaFailure,['table_prefix'=>'mig_schema_failure']);
	$t->throws(static fn()=>$t->nonPublic($schemaStore)->invoke('assertSchema',false),PDOException::class);

	$mysqlReadPdo=$t->scriptedPdo('mysql')->queueExecResult(false);
	$mysqlReadStore=new PanelPdoMigrationStore($mysqlReadPdo,['table_prefix'=>'mig_mysql_read']);
	$t->throws(static fn()=>$t->nonPublic($mysqlReadStore)->invoke('beginTransaction',false),RuntimeException::class);
	$sqliteBeginPdo=$t->scriptedPdo()->returnBeginResult(false);
	$sqliteBeginStore=new PanelPdoMigrationStore($sqliteBeginPdo,['table_prefix'=>'mig_sqlite_begin']);
	$t->throws(static fn()=>$t->nonPublic($sqliteBeginStore)->invoke('beginTransaction',false),RuntimeException::class);
	$pgsqlReadPdo=$t->scriptedPdo('pgsql')->queueExecResult(false);
	$pgsqlReadStore=new PanelPdoMigrationStore($pgsqlReadPdo,['table_prefix'=>'mig_pgsql_read']);
	$t->throws(static fn()=>$t->nonPublic($pgsqlReadStore)->invoke('beginTransaction',false),RuntimeException::class);

	$rollbackPdo=$t->scriptedPdo()->markTransactionActive()->failRollbackWith(new RuntimeException('rollback probe'));
	$rollbackStore=new PanelPdoMigrationStore($rollbackPdo,['table_prefix'=>'mig_rollback_probe']);
	$t->nonPublic($rollbackStore)->invoke('rollbackTransaction');
	$t->same(['rollback'],$rollbackPdo->operationNames());

	$duplicate=new PDOException('native duplicate');
	$duplicate->errorInfo=['HY000',1062,'duplicate entry'];
	$t->isTrue($mysqlInternals->invoke('duplicate',$duplicate));
})->tag('panel','migrations','pdo','dialects','failures','exact-coverage')->maxMillis(8000);
