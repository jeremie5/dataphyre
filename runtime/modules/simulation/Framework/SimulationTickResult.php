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

/** Bounded result from one observation-driven simulation tick. */
final class SimulationTickResult implements JsonSerializable {
	/** @param array<int,array<string,mixed>> $events @param array<int,string> $errors */
	public function __construct(
		private bool $ok,
		private string $status,
		private array $events=[],
		private array $errors=[],
		private int $revision=0,
		private bool $retrySafe=true,
	) {}

	public static function idle(string $status, int $revision=0): self { return new self(true, $status, [], [], $revision); }
	public static function failed(string $status, array $errors=[], int $revision=0, array $events=[]): self { return new self(false, $status, $events, $errors, $revision); }

	public function ok(): bool { return $this->ok; }
	public function status(): string { return $this->status; }
	/** @return array<int,array<string,mixed>> */
	public function events(): array { return $this->events; }
	public function appliedCount(): int { return count(array_filter($this->events, static fn(array $event): bool => ($event['applied'] ?? false)===true)); }
	public function revision(): int { return $this->revision; }

	public function jsonSerialize(): array {
		return [
			'ok'=>$this->ok,
			'status'=>$this->status,
			'event_count'=>count($this->events),
			'applied_count'=>$this->appliedCount(),
			'events'=>$this->events,
			'errors'=>$this->errors,
			'revision'=>$this->revision,
			'retry_safe'=>$this->retrySafe,
		];
	}
}
