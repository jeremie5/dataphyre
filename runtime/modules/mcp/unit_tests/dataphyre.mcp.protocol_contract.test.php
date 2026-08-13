<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpScenario;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

suite('MCP protocol and discovery contracts')
	->tag('mcp','protocol','coverage')
	->group('framework-coverage')
	->contract('mcp.protocol.discovery',1)
	->layer('system')
	->risk('critical')
	->watches('module:mcp')
	->through('stdio framing','JSON-RPC dispatch','resources','prompts','tool discovery')
	->isolation('process')
	->maxMillis(180000);

test('one self-describing transcript exercises every public MCP protocol family',static function(Context $t): void {
	$scenario=new McpScenario($t);
	$transcript=$scenario->exchange([
		['jsonrpc'=>'2.0','method'=>'notifications/initialized','params'=>[]],
		McpScenario::request('initialize','initialize',['protocolVersion'=>'2025-11-25']),
		McpScenario::request('tools','tools/list'),
		McpScenario::request('resources','resources/list'),
		McpScenario::request('resource','resources/read',['uri'=>'dataphyre://mcp-capabilities']),
		McpScenario::request('prompts','prompts/list'),
		McpScenario::request('prompt','prompts/get',['name'=>'dataphyre_feature_plan']),
		McpScenario::request('contract-prompt','prompts/get',['name'=>'dataphyre_contract_workflow']),
		McpScenario::request('unknown','protocol/unknown'),
		['jsonrpc'=>'2.0','id'=>'invalid','method'=>'','params'=>[]],
	],180000);

	$sourceTranscript=(new McpScenario($t))->usingOrdinaryPhpForSourceIntrospection()->exchange([
		McpScenario::request('contract-resource','resources/read',['uri'=>'dataphyre://contracts']),
		McpScenario::request('contract-catalog','tools/call',['name'=>'dataphyre_contract_catalog','arguments'=>[
			'modules'=>['mcp'],'kinds'=>['test_contract'],'query'=>'mcp.protocol.discovery','limit'=>5,
		]]),
		McpScenario::request('contract-descriptor','tools/call',['name'=>'dataphyre_contract_describe','arguments'=>[
			'id'=>'test:mcp.protocol.discovery@1',
		]]),
		McpScenario::request('panel-resource','resources/read',['uri'=>'dataphyre://panel']),
		McpScenario::request('panel-prompt','prompts/get',['name'=>'dataphyre_panel_realtime_workflow']),
		McpScenario::request('panel-catalog','tools/call',['name'=>'dataphyre_panel_capability_catalog','arguments'=>[
			'kinds'=>['platform_domain'],'query'=>'realtime','limit'=>3,
		]]),
		McpScenario::request('panel-descriptor','tools/call',['name'=>'dataphyre_panel_capability_describe','arguments'=>[
			'id'=>'panel:domain:realtime','view'=>'integration','max_items'=>3,
		]]),
	],180000);

	$t->count(9,$transcript->messages());
	$t->count(7,$sourceTranscript->messages());
	$initialize=$transcript->result('initialize');
	$t->same('2025-11-25',$initialize['protocolVersion']);
	$t->same('dataphyre-mcp',$initialize['serverInfo']['name']);
	foreach(['tools','resources','prompts'] as $capability){$t->hasKey($capability,$initialize['capabilities']);}

	$tools=$transcript->result('tools')['tools'];
	$t->greaterThan(100,count($tools));
	foreach(['name','description','inputSchema'] as $key){$t->hasKey($key,$tools[0]);}
	$t->contains('dataphyre_contract_catalog',array_column($tools,'name'));
	$t->contains('dataphyre_contract_describe',array_column($tools,'name'));
	foreach(['dataphyre_panel_capability_catalog','dataphyre_panel_capability_describe','dataphyre_panel_surface_graph','dataphyre_panel_recipe_plan','dataphyre_panel_integration_plan','dataphyre_panel_verification_plan'] as $tool){$t->contains($tool,array_column($tools,'name'));}

	$resources=$transcript->result('resources')['resources'];
	$t->greaterThan(5,count($resources));
	$t->contains('dataphyre://mcp-capabilities',array_column($resources,'uri'));
	$t->contains('dataphyre://contracts',array_column($resources,'uri'));
	$t->contains('dataphyre://panel',array_column($resources,'uri'));
	$resource=$transcript->result('resource')['contents'][0];
	$t->same('application/json',$resource['mimeType']);
	$t->isTrue(is_array(json_decode($resource['text'],true)));
	$contractResource=json_decode($sourceTranscript->result('contract-resource')['contents'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_contract_index',$contractResource['resource_type']);
	$t->same('bounded_bootstrap_partial',$contractResource['resource_mode']);
	$t->same(['mcp'],$contractResource['scope_modules']);
	$t->contains('mcp',$contractResource['available_modules']);
	$t->same('module_federation',$contractResource['enumeration_contract']['strategy']);
	$t->same('not_executed',$contractResource['execution']);
	$t->greaterThan(0,$contractResource['counts']['total']);
	$panelResource=json_decode($sourceTranscript->result('panel-resource')['contents'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_panel_capability_index',$panelResource['resource_type']);
	$t->same('bounded_domain_federation',$panelResource['resource_mode']);
	$t->same('not_executed',$panelResource['execution']);
	$t->same(25,$panelResource['counts']['domains']);
	$t->greaterThan(882,$panelResource['counts']['framework_files']);
	$t->greaterThan(649,$panelResource['counts']['contracts']);

	$prompts=$transcript->result('prompts')['prompts'];
	$t->greaterThan(5,count($prompts));
	$t->contains('dataphyre_feature_plan',array_column($prompts,'name'));
	$t->contains('dataphyre_contract_workflow',array_column($prompts,'name'));
	foreach(['dataphyre_panel_workflow','dataphyre_panel_platform_workflow','dataphyre_panel_operations_workflow','dataphyre_panel_studio_workflow','dataphyre_panel_realtime_workflow','dataphyre_panel_adapter_workflow'] as $prompt){$t->contains($prompt,array_column($prompts,'name'));}
	$t->contains('application agents',$transcript->result('prompt')['messages'][0]['content']['text']);
	$t->contains('dataphyre_contract_describe',$transcript->result('contract-prompt')['messages'][0]['content']['text']);
	$t->contains('one stream-head authority',$sourceTranscript->result('panel-prompt')['messages'][0]['content']['text']);

	$catalog=$sourceTranscript->toolPayload('contract-catalog');
	$t->same(1,$catalog['counts']['matched']);
	$t->same('test:mcp.protocol.discovery@1',$catalog['records'][0]['id']);
	$t->same('not_executed',$catalog['execution']);
	$descriptor=$sourceTranscript->toolPayload('contract-descriptor');
	$t->same('found',$descriptor['status']);
	$t->same('mcp.protocol.discovery',$descriptor['contract']['name']);
	$t->same('read_only_source_contract_metadata',$descriptor['contract_safety']['classification']);
	$panelCatalog=$sourceTranscript->toolPayload('panel-catalog');
	$t->same('dataphyre_panel_capability_catalog',$panelCatalog['catalog_type']);
	$t->same('panel:domain:realtime',$panelCatalog['records'][0]['id']);
	$panelDescriptor=$sourceTranscript->toolPayload('panel-descriptor');
	$t->same('dataphyre_panel_capability_descriptor',$panelDescriptor['descriptor_type']);
	$t->same('panel:domain:realtime',$panelDescriptor['capability']['overview']['id']);
	$t->same('integration',$panelDescriptor['view']);
	$t->same('not_executed',$panelDescriptor['execution']);
	$t->same(-32601,$transcript->response('unknown')['error']['code']);
	$t->same(-32600,$transcript->response('invalid')['error']['code']);
});

test('tool errors remain structured at validation and dispatch boundaries',static function(Context $t): void {
	$transcript=(new McpScenario($t))->exchange([
		McpScenario::request('missing-tool','tools/call',['name'=>'not_registered','arguments'=>[]]),
		McpScenario::request('missing-required','tools/call',['name'=>'dataphyre_module_describe','arguments'=>[]]),
	]);
	$t->same(-32602,$transcript->response('missing-tool')['error']['code']);
	$t->contains('Unknown MCP tool',$transcript->response('missing-tool')['error']['message']);
	$t->same(-32602,$transcript->response('missing-required')['error']['code']);
	$t->contains('module',$transcript->response('missing-required')['error']['message']);
});
