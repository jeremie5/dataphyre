<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Policy-first dry-run and execution runtime with durable idempotent receipts.
 */
final class AutomationExecutor implements \JsonSerializable {
	private ?\Closure $clock=null;

	public function __construct(private readonly AutomationRegistry $registry, private readonly AutomationStore $store) {}
	public function registry():AutomationRegistry{return$this->registry;}
	public function store():AutomationStore{return$this->store;}

	public function clock(callable $clock): self { $this->clock=\Closure::fromCallable($clock); return $this; }

	public function plan(string $name, AutomationExecutionRequest $request): AutomationExecutionResult {
		$result=$this->prepare($name, $request);
		return $result->code()==='ready'
			? AutomationExecutionResult::make(true, 'planned', 'Dry-run plan generated.', $result->plan(), null, [], [], false, $result->metadata())
			: $result;
	}

	public function execute(string $name, AutomationExecutionRequest $request): AutomationExecutionResult {
		$action=$this->registry->get($name);
		if(!$action instanceof AutomationAction){
			return AutomationExecutionResult::make(false, 'action_not_found', "Automation action '{$name}' is not registered.");
		}
		if($request->dryRun()){
			return $this->plan($name, $request);
		}
		$key=$request->idempotencyKey();
		if($action->idempotencyRequired() && ($key===null || trim($key)==='')){
			return AutomationExecutionResult::make(false, 'idempotency_required', 'This action requires an idempotency key.');
		}
		if($key!==null && trim($key)!==''){
			try{ $existing=$this->store->findByIdempotency($action->name(), $key); }
			catch(\Throwable $exception){ return $this->storeFailure($exception); }
			if($existing instanceof AutomationReceipt){
				if(($existing->metadata()['request_hash'] ?? null)!==$this->requestHash($request->input())){
					return AutomationExecutionResult::make(false, 'idempotency_conflict', 'This idempotency key was already used with different input.', null, $existing);
				}
				return AutomationExecutionResult::make($existing->ok(), 'idempotent_replay', 'Returning the durable receipt for this idempotency key.', null, $existing, [], [], true);
			}
		}
		$prepared=$this->prepare($action->name(), $request);
		if($prepared->code()!=='ready'){
			return $prepared;
		}
		$plan=$prepared->plan();
		/** @var AutomationPlan $plan A ready preparation always carries its immutable plan. */
		if(($confirmation=$this->confirmationFailure($action, $request, $plan)) instanceof AutomationExecutionResult){
			return $confirmation;
		}
		if(!$action->handler() instanceof \Closure){
			return AutomationExecutionResult::make(false, 'not_executable', 'This action has no execution handler.', $plan);
		}
		$started=$this->now()->format('c');
		try{
			$result=($action->handler())($request->input(), $request->context(), $request->actor(), $plan, $action);
			$receipt=AutomationReceipt::create(
				$action, 'completed', $request->actor(), $key, $request->input(), $plan->hash(), $result,
				null, $action->rollbackInstructions(), null,
				['policy'=>$plan->policy(), 'risk'=>$action->riskLevel(), 'request_hash'=>$this->requestHash($request->input())], $started, $this->now()->format('c')
			);
			return $this->saveReceipt($receipt, $key, $plan, 'executed', 'Action executed successfully.');
		}catch(\Throwable $exception){
			$receipt=AutomationReceipt::create(
				$action, 'failed', $request->actor(), $key, $request->input(), $plan->hash(), null,
				$exception->getMessage(), $action->rollbackInstructions(), null,
				['exception'=>$exception::class, 'policy'=>$plan->policy(), 'request_hash'=>$this->requestHash($request->input())], $started, $this->now()->format('c')
			);
			$saved=$this->saveReceipt($receipt, $key, $plan, 'execution_failed', 'Action execution failed.');
			if(in_array($saved->code(), ['storage_failed','receipt_conflict','idempotency_conflict'], true)){
				return $saved;
			}
			return AutomationExecutionResult::make(false, 'execution_failed', 'Action execution failed.', $plan, $saved->receipt() ?? $receipt, [], [], $saved->replayed(), ['exception'=>$exception::class]);
		}
	}

