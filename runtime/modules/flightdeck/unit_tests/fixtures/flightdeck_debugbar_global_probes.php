<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(function_exists('tracelog')!==true){
	function tracelog(mixed ...$arguments): void {
		$GLOBALS['flightdeck_debugbar_tracelog_calls'][]=$arguments; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Tracelog facade records calls through the legacy global diagnostics boundary."
	}
}
