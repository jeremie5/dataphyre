<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelAccessibilityAudit;
use Dataphyre\Panel\PanelCommandEnvelope;
use Dataphyre\Panel\PanelCommandFabricStore;
use Dataphyre\Panel\PanelCommandOutcome;
use Dataphyre\Panel\PanelCallbackFederationTransport;
use Dataphyre\Panel\PanelCallbackReleaseDeploymentAdapter;
use Dataphyre\Panel\PanelEventDraft;
use Dataphyre\Panel\PanelFilesystemCommandFabricStore;
use Dataphyre\Panel\PanelFederationNode;
use Dataphyre\Panel\PanelOperationsConsole;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Panel\PanelOperationsOsFabricHandler;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformAssets;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelPlatformTemplate;
use Dataphyre\Panel\PanelPolicyRequest;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelReleaseArtifact;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_console_config(array $overrides=[]):array{return array_replace([
	'master_key'=>str_repeat('o',48),
	'clock'=>static fn():string=>'2026-07-16T16:00:00Z',
	'policy_bundles'=>[['id'=>'console_operators','version'=>'1.0.0','rules'=>['allow_all'=>['effect'=>'allow','abilities'=>['*'],'priority'=>100,'reason'=>'Console test operator.']]]],
	'fabric_handlers'=>[['pattern'=>'demo.*','contributor'=>'test','handler'=>static fn(PanelCommandEnvelope $command):PanelCommandOutcome=>PanelCommandOutcome::make(
		['accepted'=>true,'receipt_secret'=>'receipt-secret-value'],
		[new PanelEventDraft('demo.executed','order','Order:1',['event_secret'=>'event-secret-value'],['projection_secret'=>'metadata-secret-value'])],
	)]],
],$overrides);}

/** @return array<string,false> */
function dp_panel_console_disabled_domains():array{return array_fill_keys(['operations','distributed_operations','migrations','observability','data','workflows','automation','authentication','iam','studio','notifications','media','localization','preferences','collaboration','relations','security','development','extensions'],false);}

test('Operations console projects bounded tenant-safe metadata and never leaks subsystem payloads',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-redaction'),dp_panel_console_config());$console=new PanelOperationsConsole($os,2);
	$os->workGraph()->create('Tenant:A',['id'=>'Case:A','title'=>'Only tenant A','assignee'=>'Agent:A','data'=>['api_key'=>'work-secret-value']],'Actor:A','work-a');
	$os->workGraph()->create('Tenant:B',['id'=>'Case:B','title'=>'Only tenant B','data'=>['password'=>'work-secret-b']],'Actor:B','work-b');
	$command=new PanelCommandEnvelope('demo.execute','demo.execute','Tenant:A','Actor:A','idempotency-secret-value',['api_key'=>'input-secret-value'],'high',['operator'],['demo.*'],'Correlation:A',null,null,['lease_token'=>'lease-secret-value']);
	$t->isTrue($os->commandFabric()->dispatch($command)->ok());
	$snapshot=$console->snapshot('Tenant:A',['limit'=>99]);$encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);
	$t->same(2,$snapshot['limits']['requested']);$t->same(2,$snapshot['limits']['maximum']);$t->isTrue($snapshot['work']['scoped']);$t->same('Only tenant A',$snapshot['work']['queue'][0]['title']);
	$t->notContains('Only tenant B',$encoded);$t->notContains('work-secret-value',$encoded);$t->notContains('work-secret-b',$encoded);$t->notContains('input-secret-value',$encoded);$t->notContains('idempotency-secret-value',$encoded);$t->notContains('event-secret-value',$encoded);$t->notContains('metadata-secret-value',$encoded);$t->notContains('lease-secret-value',$encoded);$t->notContains('receipt-secret-value',$encoded);
	$t->same(['id','type','title','state','priority','queue','assignee','subject_type','subject_id','due_at','tags','version','created_at','updated_at'],array_keys($snapshot['work']['queue'][0]));
	$t->same(['id','sequence','event_type','aggregate_type','aggregate_id','tenant_id','actor_hash','correlation_id','occurred_at','digest'],array_keys($snapshot['fabric']['event_stream'][0]));
	$t->same(['id','command','ability','tenant_id','actor_hash','risk','status','attempts','correlation_id','created_at','updated_at','event_count','error_code'],array_keys($snapshot['fabric']['journal'][0]));
	$global=$console->snapshot();$t->isFalse($global['work']['scoped']);$t->same([],$global['work']['queue']);$t->isFalse($global['security']['tenant_scope_explicit']);
})->tag('panel','operations-os','console','redaction','tenancy')->isolation('case')->maxMillis(12000);

