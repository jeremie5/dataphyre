<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAttestedCommandObligationVerifier;
use Dataphyre\Panel\PanelClosedLoopIntelligence;
use Dataphyre\Panel\PanelCommandApprovalAttestation;
use Dataphyre\Panel\PanelCommandEnvelope;
use Dataphyre\Panel\PanelCommandFabric;
use Dataphyre\Panel\PanelCommandFabricStore;
use Dataphyre\Panel\PanelCommandOutcome;
use Dataphyre\Panel\PanelCommandRegistry;
use Dataphyre\Panel\PanelEncryptedCommandPayloadCodec;
use Dataphyre\Panel\PanelEventDraft;
use Dataphyre\Panel\PanelFilesystemCommandFabricStore;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{0:PanelPolicyControlPlane,1:string} */
function dp_panel_closed_loop_policy():array {
	$key=str_repeat('P',48);$policy=new PanelPolicyControlPlane(['policy'=>$key],true);
	$policy->register(PanelPolicyBundle::from(['id'=>'closed_loop_policy','version'=>'1.0.0','rules'=>[
		'intelligence'=>['effect'=>'allow','abilities'=>['intelligence.*'],'priority'=>100,'reason'=>'Governed intelligence operation.'],
		'orders'=>['effect'=>'allow','abilities'=>['orders.remediate'],'priority'=>100,'obligations'=>['approval_count'=>2,'confirmation'=>true,'separation_of_duties'=>true],'reason'=>'Independently approved remediation.'],
	]])->sign('policy',$key));
	return[$policy,$key];
}

/** @param array<string,mixed> $context */
function dp_panel_closed_loop_request(string $actor,array $context=[]):PanelPolicyRequest {
	return new PanelPolicyRequest($actor,'intelligence.request','tenant-a',null,null,'high',['operator'],['intelligence.*','orders.*'],$context);
}

test('command authority evidence is signed encrypted target-bound and backward compatible',static function(Context $t):void {
	[$policy]=dp_panel_closed_loop_policy();$approvalKey=str_repeat('I',48);$now='2026-07-16T12:00:00.000000Z';$verifier=new PanelAttestedCommandObligationVerifier(['intelligence'=>$approvalKey],static fn():string=>$now);
	$base=new PanelCommandEnvelope('orders.remediate','orders.remediate','tenant-a','dispatcher','authority-key',['order'=>'SO-private'],'high',['operator'],['orders.*'],metadata:['confirmed'=>true],createdAt:$now);
	$attestation=PanelCommandApprovalAttestation::sign($base->executionTarget(),'intelligence','proposal:1',['approver-a','approver-b'],$now,'2026-07-16T13:00:00Z','intelligence',$approvalKey);
	$command=$base->withEvidence(['approval_attestation'=>$attestation->jsonSerialize()]);$decision=$policy->evaluate(new PanelPolicyRequest('dispatcher','orders.remediate','tenant-a',null,null,'high',['operator'],['orders.*'],['metadata'=>['confirmed'=>true]],$now));
	$result=$verifier->verify($command,$decision);$t->isTrue($decision->allowed());$t->isTrue($result->satisfied());$t->same(2,$result->evidence()['approved_count']);$t->same($base->executionTarget(),$command->executionTarget());$t->isFalse(hash_equals($base->fingerprint(),$command->fingerprint()));
	$manifest=json_encode($command,JSON_THROW_ON_ERROR);$t->notContains('approver-a',$manifest);$t->notContains('approval_attestation',$manifest);$t->notContains('SO-private',$manifest);
	$hydrated=PanelCommandEnvelope::hydrate($command->jsonSerialize(),$command->sealedPayload());$t->same($command->fingerprint(),$hydrated->fingerprint());$t->isTrue($verifier->verify($hydrated,$decision)->satisfied());
	$tampered=$attestation->jsonSerialize();$tampered['signature']=str_repeat('0',64);$denied=$verifier->verify($base->withEvidence(['approval_attestation'=>$tampered]),$decision);$t->isFalse($denied->satisfied());
	$oldOne=new PanelCommandEnvelope('orders.remediate','orders.remediate','tenant-a','dispatcher','old-key',['value'=>1],createdAt:$now);$oldLater=new PanelCommandEnvelope('orders.remediate','orders.remediate','tenant-a','dispatcher','old-key',['value'=>1],createdAt:'2026-07-17T12:00:00Z');$t->same($oldOne->fingerprint(),$oldLater->fingerprint());
})->tag('panel','intelligence','fabric','attestation','security')->isolation('case')->maxMillis(7000);

