<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\InMemoryPanelAgentWorkflowStore;
use Dataphyre\Panel\PanelAgentCallbackWorkflowJobResolver;
use Dataphyre\Panel\PanelAgentConfirmationVerifier;
use Dataphyre\Panel\PanelAgentDeferredExecution;
use Dataphyre\Panel\PanelAgentException;
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
use Dataphyre\Panel\PanelAgentWorkflowJob;
use Dataphyre\Panel\PanelAgentWorkflowOperationBridge;
use Dataphyre\Panel\PanelAgentWorkflowWorkerContext;
use Dataphyre\Panel\PanelAgentWorkflowStore;
use Dataphyre\Panel\PanelAtomicAgentWorkflowStore;
use Dataphyre\Panel\PanelAtomicLeasedOperationStore;
use Dataphyre\Panel\PanelLeasedOperationRunner;
use Dataphyre\Panel\PanelOperationHandlerRegistry;
use Dataphyre\Panel\PanelOperationLease;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelOperationStatus;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelDeferredWorkerPolicy implements PanelAgentPolicyResolver {
	public bool $allowed=true;
	public string $reason='Host policy allowed the bounded operation.';
	private readonly string $fingerprint;
	public function __construct(){ $this->fingerprint=hash('sha256','dp-panel-deferred-worker-policy-v1'); }
	public function decide(PanelAgentRequestContext $context,PanelAgentTool $tool,array $arguments):PanelAgentPolicyDecision {
		return $this->allowed ? PanelAgentPolicyDecision::allow($this->reason) : PanelAgentPolicyDecision::deny($this->reason);
	}
	public function approve(PanelAgentRequestContext $approver,PanelAgentPlan $plan):PanelAgentPolicyDecision { return PanelAgentPolicyDecision::allow('Independent approver accepted the exact plan.'); }
	public function fingerprint():string { return $this->fingerprint; }
}

final class DpPanelDeferredWorkerExecutor implements PanelAgentToolExecutor {
	public int $calls=0;
	public ?Closure $after=null;
	public function execute(PanelAgentToolExecutionRequest $request):PanelAgentToolExecutionResult {
		$this->calls++;
		if($this->after instanceof Closure){ ($this->after)($request); }
		return PanelAgentToolExecutionResult::success(['order_id'=>$request->arguments()['order_id'],'access_token'=>'executor-secret']);
	}
}

final class DpPanelDeferredConfirmation implements PanelAgentConfirmationVerifier {
	private readonly string $fingerprint;
	private readonly string $secret;
	public function __construct(){ $this->fingerprint=hash('sha256','dp-panel-deferred-confirmation-v1'); $this->secret=str_repeat('c',32); }
	public function fingerprint():string { return $this->fingerprint; }
	public function evidence(PanelAgentRequestContext $context,PanelAgentPlan $plan):string {
		return 'confirmation.'.hash_hmac('sha256',$plan->hash()."\0".$context->scopeFingerprint(),$this->secret);
	}
	public function verify(PanelAgentRequestContext $context,PanelAgentPlan $plan,string $evidence):bool { return hash_equals($this->evidence($context,$plan),$evidence); }
}

