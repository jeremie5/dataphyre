<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Release\ApplicationReleasePreflightCommand;

require_once dirname(__DIR__).'/Framework/ApplicationReleasePreflightCommand.php';

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(ApplicationReleasePreflightCommand::main(
		is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []
	));
}
