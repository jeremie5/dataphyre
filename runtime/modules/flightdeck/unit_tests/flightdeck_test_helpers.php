<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__).'/kernel/auth.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

function dp_flightdeck_unit_private(string $method, array $args=[]): mixed {
	$reflection=new ReflectionMethod('dataphyre_flightdeck_debugbar', $method);
	$reflection->setAccessible(true);
	return $reflection->invokeArgs(null, $args);
}

function dp_flightdeck_unit_auth_private(string $method, array $args=[]): mixed {
	$reflection=new ReflectionMethod('dataphyre_flightdeck_auth', $method);
	$reflection->setAccessible(true);
	return $reflection->invokeArgs(null, $args);
}

function dp_flightdeck_unit_csrf_survives_remote_addr_change(): array {
	$previous_remote_addr=$_SERVER['REMOTE_ADDR'] ?? null;
	$_SERVER['REMOTE_ADDR']='198.51.100.10';
	$token=dataphyre_flightdeck_auth::csrf_token();
	$_SERVER['REMOTE_ADDR']='198.51.100.11';
	$valid=dataphyre_flightdeck_auth::verify_csrf($token);
	if($previous_remote_addr===null){
		unset($_SERVER['REMOTE_ADDR']);
	}
	else
	{
		$_SERVER['REMOTE_ADDR']=$previous_remote_addr;
	}
	return ['result'=>[
		'token_length'=>strlen($token),
		'valid_after_ip_change'=>$valid,
		'rejects_empty'=>dataphyre_flightdeck_auth::verify_csrf('')===false,
	]];
}

function dp_flightdeck_unit_auth_accepts_cross_app_toolbar_cookies(): array {
	$bootstrap=defined('DATAPHYRE_BOOTSTRAP_CONFIG') && is_array(DATAPHYRE_BOOTSTRAP_CONFIG)
		? DATAPHYRE_BOOTSTRAP_CONFIG
		: ($GLOBALS['dataphyre_bootstrap_config'] ?? []);
	$default_app=is_array($bootstrap) && is_string($bootstrap['app'] ?? null) && $bootstrap['app']!==''
		? $bootstrap['app']
		: (defined('APP') ? (string)APP : 'demo_app');
	$previous_bootstrap=$GLOBALS['dataphyre_bootstrap_config'] ?? null;
	if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')){
		$GLOBALS['dataphyre_bootstrap_config']=array_replace(is_array($previous_bootstrap) ? $previous_bootstrap : [], ['app'=>$default_app]);
	}
	$auth_project=dp_flightdeck_unit_auth_private('cookie_secret');
	$auth_legacy=dp_flightdeck_unit_auth_private('cookie_secret_for_app', [$default_app]);
	$auth_candidates=dp_flightdeck_unit_auth_private('cookie_secrets');
	$debugbar_project=dp_flightdeck_unit_private('secret');
	$debugbar_legacy=dp_flightdeck_unit_private('secret_for_app', [$default_app]);
	$debugbar_candidates=dp_flightdeck_unit_private('secret_candidates');
	if(!defined('DATAPHYRE_BOOTSTRAP_CONFIG')){
		if($previous_bootstrap===null){
			unset($GLOBALS['dataphyre_bootstrap_config']);
		}
		else
		{
			$GLOBALS['dataphyre_bootstrap_config']=$previous_bootstrap;
		}
	}
	return ['result'=>[
		'default_app'=>$default_app,
		'auth_project_differs_from_legacy'=>$auth_project!==$auth_legacy,
		'auth_accepts_legacy_default_app_cookie'=>in_array($auth_legacy, $auth_candidates, true),
		'debugbar_project_differs_from_legacy'=>$debugbar_project!==$debugbar_legacy,
		'debugbar_accepts_legacy_default_app_cookie'=>in_array($debugbar_legacy, $debugbar_candidates, true),
	]];
}

