<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Flightdeck\TestFixture;

final class RenderScenarios {
	public static function moduleNames(int $count): array {
		$modules=[];
		for($index=1;$index<=$count;$index++){
			$modules[]='module_'.$index;
		}
		return $modules;
	}

	public static function timelineEvents(int $count): array {
		$events=[];
		for($index=1;$index<=$count;$index++){
			$events[]=[
				'offset_ms'=>(float)$index,'duration_ms'=>1.0,'type'=>'probe',
				'label'=>'Timeline event '.$index,'detail'=>'Generated boundary event',
			];
		}
		return $events;
	}

	public static function clientTimelineCatalog(): array {
		$events=['invalid event',['type'=>'page_performance']];
		$types=[
			'resource_error','stylesheet_missing','js_error','unhandled_rejection','client_http_error',
			'client_fetch_error','client_http_slow','slow_resource','resource_timing','production_replay',
			'accessibility_policy','custom_browser_event',
		];
		foreach($types as $index=>$type){
			$events[]=[
				'type'=>$type,'start_time_ms'=>100.0+$index,'message'=>$type.' evidence',
				'url'=>$type==='custom_browser_event' ? '' : '/probe/'.$type,
				'duration_ms'=>$type==='resource_timing' ? 1500.0 : 25.0,
				'response_status'=>$type==='resource_timing' ? 200 : ($type==='production_replay' ? 204 : 0),
				'server_duration_ms'=>$type==='production_replay' ? 80.0 : 0.0,
				'replay_peak_mb'=>$type==='production_replay' ? 24.0 : 0.0,
				'replay_debug_overhead_mb'=>$type==='production_replay' ? 3.5 : 0.0,
				'a11y_issue_count'=>$type==='accessibility_policy' ? 1 : 0,
				'a11y_adjustment_count'=>$type==='accessibility_policy' ? 2 : 0,
			];
		}
		return $events;
	}

	public static function panelLifecycle(): array {
		return [
			'available'=>true,'loaded'=>true,
			'insights'=>['invalid insight',['level'=>'warning','title'=>'Panel warning','detail'=>'Inspect the action.']],
			'category_counts'=>['action'=>2],'operation_counts'=>['approve'=>1],
			'events'=>[array_merge(self::lifecycleEvent(),[
				'event'=>'action.completed','category'=>'action','resource'=>'orders','operation'=>'approve',
			])],
			'resources'=>[['name'=>'orders','label'=>'Orders','source'=>'shop.orders','fields'=>4,'columns'=>3,'relations'=>1,'actions'=>2]],
			'pages'=>[['name'=>'overview']],'widgets'=>[['name'=>'orders_table']],'actions'=>[['name'=>'approve']],
			'navigation'=>[['label'=>'Orders']],'theme'=>['name'=>'operator'],
		];
	}

	public static function reactorLifecycle(): array {
		return [
			'available'=>true,'loaded'=>true,'manifest_error'=>'Manifest warning',
			'insights'=>['invalid insight',['level'=>'warning','title'=>'Reactor warning','detail'=>'Inspect the component.']],
			'capability_counts'=>['actions'=>2],'event_counts'=>['action.completed'=>1],
			'components'=>[
				'invalid component',
				[
					'name'=>'Orders','capabilities'=>['actions'],'state_keys'=>3,'locked'=>1,'computed'=>1,
					'session'=>1,'actions'=>2,'rules'=>1,'bindings'=>['input'=>2],
				],
			],
			'events'=>[array_merge(self::lifecycleEvent(),[
				'event'=>'action.completed','category'=>'action','component'=>'Orders','action'=>'approve',
			])],
			'manifest'=>['version'=>'3.0','component_count'=>1],
		];
	}

	public static function assetNodeState(): array {
		return [
			'available'=>true,'configured'=>true,'can_store'=>true,'current_name'=>'','current_ip'=>'192.0.2.20','server_step'=>2,
			'storage'=>[
				'path'=>'/tmp/assets','disk_path'=>'/tmp','exists'=>true,'total_bytes'=>1000,'free_bytes'=>400,'used_percent'=>60,
			],
			'config'=>[
				'server_count'=>1,'redundancy_level'=>2,'default_protocol'=>'https','default_port'=>0,
				'effective_default_port'=>443,'containerization_size_threshold'=>4096,
			],
			'request'=>[
				'uri'=>'/assets/app.js','params'=>['variant'=>'modern'],'content_probe'=>[
					'decoded_blockpath'=>'app.js','relative_directory'=>'assets','filename'=>'app.js',
					'expected_file'=>'/tmp/assets/app.js','parent_directory'=>'/tmp/assets',
					'parent_exists'=>'true','file_exists'=>'true','is_file'=>'true','is_readable'=>'true','is_link'=>'false',
					'size_bytes'=>4096,
				],
			],
			'servers'=>[
				'invalid server',
				['ip'=>'192.0.2.20','name'=>'edge-yul','datacenter'=>'yul','protocol'=>'https','port'=>443],
			],
			'trace'=>[
				'invalid trace event',
				['offset_ms'=>12.5,'stage'=>'content.local_probe','data'=>['exists'=>'true']],
			],
		];
	}

