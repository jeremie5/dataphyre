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

define('DP_MIGRATION_TEST_DBMS', 'postgresql');
require_once __DIR__.'/fixtures/sql_migration_coverage_bootstrap.php';

test('sql migration postgresql coverage emits index definitions and grants', static function(Context $t): void {
	$fixture=dp_migration_sandbox($t,'postgresql');
	$fixture->selectResults(
			[['column_name'=>'id']],
			[['indexname'=>'items_pkey','indexdef'=>' CREATE UNIQUE INDEX items_pkey ON items (id) ']],
			[['grantee'=>'app_role','privilege_type'=>'SELECT']],
	);
	$filename=migration::generate_migration_diff('items');
	$t->isTrue(is_string($filename) && is_file($filename));
	$sql=$fixture->firstEmit()['migrations'][0]['up']['postgresql'];
	$t->contains('CREATE UNIQUE INDEX items_pkey ON items (id);',$sql);
	$t->contains('GRANT SELECT ON items TO app_role;',$sql);
	$t->same('postgresql',DP_MIGRATION_TEST_DBMS);
})->tag('sql','migration','postgresql','coverage')->group('framework-coverage')->maxMillis(10000);
