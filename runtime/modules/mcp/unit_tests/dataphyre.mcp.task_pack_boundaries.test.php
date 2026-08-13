<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpTaskPackBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$taskPackContracts=[];
foreach(McpTaskPackBoundaryHarness::contractNames() as $contract){$taskPackContracts[$contract]=[$contract];}
dataset('MCP task-pack boundary contracts',$taskPackContracts);

suite('MCP task-pack and apply-audit planning contracts')
	->tag('mcp','planning','task-pack','apply-audit','path','risk','verification','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.planning.task-pack-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.planning.task_pack.php')
	->through('task-pack builder','apply-audit builder','path normalizer','scope classifier','file classifier','verification inference','publication inference','risk classifier','placement decision','prompt summary')
	->isolation('process')
	->coverageMemoryLimit('1G');

test('each task-pack boundary reads as a planning contract',static function(Context $t,string $name): void {
	$contract=(new McpTaskPackBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='task packs reject empty work and separate compact scaffold from governance'){
		$t->contains('task is required',$contract['missing_task']);
		$compact=$contract['compact'];
		$t->same('builder',$compact['profile']);
		$t->isFalse($compact['governance_inline']);
		$t->isTrue($compact['governance_collapsed']);
		$t->same('panel_resource',$compact['scaffold']['type']);
		$t->same('dry_run_only',$compact['scaffold']['write_policy']);
		$t->containsAll(['dataphyre_php_lint','dataphyre_run_panel_regression','dataphyre_run_panel_field_catalog_check'],$compact['verification']);
		$t->contains('Task: Build a project tracker',$compact['prompt']);
		$t->isFalse($compact['has_builder_plan']);
		$governance=$contract['governance'];
		$t->same('governance',$governance['profile']);
		$t->isTrue($governance['governance_inline']);
		$t->isFalse($governance['governance_collapsed']);
		$t->isTrue($governance['has_extension_boundary']);
		$t->isTrue($governance['has_publication_validation']);
		$t->isTrue($governance['has_guardrails']);
		return;
	}

	if($name==='proposed files normalize scope category and safety warnings consistently'){
		$t->same('common/dataphyre/runtime/modules/mcp/example.php',$contract['normalized']);
		$t->same('runtime/modules/mcp/example.php',$contract['scopes']['dataphyre']);
		$t->same('docs/guide.md',$contract['scopes']['common']);
		$t->same('applications/example/file.php',$contract['scopes']['caller']);
		$t->isTrue($contract['membership']['exact']);
		$t->isTrue($contract['membership']['child']);
		$t->isFalse($contract['membership']['sibling']);
		$t->same('dataphyre_package',$contract['package']['runtime']);
		$t->same('dataphyre_package',$contract['package']['dev']);
		$t->same('dataphyre_package',$contract['package']['docs']);
		$t->same('dataphyre_package',$contract['package']['documentation']);
		$t->same('caller',$contract['package']['caller']);
		$t->same('runtime/modules/mcp/kernel/example.php',$contract['entries']['normalized']['scope_path']);
		$t->isFalse($contract['entries']['parent']['repo_relative']);
		$t->containsAll(['path_is_not_repo_relative','path_contains_parent_segment','sensitive_file_type'],$contract['entries']['parent']['warnings']);
		$t->contains('third_party_directory',$contract['entries']['third_party']['warnings']);
		$t->same([
			'documentation'=>'documentation',
			'test'=>'test',
			'php_source'=>'php_source',
			'json_manifest'=>'json_manifest',
			'script'=>'script',
			'other'=>'other',
		],$contract['categories']);
		return;
	}

	if($name==='apply plans infer focused verification publication evidence and risk'){
		$t->contains('task is required',$contract['missing_task']);
		$plan=$contract['plan'];
		$t->same('critical',$plan['risk_level']);
		$t->count(9,$plan['proposed_files']);
		$t->containsAll(['custom focused check','dataphyre_php_lint','dataphyre_run_panel_field_catalog_check','JSON parse check'],$plan['verification']);
		$t->containsAll(['dataphyre_mcp_doctor','Dataphyre MCP publication evidence','MCP app-coupling guard scan','Dataphyre maintainer release check evidence before public claims'],$plan['publication_validation']);
		$t->containsAll(['non_repo_relative_path','path_contains_parent_segment','sensitive_file_type','third_party_directory','configuration_surface','script_surface','runtime_surface','sensitive_behavior_summary'],$plan['risks']);
		$t->same('escalate_framework_change',$plan['apply_next_action']['status']);
		$t->same('medium',$contract['hinted']['risk_level']);
		$t->same('<task>',$contract['blank_readiness']['task']);
		$t->same('Continue safely',$contract['readiness']['task']);
		$t->isFalse($contract['readiness']['files_written']);
		$t->same('use_app_owned_extension_point',$contract['readiness']['apply_next_action']['status']);
		return;
	}

	if($name==='placement and risk classifiers distinguish app work from framework escalation'){
		$t->same('use_app_owned_extension_point',$contract['next_actions']['app']['status']);
		$t->same('escalate_framework_change',$contract['next_actions']['docs']['status']);
		$t->same('escalate_framework_change',$contract['next_actions']['runtime']['status']);
		$t->containsAll(['configuration_surface','script_surface','non_repo_relative_path','path_contains_parent_segment','sensitive_file_type','sensitive_behavior_summary'],$contract['risks']);
		$t->same('critical',$contract['levels']['critical']);
		$t->same('high',$contract['levels']['high']);
		$t->same('medium',$contract['levels']['medium_count']);
		$t->same('medium',$contract['levels']['medium_script']);
		$t->same('low',$contract['levels']['low']);
		return;
	}

	$t->containsAll(['panel','sql','routing','mvc','tracelog','mcp'],$contract['modules']);
	$t->same(['panel','sql'],$contract['app_modules']);
	$t->same(['mcp'],$contract['default_modules']);
	$t->contains('Chunked scaffold: yes; deferred entities: Seven, Eight.',$contract['prompt']);
	$t->contains('Chunked scaffold: no',$contract['empty_prompt']);
	$t->contains('projects via schema.php, repository.php, resource.php',$contract['data_model']);
	$t->contains('inspect existing app schema',$contract['empty_data_model']);
	$t->contains('inspect existing app schema',$contract['invalid_data_model']);
	$t->same('Create files. Verify behavior. Keep ownership local.',$contract['acceptance']);
	$t->same('focused app/module checks pass and changes stay app-owned',$contract['empty_acceptance']);
	$t->containsAll([
		'dataphyre_php_lint',
		'dataphyre_run_panel_regression',
		'dataphyre_run_panel_field_catalog_check',
		'dataphyre_route_manifest_read',
		'dataphyre_route_match_preview',
		'dataphyre_sql_schema_read',
		'dataphyre_sql_tables_list',
		'dataphyre_tracelog_artifacts_list',
		'custom check',
	],$contract['verification']);
})->with('MCP task-pack boundary contracts');
