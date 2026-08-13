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
use Dataphyre\Panel\PanelCommandEnvelope;
use Dataphyre\Panel\PanelCommandFabric;
use Dataphyre\Panel\PanelCommandFabricLeaseLost;
use Dataphyre\Panel\PanelCommandFabricStorageException;
use Dataphyre\Panel\PanelCommandFabricSubscriberLease;
use Dataphyre\Panel\PanelCommandOutcome;
use Dataphyre\Panel\PanelCommandRegistry;
use Dataphyre\Panel\PanelEncryptedCommandPayloadCodec;
use Dataphyre\Panel\PanelEventDraft;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Panel\PanelPdoCommandFabricStore;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_pdo_fabric_connection(string $path,int $busyMilliseconds=5000):PDO {
	$pdo=new PDO('sqlite:'.$path);
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	$pdo->exec('PRAGMA busy_timeout = '.max(0,$busyMilliseconds));
	return$pdo;
}

/** @param array<string,mixed> $options @return array{path:string,pdo:PDO,store:PanelPdoCommandFabricStore,clock:Closure,advance:Closure} */
function dp_panel_pdo_fabric_fixture(Context $t,string $name,array $options=[]):array {
	$path=$t->tempDirectory('panel-pdo-fabric-'.$name).DIRECTORY_SEPARATOR.'fabric.sqlite';
	$pdo=dp_panel_pdo_fabric_connection($path);$now='2026-07-16T12:00:00Z';$token=0;
	$clock=static function()use(&$now):string{return$now;};
	$advance=static function(int $seconds)use(&$now):void{$now=(new DateTimeImmutable($now))->modify('+'.$seconds.' seconds')->format('Y-m-d\TH:i:s\Z');};
	$tokens=static function()use(&$token):string{$token++;return str_pad('pdo-fabric-token-'.$token,64,'x');};
	$store=new PanelPdoCommandFabricStore($pdo,$options,$clock,$tokens);
	return compact('path','pdo','store','clock','advance');
}

/** @return array{fabric:PanelCommandFabric,registry:PanelCommandRegistry,policy:PanelPolicyControlPlane,key:string} */
function dp_panel_pdo_fabric_runtime(PanelPdoCommandFabricStore $store,string $worker='worker-a',?callable $clock=null):array {
	$policyKey=str_repeat('P',48);$policy=new PanelPolicyControlPlane(['policy'=>$policyKey],true);
	$policy->register(PanelPolicyBundle::from(['id'=>'pdo_fabric_allow','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['orders.*'],'priority'=>100,'reason'=>'PDO fabric test.']]])->sign('policy',$policyKey));
	$registry=new PanelCommandRegistry();$key=str_repeat('F',48);$nonce=0;
	$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48),static function()use(&$nonce):string{$nonce++;return substr(hash('sha256','pdo-fabric-nonce-'.$nonce,true),0,12);});
	$fabric=new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>$key],'fabric',clock:$clock,subscriberWorker:$worker,subscriberLeaseTtlSeconds:5);
	return compact('fabric','registry','policy','key');
}

function dp_panel_pdo_fabric_error(Context $t,callable $callback,string $code):PanelCommandFabricStorageException {
	try{$callback();}catch(PanelCommandFabricStorageException $error){$t->same($code,$error->errorCode());return$error;}
	throw new RuntimeException("Expected PanelCommandFabricStorageException {$code}.");
}

