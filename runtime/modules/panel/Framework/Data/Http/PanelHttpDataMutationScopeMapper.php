<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned fail-closed projection from local mutation authority to remote claims. */
interface PanelHttpDataMutationScopeMapper {
	public function map(PanelDataMutation $mutation,PanelHttpDataMutationDefinition $definition):PanelHttpDataSourceScope;
}