test('Operations console exposes crash recovery and durable projector controls without lease internals',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-maintenance'),dp_panel_console_config());$console=new PanelOperationsConsole($os);
	$os->commandFabric()->subscribe('search_projection','demo.*',static fn():bool=>true);
	$os->commandFabric()->dispatch(new PanelCommandEnvelope('demo.execute','demo.execute','Tenant:A','Actor:A','project-one'));
	$drain=$console->drainSubscriber('search_projection',10);$t->isTrue($drain['ok']);$t->same(1,$drain['processed']);$t->same(1,$drain['cursor']);$t->isFalse(array_key_exists('lease',$drain));
	$recovery=$console->recoverStale(300,10);$t->same([],$recovery['resumed']);$t->same(0,$recovery['error_count']);
	$snapshot=$console->snapshot('Tenant:A');$t->same('search_projection',$snapshot['fabric']['subscribers'][0]['name']);$t->same(1,$snapshot['fabric']['subscribers'][0]['cursor']);
})->tag('panel','operations-os','console','recovery','subscribers')->isolation('case')->maxMillis(12000);

test('first-party Operations OS commands are policy-gated ability-bound and evented',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-controls'),dp_panel_console_config());$console=new PanelOperationsConsole($os);$tenant='Tenant:A';$actor='Actor:A';
	$policy=static fn(string $ability,string $type,string $id):PanelPolicyRequest=>new PanelPolicyRequest('Actor:A',$ability,'Tenant:A',$type,$id,'high',['operator'],['*']);
	$os->releases()->ring('canary',10,1000,[],$policy('release.ring.configure','release_ring','canary'));
	$pause=$console->dispatch(new PanelCommandEnvelope('operations_os.release.pause','release.ring.pause',$tenant,$actor,'pause-canary',['ring'=>'canary','paused'=>true],'high',['operator'],['*']));
	$t->isTrue($pause->ok());$t->isTrue($os->releases()->jsonSerialize()['rings']['canary']['paused']);
	$engage=$console->dispatch(new PanelCommandEnvelope('operations_os.policy.engage','operations_os.policy.engage',$tenant,$actor,'engage-demo',['pattern'=>'demo.*'],'critical',['operator'],['*']));$t->isTrue($engage->ok());$t->isTrue(in_array('demo.*',$os->policy()->jsonSerialize()['kill_switches'],true));
	$release=$console->dispatch(new PanelCommandEnvelope('operations_os.policy.release','operations_os.policy.release',$tenant,$actor,'release-demo',['pattern'=>'demo.*'],'critical',['operator'],['*']));$t->isTrue($release->ok());$t->isFalse(in_array('demo.*',$os->policy()->jsonSerialize()['kill_switches'],true));
	$digest=hash('sha256','policy-v2');$desired=$console->dispatch(new PanelCommandEnvelope('operations_os.federation.desired','operations_os.federation.desired',$tenant,$actor,'federation-v2',['desired'=>['policy'=>$digest]],'high',['operator'],['*']));$t->isTrue($desired->ok());$t->same($digest,$os->federation()->jsonSerialize()['desired_state']['policy']);
	$remote=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-federation-remote'),dp_panel_console_config(['federation_node_id'=>'remote']));$transport=new PanelCallbackFederationTransport('console_test',static fn(array $wire):array=>$remote->federationGateway()->receive($wire));$local=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-federation-local'),dp_panel_console_config(['federation_node_id'=>'local','federation_transport'=>$transport]));$localConsole=new PanelOperationsConsole($local);$remoteDigest=hash('sha256','policy-v3');
	$queued=$localConsole->dispatch(new PanelCommandEnvelope('operations_os.federation.push_desired','federation.transport.send',$tenant,$actor,'federation-push-v3',['target_node'=>'remote','desired'=>['policy'=>$remoteDigest],'immediate'=>false],'high',['operator'],['*']));$t->isTrue($queued->ok());$queuedSnapshot=$localConsole->snapshot($tenant);$t->same(1,$queuedSnapshot['fleet']['federation']['gateway']['outbox_statuses']['pending']);$t->same([],$remote->federation()->jsonSerialize()['desired_state']);$t->isTrue($queuedSnapshot['fleet']['federation']['gateway']['recent_outbox'][0]['payload_redacted']);
	$flushed=$localConsole->dispatch(new PanelCommandEnvelope('operations_os.federation.flush','federation.transport.flush',$tenant,$actor,'federation-flush-v3',['limit'=>10,'stale_after_seconds'=>300],'high',['operator'],['*']));$t->isTrue($flushed->ok());$t->same($remoteDigest,$remote->federation()->jsonSerialize()['desired_state']['policy']);$deliveredSnapshot=$localConsole->snapshot($tenant);$t->same(1,$deliveredSnapshot['fleet']['federation']['gateway']['outbox_statuses']['delivered']);$t->same('delivered',$deliveredSnapshot['fleet']['federation']['gateway']['recent_outbox'][0]['status']);$federationHtml=PanelPlatformTemplate::operationsOs($deliveredSnapshot,['action_url'=>'/operations-os','control_tenant'=>$tenant])->content();$t->contains('Federation transport',$federationHtml);$t->contains('Flush federation outbox',$federationHtml);$t->contains('value="operations_os.federation.flush"',$federationHtml);
	$mismatch=$console->dispatch(new PanelCommandEnvelope('operations_os.release.pause','operations_os.policy.engage',$tenant,$actor,'ability-mismatch',['ring'=>'canary','paused'=>false],'high',['operator'],['*']));$t->isFalse($mismatch->ok());$t->same('failed',$mismatch->status());$t->isTrue($os->releases()->jsonSerialize()['rings']['canary']['paused']);
	$routes=$os->commandFabric()->registry()->jsonSerialize()['routes'];$t->isTrue(isset($routes['operations_os.*']));$t->same('operations_os.control',$routes['operations_os.*'][0]['contributor']);$t->instanceOf(PanelOperationsOsFabricHandler::class,new PanelOperationsOsFabricHandler($os));
})->tag('panel','operations-os','console','command-fabric','policy')->isolation('case')->maxMillis(12000);

