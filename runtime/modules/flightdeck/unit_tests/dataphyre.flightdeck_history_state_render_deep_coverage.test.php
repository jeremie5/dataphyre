<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/flightdeck_debugbar_scenarios.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

test('Flightdeck history compares equivalent requests and aggregates bounded browser evidence', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$current=flightdeck_debugbar_rich_snapshot();
	$previous=$current;
	$previous['id']='previous-snapshot';
	$previous['recorded_at']=time()-60;
	$previous['request']['status']=200;
	$previous['duration_ms']=100.0;
	$previous['sql']['query_events']=0;
	$previous['sql']['total_duration_ms']=0.0;
	$previous['diagnostics']=['count'=>0,'worst_level'=>'ok','findings'=>[]];
	$previous['client']=[];
	$previous['response']['missing_asset_count']=0;
	$previous['response']['json_failure_count']=0;
	$previous['response']['bytes']=256;
	$previous['memory_mb']=8.0;

	$session=$t->globalMap('_SESSION')->replace([
		'dataphyre_flightdeck_debugbar_history'=>[$current,'invalid',['label'=>'missing id'],$previous],
	]);
	$t->count(2,dataphyre_flightdeck_debugbar::history());
	$t->same($current,dataphyre_flightdeck_debugbar::history_snapshot('current-snapshot'));
	$t->same(null,dataphyre_flightdeck_debugbar::history_snapshot(''));
	$t->same(null,dataphyre_flightdeck_debugbar::history_snapshot('missing'));
	$t->same($previous,$debugbar->invoke('previous_comparable_snapshot',[$current,$previous],$current));
	$t->same(null,$debugbar->invoke('previous_comparable_snapshot',[],$current));
	$t->same('shop|POST|/orders',$debugbar->invoke('comparable_snapshot_key',$current));

	$comparison=$debugbar->invoke('snapshot_comparison',$current,$previous);
	$t->hasPathValues([
		'available'=>true,'previous_id'=>'previous-snapshot','key'=>'shop|POST|/orders',
	],$comparison);
	$t->greaterThan(0,$comparison['regressions']);
	$t->greaterThan(0,$comparison['error_regressions']);
	$t->contains('regression',$comparison['summary']);
	$t->count(11,$debugbar->invoke('comparison_metric_definitions'));
	$t->same(null,$debugbar->invoke('comparison_change',$current,$current,['key'=>'duration_ms','label'=>'Server time','unit'=>'ms']));
	$statusImproved=$debugbar->invoke('comparison_change',$previous,$current,['key'=>'status','label'=>'Status','unit'=>'status']);
	$t->hasPathValues(['direction'=>'better','tone'=>'good'],$statusImproved);
	$t->same(3,$debugbar->invoke('status_score',500));
	$t->same(2,$debugbar->invoke('status_score',404));
	$t->same(1,$debugbar->invoke('status_score',302));
	$t->same(0,$debugbar->invoke('status_score',200));
	$t->same('+250ms (+25%)',$debugbar->invoke('comparison_delta_label',250.0,0.25,'ms'));
	$t->same('-1kb (-50%)',$debugbar->invoke('comparison_delta_label',-1024.0,-0.5,'bytes'));
	$t->same('1.5mb',$debugbar->invoke('comparison_value_label',1.5,'mb'));
	$t->same('2kb',$debugbar->invoke('comparison_value_label',2048,'bytes'));
	foreach(['status','duration_ms','sql_queries','sql_time_ms','findings','browser_events','browser_load_ms','missing_assets','api_failures','memory_mb','body_bytes','unknown'] as $metric){
		$t->type(in_array($metric,['status','sql_queries','findings','browser_events','missing_assets','api_failures','body_bytes','unknown'],true)?'integer':'double',$debugbar->invoke('snapshot_metric_value',$current,$metric));
	}

	$compacted=$debugbar->invoke('compact_snapshot',$current);
	$t->notEmpty($compacted['id']);
	$t->same('POST /orders?mode=approve',$compacted['label']);
	$t->same(1,count($compacted['sql']['events']));
	$t->same('[object stdClass]',$debugbar->invoke('clamp_history_value',new stdClass()));
	$t->same('[depth-limit]',$debugbar->invoke('clamp_history_value',['deep'=>true],'',9));
	$t->endsWith('...',$debugbar->invoke('clamp_history_value',str_repeat('x',3000),'message'));
	$many=[];
	for($index=0;$index<110;$index++){
		$many['key_'.$index]=$index;
	}
	$t->hasKey('...',$debugbar->invoke('clamp_history_value',$many));
	$t->count(1,$debugbar->invoke('history_within_session_budget',[$compacted]));

	$rawEvents=[
		['type'=>'resource_error','message'=>'Script failed','url'=>'https://example.test/app.js','tag'=>'script','timestamp'=>1700000000000],
		['type'=>'stylesheet_missing','message'=>'Stylesheet missing','url'=>'/app.css','timestamp'=>1700000000100],
		['type'=>'js_error','message'=>'ReferenceError','source'=>'/app.js','line'=>10,'column'=>4,'stack'=>'stack','timestamp'=>1700000000200],
		['type'=>'unhandled_rejection','message'=>'Promise rejected','timestamp'=>1700000000300],
		['type'=>'slow_resource','url'=>'/hero.jpg','initiator_type'=>'img','duration_ms'=>3500,'transfer_size'=>6000000,'encoded_size'=>5000000,'decoded_size'=>7000000,'timestamp'=>1700000000400],
		['type'=>'client_http_error','url'=>'https://example.test/api/orders?mode=approve','method'=>'POST','response_status'=>503,'timestamp'=>$previous['recorded_at']*1000],
		['type'=>'client_http_slow','url'=>'/api/orders','method'=>'POST','duration_ms'=>3500,'timestamp'=>1700000000500],
		['type'=>'client_fetch_error','url'=>'/api/customers','method'=>'GET','timestamp'=>1700000000600],
		['type'=>'page_performance','load_ms'=>9000,'dom_content_loaded_ms'=>3000,'first_byte_ms'=>200,'resource_count'=>2,'slow_resource_count'=>1,'timestamp'=>1700000000700],
		['type'=>'resource_timing','url'=>'/hero.jpg','initiator_type'=>'img','duration_ms'=>3500,'transfer_size'=>6000000,'encoded_size'=>5000000,'decoded_size'=>7000000,'timestamp'=>1700000000800],
		['type'=>'production_replay','response_status'=>503,'replay_responded'=>true,'replay_verified'=>true,'replay_write_blocks'=>2,'timestamp'=>1700000000900],
		['type'=>'accessibility_policy','checked'=>2,'fields'=>[
			['name'=>'email','issues'=>['width_constrained'],'issue_messages'=>['Narrow'],'usable_width'=>100,'required_width'=>320],
			['name'=>'sku','actions'=>['label_stacked'],'action_messages'=>['Stacked']],
		],'timestamp'=>1700000001000],
		['type'=>'unknown_event','message'=>'fallback'],
		'invalid',
	];
	$events=$debugbar->invoke('normalize_client_events',$rawEvents);
	$t->count(13,$events);
	$t->same('client_event',$events[12]['type']);
	$t->same('combined_fields',$events[11]['a11y_field_source']);
	$t->same('needs_attention',$events[11]['a11y_status']);
	$apiPrevious=$previous;
	$apiPrevious['id']='previous-api';
	$apiPrevious['uri']='https://example.test/api/orders?mode=approve';
	$apiPrevious['request']['path']='/api/orders';
	$apiPrevious['request']['host']='example.test';
	$apiPrevious['request']['query']='mode=approve';
	$apiPrevious['request']['status']=503;
	$linked=$debugbar->invoke('link_client_events_to_history',$events,[$current,$previous,$apiPrevious],'current-snapshot');
	$t->same('previous-api',$linked[5]['server_snapshot_id']);
	$t->same($apiPrevious,$debugbar->invoke('matching_snapshot_for_client_event',$events[5],[$current,$previous,$apiPrevious],'current-snapshot'));
	$t->same(null,$debugbar->invoke('matching_snapshot_for_client_event',['url'=>''],[$previous],'current-snapshot'));
	$t->hasPathValues(['method'=>'POST','host'=>'example.test','path'=>'/api/orders','query'=>'mode=approve'],$debugbar->invoke('client_event_target',$events[5]));
	$t->same('/orders',$debugbar->invoke('snapshot_target',$current)['path']);
	$t->same($previous['recorded_at'],$debugbar->invoke('client_event_seconds',['timestamp'=>$previous['recorded_at']*1000]));
	$t->same(0,$debugbar->invoke('client_event_seconds',[]));
	$t->same('example.test',$debugbar->invoke('normalize_host',' EXAMPLE.TEST:443 '));

	$client=$debugbar->invoke('client_state_from_events',$linked);
	$t->same(13,$client['event_count']);
	$t->same(1,$client['accessibility_issues']);
	$t->same(1,$client['accessibility_adjustments']);
	$t->notEmpty($client['resource_summary']);
	$t->same(1,$client['production_replay_count']);
	$t->greaterThan(0,$client['linked_server_events']);
	$merged=$debugbar->invoke('merge_client_events',['events'=>array_slice($linked,0,2)],array_slice($linked,2));
	$t->same(13,$merged['event_count']);
	$t->same(['width_constrained'=>2],$debugbar->invoke('accessibility_token_counts',[
		['issues'=>['width_constrained','width_constrained']],['issues'=>['']],
	],'issues'));
	$resource=$debugbar->invoke('client_resource_timing_summary',[
		['initiator_type'=>'script','duration_ms'=>100,'transfer_size'=>1000,'encoded_size'=>900,'decoded_size'=>1200],
		['initiator_type'=>'script','duration_ms'=>200,'transfer_size'=>2000,'encoded_size'=>1800,'decoded_size'=>2400],
		['duration_ms'=>50,'transfer_size'=>500],
	]);
	$t->hasPathValues(['count'=>3,'total_duration_ms'=>350.0,'max_duration_ms'=>200.0,'total_transfer_size'=>3500],$resource);
	$diagnostics=$debugbar->invoke('with_client_diagnostics',['findings'=>[['level'=>'info','title'=>'Server','source'=>'server']]],$client);
	$t->same('error',$diagnostics['worst_level']);
	$t->greaterThan(5,$diagnostics['count']);
	$t->same('fatal',$debugbar->invoke('diagnostics_from_findings',[['level'=>'fatal']])['worst_level']);
	$t->same('client_event',$debugbar->invoke('client_event_type','unknown'));
	$t->same('warning',$debugbar->invoke('client_event_level','client_http_slow',''));
	$t->same('info',$debugbar->invoke('client_event_level','page_performance',''));
	$t->same('error',$debugbar->invoke('client_event_level','js_error',''));

	$t->isFalse(dataphyre_flightdeck_debugbar::record_client_events('','bad',[])['ok']);
	$t->same('No active Flightdeck session.',dataphyre_flightdeck_debugbar::record_client_events('current-snapshot',dataphyre_flightdeck_debugbar::client_token('current-snapshot'),[])['message']);
	$t->same(null,$debugbar->invoke('record_snapshot',$current));
	dataphyre_flightdeck_debugbar::clear_history();
	$t->isFalse($session->get('dataphyre_flightdeck_debugbar_history','missing')!=='missing');
})->tag('flightdeck','coverage','history','browser-evidence')->group('framework-coverage');

