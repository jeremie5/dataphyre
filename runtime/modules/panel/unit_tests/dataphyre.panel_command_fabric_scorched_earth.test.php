<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\AutomationAction;
use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\InMemoryPanelAgentWorkflowStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\PanelAgentFabricHandler;
use Dataphyre\Panel\PanelAgentPlan;
use Dataphyre\Panel\PanelAgentPolicyDecision;
use Dataphyre\Panel\PanelAgentPolicyEngine;
use Dataphyre\Panel\PanelAgentPolicyResolver;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentRuntime;
use Dataphyre\Panel\PanelAgentIntentSigner;
use Dataphyre\Panel\PanelAgentTool;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelAgentToolExecutionRequest;
use Dataphyre\Panel\PanelAgentToolExecutionResult;
use Dataphyre\Panel\PanelAgentToolExecutor;
use Dataphyre\Panel\PanelAutomationFabricHandler;
use Dataphyre\Panel\PanelAttestedCommandObligationVerifier;
use Dataphyre\Panel\PanelCommandApprovalAttestation;
use Dataphyre\Panel\PanelCommandEnvelope;
use Dataphyre\Panel\PanelCommandFabric;
use Dataphyre\Panel\PanelCommandFabricLeaseLost;
use Dataphyre\Panel\PanelCommandFabricState;
use Dataphyre\Panel\PanelCommandFabricStore;
use Dataphyre\Panel\PanelCommandFabricSubscriberLease;
use Dataphyre\Panel\PanelCommandObligationResult;
use Dataphyre\Panel\PanelCommandObligationVerifier;
use Dataphyre\Panel\PanelCommandOutcome;
use Dataphyre\Panel\PanelCommandRegistry;
use Dataphyre\Panel\PanelComplianceFabricProjector;
use Dataphyre\Panel\PanelComplianceLedger;
use Dataphyre\Panel\PanelDelegatingDomainFabricHandler;
use Dataphyre\Panel\PanelDomainCommandDefinition;
use Dataphyre\Panel\PanelDomainCommandExecutor;
use Dataphyre\Panel\PanelDomainCommandInvocation;
use Dataphyre\Panel\PanelDomainFabricCommandExecutor;
use Dataphyre\Panel\PanelEncryptedCommandPayloadCodec;
use Dataphyre\Panel\PanelEventDraft;
use Dataphyre\Panel\PanelFilesystemCommandFabricStore;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelIamFabricHandler;
use Dataphyre\Panel\PanelIamManager;
use Dataphyre\Panel\PanelMemoryIamStore;
use Dataphyre\Panel\PanelInMemoryCommandFabricStore;
use Dataphyre\Panel\PanelInMemoryNotificationAdapter;
use Dataphyre\Panel\PanelInMemoryRealtimeBroker;
use Dataphyre\Panel\PanelLeasedCommandFabricStore;
use Dataphyre\Panel\PanelLocalOperationQueue;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelNotificationFabricProjector;
use Dataphyre\Panel\PanelOperationFabricHandler;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationsOs;
use Dataphyre\Panel\PanelPolicyBundle;
use Dataphyre\Panel\PanelPolicyControlPlane;
use Dataphyre\Panel\PanelPolicyDecision;
use Dataphyre\Panel\PanelRealtimeFabricProjector;
use Dataphyre\Panel\PanelStrictCommandObligationVerifier;
use Dataphyre\Panel\PanelSynchronousOperationRunner;
use Dataphyre\Panel\PanelTenantFabricHandler;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelWorkflowFabricHandler;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @param list<string> $abilities @param array<string,mixed> $obligations */
function dp_panel_fabric_policy(array $abilities=['*'],array $obligations=[]):array {
	$policyKey=str_repeat('P',48);
	$policy=new PanelPolicyControlPlane(['policy'=>$policyKey],true);
	$bundle=PanelPolicyBundle::from([
		'id'=>'fabric_allow','version'=>'1.0.0','rules'=>[
			'allow'=>['effect'=>'allow','abilities'=>$abilities,'priority'=>100,'obligations'=>$obligations,'reason'=>'Fabric test operation allowed.'],
		],
	])->sign('policy',$policyKey);
	$policy->register($bundle);
	return [$policy,$policyKey];
}

/** @param list<string> $abilities */
function dp_panel_fabric_fixture(array $abilities=['*'],?object $store=null,?PanelCommandObligationVerifier $obligations=null,?callable $clock=null):array {
	[$policy]=$policyFixture=dp_panel_fabric_policy($abilities);
	$registry=new PanelCommandRegistry();
	$store??=new PanelInMemoryCommandFabricStore();
	$key=str_repeat('F',48);
	$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48),static fn():string=>str_repeat('N',12));
	$fabric=new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>$key],'fabric',$obligations,$clock);
	return compact('fabric','registry','store','policy','key','codec','policyFixture');
}

/** @return array<string,mixed> */
function dp_panel_fabric_executing_state(PanelCommandEnvelope $command,PanelEncryptedCommandPayloadCodec $codec,string $updatedAt='2026-07-16T12:00:00.000000Z'):array {
	$hash=$command->idempotencyHash();$state=PanelCommandFabricState::initial();$state['revision']=1;
	$state['commands'][$hash]=[
		'fingerprint'=>$command->fingerprint(),'status'=>'executing','envelope'=>$command->jsonSerialize(),
		'sealed'=>$codec->seal($command->sealedPayload(),'fabric.'.$hash.'.'.$command->fingerprint()),'attempts'=>1,'updated_at'=>$updatedAt,
	];
	return$state;
}

test('encrypted command envelopes are deterministic, tenant scoped, and fail closed on tamper',static function(Context $t):void {
	$t->throws(static fn()=>new PanelEncryptedCommandPayloadCodec('weak'),InvalidArgumentException::class);
	$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48),static fn():string=>str_repeat('N',12));
	$payload=['input'=>['email'=>'private@example.test'],'idempotency_key'=>'raw-idempotency-secret'];
	$sealed=$codec->seal($payload,'fabric.context');$t->notContains('private@example.test',json_encode($sealed,JSON_THROW_ON_ERROR));$t->notContains('raw-idempotency-secret',json_encode($sealed,JSON_THROW_ON_ERROR));
	$reordered=array_reverse($sealed,true);$opened=$codec->open($reordered,'fabric.context');$t->same($payload['input'],$opened['input']);$t->same($payload['idempotency_key'],$opened['idempotency_key']);
	$tampered=$sealed;$tampered['ciphertext']=base64_encode('tampered');$t->throws(static fn()=>$codec->open($tampered,'fabric.context'),UnexpectedValueException::class);$t->throws(static fn()=>$codec->open($sealed,'fabric.other'),UnexpectedValueException::class);
	$one=new PanelCommandEnvelope('orders.create','orders.create','tenant-a','actor','same-key',['value'=>1],createdAt:'2026-07-16T12:00:00Z');
	$later=new PanelCommandEnvelope('orders.create','orders.create','tenant-a','actor','same-key',['value'=>1],createdAt:'2026-07-17T12:00:00Z');
	$otherTenant=new PanelCommandEnvelope('orders.create','orders.create','tenant-b','actor','same-key',['value'=>1],createdAt:'2026-07-16T12:00:00Z');
	$t->same($one->fingerprint(),$later->fingerprint());$t->isFalse(hash_equals($one->idempotencyHash(),$otherTenant->idempotencyHash()));$t->notContains('same-key',json_encode($one,JSON_THROW_ON_ERROR));
	$authorized=$one->withEvidence(['approval'=>'sealed']);$t->same(['approval'=>'sealed'],$authorized->evidence());$t->isTrue(isset($authorized->sealedPayload()['evidence']));$t->same($one->executionTarget(),$authorized->executionTarget());
	$t->same($authorized->fingerprint(),PanelCommandEnvelope::hydrate($authorized->jsonSerialize(),$authorized->sealedPayload())->fingerprint());
});

test('dispatch atomically signs receipts and events, replays exactly once, and detects stored corruption',static function(Context $t):void {
	$fixture=dp_panel_fabric_fixture(['orders.*']);$runs=0;
	$fixture['registry']->register('orders.*',static function(PanelCommandEnvelope $command)use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(['created'=>true],[new PanelEventDraft('orders.created','order','order-1',['status'=>'created'])],['adapter'=>'test']);});
	$command=new PanelCommandEnvelope('orders.create','orders.create','tenant-a','actor-a','create-key',['email'=>'private@example.test'],roles:['operator'],permissions:['orders.*'],createdAt:'2026-07-16T12:00:00Z');
	$receipt=$fixture['fabric']->dispatch($command);$t->isTrue($receipt->ok());$t->same(1,$runs);$t->same(1,count($receipt->eventIds()));$t->isTrue($receipt->verify(['fabric'=>$fixture['key']]));
	$replay=$fixture['fabric']->dispatch(new PanelCommandEnvelope('orders.create','orders.create','tenant-a','actor-a','create-key',['email'=>'private@example.test'],roles:['operator'],permissions:['orders.*'],createdAt:'2026-07-17T12:00:00Z'));
	$t->isTrue($replay->replay());$t->same($receipt->digest(),$replay->digest());$t->same(1,$runs);$t->same(1,$fixture['fabric']->verifyIntegrity()['events']);
	$t->throws(static fn()=>$fixture['fabric']->dispatch(new PanelCommandEnvelope('orders.create','orders.create','tenant-a','actor-a','create-key',['email'=>'changed@example.test'])),LogicException::class);
	$serialized=json_encode($fixture['store']->payload(),JSON_THROW_ON_ERROR);$t->notContains('private@example.test',$serialized);$t->notContains('create-key',$serialized);
	$corrupt=$fixture['store']->payload();$eventId=array_key_first($corrupt['events']);$corrupt['events'][$eventId]['signature']=str_repeat('0',64);
	$t->throws(static fn()=>new PanelCommandFabric(new PanelCommandRegistry(),new PanelInMemoryCommandFabricStore($corrupt),$fixture['policy'],$fixture['codec'],['fabric'=>$fixture['key']],'fabric'),UnexpectedValueException::class);
	$corrupt=$fixture['store']->payload();$hash=$command->idempotencyHash();$corrupt['commands'][$hash]['sealed']['ciphertext']=base64_encode('tampered');
	$t->throws(static fn()=>new PanelCommandFabric(new PanelCommandRegistry(),new PanelInMemoryCommandFabricStore($corrupt),$fixture['policy'],$fixture['codec'],['fabric'=>$fixture['key']],'fabric'),UnexpectedValueException::class);
});

