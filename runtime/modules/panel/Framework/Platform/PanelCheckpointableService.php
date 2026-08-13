<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Exact in-process rollback contract for mutable services installed in a
 * PanelPlatform container.
 *
 * Checkpoints are trusted runtime values, not public manifests or persistence
 * payloads. Implementations must validate before mutation and remain safely
 * cloneable so PanelPlatform can preflight a complete restore atomically.
 */
interface PanelCheckpointableService {
	public function checkpointType(): string;
	/** @return array<string,mixed> */
	public function checkpoint(): array;
	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint): PanelCheckpointableService;
}
