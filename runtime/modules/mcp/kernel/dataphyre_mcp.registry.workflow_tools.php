<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Dataphyre MCP workflow and handoff tool descriptors.
 */
trait dataphyre_mcp_registry_workflow_tool_surfaces {

	/**
	 * Returns workflow, state, transcript, handoff, start-pack, and brief tool descriptors.
	 *
	 * @return array<int,array<string,mixed>> MCP tool descriptors.
	 */
	private function mcp_workflow_tool_descriptors(): array {
		return [
			$this->tool('dataphyre_mcp_workflow_playbook_export', 'Export ordered read-only MCP workflow playbooks for common Dataphyre agent tasks.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow filter: feature, routes, sql, diagnostics, client, release, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_workflow_readiness_audit', 'Audit MCP workflow playbooks for registered tools, prompts, examples, and docs coverage.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow filter: feature, routes, sql, diagnostics, client, release, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_workflow_session_export', 'Export portable JSON-RPC message sessions for ready MCP workflow playbooks without executing them.', [
				'workflow'=>['type'=>'string', 'description'=>'Workflow session to export: feature, routes, sql, diagnostics, client, or release. Defaults client.'],
				'include_frames'=>['type'=>'boolean', 'description'=>'Include stdio Content-Length framed messages. Defaults true.'],
			]),
			$this->tool('dataphyre_mcp_workflow_transcript_schema_export', 'Export a redaction-aware schema for recording MCP workflow request and response transcripts.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow label for the transcript schema: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_schema_export', 'Export a read-only client-side workflow state schema for carrying MCP workflow progress between turns.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow label for the state schema: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_audit', 'Audit a client-owned MCP workflow state envelope for shape, redaction, phase, decision, and registered-tool issues.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to audit.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to audit when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_summary_export', 'Export a compact safe handoff summary from a client-owned MCP workflow state envelope.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to summarize.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to summarize when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_transition_export', 'Export a read-only suggested client-side workflow state transition patch from current state and next-action guidance.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to transition.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to transition when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'task'=>['type'=>'string', 'description'=>'Optional task, symptom, or intent text to carry into next-action guidance.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_sync_pack_export', 'Export a read-only workflow state sync pack with schema, audit, summary, transition, and next-action guidance.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to package.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to package when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'task'=>['type'=>'string', 'description'=>'Optional task, symptom, or intent text to carry into next-action guidance.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_timeline_export', 'Export a compact read-only timeline view from client-owned workflow state and transition guidance.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to map into a timeline.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to map when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'task'=>['type'=>'string', 'description'=>'Optional task, symptom, or intent text to carry into next-action guidance.'],
			]),
			$this->tool('dataphyre_mcp_workflow_state_resume_brief_export', 'Export a compact read-only agent resume brief from client-owned workflow state continuity data.', [
				'state'=>['type'=>'object', 'description'=>'Optional decoded workflow state object to brief.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional workflow state JSON string to brief when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'task'=>['type'=>'string', 'description'=>'Optional task, symptom, or intent text to carry into next-action guidance.'],
			]),
			$this->tool('dataphyre_mcp_workflow_transcript_audit', 'Audit a client-captured MCP workflow transcript shape for redaction, status, and consistency issues.', [
				'transcript'=>['type'=>'object', 'description'=>'Optional decoded transcript object to audit.'],
				'transcript_json'=>['type'=>'string', 'description'=>'Optional transcript JSON string to audit when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
			]),
			$this->tool('dataphyre_mcp_workflow_transcript_summary_export', 'Export a compact safe agent handoff summary from a client-captured workflow transcript with step-window metadata.', [
				'transcript'=>['type'=>'object', 'description'=>'Optional decoded transcript object to summarize.'],
				'transcript_json'=>['type'=>'string', 'description'=>'Optional transcript JSON string to summarize when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'max_summary_steps'=>['type'=>'integer', 'description'=>'Maximum transcript steps to return in step_summaries. Defaults 20 and is hard capped at 50; step_window reports omitted steps.'],
			]),
			$this->tool('dataphyre_mcp_workflow_checkpoint_export', 'Export a compact read-only progress checkpoint from a client-captured workflow transcript with step-window metadata.', [
				'transcript'=>['type'=>'object', 'description'=>'Optional decoded transcript object to checkpoint.'],
				'transcript_json'=>['type'=>'string', 'description'=>'Optional transcript JSON string to checkpoint when structured objects are not available.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'task'=>['type'=>'string', 'description'=>'Optional task text to carry into the checkpoint.'],
				'max_summary_steps'=>['type'=>'integer', 'description'=>'Maximum transcript steps to return in step_checkpoints. Defaults 20 and is hard capped at 50; progress.step_window reports omitted steps.'],
			]),
			$this->tool('dataphyre_mcp_workflow_handoff_pack_export', 'Export a pre-run MCP workflow handoff pack with playbook, readiness, session, and transcript guidance.', [
				'workflow'=>['type'=>'string', 'description'=>'Workflow handoff to export: feature, routes, sql, diagnostics, client, or release. Defaults client.'],
				'include_frames'=>['type'=>'boolean', 'description'=>'Include stdio Content-Length frames in the session export. Defaults true.'],
			]),
			$this->tool('dataphyre_mcp_workflow_catalog', 'Catalog available MCP workflows with readiness, prompts, step counts, and handoff tools.', []),
			$this->tool('dataphyre_mcp_workflow_lifecycle_export', 'Export a read-only workflow lifecycle runbook from task start through checkpoint and verification.', [
				'workflow'=>['type'=>'string', 'description'=>'Optional workflow label: feature, routes, sql, diagnostics, client, release, or all. Defaults all.'],
			]),
			$this->tool('dataphyre_mcp_workflow_next_action_export', 'Export a read-only workflow next-action decision from task text and optional transcript state; ordinary app-building decisions use builder_response.first_read.next_action and next_detail_page mirrored as compact app_builder_next_action, compact write_readiness, diagnostics only after focused failures, apply_audit_handoff only when writes are ready, and focused verification guidance.', [
				'task'=>['type'=>'string', 'description'=>'Optional task, symptom, or intent text.'],
				'workflow'=>['type'=>'string', 'description'=>'Optional expected workflow label: feature, routes, sql, diagnostics, client, release, or generic. Defaults generic.'],
				'transcript'=>['type'=>'object', 'description'=>'Optional decoded client-captured transcript object.'],
				'transcript_json'=>['type'=>'string', 'description'=>'Optional client-captured transcript JSON string.'],
				'state'=>['type'=>'object', 'description'=>'Optional decoded client-owned workflow state object.'],
				'state_json'=>['type'=>'string', 'description'=>'Optional client-owned workflow state JSON string.'],
			]),
			$this->tool('dataphyre_mcp_workflow_recommend', 'Recommend ranked MCP workflows for task text. Ordinary app-building recommendations point agents to dataphyre_app_builder_plan_generate with builder_response.first_read.next_action and next_detail_page mirrored as app_builder_next_action, entity_planning.continuation_calls when deferred entities remain, compact write_readiness, diagnostics only after focused failures, apply_audit_handoff only when writes are ready, and focused verification before broader workflow context.', [
				'task'=>['type'=>'string', 'description'=>'Task, symptom, or intent text to match against available MCP workflows.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum workflow recommendations to return, default 3.'],
			]),
			$this->tool('dataphyre_mcp_workflow_recommendation_handoff_export', 'Recommend a workflow from task text and export or link the top pre-run handoff pack. App-building handoffs keep runnable workflow sessions behind handoff_pack_ref while propagating builder_response.first_read.next_action and next_detail_page mirrored as app_builder_next_action, deferred entity chunks, compact write_readiness, diagnostics only after focused failures, apply_audit_handoff only when writes are ready, and focused verification.', [
				'task'=>['type'=>'string', 'description'=>'Task, symptom, or intent text to match against available MCP workflows.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum recommendations to include for context, default 3.'],
				'include_frames'=>['type'=>'boolean', 'description'=>'Include stdio Content-Length frames in the selected handoff session. Defaults true.'],
			]),
			$this->tool('dataphyre_mcp_task_start_pack_export', 'Export builder-profile cold-start context after app-builder compact planning or an agent brief needs broader workflow discovery; defaults payload_profile=builder.', array_merge([
				'task'=>['type'=>'string', 'description'=>'Task, symptom, or intent text for the agent start pack.'],
				'target'=>['type'=>'string', 'description'=>'Agent target for instruction context: codex, claude, cursor, or generic.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum recommendations and discovery matches to include, default 4.'],
			], $this->mcp_app_builder_argument_schemas(['entities', 'max_entities', 'application_path', 'app_namespace', 'fields'], 'builder_context', 'compact'), [
				'include_frames'=>['type'=>'boolean', 'description'=>'Include stdio Content-Length frames in the selected handoff session. Defaults false.'],
				'payload_profile'=>['type'=>'string', 'description'=>'Optional profile: builder for compact ordinary app work; detail adds contracts and discovery while app-builder bulk stays paginated; deep is explicit escalation evidence with status/safety, enterprise audit, or full workflow handoff. Defaults builder.'],
				'include_detail_context'=>['type'=>'boolean', 'description'=>'Inline full contracts, tool audience boundaries, and discovery matches. Defaults false for builder profile and true for explicit detail/deep.'],
				'include_deep_context'=>['type'=>'boolean', 'description'=>'Inline status, safety, enterprise audit, and full workflow handoff context. Defaults false; set true or use payload_profile=deep for explicit elevated review payloads.'],
			])),
			$this->tool('dataphyre_mcp_agent_brief_export', 'Export a compact read-only brief; app-building tasks use the direct app-builder fast lane with first-view guidance, next_detail_page, and collapsed enterprise context.', array_merge([
				'task'=>['type'=>'string', 'description'=>'Task, symptom, or intent text for the agent brief.'],
				'target'=>['type'=>'string', 'description'=>'Agent target for instruction context: codex, claude, cursor, or generic.'],
				'limit'=>['type'=>'integer', 'description'=>'Maximum recommendations and discovery matches to inspect, default 4.'],
			], $this->mcp_app_builder_argument_schemas(['entities', 'max_entities', 'application_path', 'app_namespace', 'fields'], 'brief', 'compact'))),
		];
	}

}
