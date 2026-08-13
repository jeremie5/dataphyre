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
use Dataphyre\Panel\PanelAgentApprovalEnvelope;
use Dataphyre\Panel\PanelAgentAuditReceipt;
use Dataphyre\Panel\PanelAgentAutomationToolExecutor;
use Dataphyre\Panel\PanelAgentConfirmationVerifier;
use Dataphyre\Panel\PanelAgentException;
use Dataphyre\Panel\PanelAgentExecutionResult;
use Dataphyre\Panel\PanelAgentGuard;
use Dataphyre\Panel\PanelAgentIntentSigner;
use Dataphyre\Panel\PanelAgentIntentVerification;
use Dataphyre\Panel\PanelAgentPlan;
use Dataphyre\Panel\PanelAgentPlanEnvelope;
use Dataphyre\Panel\PanelAgentPlanStep;
use Dataphyre\Panel\PanelAgentPolicyDecision;
use Dataphyre\Panel\PanelAgentPolicyEngine;
use Dataphyre\Panel\PanelAgentPolicyResolver;
use Dataphyre\Panel\PanelAgentRequestContext;
use Dataphyre\Panel\PanelAgentRuntime;
use Dataphyre\Panel\PanelSensitiveDataSanitizer;
use Dataphyre\Panel\PanelAgentSignedIntent;
use Dataphyre\Panel\PanelAgentStoreReservation;
use Dataphyre\Panel\PanelAgentTool;
use Dataphyre\Panel\PanelAgentToolCatalog;
use Dataphyre\Panel\PanelAgentToolExecutionRequest;
use Dataphyre\Panel\PanelAgentToolExecutionResult;
use Dataphyre\Panel\PanelAgentToolExecutor;
use Dataphyre\Panel\PanelAgentToolExecutorConformance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelAgentPolicyFixture implements PanelAgentPolicyResolver {
	public bool $allow=true;
	public bool $throw=false;
	public int $approvals=0;
	public bool $confirmation=false;
	public bool $separation=false;
	public bool $approvalAllowed=true;
	public string $deniedReason='Host permission resolver denied this tool.';
	public string $approvalDeniedReason='Host approval policy denied this principal.';
	public bool $fingerprintThrow=false;
	public int $fingerprintCalls=0;
	public string $policyFingerprint;
	/** @var list<string> */ public array $permissions=['orders.update','orders.critical','orders.fail','orders.hidden','orders.automation'];
	public function __construct(){ $this->policyFingerprint=hash('sha256','dp-panel-agent-policy-v1'); }
	public function decide(PanelAgentRequestContext $context, PanelAgentTool $tool, array $arguments): PanelAgentPolicyDecision {
		if($this->throw){ throw new RuntimeException('policy unavailable'); }
		if(!$this->allow || !in_array($tool->permission(),$this->permissions,true)){ return PanelAgentPolicyDecision::deny($this->deniedReason,['tenant'=>$context->tenantFingerprint()]); }
		return PanelAgentPolicyDecision::allow('Host permission resolver allowed this tool.',$this->approvals,$this->confirmation,$this->separation,['argument_count'=>count($arguments)]);
	}
	public function approve(PanelAgentRequestContext $approver, PanelAgentPlan $plan): PanelAgentPolicyDecision {
		if($this->throw){ throw new RuntimeException('approval policy unavailable'); }
		return $this->approvalAllowed ? PanelAgentPolicyDecision::allow('Host approval policy allowed this principal.') : PanelAgentPolicyDecision::deny($this->approvalDeniedReason);
	}
	public function fingerprint(): string { $this->fingerprintCalls++; if($this->fingerprintThrow){ throw new RuntimeException('fingerprint unavailable'); } return $this->policyFingerprint; }
}

final class DpPanelAgentExecutorFixture implements PanelAgentToolExecutor {
	/** @var list<PanelAgentToolExecutionRequest> */ public array $requests=[];
	/** @var list<PanelAgentToolExecutionResult|Throwable> */ public array $results=[];
	public ?Closure $after=null;
	public function execute(PanelAgentToolExecutionRequest $request): PanelAgentToolExecutionResult {
		$this->requests[]=$request; $next=$this->results!==[] ? array_shift($this->results) : PanelAgentToolExecutionResult::success(['order_id'=>$request->arguments()['order_id'] ?? null,'secret_token'=>'hide-me']);
		if($this->after instanceof Closure){ ($this->after)($request); }
		if($next instanceof Throwable){ throw $next; }
		return $next;
	}
}

final class DpPanelAgentConfirmationFixture implements PanelAgentConfirmationVerifier {
	public bool $throw=false;
	public int $fingerprintCalls=0;
	public int $verificationCalls=0;
	/** @var list<string> */ public array $evidenceHashes=[];
	private readonly string $configurationFingerprint;
	private readonly string $secret;
	public function __construct(string $version='v1'){
		$this->configurationFingerprint=hash('sha256','dp-panel-agent-confirmation-'.$version);
		$this->secret=hash('sha256','dp-panel-agent-confirmation-secret-'.$version,true);
	}
	public function fingerprint(): string { $this->fingerprintCalls++; return $this->configurationFingerprint; }
	public function evidence(PanelAgentRequestContext $context, PanelAgentPlan $plan): string {
		return 'confirm.'.hash_hmac('sha256',PanelAgentGuard::canonicalJson(['plan'=>$plan->hash(),'scope'=>$context->scopeFingerprint(),'subject'=>$context->subjectFingerprint()]),$this->secret);
	}
	public function verify(PanelAgentRequestContext $context, PanelAgentPlan $plan, string $evidence): bool {
		$this->verificationCalls++; $this->evidenceHashes[]=hash('sha256',$evidence);
		if($this->throw){ throw new RuntimeException('raw confirmation verifier secret'); }
		return hash_equals($this->evidence($context,$plan),$evidence);
	}
}

/** @return array<string,mixed> */
function dp_panel_agent_schema(): array {
	return [
		'type'=>'object','required'=>['order_id'],'additionalProperties'=>false,
		'properties'=>[
			'order_id'=>['type'=>'string','minLength'=>3,'maxLength'=>32,'pattern'=>'/^ord-[0-9]+$/'],
			'amount'=>['type'=>'number','minimum'=>0,'maximum'=>10000],
			'tags'=>['type'=>'array','minItems'=>1,'maxItems'=>3,'items'=>['type'=>'string','enum'=>['safe','review']]],
			'active'=>['type'=>'boolean'],'count'=>['type'=>'integer'],'note'=>['type'=>'null'],
		],
	];
}

function dp_panel_agent_tool(string $name='orders.update', string $risk='low', int $outputLimit=65536, bool $hidden=false, bool $dryRun=true): PanelAgentTool {
	return new PanelAgentTool($name,'2026.7','Update one bounded order.',$name,$risk,$dryRun,false,0,false,dp_panel_agent_schema(),$hidden,$outputLimit,256,['owner'=>'operations','api_token'=>'never expose']);
}

function dp_panel_agent_context(string $principal='operator:1', string $tenant='tenant-a', string $session='session-a'): PanelAgentRequestContext {
	return new PanelAgentRequestContext('operations',$tenant,$principal,$session,'request-1');
}

/** @return array{runtime:PanelAgentRuntime,catalog:PanelAgentToolCatalog,policy:PanelAgentPolicyEngine,resolver:DpPanelAgentPolicyFixture,store:InMemoryPanelAgentWorkflowStore,signer:PanelAgentIntentSigner,executor:DpPanelAgentExecutorFixture,confirmation:DpPanelAgentConfirmationFixture,now:int} */
function dp_panel_agent_runtime(string $risk='low', int $outputLimit=65536): array {
	$now=1784016000;
	$resolver=new DpPanelAgentPolicyFixture(); $policy=new PanelAgentPolicyEngine($resolver); $store=new InMemoryPanelAgentWorkflowStore(); $executor=new DpPanelAgentExecutorFixture();
	$catalog=new PanelAgentToolCatalog(); $catalog->register(dp_panel_agent_tool('orders.update',$risk,$outputLimit),$executor,'core');
	$signer=new PanelAgentIntentSigner(['old'=>str_repeat('o',32),'current'=>str_repeat('c',32)],'current',static fn(): int=>$now);
	$confirmation=new DpPanelAgentConfirmationFixture(); $runtime=new PanelAgentRuntime($catalog,$policy,$signer,$store,static fn(): int=>$now,$confirmation);
	return compact('runtime','catalog','policy','resolver','store','signer','executor','confirmation','now');
}

/** @param callable():mixed $callback */
function dp_panel_agent_expect(Context $t, callable $callback, string $code): PanelAgentException {
	try{ $callback(); }catch(PanelAgentException $exception){ $t->same($code,$exception->errorCode()); return $exception; }
	throw new RuntimeException("Expected PanelAgentException {$code}.");
}

/** @param list<array<string,mixed>> $steps @return array<string,mixed> */
function dp_panel_agent_proposal(array $steps, string $title='Update orders'): array { return ['title'=>$title,'steps'=>$steps]; }

