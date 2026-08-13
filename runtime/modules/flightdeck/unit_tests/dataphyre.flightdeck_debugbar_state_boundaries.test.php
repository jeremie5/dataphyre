<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Flightdeck\TestFixture\StateScenarios;
use Dataphyre\Panel\Panel;
use Dataphyre\Reactor\Reactor;
use Dataphyre\Reactor\ReactorTrace;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/flightdeck_state_runtime_probes.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck debugbar state boundaries')
	->tag('flightdeck','debugbar','state','facades','bounded-data','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.state-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck')
	->through('runtime facades','request and response evidence','bounded JSON diagnostics')
	->isolation('process');

test('state collectors describe loaded framework facades and survive diagnostic facade failures',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);

	Reactor::manifestFails('manifest unavailable');
	ReactorTrace::eventsFail('trace unavailable');
	$failedReactor=$debugbar->invoke('reactor_state',[]);
	$t->hasPathValues([
		'available'=>true,'loaded'=>true,'event_count'=>0,'manifest_error'=>'manifest unavailable',
	],$failedReactor);
	$t->containsRows([['title'=>'Reactor manifest failed']],$failedReactor['insights']);

	Reactor::manifestIs([
		'module'=>'reactor','version'=>'3.0','component_count'=>1,'trace'=>['count'=>1],
		'components'=>[
			'invalid component',
			['name'=>'Orders','capabilities'=>['approve','export'],'actions'=>['approve'],'rules'=>[]],
		],
	]);
	ReactorTrace::eventsAre([
		'invalid event',
		['id'=>'reactor-1','time'=>microtime(true),'event'=>'action.failed','context'=>['component'=>'Orders','action'=>'approve']],
	]);
	$reactor=$debugbar->invoke('reactor_state',[]);
	$t->hasPathValues([
		'event_count'=>1,'capability_counts.approve'=>1,'capability_counts.export'=>1,
	],$reactor);
	$t->containsRows([['name'=>'Orders','actions'=>1,'rules'=>0]],$reactor['components']);
	$t->containsRows([
		['title'=>'Reactor lifecycle warnings'],
		['title'=>'Reactive actions without validation'],
	],$reactor['insights']);

	Panel::fails('traceSummary','trace','describe');
	$failedPanel=$debugbar->invoke('panel_state',[]);
	$t->hasPathValues([
		'available'=>true,'loaded'=>true,'event_count'=>0,'describe_error'=>'describe probe failed',
	],$failedPanel);
	$t->containsRows([['title'=>'Panel description failed']],$failedPanel['insights']);

	Panel::returns(
		['count'=>1],
		[['id'=>'panel-1','time'=>microtime(true),'event'=>'action.completed','context'=>['resource'=>'orders','operation'=>'approve']]],
		[
			'resources'=>[
				'invalid resource',
				['name'=>'orders','label'=>'Orders','actions'=>[
					'invalid action',
					['name'=>'approve','label'=>'Approve','tone'=>'positive','modal'=>true,'requires_confirmation'=>true],
				]],
			],
			'pages'=>[['name'=>'overview','label'=>'Overview','route'=>'/orders']],
			'widgets'=>[['name'=>'orders_table','label'=>'Orders','type'=>'table']],
			'navigation'=>[['label'=>'Orders','route'=>'/orders']],
			'theme'=>['name'=>'operator'],
		],
	);
	$panel=$debugbar->invoke('panel_state',[]);
	$t->hasPathValues([
		'event_count'=>1,'category_counts.action'=>1,'operation_counts.approve'=>1,'theme.name'=>'operator',
	],$panel);
	$t->containsRows([['resource'=>'orders','name'=>'approve','requires_confirmation'=>true]],$panel['actions']);

	\dataphyre\asset_node::represents([
		'ip'=>'192.0.2.20','name'=>'edge-yul','info'=>['datacenter'=>'yul'],'configured'=>true,
		'step'=>2,'can_store'=>true,'storage_path'=>__DIR__,
	]);
	$assetNode=$debugbar->invoke('asset_node_state',[]);
	$t->hasPathValues([
		'configured'=>true,'current_ip'=>'192.0.2.20','current_name'=>'edge-yul','current_info.datacenter'=>'yul',
		'server_step'=>2,'can_store'=>true,'storage.exists'=>true,
	],$assetNode);
	$t->global('dataphyre_asset_node_trace')->replace('invalid trace buffer');
	$t->same([],$debugbar->invoke('asset_node_trace_state'));
	$t->same([],$debugbar->invoke('asset_node_latest_trace_data',['content.local_probe']));

	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/fallback?probe=1','REQUEST_METHOD'=>'PATCH']);
	\dataphyre\routing::snapshotIs(['matched_route'=>'orders.update','method'=>'PATCH']);
	$t->hasPathValues(['matched_route'=>'orders.update','method'=>'PATCH'],$debugbar->invoke('routing_state'));
	\dataphyre\routing::snapshotFails('routing unavailable');
	$t->hasPathValues(['request_path'=>'/fallback','method'=>'PATCH'],$debugbar->invoke('routing_state'));
});

