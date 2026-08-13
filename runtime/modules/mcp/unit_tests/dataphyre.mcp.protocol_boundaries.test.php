<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpProtocolBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$protocolContracts=[];
foreach(McpProtocolBoundaryHarness::contractNames() as $contract){$protocolContracts[$contract]=[$contract];}
dataset('MCP protocol boundary contracts',$protocolContracts);

suite('MCP protocol boundary contracts')
	->tag('mcp','protocol','stdio','framing','bootstrap','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.protocol.boundaries',1)
	->layer('system')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.php','path:runtime/modules/mcp/kernel/mcp.main.php')
	->through('module bootstrap','line decoder','header decoder','catalog dispatcher','debug lifecycle')
	->isolation('process')
	->coverageMemoryLimit('1G')
	->maxMillis(180000);

test('each protocol boundary produces named transport evidence without inline process plumbing',static function(Context $t,string $name): void {
	$contract=(new McpProtocolBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='runtime module bootstrap remains trace-only and never starts stdio'){
		$t->same(0,$contract['exit_code']);
		$t->same('',$contract['stderr']);
		$t->count(1,$contract['payload']['events']);
		$event=$contract['payload']['events'][0];
		$t->same('mcp.main.php',$event['file']);
		$t->same(10,$event['line']);
		$t->same('',$event['class']);
		$t->same('',$event['function']);
		$t->same('Module initialization',$event['message']);
		return;
	}

	if($name==='line transport recovers from malformed and invalid request shapes'){
		$t->same(5,$contract['message_count']);
		$t->same([-32700,-32600,-32600,-32000,null],$contract['codes']);
		$t->same([null,null,'missing-method','unknown-prompt','default-initialize'],$contract['ids']);
		$t->contains('Unknown prompt',$contract['unknown_message']);
		$t->same('2025-11-25',$contract['default_protocol']);
		return;
	}

	if($name==='header transport validates content length and incomplete frames'){
		$t->same('2025-11-25',$contract['default_protocol']);
		$t->same(-32600,$contract['errors']['missing']['code']);
		$t->contains('Content-Length',$contract['errors']['missing']['message']);
		$t->same(-32600,$contract['errors']['eof_header']['code']);
		$t->same(-32600,$contract['errors']['oversized']['code']);
		$t->contains('maximum frame size',$contract['errors']['oversized']['message']);
		$t->same(-32700,$contract['errors']['incomplete']['code']);
		$t->contains('incomplete',$contract['errors']['incomplete']['message']);
		$t->same(-32700,$contract['errors']['malformed']['code']);
		$t->contains('malformed',$contract['errors']['malformed']['message']);
		$t->same(-32600,$contract['blank']['code']);
		$t->contains('Content-Length',$contract['blank']['message']);
		return;
	}

	if($name==='protocol catalogs expose every static resource and prompt branch'){
		$t->count(14,$contract['prompts']);
		foreach($contract['prompts'] as $prompt=>$bytes){$t->greaterThan(80,$bytes,$prompt);}
		$t->count(8,$contract['resources']);
		foreach($contract['resources'] as $uri=>$resource){
			$t->greaterThan(0,$resource['bytes'],$uri);
			$t->same(in_array($uri,['dataphyre://mcp-capabilities','dataphyre://contracts','dataphyre://panel'],true) ? 'application/json' : 'text/markdown',$resource['mime_type'],$uri);
		}
		$t->contains('Unknown prompt',$contract['unknown_prompt']);
		return;
	}

	$t->same(['start','stop'],$contract['default_events']);
	$t->same(['start','stop'],$contract['explicit_events']);
	$t->same('2.0',$contract['valid_body']['jsonrpc']);
	$t->same('contract',$contract['valid_body']['id']);
	$t->isTrue($contract['valid_body']['result']['ok']);
	$t->same(-32603,$contract['invalid_body']['error']['code']);
	$t->same('Unable to encode response',$contract['invalid_body']['error']['message']);
	$t->same('null',$contract['invalid_tool_json']);
})->with('MCP protocol boundary contracts');