	/** Reverses a completed receipt after a receipt-specific strong confirmation. */
	public function rollback(string $receiptId, AutomationExecutionRequest $request): AutomationExecutionResult {
		try{ $original=$this->store->get($receiptId); }
		catch(\Throwable $exception){ return $this->storeFailure($exception); }
		if(!$original instanceof AutomationReceipt){
			return AutomationExecutionResult::make(false, 'receipt_not_found', "Automation receipt '{$receiptId}' was not found.");
		}
		$action=$this->registry->get($original->action());
		if(!$action instanceof AutomationAction || !$action->rollbackHandler() instanceof \Closure){
			return AutomationExecutionResult::make(false, 'rollback_not_supported', 'The original action does not expose a rollback handler.', null, $original);
		}
		if(!$original->ok() || $original->status()!=='completed'){
			return AutomationExecutionResult::make(false, 'receipt_not_rollbackable', 'Only a successfully completed execution receipt can be rolled back.', null, $original);
		}
		$phrase='ROLLBACK '.$original->id();
		if(!$request->confirmed() || !hash_equals($phrase, (string)$request->confirmationPhrase())){
			return AutomationExecutionResult::make(false, 'rollback_confirmation_required', 'Rollback requires receipt-specific strong confirmation.', null, $original, [], [], false, ['confirmation_phrase'=>$phrase]);
		}
		$key=$request->idempotencyKey();
		$effectiveKey=$key===null || trim($key)==='' ? null : 'rollback:'.$original->id().':'.trim($key);
		if($effectiveKey!==null){
			try{ $existing=$this->store->findByIdempotency($action->name(), $effectiveKey); }
			catch(\Throwable $exception){ return $this->storeFailure($exception); }
			if($existing instanceof AutomationReceipt){
				if(($existing->metadata()['request_hash'] ?? null)!==$this->requestHash($request->input())){
					return AutomationExecutionResult::make(false, 'idempotency_conflict', 'This rollback idempotency key was already used with different input.', null, $existing);
				}
				return AutomationExecutionResult::make($existing->ok(), 'idempotent_replay', 'Returning the durable rollback receipt.', null, $existing, [], [], true);
			}
		}
		try{
			foreach($this->store->all($action->name()) as $existingRollback){
				if($existingRollback->parentReceiptId()===$original->id() && $existingRollback->status()==='rolled_back'){
					return AutomationExecutionResult::make(false, 'already_rolled_back', 'This execution receipt was already rolled back.', null, $existingRollback);
				}
			}
		}catch(\Throwable $exception){ return $this->storeFailure($exception); }
		$started=$this->now()->format('c');
		try{
			$result=($action->rollbackHandler())($original, $request->input(), $request->context(), $request->actor(), $action);
			$receipt=AutomationReceipt::create(
				$action, 'rolled_back', $request->actor(), $effectiveKey, $request->input(), $original->planHash(),
				$result, null, [], $original->id(), ['rollback_of'=>$original->id(), 'request_hash'=>$this->requestHash($request->input())], $started, $this->now()->format('c')
			);
			return $this->saveReceipt($receipt, $effectiveKey, null, 'rolled_back', 'Action rollback completed.');
		}catch(\Throwable $exception){
			$receipt=AutomationReceipt::create(
				$action, 'rollback_failed', $request->actor(), $effectiveKey, $request->input(), $original->planHash(),
				null, $exception->getMessage(), $original->rollbackInstructions(), $original->id(),
				['rollback_of'=>$original->id(), 'exception'=>$exception::class, 'request_hash'=>$this->requestHash($request->input())], $started, $this->now()->format('c')
			);
			$saved=$this->saveReceipt($receipt, $effectiveKey, null, 'rollback_failed', 'Action rollback failed.');
			if(in_array($saved->code(), ['storage_failed','receipt_conflict','idempotency_conflict'], true)){
				return $saved;
			}
			return AutomationExecutionResult::make(false, 'rollback_failed', 'Action rollback failed.', null, $saved->receipt() ?? $receipt, [], [], $saved->replayed(), ['exception'=>$exception::class]);
		}
	}

