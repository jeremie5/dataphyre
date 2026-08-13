<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAgentAuditReceipt;
use Dataphyre\Panel\PanelAgentException;
use Dataphyre\Panel\PanelAgentExecutionResult;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentWorkflowStorageException;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelPdoAgentWorkflowStore;
use Dataphyre\Test\Context;
use Dataphyre\Test\ScriptedPdoStatement;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_pdo_agent_connection(string $path,int $busyMilliseconds=5000):PDO {
	$pdo=new PDO('sqlite:'.$path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA busy_timeout = '.max(0,$busyMilliseconds));
	return $pdo;
}

/**
 * @param array<string,mixed> $options
 * @return array{path:string,pdo:PDO,store:PanelPdoAgentWorkflowStore,clock:Closure,advance:Closure,factory:Closure}
 */
function dp_panel_pdo_agent_fixture(Context $t,string $name,array $options=[]):array {
	$path=$t->tempDirectory('panel-pdo-agent-'.$name).DIRECTORY_SEPARATOR.'workflows.sqlite';
	$pdo=dp_panel_pdo_agent_connection($path);
	$now=1784016000;
	$nextId=0;
	$clock=static function() use (&$now):int { return $now; };
	$advance=static function(int $seconds) use (&$now):void { $now+=$seconds; };
	$factory=static function() use (&$nextId):string { return 'agent_reservation_pdo_'.(++$nextId); };
	$store=new PanelPdoAgentWorkflowStore($pdo,$options,$clock,$factory);
	return compact('path','pdo','store','clock','advance','factory');
}

function dp_panel_pdo_agent_context(string $principal='operator:1',string $tenant='tenant-a',string $session='session-a'):PanelAgentRequestContext {
	return new PanelAgentRequestContext('operations',$tenant,$principal,$session,'request-pdo-agent-1');
}

/** @return array{plan:string,request:string,nonce:string} */
function dp_panel_pdo_agent_material(string $name):array {
	return [
		'plan'=>hash('sha256','pdo-agent-plan-'.$name),
		'request'=>hash('sha256','pdo-agent-request-'.$name),
		'nonce'=>substr(hash('sha256','pdo-agent-nonce-'.$name),0,32),
	];
}

function dp_panel_pdo_agent_receipt(
	PanelPdoAgentWorkflowStore $store,
	PanelAgentRequestContext $actor,
	string $plan,
	string $event,
	string $code,
	int $occurredAt,
	array $details=[]
):PanelAgentAuditReceipt {
	return PanelAgentAuditReceipt::create(
		count($store->audit())+1,
		$event,
		$actor,
		$plan,
		$code,
		$details,
		$store->lastAuditHash(),
		$occurredAt,
	);
}

function dp_panel_pdo_agent_domain_error(Context $t,callable $operation,string $code):PanelAgentException {
	try{
		$operation();
	}catch(PanelAgentException $error){
		$t->same($code,$error->errorCode());
		return $error;
	}
	throw new RuntimeException("Expected PanelAgentException {$code}.");
}

function dp_panel_pdo_agent_storage_error(Context $t,callable $operation,string $code):PanelAgentWorkflowStorageException {
	try{
		$operation();
	}catch(PanelAgentWorkflowStorageException $error){
		$t->same($code,$error->errorCode());
		return $error;
	}
	throw new RuntimeException("Expected PanelAgentWorkflowStorageException {$code}.");
}

suite('Panel durable shared-SQL agent workflow store')
	->contract('panel.agent-workflow-store.pdo',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','optimistic-revisions','lease-fence','idempotency','audit-integrity','garbage-collection','change-feed')
	->isolation('case')
	->tag('panel','agents','pdo','distributed','security')
	->group('framework-coverage');

test('schema plans are portable explicit idempotent and connection-secret free',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'schema',[
		'table_prefix'=>'agent_schema',
		'lease_seconds'=>60,
		'max_entries'=>32,
		'retention_seconds'=>7200,
		'maximum_result_bytes'=>16384,
		'maximum_audit_bytes'=>8192,
		'change_retention'=>32,
		'transaction_retries'=>2,
		'retry_delay_microseconds'=>0,
	]);
	$store=$fixture['store'];
	$first=$store->installSchema();
	$second=$store->installSchema();

	$t->same('sqlite',$store->driver());
	$t->same(12,$first['statements']);
	$t->same($first,$second);
	$t->isTrue($first['idempotent']);
	$t->isFalse($first['destructive']);
	$t->same($store->schemaStatements(),PanelPdoAgentWorkflowStore::schemaStatementsFor('sqlite','agent_schema'));

	$sqlite=PanelPdoAgentWorkflowStore::schemaStatementsFor('sqlite','agent_plan');
	$mysql=PanelPdoAgentWorkflowStore::schemaStatementsFor('mysql','agent_plan');
	$pgsql=PanelPdoAgentWorkflowStore::schemaStatementsFor('pgsql','agent_plan');
	$t->same(12,count($sqlite));
	$t->same(7,count($mysql));
	$t->same(12,count($pgsql));
	$t->contains('AUTOINCREMENT',$sqlite[9]);
	$t->contains('ENGINE=InnoDB',$mysql[0]);
	$t->contains('GENERATED BY DEFAULT AS IDENTITY',$pgsql[9]);
	$t->same('BEGIN IMMEDIATE',PanelPdoAgentWorkflowStore::dialectPlanFor('sqlite')['write_begin']);
	$t->same(' FOR UPDATE',PanelPdoAgentWorkflowStore::dialectPlanFor('mysql')['lock_suffix']);
	$t->contains('REPEATABLE READ',PanelPdoAgentWorkflowStore::dialectPlanFor('pgsql')['read_after'][0]);

	$manifest=$store->manifest();
	$serialized=json_encode($store,JSON_THROW_ON_ERROR);
	$t->same('panel_pdo_agent_workflow_store',$manifest['type']);
	$t->isTrue($manifest['distributed']);
	$t->isTrue($manifest['renewable_fenced_reservations']);
	$t->isTrue($manifest['audit_hash_chain']);
	$t->isFalse($manifest['raw_idempotency_keys_stored']);
	$t->isFalse($manifest['raw_intent_nonces_stored']);
	$t->same($manifest,$store->jsonSerialize());
	foreach([$fixture['path'],'agent_schema','sqlite:','password','nonce_hash','result_json'] as $secret){
		$t->notContains($secret,$serialized);
	}
})->tag('panel','agents','pdo','schema','manifest')->maxMillis(5000);

