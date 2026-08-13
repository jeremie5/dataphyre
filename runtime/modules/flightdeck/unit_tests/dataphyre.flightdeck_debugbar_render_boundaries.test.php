<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Flightdeck\TestFixture\RenderScenarios;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/flightdeck_render_scenarios.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck debugbar render boundaries')
	->tag('flightdeck','debugbar','render','evidence','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.render-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck')
	->through('toolbar shell','triage decisions','lifecycle panels','browser evidence','accessibility evidence')
	->isolation('process');

test('renderer shell and diagnostic guidance remain useful at empty and bounded states',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$modules=RenderScenarios::moduleNames(13);
	$state=[
		'modules'=>$modules,'uri'=>'/orders','method'=>'GET','duration_ms'=>12.5,'memory_mb'=>8.0,'files'=>20,
		'sql'=>[],'routing'=>['matched_route'=>'orders.index'],'request'=>['status'=>200],
		'response'=>[],'templating'=>[],'panel'=>[],'reactor'=>[],'asset_node'=>[],'runtime'=>[],
		'trace'=>[],'timeline'=>[],'errors'=>[],'diagnostics'=>['count'=>0,'worst_level'=>'ok','findings'=>[]],
	];
	$t->contains('Modules: module_1',$debugbar->invoke('markup',null,$state));
	$t->contains('+1',$debugbar->invoke('markup',null,$state));
	$invalidUtf8="\xB1\x31";
	$t->same($invalidUtf8,$debugbar->invoke('isolate_toolbar_markup',$invalidUtf8));
	$t->contains('No derived diagnostics',$debugbar->invoke('render_diagnostics_panel',[],[]));

	$guidance=$debugbar->invokeCases([
		'php'=>['method'=>'diagnostic_next_step','arguments'=>[['source'=>'php','title'=>'PHP emitted diagnostics']]],
		'encoding'=>['method'=>'diagnostic_next_step','arguments'=>[['title'=>'Mojibake encoding']]],
		'document_shell'=>['method'=>'diagnostic_next_step','arguments'=>[['title'=>'Duplicate document shells']]],
		'asset'=>['method'=>'diagnostic_next_step','arguments'=>[['title'=>'Suspicious asset URLs']]],
		'html_id'=>['method'=>'diagnostic_next_step','arguments'=>[['title'=>'Duplicate HTML ids']]],
		'json'=>['method'=>'diagnostic_next_step','arguments'=>[['title'=>'JSON response is invalid']]],
		'reactor'=>['method'=>'diagnostic_next_step','arguments'=>[['source'=>'reactor']]],
		'routing'=>['method'=>'diagnostic_next_step','arguments'=>[['source'=>'routing']]],
		'tracelog'=>['method'=>'diagnostic_next_step','arguments'=>[['source'=>'tracelog']]],
		'timeline'=>['method'=>'diagnostic_next_step','arguments'=>[['source'=>'timeline']]],
	]);
	$t->pathsContain([
		'php'=>'PHP event list','encoding'=>'charset','document_shell'=>'duplicate layout',
		'asset'=>'asset resolution','html_id'=>'repeated id','json'=>'decoded JSON marker',
		'reactor'=>'component','routing'=>'matched route','tracelog'=>'pre-module buffer','timeline'=>'request range',
	],$guidance);

	$nav=$debugbar->invoke('render_panel_nav',$state,[
		'comparison'=>['available'=>true,'regressions'=>2,'error_regressions'=>1],
	],[]);
	$t->contains('Compare',$nav);
	$t->contains('dfd-bad',$nav);
	$t->contains('No strong leads yet',$debugbar->invoke('render_triage_panel',['client'=>[]],[]));
});

