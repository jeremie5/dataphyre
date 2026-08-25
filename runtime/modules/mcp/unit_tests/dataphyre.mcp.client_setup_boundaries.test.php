<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpClientSetupBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$setupContracts=[];
foreach(McpClientSetupBoundaryHarness::contractNames() as $contract){$setupContracts[$contract]=[$contract];}
dataset('MCP client setup boundary contracts',$setupContracts);

suite('MCP portable client setup contracts')
	->tag('mcp','client','setup','config','audit','troubleshooting','smoke','onboarding','compatibility','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.client.setup-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.client.setup.php')
	->through('target vocabulary','install plan','troubleshooting classifier','config builder','input resolver','config audit','smoke export','onboarding pack','compatibility matrix')
	->isolation('process')
	->coverageMemoryLimit('1G');

test('each client setup boundary reads as a portable setup contract',static function(Context $t,string $name): void {
	$contract=(new McpClientSetupBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='install plans normalize every supported client without machine-local writes'){
		$t->same('cursor',$contract['normalized_target']);
		$t->same('generic',$contract['fallback_target']);
		$t->same('<codex-client-config-path>',$contract['plans']['codex']['config_path']);
		$t->same('<claude-desktop-config-path>',$contract['plans']['claude']['config_path']);
		$t->same('<cursor-mcp-config-path>',$contract['plans']['cursor']['config_path']);
		$t->same('<mcp-client-config-path>',$contract['plans']['generic']['config_path']);
		$t->same('generic',$contract['plans']['unsupported']['target']);
		$t->contains('exact Dataphyre revision resolved by the application dependency lock',$contract['entrypoint_contract']['client_policy']);
		$t->contains('framework-maintainer checkout proves only that checkout',$contract['entrypoint_contract']['client_policy']);
		foreach($contract['plans'] as $plan){
			$t->isFalse($plan['artifacts_written']);
			$t->pathEquals('proposed_config.mcpServers.dataphyre.command','php',$plan);
			$t->same($plan['config_path'],$plan['proposed_writes'][0]['path']);
		}
		$t->same('codex',$contract['checklists']['codex']['target']);
		$t->same('claude',$contract['checklists']['claude']['target']);
		$t->same('cursor',$contract['checklists']['cursor']['target']);
		$t->same('generic',$contract['checklists']['unsupported']['target']);
		return;
	}

	if($name==='troubleshooting maps recognizable symptoms to focused setup diagnoses'){
		$t->same(6,$contract['focused']['symptom_count']);
		$t->same(['no_server_response','invalid_framing','wrong_working_directory','php_binary','missing_tool','unsafe_expectation'],$contract['focused_ids']);
		$t->same('codex',$contract['focused']['target']);
		$t->same(1,$contract['generic']['symptom_count']);
		$t->same(['generic_client_setup'],$contract['generic_ids']);
		$t->same('generic',$contract['generic']['target']);
		return;
	}

	if($name==='config input preserves object JSON missing and malformed provenance'){
		$t->same('array',$contract['array']['source']);
		$t->same('php',$contract['array']['config']['mcpServers']['dataphyre']['command']);
		$t->isNull($contract['array']['parse_error']);
		$t->same('json',$contract['json']['source']);
		$t->same($contract['array']['config'],$contract['json']['config']);
		$t->same('array',$contract['array_precedence']['source']);
		$t->same('missing',$contract['missing']['source']);
		$t->same([],$contract['missing']['config']);
		$t->same('invalid_json',$contract['invalid']['source']);
		$t->notNull($contract['invalid']['parse_error']);
		$t->same('invalid_json',$contract['non_object']['source']);
		$t->same('No error',$contract['non_object']['parse_error']);
		return;
	}

	if($name==='config audits separate blocking issues warnings and portability passes'){
		$t->isTrue($contract['valid']['passed']);
		$t->same(0,$contract['valid']['issue_count']);
		$t->same(0,$contract['valid']['warning_count']);
		$t->containsAll(['command_present','server_arg_present','unsafe_not_enabled','cwd_present','no_product_local_paths'],$contract['valid']['passes']);
		$t->isTrue($contract['windows_php']['passed']);
		$t->contains('command_present',$contract['windows_php']['passes']);
		$t->same(['empty_config'],$contract['empty']['issue_ids']);
		$t->same(['invalid_json','empty_config'],$contract['malformed']['issue_ids']);
		$t->same(['missing_dataphyre_server'],$contract['missing_server']['issue_ids']);
		$t->isFalse($contract['broken']['passed']);
		$t->same(['missing_command','missing_server_arg','module_bootstrap_used_as_server','product_local_path'],$contract['broken']['issue_ids']);
		$t->same(['unsafe_enabled','cwd_not_set'],$contract['broken']['warning_ids']);
		$t->isTrue($contract['non_php']['passed']);
		$t->same(['non_php_command'],$contract['non_php']['warning_ids']);
		$t->containsAll(['server_arg_present','unsafe_not_enabled','cwd_present','no_product_local_paths'],$contract['non_php']['passes']);
		foreach($contract['application_local_variants'] as $variant){
			$t->isFalse($variant['passed']);
			$t->same(['product_local_path'],$variant['issue_ids']);
		}
		return;
	}

	$t->same(['powershell'],$contract['smoke']['powershell']['script_names']);
	$t->same(['bash'],$contract['smoke']['bash']['script_names']);
	$t->same(['node'],$contract['smoke']['node']['script_names']);
	$t->same(['php'],$contract['smoke']['php']['script_names']);
	$t->same(['powershell','bash','node','php'],$contract['smoke']['unsupported']['script_names']);
	foreach($contract['smoke'] as $smoke){$t->same(5,$smoke['request_count']);}
	$t->same('cursor',$contract['onboarding']['target']);
	$t->same('php',$contract['onboarding']['smoke_tests']['format']);
	$t->same(['codex','claude','cursor','generic'],$contract['all']['targets']);
	$t->same($contract['all']['targets'],$contract['all_row_targets']);
	$t->same(['cursor'],$contract['filtered']['targets']);
	$t->same(['codex','claude','cursor','generic'],$contract['fallback']['targets']);
	$t->hasKey('dataphyre_mcp_client_onboarding_pack',$contract['boundaries']);
	$t->hasKey('dataphyre_mcp_live_validate',$contract['boundaries']);
	$t->missingKey('not_registered',$contract['boundaries']);
})->with('MCP client setup boundary contracts');