test('closed loop intelligence governs proposals approvals exact dispatch feedback and restart',static function(Context $t):void {
	$root=$t->tempDirectory('panel-closed-loop');[$policy]=dp_panel_closed_loop_policy();$approvalKey=str_repeat('I',48);$fabricKey=str_repeat('F',48);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48));$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$runs=0;$seen=[];
	$handler=static function(PanelCommandEnvelope $command)use(&$runs,&$seen):PanelCommandOutcome{$runs++;$seen=['input'=>$command->input(),'evidence'=>$command->evidence(),'actor'=>$command->actorId()];return PanelCommandOutcome::make(['remediated'=>true],[new PanelEventDraft('orders.remediated','order','SO-1')]);};
	$registry=new PanelCommandRegistry();$registry->register('orders.*',$handler);$fabric=new PanelCommandFabric($registry,new PanelFilesystemCommandFabricStore($root.'/fabric'),$policy,$codec,['fabric'=>$fabricKey],'fabric',new PanelAttestedCommandObligationVerifier(['intelligence'=>$approvalKey],$clock),$clock);
	$loop=new PanelClosedLoopIntelligence($root.'/intelligence',$fabric,$policy,$codec,['intelligence'=>$approvalKey],'intelligence',clock:$clock);
	$signal=$loop->observe('anomaly','tenant-a','process-miner','order','SO-1','Repeated fulfillment retries.','high',9700,dp_panel_closed_loop_request('proposer-private'),['trace'=>'signal-evidence-private'],ttlSeconds:7200);
	$t->same('signal-evidence-private',$signal->evidence()['trace']);$signalPublic=json_encode($signal,JSON_THROW_ON_ERROR);$t->notContains('signal-evidence-private',$signalPublic);$t->isFalse($signal::hydrate($signal->jsonSerialize())->hasEvidencePayload());
	$malformedSignal=$signal->jsonSerialize();unset($malformedSignal['signature']);$t->throws(static fn()=>$signal::hydrate($malformedSignal),UnexpectedValueException::class);
	$proposal=$loop->propose($signal->id(),'orders.remediate','orders.remediate',['order_id'=>'SO-1','token'=>'command-input-private'],'high','Retry through the governed adapter.',dp_panel_closed_loop_request('proposer-private'),'proposal-idempotency-private');
	$t->same('awaiting_approval',$proposal->status());$t->same(2,$proposal->requiredApprovals());$t->same($proposal->id(),$loop->propose($signal->id(),'orders.remediate','orders.remediate',['order_id'=>'SO-1','token'=>'command-input-private'],'high','Retry through the governed adapter.',dp_panel_closed_loop_request('proposer-private'),'proposal-idempotency-private')->id());
	$t->throws(static fn()=>$loop->propose($signal->id(),'orders.remediate','orders.remediate',['order_id'=>'different'],'high','Retry through the governed adapter.',dp_panel_closed_loop_request('proposer-private'),'proposal-idempotency-private'),LogicException::class);
	$rejectable=$loop->propose($signal->id(),'orders.remediate','orders.remediate',['order_id'=>'SO-rejected'],'low','Do not execute this candidate.',dp_panel_closed_loop_request('proposer-private'),'proposal-rejection-private');$rejected=$loop->reject($rejectable->id(),'Operator rejected this candidate.',dp_panel_closed_loop_request('reviewer-private'));$t->same('rejected',$rejected->status());$t->throws(static fn()=>$loop->dispatch($rejected->id(),dp_panel_closed_loop_request('dispatcher'),true),LogicException::class);
	$t->throws(static fn()=>$loop->approve($proposal->id(),dp_panel_closed_loop_request('proposer-private')),LogicException::class);
	$one=$loop->approve($proposal->id(),dp_panel_closed_loop_request('approver-a'));$t->same('approver-a',$one->approverId());$t->same('awaiting_approval',$loop->proposal($proposal->id())->status());
	$two=$loop->approve($proposal->id(),dp_panel_closed_loop_request('approver-b'));$t->same('approver-b',$two->approverId());$t->same('ready',$loop->proposal($proposal->id())->status());
	$t->throws(static fn()=>$loop->dispatch($proposal->id(),dp_panel_closed_loop_request('approver-a'),true),LogicException::class);
	$receipt=$loop->dispatch($proposal->id(),dp_panel_closed_loop_request('dispatcher',['mfa_level'=>2]),true);$t->isTrue($receipt->ok());$t->same(1,$runs);$t->same('dispatcher',$seen['actor']);$t->same('command-input-private',$seen['input']['token']);$t->isTrue(isset($seen['evidence']['approval_attestation']));$t->same('succeeded',$loop->proposal($proposal->id())->status());
	$replay=$loop->dispatch($proposal->id(),dp_panel_closed_loop_request('dispatcher',['mfa_level'=>2]),true);$t->isTrue($replay->replay());$t->same($receipt->digest(),$replay->digest());$t->same(1,$runs);
	$feedback=$loop->recordFeedback($proposal->id(),'positive',9100,['measurement'=>'feedback-evidence-private'],dp_panel_closed_loop_request('observer-private'),'feedback-idempotency-private');$t->same('feedback-evidence-private',$feedback->evidence()['measurement']);$t->same($feedback->id(),$loop->recordFeedback($proposal->id(),'positive',9100,['measurement'=>'feedback-evidence-private'],dp_panel_closed_loop_request('observer-private'),'feedback-idempotency-private')->id());$t->throws(static fn()=>$loop->recordFeedback($proposal->id(),'negative',100,['measurement'=>'changed'],dp_panel_closed_loop_request('observer-private'),'feedback-idempotency-private'),LogicException::class);
	$t->same($feedback->id(),$loop->feedback('tenant-a',$proposal->id())[0]->id());$t->isTrue($loop->changesSince()['cursor']>0);
	$effectiveness=$loop->effectiveness('tenant-a');$t->same(1,$effectiveness['feedback_count']);$t->same(9100,$effectiveness['average_effectiveness_basis_points']);$t->same(1,$effectiveness['commands']['orders.remediate']['positive']);$t->isTrue($loop->verifyIntegrity()['ok']);
	$contents='';foreach(glob($root.'/{fabric,intelligence}/*.json',GLOB_BRACE)?:[]as$file){$contents.=(string)file_get_contents($file);}$t->notContains('signal-evidence-private',$contents);$t->notContains('command-input-private',$contents);$t->notContains('feedback-evidence-private',$contents);$t->notContains('proposal-idempotency-private',$contents);$t->notContains('feedback-idempotency-private',$contents);$t->notContains('proposer-private',$contents);$t->notContains('approver-a',$contents);$t->notContains('approver-b',$contents);$t->notContains('observer-private',$contents);
	$nextRegistry=new PanelCommandRegistry();$nextRegistry->register('orders.*',$handler);$nextFabric=new PanelCommandFabric($nextRegistry,new PanelFilesystemCommandFabricStore($root.'/fabric'),$policy,$codec,['fabric'=>$fabricKey],'fabric',new PanelAttestedCommandObligationVerifier(['intelligence'=>$approvalKey],$clock),$clock);$next=new PanelClosedLoopIntelligence($root.'/intelligence',$nextFabric,$policy,$codec,['intelligence'=>$approvalKey],'intelligence',clock:$clock);$t->same('succeeded',$next->proposal($proposal->id())->status());$t->isTrue($next->dispatch($proposal->id(),dp_panel_closed_loop_request('dispatcher'),true)->replay());$t->same(1,$runs);$t->same(1,$next->jsonSerialize()['feedback_count']);
})->tag('panel','intelligence','closed-loop','approvals','feedback','restart','security')->isolation('case')->maxMillis(18000);

