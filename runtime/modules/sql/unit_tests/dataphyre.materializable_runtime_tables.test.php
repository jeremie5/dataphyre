<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!\defined('DATAPHYRE_MODULE_POLICY')){
	\define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>[],
		'disabled'=>[],
		'allow_all'=>true,
	]);
}

require_once __DIR__.'/fixtures/sql_kernel_main_coverage_bootstrap.php';

test('legacy discovery materializes available runtime tables and ignores absent modules',static function(Context $t): void {
	dp_sql_kernel_reset($t);
	$tables=\dataphyre\sql::materializable_table_definitions();

	$t->same(20,\count($tables));
	$t->contains('dataphyre.vestra_objects',$tables);
	$t->isFalse(\in_array('dataphyre.aceit_engine_experiments',$tables,true));
	$t->isFalse(\in_array('dataphyre.sentinel_events',$tables,true));
	foreach($tables as $table){
		$t->instanceOf(TableDefinition::class,\dataphyre\sql::table_definition($table),$table);
	}
})->tag('sql','table-definition','materialization','module-policy','legacy','release')
	->group('framework-coverage');
