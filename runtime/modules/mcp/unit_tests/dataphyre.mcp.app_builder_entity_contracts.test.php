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

$entityChunks=[];
foreach(array_chunk(McpKernelHarness::appBuilderEntityNamesFromSource(),16) as $index=>$entities){
	$entityChunks['catalog chunk '.($index+1)]=[$entities];
}
dataset('MCP app-builder entity catalog chunks',$entityChunks);

suite('MCP app-builder executable entity catalog')
	->tag('mcp','app-builder','schema','catalog-contract')
	->group('framework-coverage')
	->contract('mcp.app-builder.entity-fields',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp')
	->through('declared entity arms','isolated defaults','connected relationship defaults','field metadata')
	->isolation('process');

test('every declared app-builder entity produces valid isolated and connected field contracts',static function(Context $t,array $entities): void {
	$contracts=(new McpKernelHarness($t))->appBuilderDefaultFieldContracts($entities);
	$t->same($entities,array_keys($contracts));
	foreach($contracts as $entity=>$contexts){
		foreach($contexts as $context=>$fields){
			$t->notEmpty($fields,$entity.' '.$context);
			foreach($fields as $name=>$metadata){
				$t->notEmpty((string)$name,$entity.' field name');
				$t->type('array',$metadata,$entity.'.'.$name.' metadata');
				$t->hasKey('type',$metadata,$entity.'.'.$name.' type');
				$t->notEmpty((string)$metadata['type'],$entity.'.'.$name.' type');
				if(isset($metadata['foreign_key_target'])){
					$t->notEmpty((string)$metadata['foreign_key_target'],$entity.'.'.$name.' relationship target');
				}
			}
		}
	}
})->with('MCP app-builder entity catalog chunks');