/** @return array<string,mixed> */
function dp_panel_deferred_worker_fixture(Context $t,string $name='fixture',string $risk='low',bool $durable=false):array {
	$now=1784030400;
	$clock=static function()use(&$now):int{return $now;};
	$store=$durable
		? new PanelAtomicAgentWorkflowStore($t->tempDirectory('panel-agent-worker-'.$name),$clock,120,64,3600,8)
		: new InMemoryPanelAgentWorkflowStore($clock,120,64);
	$policyResolver=new DpPanelDeferredWorkerPolicy();
	$policy=new PanelAgentPolicyEngine($policyResolver);
	$executor=new DpPanelDeferredWorkerExecutor();
	$catalog=new PanelAgentToolCatalog();
	$catalog->register(new PanelAgentTool(
		'orders.defer','2026.7','Execute one bounded deferred order operation.','orders.defer',$risk,true,false,0,false,
		['type'=>'object','required'=>['order_id'],'additionalProperties'=>false,'properties'=>['order_id'=>['type'=>'string','pattern'=>'/^ord-[0-9]+$/']]],
	),$executor,'test');
	$signer=new PanelAgentIntentSigner(['current'=>str_repeat('k',32)],'current',$clock,0);
	$confirmation=new DpPanelDeferredConfirmation();
	$runtime=new PanelAgentRuntime($catalog,$policy,$signer,$store,$clock,$confirmation);
	$context=new PanelAgentRequestContext('operations','tenant-a','operator:1','session-a','request-'.$name);
	$envelope=$runtime->prepare(['title'=>'Customer supplied title must not enter the queue','steps'=>[['tool'=>'orders.defer','arguments'=>['order_id'=>'ord-7']]]],$context,$catalog->revision(),$store->revision(),300);
	$approvals=[]; $revision=$envelope->storeRevision();
	if($envelope->plan()->approvalCount()>0){
		$approver=new PanelAgentRequestContext('operations','tenant-a','operator:2','session-b','approval-'.$name);
		$approval=$runtime->approve($envelope->plan(),$envelope->intent()->token(),$context,$approver,$revision,300);
		$approvals[]=$approval->intent()->token(); $revision=$approval->storeRevision();
	}
	$evidence=$envelope->plan()->confirmationRequired() ? $confirmation->evidence($context,$envelope->plan()) : null;
	$idempotency='private-idempotency-'.$name;
	$deferred=new PanelAgentDeferredExecution($envelope->plan(),$envelope->intent()->token(),$context,$approvals,$idempotency,$revision,$evidence,$envelope->intent()->expiresAt());
	return compact('runtime','catalog','policy','policyResolver','store','signer','executor','confirmation','context','envelope','approvals','evidence','idempotency','deferred','now','clock');
}

/** @return array{store:PanelAtomicLeasedOperationStore,handlers:PanelOperationHandlerRegistry,runner:PanelLeasedOperationRunner,advance:Closure,directory:string,tokens:Closure} */
function dp_panel_deferred_operation_runtime(Context $t,string $name):array {
	$epoch=1784030400; $sequence=0; $issuedTokens=[];
	$clock=static function()use(&$epoch):string{return gmdate(DATE_ATOM,$epoch);};
	$advance=static function(int $seconds)use(&$epoch):void{$epoch+=$seconds;};
	$tokenFactory=static function()use(&$sequence,&$issuedTokens):string{$token=hash('sha256','dp-panel-operation-token-'.(++$sequence));$issuedTokens[]=$token;return$token;};
	$directory=$t->tempDirectory('panel-agent-operation-'.$name);
	$store=new PanelAtomicLeasedOperationStore($directory,64,$clock,$tokenFactory);
	$handlers=new PanelOperationHandlerRegistry();
	$runner=new PanelLeasedOperationRunner($store,$handlers,5);
	$tokens=static function()use(&$issuedTokens):array{return$issuedTokens;};
	return compact('store','handlers','runner','advance','directory','tokens');
}

function dp_panel_deferred_error(Context $t,callable $callback,string $code):PanelAgentException {
	try{$callback();}catch(PanelAgentException $error){$t->same($code,$error->errorCode());return$error;}
	throw new RuntimeException("Expected PanelAgentException {$code}.");
}

function dp_panel_deferred_worker_context(PanelAgentWorkflowJob $job):PanelAgentWorkflowWorkerContext {
	$at='2026-07-14T12:00:00Z';
	$record=PanelOperationRecord::make(PanelAgentWorkflowOperationBridge::OPERATION_TYPE,$job->name(),['id'=>'agent-worker-context','queue'=>$job->queue(),'max_attempts'=>$job->maxAttempts(),'created_at'=>$at])->start('worker-a',$at);
	$lease=PanelOperationLease::make($record->id(),'worker-a',str_repeat('l',48),3,$at,'2026-07-14T12:01:00Z');
	return PanelAgentWorkflowWorkerContext::fromOperation($record,$lease,$job);
}

