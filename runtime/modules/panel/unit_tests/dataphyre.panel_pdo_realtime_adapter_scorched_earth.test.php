<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPdoRealtimeAdapter;
use Dataphyre\Panel\PanelRealtimeCancellationToken;
use Dataphyre\Panel\PanelRealtimeContext;
use Dataphyre\Panel\PanelRealtimeEndpoint;
use Dataphyre\Panel\PanelRealtimeEvent;
use Dataphyre\Panel\PanelRealtimeException;
use Dataphyre\Panel\PanelRealtimeIntentSigner;
use Dataphyre\Panel\PanelRealtimeIntentVerification;
use Dataphyre\Panel\PanelRealtimeSubscription;
use Dataphyre\Panel\Testing\PanelRealtimeBrokerConformance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);
require_once dirname(__DIR__).'/testing/PanelRealtimeBrokerConformance.php';

/** @return array{context:PanelRealtimeContext,other:PanelRealtimeContext,subscription:PanelRealtimeSubscription} */
function dp_panel_pdo_realtime_scope(): array {
	$context=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator-7','correlation_id'=>'pdo-7']);
	$other=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'south','principal_id'=>'operator-8']);
	$subscription=PanelRealtimeSubscription::fromTrusted($context,'orders',['*']);
	return compact('context','other','subscription');
}

function dp_panel_pdo_realtime_connection(string $path, int $busyMilliseconds=5000): PDO {
	$pdo=new PDO('sqlite:'.$path); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->exec('PRAGMA busy_timeout = '.max(0,$busyMilliseconds)); return $pdo;
}

/** @param array<string,mixed> $options */
function dp_panel_pdo_realtime_adapter(Context $t, string $name, array $options=[], ?callable $clock=null): array {
	$directory=$t->tempDirectory('panel-pdo-realtime-'.$name); $path=$directory.DIRECTORY_SEPARATOR.'realtime.sqlite'; $pdo=dp_panel_pdo_realtime_connection($path); $adapter=new PanelPdoRealtimeAdapter($pdo,$options,$clock); return compact('directory','path','pdo','adapter');
}

function dp_panel_pdo_realtime_error(Context $t, callable $callback, string $code): PanelRealtimeException {
	try{ $callback(); }catch(PanelRealtimeException $error){ $t->same($code,$error->publicCode()); return $error; }
	throw new RuntimeException("Expected PanelRealtimeException {$code}.");
}

function dp_panel_pdo_realtime_intent(string $nonce, int $expiresAt=1100, string $purpose='subscribe'): PanelRealtimeIntentVerification {
	return new PanelRealtimeIntentVerification($purpose,0,900,$expiresAt,'active',$nonce);
}