test('Operations OS controller independently enforces authentication authorization CSRF tenant and command scope',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-controller'),dp_panel_console_config());$console=new PanelOperationsConsole($os);$controller=(new PanelPlatformController())->authorize(static fn():bool=>true)->csrf(static fn():bool=>true);
	$user=['id'=>'Actor:A','tenant_id'=>'Tenant:A','roles'=>['operator'],'permissions'=>['*']];
	$read=$controller->operationsOs($console,['action_url'=>'/operations-os'],PanelRequest::fromArray(['method'=>'GET','tenant'=>'Tenant:A','user'=>$user]));$t->same(200,$read->status());$t->contains('Operations OS',$read->content());$t->contains('data-dp-panel-kind="operations_os_console"',$read->content());
	$inherited=$controller->operationsOs($console,[],PanelRequest::fromArray(['method'=>'GET','user'=>$user]));$t->same(200,$inherited->status());$t->same('Tenant:A',$inherited->data()['operations_os']['tenant_id']);
	$unauth=$controller->operationsOs($console,[],PanelRequest::fromArray(['method'=>'GET']));$t->same(401,$unauth->status());
	$csrf=(new PanelPlatformController())->authorize(static fn():bool=>true)->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>['operation'=>'recover_stale']]));$t->same(419,$csrf->status());
	$post=PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>['operation'=>'dispatch','command'=>'operations_os.policy.engage','ability'=>'operations_os.policy.engage','idempotency_key'=>'controller-engage','risk'=>'critical','input'=>['pattern'=>'catalog.*']]]);$response=$controller->operateOperationsOs($console,$post);$t->same(200,$response->status());$payload=json_decode($response->content(),true,512,JSON_THROW_ON_ERROR);$t->isTrue($payload['ok']);
	$mismatch=$controller->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>['operation'=>'dispatch','command'=>'operations_os.policy.release','ability'=>'operations_os.policy.release','tenant_id'=>'Tenant:B','idempotency_key'=>'mismatch','input'=>['pattern'=>'catalog.*']]]));$t->same(403,$mismatch->status());
	$implicitMismatch=$controller->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','user'=>$user,'input'=>['operation'=>'dispatch','command'=>'operations_os.policy.release','ability'=>'operations_os.policy.release','tenant_id'=>'Tenant:B','idempotency_key'=>'implicit-mismatch','input'=>['pattern'=>'catalog.*']]]));$t->same(403,$implicitMismatch->status());
	$blocked=$controller->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>['operation'=>'dispatch','command'=>'demo.execute','ability'=>'demo.execute','idempotency_key'=>'blocked','input'=>[]]]));$t->same(403,$blocked->status());
	$allowed=$controller->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>['operation'=>'dispatch','command'=>'demo.execute','ability'=>'demo.execute','idempotency_key'=>'allowed','input'=>[]]]),['allowed_commands'=>['demo.*']]);$t->same(200,$allowed->status());
})->tag('panel','operations-os','console','controller','security')->isolation('case')->maxMillis(12000);

