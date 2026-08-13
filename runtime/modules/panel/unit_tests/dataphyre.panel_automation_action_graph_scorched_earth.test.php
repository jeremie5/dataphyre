<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\AutomationAction;
use Dataphyre\Panel\AutomationExecutionRequest;
use Dataphyre\Panel\AutomationExecutionResult;
use Dataphyre\Panel\AutomationExecutor;
use Dataphyre\Panel\AutomationPlan;
use Dataphyre\Panel\AutomationPolicyDecision;
use Dataphyre\Panel\AutomationReceipt;
use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\AutomationStore;
use Dataphyre\Panel\AutomationValidationIssue;
use Dataphyre\Panel\FilesystemAutomationStore;
use Dataphyre\Panel\InMemoryAutomationStore;
use Dataphyre\Panel\WorkflowActor;
use Dataphyre\Panel\WorkflowEvent;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelScorchedAutomationStore implements AutomationStore {
	public InMemoryAutomationStore $inner;
	public bool $throwSave=false;
	public bool $throwGet=false;
	public bool $throwFind=false;
	public bool $throwAll=false;
	public bool $refuseSave=false;
	/** @var list<?AutomationReceipt> */
	public array $findSequence=[];
	public function __construct(){ $this->inner=new InMemoryAutomationStore(); }
	public function save(AutomationReceipt $receipt): bool { if($this->throwSave){throw new RuntimeException('save unavailable');} return $this->refuseSave ? false : $this->inner->save($receipt); }
	public function get(string $receiptId): ?AutomationReceipt { if($this->throwGet){throw new RuntimeException('get unavailable');} return $this->inner->get($receiptId); }
	public function findByIdempotency(string $action,string $idempotencyKey): ?AutomationReceipt { if($this->throwFind){throw new RuntimeException('find unavailable');} return $this->findSequence!==[] ? array_shift($this->findSequence) : $this->inner->findByIdempotency($action,$idempotencyKey); }
	public function all(?string $action=null): array { if($this->throwAll){throw new RuntimeException('all unavailable');} return $this->inner->all($action); }
}

/** @param callable(array<string,mixed>):mixed|null $handler @param callable():mixed|null $rollback */
function dp_panel_scorched_automation_action(?callable $handler=null,?callable $rollback=null): AutomationAction {
	$action=AutomationAction::make('release_order')
		->label('Release order')->description('Release a validated order to fulfillment.')
		->version('2026.7')->risk('critical')->confirmation('critical','RELEASE ORDER')
		->inputSchema([
			'type'=>'object',
			'required'=>['order_id','amount','items','target'],
			'properties'=>[
				'order_id'=>['type'=>'string','minLength'=>3,'maxLength'=>30,'pattern'=>'/^ord-/'],
				'amount'=>['type'=>'number','minimum'=>1,'maximum'=>1000],
				'items'=>['type'=>'array','minItems'=>1,'maxItems'=>5,'items'=>['type'=>'string','minLength'=>1]],
				'email'=>['type'=>'string','format'=>'email'],
				'target'=>['type'=>'object','required'=>['region'],'properties'=>['region'=>['type'=>'string','enum'=>['ca','us']]]],
			],
		])
		->validateUsing(static fn(array $input): array=>($input['note'] ?? '')==='blocked' ? [['path'=>'note','code'=>'blocked','message'=>'Blocked note.']] : [])
		->policy(static function(array $input,WorkflowActor $actor): AutomationPolicyDecision {
			if(!$actor->can('orders.release')){ return AutomationPolicyDecision::deny('Actor lacks orders.release.'); }
			if((float)($input['amount'] ?? 0)>=100){
				return AutomationPolicyDecision::approval('High-value order requires supervisor approval.',[
					'workflow'=>'order_release','transition'=>'approve','assignee_role'=>'supervisor',
				]);
			}
			return AutomationPolicyDecision::allow('Order release policy passed.',['scope'=>'orders']);
		})
		->planUsing(static fn(array $input): array=>[
			'summary'=>'Release '.$input['order_id'].' with '.count($input['items']).' line items.',
			'steps'=>['Validate inventory',['name'=>'reserve','description'=>'Reserve stock atomically.']],
			'effects'=>[['resource'=>'orders','operation'=>'update'],['resource'=>'inventory','operation'=>'reserve']],
			'warnings'=>['Carrier allocation may be irreversible.'],
			'metadata'=>['order_id'=>$input['order_id']],
		])
		->handle($handler ?? static fn(array $input): array=>['released'=>$input['order_id'],'secret_token'=>'never-store-me'])
		->metadata(['owner'=>'operations']);
	if($rollback!==null){
		$action=$action->rollbackUsing($rollback,['Release stock reservation.','Restore the previous order state.']);
	}
	return $action;
}

