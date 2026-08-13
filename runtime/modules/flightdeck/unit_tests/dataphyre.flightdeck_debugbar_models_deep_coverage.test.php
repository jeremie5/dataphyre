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

require_once dirname(__DIR__).'/kernel/debugbar.php';

test('Flightdeck SQL flight recorder groups repeated queries cache pressure and mixed read-write targets', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$events=[];
	for($index=0;$index<20;$index++){
		$events[]=$debugbar->invoke('normalize_sql_event',[
			'source'=>'kernel','event'=>'execute','operation'=>'select','location'=>'shop.orders',
			'cluster'=>'primary','dbms'=>'sqlite','result_ok'=>$index!==19,
			'statement'=>'SELECT * FROM shop.orders WHERE id = '.($index+1),
			'vars'=>[$index+1],'duration_ms'=>60.0+$index,
			'timestamp'=>1000.0+$index,
			'caller'=>['file'=>'OrderRepository.php','line'=>42,'call'=>'load'],
			'template_name'=>'orders.tpl','binding_name'=>'order_row',
		]);
	}
	foreach(['insert','update','delete'] as $index=>$operation){
		$events[]=$debugbar->invoke('normalize_sql_event',[
			'event'=>'execute','operation'=>$operation,'location'=>'shop.orders','cluster'=>'primary','dbms'=>'sqlite','result_ok'=>true,
			'statement'=>strtoupper($operation).' shop.orders SET status = ?',
			'duration_ms'=>10.0+$index,'timestamp'=>1100.0+$index,
		]);
	}
	for($index=0;$index<6;$index++){
		$events[]=$debugbar->invoke('normalize_sql_event',[
			'event'=>'cache_miss','operation'=>'select','location'=>'shop.orders','cache_type'=>'session',
			'cache_names'=>['orders.by_id'],'timestamp'=>1200.0+$index,
		]);
	}
	$events[]=$debugbar->invoke('normalize_sql_event',[
		'event'=>'cache_hit','operation'=>'select','location'=>'shop.orders','cache_type'=>'session',
		'cache_names'=>['orders.by_id'],'timestamp'=>1210.0,
	]);
	$events[]=$debugbar->invoke('normalize_sql_event',[
		'event'=>'cache_invalidate','operation'=>'update','location'=>'shop.orders','cache_type'=>'session',
		'invalidation_names'=>['orders.by_id'],'timestamp'=>1211.0,
	]);

	$t->same(null,$debugbar->invoke('normalize_sql_event','unsupported'));
	$serializable=new class implements JsonSerializable {
		public function jsonSerialize(): array { return ['event'=>'execute','operation'=>'count','statement'=>'SELECT COUNT(*) FROM shop.orders']; }
	};
	$t->same('count',$debugbar->invoke('normalize_sql_event',$serializable)['operation']);
	$convertible=new class {
		public function toArray(): array { return ['event'=>'queue_push','operation'=>'insert','queued'=>true]; }
	};
	$t->same('queue_push',$debugbar->invoke('normalize_sql_event',$convertible)['event']);

	$targets=$debugbar->invoke('sql_target_summary',$events);
	$operations=$debugbar->invoke('sql_operation_summary',$events);
	$caches=$debugbar->invoke('sql_cache_summary',$events);
	$duplicates=$debugbar->invoke('duplicate_sql_events',$events);
	$slowest=$debugbar->invoke('slowest_sql_events',$events,5);
	$insights=$debugbar->invoke('sql_insights',$events,$duplicates,$targets,$caches);
	$t->hasPathValues([
		'0.target'=>'shop.orders','0.execute_count'=>23,'0.failed_count'=>1,
	],$targets);
	$t->notEmpty($operations);
	$t->hasPathValues(['0.target'=>'shop.orders','0.misses'=>6,'0.hits'=>1,'0.invalidations'=>1],$caches);
	$t->same(20,$duplicates[0]['count']);
	$t->count(5,$slowest);
	$t->containsAll(['Likely repeated lookup','Template binding loop','Hot SQL target','Cache miss pressure','Read/write mix on one target'],array_column($insights,'title'));

	$t->notEmpty($debugbar->invoke('sql_template_binding_groups',$events));
	$t->greaterThanOrEqual(1,count($debugbar->invoke('sql_read_write_targets',$targets)));
	$t->same('shop.orders',$debugbar->invoke('sql_statement_target','SELECT * FROM shop.orders'));
	$t->same('shop.orders',$debugbar->invoke('sql_statement_target','INSERT INTO `shop.orders` VALUES (1)'));
	$t->same('raw sql',$debugbar->invoke('sql_statement_target','PRAGMA table_info'));
	$t->same('', $debugbar->invoke('sql_statement_target',''));
	$t->same('select 4 / update 2',$debugbar->invoke('count_map_label',['update'=>2,'select'=>4],5));
	$t->same('none',$debugbar->invoke('count_map_label',[],5));
	$t->same($debugbar->invoke('sql_shape_key',"SELECT * FROM users WHERE id=1"),$debugbar->invoke('sql_shape_key',"select * from users where id=99"));
	$t->same('', $debugbar->invoke('sql_shape_key',''));
	$t->same('custom label',$debugbar->invoke('caller_label',['label'=>' custom label ']));
	$t->contains('OrderRepository.php:42',$debugbar->invoke('caller_label',['file'=>'/src/OrderRepository.php','line'=>42,'call'=>'load']));
	$t->same('', $debugbar->invoke('caller_label','invalid'));

	$sql=[
		'events'=>$events,'query_events'=>23,'execute_events'=>23,'queued_events'=>1,'queue_execute_events'=>1,
		'total_duration_ms'=>1400.0,'cache_hits'=>1,'cache_misses'=>6,'cache_stores'=>0,'cache_invalidations'=>1,
		'slowest'=>$slowest,'duplicates'=>$duplicates,'target_summary'=>$targets,
		'operation_summary'=>$operations,'cache_summary'=>$caches,'insights'=>$insights,
	];
	$html=$debugbar->invoke('render_sql_panel',$sql);
	$t->containsAll(['SQL Flight Recorder','Likely repeated lookup','Target heatmap','Operation mix','Cache map','Repeated query shapes','Slowest queries','shop.orders'],$html);
	$t->contains('No SQL trace events captured',$debugbar->invoke('render_sql_panel',['events'=>[]]));
})->tag('flightdeck','coverage','sql-flight-recorder')->group('framework-coverage');