test('independent connections share revisions leases results cancellations and audit integrity',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'lifecycle',['table_prefix'=>'agent_lifecycle','lease_seconds'=>60]);
	$first=$fixture['store'];
	$first->installSchema();
	$second=new PanelPdoAgentWorkflowStore(
		dp_panel_pdo_agent_connection($fixture['path']),
		['table_prefix'=>'agent_lifecycle','lease_seconds'=>60],
		$fixture['clock'],
		$fixture['factory'],
	);
	$actor=dp_panel_pdo_agent_context();
	$material=dp_panel_pdo_agent_material('lifecycle');
	$now=($fixture['clock'])();

	$t->same(0,$first->revision());
	$t->same([],$first->audit());
	$t->same('',$first->lastAuditHash());
	$planned=dp_panel_pdo_agent_receipt($first,$actor,$material['plan'],'plan_validated','planned',$now,['api_token'=>'remove-me']);
	$t->same(1,$first->append($planned,0));
	$t->same($planned->hash(),$second->lastAuditHash());

	$reservation=$first->reserve($material['plan'],$actor->scopeFingerprint(),'secret-idempotency-key',$material['request'],[$material['nonce']],1);
	$t->isTrue($reservation->acquiredNew());
	$t->same(2,$reservation->revision());
	$t->same($now+60,$reservation->expiresAt());
	dp_panel_pdo_agent_domain_error(
		$t,
		static fn()=>$second->lookup($material['plan'],$actor->scopeFingerprint(),'secret-idempotency-key',$material['request']),
		'execution_in_progress',
	);

	$renewed=$second->renew((string)$reservation->id(),$reservation->revision(),90);
	$t->same(3,$renewed->revision());
	$t->same($now+90,$renewed->expiresAt());
	$result=PanelAgentExecutionResult::make(
		true,
		'executed',
		$material['plan'],
		[['ordinal'=>1,'tool'=>'orders.update','ok'=>true,'code'=>'completed','output'=>['order'=>'ord-1','access_token'=>'remove-me'],'error'=>null,'retryable'=>false]],
		$renewed->revision(),
		null,
		['safe'=>'yes','api_key'=>'remove-me'],
	);
	$completed=$second->complete(
		(string)$renewed->id(),
		$result,
		$actor,
		'execution_completed',
		'executed',
		['summary'=>'updated','idempotency_key'=>'remove-me'],
		$now,
		$renewed->revision(),
	);
	$t->same(4,$completed->storeRevision());
	$t->same('[REDACTED]',$completed->metadata()['api_key']);
	$t->same('[REDACTED]',$completed->receipt()?->details()['idempotency_key']);

	$found=$first->lookup($material['plan'],$actor->scopeFingerprint(),'secret-idempotency-key',$material['request']);
	$t->same('executed',$found?->code());
	$t->same($completed->receipt()?->hash(),$found?->receipt()?->hash());
	$replay=$first->reserve($material['plan'],$actor->scopeFingerprint(),'secret-idempotency-key',$material['request'],[$material['nonce']],4);
	$t->isFalse($replay->acquiredNew());
	$t->same('executed',$replay->result()?->code());

	$cancelPlan=hash('sha256','pdo-agent-cancelled-plan');
	$cancellation=dp_panel_pdo_agent_receipt($first,$actor,$cancelPlan,'plan_cancelled','cancelled',$now,['reason'=>'operator']);
	$t->same(5,$second->cancel($cancelPlan,$cancellation,4));
	$t->isTrue($first->cancelled($cancelPlan));
	$t->same(5,$first->cancel($cancelPlan,$cancellation,5));
	$t->same(3,count($first->audit()));

	$changes=$first->changesSince(0,100);
	$t->same(5,$changes['cursor']);
	$t->same(['audit.appended','reservation.acquired','reservation.renewed','reservation.completed','plan.cancelled'],array_column($changes['changes'],'type'));
	$t->same(5,$first->cursor());

	$database=(string)file_get_contents($fixture['path']);
	foreach(['secret-idempotency-key',$material['nonce'],'operator:1','tenant-a','session-a','remove-me'] as $secret){
		$t->notContains($secret,$database);
	}
})->tag('panel','agents','pdo','lifecycle','cross-connection','privacy')->maxMillis(10000);