/** @return array<string,mixed> */
function dp_panel_scorched_valid_automation_input(array $replace=[]): array {
	return array_replace([
		'order_id'=>'ord-1001','amount'=>25,'items'=>['sku-a'],'email'=>'ops@example.com',
		'target'=>['region'=>'ca'],'password'=>'must-not-persist',
	],$replace);
}

suite('Panel automation action-graph lifecycle')
	->contract('panel.automation-action-graph', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('automation-plan', 'policy', 'executor', 'receipt-store', 'rollback')
	->isolation('case')
	->tag('panel', 'automation')
	->group('framework-coverage');

test('automation descriptors policies registry and validation values remain machine readable',static function(Context $t): void {
	$t->throws(static fn()=>AutomationAction::make(' '),InvalidArgumentException::class);
	$action=dp_panel_scorched_automation_action(null,static fn(): bool=>true);
	$t->same('release_order',$action->name());
	$t->same('critical',$action->riskLevel());
	$t->same('RELEASE ORDER',$action->confirmationPhrase());
	$t->isTrue($action->idempotencyRequired());
	$t->same(2,count($action->rollbackInstructions()));
	$manifest=$action->jsonSerialize();
	$t->same('panel_automation_action',$manifest['type']);
	$t->isTrue($manifest['capabilities']['rollback']);

	$registry=new AutomationRegistry([$action]);
	$t->isTrue($registry->has('release order'));
	$t->same($action,$registry->get('release_order'));
	$t->throws(static fn()=>$registry->register($action),LogicException::class);
	$replacement=$action->version(2)->risk('unknown')->confirmation('unknown');
	$registry->register($replacement,true);
	$t->same('2',$registry->get('release_order')?->versionValue());
	$t->same('low',$registry->get('release_order')?->riskLevel());
	$t->same(1,$registry->jsonSerialize()['action_count']);
	$registry->unregister('release_order');
	$t->isFalse($registry->has('release_order'));

	$allow=AutomationPolicyDecision::from(['allowed'=>true,'explanation'=>'yes']);
	$deny=AutomationPolicyDecision::from('No access');
	$approval=AutomationPolicyDecision::approval('Review',['queue'=>'supervisor']);
	$t->isTrue($allow->allowed());
	$t->same('No access',$deny->explanation());
	$t->isTrue($approval->requiresApproval());
	$t->same('supervisor',$approval->handoff()['queue']);

	$issue=AutomationValidationIssue::from(['path'=>'amount','code'=>'too small','message'=>'Too small','severity'=>'warning']);
	$t->same('too_small',$issue->code());
	$t->same('warning',$issue->severity());
	$t->same('invalid',AutomationValidationIssue::from('Invalid input')->code());

	$actor=WorkflowActor::from(['id'=>'agent:1','permissions'=>['orders.release']]);
	$request=new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$actor,'key',true,true,'RELEASE ORDER',['origin'=>'unit']);
	$serialized=$request->jsonSerialize();
	$t->same('[redacted]',$serialized['input']['password']);
	$t->isTrue($serialized['dry_run']);
	$t->same(['origin'],$serialized['context_keys']);
})->tag('panel','automation','manifest','scorched-earth')->group('framework-coverage');

