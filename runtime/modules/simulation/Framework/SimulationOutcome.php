<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use JsonSerializable;

/** Safe result returned by an application adapter after applying one intent. */
final class SimulationOutcome implements JsonSerializable {
	/** @param array<string,mixed> $summary @param array<int,SimulationIntent> $followUps @param array<int,string> $errors */
	public function __construct(
		private bool $applied,
		private string $status,
		private array $summary=[],
		private array $followUps=[],
		private array $errors=[],
	) {
		$this->status=trim($this->status)!=='' ? trim($this->status) : ($this->applied ? 'applied' : 'skipped');
		$this->followUps=array_values(array_filter($this->followUps, static fn(mixed $intent): bool => $intent instanceof SimulationIntent));
		$this->errors=array_values(array_unique(array_filter(array_map('strval', $this->errors), static fn(string $error): bool => trim($error)!=='')));
	}

	public static function applied(string $status='applied', array $summary=[], array $followUps=[]): self {
		return new self(true, $status, $summary, $followUps);
	}

	public static function skipped(string $status='skipped', array $summary=[]): self {
		return new self(false, $status, $summary);
	}

	public static function failed(string $status='failed', array $errors=[], array $summary=[]): self {
		return new self(false, $status, $summary, [], $errors);
	}

	public function wasApplied(): bool { return $this->applied; }
	public function status(): string { return $this->status; }
	/** @return array<string,mixed> */
	public function summary(): array { return $this->summary; }
	/** @return array<int,SimulationIntent> */
	public function followUps(): array { return $this->followUps; }
	/** @return array<int,string> */
	public function errors(): array { return $this->errors; }

	public function jsonSerialize(): array {
		return ['applied'=>$this->applied, 'status'=>$this->status, 'summary'=>$this->summary, 'errors'=>$this->errors];
	}
}
