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

$defaultFieldMethodChunks=[];
foreach(array_chunk(McpKernelHarness::appBuilderDefaultFieldMethodNamesFromSource(),16) as $index=>$methods){
	$defaultFieldMethodChunks['default-field methods '.($index+1)]=[$methods];
}
dataset('MCP app-builder default-field method chunks',$defaultFieldMethodChunks);

$phraseLiteralChunks=[];
foreach(array_chunk(McpKernelHarness::appBuilderSchemaPhraseLiteralsFromSource(),32) as $index=>$literals){
	$phraseLiteralChunks['phrase literals '.($index+1)]=[$literals];
}
dataset('MCP app-builder phrase literal chunks',$phraseLiteralChunks);

suite('MCP app-builder source-derived schema inference contracts')
	->tag('mcp','app-builder','schema','inference','source-derived','contract')
	->group('framework-coverage')
	->contract('mcp.app-builder.schema-inference',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('phrase taxonomy','domain ordering','nested field input','conditional defaults','rendering helpers')
	->isolation('process')
	->coverageMemoryLimit('1G');

test('every production phrase participates in executable entity inference',static function(Context $t,array $literals): void {
	$contract=(new McpKernelHarness($t))->appBuilderSchemaPhraseContract($literals);
	$t->greaterThan(0,$contract['literal_count']);
	$t->type('integer',$contract['matched_literal_count']);
	$t->type('array',$contract['matched_entities']);
	$t->type('array',$contract['composite_entities']);
})->with('MCP app-builder phrase literal chunks');

test('schema helper semantics preserve explicit inputs, domain ordering, typed metadata, and generated source',static function(Context $t): void {
	$contract=(new McpKernelHarness($t))->appBuilderSchemaHelperContract();
	$t->notEmpty($contract['scaffold_types']);
	$t->notEmpty($contract['entity_scenarios']);
	$t->notEmpty($contract['field_inputs']);
	foreach($contract['phrase_exclusions'] as $scenario=>$excluded){
		$t->isTrue($excluded,$scenario);
	}
	$t->notEmpty($contract['pascal_property_entities']);
	$t->same('flat_field',$contract['flat_fields_for_entity'][0]['name']);
	$t->same([],$contract['invalid_field_entities']);
	$t->greaterThan(0,$contract['schema_count']);
	$t->notEmpty($contract['filters']);
	$t->notEmpty($contract['relationships']);
	foreach($contract['rendered_bytes'] as $surface=>$bytes){
		$t->greaterThan(0,$bytes,$surface);
	}
	$t->same(['Customer','Order','Other'],$contract['preferred']);
	$t->same("['ready'=>'Ready']",$contract['empty_panel_options']);
	$t->same('DataphyreItem',$contract['empty_relationship_target']);
	$t->same(['tenant_id'],$contract['webhook_idempotency_scope']);
	$t->same(['id','name','status'],$contract['fallback_field_names']);
	$t->isFalse($contract['bounded_empty']);
});

test('each conditional default-field method executes its own source-declared entity and task contexts',static function(Context $t,array $methods): void {
	$contracts=(new McpKernelHarness($t))->appBuilderDefaultFieldBranchContracts($methods);
	$t->same($methods,array_keys($contracts));
	foreach($contracts as $method=>$contract){
		$t->greaterThan(0,$contract['invocations'],$method);
		$t->greaterThan(0,$contract['distinct_shapes'],$method);
	}
})->with('MCP app-builder default-field method chunks');