test('automation executor validates plans policy approval confirmation idempotency receipts and rollback',static function(Context $t): void {
	$executions=0;
	$rollbacks=0;
	$action=dp_panel_scorched_automation_action(
		static function(array $input)use(&$executions): array { $executions++; return ['released'=>$input['order_id'],'secret_token'=>'secret-value']; },
		static function(AutomationReceipt $receipt,array $input)use(&$rollbacks): array { $rollbacks++; return ['restored'=>$receipt->input()['order_id'],'reason'=>$input['reason'] ?? '']; }
	);
	$store=new InMemoryAutomationStore();
	$executor=(new AutomationExecutor(new AutomationRegistry([$action]),$store))->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$allowed=WorkflowActor::from(['id'=>'agent:1','permissions'=>['orders.release']]);
	$denied=WorkflowActor::from('agent:denied');

	$t->same('action_not_found',$executor->execute('missing',new AutomationExecutionRequest([],$allowed,'x'))->code());
	$t->same('idempotency_required',$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed))->code());
	$invalid=$executor->execute('release_order',new AutomationExecutionRequest(['order_id'=>'x','amount'=>0,'items'=>[],'target'=>[]],$allowed,'invalid'));
	$t->same('validation_failed',$invalid->code());
	$t->isTrue(count($invalid->issues())>=5);
	$t->same('validation_failed',$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(['note'=>'blocked']),$allowed,'blocked'))->code());
	$t->same('policy_denied',$executor->plan('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$denied,null,true))->code());

	$planned=$executor->plan('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed,null,true));
	$t->same('planned',$planned->code());
	$t->same(2,count($planned->plan()?->steps() ?? []));
	$t->same(2,count($planned->plan()?->effects() ?? []));
	$t->same($planned->plan()?->hash(),$planned->plan()?->jsonSerialize()['hash']);
	$approval=$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(['amount'=>500]),$allowed,'high',false,true,'RELEASE ORDER'));
	$t->same('approval_required',$approval->code());
	$t->same('order_release',$approval->handoff()['workflow']);
	$t->isTrue(isset($approval->handoff()['handoff_id']));

	$confirmation=$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed,'execute-1'));
	$t->same('confirmation_required',$confirmation->code());
	$t->same('confirmation_phrase_mismatch',$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed,'execute-1',false,true,'WRONG'))->code());
	$executed=$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed,'execute-1',false,true,'RELEASE ORDER'));
	$t->same('executed',$executed->code());
	$t->isTrue($executed->ok());
	$t->same(1,$executions);
	$t->same('[redacted]',$executed->receipt()?->input()['password']);
	$t->same('[redacted]',$executed->receipt()?->result()['secret_token']);
	$t->same(2,count($executed->receipt()?->rollbackInstructions() ?? []));
	$replay=$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(),$allowed,'execute-1',false,true,'RELEASE ORDER'));
	$t->same('idempotent_replay',$replay->code());
	$t->isTrue($replay->replayed());
	$t->same(1,$executions);
	$t->same('idempotency_conflict',$executor->execute('release_order',new AutomationExecutionRequest(dp_panel_scorched_valid_automation_input(['amount'=>30]),$allowed,'execute-1',false,true,'RELEASE ORDER'))->code());

	$receiptId=(string)$executed->receipt()?->id();
	$rollbackPhrase='ROLLBACK '.$receiptId;
	$t->same('rollback_confirmation_required',$executor->rollback($receiptId,new AutomationExecutionRequest(['reason'=>'operator'], $allowed,'rollback-1'))->code());
	$rolled=$executor->rollback($receiptId,new AutomationExecutionRequest(['reason'=>'operator'],$allowed,'rollback-1',false,true,$rollbackPhrase));
	$t->same('rolled_back',$rolled->code());
	$t->same($receiptId,$rolled->receipt()?->parentReceiptId());
	$t->same(1,$rollbacks);
	$t->isTrue($executor->rollback($receiptId,new AutomationExecutionRequest(['reason'=>'operator'],$allowed,'rollback-1',false,true,$rollbackPhrase))->replayed());
	$t->same('idempotency_conflict',$executor->rollback($receiptId,new AutomationExecutionRequest(['reason'=>'different'],$allowed,'rollback-1',false,true,$rollbackPhrase))->code());
	$t->same('already_rolled_back',$executor->rollback($receiptId,new AutomationExecutionRequest([],$allowed,'rollback-2',false,true,$rollbackPhrase))->code());
	$t->same(1,$rollbacks);
	$t->same(2,count($store->all('release_order')));
	$t->same('in_memory_automation_store',$store->jsonSerialize()['type']);
	$t->same('panel_automation_executor',$executor->jsonSerialize()['type']);
})->tag('panel','automation','execution','rollback','scorched-earth')->group('framework-coverage');