suite('Panel agent-safe bounded workflow runtime')
	->contract('panel.agent-safe-workflow',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('agent-plan','tool-catalog','policy','signed-intent','approval','execution','audit')
	->isolation('case')
	->tag('panel','agents','security')
	->group('framework-coverage');

test('agent guards contexts tools and value contracts reject non-deterministic or oversized data',static function(Context $t): void {
	$t->throws(static fn()=>new PanelAgentException('Bad','bad'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentException('bad','bad',200),InvalidArgumentException::class);
	$exception=new PanelAgentException('valid_error','Safe failure.',403); $t->same('valid_error',$exception->errorCode()); $t->same(403,$exception->httpStatus());
	$t->same('orders.update',PanelAgentGuard::identifier(' Orders.Update ','tool'));
	$t->throws(static fn()=>PanelAgentGuard::identifier('1bad','tool'),InvalidArgumentException::class);
	$t->same('',PanelAgentGuard::boundedString(' ','optional',5,true));
	$t->throws(static fn()=>PanelAgentGuard::boundedString(1,'value',5),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentGuard::boundedString("x\0y",'value',5),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentGuard::boundedString('abcdef','value',5),InvalidArgumentException::class);
	$digest=hash('sha256','x'); $t->same($digest,PanelAgentGuard::digest(strtoupper($digest))); $t->throws(static fn()=>PanelAgentGuard::digest('bad'),InvalidArgumentException::class);
	$t->same('{"a":1,"b":2}',PanelAgentGuard::canonicalJson(['b'=>2,'a'=>1]));
	PanelAgentGuard::assertJson(['safe'=>[1,true,null,1.5]]);
	$t->throws(static fn()=>PanelAgentGuard::assertJson(NAN),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentGuard::assertJson(new stdClass()),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentGuard::assertJson(['large'=>str_repeat('x',20)],10),LengthException::class);
	$t->throws(static fn()=>PanelAgentGuard::assertJson([[[1]]],100,1),LengthException::class);
	$t->throws(static fn()=>PanelAgentGuard::assertJson([1,2,3],100,4,2),LengthException::class);

	foreach([
		['unknown'=>true],['type'=>'date'],['type'=>'object','required'=>'x'],['type'=>'object','properties'=>['x']],
		['type'=>'object','properties'=>['bad key'=>['type'=>'string']]],['type'=>'object','properties'=>['x'=>'bad']],
		['type'=>'array','items'=>'bad'],['type'=>'object','additionalProperties'=>'yes'],['type'=>'string','pattern'=>'/['],['type'=>'string','enum'=>[]],
	] as $schema){ $t->throws(static fn()=>PanelAgentGuard::assertSchema($schema),InvalidArgumentException::class); }
	$schema=dp_panel_agent_schema(); $normalized=PanelAgentGuard::normalizeArguments(['order_id'=>'ord-1','amount'=>1.5,'tags'=>['safe'],'active'=>true,'count'=>2,'note'=>null],$schema);
	$t->same(['active','amount','count','note','order_id','tags'],array_keys($normalized));
	foreach([
		['order_id'=>'x'],['order_id'=>'ord-123456789012345678901234567890123'],['order_id'=>'bad-1'],[],['order_id'=>'ord-1','extra'=>1],
		['order_id'=>'ord-1','amount'=>-1],['order_id'=>'ord-1','amount'=>10001],['order_id'=>'ord-1','tags'=>[]],
		['order_id'=>'ord-1','tags'=>['safe','safe','safe','safe']],['order_id'=>'ord-1','tags'=>['unsafe']],['order_id'=>'ord-1','active'=>1],
		['order_id'=>'ord-1','count'=>1.2],['order_id'=>'ord-1','note'=>'no'],
	] as $arguments){ dp_panel_agent_expect($t,static fn()=>PanelAgentGuard::normalizeArguments($arguments,$schema),'arguments_invalid'); }
	$t->same(['password'=>PanelSensitiveDataSanitizer::REDACTED,'nested'=>['api_token'=>PanelSensitiveDataSanitizer::REDACTED]],PanelAgentGuard::redact(['password'=>'x','nested'=>['api_token'=>'y']])); $t->contains(PanelSensitiveDataSanitizer::REDACTED,(string)PanelAgentGuard::redact('Bearer abc.def'));
	$t->contains(PanelSensitiveDataSanitizer::REDACTED,PanelAgentGuard::safeError('Bearer abc.def',128));
	$t->contains('...',PanelAgentGuard::safeError(str_repeat('x',300),128));
	$secretPayload=['nested'=>['apiKey'=>'camel-secret','csrfToken'=>'csrf-secret','otp'=>'123456','recoveryCodes'=>['recovery-secret'],'encryptionKey'=>'encrypt-secret','signingKey'=>'sign-secret','challengeKey'=>'challenge-secret','pepper'=>'pepper-secret'],'message'=>'client_secret="quoted-secret" eyJabcdefghijk.abcdefghijkl.abcdefghijkl https://user:url-secret@example.test/path'];
	$secretJson=json_encode(PanelAgentGuard::redact($secretPayload),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
	foreach(['camel-secret','csrf-secret','123456','recovery-secret','encrypt-secret','sign-secret','challenge-secret','pepper-secret','quoted-secret','eyJabcdefghijk','url-secret'] as $secret){ $t->notContains($secret,$secretJson); } $t->contains(PanelSensitiveDataSanitizer::REDACTED,$secretJson);

	$context=dp_panel_agent_context(); $contextManifest=$context->jsonSerialize();
	$t->same('operations',$context->panel()); $t->same('tenant-a',$context->tenant()); $t->same('operator:1',$context->principal()); $t->same('session-a',$context->session()); $t->same('request-1',$context->requestId());
	$t->same(64,strlen($context->scopeFingerprint())); $t->isFalse($contextManifest['raw_identity_exposed']);
	$t->throws(static fn()=>new PanelAgentRequestContext('bad panel','tenant','principal','session','request'),InvalidArgumentException::class);

	$tool=dp_panel_agent_tool(); $manifest=$tool->manifest();
	$t->same('orders.update',$tool->name()); $t->same('2026.7',$tool->version()); $t->same('low',$tool->risk()); $t->isTrue($tool->dryRunSupported()); $t->isFalse($tool->hidden()); $t->same(65536,$tool->outputByteLimit()); $t->same(256,$tool->errorByteLimit()); $t->same(PanelSensitiveDataSanitizer::REDACTED,$manifest['metadata']['api_token']); $t->same($tool->fingerprint(),$manifest['fingerprint']);
	$t->throws(static fn()=>new PanelAgentTool('x','1','D','x','unknown'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentTool('x','1','D','x','low',true,false,3),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentTool('x','1','D','x','low',true,false,0,true),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentTool('x','1','D','x','low',true,false,0,false,['type'=>'object'],false,1),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentTool('x','1','D','x','low',true,false,0,false,['type'=>'object'],false,256,9000),InvalidArgumentException::class);

	$request=new PanelAgentToolExecutionRequest($context,'orders.update',['password'=>'x'],'key',true,true,$digest,1);
	$t->same('orders.update',$request->tool()); $t->same(PanelSensitiveDataSanitizer::REDACTED,$request->jsonSerialize()['arguments']['password']); $t->isTrue($request->dryRun()); $t->isTrue($request->confirmed()); $t->isFalse($request->cancellationRequested());
	$cancelRequest=new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,static fn(): bool=>true); $t->isTrue($cancelRequest->cancellationRequested()); $t->isTrue($cancelRequest->jsonSerialize()['cancellation_probe_present']);
	$failedCancelRequest=new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,static function(): never{throw new RuntimeException('probe failed');}); $t->isTrue($failedCancelRequest->cancellationRequested());
	$deadlineRequest=new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,null,10); $t->same(10,$deadlineRequest->deadlineAt()); $t->isFalse($deadlineRequest->cancellationRequested(9)); $t->isTrue($deadlineRequest->cancellationRequested(10)); $t->same(10,$deadlineRequest->jsonSerialize()['deadline_at']);
	$deadlineNow=9; $clockedDeadline=new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,null,10,static function()use(&$deadlineNow): int{return $deadlineNow;}); $t->isFalse($clockedDeadline->cancellationRequested()); $deadlineNow=10; $t->isTrue($clockedDeadline->cancellationRequested()); $t->isTrue($clockedDeadline->jsonSerialize()['deadline_clock_present']);
	$failedClockDeadline=new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,null,10,static function(): never{throw new RuntimeException('clock failed');}); $t->isTrue($failedClockDeadline->cancellationRequested());
	$t->throws(static fn()=>new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,0),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentToolExecutionRequest($context,'orders.update',[],'key',false,false,$digest,1,null,0),InvalidArgumentException::class);
	$success=PanelAgentToolExecutionResult::success(['ok'=>true],['trace'=>'x']); $failure=PanelAgentToolExecutionResult::failure('no',true);
	$t->isTrue($success->ok()); $t->same(['ok'=>true],$success->output()); $t->same('no',$failure->error()); $t->isTrue($failure->retryable());
})->tag('panel','agents','guard','values','adversarial')->group('framework-coverage');