suite('Panel durable shared-SQL realtime adapter')
	->contract('panel.realtime.pdo-adapter',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('pdo','schema-migration','atomic-publication','retained-replay','nonce-replay-policy','cross-process')
	->isolation('case')
	->tag('panel','realtime','pdo','durability','security')
	->group('framework-coverage');

test('pdo realtime schema plans are explicit portable idempotent and manifests stay connection-redacted',static function(Context $t): void {
	$fixture=dp_panel_pdo_realtime_adapter($t,'schema',['table_prefix'=>'rt_schema']); $adapter=$fixture['adapter'];
	$first=$adapter->installSchema(); $second=$adapter->installSchema();
	$t->same('sqlite',$adapter->driver()); $t->same(7,$first['statements']); $t->same($first,$second); $t->isTrue($first['idempotent']); $t->isFalse($first['destructive']);
	$sqlite=PanelPdoRealtimeAdapter::schemaStatementsFor('sqlite','rt_plan'); $mysql=PanelPdoRealtimeAdapter::schemaStatementsFor('mysql','rt_plan'); $pgsql=PanelPdoRealtimeAdapter::schemaStatementsFor('pgsql','rt_plan');
	$t->same(7,count($sqlite)); $t->same(5,count($mysql)); $t->same(7,count($pgsql));
	$t->contains('INSERT OR IGNORE',$sqlite[6]); $t->contains('ENGINE=InnoDB',$mysql[0]); $t->contains('INSERT IGNORE',$mysql[4]); $t->contains('ON CONFLICT',$pgsql[6]); $t->same(PanelPdoRealtimeAdapter::schemaStatementsFor('sqlite','rt_schema'),$adapter->schemaStatements());
	$t->same('BEGIN IMMEDIATE',PanelPdoRealtimeAdapter::dialectPlanFor('sqlite')['write_begin']); $t->contains('REPEATABLE READ',PanelPdoRealtimeAdapter::dialectPlanFor('mysql')['read_before'][0]); $t->contains('REPEATABLE READ',PanelPdoRealtimeAdapter::dialectPlanFor('pgsql')['read_after'][0]);
	$manifest=$adapter->jsonSerialize(); $encoded=json_encode($manifest,JSON_THROW_ON_ERROR); $t->same('panel_realtime_pdo_adapter',$manifest['type']); $t->isTrue($manifest['durable']); $t->isTrue($manifest['distributed']); $t->isTrue($manifest['atomic_publication']); $t->isFalse($manifest['raw_nonces_stored']);
	foreach([$fixture['path'],'rt_schema','sqlite:','password','dsn','nonce_hash'] as $secret){ $t->notContains($secret,$encoded); }
})->tag('panel','realtime','pdo','migration','manifest')->maxMillis(5000);

test('pdo realtime adapter passes conformance and persists one ordered stream across independent connections',static function(Context $t): void {
	$fixture=dp_panel_pdo_realtime_adapter($t,'conformance',['table_prefix'=>'rt_conformance','retained_events_per_stream'=>128]); $first=$fixture['adapter']; $first->installSchema(); $scope=dp_panel_pdo_realtime_scope();
	$report=PanelRealtimeBrokerConformance::verify($first,$scope['context'],$scope['other']); $t->isTrue($report['passed']); $t->same(8,$report['checks']); $t->same([],$report['violations']);
	$second=new PanelPdoRealtimeAdapter(dp_panel_pdo_realtime_connection($fixture['path']),['table_prefix'=>'rt_conformance','retained_events_per_stream'=>128]);
	$four=$second->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>4],['status'=>'paid']); $five=$first->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>5]);
	$t->same(4,$four->sequence()); $t->same(5,$five->sequence());
	unset($first,$second); $reopened=new PanelPdoRealtimeAdapter(dp_panel_pdo_realtime_connection($fixture['path']),['table_prefix'=>'rt_conformance','retained_events_per_stream'=>128]);
	$result=$reopened->read($scope['subscription'],0,100); $t->same(5,$result->head()); $t->same([1,2,3,4,5],array_map(static fn(PanelRealtimeEvent $event):int=>$event->sequence(),$result->events())); $t->isFalse($result->hasMore());
	$foreign=$reopened->read(PanelRealtimeSubscription::fromTrusted($scope['other'],'orders',['*']),0,10); $t->same(0,$foreign->head()); $t->same([],$foreign->events());
})->tag('panel','realtime','pdo','conformance','durable','cross-connection')->maxMillis(8000);

test('independent php processes serialize publication into one gapless durable stream',static function(Context $t): void {
	$fixture=dp_panel_pdo_realtime_adapter($t,'process-race',['table_prefix'=>'rt_process','retained_events_per_stream'=>128,'transaction_retries'=>10]); $fixture['adapter']->installSchema(); $panelRoot=dirname(__DIR__);
	$code=<<<'PHP'
foreach(['PanelRealtimeGuard.php','PanelRealtimeException.php','PanelRealtimeCancellation.php','PanelRealtimeBroker.php','PanelRealtimePublisher.php','PanelRealtimeSubscriptionIntentReplayPolicy.php','PanelRealtimeContext.php','PanelRealtimeSubscription.php','PanelRealtimeEvent.php','PanelRealtimeReadResult.php','PanelRealtimeIntentVerification.php','PanelPdoRealtimeAdapter.php'] as $source){require $argv[1].'/Framework/Realtime/'.$source;}
$pdo=new PDO('sqlite:'.$argv[2]);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout = 5000');
$adapter=new \Dataphyre\Panel\PanelPdoRealtimeAdapter($pdo,['table_prefix'=>'rt_process','retained_events_per_stream'=>128,'transaction_retries'=>10]);
$context=\Dataphyre\Panel\PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'worker-'.$argv[3]]);
for($i=0;$i<8;$i++){$adapter->publish($context,'orders','orders.updated','orders.updated',['worker'=>$argv[3],'ordinal'=>$i]);}
echo 'ok';
PHP;
	$workers=[]; foreach(['a','b','c'] as $worker){ $workers[]=$t->startPhpProcess(['-r',$code,$panelRoot,$fixture['path'],$worker],timeout_millis:15000); }
	foreach($workers as $process){ $result=$process->wait(); if(!$result->succeeded()){ throw new RuntimeException('Realtime worker failed: '.$result->stderr().' '.$result->stdout()); } $t->same('ok',trim($result->stdout())); $t->same('',trim($result->stderr())); }
	$scope=dp_panel_pdo_realtime_scope(); $reopened=new PanelPdoRealtimeAdapter(dp_panel_pdo_realtime_connection($fixture['path']),['table_prefix'=>'rt_process','retained_events_per_stream'=>128]); $result=$reopened->read($scope['subscription'],0,100);
	$t->same(24,$result->head()); $t->same(range(1,24),array_map(static fn(PanelRealtimeEvent $event):int=>$event->sequence(),$result->events()));
})->tag('panel','realtime','pdo','durable','cross-process','race')->maxMillis(20000);