function dp_flightdeck_unit_format_samples(): array {
	return ['result'=>[
		'ms'=>dp_flightdeck_unit_private('format_ms', [42.25]),
		'seconds'=>dp_flightdeck_unit_private('format_ms', [1500.0]),
		'bytes'=>dp_flightdeck_unit_private('format_bytes', [512]),
		'kilobytes'=>dp_flightdeck_unit_private('format_bytes', [2048]),
		'megabytes'=>dp_flightdeck_unit_private('format_bytes', [1572864]),
		'short'=>dp_flightdeck_unit_private('shorten', ['alpha  beta	 gamma', 20]),
		'truncated'=>dp_flightdeck_unit_private('shorten', ['abcdefghijklmnopqrstuvwxyz', 10]),
	]];
}

function dp_flightdeck_unit_sanitize_samples(): array {
	return ['result'=>dp_flightdeck_unit_private('sanitize_value', [[
		'username'=>'ada',
		'api_key'=>'secret-value',
		'nested'=>[
			'csrf_token'=>'token-value',
			'count'=>3,
		],
		'object'=>(object)['x'=>1],
	]])];
}

function dp_flightdeck_unit_response_json_summary(): string {
	$payload=json_encode([
		'/api/orders'=>[
			['success'=>true, 'id'=>1],
			['success'=>false, 'error'=>'Inventory missing'],
		],
		'/api/customers'=>[
			'status'=>'failed',
			'errors'=>['missing email'],
		],
		'api_key'=>'secret-value',
	], JSON_UNESCAPED_SLASHES);
	$response=dp_flightdeck_unit_private('response_state', [$payload]);
	$routes=$response['json_batch_routes'] ?? [];

	return json_encode([
		'body_kind'=>$response['body_kind'] ?? '',
		'is_json'=>$response['is_json'] ?? false,
		'json_valid'=>$response['json_valid'] ?? false,
		'top_level'=>$response['json_top_level'] ?? '',
		'key_count'=>$response['json_key_count'] ?? 0,
		'item_count'=>$response['json_item_count'] ?? 0,
		'batch_route_count'=>$response['json_batch_route_count'] ?? 0,
		'first_route'=>$routes[0]['route'] ?? '',
		'first_status'=>$routes[0]['status'] ?? '',
		'first_failed'=>$routes[0]['failed'] ?? null,
		'second_route'=>$routes[1]['route'] ?? '',
		'second_status'=>$routes[1]['status'] ?? '',
		'failure_count'=>$response['json_failure_count'] ?? 0,
		'preview_api_key'=>$response['json_preview']['api_key'] ?? '',
	], JSON_UNESCAPED_SLASHES);
}

function dp_flightdeck_unit_history_keys(): array {
	return [
		dp_flightdeck_unit_private('comparable_snapshot_key', [[
			'app'=>'shop',
			'method'=>'post',
			'request'=>[
				'path'=>'//checkout//submit',
			],
		]]),
		dp_flightdeck_unit_private('comparable_snapshot_key', [[
			'app'=>'docs',
			'uri'=>'https://example.test/dataphyre/docs?tab=unit',
			'request'=>[
				'method'=>'GET',
			],
		]]),
	];
}

function dp_flightdeck_unit_snapshot_metrics(): array {
	$snapshot=[
		'request'=>['status'=>503],
		'duration_ms'=>123.5,
		'sql'=>['query_events'=>7, 'total_duration_ms'=>45.25],
		'diagnostics'=>['count'=>2],
		'client'=>[
			'resource_errors'=>1,
			'stylesheet_missing'=>1,
			'js_errors'=>2,
			'page_performance'=>['load_ms'=>987.6],
		],
		'response'=>[
			'missing_asset_count'=>4,
			'json_failure_count'=>1,
			'bytes'=>2048,
		],
		'memory_mb'=>18.75,
	];
	return ['result'=>[
		'status'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'status']),
		'duration_ms'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'duration_ms']),
		'sql_queries'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'sql_queries']),
		'sql_time_ms'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'sql_time_ms']),
		'findings'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'findings']),
		'browser_events'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'browser_events']),
		'browser_load_ms'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'browser_load_ms']),
		'missing_assets'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'missing_assets']),
		'api_failures'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'api_failures']),
		'memory_mb'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'memory_mb']),
		'body_bytes'=>dp_flightdeck_unit_private('snapshot_metric_value', [$snapshot, 'body_bytes']),
	]];
}