suite('Panel agent workflows on leased operation workers')
	->contract('panel.agent-workflow-operations',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('agent-runtime','secure-resolver','leased-operations','fencing','idempotency')
	->isolation('case')
	->tag('panel','agents','operations','workers','security')
	->group('framework-coverage');

test('deferred execution and queued job values commit authority without serializing it',static function(Context $t):void{
	$low=dp_panel_deferred_worker_fixture($t,'values-low'); $deferred=$low['deferred'];
	$t->same($low['envelope']->plan(),$deferred->plan()); $t->same($low['envelope']->intent()->token(),$deferred->planIntent());
	$t->same($low['context'],$deferred->context()); $t->same([],$deferred->approvalIntents()); $t->same($low['idempotency'],$deferred->idempotencyKey());
	$t->same($low['envelope']->storeRevision(),$deferred->expectedStoreRevision()); $t->same(null,$deferred->confirmationEvidence());
	$t->same($low['envelope']->intent()->expiresAt(),$deferred->expiresAt()); $t->isFalse($deferred->expired($low['now'])); $t->isTrue($deferred->expired($deferred->expiresAt()));
	$t->throws(static fn()=>$deferred->expired(-1),InvalidArgumentException::class);
	$manifest=$deferred->jsonSerialize(); $encoded=json_encode($manifest,JSON_THROW_ON_ERROR);
	$t->same('panel_agent_deferred_execution',$manifest['type']); $t->isFalse($manifest['sensitive_material_serialized']); $t->isFalse($manifest['revision_committed_by_fingerprint']);
	foreach([$deferred->planIntent(),$deferred->idempotencyKey(),$low['context']->tenant(),$low['context']->principal()] as $secret){$t->notContains($secret,$encoded);}
	$refreshed=new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),$deferred->context(),[],$deferred->idempotencyKey(),999,null,$deferred->expiresAt());
	$t->same($deferred->fingerprint(),$refreshed->fingerprint()); $t->same(999,$refreshed->expectedStoreRevision());

	$high=dp_panel_deferred_worker_fixture($t,'values-high','high'); $guarded=$high['deferred'];
	$t->same(1,count($guarded->approvalIntents())); $t->same($high['evidence'],$guarded->confirmationEvidence()); $t->isTrue($guarded->jsonSerialize()['confirmation_required']);
	$highEncoded=json_encode($guarded,JSON_THROW_ON_ERROR);
	$t->notContains($guarded->approvalIntents()[0],$highEncoded); $t->notContains((string)$guarded->confirmationEvidence(),$highEncoded);

	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),new PanelAgentRequestContext('operations','tenant-b','operator:1','session-a','other-scope'),[],'key',0,null,$deferred->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),'',$deferred->context(),[],'key',0,null,$deferred->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($guarded->plan(),$guarded->planIntent(),$guarded->context(),['approval'=>$guarded->approvalIntents()[0]],'key',0,$guarded->confirmationEvidence(),$guarded->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($guarded->plan(),$guarded->planIntent(),$guarded->context(),[1],'key',0,$guarded->confirmationEvidence(),$guarded->expiresAt()),Throwable::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),$deferred->context(),[],'',0,null,$deferred->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),$deferred->context(),[],'key',-1,null,$deferred->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),$deferred->context(),[],'key',0,'unexpected',$deferred->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($guarded->plan(),$guarded->planIntent(),$guarded->context(),$guarded->approvalIntents(),'key',0,null,$guarded->expiresAt()),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentDeferredExecution($deferred->plan(),$deferred->planIntent(),$deferred->context(),[],'key',0,null,0),InvalidArgumentException::class);

	$resolverFingerprint=hash('sha256','resolver-values');
	$job=PanelAgentWorkflowJob::make('vault://tenant-a/raw-reference',$deferred,$resolverFingerprint);
	$t->same(64,strlen($job->reference())); $t->notContains('tenant-a',$job->reference()); $t->same($deferred->fingerprint(),$job->executionFingerprint());
	$t->same($resolverFingerprint,$job->resolverFingerprint()); $t->same($deferred->plan()->hash(),$job->planHash()); $t->same($deferred->context()->scopeFingerprint(),$job->scopeFingerprint());
	$t->same($deferred->expiresAt(),$job->expiresAt()); $t->same('agent_workflows',$job->queue()); $t->same('Deferred agent workflow',$job->name()); $t->same(3,$job->maxAttempts());
	$t->same('panel_agent_workflow:'.$job->fingerprint(),$job->operationIdempotencyKey()); $t->isFalse($job->expired($low['now'])); $t->isTrue($job->expired($job->expiresAt()));
	$t->throws(static fn()=>$job->expired(-1),InvalidArgumentException::class);
	$jobPayload=$job->jsonSerialize(); $roundTrip=PanelAgentWorkflowJob::fromArray($jobPayload); $t->same($jobPayload,$roundTrip->jsonSerialize());
	$jobJson=json_encode($jobPayload,JSON_THROW_ON_ERROR); $t->notContains('vault://',$jobJson); $t->notContains($deferred->planIntent(),$jobJson); $t->notContains($deferred->idempotencyKey(),$jobJson);
	$custom=PanelAgentWorkflowJob::make('custom-reference',$deferred,$resolverFingerprint,['queue'=>'urgent_agents','name'=>'Approved order operation','max_attempts'=>20]);
	$t->same('urgent_agents',$custom->queue()); $t->same('Approved order operation',$custom->name()); $t->same(20,$custom->maxAttempts());
	$t->throws(static fn()=>PanelAgentWorkflowJob::make('ref',$deferred,$resolverFingerprint,['unknown'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentWorkflowJob::make('',$deferred,$resolverFingerprint),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentWorkflowJob::make('ref',$deferred,'bad'),InvalidArgumentException::class);
	$invalid=$jobPayload; unset($invalid['type']); $t->throws(static fn()=>PanelAgentWorkflowJob::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$jobPayload; $invalid['expires_at']=0; $t->throws(static fn()=>PanelAgentWorkflowJob::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$jobPayload; $invalid['max_attempts']=0; $t->throws(static fn()=>PanelAgentWorkflowJob::fromArray($invalid),InvalidArgumentException::class);
	$invalid=$jobPayload; $invalid['job_fingerprint']=str_repeat('0',64); $t->throws(static fn()=>PanelAgentWorkflowJob::fromArray($invalid),InvalidArgumentException::class);
})->tag('panel','agents','workers','values','secrets')->maxMillis(10000);

test('resolver callbacks receive only a fenced secret-free worker claim',static function(Context $t):void{
	$fixture=dp_panel_deferred_worker_fixture($t,'resolver'); $job=PanelAgentWorkflowJob::make('resolver-record',$fixture['deferred'],hash('sha256','resolver-context'));
	$context=dp_panel_deferred_worker_context($job); $manifest=$context->jsonSerialize();
	$t->same('agent-worker-context',$context->operationId()); $t->same(1,$context->attempt()); $t->same('worker-a',$context->worker()); $t->same(3,$context->fence());
	$t->same($job->queue(),$context->queue()); $t->same($job->fingerprint(),$context->jobFingerprint()); $t->same(64,strlen($context->claimFingerprint()));
	$t->isFalse($manifest['lease_token_exposed']); $t->notContains(str_repeat('l',48),json_encode($manifest,JSON_THROW_ON_ERROR));
	$calls=0; $resolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-context'),static function(PanelAgentWorkflowJob $received,PanelAgentWorkflowWorkerContext $claim)use(&$calls,$job,$context,$fixture):PanelAgentDeferredExecution{
		$calls++; if($received->fingerprint()!==$job->fingerprint() || $claim->claimFingerprint()!==$context->claimFingerprint()){throw new LogicException('wrong resolver inputs');} return $fixture['deferred'];
	});
	$t->same(hash('sha256','resolver-context'),$resolver->fingerprint()); $t->same($fixture['deferred'],$resolver->resolve($job,$context)); $t->same(1,$calls);
	$resolverManifest=$resolver->jsonSerialize(); $t->isFalse($resolverManifest['callback_serialized']); $t->isTrue($resolverManifest['runtime_resolver_installed']); $t->same('host',$resolverManifest['storage_authority']);
	$t->throws(static fn()=>new PanelAgentCallbackWorkflowJobResolver('bad',static fn()=>null),InvalidArgumentException::class);
	$wrong=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','wrong-return'),static fn()=>['not'=>'execution']); $t->throws(static fn()=>$wrong->resolve($job,$context),UnexpectedValueException::class);

	$at='2026-07-14T12:00:00Z'; $lease=PanelOperationLease::make('different-id','worker-a',str_repeat('m',48),1,$at,'2026-07-14T12:01:00Z');
	$record=PanelOperationRecord::make('panel_agent_workflow','Context',['id'=>'agent-worker-context','queue'=>$job->queue(),'created_at'=>$at])->start('worker-a',$at);
	$t->throws(static fn()=>PanelAgentWorkflowWorkerContext::fromOperation($record,$lease,$job),InvalidArgumentException::class);
	$wrongWorkerLease=PanelOperationLease::make($record->id(),'worker-b',str_repeat('n',48),1,$at,'2026-07-14T12:01:00Z');
	$t->throws(static fn()=>PanelAgentWorkflowWorkerContext::fromOperation($record,$wrongWorkerLease,$job),InvalidArgumentException::class);
	$wrongQueue=PanelOperationRecord::make('panel_agent_workflow','Context',['id'=>'wrong-queue','queue'=>'other_queue','created_at'=>$at])->start('worker-a',$at);
	$wrongQueueLease=PanelOperationLease::make('wrong-queue','worker-a',str_repeat('o',48),1,$at,'2026-07-14T12:01:00Z');
	$t->throws(static fn()=>PanelAgentWorkflowWorkerContext::fromOperation($wrongQueue,$wrongQueueLease,$job),InvalidArgumentException::class);
	$queued=PanelOperationRecord::make('panel_agent_workflow','Context',['id'=>'queued-context','queue'=>$job->queue(),'created_at'=>$at]);
	$queuedLease=PanelOperationLease::make('queued-context','worker-a',str_repeat('p',48),1,$at,'2026-07-14T12:01:00Z');
	$t->throws(static fn()=>PanelAgentWorkflowWorkerContext::fromOperation($queued,$queuedLease,$job),InvalidArgumentException::class);
})->tag('panel','agents','workers','resolver','fencing')->maxMillis(10000);