test('triage prioritizes every actionable server, replay, SQL, and browser boundary',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$scenarios=$debugbar->invokeCases([
		'request_warning'=>['method'=>'triage_items','arguments'=>[['request'=>['status'=>404]],[]]],
		'php_error'=>['method'=>'triage_items','arguments'=>[['errors'=>['events'=>[[
			'severity'=>'fatal','message'=>'Fatal probe','file'=>'/tmp/probe.php','line'=>42,
		]]]],[]]],
		'invalid_json'=>['method'=>'triage_items','arguments'=>[['response'=>[
			'is_json'=>true,'json_valid'=>false,'json_error'=>'Syntax error',
		]],[]]],
		'accessibility_adjustment'=>['method'=>'triage_items','arguments'=>[[],[
			'accessibility_adjustments'=>2,
		]]],
		'replay_unverified'=>['method'=>'triage_items','arguments'=>[['duration_ms'=>200.0],[
			'production_replay'=>[
				'replay_verified'=>0,'replay_responded'=>1,'response_status'=>200,'replay_write_blocks'=>2,'server_duration_ms'=>80.0,
			],
		]]],
		'replay_no_response'=>['method'=>'triage_items','arguments'=>[[],[
			'production_replay'=>['replay_verified'=>0,'replay_responded'=>0,'response_status'=>0],
		]]],
		'replay_failed'=>['method'=>'triage_items','arguments'=>[[],[
			'production_replay'=>['replay_verified'=>1,'response_status'=>500],
		]]],
		'replay_rejected'=>['method'=>'triage_items','arguments'=>[[],[
			'production_replay'=>['replay_verified'=>1,'response_status'=>404],
		]]],
		'sql_pressure'=>['method'=>'triage_items','arguments'=>[['sql'=>[
			'failed_events'=>1,'slow_events'=>2,'duplicates'=>[['count'=>3]],
			'insights'=>[null,['level'=>'info'],['level'=>'warning','title'=>'SQL queue pressure','detail'=>'Queue is growing.']],
		]],[]]],
		'very_slow_browser'=>['method'=>'triage_items','arguments'=>[[],[
			'page_performance'=>['load_ms'=>8000.0],
			'resource_summary'=>['total_transfer_size'=>15728640,'max_duration_ms'=>8000.0],
		]]],
		'slow_browser'=>['method'=>'triage_items','arguments'=>[[],[
			'page_performance'=>['load_ms'=>3000.0],
		]]],
		'response_copy'=>['method'=>'triage_items','arguments'=>[['response'=>[
			'suspicious_phrases'=>['Fatal error'],
		],'diagnostics'=>['findings'=>[null,['level'=>'info','title'=>''],['level'=>'warning','title'=>'Runtime probe']]]],[]]],
	]);

	$t->containsRows([['title'=>'HTTP 404 response']],$scenarios['request_warning']);
	$t->containsRows([['title'=>'PHP error captured']],$scenarios['php_error']);
	$t->containsRows([['title'=>'Invalid JSON response']],$scenarios['invalid_json']);
	$t->containsRows([['title'=>'Panel accessibility policies adjusted layout']],$scenarios['accessibility_adjustment']);
	$t->containsRows([
		['title'=>'Production replay responded without metrics'],
		['title'=>'Replay hit write paths'],
		['title'=>'Production replay timing differs'],
	],$scenarios['replay_unverified']);
	$t->containsRows([['title'=>'Production replay did not return an HTTP response']],$scenarios['replay_no_response']);
	$t->containsRows([['title'=>'Production replay failed']],$scenarios['replay_failed']);
	$t->containsRows([['title'=>'Production replay did not succeed']],$scenarios['replay_rejected']);
	$t->containsRows([
		['title'=>'SQL operation failed'],['title'=>'Slow SQL is present'],
		['title'=>'SQL queue pressure'],['title'=>'Repeated SQL shape'],
	],$scenarios['sql_pressure']);
	$t->containsRows([
		['title'=>'Page load is very slow'],['title'=>'Browser resources are heavy'],
		['title'=>'One resource dominates load time'],
	],$scenarios['very_slow_browser']);
	$t->containsRows([['title'=>'Page load is slow']],$scenarios['slow_browser']);
	$t->containsRows([
		['title'=>'Error copy reached the response'],['title'=>'Runtime probe'],
	],$scenarios['response_copy']);
});

