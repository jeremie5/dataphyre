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

define('DP_MIGRATION_TEST_DBMS', 'sqlite');
require_once __DIR__.'/fixtures/sql_migration_coverage_bootstrap.php';

test('sql migration sqlite coverage emits the portable index placeholder', static function(Context $t): void {
	$fixture=dp_migration_sandbox($t,'sqlite');
	$fixture->selectResults(
			[['name'=>'id']],
			[['name'=>'idx_items_id']],
			[],
	);
	$filename=migration::generate_migration_diff('items');
	$t->isTrue(is_string($filename) && is_file($filename));
	$sql=$fixture->firstEmit()['migrations'][0]['up']['sqlite'];
	$t->contains('-- CREATE INDEX idx_items_id ON items(...);',$sql);
	$t->same('sqlite',DP_MIGRATION_TEST_DBMS);
})->tag('sql','migration','sqlite','coverage')->group('framework-coverage')->maxMillis(10000);
