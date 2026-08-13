<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpClientClosedWorldHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpClientClosedWorldTestKit.php';

$contracts=[];
foreach(McpClientClosedWorldHarness::contractNames() as $contract){$contracts[$contract]=[$contract];}
dataset('MCP client closed-world contracts',$contracts);

suite('MCP client closed-world contracts')
	->tag('mcp','client','closed-world','workflow','discovery','skills','documentation','safety')
	->group('framework-coverage')
	->contract('mcp.client.closed-world',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('named adversarial catalogs','fluent transcripts','independent registry snapshots','semantic evidence')
	->isolation('process')
	->memoryLimit('384M')
	->coverageMemoryLimit('1G');

test('each client edge is explained by a named semantic contract',static function(Context $t,string $name): void {
	$contract=(new McpClientClosedWorldHarness($t))->contract($name);
	$t->same($name,$contract['contract']);
	if(str_starts_with($name,'workflow catalogs')){
		$t->same(['missing.tool'],$contract['catalog_gaps']['tools']);
		$t->same(['missing.prompt'],$contract['catalog_gaps']['prompts']);
		$t->same(['healthy'=>'summarize_or_verify','blocked'=>'fix_blocked_transcript','empty'=>'start_workflow','review'=>'review_transcript'],$contract['next_decisions']);
		$t->same('dataphyre_app_builder_plan_generate',$contract['app']['recommended_tool']);
		$t->hasKey('enterprise_preflight',$contract['release']);
		$t->same(['support'=>false,'planning'=>true],$contract['task_detection']);
		$t->same(['valid'=>'routes','malformed'=>'client','unknown'=>'client'],$contract['recommendation_names']);
		return;
	}
	if(str_starts_with($name,'discovery ranks')){
		$t->same('dataphyre_app_builder_plan_generate',$contract['tool_finder']['recommended_first_call']['tool']);
		$t->containsAll(['api','routing'],$contract['resource_modules']['api']);
		$t->containsAll(['panel','sql','routing'],$contract['resource_modules']['application route']);
		$t->same('docs/Panel_Recipes.md',$contract['prioritized_paths'][0]);
		$t->same('keep governance collapsed unless escalation triggers match',$contract['collapsed_scope']['default_action']);
		return;
	}
	if(str_starts_with($name,'skill catalogs')){
		$t->same(['portable'],$contract['selected_names']);
		$t->same(['missing_tools','missing_prompts','missing_resources','product_local_coupling'],$contract['audit_codes']);
		$t->isFalse($contract['audit_ready']);
		$t->same('$CODEX_HOME/skills',$contract['roots']['codex']);
		$t->same('<claude-client-skill-root>',$contract['roots']['claude']);
		$t->hasKey('governance_notes',$contract['app_builder_pack']);
		return;
	}
	if(str_starts_with($name,'documentation examples')){
		$t->same(['missing.tool'],$contract['documentation_gaps']['tools']);
		$t->same(['dataphyre://missing'],$contract['documentation_gaps']['core_resources']);
		$t->same(['missing.tool'],$contract['example_gaps']);
		$t->hasKey('governance_notes',$contract['app_examples']);
		$t->same('no_publication_validation_slice',$contract['publication_fallback']['status']);
		$t->same('no_publication_validation_slice',$contract['publication_malformed_fallback']['status']);
		$t->same(['A concrete release note.'],$contract['release_highlights']);
		$t->contains('## Client Author Notes',$contract['release_notes']['markdown']);
		$t->same('release',$contract['prompts']['theme']);
		return;
	}
	$t->same('php',$contract['config']['manual_config']['mcpServers']['dataphyre']['command']);
	$t->same('inspect_client_setup',$contract['unknown_setup']['status']);
	$t->same(['empty'=>false,'release'=>false],$contract['prompt_pack']);
	$t->same(['future.tool'],array_keys($contract['audience']));
	$t->same('unsafe_enabled_use_bounded_tools_only',$contract['safety']['status']);
	$t->isTrue($contract['cli_guard']['accepted']);
	$t->isFalse($contract['cli_guard']['rejected']);
	$t->contains('only available from CLI',$contract['cli_guard']['output']);
	$t->contains('synthetic server construction failure',$contract['lifecycle_failure']);
})->with('MCP client closed-world contracts');