test('renderer timing and framework lifecycle panels explain all loaded and idle states',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->same([
		'timeline'=>'Timeline','tracelog'=>'Tracelog','reactor'=>'Reactor','asset_node'=>'Asset Node',
		'templating'=>'Templating','triage'=>'Triage',
	],$debugbar->invokeCases([
		'timeline'=>['method'=>'panel_label','arguments'=>['timeline']],
		'tracelog'=>['method'=>'panel_label','arguments'=>['tracelog']],
		'reactor'=>['method'=>'panel_label','arguments'=>['reactor']],
		'asset_node'=>['method'=>'panel_label','arguments'=>['cdn-server']],
		'templating'=>['method'=>'panel_label','arguments'=>['templating']],
		'triage'=>['method'=>'panel_label','arguments'=>['triage']],
	]));

	$phases=$debugbar->invoke('triage_phases',[
		'duration_ms'=>300.0,'sql'=>['total_duration_ms'=>120.0,'execute_events'=>2],
	],[
		'production_replay'=>['server_duration_ms'=>100.0,'replay_write_blocks'=>2],
		'page_performance'=>['first_byte_ms'=>500.0,'dom_content_loaded_ms'=>900.0,'load_ms'=>1200.0,'resource_count'=>4],
	]);
	$t->containsRows([
		['label'=>'Production replay'],['label'=>'Browser first byte'],['label'=>'DOM ready'],['label'=>'Page complete'],
	],$phases);

	$t->contains('No measurable change',$debugbar->invoke('render_comparison_panel',[
		'comparison'=>['available'=>true,'changes'=>[],'previous_label'=>'previous request'],
	]));
	$t->contains('SQL shifted',$debugbar->invoke('render_comparison_panel',[
		'comparison'=>[
			'available'=>true,'changes'=>[[
				'key'=>'sql_queries','label'=>'SQL queries','delta_label'=>'+2','previous_label'=>'1','current_label'=>'3',
			]],
		],
	]));

	$timeline=$debugbar->invoke('timeline_with_client_events',[
		'duration_ms'=>2000.0,'events'=>[['offset_ms'=>0.0,'label'=>'Request started']],
	],[
		'page_performance'=>['first_byte_ms'=>100.0,'dom_content_loaded_ms'=>200.0,'load_ms'=>500.0,'resource_count'=>4],
		'events'=>RenderScenarios::clientTimelineCatalog(),
	]);
	$t->containsRows([
		['label'=>'First byte received'],['label'=>'Browser resource failed'],['label'=>'Stylesheet missing'],
		['label'=>'JavaScript error'],['label'=>'Unhandled promise rejection'],['label'=>'Browser API error'],
		['label'=>'Browser fetch failed'],['label'=>'Slow browser API request'],['label'=>'Slow browser resource'],
		['label'=>'Resource timing'],['label'=>'Production replay'],['label'=>'Panel accessibility policy'],
	],$timeline);
	$t->contains('No timeline events captured',$debugbar->invoke('render_timeline_panel',[],[]));
	$t->contains('Showing 48 of 49',$debugbar->invoke('render_timeline_panel',[
		'duration_ms'=>60.0,'events'=>RenderScenarios::timelineEvents(49),
	],[]));

	$t->same('',$debugbar->invoke('render_panel_lifecycle_panel',[]));
	$t->contains('No Panel classes were loaded',$debugbar->invoke('render_panel_lifecycle_panel',['available'=>true,'loaded'=>false]));
	$t->containsAll(['Panel warning','Lifecycle Trace','Registered Panel Shape'],$debugbar->invoke('render_panel_lifecycle_panel',RenderScenarios::panelLifecycle()));
	$t->same('',$debugbar->invoke('render_reactor_panel',[]));
	$t->contains('No Reactor classes were loaded',$debugbar->invoke('render_reactor_panel',['available'=>true,'loaded'=>false]));
	$t->containsAll(['Manifest error','Reactor warning','Reactive Components'],$debugbar->invoke('render_reactor_panel',RenderScenarios::reactorLifecycle()));
	$t->contains('none',$debugbar->invoke('reactor_badges',[]));
	$t->same('',$debugbar->invoke('render_asset_node_panel',[]));
	$t->containsAll(['400b free','effective','Resolved content path','Configured servers','Request trace'],$debugbar->invoke('render_asset_node_panel',RenderScenarios::assetNodeState()));
	$t->containsAll(['Included files by module','Root paths','Latest included files'],$debugbar->invoke('render_runtime_panel',[
		'files_by_module'=>['core'=>4,'flightdeck'=>8],
		'root_paths'=>['dataphyre'=>'/workspace/dataphyre'],
		'included_tail'=>['/workspace/dataphyre/index.php'],
	],['entry_count'=>1]));
});