test('state diagnostics expose bounded request, response, timeline, and memory evidence',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/orders','REQUEST_METHOD'=>'GET']);
	$t->globalMap('_SESSION')->replace(StateScenarios::sessionEntries(31));

	$request=$debugbar->invoke('request_state');
	$t->hasKey('...',$request['session']);
	$t->same('truncated',$request['session']['...']);

	$diagnostics=$debugbar->invoke(
		'diagnostics_state',
		['query_events'=>50],
		['matched_route'=>'orders.show'],
		['status'=>404,'path'=>'/orders'],
		['available'=>true,'is_json'=>true,'json_valid'=>false,'json_error'=>'Syntax error','is_html'=>true,'charset'=>''],
		[],
		['memory_limit'=>StateScenarios::memoryLimitAtCurrentPeakRatio(0.70)],
		[],
		[],
		[],
		['insights'=>[null]],
		['insights'=>[null]],
	);
	$t->containsRows([
		['title'=>'Response is not successful'],
		['title'=>'High query count'],
		['title'=>'JSON response is invalid'],
		['title'=>'HTML charset is not declared'],
		['title'=>'Memory pressure is rising'],
	],$diagnostics['findings']);

	$started=1000.0;
	$sqlEvents=[
		null,
		['event'=>'','operation'=>''],
		['event'=>'queue_execute_end','operation'=>'queue_execute','queue'=>'end','timestamp'=>$started+0.10,'context'=>['duration_ms'=>50.0]],
		['event'=>'queue_push','operation'=>'select','queue'=>'end','timestamp'=>$started+0.05,'location'=>'shop.orders','context'=>[]],
	];
	$t->same([],$debugbar->invoke('sql_queue_wait_events',$sqlEvents,$started,100.0));
	$timeline=$debugbar->invoke(
		'timeline_state',$started,100.0,['events'=>$sqlEvents],['matched_route'=>'orders.show'],
		['method'=>'GET','path'=>'/orders','status'=>200],['files_count'=>1],
		['events'=>[null,['time'=>0]]],['events'=>[null,['time'=>0]]],
	);
	$t->containsRows([
		['type'=>'routing','label'=>'Route matched'],
		['type'=>'sql-queue','label'=>'queue_execute queue_execute_end'],
	],$timeline['events']);

	$response=$debugbar->invoke(
		'response_state','<html><body>orders</body></html>',
		['Content-Type: text/html; charset=windows-1252'],
	);
	$t->hasPathValues([
		'content_type'=>'text/html; charset=windows-1252','body_kind'=>'html','charset'=>'windows-1252',
	],$response);

	$plainResponse=['content_type'=>'','is_json'=>false,'body_kind'=>'text','is_html'=>false];
	$t->same([
		'empty'=>false,
		'classified_html'=>true,
		'closing_body'=>true,
		'doctype'=>true,
		'html_tag'=>true,
	],$debugbar->invokeCases([
		'empty'=>['method'=>'response_allows_toolbar_markup','arguments'=>[$plainResponse,'']],
		'classified_html'=>['method'=>'response_allows_toolbar_markup','arguments'=>[[
			'content_type'=>'','is_json'=>false,'body_kind'=>'text','is_html'=>true,
		],'plain text']],
		'closing_body'=>['method'=>'response_allows_toolbar_markup','arguments'=>[$plainResponse,'plain</body>']],
		'doctype'=>['method'=>'response_allows_toolbar_markup','arguments'=>[$plainResponse,'<!doctype document>']],
		'html_tag'=>['method'=>'response_allows_toolbar_markup','arguments'=>[$plainResponse,'<html-fragment>']],
	]));
});

