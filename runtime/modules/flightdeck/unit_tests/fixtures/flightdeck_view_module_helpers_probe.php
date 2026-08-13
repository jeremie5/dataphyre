<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function dp_module_required(string $module): array {
	return dp_module_present($module);
}

function dp_module_present(string $module): array {
	return $module==='templating' ? [(string)($GLOBALS['dp_flightdeck_templating_facade'] ?? '')] : []; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Module helper fixture consumes the standalone templating facade path."
}
