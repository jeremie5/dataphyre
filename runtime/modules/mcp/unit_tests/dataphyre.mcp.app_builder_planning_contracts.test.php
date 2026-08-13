<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpKernelHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$planningContracts=[];
foreach(McpKernelHarness::appBuilderPlanningContractNames() as $contract){
	$planningContracts[$contract]=[$contract];
}
dataset('MCP app-builder planning contracts',$planningContracts);

suite('MCP app-builder executable planning contracts')
	->tag('mcp','app-builder','planning','verification','relationships','contract')
	->group('framework-coverage')
	->contract('mcp.app-builder.planning',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('integrity metadata','relationship adapters','implementation recipe','focused verification','feature intent','dependency order')
	->isolation('process');

test('each planning surface turns rich and noisy app metadata into bounded actionable contracts',static function(Context $t,string $name): void {
	$contract=(new McpKernelHarness($t))->appBuilderPlanningContract($name);
	$t->same($name,$contract['contract']);
	if($name==='integrity and relationships'){
		$t->isTrue($contract['integrity_work']);
		$t->greaterThan(0,$contract['index_count']);
		$t->greaterThan(0,$contract['unique_count']);
		$t->greaterThan(0,$contract['foreign_key_count']);
		$t->greaterThan(0,$contract['local_relationship_count']);
		$t->greaterThan(0,$contract['external_reference_count']);
		$t->greaterThan(0,$contract['scope_field_count']);
		$t->greaterThan(0,$contract['relationship_total']);
		$t->greaterThan(0,$contract['field_metadata_count']);
		return;
	}
	if($name==='implementation and verification'){
		foreach(['skeleton_total','verification_steps','recovery_branches','recipe_items','execution_items','fixture_count','negative_case_count','tenant_identity_case_count','recovery_tool_count','acceptance_items'] as $measure){
			$t->greaterThan(0,$contract[$measure],$measure);
		}
		$t->notEmpty($contract['placeholder_shapes']);
		return;
	}
	if($name==='malformed planning rows preserve valid work'){
		$expected=[
			'panel_metadata_count'=>1,
			'probe_uses_placeholder_root'=>true,
			'data_model_columns'=>['id','status'],
			'field_metadata_count'=>1,
			'integrity_indexes_are_unique'=>true,
			'recipe_kinds'=>['unknown','panel_resource'],
			'parallel_batch_count'=>1,
			'workflow_rule_count'=>2,
			'workflow_field_count'=>2,
			'model_skeleton_count'=>1,
			'fallback_verification_steps'=>1,
			'fixture_count'=>1,
			'execution_item_count'=>1,
			'recovery_branch_count'=>1,
			'acceptance_item_count'=>1,
			'obligation_review_count'=>1,
			'adapter_count'=>1,
		];
		$t->same($expected,array_intersect_key($contract,$expected),'Malformed rows are ignored while every valid planning row survives.');
		$t->greaterThan(0,$contract['relationship_total']);
		return;
	}
	if($name==='verification recovery is focused and copy-safe'){
		$t->same([
			'PHP syntax'=>1,
			'Panel field catalog'=>1,
			'Panel regression'=>1,
			'app-owned PHP tests'=>1,
			'SQL schema metadata'=>1,
			'route declarations and previews'=>3,
			'API static contracts'=>3,
			'unknown focused tools'=>1,
		],$contract['family_tool_counts']);
		foreach(['tool_count','actionable_tool_count','copy_safe_tool_count','path_mode_count'] as $measure){
			$t->same(12,$contract[$measure],$measure);
		}
		return;
	}
	if($name==='paths, chunks, and companion APIs'){
		$t->isTrue($contract['existing_path_found']);
		$t->greaterThan(0,$contract['continuation_count']);
		$t->greaterThan(0,$contract['dependency_chunks']);
		$t->greaterThan(0,$contract['incoming_dependency_count']);
		$t->greaterThan(0,$contract['companion_queue_count']);
		$t->same([
			'release'=>true,
			'explicit'=>true,
			'regex'=>true,
			'signal'=>true,
			'ordinary'=>false,
		],$contract['sensitive_checks']);
		$t->greaterThan(0,$contract['naming_mappings']);
		$t->isTrue($contract['has_normalization_notes']);
		$t->greaterThan(0,$contract['field_metadata_count']);
		$t->same('task is required.',$contract['missing_task_exception']);
		$t->notEmpty($contract['builder_plan_keys']);
		return;
	}
	$t->greaterThan(0,$contract['requested_features']);
	$t->greaterThan(0,$contract['decision_prompts']);
	$t->greaterThan(0,$contract['dependency_entities']);
	$t->same(0,$contract['empty_dependency_entities']);
	$t->notEmpty($contract['enterprise_note_keys']);
	$t->same(3,$contract['api_skeleton_count']);
})->with('MCP app-builder planning contracts');