	public function jsonSerialize(): array {
		return ['type'=>'panel_automation_executor', 'registry'=>$this->registry, 'store'=>$this->store];
	}

	private function prepare(string $name, AutomationExecutionRequest $request): AutomationExecutionResult {
		$action=$this->registry->get($name);
		if(!$action instanceof AutomationAction){
			return AutomationExecutionResult::make(false, 'action_not_found', "Automation action '{$name}' is not registered.");
		}
		$issues=$this->validate($action, $request);
		if($issues!==[]){
			return AutomationExecutionResult::make(false, 'validation_failed', 'Action input failed validation.', null, null, $issues);
		}
		try{
			$rawPolicy=$action->policyResolver() instanceof \Closure
				? ($action->policyResolver())($request->input(), $request->actor(), $request->context(), $action)
				: true;
			$policy=AutomationPolicyDecision::from($rawPolicy);
		}catch(\Throwable $exception){
			return AutomationExecutionResult::make(false, 'policy_failed', 'Action policy could not be evaluated.', null, null, [], [], false, ['exception'=>$exception::class, 'message'=>$exception->getMessage()]);
		}
		try{
			$raw=$action->planner() instanceof \Closure
				? ($action->planner())($request->input(), $request->actor(), $request->context(), $policy, $action)
				: [];
		}catch(\Throwable $exception){
			return AutomationExecutionResult::make(false, 'planning_failed', 'Action dry-run planner failed.', null, null, [], [], false, ['exception'=>$exception::class, 'message'=>$exception->getMessage()]);
		}
		$raw=is_array($raw) ? $raw : ['summary'=>(string)$raw];
		$steps=$this->descriptors($raw['steps'] ?? [['name'=>'execute', 'description'=>'Execute '.$action->labelValue().'.']]);
		$effects=$this->descriptors($raw['effects'] ?? []);
		$warnings=array_values(array_filter(array_map(static fn(mixed $warning): string=>trim((string)$warning), is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [])));
		if(in_array($action->riskLevel(), ['high','critical'], true) && !$action->rollbackHandler() instanceof \Closure){
			$warnings[]='This high-risk action does not expose automatic rollback.';
		}
		$plan=new AutomationPlan(
			$action->name(), trim((string)($raw['summary'] ?? 'Execute '.$action->labelValue().'.')),
			$steps, $effects, array_values(array_unique($warnings)), $policy,
			$action->riskLevel(), $action->confirmationLevel(),
			WorkflowRecord::jsonSafe(is_array($raw['metadata'] ?? null) ? $raw['metadata'] : []), $this->now()->format('c')
		);
		if($policy->requiresApproval()){
			$handoff=array_replace($policy->handoff(), [
				'type'=>'panel_automation_human_approval', 'action'=>$action->name(),
				'actor_id'=>$request->actor()->id(), 'plan_hash'=>$plan->hash(),
				'handoff_id'=>hash('sha256', $action->name()."\0".$request->actor()->id()."\0".$plan->hash()),
				'idempotency_key_present'=>$request->idempotencyKey()!==null && trim((string)$request->idempotencyKey())!=='',
			]);
			return AutomationExecutionResult::make(true, 'approval_required', $policy->explanation(), $plan, null, [], $handoff);
		}
		if(!$policy->allowed()){
			return AutomationExecutionResult::make(false, 'policy_denied', $policy->explanation(), $plan);
		}
		return AutomationExecutionResult::make(true, 'ready', 'Action is valid and policy-authorized.', $plan, null, [], [], false, ['policy'=>$policy]);
	}

