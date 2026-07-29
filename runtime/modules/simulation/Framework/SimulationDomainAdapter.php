<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

/** Application-owned semantics and idempotent mutation boundary for one business domain. */
interface SimulationDomainAdapter {
	/** @return array<string,mixed> */
	public function snapshot(SimulationContext $context): array;

	public function apply(SimulationIntent $intent, SimulationContext $context): SimulationOutcome;
}
