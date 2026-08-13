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

if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
if(!defined('DP_CORE_CFG')){ define('DP_CORE_CFG',['datacenter'=>'']); }
if(!defined('DP_SQL_CFG')){ define('DP_SQL_CFG',['default_cluster'=>'','datacenters'=>[''=>['dbms_clusters'=>'invalid']]]); }
framework(['sql']);
if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static array $observers=[]; public static function add_observer(callable $observer): void { self::$observers[]=$observer; } }');
}

test('database DB optional absence coverage handles malformed config and unloaded bridges',static function(Context $t): void {
	$dbInternals=$t->nonPublic(DB::class);
	$t->same([],DB::clusters());
	$t->same(null,DB::defaultCluster());
	$t->same(null,DB::clusterDbms());
	$t->same(null,DB::datacenter());
	$t->isFalse(DB::guardrailsEnabled());
	DB::bootRuntimeBridges();
	$dbInternals->invoke('bootTemplatingCacheBridge');
	$dbInternals->invoke('bootApiCacheBridge');
	$t->isTrue($dbInternals->invoke('tracingEnabled'));
})->tag('database','db','deep-coverage')->group('framework-coverage');