test('Operations OS controller contains maintenance read and governed rejection failures at the HTTP boundary',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-controller-boundaries'),dp_panel_console_config());$console=new PanelOperationsConsole($os);$controller=(new PanelPlatformController())->authorize(static fn():bool=>true)->csrf(static fn():bool=>true);
	$user=['id'=>'Actor:A','tenant_id'=>'Tenant:A','roles'=>['operator'],'permissions'=>['*'],'mfa_level'=>2,'trusted_session'=>true];
	$post=static fn(array $input):PanelRequest=>PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user,'input'=>$input]);

	$invalidRead=$controller->operationsOs($console,[],PanelRequest::fromArray(['method'=>'GET','tenant'=>'Tenant A','user'=>['id'=>'Actor:A','tenant_id'=>'Tenant A','permissions'=>['*']]]));
	$t->same(422,$invalidRead->status());$t->same('invalid_operations_os_console_request',json_decode($invalidRead->content(),true,512,JSON_THROW_ON_ERROR)['error']['code']);

	$recovery=$controller->operateOperationsOs($console,$post(['operation'=>'recover_stale','stale_after_seconds'=>300,'limit'=>10]));
	$t->same(200,$recovery->status());$recoveryPayload=json_decode($recovery->content(),true,512,JSON_THROW_ON_ERROR);$t->same('committed',$recoveryPayload['status']);$t->same([],$recoveryPayload['result']['resumed']);

	$os->commandFabric()->subscribe('controller_projection','demo.*',static fn():bool=>true);
	$os->commandFabric()->dispatch(new PanelCommandEnvelope('demo.execute','demo.execute','Tenant:A','Actor:A','controller-drain'));
	$drain=$controller->operateOperationsOs($console,$post(['operation'=>'drain_subscriber','subscriber'=>'controller_projection','limit'=>10]));
	$t->same(200,$drain->status());$drainPayload=json_decode($drain->content(),true,512,JSON_THROW_ON_ERROR);$t->isTrue($drainPayload['ok']);$t->same(1,$drainPayload['result']['processed']);

	$invalidDrain=$controller->operateOperationsOs($console,$post(['operation'=>'drain_subscriber','subscriber'=>' ']));
	$t->same(409,$invalidDrain->status());$t->same('conflict',json_decode($invalidDrain->content(),true,512,JSON_THROW_ON_ERROR)['error']['code']);

	$innerStore=new PanelFilesystemCommandFabricStore($t->tempDirectory('operations-console-controller-fault-store'));
	$faultStore=new class($innerStore)implements PanelCommandFabricStore{public bool$failPayload=false;public function __construct(private readonly PanelCommandFabricStore$inner){}public function payload():array{if($this->failPayload){throw new RuntimeException('Simulated command store outage.');}return$this->inner->payload();}public function transaction(callable$mutation,string$type,array$event=[]):array{return$this->inner->transaction($mutation,$type,$event);}public function changesSince(int$cursor=0,int$limit=100):array{return$this->inner->changesSince($cursor,$limit);}};
	$faultOs=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-controller-fault-runtime'),dp_panel_console_config(['fabric_store'=>$faultStore]));$faultStore->failPayload=true;
	$fault=$controller->operateOperationsOs(new PanelOperationsConsole($faultOs),$post(['operation'=>'recover_stale']));
	$t->same(422,$fault->status());$t->same('invalid_operations_os_request',json_decode($fault->content(),true,512,JSON_THROW_ON_ERROR)['error']['code']);

	$policy=new PanelPolicyRequest('Actor:A','intelligence.console','Tenant:A',null,null,'high',['operator'],['*']);
	$signal=$console->observe('anomaly','Tenant:A','controller','order','SO-REJECT-1','Reject this recommendation.','high',9400,$policy,[],null,900);
	$proposal=$console->propose($signal->id(),'demo.execute','demo.execute',['order_id'=>'SO-REJECT-1'],'medium','Exercise the controller rejection branch.',$policy,'controller-reject-proposal');
	$rejected=$controller->operateOperationsOs($console,$post(['operation'=>'intelligence_reject','tenant_id'=>'Tenant:A','proposal_id'=>$proposal->id(),'reason'=>'Operator declined the recommendation.']));
	$t->same(200,$rejected->status());$rejectedPayload=json_decode($rejected->content(),true,512,JSON_THROW_ON_ERROR);$t->same('rejected',$rejectedPayload['result']['status']);
})->tag('panel','operations-os','console','controller','maintenance','rejection','failure-boundary','exact-coverage')->isolation('case')->maxMillis(15000);

