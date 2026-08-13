<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Explainable allow, deny, or human-approval policy decision.
 */
final class AutomationPolicyDecision implements \JsonSerializable {
	/** @param array<string,mixed> $handoff @param array<string,mixed> $metadata */
	private function __construct(
		private readonly string $outcome,
		private readonly string $explanation,
		private readonly array $handoff=[],
		private readonly array $metadata=[]
	){}

	/** @param array<string,mixed> $metadata */
	public static function allow(string $explanation='Policy allowed execution.', array $metadata=[]): self {
		return new self('allow', trim($explanation), [], WorkflowRecord::jsonSafe($metadata));
	}

	/** @param array<string,mixed> $metadata */
	public static function deny(string $explanation, array $metadata=[]): self {
		return new self('deny', trim($explanation) ?: 'Policy denied execution.', [], WorkflowRecord::jsonSafe($metadata));
	}

	/** @param array<string,mixed> $handoff @param array<string,mixed> $metadata */
	public static function approval(string $explanation, array $handoff, array $metadata=[]): self {
		return new self('approval_required', trim($explanation) ?: 'Human approval is required.', WorkflowRecord::jsonSafe($handoff), WorkflowRecord::jsonSafe($metadata));
	}

	/** @param self|array<string,mixed>|bool|string|null $decision */
	public static function from(self|array|bool|string|null $decision): self {
		if($decision instanceof self){ return $decision; }
		if($decision===true || $decision===null){ return self::allow(); }
		if($decision===false){ return self::deny('Policy denied execution.'); }
		if(is_string($decision)){ return self::deny($decision); }
		$outcome=WorkflowState::normalize((string)($decision['outcome'] ?? ($decision['allowed'] ?? false ? 'allow' : 'deny')));
		return match($outcome){
			'allow', 'allowed' => self::allow((string)($decision['explanation'] ?? 'Policy allowed execution.'), is_array($decision['metadata'] ?? null) ? $decision['metadata'] : []),
			'approval', 'approval_required', 'human_approval' => self::approval((string)($decision['explanation'] ?? ''), is_array($decision['handoff'] ?? null) ? $decision['handoff'] : [], is_array($decision['metadata'] ?? null) ? $decision['metadata'] : []),
			default => self::deny((string)($decision['explanation'] ?? 'Policy denied execution.'), is_array($decision['metadata'] ?? null) ? $decision['metadata'] : []),
		};
	}

	public function outcome(): string { return $this->outcome; }
	public function allowed(): bool { return $this->outcome==='allow'; }
	public function requiresApproval(): bool { return $this->outcome==='approval_required'; }
	public function explanation(): string { return $this->explanation; }
	/** @return array<string,mixed> */
	public function handoff(): array { return $this->handoff; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return ['type'=>'automation_policy_decision', 'outcome'=>$this->outcome, 'allowed'=>$this->allowed(), 'explanation'=>$this->explanation, 'handoff'=>$this->handoff, 'metadata'=>$this->metadata];
	}
}
