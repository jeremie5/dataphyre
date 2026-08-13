<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAtomicLeasedOperationStore;
use Dataphyre\Panel\PanelLeasedOperationRunner;
use Dataphyre\Panel\PanelOperationExecution;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationInterrupted;
use Dataphyre\Panel\PanelOperationLease;
use Dataphyre\Panel\PanelOperationLeaseLost;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationReservation;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{0:PanelAtomicLeasedOperationStore,1:Closure,2:Closure,3:string} */
function dp_panel_leased_store(Context $t,string $name='store'):array {
	$now='2026-07-13T12:00:00+00:00'; $sequence=0;
	$clock=static function()use(&$now):string{return $now;};
	$advance=static function(int $seconds)use(&$now):void{$now=(new DateTimeImmutable($now))->modify('+'.$seconds.' seconds')->format(DATE_ATOM);};
	$tokens=static function()use(&$sequence):string{$sequence++;return str_pad('lease-token-'.$sequence,64,'x');};
	$directory=$t->tempDirectory('panel-leased-'.$name);
	return [new PanelAtomicLeasedOperationStore($directory,32,$clock,$tokens),$advance,$clock,$directory];
}

/** @param array<string,mixed> $options */
function dp_panel_leased_record(string $type,string $name,array $options=[]):PanelOperationRecord { return PanelOperationRecord::make($type,$name,$options+['created_at'=>'2026-07-13T12:00:00Z']); }