test('policy denials, unmet obligations, and handler failures produce signed secret-safe terminal receipts',static function(Context $t):void {
	[$denyPolicy]=dp_panel_fabric_policy(['unrelated.*']);$key=str_repeat('F',48);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48));$calls=0;$registry=new PanelCommandRegistry();$registry->register('orders.*',static function()use(&$calls):PanelCommandOutcome{$calls++;return PanelCommandOutcome::make(true);});
	$denied=new PanelCommandFabric($registry,new PanelInMemoryCommandFabricStore(),$denyPolicy,$codec,['fabric'=>$key],'fabric');$receipt=$denied->dispatch(new PanelCommandEnvelope('orders.delete','orders.delete','tenant','actor','deny-key'));
	$t->same('denied',$receipt->status());$t->same(0,$calls);$t->isTrue($receipt->verify(['fabric'=>$key]));$t->isTrue($denied->dispatch(new PanelCommandEnvelope('orders.delete','orders.delete','tenant','actor','deny-key'))->replay());
	[$obligationPolicy]=dp_panel_fabric_policy(['orders.*'],['confirmation'=>true,'approval_count'=>1]);$obligationFabric=new PanelCommandFabric($registry,new PanelInMemoryCommandFabricStore(),$obligationPolicy,$codec,['fabric'=>$key],'fabric',new PanelStrictCommandObligationVerifier());
	$obligation=$obligationFabric->dispatch(new PanelCommandEnvelope('orders.delete','orders.delete','tenant','actor','obligation-key',metadata:['confirmed'=>true]));$t->same('denied',$obligation->status());$t->contains('approval',strtolower($obligation->error()??''));$t->same(0,$calls);
	[$allowPolicy]=dp_panel_fabric_policy(['orders.*']);$failingRegistry=new PanelCommandRegistry();$failingRegistry->register('orders.*',static function():never{throw new RuntimeException('database password=never-leak');});$failing=new PanelCommandFabric($failingRegistry,new PanelInMemoryCommandFabricStore(),$allowPolicy,$codec,['fabric'=>$key],'fabric');
	$failure=$failing->dispatch(new PanelCommandEnvelope('orders.update','orders.update','tenant','actor','failure-key',['password'=>'never-leak']));$t->same('failed',$failure->status());$t->same('Command execution failed.',$failure->error());$t->notContains('never-leak',json_encode($failing->store()->payload(),JSON_THROW_ON_ERROR));
});

test('stale executions recover idempotently and subscriber cursors retry without event loss',static function(Context $t):void {
	[$policy]=dp_panel_fabric_policy(['orders.*']);$key=str_repeat('F',48);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48),static fn():string=>str_repeat('N',12));$command=new PanelCommandEnvelope('orders.recover','orders.recover','tenant','actor','recovery-key',['value'=>1],createdAt:'2026-07-16T12:00:00Z');
	$hash=$command->idempotencyHash();$state=PanelCommandFabricState::initial();$state['revision']=1;$state['commands'][$hash]=['fingerprint'=>$command->fingerprint(),'status'=>'executing','envelope'=>$command->jsonSerialize(),'sealed'=>$codec->seal($command->sealedPayload(),'fabric.'.$hash.'.'.$command->fingerprint()),'attempts'=>1,'updated_at'=>'2026-07-16T12:00:00.000000Z'];
	$runs=0;$registry=new PanelCommandRegistry();$registry->register('orders.*',static function()use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(['recovered'=>true],[new PanelEventDraft('orders.recovered','order','order-1')]);});
	$fabric=new PanelCommandFabric($registry,new PanelInMemoryCommandFabricStore($state),$policy,$codec,['fabric'=>$key],'fabric',clock:static fn():string=>'2026-07-16T12:10:00Z');
	$receipt=$fabric->resume($hash,300);$t->isTrue($receipt->ok());$t->same(1,$runs);$t->same(2,$fabric->store()->payload()['commands'][$hash]['attempts']);
	$deliveries=0;$fabric->subscribe('projection','orders.*',static function()use(&$deliveries):bool{return ++$deliveries>1;});
	$first=$fabric->drainSubscriber('projection');$t->isFalse($first['ok']);$t->same(0,$first['cursor']);$t->same(1,$first['retry_sequence']);
	$second=$fabric->drainSubscriber('projection');$t->isTrue($second['ok']);$t->same(1,$second['cursor']);$t->same(2,$deliveries);$t->same(0,$fabric->drainSubscriber('projection')['processed']);
});

test('filesystem restart recovery verifies signatures and never re-executes a completed command',static function(Context $t):void {
	$root=$t->tempDirectory('panel-command-fabric');[$policy]=dp_panel_fabric_policy(['orders.*']);$key=str_repeat('F',48);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',48),static fn():string=>str_repeat('N',12));$runs=0;
	$handler=static function()use(&$runs):PanelCommandOutcome{$runs++;return PanelCommandOutcome::make(['ok'=>true],[new PanelEventDraft('orders.persisted','order','order-1')]);};
	$firstRegistry=new PanelCommandRegistry();$firstRegistry->register('orders.*',$handler);$first=new PanelCommandFabric($firstRegistry,new PanelFilesystemCommandFabricStore($root),$policy,$codec,['fabric'=>$key],'fabric');$command=new PanelCommandEnvelope('orders.persist','orders.persist','tenant','actor','filesystem-secret-key',['email'=>'hidden@example.test']);$firstReceipt=$first->dispatch($command);$t->same(1,$runs);
	$secondRegistry=new PanelCommandRegistry();$secondRegistry->register('orders.*',$handler);$second=new PanelCommandFabric($secondRegistry,new PanelFilesystemCommandFabricStore($root),$policy,$codec,['fabric'=>$key],'fabric');$replay=$second->dispatch($command);$t->isTrue($replay->replay());$t->same($firstReceipt->digest(),$replay->digest());$t->same(1,$runs);
	$contents='';foreach(glob($root.'/*.json')?:[]as$file){$contents.=(string)file_get_contents($file);}$t->notContains('filesystem-secret-key',$contents);$t->notContains('hidden@example.test',$contents);$t->same(1,$second->verifyIntegrity()['events']);
});

test('native workflow automation operation domain and realtime runtimes share one fabric without shadow engines',static function(Context $t):void {
	$fixture=dp_panel_fabric_fixture(['workflow.*','automation.*','operation.*','domain.*']);$fabric=$fixture['fabric'];$registry=$fixture['registry'];
	$workflowDefinition=WorkflowDefinition::make('orders')->state('draft')->state('done',['terminal'=>true])->initial('draft')->transition(WorkflowTransition::make('complete','draft','done'));
	$workflows=new WorkflowEngine(new InMemoryWorkflowStore(),[$workflowDefinition]);$workflowHandler=new PanelWorkflowFabricHandler($workflows);$registry->register('workflow.*',$workflowHandler);$t->same('panel_workflow_fabric_handler',$workflowHandler->jsonSerialize()['type']);
	$workflow=$fabric->dispatch(new PanelCommandEnvelope('workflow.start','workflow.start','tenant','actor','workflow-key',['definition'=>'orders','id'=>'workflow-1','data'=>['value'=>1]]));$t->isTrue($workflow->ok());$t->isTrue($workflows->store()->load('orders','workflow-1')!==null);
	$automationRuns=0;$action=AutomationAction::make('ping')->requiresIdempotency()->handle(static function(array $input)use(&$automationRuns):array{$automationRuns++;return['pong'=>$input['value']??null];});$automation=new AutomationExecutor(new AutomationRegistry([$action]),new InMemoryAutomationStore());$automationHandler=new PanelAutomationFabricHandler($automation);$registry->register('automation.*',$automationHandler);$t->same('panel_automation_fabric_handler',$automationHandler->jsonSerialize()['type']);
	$automationReceipt=$fabric->dispatch(new PanelCommandEnvelope('automation.execute','automation.execute','tenant','actor','automation-key',['name'=>'ping','input'=>['value'=>7]]));$t->isTrue($automationReceipt->ok());$t->same(1,$automationRuns);
	$operationStore=new PanelFilesystemOperationStore($t->tempDirectory('panel-fabric-operations'));$operationHandlers=(new PanelOperationHandlerRegistry())->register('sum',static fn(array $payload):array=>['sum'=>array_sum($payload)]);$operationRunner=new PanelSynchronousOperationRunner($operationStore,$operationHandlers,new PanelLocalOperationQueue($operationStore));$operationHandler=new PanelOperationFabricHandler($operationRunner);$registry->register('operation.*',$operationHandler);$t->same('panel_operation_fabric_handler',$operationHandler->jsonSerialize()['type']);
	$submitted=$fabric->dispatch(new PanelCommandEnvelope('operation.submit','operation.submit','tenant','actor','operation-submit-key',['type'=>'sum','name'=>'Sum','payload'=>[2,3]]));$operationId=$submitted->result()['id']??null;$t->isTrue(is_string($operationId));$ran=$fabric->dispatch(new PanelCommandEnvelope('operation.run','operation.run','tenant','actor','operation-run-key',['id'=>$operationId]));$t->same('completed',$ran->result()['status']??null);
	$domainRuns=0;$delegate=new class($domainRuns) implements PanelDomainCommandExecutor {public function __construct(private int &$runs){}public function execute(PanelDomainCommandInvocation $invocation):mixed{$this->runs++;return['qualified'=>$invocation->command()->qualifiedName(),'value'=>$invocation->input()['value']??null];}};$domainHandler=new PanelDelegatingDomainFabricHandler($delegate);$registry->register('domain.*',$domainHandler);$t->same('panel_delegating_domain_fabric_handler',$domainHandler->jsonSerialize()['type']);$domainExecutor=new PanelDomainFabricCommandExecutor($fabric);$t->same('panel_domain_fabric_command_executor',$domainExecutor->jsonSerialize()['type']);$definition=PanelDomainCommandDefinition::from('commerce','1.0.0','close',['label'=>'Close','entity'=>'order','operation'=>'close','risk'=>'medium','input'=>['value'=>['type'=>'integer']]]);
	$domainResult=$domainExecutor->execute(new PanelDomainCommandInvocation($definition,'tenant','actor','domain-key',['value'=>9],'order-1'));$t->same('commerce.close',$domainResult['qualified']);$t->same(1,$domainRuns);
	$broker=new PanelInMemoryRealtimeBroker();$realtimeProjector=new PanelRealtimeFabricProjector($broker,'admin');$fabric->subscribe('realtime','*',$realtimeProjector);$t->same('panel_realtime_fabric_projector',$realtimeProjector->jsonSerialize()['type']);$projection=$fabric->drainSubscriber('realtime',100);$t->isTrue($projection['ok']);$t->isTrue($projection['processed']>=5);$t->isTrue($broker->jsonSerialize()['active_streams']>=1);
	$t->same($workflows,$workflows);$t->same($automation,$automation);$t->isTrue($fabric->verifyIntegrity()['events']>=5);
});

