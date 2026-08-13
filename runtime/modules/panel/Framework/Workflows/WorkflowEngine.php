<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Executes validated workflows with optimistic concurrency and immutable audit history.
 */
final class WorkflowEngine implements PanelCheckpointableService, \JsonSerializable {
	private const MAX_DEFINITIONS=4096;
	/** @var array<string,WorkflowDefinition> */
	private array $definitions=[];
	private ?\Closure $clock=null;
	private int $revision=0;
	private readonly string $checkpointOwner;

	/** @param iterable<WorkflowDefinition> $definitions */
	public function __construct(private readonly WorkflowStore $store, iterable $definitions=[]) {
		$this->checkpointOwner=bin2hex(random_bytes(16));
		foreach($definitions as $definition){
			$this->register($definition);
		}
	}

	public function register(WorkflowDefinition $definition): self {
		$definition->assertValid();
		if(!isset($this->definitions[$definition->name()])&&count($this->definitions)>=self::MAX_DEFINITIONS){throw new \OverflowException('Panel workflow definition capacity is exhausted.');}
		$this->definitions[$definition->name()]=$definition;
		$this->revision++;
		return $this;
	}

	/** Removes one definition without disturbing workflow instances or unrelated definitions. */
	public function unregister(string $name): self {
		$name=WorkflowState::normalize($name);
		if(isset($this->definitions[$name])){
			unset($this->definitions[$name]);
			$this->revision++;
		}
		return $this;
	}

	/** @return array<string,WorkflowDefinition> */
	public function definitions(): array {
		$definitions=$this->definitions;
		ksort($definitions, SORT_STRING);
		return $definitions;
	}

	public function clock(callable $clock): self {
		$this->clock=\Closure::fromCallable($clock);
		$this->revision++;
		return $this;
	}

	public function definition(string $name): ?WorkflowDefinition {
		return $this->definitions[WorkflowState::normalize($name)] ?? null;
	}
	public function store():WorkflowStore{return$this->store;}

