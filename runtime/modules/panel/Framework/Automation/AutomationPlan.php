<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable dry-run plan shown before an automated or human-triggered action executes.
 */
final class AutomationPlan implements \JsonSerializable {
	/**
	 * @param list<array<string,mixed>> $steps
	 * @param list<array<string,mixed>> $effects
	 * @param list<string> $warnings
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		private readonly string $action,
		private readonly string $summary,
		private readonly array $steps,
		private readonly array $effects,
		private readonly array $warnings,
		private readonly AutomationPolicyDecision $policy,
		private readonly string $risk,
		private readonly string $confirmation,
		private readonly array $metadata=[],
		private readonly ?string $createdAt=null
	){}

	public function action(): string { return $this->action; }
	public function summary(): string { return $this->summary; }
	/** @return list<array<string,mixed>> */
	public function steps(): array { return $this->steps; }
	/** @return list<array<string,mixed>> */
	public function effects(): array { return $this->effects; }
	/** @return list<string> */
	public function warnings(): array { return $this->warnings; }
	public function policy(): AutomationPolicyDecision { return $this->policy; }
	public function hash(): string {
		$payload=$this->jsonSerialize(); unset($payload['hash']);
		return hash('sha256', WorkflowEvent::canonicalJson($payload));
	}

	public function jsonSerialize(): array {
		$payload=[
			'type'=>'panel_automation_plan', 'action'=>$this->action, 'summary'=>$this->summary,
			'steps'=>$this->steps, 'effects'=>$this->effects, 'warnings'=>$this->warnings,
			'policy'=>$this->policy, 'risk'=>$this->risk, 'confirmation'=>$this->confirmation,
			'metadata'=>$this->metadata, 'created_at'=>$this->createdAt ?? gmdate('c'),
		];
		$payload['hash']=hash('sha256', WorkflowEvent::canonicalJson($payload));
		return $payload;
	}
}
