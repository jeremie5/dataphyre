<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Exact invocation envelope delivered only after verification and authorization. */
final class PanelAgentToolExecutionRequest implements \JsonSerializable {
	private ?\Closure $cancellationProbe;
	private ?\Closure $clock;
	/** @param array<string,mixed> $arguments */
	public function __construct(
		private readonly PanelAgentRequestContext $context,
		private readonly string $tool,
		private readonly array $arguments,
		private readonly string $idempotencyKey,
		private readonly bool $dryRun,
		private readonly bool $confirmed,
		private readonly string $planHash,
		private readonly int $step,
		?callable $cancellationProbe=null,
		private readonly ?int $deadlineAt=null,
		?callable $clock=null
	){
		PanelAgentGuard::identifier($tool, 'tool', 128);
		PanelAgentGuard::boundedString($idempotencyKey, 'idempotency key', 256);
		PanelAgentGuard::digest($planHash, 'plan hash');
		if($step<1 || $step>32){ throw new \InvalidArgumentException('Panel agent step number is invalid.'); }
		if($deadlineAt!==null && $deadlineAt<1){ throw new \InvalidArgumentException('Panel agent execution deadline is invalid.'); }
		PanelAgentGuard::assertJson($arguments);
		$this->cancellationProbe=$cancellationProbe===null ? null : \Closure::fromCallable($cancellationProbe);
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function context(): PanelAgentRequestContext { return $this->context; }
	public function tool(): string { return strtolower(trim($this->tool)); }
	/** @return array<string,mixed> */ public function arguments(): array { return $this->arguments; }
	public function idempotencyKey(): string { return $this->idempotencyKey; }
	public function dryRun(): bool { return $this->dryRun; }
	public function confirmed(): bool { return $this->confirmed; }
	public function planHash(): string { return strtolower($this->planHash); }
	public function step(): int { return $this->step; }
	public function deadlineAt(): ?int { return $this->deadlineAt; }
	public function cancellationRequested(?int $now=null): bool {
		if($this->deadlineAt!==null){
			try{ $now ??= $this->clock===null ? time() : ($this->clock)(); }catch(\Throwable){ return true; }
			if(!is_int($now) || $now<0 || $now>=$this->deadlineAt){ return true; }
		}
		if($this->cancellationProbe===null){ return false; }
		try{ return ($this->cancellationProbe)()===true; }catch(\Throwable){ return true; }
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_tool_execution_request','tool'=>$this->tool(),'arguments'=>PanelAgentGuard::redact($this->arguments),
			'context'=>$this->context,'idempotency_hash'=>hash('sha256', $this->idempotencyKey),
			'dry_run'=>$this->dryRun,'confirmed'=>$this->confirmed,'plan_hash'=>$this->planHash(),'step'=>$this->step,'deadline_at'=>$this->deadlineAt,
			'cancellation_probe_present'=>$this->cancellationProbe!==null,'deadline_clock_present'=>$this->clock!==null,'callbacks_exposed'=>false,
		];
	}
}
