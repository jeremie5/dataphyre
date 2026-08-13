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

$compactPayloadScenarios=[];
foreach(McpKernelHarness::appBuilderCompactPayloadScenarioNames() as $scenario){
	$compactPayloadScenarios[$scenario]=[$scenario];
}
dataset('MCP app-builder compact payload scenarios',$compactPayloadScenarios);

suite('MCP app-builder compact payload pressure contracts')
	->tag('mcp','app-builder','compact-payload','pagination','contract')
	->group('framework-coverage')
	->contract('mcp.app-builder.compact-payload',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('budget policy','progressive projections','detail pages','list pagination','handoff trimming')
	->isolation('process');

test('each named payload mode remains bounded, navigable, and structurally self-describing',static function(Context $t,string $scenario): void {
	$contract=(new McpKernelHarness($t))->appBuilderCompactPayloadContract($scenario);
	$t->same($scenario,$contract['scenario']);
	if($scenario==='bounded response'){
		$t->isTrue($contract['bounded']);
		$t->isTrue($contract['should_enforce']['explicit_flag']);
		$t->isTrue($contract['should_enforce']['detail_page']);
		$t->isFalse($contract['should_enforce']['explicit_entities']);
		$t->isTrue($contract['should_enforce']['large_field_map']);
		$t->isTrue($contract['should_enforce']['top_level_budget']);
		$t->isTrue($contract['should_enforce']['zero_budget']);
		$t->isFalse($contract['should_enforce']['ordinary']);
		$t->isTrue($contract['early_budget_identity']);
		$t->isTrue($contract['zero_budget_enforced']);
		$t->isTrue($contract['explicit_files_fallback']);
		$t->isTrue($contract['selected_budget_repaired']);
		$t->isTrue($contract['incremental_collapse_stops_when_bounded']);
		$t->same(['budget'=>true,'files'=>true,'schema'=>true,'path'=>true],$contract['repaired_contract']);
		return;
	}
	if($scenario==='detail pagination'){
		$t->notEmpty($contract['pages']);
		$t->same('invalid_detail_page',$contract['selected_statuses']['invalid']);
		$t->same('selected',$contract['selected_statuses']['governance fallback']);
		$t->same('selected',$contract['selected_statuses']['response fallback']);
		$t->greaterThan(0,$contract['pagination_markers']);
		$t->greaterThan(0,$contract['collapsed_sections']);
		return;
	}
	if($scenario==='compaction primitives'){
		$t->same(3,$contract['limited_count']);
		$t->same(8,$contract['pagination']['total']);
		$t->same(3,$contract['recipe_pagination_keys']);
		$t->isTrue($contract['nested_paginated']);
		$t->isTrue($contract['invalid_json_is_null']);
		$t->notEmpty($contract['summary']);
		$t->count(7,$contract['count_evidence']);
		$t->same(['implementation'=>2,'verification'=>3,'acceptance'=>2],$contract['detail_counts']);
		$t->same('FallbackEntity',$contract['schema_row_entity']);
		$t->same(2,$contract['enterprise_controls']);
		$t->same(2,$contract['detail_count_summaries']['direct']['count']);
		$t->same(7,$contract['detail_count_summaries']['preserved']['count']);
		$t->isNull($contract['detail_count_summaries']['missing']);
		$t->isTrue($contract['root_list_preserved']);
		return;
	}
	$t->isTrue($contract['budget_enforced']);
	$t->notEmpty($contract['response_keys']);
	$t->greaterThan(0,$contract['encoded_chars']);
	if($scenario==='selected detail overflow'){
		$t->isTrue($contract['has_selected_detail']);
	}else{
		$t->isFalse($contract['has_selected_detail']);
	}
})->with('MCP app-builder compact payload scenarios');