test('automation executor returns durable failures for policies planners validators handlers and adapters',static function(Context $t): void {
	$actor=WorkflowActor::from(['id'=>'agent','permissions'=>['orders.release']]);
	$store=new InMemoryAutomationStore();
	$failed=AutomationAction::make('fails')->requiresIdempotency()->inputSchema(['type'=>'object'])->handle(static function(): never { throw new RuntimeException('handler exploded'); });
	$policyFailure=AutomationAction::make('policy_failure')->requiresIdempotency(false)->policy(static function(): never { throw new RuntimeException('policy exploded'); })->handle(static fn()=>true);
	$plannerFailure=AutomationAction::make('planner_failure')->requiresIdempotency(false)->planUsing(static function(): never { throw new RuntimeException('planner exploded'); })->handle(static fn()=>true);
	$validatorFailure=AutomationAction::make('validator_failure')->requiresIdempotency(false)->validateUsing(static function(): never { throw new RuntimeException('validator exploded'); })->handle(static fn()=>true);
	$empty=AutomationAction::make('empty')->requiresIdempotency(false);
	$executor=new AutomationExecutor(new AutomationRegistry([$failed,$policyFailure,$plannerFailure,$validatorFailure,$empty]),$store);

	$failure=$executor->execute('fails',new AutomationExecutionRequest([],$actor,'fail-1'));
	$t->same('execution_failed',$failure->code());
	$t->same('failed',$failure->receipt()?->status());
	$t->same('handler exploded',$failure->receipt()?->error());
	$t->isTrue($executor->execute('fails',new AutomationExecutionRequest([],$actor,'fail-1'))->replayed());
	$t->same('policy_failed',$executor->plan('policy_failure',new AutomationExecutionRequest([],$actor,null,true))->code());
	$t->same('planning_failed',$executor->plan('planner_failure',new AutomationExecutionRequest([],$actor,null,true))->code());
	$t->same('validation_failed',$executor->plan('validator_failure',new AutomationExecutionRequest([],$actor,null,true))->code());
	$t->same('not_executable',$executor->execute('empty',new AutomationExecutionRequest([],$actor))->code());
	$t->same('receipt_not_found',$executor->rollback('missing',new AutomationExecutionRequest([],$actor))->code());
	$t->same('rollback_not_supported',$executor->rollback((string)$failure->receipt()?->id(),new AutomationExecutionRequest([],$actor,null,false,true,'x'))->code());
})->tag('panel','automation','failures','scorched-earth')->group('framework-coverage');

test('automation wraps existing Panel actions without modifying their lifecycle API',static function(Context $t): void {
	$calls=0;
	$panelAction=Action::make('ping')
		->label('Ping record')->requiresConfirmation()
		->authorize(static fn(mixed $record): bool=>is_array($record) && ($record['allowed'] ?? false)===true)
		->handle(static function(array $record,array $data)use(&$calls): array { $calls++; return ['id'=>$record['id'],'value'=>$data['value'] ?? null]; });
	$wrapped=AutomationAction::fromPanelAction($panelAction,['risk'=>'medium']);
	$t->same('panel_action',$wrapped->metadataValues()['source']);
	$t->same('explicit',$wrapped->confirmationLevel());
	$executor=new AutomationExecutor(new AutomationRegistry([$wrapped]),new InMemoryAutomationStore());
	$actor=WorkflowActor::from('agent');
	$denied=$executor->execute('ping',new AutomationExecutionRequest(['data'=>['value'=>1]],$actor,'ping-denied',false,true,null,['record'=>['id'=>1,'allowed'=>false]]));
	$t->same('policy_denied',$denied->code());
	$allowed=$executor->execute('ping',new AutomationExecutionRequest(['data'=>['value'=>7]],$actor,'ping-allowed',false,true,null,['record'=>['id'=>2,'allowed'=>true]]));
	$t->same('executed',$allowed->code());
	$t->same(2,$allowed->receipt()?->result()['id']);
	$t->same(7,$allowed->receipt()?->result()['value']);
	$t->same(1,$calls);
})->tag('panel','automation','panel-action','scorched-earth')->group('framework-coverage');