test('Operations OS HTTP intelligence controls preserve approval separation exact dispatch and redaction',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-intelligence-http'),dp_panel_console_config());$console=new PanelOperationsConsole($os);$controller=(new PanelPlatformController())->authorize(static fn():bool=>true)->csrf(static fn():bool=>true);
	$user=static fn(string $id):array=>['id'=>$id,'tenant_id'=>'Tenant:A','roles'=>['operator'],'permissions'=>['*'],'mfa_level'=>2,'trusted_session'=>true];
	$post=static function(string $actor,array $input)use($controller,$console,$user):\Dataphyre\Panel\PanelPageResult{return$controller->operateOperationsOs($console,PanelRequest::fromArray(['method'=>'POST','tenant'=>'Tenant:A','user'=>$user($actor),'input'=>$input]));};
	$signalSecret='http-signal-evidence-private';$signalResponse=$post('Proposer:A',['operation'=>'intelligence_signal','tenant_id'=>'Tenant:A','kind'=>'anomaly','source'=>'process-miner','subject_type'=>'order','subject_id'=>'SO-HTTP-1','summary'=>'Repeated fulfillment retries.','severity'=>'high','confidence_basis_points'=>9700,'evidence'=>['trace'=>$signalSecret],'ttl_seconds'=>3600]);
	$t->same(200,$signalResponse->status());$signalPayload=json_decode($signalResponse->content(),true,512,JSON_THROW_ON_ERROR);$t->isTrue($signalPayload['ok']);$t->same('panel_intelligence_signal',$signalPayload['result']['type']);$t->isTrue($signalPayload['result']['evidence_redacted']);$t->notContains($signalSecret,$signalResponse->content());$signalId=$signalPayload['result']['id'];
	$inputSecret='http-command-input-private';$proposalResponse=$post('Proposer:A',['operation'=>'intelligence_propose','tenant_id'=>'Tenant:A','signal_id'=>$signalId,'command'=>'demo.execute','ability'=>'demo.execute','input'=>['order_id'=>'SO-HTTP-1','token'=>$inputSecret],'risk'=>'medium','reason'=>'Retry through the governed adapter.','requested_approvals'=>1,'idempotency_key'=>'http-proposal-one']);
	$t->same(200,$proposalResponse->status());$proposalPayload=json_decode($proposalResponse->content(),true,512,JSON_THROW_ON_ERROR);$t->same('awaiting_approval',$proposalPayload['result']['status']);$t->same(1,$proposalPayload['result']['required_approvals']);$t->isTrue($proposalPayload['result']['input_redacted']);$t->notContains($inputSecret,$proposalResponse->content());$proposalId=$proposalPayload['result']['id'];
	$selfApproval=$post('Proposer:A',['operation'=>'intelligence_approve','tenant_id'=>'Tenant:A','proposal_id'=>$proposalId]);$t->same(409,$selfApproval->status());$t->same('conflict',json_decode($selfApproval->content(),true,512,JSON_THROW_ON_ERROR)['error']['code']);
	$approvalResponse=$post('Approver:B',['operation'=>'intelligence_approve','tenant_id'=>'Tenant:A','proposal_id'=>$proposalId]);$t->same(200,$approvalResponse->status());$approvalPayload=json_decode($approvalResponse->content(),true,512,JSON_THROW_ON_ERROR);$t->same('panel_intelligence_approval',$approvalPayload['result']['type']);$t->isFalse($approvalPayload['result']['approver_identity_exposed']);$t->notContains('Approver:B',$approvalResponse->content());
	$page=$controller->operationsOs($console,['action_url'=>'/operations-os','control_tenant'=>'Tenant:A','csrf_name'=>'_token','csrf_token'=>str_repeat('I',32)],PanelRequest::fromArray(['method'=>'GET','tenant'=>'Tenant:A','user'=>$user('Approver:B')]));$t->same(200,$page->status());$t->contains($proposalId,$page->content());$t->contains('Confirm &amp; dispatch',$page->content());$t->contains('value="intelligence_dispatch"',$page->content());$t->notContains($signalSecret,$page->content());$t->notContains($inputSecret,$page->content());$t->isTrue(PanelAccessibilityAudit::from($page)->passed());
	$dispatchResponse=$post('Dispatcher:C',['operation'=>'intelligence_dispatch','tenant_id'=>'Tenant:A','proposal_id'=>$proposalId,'confirmed'=>'1']);$t->same(200,$dispatchResponse->status());$dispatchPayload=json_decode($dispatchResponse->content(),true,512,JSON_THROW_ON_ERROR);$t->isTrue($dispatchPayload['ok']);$t->same('succeeded',$dispatchPayload['status']);$t->same(1,$dispatchPayload['receipt']['event_count']);$t->notContains($inputSecret,$dispatchResponse->content());
	$feedbackSecret='http-feedback-evidence-private';$feedbackResponse=$post('Observer:D',['operation'=>'intelligence_feedback','tenant_id'=>'Tenant:A','proposal_id'=>$proposalId,'outcome'=>'positive','effectiveness_basis_points'=>9300,'evidence'=>['measurement'=>$feedbackSecret],'idempotency_key'=>'http-feedback-one']);$t->same(200,$feedbackResponse->status());$feedbackPayload=json_decode($feedbackResponse->content(),true,512,JSON_THROW_ON_ERROR);$t->same('panel_intelligence_feedback',$feedbackPayload['result']['type']);$t->isTrue($feedbackPayload['result']['evidence_redacted']);$t->notContains($feedbackSecret,$feedbackResponse->content());
	$recovery=$post('Recovery:E',['operation'=>'intelligence_recover','tenant_id'=>'Tenant:A','stale_after_seconds'=>300,'limit'=>10]);$t->same(200,$recovery->status());$t->same([],json_decode($recovery->content(),true,512,JSON_THROW_ON_ERROR)['result']['resumed']);
	$mismatch=$post('Observer:D',['operation'=>'intelligence_feedback','tenant_id'=>'Tenant:B','proposal_id'=>$proposalId,'outcome'=>'positive','effectiveness_basis_points'=>9300,'idempotency_key'=>'mismatch']);$t->same(403,$mismatch->status());
	$snapshot=$console->snapshot('Tenant:A');$t->same(1,$snapshot['intelligence']['closed_loop']['proposal_count']);$t->same(1,$snapshot['intelligence']['closed_loop']['feedback_count']);$t->same('succeeded',$snapshot['intelligence']['closed_loop']['proposals'][0]['status']);$encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);$t->notContains($signalSecret,$encoded);$t->notContains($inputSecret,$encoded);$t->notContains($feedbackSecret,$encoded);
})->tag('panel','operations-os','console','controller','intelligence','closed-loop','security','accessibility')->isolation('case')->maxMillis(18000);

