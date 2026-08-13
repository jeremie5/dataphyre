<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\DB;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql']);

test('database discovery fails safely before optional SQL and core configuration is defined', static function(Context $t): void {
	$t->isFalse(defined('DP_SQL_CFG'));
	$t->isFalse(defined('DP_CORE_CFG'));
	$t->same([], DB::clusters());
	$t->same(null, DB::defaultCluster());
	$t->same(null, DB::clusterDbms());
	$t->same(null, DB::datacenter());
})->tag('database', 'sql', 'configuration', 'partial-bootstrap', 'unit');
