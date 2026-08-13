<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Host-owned fail-closed projection from local query authority to remote claims. */
interface PanelHttpDataSourceScopeMapper {
	public function map(PanelDataQuery $query, PanelHttpDataSourceDefinition $definition): PanelHttpDataSourceScope;
}
