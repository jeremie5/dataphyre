<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\PostgreSqlMigrationCommand;

$migrationFramework=dirname(__DIR__).'/Framework/Migrations';
foreach([
	'PostgreSqlMigrationProfile',
	'PostgreSqlMigrationManifest',
	'PostgreSqlSchemaInspector',
	'PostgreSqlMigrationRunner',
	'PostgreSqlMigrationCommand',
] as $migrationClass){
	require_once $migrationFramework.'/'.$migrationClass.'.php';
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(PostgreSqlMigrationCommand::main(
		is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []
	));
}