test('pdo retained replay preserves scanned cursors and reports every unsafe replay reset',static function(Context $t): void {
	$now=1000; $clock=static function()use(&$now):int{return $now;}; $fixture=dp_panel_pdo_realtime_adapter($t,'retention',['table_prefix'=>'rt_retention','retained_events_per_stream'=>2],$clock); $adapter=$fixture['adapter']; $adapter->installSchema(); $scope=dp_panel_pdo_realtime_scope();
	$paid=PanelRealtimeSubscription::fromTrusted($scope['context'],'orders',['*'],['status'=>'paid']);
	$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1],['status'=>'paid']); $now++; $adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>2],['status'=>'review']); $now++; $adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>3],['status'=>'paid']);
	$gap=$adapter->read($paid,0,10); $t->same('retention_gap',$gap->resetReason()); $t->same(2,$gap->earliest()); $t->same(3,$gap->head());
	$scan=$adapter->read($paid,1,1); $t->same([],$scan->events()); $t->same(2,$scan->cursor()); $t->isTrue($scan->hasMore()); $t->same([3],array_map(static fn(PanelRealtimeEvent $event):int=>$event->sequence(),$adapter->read($paid,2,10)->events()));
	$t->same('source_reset',$adapter->read($paid,99,10)->resetReason()); $missing=PanelRealtimeSubscription::fromTrusted($scope['context'],'missing',['*']); $t->same(0,$adapter->read($missing,0,1)->head()); $t->same('source_reset',$adapter->read($missing,1,1)->resetReason());
	$cancelled=new PanelRealtimeCancellationToken(); $cancelled->cancel(); dp_panel_pdo_realtime_error($t,static fn()=>$adapter->read($paid,1,1,$cancelled),'read_cancelled');
	$t->throws(static fn()=>$adapter->read($paid,-1,1),InvalidArgumentException::class); $t->throws(static fn()=>$adapter->read($paid,0,0),InvalidArgumentException::class); $t->throws(static fn()=>$adapter->read($paid,0,1001),InvalidArgumentException::class);
})->tag('panel','realtime','pdo','retention','cursor','cancellation')->maxMillis(5000);

test('pdo publication enforces stream event and sequence bounds atomically',static function(Context $t): void {
	$fixture=dp_panel_pdo_realtime_adapter($t,'bounds',['table_prefix'=>'rt_bounds','maximum_streams'=>1,'maximum_event_bytes'=>1024]); $adapter=$fixture['adapter']; $adapter->installSchema(); $scope=dp_panel_pdo_realtime_scope();
	$t->same(1,$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1])->sequence());
	$capacity=dp_panel_pdo_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'other','other.updated','other.updated',['id'=>2]),'broker_capacity'); $t->isTrue($capacity->retryable());
	dp_panel_pdo_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',str_repeat('x',2000)),'event_too_large');
	$fixture['pdo']->prepare('UPDATE rt_bounds_streams SET head = :head WHERE stream_key = :stream_key')->execute(['head'=>PHP_INT_MAX,'stream_key'=>$scope['subscription']->streamKey()]);
	dp_panel_pdo_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>3]),'broker_sequence_exhausted');
})->tag('panel','realtime','pdo','bounds','atomicity')->maxMillis(5000);

