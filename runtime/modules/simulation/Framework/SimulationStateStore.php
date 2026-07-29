<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

/** Optimistic state boundary; applications may implement it with Dataphyre SQL. */
interface SimulationStateStore {
	/** @return ?array<string,mixed> */
	public function load(string $domain, SimulationScope|string $scope): ?array;

	/** Save only when the currently persisted revision equals the expected revision. */
	public function save(string $domain, SimulationScope|string $scope, array $state, int $expectedRevision): bool;
}
