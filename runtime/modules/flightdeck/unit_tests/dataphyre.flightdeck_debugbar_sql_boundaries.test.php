<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\DB;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/flightdeck_debugbar_database_probe.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck SQL recorder boundaries')
	->tag('flightdeck','debugbar','sql','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.sql-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck','module:sql')
	->through('trace sources','aggregates','fallback targets','insight thresholds')
	->isolation('process');

test('SQL state merges global and facade traces while database diagnostics stay non-fatal',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$globalTrace=[
		'event'=>'execute','operation'=>'select','location'=>'shop.orders',
		'duration_ms'=>75.25,'timestamp'=>1.0,'result_ok'=>true,
	];
	$databaseTrace=[
		'event'=>'execute','operation'=>'count','location'=>'shop.customers',
		'duration_ms'=>10.0,'timestamp'=>2.0,'result_ok'=>true,
	];
	$t->global('dataphyre_flightdeck_sql_events')->replace([$globalTrace]);
	DB::respond([$databaseTrace]);
	$t->containsRows([
		['operation'=>'select','location'=>'shop.orders'],
		['operation'=>'count','location'=>'shop.customers'],
	],$debugbar->invoke('sql_events'));

	DB::fail(new RuntimeException('trace source unavailable'));
	$t->containsRows([
		['operation'=>'select','location'=>'shop.orders'],
	],$debugbar->invoke('sql_events'));

	DB::respond([]);
	$t->hasPathValues([
		'query_events'=>1,
		'execute_events'=>1,
		'slow_events'=>1,
		'total_duration_ms'=>75.25,
	],$debugbar->invoke('sql_state'));
});

test('SQL summaries name fallback targets and suppress evidence below insight thresholds',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$events=[
		[
			'event'=>'queue_push','operation'=>'select','location'=>'','queued'=>true,
			'context'=>['query_target'=>'context.orders','statement'=>'','duration_ms'=>0.0],
		],
		[
			'event'=>'execute','operation'=>'select','location'=>'','queued'=>false,
			'context'=>['statement'=>'SELECT * FROM parsed.orders','duration_ms'=>5.0],
		],
		[
			'event'=>'execute','operation'=>'','location'=>'','queued'=>false,
			'context'=>['statement'=>'','duration_ms'=>1.0],
		],
	];
	$targets=$debugbar->invoke('sql_target_summary',$events);
	$t->containsRows([
		['target'=>'context.orders','queued_count'=>1],
		['target'=>'parsed.orders','execute_count'=>1],
		['target'=>'unknown','execute_count'=>1],
	],$targets);
	$t->containsRows([
		['operation'=>'select','queued_count'=>1],
		['operation'=>'query','execute_count'=>1],
	],$debugbar->invoke('sql_operation_summary',$events));
	$t->contains('1 queued',$debugbar->invoke('render_sql_target_summary',$targets));

	$t->containsRows([[
		'target'=>'unknown','cache_type'=>'default','stores'=>1,
	]],$debugbar->invoke('sql_cache_summary',[[
		'event'=>'cache_store','location'=>'','cache_type'=>'',
		'cache_names'=>['orders.by_id'],'invalidation_names'=>[],
	]]));

	$thinTemplateEvent=[
		'event'=>'execute','operation'=>'select','location'=>'shop.orders',
		'context'=>['template_name'=>'orders.php','binding_name'=>'row','duration_ms'=>1.0],
	];
	$thinReadWriteTarget=[
		'target'=>'shop.orders','execute_count'=>1,'count'=>2,'total_duration_ms'=>0.0,
		'operations'=>['select'=>1,'update'=>1],
	];
	$t->same([],$debugbar->invoke(
		'sql_insights',
		[$thinTemplateEvent],
		[['count'=>3]],
		[$thinReadWriteTarget],
		[],
	));

	include dirname(__DIR__).'/kernel/debugbar/sql.php';
});