test('expired leases reclaim only their original request scope and signed intent set',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'reclaim',['table_prefix'=>'agent_reclaim','lease_seconds'=>30]);
	$store=$fixture['store'];
	$store->installSchema();
	$actor=dp_panel_pdo_agent_context();
	$material=dp_panel_pdo_agent_material('reclaim');
	$other=dp_panel_pdo_agent_material('reclaim-other');

	$t->same(null,$store->lookup($material['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request']));
	$lease=$store->reserve($material['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request'],[$material['nonce']],0);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->renew((string)$lease->id(),0,30),'revision_conflict');
	$t->throws(static fn()=>$store->renew((string)$lease->id(),$lease->revision(),29),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->renew((string)$lease->id(),$lease->revision(),3601),InvalidArgumentException::class);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->lookup(hash('sha256','wrong-plan'),$actor->scopeFingerprint(),'reclaim-key',$material['request']),'idempotency_conflict');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->lookup($material['plan'],$actor->scopeFingerprint(),'reclaim-key',hash('sha256','wrong-request')),'idempotency_conflict');

	($fixture['advance'])(30);
	$t->same(null,$store->lookup($material['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request']));
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->renew((string)$lease->id(),$lease->revision(),30),'reservation_expired');
	$rawResult=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],$lease->revision());
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->complete((string)$lease->id(),$rawResult,$actor,'execution_completed','executed',[],($fixture['clock'])(),$lease->revision()),'reservation_expired');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->reserve($material['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request'],[$other['nonce']],1),'intent_replayed');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->reserve($other['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request'],[$material['nonce']],1),'idempotency_conflict');

	$reclaimed=$store->reserve($material['plan'],$actor->scopeFingerprint(),'reclaim-key',$material['request'],[$material['nonce']],1);
	$t->same(2,$reclaimed->revision());
	$t->isTrue($reclaimed->id()!==$lease->id());
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->reserve($other['plan'],$actor->scopeFingerprint(),'other-key',$other['request'],[$material['nonce']],2),'intent_replayed');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->renew('missing-reservation',1,30),'reservation_invalid');

	$collisionPdo=new PDO('sqlite::memory:');
	$collisionPdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$collisionStore=new PanelPdoAgentWorkflowStore(
		$collisionPdo,
		['table_prefix'=>'agent_collision'],
		static fn():int=>1784016000,
		static fn():string=>'agent_reservation_collision',
	);
	$collisionStore->installSchema();
	$first=$collisionStore->reserve($material['plan'],$actor->scopeFingerprint(),'collision-one',$material['request'],[$material['nonce']],0);
	$t->same('agent_reservation_collision',$first->id());
	dp_panel_pdo_agent_domain_error($t,static fn()=>$collisionStore->reserve($other['plan'],$actor->scopeFingerprint(),'collision-two',$other['request'],[$other['nonce']],1),'reservation_id_collision');
})->tag('panel','agents','pdo','leases','reclaim','fail-closed')->maxMillis(8000);

test('completion rejects every stale mismatched oversized or cross-scope result before committing',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'completion',['table_prefix'=>'agent_completion','maximum_result_bytes'=>4096]);
	$store=$fixture['store'];
	$store->installSchema();
	$actor=dp_panel_pdo_agent_context();
	$otherActor=dp_panel_pdo_agent_context('operator:2');
	$material=dp_panel_pdo_agent_material('completion');
	$lease=$store->reserve($material['plan'],$actor->scopeFingerprint(),'completion-key',$material['request'],[$material['nonce']],0);
	$valid=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],$lease->revision());
	$wrongPlan=PanelAgentExecutionResult::make(true,'executed',hash('sha256','wrong-plan'),[],$lease->revision());
	$wrongRevision=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[],$lease->revision()+1);
	$wrongCode=PanelAgentExecutionResult::make(true,'other_code',$material['plan'],[],$lease->revision());
	$receipt=PanelAgentAuditReceipt::create(1,'execution_completed',$actor,$material['plan'],'executed',[],'',($fixture['clock'])());
	$alreadyReceipted=$valid->withReceipt($receipt,$lease->revision());

	$t->throws(static fn()=>$store->complete((string)$lease->id(),$valid,$actor,'execution_completed','executed',[],-1,$lease->revision()),InvalidArgumentException::class);
	foreach([
		'wrong plan'=>$wrongPlan,
		'wrong revision'=>$wrongRevision,
		'wrong code'=>$wrongCode,
		'already receipted'=>$alreadyReceipted,
	] as $label=>$invalid){
		dp_panel_pdo_agent_domain_error($t,static fn()=>$store->complete((string)$lease->id(),$invalid,$actor,'execution_completed','executed',[],($fixture['clock'])(),$lease->revision()),'reservation_result_invalid');
	}
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->complete((string)$lease->id(),$valid,$actor,'execution_failed','executed',[],($fixture['clock'])(),$lease->revision()),'reservation_result_invalid');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->complete((string)$lease->id(),$valid,$otherActor,'execution_completed','executed',[],($fixture['clock'])(),$lease->revision()),'reservation_scope_mismatch');

	$oversized=PanelAgentExecutionResult::make(true,'executed',$material['plan'],[['output'=>str_repeat('x',5000)]],$lease->revision());
	dp_panel_pdo_agent_storage_error($t,static fn()=>$store->complete((string)$lease->id(),$oversized,$actor,'execution_completed','executed',[],($fixture['clock'])(),$lease->revision()),'result_too_large');
	$completed=$store->complete((string)$lease->id(),$valid,$actor,'execution_completed','executed',['signedIntent'=>'remove-me'],($fixture['clock'])(),$lease->revision());
	$t->same('[REDACTED]',$completed->receipt()?->details()['signedIntent']);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->renew((string)$lease->id(),$lease->revision(),30),'reservation_invalid');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->complete((string)$lease->id(),$valid,$actor,'execution_completed','executed',[],($fixture['clock'])(),$lease->revision()),'reservation_invalid');
})->tag('panel','agents','pdo','completion','fencing','validation')->maxMillis(8000);