test('leased worker executes approval and confirmation protected jobs without persisting authority',static function(Context $t):void{
	$fixture=dp_panel_deferred_worker_fixture($t,'success','high'); $operations=dp_panel_deferred_operation_runtime($t,'success'); $resolverCalls=0; $workerClaim=null;
	$resolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-success'),static function(PanelAgentWorkflowJob $job,PanelAgentWorkflowWorkerContext $context)use(&$resolverCalls,&$workerClaim,$fixture):PanelAgentDeferredExecution{$resolverCalls++;$workerClaim=$context;return$fixture['deferred'];});
	$bridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$resolver,static fn():int=>$fixture['now']);
	$t->same($bridge,$bridge->register($operations['handlers'])); $bridge->register($operations['handlers'],true);
	$job=$bridge->job('secure-repository://tenant-a/order-7',$fixture['deferred'],['queue'=>'protected_agents','name'=>'Protected order operation','max_attempts'=>3]);
	$submitted=$bridge->submit($operations['runner'],$job,'protected-agent-operation'); $duplicate=$bridge->submit($operations['runner'],$job,'ignored-duplicate-id');
	$t->same($submitted->id(),$duplicate->id()); $t->same(PanelOperationStatus::QUEUED,$submitted->status()); $t->same('protected_agents',$submitted->queue());
	$record=$operations['runner']->work('protected_agents',1,'agent-worker-a')[0];
	$t->same(PanelOperationStatus::COMPLETED,$record->status()); $t->same(1,$record->attempt()); $t->same(1,$record->processed()); $t->same(1,$record->succeeded());
	$t->same(1,$resolverCalls); $t->same(1,$fixture['executor']->calls); $t->isTrue($workerClaim instanceof PanelAgentWorkflowWorkerContext);
	$t->same('executed',$record->result()['agent_result']['code']); $t->isFalse($record->result()['agent_result']['replayed']);
	$t->same('[REDACTED]',$record->result()['agent_result']['steps'][0]['output']['access_token']); $t->isFalse($record->result()['sensitive_execution_material_persisted']);
	$recordJson=json_encode($record,JSON_THROW_ON_ERROR);
	foreach([$fixture['deferred']->planIntent(),$fixture['deferred']->approvalIntents()[0],$fixture['idempotency'],(string)$fixture['evidence'],'secure-repository://tenant-a/order-7'] as $secret){$t->notContains($secret,$recordJson);}
	$bridgeManifest=$bridge->jsonSerialize(); $t->same('at_least_once',$bridgeManifest['delivery']); $t->same('lease_fenced',$bridgeManifest['operation_ownership']); $t->same('renewable_fenced',$bridgeManifest['agent_execution_ownership']);
	$t->isFalse($bridgeManifest['sensitive_execution_material_persisted']); $t->isFalse($bridgeManifest['worker_process_installed']);
})->tag('panel','agents','workers','approval','confirmation','success')->maxMillis(15000);

