<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host boundary that supplies an authenticated request for tenant lifecycle commands. */
interface PanelTenantFabricRequestResolver {
	public function resolve(PanelCommandEnvelope $command):PanelRequest;
}