test('garbage collection is bounded explicit audit-preserving and change-visible',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'garbage',[ 'table_prefix'=>'agent_gc','lease_seconds'=>30,'retention_seconds'=>3600 ]);
	$store=$fixture['store'];
	$store->installSchema();
	$actor=dp_panel_pdo_agent_context();
	$completedMaterial=dp_panel_pdo_agent_material('gc-completed');
	$abandonedMaterial=dp_panel_pdo_agent_material('gc-abandoned');
	$cancelledPlan=hash('sha256','gc-cancelled-plan');
	$now=($fixture['clock'])();

	$completedLease=$store->reserve($completedMaterial['plan'],$actor->scopeFingerprint(),'gc-completed-key',$completedMaterial['request'],[$completedMaterial['nonce']],0);
	$completedResult=PanelAgentExecutionResult::make(true,'executed',$completedMaterial['plan'],[],$completedLease->revision());
	$store->complete((string)$completedLease->id(),$completedResult,$actor,'execution_completed','executed',[],$now,$completedLease->revision());
	$store->reserve($abandonedMaterial['plan'],$actor->scopeFingerprint(),'gc-abandoned-key',$abandonedMaterial['request'],[$abandonedMaterial['nonce']],2);
	$cancellation=dp_panel_pdo_agent_receipt($store,$actor,$cancelledPlan,'plan_cancelled','cancelled',$now);
	$t->same(4,$store->cancel($cancelledPlan,$cancellation,3));

	($fixture['advance'])(3630);
	$collected=$store->collectGarbage(10,true);
	$t->isTrue($collected['changed']);
	$t->same(5,$collected['revision']);
	$t->same(1,$collected['completed_reservations']);
	$t->same(1,$collected['abandoned_reservations']);
	$t->same(2,$collected['nonce_tombstones']);
	$t->same(1,$collected['cancellations']);
	$t->same(2,$collected['audit_receipts_retained']);
	$t->isFalse($store->cancelled($cancelledPlan));

	$unchanged=$store->collectGarbage(10,true);
	$t->isFalse($unchanged['changed']);
	$t->same(5,$unchanged['revision']);
	$t->throws(static fn()=>$store->collectGarbage(0),InvalidArgumentException::class);
	$t->throws(static fn()=>$store->collectGarbage(100001),InvalidArgumentException::class);
})->tag('panel','agents','pdo','garbage-collection','retention')->maxMillis(8000);

test('bounded change metadata reports retained gaps future cursors and payload-free resets',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'changes',['table_prefix'=>'agent_changes','change_retention'=>8,'max_entries'=>16]);
	$store=$fixture['store'];
	$store->installSchema();
	$actor=dp_panel_pdo_agent_context();
	$revision=0;
	for($index=1;$index<=5;$index++){
		$material=dp_panel_pdo_agent_material('change-'.$index);
		$lease=$store->reserve($material['plan'],$actor->scopeFingerprint(),'secret-change-key-'.$index,$material['request'],[$material['nonce']],$revision);
		$renewed=$store->renew((string)$lease->id(),$lease->revision(),30);
		$revision=$renewed->revision();
	}

	$t->same(10,$store->cursor());
	$fresh=$store->changesSince(-10,2);
	$t->same(2,count($fresh['changes']));
	$t->isFalse($fresh['reset_required']);
	$t->isTrue($fresh['oldest_cursor']>1);
	$stale=$store->changesSince(1,100);
	$t->isTrue($stale['reset_required']);
	$t->same('audit_and_active_workflows',$stale['snapshot']['resync']);
	$t->same([],$stale['changes']);
	$future=$store->changesSince(999,0);
	$t->isTrue($future['reset_required']);
	$t->same(10,$future['cursor']);
	$current=$store->changesSince(10,1001);
	$t->isFalse($current['reset_required']);
	$t->same([],$current['changes']);

	$raw=(string)$fixture['pdo']->query('SELECT GROUP_CONCAT(event_type || entity_type || entity_id) FROM agent_changes_changes')->fetchColumn();
	$t->notContains('secret-change-key',$raw);
	$t->same(['cursor','type','entity_type','entity_id','revision','occurred_at'],array_keys($fresh['changes'][0]));
})->tag('panel','agents','pdo','change-feed','retention','privacy')->maxMillis(8000);

