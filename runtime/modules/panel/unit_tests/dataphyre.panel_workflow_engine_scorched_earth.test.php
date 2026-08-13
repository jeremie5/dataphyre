<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\FilesystemWorkflowStore;
use Dataphyre\Panel\InMemoryWorkflowStore;
use Dataphyre\Panel\WorkflowActor;
use Dataphyre\Panel\WorkflowApprovalPolicy;
use Dataphyre\Panel\WorkflowDefinition;
use Dataphyre\Panel\WorkflowEngine;
use Dataphyre\Panel\WorkflowEvent;
use Dataphyre\Panel\WorkflowRecord;
use Dataphyre\Panel\WorkflowResult;
use Dataphyre\Panel\WorkflowState;
use Dataphyre\Panel\WorkflowStore;
use Dataphyre\Panel\WorkflowTransition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelScorchedThrowingWorkflowStore implements WorkflowStore {
	public InMemoryWorkflowStore $inner;
	public bool $throwCreate=false;
	public bool $throwLoad=false;
	public bool $throwSave=false;
	public function __construct(){ $this->inner=new InMemoryWorkflowStore(); }
	public function create(WorkflowRecord $record): bool { if($this->throwCreate){ throw new RuntimeException('create unavailable'); } return $this->inner->create($record); }
	public function load(string $definition,string $id): ?WorkflowRecord { if($this->throwLoad){ throw new RuntimeException('load unavailable'); } return $this->inner->load($definition,$id); }
	public function compareAndSwap(WorkflowRecord $record,int $expectedVersion): bool { if($this->throwSave){ throw new RuntimeException('save unavailable'); } return $this->inner->compareAndSwap($record,$expectedVersion); }
	public function all(?string $definition=null): array { if($this->throwLoad){ throw new RuntimeException('list unavailable'); } return $this->inner->all($definition); }
}

/** @param callable():void|null $compensator */
function dp_panel_scorched_workflow_definition(?callable $compensator=null): WorkflowDefinition {
	$submit=WorkflowTransition::make('submit', 'draft', 'review')
		->roles('author')->permissions('orders.submit')
		->guard(static fn(WorkflowRecord $record,array $patch): array=>[
			'allowed'=>(float)($patch['amount'] ?? $record->data()['amount'] ?? 0)>0,
			'message'=>'Amount must be positive.',
		])
		->assignUsing(static fn(): array=>['actor'=>'queue:review', 'roles'=>['approver']])
		->sla(30)->reversible(true);
	$approve=WorkflowTransition::make('approve', 'review', 'approved')
		->roles('manager')->permissions('orders.approve')
		->approval(new WorkflowApprovalPolicy(2, ['approver'], ['orders.approve'], true, false, 1, 120))
		->reversible(true, $compensator ?? static fn(): bool=>true)
		->metadata(['audit'=>'financial']);
	return WorkflowDefinition::make('order_release', 'Order release')
		->state(WorkflowState::make('draft', ['draft'=>true, 'sla_seconds'=>60, 'assignment_roles'=>['author']]))
		->state('review', ['sla_seconds'=>45, 'assignment_roles'=>['approver']])
		->state('approved', ['terminal'=>true])
		->initial('draft')->transition($submit)->transition($approve)
		->metadata(['owner'=>'operations']);
}

