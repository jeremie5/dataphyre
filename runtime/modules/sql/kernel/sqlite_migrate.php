<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\Migrations\SqliteMigrationCommand;

$migrationFramework=dirname(__DIR__).'/Framework/Migrations';
foreach(['SqliteMigrationProfile','SqliteMigrationManifest','SqliteMigrationCommand'] as $migrationClass){
	require_once $migrationFramework.'/'.$migrationClass.'.php';
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(SqliteMigrationCommand::main(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
}