test('automation residual value contracts expose complete receipts plans decisions and results',static function(Context $t): void {
	$actor=WorkflowActor::from('agent:value');
	$action=AutomationAction::make('value_action')->description('Value description')->version(3)->requiresIdempotency(false)->handle(static fn()=>true);
	$t->same('Value description',$action->descriptionValue());
	$policy=AutomationPolicyDecision::from(['outcome'=>'human approval','explanation'=>'Review','handoff'=>['team'=>'risk'],'metadata'=>['score'=>9]]);
	$t->same('approval_required',$policy->outcome());
	$t->same(9,$policy->metadata()['score']);
	$plan=new AutomationPlan('value_action','Value summary',[['name'=>'one']],[['effect'=>'write']],['Warning'],$policy,'medium','explicit',['x'=>1],'2026-07-12T12:00:00+00:00');
	$t->same('value_action',$plan->action());
	$t->same('Value summary',$plan->summary());
	$t->same(['Warning'],$plan->warnings());
	$receipt=AutomationReceipt::create($action,'completed',$actor,null,['password'=>'hide'],'plan-hash',['ok'=>true],null,[],null,['x'=>1],'2026-07-12T12:00:00+00:00','2026-07-12T12:01:00+00:00');
	$t->same('3',$receipt->actionVersion());
	$t->same('agent:value',$receipt->actorId());
	$t->same('2026-07-12T12:01:00+00:00',$receipt->completedAt());
	$result=AutomationExecutionResult::make(true,'done','Finished',$plan,$receipt,[],['next'=>'close'],false,['x'=>1]);
	$t->same('Finished',$result->message());
	$t->same('panel_automation_execution_result',$result->jsonSerialize()['type']);
	$t->same('close',$result->jsonSerialize()['handoff']['next']);
	$issue=AutomationValidationIssue::from(['path'=>'field','code'=>'invalid','message'=>'Bad','metadata'=>['rule'=>'x']]);
	$t->same('field',$issue->path());
	$t->same('Bad',$issue->message());
	$t->same('x',$issue->metadata()['rule']);
	$t->same('field',$issue->jsonSerialize()['path']);

	$disabled=Action::make('disabled')->disabled(true,'Disabled by policy')->handle(static fn()=>true);
	$wrapped=AutomationAction::fromPanelAction($disabled,['rollback'=>static fn()=>true,'rollback_instructions'=>['Undo it']]);
	$t->same(['Undo it'],$wrapped->rollbackInstructions());
	$executor=new AutomationExecutor(new AutomationRegistry([$wrapped]),new InMemoryAutomationStore());
	$t->same('policy_denied',$executor->execute('disabled',new AutomationExecutionRequest([],WorkflowActor::from('actor'),'disabled'))->code());
	$t->same('planned',(new AutomationExecutor(new AutomationRegistry([$action]),new InMemoryAutomationStore()))->execute('value_action',new AutomationExecutionRequest([],$actor,null,true))->code());
})->tag('panel','automation','values','coverage','scorched-earth')->group('framework-coverage');