test('Operations OS template is accessible responsive flat and theme-token driven',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-template'),dp_panel_console_config());$os->workGraph()->create('Tenant:A',['id'=>'Case:A','title'=>'Review release','priority'=>90],'Actor:A','case-a');$snapshot=(new PanelOperationsConsole($os))->snapshot('Tenant:A');
	$result=PanelPlatformTemplate::operationsOs($snapshot,['action_url'=>'/operations-os','control_tenant'=>'Tenant:A','csrf_name'=>'_token','csrf_token'=>str_repeat('C',32)]);$html=$result->content();$css=PanelPlatformAssets::stylesheet();
	$t->isTrue(PanelAccessibilityAudit::from($result)->passed());$t->contains('dp-ops-anchor-nav',$html);$t->contains('dp-ops-strip',$html);$t->contains('Command journal',$html);$t->contains('Universal work queue',$html);$t->contains('value="recover_stale"',$html);$t->notContains('—',$html);
	$t->contains('name="_token" value="'.str_repeat('C',32).'"',$html);$t->contains('min-block-size:2.75rem',$css);$t->contains('justify-self:start',$css);
	$t->contains('@container (max-width:52rem)',$css);$t->contains('.dp-panel-table-shell{overflow:clip}',$css);$t->contains('@container (max-width:26rem)',$css);$t->contains('forced-colors:active',$css);$t->contains('prefers-reduced-motion:reduce',$css);$t->contains('[dir="rtl"]',$css);$t->contains('var(--dp-text',$css);$t->notContains('100vh',$css);$t->notContains('position:sticky',$css);
})->tag('panel','operations-os','console','template','accessibility','responsive')->isolation('case')->maxMillis(12000);

test('platform manifest pages and Panel instance bind the console to the exact Operations OS runtime',static function(Context $t):void{
	$config=['state_root'=>$t->tempDirectory('operations-console-platform')]+dp_panel_console_disabled_domains();$config['operations_os']=dp_panel_console_config();$config['platform']=['authorize'=>static fn():bool=>true,'csrf'=>static fn():bool=>true];$platform=PanelPlatform::defaults($config);$runtime=$platform->operationsOs();
	$t->same($runtime,$platform->operationsConsole()->operationsOs());$t->same($runtime->federationGateway(),$platform->federationGateway());$domain=$platform->manifest()->domain('operations_os');$t->isTrue($domain['ready']);$t->isTrue($domain['features']['console']);$t->isTrue($domain['features']['control_handler']);$t->isTrue($domain['features']['federation_gateway']);$t->isTrue(in_array('operations_os.federation_gateway',$domain['services'],true));$t->same([],$domain['cohesion']['mismatches']);
	$pages=$platform->pages(['domains'=>['operations_os']]);$t->same(['platform_operations_os'],array_keys($pages));$rendered=$pages['platform_operations_os']->render(PanelRequest::fromArray(['method'=>'GET','tenant'=>'Tenant:A','user'=>['id'=>'Actor:A','tenant_id'=>'Tenant:A']]));$t->same(200,$rendered->status());$t->contains('Operations OS',$rendered->content());
	$surface=Panel::make('operations-console')->usePlatform($platform);$t->same($platform->operationsConsole(),$surface->operationsConsole());
	$other=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-split'),dp_panel_console_config());$platform->register('operations_os.console',new PanelOperationsConsole($other),true);$split=$platform->manifest()->domain('operations_os');$t->isFalse($split['ready']);$t->same(['operations_os.console.runtime'],$split['cohesion']['mismatches']);
})->tag('panel','operations-os','console','platform','cohesion')->isolation('case')->maxMillis(15000);

