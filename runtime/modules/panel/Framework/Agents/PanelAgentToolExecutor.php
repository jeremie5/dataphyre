<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned execution adapter. Implementations must honor dry-run and cancellation. */
interface PanelAgentToolExecutor {
	public function execute(PanelAgentToolExecutionRequest $request): PanelAgentToolExecutionResult;
}
