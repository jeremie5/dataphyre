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
 * Runtime-only signed material resolved by a trusted deferred worker.
 *
 * JSON serialization is deliberately a secret-free commitment manifest. The
 * bearer intents, idempotency key, and confirmation evidence are available
 * only through typed runtime accessors.
 */
final class PanelAgentDeferredExecution implements \JsonSerializable {
	private const MAX_INTENT_BYTES=32768;
	private const MAX_CONFIRMATION_BYTES=32768;
	/** @var list<string> */
	private readonly array $approvalIntents;
	private readonly string $planIntent;
	private readonly string $idempotencyKey;
	private readonly ?string $confirmationEvidence;
	private readonly string $fingerprint;

	/** @param list<string> $approvalIntents */
	public function __construct(
		private readonly PanelAgentPlan $plan,
		string $planIntent,
		private readonly PanelAgentRequestContext $context,
		array $approvalIntents,
		string $idempotencyKey,
		private readonly int $expectedStoreRevision,
		?string $confirmationEvidence,
		private readonly int $expiresAt,
	){
		if(!hash_equals($plan->scopeFingerprint(),$context->scopeFingerprint()) || !hash_equals($plan->subjectFingerprint(),$context->subjectFingerprint())){
			throw new \InvalidArgumentException('Deferred Panel agent execution scope does not match its plan.');
		}
		$this->planIntent=PanelAgentGuard::boundedString($planIntent,'deferred plan intent',self::MAX_INTENT_BYTES);
		if(!array_is_list($approvalIntents) || count($approvalIntents)!==$plan->approvalCount()){
			throw new \InvalidArgumentException('Deferred Panel agent approvals do not match the plan requirement.');
		}
		$normalized=[];
		foreach($approvalIntents as $intent){
			$normalized[]=PanelAgentGuard::boundedString($intent,'deferred approval intent',self::MAX_INTENT_BYTES);
		}
		$this->approvalIntents=$normalized;
		$this->idempotencyKey=PanelAgentGuard::boundedString($idempotencyKey,'deferred idempotency key',256);
		if($expectedStoreRevision<0){ throw new \InvalidArgumentException('Deferred Panel agent store revision cannot be negative.'); }
		if($confirmationEvidence!==null){
			$confirmationEvidence=PanelAgentGuard::boundedString($confirmationEvidence,'deferred confirmation evidence',self::MAX_CONFIRMATION_BYTES);
		}
		if($plan->confirmationRequired()!==($confirmationEvidence!==null)){
			throw new \InvalidArgumentException('Deferred Panel agent confirmation evidence does not match the plan requirement.');
		}
		$this->confirmationEvidence=$confirmationEvidence;
		if($expiresAt<1){ throw new \InvalidArgumentException('Deferred Panel agent execution expiry must be positive.'); }
		$this->fingerprint=hash('sha256',PanelAgentGuard::canonicalJson([
			'contract'=>'panel_agent_deferred_execution_v1',
			'plan_hash'=>$plan->hash(),
			'scope_fingerprint'=>$context->scopeFingerprint(),
			'subject_fingerprint'=>$context->subjectFingerprint(),
			'plan_intent_hash'=>self::secretDigest($this->planIntent,'plan-intent'),
			'approval_intent_hashes'=>array_map(static fn(string $intent):string=>self::secretDigest($intent,'approval-intent'),$this->approvalIntents),
			'idempotency_hash'=>self::secretDigest($this->idempotencyKey,'idempotency'),
			'confirmation_hash'=>$this->confirmationEvidence===null ? null : self::secretDigest($this->confirmationEvidence,'confirmation'),
			'expires_at'=>$this->expiresAt,
		]));
	}

	public function plan():PanelAgentPlan{return$this->plan;}
	public function planIntent():string{return$this->planIntent;}
	public function context():PanelAgentRequestContext{return$this->context;}
	/** @return list<string> */ public function approvalIntents():array{return$this->approvalIntents;}
	public function idempotencyKey():string{return$this->idempotencyKey;}
	public function expectedStoreRevision():int{return$this->expectedStoreRevision;}
	public function confirmationEvidence():?string{return$this->confirmationEvidence;}
	public function expiresAt():int{return$this->expiresAt;}
	public function fingerprint():string{return$this->fingerprint;}
	public function expired(int $now):bool{if($now<0){throw new \InvalidArgumentException('Deferred Panel agent clock cannot be negative.');}return$now>=$this->expiresAt;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return[
			'type'=>'panel_agent_deferred_execution','version'=>1,'fingerprint'=>$this->fingerprint,
			'plan_hash'=>$this->plan->hash(),'scope_fingerprint'=>$this->context->scopeFingerprint(),
			'subject_fingerprint'=>$this->context->subjectFingerprint(),'approval_count'=>count($this->approvalIntents),
			'confirmation_required'=>$this->plan->confirmationRequired(),'expires_at'=>$this->expiresAt,
			'expected_store_revision'=>$this->expectedStoreRevision,'revision_committed_by_fingerprint'=>false,
			'sensitive_material_serialized'=>false,
		];
	}

	private static function secretDigest(string $value,string $purpose):string{
		return hash('sha256',"panel-agent-deferred-v1\0{$purpose}\0{$value}");
	}
}