test('console snapshots isolate subsystem failures instead of taking down the operator surface',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-failure-isolation'),dp_panel_console_config(['clock'=>static fn():array=>[]]));$snapshot=(new PanelOperationsConsole($os))->snapshot();
	$t->isFalse($snapshot['status']['available']);$t->same('runtime_status_unavailable',$snapshot['status']['error_code']);$t->isTrue($snapshot['policy']['available']);$t->isTrue($snapshot['intelligence']['available']);$t->isFalse($snapshot['domains']['available']);$t->isFalse($snapshot['fleet']['available']);$t->isTrue(count($snapshot['attention'])>=2);
	$encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);$t->notContains('UnexpectedValueException',$encoded);$t->notContains('Operations OS clock must return',$encoded);
})->tag('panel','operations-os','console','failure-isolation')->isolation('case')->maxMillis(12000);

test('console manifest domain degradation release rollback and invalid control values are exhaustively covered',static function(Context $t):void{
	$master=str_repeat('o',48);$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-exhaustive'),dp_panel_console_config(['master_key'=>$master]));$console=new PanelOperationsConsole($os,10);
	$t->throws(static fn()=>new PanelOperationsConsole($os,0),InvalidArgumentException::class);$manifest=$console->jsonSerialize();$t->same('panel_operations_console_manifest',$manifest['type']);$t->same(10,$manifest['maximum_limit']);$t->isTrue($manifest['capabilities']['governed_command_dispatch']);
	$os->installDomain(['id'=>'console_domain','version'=>'1.0.0','entities'=>['record'=>['primary_key'=>'id','fields'=>['id'=>['type'=>'uuid','required'=>true]]]]]);$domains=$console->snapshot('Tenant:A')['domains'];$t->same(1,$domains['installed_count']);$t->same('console_domain',$domains['items'][0]['id']);$t->isTrue($domains['items'][0]['trusted']);
	$t->nonPublic($os)->writeProperty('compilationHistory',[]);$degraded=$console->snapshot('Tenant:A')['domains'];$t->same(1,$degraded['drifted_count']);$t->same(['projection_unavailable'],$degraded['items'][0]['drift_channels']);$t->isFalse($degraded['items'][0]['trusted']);
	$request=new PanelPolicyRequest('Actor:A','release.deploy','Tenant:A','release','canary','critical',['operator'],['*']);$releaseKey=hash_hmac('sha256','dataphyre.panel.operations-os.release',$master,true);$at='2026-07-16T16:00:00Z';$sbom=[['name'=>'dataphyre','version'=>'1.0.0']];
	$one=PanelReleaseArtifact::sign('console_v1','1.0.0',['code'=>hash('sha256','console-v1')],$sbom,['builder'=>'console-test'],$at,'primary',$releaseKey);$two=PanelReleaseArtifact::sign('console_v2','2.0.0',['code'=>hash('sha256','console-v2')],$sbom,['builder'=>'console-test'],$at,'primary',$releaseKey);
	$os->releases()->register($one,$request)->register($two,$request)->ring('canary',10,1000,[],$request);$os->releases()->deploy('console_v1','canary',[],$request,'deploy-console-v1');$os->releases()->deploy('console_v2','canary',[],$request,'deploy-console-v2');
	$rollback=$console->dispatch(new PanelCommandEnvelope('operations_os.release.rollback','release.rollback','Tenant:A','Actor:A','rollback-console',['ring'=>'canary'],'critical',['operator'],['*']));$t->isTrue($rollback->ok());$t->same(1,count($rollback->eventIds()));$events=$console->snapshot('Tenant:A')['fabric']['event_stream'];$t->same('operations_os.release.rolled_back',$events[array_key_last($events)]['event_type']);
	$invalid=$console->dispatch(new PanelCommandEnvelope('operations_os.release.pause','release.ring.pause','Tenant:A','Actor:A','pause-invalid-boolean',['ring'=>'canary','paused'=>'not-a-boolean'],'high',['operator'],['*']));$t->isFalse($invalid->ok());$t->same('failed',$invalid->status());
})->tag('panel','operations-os','console','coverage','release','domain')->isolation('case')->maxMillis(15000);