function dp_flightdeck_unit_accessibility_client_event(): array {
	$events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'level'=>'warning',
		'message'=>'Panel accessibility policies reported 1 field needing attention.',
		'a11y_checked'=>2,
		'a11y_issue_count'=>1,
		'a11y_adjustment_count'=>1,
		'a11y_status'=>'needs_attention',
		'a11y_issues'=>[[
			'name'=>'sku',
			'label'=>'SKU',
			'issues'=>['width_constrained'],
			'issue_messages'=>['Usable width 120px is below required 320px.'],
			'usable_width'=>120,
			'required_width'=>320,
			'required_width_source'=>'px',
		]],
		'a11y_adjustments'=>[[
			'name'=>'title',
			'actions'=>['label_stacked'],
			'action_messages'=>['Label stacked to preserve usable control width.'],
		]],
	]]]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$events]);
	$diagnostics=dp_flightdeck_unit_private('with_client_diagnostics', [['findings'=>[]], $client]);
	return ['result'=>[
		'type'=>$events[0]['type'] ?? '',
		'issues'=>$client['accessibility_issues'] ?? null,
		'adjustments'=>$client['accessibility_adjustments'] ?? null,
		'checked'=>$client['accessibility_checked'] ?? null,
		'field'=>$client['accessibility_issue_fields'][0]['name'] ?? '',
		'issue_token_count'=>$client['accessibility_issue_tokens']['width_constrained'] ?? null,
		'action_token_count'=>$client['accessibility_action_tokens']['label_stacked'] ?? null,
		'worst'=>$diagnostics['worst_level'] ?? '',
		'finding'=>$diagnostics['findings'][0]['title'] ?? '',
	]];
}

function dp_flightdeck_unit_accessibility_client_event_fields_fallback(): array {
	$a11y_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'a11y_checked'=>2,
		'a11y_fields'=>[
			[
				'name'=>'email',
				'issues'=>['width_constrained'],
				'issue_messages'=>['Usable width is below policy.'],
			],
			[
				'name'=>'sku',
				'actions'=>['width_expanded'],
				'action_messages'=>['Field expanded to satisfy usable width policy.'],
			],
		],
	]]]);
	$preferred_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>2,
		'issues'=>[[
			'name'=>'country',
			'issues'=>['contrast_fail'],
			'issue_messages'=>['Contrast ratio is below policy.'],
		]],
		'adjustments'=>[[
			'name'=>'reference',
			'actions'=>['label_stacked'],
			'action_messages'=>['Label stacked to preserve usable control width.'],
		]],
	]]]);
	$plain_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>3,
		'issue_count'=>1,
		'adjustment_count'=>0,
		'status'=>'needs_attention',
		'fields'=>[[
			'name'=>'phone',
			'issues'=>['touch_target_fail'],
			'issue_messages'=>['Touch target is too small.'],
		]],
	]]]);
	$adjusted_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>1,
		'fields'=>[[
			'name'=>'sku',
			'actions'=>['width_expanded'],
			'action_messages'=>['Field expanded to satisfy usable width policy.'],
		]],
	]]]);
	$pass_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>2,
		'fields'=>[
			['name'=>'email'],
			['name'=>'sku'],
		],
	]]]);
	$unknown_source_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>1,
		'a11y_field_source'=>'surprise_shape',
		'issues'=>[[
			'name'=>'locale',
			'issues'=>['contrast_fail'],
		]],
	]]]);
	$unknown_combined_source_events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'checked'=>1,
		'a11y_field_source'=>'surprise_shape',
		'fields'=>[[
			'name'=>'warehouse',
			'issues'=>['width_constrained'],
		]],
	]]]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$a11y_events]);
	$preferred_client=dp_flightdeck_unit_private('client_state_from_events', [$preferred_events]);
	$plain_client=dp_flightdeck_unit_private('client_state_from_events', [$plain_events]);
	$adjusted_client=dp_flightdeck_unit_private('client_state_from_events', [$adjusted_events]);
	$pass_client=dp_flightdeck_unit_private('client_state_from_events', [$pass_events]);
	$unknown_source_client=dp_flightdeck_unit_private('client_state_from_events', [$unknown_source_events]);
	$unknown_combined_source_client=dp_flightdeck_unit_private('client_state_from_events', [$unknown_combined_source_events]);
	return ['result'=>[
		'issues'=>$a11y_events[0]['a11y_issue_count'] ?? null,
		'adjustments'=>$a11y_events[0]['a11y_adjustment_count'] ?? null,
		'source'=>$a11y_events[0]['a11y_field_source'] ?? '',
		'issue_field'=>$client['accessibility_issue_fields'][0]['name'] ?? '',
		'adjustment_field'=>$client['accessibility_adjustment_fields'][0]['name'] ?? '',
		'issue_token_count'=>$client['accessibility_issue_tokens']['width_constrained'] ?? null,
		'action_token_count'=>$client['accessibility_action_tokens']['width_expanded'] ?? null,
		'preferred_issue'=>$preferred_client['accessibility_issue_fields'][0]['name'] ?? '',
		'preferred_adjustment'=>$preferred_client['accessibility_adjustment_fields'][0]['name'] ?? '',
		'preferred_status'=>$preferred_client['accessibility_latest']['a11y_status'] ?? '',
		'preferred_source'=>$preferred_client['accessibility_latest']['a11y_field_source'] ?? '',
		'plain_field'=>$plain_client['accessibility_issue_fields'][0]['name'] ?? '',
		'plain_token_count'=>$plain_client['accessibility_issue_tokens']['touch_target_fail'] ?? null,
		'plain_checked'=>$plain_client['accessibility_checked'] ?? null,
		'plain_status'=>$plain_client['accessibility_latest']['a11y_status'] ?? '',
		'adjusted_status'=>$adjusted_client['accessibility_latest']['a11y_status'] ?? '',
		'adjusted_field'=>$adjusted_client['accessibility_adjustment_fields'][0]['name'] ?? '',
		'pass_status'=>$pass_client['accessibility_latest']['a11y_status'] ?? '',
		'pass_checked'=>$pass_client['accessibility_checked'] ?? null,
		'unknown_source'=>$unknown_source_client['accessibility_latest']['a11y_field_source'] ?? '',
		'unknown_combined_source'=>$unknown_combined_source_client['accessibility_latest']['a11y_field_source'] ?? '',
	]];
}