test('pdo replay policy atomically consumes only hashed initial-connect nonces across adapters and endpoints',static function(Context $t): void {
	$now=1000; $clock=static function()use(&$now):int{return $now;}; $fixture=dp_panel_pdo_realtime_adapter($t,'replay',['table_prefix'=>'rt_replay'],$clock); $first=$fixture['adapter']; $first->installSchema(); $second=new PanelPdoRealtimeAdapter(dp_panel_pdo_realtime_connection($fixture['path']),['table_prefix'=>'rt_replay'],$clock); $scope=dp_panel_pdo_realtime_scope();
	$intent=dp_panel_pdo_realtime_intent(str_repeat('a',32)); $t->isTrue($first->consume($intent,$scope['subscription'],$scope['context'])); $t->isFalse($second->consume($intent,$scope['subscription'],$scope['context']));
	$stored=(string)$fixture['pdo']->query('SELECT nonce_hash FROM rt_replay_nonces')->fetchColumn(); $t->matches('/^[a-f0-9]{64}$/',$stored); $t->notContains($intent->nonce(),$stored);
	$t->throws(static fn()=>$first->consume(dp_panel_pdo_realtime_intent(str_repeat('b',32),1100,'resume'),$scope['subscription'],$scope['context']),InvalidArgumentException::class);
	$t->throws(static fn()=>$first->consume(dp_panel_pdo_realtime_intent(str_repeat('c',32)),$scope['subscription'],$scope['other']),InvalidArgumentException::class);
	$signer=new PanelRealtimeIntentSigner(['active'=>str_repeat('k',32)],'active',$clock); $token=$signer->issueSubscription($scope['subscription'],60)->token();
	$one=(new PanelRealtimeEndpoint($first,$signer,null,null,$clock))->protectSubscriptionIntents($first)->authorizeHost(static fn():bool=>true); $two=(new PanelRealtimeEndpoint($second,$signer,null,null,$clock))->protectSubscriptionIntents($second)->authorizeHost(static fn():bool=>true);
	$t->same(200,$one->open($scope['subscription'],$token,null,$scope['context'])->status()); $duplicate=$two->open($scope['subscription'],$token,null,$scope['context']); $t->same(409,$duplicate->status()); $t->contains('subscription_intent_replayed',(string)$duplicate->nextChunk());
})->tag('panel','realtime','pdo','replay-policy','endpoint','security')->maxMillis(8000);

test('pdo replay retention purges expired hashes and enforces global capacity without nonce disclosure',static function(Context $t): void {
	$now=1000; $clock=static function()use(&$now):int{return $now;}; $fixture=dp_panel_pdo_realtime_adapter($t,'replay-capacity',['table_prefix'=>'rt_replay_capacity','maximum_replay_entries'=>1,'replay_retention_grace_seconds'=>0],$clock); $adapter=$fixture['adapter']; $adapter->installSchema(); $scope=dp_panel_pdo_realtime_scope();
	$t->isTrue($adapter->consume(dp_panel_pdo_realtime_intent(str_repeat('d',32),1001),$scope['subscription'],$scope['context']));
	$capacity=dp_panel_pdo_realtime_error($t,static fn()=>$adapter->consume(dp_panel_pdo_realtime_intent(str_repeat('e',32),1100),$scope['subscription'],$scope['context']),'replay_policy_capacity'); $t->isTrue($capacity->retryable());
	$now=1002; $t->isTrue($adapter->consume(dp_panel_pdo_realtime_intent(str_repeat('e',32),1100),$scope['subscription'],$scope['context'])); $t->same(1,(int)$fixture['pdo']->query('SELECT COUNT(*) FROM rt_replay_capacity_nonces')->fetchColumn());
	$expired=dp_panel_pdo_realtime_error($t,static fn()=>$adapter->consume(dp_panel_pdo_realtime_intent(str_repeat('f',32),1001),$scope['subscription'],$scope['context']),'intent_expired'); $t->same(401,$expired->httpStatus());
})->tag('panel','realtime','pdo','replay-policy','retention','capacity')->maxMillis(5000);

