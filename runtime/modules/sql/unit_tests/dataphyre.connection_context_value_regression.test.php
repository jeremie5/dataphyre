<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\ConnectionContext;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

final class DpConnectionContextValueFixture {
	public static mixed $result=null;
}

if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
if(!function_exists('sql_query')){
	function sql_query(mixed ...$arguments): mixed {
		$result=DpConnectionContextValueFixture::$result;
		$callback=$arguments[7] ?? null;
		if(is_callable($callback)){
			$callback($result);
			return true;
		}
		return $result;
	}
}
if(!defined('DP_CORE_CFG')) define('DP_CORE_CFG',['datacenter'=>'test']);
if(!defined('DP_SQL_CFG')) define('DP_SQL_CFG',['default_cluster'=>null,'datacenters'=>['test'=>['dbms_clusters'=>[]]]]);

framework(['sql']);

test('connection value helpers return the first result cell for native driver row shapes',static function(Context $t): void {
	$connection=new ConnectionContext();
	DpConnectionContextValueFixture::$result=['version'=>'PostgreSQL 17.10'];
	$t->same('PostgreSQL 17.10',$connection->value('SELECT version()'));

	DpConnectionContextValueFixture::$result=[['count'=>17]];
	$t->same(17,$connection->value('SELECT count(*)'));

	DpConnectionContextValueFixture::$result=[];
	$t->same(null,$connection->value('SELECT 1 WHERE false'));

	DpConnectionContextValueFixture::$result=false;
	$t->same(false,$connection->value('SELECT broken'));

	DpConnectionContextValueFixture::$result=['total'=>5];
	$queued=null;
	$t->isTrue($connection->queueValue('SELECT count(*)',static function(mixed $value) use (&$queued): void {
		$queued=$value;
	}));
	$t->same(5,$queued);
})->tag('sql','connection-context','scalar','postgresql','regression');
