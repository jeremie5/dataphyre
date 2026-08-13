<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpClosedWorldBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpClosedWorldTestKit.php';

$contracts=[];
foreach(McpClosedWorldBoundaryHarness::contractNames() as $contract){$contracts[$contract]=[$contract];}
dataset('MCP closed-world boundary contracts',$contracts);

suite('MCP closed-world semantic boundaries')
	->tag('mcp','closed-world','boundary','contract','enterprise','app-builder','schema','routing','diagnostics','verification')
	->group('framework-coverage')
	->contract('mcp.closed-world.semantic-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('named domain matrices','non-public boundary harness','malformed fixtures','semantic assertions')
	->isolation('process');

test('each defensive branch is explained by a named domain contract',static function(Context $t,string $name): void {
	$contract=(new McpClosedWorldBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);
	if(str_starts_with($name,'registry validation')){
		$t->contains('unknown argument modul',$contract['near_typo']);
		$t->contains('Did you mean module?',$contract['near_typo']);
		$t->contains('unknown argument unrelated_distant_argument',$contract['distant_typo']);
		$t->isFalse(str_contains($contract['distant_typo'],'Did you mean'));
		$t->contains('Unknown Dataphyre tool',$contract['unknown_dispatch']);
		$t->same('text/markdown',$contract['doc_resource']['mime_type']);
		$t->isTrue($contract['doc_resource']['contains_heading']);
		return;
	}
	if(str_starts_with($name,'bounded PHP source')){
		$t->same(['applications/demo/backend/dataphyre/src/Controller/OrderController.php'],$contract['selected']);
		$t->same(1,$contract['controllers']['controller_count']);
		$t->same(2,$contract['controllers']['controllers'][0]['action_count']);
		$t->same(1,$contract['controllers']['handler_reference_count']);
		$t->same(1,$contract['middleware']['middleware_class_count']);
		$t->same(1,$contract['middleware']['config_surface_count']);
		$t->same(['dataphyre/runtime/modules/mvc/Framework/Mvc.php'],$contract['mvc_config']['config_files']);
		$t->same(['config','routes'],array_column($contract['mvc_config']['classes'][0]['public_methods'],'name'));
		$t->contains('array_file_and_sources_keys',$contract['mvc_config']['config_contract']['manifest_cache_forms']);
		$t->contains('array with file key and optional dependency sources',$contract['route_cache']['manifest_cache_contract']['forms']);
		$t->contains('manifest_cache.sources dependency mtimes',$contract['route_cache']['manifest_cache_contract']['signature_inputs']);
		return;
	}
	if(str_starts_with($name,'filesystem iterator')){
		$t->same([],$contract['files']);
		return;
	}
	if(str_starts_with($name,'enterprise audits')){
		$t->same([
			'hot path'=>'dataphyre_hot_path_candidate','application extension'=>'application_extension',
			'MCP control plane'=>'framework_control_plane','docs control plane'=>'framework_control_plane',
			'reusable contract'=>'dataphyre_reusable_contract','install extension'=>'install_extension_layer',
			'release review'=>'release_claim_review','needs context'=>'needs_context',
		],$contract['classification_lanes']);
		$t->isTrue($contract['benchmark_required']);
		$t->same(['runtime review'=>'review_extension_boundary','config'=>'config','plugin'=>'install_plugins','documented module'=>'dialbacks_callbacks','reusable module'=>'reusable_module_contract'],$contract['strategy_lanes']);
		$t->same('ready_to_claim',$contract['ready_evidence']['status']);
		$t->same('collect_missing_evidence',$contract['focused_evidence']['status']);
		$t->same(['absolute_or_home_path','machine_local_reference','hardcoded_url','url_secret_parameter'],$contract['portability_signals']);
		$t->isFalse($contract['module_evidence']['missing']['known']);
		$t->isTrue($contract['module_evidence']['known']['known']);
		$t->same('application_performance_tuning',$contract['proportional_guidance']['application performance']['signals'][0]);
		$t->same('ready_to_claim',$contract['internal_ready_audit']['claim_summary']['disposition']);
		return;
	}
	if(str_starts_with($name,'compact app-builder')){
		$t->isFalse(isset($contract['compacted_skeletons']['code_skeletons']));
		$t->isFalse(isset($contract['compacted_skeletons']['data_model'][1]['code_skeletons']));
		$t->isFalse($contract['data_model']['empty']['has_data_model_artifacts']);
		$t->isTrue($contract['data_model']['mixed']['has_data_model_artifacts']);
		$t->same('not_triggered',$contract['optional_summaries']['empty']['status']);
		$t->isTrue($contract['optional_summaries']['triggered']['triggered']);
		$t->isTrue($contract['write_packet']['can_write_now']);
		$t->same(['continue_entity_chunks','resolve_prewrite_blockers','inspect_then_write_app_owned_files','inspect_builder_plan'],array_column($contract['resume'],'phase'));
		$t->same(['entities'=>'explicit_entities','name'=>'explicit_name','fields'=>'explicit_field_entities','phrases'=>'inferred_from_task'],array_map(static fn(array $entry): string=>$entry['input_mode'],$contract['entity_contracts']));
		$t->same(['always'=>true,'catalog'=>true,'child prefix'=>true,'parent prefix'=>true,'suffix'=>true,'none'=>false],$contract['soft_coverage']);
		$t->same(['empty'=>'inferred_defaults','flat'=>'flat_single_entity','nested'=>'nested_per_entity'],array_map(static fn(array $entry): string=>$entry['input_mode'],$contract['field_contracts']));
		return;
	}
	if(str_starts_with($name,'compiled route manifests')){
		$t->count(1,$contract['artifacts']['route_artifacts']);
		$t->same(2,$contract['manifest']['route_count']);
		$t->contains('/orders/42',$contract['url']['url']);
		$t->contains('name is required.',$contract['url_errors']['missing name']);
		$t->contains('absolute http(s) URL',$contract['url_errors']['invalid base']);
		$t->isFalse($contract['matches']['none']['matched']);
		$t->isTrue($contract['matches']['order']['matched']);
		return;
	}
	if(str_starts_with($name,'static route source')){
		$t->same('<app>',$contract['route_plan']['application_id']);
		$t->same(['empty'=>false,'string'=>true,'closure'=>true,'controller action'=>true,'dynamic'=>false],$contract['handler_shapes']);
		return;
	}
	if(str_starts_with($name,'diagnostic artifacts')){
		$t->same('inspect_redacted_matches',$contract['diagnostic_next_actions']['match']['status']);
		$t->same(1,$contract['diagnostics']['search']['match_count']);
		$t->same(2,$contract['diagnostics']['search_all']['match_count']);
		$t->same(1,$contract['diagnostics']['last_error']['error_count']);
		$t->contains('query is required.',$contract['diagnostics']['missing_query']);
		$t->same('<base-url>',$contract['diagnostics']['blank_browser']['base_url']);
		return;
	}
	if(str_starts_with($name,'verification surfaces')){
		$t->contains('suite_path is required',$contract['missing_suite']);
		$t->same('run_bounded_mcp_wrapper',$contract['next_wrapped']['status']);
		$t->same('inspect_unit_manifest',$contract['next_manifest']['status']);
		$t->same('triage_diagnostic_surface',$contract['next_diagnostic']['status']);
		$t->same('no_focused_surface_selected',$contract['next_empty']['status']);
		$t->same(null,$contract['diagnostic_excluded']);
		$t->same(['mcp_self_test','mcp_live_validate','mcp_config'],$contract['tool_categories']);
		$t->same(0,$contract['missing_tool_catalog']['publication_validation']['surface_count']);
		$t->same('tool_script',$contract['custom_tool']['category']);
		$t->contains('php_lint paths must be repo-local PHP files.',$contract['invalid_lint']);
		return;
	}
	$t->same(['integer','json','string','datetime','date','boolean','integer','decimal','text','string'],array_values($contract['types']));
	$t->same(['draft','active','bad'],$contract['options']['array']);
	$t->same(['one','two','three'],$contract['options']['spaces']);
	$t->isFalse($contract['defaults']['bool']);
	$t->same(12.5,$contract['defaults']['number']);
	$t->same(null,$contract['defaults']['unsupported']);
	$t->same('user',$contract['targets']['users']);
	$t->same(['','policy','address','case','status','user','business'],$contract['singular']);
})->with('MCP closed-world boundary contracts');