suite('Panel workflow state-machine contracts')
	->contract('panel.workflow-engine', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('workflow-definition', 'policy', 'store', 'engine', 'audit')
	->isolation('case')
	->tag('panel', 'workflow')
	->group('framework-coverage');

test('workflow definitions actors policies manifests and audit values are explicit',static function(Context $t): void {
	$t->throws(static fn()=>new WorkflowActor(' '),InvalidArgumentException::class);
	$t->throws(static fn()=>WorkflowState::make(' '),InvalidArgumentException::class);
	$t->throws(static fn()=>WorkflowTransition::fromStates('x', [], 'done'),InvalidArgumentException::class);
	$t->throws(static fn()=>new WorkflowApprovalPolicy(0),InvalidArgumentException::class);

	$actor=WorkflowActor::from(['id'=>'operator:1','roles'=>['Order Manager','*'],'permissions'=>['orders.*'],'metadata'=>['tenant'=>'north']]);
	$t->same('operator:1',$actor->id());
	$t->isTrue($actor->hasRole('anything'));
	$t->isTrue($actor->can('orders.approve'));
	$t->isFalse($actor->can('users.delete'));
	$t->isTrue($actor->hasAnyRole([]));
	$t->isTrue($actor->hasAllPermissions(['orders.submit','orders.approve']));
	$t->isFalse($actor->hasAllPermissions(['orders.submit','users.delete']));

	$policy=WorkflowApprovalPolicy::from(['quorum'=>2,'roles'=>['approver'],'permissions'=>['orders.approve'],'allow_requester'=>true,'rejection_threshold'=>2,'expires_after_seconds'=>30]);
	$t->same(2,$policy->quorum());
	$t->isTrue($policy->allowRequester());
	$t->isTrue($policy->eligible(WorkflowActor::from(['id'=>'a','roles'=>['approver'],'permissions'=>['orders.approve']])));

	$definition=dp_panel_scorched_workflow_definition();
	$t->same([],$definition->validationErrors());
	$t->same($definition,$definition->assertValid());
	$manifest=$definition->jsonSerialize();
	$t->same('panel_workflow_definition',$manifest['type']);
	$t->same(3,count($manifest['states']));
	$t->same(1,$manifest['capabilities']['approvals']);
	$t->same(2,$manifest['capabilities']['rollback']);
	$t->same('financial',$definition->transitionNamed('approve')?->metadataValues()['audit']);
	$t->isTrue($definition->transitionNamed('submit')?->accepts('draft') ?? false);

	$invalid=WorkflowDefinition::make('invalid')->state('one')->initial('missing')->transition(WorkflowTransition::make('escape','one','missing'));
	$t->same(2,count($invalid->validationErrors()));
	$t->throws(static fn()=>$invalid->assertValid(),LogicException::class);

	$event=WorkflowEvent::make('created','actor','draft','draft',[],['state'=>'draft'],['state'=>['before'=>null,'after'=>'draft']],null,[],'','2026-07-12T12:00:00+00:00','event-fixed');
	$t->isTrue($event->verify(''));
	$t->isFalse($event->verify('wrong'));
	$t->isTrue(WorkflowEvent::fromArray($event->jsonSerialize())->verify(''));
	$t->same('{"a":2,"z":1}',WorkflowEvent::canonicalJson(['z'=>1,'a'=>2]));
	$deep='x';
	for($index=0;$index<30;$index++){ $deep=[$deep]; }
	$t->contains('[maximum depth]',json_encode(WorkflowRecord::jsonSafe($deep),JSON_THROW_ON_ERROR));
})->tag('panel','workflow','scorched-earth')->group('framework-coverage');

test('workflow engine executes drafts guards assignments approvals quorum rejection SLA and rollback',static function(Context $t): void {
	$compensations=0;
	$definition=dp_panel_scorched_workflow_definition(static function()use(&$compensations): bool { $compensations++; return true; });
	$store=new InMemoryWorkflowStore();
	$engine=(new WorkflowEngine($store,[$definition]))->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$author=WorkflowActor::from(['id'=>'author:1','roles'=>['author'],'permissions'=>['orders.submit']]);
	$manager=WorkflowActor::from(['id'=>'manager:1','roles'=>['manager'],'permissions'=>['orders.approve']]);
	$approver1=WorkflowActor::from(['id'=>'approver:1','roles'=>['approver'],'permissions'=>['orders.approve']]);
	$approver2=WorkflowActor::from(['id'=>'approver:2','roles'=>['approver'],'permissions'=>['orders.approve']]);

	$t->same('definition_not_found',$engine->start('missing','1',[],$author)->code());
	$t->same('invalid_instance_id',$engine->start('order_release',' ',[],$author)->code());
	$started=$engine->start('order_release','order-1',['amount'=>1,'customer'=>'Ada'],$author,'start-1');
	$t->isTrue($started->ok());
	$t->same(1,$started->record()?->version());
	$t->same('2026-07-12T12:01:00+00:00',$started->record()?->deadlineAt());
	$t->isTrue($engine->start('order_release','order-1',['amount'=>1,'customer'=>'Ada'],$author,'start-1')->replayed());
	$t->same('idempotency_conflict',$engine->start('order_release','order-1',['amount'=>2],$author,'start-1')->code());
	$t->same('instance_exists',$engine->start('order_release','order-1',[],$author,'different')->code());

	$draft=$engine->saveDraft('order_release','order-1',['notes'=>['one'=>'saved']],$author,1,'draft-1');
	$t->same('draft_saved',$draft->code());
	$t->same('saved',$draft->record()?->data()['notes']['one']);
	$t->same(2,$draft->record()?->version());
	$t->isTrue($engine->saveDraft('order_release','order-1',['notes'=>['one'=>'saved']],$author,null,'draft-1')->replayed());
	$t->same('idempotency_conflict',$engine->saveDraft('order_release','order-1',['notes'=>['one'=>'ignored']],$author,null,'draft-1')->code());
	$t->same('version_conflict',$engine->saveDraft('order_release','order-1',[],$author,1)->code());
	$t->same('role_required',$engine->transition('order_release','order-1','submit',[],WorkflowActor::from('stranger'))->code());
	$t->same('guard_refused',$engine->transition('order_release','order-1','submit',['amount'=>0],$author)->code());
	$t->same('transition_not_found',$engine->transition('order_release','order-1','missing',[],$author)->code());

	$submitted=$engine->transition('order_release','order-1','submit',['amount'=>25],$author,2,'submit-1');
	$t->same('transitioned',$submitted->code());
	$t->same('review',$submitted->record()?->state());
	$t->same('queue:review',$submitted->record()?->assignedTo());
	$t->same(['approver'],$submitted->record()?->assignedRoles());
	$t->same('2026-07-12T12:00:30+00:00',$submitted->record()?->deadlineAt());
	$t->isTrue($submitted->record()?->historyValid() ?? false);
	$t->isTrue(isset($submitted->events()[0]->diff()['data.amount']));
	$t->same('invalid_state',$engine->transition('order_release','order-1','submit',[],$author)->code());
	$t->same(1,count($engine->availableTransitions('order_release','order-1',$manager)));

	$pending=$engine->transition('order_release','order-1','approve',[],$manager,3,'approve-request');
	$t->same('approval_required',$pending->code());
	$t->same('approve',$pending->record()?->pendingApproval()['transition']);
	$t->same([],$engine->availableTransitions('order_release','order-1',$manager));
	$t->same('approval_pending',$engine->transition('order_release','order-1','submit',[],$author)->code());
	$t->same('approver_not_eligible',$engine->approve('order_release','order-1',$manager)->code());
	$requesterApprover=WorkflowActor::from(['id'=>'manager:1','roles'=>['approver'],'permissions'=>['orders.approve']]);
	$t->same('requester_cannot_approve',$engine->approve('order_release','order-1',$requesterApprover)->code());
	$one=$engine->approve('order_release','order-1',$approver1,'looks good',4,'decision-1');
	$t->same('approval_recorded',$one->code());
	$t->same(1,$one->metadata()['approvals']);
	$t->same('duplicate_approval_actor',$engine->approve('order_release','order-1',$approver1)->code());
	$approved=$engine->approve('order_release','order-1',$approver2,'approved',5,'decision-2');
	$t->same('transitioned',$approved->code());
	$t->same('approved',$approved->record()?->state());
	$t->same(null,$approved->record()?->pendingApproval());
	$t->same(2,count($approved->events()));

	$rolled=$engine->rollback('order_release','order-1',$manager,null,'mistake',6,'rollback-1');
	$t->same('rolled_back',$rolled->code());
	$t->same('review',$rolled->record()?->state());
	$t->same(1,$compensations);
	$t->isTrue($engine->rollback('order_release','order-1',$manager,null,'mistake',null,'rollback-1')->replayed());
	$t->same('rollback_not_latest',$engine->rollback('order_release','order-1',$manager,$submitted->events()[0]->id())->code());

	$assigned=$engine->assign('order_release','order-1','person:9',['manager'],$manager,7,'assign-1');
	$t->same('assigned',$assigned->code());
	$t->same('person:9',$assigned->record()?->assignedTo());
	$t->same('not_a_draft',$engine->saveDraft('order_release','order-1',[],$author)->code());
	$t->same(1,count($store->all('order_release')));
	$t->same('in_memory_workflow_store',$store->jsonSerialize()['type']);

	$engine->clock(static fn()=>new DateTimeImmutable('2026-07-12T13:00:00+00:00'));
	$breach=$engine->checkSla('order_release','order-1');
	$t->same('sla_breached',$breach->code());
	$t->isTrue($engine->checkSla('order_release','order-1')->replayed());

	$engine->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$engine->start('order_release','order-reject',['amount'=>10],$author);
	$engine->transition('order_release','order-reject','submit',[],$author);
	$engine->transition('order_release','order-reject','approve',[],$manager);
	$rejected=$engine->reject('order_release','order-reject',$approver1,'risk');
	$t->same('approval_rejected',$rejected->code());
	$t->same('review',$rejected->record()?->state());
	$t->same(null,$rejected->record()?->pendingApproval());
	$t->same('approval_not_pending',$engine->reject('order_release','order-reject',$approver2)->code());
	$t->same(1,$engine->jsonSerialize()['definition_count']);
})->tag('panel','workflow','approvals','scorched-earth')->group('framework-coverage');

test('workflow residual value contracts preserve audit integrity and safe serialization',static function(Context $t): void {
	$t->throws(static fn()=>WorkflowDefinition::make(' '),InvalidArgumentException::class);
	$t->throws(static fn()=>new WorkflowApprovalPolicy(1,[],[],true,false,0),InvalidArgumentException::class);
	$actor=WorkflowActor::from('actor:value');
	$t->same($actor,WorkflowActor::from($actor));
	$t->same([],$actor->roles());
	$t->same([],$actor->permissions());
	$t->same([],$actor->metadata());
	$t->isFalse($actor->hasRole('missing'));
	$t->isFalse($actor->hasAnyRole(['missing']));
	$t->isFalse($actor->can(' '));
	$t->same('actor:value',$actor->jsonSerialize()['id']);

	$policy=WorkflowApprovalPolicy::from([]);
	$t->same([],$policy->roles());
	$t->same([],$policy->permissions());
	$t->same(1,$policy->rejectionThreshold());
	$state=WorkflowState::make('done',['terminal'=>true,'metadata'=>['color'=>'green']]);
	$t->isTrue($state->terminal());
	$t->same('green',$state->metadata()['color']);
	$transition=WorkflowTransition::fromStates('move',['one','two'],'done')->assign('person:1','reviewer')->compensateUsing(static fn()=>true);
	$t->same('person:1',$transition->assignedActor());
	$t->same(['reviewer'],$transition->assignmentRoles());
	$t->isTrue($transition->isReversible());
	$definition=WorkflowDefinition::make('values','Value flow')->state('one')->state('two')->state($state)->transition($transition)->metadata(['x'=>1]);
	$t->same('Value flow',$definition->label());
	$t->same(3,count($definition->states()));
	$t->same(['x'=>1],$definition->metadataValues());
	$empty=WorkflowDefinition::make('empty');
	$t->same('Workflow has no states.',$empty->validationErrors()[0]);
	$missingSource=WorkflowDefinition::make('source')->state('done')->transition(WorkflowTransition::make('bad','missing','done'));
	$t->contains('missing source state',$missingSource->validationErrors()[0]);

	$event=WorkflowEvent::make('started','actor:value','','one',[],['state'=>'one'],[],null,[],'','invalid date','fixed-event');
	$t->same('actor:value',$event->actorId());
	$t->same('',$event->stateBefore());
	$t->same(['state'=>'one'],$event->after());
	$t->same('',$event->previousHash());
	$record=WorkflowRecord::create('values','record-1','one',['date'=>new DateTimeImmutable('2026-07-12T12:00:00+00:00')],$event);
	$t->same($event->occurredAt(),$record->createdAt());
	$t->same($event->occurredAt(),$record->updatedAt());
	$t->same(null,$record->event('missing'));
	$t->same(null,$record->lastAppliedTransition());
	$invalidDeadline=WorkflowRecord::fromArray(array_replace($record->jsonSerialize(),['deadline_at'=>'not-a-date']));
	$t->isFalse($invalidDeadline->isOverdue());
	$corrupt=$record->jsonSerialize();
	$corrupt['history'][0]['hash']=str_repeat('0',64);
	$t->isFalse(WorkflowRecord::fromArray($corrupt)->historyValid());

	$bounded=$record;
	for($index=0;$index<130;$index++){
		$bounded=$bounded->next([],[],'key-'.$index,['ok'=>true,'code'=>'ok']);
	}
	$t->same(128,count($bounded->jsonSerialize()['idempotency']));
	$t->same(null,$bounded->idempotencyResult('key-0'));
	$t->same('ok',$bounded->idempotencyResult('key-129')['code']);
	$date=new DateTimeImmutable('2026-07-12T12:00:00+00:00');
	$t->same($date->format('c'),WorkflowRecord::jsonSafe($date));
	$t->same(['value'=>1],WorkflowRecord::jsonSafe(new class implements JsonSerializable { public function jsonSerialize(): array{return ['value'=>1];} }));
	$t->same(['class'=>stdClass::class,'value'=>['value'=>2]],WorkflowRecord::jsonSafe((object)['value'=>2]));
	$resource=fopen('php://temp','rb');
	$t->same(['resource'=>'stream'],WorkflowRecord::jsonSafe($resource));
	fclose($resource);
	$t->isTrue(is_string(WorkflowRecord::jsonSafe($resource)));

	$failure=WorkflowResult::failure('bad','Nope',$record,['one'],['retry'=>false]);
	$t->same(['one'],$failure->errors());
	$t->same('Nope',$failure->jsonSerialize()['message']);
	$t->same(false,$failure->jsonSerialize()['ok']);
	$memory=new InMemoryWorkflowStore();
	$t->isFalse($memory->compareAndSwap($record,1));
})->tag('panel','workflow','values','coverage','scorched-earth')->group('framework-coverage');

test('workflow engine reports assignment guard approval compensation and storage failures without throwing',static function(Context $t): void {
	$author=WorkflowActor::from(['id'=>'author','roles'=>['author'],'permissions'=>['orders.submit']]);
	$noPermission=WorkflowActor::from(['id'=>'author-2','roles'=>['author']]);
	$manager=WorkflowActor::from(['id'=>'manager','roles'=>['manager'],'permissions'=>['orders.approve']]);
	$approver=WorkflowActor::from(['id'=>'approver','roles'=>['approver'],'permissions'=>['orders.approve']]);

	$store=new InMemoryWorkflowStore();
	$definition=dp_panel_scorched_workflow_definition(static function(): never{ throw new RuntimeException('compensation exploded'); });
	$engine=(new WorkflowEngine($store,[$definition]))->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$engine->start('order_release','edge',['amount'=>10],$author);
	$t->same('draft_not_assigned',$engine->saveDraft('order_release','edge',[],WorkflowActor::from('intruder'))->code());
	$engine->assign('order_release','edge','assigned-author',['other'],$author);
	$t->same('draft_saved',$engine->saveDraft('order_release','edge',['assigned'=>true],WorkflowActor::from('assigned-author'))->code());
	$t->same('permission_required',$engine->transition('order_release','edge','submit',[],$noPermission)->code());
	$t->same('sla_current',$engine->checkSla('order_release','edge')->code());
	$t->same('rollback_event_not_found',$engine->rollback('order_release','edge',$manager)->code());
	$engine->transition('order_release','edge','submit',[],$author);
	$engine->transition('order_release','edge','approve',[],$manager);
	$engine->approve('order_release','edge',$approver);
	$engine->approve('order_release','edge',WorkflowActor::from(['id'=>'approver-2','roles'=>['approver'],'permissions'=>['orders.approve']]));
	$t->same('compensation_failed',$engine->rollback('order_release','edge',$manager)->code());

	$guardDefinition=WorkflowDefinition::make('guard_throw')->state('one',['draft'=>true])->state('two')
		->transition(WorkflowTransition::make('go','one','two')->guard(static function(): never{ throw new RuntimeException('guard exploded'); }));
	$guardStore=new InMemoryWorkflowStore();
	$guardEngine=new WorkflowEngine($guardStore,[$guardDefinition]);
	$guardEngine->start('guard_throw','g',[],WorkflowActor::from('actor'));
	$t->same('guard_failed',$guardEngine->transition('guard_throw','g','go',[],WorkflowActor::from('actor'))->code());

	$assignmentDefinition=WorkflowDefinition::make('assign_throw')->state('one')->state('two')
		->transition(WorkflowTransition::make('go','one','two')->assignUsing(static function(): never{ throw new RuntimeException('assignment exploded'); }));
	$assignmentEngine=new WorkflowEngine(new InMemoryWorkflowStore(),[$assignmentDefinition]);
	$assignmentEngine->start('assign_throw','a',[],WorkflowActor::from('actor'));
	$t->same('assignment_failed',$assignmentEngine->transition('assign_throw','a','go',[],WorkflowActor::from('actor'))->code());

	$nonReversible=WorkflowDefinition::make('no_rollback')->state('one')->state('two')->transition(WorkflowTransition::make('go','one','two'));
	$nonStore=new InMemoryWorkflowStore();
	$nonEngine=new WorkflowEngine($nonStore,[$nonReversible]);
	$nonEngine->start('no_rollback','n',[],WorkflowActor::from('actor'));
	$nonEngine->transition('no_rollback','n','go',[],WorkflowActor::from('actor'));
	$t->same('rollback_not_supported',$nonEngine->rollback('no_rollback','n',WorkflowActor::from('actor'))->code());

	$expiryStore=new InMemoryWorkflowStore();
	$expiryEngine=(new WorkflowEngine($expiryStore,[dp_panel_scorched_workflow_definition()]))->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$expiryEngine->start('order_release','expired',['amount'=>10],$author);
	$expiryEngine->transition('order_release','expired','submit',[],$author);
	$expiryEngine->transition('order_release','expired','approve',[],$manager);
	$expiryEngine->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:03:00+00:00'));
	$t->same('approval_expired',$expiryEngine->approve('order_release','expired',$approver)->code());

	$badExpiry=$expiryStore->load('order_release','expired');
	$pending=$badExpiry?->pendingApproval() ?? [];
	$pending['expires_at']='invalid';
	$badNext=$badExpiry?->next(['pending_approval'=>$pending],[]) ?? throw new RuntimeException('missing record');
	$t->isTrue($expiryStore->compareAndSwap($badNext,$badExpiry->version()));
	$t->same('approval_invalid',$expiryEngine->approve('order_release','expired',$approver)->code());
	$changedDefinition=WorkflowDefinition::make('order_release')->state('draft')->state('review')->state('approved')
		->transition(WorkflowTransition::make('submit','draft','review'))->transition(WorkflowTransition::make('approve','review','approved'));
	$t->same('approval_invalid',(new WorkflowEngine($expiryStore,[$changedDefinition]))->approve('order_release','expired',$approver)->code());

	$mutable=dp_panel_scorched_workflow_definition();
	$invalidEngine=new WorkflowEngine(new InMemoryWorkflowStore(),[$mutable]);
	$t->nonPublic($mutable)->writeProperty('initial','missing');
	$t->same('invalid_definition',$invalidEngine->start('order_release','bad',[],$author)->code());

	$throwing=new DpPanelScorchedThrowingWorkflowStore();
	$throwing->throwCreate=true;
	$t->same('storage_failed',(new WorkflowEngine($throwing,[dp_panel_scorched_workflow_definition()]))->start('order_release','x',[],$author)->code());
	$throwing->throwCreate=false;
	$throwEngine=new WorkflowEngine($throwing,[dp_panel_scorched_workflow_definition()]);
	$throwEngine->start('order_release','x',['amount'=>10],$author);
	$throwing->throwLoad=true;
	$t->same('storage_failed',$throwEngine->saveDraft('order_release','x',[],$author)->code());
	$t->same([],$throwEngine->availableTransitions('order_release','x',$author));
	$throwing->throwLoad=false;
	$throwing->throwSave=true;
	$t->same('storage_failed',$throwEngine->saveDraft('order_release','x',['x'=>1],$author)->code());

	$failedRecord=$throwing->inner->load('order_release','x');
	$withFailure=$failedRecord?->next([],[],'failed-key',['ok'=>false,'code'=>'remembered_failure','message'=>'Remembered']) ?? throw new RuntimeException('record missing');
	$throwing->throwSave=false;
	$t->isTrue($throwing->inner->compareAndSwap($withFailure,$failedRecord->version()));
	$t->same('remembered_failure',$throwEngine->saveDraft('order_release','x',[],$author,null,'failed-key')->code());
})->tag('panel','workflow','failures','coverage','scorched-earth')->group('framework-coverage');

test('workflow approvals can allow repeated actors when distinct actor enforcement is disabled',static function(Context $t): void {
	$definition=WorkflowDefinition::make('repeat_approval')->state('pending')->state('done')
		->transition(WorkflowTransition::make('finish','pending','done')->approval(new WorkflowApprovalPolicy(2,[],[],false,true)));
	$engine=new WorkflowEngine(new InMemoryWorkflowStore(),[$definition]);
	$actor=WorkflowActor::from('same-actor');
	$engine->start('repeat_approval','one',[],$actor);
	$t->same('approval_required',$engine->transition('repeat_approval','one','finish',[],$actor)->code());
	$t->same('approval_recorded',$engine->approve('repeat_approval','one',$actor)->code());
	$t->same('transitioned',$engine->approve('repeat_approval','one',$actor)->code());
})->tag('panel','workflow','approval-policy','coverage','scorched-earth')->group('framework-coverage');

test('workflow filesystem adapter persists CAS records and rejects corruption',static function(Context $t): void {
	$t->throws(static fn()=>new FilesystemWorkflowStore(' '),InvalidArgumentException::class);
	$blocked=$t->tempDirectory('panel-workflow-blocked').DIRECTORY_SEPARATOR.'file';
	file_put_contents($blocked,'x');
	$t->throws(static fn()=>new FilesystemWorkflowStore($blocked),RuntimeException::class);
	$directory=$t->tempDirectory('panel-workflow-scorched');
	$store=new FilesystemWorkflowStore($directory);
	$definition=dp_panel_scorched_workflow_definition();
	$engine=(new WorkflowEngine($store,[$definition]))->clock(static fn()=>new DateTimeImmutable('2026-07-12T12:00:00+00:00'));
	$actor=WorkflowActor::from(['id'=>'author','roles'=>['author'],'permissions'=>['orders.submit']]);
	$t->same('started',$engine->start('order_release','persisted',['amount'=>10],$actor)->code());
	$loaded=$store->load('order_release','persisted');
	$t->instanceOf(WorkflowRecord::class,$loaded);
	$t->same(1,$loaded?->version());
	$t->isFalse($store->create($loaded));
	$t->same(null,$store->load('order_release','missing'));
	$t->isFalse($store->compareAndSwap($loaded,99));
	$draft=$engine->saveDraft('order_release','persisted',['note'=>'durable'],$actor,1);
	$t->same('draft_saved',$draft->code());
	$t->same('durable',(new FilesystemWorkflowStore($directory))->load('order_release','persisted')?->data()['note']);
	$t->same(1,count($store->all('order_release')));
	$t->same('filesystem_workflow_store',$store->jsonSerialize()['type']);

	$file=(glob($directory.DIRECTORY_SEPARATOR.'*.workflow.json') ?: [])[0] ?? '';
	$t->isTrue(is_file($file));
	$envelope=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
	$envelope['record']['data']['tampered']=true;
	file_put_contents($file,json_encode($envelope,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$store->load('order_release','persisted'),RuntimeException::class);

	foreach(['empty'=>'','invalid'=>'{','unsupported'=>'{}'] as $kind=>$contents){
		$caseDirectory=$t->tempDirectory('panel-workflow-'.$kind);
		$caseStore=new FilesystemWorkflowStore($caseDirectory);
		$caseEngine=new WorkflowEngine($caseStore,[$definition]);
		$caseEngine->start('order_release',$kind,['amount'=>1],$actor);
		$caseFile=(glob($caseDirectory.DIRECTORY_SEPARATOR.'*.workflow.json') ?: [])[0] ?? '';
		file_put_contents($caseFile,$contents);
		$t->throws(static fn()=>$caseStore->load('order_release',$kind),RuntimeException::class);
	}

	$historyDirectory=$t->tempDirectory('panel-workflow-history');
	$historyStore=new FilesystemWorkflowStore($historyDirectory);
	(new WorkflowEngine($historyStore,[$definition]))->start('order_release','history',['amount'=>1],$actor);
	$historyFile=(glob($historyDirectory.DIRECTORY_SEPARATOR.'*.workflow.json') ?: [])[0] ?? '';
	$historyEnvelope=json_decode((string)file_get_contents($historyFile),true,64,JSON_THROW_ON_ERROR);
	$historyEnvelope['record']['history'][0]['hash']=str_repeat('0',64);
	$historyEnvelope['checksum']=hash('sha256',WorkflowEvent::canonicalJson($historyEnvelope['record']));
	file_put_contents($historyFile,json_encode($historyEnvelope,JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$historyStore->load('order_release','history'),RuntimeException::class);
})->tag('panel','workflow','filesystem','scorched-earth')->group('framework-coverage');

test('workflow filesystem adapter cleans temporary files across injected IO failures',static function(Context $t): void {
	$io=$t->state('panel.workflow.io',['failure'=>'']);
	if(!function_exists('Dataphyre\\Panel\\fopen')){
		$t->defineSymbols(<<<'PHP'
namespace Dataphyre\Panel;
function dp_panel_workflow_io_failure(): string {
	return (string)\Dataphyre\Test\TestState::channel('panel.workflow.io')->get('failure','');
}
function fopen(string $filename,string $mode,bool $useIncludePath=false,mixed $context=null): mixed {
	$failure=dp_panel_workflow_io_failure();
	if($failure==='open_lock' && str_ends_with($filename,'.lock')){ return false; }
	if($failure==='open_temp' && str_contains($filename,'.tmp.')){ return false; }
	return $context===null ? \fopen($filename,$mode,$useIncludePath) : \fopen($filename,$mode,$useIncludePath,$context);
}
function flock(mixed $stream,int $operation,?int &$wouldBlock=null): bool {
	if(dp_panel_workflow_io_failure()==='flock' && $operation!==LOCK_UN){ return false; }
	return \flock($stream,$operation,$wouldBlock);
}
function fwrite(mixed $stream,string $data,?int $length=null): int|false {
	if(dp_panel_workflow_io_failure()==='write'){ return 0; }
	return $length===null ? \fwrite($stream,$data) : \fwrite($stream,$data,$length);
}
function fflush(mixed $stream): bool {
	if(dp_panel_workflow_io_failure()==='flush'){ return false; }
	return \fflush($stream);
}
function rename(string $from,string $to,mixed $context=null): bool {
	if(dp_panel_workflow_io_failure()==='rename'){ return false; }
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}
function random_bytes(int $length): string {
	if(dp_panel_workflow_io_failure()==='random'){ throw new \RuntimeException('random unavailable'); }
	return \random_bytes($length);
}
PHP);
	}
	$io->put('failure','random');
	$fallback=WorkflowEvent::make('fallback','actor','','one');
	$t->contains('fallback_',$fallback->id());
	$io->put('failure','');

	$recordFactory=static function(string $id): WorkflowRecord {
		$event=WorkflowEvent::make('started','actor','','one',[],['state'=>'one']);
		return WorkflowRecord::create('io_flow',$id,'one',[],$event);
	};
	foreach(['open_temp','write','flush','rename'] as $failure){
		$directory=$t->tempDirectory('panel-workflow-io-'.$failure);
		$store=new FilesystemWorkflowStore($directory);
		$io->put('failure',$failure);
		$t->throws(static fn()=>$store->create($recordFactory($failure)),RuntimeException::class);
		$t->same([],glob($directory.DIRECTORY_SEPARATOR.'*.tmp.*') ?: []);
		$io->put('failure','');
	}
	$lockDirectory=$t->tempDirectory('panel-workflow-io-lock');
	$lockStore=new FilesystemWorkflowStore($lockDirectory);
	$t->isTrue($lockStore->create($recordFactory('lock-record')));
	foreach(['open_lock','flock'] as $failure){
		$io->put('failure',$failure);
		$t->same([],$lockStore->all());
		$t->throws(static fn()=>$lockStore->load('io_flow','missing'),RuntimeException::class);
	}
	$io->put('failure','');
})->tag('panel','workflow','filesystem','fault-injection','coverage','scorched-earth')->group('framework-coverage');