suite('Panel durable shared-SQL command fabric store')
	->contract('panel.fabric.pdo-store',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','atomic-journal','outbox','subscriber-leases','fencing','restart','cross-process')
	->isolation('case')
	->tag('panel','fabric','pdo','distributed','security')
	->group('framework-coverage');

test('schema plans are explicit portable idempotent and connection redacted',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'schema',['table_prefix'=>'cf_schema','maximum_state_bytes'=>131072,'maximum_change_bytes'=>4096,'change_retention'=>32,'transaction_retries'=>2,'retry_delay_microseconds'=>0]);$store=$fixture['store'];
	$missing=dp_panel_pdo_fabric_error($t,static fn()=>$store->payload(),'schema_required');$t->isFalse($missing->retryable());
	$first=$store->installSchema();$second=$store->installSchema();$t->same($first,$second);$t->same('sqlite',$store->driver());$t->same(7,$first['statements']);$t->isTrue($first['idempotent']);$t->isFalse($first['destructive']);
	$t->same('2026-07-16T12:00:00.000000Z',$store->currentTime());
	$t->same($store->schemaStatements(),PanelPdoCommandFabricStore::schemaStatementsFor('sqlite','cf_schema'));
	$sqlite=PanelPdoCommandFabricStore::schemaStatementsFor('sqlite','cf_plan');$mysql=PanelPdoCommandFabricStore::schemaStatementsFor('mysql','cf_plan');$pgsql=PanelPdoCommandFabricStore::schemaStatementsFor('pgsql','cf_plan');
	$t->same(7,count($sqlite));$t->same(6,count($mysql));$t->same(7,count($pgsql));$t->contains('AUTOINCREMENT',$sqlite[2]);$t->contains('ENGINE=InnoDB',$mysql[0]);$t->contains('GENERATED BY DEFAULT AS IDENTITY',$pgsql[2]);$t->same('BEGIN IMMEDIATE',PanelPdoCommandFabricStore::dialectPlanFor('sqlite')['write_begin']);$t->contains('REPEATABLE READ',PanelPdoCommandFabricStore::dialectPlanFor('pgsql')['read_after'][0]);
	$manifest=$store->manifest();$encoded=json_encode($store,JSON_THROW_ON_ERROR);$t->same('locked_single_row',$manifest['state_write_serialization']);$t->isTrue($manifest['distributed']);$t->isTrue($manifest['fenced_subscriber_leases']);$t->isTrue($manifest['capabilities']['atomic_fenced_cursor']);$t->isFalse($manifest['exactly_once']);
	foreach([$fixture['path'],'cf_schema','sqlite:','password','token_hash','state_json','SELECT ']as$secret){$t->notContains($secret,$encoded);}
})->tag('panel','fabric','pdo','schema','manifest')->maxMillis(5000);

test('store passes atomic and fenced conformance then survives restart without command re-execution',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'conformance',['table_prefix'=>'cf_conformance','change_retention'=>64]);$store=$fixture['store'];$store->installSchema();$runner=new PanelAdapterConformanceRunner();
	$state=$runner->run(PanelAdapterConformanceCatalog::commandFabricStore(),$store,['allow_destructive'=>true]);$leases=$runner->run(PanelAdapterConformanceCatalog::leasedCommandFabricStore(),$store,['allow_destructive'=>true]);
	$t->isTrue($state->passed());$t->isTrue($leases->passed());$t->same(1,$state->summary()['passed']);$t->same(1,$leases->summary()['passed']);$t->same([],$store->activeSubscriberLeaseManifests());
	$runs=0;$first=dp_panel_pdo_fabric_runtime($store,'restart-a',$fixture['clock']);$first['registry']->register('orders.*',static function()use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(['created'=>true],[new PanelEventDraft('orders.created','order','order-1',['status'=>'created'])]);});
	$command=new PanelCommandEnvelope('orders.create','orders.create','tenant-a','operator','raw-restart-idempotency',['email'=>'hidden@example.test'],createdAt:'2026-07-16T12:00:00Z');$receipt=$first['fabric']->dispatch($command);$t->isTrue($receipt->ok());$t->same(1,$runs);
	$secondStore=new PanelPdoCommandFabricStore(dp_panel_pdo_fabric_connection($fixture['path']),['table_prefix'=>'cf_conformance','change_retention'=>64],$fixture['clock'],static fn():string=>str_repeat('z',64));$second=dp_panel_pdo_fabric_runtime($secondStore,'restart-b',$fixture['clock']);$second['registry']->register('orders.*',static function()use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(false);});
	$replay=$second['fabric']->dispatch($command);$t->isTrue($replay->replay());$t->same($receipt->digest(),$replay->digest());$t->same(1,$runs);$t->same(1,$second['fabric']->verifyIntegrity()['events']);
	$raw=(string)$fixture['pdo']->query('SELECT state_json FROM cf_conformance_state WHERE singleton = 1')->fetchColumn();$changes=(string)$fixture['pdo']->query("SELECT GROUP_CONCAT(event_json, '') FROM cf_conformance_changes")->fetchColumn();
	foreach(['raw-restart-idempotency','hidden@example.test','must-not-survive']as$secret){$t->notContains($secret,$raw.$changes);}
})->tag('panel','fabric','pdo','conformance','restart','idempotency','privacy')->maxMillis(10000);

