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

$startPackContracts=[];
foreach(McpKernelBoundaryHarness::clientStartPackContractNames() as $contract){$startPackContracts[$contract]=[$contract];}
dataset('MCP client start-pack boundary contracts',$startPackContracts);

suite('MCP client start-pack boundary contracts')
	->tag('mcp','client','start-pack','workflow','compact','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.client.start-pack-boundaries',1)
	->layer('contract')
	->risk('high')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.start_pack.php')
	->through('profile proportionality','detail-page selector','readiness resolver','bounded compaction','workflow handoff normalizer')
	->isolation('process');

test('each start-pack boundary explains one compact decision without exposing fixture plumbing',static function(Context $t,string $name): void {
	$contract=(new McpKernelBoundaryHarness($t))->clientStartPackContract($name);
	$t->same($name,$contract['contract']);

	if($name==='start-pack profiles keep detail and policy context proportional'){
		$detail=$contract['detail'];
		$t->same('detail',$detail['profile']);
		$t->contains('This detail profile',$detail['next_read']);
		$t->isTrue($detail['has_operating_contract']);
		$t->isFalse($detail['deep_context_inline']);
		$t->isFalse($detail['has_status_board']);
		$policy=$contract['policy'];
		$t->same('app_owned_policy_attention',$policy['status']);
		$t->same('builder',$policy['default_lane']);
		$t->greaterThan(0,$policy['open_only_for_count']);
		$t->isTrue($policy['has_compact_sensitivity']);
		$t->same('planning',$policy['detail_page']);
		return;
	}

	if($name==='next detail selection follows continuation write verification and control state'){
		$choices=$contract['choices'];
		$t->same('planning',$choices['continuation']['page']);
		$t->same('continue_entity_chunks',$choices['continuation']['status']);
		$t->same('planning',$choices['deferred entities']['page']);
		$t->same('planning',$choices['prewrite path']['page']);
		$t->same('implementation',$choices['ready writes']['page']);
		$t->same('verification',$choices['verification evidence']['page']);
		$t->same('controls',$choices['policy controls']['page']);
		$t->same('planning',$choices['default planning']['page']);
		$t->isFalse(array_key_exists('status',$choices['default planning']));
		foreach($choices as $choice){$t->contains('payload_profile=compact detail_page='.$choice['page'],$choice['open_with']);}
		return;
	}

	if($name==='builder summaries derive readiness previews and continuation state'){
		$readiness=$contract['readiness'];
		$t->same('provided_by_builder',$readiness['provided']['status']);
		$t->same('ready_for_app_owned_writes',$readiness['ready']['status']);
		$t->isTrue($readiness['ready']['ready_for_app_owned_writes']);
		$t->same('resolve_prewrite_blockers',$readiness['blocked']['status']);
		$t->same(1,$readiness['blocked']['blocker_count']);
		$completion=$contract['completion'];
		$t->isTrue($completion['empty']['complete']);
		$t->same('complete_single_chunk',$completion['empty']['status']);
		$t->isFalse($completion['continuing']['complete']);
		$t->same(['Invoice'],$completion['continuing']['deferred_entities']);
		$t->isTrue($completion['continuing']['next_continuation']['available']);
		$t->same('reuse_fields_from_original',$completion['continuing']['next_continuation']['field_scope']);
		$t->isTrue($completion['continuing']['next_continuation']['dependency_context_present']);
		$t->same(['LegacyEntity'],$completion['legacy_continuation']['entities']);
		$t->same('unspecified',$completion['legacy_continuation']['field_scope']);
		$t->isFalse($completion['legacy_continuation']['dependency_context_present']);
		$t->isFalse($completion['missing_continuation']['available']);
		$t->same([],$contract['first_read']['scaffold_completion_summary']);
		$t->same('planning',$contract['first_read']['next_detail_page']['page']);
		$t->same([],$contract['empty_verification']);
		$t->count(12,$contract['file_previews']['bundled']);
		$t->count(12,$contract['file_previews']['fallback']);
		$t->same('Orders use the app-owned repository contract.',$contract['data_model']['explicit']);
		$t->same('inspect existing app schema/repository conventions before adding data artifacts',$contract['data_model']['fallback']);
		$t->same(1,$contract['data_model']['start_model_count']);
		$t->same('Framework/Record/Order.php',$contract['paths']['found']);
		$t->same('',$contract['paths']['missing']);
		$t->same('app_builder',$contract['default_budget']['surface']);
		$t->same('compact_builder_response',$contract['default_budget']['default_payload']);
		return;
	}

	if($name==='discovery compaction is bounded and rejects malformed rows'){
		$finder=$contract['finder'];
		$t->same('tools',$finder['finder_type']);
		$t->isTrue($finder['collapsed']);
		$t->count(2,$finder['matches']);
		$t->same('dataphyre_orders_inspect',$finder['matches'][0]['name']);
		$t->same(42,$finder['matches'][0]['score']);
		$t->count(4,$finder['matches'][0]['match_reasons']);
		$t->contains('...',$finder['matches'][0]['title']);
		$t->same('dataphyre://orders',$finder['matches'][1]['name']);
		$policy=$contract['policy_lane'];
		$t->same('app_owned_policy_attention',$policy['governance_notes']['status']);
		$t->count(8,$policy['data_sensitivity_summary']['categories']);
		$t->same(5,$policy['policy_decision_register']['decision_count']);
		$t->same('keep spacing readable',$contract['text']['short_budget']);
		$t->same('abcde...',$contract['text']['truncated']);
		return;
	}

	$recommendation=$contract['recommendation'];
	$t->same('app_builder',$recommendation['selected_workflow']);
	$t->same(91,$recommendation['selected_score']);
	$t->isTrue($recommendation['ready_to_run']);
	$t->isTrue($recommendation['include_frames']);
	$t->count(1,$recommendation['recommendations']);
	$t->same('ready_for_app_owned_writes',$recommendation['app_builder_next_action']['current_status']);
	$t->count(4,$recommendation['app_builder_next_action']['handoff_pages']);
	$t->count(4,$recommendation['fetch_tools']);
	$fallback=$contract['fallback'];
	$t->isTrue($fallback['ready_to_run']);
	$t->isTrue($fallback['include_frames']);
	$t->same('fallback_action',$fallback['app_builder_next_action']['current_status']);
	$t->count(1,$fallback['recommendations']);
	$t->same($recommendation['fetch_tools'],$fallback['fetch_tools']);
	$t->same('primary_action',$contract['primary']['app_builder_next_action']['current_status']);
	$t->same('not_applicable',$contract['scalar_action']);
	$t->same([],$contract['non_array_recommendations']);
})->with('MCP client start-pack boundary contracts');
