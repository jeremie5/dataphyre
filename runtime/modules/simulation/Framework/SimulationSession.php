<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

/** Durable, serializable execution state for one domain and scope. */
final class SimulationSession {
	/** @param array<string,mixed> $controls @param array<string,float> $ruleRuns @param array<int,array<string,mixed>> $pending @param array<int,array<string,mixed>> $journal */
	public function __construct(
		private bool $enabled,
		private string $scenario,
		private string $seed,
		private int $cursor=0,
		private array $controls=[],
		private ?string $lastTickAt=null,
		private array $ruleRuns=[],
		private array $pending=[],
		private array $journal=[],
		private int $revision=0,
	) {
		$this->scenario=strtolower(trim($this->scenario));
		$this->seed=trim($this->seed)!=='' ? trim($this->seed) : bin2hex(random_bytes(16));
		$this->cursor=max(0, $this->cursor);
		$this->revision=max(0, $this->revision);
		$this->ruleRuns=array_map(static fn(mixed $value): float => max(0.0, (float)$value), $this->ruleRuns);
		$this->pending=array_values(array_filter($this->pending, 'is_array'));
		$this->journal=array_values(array_filter($this->journal, 'is_array'));
	}

	public static function fresh(string $scenario, ?string $seed=null): self {
		return new self(false, $scenario, $seed ?? bin2hex(random_bytes(16)));
	}

	/** @param array<string,mixed> $state */
	public static function fromArray(array $state, string $fallbackScenario): self {
		$enabled=$state['enabled'] ?? false;
		return new self(
			$enabled===true || $enabled===1 || $enabled==='1',
			(string)($state['scenario'] ?? $fallbackScenario),
			(string)($state['seed'] ?? ''),
			(int)($state['cursor'] ?? 0),
			is_array($state['controls'] ?? null) ? $state['controls'] : [],
			isset($state['last_tick_at']) && trim((string)$state['last_tick_at'])!=='' ? (string)$state['last_tick_at'] : null,
			is_array($state['rule_runs'] ?? null) ? $state['rule_runs'] : [],
			is_array($state['pending'] ?? null) ? $state['pending'] : [],
			is_array($state['journal'] ?? null) ? $state['journal'] : [],
			(int)($state['revision'] ?? 0),
		);
	}

	public function enabled(): bool { return $this->enabled; }
	public function scenario(): string { return $this->scenario; }
	public function seed(): string { return $this->seed; }
	public function cursor(): int { return $this->cursor; }
	/** @return array<string,mixed> */
	public function controls(): array { return $this->controls; }
	public function lastTickAt(): ?string { return $this->lastTickAt; }
	/** @return array<string,float> */
	public function ruleRuns(): array { return $this->ruleRuns; }
	/** @return array<int,array<string,mixed>> */
	public function pending(): array { return $this->pending; }
	/** @return array<int,array<string,mixed>> */
	public function journal(): array { return $this->journal; }
	public function revision(): int { return $this->revision; }

	public function configure(bool $enabled, string $scenario, array $controls, ?string $seed=null): void {
		$this->enabled=$enabled;
		$this->scenario=strtolower(trim($scenario));
		$this->controls=$controls;
		if($seed!==null && trim($seed)!=='' && !hash_equals($this->seed, trim($seed))){
			$this->seed=trim($seed);
			$this->cursor=0;
			$this->lastTickAt=null;
			$this->ruleRuns=[];
			$this->pending=[];
			$this->journal=[];
		}
	}

	/** @param array<string,float> $ruleRuns @param array<int,array<string,mixed>> $pending @param array<int,array<string,mixed>> $journal */
	public function recordTick(string $lastTickAt, int $cursor, array $ruleRuns, array $pending, array $journal): void {
		$this->lastTickAt=$lastTickAt;
		$this->cursor=max(0, $cursor);
		$this->ruleRuns=$ruleRuns;
		$this->pending=$pending;
		$this->journal=$journal;
	}

	public function advanceRevision(): void { $this->revision++; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'enabled'=>$this->enabled,
			'scenario'=>$this->scenario,
			'seed'=>$this->seed,
			'cursor'=>$this->cursor,
			'controls'=>$this->controls,
			'last_tick_at'=>$this->lastTickAt,
			'rule_runs'=>$this->ruleRuns,
			'pending'=>$this->pending,
			'journal'=>$this->journal,
			'revision'=>$this->revision,
		];
	}
}