test('operation lease value objects expose fencing without serializing bearer proofs',static function(Context $t):void{
	$token=str_repeat('s',48); $lease=PanelOperationLease::make('operation.1','worker-a',$token,7,'2026-07-13T12:00:00Z','2026-07-13T12:01:00Z');
	$t->same('operation.1',$lease->operationId());
	$t->same('worker-a',$lease->worker());
	$t->same($token,$lease->token());
	$t->same(7,$lease->fence());
	$t->same('2026-07-13T12:00:00+00:00',$lease->acquiredAt());
	$t->same($lease->acquiredAt(),$lease->renewedAt());
	$t->same('2026-07-13T12:01:00+00:00',$lease->expiresAt());
	$t->isFalse($lease->expired('2026-07-13T12:00:59Z'));
	$t->isTrue($lease->expired('2026-07-13T12:01:00Z'));
	$renewed=$lease->renewed('2026-07-13T12:00:30Z','2026-07-13T12:02:00Z');
	$t->same('2026-07-13T12:00:30+00:00',$renewed->renewedAt());
	$t->same($lease->tokenFingerprint(),$renewed->tokenFingerprint());
	$encoded=json_encode($lease,JSON_THROW_ON_ERROR);
	$t->notContains($token,$encoded);
	$t->contains($lease->tokenFingerprint(),$encoded);
	$t->throws(static fn()=>PanelOperationLease::make('bad id','worker',$token,1,'now','+1 minute'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationLease::make('ok','bad worker',$token,1,'now','+1 minute'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationLease::make('ok','worker','short',1,'now','+1 minute'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationLease::make('ok','worker',$token,0,'now','+1 minute'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationLease::make('ok','worker',$token,1,'2026-07-13T12:01:00Z','2026-07-13T12:00:00Z'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelOperationLease::make('ok','worker',$token,1,'not-time','also-not-time'),InvalidArgumentException::class);
	$lost=new PanelOperationLeaseLost('operation.1'); $t->same('operation.1',$lost->operationId());
})->tag('panel','operations','leases','security')->maxMillis(1000);

test('atomic leased store fences stale workers and keeps raw tokens out of snapshots',static function(Context $t):void{
	[$store,$advance,,$directory]=dp_panel_leased_store($t,'lifecycle');
	$record=$store->create(dp_panel_leased_record('export','Export',['id'=>'export-1','idempotency_key'=>'idem-export','total'=>2,'max_attempts'=>4]));
	$t->same(1,$record->revision());
	$t->same('export-1',$store->create(dp_panel_leased_record('export','Replay',['id'=>'another','idempotency_key'=>'idem-export']))->id());
	$first=$store->acquireLease('export-1','worker-a',5);
	$t->isTrue($first instanceof PanelOperationReservation);
	$t->same(1,$first?->lease()->fence());
	$t->same(PanelOperationStatus::RUNNING,$first?->record()->status());
	$t->same('worker-a',$first?->record()->worker());
	$t->same('panel_operation_reservation',$first?->jsonSerialize()['type']);
	$t->same(null,$store->acquireLease('export-1','worker-b'));
	$mutated=$store->mutateLease($first->lease(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->progress(1,2,'Half',1,0));
	$t->same(1,$mutated->record()->processed());
	$t->same($first->lease()->expiresAt(),$mutated->lease()->expiresAt());
	$advance(2); $renewed=$store->renewLease($mutated->lease(),30);
	$t->same('2026-07-13T12:00:32+00:00',$renewed->lease()->expiresAt());
	$t->same(1,count($store->activeLeaseManifests()));
	$t->notContains('token',json_encode($store->activeLeaseManifests(),JSON_THROW_ON_ERROR));
	$forged=PanelOperationLease::make('export-1','worker-a',str_repeat('f',48),1,$first->lease()->acquiredAt(),$renewed->lease()->expiresAt());
	$t->throws(static fn()=>$store->inspectLease($forged),PanelOperationLeaseLost::class);
	$t->throws(static fn()=>$store->delete('export-1'),Dataphyre\Panel\PanelOperationConflict::class);
	$released=$store->releaseLease($renewed->lease(),0);
	$t->same(PanelOperationStatus::RETRY_WAIT,$released->status());
	$t->same([], $store->activeLeaseManifests());
	$second=$store->reserveLease('default','worker-b',10);
	$t->same(2,$second?->lease()->fence());
	$t->throws(static fn()=>$store->mutateLease($first->lease(),static fn(PanelOperationRecord $current)=>$current),PanelOperationLeaseLost::class);
	$complete=$store->finishLease($second->lease(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->complete(['ok'=>true]));
	$t->same(PanelOperationStatus::COMPLETED,$complete->status());
	$t->same(null,$store->reserveLease());
	$t->isTrue(count($store->changesSince(0,100)['changes'])>0);
	$t->isTrue($store->delete('export-1'));
	$t->isFalse($store->delete('export-1'));
	$t->same(null,$store->findByIdempotencyKey('idem-export'));
	$files=glob($directory.DIRECTORY_SEPARATOR.'*.json')?:[];
	$raw=''; foreach($files as $file){ $raw.=(string)file_get_contents($file); }
	$t->notContains($first->lease()->token(),$raw);
	$t->same('panel_atomic_leased_operation_store',$store->jsonSerialize()['type']);
	$t->isTrue($store->jsonSerialize()['capabilities']['fencing']);
})->tag('panel','operations','leases','fencing')->maxMillis(3000);

test('leased store retains optimistic store semantics and rejects invalid mutation and reservation shapes',static function(Context $t):void{
	[$store]=dp_panel_leased_store($t,'store-contract');
	$created=$store->create(dp_panel_leased_record('job','Editable',['id'=>'editable','idempotency_key'=>'editable-key','max_attempts'=>2]));
	$edited=$created->log('info','edited',[],'2026-07-13T12:00:01Z');
	$saved=$store->save($edited,$created->revision());
	$t->same(2,$saved->revision());
	$t->same('edited',$saved->logs()[0]['message']);
	$t->throws(static fn()=>$store->save($edited,$created->revision()),Dataphyre\Panel\PanelOperationConflict::class);
	$t->throws(static fn()=>$store->save(dp_panel_leased_record('job','Missing',['id'=>'absent'])),OutOfBoundsException::class);
	$t->throws(static fn()=>$store->update('editable',static fn()=>null),UnexpectedValueException::class);
	$t->throws(static fn()=>$store->update('editable',static fn()=>dp_panel_leased_record('job','Other',['id'=>'other'])),LogicException::class);
	$t->throws(static fn()=>$store->update('editable',static fn(PanelOperationRecord $current)=>$current,1),Dataphyre\Panel\PanelOperationConflict::class);
	$t->throws(static fn()=>$store->all(['unsupported'=>'value']),InvalidArgumentException::class);
	$t->same(null,$store->findByIdempotencyKey(''));
	$t->same('editable',$store->findByIdempotencyKey('editable-key')?->id());
	$t->same(1,count($store->all(['id'=>'editable','type'=>'job','queue'=>'default','status'=>PanelOperationStatus::QUEUED,'idempotency_key'=>'editable-key','worker'=>null],10,0)));

	$exhausted=dp_panel_leased_record('job','Exhausted',['id'=>'queued-exhausted','max_attempts'=>1])->jsonSerialize();
	$exhausted['attempt']=1;
	$store->create(PanelOperationRecord::fromArray($exhausted));
	$t->same(null,$store->acquireLease('queued-exhausted','worker'));
	$t->same(null,$store->reserveLease('empty-queue','worker'));
	$t->throws(static fn()=>$store->acquireLease('editable','bad worker'),InvalidArgumentException::class);

	$fixedClock=static fn():string=>'2026-07-13T12:00:00Z';
	$nonStringTokens=new PanelAtomicLeasedOperationStore($t->tempDirectory('panel-leased-token-type'),4,$fixedClock,static fn():array=>[]);
	$nonStringTokens->create(dp_panel_leased_record('job','Bad token type',['id'=>'bad-token-type']));
	$t->throws(static fn()=>$nonStringTokens->acquireLease('bad-token-type'),UnexpectedValueException::class);
	$unsafeTokens=new PanelAtomicLeasedOperationStore($t->tempDirectory('panel-leased-token-shape'),4,$fixedClock,static fn():string=>'short');
	$unsafeTokens->create(dp_panel_leased_record('job','Bad token shape',['id'=>'bad-token-shape']));
	$t->throws(static fn()=>$unsafeTokens->acquireLease('bad-token-shape'),UnexpectedValueException::class);

	$lease=PanelOperationLease::make('editable','worker-a',str_repeat('q',48),1,'2026-07-13T12:00:00Z','2026-07-13T12:01:00Z');
	$t->throws(static fn()=>new PanelOperationReservation($saved,$lease),InvalidArgumentException::class);
	$t->isTrue($store->delete('queued-exhausted'));
	$t->isTrue($store->delete('editable'));
})->tag('panel','operations','leases','store-contract')->maxMillis(3000);

test('expired leases recover cancellation pause retry and exhausted attempts deterministically',static function(Context $t):void{
	[$store,$advance]=dp_panel_leased_store($t,'recovery');
	$records=[
		'retry'=>dp_panel_leased_record('job','Retry',['id'=>'retry','max_attempts'=>2]),
		'exhausted'=>dp_panel_leased_record('job','Exhausted',['id'=>'exhausted','max_attempts'=>1]),
		'cancel'=>dp_panel_leased_record('job','Cancel',['id'=>'cancel','max_attempts'=>2]),
		'pause'=>dp_panel_leased_record('job','Pause',['id'=>'pause','max_attempts'=>2]),
	];
	$leases=[]; foreach($records as $id=>$record){$store->create($record);$leases[$id]=$store->acquireLease($id,'worker-'.$id,5)->lease();}
	$store->update('cancel',static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->requestCancel('2026-07-13T12:00:01Z'));
	$store->update('pause',static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->requestPause('2026-07-13T12:00:01Z'));
	$advance(6);
	$t->same([], $store->activeLeaseManifests());
	$t->same(0,$store->manifest()['active_leases']);
	$first=$store->recoverExpiredLeases(2); $second=$store->recoverExpiredLeases(10);
	$t->same(4,count(array_merge($first,$second)));
	$t->same(PanelOperationStatus::RETRY_WAIT,$store->get('retry')?->status());
	$t->same(PanelOperationStatus::FAILED,$store->get('exhausted')?->status());
	$t->same(PanelOperationStatus::CANCELLED,$store->get('cancel')?->status());
	$t->same(PanelOperationStatus::PAUSED,$store->get('pause')?->status());
	$t->same([], $store->activeLeaseManifests());
	$t->throws(static fn()=>$store->inspectLease($leases['retry']),PanelOperationLeaseLost::class);
	$reacquired=$store->acquireLease('retry','worker-next',5); $t->same(2,$reacquired?->lease()->fence());
	$advance(6); $t->same(PanelOperationStatus::FAILED,(new PanelOperationControl($store))->recoverStale(1)[0]->status());
	$t->same([], $store->activeLeaseManifests());

	$local=new PanelFilesystemOperationStore($t->tempDirectory('panel-local-stale-pause'));
	$local->create(dp_panel_leased_record('local','Pause stale',['id'=>'local-stale-pause','max_attempts'=>2])->start('dead','2020-01-01T00:00:00Z')->requestPause('2020-01-01T00:00:01Z'));
	$t->same(PanelOperationStatus::PAUSED,(new PanelOperationControl($local))->recoverStale(1)[0]->status());
})->tag('panel','operations','leases','recovery')->maxMillis(3000);

test('operation execution preserves its local non-leased compatibility paths',static function(Context $t):void{
	[$store]=dp_panel_leased_store($t,'local-execution');
	$store->create(dp_panel_leased_record('local','Local',['id'=>'local-running','max_attempts'=>2]));
	$store->update('local-running',static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->start('local','2026-07-13T12:00:00Z'));
	$execution=new PanelOperationExecution($store,'local-running');
	$t->same(null,$execution->lease());
	$t->same('local-running',$execution->record()->id());
	$t->same(PanelOperationStatus::RUNNING,$execution->heartbeat()->status());
	$t->same(1,$execution->progress(1,1,'done',1,0)->processed());
	$t->throws(static fn()=>$execution->requireLease(),PanelOperationLeaseLost::class);
	$t->throws(static fn()=>(new PanelOperationExecution($store,'missing'))->record(),OutOfBoundsException::class);

	foreach(['local-cancel'=>PanelOperationStatus::CANCEL_REQUESTED,'local-pause'=>PanelOperationStatus::PAUSE_REQUESTED] as $id=>$requested){
		$store->create(dp_panel_leased_record('local','Control',['id'=>$id,'max_attempts'=>2]));
		$store->update($id,static function(PanelOperationRecord $current)use($requested):PanelOperationRecord{
			$current=$current->start('local','2026-07-13T12:00:00Z'); return $requested===PanelOperationStatus::CANCEL_REQUESTED ? $current->requestCancel('2026-07-13T12:00:01Z') : $current->requestPause('2026-07-13T12:00:01Z');
		});
		$local=new PanelOperationExecution($store,$id); $t->throws(static fn()=>$local->guard(),PanelOperationInterrupted::class);
	}
	$t->same(PanelOperationStatus::CANCELLED,$store->get('local-cancel')?->status());
	$t->same(PanelOperationStatus::PAUSED,$store->get('local-pause')?->status());
})->tag('panel','operations','execution','compatibility')->maxMillis(3000);

test('platform exposes distributed workers as an explicit instance-owned domain',static function(Context $t):void{
	$disabled=['operations'=>false,'data'=>false,'workflows'=>false,'automation'=>false,'authentication'=>false,'notifications'=>false,'media'=>false,'localization'=>false,'preferences'=>false,'collaboration'=>false,'relations'=>false,'security'=>false,'development'=>false,'extensions'=>false,'platform'=>false];
	$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-distributed'),'distributed_operations'=>['lease_ttl_seconds'=>5,'snapshot_retention'=>16]]+$disabled);
	$t->isTrue($platform->manifest()->available('distributed_operations'));
	$t->isTrue($platform->manifest()->configured('distributed_operations'));
	$t->isTrue($platform->manifest()->ready('distributed_operations'));
	$t->same($platform->distributedOperationStore(),$platform->distributedOperationRunner()->store());
	$t->same($platform->distributedOperationHandlers(),$platform->distributedOperationRunner()->handlers());
	$t->same($platform->distributedOperationControl()::class,Dataphyre\Panel\PanelOperationControl::class);
	$platform->registerDistributedOperationHandler('platform_job',static fn(mixed $payload):array=>['payload'=>$payload]);
	$submitted=$platform->distributedOperationRunner()->submit('platform_job','Platform job',['safe'=>true],['id'=>'platform-job','max_attempts'=>2]);
	$t->same(PanelOperationStatus::COMPLETED,$platform->distributedOperationRunner()->work(null,1,'platform-worker')[0]->status());
	$t->same('platform-job',$submitted->id());
	$t->isTrue(in_array('distributed_operations',$platform->jsonSerialize()['metadata']['enabled_domains'],true));

	$without=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-without-distributed')]+$disabled);
	$t->isFalse($without->has('distributed_operations.store'));
	$t->isFalse($without->manifest()->configured('distributed_operations'));
})->tag('panel','platform','operations','leases')->maxMillis(5000);

test('leased runner executes progress controls retries and refuses stale completion',static function(Context $t):void{
	[$store,$advance]=dp_panel_leased_store($t,'runner'); $handlers=new PanelOperationHandlerRegistry();
	$handlers->register('success',static function(mixed $payload,PanelOperationExecution $execution):array{
		$execution->progress(1,2,'One',1,0); $execution->advance(1,true,'Two'); $execution->checkpoint('done',['ok'=>true]); $execution->log('info','finished'); $execution->artifact('report','memory://report','text/plain',4); $execution->heartbeat(); return ['value'=>$payload];
	});
	$handlers->register('failure',static function():never{throw new RuntimeException('password=super-secret');});
	$handlers->register('stale',static function(mixed $payload,PanelOperationExecution $execution)use($advance):void{$advance(6);$execution->progress(1,1);});
	$handlers->register('partial',static fn():array=>['status'=>PanelOperationStatus::COMPLETED_WITH_FAILURES]);
	$handlers->register('recover_success',static fn():array=>['recovered'=>true]);
	$handlers->register('cancel',static function(mixed $payload,PanelOperationExecution $execution)use($store):void{$store->update($execution->id(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->requestCancel($store->currentTime()));if(!$execution->cancellationRequested()){throw new LogicException('Cancellation was not visible.');}$execution->heartbeat();});
	$handlers->register('pause',static function(mixed $payload,PanelOperationExecution $execution)use($store):void{$store->update($execution->id(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->requestPause($store->currentTime()));if(!$execution->pauseRequested()){throw new LogicException('Pause was not visible.');}$execution->heartbeat();});
	$handlers->register('self_release',static function(mixed $payload,PanelOperationExecution $execution)use($store):never{$store->releaseLease($execution->requireLease(),0);throw new RuntimeException('worker already released');});
	$runner=new PanelLeasedOperationRunner($store,$handlers,5); $t->same(5,$runner->ttlSeconds()); $t->same($store,$runner->store()); $t->same($handlers,$runner->handlers());
	$success=$runner->submit('success','Success',['id'=>7],['id'=>'success-1','total'=>2,'created_at'=>'2026-07-13T12:00:00Z']);
	$t->same('success-1',$success->id()); $result=$runner->work(null,1,'runner-a')[0];
	$t->same(PanelOperationStatus::COMPLETED,$result->status());
	$t->same(2,$result->processed());
	$t->same(1,count($result->checkpoints()));
	$t->same(1,count($result->logs()));
	$t->same(1,count($result->artifacts()));
	$t->same(['value'=>['id'=>7]],$result->result());

	$runner->submit('partial','Partial',[],['id'=>'partial-1','created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::COMPLETED_WITH_FAILURES,$runner->run('partial-1')->status());
	$runner->submit('failure','Failure',[],['id'=>'failure-1','max_attempts'=>2,'created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::RETRY_WAIT,$runner->work(null,1,'runner-b')[0]->status());
	$t->same(PanelOperationStatus::FAILED,$runner->work(null,1,'runner-b')[0]->status());
	$failureJson=json_encode($store->get('failure-1'),JSON_THROW_ON_ERROR); $t->notContains('super-secret',$failureJson); $t->contains('[REDACTED]',$failureJson);
	$runner->submit('missing','Missing',[],['id'=>'missing-1','created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::FAILED,$runner->work(null,1,'runner-c')[0]->status());
	$runner->submit('cancel','Cancel',[],['id'=>'cancel-runner','created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::CANCELLED,$runner->run('cancel-runner')->status());
	$runner->submit('pause','Pause',[],['id'=>'pause-runner','created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::PAUSED,$runner->run('pause-runner')->status());
	$runner->submit('self_release','Release',[],['id'=>'release-runner','max_attempts'=>2,'created_at'=>'2026-07-13T12:00:00Z']);
	$t->same(PanelOperationStatus::RETRY_WAIT,$runner->run('release-runner')->status());
	$missingStale=$store->create(dp_panel_leased_record('missing_again','Missing again',['id'=>'missing-stale','max_attempts'=>2]));
	$missingReservation=$store->acquireLease($missingStale->id(),'stale-missing',5); $store->releaseLease($missingReservation->lease(),0);
	$t->same(PanelOperationStatus::RETRY_WAIT,$runner->runReservation($missingReservation)->status());
	$store->delete('release-runner'); $store->delete('missing-stale');

	$runner->submit('stale','Stale',[],['id'=>'stale-1','max_attempts'=>2,'created_at'=>'2026-07-13T12:00:00Z']);
	$stale=$runner->work(null,1,'runner-d')[0];
	$t->same(PanelOperationStatus::RUNNING,$stale->status());
	$t->same(PanelOperationStatus::RETRY_WAIT,$store->recoverExpiredLeases()[0]->status());
	$runner->submit('recover_success','Recover before run',[],['id'=>'recover-before-run','max_attempts'=>2,'created_at'=>'2026-07-13T12:00:00Z']);
	$expiredReservation=$store->acquireLease('recover-before-run','expired-worker',5);
	$t->isTrue($expiredReservation instanceof PanelOperationReservation);
	$advance(6);
	$t->same(PanelOperationStatus::COMPLETED,$runner->run('recover-before-run')->status());
	$t->same(['recovered'=>true],$store->get('recover-before-run')?->result());
	$t->throws(static fn()=>$runner->run('missing-id'),OutOfBoundsException::class);
	$t->same(PanelOperationStatus::COMPLETED,$runner->run('success-1')->status());
})->tag('panel','operations','leases','runner')->maxMillis(5000);
