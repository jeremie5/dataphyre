<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Safe orchestration boundary for host/model-proposed plans.
 *
 * It never calls a model, installs a route, infers identity, or executes text.
 */
final class PanelAgentRuntime implements \JsonSerializable {
	private const MAX_EXECUTION_RESULT_BYTES=524288;
	private const RESULT_HEADROOM_BYTES=8192;
	private const STEP_TIMEOUT_SECONDS=30;
	private const COMPLETION_GRACE_SECONDS=30;
	private ?\Closure $clock;
	private readonly ?string $confirmationVerifierFingerprint;

	public function __construct(
		private readonly PanelAgentToolCatalog $catalog,
		private readonly PanelAgentPolicyEngine $policy,
		private readonly PanelAgentIntentSigner $signer,
		private readonly PanelAgentWorkflowStore $store,
		?callable $clock=null,
		private readonly ?PanelAgentConfirmationVerifier $confirmationVerifier=null
	){
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
		$this->confirmationVerifierFingerprint=$confirmationVerifier===null ? null : PanelAgentGuard::digest($confirmationVerifier->fingerprint(),'confirmation verifier fingerprint');
	}

	public function catalog(): PanelAgentToolCatalog { return $this->catalog; }
	public function policy(): PanelAgentPolicyEngine { return $this->policy; }
	public function signer(): PanelAgentIntentSigner { return $this->signer; }
	public function store(): PanelAgentWorkflowStore { return $this->store; }

	/**
	 * Validates an untrusted bounded proposal and returns an immutable signed plan.
	 * @param array<string,mixed> $proposal
	 */
	public function prepare(array $proposal, PanelAgentRequestContext $context, int $expectedCatalogRevision, int $expectedStoreRevision, int $ttl=300): PanelAgentPlanEnvelope {
		PanelAgentGuard::assertJson($proposal, 262144);
		if($expectedCatalogRevision!==$this->catalog->revision()){ throw new PanelAgentException('catalog_revision_conflict', 'Panel agent tool catalog revision is stale.', 409); }
		$keys=array_keys($proposal); sort($keys, SORT_STRING);
		if($keys!==['steps','title']){ throw new PanelAgentException('plan_invalid', 'Panel agent proposals require exactly title and steps.'); }
		$title=PanelAgentGuard::boundedString($proposal['title'] ?? null, 'plan title', 512);
		$rawSteps=$proposal['steps'] ?? null;
		if(!is_array($rawSteps) || !array_is_list($rawSteps) || $rawSteps===[] || count($rawSteps)>32){ throw new PanelAgentException('plan_invalid', 'Panel agent plans require between one and 32 steps.'); }
		$steps=[]; $requirements=[];
		foreach($rawSteps as $index=>$rawStep){
			if(!is_array($rawStep) || array_is_list($rawStep)){ throw new PanelAgentException('plan_invalid', 'Panel agent plan steps must be objects.'); }
			$stepKeys=array_keys($rawStep); sort($stepKeys, SORT_STRING);
			if(!in_array($stepKeys, [['arguments','tool'],['arguments','dry_run','tool']], true)){ throw new PanelAgentException('plan_invalid', 'Panel agent plan steps contain unsupported fields.'); }
			$name=PanelAgentGuard::identifier(is_string($rawStep['tool'] ?? null) ? $rawStep['tool'] : '', 'tool', 128);
			$tool=$this->catalog->tool($name);
			if(!$tool instanceof PanelAgentTool){ throw new PanelAgentException('tool_unavailable', "Panel agent tool '{$name}' is unavailable.", 404); }
			$arguments=$rawStep['arguments'] ?? null;
			if(!is_array($arguments) || ($arguments!==[] && array_is_list($arguments))){ throw new PanelAgentException('arguments_invalid', 'Panel agent tool arguments must be objects.'); }
			$arguments=$tool->normalize($arguments); $dryRun=($rawStep['dry_run'] ?? false)===true;
			if(isset($rawStep['dry_run']) && !is_bool($rawStep['dry_run'])){ throw new PanelAgentException('plan_invalid', 'Panel agent dry_run flags must be boolean.'); }
			if($dryRun && !$tool->dryRunSupported()){ throw new PanelAgentException('dry_run_unsupported', "Panel agent tool '{$name}' does not support dry-run."); }
			$decision=$this->policy->evaluate($context, $tool, $arguments);
			if(!$decision->allowed()){ throw new PanelAgentException('policy_denied', $decision->reason(), 403); }
			$steps[]=new PanelAgentPlanStep($index+1,$tool->name(),$tool->version(),$tool->fingerprint(),$arguments,$dryRun,$decision->approvalCount(),$decision->confirmationRequired(),$decision->separationOfDuties());
			$requirements[]=['tool'=>$tool->name(),'permission'=>$tool->permission(),'approval_count'=>$decision->approvalCount(),'confirmation'=>$decision->confirmationRequired(),'separation_of_duties'=>$decision->separationOfDuties()];
		}
		if(array_filter($steps,static fn(PanelAgentPlanStep $step): bool=>$step->confirmationRequired())!==[] && $this->confirmationVerifier===null){ throw new PanelAgentException('confirmation_unavailable','Panel agent confirmation verification is unavailable.',503); }
		$plan=new PanelAgentPlan($title,$context->scopeFingerprint(),$context->subjectFingerprint(),$this->catalog->fingerprint(),$this->catalog->revision(),$this->policy->fingerprint(),$steps,$this->now(),$this->confirmationVerifierFingerprint);
		$intent=$this->signer->issuePlan($plan, $context, $ttl);
		$receipt=$this->receipt('plan_validated',$context,$plan,'planned',['steps'=>count($steps),'requirements'=>$requirements]);
		$revision=$this->store->append($receipt, $expectedStoreRevision);
		return new PanelAgentPlanEnvelope($plan, $intent, $revision);
	}

