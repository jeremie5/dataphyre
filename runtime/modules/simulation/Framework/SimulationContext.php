<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use DateTimeImmutable;
use JsonSerializable;

/** Immutable context shared by snapshot, planning, and application mutation boundaries. */
final class SimulationContext implements JsonSerializable {
	/** @param array<string,mixed> $controls */
	public function __construct(
		private string $domain,
		private SimulationScope $scope,
		private SimulationPerspective $perspective,
		private array $controls,
		private DateTimeImmutable $now,
		private string $runId,
	) {}

	public function domain(): string { return $this->domain; }
	public function scope(): SimulationScope { return $this->scope; }
	public function perspective(): SimulationPerspective { return $this->perspective; }
	/** @return array<string,mixed> */
	public function controls(): array { return $this->controls; }
	public function control(string $name, mixed $default=null): mixed { return $this->controls[$name] ?? $default; }
	public function now(): DateTimeImmutable { return $this->now; }
	public function runId(): string { return $this->runId; }

	public function jsonSerialize(): array {
		return ['domain'=>$this->domain, 'scope'=>$this->scope, 'perspective'=>$this->perspective, 'controls'=>$this->controls, 'now'=>$this->now->format(DATE_ATOM), 'run_id'=>$this->runId];
	}
}
