<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpKernelBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpKernelBoundaryTestKit.php';

$briefContracts=[];
foreach(McpKernelBoundaryHarness::clientBriefContractNames() as $contract){$briefContracts[$contract]=[$contract];}
dataset('MCP client brief boundary contracts',$briefContracts);

suite('MCP client brief boundary contracts')
	->tag('mcp','client','brief','compact','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.client.brief-boundaries',1)
	->layer('contract')
	->risk('high')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.client.brief.php')
	->through('agent brief export','first-read compactor','payload budget','next-action pointer','write-start packet')
	->isolation('process');

test('each client brief boundary preserves a compact actionable self-describing handoff',static function(Context $t,string $name): void {
	$contract=(new McpKernelBoundaryHarness($t))->clientBriefContract($name);
	$t->same($name,$contract['contract']);
	if($name==='ordinary app builds receive a first-page-only brief'){
		$t->same('dataphyre_mcp_agent_brief_export',$contract['export_type']);
		$t->same('not_executed',$contract['execution']);
		$t->same('generic',$contract['target']);
		$t->notEmpty($contract['selected_workflow']);
		$t->same('Builder first read',$contract['first_read_title']);
		$t->isFalse($contract['has_builder_view']);
		$t->contains('compact_app_builder_plan',$contract['context_links']);
		return;
	}
	if($name==='elevated inspection briefs retain audit context without executing work'){
		$t->same('dataphyre_mcp_agent_brief_export',$contract['export_type']);
		$t->same('not_executed',$contract['execution']);
		$t->same('codex',$contract['target']);
		$t->isTrue($contract['inspection_active']);
		$t->isFalse($contract['app_builder_active']);
		$t->isTrue(is_array($contract['enterprise_audit']));
		$t->same('dataphyre_mcp_enterprise_adoption_audit',$contract['enterprise_audit']['tool']);
		$t->isTrue($contract['has_operating_contract']);
		$t->isTrue($contract['has_context_sources']);
		$t->same('cursor',$contract['ordinary']['target']);
		$t->isTrue($contract['ordinary']['inspection_active']);
		$t->isFalse($contract['ordinary']['has_enterprise_audit']);
		$t->isFalse($contract['ordinary']['has_operating_contract']);
		$t->isFalse($contract['ordinary']['has_context_sources']);
		$t->same('inspection',$contract['ordinary']['governance_notes']['default_lane']);
		return;
	}
	$budget=$contract['budget'];
	$t->same('agent_brief',$budget['surface']);
	$t->same(18000,$budget['max_response_chars']);
	$t->same('first_page_only',$budget['default_payload']);
	$t->same(7,$budget['escalation_policy']['use_extension_points_first_count']);
	$t->greaterThan(0,$budget['escalation_policy']['do_not_escalate_for_count']);
	$t->greaterThan(0,$budget['escalation_policy']['escalate_only_for_count']);
	$t->same(['status'=>'ready'],$contract['governance_notes']['array']);
	$t->same('none triggered',$contract['governance_notes']['default']);
	$t->same(['status'=>'ready','default_lane'=>'inspection','open_only_for'=>['security']],$contract['governance_notes']['compact_array']);
	$t->same(['status'=>'none triggered','default_lane'=>'inspection','open_only_for'=>['release']],$contract['governance_notes']['compact_default']);
	$firstRead=$contract['first_read'];
	$t->same(5,$firstRead['naming_contract']['mapping_count']);
	$t->count(4,$firstRead['naming_contract']['mappings']);
	$t->same(6,$firstRead['write_readiness']['handoff_fields_count']);
	$t->isFalse(array_key_exists('handoff_fields',$firstRead['write_readiness']));
	$t->isFalse(array_key_exists('not_required',$firstRead['write_readiness']));
	$t->same(2,$firstRead['scaffold_completion_summary']['continuation_count']);
	$t->same(5,$firstRead['verification_handoff']['copy_safe_fields_count']);
	$t->same('dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',$firstRead['files_summary']['open_with']);
	$t->same('commerce',$contract['app_path']['application_id']);
	$ready=$contract['ready_action'];
	$t->isTrue($ready['write_start_packet']['can_write_now']);
	$t->same(6,$ready['resume_cursor']['copy_forward_count']);
	$t->count(3,$ready['write_start_packet']['first_probe']['inspect_globs']);
	$t->count(4,$ready['write_start_packet']['first_probe']['signals']);
	$t->same('omitted_until_ready_for_app_owned_writes',$contract['deferred_action']['write_start_packet_inline']);
	$t->same('builder_first_read.next_action.resume_cursor',$contract['pointer_action']['resume_cursor_ref']);
	$t->same(6,$contract['pointer_action']['copy_forward_count']);
	$t->same(3,$contract['count_cursor']['copy_forward_count']);
	$t->same('app_owned_policy_attention',$contract['policy_payload']['policy_attention']['status']);
	$t->count(8,$contract['policy_payload']['policy_attention']['categories']);
	$t->isTrue($contract['policy_payload']['elevated_review']['required']);
})->with('MCP client brief boundary contracts');