test('lease loss after agent completion retries through durable exactly-idempotent replay',static function(Context $t):void{
	$fixture=dp_panel_deferred_worker_fixture($t,'lease-loss','low',true); $operations=dp_panel_deferred_operation_runtime($t,'lease-loss'); $resolverCalls=0;
	$fixture['executor']->after=static function()use($operations):void{static $advanced=false;if(!$advanced){$advanced=true;($operations['advance'])(6);}};
	$resolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-lease-loss'),static function(PanelAgentWorkflowJob $job,PanelAgentWorkflowWorkerContext $context)use(&$resolverCalls,$fixture):PanelAgentDeferredExecution{
		$resolverCalls++;
		return new PanelAgentDeferredExecution($fixture['deferred']->plan(),$fixture['deferred']->planIntent(),$fixture['deferred']->context(),$fixture['deferred']->approvalIntents(),$fixture['deferred']->idempotencyKey(),$fixture['store']->revision(),$fixture['deferred']->confirmationEvidence(),$fixture['deferred']->expiresAt());
	});
	$bridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$resolver,static fn():int=>$fixture['now']); $bridge->register($operations['handlers']);
	$job=$bridge->job('durable-secret-reference',$fixture['deferred'],['max_attempts'=>3]); $bridge->submit($operations['runner'],$job,'lease-loss-agent-operation');
	$first=$operations['runner']->work($job->queue(),1,'crashing-worker')[0];
	$t->same(PanelOperationStatus::RUNNING,$first->status()); $t->same(1,$fixture['executor']->calls); $t->same(1,$resolverCalls);
	$second=$operations['runner']->work($job->queue(),1,'recovery-worker')[0];
	$t->same(PanelOperationStatus::COMPLETED,$second->status()); $t->same(2,$second->attempt()); $t->same(1,$fixture['executor']->calls); $t->same(2,$resolverCalls);
	$t->same('idempotent_replay',$second->result()['agent_result']['code']); $t->isTrue($second->result()['agent_result']['replayed']); $t->same('Deferred agent workflow recovered.',$second->progressMessage());
	$operationBytes=''; foreach(glob($operations['directory'].DIRECTORY_SEPARATOR.'*.json')?:[] as $file){$operationBytes.=(string)file_get_contents($file);}
	$t->notContains($fixture['deferred']->planIntent(),$operationBytes); $t->notContains($fixture['idempotency'],$operationBytes); $t->notContains('durable-secret-reference',$operationBytes);
	foreach(($operations['tokens'])() as $token){$t->notContains($token,$operationBytes);}
})->tag('panel','agents','workers','leases','recovery','idempotency')->maxMillis(20000);

