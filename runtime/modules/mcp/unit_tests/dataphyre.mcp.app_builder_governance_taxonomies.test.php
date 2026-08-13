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

$governanceFamilies=[];
foreach(McpKernelHarness::appBuilderGovernanceFamilyNames() as $family){
	$governanceFamilies[$family]=[$family];
}
dataset('MCP app-builder governance families',$governanceFamilies);

suite('MCP app-builder source-derived governance taxonomies')
	->tag('mcp','app-builder','governance','taxonomy','contract')
	->group('framework-coverage')
	->contract('mcp.app-builder.governance-taxonomies',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('source literals','semantic classifiers','representative schemas','governance summaries','agent handoffs')
	->isolation('process');

test('each governance family executes every declared taxonomy arm and produces a structured summary',static function(Context $t,string $family): void {
	$contract=(new McpKernelHarness($t))->appBuilderGovernanceFamilyContract($family);
	$t->same($family,$contract['family']);
	if($contract['taxonomy_methods']===[]){
		$t->same(0,$contract['taxonomy_invocations'],'Summary-only governance families have no taxonomy calls to execute.');
	}else{
		$t->greaterThan(count($contract['taxonomy_methods']),(int)$contract['taxonomy_invocations']);
	}
	$t->greaterThan(0,(int)$contract['schema_count']);
	$t->notEmpty($contract['summary_keys']);
	$t->type('array',$contract['field_categories']);
	$t->type('array',$contract['entity_categories']);
	$t->type('array',$contract['control_ids']);
	$t->type('array',$contract['handoff_keys']);
	$t->type('array',$contract['noisy_handoff_keys']);
	$t->notEmpty($contract['noisy_summary_keys']);
})->with('MCP app-builder governance families');