	public function approve(PanelAgentPlan $plan, string $planIntent, PanelAgentRequestContext $executionContext, PanelAgentRequestContext $approver, int $expectedStoreRevision, int $ttl=300): PanelAgentApprovalEnvelope {
		$verified=$this->assertCurrentPlan($plan, $planIntent, $executionContext);
		if($plan->approvalCount()===0){ throw new PanelAgentException('approval_not_required', 'This Panel agent plan does not require approval.'); }
		if($approver->panel()!==$executionContext->panel() || !hash_equals($approver->tenant(), $executionContext->tenant())){ throw new PanelAgentException('approval_scope_mismatch', 'Panel agent approver is outside the plan tenant.', 403); }
		if($plan->separationOfDuties() && hash_equals($approver->subjectFingerprint(), $plan->subjectFingerprint())){ throw new PanelAgentException('self_approval_denied', 'Panel agent separation of duties forbids self-approval.', 403); }
		$approvalDecision=$this->policy->authorizeApproval($approver,$plan);
		if(!$approvalDecision->allowed()){ throw new PanelAgentException('approval_denied',$approvalDecision->reason(),403); }
		$approval=$this->signer->issueApproval($plan, $verified, $approver, $ttl);
		$receipt=$this->receipt('plan_approved',$approver,$plan,'approved',['parent_nonce_tag'=>hash('sha256',$verified->nonce()),'approval_number_unassigned'=>true]);
		$revision=$this->store->append($receipt, $expectedStoreRevision);
		return new PanelAgentApprovalEnvelope($approval, $revision);
	}