test('missing drifted corrupt locked and caller-owned storage fails closed with stable metadata',static function(Context $t):void {
	$missing=dp_panel_pdo_agent_fixture($t,'missing',['table_prefix'=>'agent_missing']);
	$required=dp_panel_pdo_agent_storage_error($t,static fn()=>$missing['store']->revision(),'schema_required');
	$t->isFalse($required->retryable());

	$broken=dp_panel_pdo_agent_fixture($t,'broken',['table_prefix'=>'agent_broken']);
	$broken['pdo']->exec('CREATE TABLE agent_broken_meta (singleton INTEGER PRIMARY KEY)');
	dp_panel_pdo_agent_storage_error($t,static fn()=>$broken['store']->installSchema(),'migration_failed');

	$drift=dp_panel_pdo_agent_fixture($t,'drift',['table_prefix'=>'agent_drift']);
	$drift['store']->installSchema();
	$drift['pdo']->exec('UPDATE agent_drift_meta SET schema_version = 9');
	dp_panel_pdo_agent_storage_error($t,static fn()=>$drift['store']->revision(),'schema_incompatible');
	dp_panel_pdo_agent_storage_error($t,static fn()=>$drift['store']->installSchema(),'schema_incompatible');

	$nested=dp_panel_pdo_agent_fixture($t,'nested',['table_prefix'=>'agent_nested']);
	$nested['store']->installSchema();
	$nested['pdo']->beginTransaction();
	$conflict=dp_panel_pdo_agent_storage_error($t,static fn()=>$nested['store']->audit(),'transaction_conflict');
	$t->isTrue($conflict->retryable());
	dp_panel_pdo_agent_storage_error($t,static fn()=>$nested['store']->installSchema(),'transaction_conflict');
	$nested['pdo']->rollBack();

	$locked=dp_panel_pdo_agent_fixture($t,'locked',['table_prefix'=>'agent_locked','transaction_retries'=>1,'retry_delay_microseconds'=>1]);
	$locked['store']->installSchema();
	$locker=dp_panel_pdo_agent_connection($locked['path'],0);
	$blocked=new PanelPdoAgentWorkflowStore(
		dp_panel_pdo_agent_connection($locked['path'],0),
		['table_prefix'=>'agent_locked','transaction_retries'=>1,'retry_delay_microseconds'=>1],
	);
	$locker->exec('BEGIN IMMEDIATE');
	$material=dp_panel_pdo_agent_material('locked');
	$unavailable=dp_panel_pdo_agent_storage_error($t,static fn()=>$blocked->reserve($material['plan'],dp_panel_pdo_agent_context()->scopeFingerprint(),'locked-key',$material['request'],[$material['nonce']],0),'storage_unavailable');
	$t->isTrue($unavailable->retryable());
	$locker->exec('ROLLBACK');

	$corrupt=dp_panel_pdo_agent_fixture($t,'corrupt',['table_prefix'=>'agent_corrupt']);
	$corrupt['store']->installSchema();
	$corrupt['pdo']->exec("UPDATE agent_corrupt_meta SET audit_sequence = 1, audit_head = ''");
	dp_panel_pdo_agent_storage_error($t,static fn()=>$corrupt['store']->revision(),'storage_corrupt');
})->tag('panel','agents','pdo','fail-closed','corruption','locking')->maxMillis(10000);

test('durable row hydration rejects malformed receipts reservations results cancellations and changes',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'hydration',['table_prefix'=>'agent_hydration']);
	$store=$fixture['store'];
	$store->installSchema();
	$actor=dp_panel_pdo_agent_context();
	$material=dp_panel_pdo_agent_material('hydration');
	$now=($fixture['clock'])();
	$receipt=PanelAgentAuditReceipt::create(1,'execution_completed',$actor,$material['plan'],'executed',[],'',$now);
	$t->same(1,$store->append($receipt,0));

	$malformedJson='[]';
	$statement=$fixture['pdo']->prepare('UPDATE agent_hydration_audit SET receipt_json = :json, receipt_digest = :digest, receipt_bytes = :bytes');
	$statement->execute(['json'=>$malformedJson,'digest'=>hash('sha256',$malformedJson),'bytes'=>strlen($malformedJson)]);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$store->audit(),'storage_corrupt');

	$access=$t->nonPublic($store);
	$invalidReservation=[
		'id'=>'Bad Reservation Id',
		'plan_hash'=>$material['plan'],
		'scope_fingerprint'=>$actor->scopeFingerprint(),
		'idempotency_hash'=>hash('sha256','idempotency'),
		'request_hash'=>$material['request'],
		'reservation_status'=>'pending',
		'lease_revision'=>1,
		'lease_expires_at'=>$now+30,
		'created_at'=>$now,
		'updated_at'=>$now,
		'result_json'=>null,
		'result_digest'=>null,
		'result_bytes'=>null,
		'completed_at'=>null,
	];
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('hydrateReservation',$invalidReservation),'storage_corrupt');
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('decodeResult',['status'=>'completed','result_json'=>'{'],[],1),'storage_corrupt');

	$invalidReceiptPayload=[
		'type'=>'panel_agent_execution_result',
		'version'=>1,
		'ok'=>true,
		'code'=>'executed',
		'plan_hash'=>$material['plan'],
		'steps'=>[],
		'replayed'=>false,
		'store_revision'=>2,
		'receipt'=>[],
		'metadata'=>[],
	];
	$invalidReceiptReservation=[
		'status'=>'completed',
		'result_json'=>json_encode($invalidReceiptPayload,JSON_THROW_ON_ERROR),
		'plan_hash'=>$material['plan'],
		'scope'=>$actor->scopeFingerprint(),
		'lease_revision'=>1,
	];
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('decodeResult',$invalidReceiptReservation,[$receipt],2),'storage_corrupt');

	$oversizedPayload=$invalidReceiptPayload;
	$oversizedPayload['receipt']=$receipt->jsonSerialize();
	$oversizedPayload['steps']=[['output'=>str_repeat('x',1050000)]];
	$oversizedReservation=$invalidReceiptReservation;
	$oversizedReservation['result_json']=json_encode($oversizedPayload,JSON_THROW_ON_ERROR);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('decodeResult',$oversizedReservation,[$receipt],2),'storage_corrupt');
	$noncanonicalPayload=$invalidReceiptPayload;
	$noncanonicalPayload['receipt']=$receipt->jsonSerialize();
	$noncanonicalReservation=$invalidReceiptReservation;
	$noncanonicalReservation['result_json']=json_encode($noncanonicalPayload,JSON_THROW_ON_ERROR);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('decodeResult',$noncanonicalReservation,[$receipt],2),'storage_corrupt');

	$invalidCancellation=['plan_hash'=>'not-a-digest','receipt_hash'=>hash('sha256','receipt'),'occurred_at'=>$now,'audit_sequence'=>1];
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('hydrateCancellation',$invalidCancellation,[$receipt]),'storage_corrupt');
	$invalidChange=['event_type'=>'Bad Event','entity_type'=>'reservation','entity_id'=>'id','change_id'=>1,'global_revision'=>1,'occurred_at'=>$now];
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('hydrateChange',$invalidChange),'storage_corrupt');

	$nonceProbe=$t->scriptedPdo()->queueRows([['reservation_id'=>'Bad Reservation Id']]);
	$nonceStore=new PanelPdoAgentWorkflowStore($nonceProbe,['table_prefix'=>'agent_nonce_probe']);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$t->nonPublic($nonceStore)->invoke('nonceOwner',hash('sha256','nonce'),false),'storage_corrupt');
})->tag('panel','agents','pdo','hydration','corruption','exact-coverage')->maxMillis(10000);

