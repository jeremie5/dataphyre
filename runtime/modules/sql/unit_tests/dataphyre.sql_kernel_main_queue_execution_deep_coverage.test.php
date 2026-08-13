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
require_once __DIR__.'/fixtures/sql_kernel_main_queue_fakes.php';

test('sql kernel main queue execution deep coverage returns each driver queue result',static function(Context $t): void {
	dp_sql_kernel_reset($t);
	$events=[];
	\dataphyre\sql::add_observer(static function(array $event)use(&$events): void {$events[]=$event;});
	\dataphyre\mysql_query_builder::$conns['main']=new \dataphyre\DpSqlMainQueueMysqlConnection();
	\dataphyre\mysql_query_builder::$queued_queries['mysql-result']=dp_sql_kernel_queue_payload();
	$t->isTrue(\dataphyre\sql::execute_queue('mysql-result'));
	\dataphyre\postgresql_query_builder::$conns['main']=new \dataphyre\DpSqlMainQueuePgConnection();
	\dataphyre\postgresql_query_builder::$queued_queries['postgresql-result']=dp_sql_kernel_queue_payload();
	$t->isTrue(\dataphyre\sql::execute_queue('postgresql-result'));
	\dataphyre\sqlite_query_builder::$conns['main']=new \dataphyre\SQLite3();
	\dataphyre\sqlite_query_builder::$queued_queries['sqlite-result']=dp_sql_kernel_queue_payload();
	$t->same(false,\dataphyre\sql::execute_queue('sqlite-result'));
	$t->same(3,count(array_filter($events,static fn(array $event): bool=>($event['event'] ?? '')==='queue_execute_end')));
})->tag('sql','kernel','main','queue','deep-coverage')->group('framework-coverage')->maxMillis(10000);