test('Operations OS and Platform preserve the caller-owned distributed store and safe worker policy',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'platform',['table_prefix'=>'cf_platform']);$fixture['store']->installSchema();$root=$t->tempDirectory('panel-pdo-fabric-platform-root');
	$platform=PanelPlatform::defaults([
		'state_root'=>$root,'authentication'=>false,'media'=>false,
		'operations_os'=>[
			'master_key'=>str_repeat('M',48),'fabric_store'=>$fixture['store'],'fabric_subscriber_worker'=>'platform-worker','fabric_subscriber_lease_ttl_seconds'=>5,
			'policy_bundles'=>[['id'=>'platform_fabric','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['*'],'priority'=>100,'reason'=>'Platform fabric test.']]]],
		],
	]);
	$t->same($fixture['store'],$platform->commandFabric()->store());$t->isTrue($platform->manifest()->ready('operations_os'));$manifest=$platform->manifest()->domain('operations_os');
	foreach(['leased_command_fabric_store','pdo_command_fabric_store','command_fabric_subscriber_lease','command_fabric_conformance']as$feature){$t->isTrue($manifest['features'][$feature]??false);}
	$fabricManifest=$platform->commandFabric()->jsonSerialize();$t->isTrue($fabricManifest['guarantees']['fenced_subscriber_ownership']);$t->same(5,$fabricManifest['subscriber_lease_ttl_seconds']);$t->notContains($fixture['path'],json_encode($platform,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>PanelOperationsOs::fromConfig($t->tempDirectory('panel-pdo-fabric-invalid-worker'),['master_key'=>str_repeat('M',48),'fabric_store'=>$fixture['store'],'fabric_subscriber_worker'=>[]]),InvalidArgumentException::class);
})->tag('panel','fabric','pdo','operations-os','platform','composition')->maxMillis(10000);

test('subscriber leases retain bearer secrecy recover expiry and reject stale fences atomically',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'leases',['table_prefix'=>'cf_leases']);$store=$fixture['store'];$store->installSchema();
	$first=$store->acquireSubscriberLease('projection','worker-a',5);$t->isTrue($first instanceof PanelCommandFabricSubscriberLease);$t->same(1,$first?->fence());$t->same(null,$store->acquireSubscriberLease('projection','worker-b',5));
	$renewed=$store->renewSubscriberLease($first,5);$store->advanceSubscriberCursor($renewed,0);$active=$store->activeSubscriberLeaseManifests();$encoded=json_encode($active,JSON_THROW_ON_ERROR);$t->same(1,count($active));$t->notContains($renewed->token(),$encoded);$t->notContains('token_hash',$encoded);
	$forged=PanelCommandFabricSubscriberLease::make('projection','worker-a',str_repeat('f',64),1,$renewed->acquiredAt(),$renewed->expiresAt(),$renewed->renewedAt());$t->throws(static fn()=>$store->inspectSubscriberLease($forged),PanelCommandFabricLeaseLost::class);
	$fixture['advance'](6);$t->throws(static fn()=>$store->inspectSubscriberLease($renewed),PanelCommandFabricLeaseLost::class);$second=$store->acquireSubscriberLease('projection','worker-b',5);$t->same(2,$second?->fence());$t->throws(static fn()=>$store->advanceSubscriberCursor($renewed,0),PanelCommandFabricLeaseLost::class);$t->throws(static fn()=>$store->releaseSubscriberLease($renewed),PanelCommandFabricLeaseLost::class);$store->advanceSubscriberCursor($second,0);$store->releaseSubscriberLease($second);$t->same([],$store->activeSubscriberLeaseManifests());
	$raw=(string)$fixture['pdo']->query('SELECT COALESCE(worker,\'\') || COALESCE(token_hash,\'\') FROM cf_leases_subscriber_leases')->fetchColumn();$t->notContains($first->token(),$raw);$t->notContains($second->token(),$raw);
	$fixture['pdo']->exec('UPDATE cf_leases_subscriber_leases SET fence = '.PHP_INT_MAX);dp_panel_pdo_fabric_error($t,static fn()=>$store->acquireSubscriberLease('projection','worker-c',5),'fence_exhausted');
})->tag('panel','fabric','pdo','leases','fencing','expiry','security')->maxMillis(7000);

