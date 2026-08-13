<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpTranscriptBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$transcriptContracts=[];
foreach(McpTranscriptBoundaryHarness::contractNames() as $contract){$transcriptContracts[$contract]=[$contract];}
dataset('MCP workflow transcript boundary contracts',$transcriptContracts);

suite('MCP workflow transcript boundary contracts')
	->tag('mcp','workflow','transcript','audit','summary','checkpoint','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.workflow.transcript-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.transcript.php')
	->through('input resolver','shape audit','redaction audit','summary window','checkpoint classifier','handoff pack')
	->isolation('process')
	->coverageMemoryLimit('1G');

test('each transcript boundary reads as a workflow contract rather than nested fixture construction',static function(Context $t,string $name): void {
	$contract=(new McpTranscriptBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='transcript input accepts objects and JSON while rejecting malformed payloads'){
		$t->same('array',$contract['array']['source']);
		$t->same('contract-transcript',$contract['array']['transcript']['transcript_id']);
		$t->same('',$contract['array']['parse_error']);
		$t->same('json',$contract['json']['source']);
		$t->same($contract['array']['transcript'],$contract['json']['transcript']);
		$t->same('array',$contract['array_precedence']['source']);
		$t->same('missing',$contract['missing']['source']);
		$t->same([],$contract['missing']['transcript']);
		$t->same('invalid_json',$contract['invalid']['source']);
		$t->contains('could not be decoded',$contract['invalid']['parse_error']);
		$t->same('routes',$contract['workflow_names']['normalized']);
		$t->same('feature',$contract['workflow_names']['fallback']);
		return;
	}

	if($name==='audit separates shape tool registration and redaction failures'){
		$valid=$contract['valid'];
		$t->isTrue($valid['passed']);
		$t->same(0,$valid['error_count']);
		$t->same(0,$valid['warning_count']);
		$t->same(2,$valid['step_count']);
		$t->isTrue($contract['missing_workflow']['passed']);
		$t->same('feature',$contract['missing_workflow']['workflow']);
		$t->contains('missing_workflow',$contract['missing_workflow']['codes']);
		$problem=$contract['problem'];
		$t->isFalse($problem['passed']);
		$t->same('feature',$problem['workflow']);
		$t->same('routes',$problem['expected_workflow']);
		$t->greaterThan(1,$problem['error_count']);
		$t->greaterThan(4,$problem['warning_count']);
		$t->same(52,$problem['step_count']);
		foreach(['workflow_mismatch','unknown_final_status','invalid_step','unknown_tools','missing_content_summaries','missing_result_keys','transcript_too_large_for_full_audit','redaction_risk'] as $code){$t->contains($code,$problem['codes']);}
		foreach(['secret_assignment','bearer_token','connection_string','absolute_windows_path','signed_url_parameter','scoped_identifier'] as $signal){$t->contains($signal,$problem['redaction_signals']);}
		$malformed=$contract['malformed'];
		$t->isFalse($malformed['passed']);
		$t->same('generic',$malformed['expected_workflow']);
		$t->contains('parse_error',$malformed['codes']);
		$t->contains('missing_transcript',$malformed['codes']);
		$t->isFalse($contract['missing']['passed']);
		$t->notContains('parse_error',$contract['missing']['codes']);
		return;
	}

	if($name==='summary windows preserve totals and application-first handoffs'){
		$summary=$contract['summary'];
		$t->same('client',$summary['workflow']);
		$t->isTrue($summary['audit_passed']);
		$t->same(3,$summary['step_count']);
		$t->same(1,$summary['completed_step_count']);
		$t->same(1,$summary['failed_step_count']);
		$t->same(1,$summary['unknown_step_count']);
		$t->same(2,$summary['step_summary_count']);
		$t->same(1,$summary['omitted_step_count']);
		$t->isTrue($summary['step_window']['truncated']);
		$t->same(2,$summary['step_window']['returned_steps']);
		$t->same(['dataphyre_mcp_workflow_state_audit','dataphyre_mcp_workflow_readiness_audit'],$summary['agent_handoff']['next_tools']);
		$t->isFalse(in_array('dataphyre_mcp_verify_all',$summary['agent_handoff']['next_tools'],true));
		$t->sameKeys($summary['agent_handoff']['next_tools'],$summary['agent_handoff']['next_tool_boundaries']);
		$t->same(['defaulted'=>20,'bounded'=>50,'custom'=>3],$contract['limits']);
		$t->same(0,$contract['non_omitting_window']['omitted_steps']);
		$t->isFalse($contract['non_omitting_window']['truncated']);
		return;
	}

	if($name==='checkpoints classify empty healthy review and blocked progress'){
		$checkpoints=$contract['checkpoints'];
		$t->same('empty',$checkpoints['empty']['checkpoint_status']);
		$t->same(0,$checkpoints['empty']['progress']['total_steps']);
		$t->same('healthy',$checkpoints['healthy']['checkpoint_status']);
		$t->isTrue($checkpoints['healthy']['safe_to_share']);
		$t->same(1,$checkpoints['healthy']['progress']['completed_steps']);
		$t->same('blocked',$checkpoints['blocked']['checkpoint_status']);
		$t->same(1,$checkpoints['blocked']['progress']['failed_steps']);
		$t->same('needs_review',$checkpoints['review']['checkpoint_status']);
		$t->isFalse($checkpoints['review']['safe_to_share']);
		$t->greaterThan(0,$checkpoints['review']['audit']['error_count']);
		foreach($checkpoints as $checkpoint){$t->same($checkpoint['progress']['returned_step_checkpoints'],count($checkpoint['step_checkpoints']));}
		return;
	}

	$schema=$contract['schema'];
	$t->same('generic',$schema['workflow']);
	$t->same('generic',$schema['example_transcript']['workflow']);
	$t->count(3,$schema['validation_tool_boundaries']);
	$t->contains('hard_cap',$schema['schema']['step_window']);
	$handoff=$contract['handoff'];
	$t->same('client',$handoff['workflow']);
	$t->isFalse($handoff['include_frames']);
	$t->same('client',$handoff['transcript_schema']['workflow']);
	$t->count(3,$handoff['post_run_tools']);
	$t->same(['dataphyre_mcp_workflow_state_audit','123'],$contract['app_tools']);
	$t->count(9,$contract['boundaries']);
	$t->same('publication_validation_not_ordinary_app_work',$contract['boundaries']['dataphyre_mcp_verify_all']['audience_scope']);
	$t->isFalse(array_key_exists('not_registered',$contract['boundaries']));
})->with('MCP workflow transcript boundary contracts');