test('automation executor covers rollback failures high risk warnings invalid schemas and store outages',static function(Context $t): void {
	$actor=WorkflowActor::from('agent');
	$high=AutomationAction::make('high_no_rollback')->risk('high')->requiresIdempotency(false)->handle(static fn()=>true);
	$highPlan=(new AutomationExecutor(new AutomationRegistry([$high]),new InMemoryAutomationStore()))->plan('high_no_rollback',new AutomationExecutionRequest([],$actor,null,true));
	$t->contains('does not expose automatic rollback',implode(' ',$highPlan->plan()?->warnings() ?? []));
	$t->same('action_not_found',(new AutomationExecutor(new AutomationRegistry(),new InMemoryAutomationStore()))->plan('missing',new AutomationExecutionRequest([],$actor,null,true))->code());

	$schema=AutomationAction::make('schema')->requiresIdempotency(false)->inputSchema(['required'=>['missing'],'properties'=>['weird'=>['type'=>'unsupported']]])->handle(static fn()=>true);
	$schemaResult=(new AutomationExecutor(new AutomationRegistry([$schema]),new InMemoryAutomationStore()))->plan('schema',new AutomationExecutionRequest(['weird'=>'x'],$actor,null,true));
	$t->same('validation_failed',$schemaResult->code());
	$t->same(2,count($schemaResult->issues()));

	$rollbackAction=AutomationAction::make('rollback_failure')->requiresIdempotency()->handle(static fn()=>['done'=>true])->rollbackUsing(static function(): never{throw new RuntimeException('rollback exploded');},['Manual repair']);
	$rollbackStore=new InMemoryAutomationStore();
	$rollbackExecutor=new AutomationExecutor(new AutomationRegistry([$rollbackAction]),$rollbackStore);
	$executed=$rollbackExecutor->execute('rollback_failure',new AutomationExecutionRequest([],$actor,'execute'));
	$id=(string)$executed->receipt()?->id();
	$failedRollback=$rollbackExecutor->rollback($id,new AutomationExecutionRequest([],$actor,'rollback',false,true,'ROLLBACK '.$id));
	$t->same('rollback_failed',$failedRollback->code());
	$t->same('rollback exploded',$failedRollback->receipt()?->error());

	$failedAction=AutomationAction::make('failed_with_rollback')->requiresIdempotency()->handle(static function(): never{throw new RuntimeException('failed');})->rollbackUsing(static fn()=>true);
	$failedStore=new InMemoryAutomationStore();
	$failedExecutor=new AutomationExecutor(new AutomationRegistry([$failedAction]),$failedStore);
	$failed=$failedExecutor->execute('failed_with_rollback',new AutomationExecutionRequest([],$actor,'failed'));
	$t->same('receipt_not_rollbackable',$failedExecutor->rollback((string)$failed->receipt()?->id(),new AutomationExecutionRequest([],$actor,null,false,true,'unused'))->code());

	$throwStore=new DpPanelScorchedAutomationStore();
	$throwStore->throwFind=true;
	$throwExecutor=new AutomationExecutor(new AutomationRegistry([AutomationAction::make('stored')->handle(static fn()=>true)]),$throwStore);
	$t->same('storage_failed',$throwExecutor->execute('stored',new AutomationExecutionRequest([],$actor,'key'))->code());
	$throwStore->throwFind=false;
	$throwStore->throwSave=true;
	$t->same('storage_failed',$throwExecutor->execute('stored',new AutomationExecutionRequest([],$actor,'key'))->code());
	$failedSaveStore=new DpPanelScorchedAutomationStore();
	$failedSaveStore->throwSave=true;
	$failedSaveAction=AutomationAction::make('failed_save')->handle(static function(): never{throw new RuntimeException('handler failed');});
	$t->same('storage_failed',(new AutomationExecutor(new AutomationRegistry([$failedSaveAction]),$failedSaveStore))->execute('failed_save',new AutomationExecutionRequest([],$actor,'failed-save'))->code());

	$getStore=new DpPanelScorchedAutomationStore();
	$getStore->throwGet=true;
	$t->same('storage_failed',(new AutomationExecutor(new AutomationRegistry([$rollbackAction]),$getStore))->rollback('x',new AutomationExecutionRequest([],$actor))->code());

	$allStore=new DpPanelScorchedAutomationStore();
	$allExecutor=new AutomationExecutor(new AutomationRegistry([$rollbackAction]),$allStore);
	$base=$allExecutor->execute('rollback_failure',new AutomationExecutionRequest([],$actor,'base'));
	$allStore->throwAll=true;
	$baseId=(string)$base->receipt()?->id();
	$t->same('storage_failed',$allExecutor->rollback($baseId,new AutomationExecutionRequest([],$actor,null,false,true,'ROLLBACK '.$baseId))->code());

	$findStore=new DpPanelScorchedAutomationStore();
	$findExecutor=new AutomationExecutor(new AutomationRegistry([$rollbackAction]),$findStore);
	$findBase=$findExecutor->execute('rollback_failure',new AutomationExecutionRequest([],$actor,'find-base'));
	$findStore->throwFind=true;
	$findId=(string)$findBase->receipt()?->id();
	$t->same('storage_failed',$findExecutor->rollback($findId,new AutomationExecutionRequest([],$actor,'find-rollback',false,true,'ROLLBACK '.$findId))->code());

	$rollbackSaveStore=new DpPanelScorchedAutomationStore();
	$rollbackSaveExecutor=new AutomationExecutor(new AutomationRegistry([$rollbackAction]),$rollbackSaveStore);
	$rollbackSaveBase=$rollbackSaveExecutor->execute('rollback_failure',new AutomationExecutionRequest([],$actor,'save-base'));
	$rollbackSaveStore->throwSave=true;
	$rollbackSaveId=(string)$rollbackSaveBase->receipt()?->id();
	$t->same('storage_failed',$rollbackSaveExecutor->rollback($rollbackSaveId,new AutomationExecutionRequest([],$actor,null,false,true,'ROLLBACK '.$rollbackSaveId))->code());
})->tag('panel','automation','failures','stores','coverage','scorched-earth')->group('framework-coverage');