test('lease-aware fabric reports busy ownership and replays projection after post-effect lease loss',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'worker',['table_prefix'=>'cf_worker','change_retention'=>128]);$store=$fixture['store'];$store->installSchema();$runtime=dp_panel_pdo_fabric_runtime($store,'fabric-worker-a',$fixture['clock']);
	$runtime['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true,[new PanelEventDraft('orders.changed','order','order-1')]));$runtime['fabric']->dispatch(new PanelCommandEnvelope('orders.change','orders.change','tenant-a','operator','worker-command'));
	$deliveries=0;$runtime['fabric']->subscribe('projection','orders.*',static function()use(&$deliveries):bool{$deliveries++;return true;});
	$other=new PanelPdoCommandFabricStore(dp_panel_pdo_fabric_connection($fixture['path']),['table_prefix'=>'cf_worker','change_retention'=>128],$fixture['clock'],static fn():string=>str_repeat('o',64));$blocking=$other->acquireSubscriberLease('projection','external-worker',5);$busy=$runtime['fabric']->drainSubscriber('projection');$t->isTrue($busy['ok']);$t->isTrue($busy['busy']);$t->isFalse($busy['lease']['acquired']);$t->same(0,$deliveries);$other->releaseSubscriberLease($blocking);
	$drained=$runtime['fabric']->drainSubscriber('projection');$t->isTrue($drained['ok']);$t->isFalse($drained['busy']);$t->isTrue($drained['lease']['acquired']);$t->same(1,$drained['cursor']);$t->same(1,$deliveries);$t->same([],$store->activeSubscriberLeaseManifests());
	$takeover=null;$steal=true;$replays=0;$runtime['fabric']->subscribe('volatile','orders.*',static function()use(&$replays,&$steal,&$takeover,$fixture,$other):bool{$replays++;if($steal){$steal=false;$fixture['advance'](6);$takeover=$other->acquireSubscriberLease('volatile','takeover-worker',5);}return true;});
	$lost=$runtime['fabric']->drainSubscriber('volatile');$t->isFalse($lost['ok']);$t->same('lease_lost',$lost['error_code']);$t->same(0,$lost['cursor']);$t->same(1,$replays);$t->isTrue($takeover instanceof PanelCommandFabricSubscriberLease);$other->releaseSubscriberLease($takeover);
	$retried=$runtime['fabric']->drainSubscriber('volatile');$t->isTrue($retried['ok']);$t->same(1,$retried['cursor']);$t->same(2,$replays);$t->same(0,$runtime['fabric']->drainSubscriber('volatile')['processed']);
	$manifest=$runtime['fabric']->jsonSerialize();$t->isTrue($manifest['guarantees']['fenced_subscriber_ownership']);$t->isTrue($manifest['guarantees']['atomic_fenced_subscriber_cursor']);$t->isFalse($manifest['subscriber_worker_exposed']);
})->tag('panel','fabric','pdo','subscriber','worker','at-least-once','lease-loss')->maxMillis(10000);