	/** @param list<string> $approvalIntents */
	public function execute(PanelAgentPlan $plan, string $planIntent, PanelAgentRequestContext $context, array $approvalIntents, string $idempotencyKey, int $expectedStoreRevision, ?string $confirmationEvidence=null): PanelAgentExecutionResult {
		$verified=$this->assertCurrentPlan($plan, $planIntent, $context);
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey, 'idempotency key', 256);
		$this->revalidateSteps($plan, $context);
		if(count($approvalIntents)!==$plan->approvalCount()){ throw new PanelAgentException('approval_count_mismatch', 'Panel agent plan approval count does not match its policy.', 403); }
		$nonces=[$verified->nonce()]; $approvers=[];
		foreach($approvalIntents as $token){
			if(!is_string($token)){ throw new PanelAgentException('approval_invalid', 'Panel agent approval intent is invalid.', 401); }
			$approval=$this->signer->verifyApproval($token, $plan, $context, $verified->nonce());
			if(isset($approvers[$approval->subjectFingerprint()])){ throw new PanelAgentException('duplicate_approval', 'Panel agent approvals must come from distinct principals.', 403); }
			if($plan->separationOfDuties() && hash_equals($approval->subjectFingerprint(), $plan->subjectFingerprint())){ throw new PanelAgentException('self_approval_denied', 'Panel agent separation of duties forbids self-approval.', 403); }
			$approvers[$approval->subjectFingerprint()]=true; $nonces[]=$approval->nonce();
		}
		$confirmed=$this->verifyConfirmation($plan,$context,$confirmationEvidence);
		$requestHash=$this->requestHash($plan,$context); $executionTimestamp=$this->now();
		$reservation=$this->store->reserve($plan->hash(),$context->scopeFingerprint(),$idempotencyKey,$requestHash,$nonces,$expectedStoreRevision);
		if(!$reservation->acquiredNew()){
			$result=$reservation->result();
			if(!$result instanceof PanelAgentExecutionResult || !hash_equals($plan->hash(),$result->planHash())){ throw new PanelAgentException('store_invalid', 'Panel agent store returned an invalid replay.', 500); }
			return $result->asReplay($reservation->revision());
		}
		$steps=[]; $ok=true; $code='executed';
		foreach($plan->steps() as $step){
			if($this->policy->killed()){ $steps[]=$this->failedStep($step,'kill_switch','Panel agent kill switch interrupted execution.',false); $ok=false; $code='execution_cancelled'; break; }
			if($this->store->cancelled($plan->hash())){ $steps[]=$this->failedStep($step,'plan_cancelled','Panel agent plan was cancelled.',false); $ok=false; $code='execution_cancelled'; break; }
			try{ [$tool,$executor]=$this->revalidateStep($plan,$step,$context); }
			catch(\Throwable $exception){ $expected=$exception instanceof PanelAgentException; $steps[]=$this->failedStep($step,$expected ? $exception->errorCode() : 'revalidation_failed',$expected ? PanelAgentGuard::safeError($exception->getMessage(),2048) : 'Panel agent step revalidation failed closed.',false); $ok=false; $code='execution_failed'; break; }
			$stepStartedAt=$this->now();
			$reservation=$this->store->renew((string)$reservation->id(),$reservation->revision(),self::STEP_TIMEOUT_SECONDS+self::COMPLETION_GRACE_SECONDS);
			$request=new PanelAgentToolExecutionRequest(
				$context,$tool->name(),$step->arguments(),$this->stepIdempotency($idempotencyKey,$context,$plan,$step),
				$step->dryRun(),$confirmed,$plan->hash(),$step->ordinal(),
				fn(): bool=>$this->policy->killed() || $this->store->cancelled($plan->hash()),
				$stepStartedAt>PHP_INT_MAX-self::STEP_TIMEOUT_SECONDS ? PHP_INT_MAX : $stepStartedAt+self::STEP_TIMEOUT_SECONDS,
				fn(): int=>$this->now()
			);
			try{ $raw=$executor->execute($request); }
			catch(\Throwable){ $raw=PanelAgentToolExecutionResult::failure('Panel agent tool executor raised an exception.'); }
			try{ $cancelled=$request->cancellationRequested($this->now()); }catch(\Throwable){ $cancelled=true; }
			if($cancelled){ $steps[]=$this->failedStep($step,'execution_cancelled','Panel agent execution was cancelled or exceeded its deadline.',false); $ok=false; $code='execution_cancelled'; break; }
			if($raw->ok()){
				try{ $output=PanelAgentGuard::boundedOutput($raw->output(), $tool->outputByteLimit()); }
				catch(\LengthException){ $steps[]=$this->failedStep($step,'output_too_large','Panel agent tool output exceeded its byte limit.',false); $ok=false; $code='execution_failed'; break; }
				catch(\Throwable){ $steps[]=$this->failedStep($step,'output_invalid','Panel agent tool returned non-JSON output.',false); $ok=false; $code='execution_failed'; break; }
				$completed=['ordinal'=>$step->ordinal(),'tool'=>$step->tool(),'ok'=>true,'code'=>$step->dryRun() ? 'dry_run_completed' : 'completed','output'=>$output,'error'=>null,'retryable'=>false];
				if(!$this->fitsResult([...$steps,$completed],$plan)){
					$outputJson=PanelAgentGuard::canonicalJson($output); $steps[]=$this->failedStep($step,'aggregate_result_too_large','Panel agent aggregate result exceeded its byte limit.',false)+['output_bytes'=>strlen($outputJson),'output_hash'=>hash('sha256',$outputJson)];
					$ok=false; $code='execution_failed'; break;
				}
				$steps[]=$completed;
			}else{
				$steps[]=$this->failedStep($step,'tool_failed',PanelAgentGuard::safeError((string)$raw->error(),$tool->errorByteLimit()),$raw->retryable()); $ok=false; $code='execution_failed'; break;
			}
		}
		$result=PanelAgentExecutionResult::make($ok,$code,$plan->hash(),$steps,$reservation->revision(),null,['approval_count'=>count($approvers),'aggregate_limit_bytes'=>self::MAX_EXECUTION_RESULT_BYTES]);
		return $this->store->complete(
			(string)$reservation->id(),$result,$context,$ok ? 'execution_completed' : 'execution_failed',$code,
			['step_summaries'=>$this->auditStepSummaries($steps),'idempotency_hash'=>hash('sha256',$idempotencyKey)],$executionTimestamp,$reservation->revision()
		);
	}

	/** Authenticated scope-bound recovery that does not require an unexpired bearer intent. */
	public function result(PanelAgentPlan $plan, PanelAgentRequestContext $context, string $idempotencyKey): ?PanelAgentExecutionResult {
		if(!hash_equals($plan->scopeFingerprint(),$context->scopeFingerprint()) || !hash_equals($plan->subjectFingerprint(),$context->subjectFingerprint())){ throw new PanelAgentException('scope_mismatch','Panel agent result scope does not match the request context.',403); }
		$idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'idempotency key',256);
		$result=$this->store->lookup($plan->hash(),$context->scopeFingerprint(),$idempotencyKey,$this->requestHash($plan,$context));
		if($result instanceof PanelAgentExecutionResult && !hash_equals($plan->hash(),$result->planHash())){ throw new PanelAgentException('store_invalid','Panel agent store returned a result for another plan.',500); }
		return $result?->asReplay($this->store->revision());
	}

	public function cancel(PanelAgentPlan $plan, string $planIntent, PanelAgentRequestContext $context, string $reason, int $expectedStoreRevision): PanelAgentExecutionResult {
		$this->signer->verifyPlan($planIntent,$plan,$context); $reason=PanelAgentGuard::boundedString($reason, 'cancellation reason', 2048);
		if($this->store->cancelled($plan->hash())){ return PanelAgentExecutionResult::make(true,'already_cancelled',$plan->hash(),[],$this->store->revision(),null); }
		$receipt=$this->receipt('plan_cancelled',$context,$plan,'cancelled',['reason'=>$reason]);
		$revision=$this->store->cancel($plan->hash(),$receipt,$expectedStoreRevision);
		return PanelAgentExecutionResult::make(true,'cancelled',$plan->hash(),[],$revision,$receipt);
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_runtime','version'=>1,'catalog'=>$this->catalog,'policy'=>$this->policy,
			'signer'=>$this->signer,'store_revision'=>$this->store->revision(),
			'confirmation_verifier_installed'=>$this->confirmationVerifier!==null,'confirmation_verifier_fingerprint'=>$this->confirmationVerifierFingerprint,
			'model_client'=>false,'routes_installed'=>false,'identity_inferred'=>false,'arbitrary_output_execution'=>false,
			'host_obligations'=>['identity','permission_resolver','confirmation_evidence','keyring','route_auth','csrf','origin','rate_limit','durable_store','model'],
		];
	}

	private function assertCurrentPlan(PanelAgentPlan $plan, string $token, PanelAgentRequestContext $context): PanelAgentIntentVerification {
		if($plan->catalogRevision()!==$this->catalog->revision() || !hash_equals($plan->catalogFingerprint(),$this->catalog->fingerprint())){ throw new PanelAgentException('catalog_stale', 'Panel agent plan catalog is stale.', 409); }
		if(!hash_equals($plan->policyFingerprint(),$this->policy->fingerprint())){ throw new PanelAgentException('policy_stale', 'Panel agent plan policy is stale.', 409); }
		if(!hash_equals($plan->confirmationVerifierFingerprint() ?? '',$this->confirmationVerifierFingerprint ?? '')){ throw new PanelAgentException('confirmation_stale','Panel agent confirmation verifier is stale.',409); }
		return $this->signer->verifyPlan($token,$plan,$context);
	}

	private function revalidateSteps(PanelAgentPlan $plan, PanelAgentRequestContext $context): void {
		foreach($plan->steps() as $step){ $this->revalidateStep($plan,$step,$context); }
	}

	/** @return array{PanelAgentTool,PanelAgentToolExecutor} */
	private function revalidateStep(PanelAgentPlan $plan, PanelAgentPlanStep $step, PanelAgentRequestContext $context): array {
		if($plan->catalogRevision()!==$this->catalog->revision() || !hash_equals($plan->catalogFingerprint(),$this->catalog->fingerprint())){ throw new PanelAgentException('catalog_stale','Panel agent plan catalog changed during execution.',409); }
		if(!hash_equals($plan->policyFingerprint(),$this->policy->fingerprint())){ throw new PanelAgentException('policy_stale','Panel agent plan policy changed during execution.',409); }
		if(!hash_equals($plan->confirmationVerifierFingerprint() ?? '',$this->confirmationVerifierFingerprint ?? '')){ throw new PanelAgentException('confirmation_stale','Panel agent confirmation verifier changed during execution.',409); }
		$tool=$this->catalog->tool($step->tool()); $executor=$this->catalog->executor($step->tool());
		if(!$tool instanceof PanelAgentTool || !$executor instanceof PanelAgentToolExecutor || !hash_equals($tool->version(),$step->toolVersion()) || !hash_equals($tool->fingerprint(),$step->toolFingerprint())){ throw new PanelAgentException('tool_stale', "Panel agent tool '{$step->tool()}' changed after planning.", 409); }
		$normalized=$tool->normalize($step->arguments());
		if(!hash_equals(PanelAgentGuard::canonicalJson($normalized),PanelAgentGuard::canonicalJson($step->arguments()))){ throw new PanelAgentException('arguments_stale', 'Panel agent normalized arguments changed after planning.', 409); }
		$decision=$this->policy->evaluate($context,$tool,$normalized);
		if(!$decision->allowed()){ throw new PanelAgentException('policy_denied',$decision->reason(),403); }
		if($decision->approvalCount()>$step->approvalCount() || ($decision->confirmationRequired() && !$step->confirmationRequired()) || ($decision->separationOfDuties() && !$step->separationOfDuties())){ throw new PanelAgentException('authorization_stale','Panel agent authorization requirements became stricter.',409); }
		return [$tool,$executor];
	}

	/** @return array<string,mixed> */
	private function failedStep(PanelAgentPlanStep $step, string $code, string $error, bool $retryable): array { return ['ordinal'=>$step->ordinal(),'tool'=>$step->tool(),'ok'=>false,'code'=>$code,'output'=>null,'error'=>$error,'retryable'=>$retryable]; }
	/** @param list<array<string,mixed>> $steps @return list<array<string,mixed>> */
	private function auditStepSummaries(array $steps): array {
		$summaries=[];
		foreach($steps as $step){
			$output=$step['output'] ?? null; $error=$step['error'] ?? null;
			$outputJson=$output===null ? '' : PanelAgentGuard::canonicalJson($output);
			$summaries[]=[
				'ordinal'=>(int)($step['ordinal'] ?? 0),'tool'=>(string)($step['tool'] ?? 'unknown'),'ok'=>($step['ok'] ?? false)===true,
				'code'=>(string)($step['code'] ?? 'unknown'),'retryable'=>($step['retryable'] ?? false)===true,
				'output_bytes'=>strlen($outputJson),'output_hash'=>$outputJson==='' ? null : hash('sha256',$outputJson),
				'error_bytes'=>is_string($error) ? strlen($error) : 0,'error_hash'=>is_string($error) && $error!=='' ? hash('sha256',$error) : null,
			];
		}
		return $summaries;
	}
	/** @param list<array<string,mixed>> $steps */
	private function fitsResult(array $steps, PanelAgentPlan $plan): bool {
		try{ PanelAgentGuard::assertJson(['type'=>'panel_agent_execution_result','version'=>1,'plan_hash'=>$plan->hash(),'steps'=>$steps,'metadata'=>['aggregate_limit_bytes'=>self::MAX_EXECUTION_RESULT_BYTES]],self::MAX_EXECUTION_RESULT_BYTES-self::RESULT_HEADROOM_BYTES); return true; }
		catch(\LengthException){ return false; }
	}
	private function receipt(string $event, PanelAgentRequestContext $actor, PanelAgentPlan $plan, string $code, array $details, ?int $occurredAt=null): PanelAgentAuditReceipt { return PanelAgentAuditReceipt::create(count($this->store->audit())+1,$event,$actor,$plan->hash(),$code,$details,$this->store->lastAuditHash(),$occurredAt ?? $this->now()); }
	private function stepIdempotency(string $key, PanelAgentRequestContext $context, PanelAgentPlan $plan, PanelAgentPlanStep $step): string { return 'agent-step-'.$step->ordinal().'-'.hash('sha256',PanelAgentGuard::canonicalJson(['scope'=>$context->scopeFingerprint(),'plan'=>$plan->hash(),'tool'=>$step->tool(),'ordinal'=>$step->ordinal(),'key'=>$key])); }
	private function requestHash(PanelAgentPlan $plan, PanelAgentRequestContext $context): string { return hash('sha256',PanelAgentGuard::canonicalJson(['plan'=>$plan->hash(),'scope'=>$context->scopeFingerprint()])); }
	private function verifyConfirmation(PanelAgentPlan $plan, PanelAgentRequestContext $context, ?string $evidence): bool {
		if(!$plan->confirmationRequired()){ return false; }
		if($this->confirmationVerifier===null){ throw new PanelAgentException('confirmation_unavailable','Panel agent confirmation verification is unavailable.',503); }
		if($evidence===null){ throw new PanelAgentException('confirmation_required','Panel agent execution requires authenticated confirmation evidence.',409); }
		try{ $evidence=PanelAgentGuard::boundedString($evidence,'confirmation evidence',8192); }
		catch(\Throwable){ throw new PanelAgentException('confirmation_invalid','Panel agent confirmation evidence is invalid.',403); }
		try{ $verified=$this->confirmationVerifier->verify($context,$plan,$evidence); }
		catch(\Throwable){ throw new PanelAgentException('confirmation_verification_failed','Panel agent confirmation verification failed closed.',503); }
		if(!$verified){ throw new PanelAgentException('confirmation_invalid','Panel agent confirmation evidence is invalid.',403); }
		return true;
	}
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel agent runtime clock must return a non-negative integer timestamp.'); } return $value; }
}
