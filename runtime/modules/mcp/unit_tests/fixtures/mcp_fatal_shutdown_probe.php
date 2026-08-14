<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$entrypoint=$argv[1] ?? '';
if(!is_string($entrypoint) || !is_file($entrypoint)){
	fwrite(STDERR, "MCP entrypoint is unavailable.\n");
	exit(64);
}

require $entrypoint;

trigger_error('Deliberate MCP fatal shutdown probe.', E_USER_ERROR);