test('bounded change feeds force retained and future resets without persisting secret metadata',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'changes',['table_prefix'=>'cf_changes','change_retention'=>8]);$store=$fixture['store'];$store->installSchema();
	for($i=1;$i<=12;$i++){$store->transaction(static function(array &$state):void{$state['revision']++;},'probe_changed',['ordinal'=>$i,'password'=>'payload-secret-'.$i]);}
	$t->same(12,$store->cursor());$stale=$store->changesSince(0,100);$t->isTrue($stale['reset_required']);$t->same([],$stale['changes']);$t->same(12,$stale['snapshot']['sequence']);$t->same($store->payload(),$stale['snapshot']['payload']);
	$oldest=$stale['oldest_cursor'];$retained=$store->changesSince($oldest-1,2);$t->isFalse($retained['reset_required']);$t->same(2,count($retained['changes']));$future=$store->changesSince(99,10);$t->isTrue($future['reset_required']);$t->same(12,$future['cursor']);$current=$store->changesSince(12,10);$t->isFalse($current['reset_required']);$t->same([],$current['changes']);
	$raw=(string)$fixture['pdo']->query("SELECT GROUP_CONCAT(event_json, '') FROM cf_changes_changes")->fetchColumn();$t->notContains('payload-secret',$raw);$t->contains('[REDACTED]',$raw);
})->tag('panel','fabric','pdo','change-feed','retention','privacy')->maxMillis(6000);

test('independent PHP workers serialize shared aggregate commits without lost updates',static function(Context $t):void {
	$fixture=dp_panel_pdo_fabric_fixture($t,'process',['table_prefix'=>'cf_process','change_retention'=>128,'transaction_retries'=>10,'retry_delay_microseconds'=>1000]);$fixture['store']->installSchema();$panelRoot=dirname(__DIR__);
	$code=<<<'PHP'
require $argv[1].'/unit_tests/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
$pdo=new PDO('sqlite:'.$argv[2]);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout = 5000');
$store=new \Dataphyre\Panel\PanelPdoCommandFabricStore($pdo,['table_prefix'=>'cf_process','change_retention'=>128,'transaction_retries'=>10,'retry_delay_microseconds'=>1000]);
for($i=0;$i<20;$i++){$store->transaction(static function(array &$state):void{$state['revision']++;},'worker_committed',['worker_hash'=>hash('sha256',$argv[3])]);}
echo '20';
PHP;
	$workers=[];foreach(['a','b','c']as$worker){$workers[]=$t->startPhpProcess(['-r',$code,$panelRoot,$fixture['path'],$worker],timeout_millis:20000);}
	$total=0;foreach($workers as$process){$result=$process->wait();if(!$result->succeeded()){throw new RuntimeException('Command-fabric worker failed: '.$result->stderr().' '.$result->stdout());}$t->same('',trim($result->stderr()));$total+=(int)trim($result->stdout());}
	$t->same(60,$total);$t->same(60,$fixture['store']->payload()['revision']);$t->same(60,$fixture['store']->cursor());$t->same(60,count($fixture['store']->changesSince(0,100)['changes']));
})->tag('panel','fabric','pdo','cross-process','transactions','serialization')->maxMillis(30000);