test('worker bridge fails closed on malformed stale expired mismatched and leaking inputs',static function(Context $t):void{
	$fixture=dp_panel_deferred_worker_fixture($t,'failures');

	$malformedOps=dp_panel_deferred_operation_runtime($t,'malformed'); $malformedCalls=0;
	$malformedResolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-malformed'),static function()use(&$malformedCalls):never{$malformedCalls++;throw new LogicException('must not resolve');});
	$malformedBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$malformedResolver,static fn():int=>$fixture['now']); $malformedBridge->register($malformedOps['handlers']);
	$malformedOps['runner']->submit(PanelAgentWorkflowOperationBridge::OPERATION_TYPE,'Malformed',['wrong'=>true],['id'=>'malformed-shape','max_attempts'=>1,'created_at'=>$malformedOps['store']->currentTime()]);
	$malformed=$malformedOps['runner']->work(null,1,'malformed-worker')[0]; $t->same(PanelOperationStatus::FAILED,$malformed->status()); $t->same(0,$malformedCalls);
	$malformedOps['runner']->submit(PanelAgentWorkflowOperationBridge::OPERATION_TYPE,'Malformed job',['job'=>['bad'=>true]],['id'=>'malformed-job','max_attempts'=>1,'created_at'=>$malformedOps['store']->currentTime()]);
	$t->same(PanelOperationStatus::FAILED,$malformedOps['runner']->work(null,1,'malformed-worker')[0]->status()); $t->same(0,$malformedCalls);

	$staleJob=PanelAgentWorkflowJob::make('stale-resolver',$fixture['deferred'],hash('sha256','old-resolver'));
	dp_panel_deferred_error($t,static fn()=>$malformedBridge->submit($malformedOps['runner'],$staleJob),'worker_resolver_stale');
	$invalidClock=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$malformedResolver,static fn():string=>'invalid');
	$t->throws(static fn()=>$invalidClock->submit($malformedOps['runner'],$invalidClock->job('clock',$fixture['deferred'])),UnexpectedValueException::class);

	$expiryNow=$fixture['now']; $expiryOps=dp_panel_deferred_operation_runtime($t,'expiry'); $expiryResolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-expiry'),static fn()=>$fixture['deferred']);
	$expiryBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$expiryResolver,static function()use(&$expiryNow):int{return$expiryNow;}); $expiryBridge->register($expiryOps['handlers']);
	$expiryJob=$expiryBridge->job('expiry',$fixture['deferred'],['max_attempts'=>1]); $expiryBridge->submit($expiryOps['runner'],$expiryJob,'expires-in-worker'); $expiryNow=$expiryJob->expiresAt();
	$t->same(PanelOperationStatus::FAILED,$expiryOps['runner']->work(null,1,'expiry-worker')[0]->status());
	dp_panel_deferred_error($t,static fn()=>$expiryBridge->submit($expiryOps['runner'],$expiryJob),'worker_job_expired');

	$mismatchOps=dp_panel_deferred_operation_runtime($t,'material-mismatch');
	$otherMaterial=new PanelAgentDeferredExecution($fixture['deferred']->plan(),$fixture['deferred']->planIntent(),$fixture['deferred']->context(),[],'different-private-key',$fixture['deferred']->expectedStoreRevision(),null,$fixture['deferred']->expiresAt());
	$mismatchResolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-mismatch'),static fn()=>$otherMaterial);
	$mismatchBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$mismatchResolver,static fn():int=>$fixture['now']); $mismatchBridge->register($mismatchOps['handlers']);
	$mismatchJob=$mismatchBridge->job('mismatch',$fixture['deferred'],['max_attempts'=>1]); $mismatchBridge->submit($mismatchOps['runner'],$mismatchJob,'material-mismatch');
	$mismatch=$mismatchOps['runner']->work(null,1,'mismatch-worker')[0]; $t->same(PanelOperationStatus::FAILED,$mismatch->status()); $t->same(0,$fixture['executor']->calls);

	$leakOps=dp_panel_deferred_operation_runtime($t,'resolver-leak'); $resolverSecret='resolver-plain-swordfish';
	$leakingResolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-leak'),static fn()=>throw new PanelAgentException('resolver_private_failure','Resolver leaked '.$resolverSecret,503));
	$leakBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$leakingResolver,static fn():int=>$fixture['now']); $leakBridge->register($leakOps['handlers']);
	$leakJob=$leakBridge->job('resolver-leak',$fixture['deferred'],['max_attempts'=>1]); $leakBridge->submit($leakOps['runner'],$leakJob,'resolver-leak');
	$leakRecord=$leakOps['runner']->work(null,1,'leak-worker')[0]; $leakJson=json_encode($leakRecord,JSON_THROW_ON_ERROR);
	$t->same(PanelOperationStatus::FAILED,$leakRecord->status()); $t->notContains($resolverSecret,$leakJson); $t->contains('Deferred Panel agent workflow material could not be resolved.',$leakJson);

	$runtimeOps=dp_panel_deferred_operation_runtime($t,'runtime-leak'); $runtimeResolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','runtime-leak'),static fn()=>$fixture['deferred']);
	$runtimeBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$runtimeResolver,static fn():int=>$fixture['now']); $runtimeBridge->register($runtimeOps['handlers']);
	$runtimeJob=$runtimeBridge->job('runtime-leak',$fixture['deferred'],['max_attempts'=>1]); $runtimeBridge->submit($runtimeOps['runner'],$runtimeJob,'runtime-leak');
	$fixture['policyResolver']->allowed=false; $fixture['policyResolver']->reason='policy-plain-swordfish';
	$runtimeRecord=$runtimeOps['runner']->work(null,1,'runtime-worker')[0]; $runtimeJson=json_encode($runtimeRecord,JSON_THROW_ON_ERROR);
	$t->same(PanelOperationStatus::FAILED,$runtimeRecord->status()); $t->notContains('policy-plain-swordfish',$runtimeJson); $t->contains('Deferred Panel agent workflow execution failed closed.',$runtimeJson);
})->tag('panel','agents','workers','fail-closed','adversarial')->maxMillis(30000);