test('Flightdeck Tracelog normalizes legacy rows and renders bounded call graphs with safe inline markup', static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->globalMap('_SERVER')->replace(['REQUEST_TIME_FLOAT'=>1000.0]);
	$retroactive=$debugbar->invoke('normalize_retroactive_trace_entry',[
		'/app/Order.php',42,'OrderService','load','Loaded order','function_call_with_test',
		['id'=>42,true,1.25,null],1000.125,2048,
	],0);
	$t->hasPathValues([
		'origin'=>'retroactive','call'=>'OrderService::load','call_kind'=>'FCwT','offset_ms'=>125.0,
	],$retroactive);
	$t->containsAll(['Integer(42)','True','Float(1.25)','Null'],$retroactive['parameter_shape']);
	$t->same('plain message',$debugbar->invoke('normalize_retroactive_trace_entry','plain message',1)['message']);
	$t->same(null,$debugbar->invoke('normalize_retroactive_trace_entry',null,2));

	$liveHtml='<span title="/app/Order.php">Order.php</span>: 42 : <b>125.5ms, 2mb &gt;</b> <i style="color:cyan">trace</i> &gt; <b>FC: <span style="color:#00ffaa">OrderService::load</span>(42)</b><br>'
		.'Order.php:43: <b style="color:orange">Slow query warning</b><br>'
		.'<script>alert(1)</script>';
	$live=$debugbar->invoke('live_trace_entries',$liveHtml,'live');
	$t->greaterThanOrEqual(2,count($live));
	$t->hasPathValues(['0.origin'=>'live','0.file'=>'/app/Order.php','0.line'=>'42','0.call'=>'OrderService::load'],$live);
	$t->contains('Slow query warning',$debugbar->invoke('trace_message_html',$liveHtml));
	$t->contains('&lt;script&gt;',htmlspecialchars('<script>'));
	$t->contains('<span style="color:#00ffaa">safe</span>',$debugbar->invoke('trace_inline_html','<span style="color:#00ffaa">safe</span><img src=x onerror=1>'));
	$t->same('#abc',$debugbar->invoke('trace_span_color','style="color:#abc"'));
	$t->same('rgba(1, 2, 3, .5)',$debugbar->invoke('trace_span_color','style="color:rgba(1, 2, 3, .5)"'));
	$t->same('red',$debugbar->invoke('trace_span_color','style="color:red"'));
	$t->same('', $debugbar->invoke('trace_span_color','style="color:url(javascript:x)"'));
	$t->same('FCwT',$debugbar->invoke('trace_call_kind','function_call_with_test'));
	$t->same('FC',$debugbar->invoke('trace_call_kind','info','OrderService::load'));
	$t->same('', $debugbar->invoke('trace_call_kind','info'));
	$t->same('#00ffaa',$debugbar->invoke('trace_call_color_from_html','<span style="color:#00ffaa">OrderService::load</span>','OrderService::load'));
	$t->same('', $debugbar->invoke('trace_call_color',''));
	$t->contains('hsl(',$debugbar->invoke('trace_call_color','OrderService::load'));

	$t->same('"word"',$debugbar->invoke('trace_parameter_shape_value','word'));
	$t->same('Array',$debugbar->invoke('trace_parameter_shape_value',[]));
	$t->same('True',$debugbar->invoke('trace_parameter_shape_value',true));
	$t->same('False',$debugbar->invoke('trace_parameter_shape_value',false));
	$t->same('Integer(12)',$debugbar->invoke('trace_parameter_shape_value',12));
	$t->same('Float(1.25)',$debugbar->invoke('trace_parameter_shape_value',1.25));
	$t->same('Null',$debugbar->invoke('trace_parameter_shape_value',null));
	$t->same('Object',$debugbar->invoke('trace_parameter_shape_value',new stdClass()));
	$t->same('', $debugbar->invoke('trace_parameter_shape',[]));
	$t->same('42, true',$debugbar->invoke('trace_parameter_shape_from_message','FC: OrderService::load(42, true)','OrderService::load'));
	$t->same('', $debugbar->invoke('trace_parameter_shape_from_message','no parameters'));
	$t->same("first\nsecond",$debugbar->invoke('trace_plain_text',' first <br> second '));
	foreach(['fatal','error','warning','info','function_call','function_call_with_test'] as $level){
		$t->same($level,$debugbar->invoke('trace_level',$level));
	}
	$t->same('info',$debugbar->invoke('trace_level','unknown'));
	$t->same('fatal',$debugbar->invoke('trace_level_from_html','color:red'));
	$t->same('error',$debugbar->invoke('trace_level_from_html','color:pink'));
	$t->same('warning',$debugbar->invoke('trace_level_from_html','color:orange'));
	$t->same('function_call_with_test',$debugbar->invoke('trace_level_from_html','FCwT: call'));
	$t->same('function_call',$debugbar->invoke('trace_level_from_html','FC: call'));
	$t->same(null,$debugbar->invoke('trace_offset_ms',null));
	$t->same(125.0,$debugbar->invoke('trace_offset_ms',1000.125));

	$frames=[
		[
			['file'=>'/app/Order.php','line'=>42,'class'=>'OrderService','function'=>'load','args'=>[42],'time'=>'1'],
			['file'=>'/app/Controller.php','line'=>10,'class'=>'OrderController','function'=>'show','args'=>[],'time'=>'1'],
		],
		[
			['file'=>'/app/Order.php','line'=>42,'class'=>'OrderService','function'=>'load','args'=>[43],'time'=>'2'],
			['file'=>'/app/Controller.php','line'=>10,'class'=>'OrderController','function'=>'show','args'=>[],'time'=>'2'],
		],
	];
	$plot=$debugbar->invoke('trace_plot_from_frames',$frames,'plotting_file');
	$t->hasPathValues(['source'=>'plotting_file','frame_count'=>2,'node_count'=>2,'link_count'=>1],$plot);
	$t->same('', $debugbar->invoke('trace_plot_node_id',['function'=>'']));
	$t->notEmpty($debugbar->invoke('trace_plot_node_id',['class'=>'N/A','function'=>'load','file'=>'x','line'=>1]));
	$flat=$debugbar->invoke('trace_plot_from_entries',[$retroactive,['call'=>'OrderRepository::find','file'=>'/app/Repo.php','line'=>8,'offset_ms'=>130]],'trace_rows');
	$t->same(2,$flat['node_count']);
	$t->same(0,$debugbar->invoke('trace_plot_trim',[],[],[],0,'empty')['node_count']);
	$t->containsAll(['Call graph','OrderService::load'],$debugbar->invoke('render_trace_plot',$plot));
	$t->same('', $debugbar->invoke('render_trace_plot',[]));
	$t->containsAll(['Tracelog','Current request tracelog','OrderService::load'],$debugbar->invoke('render_tracelog_panel',[
		'entry_count'=>count($live)+1,'live_bytes'=>strlen($liveHtml),'entries'=>[$retroactive],
		'live_entries'=>$live,'session_entries'=>[],'plot'=>$plot,
	]));
	$t->contains('No Tracelog rows were captured',$debugbar->invoke('render_tracelog_panel',[]));
})->tag('flightdeck','coverage','tracelog')->group('framework-coverage');