test('workflow and automation fabric handlers exercise every signed command variant and fail closed',static function(Context $t):void {
	$workflowDefinition=WorkflowDefinition::make('orders')->state('draft')->state('done',['terminal'=>true])->initial('draft')->transition(WorkflowTransition::make('complete','draft','done')->reversible(true));
	$workflowHandler=new PanelWorkflowFabricHandler(new WorkflowEngine(new InMemoryWorkflowStore(),[$workflowDefinition]));
	$workflowHandler->handle(new PanelCommandEnvelope('workflow.start','workflow.start','tenant','actor','workflow-variants-start',['definition'=>'orders','id'=>'one']));
	foreach([
		['workflow.transition',['transition'=>'missing','data_patch'=>[]]],
		['workflow.approve',['comment'=>'bounded approval']],
		['workflow.reject',['comment'=>'bounded rejection']],
		['workflow.assign',['assigned_to'=>'operator-2','roles'=>'reviewer']],
		['workflow.rollback',['event_id'=>null,'reason'=>'bounded rollback']],
	]as[$command,$extra]){
		$input=['definition'=>'orders','id'=>'missing']+$extra;
		$t->throws(static fn()=>$workflowHandler->handle(new PanelCommandEnvelope($command,$command,'tenant','actor','variant-'.str_replace('.','-',$command),$input)),Dataphyre\Panel\PanelCommandExecutionException::class);
	}
	$t->same('trimmed',$t->nonPublic($workflowHandler)->invoke('optionalString',['comment'=>' trimmed '],'comment',''));
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('map',['value'=>fopen('php://memory','r')],'workflow map'),Dataphyre\Panel\PanelCommandExecutionException::class);

	$action=AutomationAction::make('ping')->requiresIdempotency()->handle(static fn():array=>['pong'=>true]);
	$automationHandler=new PanelAutomationFabricHandler(new AutomationExecutor(new AutomationRegistry([$action]),new InMemoryAutomationStore()));
	$t->throws(static fn()=>$automationHandler->handle(new PanelCommandEnvelope('automation.rollback','automation.rollback','tenant','actor','automation-rollback-missing',['receipt_id'=>'missing','confirmation_phrase'=>'bounded'])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same('bounded',$t->nonPublic($automationHandler)->invoke('nullableString',['confirmation_phrase'=>'bounded'],'confirmation_phrase'));
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('map',['value'=>fopen('php://memory','r')],'automation map'),Dataphyre\Panel\PanelCommandExecutionException::class);
})->tag('panel','fabric','workflow','automation','coverage')->isolation('case')->maxMillis(5000);

test('IAM commands retain native authorization idempotency concurrency and audit guarantees',static function(Context $t):void {
	$fixture=dp_panel_fabric_fixture(['iam.*']);$manager=new PanelIamManager(new PanelMemoryIamStore(),str_repeat('I',32),static fn():bool=>true,['clock'=>static fn():string=>'2026-07-16T12:00:00Z']);
	$iamHandler=new PanelIamFabricHandler($manager);$fixture['registry']->register('iam.*',$iamHandler);$t->same('panel_iam_fabric_handler',$iamHandler->jsonSerialize()['type']);
	$create=new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','iam-create-key',[
		'subject_id'=>'person-1','reason'=>'Provision the bounded operator identity.',
		'principal'=>['display_name'=>'Avery Stone','email'=>'avery@example.test','metadata'=>['department'=>'ops']],
	],createdAt:'2026-07-16T12:00:00Z');
	$receipt=$fixture['fabric']->dispatch($create);$t->isTrue($receipt->ok());$t->same('person-1',$manager->principal('tenant-a','person-1')?->id());$t->same(1,count($manager->audit('tenant-a')));
	$replay=$fixture['fabric']->dispatch($create);$t->isTrue($replay->replay());$t->same(1,count($manager->audit('tenant-a')));
	$grant=$fixture['fabric']->dispatch(new PanelCommandEnvelope('iam.membership.grant','iam.membership.grant','tenant-a','operator','iam-grant-key',[
		'subject_type'=>'principal','subject_id'=>'person-1','reason'=>'Grant order-review access.','roles'=>['reviewer'],'permissions'=>['orders.read'],
	]));
	$t->isTrue($grant->ok());$t->same(['orders.read'],$manager->membership('tenant-a','principal','person-1')?->permissions());$t->same(2,count($manager->audit('tenant-a')));$t->isTrue($manager->verifyAudit('tenant-a'));
	$service=$iamHandler->handle(new PanelCommandEnvelope('iam.service.create','iam.service.create','tenant-a','operator','iam-service-key',[
		'subject_id'=>'service-1','reason'=>'Provision a bounded service identity.','service'=>['display_name'=>'Order worker'],
	],createdAt:'2026-07-16T12:00:00Z'));
	$t->same('service.create',$service->result()->operation());
	$rotated=$iamHandler->handle(new PanelCommandEnvelope('iam.service.rotate_credential','iam.service.rotate_credential','tenant-a','operator','iam-rotate-key',[
		'subject_id'=>'service-1','reason'=>'Rotate the bounded service credential.','credential_metadata'=>['key_id'=>'orders-key','version'=>1,'rotated_at'=>'2026-07-16T12:00:00+00:00'],
	],expectedRevision:1));
	$t->same('service.rotate_credential',$rotated->result()->operation());
	$suspended=$iamHandler->handle(new PanelCommandEnvelope('iam.membership.suspend','iam.membership.suspend','tenant-a','operator','iam-suspend-key',['subject_type'=>'principal','subject_id'=>'person-1','reason'=>'Pause access for review.'],expectedRevision:1));
	$t->same('suspended',$suspended->result()->status());
	$restored=$iamHandler->handle(new PanelCommandEnvelope('iam.membership.restore','iam.membership.restore','tenant-a','operator','iam-restore-key',['subject_type'=>'principal','subject_id'=>'person-1','reason'=>'Restore reviewed access.'],expectedRevision:2));
	$t->same('active',$restored->result()->status());
	$revoked=$iamHandler->handle(new PanelCommandEnvelope('iam.membership.revoke','iam.membership.revoke','tenant-a','operator','iam-revoke-key',['subject_type'=>'principal','subject_id'=>'person-1','reason'=>'Remove obsolete access.'],expectedRevision:3));
	$t->same('revoked',$revoked->result()->status());

	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.unknown','iam.unknown','tenant-a','operator','iam-unknown-key')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.service.create','iam.service.create','tenant-a','operator','iam-type-mismatch',['subject_type'=>'principal','subject_id'=>'service-2','reason'=>'Reject a mismatched signed scope.','service'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','iam-reason-invalid',['subject_id'=>'person-reason','reason'=>str_repeat('x',501),'principal'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','iam-principal-invalid',['subject_id'=>'person-invalid','reason'=>'Reject an invalid principal.','principal'=>['status'=>'invalid']])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.service.create','iam.service.create','tenant-a','operator','iam-service-invalid',['subject_id'=>'service-invalid','reason'=>'Reject an invalid service account.','service'=>['status'=>'invalid']])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$conflict=$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','iam-conflict-key',['subject_id'=>'person-1','reason'=>'Reject duplicate identity creation.','principal'=>['display_name'=>'Duplicate person']],createdAt:'2026-07-16T12:00:00Z')),Dataphyre\Panel\PanelCommandExecutionException::class);$t->same('iam_conflict',$conflict->errorCode());
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.membership.revoke','iam.membership.revoke','tenant-a','operator','iam-not-found-key',['subject_type'=>'principal','subject_id'=>'person-missing','reason'=>'Reject a missing membership.'])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$iamHandler->handle(new PanelCommandEnvelope('iam.membership.grant','iam.membership.grant','tenant-a','operator','iam-invalid-grant',['subject_type'=>'principal','subject_id'=>'person-1','reason'=>'Reject an empty grant.'])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$deniedHandler=new PanelIamFabricHandler(new PanelIamManager(new PanelMemoryIamStore(),str_repeat('D',32),static fn():bool=>false,['clock'=>static fn():string=>'2026-07-16T12:00:00Z']));
	$deniedError=$t->throws(static fn()=>$deniedHandler->handle(new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','iam-denied-key',['subject_id'=>'denied-person','reason'=>'Exercise authorization denial.','principal'=>['display_name'=>'Denied person']],createdAt:'2026-07-16T12:00:00Z')),Dataphyre\Panel\PanelCommandExecutionException::class);$t->same('iam_authorization_denied',$deniedError->errorCode());
	$t->notContains('iam-create-key',json_encode($fixture['store']->payload(),JSON_THROW_ON_ERROR));
});