test('PDO protocol failures map duplicate transient and unavailable storage without leaking driver errors',static function(Context $t):void {
	$actor=dp_panel_pdo_agent_context();
	$material=dp_panel_pdo_agent_material('protocol');
	$receipt=PanelAgentAuditReceipt::create(1,'plan_validated',$actor,$material['plan'],'planned',[],'',1784016000);
	$duplicate=new PDOException('UNIQUE constraint failed',23000);
	$unavailable=new PDOException('connection unavailable');

	$migrationStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo(),['table_prefix'=>'agent_migration_probe']);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$migrationStore->installSchema(),'migration_failed');

	$duplicateAuditStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->queuePrepareFailure($duplicate),['table_prefix'=>'agent_duplicate_audit']);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$t->nonPublic($duplicateAuditStore)->invoke('insertAudit',$receipt),'storage_corrupt');
	$failedAuditStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->queuePrepareFailure($unavailable),['table_prefix'=>'agent_failed_audit']);
	$t->throws(static fn()=>$t->nonPublic($failedAuditStore)->invoke('insertAudit',$receipt),PDOException::class);

	$reservationArguments=[
		'agent_reservation_protocol',
		$material['plan'],
		$actor->scopeFingerprint(),
		hash('sha256','idempotency'),
		$material['request'],
		[hash('sha256',"panel-agent-nonce-v1\0".$material['nonce'])],
		1,
		1784016030,
		1784016000,
	];
	$duplicateReservationStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->queuePrepareFailure($duplicate),['table_prefix'=>'agent_duplicate_reservation']);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$t->nonPublic($duplicateReservationStore)->invoke('insertReservation',...$reservationArguments),'reservation_id_collision');
	$failedReservationStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->queuePrepareFailure($unavailable),['table_prefix'=>'agent_failed_reservation']);
	$t->throws(static fn()=>$t->nonPublic($failedReservationStore)->invoke('insertReservation',...$reservationArguments),PDOException::class);

	$duplicateNoncePdo=$t->scriptedPdo()->queueStatement(new ScriptedPdoStatement())->queuePrepareFailure($duplicate);
	$duplicateNonceStore=new PanelPdoAgentWorkflowStore($duplicateNoncePdo,['table_prefix'=>'agent_duplicate_nonce']);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$t->nonPublic($duplicateNonceStore)->invoke('insertReservation',...$reservationArguments),'intent_replayed');
	$failedNoncePdo=$t->scriptedPdo()->queueStatement(new ScriptedPdoStatement())->queuePrepareFailure($unavailable);
	$failedNonceStore=new PanelPdoAgentWorkflowStore($failedNoncePdo,['table_prefix'=>'agent_failed_nonce']);
	$t->throws(static fn()=>$t->nonPublic($failedNonceStore)->invoke('insertReservation',...$reservationArguments),PDOException::class);

	$pgsql=$t->scriptedPdo('pgsql')->queueScalar('1');
	$pgsqlStore=new PanelPdoAgentWorkflowStore($pgsql,['table_prefix'=>'agent_pgsql_change'],static fn():int=>1784016000);
	$t->nonPublic($pgsqlStore)->invoke('recordChange','reservation.acquired','reservation','agent_reservation_protocol',1);
	$t->contains('RETURNING change_id',$pgsql->preparedSql()[0]);

	$schemaFailureStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->queuePrepareFailure($unavailable),['table_prefix'=>'agent_schema_failure']);
	$t->throws(static fn()=>$t->nonPublic($schemaFailureStore)->invoke('assertSchema',false),PDOException::class);
	$beginFailureStore=new PanelPdoAgentWorkflowStore($t->scriptedPdo()->returnBeginResult(false),['table_prefix'=>'agent_begin_failure']);
	$t->throws(static fn()=>$t->nonPublic($beginFailureStore)->invoke('beginTransaction',false),RuntimeException::class);
	$rollbackPdo=$t->scriptedPdo()->markTransactionActive()->failRollbackWith(new RuntimeException('rollback unavailable'));
	$rollbackStore=new PanelPdoAgentWorkflowStore($rollbackPdo,['table_prefix'=>'agent_rollback_failure']);
	$t->nonPublic($rollbackStore)->invoke('rollbackTransaction');
	$t->isTrue($rollbackPdo->inTransaction());
})->tag('panel','agents','pdo','protocol','fail-closed','exact-coverage')->maxMillis(8000);