	public function revision():int{return$this->revision;}
	public function checkpointType():string{return'panel_workflow_engine_v1';}
	/** @return array{owner:string,definitions:array<string,WorkflowDefinition>,clock:?\Closure,revision:int,digest:string} */
	public function checkpoint():array{return['owner'=>$this->checkpointOwner,'definitions'=>$this->definitions,'clock'=>$this->clock,'revision'=>$this->revision,'digest'=>$this->checkpointDigest($this->definitions,$this->clock,$this->revision)];}
	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self{
		if(array_keys($checkpoint)!==['owner','definitions','clock','revision','digest']||!is_string($checkpoint['owner'])||!hash_equals($this->checkpointOwner,$checkpoint['owner'])||!is_array($checkpoint['definitions'])||count($checkpoint['definitions'])>self::MAX_DEFINITIONS||(!is_null($checkpoint['clock'])&&!$checkpoint['clock'] instanceof \Closure)||!is_int($checkpoint['revision'])||$checkpoint['revision']<0||!is_string($checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel workflow engine checkpoint.');}
		foreach($checkpoint['definitions']as$name=>$definition){if(!is_string($name)||WorkflowState::normalize($name)!==$name||!$definition instanceof WorkflowDefinition||$definition->name()!==$name){throw new \InvalidArgumentException('Invalid Panel workflow engine checkpoint.');}try{$definition->assertValid();}catch(\Throwable $error){throw new \InvalidArgumentException('Invalid Panel workflow engine checkpoint.',0,$error);}}
		if(!hash_equals($this->checkpointDigest($checkpoint['definitions'],$checkpoint['clock'],$checkpoint['revision']),$checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel workflow engine checkpoint.');}
		$this->definitions=$checkpoint['definitions'];$this->clock=$checkpoint['clock'];$this->revision=$checkpoint['revision'];return$this;
	}
	/** @param array<string,WorkflowDefinition> $definitions */ private function checkpointDigest(array $definitions,?\Closure $clock,int $revision):string{$identities=[];foreach($definitions as$name=>$definition){$identities[$name]=spl_object_id($definition);}return hash('sha256',json_encode(['owner'=>$this->checkpointOwner,'definitions'=>$identities,'clock'=>$clock===null?null:spl_object_id($clock),'revision'=>$revision],JSON_THROW_ON_ERROR));}

	/** @param array<string,mixed> $data @param WorkflowActor|array<string,mixed>|string $actor @param array<string,mixed> $metadata */
	public function start(string $definitionName, string $id, array $data, WorkflowActor|array|string $actor, ?string $idempotencyKey=null, array $metadata=[]): WorkflowResult {
		$definition=$this->definition($definitionName);
		if(!$definition instanceof WorkflowDefinition){
			return WorkflowResult::failure('definition_not_found', "Workflow '{$definitionName}' is not registered.");
		}
		$id=trim($id);
		if($id===''){
			return WorkflowResult::failure('invalid_instance_id', 'Workflow instance id cannot be empty.');
		}
		$actor=WorkflowActor::from($actor);
		$startFingerprint=$this->fingerprint('start', ['definition'=>$definition->name(), 'id'=>$id, 'data'=>$data, 'actor'=>$actor->id(), 'metadata'=>$metadata]);
		$initial=$definition->stateNamed($definition->initialState());
		if(!$initial instanceof WorkflowState){
			return WorkflowResult::failure('invalid_definition', 'Workflow initial state is unavailable.');
		}
		$idempotencyHash=$idempotencyKey===null || trim($idempotencyKey)==='' ? null : hash('sha256', trim($idempotencyKey));
		$metadata=WorkflowRecord::jsonSafe(array_replace($metadata, ['start_idempotency'=>$idempotencyHash, 'start_fingerprint'=>$startFingerprint]));
		$deadline=$this->deadline($initial->slaSeconds());
		$after=['state'=>$initial->name(), 'data'=>$data, 'assigned_to'=>null, 'assigned_roles'=>$initial->assignmentRoles(), 'deadline_at'=>$deadline];
		$event=$this->event('workflow_started', $actor, '', $initial->name(), [], $after, null, ['definition'=>$definition->name()]);
		$record=WorkflowRecord::create($definition->name(), $id, $initial->name(), $data, $event, null, $initial->assignmentRoles(), $deadline, $metadata);
		try{
			if(!$this->store->create($record)){
				$existing=$this->store->load($definition->name(), $id);
				if($existing instanceof WorkflowRecord && $idempotencyHash!==null && hash_equals((string)($existing->metadata()['start_idempotency'] ?? ''), $idempotencyHash)){
					if(!hash_equals((string)($existing->metadata()['start_fingerprint'] ?? ''), $startFingerprint)){
						return WorkflowResult::failure('idempotency_conflict', 'This start idempotency key was already used with different input.', $existing);
					}
					return WorkflowResult::success('started', 'Workflow instance already started by this idempotent request.', $existing)->asReplay();
				}
				return WorkflowResult::failure('instance_exists', "Workflow instance '{$id}' already exists.", $existing);
			}
		}catch(\Throwable $exception){
			return $this->storageFailure($exception);
		}
		return WorkflowResult::success('started', 'Workflow instance started.', $record, [$event]);
	}

	/** @param array<string,mixed> $dataPatch @param WorkflowActor|array<string,mixed>|string $actor */
	public function transition(string $definitionName, string $id, string $transitionName, array $dataPatch, WorkflowActor|array|string $actor, ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[$definition, $record]=$loaded;
		$actor=WorkflowActor::from($actor);
		$fingerprint=$this->fingerprint('transition', ['transition'=>WorkflowState::normalize($transitionName), 'data_patch'=>$dataPatch, 'actor'=>$actor->id()]);
		if(($replay=$this->replay($record, $idempotencyKey, $fingerprint)) instanceof WorkflowResult){ return $replay; }
		if(($version=$this->versionCheck($record, $expectedVersion)) instanceof WorkflowResult){ return $version; }
		$transition=$definition->transitionNamed($transitionName);
		if(!$transition instanceof WorkflowTransition){
			return WorkflowResult::failure('transition_not_found', "Transition '{$transitionName}' does not exist.", $record);
		}
		if($record->pendingApproval()!==null){
			return WorkflowResult::failure('approval_pending', 'Resolve the pending approval before requesting another transition.', $record);
		}
		if(!$transition->accepts($record->state())){
			return WorkflowResult::failure('invalid_state', "Transition '{$transition->name()}' is unavailable from state '{$record->state()}'.", $record);
		}
		if(($authority=$this->authorize($transition, $actor, $record)) instanceof WorkflowResult){ return $authority; }
		if(($guard=$this->guard($definition, $transition, $record, $dataPatch, $actor)) instanceof WorkflowResult){ return $guard; }
		if($transition->approvalPolicy() instanceof WorkflowApprovalPolicy){
			return $this->requestApproval($definition, $transition, $record, $dataPatch, $actor, $idempotencyKey, $fingerprint);
		}
		return $this->applyTransition($definition, $transition, $record, $dataPatch, $actor, [], $idempotencyKey, [], $fingerprint);
	}

	/** @param WorkflowActor|array<string,mixed>|string $actor */
	public function approve(string $definitionName, string $id, WorkflowActor|array|string $actor, string $comment='', ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		return $this->decideApproval($definitionName, $id, $actor, true, $comment, $expectedVersion, $idempotencyKey);
	}

	/** @param WorkflowActor|array<string,mixed>|string $actor */
	public function reject(string $definitionName, string $id, WorkflowActor|array|string $actor, string $comment='', ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		return $this->decideApproval($definitionName, $id, $actor, false, $comment, $expectedVersion, $idempotencyKey);
	}

	/** @param array<string,mixed> $dataPatch @param WorkflowActor|array<string,mixed>|string $actor */
	public function saveDraft(string $definitionName, string $id, array $dataPatch, WorkflowActor|array|string $actor, ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[$definition, $record]=$loaded;
		$actor=WorkflowActor::from($actor);
		$fingerprint=$this->fingerprint('save_draft', ['data_patch'=>$dataPatch, 'actor'=>$actor->id()]);
		if(($replay=$this->replay($record, $idempotencyKey, $fingerprint)) instanceof WorkflowResult){ return $replay; }
		if(($version=$this->versionCheck($record, $expectedVersion)) instanceof WorkflowResult){ return $version; }
		if($definition->stateNamed($record->state())?->draft()!==true){
			return WorkflowResult::failure('not_a_draft', "State '{$record->state()}' does not accept draft saves.", $record);
		}
		$assigned=$record->assignedTo()!==null
			? $record->assignedTo()===$actor->id()
			: $actor->hasAnyRole($record->assignedRoles());
		if(!$assigned){
			return WorkflowResult::failure('draft_not_assigned', 'Actor is not assigned to edit this draft.', $record);
		}
		$data=array_replace_recursive($record->data(), WorkflowRecord::jsonSafe($dataPatch));
		$before=$this->snapshot($record);
		$after=array_replace($before, ['data'=>$data]);
		$event=$this->event('draft_saved', $actor, $record->state(), $record->state(), $before, $after, null, [], $record->lastHash());
		$next=$record->next(['data'=>$data], [$event]);
		$result=WorkflowResult::success('draft_saved', 'Draft saved.', $next, [$event]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	/** @param list<string>|string $roles @param WorkflowActor|array<string,mixed>|string $actor */
	public function assign(string $definitionName, string $id, ?string $assignedTo, array|string $roles, WorkflowActor|array|string $actor, ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[, $record]=$loaded;
		$actor=WorkflowActor::from($actor);
		$roles=is_array($roles) ? $roles : [$roles];
		$fingerprint=$this->fingerprint('assign', ['assigned_to'=>$assignedTo, 'roles'=>$roles, 'actor'=>$actor->id()]);
		if(($replay=$this->replay($record, $idempotencyKey, $fingerprint)) instanceof WorkflowResult){ return $replay; }
		if(($version=$this->versionCheck($record, $expectedVersion)) instanceof WorkflowResult){ return $version; }
		$before=$this->snapshot($record);
		$after=array_replace($before, ['assigned_to'=>$assignedTo, 'assigned_roles'=>$roles]);
		$event=$this->event('assignment_changed', $actor, $record->state(), $record->state(), $before, $after, null, [], $record->lastHash());
		$next=$record->next(['assigned_to'=>$assignedTo, 'assigned_roles'=>$roles], [$event]);
		$result=WorkflowResult::success('assigned', 'Workflow assignment updated.', $next, [$event]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	/** @param WorkflowActor|array<string,mixed>|string $actor */
	public function rollback(string $definitionName, string $id, WorkflowActor|array|string $actor, ?string $eventId=null, string $reason='', ?int $expectedVersion=null, ?string $idempotencyKey=null): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[$definition, $record]=$loaded;
		$actor=WorkflowActor::from($actor);
		$fingerprint=$this->fingerprint('rollback', ['event_id'=>$eventId, 'reason'=>trim($reason), 'actor'=>$actor->id()]);
		if(($replay=$this->replay($record, $idempotencyKey, $fingerprint)) instanceof WorkflowResult){ return $replay; }
		if(($version=$this->versionCheck($record, $expectedVersion)) instanceof WorkflowResult){ return $version; }
		$target=$eventId===null ? $record->lastAppliedTransition() : $record->event($eventId);
		if(!$target instanceof WorkflowEvent || $target->type()!=='transition_applied'){
			return WorkflowResult::failure('rollback_event_not_found', 'The requested transition event cannot be rolled back.', $record);
		}
		if($record->lastAppliedTransition()?->id()!==$target->id() || $record->state()!==$target->stateAfter()){
			return WorkflowResult::failure('rollback_not_latest', 'Only the latest active transition may be rolled back safely.', $record);
		}
		$transition=$definition->transitionNamed((string)$target->transition());
		if(!$transition instanceof WorkflowTransition || !$transition->isReversible()){
			return WorkflowResult::failure('rollback_not_supported', 'This transition does not declare rollback support.', $record);
		}
		if(($authority=$this->authorize($transition, $actor, $record)) instanceof WorkflowResult){ return $authority; }
		if($transition->compensator() instanceof \Closure){
			try{
				$outcome=($transition->compensator())($record, $target, $actor, $reason, $definition);
				if($outcome===false || is_string($outcome)){
					return WorkflowResult::failure('compensation_refused', is_string($outcome) ? $outcome : 'Transition compensation refused rollback.', $record);
				}
			}catch(\Throwable $exception){
				return WorkflowResult::failure('compensation_failed', 'Transition compensation failed.', $record, [$exception->getMessage()]);
			}
		}
		$restore=$target->before();
		$before=$this->snapshot($record);
		$event=$this->event('rollback_applied', $actor, $record->state(), (string)($restore['state'] ?? $target->stateBefore()), $before, $restore, $transition->name(), ['rolled_back_event'=>$target->id(), 'reason'=>trim($reason)], $record->lastHash());
		$changes=[
			'state'=>$restore['state'] ?? $target->stateBefore(), 'data'=>$restore['data'] ?? $record->data(),
			'assigned_to'=>$restore['assigned_to'] ?? null, 'assigned_roles'=>$restore['assigned_roles'] ?? [],
			'deadline_at'=>$restore['deadline_at'] ?? null, 'pending_approval'=>null,
		];
		$next=$record->next($changes, [$event]);
		$result=WorkflowResult::success('rolled_back', 'Transition rolled back and compensation completed.', $next, [$event], ['rolled_back_event'=>$target->id()]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	/** Records one SLA breach exactly once for the active deadline. */
	public function checkSla(string $definitionName, string $id, WorkflowActor|array|string $actor='system:sla'): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[, $record]=$loaded;
		if(!$record->isOverdue($this->now())){
			return WorkflowResult::success('sla_current', 'Workflow SLA is not overdue.', $record);
		}
		foreach(array_reverse($record->history()) as $existing){
			if($existing->type()==='sla_breached' && ($existing->metadata()['deadline_at'] ?? null)===$record->deadlineAt()){
				return WorkflowResult::success('sla_breached', 'Workflow SLA breach was already recorded.', $record, [$existing])->asReplay();
			}
		}
		$actor=WorkflowActor::from($actor);
		$snapshot=$this->snapshot($record);
		$event=$this->event('sla_breached', $actor, $record->state(), $record->state(), $snapshot, $snapshot, null, ['deadline_at'=>$record->deadlineAt()], $record->lastHash());
		$next=$record->next([], [$event]);
		$result=WorkflowResult::success('sla_breached', 'Workflow SLA breach recorded.', $next, [$event]);
		return $this->persist($record, $next, $result, null);
	}

	/** @return list<array<string,mixed>> */
	public function availableTransitions(string $definitionName, string $id, WorkflowActor|array|string $actor): array {
		$definition=$this->definition($definitionName);
		try{ $record=$definition instanceof WorkflowDefinition ? $this->store->load($definition->name(), $id) : null; }
		catch(\Throwable){ return []; }
		if(!$definition instanceof WorkflowDefinition || !$record instanceof WorkflowRecord || $record->pendingApproval()!==null){ return []; }
		$actor=WorkflowActor::from($actor);
		$result=[];
		foreach($definition->transitions() as $transition){
			if($transition->accepts($record->state()) && $this->authorize($transition, $actor, $record)===null){
				$result[]=$transition->jsonSerialize();
			}
		}
		return $result;
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_workflow_engine', 'definition_count'=>count($this->definitions),'revision'=>$this->revision,
			'definitions'=>array_map(static fn(WorkflowDefinition $definition): array=>$definition->jsonSerialize(), array_values($this->definitions)),
			'store'=>$this->store,
		];
	}

	/** @return array{WorkflowDefinition,WorkflowRecord}|WorkflowResult */
	private function load(string $definitionName, string $id): array|WorkflowResult {
		$definition=$this->definition($definitionName);
		if(!$definition instanceof WorkflowDefinition){ return WorkflowResult::failure('definition_not_found', "Workflow '{$definitionName}' is not registered."); }
		try{ $record=$this->store->load($definition->name(), trim($id)); }
		catch(\Throwable $exception){ return $this->storageFailure($exception); }
		if(!$record instanceof WorkflowRecord){ return WorkflowResult::failure('instance_not_found', "Workflow instance '{$id}' was not found."); }
		if(!$record->historyValid()){ return WorkflowResult::failure('audit_corrupt', 'Workflow audit history failed verification.', $record); }
		return [$definition, $record];
	}

	private function replay(WorkflowRecord $record, ?string $key, ?string $fingerprint=null): ?WorkflowResult {
		if($key===null || trim($key)==='' || !is_array($stored=$record->idempotencyResult($key))){ return null; }
		if($fingerprint!==null && is_string($stored['fingerprint'] ?? null) && $stored['fingerprint']!=='' && !hash_equals($stored['fingerprint'], $fingerprint)){
			return WorkflowResult::failure('idempotency_conflict', 'This idempotency key was already used with different workflow input.', $record);
		}
		return (($stored['ok'] ?? false)===true
			? WorkflowResult::success((string)($stored['code'] ?? 'ok'), (string)($stored['message'] ?? 'Idempotent request replayed.'), $record, [], is_array($stored['metadata'] ?? null) ? $stored['metadata'] : [])
			: WorkflowResult::failure((string)($stored['code'] ?? 'failed'), (string)($stored['message'] ?? 'Idempotent request replayed.'), $record)
		)->asReplay();
	}

	private function versionCheck(WorkflowRecord $record, ?int $expected): ?WorkflowResult {
		return $expected!==null && $expected!==$record->version()
			? WorkflowResult::failure('version_conflict', "Expected workflow version {$expected}, current version is {$record->version()}.", $record, [], ['expected'=>$expected, 'actual'=>$record->version()])
			: null;
	}

	private function authorize(WorkflowTransition $transition, WorkflowActor $actor, WorkflowRecord $record): ?WorkflowResult {
		if(!$actor->hasAnyRole($transition->requiredRoles())){
			return WorkflowResult::failure('role_required', 'Actor does not hold a role required by this transition.', $record, [], ['required_roles'=>$transition->requiredRoles()]);
		}
		if(!$actor->hasAllPermissions($transition->requiredPermissions())){
			return WorkflowResult::failure('permission_required', 'Actor lacks a permission required by this transition.', $record, [], ['required_permissions'=>$transition->requiredPermissions()]);
		}
		return null;
	}

	/** @param array<string,mixed> $patch */
	private function guard(WorkflowDefinition $definition, WorkflowTransition $transition, WorkflowRecord $record, array $patch, WorkflowActor $actor): ?WorkflowResult {
		if(!$transition->guardResolver() instanceof \Closure){ return null; }
		try{ $outcome=($transition->guardResolver())($record, $patch, $actor, $transition, $definition); }
		catch(\Throwable $exception){ return WorkflowResult::failure('guard_failed', 'Transition guard could not be evaluated.', $record, [$exception->getMessage()]); }
		$allowed=is_array($outcome) ? ($outcome['allowed'] ?? false)===true : $outcome===true;
		if($allowed){ return null; }
		$message=is_string($outcome) ? trim($outcome) : (is_array($outcome) ? trim((string)($outcome['message'] ?? '')) : '');
		return WorkflowResult::failure('guard_refused', $message!=='' ? $message : 'Transition guard refused the operation.', $record);
	}

	/** @param array<string,mixed> $patch */
	private function requestApproval(WorkflowDefinition $definition, WorkflowTransition $transition, WorkflowRecord $record, array $patch, WorkflowActor $actor, ?string $idempotencyKey, ?string $fingerprint=null): WorkflowResult {
		$policy=$transition->approvalPolicy();
		$created=$this->now();
		$pending=[
			'id'=>'approval_'.bin2hex(random_bytes(10)), 'transition'=>$transition->name(),
			'requested_by'=>$actor->id(), 'requested_at'=>$created->format('c'),
			'expires_at'=>$policy?->expiresAfterSeconds()===null ? null : $created->modify('+'.$policy->expiresAfterSeconds().' seconds')->format('c'),
			'data_patch'=>WorkflowRecord::jsonSafe($patch), 'approvals'=>[], 'rejections'=>[],
			'policy'=>$policy,
		];
		$snapshot=$this->snapshot($record);
		$event=$this->event('approval_requested', $actor, $record->state(), $record->state(), $snapshot, $snapshot, $transition->name(), ['approval_id'=>$pending['id']], $record->lastHash());
		$next=$record->next(['pending_approval'=>$pending], [$event]);
		$result=WorkflowResult::success('approval_required', 'Transition is waiting for human approval.', $next, [$event], ['approval'=>$pending]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	private function decideApproval(string $definitionName, string $id, WorkflowActor|array|string $actor, bool $approve, string $comment, ?int $expectedVersion, ?string $idempotencyKey): WorkflowResult {
		$loaded=$this->load($definitionName, $id);
		if($loaded instanceof WorkflowResult){ return $loaded; }
		[$definition, $record]=$loaded;
		$actor=WorkflowActor::from($actor);
		$fingerprint=$this->fingerprint($approve ? 'approve' : 'reject', ['actor'=>$actor->id(), 'comment'=>trim($comment)]);
		if(($replay=$this->replay($record, $idempotencyKey, $fingerprint)) instanceof WorkflowResult){ return $replay; }
		if(($version=$this->versionCheck($record, $expectedVersion)) instanceof WorkflowResult){ return $version; }
		$pending=$record->pendingApproval();
		if(!is_array($pending)){
			return WorkflowResult::failure('approval_not_pending', 'This workflow has no pending approval.', $record);
		}
		$transition=$definition->transitionNamed((string)($pending['transition'] ?? ''));
		$policy=$transition?->approvalPolicy();
		if(!$transition instanceof WorkflowTransition || !$policy instanceof WorkflowApprovalPolicy){
			return WorkflowResult::failure('approval_invalid', 'Pending approval no longer matches the workflow definition.', $record);
		}
		if(isset($pending['expires_at']) && is_string($pending['expires_at']) && $pending['expires_at']!==''){
			try{ $expiresAt=new \DateTimeImmutable($pending['expires_at']); }
			catch(\Throwable){ return WorkflowResult::failure('approval_invalid', 'Pending approval expiry is invalid.', $record); }
			if($expiresAt < $this->now()){
				return WorkflowResult::failure('approval_expired', 'The pending approval has expired.', $record);
			}
		}
		if(!$policy->eligible($actor)){
			return WorkflowResult::failure('approver_not_eligible', 'Actor does not satisfy approver role or permission policy.', $record);
		}
		if(!$policy->allowRequester() && hash_equals((string)($pending['requested_by'] ?? ''), $actor->id())){
			return WorkflowResult::failure('requester_cannot_approve', 'The transition requester cannot decide this approval.', $record);
		}
		$approvals=is_array($pending['approvals'] ?? null) ? $pending['approvals'] : [];
		$rejections=is_array($pending['rejections'] ?? null) ? $pending['rejections'] : [];
		$decidedActors=array_merge(array_keys($approvals), array_keys($rejections));
		if($policy->distinctActors() && in_array($actor->id(), $decidedActors, true)){
			return WorkflowResult::failure('duplicate_approval_actor', 'This actor already recorded a decision.', $record);
		}
		$decision=['actor_id'=>$actor->id(), 'comment'=>trim($comment), 'decided_at'=>$this->now()->format('c')];
		$decisionKey=$policy->distinctActors() ? $actor->id() : $actor->id().'#'.(count($approvals)+count($rejections)+1);
		if($approve){
			$approvals[$decisionKey]=$decision;
		}else{
			$rejections[$decisionKey]=$decision;
		}
		$pending['approvals']=$approvals;
		$pending['rejections']=$rejections;
		$snapshot=$this->snapshot($record);
		$type=$approve ? 'approval_recorded' : 'rejection_recorded';
		$event=$this->event($type, $actor, $record->state(), $record->state(), $snapshot, $snapshot, $transition->name(), ['approval_id'=>$pending['id'] ?? null, 'comment'=>trim($comment)], $record->lastHash());
		if(!$approve && count($rejections)>=$policy->rejectionThreshold()){
			$next=$record->next(['pending_approval'=>null], [$event]);
			$result=WorkflowResult::success('approval_rejected', 'Transition approval was rejected.', $next, [$event], ['rejections'=>count($rejections)]);
			return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
		}
		if($approve && count($approvals)>=$policy->quorum()){
			return $this->applyTransition($definition, $transition, $record, is_array($pending['data_patch'] ?? null) ? $pending['data_patch'] : [], $actor, [$event], $idempotencyKey, ['approval_id'=>$pending['id'] ?? null, 'approved_by'=>array_keys($approvals)], $fingerprint);
		}
		$next=$record->next(['pending_approval'=>$pending], [$event]);
		$result=WorkflowResult::success($approve ? 'approval_recorded' : 'rejection_recorded', $approve ? 'Approval recorded; quorum is still pending.' : 'Rejection recorded; rejection threshold is still pending.', $next, [$event], ['approvals'=>count($approvals), 'rejections'=>count($rejections), 'quorum'=>$policy->quorum()]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	/** @param array<string,mixed> $patch @param list<WorkflowEvent> $prefixEvents @param array<string,mixed> $metadata */
	private function applyTransition(WorkflowDefinition $definition, WorkflowTransition $transition, WorkflowRecord $record, array $patch, WorkflowActor $actor, array $prefixEvents=[], ?string $idempotencyKey=null, array $metadata=[], ?string $fingerprint=null): WorkflowResult {
		$data=array_replace_recursive($record->data(), WorkflowRecord::jsonSafe($patch));
		$target=$definition->stateNamed($transition->to());
		if(!$target instanceof WorkflowState){ return WorkflowResult::failure('target_state_missing', 'Transition target state is unavailable.', $record); }
		$assignedTo=$transition->assignedActor();
		$assignedRoles=$transition->assignmentRoles();
		if($transition->assignmentResolver() instanceof \Closure){
			try{ $assignment=($transition->assignmentResolver())($record, $data, $actor, $transition, $definition); }
			catch(\Throwable $exception){ return WorkflowResult::failure('assignment_failed', 'Transition assignment could not be resolved.', $record, [$exception->getMessage()]); }
			if(is_string($assignment)){ $assignedTo=trim($assignment) ?: null; }
			elseif(is_array($assignment)){
				$assignedTo=isset($assignment['actor']) ? trim((string)$assignment['actor']) : null;
				$assignedRoles=is_array($assignment['roles'] ?? null) ? $assignment['roles'] : $assignedRoles;
			}
		}
		if($assignedTo===null && $assignedRoles===[]){
			$assignedTo=$record->assignedTo();
			$assignedRoles=$target->assignmentRoles()!==[] ? $target->assignmentRoles() : $record->assignedRoles();
		}
		$deadline=$this->deadline($transition->slaSeconds() ?? $target->slaSeconds());
		$before=$this->snapshot($record);
		$after=['state'=>$target->name(), 'data'=>$data, 'assigned_to'=>$assignedTo, 'assigned_roles'=>$assignedRoles, 'deadline_at'=>$deadline];
		$previous=$prefixEvents!==[] ? $prefixEvents[count($prefixEvents)-1]->hash() : $record->lastHash();
		$event=$this->event('transition_applied', $actor, $record->state(), $target->name(), $before, $after, $transition->name(), array_replace(['reversible'=>$transition->isReversible()], $metadata), $previous);
		$events=array_merge($prefixEvents, [$event]);
		$next=$record->next(['state'=>$target->name(), 'data'=>$data, 'assigned_to'=>$assignedTo, 'assigned_roles'=>$assignedRoles, 'deadline_at'=>$deadline, 'pending_approval'=>null], $events);
		$result=WorkflowResult::success('transitioned', "Transition '{$transition->name()}' applied.", $next, $events, ['transition'=>$transition->name(), 'state'=>$target->name()]);
		return $this->persist($record, $next, $result, $idempotencyKey, $fingerprint);
	}

	private function persist(WorkflowRecord $current, WorkflowRecord $next, WorkflowResult $result, ?string $idempotencyKey, ?string $fingerprint=null): WorkflowResult {
		if($idempotencyKey!==null && trim($idempotencyKey)!==''){
			$next=$current->next($this->changesBetween($current, $next), array_slice($next->history(), count($current->history())), $idempotencyKey, $result->idempotencySnapshot($fingerprint));
			$result=WorkflowResult::success($result->code(), $result->message(), $next, array_slice($next->history(), count($current->history())), $result->metadata());
		}
		try{
			if(!$this->store->compareAndSwap($next, $current->version())){
				return WorkflowResult::failure('version_conflict', 'Workflow changed concurrently; reload and retry.', $this->store->load($current->definition(), $current->id()));
			}
		}catch(\Throwable $exception){ return $this->storageFailure($exception, $current); }
		return $result;
	}

	/** @return array<string,mixed> */
	private function changesBetween(WorkflowRecord $current, WorkflowRecord $next): array {
		return [
			'state'=>$next->state(), 'data'=>$next->data(), 'assigned_to'=>$next->assignedTo(),
			'assigned_roles'=>$next->assignedRoles(), 'deadline_at'=>$next->deadlineAt(),
			'pending_approval'=>$next->pendingApproval(), 'metadata'=>$next->metadata(),
		];
	}

	private function event(string $type, WorkflowActor $actor, string $stateBefore, string $stateAfter, array $before, array $after, ?string $transition=null, array $metadata=[], string $previousHash=''): WorkflowEvent {
		return WorkflowEvent::make($type, $actor->id(), $stateBefore, $stateAfter, $before, $after, self::diff($before, $after), $transition, $metadata, $previousHash, $this->now()->format('c'));
	}

	/** @return array<string,mixed> */
	private function snapshot(WorkflowRecord $record): array {
		return ['state'=>$record->state(), 'data'=>$record->data(), 'assigned_to'=>$record->assignedTo(), 'assigned_roles'=>$record->assignedRoles(), 'deadline_at'=>$record->deadlineAt()];
	}

	/** @return array<string,array{before:mixed,after:mixed}> */
	public static function diff(array $before, array $after, string $prefix=''): array {
		$result=[];
		$keys=array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
		foreach($keys as $key){
			$path=$prefix==='' ? (string)$key : $prefix.'.'.$key;
			$left=$before[$key] ?? null; $right=$after[$key] ?? null;
			if(is_array($left) && is_array($right) && !array_is_list($left) && !array_is_list($right)){
				$result+=self::diff($left, $right, $path);
			}elseif($left!==$right || !array_key_exists($key, $before) || !array_key_exists($key, $after)){
				$result[$path]=['before'=>WorkflowRecord::jsonSafe($left), 'after'=>WorkflowRecord::jsonSafe($right)];
			}
		}
		return $result;
	}

	private function deadline(?int $seconds): ?string {
		return $seconds===null ? null : $this->now()->modify('+'.max(1, $seconds).' seconds')->format('c');
	}

	private function now(): \DateTimeImmutable {
		$value=$this->clock instanceof \Closure ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if(!$value instanceof \DateTimeImmutable){ $value=new \DateTimeImmutable((string)$value); }
		return $value->setTimezone(new \DateTimeZone('UTC'));
	}

	private function storageFailure(\Throwable $exception, ?WorkflowRecord $record=null): WorkflowResult {
		return WorkflowResult::failure('storage_failed', 'Workflow persistence failed.', $record, [$exception->getMessage()]);
	}

	/** @param array<string,mixed> $input */
	private function fingerprint(string $operation, array $input): string {
		return hash('sha256', WorkflowEvent::canonicalJson(['operation'=>WorkflowState::normalize($operation), 'input'=>$input]));
	}
}