test('pdo adapter fails closed on schema drift row corruption lock contention and foreign transactions',static function(Context $t): void {
	$scope=dp_panel_pdo_realtime_scope();
	$missing=dp_panel_pdo_realtime_adapter($t,'missing',['table_prefix'=>'rt_missing'],static fn():int=>1000); dp_panel_pdo_realtime_error($t,static fn()=>$missing['adapter']->read($scope['subscription'],0,1),'broker_storage_unavailable'); dp_panel_pdo_realtime_error($t,static fn()=>$missing['adapter']->consume(dp_panel_pdo_realtime_intent(str_repeat('1',32)),$scope['subscription'],$scope['context']),'replay_policy_unavailable');
	$broken=dp_panel_pdo_realtime_adapter($t,'broken-migration',['table_prefix'=>'rt_broken']); $broken['pdo']->exec('CREATE TABLE rt_broken_meta (singleton INTEGER PRIMARY KEY)'); dp_panel_pdo_realtime_error($t,static fn()=>$broken['adapter']->installSchema(),'broker_migration_failed');
	$drift=dp_panel_pdo_realtime_adapter($t,'drift',['table_prefix'=>'rt_drift']); $drift['adapter']->installSchema(); $drift['pdo']->exec('UPDATE rt_drift_meta SET schema_version = 2'); dp_panel_pdo_realtime_error($t,static fn()=>$drift['adapter']->read($scope['subscription'],0,1),'broker_schema_incompatible');
	$corrupt=dp_panel_pdo_realtime_adapter($t,'corrupt',['table_prefix'=>'rt_corrupt']); $corrupt['adapter']->installSchema(); $corrupt['adapter']->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]); $corrupt['pdo']->exec('UPDATE rt_corrupt_events SET wire_bytes = wire_bytes + 1'); dp_panel_pdo_realtime_error($t,static fn()=>$corrupt['adapter']->read($scope['subscription'],0,10),'broker_storage_corrupt');
	$nested=dp_panel_pdo_realtime_adapter($t,'nested',['table_prefix'=>'rt_nested']); $nested['adapter']->installSchema(); $nested['pdo']->beginTransaction(); dp_panel_pdo_realtime_error($t,static fn()=>$nested['adapter']->read($scope['subscription'],0,1),'broker_transaction_conflict'); dp_panel_pdo_realtime_error($t,static fn()=>$nested['adapter']->installSchema(),'broker_transaction_conflict'); $nested['pdo']->rollBack();
	$locked=dp_panel_pdo_realtime_adapter($t,'locked',['table_prefix'=>'rt_locked','transaction_retries'=>0]); $locked['adapter']->installSchema(); $locker=dp_panel_pdo_realtime_connection($locked['path'],0); $blockedPdo=dp_panel_pdo_realtime_connection($locked['path'],0); $blocked=new PanelPdoRealtimeAdapter($blockedPdo,['table_prefix'=>'rt_locked','transaction_retries'=>0]); $locker->exec('BEGIN IMMEDIATE'); $failure=dp_panel_pdo_realtime_error($t,static fn()=>$blocked->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]),'broker_storage_unavailable'); $t->isTrue($failure->retryable()); $locker->exec('ROLLBACK');
})->tag('panel','realtime','pdo','fail-closed','corruption','locking')->maxMillis(8000);

test('pdo adapter rejects invalid drivers prefixes options clocks and incompatible installed schemas',static function(Context $t): void {
	$t->throws(static fn()=>PanelPdoRealtimeAdapter::schemaStatementsFor('oracle'),InvalidArgumentException::class); $t->throws(static fn()=>PanelPdoRealtimeAdapter::schemaStatementsFor('sqlite','bad-prefix'),InvalidArgumentException::class); $t->throws(static fn()=>PanelPdoRealtimeAdapter::dialectPlanFor('sqlsrv'),InvalidArgumentException::class);
	$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
	foreach([
		['unknown'=>1],['table_prefix'=>'1bad'],['retained_events_per_stream'=>0],['maximum_streams'=>0],['maximum_event_bytes'=>100],['maximum_replay_entries'=>0],['replay_retention_grace_seconds'=>301],['transaction_retries'=>11],
	] as $options){ $t->throws(static fn()=>new PanelPdoRealtimeAdapter($pdo,$options),InvalidArgumentException::class); }
	$badClock=new PanelPdoRealtimeAdapter($pdo,['table_prefix'=>'rt_clock'],static fn():string=>'bad'); $badClock->installSchema(); $scope=dp_panel_pdo_realtime_scope(); $t->throws(static fn()=>$badClock->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]),UnexpectedValueException::class);
	$incompatible=new PDO('sqlite::memory:'); $incompatible->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $adapter=new PanelPdoRealtimeAdapter($incompatible,['table_prefix'=>'rt_incompatible']); $adapter->installSchema(); $incompatible->exec('UPDATE rt_incompatible_meta SET schema_version = 9'); dp_panel_pdo_realtime_error($t,static fn()=>$adapter->installSchema(),'broker_schema_incompatible');
})->tag('panel','realtime','pdo','validation','exact-coverage')->maxMillis(5000);