test('agent catalog layering provenance checkpoints and default-deny policy are deterministic',static function(Context $t): void {
	$executor=new DpPanelAgentExecutorFixture(); $tool=dp_panel_agent_tool();
	$deny=new PanelAgentToolCatalog(); $deny->register($tool,$executor,'core');
	$t->throws(static fn()=>$deny->register($tool,$executor,'plugin'),LogicException::class);
	$t->throws(static fn()=>$deny->register($tool,$executor,'core'),LogicException::class);
	$t->same($tool,$deny->tool('orders.update')); $t->same($executor,$deny->executor('orders.update')); $t->same('core',$deny->contributor('orders.update')); $t->isTrue($deny->has('orders.update')); $t->isFalse($deny->has('missing'));
	$catalogManifest=$deny->jsonSerialize(); $t->same(1,$catalogManifest['tool_count']); $t->isFalse($catalogManifest['executors_exposed']);
	$checkpoint=$deny->checkpoint(); $deny->unregisterContributor('absent'); $t->same(1,$deny->revision()); $deny->unregisterContributor('core'); $t->same(2,$deny->revision()); $deny->restore($checkpoint); $t->same(1,$deny->revision());
	$bad=$checkpoint; $bad['type']='bad'; $t->throws(static fn()=>$deny->restore($bad),InvalidArgumentException::class);
	$beforeInvalid=[$deny->revision(),$deny->fingerprint()]; $badLayer=$checkpoint; $badLayer['layers']['orders.update'][0]['priority']='0'; $t->throws(static fn()=>$deny->restore($badLayer),InvalidArgumentException::class); $t->same($beforeInvalid,[$deny->revision(),$deny->fingerprint()]);
	$t->throws(static fn()=>(new PanelAgentToolCatalog())->restore($checkpoint),InvalidArgumentException::class);
	$longLived=new PanelAgentToolCatalog(); $longLived->register($tool,$executor,'core'); $baseline=$longLived->checkpoint();
	foreach(range(1,40) as $index){ $longLived->checkpoint(); $longLived->register(new PanelAgentTool('orders.extra'.$index,'1','Extra tool '.$index.'.','orders.update'),$executor,'plugin'.$index); }
	$longLived->restore($baseline); $t->isTrue($longLived->has('orders.update')); $t->isFalse($longLived->has('orders.extra40')); $t->same(1,$longLived->revision());

	$replace=new PanelAgentToolCatalog('replace'); $replace->register($tool,$executor,'core',0)->register(new PanelAgentTool('orders.update','2','Replacement','orders.update'),$executor,'plugin',-5);
	$t->same('2',$replace->tool('orders.update')?->version()); $t->same(2,$replace->jsonSerialize()['layer_count']);
	$priority=new PanelAgentToolCatalog('priority'); $priority->register($tool,$executor,'core',1)->register(new PanelAgentTool('orders.update','2','Replacement','orders.update'),$executor,'plugin',10);
	$t->same('2',$priority->tool('orders.update')?->version()); $priority->restore($priority->checkpoint()); $t->throws(static fn()=>$priority->register(new PanelAgentTool('orders.update','3','Ambiguous','orders.update'),$executor,'third',10),LogicException::class);
	$t->throws(static fn()=>new PanelAgentToolCatalog('unsafe'),InvalidArgumentException::class);
	$t->throws(static fn()=>$priority->register(new PanelAgentTool('another','1','Another','another'),$executor,'bad contributor',0),InvalidArgumentException::class);
	$t->throws(static fn()=>$priority->register(new PanelAgentTool('another','1','Another','another'),$executor,'good',1001),InvalidArgumentException::class);
	$hidden=new PanelAgentToolCatalog(); $hidden->register(dp_panel_agent_tool('orders.hidden','low',65536,true),$executor,'core');
	$t->isFalse($hidden->has('orders.hidden')); $t->isTrue($hidden->has('orders.hidden',true)); $t->same(1,$hidden->jsonSerialize()['hidden_tools_omitted']);

	$default=new PanelAgentPolicyEngine(); $context=dp_panel_agent_context();
	$t->isFalse($default->evaluate($context,$tool,['order_id'=>'ord-1'])->allowed()); $t->isTrue($default->jsonSerialize()['default_deny']);
	$resolver=new DpPanelAgentPolicyFixture(); $engine=new PanelAgentPolicyEngine($resolver);
	$t->same(1,$resolver->fingerprintCalls); $cachedFingerprint=$engine->fingerprint(); $resolver->policyFingerprint=hash('sha256','changed-without-engine-replacement'); $resolver->fingerprintThrow=true; $engine->jsonSerialize(); $engine->checkpoint(); $t->isTrue($engine->evaluate($context,$tool,['order_id'=>'ord-1'])->allowed()); $t->same($cachedFingerprint,$engine->fingerprint()); $t->same(1,$resolver->fingerprintCalls); $resolver->fingerprintThrow=false;
	$low=$engine->evaluate($context,$tool,['order_id'=>'ord-1']); $t->isTrue($low->allowed()); $t->same(0,$low->approvalCount()); $t->isFalse($low->confirmationRequired());
	$medium=$engine->evaluate($context,dp_panel_agent_tool('orders.update','medium'),['order_id'=>'ord-1']); $t->isTrue($medium->confirmationRequired());
	$high=$engine->evaluate($context,dp_panel_agent_tool('orders.update','high'),['order_id'=>'ord-1']); $t->same(1,$high->approvalCount());
	$critical=$engine->evaluate($context,dp_panel_agent_tool('orders.update','critical'),['order_id'=>'ord-1']); $t->same(2,$critical->approvalCount()); $t->isTrue($critical->separationOfDuties());
	$resolver->allow=false; $t->isFalse($engine->evaluate($context,$tool,[])->allowed()); $resolver->allow=true; $resolver->throw=true; $t->isFalse($engine->evaluate($context,$tool,[])->allowed()); $resolver->throw=false;
	$t->isTrue($engine->authorizeApproval($context,new PanelAgentPlan('Approval',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','c'),0,$engine->fingerprint(),[new PanelAgentPlanStep(1,$tool->name(),$tool->version(),$tool->fingerprint(),[],false,0,false,false)],1))->allowed());
	$resolver->approvalAllowed=false; $t->isFalse($engine->authorizeApproval($context,new PanelAgentPlan('Approval',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','c'),0,$engine->fingerprint(),[new PanelAgentPlanStep(1,$tool->name(),$tool->version(),$tool->fingerprint(),[],false,0,false,false)],1))->allowed()); $resolver->approvalAllowed=true;
	$resolver->throw=true; $t->isFalse($engine->authorizeApproval($context,new PanelAgentPlan('Approval',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','c'),0,$engine->fingerprint(),[new PanelAgentPlanStep(1,$tool->name(),$tool->version(),$tool->fingerprint(),[],false,0,false,false)],1))->allowed()); $resolver->throw=false;
	$policyCheckpoint=$engine->checkpoint(); $engine->engageKillSwitch('Sensitive incident detail'); $t->isTrue($engine->killed()); $t->isFalse($engine->evaluate($context,$tool,[])->allowed()); $killManifest=$engine->jsonSerialize(); $t->isTrue($killManifest['kill_reason_configured']); $t->same(64,strlen((string)$killManifest['kill_reason_tag'])); $t->isFalse(str_contains(json_encode($killManifest,JSON_THROW_ON_ERROR),'Sensitive incident detail')); $engine->engageKillSwitch('Sensitive incident detail'); $engine->releaseKillSwitch(); $t->isFalse($engine->killed()); $engine->releaseKillSwitch();
	$engine->restore($policyCheckpoint); $t->same(0,$engine->revision());
	$invalidPolicy=$policyCheckpoint; $invalidPolicy['resolver_fingerprint']=hash('sha256','other'); $t->throws(static fn()=>$engine->restore($invalidPolicy),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentPolicyDecision::allow('Allowed',3),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAgentPolicyDecision::allow('Allowed',0,false,true),InvalidArgumentException::class);
	$safeDenial=PanelAgentPolicyDecision::deny('Denied Bearer policy-secret api_key=policy-api eyJabcdefghijk.abcdefghijkl.abcdefghijkl'); $safeDenialJson=json_encode($safeDenial,JSON_THROW_ON_ERROR); foreach(['policy-secret','policy-api','eyJabcdefghijk'] as $secret){ $t->notContains($secret,$safeDenialJson); }
	$t->same('panel_agent_policy_decision',$critical->jsonSerialize()['type']);
})->tag('panel','agents','catalog','policy','checkpoint')->group('framework-coverage');

test('agent plan and rotating-key intents bind tenant principal session parent expiry and exact claims',static function(Context $t): void {
	$now=1784016000; $context=dp_panel_agent_context(); $tool=dp_panel_agent_tool();
	$step=new PanelAgentPlanStep(1,$tool->name(),$tool->version(),$tool->fingerprint(),$tool->normalize(['order_id'=>'ord-1']),false,1,true,true);
	$plan=new PanelAgentPlan('Update order',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','catalog'),2,hash('sha256','policy'),[$step],$now);
	$t->same(1,$plan->approvalCount()); $t->isTrue($plan->confirmationRequired()); $t->isTrue($plan->separationOfDuties()); $t->same($plan->hash(),$plan->jsonSerialize()['hash']);
	$t->same('orders.update',$step->tool()); $t->same('ord-1',$step->arguments()['order_id']); $t->same($step->jsonSerialize()['tool_fingerprint'],$step->toolFingerprint());
	$t->throws(static fn()=>new PanelAgentPlanStep(0,$tool->name(),$tool->version(),$tool->fingerprint(),[],false,0,false,false),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentPlan('Empty',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','c'),0,hash('sha256','p'),[],$now),LengthException::class);
	$t->throws(static fn()=>new PanelAgentPlan('Wrong order',$context->scopeFingerprint(),$context->subjectFingerprint(),hash('sha256','c'),0,hash('sha256','p'),[new PanelAgentPlanStep(2,$tool->name(),$tool->version(),$tool->fingerprint(),[],false,0,false,false)],$now),InvalidArgumentException::class);

	$signer=new PanelAgentIntentSigner(['old'=>str_repeat('o',32),'current'=>str_repeat('c',32)],'current',static fn(): int=>$now);
	$intent=$signer->issuePlan($plan,$context,60); $verified=$signer->verifyPlan($intent->token(),$plan,$context);
	$t->same('dp-panel-agent-plan',$verified->audience()); $t->same('current',$verified->keyId()); $t->same($plan->hash(),$verified->planHash()); $t->same(60,$verified->expiresAt()-$verified->issuedAt());
	$approver=dp_panel_agent_context('supervisor:1','tenant-a','approval-session');
	$approval=$signer->issueApproval($plan,$verified,$approver,60); $approvalClaims=$signer->verifyApproval($approval->token(),$plan,$context,$verified->nonce());
	$t->same($approver->subjectFingerprint(),$approvalClaims->subjectFingerprint()); $t->same($verified->nonce(),$approvalClaims->parentNonce());
	$t->same(2,$signer->jsonSerialize()['retained_key_count']+1); $t->isFalse($signer->jsonSerialize()['secrets_exposed']);
	foreach([dp_panel_agent_context('operator:2'),dp_panel_agent_context('operator:1','tenant-b'),dp_panel_agent_context('operator:1','tenant-a','other-session')] as $wrong){ dp_panel_agent_expect($t,static fn()=>$signer->verifyPlan($intent->token(),$plan,$wrong),'scope_mismatch'); }
	$forged=$intent->token(); $signatureOffset=(int)strrpos($forged,'.')+1; $forged[$signatureOffset]=$forged[$signatureOffset]==='a' ? 'b' : 'a'; dp_panel_agent_expect($t,static fn()=>$signer->verifyPlan($forged,$plan,$context),'intent_invalid');
	dp_panel_agent_expect($t,static fn()=>$signer->verifyApproval($approval->token(),$plan,$context,str_repeat('a',32)),'intent_invalid');
	$expiredSigner=new PanelAgentIntentSigner(['current'=>str_repeat('c',32)],'current',static fn(): int=>$now+100);
	dp_panel_agent_expect($t,static fn()=>$expiredSigner->verifyPlan($intent->token(),$plan,$context),'intent_expired');
	$t->throws(static fn()=>new PanelAgentIntentSigner(['short'=>'x'],'short'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentIntentSigner(['current'=>str_repeat('c',32)],'missing'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentIntentSigner([],'current'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentIntentSigner([str_repeat('c',32)],'current'),InvalidArgumentException::class);
	$tooMany=[]; foreach(range(1,9) as $index){ $tooMany['key'.$index]=str_repeat((string)$index,32); } $t->throws(static fn()=>new PanelAgentIntentSigner($tooMany,'key1'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelAgentIntentSigner(['Current'=>str_repeat('c',32),'current'=>str_repeat('d',32)],'current'),InvalidArgumentException::class);
	$normalizedSigner=new PanelAgentIntentSigner(['Current'=>str_repeat('c',32)],' CURRENT '); $t->same('current',$normalizedSigner->jsonSerialize()['current_key_id']);
	$t->throws(static fn()=>new PanelAgentIntentSigner(['current'=>str_repeat('c',32)],'current',null,61),InvalidArgumentException::class);
	$t->throws(static fn()=>$signer->issuePlan($plan,$context,1),InvalidArgumentException::class);
	$badParent=new PanelAgentIntentVerification('dp-panel-agent-approval','current',str_repeat('a',32),$plan->scopeFingerprint(),$context->subjectFingerprint(),$plan->hash(),$plan->catalogFingerprint(),$plan->policyFingerprint(),'',1,2);
	dp_panel_agent_expect($t,static fn()=>$signer->issueApproval($plan,$badParent,$approver),'intent_parent_invalid');
	$envelope=new PanelAgentPlanEnvelope($plan,$intent,3); $approvalEnvelope=new PanelAgentApprovalEnvelope($approval,4);
	$t->same(3,$envelope->storeRevision()); $t->same(4,$approvalEnvelope->storeRevision()); $t->same('panel_agent_plan_envelope',$envelope->jsonSerialize()['type']);
	$t->throws(static fn()=>new PanelAgentSignedIntent('wrong audience','x',1,2),InvalidArgumentException::class);
})->tag('panel','agents','intent','crypto','scope','adversarial')->group('framework-coverage');

test('agent runtime validates authorizes approves executes audits and replays critical plans safely',static function(Context $t): void {
	$fixture=dp_panel_agent_runtime('critical'); $runtime=$fixture['runtime']; $catalog=$fixture['catalog']; $store=$fixture['store']; $executor=$fixture['executor']; $confirmation=$fixture['confirmation']; $context=dp_panel_agent_context();
	$proposal=dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1','amount'=>45]]]);
	$prepared=$runtime->prepare($proposal,$context,$catalog->revision(),$store->revision(),120);
	$evidence=$confirmation->evidence($context,$prepared->plan());
	$t->same(2,$prepared->plan()->approvalCount()); $t->isTrue($prepared->plan()->confirmationRequired()); $t->same(1,$store->revision());
	$t->same('plan_validated',$store->audit()[0]->event()); $t->isTrue($store->audit()[0]->verify(''));
	$supervisorA=dp_panel_agent_context('supervisor:1','tenant-a','approval-a'); $supervisorB=dp_panel_agent_context('supervisor:2','tenant-a','approval-b');
	dp_panel_agent_expect($t,static fn()=>$runtime->approve($prepared->plan(),$prepared->intent()->token(),$context,$context,$store->revision()),'self_approval_denied');
	dp_panel_agent_expect($t,static fn()=>$runtime->approve($prepared->plan(),$prepared->intent()->token(),$context,dp_panel_agent_context('supervisor:3','tenant-b'),$store->revision()),'approval_scope_mismatch');
	$fixture['resolver']->approvalAllowed=false; $fixture['resolver']->approvalDeniedReason='Denied Bearer approval-secret api_key=approval-api eyJabcdefghijk.abcdefghijkl.abcdefghijkl'; $approvalDenied=dp_panel_agent_expect($t,static fn()=>$runtime->approve($prepared->plan(),$prepared->intent()->token(),$context,$supervisorA,$store->revision()),'approval_denied'); foreach(['approval-secret','approval-api','eyJabcdefghijk'] as $secret){ $t->notContains($secret,$approvalDenied->getMessage()); } $fixture['resolver']->approvalAllowed=true;
	$approvedA=$runtime->approve($prepared->plan(),$prepared->intent()->token(),$context,$supervisorA,$store->revision());
	$approvedB=$runtime->approve($prepared->plan(),$prepared->intent()->token(),$context,$supervisorB,$approvedA->storeRevision());
	$t->same(3,$store->revision()); $t->same('plan_approved',$store->audit()[2]->event());
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[],'run-1',$store->revision(),$evidence),'approval_count_mismatch');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-1',$store->revision()),'confirmation_required');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-1',$store->revision(),'forged-confirmation-evidence'),'confirmation_invalid');
	$confirmation->throw=true; $verificationFailure=dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-1',$store->revision(),$evidence),'confirmation_verification_failed'); $t->notContains('raw confirmation verifier secret',$verificationFailure->getMessage()); $confirmation->throw=false;
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedA->intent()->token()],'run-1',$store->revision(),$evidence),'duplicate_approval');
	$result=$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-1',$store->revision(),$evidence);
	$t->isTrue($result->ok()); $t->same('executed',$result->code()); $t->same(PanelSensitiveDataSanitizer::REDACTED,$result->steps()[0]['output']['secret_token']); $t->same(1,count($executor->requests)); $t->same(6,$store->revision());
	$t->same('execution_completed',$result->receipt()?->event()); $t->isTrue($result->receipt()?->verify($store->audit()[2]->hash()) ?? false);
	$replay=$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-1',$store->revision(),$evidence);
	$t->isTrue($replay->replayed()); $t->same('idempotent_replay',$replay->code()); $t->same(1,count($executor->requests));
	$recovered=$runtime->result($prepared->plan(),$context,'run-1'); $t->isTrue($recovered?->replayed() ?? false); $t->same('idempotent_replay',$recovered?->code());
	$t->same(null,$runtime->result($prepared->plan(),$context,'unknown-key')); dp_panel_agent_expect($t,static fn()=>$runtime->result($prepared->plan(),dp_panel_agent_context('other'),'run-1'),'scope_mismatch');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[$approvedA->intent()->token(),$approvedB->intent()->token()],'run-2',$store->revision(),$evidence),'intent_replayed');
	$runtimeManifest=$runtime->jsonSerialize(); $t->same('panel_agent_runtime',$runtimeManifest['type']); $t->isFalse($runtimeManifest['arbitrary_output_execution']); $t->isTrue($runtimeManifest['confirmation_verifier_installed']); $t->same(1,$confirmation->fingerprintCalls);
	$t->notContains($evidence,json_encode([$runtimeManifest,$result,$store->audit(),$executor->requests],JSON_THROW_ON_ERROR));
	$t->same($catalog,$runtime->catalog()); $t->same($fixture['policy'],$runtime->policy()); $t->same($fixture['signer'],$runtime->signer()); $t->same($store,$runtime->store());
})->tag('panel','agents','runtime','approval','execution','audit','replay')->group('framework-coverage');

test('agent confirmation evidence is plan scoped context scoped replay safe and stale verifier safe',static function(Context $t): void {
	$fixture=dp_panel_agent_runtime('medium'); $runtime=$fixture['runtime']; $catalog=$fixture['catalog']; $policy=$fixture['policy']; $signer=$fixture['signer']; $store=$fixture['store']; $confirmation=$fixture['confirmation']; $context=dp_panel_agent_context();
	$planA=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-31']]],'First confirmation'),$context,$catalog->revision(),$store->revision());
	$evidenceA=$confirmation->evidence($context,$planA->plan());
	$t->same($confirmation->fingerprint(),$planA->plan()->confirmationVerifierFingerprint());
	$t->same($planA->plan()->confirmationVerifierFingerprint(),$signer->verifyPlan($planA->intent()->token(),$planA->plan(),$context)->confirmationVerifierFingerprint());
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($planA->plan(),$planA->intent()->token(),$context,[],'confirm-a',$store->revision()),'confirmation_required');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($planA->plan(),$planA->intent()->token(),$context,[],'confirm-a',$store->revision(),''),'confirmation_invalid');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($planA->plan(),$planA->intent()->token(),dp_panel_agent_context('other'),[],'confirm-a',$store->revision(),$evidenceA),'scope_mismatch');

	$planB=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-32']]],'Second confirmation'),$context,$catalog->revision(),$store->revision());
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($planB->plan(),$planB->intent()->token(),$context,[],'confirm-b',$store->revision(),$evidenceA),'confirmation_invalid');
	$evidenceB=$confirmation->evidence($context,$planB->plan()); $executed=$runtime->execute($planB->plan(),$planB->intent()->token(),$context,[],'confirm-b',$store->revision(),$evidenceB); $t->isTrue($executed->ok());
	$replayed=$runtime->execute($planB->plan(),$planB->intent()->token(),$context,[],'confirm-b',$store->revision(),$evidenceB); $t->isTrue($replayed->replayed()); $t->same(1,count($fixture['executor']->requests));

	$withoutVerifier=new PanelAgentRuntime($catalog,$policy,$signer,$store,static fn(): int=>$fixture['now']);
	dp_panel_agent_expect($t,static fn()=>$withoutVerifier->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-33']]]),$context,$catalog->revision(),$store->revision()),'confirmation_unavailable');
	$t->isFalse($withoutVerifier->jsonSerialize()['confirmation_verifier_installed']);
	$replacement=new DpPanelAgentConfirmationFixture('v2'); $staleRuntime=new PanelAgentRuntime($catalog,$policy,$signer,$store,static fn(): int=>$fixture['now'],$replacement);
	dp_panel_agent_expect($t,static fn()=>$staleRuntime->execute($planA->plan(),$planA->intent()->token(),$context,[],'confirm-a',$store->revision(),$replacement->evidence($context,$planA->plan())),'confirmation_stale');
})->tag('panel','agents','confirmation','scope','replay','adversarial')->group('framework-coverage');

test('agent execution revalidates every step and completes durable failures after mid-flight mutation',static function(Context $t): void {
	$context=dp_panel_agent_context();
	$catalogFixture=dp_panel_agent_runtime(); $catalogRuntime=$catalogFixture['runtime']; $catalog=$catalogFixture['catalog']; $catalogStore=$catalogFixture['store']; $catalogExecutor=$catalogFixture['executor']; $mutations=0;
	$catalogExecutor->after=static function()use(&$mutations,$catalog): void { if($mutations++===0){ $catalog->register(new PanelAgentTool('late.tool','1','Late tool.','orders.update'),new DpPanelAgentExecutorFixture(),'late-plugin'); } };
	$catalogPlan=$catalogRuntime->prepare(dp_panel_agent_proposal([
		['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1']],['tool'=>'orders.update','arguments'=>['order_id'=>'ord-2']],
	]),$context,$catalog->revision(),$catalogStore->revision());
	$catalogResult=$catalogRuntime->execute($catalogPlan->plan(),$catalogPlan->intent()->token(),$context,[],'catalog-toctou',$catalogStore->revision());
	$t->isFalse($catalogResult->ok()); $t->same('catalog_stale',$catalogResult->steps()[1]['code']); $t->same(1,count($catalogExecutor->requests)); $t->same('execution_failed',$catalogResult->receipt()?->event());
	$t->isTrue($catalogRuntime->result($catalogPlan->plan(),$context,'catalog-toctou')?->replayed() ?? false);

	$policyFixture=dp_panel_agent_runtime(); $policyRuntime=$policyFixture['runtime']; $policyStore=$policyFixture['store']; $policyExecutor=$policyFixture['executor']; $resolver=$policyFixture['resolver']; $mutations=0;
	$policyExecutor->after=static function()use(&$mutations,$resolver): void { if($mutations++===0){ $resolver->allow=false; } };
	$policyPlan=$policyRuntime->prepare(dp_panel_agent_proposal([
		['tool'=>'orders.update','arguments'=>['order_id'=>'ord-3']],['tool'=>'orders.update','arguments'=>['order_id'=>'ord-4']],
	]),$context,$policyFixture['catalog']->revision(),$policyStore->revision());
	$policyResult=$policyRuntime->execute($policyPlan->plan(),$policyPlan->intent()->token(),$context,[],'policy-toctou',$policyStore->revision());
	$t->isFalse($policyResult->ok()); $t->same('policy_denied',$policyResult->steps()[1]['code']); $t->same(1,count($policyExecutor->requests)); $t->isTrue($policyRuntime->result($policyPlan->plan(),$context,'policy-toctou')?->replayed() ?? false);

	$cancelFixture=dp_panel_agent_runtime(); $cancelRuntime=$cancelFixture['runtime']; $cancelStore=$cancelFixture['store']; $cancelPolicy=$cancelFixture['policy']; $cancelExecutor=$cancelFixture['executor'];
	$cancelExecutor->after=static function()use($cancelPolicy): void { $cancelPolicy->engageKillSwitch('Raised while the executor was active.'); };
	$cancelPlan=$cancelRuntime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-5']]]),$context,$cancelFixture['catalog']->revision(),$cancelStore->revision());
	$cancelResult=$cancelRuntime->execute($cancelPlan->plan(),$cancelPlan->intent()->token(),$context,[],'post-executor-cancel',$cancelStore->revision());
	$t->isFalse($cancelResult->ok()); $t->same('execution_cancelled',$cancelResult->code()); $t->same('execution_cancelled',$cancelResult->steps()[0]['code']); $t->same(1784016030,$cancelExecutor->requests[0]->deadlineAt());
})->tag('panel','agents','toctou','catalog','policy','audit')->group('framework-coverage');

test('agent execution bounds aggregate results uses summary audits and survives unrelated store revisions',static function(Context $t): void {
	$fixture=dp_panel_agent_runtime('low',400000); $runtime=$fixture['runtime']; $catalog=$fixture['catalog']; $store=$fixture['store']; $executor=$fixture['executor']; $context=dp_panel_agent_context();
	$largeOutput=['parts'=>array_fill(0,5,str_repeat('x',60000))]; $executor->results=[PanelAgentToolExecutionResult::success($largeOutput),PanelAgentToolExecutionResult::success($largeOutput)];
	$prepared=$runtime->prepare(dp_panel_agent_proposal([
		['tool'=>'orders.update','arguments'=>['order_id'=>'ord-10']],['tool'=>'orders.update','arguments'=>['order_id'=>'ord-11']],
	]),$context,$catalog->revision(),$store->revision());
	$result=$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[],'aggregate',$store->revision());
	$t->isFalse($result->ok()); $t->same('aggregate_result_too_large',$result->steps()[1]['code']); $t->same(2,count($executor->requests)); $t->isTrue(strlen(json_encode($result,JSON_THROW_ON_ERROR))<524288);
	$receiptJson=json_encode($result->receipt(),JSON_THROW_ON_ERROR); $t->isTrue(strlen($receiptJson)<10000); $t->notContains(str_repeat('x',100),$receiptJson); $t->same(2,count($result->receipt()?->details()['step_summaries'] ?? []));

	$revisionFixture=dp_panel_agent_runtime(); $revisionRuntime=$revisionFixture['runtime']; $revisionStore=$revisionFixture['store']; $revisionExecutor=$revisionFixture['executor']; $revisionContext=dp_panel_agent_context();
	$revisionPlan=$revisionRuntime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-12']]]),$revisionContext,$revisionFixture['catalog']->revision(),$revisionStore->revision());
	$revisionExecutor->after=static function()use($revisionStore,$revisionContext): void {
		$unrelated=PanelAgentAuditReceipt::create(count($revisionStore->audit())+1,'plan_validated',$revisionContext,hash('sha256','unrelated-plan'),'planned',[],$revisionStore->lastAuditHash(),1784016000);
		$revisionStore->append($unrelated,$revisionStore->revision());
	};
	$revisionResult=$revisionRuntime->execute($revisionPlan->plan(),$revisionPlan->intent()->token(),$revisionContext,[],'revision-safe',$revisionStore->revision());
	$t->isTrue($revisionResult->ok()); $t->same('execution_completed',$revisionResult->receipt()?->event()); $t->same(5,$revisionStore->revision()); $t->isTrue($revisionRuntime->result($revisionPlan->plan(),$revisionContext,'revision-safe')?->replayed() ?? false);

	$lateNow=1000; $lateEffects=0; $lateResolver=new DpPanelAgentPolicyFixture(); $latePolicy=new PanelAgentPolicyEngine($lateResolver); $lateStore=new InMemoryPanelAgentWorkflowStore(static function()use(&$lateNow): int{return $lateNow;},30); $lateExecutor=new DpPanelAgentExecutorFixture();
	$lateExecutor->after=static function()use(&$lateNow,&$lateEffects): void { $lateEffects++; $lateNow+=61; };
	$lateCatalog=new PanelAgentToolCatalog(); $lateCatalog->register(dp_panel_agent_tool(),$lateExecutor,'core'); $lateSigner=new PanelAgentIntentSigner(['current'=>str_repeat('c',32)],'current',static function()use(&$lateNow): int{return $lateNow;}); $lateRuntime=new PanelAgentRuntime($lateCatalog,$latePolicy,$lateSigner,$lateStore,static function()use(&$lateNow): int{return $lateNow;});
	$latePlan=$lateRuntime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-13']]]),$revisionContext,$lateCatalog->revision(),$lateStore->revision());
	$lateResult=$lateRuntime->execute($latePlan->plan(),$latePlan->intent()->token(),$revisionContext,[],'late-executor',$lateStore->revision());
	$t->isFalse($lateResult->ok()); $t->same('execution_cancelled',$lateResult->code()); $t->same('execution_failed',$lateResult->receipt()?->event()); $t->same(1,$lateEffects); $t->same(4,$lateStore->revision());
	$lateReplay=$lateRuntime->execute($latePlan->plan(),$latePlan->intent()->token(),$revisionContext,[],'late-executor',$lateStore->revision()); $t->isTrue($lateReplay->replayed()); $t->same(1,$lateEffects);
})->tag('panel','agents','aggregate-limit','audit-summary','reservation')->group('framework-coverage');

test('agent Automation bridge scopes downstream idempotency by tenant plan and tool',static function(Context $t): void {
	$executions=0; $automationStore=new InMemoryAutomationStore();
	$action=AutomationAction::make('agent_scoped')->inputSchema(dp_panel_agent_schema())->handle(static function(array $input)use(&$executions): array{$executions++;return['order_id'=>$input['order_id']];});
	$adapter=new PanelAgentAutomationToolExecutor(new AutomationExecutor(new AutomationRegistry([$action]),$automationStore),'agent_scoped');
	$catalog=new PanelAgentToolCatalog(); $catalog->register(new PanelAgentTool('orders.automation','1','Scoped automation.','orders.automation','low',true,false,0,false,dp_panel_agent_schema()),$adapter,'automation');
	$resolver=new DpPanelAgentPolicyFixture(); $policy=new PanelAgentPolicyEngine($resolver); $store=new InMemoryPanelAgentWorkflowStore(); $now=1784016000; $signer=new PanelAgentIntentSigner(['current'=>str_repeat('c',32)],'current',static fn():int=>$now); $runtime=new PanelAgentRuntime($catalog,$policy,$signer,$store,static fn():int=>$now);
	$tenantA=dp_panel_agent_context('operator:1','tenant-a','session-a'); $tenantB=dp_panel_agent_context('operator:1','tenant-b','session-b');
	$planA=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.automation','arguments'=>['order_id'=>'ord-21']]]),$tenantA,$catalog->revision(),$store->revision()); $resultA=$runtime->execute($planA->plan(),$planA->intent()->token(),$tenantA,[],'shared-client-key',$store->revision());
	$planB=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.automation','arguments'=>['order_id'=>'ord-22']]]),$tenantB,$catalog->revision(),$store->revision()); $resultB=$runtime->execute($planB->plan(),$planB->intent()->token(),$tenantB,[],'shared-client-key',$store->revision());
	$t->isTrue($resultA->ok()); $t->isTrue($resultB->ok()); $t->same('ord-21',$resultA->steps()[0]['output']['order_id']); $t->same('ord-22',$resultB->steps()[0]['output']['order_id']); $t->same(2,$executions);
})->tag('panel','agents','automation','idempotency','tenant-isolation')->group('framework-coverage');

test('agent runtime rejects hidden stale forged cross-scope invalid and cancelled plans before execution',static function(Context $t): void {
	$fixture=dp_panel_agent_runtime(); $runtime=$fixture['runtime']; $catalog=$fixture['catalog']; $store=$fixture['store']; $policy=$fixture['policy']; $resolver=$fixture['resolver']; $context=dp_panel_agent_context();
	foreach([
		['proposal'=>['title'=>'x'],'code'=>'plan_invalid'],
		['proposal'=>['title'=>'x','steps'=>[]],'code'=>'plan_invalid'],
		['proposal'=>dp_panel_agent_proposal([['tool'=>'missing','arguments'=>[]]]),'code'=>'tool_unavailable'],
		['proposal'=>dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'bad']]]),'code'=>'arguments_invalid'],
		['proposal'=>dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1'],'unknown'=>true]]),'code'=>'plan_invalid'],
	] as $case){ dp_panel_agent_expect($t,static fn()=>$runtime->prepare($case['proposal'],$context,$catalog->revision(),$store->revision()),$case['code']); }
	dp_panel_agent_expect($t,static fn()=>$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1']]]),$context,$catalog->revision()+1,$store->revision()),'catalog_revision_conflict');
	$resolver->allow=false; $resolver->deniedReason='Denied Bearer decision-secret api_key=decision-api eyJabcdefghijk.abcdefghijkl.abcdefghijkl'; $policyDenied=dp_panel_agent_expect($t,static fn()=>$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1']]]),$context,$catalog->revision(),$store->revision()),'policy_denied'); foreach(['decision-secret','decision-api','eyJabcdefghijk'] as $secret){ $t->notContains($secret,$policyDenied->getMessage()); } $resolver->allow=true;
	$hiddenExecutor=new DpPanelAgentExecutorFixture(); $hiddenCatalog=new PanelAgentToolCatalog(); $hiddenCatalog->register(dp_panel_agent_tool('orders.hidden','low',65536,true),$hiddenExecutor,'core');
	$hiddenRuntime=new PanelAgentRuntime($hiddenCatalog,$policy,$fixture['signer'],new InMemoryPanelAgentWorkflowStore(),static fn(): int=>$fixture['now']);
	dp_panel_agent_expect($t,static fn()=>$hiddenRuntime->prepare(dp_panel_agent_proposal([['tool'=>'orders.hidden','arguments'=>['order_id'=>'ord-1']]]),$context,$hiddenCatalog->revision(),0),'tool_unavailable');

	$prepared=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1']]]),$context,$catalog->revision(),$store->revision());
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),dp_panel_agent_context('other'),[],'x',$store->revision()),'scope_mismatch');
	$forged=$prepared->intent()->token(); $forged[5]=$forged[5]==='a' ? 'b' : 'a'; dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$forged,$context,[],'x',$store->revision()),'intent_invalid');
	$policy->engageKillSwitch('Incident'); dp_panel_agent_expect($t,static fn()=>$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[],'x',$store->revision()),'policy_stale');
	$cancelled=$runtime->cancel($prepared->plan(),$prepared->intent()->token(),$context,'Operator cancelled',$store->revision()); $t->same('cancelled',$cancelled->code());
	$t->same('already_cancelled',$runtime->cancel($prepared->plan(),$prepared->intent()->token(),$context,'Again',$store->revision())->code());
	$policy->releaseKillSwitch();

	$fresh=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-2']]]),$context,$catalog->revision(),$store->revision());
	$catalog->register(new PanelAgentTool('another','1','Another tool.','orders.update'),new DpPanelAgentExecutorFixture(),'plugin');
	dp_panel_agent_expect($t,static fn()=>$runtime->execute($fresh->plan(),$fresh->intent()->token(),$context,[],'fresh',$store->revision()),'catalog_stale');
	$dryCatalog=new PanelAgentToolCatalog(); $dryCatalog->register(dp_panel_agent_tool('orders.update','low',65536,false,false),new DpPanelAgentExecutorFixture(),'core');
	$dryRuntime=new PanelAgentRuntime($dryCatalog,new PanelAgentPolicyEngine(new DpPanelAgentPolicyFixture()),$fixture['signer'],new InMemoryPanelAgentWorkflowStore(),static fn(): int=>$fixture['now']);
	dp_panel_agent_expect($t,static fn()=>$dryRuntime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-1'],'dry_run'=>true]]),$context,$dryCatalog->revision(),0),'dry_run_unsupported');
})->tag('panel','agents','adversarial','scope','stale','cancel')->group('framework-coverage');

