<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpToolContractMatrix;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$toolContracts=[];
foreach(McpToolContractMatrix::registeredToolNamesFromSource() as $toolName){
	$toolContracts[$toolName]=[[$toolName]];
}
dataset('MCP registered tool semantic contracts',$toolContracts);

suite('MCP schema-driven semantic variant matrix')
	->tag('mcp','tools','schema-variants','contract-matrix')
	->group('framework-coverage')
	->contract('mcp.tools.semantic-variants',1)
	->layer('system')
	->risk('critical')
	->watches('module:mcp')
	->through('required-only inputs','engaged optionals','named behavior axes','bounded errors')
	->isolation('process')
	->memoryLimit('512M')
	->coverageMemoryLimit('1G')
	->maxMillis(300000);

test('every registered tool survives schema-derived optionality and semantic axis variants',static function(Context $t,array $toolNames): void {
	(new McpToolContractMatrix($t))->semanticVariants(toolNames:$toolNames)->assertComplete($t,0.0);
})->with('MCP registered tool semantic contracts');