test('agent execution crosses the fabric with encrypted raw plans and bearer intents only',static function(Context $t):void {
	$now=1784212800;$clock=static fn():int=>$now;
	$policyResolver=new class implements PanelAgentPolicyResolver {
		public function decide(PanelAgentRequestContext $context,PanelAgentTool $tool,array $arguments):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow();}
		public function approve(PanelAgentRequestContext $approver,PanelAgentPlan $plan):PanelAgentPolicyDecision{return PanelAgentPolicyDecision::allow();}
		public function fingerprint():string{return hash('sha256','fabric-agent-policy-v1');}
	};
	$executor=new class implements PanelAgentToolExecutor {public int $calls=0;public bool $fail=false;public function execute(PanelAgentToolExecutionRequest $request):PanelAgentToolExecutionResult{$this->calls++;return$this->fail?PanelAgentToolExecutionResult::failure('Bounded tool failure.'):PanelAgentToolExecutionResult::success(['updated'=>isset($request->arguments()['order_id'])]);}};
	$catalog=new PanelAgentToolCatalog();$catalog->register(new PanelAgentTool('orders.update','1.0.0','Update one bounded order.','orders.update','low',true,false,0,false,['type'=>'object','required'=>['order_id'],'additionalProperties'=>false,'properties'=>['order_id'=>['type'=>'string']]]),$executor,'test');
	$store=new InMemoryPanelAgentWorkflowStore($clock);$signer=new PanelAgentIntentSigner(['current'=>str_repeat('A',32)],'current',$clock,0);$runtime=new PanelAgentRuntime($catalog,new PanelAgentPolicyEngine($policyResolver),$signer,$store,$clock);
	$context=new PanelAgentRequestContext('operations','tenant-a','operator','session-private','agent-request');$prepared=$runtime->prepare(['title'=>'Update order','steps'=>[['tool'=>'orders.update','arguments'=>['order_id'=>'ord-private']]]],$context,$catalog->revision(),$store->revision());
	$planPayload=$prepared->plan()->executionPayload();$t->same($prepared->plan()->hash(),PanelAgentPlan::hydrateExecutionPayload($planPayload)->hash());$tampered=$planPayload;$tampered['steps'][0]['arguments']['order_id']='ord-tampered';$t->throws(static fn()=>PanelAgentPlan::hydrateExecutionPayload($tampered),UnexpectedValueException::class);
	$fixture=dp_panel_fabric_fixture(['agent.*']);$agentHandler=new PanelAgentFabricHandler($runtime);$fixture['registry']->register('agent.*',$agentHandler);$t->same('panel_agent_fabric_handler',$agentHandler->jsonSerialize()['type']);$token=$prepared->intent()->token();
	$command=new PanelCommandEnvelope('agent.execute','agent.execute','tenant-a','operator','agent-fabric-key',[
		'plan'=>$planPayload,'plan_intent'=>$token,'context'=>$context->executionPayload(),'approval_intents'=>[],
	],expectedRevision:$prepared->storeRevision());
	$receipt=$fixture['fabric']->dispatch($command);$t->isTrue($receipt->ok());$t->same(1,$executor->calls);$t->isTrue($fixture['fabric']->dispatch($command)->replay());$t->same(1,$executor->calls);
	$serialized=json_encode($fixture['store']->payload(),JSON_THROW_ON_ERROR);$t->notContains($token,$serialized);$t->notContains('session-private',$serialized);$t->notContains('ord-private',$serialized);
	$t->throws(static fn()=>$agentHandler->handle(new PanelCommandEnvelope('agent.unknown','agent.unknown','tenant-a','operator','agent-unknown')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$agentHandler->handle(new PanelCommandEnvelope('agent.execute','agent.execute','tenant-b','operator','agent-scope-mismatch',['plan'=>$planPayload,'plan_intent'=>$token,'context'=>$context->executionPayload()],expectedRevision:$store->revision())),Dataphyre\Panel\PanelCommandExecutionException::class);
	$intentFailure=$t->throws(static fn()=>$agentHandler->handle(new PanelCommandEnvelope('agent.execute','agent.execute','tenant-a','operator','agent-intent-failure',['plan'=>$planPayload,'plan_intent'=>'invalid-bearer-intent','context'=>$context->executionPayload()],expectedRevision:$store->revision())),Dataphyre\Panel\PanelCommandExecutionException::class);$t->contains('agent_',$intentFailure->errorCode());

	$cancelPrepared=$runtime->prepare(['title'=>'Cancel order update','steps'=>[['tool'=>'orders.update','arguments'=>['order_id'=>'ord-cancel']]]],$context,$catalog->revision(),$store->revision());
	$cancelled=$agentHandler->handle(new PanelCommandEnvelope('agent.cancel','agent.cancel','tenant-a','operator','agent-cancel-command',['plan'=>$cancelPrepared->plan()->executionPayload(),'plan_intent'=>$cancelPrepared->intent()->token(),'context'=>$context->executionPayload(),'reason'=>'Operator cancelled the pending plan.'],expectedRevision:$cancelPrepared->storeRevision()));$t->same('cancelled',$cancelled->result()->code());
	$failedPrepared=$runtime->prepare(['title'=>'Fail order update','steps'=>[['tool'=>'orders.update','arguments'=>['order_id'=>'ord-fail']]]],$context,$catalog->revision(),$store->revision());$executor->fail=true;
	$failed=$t->throws(static fn()=>$agentHandler->handle(new PanelCommandEnvelope('agent.execute','agent.execute','tenant-a','operator','agent-result-failure',['plan'=>$failedPrepared->plan()->executionPayload(),'plan_intent'=>$failedPrepared->intent()->token(),'context'=>$context->executionPayload()],expectedRevision:$failedPrepared->storeRevision())),Dataphyre\Panel\PanelCommandExecutionException::class);$t->contains('agent_',$failed->errorCode());
	$clockFailure=$runtime->prepare(['title'=>'Clock failure cancellation','steps'=>[['tool'=>'orders.update','arguments'=>['order_id'=>'ord-clock']]]],$context,$catalog->revision(),$store->revision());$t->nonPublic($runtime)->writeProperty('clock',static fn():string=>'not-an-instant');
	$invalid=$t->throws(static fn()=>$agentHandler->handle(new PanelCommandEnvelope('agent.cancel','agent.cancel','tenant-a','operator','agent-clock-failure',['plan'=>$clockFailure->plan()->executionPayload(),'plan_intent'=>$clockFailure->intent()->token(),'context'=>$context->executionPayload(),'reason'=>'Exercise a malformed native clock.'],expectedRevision:$clockFailure->storeRevision())),Dataphyre\Panel\PanelCommandExecutionException::class);$t->same('agent_input_invalid',$invalid->errorCode());
	$t->same('agent_bad_code',$t->nonPublic($agentHandler)->invoke('errorCode','Bad Code'));$t->same('The agent command was refused.',$t->nonPublic($agentHandler)->invoke('safeMessage',''));
});

