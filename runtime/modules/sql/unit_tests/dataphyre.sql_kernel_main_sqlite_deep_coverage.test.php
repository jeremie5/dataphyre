<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

define('DP_SQL_KERNEL_TEST_DBMS','sqlite');
require_once __DIR__.'/fixtures/sql_kernel_main_coverage_bootstrap.php';
require_once __DIR__.'/fixtures/sql_kernel_main_operations_helpers.php';

test('sql kernel main sqlite deep coverage executes immediate operation matrix',static function(Context $t): void {
	dp_sql_kernel_run_immediate_operations($t,'sqlite');
})->tag('sql','kernel','main','sqlite','deep-coverage')->group('framework-coverage')->maxMillis(15000);

test('sql kernel main sqlite deep coverage queues every operation shape',static function(Context $t): void {
	dp_sql_kernel_run_queued_operations($t,'sqlite');
})->tag('sql','kernel','main','sqlite','deep-coverage')->group('framework-coverage')->maxMillis(10000);