test('Flightdeck state collectors turn framework lifecycles and response evidence into bounded diagnostics', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$started=microtime(true)-0.5;
	$t->globalMap('_SERVER')->replace([
		'REQUEST_TIME_FLOAT'=>$started,'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/orders?mode=approve',
		'HTTPS'=>'on','HTTP_HOST'=>'example.test','HTTP_X_FORWARDED_FOR'=>'198.51.100.10',
		'HTTP_USER_AGENT'=>'Dataphyre Test Browser','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest','HTTP_DNT'=>'1',
		'HTTP_AUTHORIZATION'=>'Bearer private-token','CONTENT_TYPE'=>'application/json',
	]);
	$t->globalMap('_GET')->replace(['mode'=>'approve']);
	$t->globalMap('_POST')->replace(['order_id'=>42,'password'=>'private']);
	$t->globalMap('_COOKIE')->replace(['session'=>'private']);
	$t->globalMap('_REQUEST')->replace([
		'uri'=>'/assets/app.js','passkey'=>'private','expected_hash'=>'private','variant'=>'modern','options'=>['minify'=>true],
	]);
	$t->globalMap('_SESSION')->replace(['user_id'=>42,'token'=>'private']);
	$t->global('dataphyre_asset_node_trace')->replace([
		['time'=>$started+0.1,'stage'=>'request.received','data'=>['uri'=>'/assets/app.js','passkey'=>'private','cached'=>false]],
		'invalid',
		['time'=>$started+0.2,'stage'=>'content.local_probe','data'=>['path'=>'/assets/app.js','exists'=>true,'expected_hash'=>'private','metadata'=>['size'=>12]]],
	]);
	$t->global('dataphyre_flightdeck_php_errors')->replace([
		['errno'=>E_WARNING,'message'=>str_repeat('Warning ',60),'file'=>__FILE__,'line'=>__LINE__,'timestamp'=>$started+0.3,'memory_bytes'=>2048,'stack'=>[['function'=>'loadOrder']]],
		'invalid',
		['errno'=>E_USER_NOTICE,'message'=>'A useful notice','file'=>__FILE__,'line'=>__LINE__],
	]);

	$t->same([],$debugbar->invoke('reactor_state',[]));
	$t->hasPathValues(['available'=>true,'loaded'=>false,'event_count'=>0],$debugbar->invoke('reactor_state',['reactor']));
	$reactorEvents=$debugbar->invoke('reactor_events_state',[
		['id'=>'r1','time'=>$started+0.15,'event'=>'action.validation_failed','memory'=>4096,'context'=>[
			'component'=>'Orders','action'=>'approve','status'=>422,'duration_ms'=>125.5,'memory_delta'=>512,
			'payload'=>['order_id'=>42],'exception'=>new RuntimeException('invalid order'),
		]],
		'invalid',
	]);
	$t->count(1,$reactorEvents);
	$t->hasPathValues(['category'=>'validation','component'=>'Orders','action'=>'approve','status'=>422],$reactorEvents[0]);
	foreach([
		'snapshot.created'=>'snapshot','authorization.denied'=>'auth','validation.failed'=>'validation','action.started'=>'action',
		'effect.applied'=>'effect','model.loaded'=>'model','component.mounted'=>'component','manifest.loaded'=>'manifest',
		'request.received'=>'request','response.ready'=>'response','span.closed'=>'span','booted'=>'lifecycle',
	] as $event=>$category){
		$t->same($category,$debugbar->invoke('reactor_event_category',$event));
	}
	$t->same(['scalar'=>'value','nothing'=>'null','items'=>'array(2)','object'=>'stdClass'],$debugbar->invoke('reactor_context_summary',[
		'scalar'=>'value','nothing'=>null,'items'=>[1,2],'object'=>new stdClass(),
	]));
	$components=[[
		'name'=>'Orders','capabilities'=>['approve'],'state_keys'=>['order'],'locked'=>['id'],'actions'=>['approve'],
		'computed'=>['total'],'rules'=>[],'listeners'=>['refresh'],'session'=>['user'],'bindings'=>['input'=>['order_id']],
	], 'invalid'];
	$t->hasPathValues(['name'=>'Orders','state_keys'=>1,'locked'=>1,'actions'=>1,'computed'=>1,'rules'=>0,'listeners'=>1,'session'=>1],$debugbar->invoke('reactor_component_summary',$components)[0]);
	$t->hasPathValues(['module'=>'reactor','version'=>'2.0','component_count'=>1,'trace_count'=>2],$debugbar->invoke('reactor_manifest_summary',[
		'module'=>'reactor','version'=>'2.0','component_count'=>1,'trace'=>['count'=>2],
	]));
	$t->count(3,$debugbar->invoke('reactor_insights',$reactorEvents,[],['error'=>'manifest unavailable']));
	$t->count(1,$debugbar->invoke('reactor_insights',[],$components,[]));

	$t->same([],$debugbar->invoke('panel_state',[]));
	$t->hasPathValues(['available'=>true,'loaded'=>false,'event_count'=>0],$debugbar->invoke('panel_state',['panel']));
	$panelEvents=$debugbar->invoke('panel_events_state',[
		['id'=>'p1','time'=>$started+0.2,'event'=>'bulk_action.failed','memory'=>8192,'context'=>[
			'request'=>['resource'=>'orders','operation'=>'approve'],'result'=>['status'=>500],
			'duration_ms'=>150.0,'memory_delta'=>1024,'selection'=>['type'=>'ids','count'=>2],'exception'=>new RuntimeException('failed'),
		]],
		'invalid',
	]);
	$t->count(1,$panelEvents);
	$t->hasPathValues(['category'=>'action','resource'=>'orders','operation'=>'approve','status'=>500],$panelEvents[0]);
	foreach([
		'action.opened'=>'action','bulk.selected'=>'bulk','save.completed'=>'write','delete.completed'=>'write','restore.completed'=>'write',
		'duplicate.completed'=>'write','import.started'=>'import','export.ready'=>'export','form.opened'=>'form','field.updated'=>'form',
		'table.loaded'=>'table','relation.loaded'=>'relation','widget.rendered'=>'widget','navigation.built'=>'navigation',
		'theme.loaded'=>'theme','search.completed'=>'search','request.received'=>'request','page.rendered'=>'page',
		'resource.loaded'=>'resource','booted'=>'lifecycle',
	] as $event=>$category){
		$t->same($category,$debugbar->invoke('panel_event_category',$event));
	}
	$t->same([
		'request'=>'request orders/approve','result'=>'status 500','selection'=>'ids(2)','items'=>'array(2)','object'=>'stdClass',
	],$debugbar->invoke('panel_context_summary',[
		'request'=>['resource'=>'orders','operation'=>'approve'],'result'=>['status'=>500],
		'selection'=>['type'=>'ids','count'=>2],'items'=>[1,2],'object'=>new stdClass(),
	]));
	$resources=[[
		'name'=>'orders','label'=>'Orders','navigation_group'=>'Sales','table'=>'shop.orders','global_searchable'=>true,
		'form'=>['fields'=>[['name'=>'status']]],'table_schema'=>['columns'=>[['name'=>'id']],'filters'=>[['name'=>'status']],'views'=>[['name'=>'pending']]],
		'actions'=>[['name'=>'approve','label'=>'Approve','tone'=>'success','modal'=>true,'requires_confirmation'=>false]],
		'relations'=>[['name'=>'customer']],
	], 'invalid'];
	$t->hasPathValues(['name'=>'orders','fields'=>1,'columns'=>1,'filters'=>1,'views'=>1,'actions'=>1,'relations'=>1,'searchable'=>true],$debugbar->invoke('panel_resource_summary',$resources)[0]);
	$t->hasPathValues(['name'=>'overview','actions'=>1,'renders'=>true,'authorizes'=>true],$debugbar->invoke('panel_page_summary',[[
		'name'=>'overview','label'=>'Overview','route'=>'/overview','group'=>'Sales','actions'=>['refresh'],'renders'=>true,'authorizes'=>true,
	], 'invalid'])[0]);
	$t->hasPathValues(['name'=>'sales','type'=>'chart','sort'=>10],$debugbar->invoke('panel_widget_summary',[[
		'name'=>'sales','label'=>'Sales','type'=>'chart','tone'=>'positive','sort'=>10,
	], 'invalid'])[0]);
	$actions=$debugbar->invoke('panel_action_summary',$resources);
	$t->hasPathValues(['resource'=>'orders','name'=>'approve','modal'=>true,'requires_confirmation'=>false],$actions[0]);
	$t->count(3,$debugbar->invoke('panel_insights',$panelEvents,[],[],[],[],['error'=>'describe failed']));
	$t->count(1,$debugbar->invoke('panel_insights',[],$resources,[],[],$actions,[]));

	$t->same([],$debugbar->invoke('asset_node_state',[]));
	$t->hasPathValues(['available'=>true,'configured'=>null],$debugbar->invoke('asset_node_state',['asset_node']));
	$t->same('ok',$debugbar->invoke('asset_node_safe_call',static fn(): string=>'ok','fallback'));
	$t->same('fallback',$debugbar->invoke('asset_node_safe_call',static function(): never { throw new RuntimeException('probe failed'); },'fallback'));
	$t->same([['ip'=>'192.0.2.10','name'=>'edge','datacenter'=>'yul','protocol'=>'https','port'=>443]],$debugbar->invoke('asset_node_servers_state',[
		'192.0.2.10'=>['name'=>'edge','datacenter'=>'yul','protocol'=>'https','port'=>443],
	]));
	$assetRequest=$debugbar->invoke('asset_node_request_state');
	$t->hasPathValues(['uri'=>'/assets/app.js','has_passkey'=>true,'has_expected_hash'=>true],$assetRequest);
	$t->same('[redacted]',$assetRequest['params']['passkey']);
	$t->same('[array]',$assetRequest['params']['options']);
	$assetTrace=$debugbar->invoke('asset_node_trace_state');
	$t->greaterThan(1,count($assetTrace));
	$t->same('[redacted]',$assetTrace[0]['data']['passkey']);
	$t->same('true',$assetTrace[1]['data']['exists']);
	$t->same('[array]',$assetTrace[1]['data']['metadata']);
	$t->same(['path'=>'/assets/app.js','exists'=>'true','expected_hash'=>'[redacted]','metadata'=>'[array]'],$debugbar->invoke('asset_node_latest_trace_data',['content.local_probe']));
	$t->same([],$debugbar->invoke('asset_node_latest_trace_data',['missing.stage']));

	$runtime=$debugbar->invoke('runtime_state',[
		'C:/project/runtime/modules/sql/kernel/sql.php','C:/project/runtime/common/helpers.php','C:/project/app/index.php',
	],['core','sql']);
	$t->hasPathValues(['files_count'=>3,'modules_count'=>2],$runtime);
	$t->same(['sql'=>1,'common'=>1,'application'=>1],$runtime['files_by_module']);
	$t->same('POST /orders?mode=approve',$debugbar->invoke('snapshot_label',['method'=>'POST','uri'=>'/orders?mode=approve']));
	$errors=$debugbar->invoke('error_state');
	$t->greaterThan(1,$errors['count']);
	$t->greaterThan(0,$errors['counts']['warning']);

	$sqlEvents=[
		['event'=>'queue_push','operation'=>'select','queue'=>'end','location'=>'shop.orders','timestamp'=>$started+0.05,'context'=>[]],
		['event'=>'queue_execute_start','operation'=>'queue_execute','queue'=>'end','timestamp'=>$started+0.2,'context'=>[]],
		['event'=>'execute','operation'=>'select','location'=>'shop.orders','timestamp'=>$started+0.3,'result_ok'=>false,'context'=>['duration_ms'=>75.0,'statement'=>'SELECT * FROM orders']],
		['event'=>'cache_hit','operation'=>'read','location'=>'orders','timestamp'=>$started+0.32,'context'=>[]],
		['event'=>'ignored','operation'=>'metadata','timestamp'=>$started+0.33,'context'=>[]],
	];
	$sql=['events'=>$sqlEvents,'failed_events'=>1,'slow_events'=>1,'query_events'=>101,'duplicates'=>[['count'=>3]]];
	$routing=['matched_route'=>'orders.approve','matched_at'=>$started+0.01];
	$request=['method'=>'POST','path'=>'/orders','status'=>503];
	$panel=['events'=>$panelEvents,'insights'=>[['level'=>'error','title'=>'Panel failed','detail'=>'Bulk action failed','source'=>'panel']]];
	$reactor=['events'=>$reactorEvents,'insights'=>[['level'=>'warning','title'=>'Reactor invalid','detail'=>'Validation failed','source'=>'reactor']]];
	$timeline=$debugbar->invoke('timeline_state',$started,500.0,$sql,$routing,$request,$runtime,$panel,$reactor);
	$t->greaterThan(7,$timeline['event_count']);
	$t->same('request',$timeline['events'][0]['type']);
	$t->same(['start_ms'=>225.0,'end_ms'=>300.0,'duration_ms'=>75.0],$debugbar->invoke('timeline_event_range',$sqlEvents[2],$started,500.0));
	$waits=$debugbar->invoke('sql_queue_wait_events',$sqlEvents,$started,500.0);
	$t->count(1,$waits);
	$t->same(150.0,$waits[0]['duration_ms']);

	$t->hasPathValues(['request_path'=>'/orders','method'=>'POST'],$debugbar->invoke('routing_state'));
	$requestState=$debugbar->invoke('request_state');
	$t->hasPathValues(['method'=>'POST','scheme'=>'https','host'=>'example.test','path'=>'/orders','query'=>'mode=approve','ajax'=>true,'do_not_track'=>true],$requestState);
	$t->same('[redacted]',$requestState['cookies']['session']);
	$t->same('[redacted]',$requestState['body_params']['password']);
	$t->same('[redacted]',$requestState['session']['token']);

	$t->hasPathValues(['available'=>false,'bytes'=>0,'body_kind'=>'unknown'],$debugbar->invoke('response_state',null));
	$json=$debugbar->invoke('response_state','{"/api/orders":[{"success":true},{"success":false,"error":"inventory"}],"token":"private"}');
	$t->hasPathValues(['body_kind'=>'json','json_valid'=>true,'json_top_level'=>'object','json_batch_route_count'=>1],$json);
	$t->greaterThan(1,$json['json_failure_count']);
	$t->same('[redacted]',$json['json_preview']['token']);
	$invalidJson=$debugbar->invoke('response_state','{"broken":');
	$t->hasPathValues(['body_kind'=>'json','json_valid'=>false],$invalidJson);
	$t->notEmpty($invalidJson['json_error']);
	$html='<!doctype html><html><head><title>Broken &amp; Slow</title><meta charset="UTF-8"><link rel="stylesheet" href="/missing.css"></head><body id="duplicate"><body><div id="duplicate">Something broke on our end Ã¢â‚¬â„¢</div><script src="https://cdn.example.test/app.js"></script><img src="javascript:alert(1)"><form></form></body></html>';
	$htmlState=$debugbar->invoke('response_state',$html);
	$t->hasPathValues(['body_kind'=>'html','is_html'=>true,'title'=>'Broken & Slow','charset'=>'UTF-8','html_tag_count'=>1,'body_tag_count'=>2,'script_count'=>1,'stylesheet_count'=>1,'image_count'=>1,'form_count'=>1],$htmlState);
	$t->notEmpty($htmlState['duplicate_ids']);
	$t->notEmpty($htmlState['suspicious_phrases']);
	$t->same('binary',$debugbar->invoke('response_state',"image\0bytes")['body_kind']);
	$t->isTrue($debugbar->invoke('response_allows_toolbar_markup',['content_type'=>'text/html','is_json'=>false,'body_kind'=>'html'],$html));
	$t->isFalse($debugbar->invoke('response_allows_toolbar_markup',['content_type'=>'application/json','is_json'=>true,'body_kind'=>'json'],'{}'));
	$t->isFalse($debugbar->invoke('response_allows_toolbar_markup',['content_type'=>'text/css','is_json'=>false,'body_kind'=>'text'],'body{}'));
	$t->isFalse($debugbar->invoke('looks_binary',''));
	$t->isTrue($debugbar->invoke('looks_binary',"a\0b"));

	$t->same('[redacted]',$debugbar->invoke('json_preview','private',0,'password'));
	$t->same('[depth-limit]',$debugbar->invoke('json_preview',['nested'=>true],5));
	$t->same('stdClass',$debugbar->invoke('json_preview',new stdClass()));
	$t->same([],$debugbar->invoke('json_batch_routes',[['success'=>true]]));
	$t->same('success',$debugbar->invoke('json_batch_entry_status',['success'=>true]));
	$t->same('failed',$debugbar->invoke('json_batch_entry_status',['errors'=>['bad']]));
	$t->same('failed',$debugbar->invoke('json_batch_entry_status',['success'=>false]));
	$t->same('unknown',$debugbar->invoke('json_batch_entry_status','value'));
	foreach([
		['error','message',true],['success',false,true],['status','errored',true],['errors',[],false],['status','ok',false],
	] as [$key,$value,$expected]){
		$t->same($expected,$debugbar->invoke('json_value_reports_failure',$key,$value));
	}
	$t->isFalse($debugbar->invoke('json_failure_value_is_significant',null));
	$t->isFalse($debugbar->invoke('json_failure_value_is_significant',[]));
	$t->isTrue($debugbar->invoke('json_failure_value_is_significant',['message'=>'bad']));
	$t->same('true',$debugbar->invoke('json_failure_value_label',true));
	$t->same('42',$debugbar->invoke('json_failure_value_label',42));
	$t->contains('message',$debugbar->invoke('json_failure_value_label',['message'=>'bad']));
	$t->same('null',$debugbar->invoke('json_failure_value_label',null));
	$t->isFalse($debugbar->invoke('is_assoc_array',[]));
	$t->isFalse($debugbar->invoke('is_assoc_array',['a','b']));
	$t->isTrue($debugbar->invoke('is_assoc_array',['name'=>'order']));
	$t->hasKey('sql_binding_count',$debugbar->invoke('templating_state'));

	$diagnostics=$debugbar->invoke('diagnostics_state',$sql,[],['status'=>503,'path'=>'/orders'],array_replace($htmlState,[
		'available'=>true,'is_json'=>true,'json_valid'=>true,'json_failure_count'=>1,'json_failure_markers'=>[['path'=>'$.success','value'=>'false']],
		'mojibake_count'=>1,'missing_asset_count'=>1,
	]),[],array_replace($runtime,['memory_limit'=>'1T']),['retroactive_count'=>1],['event_count'=>121],$errors,$panel,$reactor);
	$t->same('error',$diagnostics['worst_level']);
	$t->greaterThan(12,$diagnostics['count']);

	$state=dataphyre_flightdeck_debugbar::state('<!doctype html><html><head><meta charset="UTF-8"></head><body>ok</body></html>');
	$t->hasPathValues(['available'=>true,'method'=>'POST'],$state);
	$t->hasKey('diagnostics',$state);
})->tag('flightdeck','coverage','state','diagnostics')->group('framework-coverage');