test('automation executor resolves concurrent receipt races as replay or idempotency conflict',static function(Context $t): void {
	$actor=WorkflowActor::from('agent');
	$input=['value'=>1];
	$action=AutomationAction::make('race')->handle(static fn()=>['local'=>true]);
	$requestHash=hash('sha256',WorkflowEvent::canonicalJson($input));
	$existing=AutomationReceipt::create($action,'completed',$actor,'race-key',$input,'existing',['remote'=>true],null,[],null,['request_hash'=>$requestHash]);
	$store=new DpPanelScorchedAutomationStore();
	$store->refuseSave=true;
	$store->findSequence=[null,$existing];
	$result=(new AutomationExecutor(new AutomationRegistry([$action]),$store))->execute('race',new AutomationExecutionRequest($input,$actor,'race-key'));
	$t->same('idempotent_replay',$result->code());
	$t->isTrue($result->replayed());

	$conflicting=AutomationReceipt::create($action,'completed',$actor,'race-key',['value'=>2],'existing',['remote'=>true],null,[],null,['request_hash'=>hash('sha256',WorkflowEvent::canonicalJson(['value'=>2]))]);
	$store2=new DpPanelScorchedAutomationStore();
	$store2->refuseSave=true;
	$store2->findSequence=[null,$conflicting];
	$t->same('idempotency_conflict',(new AutomationExecutor(new AutomationRegistry([$action]),$store2))->execute('race',new AutomationExecutionRequest($input,$actor,'race-key'))->code());
})->tag('panel','automation','concurrency','coverage','scorched-earth')->group('framework-coverage');

