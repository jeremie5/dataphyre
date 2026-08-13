<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable invocation envelope shared by dry-run and execution paths.
 */
final class AutomationExecutionRequest implements \JsonSerializable {
	/** @param array<string,mixed> $input @param WorkflowActor|array<string,mixed>|string $actor @param array<string,mixed> $context */
	public function __construct(
		private readonly array $input,
		WorkflowActor|array|string $actor,
		private readonly ?string $idempotencyKey=null,
		private readonly bool $dryRun=false,
		private readonly bool $confirmed=false,
		private readonly ?string $confirmationPhrase=null,
		private readonly array $context=[]
	){ $this->actor=WorkflowActor::from($actor); }

	private readonly WorkflowActor $actor;
	/** @return array<string,mixed> */
	public function input(): array { return $this->input; }
	public function actor(): WorkflowActor { return $this->actor; }
	public function idempotencyKey(): ?string { return $this->idempotencyKey; }
	public function dryRun(): bool { return $this->dryRun; }
	public function confirmed(): bool { return $this->confirmed; }
	public function confirmationPhrase(): ?string { return $this->confirmationPhrase; }
	/** @return array<string,mixed> */
	public function context(): array { return $this->context; }

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_automation_request', 'input'=>AutomationReceipt::redact($this->input),
			'actor'=>$this->actor, 'idempotency_key_present'=>$this->idempotencyKey!==null && trim($this->idempotencyKey)!=='',
			'dry_run'=>$this->dryRun, 'confirmed'=>$this->confirmed,
			'confirmation_phrase_present'=>$this->confirmationPhrase!==null,
			'context_keys'=>array_keys($this->context),
		];
	}
}