test('Flightdeck renders one rich snapshot into a navigable evidence console', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$snapshot=flightdeck_debugbar_rich_snapshot();
	$snapshot['errors']=['events'=>[
		['severity'=>'warning','message'=>'Inventory service unavailable','file'=>__FILE__,'line'=>__LINE__,'timestamp'=>time(),'stack'=>[['function'=>'approve']]],
	]];
	$html=dataphyre_flightdeck_debugbar::render_snapshot_html($snapshot);
	foreach([
		'debugbar-snapshot.css','Request snapshot','Triage','Comparison','Diagnostics','SQL','Timeline','Tracelog','Routing',
		'Panel lifecycle','Reactor','Asset node','Request','Response','Accessibility','Browser','Templating','Runtime','debugbar-snapshot.js',
	] as $visibleContract){
		$t->contains($visibleContract,$html);
	}
	$t->contains('previous-snapshot',$html);
	$t->contains('width_constrained',$html);

	$isolated=$debugbar->invoke('isolate_toolbar_markup','<aside id="toolbar">safe</aside>');
	$t->contains('dataphyre-flightdeck-debugbar-host',$isolated);
	$t->contains('attachShadow',$isolated);
	$toolbar=$debugbar->invoke('markup','<html><body>orders</body></html>',$snapshot);
	$t->contains('Dataphyre Flightdeck',$toolbar);
	$t->contains('debugbar.js',$toolbar);
	$t->contains('action=client_event',$toolbar);

	$t->contains('Runtime',$debugbar->invoke('diagnostic_next_step',['next'=>'Inspect the response panel.']));
	$t->contains('SQL',$debugbar->invoke('diagnostic_next_step',['source'=>'sql','title'=>'Slow query']));
	$t->contains('Request',$debugbar->invoke('diagnostic_next_step',['source'=>'request','title'=>'Failed response']));
	$t->contains('referenced panel',$debugbar->invoke('diagnostic_next_step',['source'=>'client','title'=>'Resource error']));
	$t->contains('referenced panel',$debugbar->invoke('diagnostic_next_step',['source'=>'unknown','title'=>'Unknown']));

	foreach([
		['response','API failure','Inspect response','response'],['sql','Slow query','Inspect query','sql'],
		['routing','No route','Inspect route','routing'],['panel','Panel failed','Inspect action','runtime'],
		['reactor','Validation denied','Inspect component','runtime'],['client','Resource failed','Inspect browser','browser'],
		['runtime','Memory pressure','Inspect runtime','runtime'],
	] as [$source,$title,$next,$target]){
		$t->same($target,$debugbar->invoke('triage_panel_target',$source,$title,$next));
	}
	$t->same('runtime',$debugbar->invoke('valid_panel_target','not-real'));
	$t->same('Response',$debugbar->invoke('panel_label','response'));
	$t->notEmpty($debugbar->invoke('panel_targets'));
	$t->contains('data-dfd-panel-target="response"',$debugbar->invoke('triage_panel_link','Open response','response'));

	$triage=$debugbar->invoke('triage_items',$snapshot,$snapshot['client']);
	$t->greaterThan(5,count($triage));
	$t->contains('data-dfd-panel-target',$debugbar->invoke('triage_reference_links',$triage[0]));
	$t->notEmpty($debugbar->invoke('triage_phases',$snapshot,$snapshot['client']));
	$t->hasPathValues(['key'=>'status','direction'=>'worse'],$debugbar->invoke('comparison_change_by_key',$snapshot['comparison']['changes'],'status'));
	$t->same(null,$debugbar->invoke('comparison_change_by_key',$snapshot['comparison']['changes'],'missing'));

	$timeline=$debugbar->invoke('timeline_with_client_events',$snapshot['timeline'],$snapshot['client']);
	$t->greaterThan(count($snapshot['timeline']['events']),count($timeline));
	$t->hasPathValues(['type'=>'client','source'=>'client','duration_ms'=>125.0],$debugbar->invoke('client_timeline_event',25.0,'client','Browser fetch','/api/orders','bad',125.0));
	$t->contains('width_constrained',$debugbar->invoke('render_accessibility_event_log',$snapshot['client']['events']));
	$t->same(['width_constrained'=>1,'label_stacked'=>1],$debugbar->invoke('accessibility_event_tokens',$snapshot['client']['events'][0]));
	$t->contains('width_constrained',$debugbar->invoke('render_accessibility_token_buttons',['width_constrained'=>2]));
	$t->contains('Issue Types',$debugbar->invoke('render_accessibility_token_summary',['width_constrained'=>1],['label_stacked'=>1]));
	$t->contains('Remediation guidance',$debugbar->invoke('render_accessibility_remediation',['width_constrained'=>1],['label_stacked'=>1]));
	$t->contains('email',$debugbar->invoke('render_accessibility_fields_table',$snapshot['client']['accessibility_issue_fields']));
	$t->contains('email',$debugbar->invoke('render_client_accessibility_event',$snapshot['client']['events'][0]));
	$t->contains('Resource Timing',$debugbar->invoke('render_client_resource_timing',$snapshot['client']['resource_timing']));
	$t->contains('previous-snapshot',$debugbar->invoke('client_event_server_link',$snapshot['client']['events'][1]));
	$t->contains('previous-snapshot',$debugbar->invoke('history_link','previous-snapshot','Previous request'));

	$t->contains('dfd-metric',$debugbar->invoke('metric','Queries','1','Captured SQL','warn','data-test="metric"'));
	$t->contains('dfd-pill',$debugbar->invoke('pill','warning','warn','custom'));
	$t->contains('dfd-pill-label',$debugbar->invoke('status_pill','HTTP','503','bad','custom'));
	$t->contains('data-dfd-action="dock"',$debugbar->invoke('action_button','dock','Dock','&uarr;',['aria-expanded'=>'true']));
	$t->contains('/dataphyre/debugbar',$debugbar->invoke('action_link','/dataphyre/debugbar','Open','&nearr;','custom'));
})->tag('flightdeck','coverage','render','console')->group('framework-coverage');