test('Operations OS fabric executes releases recovers workers and queues heartbeat and reconciliation controls',static function(Context $t):void{
	$master=str_repeat('f',48);$adapter=new PanelCallbackReleaseDeploymentAdapter('fabric_deployer',static fn(string $phase,array $context):array=>['ok'=>true,'code'=>$phase.'_complete']);
	$config=dp_panel_console_config([
		'master_key'=>$master,'release_deployment_adapter'=>$adapter,
		'fabric_handlers'=>['mapped.*'=>['contributor'=>'mapped-test','priority'=>3,'handler'=>static fn(PanelCommandEnvelope $command):PanelCommandOutcome=>PanelCommandOutcome::make(['mapped'=>true])]],
	]);
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-fabric-controls'),$config);$console=new PanelOperationsConsole($os);$tenant='Tenant:A';$actor='Actor:A';
	$policy=new PanelPolicyRequest($actor,'release.execute',$tenant,'release','fabric_v1','critical',['operator'],['*']);$releaseKey=hash_hmac('sha256','dataphyre.panel.operations-os.release',$master,true);$at='2026-07-16T16:00:00Z';
	$artifact=PanelReleaseArtifact::sign('fabric_v1','1.0.0',['code'=>hash('sha256','fabric-v1')],[['name'=>'dataphyre','version'=>'1.0.0']],['builder'=>'fabric-test'],$at,'primary',$releaseKey);$os->releases()->register($artifact,$policy)->ring('canary',10,1000,[],$policy);
	$executed=$console->dispatch(new PanelCommandEnvelope('operations_os.release.execute','release.execute',$tenant,$actor,'fabric-release-execute',['artifact_id'=>'fabric_v1','ring'=>'canary','health'=>[]],'critical',['operator'],['*']));$t->isTrue($executed->ok());$t->same('active',$executed->result()['status']);$t->same('release_execute',$executed->result()['operation']);
	$recovered=$console->dispatch(new PanelCommandEnvelope('operations_os.release.recover','release.execute.recover',$tenant,$actor,'fabric-release-recover',['stale_after_seconds'=>0,'limit'=>10],'critical',['operator'],['*']));$t->isTrue($recovered->ok());$t->same(0,$recovered->result()['resumed_count']);

	$federationKey=hash_hmac('sha256','dataphyre.panel.operations-os.federation',$master,true);$node=PanelFederationNode::sign('local','production','ca',1,'2026-07-16T15:59:00Z','2026-07-16T16:10:00Z',['sync'],['policy'=>hash('sha256','policy')],[],'primary',$federationKey);
	$heartbeat=$console->dispatch(new PanelCommandEnvelope('operations_os.federation.heartbeat','federation.transport.send',$tenant,$actor,'fabric-heartbeat',['target_node'=>'remote','node'=>$node->jsonSerialize(),'immediate'=>false],'high',['operator'],['*']));$t->isTrue($heartbeat->ok());$t->same('federation_heartbeat',$heartbeat->result()['operation']);$t->same('pending',$heartbeat->result()['status']);
	$reconcile=$console->dispatch(new PanelCommandEnvelope('operations_os.federation.reconcile','federation.transport.send',$tenant,$actor,'fabric-reconcile',['target_node'=>'remote','immediate'=>false],'high',['operator'],['*']));$t->isTrue($reconcile->ok());$t->same('federation_reconcile',$reconcile->result()['operation']);
	$routes=$os->commandFabric()->registry()->jsonSerialize()['routes'];$t->same('mapped-test',$routes['mapped.*'][0]['contributor']);
})->tag('panel','operations-os','console','fabric','release','federation')->isolation('case')->maxMillis(18000);

test('Operations console directly records signed signals and rejects governed proposals',static function(Context $t):void{
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('operations-console-direct-intelligence'),dp_panel_console_config());$console=new PanelOperationsConsole($os);$request=new PanelPolicyRequest('Operator:A','intelligence.signal.record','Tenant:A','intelligence','signal','high',['operator'],['*']);
	$signal=$os->intelligence()->issueSignal('anomaly','Tenant:A','console','order','SO-DIRECT-1','Directly recorded anomaly.','high',9500,['trace'=>'private'],'2026-07-16T16:00:00Z',3600);$recorded=$console->recordSignal($signal,$request);$t->same($signal->id(),$recorded->id());
	$proposal=$console->propose($signal->id(),'demo.execute','demo.execute',['order_id'=>'SO-DIRECT-1'],'medium','Exercise the rejection path.',$request,'direct-proposal');$rejected=$console->rejectProposal($proposal->id(),'Operator rejected the recommendation.',$request);$t->same('rejected',$rejected->status());
})->tag('panel','operations-os','console','intelligence','rejection')->isolation('case')->maxMillis(12000);