test('invariant probes validate nested redaction nonce sets audit chains and unencodable results',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'invariants',['table_prefix'=>'agent_invariants']);
	$store=$fixture['store'];
	$store->installSchema();
	$access=$t->nonPublic($store);
	$actor=dp_panel_pdo_agent_context();
	$material=dp_panel_pdo_agent_material('invariants');

	$t->same(['nested'=>['nonce'=>'[REDACTED]']],$access->invoke('durableDetails',['nested'=>['nonce'=>'raw']]));
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->reserve($material['plan'],$actor->scopeFingerprint(),'empty-nonces',$material['request'],[],0),'nonce_invalid');
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->reserve($material['plan'],$actor->scopeFingerprint(),'duplicate-nonces',$material['request'],[$material['nonce'],$material['nonce']],0),'nonce_invalid');

	$wrongChain=PanelAgentAuditReceipt::create(1,'plan_validated',$actor,$material['plan'],'planned',[],'',1784016000);
	$store->append($wrongChain,0);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$store->append($wrongChain,1),'audit_chain_invalid');

	$unsafeReceipt=$t->nonPublic(PanelAgentAuditReceipt::class)->withoutConstructor();
	$t->nonPublic($unsafeReceipt)->writeProperty('details',['nonce'=>'raw-secret']);
	dp_panel_pdo_agent_domain_error($t,static fn()=>$access->invoke('assertDurableReceipt',$unsafeReceipt),'audit_details_unsafe');

	$invalidResult=$t->nonPublic(PanelAgentExecutionResult::class)->withoutConstructor();
	$invalidResultAccess=$t->nonPublic($invalidResult);
	$invalidResultAccess
		->writeProperty('ok',true)
		->writeProperty('code','executed')
		->writeProperty('planHash',$material['plan'])
		->writeProperty('steps',[])
		->writeProperty('replayed',false)
		->writeProperty('storeRevision',1)
		->writeProperty('receipt',null);
	$stream=fopen('php://memory','r');
	$invalidResultAccess->writeProperty('metadata',['stream'=>$stream]);
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('encodeResult',$invalidResult),'result_invalid');
	fclose($stream);
})->tag('panel','agents','pdo','invariants','redaction','exact-coverage')->maxMillis(8000);

