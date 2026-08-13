<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpPlanningClosedWorldHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpPlanningClosedWorldTestKit.php';

$contracts=[];
foreach(McpPlanningClosedWorldHarness::contractNames() as $contract){$contracts[$contract]=[$contract];}
dataset('MCP planning closed-world contracts',$contracts);

suite('MCP planning closed-world contracts')
	->tag('mcp','planning','closed-world','docs','modules','sensitivity','readiness','api')
	->group('framework-coverage')
	->contract('mcp.planning.closed-world',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('managed repository fixtures','malformed planning rows','named policy matrices','semantic evidence')
	->isolation('process')
	->memoryLimit('384M')
	->coverageMemoryLimit('768M');

test('each planning edge is explained by a named semantic contract',static function(Context $t,string $name): void {
	$contract=(new McpPlanningClosedWorldHarness($t))->contract($name);
	$t->same($name,$contract['contract']);
	if(str_starts_with($name,'documentation search')){
		$t->contains('query is required',$contract['empty_search']);
		$t->count(1,$contract['search']['matches']);
		$t->count(1,$contract['module_sets']);
		$t->count(2,$contract['selection']);
		$t->count(1,$contract['round_robin']);
		$t->same('<docs-base-url>',$contract['readiness']['remote']['base_url']);
		$t->same('<embedding-provider>',$contract['readiness']['embeddings']['provider']);
		$t->same('<project>',$contract['readiness']['datadoc']['project']);
		$t->same([],$contract['missing_definition']);
		return;
	}
	if(str_starts_with($name,'module declarations')){
		$t->same(['demo','minimal'],array_keys($contract['declarations']));
		$t->same([],$contract['declarations']['minimal']['notes']);
		$t->contains('module must be a runtime module directory name',$contract['invalid_module']);
		$t->same(['core'],$contract['dependency_map']['required_modules']);
		$t->notNull($contract['sources']['existing']);
		$t->same(null,$contract['sources']['missing']);
		$t->count(1,$contract['bounded_sources']);
		return;
	}
	if(str_starts_with($name,'app sensitivity')){
		$t->same(['Order','Credential'],array_column($contract['sensitivity_schemas'],'entity'));
		$t->isTrue($contract['summary']['has_sensitive_signals']);
		$t->same(1,$contract['register']['required_count']);
		$t->same(['other_category'=>false,'other_field'=>false,'service_health'=>true],$contract['contextual_status']);
		$t->same('high',$contract['policies']['residency']['sensitivity_level']);
		$t->same('medium',$contract['policies']['fallback']['sensitivity_level']);
		$t->same('critical',$contract['metadata']['highest_sensitivity_level']);
		return;
	}
	if(str_starts_with($name,'write readiness')){
		$t->count(1,$contract['queue']);
		$t->same('prior_chunk',array_key_first($contract['context']['relationship_summary']['dependency_scopes']));
		$t->same('resolve_blockers_before_writes',$contract['resolution']['status']);
		$t->same('implementation',$contract['handoff']['first_batch']['concern']);
		$t->same(['app/Order.php'],$contract['handoff']['first_batch']['paths']);
		$t->isTrue($contract['handoff']['ready_for_app_owned_writes']);
		$t->same(['invalid namespace'=>'invalid_app_namespace','existing path'=>'concrete_app_paths_available'],$contract['path_states']);
		return;
	}
	$t->same(['GET'],$contract['api']['endpoint']['methods']);
	$t->same('/api/orders',$contract['api']['endpoint']['path']);
	$t->contains('name is required',$contract['api_missing_name']);
	$t->same('controller_backed',$contract['recipes']['selected']['selected_recipe']);
	$t->contains('Unknown API recipe',$contract['recipes']['invalid']);
	$t->same('<app>',$contract['openapi']['application_id']);
	$t->contains('name is required',$contract['scaffold_missing_name']);
	$t->same(['mcp'],$contract['agent_context']['modules']);
	$t->same('panel',$contract['panel_media']['module']);
	$t->count(1,$contract['panel_media']['media_files']);
})->with('MCP planning closed-world contracts');