test('agent runtime bounds output errors executor exceptions and supports dry-run without arbitrary execution',static function(Context $t): void {
	$fixture=dp_panel_agent_runtime('low',256); $runtime=$fixture['runtime']; $catalog=$fixture['catalog']; $store=$fixture['store']; $executor=$fixture['executor']; $context=dp_panel_agent_context();
	$cases=[
		PanelAgentToolExecutionResult::success(['blob'=>str_repeat('x',400)]),
		PanelAgentToolExecutionResult::success(new stdClass()),
		PanelAgentToolExecutionResult::failure('Bearer topsecret token=abc '.str_repeat('x',400),true),
		new RuntimeException('executor exploded'),
	];
	$expected=['output_too_large','output_invalid','tool_failed','tool_failed'];
	foreach($cases as $index=>$raw){
		$executor->results=[$raw]; $prepared=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-'.($index+1)]]]),$context,$catalog->revision(),$store->revision());
		$result=$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[],'failure-'.$index,$store->revision());
		$t->isFalse($result->ok()); $t->same($expected[$index],$result->steps()[0]['code']);
		if($index===2){ $t->contains(PanelSensitiveDataSanitizer::REDACTED,$result->steps()[0]['error']); $t->isTrue($result->steps()[0]['retryable']); }
	}
	$executor->results=[PanelAgentToolExecutionResult::success(['preview'=>true])];
	$prepared=$runtime->prepare(dp_panel_agent_proposal([['tool'=>'orders.update','arguments'=>['order_id'=>'ord-9'],'dry_run'=>true]]),$context,$catalog->revision(),$store->revision());
	$result=$runtime->execute($prepared->plan(),$prepared->intent()->token(),$context,[],'dry-run',$store->revision());
	$t->same('dry_run_completed',$result->steps()[0]['code']); $t->isTrue($executor->requests[count($executor->requests)-1]->dryRun());
})->tag('panel','agents','limits','failure','dry-run')->group('framework-coverage');

