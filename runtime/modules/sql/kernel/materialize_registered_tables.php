<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\RegisteredTableMaterializationCommand;

require_once dirname(__DIR__).'/Framework/RegisteredTableMaterializationCommand.php';

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(RegisteredTableMaterializationCommand::main(
		is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []
	));
}