test('queued commitments reject copied payloads and idempotency collisions before resolution',static function(Context $t):void{
	$fixture=dp_panel_deferred_worker_fixture($t,'envelope'); $ops=dp_panel_deferred_operation_runtime($t,'envelope'); $calls=0;
	$resolver=new PanelAgentCallbackWorkflowJobResolver(hash('sha256','resolver-envelope'),static function()use(&$calls,$fixture):PanelAgentDeferredExecution{$calls++;return$fixture['deferred'];});
	$bridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$resolver,static fn():int=>$fixture['now']); $bridge->register($ops['handlers']);
	$job=$bridge->job('envelope-reference',$fixture['deferred'],['queue'=>'committed_queue','name'=>'Committed workflow','max_attempts'=>1]);
	$ops['runner']->submit(PanelAgentWorkflowOperationBridge::OPERATION_TYPE,'Copied workflow',['job'=>$job->jsonSerialize()],['id'=>'copied-envelope','queue'=>'committed_queue','max_attempts'=>1,'idempotency_key'=>$job->operationIdempotencyKey().'-copy','total'=>1,'created_at'=>$ops['store']->currentTime()]);
	$copied=$ops['runner']->work('committed_queue',1,'copied-worker')[0]; $t->same(PanelOperationStatus::FAILED,$copied->status()); $t->same(0,$calls);

	$collisionOps=dp_panel_deferred_operation_runtime($t,'collision'); $collisionBridge=new PanelAgentWorkflowOperationBridge($fixture['runtime'],$resolver,static fn():int=>$fixture['now']); $collisionBridge->register($collisionOps['handlers']);
	$collisionOps['runner']->submit(PanelAgentWorkflowOperationBridge::OPERATION_TYPE,'Forged collision',['forged'=>true],['id'=>'forged-collision','queue'=>$job->queue(),'max_attempts'=>$job->maxAttempts(),'idempotency_key'=>$job->operationIdempotencyKey(),'total'=>1,'created_at'=>$collisionOps['store']->currentTime()]);
	dp_panel_deferred_error($t,static fn()=>$collisionBridge->submit($collisionOps['runner'],$job),'worker_operation_mismatch'); $t->same(0,$calls);
})->tag('panel','agents','workers','commitments','tampering')->maxMillis(15000);