function dp_flightdeck_unit_accessibility_fields_fallback_render_note(): array {
	$events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'a11y_checked'=>1,
		'a11y_fields'=>[[
			'name'=>'email',
			'issues'=>['width_constrained'],
			'issue_messages'=>['Usable width is below policy.'],
		]],
	]]]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$events]);
	$html=dp_flightdeck_unit_private('render_accessibility_panel', [$client]);
	return ['result'=>[
		'has_combined_note'=>str_contains($html, '(combined fields)'),
		'has_issue_row'=>str_contains($html, 'email') && str_contains($html, 'width_constrained'),
	]];
}

function dp_flightdeck_unit_accessibility_adjusted_status_render(): array {
	$events=dp_flightdeck_unit_private('normalize_client_events', [[[
		'type'=>'accessibility_policy',
		'a11y_checked'=>1,
		'a11y_adjustment_count'=>1,
		'a11y_status'=>'adjusted',
		'a11y_adjustments'=>[[
			'name'=>'sku',
			'actions'=>['width_expanded'],
			'action_messages'=>['Field expanded to satisfy usable width policy.'],
		]],
	]]]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$events]);
	$html=dp_flightdeck_unit_private('render_accessibility_panel', [$client]);
	return ['result'=>[
		'has_adjusted_status'=>str_contains($html, 'adjusted'),
		'has_adjustment_row'=>str_contains($html, 'sku') && str_contains($html, 'width_expanded'),
		'has_adjusted_metric'=>str_contains($html, 'Adjusted Fields') && str_contains($html, '>1<'),
	]];
}

