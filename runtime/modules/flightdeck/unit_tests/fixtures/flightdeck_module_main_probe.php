<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$GLOBALS['flightdeck_required_modules']=[]; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Module entrypoint fixture observes the legacy dependency publication boundary."

function dp_module_required(string $module, string $dependency): void {
	$GLOBALS['flightdeck_required_modules'][]=[$module,$dependency]; // dataphyre-test-architecture: exempt[raw-global-variable] reason="Module entrypoint fixture records dependency calls at the legacy global boundary."
}

require (string)($argv[1] ?? '');

echo json_encode([
	'required_modules'=>$GLOBALS['flightdeck_required_modules'], // dataphyre-test-architecture: exempt[raw-global-variable] reason="Module entrypoint fixture serializes its legacy dependency call record."
],JSON_THROW_ON_ERROR);