	/** @return list<AutomationValidationIssue> */
	private function validate(AutomationAction $action, AutomationExecutionRequest $request): array {
		$issues=[];
		$schema=$action->schema();
		$required=is_array($schema['required'] ?? null) ? $schema['required'] : [];
		foreach($required as $field){
			$field=(string)$field;
			if(!array_key_exists($field, $request->input())){
				$issues[]=new AutomationValidationIssue($field, 'required', "Field '{$field}' is required.");
			}
		}
		$properties=is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		foreach($properties as $field=>$fieldSchema){
			if(array_key_exists((string)$field, $request->input()) && is_array($fieldSchema)){
				$issues=array_merge($issues, $this->validateValue($request->input()[(string)$field], $fieldSchema, (string)$field));
			}
		}
		if($action->validator() instanceof \Closure){
			try{ $custom=($action->validator())($request->input(), $request->actor(), $request->context(), $action); }
			catch(\Throwable $exception){
				$issues[]=new AutomationValidationIssue('', 'validator_failed', 'Custom action validation failed to run.', 'error', ['exception'=>$exception::class, 'message'=>$exception->getMessage()]);
				return $issues;
			}
			if($custom===false){ $custom=['Custom validation refused the input.']; }
			if(is_string($custom) || $custom instanceof AutomationValidationIssue){ $custom=[$custom]; }
			foreach(is_array($custom) ? $custom : [] as $issue){
				if(is_string($issue) || is_array($issue) || $issue instanceof AutomationValidationIssue){ $issues[]=AutomationValidationIssue::from($issue); }
			}
		}
		return $issues;
	}