function dp_flightdeck_unit_accessibility_probe_script(): array {
	$script=dp_flightdeck_unit_private('client_probe_script', ['snapshot-test', 'token-test']);
	return ['result'=>[
		'has_flush_timer'=>str_contains($script, 'var accessibilityFlushTimer=0;'),
		'flushes_accessibility_policy'=>str_contains($script, 'event.type==="accessibility_policy"') && str_contains($script, 'setTimeout(function(){') && str_contains($script, 'flush();'),
		'listens_document'=>str_contains($script, 'document.addEventListener("DataphyrePanelAccessibilityPolicy"'),
		'listens_window'=>str_contains($script, 'window.addEventListener("DataphyrePanelAccessibilityPolicy"'),
		'accepts_fields_fallback'=>str_contains($script, 'Array.isArray(detail.fields)') && str_contains($script, 'fallbackFields.filter'),
		'marks_combined_fields'=>str_contains($script, 'fieldSource="combined_fields"') && str_contains($script, 'a11y_field_source:fieldSource'),
		'renders_combined_note'=>str_contains($script, '(combined fields)'),
		'summarizes_computed_counts'=>str_contains($script, 'accessibilitySummaryMessage({issue_count:issueCount, adjustment_count:adjustmentCount})'),
		'infers_adjusted_status'=>str_contains($script, 'adjustmentCount>0 ? "adjusted" : "pass"'),
		'renders_status_pill'=>str_contains($script, 'function accessibilityStatusPill') && str_contains($script, 'dfd-pill'),
	]];
}

function dp_flightdeck_unit_accessibility_preserves_issue_rows(): array {
	$raw=[
		[
			'type'=>'accessibility_policy',
			'level'=>'warning',
			'message'=>'Panel accessibility policies reported 1 field needing attention.',
			'a11y_checked'=>1,
			'a11y_issue_count'=>1,
			'a11y_adjustment_count'=>0,
			'a11y_status'=>'needs_attention',
			'a11y_issues'=>[[
				'name'=>'postal_code',
				'label'=>'Postal code',
				'issues'=>['touch_target_fail'],
				'issue_messages'=>['Touch target is too small.'],
				'touch_target_failures'=>1,
			]],
			'a11y_adjustments'=>[],
		],
		[
			'type'=>'accessibility_policy',
			'level'=>'info',
			'message'=>'Panel accessibility policies passed.',
			'a11y_checked'=>0,
			'a11y_issue_count'=>0,
			'a11y_adjustment_count'=>0,
			'a11y_status'=>'pass',
			'a11y_issues'=>[],
			'a11y_adjustments'=>[],
		],
	];
	$events=dp_flightdeck_unit_private('normalize_client_events', [$raw]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$events]);
	return ['result'=>[
		'events'=>$client['accessibility_policy_events'] ?? null,
		'issues'=>$client['accessibility_issues'] ?? null,
		'field'=>$client['accessibility_issue_fields'][0]['name'] ?? '',
		'token'=>$client['accessibility_issue_tokens']['touch_target_fail'] ?? null,
		'latest_status'=>$client['accessibility_latest']['a11y_status'] ?? '',
	]];
}

function dp_flightdeck_unit_accessibility_retained_note(): array {
	$raw=[
		[
			'type'=>'accessibility_policy',
			'a11y_checked'=>1,
			'a11y_issue_count'=>1,
			'a11y_status'=>'needs_attention',
			'a11y_issues'=>[[
				'name'=>'postal_code',
				'issues'=>['touch_target_fail'],
				'issue_messages'=>['Touch target is too small.'],
			]],
		],
		[
			'type'=>'accessibility_policy',
			'a11y_checked'=>0,
			'a11y_issue_count'=>0,
			'a11y_adjustment_count'=>0,
			'a11y_status'=>'pass',
			'message'=>'Panel accessibility policies passed.',
		],
	];
	$events=dp_flightdeck_unit_private('normalize_client_events', [$raw]);
	$client=dp_flightdeck_unit_private('client_state_from_events', [$events]);
	$html=dp_flightdeck_unit_private('render_accessibility_panel', [$client]);
	return ['result'=>[
		'has_retained_note'=>str_contains($html, 'data-dfd-accessibility-retained-note') && str_contains($html, 'Latest policy report passed without field rows'),
		'has_field_row'=>str_contains($html, 'postal_code'),
		'latest_message'=>str_contains($html, 'Panel accessibility policies passed.'),
	]];
}
