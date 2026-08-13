<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host-owned schema/data migration boundary for domain activation plans. */
interface PanelDomainMigrationExecutor {
	/** @return array<string,mixed> Durable, sanitized migration receipt. */
	public function migrate(PanelDomainActivationPlan $plan,?PanelDomainCompilation $from,?PanelDomainCompilation $to):array;
	/** @param array<string,mixed> $receipt Compensates a migration after a later activation stage fails. */
	public function compensate(PanelDomainActivationPlan $plan,array $receipt,?PanelDomainCompilation $from,?PanelDomainCompilation $to):void;
}