	/** @param array<string,mixed> $schema @return list<AutomationValidationIssue> */
	private function validateValue(mixed $value, array $schema, string $path): array {
		$issues=[];
		$type=(string)($schema['type'] ?? '');
		$valid=match($type){
			'' => true, 'string'=>is_string($value), 'integer'=>is_int($value),
			'number'=>is_int($value) || is_float($value), 'boolean'=>is_bool($value),
			'array'=>is_array($value) && array_is_list($value),
			'object'=>is_array($value) && (!array_is_list($value) || $value===[]), 'null'=>$value===null,
			default=>false,
		};
		if(!$valid){ return [new AutomationValidationIssue($path, 'type', "Field '{$path}' must be of type {$type}.", 'error', ['expected'=>$type, 'actual'=>get_debug_type($value)])]; }
		if(is_array($schema['enum'] ?? null) && !in_array($value, $schema['enum'], true)){ $issues[]=new AutomationValidationIssue($path, 'enum', "Field '{$path}' has an unsupported value."); }
		if(is_string($value)){
			$length=function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
			if(isset($schema['minLength']) && $length<(int)$schema['minLength']){ $issues[]=new AutomationValidationIssue($path, 'min_length', "Field '{$path}' is too short."); }
			if(isset($schema['maxLength']) && $length>(int)$schema['maxLength']){ $issues[]=new AutomationValidationIssue($path, 'max_length', "Field '{$path}' is too long."); }
			if(isset($schema['pattern']) && @preg_match((string)$schema['pattern'], $value)!==1){ $issues[]=new AutomationValidationIssue($path, 'pattern', "Field '{$path}' does not match the required pattern."); }
			$format=(string)($schema['format'] ?? '');
			if($format==='email' && filter_var($value, FILTER_VALIDATE_EMAIL)===false){ $issues[]=new AutomationValidationIssue($path, 'format', "Field '{$path}' must be an email address."); }
			if($format==='uuid' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)!==1){ $issues[]=new AutomationValidationIssue($path, 'format', "Field '{$path}' must be a UUID."); }
		}
		if(is_int($value) || is_float($value)){
			if(isset($schema['minimum']) && $value<$schema['minimum']){ $issues[]=new AutomationValidationIssue($path, 'minimum', "Field '{$path}' is below its minimum."); }
			if(isset($schema['maximum']) && $value>$schema['maximum']){ $issues[]=new AutomationValidationIssue($path, 'maximum', "Field '{$path}' exceeds its maximum."); }
		}
		if(is_array($value) && array_is_list($value)){
			if(isset($schema['minItems']) && count($value)<(int)$schema['minItems']){ $issues[]=new AutomationValidationIssue($path, 'min_items', "Field '{$path}' has too few items."); }
			if(isset($schema['maxItems']) && count($value)>(int)$schema['maxItems']){ $issues[]=new AutomationValidationIssue($path, 'max_items', "Field '{$path}' has too many items."); }
			if(is_array($schema['items'] ?? null)){
				foreach($value as $index=>$nested){ $issues=array_merge($issues, $this->validateValue($nested, $schema['items'], $path.'.'.$index)); }
			}
		}
		if(is_array($value) && (!array_is_list($value) || $value===[]) && is_array($schema['properties'] ?? null)){
			foreach(is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required){
				if(!array_key_exists((string)$required, $value)){ $issues[]=new AutomationValidationIssue($path.'.'.$required, 'required', "Field '{$path}.{$required}' is required."); }
			}
			foreach($schema['properties'] as $key=>$nestedSchema){
				if(array_key_exists((string)$key, $value) && is_array($nestedSchema)){ $issues=array_merge($issues, $this->validateValue($value[(string)$key], $nestedSchema, $path.'.'.$key)); }
			}
		}
		return $issues;
	}

	private function confirmationFailure(AutomationAction $action, AutomationExecutionRequest $request, AutomationPlan $plan): ?AutomationExecutionResult {
		$level=$action->confirmationLevel();
		if($level==='none'){ return null; }
		if(!$request->confirmed()){
			return AutomationExecutionResult::make(false, 'confirmation_required', 'Action confirmation is required.', $plan, null, [], [], false, ['level'=>$level, 'phrase'=>$action->confirmationPhrase()]);
		}
		if(in_array($level, ['phrase','critical'], true)){
			$expected=$action->confirmationPhrase() ?? strtoupper($action->name());
			if(!hash_equals($expected, (string)$request->confirmationPhrase())){
				return AutomationExecutionResult::make(false, 'confirmation_phrase_mismatch', 'The confirmation phrase does not match.', $plan, null, [], [], false, ['level'=>$level, 'phrase'=>$expected]);
			}
		}
		return null;
	}

	/** @return list<array<string,mixed>> */
	private function descriptors(mixed $values): array {
		$result=[];
		foreach(is_array($values) ? $values : [] as $index=>$value){
			if(is_string($value)){ $value=['name'=>'step_'.($index+1), 'description'=>$value]; }
			if(is_array($value)){ $result[]=WorkflowRecord::jsonSafe($value); }
		}
		return $result;
	}

	private function saveReceipt(AutomationReceipt $receipt, ?string $key, ?AutomationPlan $plan, string $code, string $message): AutomationExecutionResult {
		try{
			if(!$this->store->save($receipt)){
				$existing=$key===null ? null : $this->store->findByIdempotency($receipt->action(), $key);
				if($existing instanceof AutomationReceipt && ($existing->metadata()['request_hash'] ?? null)!==($receipt->metadata()['request_hash'] ?? null)){
					return AutomationExecutionResult::make(false, 'idempotency_conflict', 'A concurrent request used this idempotency key with different input.', $plan, $existing);
				}
				return $existing instanceof AutomationReceipt
					? AutomationExecutionResult::make($existing->ok(), 'idempotent_replay', 'A concurrent request already produced this receipt.', $plan, $existing, [], [], true)
					: AutomationExecutionResult::make(false, 'receipt_conflict', 'Automation receipt could not be persisted.', $plan);
			}
		}catch(\Throwable $exception){ return $this->storeFailure($exception, $plan); }
		return AutomationExecutionResult::make($receipt->ok(), $code, $message, $plan, $receipt);
	}

	private function storeFailure(\Throwable $exception, ?AutomationPlan $plan=null): AutomationExecutionResult {
		return AutomationExecutionResult::make(false, 'storage_failed', 'Automation receipt persistence failed.', $plan, null, [], [], false, ['exception'=>$exception::class, 'message'=>$exception->getMessage()]);
	}

	private function now(): \DateTimeImmutable {
		$value=$this->clock instanceof \Closure ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if(!$value instanceof \DateTimeImmutable){ $value=new \DateTimeImmutable((string)$value); }
		return $value->setTimezone(new \DateTimeZone('UTC'));
	}

	/** @param array<string,mixed> $input */
	private function requestHash(array $input): string {
		return hash('sha256', WorkflowEvent::canonicalJson($input));
	}
}
