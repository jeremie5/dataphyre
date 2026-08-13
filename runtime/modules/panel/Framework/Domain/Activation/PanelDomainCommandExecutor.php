<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host-owned execution boundary for materialized domain commands. */
interface PanelDomainCommandExecutor {
	public function execute(PanelDomainCommandInvocation $invocation):mixed;
}