test('automation filesystem store preserves receipts idempotency and checksum integrity',static function(Context $t): void {
	$t->throws(static fn()=>new FilesystemAutomationStore(' '),InvalidArgumentException::class);
	$blocked=$t->tempDirectory('panel-automation-blocked').DIRECTORY_SEPARATOR.'file';
	file_put_contents($blocked,'x');
	$t->throws(static fn()=>new FilesystemAutomationStore($blocked),RuntimeException::class);
	$directory=$t->tempDirectory('panel-automation-scorched');
	$store=new FilesystemAutomationStore($directory);
	$action=AutomationAction::make('durable')->requiresIdempotency()->handle(static fn(array $input): array=>['saved'=>$input['value']]);
	$executor=new AutomationExecutor(new AutomationRegistry([$action]),$store);
	$actor=WorkflowActor::from('agent');
	$result=$executor->execute('durable',new AutomationExecutionRequest(['value'=>3,'api_token'=>'hide'],$actor,'durable-1'));
	$t->same('executed',$result->code());
	$id=(string)$result->receipt()?->id();
	$reloaded=new FilesystemAutomationStore($directory);
	$t->same($id,$reloaded->get($id)?->id());
	$t->same($id,$reloaded->findByIdempotency('durable','durable-1')?->id());
	$t->same('[redacted]',$reloaded->get($id)?->input()['api_token']);
	$t->same(1,count($reloaded->all('durable')));
	$t->isFalse($reloaded->save($result->receipt()));
	$duplicate=AutomationReceipt::create($action,'completed',$actor,'durable-1',['value'=>3],'other',['saved'=>3]);
	$t->isFalse($reloaded->save($duplicate));
	$t->same('filesystem_automation_store',$reloaded->jsonSerialize()['type']);

	$file=(glob($directory.DIRECTORY_SEPARATOR.'*.receipt.json') ?: [])[0] ?? '';
	$envelope=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
	$envelope['receipt']['result']['tampered']=true;
	file_put_contents($file,json_encode($envelope,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$reloaded->get($id),RuntimeException::class);

	$invalidDirectory=$t->tempDirectory('panel-automation-invalid-json');
	$invalidStore=new FilesystemAutomationStore($invalidDirectory);
	$invalidReceipt=AutomationReceipt::create($action,'completed',$actor,null,[],'x',true);
	$t->isTrue($invalidStore->save($invalidReceipt));
	$invalidFile=(glob($invalidDirectory.DIRECTORY_SEPARATOR.'*.receipt.json') ?: [])[0] ?? '';
	file_put_contents($invalidFile,'{');
	$t->throws(static fn()=>$invalidStore->get($invalidReceipt->id()),RuntimeException::class);

	$unsupportedDirectory=$t->tempDirectory('panel-automation-unsupported');
	$unsupportedStore=new FilesystemAutomationStore($unsupportedDirectory);
	$unsupportedReceipt=AutomationReceipt::create($action,'completed',$actor,null,[],'x',true);
	$t->isTrue($unsupportedStore->save($unsupportedReceipt));
	$unsupportedFile=(glob($unsupportedDirectory.DIRECTORY_SEPARATOR.'*.receipt.json') ?: [])[0] ?? '';
	file_put_contents($unsupportedFile,'{}');
	$t->throws(static fn()=>$unsupportedStore->get($unsupportedReceipt->id()),RuntimeException::class);
})->tag('panel','automation','filesystem','scorched-earth')->group('framework-coverage');

test('automation filesystem store removes temporary receipts when atomic publication fails',static function(Context $t): void {
	$io=$t->state('panel.automation.io',['failure'=>'']);
	if(!function_exists('Dataphyre\\Panel\\rename')){
		$t->defineSymbols(<<<'PHP'
namespace Dataphyre\Panel;
function dp_panel_automation_io_failure(): string {
	return (string)\Dataphyre\Test\TestState::channel('panel.automation.io')->get('failure','');
}
function fwrite(mixed $stream,string $data,?int $length=null): int|false {
	if(dp_panel_automation_io_failure()==='write'){ return 0; }
	return $length===null ? \fwrite($stream,$data) : \fwrite($stream,$data,$length);
}
function fflush(mixed $stream): bool {
	if(dp_panel_automation_io_failure()==='flush'){ return false; }
	return \fflush($stream);
}
function rename(string $from,string $to,mixed $context=null): bool {
	if(dp_panel_automation_io_failure()==='rename'){ return false; }
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}
PHP);
	}
	foreach(['write','flush','rename'] as $failure){
		$directory=$t->tempDirectory('panel-automation-'.$failure.'-failure');
		$store=new FilesystemAutomationStore($directory);
		$receipt=AutomationReceipt::create(AutomationAction::make('publish'), 'completed', WorkflowActor::from('actor'), null, [], 'plan', true);
		$io->put('failure',$failure);
		$t->throws(static fn()=>$store->save($receipt),RuntimeException::class);
		$io->put('failure','');
		$t->same([],glob($directory.DIRECTORY_SEPARATOR.'*.tmp.*') ?: []);
	}
})->tag('panel','automation','filesystem','fault-injection','coverage','scorched-earth')->group('framework-coverage');
