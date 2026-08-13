<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded execution, failure, cancellation, or idempotent replay result. */
final class PanelAgentExecutionResult implements \JsonSerializable {
	/** @param list<array<string,mixed>> $steps @param array<string,mixed> $metadata */
	private function __construct(
		private readonly bool $ok,
		private readonly string $code,
		private readonly string $planHash,
		private readonly array $steps,
		private readonly bool $replayed,
		private readonly int $storeRevision,
		private readonly ?PanelAgentAuditReceipt $receipt,
		private readonly array $metadata
	){
		PanelAgentGuard::identifier($code, 'execution code', 96);
		PanelAgentGuard::digest($planHash, 'execution plan hash');
		if($storeRevision<0){ throw new \InvalidArgumentException('Panel agent execution store revision is invalid.'); }
		PanelAgentGuard::assertJson($steps, 1048576); PanelAgentGuard::assertJson($metadata, 65536);
	}

	/** @param list<array<string,mixed>> $steps @param array<string,mixed> $metadata */
	public static function make(bool $ok, string $code, string $planHash, array $steps, int $storeRevision, ?PanelAgentAuditReceipt $receipt=null, array $metadata=[]): self {
		return new self($ok, $code, $planHash, PanelAgentGuard::redact($steps), false, $storeRevision, $receipt, PanelAgentGuard::redact($metadata));
	}
	public function withReceipt(PanelAgentAuditReceipt $receipt, int $storeRevision): self { return new self($this->ok,$this->code,$this->planHash,$this->steps,$this->replayed,$storeRevision,$receipt,$this->metadata); }
	public function asReplay(int $storeRevision): self { return new self($this->ok,'idempotent_replay',$this->planHash,$this->steps,true,$storeRevision,$this->receipt,$this->metadata); }
	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function planHash(): string { return $this->planHash; }
	/** @return list<array<string,mixed>> */ public function steps(): array { return $this->steps; }
	public function replayed(): bool { return $this->replayed; }
	public function storeRevision(): int { return $this->storeRevision; }
	public function receipt(): ?PanelAgentAuditReceipt { return $this->receipt; }
	/** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }
	public function jsonSerialize(): array { return ['type'=>'panel_agent_execution_result','version'=>1,'ok'=>$this->ok,'code'=>$this->code,'plan_hash'=>$this->planHash,'steps'=>$this->steps,'replayed'=>$this->replayed,'store_revision'=>$this->storeRevision,'receipt'=>$this->receipt,'metadata'=>$this->metadata]; }
}