test('response and browser panels render raw failure evidence without ad hoc transforms',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$response=$debugbar->invoke('render_response_panel',RenderScenarios::responseAudit());
	$t->containsAll([
		'API failure markers','API batch routes','JSON parse error','JSON preview','Suspicious response text',
		'Suspicious assets','Duplicate HTML ids','Assets',
	],$response);

	$t->contains('No browser-side events',$debugbar->invoke('render_client_panel',[]));
	$t->contains('No browser-side failures',$debugbar->invoke('render_client_panel',[
		'event_count'=>1,'events'=>[['type'=>'resource_timing']],
	]));
	$browser=$debugbar->invoke('render_client_panel',RenderScenarios::browserState());
	$t->containsAll([
		'debug overhead excluded','Last browser event','tag form','line 20:4','POST','status 422','load 2.5s',
		'dom 1.2s','8 resources','HTTP response received','replay verified','production mode','read-only',
		'server 80ms','peak 24mb','body 2kb','2 write blocks','combined field rows','probe stack',
	],$browser);

	$t->contains('not matched',$debugbar->invoke('client_event_server_link',[]));
	$serverLink=$debugbar->invoke('client_event_server_link',[
		'server_snapshot_id'=>'snapshot-1','server_label'=>'Orders request','server_status'=>503,
		'server_duration_ms'=>125.0,'server_findings'=>2,
	]);
	$t->containsAll(['HTTP 503','125ms','2 findings'],$serverLink);
	$t->same('Previous request',$debugbar->invoke('history_link','','Previous request'));
	$t->containsAll(['orders.tpl','order_row','shop.orders'],$debugbar->invoke('render_templating_panel',[
		'sql_binding_count'=>1,'render_trace_count'=>1,'strict_mode'=>true,'contracts'=>1,
		'bindings'=>[['template_name'=>'orders.tpl','binding_name'=>'order_row','binding_path'=>'orders.*','query_target'=>'shop.orders']],
	]));
});

test('accessibility and resource evidence renders empty, malformed, and measured boundaries',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->contains('No Panel accessibility policy reports',$debugbar->invoke('render_accessibility_panel',[]));
	$t->contains('Policy event log',$debugbar->invoke('render_accessibility_event_log',[
		'invalid event',
		['timestamp'=>1700000000000,'a11y_status'=>'checked','a11y_checked'=>1,'message'=>'Policy passed.'],
	]));
	$t->same([],$debugbar->invoke('accessibility_event_tokens',[
		'a11y_issues'=>['invalid field'],'a11y_adjustments'=>['invalid field'],
	]));
	$t->same('',$debugbar->invoke('render_accessibility_token_summary',[],[]));
	$t->same('',$debugbar->invoke('render_accessibility_remediation',[],[]));
	$t->same('',$debugbar->invoke('render_accessibility_fields_table',[]));
	$fields=$debugbar->invoke('render_accessibility_fields_table',[
		'invalid field',RenderScenarios::accessibilityField(),
	]);
	$t->containsAll([
		'width 160/320px','source minimum characters','2 touch target failures','6 columns',
		'table 700/900px in 800px','2 compact columns','scroll preserved','Measurements:',
	],$fields);
	$t->same('',$debugbar->invoke('render_client_accessibility_event',[]));

	$resources=$debugbar->invoke('render_client_resource_timing',RenderScenarios::resourceTimingSummary());
	$t->containsAll(['Resource Timing','script','1.8s','1.5s','4kb','8kb','Slowest resources','500'],$resources);
	include dirname(__DIR__).'/kernel/debugbar/render.php';
});
