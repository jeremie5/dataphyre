<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explainable default-deny decision plus non-weakenable approval requirements. */
final class PanelAgentPolicyDecision implements \JsonSerializable {
	/** @param array<string,mixed> $metadata */
	private function __construct(
		private readonly bool $allowed,
		private readonly string $reason,
		private readonly int $approvalCount,
		private readonly bool $confirmationRequired,
		private readonly bool $separationOfDuties,
		private readonly array $metadata
	){
		if($approvalCount<0 || $approvalCount>2){ throw new \InvalidArgumentException('Panel agent policy approval count must be between zero and two.'); }
		if($separationOfDuties && $approvalCount===0){ throw new \InvalidArgumentException('Panel agent policy separation requires an approval.'); }
		PanelAgentGuard::boundedString($reason, 'policy reason', 2048);
		PanelAgentGuard::assertJson($metadata, 32768);
	}

	/** @param array<string,mixed> $metadata */
	public static function allow(string $reason='Host policy allowed this tool.', int $approvalCount=0, bool $confirmationRequired=false, bool $separationOfDuties=false, array $metadata=[]): self {
		return new self(true, PanelAgentGuard::safeError($reason,2048), $approvalCount, $confirmationRequired, $separationOfDuties, $metadata);
	}
	/** @param array<string,mixed> $metadata */
	public static function deny(string $reason='Host policy denied this tool.', array $metadata=[]): self { return new self(false, PanelAgentGuard::safeError($reason,2048), 0, false, false, $metadata); }

	public function allowed(): bool { return $this->allowed; }
	public function reason(): string { return trim($this->reason); }
	public function approvalCount(): int { return $this->approvalCount; }
	public function confirmationRequired(): bool { return $this->confirmationRequired; }
	public function separationOfDuties(): bool { return $this->separationOfDuties; }
	/** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_policy_decision','allowed'=>$this->allowed,'reason'=>$this->reason(),
			'approval_count'=>$this->approvalCount,'confirmation_required'=>$this->confirmationRequired,
			'separation_of_duties'=>$this->separationOfDuties,'metadata'=>PanelAgentGuard::redact($this->metadata),
		];
	}
}
