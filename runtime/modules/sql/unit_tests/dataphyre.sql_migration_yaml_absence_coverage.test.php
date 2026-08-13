<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use dataphyre\sql\migration;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
$dpMigrationAbsenceRoot=\Dataphyre\Test\dataphyre_path();
require_once $dpMigrationAbsenceRoot.'/runtime/modules/sql/kernel/migration.php';

test('sql migration yaml absence coverage reports the missing extension', static function(Context $t): void {
	$t->isFalse(function_exists('yaml_parse_file'));
	$migrationInternals=$t->nonPublic(migration::class);
	$t->throws(static fn()=>$migrationInternals->invoke('parse_yaml',__FILE__),RuntimeException::class);
})->tag('sql','migration','yaml','coverage')->group('framework-coverage');
