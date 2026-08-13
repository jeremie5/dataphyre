<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host boundary for idempotent release preparation, activation, verification, and rollback. */
interface PanelReleaseDeploymentAdapter extends \JsonSerializable {
	/**
	 * The operation_key in the context is stable across retries. Implementations
	 * must return the same effect for repeated calls with that key.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed> Must contain a boolean `ok` member.
	 */
	public function execute(string $phase,array $context):array;
}