test('configuration clocks factories SQL protocol and driver dialects expose explicit test seams',static function(Context $t):void {
	$typed=new PanelAgentWorkflowStorageException('typed_failure','Typed storage failure.',true);
	$t->same('typed_failure',$typed->errorCode());
	$t->isTrue($typed->retryable());
	$t->throws(static fn()=>new PanelAgentWorkflowStorageException('Bad Code','Invalid code.'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoAgentWorkflowStore::schemaStatementsFor('oracle'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoAgentWorkflowStore::schemaStatementsFor('sqlite','bad-prefix'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPdoAgentWorkflowStore::dialectPlanFor('sqlsrv'),InvalidArgumentException::class);

	$pdo=new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	foreach([
		['unknown'=>1],
		['table_prefix'=>'1bad'],
		['lease_seconds'=>29],
		['max_entries'=>0],
		['retention_seconds'=>3599],
		['maximum_result_bytes'=>4095],
		['maximum_audit_bytes'=>1023],
		['change_retention'=>7],
		['transaction_retries'=>11],
		['retry_delay_microseconds'=>100001],
	] as $options){
		$t->throws(static fn()=>new PanelPdoAgentWorkflowStore($pdo,$options),InvalidArgumentException::class);
	}
	$silent=new PDO('sqlite::memory:');
	$silent->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
	$t->throws(static fn()=>new PanelPdoAgentWorkflowStore($silent),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoAgentWorkflowStore($t->scriptedPdo('sqlite')->failDriverWith(new RuntimeException('inspect failed'))),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoAgentWorkflowStore($t->scriptedPdo('sqlsrv')),InvalidArgumentException::class);

	$material=dp_panel_pdo_agent_material('validation');
	$actor=dp_panel_pdo_agent_context();
	$badClock=new PanelPdoAgentWorkflowStore($pdo,['table_prefix'=>'agent_bad_clock'],static fn():string=>'not-an-integer');
	$badClock->installSchema();
	$t->throws(static fn()=>$badClock->reserve($material['plan'],$actor->scopeFingerprint(),'bad-clock',$material['request'],[$material['nonce']],0),UnexpectedValueException::class);
	foreach([static fn():array=>[],static fn():string=>'Bad Reservation Id'] as $index=>$factory){
		$fixture=dp_panel_pdo_agent_fixture($t,'bad-factory-'.$index,['table_prefix'=>'agent_bad_factory_'.$index]);
		$store=new PanelPdoAgentWorkflowStore($fixture['pdo'],['table_prefix'=>'agent_bad_factory_'.$index],$fixture['clock'],$factory);
		$store->installSchema();
		$t->throws(static fn()=>$store->reserve($material['plan'],$actor->scopeFingerprint(),'bad-factory-'.$index,$material['request'],[$material['nonce']],0),UnexpectedValueException::class);
	}

	$probe=new PanelPdoAgentWorkflowStore($pdo,['table_prefix'=>'agent_probe']);
	$access=$t->nonPublic($probe);
	$t->same(PHP_INT_MAX,$access->invoke('plusSeconds',PHP_INT_MAX,30));
	$t->same(12,$access->invoke('integer','12',0));
	dp_panel_pdo_agent_storage_error($t,static fn()=>$access->invoke('integer','01',0),'storage_corrupt');
	$t->throws(static fn()=>$access->invoke('tableCount','unsupported'),LogicException::class);

	$duplicate=new PDOException('UNIQUE constraint failed',23000);
	$t->isTrue($access->invoke('duplicate',$duplicate));
	$t->isTrue($access->invoke('transient',new PDOException('database is locked')));
	$t->isTrue($access->invoke('missingRelation',new PDOException('no such table: workflows')));

	$mysql=$t->scriptedPdo('mysql');
	$mysqlStore=new PanelPdoAgentWorkflowStore($mysql,['table_prefix'=>'agent_mysql']);
	$t->nonPublic($mysqlStore)->invoke('beginTransaction',false);
	$t->same(['exec','begin'],$mysql->operationNames());
	$t->nonPublic($mysqlStore)->invoke('commitTransaction');

	$pgsql=$t->scriptedPdo('pgsql');
	$pgsqlStore=new PanelPdoAgentWorkflowStore($pgsql,['table_prefix'=>'agent_pgsql']);
	$t->nonPublic($pgsqlStore)->invoke('beginTransaction',false);
	$t->same(['begin','exec'],$pgsql->operationNames());
	$t->nonPublic($pgsqlStore)->invoke('rollbackTransaction');

	$prepareMiss=$t->scriptedPdo()->queuePrepareMiss();
	$prepareMissStore=new PanelPdoAgentWorkflowStore($prepareMiss,['table_prefix'=>'agent_prepare']);
	$t->throws(static fn()=>$t->nonPublic($prepareMissStore)->invoke('execute','SELECT 1'),RuntimeException::class);
	$executeMiss=$t->scriptedPdo()->queueStatement((new ScriptedPdoStatement())->returnExecuteResult(false));
	$executeMissStore=new PanelPdoAgentWorkflowStore($executeMiss,['table_prefix'=>'agent_execute']);
	$t->throws(static fn()=>$t->nonPublic($executeMissStore)->invoke('execute','SELECT 1'),RuntimeException::class);

	$bindings=$t->scriptedPdo();
	$bindingStore=new PanelPdoAgentWorkflowStore($bindings,['table_prefix'=>'agent_bindings']);
	$t->nonPublic($bindingStore)->invoke('execute','SELECT :null_value, :bool_value, :int_value, :float_value, :string_value',[
		'null_value'=>null,
		'bool_value'=>true,
		'int_value'=>7,
		'float_value'=>1.5,
		'string_value'=>'value',
	]);
	$types=$bindings->lastStatement()?->bindingTypes()??[];
	$t->same(PDO::PARAM_NULL,$types[':null_value']);
	$t->same(PDO::PARAM_BOOL,$types[':bool_value']);
	$t->same(PDO::PARAM_INT,$types[':int_value']);
	$t->same(PDO::PARAM_STR,$types[':float_value']);
	$t->same(PDO::PARAM_STR,$types[':string_value']);
})->tag('panel','agents','pdo','validation','protocol','exact-coverage')->maxMillis(10000);

test('pdo agent workflow adapter passes the reusable fenced idempotent store conformance contract',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'conformance',['table_prefix'=>'agent_conformance','change_retention'=>256]);
	$fixture['store']->installSchema();
	$report=(new PanelAdapterConformanceRunner())->run(
		PanelAdapterConformanceCatalog::agentWorkflowStore(),
		$fixture['store'],
		['allow_destructive'=>true],
	);
	$t->isTrue($report->passed());
	$t->same(1,$report->summary()['passed']);
	$t->same(0,$report->summary()['failed']);
	$t->same(5,$fixture['store']->revision());
	$t->same(3,count($fixture['store']->audit()));
})->tag('panel','agents','pdo','conformance','contract')->maxMillis(15000);

test('independent php workers serialize one global optimistic reservation winner in shared sqlite',static function(Context $t):void {
	$fixture=dp_panel_pdo_agent_fixture($t,'process-race',['table_prefix'=>'agent_process','transaction_retries'=>10,'retry_delay_microseconds'=>1000]);
	$fixture['store']->installSchema();
	$panelRoot=dirname(__DIR__);
	$actor=dp_panel_pdo_agent_context();
	$code=<<<'PHP'
require $argv[1].'/Framework/Support/PanelSensitiveDataSanitizer.php';
foreach(['PanelAgentGuard.php','PanelAgentException.php','PanelAgentWorkflowStore.php','PanelAgentAuditReceipt.php','PanelAgentExecutionResult.php','PanelAgentStoreReservation.php','PanelAgentWorkflowStorageException.php','PanelPdoAgentWorkflowStore.php'] as $source){require $argv[1].'/Framework/Agents/'.$source;}
$pdo=new PDO('sqlite:'.$argv[2]);
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');
$store=new \Dataphyre\Panel\PanelPdoAgentWorkflowStore($pdo,['table_prefix'=>'agent_process','transaction_retries'=>10,'retry_delay_microseconds'=>1000],static fn():int=>40000,static fn():string=>$argv[3]);
try{$reservation=$store->reserve($argv[4],$argv[5],$argv[6],$argv[7],[$argv[8]],0);echo 'acquired:'.$reservation->revision();}
catch(\Dataphyre\Panel\PanelAgentException $error){echo 'error:'.$error->errorCode();}
PHP;
	$workers=[];
	foreach(['one','two'] as $worker){
		$material=dp_panel_pdo_agent_material('process-'.$worker);
		$workers[]=$t->startPhpProcess([
			'-r',$code,$panelRoot,$fixture['path'],'agent_reservation_process_'.$worker,$material['plan'],$actor->scopeFingerprint(),'process-key-'.$worker,$material['request'],$material['nonce'],
		],timeout_millis:15000);
	}
	$outputs=[];
	foreach($workers as $process){
		$result=$process->wait();
		if(!$result->succeeded()){ throw new RuntimeException('PDO agent race worker failed: '.$result->stderr().' '.$result->stdout()); }
		$t->same('',trim($result->stderr()));
		$outputs[]=trim($result->stdout());
	}
	sort($outputs,SORT_STRING);
	$t->same(['acquired:1','error:revision_conflict'],$outputs);
	$t->same(1,$fixture['store']->revision());
	$t->same(1,(int)$fixture['pdo']->query('SELECT COUNT(*) FROM agent_process_reservations')->fetchColumn());
})->tag('panel','agents','pdo','cross-process','race','fencing')->maxMillis(20000);
