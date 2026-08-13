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
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

suite('MCP registered tool contract matrix')
	->tag('mcp','tools','coverage','contract-matrix')
	->group('framework-coverage')
	->contract('mcp.tools.registered-matrix',1)
	->layer('system')
	->risk('critical')
	->watches('module:mcp')
	->through('live registry','input schemas','exhaustive dispatch','bounded result encoding')
	->isolation('process')
	->coverageMemoryLimit('1G')
	->maxMillis(300000);

test('every registered tool accepts a generated representative contract call',static function(Context $t): void {
	(new McpToolContractMatrix($t))->representative()->assertComplete($t,0.75);
});