test('state JSON and templating evidence stops at explicit diagnostic budgets',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);

	$preview=$debugbar->invoke('json_preview',StateScenarios::keyedValues(25));
	$t->same('truncated',$preview['...']);
	$unknownRoute=$debugbar->invoke('json_batch_routes',[
		'/probe/unknown'=>StateScenarios::keyedValues(13),
	]);
	$t->containsRows([[
		'route'=>'/probe/unknown','unknown'=>1,'status'=>'mixed',
	]],$unknownRoute);
	$t->count(12,$unknownRoute[0]['keys']);
	$t->count(32,$debugbar->invoke('json_batch_routes',StateScenarios::batchRoutes(33)));
	$t->same('unknown',$debugbar->invoke('json_batch_entry_status',['value'=>1]));
	$t->same([],$debugbar->invoke('json_failure_markers',['deep'=>true],'$',8));
	$t->same([],$debugbar->invoke('json_failure_markers','scalar'));
	$t->count(32,$debugbar->invoke('json_failure_markers',StateScenarios::failureMarkerBudgetBoundary()));
	$t->count(32,$debugbar->invoke('json_failure_markers',StateScenarios::failureBranches(40)));
	$resource=fopen('php://memory','rb');
	$t->contains('resource',$debugbar->invoke('json_failure_value_label',$resource));
	fclose($resource);

	$t->global('dataphyre_flightdeck_sql_events')->replace([
		['event'=>'execute','operation'=>'select','location'=>'shop.orders','binding_trace_id'=>'','timestamp'=>1000.0],
		[
			'event'=>'execute','operation'=>'select','location'=>'shop.orders',
			'binding_trace_id'=>'binding-1','render_trace_id'=>'render-1','template_name'=>'orders.tpl',
			'binding_name'=>'row','binding_path'=>'orders.*','query_target'=>'shop.orders','query_mode'=>'select','timestamp'=>1001.0,
		],
	]);
	\dataphyre\templating::stateIs([
		'is_dev_mode'=>true,'strict_mode'=>true,'cache_dir'=>'/tmp/dataphyre-templates',
		'template_contracts'=>['orders'=>['version'=>1]],
	]);
	$t->count(2,$debugbar->invoke('sql_events'),'Both templating SQL evidence rows should be available.');
	$templating=$debugbar->invoke('templating_state');
	$t->hasPathValues([
		'dev_mode'=>true,'strict_mode'=>true,'cache_dir'=>'/tmp/dataphyre-templates',
		'contracts'=>1,'sql_binding_count'=>1,'render_trace_count'=>1,
	],$templating);
	$t->containsRows([[
		'binding_trace_id'=>'binding-1','render_trace_id'=>'render-1','template_name'=>'orders.tpl',
		'binding_name'=>'row','binding_path'=>'orders.*','query_target'=>'shop.orders','query_mode'=>'select',
	]],$templating['bindings']);

	\dataphyre\templating::stateFails('templating unavailable');
	$failedTemplating=$debugbar->invoke('templating_state');
	$t->missingKey('dev_mode',$failedTemplating);
	$t->same(1,$failedTemplating['sql_binding_count']);

	include dirname(__DIR__).'/kernel/debugbar/state.php';
});
