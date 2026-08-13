<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Testing\McpWorkflowStateBoundaryHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpTestKit.php';

$stateContracts=[];
foreach(McpWorkflowStateBoundaryHarness::contractNames() as $contract){$stateContracts[$contract]=[$contract];}
dataset('MCP workflow-state boundary contracts',$stateContracts);

suite('MCP client-owned workflow-state lifecycle contracts')
	->tag('mcp','workflow','state','audit','summary','transition','sync','timeline','resume','boundary','contract')
	->group('framework-coverage')
	->contract('mcp.workflow.state-boundaries',1)
	->layer('contract')
	->risk('critical')
	->watches('module:mcp','path:runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.state.php')
	->through('input resolver','state builder','shape audit','redaction audit','summary handoff','transition patch','sync pack','timeline','resume brief')
	->isolation('process')
	->coverageMemoryLimit('1G');

test('each state boundary reads as a client-owned lifecycle contract',static function(Context $t,string $name): void {
	$contract=(new McpWorkflowStateBoundaryHarness($t))->contract($name);
	$t->same($name,$contract['contract']);

	if($name==='state input preserves object JSON missing and malformed provenance'){
		$t->same('array',$contract['array']['source']);
		$t->same('contract-state',$contract['array']['state']['state_id']);
		$t->same('',$contract['array']['parse_error']);
		$t->same('json',$contract['json']['source']);
		$t->same($contract['array']['state'],$contract['json']['state']);
		$t->same('array',$contract['array_precedence']['source']);
		$t->same('missing',$contract['missing']['source']);
		$t->same([],$contract['missing']['state']);
		$t->same('invalid_json',$contract['invalid']['source']);
		$t->contains('could not be decoded',$contract['invalid']['parse_error']);
		$t->same('routes',$contract['schemas']['normalized']['workflow']);
		$t->same('routes',$contract['schemas']['normalized']['example_state']['workflow']);
		$t->same('generic',$contract['schemas']['fallback']['workflow']);
		return;
	}

	if($name==='audit separates lifecycle shape tool and redaction failures'){
		$valid=$contract['valid'];
		$t->isTrue($valid['passed']);
		$t->same(0,$valid['error_count']);
		$t->same(0,$valid['warning_count']);
		$t->same(1,$valid['pending_tool_count']);
		$t->same(1,$valid['completed_tool_count']);
		$t->isTrue($contract['missing_workflow']['passed']);
		$t->contains('missing_workflow',$contract['missing_workflow']['codes']);
		$problem=$contract['problem'];
		$t->isFalse($problem['passed']);
		$t->same('feature',$problem['workflow']);
		$t->same('routes',$problem['expected_workflow']);
		$t->greaterThan(0,$problem['error_count']);
		$t->greaterThan(7,$problem['warning_count']);
		foreach(['workflow_mismatch','missing_updated_at','missing_client','unknown_current_phase','unknown_last_decision','unknown_checkpoint_status','invalid_last_tool','invalid_pending_tools','invalid_findings','invalid_notes','unknown_tools','redaction_risk'] as $code){$t->contains($code,$problem['codes']);}
		foreach(['secret_assignment','bearer_token','connection_string','absolute_windows_path','signed_url_parameter','scoped_identifier'] as $signal){$t->contains($signal,$problem['redaction_signals']);}
		$t->isFalse($contract['malformed']['passed']);
		$t->same('generic',$contract['malformed']['expected_workflow']);
		$t->contains('missing_state',$contract['malformed']['codes']);
		$t->contains('parse_error',$contract['malformed']['codes']);
		$t->isFalse($contract['missing']['passed']);
		$t->notContains('parse_error',$contract['missing']['codes']);
		return;
	}

	if($name==='summaries bound and redact handoff state without release-gate leakage'){
		$safe=$contract['safe'];
		$t->same('client',$safe['workflow']);
		$t->isTrue($safe['audit_passed']);
		$t->same(['dataphyre_mcp_workflow_state_audit'],$safe['pending_tools']);
		$t->same(['dataphyre_mcp_workflow_state_audit','dataphyre_mcp_workflow_next_action_export'],$safe['agent_handoff']['next_tools']);
		$t->sameKeys($safe['agent_handoff']['next_tools'],$safe['agent_handoff']['next_tool_boundaries']);
		$t->isFalse(in_array('dataphyre_mcp_verify_all',$safe['agent_handoff']['next_tools'],true));
		$t->same('copy_safe_resume_ready',$safe['agent_handoff']['copy_safe_resume']['status']);
		$redacted=$contract['redacted'];
		$t->isFalse($redacted['audit_passed']);
		$t->same(['dataphyre_mcp_workflow_state_audit'],$redacted['pending_tools']);
		$t->notContains('abcdefghijklmnop',$redacted['notes'][0]);
		$t->notContains('Alice',$redacted['notes'][1]);
		$t->notContains('hunter2',$redacted['state_findings'][0]);
		$t->notContains('acme',$redacted['state_findings'][1]);
		$t->same([],$contract['empty_items']);
		$t->same([],$contract['zero_window']);
		return;
	}

	if($name==='transitions and sync packs describe client-owned lifecycle patches'){
		$checkpoint=$contract['checkpoint'];
		$t->same('summarize_or_verify',$checkpoint['decision']);
		$t->same('dataphyre_mcp_workflow_state_summary_export',$checkpoint['recommended_tool']);
		$t->same('handoff_summary',$checkpoint['next_phase']);
		$t->contains('dataphyre_mcp_workflow_checkpoint_export',$checkpoint['suggested_patch']['completed_tools']);
		$t->notContains('abcdefghijklmnop',$checkpoint['suggested_patch']['task']);
		$t->same('pre_run_handoff',$contract['transitions']['start']['next_phase']);
		$t->same('start_workflow',$contract['transitions']['start']['decision']);
		$t->same('audit',$contract['transitions']['review']['next_phase']);
		$t->same('review_transcript',$contract['transitions']['review']['decision']);
		$t->same('fix_blocked_transcript',$contract['transitions']['blocked']['decision']);
		$t->same('done',$contract['transitions']['done']['next_phase']);
		$t->same('done',$contract['transitions']['done']['decision']);
		$sync=$contract['sync'];
		$t->isTrue($sync['audit_passed']);
		$t->same('checkpoint',$sync['current_phase']);
		$t->same('handoff_summary',$sync['next_phase']);
		$t->same($sync['transition']['recommended_tool'],$sync['recommended_tool']);
		$t->isFalse($contract['malformed_sync']['audit_passed']);
		$t->same('generic',$contract['malformed_sync']['workflow']);
		return;
	}

	$timeline=$contract['timeline'];
	$t->same('routes',$timeline['workflow']);
	$t->same('client_run',$timeline['current_phase']);
	$t->same('audit',$timeline['next_phase']);
	$t->same('current',$timeline['timeline'][3]['status']);
	$t->same('next',$timeline['timeline'][5]['status']);
	foreach([0,1,2] as $index){$t->same('completed_or_skipped',$timeline['timeline'][$index]['status']);}
	$resume=$contract['resume'];
	$t->same('copy_safe_resume_ready',$resume['copy_safe_resume']['status']);
	$t->same('client_run',$resume['current']['phase']);
	$t->same('audit',$resume['next']['phase']);
	$t->same('dataphyre_mcp_workflow_transcript_audit',$resume['next']['tool']);
	$t->same('resume_from_summary_not_raw_payloads',$contract['continuity']['default']);
	$t->same('dataphyre_mcp_task_start_pack_export',$contract['phase_tools']['start']);
	$t->same('dataphyre_mcp_workflow_recommend',$contract['phase_tools']['choose_workflow']);
	$t->same('dataphyre_mcp_workflow_handoff_pack_export',$contract['phase_tools']['pre_run_handoff']);
	$t->same('dataphyre_mcp_workflow_session_export',$contract['phase_tools']['client_run']);
	$t->same('dataphyre_mcp_workflow_transcript_schema_export',$contract['phase_tools']['capture']);
	$t->same('dataphyre_mcp_workflow_transcript_audit',$contract['phase_tools']['audit']);
	$t->same('dataphyre_mcp_workflow_checkpoint_export',$contract['phase_tools']['checkpoint']);
	$t->same('dataphyre_mcp_workflow_state_summary_export',$contract['phase_tools']['handoff_summary']);
	$t->same('focused_application_or_module_verification',$contract['phase_tools']['verify']);
	$t->same('dataphyre_mcp_workflow_state_sync_pack_export',$contract['phase_tools']['done']);
	$t->same('dataphyre_mcp_workflow_next_action_export',$contract['phase_tools']['unknown']);
})->with('MCP workflow-state boundary contracts');
