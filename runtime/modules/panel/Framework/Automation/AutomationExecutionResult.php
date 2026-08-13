<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Structured planning, handoff, execution, failure, or replay response.
 */
final class AutomationExecutionResult implements \JsonSerializable {
	/** @param list<AutomationValidationIssue> $issues @param array<string,mixed> $handoff @param array<string,mixed> $metadata */
	private function __construct(
		private readonly bool $ok,
		private readonly string $code,
		private readonly string $message,
		private readonly ?AutomationPlan $plan=null,
		private readonly ?AutomationReceipt $receipt=null,
		private readonly array $issues=[],
		private readonly array $handoff=[],
		private readonly bool $replayed=false,
		private readonly array $metadata=[]
	){}

	/** @param list<AutomationValidationIssue> $issues @param array<string,mixed> $handoff @param array<string,mixed> $metadata */
	public static function make(bool $ok, string $code, string $message, ?AutomationPlan $plan=null, ?AutomationReceipt $receipt=null, array $issues=[], array $handoff=[], bool $replayed=false, array $metadata=[]): self {
		return new self($ok, WorkflowState::normalize($code), trim($message), $plan, $receipt, $issues, WorkflowRecord::jsonSafe($handoff), $replayed, WorkflowRecord::jsonSafe($metadata));
	}

	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	public function plan(): ?AutomationPlan { return $this->plan; }
	public function receipt(): ?AutomationReceipt { return $this->receipt; }
	/** @return list<AutomationValidationIssue> */
	public function issues(): array { return $this->issues; }
	/** @return array<string,mixed> */
	public function handoff(): array { return $this->handoff; }
	public function replayed(): bool { return $this->replayed; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_automation_execution_result', 'ok'=>$this->ok, 'code'=>$this->code,
			'message'=>$this->message, 'plan'=>$this->plan, 'receipt'=>$this->receipt,
			'issues'=>$this->issues, 'handoff'=>$this->handoff, 'replayed'=>$this->replayed,
			'metadata'=>$this->metadata,
		];
	}
}
