<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Returns one deliberately information-rich request snapshot for Flightdeck
 * renderer and comparison contracts.
 *
 * @return array<string,mixed>
 */
function flightdeck_debugbar_rich_snapshot(): array {
	$now=time();
	$clientEvents=[
		[
			'type'=>'accessibility_policy','level'=>'warning','message'=>'One field needs attention.',
			'timestamp'=>1700000000000,'offset_ms'=>220.0,'a11y_checked'=>2,
			'a11y_issue_count'=>1,'a11y_adjustment_count'=>1,'a11y_status'=>'needs_attention',
			'a11y_issues'=>[['name'=>'email','label'=>'Email','issues'=>['width_constrained'],'issue_messages'=>['Field is too narrow.']]],
			'a11y_adjustments'=>[['name'=>'sku','label'=>'SKU','actions'=>['label_stacked'],'action_messages'=>['Label was stacked.']]],
			'server_snapshot_id'=>'previous-snapshot','server_snapshot_label'=>'Previous request',
		],
		[
			'type'=>'http_error','level'=>'error','message'=>'API returned 503','timestamp'=>1700000000100,
			'url'=>'https://example.test/api/orders','method'=>'POST','status'=>503,'duration_ms'=>425.5,
			'server_snapshot_id'=>'previous-snapshot','server_snapshot_label'=>'Previous request',
		],
	];
	$client=[
		'event_count'=>2,'events'=>$clientEvents,'resource_errors'=>1,'stylesheet_missing'=>1,
		'js_errors'=>1,'unhandled_rejections'=>1,'client_http_errors'=>1,'client_fetch_errors'=>1,
		'client_http_slow'=>1,'slow_resources'=>1,'accessibility_policy_events'=>1,
		'accessibility_checked'=>2,'accessibility_issues'=>1,'accessibility_adjustments'=>1,
		'accessibility_issue_fields'=>$clientEvents[0]['a11y_issues'],
		'accessibility_adjustment_fields'=>$clientEvents[0]['a11y_adjustments'],
		'accessibility_issue_tokens'=>['width_constrained'=>1],
		'accessibility_action_tokens'=>['label_stacked'=>1],
		'accessibility_latest'=>$clientEvents[0],
		'page_performance'=>['load_ms'=>900.0,'dom_content_loaded_ms'=>420.0,'first_contentful_paint_ms'=>180.0],
		'resource_timing'=>[
			'count'=>2,'total_duration_ms'=>210.0,'total_transfer_bytes'=>4096,'slow_count'=>1,'failed_count'=>1,
			'by_type'=>['script'=>1,'css'=>1],
			'slowest'=>[['name'=>'/assets/app.js','initiator_type'=>'script','duration_ms'=>180.0,'transfer_size'=>3072]],
		],
	];
	$sqlEvent=[
		'source'=>'kernel','event'=>'execute','operation'=>'select','location'=>'shop.orders','cluster'=>'primary','dbms'=>'sqlite',
		'queue'=>'foreground','queued'=>false,'cache_status'=>'miss','cache_type'=>'session','cache_names'=>['orders'],
		'invalidation_names'=>[],'result_ok'=>true,'timestamp'=>microtime(true),
		'context'=>[
			'statement'=>'SELECT * FROM shop.orders WHERE id = ?','vars'=>[42],'duration_ms'=>62.5,
			'caller'=>['file'=>__FILE__,'line'=>__LINE__,'call'=>'loadOrder'],
			'template_name'=>'orders.tpl','binding_name'=>'order_row','binding_path'=>'orders.*',
		],
	];
	$traceEntry=[
		'origin'=>'live','index'=>0,'file'=>__FILE__,'line'=>(string)__LINE__,'call'=>'OrderService::load',
		'type'=>'function_call','call_kind'=>'FC','call_color'=>'#00ffaa','message'=>'Loaded order','message_html'=>'Loaded <b>order</b>',
		'offset_ms'=>130.0,'memory_bytes'=>2048,'arguments'=>[42],'parameter_shape'=>'Integer(42)',
	];
	$timeline=[
		['offset_ms'=>0.0,'duration_ms'=>12.0,'type'=>'request','label'=>'Request started','detail'=>'POST /orders','tone'=>''],
		['offset_ms'=>20.0,'duration_ms'=>62.5,'type'=>'sql','label'=>'SQL select','detail'=>'shop.orders','tone'=>'warn'],
		['offset_ms'=>120.0,'duration_ms'=>0.0,'type'=>'panel','label'=>'Panel action','detail'=>'orders.approve','tone'=>''],
	];
	$findings=[
		['level'=>'error','title'=>'API failure','detail'=>'The response returned a failed batch entry.','source'=>'response','next'=>'Inspect the response panel.','panel'=>'response'],
		['level'=>'warning','title'=>'Slow SQL','detail'=>'A query exceeded the request threshold.','source'=>'sql','next'=>'Inspect the SQL panel.','panel'=>'sql'],
		['level'=>'info','title'=>'Accessibility adjustment','detail'=>'One label was stacked.','source'=>'client','next'=>'Inspect accessibility evidence.','panel'=>'client'],
	];

	return [
		'id'=>'current-snapshot','recorded_at'=>$now,'label'=>'POST /orders','available'=>true,'enabled'=>true,
		'request_id'=>'request-42','app'=>'shop','method'=>'POST','uri'=>'/orders?mode=approve','duration_ms'=>325.5,
		'memory_mb'=>24.5,'peak_mb'=>31.25,'files'=>80,'modules'=>['core','sql','panel','reactor'],'run_mode'=>'http','production'=>false,
		'request'=>[
			'method'=>'POST','uri'=>'/orders?mode=approve','path'=>'/orders','query'=>'mode=approve',
			'post'=>['order_id'=>42,'csrf_token'=>'[redacted]'],'cookies'=>['session'=>'[redacted]'],
			'headers'=>['Content-Type'=>'application/json','Authorization'=>'[redacted]'],
			'files'=>['receipt'=>['name'=>'receipt.pdf','size'=>1024]],'status'=>503,
			'client_ip'=>'198.51.100.10','user_agent'=>'Dataphyre Browser','content_type'=>'application/json','content_length'=>512,
		],
		'response'=>[
			'status'=>503,'bytes'=>2048,'content_type'=>'application/json','body_kind'=>'json','allows_toolbar_markup'=>false,
			'is_json'=>true,'json_valid'=>true,'json_top_level'=>'object','json_key_count'=>2,'json_item_count'=>2,
			'json_batch_route_count'=>1,'json_batch_routes'=>[['route'=>'/api/orders','status'=>'failed','failed'=>1,'total'=>2]],
			'json_failure_count'=>1,'json_failures'=>[['path'=>'$.orders.1','key'=>'success','value'=>'false']],
			'json_preview'=>['orders'=>[['success'=>true],['success'=>false,'error'=>'Inventory missing']]],
			'assets'=>[
				['kind'=>'stylesheet','url'=>'/assets/app.css','issue'=>'local_file_not_found','status'=>'missing','path'=>'/assets/app.css','size_bytes'=>0,'mime'=>'text/css'],
				['kind'=>'script','url'=>'https://cdn.example.test/app.js','issue'=>'','status'=>'remote','path'=>'/app.js','size_bytes'=>4096,'mime'=>'application/javascript'],
			],
			'asset_count'=>2,'missing_asset_count'=>1,'duplicate_ids'=>['dialog-title'=>2],'duplicate_id_count'=>1,'mojibake_count'=>1,
		],
		'client'=>$client,
		'routing'=>[
			'available'=>true,'method'=>'POST','path'=>'/orders','route'=>'orders.approve','name'=>'orders.approve',
			'pattern'=>'/orders','controller'=>'OrderController::approve','action'=>'approve','middleware'=>['auth','csrf'],
			'parameters'=>['order'=>42],'bindings'=>['order'=>['id'=>42]],'dispatch_ms'=>8.5,
		],
		'sql'=>[
			'events'=>[$sqlEvent],'query_events'=>1,'execute_events'=>1,'queued_events'=>0,'queue_execute_events'=>0,
			'total_duration_ms'=>62.5,'cache_hits'=>0,'cache_misses'=>1,'cache_stores'=>0,'cache_invalidations'=>0,
			'slowest'=>[$sqlEvent],'duplicates'=>[],'target_summary'=>[['target'=>'shop.orders','count'=>1,'execute_count'=>1,'queued_count'=>0,'failed_count'=>0,'slow_count'=>1,'total_duration_ms'=>62.5,'slowest_ms'=>62.5,'operations'=>['select'=>1],'callers'=>['loadOrder'],'templates'=>['orders.tpl']]],
			'operation_summary'=>[['operation'=>'select','count'=>1,'execute_count'=>1,'queued_count'=>0,'failed_count'=>0,'slow_count'=>1,'total_duration_ms'=>62.5]],
			'cache_summary'=>[['target'=>'shop.orders','cache_type'=>'session','hits'=>0,'misses'=>1,'stores'=>0,'invalidations'=>0,'cache_names'=>['orders'],'invalidation_names'=>[]]],
			'insights'=>[['level'=>'warning','title'=>'Slow lookup','detail'=>'The lookup exceeded 50ms.','target'=>'shop.orders','next'=>'Inspect the query plan.','count'=>1,'time_ms'=>62.5,'origin'=>'loadOrder']],
		],
		'templating'=>[
			'available'=>true,'engine'=>'Dataphyre','template_count'=>2,'binding_count'=>3,'render_count'=>1,'cache_hits'=>1,
			'templates'=>[['name'=>'orders.tpl','duration_ms'=>12.5,'bindings'=>3]],
			'last_template'=>'orders.tpl','last_binding'=>'order_row','errors'=>[['message'=>'Optional partial missing']],
		],
		'panel'=>[
			'available'=>true,'loaded'=>true,'event_count'=>1,'resource_count'=>1,'page_count'=>1,'widget_count'=>1,'action_count'=>1,
			'events'=>[['event'=>'action.executed','category'=>'action','resource'=>'orders','page'=>'index','duration_ms'=>22.0,'context'=>['action'=>'approve']]],
			'resources'=>[['name'=>'orders','label'=>'Orders','pages'=>2,'widgets'=>1,'actions'=>3]],
			'pages'=>[['name'=>'index','resource'=>'orders','route'=>'/orders','widgets'=>1,'actions'=>2]],
			'widgets'=>[['name'=>'summary','type'=>'table','resource'=>'orders','page'=>'index']],
			'actions'=>[['name'=>'approve','resource'=>'orders','type'=>'bulk','method'=>'POST']],
			'navigation'=>[['label'=>'Orders','route'=>'/orders']],'insights'=>[['level'=>'info','title'=>'Panel active','detail'=>'One lifecycle event captured.']],
			'describe'=>['resources'=>1,'pages'=>1,'widgets'=>1],
		],
		'reactor'=>[
			'available'=>true,'loaded'=>true,'event_count'=>1,'events'=>[['event'=>'order.approved','category'=>'domain','component'=>'Orders','context'=>['id'=>42]]],
			'components'=>[['name'=>'Orders','capabilities'=>['approve'],'events'=>['order.approved']]],
			'capability_counts'=>['approve'=>1],'event_counts'=>['order.approved'=>1],
			'insights'=>[['level'=>'info','title'=>'Domain event captured','detail'=>'Orders emitted order.approved.']],
			'manifest'=>['component_count'=>1,'event_count'=>1,'binding_count'=>1],
		],
		'asset_node'=>[
			'available'=>true,'loaded'=>true,'server_count'=>1,'request_count'=>1,'trace_count'=>1,
			'servers'=>[['name'=>'frontend','host'=>'127.0.0.1','port'=>5173,'status'=>'running','url'=>'http://127.0.0.1:5173']],
			'requests'=>[['method'=>'GET','path'=>'/assets/app.js','status'=>200,'duration_ms'=>15.0]],
			'trace'=>[['stage'=>'compile','status'=>'ok','duration_ms'=>8.0,'detail'=>'app.js']],
		],
		'runtime'=>[
			'php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'os'=>PHP_OS_FAMILY,'memory_limit'=>'1G','time_limit'=>'120',
			'included_files'=>80,'included_file_sample'=>[__FILE__],'modules'=>['core','sql','panel','reactor'],
			'extensions'=>['json','pcre'],'loaded_extensions'=>count(get_loaded_extensions()),'opcache'=>false,'xdebug'=>false,
		],
		'trace'=>[
			'retroactive_count'=>0,'live_bytes'=>256,'live_entry_count'=>1,'session_bytes'=>0,'session_entry_count'=>0,
			'entry_count'=>1,'handoff'=>'','entries'=>[],'live_entries'=>[$traceEntry],'session_entries'=>[],
			'plot'=>['source'=>'trace_rows','frame_count'=>1,'node_count'=>1,'link_count'=>0,'nodes'=>[['id'=>'node-1','index'=>0,'label'=>'load','call'=>'OrderService::load','class'=>'OrderService','file'=>__FILE__,'line'=>(string)__LINE__,'args'=>['42'],'count'=>1,'last_time'=>'130','color'=>'#00ffaa']],'links'=>[]],
		],
		'timeline'=>['duration_ms'=>325.5,'events'=>$timeline],
		'errors'=>[['level'=>'error','message'=>'Inventory service unavailable','file'=>__FILE__,'line'=>__LINE__,'type'=>E_WARNING,'stack'=>[]]],
		'diagnostics'=>['count'=>3,'worst_level'=>'error','findings'=>$findings,'counts'=>['error'=>1,'warning'=>1,'info'=>1]],
		'comparison'=>[
			'available'=>true,'previous_id'=>'previous-snapshot','previous_label'=>'POST /orders','previous_recorded_at'=>$now-60,
			'previous_status'=>200,'key'=>'shop|POST|/orders','regressions'=>2,'error_regressions'=>1,'improvements'=>1,
			'summary'=>'2 regressions, 1 improvement','changes'=>[
				['key'=>'status','label'=>'Status','previous'=>200,'current'=>503,'delta'=>303,'delta_label'=>'200 -> 503','previous_label'=>'200','current_label'=>'503','direction'=>'worse','tone'=>'bad','significant'=>true],
				['key'=>'duration_ms','label'=>'Server time','previous'=>600.0,'current'=>325.5,'delta'=>-274.5,'percent'=>-0.4575,'delta_label'=>'-274.5ms (-45.8%)','previous_label'=>'600ms','current_label'=>'325.5ms','direction'=>'better','tone'=>'good','significant'=>true],
			],
		],
	];
}