test('agent store optimistic revisions checkpoints audit chain and adapter conformance fail closed',static function(Context $t): void {
	$store=new InMemoryPanelAgentWorkflowStore(); $context=dp_panel_agent_context(); $planHash=hash('sha256','plan');
	$receipt=PanelAgentAuditReceipt::create(1,'plan_validated',$context,$planHash,'planned',['secret_token'=>'hide'],'',1);
	$t->isTrue($receipt->verify('')); $t->isFalse($receipt->verify(hash('sha256','wrong'))); $t->same(PanelSensitiveDataSanitizer::REDACTED,$receipt->details()['secret_token']);
	$t->same(1,$store->append($receipt,0)); dp_panel_agent_expect($t,static fn()=>$store->append($receipt,0),'revision_conflict'); dp_panel_agent_expect($t,static fn()=>$store->append($receipt,1),'audit_chain_invalid');
	$reservation=$store->reserve($planHash,$context->scopeFingerprint(),'key',hash('sha256','request'),[str_repeat('a',32)],1); $t->isTrue($reservation->acquiredNew());
	dp_panel_agent_expect($t,static fn()=>$store->lookup($planHash,$context->scopeFingerprint(),'key',hash('sha256','request')),'execution_in_progress');
	dp_panel_agent_expect($t,static fn()=>$store->reserve($planHash,$context->scopeFingerprint(),'key',hash('sha256','request'),[str_repeat('b',32)],$store->revision()),'execution_in_progress');
	$result=PanelAgentExecutionResult::make(true,'executed',$planHash,[],$store->revision());
	dp_panel_agent_expect($t,static fn()=>$store->complete((string)$reservation->id(),PanelAgentExecutionResult::make(true,'executed',hash('sha256','other-plan'),[],$store->revision()),$context,'execution_completed','executed',[],2,$reservation->revision()),'reservation_result_invalid');
	dp_panel_agent_expect($t,static fn()=>$store->complete((string)$reservation->id(),$result,dp_panel_agent_context('operator:1','tenant-b'),'execution_completed','executed',[],2,$reservation->revision()),'reservation_scope_mismatch');
	$concurrent=PanelAgentAuditReceipt::create(2,'plan_validated',$context,hash('sha256','concurrent'),'planned',[],$receipt->hash(),2); $t->same(3,$store->append($concurrent,$store->revision()));
	$completed=$store->complete((string)$reservation->id(),$result,$context,'execution_completed','executed',[],3,$reservation->revision()); $t->same(4,$completed->storeRevision()); $t->same(3,$completed->receipt()?->sequence());
	$replay=$store->reserve($planHash,$context->scopeFingerprint(),'key',hash('sha256','request'),[str_repeat('b',32)],1); $t->isFalse($replay->acquiredNew()); $t->same($store->revision(),$replay->revision());
	$t->same($completed,$store->lookup($planHash,$context->scopeFingerprint(),'key',hash('sha256','request'))); $t->same(null,$store->lookup($planHash,$context->scopeFingerprint(),'missing',hash('sha256','request')));
	dp_panel_agent_expect($t,static fn()=>$store->lookup(hash('sha256','wrong-plan'),$context->scopeFingerprint(),'key',hash('sha256','request')),'idempotency_conflict');
	dp_panel_agent_expect($t,static fn()=>$store->reserve($planHash,$context->scopeFingerprint(),'key',hash('sha256','different'),[str_repeat('b',32)],$store->revision()),'idempotency_conflict');
	$checkpoint=$store->checkpoint(); $restored=new InMemoryPanelAgentWorkflowStore(); $restored->restore($checkpoint); $t->same($store->revision(),$restored->revision()); $t->same($store->lastAuditHash(),$restored->lastAuditHash());
	$bad=$checkpoint; $bad['type']='bad'; $t->throws(static fn()=>$restored->restore($bad),InvalidArgumentException::class);
	$t->isFalse($store->jsonSerialize()['durable']); $t->isTrue($store->jsonSerialize()['bounded']);

	$leaseNow=100; $leaseStore=new InMemoryPanelAgentWorkflowStore(static function()use(&$leaseNow): int{return $leaseNow;},30,2); $leaseNonce=str_repeat('c',32);
	$expiredLease=$leaseStore->reserve($planHash,$context->scopeFingerprint(),'lease-key',hash('sha256','lease-request'),[$leaseNonce],0); $t->same(130,$expiredLease->expiresAt());
	dp_panel_agent_expect($t,static fn()=>$leaseStore->lookup($planHash,$context->scopeFingerprint(),'lease-key',hash('sha256','lease-request')),'execution_in_progress');
	$renewedLease=$leaseStore->renew((string)$expiredLease->id(),$expiredLease->revision(),60); $t->same(160,$renewedLease->expiresAt()); $t->throws(static fn()=>$leaseStore->renew((string)$renewedLease->id(),$renewedLease->revision(),29),InvalidArgumentException::class);
	$leaseNow=160; $t->same(null,$leaseStore->lookup($planHash,$context->scopeFingerprint(),'lease-key',hash('sha256','lease-request')));
	$reclaimedLease=$leaseStore->reserve($planHash,$context->scopeFingerprint(),'lease-key',hash('sha256','lease-request'),[$leaseNonce],$leaseStore->revision()); $t->isTrue($reclaimedLease->acquiredNew());
	dp_panel_agent_expect($t,static fn()=>$leaseStore->complete((string)$expiredLease->id(),PanelAgentExecutionResult::make(true,'executed',$planHash,[],$leaseStore->revision()),$context,'execution_completed','executed',[],130,$expiredLease->revision()),'reservation_invalid');
	$leaseNow=161; $leaseStore->complete((string)$reclaimedLease->id(),PanelAgentExecutionResult::make(true,'executed',$planHash,[],$leaseStore->revision()),$context,'execution_completed','executed',[],161,$reclaimedLease->revision());
	$leaseStore->reserve($planHash,$context->scopeFingerprint(),'second-key',hash('sha256','second-request'),[str_repeat('d',32)],$leaseStore->revision());
	dp_panel_agent_expect($t,static fn()=>$leaseStore->reserve($planHash,$context->scopeFingerprint(),'third-key',hash('sha256','third-request'),[str_repeat('e',32)],$leaseStore->revision()),'store_capacity_exceeded');
	$t->same(2,$leaseStore->jsonSerialize()['max_entries']); $t->same(30,$leaseStore->jsonSerialize()['lease_seconds']);
	$t->throws(static fn()=>new InMemoryPanelAgentWorkflowStore(null,29),InvalidArgumentException::class); $t->throws(static fn()=>new InMemoryPanelAgentWorkflowStore(null,30,0),InvalidArgumentException::class);

	$automation=AutomationAction::make('agent_automation')->requiresIdempotency(false)->inputSchema(dp_panel_agent_schema())->handle(static fn(array $input): array=>['order_id'=>$input['order_id']]);
	$adapter=new PanelAgentAutomationToolExecutor(new AutomationExecutor(new AutomationRegistry([$automation]),new InMemoryAutomationStore()),'agent_automation',['orders.automation']);
	$request=new PanelAgentToolExecutionRequest($context,'orders.automation',['order_id'=>'ord-10'],'automation-key',false,false,$planHash,1);
	$adapted=$adapter->execute($request); $t->isTrue($adapted->ok()); $t->same('ord-10',$adapted->output()['order_id']); $t->isTrue($adapter->jsonSerialize()['automation_guards_preserved']);
	$tool=dp_panel_agent_tool(); $t->same([],PanelAgentToolExecutorConformance::inspect(PanelAgentToolExecutionResult::success(['safe'=>true]),$tool));
	$badResult=PanelAgentToolExecutionResult::failure(str_repeat('x',500)); $issues=PanelAgentToolExecutorConformance::inspect($badResult,$tool); $t->same(1,count($issues));
	$t->same(5,count(PanelAgentToolExecutorConformance::cases())); $t->isFalse((new PanelAgentToolExecutorConformance())->jsonSerialize()['executes_tools']);
})->tag('panel','agents','store','checkpoint','automation','conformance')->group('framework-coverage');