	public static function responseAudit(): array {
		$marker=['path'=>'$.success','value'=>'false'];
		return [
			'available'=>true,'bytes'=>512,'body_kind'=>'json','is_json'=>true,'json_valid'=>false,
			'json_error'=>'Syntax error','json_top_level'=>'object','json_item_count'=>2,'json_route_count'=>1,
			'json_keys'=>['success','error'],'json_failure_markers'=>[$marker],
			'json_batch_routes'=>[self::batchRoute($marker)],'json_preview'=>['success'=>false],
			'charset'=>'UTF-8','content_type'=>'application/json','title'=>'API response',
			'html_tag_count'=>0,'body_tag_count'=>0,'asset_count'=>1,'resolved_asset_count'=>0,'missing_asset_count'=>1,'remote_asset_count'=>0,
			'suspicious_phrases'=>['Fatal error'],'mojibake_count'=>1,
			'suspicious_assets'=>[[
				'kind'=>'script','issue'=>'local_file_not_found','status'=>'missing','url'=>'/assets/app.js','local_path'=>'/tmp/assets/app.js',
			]],
			'duplicate_ids'=>['dialog-title'=>2],
			'assets'=>[['kind'=>'script','status'=>'missing','url'=>'/assets/app.js','local_path'=>'','expected_mime'=>'application/javascript']],
		];
	}

	public static function browserState(): array {
		$event=[
			'type'=>'accessibility_policy','level'=>'warning','message'=>'Accessibility policy evidence','url'=>'/orders',
			'tag'=>'form','line'=>20,'column'=>4,'method'=>'POST','duration_ms'=>125.0,'response_status'=>422,
			'load_ms'=>2500.0,'dom_content_loaded_ms'=>1200.0,'resource_count'=>8,
			'replay_responded'=>1,'replay_verified'=>1,'replay_production'=>1,'replay_readonly'=>1,
			'server_duration_ms'=>80.0,'replay_peak_mb'=>24.0,'replay_debug_overhead_mb'=>3.5,
			'replay_body_bytes'=>2048,'replay_write_blocks'=>2,'a11y_checked'=>3,'a11y_issue_count'=>1,
			'a11y_adjustment_count'=>1,'a11y_field_source'=>'combined_fields','stack'=>'probe stack',
			'a11y_issues'=>[self::accessibilityField()],'a11y_adjustments'=>[],
		];
		return [
			'event_count'=>1,'events'=>[$event],'linked_server_events'=>0,'last_seen_at'=>1700000000.0,
			'page_performance'=>['load_ms'=>2500.0,'dom_content_loaded_ms'=>1200.0],
			'production_replay'=>[
				'response_status'=>204,'server_duration_ms'=>80.0,'replay_write_blocks'=>2,
				'replay_debug_overhead_mb'=>3.5,'replay_peak_mb'=>24.0,
			],
			'accessibility_issues'=>1,'accessibility_adjustments'=>1,'accessibility_checked'=>3,
			'resource_summary'=>self::resourceTimingSummary(),
		];
	}

	public static function resourceTimingSummary(): array {
		return [
			'count'=>2,'total_transfer_size'=>4096,'total_decoded_size'=>8192,'total_duration_ms'=>1800.0,'max_duration_ms'=>1500.0,
			'by_type'=>[
				'invalid'=>'invalid row',
				'script'=>[
					'count'=>2,'total_duration_ms'=>1800.0,'max_duration_ms'=>1500.0,
					'total_transfer_size'=>4096,'total_decoded_size'=>8192,
				],
			],
			'slowest'=>[
				'invalid resource',
				[
					'start_time_ms'=>10.0,'initiator_type'=>'script','duration_ms'=>1500.0,'response_status'=>500,
					'transfer_size'=>4096,'next_hop_protocol'=>'h2','render_blocking_status'=>'blocking','url'=>'/assets/app.js',
				],
			],
		];
	}

	public static function accessibilityField(): array {
		return [
			'name'=>'email','issues'=>['width_constrained'],'actions'=>['label_stacked'],
			'issue_messages'=>['Field is too narrow.'],'action_messages'=>['Label was stacked.'],
			'usable_width'=>160.0,'required_width'=>320.0,'required_width_source'=>'minimum characters',
			'touch_target_failures'=>2,'table_columns'=>6,'table_available_width'=>800.0,
			'table_applied_width'=>700.0,'table_desired_width'=>900.0,'table_compact_columns'=>2,
			'table_scroll_preserved'=>true,
		];
	}

	private static function lifecycleEvent(): array {
		return [
			'time'=>1700000000.125,'duration_ms'=>25.0,'status'=>200,'context'=>['probe'=>'ready'],
		];
	}

	private static function batchRoute(array $marker): array {
		return [
			'route'=>'/api/orders','status'=>'failed','entries'=>2,'success'=>1,'failed'=>1,
			'keys'=>['success','error'],'failure_markers'=>[$marker],
		];
	}
}