test('closed loop intelligence recovers a split journal and rejects snapshot tampering',static function(Context $t):void {
	$root=$t->tempDirectory('panel-closed-loop-recovery');[$policy]=dp_panel_closed_loop_policy();$approvalKey=str_repeat('I',48);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48));$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$runs=0;
	$inner=new PanelFilesystemCommandFabricStore($root.'/fabric');$store=new class($inner)implements PanelCommandFabricStore,JsonSerializable{public bool $failFinalization=true;public function __construct(private readonly PanelCommandFabricStore $inner){}public function payload():array{return$this->inner->payload();}public function transaction(callable $mutation,string $type,array $event=[]):array{if($this->failFinalization&&$type==='command_succeeded'){$this->failFinalization=false;throw new RuntimeException('simulated crash after handler');}return$this->inner->transaction($mutation,$type,$event);}public function changesSince(int $cursor=0,int $limit=100):array{return$this->inner->changesSince($cursor,$limit);}public function jsonSerialize():array{return['type'=>'fault_injecting_command_store'];}};
	$registry=new PanelCommandRegistry();$registry->register('orders.*',static function()use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(['ok'=>true]);});$fabric=new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>str_repeat('F',48)],'fabric',new PanelAttestedCommandObligationVerifier(['intelligence'=>$approvalKey],$clock),$clock);$loop=new PanelClosedLoopIntelligence($root.'/intelligence',$fabric,$policy,$codec,['intelligence'=>$approvalKey],'intelligence',clock:$clock,dispatchStaleSeconds:300);
	$signal=$loop->observe('recommendation','tenant-a','optimizer','order','SO-2','Apply bounded remediation.','high',9000,dp_panel_closed_loop_request('proposer'),[],ttlSeconds:7200);$proposal=$loop->propose($signal->id(),'orders.remediate','orders.remediate',['order_id'=>'SO-2'],'high','Apply remediation.',dp_panel_closed_loop_request('proposer'),'recovery-proposal');$loop->approve($proposal->id(),dp_panel_closed_loop_request('approver-one'));$loop->approve($proposal->id(),dp_panel_closed_loop_request('approver-two'));
	$t->throws(static fn()=>$loop->dispatch($proposal->id(),dp_panel_closed_loop_request('dispatcher'),true),RuntimeException::class);$t->same(1,$runs);$t->same('dispatching',$loop->proposal($proposal->id())->status());$t->same('executing',$store->payload()['commands'][array_key_first($store->payload()['commands'])]['status']);
	$now='2026-07-16T12:10:00.000000Z';$recovered=$loop->recoverStale(dp_panel_closed_loop_request('recovery-operator'),300);$t->same(1,count($recovered['resumed']));$t->same([],$recovered['errors']);$t->same(2,$runs);$t->same('succeeded',$loop->proposal($proposal->id())->status());$t->same(2,$loop->proposal($proposal->id())->dispatchAttempts());
	$files=glob($root.'/intelligence/*.json')?:[];sort($files,SORT_STRING);$latest=$files[array_key_last($files)];$snapshot=json_decode((string)file_get_contents($latest),true,512,JSON_THROW_ON_ERROR);$snapshot['payload']['revision']++;file_put_contents($latest,json_encode($snapshot,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>new PanelClosedLoopIntelligence($root.'/intelligence',$fabric,$policy,$codec,['intelligence'=>$approvalKey],'intelligence',clock:$clock),UnexpectedValueException::class);
})->tag('panel','intelligence','recovery','tamper','split-journal')->isolation('case')->maxMillis(18000);