test('tenant lifecycle commands require explicit host request context and preserve native onboarding replay',static function(Context $t):void {
	$manager=new PanelManager();$registry=$manager->tenantRegistry();$requests=0;
	$fixture=dp_panel_fabric_fixture(['tenant.*']);$tenantHandler=new PanelTenantFabricHandler($registry,static function()use(&$requests):PanelRequest{$requests++;return PanelRequest::fromArray(['method'=>'POST','operation'=>'store','tenant'=>'system','user'=>['id'=>'operator']]);});$fixture['registry']->register('tenant.*',$tenantHandler);$t->same('panel_tenant_fabric_handler',$tenantHandler->jsonSerialize()['type']);
	$registered=$fixture['fabric']->dispatch(new PanelCommandEnvelope('tenant.register','tenant.register','system','operator','tenant-register-key',['tenant'=>['name'=>'north','label'=>'North']]));$t->isTrue($registered->ok());$t->isTrue($registry->has('north'));$t->same(0,$requests);
	$onboarded=$fixture['fabric']->dispatch(new PanelCommandEnvelope('tenant.onboard','tenant.onboard','system','operator','tenant-onboard-key',['tenant'=>['name'=>'south','label'=>'South']]));$t->isTrue($onboarded->ok());$t->isTrue($registry->has('south'));$t->same(1,$requests);$t->isTrue($fixture['fabric']->dispatch(new PanelCommandEnvelope('tenant.onboard','tenant.onboard','system','operator','tenant-onboard-key',['tenant'=>['name'=>'south','label'=>'South']]))->replay());$t->same(1,$requests);
	$manager->tenantMembershipsUsing(static fn():array=>['north'=>['preferred'=>true],'south'=>[]]);$manager->tenantAuthorizationUsing(static fn():bool=>true);
	$t->throws(static fn()=>$tenantHandler->handle(new PanelCommandEnvelope('tenant.switch','tenant.switch','system','operator','tenant-switch-unconfigured',['tenant'=>'south'])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$manager->tenantPersistenceUsing(static fn():bool=>true);$switched=$tenantHandler->handle(new PanelCommandEnvelope('tenant.switch','tenant.switch','system','operator','tenant-switch-key',['tenant'=>'south']));$t->same('south',$switched->result()->current()->tenantKey());
	$t->throws(static fn()=>$tenantHandler->handle(new PanelCommandEnvelope('tenant.register','tenant.register','system','operator','tenant-invalid-name',['tenant'=>['name'=>'a.']])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$fullManager=new PanelManager();for($index=0;$index<100;$index++){$fullManager->registerTenant(['name'=>'tenant-'.$index]);}$fullHandler=new PanelTenantFabricHandler($fullManager->tenantRegistry());
	$t->throws(static fn()=>$fullHandler->handle(new PanelCommandEnvelope('tenant.register','tenant.register','system','operator','tenant-registry-full',['tenant'=>['name'=>'overflow']])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$closed=dp_panel_fabric_fixture(['tenant.*']);$closed['registry']->register('tenant.*',new PanelTenantFabricHandler((new PanelManager())->tenantRegistry()));$failure=$closed['fabric']->dispatch(new PanelCommandEnvelope('tenant.onboard','tenant.onboard','system','operator','tenant-closed-key',['tenant'=>['name'=>'closed']]));$t->same('failed',$failure->status());$t->contains('request resolver',strtolower($failure->error()??''));
});

test('notification and compliance projectors are explicit deterministic payload-free and replay tolerant',static function(Context $t):void {
	$fixture=dp_panel_fabric_fixture(['orders.*']);$fixture['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(['ok'=>true],[new PanelEventDraft('orders.reviewed','order','order-7',[
		'status'=>'reviewed','notification'=>['type'=>'success','title'=>'Review complete','message'=>'Order 7 passed review.','recipient'=>'operator','channels'=>['database'],'deliver'=>true],
	])]));
	$adapter=PanelInMemoryNotificationAdapter::make([],['database']);$notifications=new PanelNotificationFabricProjector($adapter);
	$ledger=new PanelComplianceLedger($t->tempDirectory('panel-fabric-compliance'),['primary'=>str_repeat('C',32)],'primary',static fn():bool=>true,static fn():string=>'2026-07-16T12:00:00Z');$ledger->registerControl('order_review',['title'=>'Order review','framework'=>'soc2','automated'=>true],'operator');$compliance=new PanelComplianceFabricProjector($ledger,['orders.reviewed'=>['control_id'=>'order_review','status'=>'satisfied']]);
	$t->same('panel_notification_fabric_projector',$notifications->jsonSerialize()['type']);$t->same('panel_compliance_fabric_projector',$compliance->jsonSerialize()['type']);
	$fixture['fabric']->subscribe('notifications','*',$notifications)->subscribe('compliance',['orders.reviewed'],$compliance);
	$fixture['fabric']->dispatch(new PanelCommandEnvelope('orders.review','orders.review','tenant-a','operator','projector-key'));
	$t->isTrue($fixture['fabric']->drainSubscriber('notifications')['ok']);$t->isTrue($fixture['fabric']->drainSubscriber('compliance')['ok']);$t->same(1,count($adapter->forRecipient('operator')));$t->same(1,count($ledger->pack(['order_review'])->payload()['evidence']));
	$event=$fixture['fabric']->events()[0];$notifications($event);$compliance($event);$t->same(1,count($adapter->forRecipient('operator')));$evidence=$ledger->pack(['order_review'])->payload()['evidence'];$t->same(1,count($evidence));$t->isFalse(array_key_exists('notification',$evidence[0]['evidence']));$t->isTrue($ledger->verify());
});

test('Operations OS composes IAM tenancy notification and compliance bridges around shared native services',static function(Context $t):void {
	$iam=new PanelIamManager(new PanelMemoryIamStore(),str_repeat('I',32),static fn():bool=>true,['clock'=>static fn():string=>'2026-07-16T12:00:00Z']);$tenants=(new PanelManager())->tenantRegistry();$notifications=PanelInMemoryNotificationAdapter::make();
	$os=PanelOperationsOs::fromConfig($t->tempDirectory('panel-fabric-os-composition'),[
		'master_key'=>str_repeat('M',48),'clock'=>static fn():string=>'2026-07-16T12:00:00Z',
		'policy_bundles'=>[['id'=>'fabric-os','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['*'],'priority'=>100,'reason'=>'Composition test.']]]],
		'iam_manager'=>$iam,'tenant_registry'=>$tenants,'tenant_request_resolver'=>static fn():PanelRequest=>PanelRequest::fromArray(['method'=>'POST','user'=>['id'=>'operator']]),
		'notification_adapter'=>$notifications,'fabric_compliance_mappings'=>['orders.reviewed'=>['control_id'=>'order_review','status'=>'satisfied']],
	]);
	$routes=$os->commandFabric()->registry()->jsonSerialize()['routes'];$t->isTrue(isset($routes['iam.*'],$routes['tenant.*'],$routes['workflow.*'],$routes['automation.*'],$routes['operation.*']));
	$created=$os->commandFabric()->dispatch(new PanelCommandEnvelope('iam.principal.create','iam.principal.create','tenant-a','operator','os-iam-key',['subject_id'=>'person-1','reason'=>'Create the operator.','principal'=>['display_name'=>'Operator One']],createdAt:'2026-07-16T12:00:00Z'));$t->isTrue($created->ok());$t->same('person-1',$iam->principal('tenant-a','person-1')?->id());
	$registered=$os->commandFabric()->dispatch(new PanelCommandEnvelope('tenant.register','tenant.register','system','operator','os-tenant-key',['tenant'=>['name'=>'north']]));$t->isTrue($registered->ok());$t->isTrue($tenants->has('north'));
	$os->compliance()->registerControl('order_review',['title'=>'Order review','framework'=>'soc2','automated'=>true],'operator');
	$os->commandFabric()->registry()->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true,[new PanelEventDraft('orders.reviewed','order','order-9',['notification'=>['message'=>'Order review completed.','recipient'=>'operator']])]),'test');
	$os->commandFabric()->dispatch(new PanelCommandEnvelope('orders.review','orders.review','tenant-a','operator','os-order-key'));
	$t->isTrue($os->commandFabric()->drainSubscriber('operations_os.notifications')['ok']);$t->isTrue($os->commandFabric()->drainSubscriber('operations_os.compliance')['ok']);$t->same(1,count($notifications->forRecipient('operator')));$t->same(1,count($os->compliance()->pack(['order_review'])->payload()['evidence']));
});

test('fabric value contracts expose bounded evidence and reject every malformed persistence shape',static function(Context $t):void {
	$t->throws(static fn()=>new PanelCommandEnvelope('orders.create','orders.create','tenant','actor',''),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelCommandEnvelope('orders.create','orders.create','tenant','actor','key',risk:'extreme'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelCommandEnvelope('orders.create','orders.create','tenant','actor','key',expectedRevision:-1),InvalidArgumentException::class);
	$command=new PanelCommandEnvelope('orders.create','orders.create','tenant','actor','key',['id'=>1],createdAt:'2026-07-16T12:00:00Z');
	$manifest=$command->jsonSerialize();$sealed=$command->sealedPayload();
	$t->throws(static fn()=>PanelCommandEnvelope::hydrate([],[]),UnexpectedValueException::class);
	$corrupt=$manifest;$corrupt['fingerprint']=str_repeat('0',64);$t->throws(static fn()=>PanelCommandEnvelope::hydrate($corrupt,$sealed),UnexpectedValueException::class);

	$approvalKey=str_repeat('A',32);$attestation=PanelCommandApprovalAttestation::sign($command->executionTarget(),'operations','order-1',['approver-b','approver-a'],'2026-07-16T12:00:00Z','2026-07-16T13:00:00Z','approval',$approvalKey);
	$t->same($command->executionTarget(),$attestation->targetDigest());$t->same('order-1',$attestation->subjectId());$t->same(2,count($attestation->approverHashes()));$t->same('2026-07-16T12:00:00.000000Z',$attestation->issuedAt());$t->same('2026-07-16T13:00:00.000000Z',$attestation->expiresAt());$t->same('approval',$attestation->keyId());$t->same(64,strlen($attestation->digest()));
	$t->same($attestation->jsonSerialize(),PanelCommandApprovalAttestation::hydrate($attestation->jsonSerialize())->jsonSerialize());
	$t->same('operations',$attestation->source());$t->same(2,$attestation->approvedCount());$t->isTrue($attestation->includesActor('approver-a'));
	$badApproval=$attestation->jsonSerialize();unset($badApproval['source']);$t->throws(static fn()=>PanelCommandApprovalAttestation::hydrate($badApproval),UnexpectedValueException::class);

	$registry=new PanelCommandRegistry('priority');$handler=static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true);
	$registry->register('orders.*',$handler,'primary',10)->register('orders.*',$handler,'secondary',5);
	$t->same(2,$registry->revision());$t->same('panel_command_registry_v1',$registry->checkpointType());
	$t->throws(static fn()=>(new PanelCommandRegistry('invalid')),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->register('orders.*',$handler,'primary',8),LogicException::class);
	$t->throws(static fn()=>$registry->register('orders.*',$handler,'third',10),LogicException::class);
	$checkpoint=$registry->checkpoint();$registry->unregisterContributor('primary');$t->same('secondary',$registry->resolve('orders.create')['contributor']);$registry->restore($checkpoint);$t->same('primary',$registry->resolve('orders.create')['contributor']);
	$invalidCheckpoint=$checkpoint;$invalidCheckpoint['digest']='bad';$t->throws(static fn()=>$registry->restore($invalidCheckpoint),InvalidArgumentException::class);
	$invalidRoutes=$checkpoint;$invalidRoutes['routes']=['orders.*'=>'bad'];$invalidRoutes['digest']=$t->nonPublic($registry)->invoke('checkpointDigest',$invalidRoutes['routes'],$invalidRoutes['revision'],$invalidRoutes['order']);$t->throws(static fn()=>$registry->restore($invalidRoutes),InvalidArgumentException::class);
	$invalidRoute=$checkpoint;$invalidRoute['routes']['orders.*']=[['handler'=>'bad','contributor'=>'x','priority'=>0,'order'=>1]];$invalidRoute['digest']=$t->nonPublic($registry)->invoke('checkpointDigest',$invalidRoute['routes'],$invalidRoute['revision'],$invalidRoute['order']);$t->throws(static fn()=>$registry->restore($invalidRoute),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->register('orders*',$handler,'invalid'),InvalidArgumentException::class);
	$denyRegistry=new PanelCommandRegistry();$denyRegistry->register('orders.*',$handler);$t->throws(static fn()=>$denyRegistry->register('orders.*',$handler,'second'),LogicException::class);
	$t->same(64,strlen($t->nonPublic($registry)->invoke('checkpointDigest',['ignored'=>'malformed'],0,0)));

	$state=PanelCommandFabricState::initial();$invalid=$state;unset($invalid['version']);$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$oversized=$state;$oversized['commands']=array_fill(0,50001,[]);$t->throws(static fn()=>PanelCommandFabricState::validate($oversized),LengthException::class);
	$invalid=$state;$invalid['commands']=[str_repeat('a',64)=>[]];$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$invalid=$state;$invalid['receipts']=['bad'=>[]];$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$invalid=$state;$invalid['events']=['event'=>'bad'];$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$eventFixture=dp_panel_fabric_fixture(['events.*']);$eventFixture['registry']->register('events.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true,[new PanelEventDraft('events.created','event','one')]));$eventFixture['fabric']->dispatch(new PanelCommandEnvelope('events.create','events.create','tenant','actor','event-state-key'));$eventState=$eventFixture['store']->payload();
	$invalid=$eventState;$eventId=array_key_first($invalid['events']);$invalid['events']['wrong-id']=$invalid['events'][$eventId];unset($invalid['events'][$eventId]);$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$invalid=$eventState;$invalid['sequence']++;$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);
	$invalid=$state;$invalid['subscriber_cursors']=['projection'=>1];$t->throws(static fn()=>PanelCommandFabricState::validate($invalid),UnexpectedValueException::class);

	$lease=PanelCommandFabricSubscriberLease::make('projection','worker-1',str_repeat('t',32),1,'2026-07-16T12:00:00Z','2026-07-16T12:10:00Z');$t->same('panel_command_fabric_subscriber_lease',$lease->jsonSerialize()['type']);
	$lost=new PanelCommandFabricLeaseLost('projection');$t->same('projection',$lost->subscriber());
	$result=new PanelCommandObligationResult(false,['blocked'],['confirmed'=>false]);$t->same('panel_command_obligation_result',$result->jsonSerialize()['type']);
})->tag('panel','fabric','coverage','persistence','security')->isolation('case')->maxMillis(5000);

test('strict command obligations enumerate confirmation assurance dry-run cost approval and duty failures',static function(Context $t):void {
	$decision=new PanelPolicyDecision(true,str_repeat('a',64),['allow'],['Allowed.'],[
		'confirmation'=>true,'mfa_level'=>3,'dry_run'=>true,'max_cost_micros'=>100,'approval_count'=>1,'separation_of_duties'=>true,
	],[],1);
	$command=new PanelCommandEnvelope('orders.delete','orders.delete','tenant','actor','obligation-coverage',metadata:['cost_micros'=>101]);
	$verifier=new PanelStrictCommandObligationVerifier();$result=$verifier->verify($command,$decision);
	$t->isFalse($result->satisfied());$t->same(6,count($result->reasons()));$t->same('panel_strict_command_obligation_verifier',$verifier->jsonSerialize()['type']);
	$passDecision=new PanelPolicyDecision(true,str_repeat('b',64),[],[],['confirmation'=>true,'mfa_level'=>2,'dry_run'=>true,'max_cost_micros'=>1000],[],2);
	$passed=$verifier->verify(new PanelCommandEnvelope('orders.preview','orders.preview','tenant','actor','obligation-pass',metadata:['confirmed'=>true,'mfa_level'=>2,'dry_run'=>true,'cost_micros'=>10]),$passDecision);
	$t->isTrue($passed->satisfied());
})->tag('panel','fabric','coverage','obligations')->isolation('case')->maxMillis(2000);

test('signed command approval evidence verifies trust scope expiry separation and malformed payloads',static function(Context $t):void {
	$key=str_repeat('A',32);$command=new PanelCommandEnvelope('orders.delete','orders.delete','tenant','actor','attested-command',metadata:['confirmed'=>true]);
	$attestation=PanelCommandApprovalAttestation::sign($command->executionTarget(),'operations','order-1',['approver-a'],'2026-07-16T12:00:00Z','2026-07-16T13:00:00Z','approval',$key);
	$t->isTrue($attestation->verify(['approval'=>$key],$command->executionTarget(),'2026-07-16T12:30:00Z','operations'));
	$t->isFalse($attestation->verify(['approval'=>$key],str_repeat('0',64),'2026-07-16T12:30:00Z','operations'));
	$decision=new PanelPolicyDecision(true,str_repeat('a',64),['allow'],['Allowed.'],['confirmation'=>true,'approval_count'=>1,'separation_of_duties'=>true],[],1);
	$verifier=new PanelAttestedCommandObligationVerifier(['approval'=>$key],static fn():string=>'2026-07-16T12:30:00Z');
	$verified=$verifier->verify($command->withEvidence(['approval_attestation'=>$attestation->jsonSerialize()]),$decision);$t->isTrue($verified->satisfied());$t->same('signed_attestation',$verified->evidence()['approval_evidence']);$t->same('panel_attested_command_obligation_verifier',$verifier->jsonSerialize()['type']);
	$invalid=$verifier->verify($command->withEvidence(['approval_attestation'=>['malformed'=>true]]),$decision);$t->isFalse($invalid->satisfied());$t->contains('invalid or expired',implode(' ',$invalid->reasons()));
	$t->same('panel_encrypted_command_payload_codec',(new PanelEncryptedCommandPayloadCodec(str_repeat('E',32)))->jsonSerialize()['type']);
})->tag('panel','fabric','coverage','attestation','security')->isolation('case')->maxMillis(3000);

test('domain fabric boundaries reject malformed definitions scope mismatches delegate failures and denied receipts',static function(Context $t):void {
	$definition=PanelDomainCommandDefinition::from('commerce','1.0.0','close',['label'=>'Close','entity'=>'order','operation'=>'close','risk'=>'medium','input'=>['value'=>['type'=>'integer']]]);
	$successDelegate=new class implements PanelDomainCommandExecutor {public function execute(PanelDomainCommandInvocation $invocation):mixed{return['ok'=>true];}};$handler=new PanelDelegatingDomainFabricHandler($successDelegate);
	$t->throws(static fn()=>$handler->handle(new PanelCommandEnvelope('domain.commerce.close','orders.close','tenant','actor','domain-invalid-shape',['definition'=>[],'input'=>['list']])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$handler->handle(new PanelCommandEnvelope('domain.commerce.close','orders.close','tenant','actor','domain-invalid-definition',['definition'=>['bad'=>true],'input'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$handler->handle(new PanelCommandEnvelope('domain.commerce.other','orders.close','tenant','actor','domain-scope-mismatch',['definition'=>$definition->jsonSerialize(),'input'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$explicitFailure=new PanelDelegatingDomainFabricHandler(new class implements PanelDomainCommandExecutor {public function execute(PanelDomainCommandInvocation $invocation):mixed{throw new Dataphyre\Panel\PanelCommandExecutionException('domain_refused','Refused.');}});
	$t->throws(static fn()=>$explicitFailure->handle(new PanelCommandEnvelope('domain.commerce.close',$definition->ability(),'tenant','actor','domain-explicit-failure',['definition'=>$definition->jsonSerialize(),'input'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$unexpectedFailure=new PanelDelegatingDomainFabricHandler(new class implements PanelDomainCommandExecutor {public function execute(PanelDomainCommandInvocation $invocation):mixed{throw new RuntimeException('host failure');}});
	$t->throws(static fn()=>$unexpectedFailure->handle(new PanelCommandEnvelope('domain.commerce.close',$definition->ability(),'tenant','actor','domain-host-failure',['definition'=>$definition->jsonSerialize(),'input'=>[]])),Dataphyre\Panel\PanelCommandExecutionException::class);
	[$policy]=dp_panel_fabric_policy(['unrelated.*']);$fabric=new PanelCommandFabric(new PanelCommandRegistry(),new PanelInMemoryCommandFabricStore(),$policy,new PanelEncryptedCommandPayloadCodec(str_repeat('E',32)),['fabric'=>str_repeat('F',32)],'fabric');$executor=new PanelDomainFabricCommandExecutor($fabric);
	$t->throws(static fn()=>$executor->execute(new PanelDomainCommandInvocation($definition,'tenant','actor','domain-denied',[])),Dataphyre\Panel\PanelCommandExecutionException::class);
})->tag('panel','fabric','coverage','domain','fail-closed')->isolation('case')->maxMillis(4000);

test('fabric handlers fail closed across malformed names identifiers maps labels roles and host contexts',static function(Context $t):void {
	$workflowHandler=new PanelWorkflowFabricHandler(new WorkflowEngine(new InMemoryWorkflowStore(),[]));
	$t->throws(static fn()=>$workflowHandler->handle(new PanelCommandEnvelope('workflow.unknown','workflow.unknown','tenant','actor','workflow-unknown',['definition'=>'orders','id'=>'one'])),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('requiredIdentifier',[],'definition'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('requiredIdentifier',['definition'=>"bad\0id"],'definition'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('requiredName',[],'transition'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('requiredName',['transition'=>'bad name'],'transition'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same(null,$t->nonPublic($workflowHandler)->invoke('nullableIdentifier',[],'assigned_to'));
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('nullableIdentifier',['assigned_to'=>[]],'assigned_to'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('nullableIdentifier',['assigned_to'=>"bad\0id"],'assigned_to'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('optionalString',['comment'=>[]],'comment',''),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('map',['list'],'workflow map'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same(['reviewer'],$t->nonPublic($workflowHandler)->invoke('roles','reviewer'));
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('roles',42),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($workflowHandler)->invoke('roles',["bad\0role"]),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same('workflow_bad_code',$t->nonPublic($workflowHandler)->invoke('errorCode','workflow','Bad Code'));
	$t->same('Workflow command failed.',$t->nonPublic($workflowHandler)->invoke('safeMessage',''));

	$automation=new AutomationExecutor(new AutomationRegistry([AutomationAction::make('ping')->handle(static fn()=>true)]),new InMemoryAutomationStore());$automationHandler=new PanelAutomationFabricHandler($automation);
	$t->throws(static fn()=>$automationHandler->handle(new PanelCommandEnvelope('automation.unknown','automation.unknown','tenant','actor','automation-unknown')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('requiredName',[],'name'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('requiredName',['name'=>'bad name'],'name'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('requiredIdentifier',[],'receipt_id'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('requiredIdentifier',['receipt_id'=>"bad\0id"],'receipt_id'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('nullableString',['confirmation_phrase'=>[]],'confirmation_phrase'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($automationHandler)->invoke('map',['list'],'automation map'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same('automation_bad_code',$t->nonPublic($automationHandler)->invoke('errorCode','Bad Code'));
	$t->same('Automation command failed.',$t->nonPublic($automationHandler)->invoke('safeMessage',''));

	$operationStore=new PanelFilesystemOperationStore($t->tempDirectory('panel-fabric-handler-boundaries'));$operationRunner=new PanelSynchronousOperationRunner($operationStore,new PanelOperationHandlerRegistry());$operationHandler=new PanelOperationFabricHandler($operationRunner);
	$t->throws(static fn()=>$operationHandler->handle(new PanelCommandEnvelope('operation.unknown','operation.unknown','tenant','actor','operation-unknown')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('requiredName',[],'type'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('requiredName',['type'=>'bad name'],'type'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('requiredIdentifier',['id'=>"bad\0id"],'id'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('optionalLabel',['name'=>[]],'name','operation'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('optionalLabel',['name'=>"bad\0label"],'name','operation'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('map',['list'],'operation map'),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($operationHandler)->invoke('map',['value'=>fopen('php://memory','r')],'operation map'),Dataphyre\Panel\PanelCommandExecutionException::class);

	$tenantHandler=new PanelTenantFabricHandler((new PanelManager())->tenantRegistry(),static function():never{throw new RuntimeException('resolver failed');});
	$t->throws(static fn()=>$tenantHandler->handle(new PanelCommandEnvelope('tenant.unknown','tenant.unknown','system','actor','tenant-unknown')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($tenantHandler)->invoke('tenant',[]),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($tenantHandler)->invoke('tenant',['tenant'=>['name'=>'bad name']]),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->throws(static fn()=>$t->nonPublic($tenantHandler)->invoke('request',new PanelCommandEnvelope('tenant.switch','tenant.switch','system','actor','tenant-request')),Dataphyre\Panel\PanelCommandExecutionException::class);
	$t->same('tenant_bad_code',$t->nonPublic($tenantHandler)->invoke('errorCode','Bad Code'));
})->tag('panel','fabric','coverage','input-validation')->isolation('case')->maxMillis(5000);

test('command fabric configuration pass-throughs and terminal handler failures remain explicit',static function(Context $t):void {
	[$policy]=dp_panel_fabric_policy(['orders.*']);$registry=new PanelCommandRegistry();$store=new PanelInMemoryCommandFabricStore();$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',32));$key=str_repeat('F',32);
	$t->throws(static fn()=>new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>'short'],'fabric'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelCommandFabric($registry,$store,$policy,$codec,['other'=>$key],'fabric'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>$key],'fabric',subscriberLeaseTtlSeconds:4),InvalidArgumentException::class);
	$fabric=new PanelCommandFabric($registry,$store,$policy,$codec,['fabric'=>$key],'fabric');$t->same('panel_strict_command_obligation_verifier',$fabric->obligationVerifier()->jsonSerialize()['type']);
	$command=new PanelCommandEnvelope('orders.inspect','orders.inspect','tenant','actor','fabric-public-contract');$t->isTrue($fabric->decisionFor($command)->allowed());$t->same(0,$fabric->changesSince()['cursor']);
	$fabric->subscribe('temporary','orders.*',static fn():bool=>true)->unsubscribe('temporary');$t->throws(static fn()=>$fabric->drainSubscriber('temporary'),OutOfBoundsException::class);$t->throws(static fn()=>$fabric->subscribe('invalid','bad pattern',static fn():bool=>true),InvalidArgumentException::class);
	$t->throws(static fn()=>$fabric->resume(str_repeat('a',64),-1),InvalidArgumentException::class);

	$invalidOutcome=dp_panel_fabric_fixture(['orders.*']);$invalidOutcome['registry']->register('orders.*',static fn():bool=>true);$invalidReceipt=$invalidOutcome['fabric']->dispatch(new PanelCommandEnvelope('orders.invalid','orders.invalid','tenant','actor','invalid-outcome'));$t->same('failed',$invalidReceipt->status());$t->same('handler_exception',$invalidReceipt->metadata()['error_code']);
	$missing=dp_panel_fabric_fixture(['orders.*']);$missingReceipt=$missing['fabric']->dispatch(new PanelCommandEnvelope('orders.missing','orders.missing','tenant','actor','missing-handler'));$t->same('failed',$missingReceipt->status());$t->same('handler_not_found',$missingReceipt->metadata()['error_code']);
	$badClockRegistry=new PanelCommandRegistry();$badClockRegistry->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$badClock=new PanelCommandFabric($badClockRegistry,new PanelInMemoryCommandFabricStore(),$policy,$codec,['fabric'=>$key],'fabric',clock:static fn():array=>[]);$t->throws(static fn()=>$badClock->dispatch(new PanelCommandEnvelope('orders.clock','orders.clock','tenant','actor','bad-clock')),UnexpectedValueException::class);
})->tag('panel','fabric','coverage','configuration','fail-closed')->isolation('case')->maxMillis(4000);

test('command recovery replays terminal receipts denies changed policy and scans stale entries deterministically',static function(Context $t):void {
	$completed=dp_panel_fabric_fixture(['orders.*']);$completed['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$doneCommand=new PanelCommandEnvelope('orders.done','orders.done','tenant','actor','resume-terminal');$completed['fabric']->dispatch($doneCommand);$t->isTrue($completed['fabric']->resume($doneCommand->idempotencyHash())->replay());

	$key=str_repeat('F',32);$codec=new PanelEncryptedCommandPayloadCodec(str_repeat('E',32),static fn():string=>str_repeat('N',12));$resumeCommand=new PanelCommandEnvelope('orders.resume','orders.resume','tenant','actor','resume-denied',createdAt:'2026-07-16T12:00:00Z');
	[$denyPolicy]=dp_panel_fabric_policy(['unrelated.*']);$denied=new PanelCommandFabric(new PanelCommandRegistry(),new PanelInMemoryCommandFabricStore(dp_panel_fabric_executing_state($resumeCommand,$codec)),$denyPolicy,$codec,['fabric'=>$key],'fabric',clock:static fn():string=>'2026-07-16T12:10:00Z');$deniedReceipt=$denied->resume($resumeCommand->idempotencyHash());$t->same('denied',$deniedReceipt->status());$t->same('policy_denied_on_resume',$deniedReceipt->metadata()['error_code']);
	[$obligationPolicy]=dp_panel_fabric_policy(['orders.*'],['confirmation'=>true]);$obligationCommand=new PanelCommandEnvelope('orders.resume','orders.resume','tenant','actor','resume-obligation',createdAt:'2026-07-16T12:00:00Z');$obligation=new PanelCommandFabric(new PanelCommandRegistry(),new PanelInMemoryCommandFabricStore(dp_panel_fabric_executing_state($obligationCommand,$codec)),$obligationPolicy,$codec,['fabric'=>$key],'fabric',clock:static fn():string=>'2026-07-16T12:10:00Z');$obligationReceipt=$obligation->resume($obligationCommand->idempotencyHash());$t->same('denied',$obligationReceipt->status());$t->same('obligation_unsatisfied_on_resume',$obligationReceipt->metadata()['error_code']);

	[$allowPolicy]=dp_panel_fabric_policy(['orders.*']);$stale=new PanelCommandEnvelope('orders.recover','orders.recover','tenant','actor','recover-stale',createdAt:'2026-07-16T12:00:00Z');$fresh=new PanelCommandEnvelope('orders.recover','orders.recover','tenant','actor','recover-fresh',createdAt:'2026-07-16T12:00:00Z');$state=dp_panel_fabric_executing_state($stale,$codec);$freshState=dp_panel_fabric_executing_state($fresh,$codec,'2026-07-16T12:09:30.000000Z');$state['commands']+=$freshState['commands'];$state['revision']=2;
	$recoveryRegistry=new PanelCommandRegistry();$recoveryRegistry->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$recovery=new PanelCommandFabric($recoveryRegistry,new PanelInMemoryCommandFabricStore($state),$allowPolicy,$codec,['fabric'=>$key],'fabric',clock:static fn():string=>'2026-07-16T12:10:00Z');$scan=$recovery->recoverStale(300,10);$t->same(1,count($scan['resumed']));$t->same([],$scan['errors']);
	$failingStore=new class(dp_panel_fabric_executing_state(new PanelCommandEnvelope('orders.recover','orders.recover','tenant','actor','recover-error',createdAt:'2026-07-16T12:00:00Z'),$codec)) implements PanelCommandFabricStore {
		public function __construct(private array $state){}public function payload():array{return$this->state;}public function transaction(callable $mutation,string $type,array $event=[]):array{throw new RuntimeException('storage unavailable');}public function changesSince(int $cursor=0,int $limit=100):array{return['cursor'=>0,'oldest_cursor'=>0,'reset_required'=>false,'changes'=>[],'snapshot'=>null];}
	};
	$failedRecovery=new PanelCommandFabric($recoveryRegistry,$failingStore,$allowPolicy,$codec,['fabric'=>$key],'fabric',clock:static fn():string=>'2026-07-16T12:10:00Z');$failedScan=$failedRecovery->recoverStale();$t->same(1,count($failedScan['errors']));$t->same('Command recovery failed.',array_values($failedScan['errors'])[0]);
})->tag('panel','fabric','coverage','recovery','policy')->isolation('case')->maxMillis(5000);

test('command journal integrity rejects receipt drift reordered chains false anchors orphan references and incomplete ciphertext',static function(Context $t):void {
	$source=dp_panel_fabric_fixture(['orders.*']);$source['registry']->register('orders.*',static fn(PanelCommandEnvelope $command):PanelCommandOutcome=>PanelCommandOutcome::make(true,[new PanelEventDraft('orders.changed','order',$command->idempotencyHash())]));
	$first=new PanelCommandEnvelope('orders.change','orders.change','tenant','actor','integrity-one',['value'=>1]);$second=new PanelCommandEnvelope('orders.change','orders.change','tenant','actor','integrity-two',['value'=>2]);$source['fabric']->dispatch($first);$source['fabric']->dispatch($second);$state=$source['store']->payload();
	$runtime=static function(array $payload)use($source):array{$store=new PanelInMemoryCommandFabricStore($payload);$fabric=new PanelCommandFabric(new PanelCommandRegistry(),$store,$source['policy'],$source['codec'],['fabric'=>$source['key']],'fabric');return[$fabric,$store];};

	[$statusFabric,$statusStore]=$runtime($state);$corrupt=$state;$firstHash=$first->idempotencyHash();$corrupt['commands'][$firstHash]['status']='denied';$t->nonPublic($statusStore)->writeProperty('state',$corrupt);$t->throws(static fn()=>$statusFabric->verifyIntegrity(),UnexpectedValueException::class);
	[$chainFabric,$chainStore]=$runtime($state);$corrupt=$state;$corrupt['events']=array_reverse($corrupt['events'],true);$t->nonPublic($chainStore)->writeProperty('state',$corrupt);$t->throws(static fn()=>$chainFabric->verifyIntegrity(),UnexpectedValueException::class);
	[$anchorFabric,$anchorStore]=$runtime($state);$corrupt=$state;$corrupt['anchor_hash']=str_repeat('f',64);$t->nonPublic($anchorStore)->writeProperty('state',$corrupt);$t->throws(static fn()=>$anchorFabric->verifyIntegrity(),UnexpectedValueException::class);
	[$referenceFabric,$referenceStore]=$runtime($state);$corrupt=$state;$corrupt['events']=[];$corrupt['sequence']=0;$corrupt['anchor_hash']=str_repeat('0',64);$t->nonPublic($referenceStore)->writeProperty('state',$corrupt);$t->throws(static fn()=>$referenceFabric->verifyIntegrity(),UnexpectedValueException::class);

	[$validFabric]=$runtime($state);$fakeHash=str_repeat('a',64);$t->throws(static fn()=>$t->nonPublic($validFabric)->invoke('commandFromEntry',$fakeHash,['fingerprint'=>str_repeat('b',64)]),UnexpectedValueException::class);
	$entry=['fingerprint'=>$first->fingerprint(),'envelope'=>$first->jsonSerialize(),'sealed'=>$source['codec']->seal($first->sealedPayload(),'fabric.'.$fakeHash.'.'.$first->fingerprint())];$t->throws(static fn()=>$t->nonPublic($validFabric)->invoke('commandFromEntry',$fakeHash,$entry),UnexpectedValueException::class);

	$executing=new PanelCommandEnvelope('orders.change','orders.change','tenant','actor','journal-conflict',['value'=>1]);$conflictStore=new PanelInMemoryCommandFabricStore(dp_panel_fabric_executing_state($executing,$source['codec']));$conflictFabric=new PanelCommandFabric(new PanelCommandRegistry(),$conflictStore,$source['policy'],$source['codec'],['fabric'=>$source['key']],'fabric');$t->throws(static fn()=>$conflictFabric->dispatch(new PanelCommandEnvelope('orders.change','orders.change','tenant','actor','journal-conflict',['value'=>2])),LogicException::class);
})->tag('panel','fabric','coverage','integrity','tamper')->isolation('case')->maxMillis(5000);

test('concurrent claims replay the winning receipt and reject mismatched claim or completion races',static function(Context $t):void {
	$winner=dp_panel_fabric_fixture(['orders.*']);$winner['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$command=new PanelCommandEnvelope('orders.race','orders.race','tenant','actor','race-key',['value'=>1]);$winner['fabric']->dispatch($command);$winnerState=$winner['store']->payload();
	$raceStore=new class($winnerState) implements PanelCommandFabricStore {
		private array $state;public function __construct(private readonly array $raceState){$this->state=PanelCommandFabricState::initial();}
		public function payload():array{return$this->state;}
		public function transaction(callable $mutation,string $type,array $event=[]):array{if($type==='command_claimed'){$this->state=$this->raceState;}$next=$this->state;$result=$mutation($next);$this->state=PanelCommandFabricState::validate($next);return['result'=>$result,'snapshot'=>['sequence'=>0,'payload'=>$this->state,'event'=>[]]];}
		public function changesSince(int $cursor=0,int $limit=100):array{return['cursor'=>0,'oldest_cursor'=>0,'reset_required'=>false,'changes'=>[],'snapshot'=>null];}
	};
	$raceFabric=new PanelCommandFabric(new PanelCommandRegistry(),$raceStore,$winner['policy'],$winner['codec'],['fabric'=>$winner['key']],'fabric');$t->isTrue($raceFabric->dispatch($command)->replay());

	$alternate=dp_panel_fabric_fixture(['orders.*']);$alternate['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$alternateCommand=new PanelCommandEnvelope('orders.race','orders.race','tenant','actor','race-key',['value'=>2]);$alternate['fabric']->dispatch($alternateCommand);$alternateState=$alternate['store']->payload();
	$mismatchStore=new class($alternateState) implements PanelCommandFabricStore {
		private array $state;public function __construct(private readonly array $raceState){$this->state=PanelCommandFabricState::initial();}
		public function payload():array{return$this->state;}
		public function transaction(callable $mutation,string $type,array $event=[]):array{if($type==='command_claimed'){$this->state=$this->raceState;}$next=$this->state;$result=$mutation($next);$this->state=PanelCommandFabricState::validate($next);return['result'=>$result,'snapshot'=>['sequence'=>0,'payload'=>$this->state,'event'=>[]]];}
		public function changesSince(int $cursor=0,int $limit=100):array{return['cursor'=>0,'oldest_cursor'=>0,'reset_required'=>false,'changes'=>[],'snapshot'=>null];}
	};
	$mismatchFabric=new PanelCommandFabric(new PanelCommandRegistry(),$mismatchStore,$winner['policy'],$winner['codec'],['fabric'=>$winner['key']],'fabric');$t->throws(static fn()=>$mismatchFabric->dispatch($command),LogicException::class);

	$alternateReceipt=$alternateState['receipts'][$alternateCommand->idempotencyHash()];$completionStore=new class($alternateReceipt) implements PanelCommandFabricStore {
		private array $state;public function __construct(private readonly array $foreignReceipt){$this->state=PanelCommandFabricState::initial();}
		public function payload():array{return$this->state;}
		public function transaction(callable $mutation,string $type,array $event=[]):array{if($type==='command_succeeded'){return['result'=>$this->foreignReceipt,'snapshot'=>['sequence'=>0,'payload'=>$this->state,'event'=>[]]];}$next=$this->state;$result=$mutation($next);$this->state=PanelCommandFabricState::validate($next);return['result'=>$result,'snapshot'=>['sequence'=>0,'payload'=>$this->state,'event'=>[]]];}
		public function changesSince(int $cursor=0,int $limit=100):array{return['cursor'=>0,'oldest_cursor'=>0,'reset_required'=>false,'changes'=>[],'snapshot'=>null];}
	};
	$completionRegistry=new PanelCommandRegistry();$completionRegistry->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true));$completionFabric=new PanelCommandFabric($completionRegistry,$completionStore,$winner['policy'],$winner['codec'],['fabric'=>$winner['key']],'fabric');$t->throws(static fn()=>$completionFabric->dispatch(new PanelCommandEnvelope('orders.race','orders.race','tenant','actor','completion-race',['value'=>3])),UnexpectedValueException::class);
})->tag('panel','fabric','coverage','concurrency','idempotency')->isolation('case')->maxMillis(5000);

test('subscriber ownership reports renewal loss pending faults release failures and projection retries',static function(Context $t):void {
	$source=dp_panel_fabric_fixture(['orders.*']);$source['registry']->register('orders.*',static fn():PanelCommandOutcome=>PanelCommandOutcome::make(true,[new PanelEventDraft('orders.changed','order','subscriber-order')]));$source['fabric']->dispatch(new PanelCommandEnvelope('orders.change','orders.change','tenant','actor','subscriber-event'));$state=$source['store']->payload();
	$leasedStore=static function(string $mode)use($state):PanelLeasedCommandFabricStore{return new class($state,$mode) implements PanelLeasedCommandFabricStore {
		private PanelInMemoryCommandFabricStore $inner;public function __construct(array $state,private readonly string $mode){$this->inner=new PanelInMemoryCommandFabricStore($state);}
		public function payload():array{return$this->inner->payload();}public function transaction(callable $mutation,string $type,array $event=[]):array{return$this->inner->transaction($mutation,$type,$event);}public function changesSince(int $cursor=0,int $limit=100):array{return$this->inner->changesSince($cursor,$limit);}public function currentTime():string{return'2026-07-16T12:00:00.000000Z';}
		public function acquireSubscriberLease(string $subscriber,string $worker='worker',int $ttlSeconds=60):?PanelCommandFabricSubscriberLease{return PanelCommandFabricSubscriberLease::make($subscriber,$worker,str_repeat('t',32),1,'2026-07-16T12:00:00.000000Z','2026-07-16T12:10:00.000000Z','2026-07-16T12:00:00.000000Z');}
		public function inspectSubscriberLease(PanelCommandFabricSubscriberLease $lease):PanelCommandFabricSubscriberLease{return$lease;}
		public function renewSubscriberLease(PanelCommandFabricSubscriberLease $lease,int $ttlSeconds=60):PanelCommandFabricSubscriberLease{if($this->mode==='renew_lost'){throw new PanelCommandFabricLeaseLost($lease->subscriber());}if($this->mode==='renew_error'){throw new RuntimeException('renewal failed');}return$lease;}
		public function advanceSubscriberCursor(PanelCommandFabricSubscriberLease $lease,int $sequence):void{}
		public function releaseSubscriberLease(PanelCommandFabricSubscriberLease $lease):void{if($this->mode==='release_lost'){throw new PanelCommandFabricLeaseLost($lease->subscriber());}}
		public function activeSubscriberLeaseManifests():array{return[];}
	};};
	$makeFabric=static function(PanelLeasedCommandFabricStore $store)use($source):PanelCommandFabric{return new PanelCommandFabric(new PanelCommandRegistry(),$store,$source['policy'],$source['codec'],['fabric'=>$source['key']],'fabric',subscriberWorker:'subscriber-worker');};
	$lost=$makeFabric($leasedStore('renew_lost'));$lost->subscribe('projection','orders.*',static fn():bool=>true);$lostResult=$lost->drainSubscriber('projection');$t->isFalse($lostResult['ok']);$t->same('lease_lost',$lostResult['error_code']);
	$pending=$makeFabric($leasedStore('renew_error'));$pending->subscribe('projection','orders.*',static fn():bool=>true);$t->throws(static fn()=>$pending->drainSubscriber('projection'),RuntimeException::class);
	$release=$makeFabric($leasedStore('release_lost'));$release->subscribe('projection','orders.*',static fn():bool=>true);$releaseResult=$release->drainSubscriber('projection');$t->isFalse($releaseResult['ok']);$t->same('lease_release_failed',$releaseResult['error_code']);

	$source['fabric']->subscribe('throwing','orders.*',static function():never{throw new RuntimeException('projection failed');});$projection=$source['fabric']->drainSubscriber('throwing');$t->isFalse($projection['ok']);$t->contains('projection failed',strtolower($projection['error']));
	$manualLease=PanelCommandFabricSubscriberLease::make('manual','worker',str_repeat('m',32),1,'2026-07-16T12:00:00.000000Z','2026-07-16T12:10:00.000000Z');$descriptor=['patterns'=>['orders.*'],'handler'=>static fn():bool=>true];$t->throws(static fn()=>$t->nonPublic($source['fabric'])->invoke('drainOwnedSubscriber','manual',$descriptor,0,1,$manualLease),LogicException::class);
})->tag('panel','fabric','coverage','subscriber','leases')->isolation('case')->maxMillis(5000);
