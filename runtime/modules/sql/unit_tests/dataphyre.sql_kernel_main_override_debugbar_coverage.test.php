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

final class dataphyre_flightdeck_debugbar {
	public static int $attached=0;
	public static function attach_sql_observer(): void {self::$attached++;}
}

test('sql kernel main override debugbar coverage loads policy override and observer bridge',static function(Context $t): void {
	if(!defined('DP_SQL_KERNEL_TEST_DBMS')){ define('DP_SQL_KERNEL_TEST_DBMS','sqlite'); }
	if(!defined('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE')){
		define('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE',['type'=>'fs','max_lifespan'=>'2 hour','hash_type'=>'sha256']);
	}
	$t->global('dataphyre_flightdeck_debugbar_active')->replace(true);
	require_once __DIR__.'/fixtures/sql_kernel_main_coverage_bootstrap.php';
	$t->same(1,dataphyre_flightdeck_debugbar::$attached);
	$t->same('fs',\dataphyre\sql::get_table_cache_policy('ordinary')['type']);
	$t->same('sha256',\dataphyre\sql::get_table_cache_policy('ordinary')['hash_type']);
})->tag('sql','kernel','main','override','debugbar','coverage')->group('framework-coverage');