test('agent residual accessors manifests and malformed trusted checkpoints remain exact',static function(Context $t): void {
	$context=dp_panel_agent_context(); $tool=dp_panel_agent_tool(); $executor=new DpPanelAgentExecutorFixture();
	$catalog=new PanelAgentToolCatalog(); $catalog->register($tool,$executor,'core'); $t->same('deny',$catalog->conflictPolicy());
	$t->same(dp_panel_agent_schema(),$tool->inputSchema()); $t->same('operations',$tool->metadata()['owner']); $t->same('panel_agent_tool',$tool->jsonSerialize()['type']);
	$catalogCheckpoint=$catalog->checkpoint(); $badCatalog=$catalogCheckpoint; $badCatalog['digest']=hash('sha256','tampered');
	$t->throws(static fn()=>$catalog->restore($badCatalog),InvalidArgumentException::class);

	$step=new PanelAgentPlanStep(1,$tool->name(),$tool->version(),$tool->fingerprint(),['password'=>'hide'],false,0,false,false);
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$step->jsonSerialize()['arguments']['password']);
	$plan=new PanelAgentPlan('Residual',$context->scopeFingerprint(),$context->subjectFingerprint(),$catalog->fingerprint(),$catalog->revision(),hash('sha256','policy'),[$step],123);
	$t->same(123,$plan->createdAt());
	$t->throws(static fn()=>PanelAgentPlanStep::hydrateExecutionPayload([]),UnexpectedValueException::class);
	$invalidStep=$step->executionPayload();$invalidStep['ordinal']=0;
	$t->throws(static fn()=>PanelAgentPlanStep::hydrateExecutionPayload($invalidStep),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelAgentPlan::hydrateExecutionPayload([]),UnexpectedValueException::class);
	$invalidNestedStep=$plan->executionPayload();$invalidNestedStep['steps'][0]='not-a-step';
	$t->throws(static fn()=>PanelAgentPlan::hydrateExecutionPayload($invalidNestedStep),UnexpectedValueException::class);
	$invalidPlan=$plan->executionPayload();$invalidPlan['catalog_revision']=-1;
	$t->throws(static fn()=>PanelAgentPlan::hydrateExecutionPayload($invalidPlan),UnexpectedValueException::class);
	$t->same($plan->hash(),PanelAgentPlan::hydrateExecutionPayload($plan->executionPayload())->hash());
	$tamperedPlan=$plan->executionPayload();$tamperedPlan['hash']=str_repeat('0',64);
	$t->throws(static fn()=>PanelAgentPlan::hydrateExecutionPayload($tamperedPlan),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelAgentRequestContext::hydrateExecutionPayload([]),UnexpectedValueException::class);
	$invalidContext=$context->executionPayload();$invalidContext['panel']='bad panel';
	$t->throws(static fn()=>PanelAgentRequestContext::hydrateExecutionPayload($invalidContext),UnexpectedValueException::class);
	$receipt=PanelAgentAuditReceipt::create(1,'plan_validated',$context,$plan->hash(),'planned',[],'',123);
	$t->same($context->scopeFingerprint(),$receipt->scopeFingerprint()); $t->same($context->subjectFingerprint(),$receipt->actorFingerprint()); $t->same($plan->hash(),$receipt->planHash()); $t->same('planned',$receipt->code()); $t->same('',$receipt->previousHash()); $t->same(123,$receipt->occurredAt());
	$result=PanelAgentExecutionResult::make(true,'executed',$plan->hash(),[],1,null,['safe'=>true]); $withReceipt=$result->withReceipt($receipt,2);
	$t->same($plan->hash(),$withReceipt->planHash()); $t->same(2,$withReceipt->storeRevision()); $t->same(['safe'=>true],$withReceipt->metadata()); $t->same('panel_agent_execution_result',$withReceipt->jsonSerialize()['type']);
	$durableSecrets=['apiKey'=>'camel-durable-secret','nested'=>['csrfToken'=>'csrf-durable-secret'],'message'=>'Bearer durable-bearer eyJabcdefghijk.abcdefghijkl.abcdefghijkl https://user:durable-url-secret@example.test/path'];
	$safeReceipt=PanelAgentAuditReceipt::create(1,'plan_validated',$context,$plan->hash(),'planned',$durableSecrets,'',123); $safeResult=PanelAgentExecutionResult::make(true,'executed',$plan->hash(),[['output'=>$durableSecrets]],1);
	$durableJson=json_encode(['receipt'=>$safeReceipt,'result'=>$safeResult],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); foreach(['camel-durable-secret','csrf-durable-secret','durable-bearer','eyJabcdefghijk','durable-url-secret'] as $secret){ $t->notContains($secret,$durableJson); } $t->contains(PanelSensitiveDataSanitizer::REDACTED,$durableJson);

	$intent=new PanelAgentSignedIntent('dp-panel-agent-plan','opaque',10,20); $t->same(10,$intent->issuedAt()); $t->same(20,$intent->expiresAt()); $t->same('panel_agent_signed_intent',$intent->jsonSerialize()['type']);
	$verification=new PanelAgentIntentVerification('dp-panel-agent-plan','key',str_repeat('a',32),$context->scopeFingerprint(),$context->subjectFingerprint(),$plan->hash(),$catalog->fingerprint(),hash('sha256','policy'),'',10,20);
	$t->same($catalog->fingerprint(),$verification->catalogFingerprint()); $t->same(hash('sha256','policy'),$verification->policyFingerprint()); $t->same('',$verification->parentNonce());
	$t->isTrue(interface_exists(PanelAgentConfirmationVerifier::class));
	$approvalIntent=new PanelAgentSignedIntent('dp-panel-agent-approval','opaque',10,20); $approvalEnvelope=new PanelAgentApprovalEnvelope($approvalIntent,3); $t->same('panel_agent_approval_envelope',$approvalEnvelope->jsonSerialize()['type']);

	$additional=['type'=>'object','properties'=>[],'additionalProperties'=>true];
	$t->same(['custom'=>1],PanelAgentGuard::normalizeArguments(['custom'=>1],$additional));
	$guard=$t->nonPublic(PanelAgentGuard::class);
	dp_panel_agent_expect($t,static fn()=>$guard->invoke('normalizeValue','value',['type'=>'impossible'],'$',0),'arguments_invalid');

	$store=new InMemoryPanelAgentWorkflowStore(); $store->append($receipt,0); $reservation=$store->reserve($plan->hash(),$context->scopeFingerprint(),'key',hash('sha256','request'),[str_repeat('b',32)],1);
	$storeCheckpoint=$store->checkpoint(); $badStore=$storeCheckpoint; $badStore['reservations'][(string)$reservation->id()]['status']='completed';
	$t->throws(static fn()=>$store->restore($badStore),InvalidArgumentException::class);
	$badMetadata=PanelAgentToolExecutionResult::success(['ok'=>true],['object'=>new stdClass()]);
	$t->same(['Executor output or metadata is not bounded JSON.'],PanelAgentToolExecutorConformance::inspect($badMetadata,$tool));

	$automationAdapter=new PanelAgentAutomationToolExecutor(new AutomationExecutor(new AutomationRegistry(),new InMemoryAutomationStore()),'missing');
	$automationRequest=new PanelAgentToolExecutionRequest($context,'orders.automation',['order_id'=>'ord-1'],'key',false,false,$plan->hash(),1);
	$t->isFalse($automationAdapter->execute($automationRequest)->ok());
})->tag('panel','agents','residual','manifest','checkpoint')->group('framework-coverage');