test('store fails closed on schema drift corruption caller transactions locks and invalid configuration',static function(Context $t):void {
	$readOnly=new PDO('sqlite::memory:');$readOnly->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$readOnly->exec('PRAGMA query_only = ON');$readOnlyStore=new PanelPdoCommandFabricStore($readOnly,['table_prefix'=>'cf_read_only']);dp_panel_pdo_fabric_error($t,static fn()=>$readOnlyStore->installSchema(),'migration_failed');
	$drift=dp_panel_pdo_fabric_fixture($t,'drift',['table_prefix'=>'cf_drift']);$drift['store']->installSchema();$drift['pdo']->exec('UPDATE cf_drift_meta SET schema_version = 9');dp_panel_pdo_fabric_error($t,static fn()=>$drift['store']->payload(),'schema_incompatible');dp_panel_pdo_fabric_error($t,static fn()=>$drift['store']->installSchema(),'schema_incompatible');
	$nested=dp_panel_pdo_fabric_fixture($t,'nested',['table_prefix'=>'cf_nested']);$nested['store']->installSchema();$nested['pdo']->beginTransaction();$conflict=dp_panel_pdo_fabric_error($t,static fn()=>$nested['store']->payload(),'transaction_conflict');$t->isTrue($conflict->retryable());dp_panel_pdo_fabric_error($t,static fn()=>$nested['store']->installSchema(),'transaction_conflict');$nested['pdo']->rollBack();
	$locked=dp_panel_pdo_fabric_fixture($t,'locked',['table_prefix'=>'cf_locked','transaction_retries'=>1,'retry_delay_microseconds'=>0]);$locked['store']->installSchema();$locker=dp_panel_pdo_fabric_connection($locked['path'],0);$blocked=new PanelPdoCommandFabricStore(dp_panel_pdo_fabric_connection($locked['path'],0),['table_prefix'=>'cf_locked','transaction_retries'=>1,'retry_delay_microseconds'=>0]);$locker->exec('BEGIN IMMEDIATE');$unavailable=dp_panel_pdo_fabric_error($t,static fn()=>$blocked->transaction(static function(array &$state):void{$state['revision']++;},'blocked'),'storage_unavailable');$t->isTrue($unavailable->retryable());$locker->exec('ROLLBACK');
	$corrupt=dp_panel_pdo_fabric_fixture($t,'corrupt',['table_prefix'=>'cf_corrupt']);$corrupt['store']->installSchema();$corrupt['pdo']->exec('UPDATE cf_corrupt_state SET state_bytes = state_bytes + 1');dp_panel_pdo_fabric_error($t,static fn()=>$corrupt['store']->payload(),'storage_corrupt');
	$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);foreach([['unknown'=>1],['table_prefix'=>'bad-prefix'],['maximum_state_bytes'=>1],['maximum_change_bytes'=>1],['change_retention'=>7],['transaction_retries'=>11],['retry_delay_microseconds'=>100001]]as$options){$t->throws(static fn()=>new PanelPdoCommandFabricStore($pdo,$options),InvalidArgumentException::class);}
	$t->throws(static fn()=>PanelPdoCommandFabricStore::schemaStatementsFor('oracle'),InvalidArgumentException::class);$t->throws(static fn()=>PanelPdoCommandFabricStore::schemaStatementsFor('sqlite','bad-prefix'),InvalidArgumentException::class);$silent=new PDO('sqlite::memory:');$silent->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);$t->throws(static fn()=>new PanelPdoCommandFabricStore($silent),InvalidArgumentException::class);
	$badToken=new PanelPdoCommandFabricStore($pdo,['table_prefix'=>'cf_bad_token'],null,static fn():string=>'short');$badToken->installSchema();$t->throws(static fn()=>$badToken->acquireSubscriberLease('projection','worker',5),UnexpectedValueException::class);$t->throws(static fn()=>new PanelCommandFabricStorageException('Bad Code','bad'),InvalidArgumentException::class);
})->tag('panel','fabric','pdo','fail-closed','corruption','locking','validation')->maxMillis(10000);
