<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP task start-pack and compact app-builder workflow summaries.
 */
trait dataphyre_mcp_client_workflow_start_pack_surfaces {

	/**
	 * Builds a starter pack for beginning an MCP-assisted task.
	 *
	 * @param array<string,mixed> $args Task description and startup options.
	 * @return array Task start pack payload.
	 */
	private function mcp_task_start_pack_export(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$limit=max(1, min(8, (int)($args['limit'] ?? 4)));
		$include_frames=($args['include_frames'] ?? false)===true;
		$proportional_guidance=$this->mcp_task_proportional_guidance($task);
		$payload_profile=strtolower(trim((string)($args['payload_profile'] ?? '')));
		if(!in_array($payload_profile, ['builder', 'detail', 'deep'], true)){
			$payload_profile='builder';
		}
		$include_deep_context=($args['include_deep_context'] ?? null);
		$include_deep_context=is_bool($include_deep_context)
			? $include_deep_context
			: $payload_profile==='deep';
		$include_detail_context=($args['include_detail_context'] ?? null);
		$include_detail_context=is_bool($include_detail_context)
			? $include_detail_context
			: ($payload_profile==='detail' || $include_deep_context);
		$app_builder_task=$this->mcp_task_implies_app_builder($task);
		$app_builder_lane=$this->app_builder_lane($task, $args);
		$builder_start=$this->mcp_app_builder_start_summary($app_builder_lane);
		$builder_write_readiness=is_array($builder_start['write_readiness'] ?? null) && $builder_start['write_readiness']!==[]
			? $builder_start['write_readiness']
			: $this->app_builder_write_readiness($builder_start['scaffold_completion_summary'] ?? $this->mcp_app_builder_scaffold_completion_summary($app_builder_lane['entity_planning'] ?? []), $builder_start['prewrite_checklist'] ?? []);
		$builder_governance_notes=is_array($builder_start['governance_notes'] ?? null)
			? $builder_start['governance_notes']
			: 'none triggered';
		$builder_preview_summaries=is_array($builder_start['preview_summaries'] ?? null) ? $builder_start['preview_summaries'] : [];
		$builder_first_read=$app_builder_task ? $this->mcp_app_builder_first_read($builder_start, $builder_preview_summaries, [
			'planning'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
			'verification'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
			'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
			'full_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		]) : [];
		if($app_builder_task && !$include_detail_context){
			$builder_first_read=$this->mcp_agent_brief_compact_first_read($builder_first_read);
			if(is_array($builder_first_read['next_action'] ?? null)){
				$builder_first_read['next_action']=$this->mcp_agent_brief_compact_next_action($builder_first_read['next_action'], true);
			}
		}
		$app_builder_lane_payload=$app_builder_task===false ? [
			'active'=>false,
			'lane'=>'inspection',
			'purpose'=>'Not active for read-only inspection tasks.',
			'progressive_disclosure'=>'Use inspection_view first; open dataphyre_app_builder_plan_generate only if the task becomes create/build/scaffold implementation work.',
			'entrypoint_tool'=>'dataphyre_app_builder_plan_generate',
			'activation_examples'=>[
				'build an internal app',
				'create a Panel resource',
				'add CRUD with filters and actions',
			],
		] : ($include_deep_context ? $app_builder_lane : $this->mcp_app_builder_compact_lane($app_builder_lane, $builder_start, $builder_first_read, $builder_preview_summaries, $builder_write_readiness, $builder_governance_notes));
		$workflow_recommendation=$this->mcp_workflow_recommend(['task'=>$task, 'limit'=>$limit]);
		$workflow_handoff_full=$include_deep_context ? $this->mcp_workflow_recommendation_handoff_export([
			'task'=>$task,
			'limit'=>$limit,
			'include_frames'=>$include_frames,
		]) : null;
		$workflow_handoff=is_array($workflow_handoff_full)
			? $workflow_handoff_full
			: $this->mcp_workflow_recommendation_summary($workflow_recommendation, $task, $include_frames);
		$agent_context=$this->generate_agent_context(['target'=>$target]);
		$tool_finder=$this->mcp_tool_finder([
			'query'=>$task,
			'group'=>'',
			'limit'=>$limit,
		]);
		$resource_query=$include_detail_context ? trim('workflow '.$task) : $task;
		$resource_finder=$this->mcp_resource_finder([
			'query'=>$resource_query,
			'kind'=>'all',
			'limit'=>$limit,
		]);
		$enterprise_audit=$include_deep_context ? $this->mcp_enterprise_adoption_audit([
			'feature'=>$task,
			'public_claim'=>$this->mcp_task_implies_release_claim($task),
		]) : null;
		$status_board=$include_deep_context ? $this->mcp_status_board() : null;
		$safety_boundary=$include_deep_context ? $this->mcp_safety_boundary_report() : null;
		$deep_context=[
			'included_inline'=>$include_deep_context,
			'default_for_lightweight_tasks'=>'summaries_and_links_only',
			'open_when'=>$app_builder_lane['governance_lane']['open_when'] ?? [
				'corporate-ready, public Dataphyre framework, or release-facing claims',
				'security, identity/access, session, credential, tenant isolation, privacy, compliance, billing, audit, data residency, retention, or legal-hold behavior',
				'Dataphyre runtime-internal or shared production hot-path changes',
			],
			'fetch_tools'=>[
				'status_board'=>'dataphyre_mcp_status_board',
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
				'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
			'workflow_handoff'=>'dataphyre_mcp_workflow_recommendation_handoff_export',
			'workflow_handoff_pack'=>'dataphyre_mcp_workflow_handoff_pack_export',
			],
			'safety_summary'=>[
				'default_safety'=>$safety_boundary['default_safety'] ?? 'read_only',
				'unsafe_enabled'=>$safety_boundary['unsafe_enabled'] ?? false,
				'redaction_policy'=>$safety_boundary['redaction_policy'] ?? ['redact secrets, credentials, tenant-identifying values, and raw local paths from shared summaries'],
			],
			'enterprise_summary'=>[
				'review_required'=>($proportional_guidance['enterprise_review_required'] ?? false)===true,
				'tier'=>$proportional_guidance['tier'] ?? 'lightweight',
				'computed_inline'=>is_array($enterprise_audit),
				'attention_count'=>is_array($enterprise_audit) ? (int)($enterprise_audit['attention_count'] ?? 0) : 0,
				'attention_ids'=>is_array($enterprise_audit) ? ($enterprise_audit['attention_ids'] ?? []) : [],
				'contract_resource'=>is_array($enterprise_audit) ? ($enterprise_audit['contract_resource'] ?? 'dataphyre://agentic-enterprise') : 'dataphyre://agentic-enterprise',
			],
		];
		$builder_response=$app_builder_task && !$include_detail_context
			? $this->mcp_app_builder_compact_builder_view($builder_start, $app_builder_lane, $builder_first_read, $builder_preview_summaries, $builder_write_readiness, $builder_governance_notes)
			: ($app_builder_task ? [
			'title'=>'Builder plan',
			'first_read'=>$builder_first_read,
			'next_action'=>$builder_start['next_action'] ?? [],
			'agent_workload'=>$this->mcp_app_builder_workload_budget(),
			'files'=>$builder_start['files'] ?? [],
			'files_summary'=>$builder_preview_summaries['files'] ?? [],
			'schema'=>$builder_start['schema'] ?? [],
			'schema_summary'=>$builder_preview_summaries['schema'] ?? [],
			'naming_contract'=>$builder_start['naming_contract'] ?? [],
			'entity_input_contract'=>$builder_start['entity_input_contract'] ?? [],
			'entity_planning'=>$builder_start['entity_planning'] ?? [],
			'scaffold_completion_summary'=>$builder_start['scaffold_completion_summary'] ?? [],
			'surface_execution_plan'=>$builder_start['surface_execution_plan'] ?? [],
			'companion_surface_handoff'=>$builder_start['companion_surface_handoff'] ?? [],
			'relationship_contract_summary'=>$builder_start['relationship_contract_summary'] ?? [],
			'relationship_adapter_handoff'=>$builder_start['relationship_adapter_handoff'] ?? [],
			'field_metadata_summary'=>$builder_start['field_metadata_summary'] ?? [],
			'data_model_handoff'=>$builder_start['data_model_handoff'] ?? [],
			'data_integrity_summary'=>$builder_start['data_integrity_summary'] ?? [],
			'lifecycle_policy_summary'=>$builder_start['lifecycle_policy_summary'] ?? [],
			'lifecycle_state_handoff'=>$builder_start['lifecycle_state_handoff'] ?? [],
			'audit_retention_summary'=>$builder_start['audit_retention_summary'] ?? [],
			'audit_retention_handoff'=>$builder_start['audit_retention_handoff'] ?? [],
			'access_control_summary'=>$builder_start['access_control_summary'] ?? [],
			'access_control_handoff'=>$builder_start['access_control_handoff'] ?? [],
			'operational_reliability_summary'=>$builder_start['operational_reliability_summary'] ?? [],
			'operational_reliability_handoff'=>$builder_start['operational_reliability_handoff'] ?? [],
			'support_observability_summary'=>$builder_start['support_observability_summary'] ?? [],
			'support_observability_handoff'=>$builder_start['support_observability_handoff'] ?? [],
			'change_management_summary'=>$builder_start['change_management_summary'] ?? [],
			'change_management_handoff'=>$builder_start['change_management_handoff'] ?? [],
			'integration_boundary_summary'=>$builder_start['integration_boundary_summary'] ?? [],
			'integration_boundary_handoff'=>$builder_start['integration_boundary_handoff'] ?? [],
			'tenant_identity_handoff'=>$builder_start['tenant_identity_handoff'] ?? [],
			'business_policy_summary'=>$builder_start['business_policy_summary'] ?? [],
			'process_policy_summary'=>$builder_start['process_policy_summary'] ?? [],
			'domain_workflow_handoff'=>$builder_start['domain_workflow_handoff'] ?? [],
			'reporting_analytics_summary'=>$builder_start['reporting_analytics_summary'] ?? [],
			'reporting_analytics_handoff'=>$builder_start['reporting_analytics_handoff'] ?? [],
			'notification_communication_summary'=>$builder_start['notification_communication_summary'] ?? [],
			'notification_communication_handoff'=>$builder_start['notification_communication_handoff'] ?? [],
			'panel_fields'=>$builder_start['panel_fields'] ?? [],
			'panel_fields_summary'=>$builder_preview_summaries['panel_fields'] ?? [],
			'filters'=>$builder_start['filters'] ?? [],
			'filters_summary'=>$builder_preview_summaries['filters'] ?? [],
			'actions'=>$builder_start['actions'] ?? [],
			'actions_summary'=>$builder_preview_summaries['actions'] ?? [],
			'verification'=>$builder_start['verification'] ?? [],
			'verification_evidence'=>$builder_start['verification_evidence'] ?? [],
			'verification_evidence_summary'=>$builder_preview_summaries['verification_evidence'] ?? [],
			'verification_todo'=>$builder_start['verification_todo'] ?? [],
			'verification_handoff'=>$app_builder_lane['verification_plan']['handoff'] ?? [],
			'verification_execution_plan'=>$builder_start['verification_execution_plan'] ?? [],
			'acceptance_criteria'=>$builder_start['acceptance_criteria'] ?? [],
			'acceptance_review_plan'=>$builder_start['acceptance_review_plan'] ?? [],
			'diagnostic_handoff_hint'=>$builder_start['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint(is_array($app_builder_lane['verification_plan'] ?? null) ? $app_builder_lane['verification_plan'] : []),
			'verification_recovery_plan'=>$builder_start['verification_recovery_plan'] ?? [],
			'recovery_hints'=>$app_builder_lane['verification_plan']['recovery_hints'] ?? [],
			'app_contract_summary'=>$builder_start['app_contract_summary'] ?? [],
			'data_sensitivity_summary'=>$builder_start['data_sensitivity_summary'] ?? [],
			'policy_decision_register'=>$builder_start['policy_decision_register'] ?? [],
			'next_edits'=>$builder_start['next_edits'] ?? [],
			'code_skeleton_summary'=>$builder_start['code_skeleton_summary'] ?? [],
			'local_convention_probe'=>$builder_start['local_convention_probe'] ?? [],
			'write_plan_summary'=>$builder_start['write_plan_summary'] ?? [],
			'implementation_matrix'=>$builder_start['implementation_matrix'] ?? [],
			'implementation_recipe'=>$builder_start['implementation_recipe'] ?? [],
			'write_handoff'=>$builder_start['write_handoff'] ?? [],
			'prewrite_checklist'=>$builder_start['prewrite_checklist'] ?? [],
			'write_readiness'=>$builder_write_readiness,
			'governance_notes'=>$builder_governance_notes,
			'optional_context'=>[
				'app_builder_lane'=>'compact file/edit/tool summary follows',
				'entity_input_contract'=>'builder_response.entity_input_contract',
				'relationship_contract_summary'=>'builder_response.relationship_contract_summary',
				'relationship_adapter_handoff'=>'builder_response.relationship_adapter_handoff',
				'data_model_handoff'=>'builder_response.data_model_handoff',
				'data_integrity_summary'=>'builder_response.data_integrity_summary',
				'lifecycle_policy_summary'=>'builder_response.lifecycle_policy_summary',
				'lifecycle_state_handoff'=>'builder_response.lifecycle_state_handoff',
				'audit_retention_summary'=>'builder_response.audit_retention_summary',
				'audit_retention_handoff'=>'builder_response.audit_retention_handoff',
				'access_control_summary'=>'builder_response.access_control_summary',
				'access_control_handoff'=>'builder_response.access_control_handoff',
				'operational_reliability_summary'=>'builder_response.operational_reliability_summary',
				'operational_reliability_handoff'=>'builder_response.operational_reliability_handoff',
				'support_observability_summary'=>'builder_response.support_observability_summary',
				'support_observability_handoff'=>'builder_response.support_observability_handoff',
				'change_management_summary'=>'builder_response.change_management_summary',
				'change_management_handoff'=>'builder_response.change_management_handoff',
				'integration_boundary_summary'=>'builder_response.integration_boundary_summary',
				'integration_boundary_handoff'=>'builder_response.integration_boundary_handoff',
				'tenant_identity_handoff'=>'builder_response.tenant_identity_handoff',
				'business_policy_summary'=>'builder_response.business_policy_summary',
				'process_policy_summary'=>'builder_response.process_policy_summary',
				'domain_workflow_handoff'=>'builder_response.domain_workflow_handoff',
				'reporting_analytics_summary'=>'builder_response.reporting_analytics_summary',
				'reporting_analytics_handoff'=>'builder_response.reporting_analytics_handoff',
				'notification_communication_summary'=>'builder_response.notification_communication_summary',
				'notification_communication_handoff'=>'builder_response.notification_communication_handoff',
				'implementation_recipe'=>'builder_response.implementation_recipe',
				'local_convention_probe'=>'builder_response.local_convention_probe',
				'verification_execution_plan'=>'builder_response.verification_execution_plan',
				'acceptance_review_plan'=>'builder_response.acceptance_review_plan',
				'full_builder_plan'=>'dataphyre_app_builder_plan_generate',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				'governance'=>'dataphyre_mcp_task_start_pack_export payload_profile=detail or deep only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary(),
			],
		] : [
			'title'=>'Inspection plan',
			'active'=>false,
			'governance_notes'=>'not triggered for read-only inspection',
			'optional_context'=>[
				'inspection_view'=>'use inspection_view first for read-only discovery',
				'app_builder'=>'dataphyre_app_builder_plan_generate only if the task becomes create/build/scaffold work',
			],
		]);
		$payload=[
			'builder_response'=>$builder_response,
			'inspection_view'=>[
				'title'=>'Inspection plan',
				'active'=>$app_builder_task===false,
				'task'=>$task,
				'workflow'=>$workflow_handoff['selected_workflow'] ?? null,
				'tool_matches'=>array_values(array_slice($this->mcp_compact_finder_summary($tool_finder)['matches'] ?? [], 0, $limit)),
				'resource_matches'=>array_values(array_slice($this->mcp_compact_finder_summary($resource_finder)['matches'] ?? [], 0, $limit)),
				'verification'=>'focused application or module checks',
				'write_policy'=>'read_only',
				'next_reads'=>[
					'Use tool_matches and resource_matches for focused read-only discovery.',
					'Open detail/deep context only if the task escalates beyond inspection.',
				],
			],
			'builder_view'=>[
				'title'=>'Builder plan',
				'active'=>$app_builder_task,
				'first_read'=>$builder_first_read,
				'next_action'=>$app_builder_task ? ($builder_start['next_action'] ?? []) : [],
				'files'=>$app_builder_task ? ($builder_start['files'] ?? []) : [],
				'files_summary'=>$app_builder_task ? ($builder_preview_summaries['files'] ?? []) : [],
				'app_path_context'=>$app_builder_task ? ($builder_start['app_path_context'] ?? ($app_builder_lane['app_path_context'] ?? [])) : [],
				'schema'=>$app_builder_task ? ($builder_start['schema'] ?? []) : [],
				'schema_summary'=>$app_builder_task ? ($builder_preview_summaries['schema'] ?? []) : [],
				'naming_contract'=>$app_builder_task ? ($builder_start['naming_contract'] ?? []) : [],
				'entity_input_contract'=>$app_builder_task ? ($builder_start['entity_input_contract'] ?? []) : [],
				'surface_execution_plan'=>$app_builder_task ? ($builder_start['surface_execution_plan'] ?? []) : [],
				'companion_surface_handoff'=>$app_builder_task ? ($builder_start['companion_surface_handoff'] ?? []) : [],
				'relationship_contract_summary'=>$app_builder_task ? ($builder_start['relationship_contract_summary'] ?? []) : [],
				'relationship_adapter_handoff'=>$app_builder_task ? ($builder_start['relationship_adapter_handoff'] ?? []) : [],
				'field_metadata_summary'=>$app_builder_task ? ($builder_start['field_metadata_summary'] ?? []) : [],
				'data_model_handoff'=>$app_builder_task ? ($builder_start['data_model_handoff'] ?? []) : [],
				'data_integrity_summary'=>$app_builder_task ? ($builder_start['data_integrity_summary'] ?? []) : [],
				'lifecycle_policy_summary'=>$app_builder_task ? ($builder_start['lifecycle_policy_summary'] ?? []) : [],
				'lifecycle_state_handoff'=>$app_builder_task ? ($builder_start['lifecycle_state_handoff'] ?? []) : [],
				'audit_retention_summary'=>$app_builder_task ? ($builder_start['audit_retention_summary'] ?? []) : [],
				'audit_retention_handoff'=>$app_builder_task ? ($builder_start['audit_retention_handoff'] ?? []) : [],
				'access_control_summary'=>$app_builder_task ? ($builder_start['access_control_summary'] ?? []) : [],
				'access_control_handoff'=>$app_builder_task ? ($builder_start['access_control_handoff'] ?? []) : [],
				'operational_reliability_summary'=>$app_builder_task ? ($builder_start['operational_reliability_summary'] ?? []) : [],
				'operational_reliability_handoff'=>$app_builder_task ? ($builder_start['operational_reliability_handoff'] ?? []) : [],
				'support_observability_summary'=>$app_builder_task ? ($builder_start['support_observability_summary'] ?? []) : [],
				'support_observability_handoff'=>$app_builder_task ? ($builder_start['support_observability_handoff'] ?? []) : [],
				'change_management_summary'=>$app_builder_task ? ($builder_start['change_management_summary'] ?? []) : [],
				'change_management_handoff'=>$app_builder_task ? ($builder_start['change_management_handoff'] ?? []) : [],
				'integration_boundary_summary'=>$app_builder_task ? ($builder_start['integration_boundary_summary'] ?? []) : [],
				'integration_boundary_handoff'=>$app_builder_task ? ($builder_start['integration_boundary_handoff'] ?? []) : [],
				'tenant_identity_handoff'=>$app_builder_task ? ($builder_start['tenant_identity_handoff'] ?? []) : [],
				'business_policy_summary'=>$app_builder_task ? ($builder_start['business_policy_summary'] ?? []) : [],
				'process_policy_summary'=>$app_builder_task ? ($builder_start['process_policy_summary'] ?? []) : [],
				'domain_workflow_handoff'=>$app_builder_task ? ($builder_start['domain_workflow_handoff'] ?? []) : [],
				'reporting_analytics_summary'=>$app_builder_task ? ($builder_start['reporting_analytics_summary'] ?? []) : [],
				'reporting_analytics_handoff'=>$app_builder_task ? ($builder_start['reporting_analytics_handoff'] ?? []) : [],
				'notification_communication_summary'=>$app_builder_task ? ($builder_start['notification_communication_summary'] ?? []) : [],
				'notification_communication_handoff'=>$app_builder_task ? ($builder_start['notification_communication_handoff'] ?? []) : [],
				'data_sensitivity_summary'=>$app_builder_task ? ($builder_start['data_sensitivity_summary'] ?? []) : [],
				'policy_decision_register'=>$app_builder_task ? ($builder_start['policy_decision_register'] ?? []) : [],
				'panel_fields'=>$app_builder_task ? ($builder_start['panel_fields'] ?? []) : [],
				'panel_fields_summary'=>$app_builder_task ? ($builder_preview_summaries['panel_fields'] ?? []) : [],
				'filters'=>$app_builder_task ? ($builder_start['filters'] ?? []) : [],
				'filters_summary'=>$app_builder_task ? ($builder_preview_summaries['filters'] ?? []) : [],
				'actions'=>$app_builder_task ? ($builder_start['actions'] ?? []) : [],
				'actions_summary'=>$app_builder_task ? ($builder_preview_summaries['actions'] ?? []) : [],
				'verification'=>$app_builder_task ? ($builder_start['verification'] ?? []) : [],
				'verification_evidence'=>$app_builder_task ? ($builder_start['verification_evidence'] ?? []) : [],
				'verification_evidence_summary'=>$app_builder_task ? ($builder_preview_summaries['verification_evidence'] ?? []) : [],
				'verification_todo'=>$app_builder_task ? ($builder_start['verification_todo'] ?? []) : [],
				'verification_handoff'=>$app_builder_task ? ($app_builder_lane['verification_plan']['handoff'] ?? []) : [],
				'verification_execution_plan'=>$app_builder_task ? ($builder_start['verification_execution_plan'] ?? []) : [],
				'verification_fixture_handoff'=>$app_builder_task ? ($builder_start['verification_fixture_handoff'] ?? []) : [],
				'acceptance_criteria'=>$app_builder_task ? ($builder_start['acceptance_criteria'] ?? []) : [],
				'acceptance_review_plan'=>$app_builder_task ? ($builder_start['acceptance_review_plan'] ?? []) : [],
				'diagnostic_handoff_hint'=>$app_builder_task ? ($builder_start['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint(is_array($app_builder_lane['verification_plan'] ?? null) ? $app_builder_lane['verification_plan'] : [])) : [],
				'verification_recovery_plan'=>$app_builder_task ? ($builder_start['verification_recovery_plan'] ?? []) : [],
				'recovery_hints'=>$app_builder_task ? ($app_builder_lane['verification_plan']['recovery_hints'] ?? []) : [],
				'next_edits'=>$app_builder_task ? ($builder_start['next_edits'] ?? []) : [],
				'local_convention_probe'=>$app_builder_task ? ($builder_start['local_convention_probe'] ?? []) : [],
				'write_plan_summary'=>$app_builder_task ? ($builder_start['write_plan_summary'] ?? []) : [],
				'implementation_matrix'=>$app_builder_task ? ($builder_start['implementation_matrix'] ?? []) : [],
				'implementation_recipe'=>$app_builder_task ? ($builder_start['implementation_recipe'] ?? []) : [],
				'write_handoff'=>$app_builder_task ? ($builder_start['write_handoff'] ?? []) : [],
				'prewrite_checklist'=>$app_builder_task ? ($builder_start['prewrite_checklist'] ?? []) : [],
				'write_readiness'=>$app_builder_task ? $builder_write_readiness : [],
				'governance_notes'=>$app_builder_task ? $builder_governance_notes : 'not triggered for read-only inspection',
				'secondary_context'=>$app_builder_task ? 'Use first_read for the default app-agent pass; app_builder_lane, compact tool/resource matches, and workflow summary follow; open detail/deep only with explicit payload_profile=detail/deep or include_deep_context=true.' : 'Use inspection_view for read-only discovery; open app_builder_lane only when the task becomes create/build/scaffold work.',
			],
			'export_type'=>'dataphyre_mcp_task_start_pack_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$task,
			'target'=>$target,
			'limit'=>$limit,
			'payload_profile'=>$payload_profile,
			'builder_first_read'=>$builder_first_read,
			'builder_start'=>$builder_start,
			'app_builder_lane'=>$app_builder_lane_payload,
			'startup_lane'=>[
				'default_lane'=>$app_builder_task ? 'builder_first_read' : 'inspection_view',
				'payload_profile'=>$payload_profile,
				'deep_context_inline'=>$include_deep_context,
				'detail_context_inline'=>$include_detail_context,
				'profiles'=>[
					'builder'=>'builder_first_read, compact builder_response refs, compact discovery, and fetchable context links',
					'detail'=>'builder profile plus full contracts, tool audience boundaries, and discovery matches',
					'deep'=>'detail profile plus status board, safety boundary, enterprise audit, full workflow handoff, and full app_builder_lane',
				],
				'governance'=>'collapsed unless payload_profile=deep or include_deep_context=true is requested',
				'next_read'=>$app_builder_task ? 'Use builder_first_read first: next_action, files_summary, schema_summary, naming_contract, write_readiness, scaffold_completion_summary, and verification_handoff. Open dataphyre_app_builder_plan_generate payload_profile=full only for the detail page or skeleton bodies needed next; use start-pack detail/deep only when explicitly requested for the next escalation decision.' : 'Use inspection_view tool_matches and resource_matches before opening app_builder_lane or deep_context.',
			],
			'agent_context'=>$include_detail_context ? [
				'target'=>$agent_context['target'] ?? $target,
				'recommended_path'=>$agent_context['recommended_path'] ?? $this->agent_context_path($target),
				'write_policy'=>$agent_context['write_policy'] ?? 'not_written_by_mcp_tool',
				'modules'=>$agent_context['modules'] ?? [],
				'source_documents'=>$agent_context['source_documents'] ?? [],
				'application_agent_operating_contract'=>$agent_context['application_agent_operating_contract'] ?? $this->mcp_application_agent_operating_contract('task_start_pack_agent_context'),
				'ordinary_app_work'=>$agent_context['ordinary_app_work'] ?? $this->mcp_ordinary_app_work_contract('task_start_pack_agent_context'),
			] : [
				'target'=>$agent_context['target'] ?? $target,
				'recommended_path'=>$agent_context['recommended_path'] ?? $this->agent_context_path($target),
				'write_policy'=>$agent_context['write_policy'] ?? 'not_written_by_mcp_tool',
				'modules'=>$agent_context['modules'] ?? [],
				'contracts_collapsed'=>true,
				'open_detail_with'=>'payload_profile=detail/deep or include_detail_context=true',
			],
			'workflow_handoff'=>$workflow_handoff,
			'tool_matches'=>$include_detail_context ? $tool_finder : $this->mcp_compact_finder_summary($tool_finder),
			'resource_matches'=>$include_detail_context ? $resource_finder : $this->mcp_compact_finder_summary($resource_finder),
			'recommended_sequence'=>$include_detail_context ? array_values(array_filter([
				$include_deep_context ? 'Read safety_boundary before suggesting client configuration, filesystem writes, or unsafe actions.' : 'Use deep_context.safety_summary for ordinary app work; fetch safety_boundary only before client configuration, filesystem writes, or unsafe actions.',
				'Use agent_context source_documents and recommended_path to load local coding rules outside MCP.',
				($proportional_guidance['enterprise_review_required'] ?? false)===true ? 'Review enterprise_audit before the identified elevated-risk claim or framework work.' : 'Keep enterprise_audit as optional context unless the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
				in_array('runtime_quality_gates', $proportional_guidance['required_reviews'] ?? [], true) ? 'Review enterprise_audit.runtime_quality_gates before framework-level edits.' : null,
				in_array('governance_baseline', $proportional_guidance['required_reviews'] ?? [], true) ? 'Review enterprise_audit.governance_baseline before corporate-ready claims or tenant/security/identity/access/session/credential/privacy/compliance/data-residency/retention/data-boundary work.' : null,
				$include_deep_context ? 'Inspect workflow_handoff selected_workflow, readiness, and session messages before executing any client-side MCP frames.' : 'Use workflow_handoff selected_workflow and fetch_tools for the full handoff only when ready to run workflow messages.',
				'Use tool_matches and resource_matches to refine the plan before requesting full manifest schemas.',
				'After a workflow run, capture, audit, and summarize responses with the transcript tools.',
			])) : ($app_builder_task ? [
				'Use builder_first_read first for the ordinary app-build handoff.',
				'Open dataphyre_app_builder_plan_generate payload_profile=full only after first_read shows blockers, continuation calls, companion endpoints, skeletons, or verification fields that need detail.',
				'Use dataphyre_app_builder_plan_generate for the full builder plan; use dataphyre_scaffold_plan_generate only for lower-level dry-run scaffolds.',
				'Use compact tool/resource matches only to fetch targeted Panel, SQL, or routing docs.',
				'Open deep_context or detail context only if the task escalates beyond ordinary app-owned work.',
			] : [
				'Use inspection_view first for focused read-only discovery; do not create files for inspection-only tasks.',
				'Use compact tool/resource matches to fetch targeted route, config, SQL, diagnostic, or module docs.',
				'Open app_builder_lane only if the task changes into create/build/scaffold implementation work.',
				'Open deep_context or detail context only if the task escalates beyond ordinary inspection.',
			]),
			'usage_notes'=>[
				'This start pack composes existing read-only MCP surfaces; it does not execute, store, or replay workflow messages.',
				'The pack keeps deep governance/status context out of the default lightweight lane unless payload_profile=deep or include_deep_context=true is requested.',
				'Use focused application or module verification for app behavior; use publication validation only for MCP/release-surface claims.',
			],
		];
		if($app_builder_task && !$include_deep_context){
			$compact_builder_view=$this->mcp_app_builder_compact_builder_view($builder_start, $app_builder_lane, $builder_first_read, $builder_preview_summaries, $builder_write_readiness, $builder_governance_notes);
			$payload['startup_lane']['profiles']['builder']='builder_first_read, compact builder_response refs, compact discovery, and fetchable context links';
			$payload['startup_lane']['next_read']=$include_detail_context
				? 'Use builder_first_read first. This detail profile adds contracts and discovery but keeps app-builder bulk paginated; open dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for the one detail page needed next, or payload_profile=deep only for explicit escalation evidence.'
				: 'Use builder_first_read first. Open dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for the one detail page needed next; use start-pack detail/deep only when explicitly requested for the next escalation decision.';
			$payload=$this->mcp_app_builder_apply_compact_envelope($payload, $compact_builder_view, [
				'inspection_view',
				'builder_view',
				'builder_start',
				'app_builder_lane',
			], [
				'default_lane'=>'builder_first_read',
				'details_collapsed'=>true,
				'omitted_default_fields'=>[
					'inactive inspection_view',
					'builder_view',
					'builder_start',
					'app_builder_lane',
				],
				'open_detail_page_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page>',
				'open_full_plan_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				'open_start_pack_details_with'=>$include_detail_context ? 'dataphyre_mcp_task_start_pack_export payload_profile=deep only for explicit escalation evidence' : 'dataphyre_mcp_task_start_pack_export payload_profile=detail/deep only for explicit escalation context',
			]);
		}
		if($include_detail_context){
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('task_start_pack');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('task_start_pack');
			$payload['tool_audience_boundaries']=$workflow_recommendation['tool_audience_boundaries'] ?? $this->mcp_current_tool_audience_boundaries();
			$payload['proportional_guidance']=$proportional_guidance;
			$payload['deep_context']=$deep_context;
		}else{
			$governance_notes=is_array($builder_governance_notes) ? $builder_governance_notes+[
				'default_lane'=>$app_builder_task ? 'builder' : 'inspection',
				'open_only_for'=>$deep_context['open_when'] ?? [],
			] : [
				'status'=>'none triggered',
				'default_lane'=>$app_builder_task ? 'builder' : 'inspection',
				'open_only_for'=>$deep_context['open_when'] ?? [],
			];
			if($app_builder_task){
				$payload['context_policy']['governance_notes']=$governance_notes;
			}else{
				$payload['governance_notes']=$governance_notes;
			}
			$payload['context_links']=[
				'compact_app_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=compact',
				'planning_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'verification_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				'controls_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls',
				'broader_builder_context'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				'agent_context'=>'dataphyre_agent_context_generate',
				'escalation_readiness_details'=>'dataphyre_mcp_readiness_report',
			];
		}
		if($include_deep_context){
			$payload['status_board']=$status_board;
			$payload['safety_boundary']=$safety_boundary;
			$payload['enterprise_audit']=$enterprise_audit;
		}
		return $payload;
	}

	/**
	 * Builds the smallest app-builder handoff an application agent should read first.
	 *
	 * @param array<string,mixed> $source Builder start summary or compact builder response.
	 * @param array<string,mixed> $preview_summaries Optional preview metadata keyed by payload section.
	 * @param array<string,string> $open_details Links to richer fields or tools.
	 * @return array<string,mixed> Compact first-read app-builder handoff.
	 */
	private function mcp_app_builder_first_read(array $source, array $preview_summaries=[], array $open_details=[]): array {
		$files=is_array($source['files'] ?? null) ? $source['files'] : [];
		$schema=is_array($source['schema'] ?? null) ? $source['schema'] : [];
		$files_summary=is_array($preview_summaries['files'] ?? null)
			? $preview_summaries['files']
			: $this->mcp_compact_preview_summary($files, count($files), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning');
		$schema_summary=is_array($preview_summaries['schema'] ?? null)
			? $preview_summaries['schema']
			: $this->mcp_compact_preview_summary($schema, count($schema), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning');
		$verification_handoff=is_array($source['verification_handoff'] ?? null)
			? $source['verification_handoff']
			: (is_array($source['verification_plan']['handoff'] ?? null) ? $source['verification_plan']['handoff'] : []);
		$verification_handoff=$this->mcp_app_builder_compact_verification_handoff($verification_handoff);
		$next_action=is_array($source['next_action'] ?? null) && $source['next_action']!==[]
			? $source['next_action']
			: $this->app_builder_compact_next_action($source);
		$next_action=$this->mcp_app_builder_compact_handoff_field_refs($next_action);
		$write_readiness=is_array($source['write_readiness'] ?? null) ? $this->mcp_app_builder_compact_handoff_field_refs($source['write_readiness']) : [];
		$app_path_context=is_array($source['app_path_context'] ?? null) ? $this->mcp_app_builder_compact_app_path_context($source['app_path_context']) : [];
		$scaffold_completion_summary=is_array($source['scaffold_completion_summary'] ?? null)
			? $this->mcp_app_builder_compact_scaffold_completion_summary($source['scaffold_completion_summary'])
			: [];
		$governance_notes=is_array($source['governance_notes'] ?? null) ? $source['governance_notes'] : [];
		return [
			'title'=>'Builder first read',
			'purpose'=>'Default small app-agent handoff; open details only when this object points to them.',
			'next_action'=>$next_action,
			'next_detail_page'=>$this->mcp_app_builder_next_detail_page($next_action, $write_readiness, $scaffold_completion_summary, $governance_notes),
			'files_summary'=>$files_summary,
			'schema_summary'=>$schema_summary,
			'app_path_context'=>$app_path_context,
			'naming_contract'=>$source['naming_contract'] ?? [],
			'write_readiness'=>$write_readiness,
			'scaffold_completion_summary'=>$scaffold_completion_summary,
			'verification_handoff'=>$verification_handoff,
			'open_details'=>$open_details+[
				'files'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'schema'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
				'governance'=>'payload_profile=detail/deep only for escalation triggers',
			],
		];
	}

	/**
	 * Chooses the one detail page most likely to unblock the current builder phase.
	 *
	 * @param array<string,mixed> $next_action Compact next-action payload.
	 * @param array<string,mixed> $write_readiness Compact write-readiness payload.
	 * @param array<string,mixed> $scaffold_completion_summary Compact scaffold summary.
	 * @param array<string,mixed> $governance_notes Compact governance notes.
	 * @return array<string,string> First-page detail-page selector.
	 */
	private function mcp_app_builder_next_detail_page(array $next_action, array $write_readiness, array $scaffold_completion_summary, array $governance_notes=[]): array {
		$status=strtolower((string)($next_action['status'] ?? $write_readiness['status'] ?? ''));
		$action=strtolower((string)($next_action['action'] ?? $write_readiness['next_action'] ?? ''));
		$deferred_count=(int)($scaffold_completion_summary['deferred_count'] ?? 0);
		if($status==='continue_entity_chunks' || $deferred_count>0){
			return $this->mcp_app_builder_detail_page_choice('planning', 'Continue entity chunks and dependency context before opening implementation detail.', $status);
		}
		if(str_contains($status, 'prewrite') || str_contains($action, 'application_path') || str_contains($action, 'placeholder')){
			return $this->mcp_app_builder_detail_page_choice('planning', 'Resolve app paths, placeholders, schema, and relationship planning before writing files.', $status);
		}
		if(str_contains($status, 'ready_for_app_owned_writes') || ($write_readiness['ready_for_app_owned_writes'] ?? false)===true){
			return $this->mcp_app_builder_detail_page_choice('implementation', 'Open implementation only after scaffold and app-owned write readiness are satisfied.', $status);
		}
		if(str_contains($status, 'verification') || str_contains($action, 'verify') || str_contains($action, 'evidence')){
			return $this->mcp_app_builder_detail_page_choice('verification', 'Collect focused app/module verification and acceptance evidence for completed writes.', $status);
		}
		if(($governance_notes['status'] ?? null)==='app_owned_policy_attention'){
			return $this->mcp_app_builder_detail_page_choice('controls', 'Resolve app-owned policy, sensitivity, tenant, access, and integrity decisions only when signaled.', $status);
		}
		return $this->mcp_app_builder_detail_page_choice('planning', 'Start with planning summaries; open broader detail only when the next action asks for it.', $status);
	}

	/**
	 * Formats a compact app-builder detail-page choice.
	 *
	 * @return array<string,string> Detail-page choice.
	 */
	private function mcp_app_builder_detail_page_choice(string $page, string $reason, string $status=''): array {
		$choice=[
			'page'=>$page,
			'open_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page='.$page,
			'reason'=>$reason,
		];
		if($status!==''){
			$choice['status']=$status;
		}
		return $choice;
	}

	/**
	 * Keeps only path hints needed for the first app-builder read.
	 *
	 * @param array<string,mixed> $context Full app path context.
	 * @return array<string,mixed> Compact app path context.
	 */
	private function mcp_app_builder_compact_app_path_context(array $context): array {
		$hint=is_array($context['discovery_hint'] ?? null) ? $context['discovery_hint'] : [];
		return [
			'application_id'=>$context['application_id'] ?? null,
			'application_path'=>$context['application_path'] ?? '',
			'dataphyre_root'=>$context['dataphyre_root'] ?? null,
			'framework_path'=>$context['framework_path'] ?? null,
			'panel_resource_namespace'=>$context['panel_resource_namespace'] ?? null,
			'placeholder_mode'=>($context['placeholder_mode'] ?? false)===true,
			'path_confidence'=>$context['path_confidence'] ?? null,
			'discovery_hint'=>array_filter([
				'status'=>$hint['status'] ?? null,
				'next_tool'=>$hint['next_tool'] ?? null,
				'then_supply'=>$hint['then_supply'] ?? null,
				'accepted_forms'=>array_values(array_slice(array_map('strval', is_array($hint['accepted_forms'] ?? null) ? $hint['accepted_forms'] : []), 0, 4)),
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
		];
	}

	/**
	 * Replaces long handoff field lists with page-oriented references for first-read payloads.
	 *
	 * @param array<string,mixed> $payload Next-action or readiness payload.
	 * @return array<string,mixed> Payload with handoff fields summarized.
	 */
	private function mcp_app_builder_compact_handoff_field_refs(array $payload): array {
		if(is_array($payload['handoff_fields'] ?? null)){
			$payload['handoff_pages']=[
				'detail_pagination.pages.planning',
				'detail_pagination.pages.implementation',
				'detail_pagination.pages.verification',
				'detail_pagination.pages.controls',
			];
			$payload['handoff_fields_count']=count($payload['handoff_fields']);
			unset($payload['handoff_fields']);
		}
		return $payload;
	}

	/**
	 * Keeps chunking state useful without inlining every continuation body.
	 *
	 * @param array<string,mixed> $summary Full scaffold completion summary.
	 * @return array<string,mixed> Compact scaffold completion summary.
	 */
	private function mcp_app_builder_compact_scaffold_completion_summary(array $summary): array {
		$next=is_array($summary['next_continuation'] ?? null) ? $summary['next_continuation'] : [];
		$queue=is_array($summary['continuation_queue'] ?? null) ? $summary['continuation_queue'] : [];
		$first_queue=is_array($queue[0] ?? null) ? $queue[0] : [];
		return [
			'owner'=>$summary['owner'] ?? 'consuming_application',
			'complete'=>($summary['complete'] ?? false)===true,
			'status'=>$summary['status'] ?? null,
			'planned_count'=>(int)($summary['planned_count'] ?? 0),
			'deferred_count'=>(int)($summary['deferred_count'] ?? 0),
			'planned_entities'=>array_values(array_slice(array_map('strval', is_array($summary['planned_entities'] ?? null) ? $summary['planned_entities'] : []), 0, 8)),
			'deferred_entities'=>array_values(array_slice(array_map('strval', is_array($summary['deferred_entities'] ?? null) ? $summary['deferred_entities'] : []), 0, 8)),
			'next_action'=>$summary['next_action'] ?? null,
			'next_continuation'=>array_filter([
				'available'=>($next['available'] ?? false)===true,
				'tool'=>$next['tool'] ?? null,
				'chunk'=>$next['chunk'] ?? null,
				'entities'=>array_values(array_slice(array_map('strval', is_array($next['entities'] ?? null) ? $next['entities'] : []), 0, 8)),
				'field_scope'=>$next['field_scope'] ?? null,
				'dependency_context_present'=>$next['dependency_context_present'] ?? null,
				'argument_source'=>$next['argument_source'] ?? null,
				'repeat_until'=>$next['repeat_until'] ?? null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'continuation_queue'=>$first_queue===[] ? [] : [
				array_filter([
					'chunk'=>$first_queue['chunk'] ?? null,
					'entities'=>array_values(array_slice(array_map('strval', is_array($first_queue['entities'] ?? null) ? $first_queue['entities'] : []), 0, 8)),
					'field_scope'=>$first_queue['field_scope'] ?? null,
					'dependency_context_present'=>$first_queue['dependency_context_present'] ?? null,
					'relationship_summary'=>is_array($first_queue['relationship_summary'] ?? null) ? $first_queue['relationship_summary'] : null,
					'argument_source'=>$first_queue['argument_source'] ?? null,
				], static fn(mixed $value): bool => $value!==null && $value!==[]),
			],
			'continuation_count'=>count($queue),
			'full_queue'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.scaffold_completion_summary.continuation_queue',
		];
	}

	/**
	 * Keeps verification handoffs first-page friendly while preserving copy-safe refs.
	 *
	 * @param array<string,mixed> $handoff Full verification handoff.
	 * @return array<string,mixed> Compact verification handoff.
	 */
	private function mcp_app_builder_compact_verification_handoff(array $handoff): array {
		if($handoff===[]){
			return [];
		}
		$compact=[
			'owner'=>$handoff['owner'] ?? 'consuming_application',
			'status'=>$handoff['status'] ?? null,
			'tools'=>array_values(array_slice(array_map('strval', is_array($handoff['tools'] ?? null) ? $handoff['tools'] : []), 0, 6)),
			'copy_safe_fields_count'=>count(is_array($handoff['copy_safe_fields'] ?? null) ? $handoff['copy_safe_fields'] : []),
			'not_included_count'=>count(is_array($handoff['not_included'] ?? null) ? $handoff['not_included'] : []),
			'done_when'=>$handoff['done_when'] ?? null,
			'full_handoff'=>'dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.verification_plan.handoff',
		];
		if(is_array($handoff['post_write_handoff_template'] ?? null)){
			$compact['post_write_handoff_template_ref']='dataphyre_app_builder_plan_generate payload_profile=full -> builder_plan.verification_plan.handoff.post_write_handoff_template';
		}
		return array_filter($compact, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Builds the compact app-builder lane returned by default start/brief payloads.
	 *
	 * @param array<string,mixed> $lane Full app-builder lane.
	 * @param array<string,mixed> $builder_start Builder start summary.
	 * @param array<string,mixed> $first_read First-read summary.
	 * @param array<string,mixed> $preview_summaries Preview metadata.
	 * @param array<string,mixed> $write_readiness Write readiness summary.
	 * @param mixed $governance_notes Governance note summary.
	 * @return array<string,mixed> Compact app-builder lane.
	 */
	private function mcp_app_builder_compact_lane(array $lane, array $builder_start, array $first_read, array $preview_summaries, array $write_readiness, mixed $governance_notes): array {
		$payload=[
			'active'=>true,
			'lane'=>$lane['lane'] ?? 'builder',
			'purpose'=>'Concise app-building path before detail pages.',
			'progressive_disclosure'=>'Read first_read first; fetch dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for the one page needed next.',
			'scaffold_type'=>$lane['scaffold_type'] ?? '',
			'entrypoint_tool'=>$lane['entrypoint_tool'] ?? 'dataphyre_app_builder_plan_generate',
			'primary_tool'=>$lane['primary_tool'] ?? 'dataphyre_scaffold_plan_generate',
			'scaffold_tool'=>$lane['scaffold_tool'] ?? 'dataphyre_scaffold_plan_generate',
			'entities'=>array_values(array_slice(array_map('strval', is_array($lane['entities'] ?? null) ? $lane['entities'] : []), 0, 12)),
			'first_read_ref'=>'builder_first_read',
			'app_path_context'=>$builder_start['app_path_context'] ?? ($lane['app_path_context'] ?? []),
			'files_summary'=>$preview_summaries['files'] ?? ($first_read['files_summary'] ?? []),
			'schema_summary'=>$preview_summaries['schema'] ?? ($first_read['schema_summary'] ?? []),
			'panel_fields_summary'=>$preview_summaries['panel_fields'] ?? [],
			'filters_summary'=>$preview_summaries['filters'] ?? [],
			'actions_summary'=>$preview_summaries['actions'] ?? [],
			'detail_pagination'=>$this->mcp_app_builder_detail_pagination(),
		];
		if(is_array($governance_notes) && ($governance_notes['status'] ?? null)==='app_owned_policy_attention'){
			$payload['data_sensitivity_summary']=$this->mcp_app_builder_compact_policy_summary(is_array($builder_start['data_sensitivity_summary'] ?? null) ? $builder_start['data_sensitivity_summary'] : []);
			$payload['policy_decision_register']=$this->mcp_app_builder_compact_policy_summary(is_array($builder_start['policy_decision_register'] ?? null) ? $builder_start['policy_decision_register'] : []);
			$payload['governance_notes']=$governance_notes;
		}
		return $payload;
	}

	/**
	 * Builds the compact builder view returned by default brief/start payloads.
	 *
	 * @param array<string,mixed> $builder_start Builder start summary.
	 * @param array<string,mixed> $lane Full app-builder lane.
	 * @param array<string,mixed> $first_read First-read summary.
	 * @param array<string,mixed> $preview_summaries Preview metadata.
	 * @param array<string,mixed> $write_readiness Write readiness summary.
	 * @param mixed $governance_notes Governance note summary.
	 * @return array<string,mixed> Compact builder view.
	 */
	private function mcp_app_builder_compact_builder_view(array $builder_start, array $lane, array $first_read, array $preview_summaries, array $write_readiness, mixed $governance_notes): array {
		$next_edits=array_values(array_slice(array_map('strval', is_array($builder_start['next_edits'] ?? null) ? $builder_start['next_edits'] : (is_array($lane['next_edits'] ?? null) ? $lane['next_edits'] : [])), 0, 6));
		$verification=array_values(array_slice(array_map('strval', is_array($builder_start['verification'] ?? null) ? $builder_start['verification'] : (is_array($lane['verification'] ?? null) ? $lane['verification'] : [])), 0, 8));
		$compact_governance_notes=$governance_notes;
		if(is_array($governance_notes) && ($governance_notes['status'] ?? null)==='app_owned_policy_attention'){
			$compact_governance_notes=[
				'status'=>$governance_notes['status'] ?? null,
				'mode'=>$governance_notes['mode'] ?? null,
				'categories'=>array_values(array_slice(array_map('strval', is_array($governance_notes['categories'] ?? null) ? $governance_notes['categories'] : []), 0, 8)),
				'policy_required_count'=>$governance_notes['policy_required_count'] ?? null,
				'detail_page'=>'controls',
				'not_required'=>array_values(array_slice(array_map('strval', is_array($governance_notes['not_required'] ?? null) ? $governance_notes['not_required'] : []), 0, 4)),
			];
		}
		$payload=[
			'title'=>'Builder first page',
			'active'=>true,
			'first_read_ref'=>'builder_first_read',
			'files_summary'=>$preview_summaries['files'] ?? ($first_read['files_summary'] ?? []),
			'app_path_context'=>$builder_start['app_path_context'] ?? ($lane['app_path_context'] ?? []),
			'schema_summary'=>$preview_summaries['schema'] ?? ($first_read['schema_summary'] ?? []),
			'next_edits'=>$next_edits,
			'verification'=>$verification,
			'verification_evidence_summary'=>$preview_summaries['verification_evidence'] ?? [],
			'governance_notes'=>$compact_governance_notes,
			'detail_pagination'=>$this->mcp_app_builder_detail_pagination(),
			'payload_budget'=>$this->mcp_app_builder_payload_budget('task_start_builder'),
			'semantic_contract'=>$this->mcp_app_builder_semantic_contract(),
			'secondary_context'=>'Default first page only. Fetch dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for the needed page instead of reading a giant inline brief.',
		];
		if(is_array($governance_notes) && ($governance_notes['status'] ?? null)==='app_owned_policy_attention'){
			$payload['data_sensitivity_summary']=$this->mcp_app_builder_compact_policy_summary(is_array($builder_start['data_sensitivity_summary'] ?? null) ? $builder_start['data_sensitivity_summary'] : []);
			$payload['policy_decision_register']=$this->mcp_app_builder_compact_policy_summary(is_array($builder_start['policy_decision_register'] ?? null) ? $builder_start['policy_decision_register'] : []);
		}
		return $payload;
	}

	/**
	 * Keeps sensitive-policy signals visible without inlining full decision registers.
	 *
	 * @param array<string,mixed> $summary Full policy/sensitivity summary.
	 * @return array<string,mixed> Compact policy/sensitivity marker.
	 */
	private function mcp_app_builder_compact_policy_summary(array $summary): array {
		return [
			'owner'=>$summary['owner'] ?? 'consuming_application',
			'status'=>$summary['status'] ?? null,
			'has_sensitive_signals'=>$summary['has_sensitive_signals'] ?? null,
			'categories'=>array_values(array_slice(array_map('strval', is_array($summary['categories'] ?? null) ? $summary['categories'] : []), 0, 8)),
			'decision_count'=>is_array($summary['decisions'] ?? null) ? count($summary['decisions']) : (is_array($summary['decision_prompts'] ?? null) ? count($summary['decision_prompts']) : null),
			'detail_page'=>'controls',
			'open_with'=>'dataphyre_app_builder_plan_generate',
		];
	}

	/**
	 * Names compact app-builder fields that must stay machine-usable.
	 *
	 * @return array<string,mixed> Compact semantic contract.
	 */
	private function mcp_app_builder_semantic_contract(): array {
		return [
			'compact_mode'=>'first_page_plus_machine_usable_contracts',
			'must_preserve'=>[
				'first_read.next_action',
				'entity_planning.continuation_calls',
				'scaffold_completion_summary.next_continuation',
				'field_metadata_summary',
				'write_readiness',
				'verification_handoff',
			],
			'may_paginate'=>[
				'files',
				'schema',
				'panel_fields',
				'implementation_recipe',
				'verification_execution_plan',
				'acceptance_review_plan',
				'code_skeleton_bodies',
			],
			'budget_policy'=>'Summarize large bodies with detail refs, but keep continuation arguments, field metadata, write readiness, and focused verification contracts machine-readable.',
		];
	}

	/**
	 * Describes where app-builder detail pages live without inlining them in compact payloads.
	 *
	 * @return array<string,mixed> Detail pagination contract.
	 */
	private function mcp_app_builder_detail_pagination(): array {
		return [
			'default_payload'=>'first_page_only',
			'full_plan_tool'=>'dataphyre_app_builder_plan_generate',
			'start_pack_broader_context'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
			'start_pack_detail'=>'dataphyre_mcp_task_start_pack_export payload_profile=detail (full-contract or escalation path)',
			'start_pack_deep'=>'dataphyre_mcp_task_start_pack_export payload_profile=deep',
			'pages'=>[
				'planning'=>['files', 'schema', 'panel_fields', 'filters', 'actions', 'entity_planning', 'relationship_contract_summary'],
				'implementation'=>['surface_execution_plan', 'companion_surface_handoff', 'local_convention_probe', 'implementation_matrix', 'implementation_recipe', 'write_handoff'],
				'verification'=>['verification_execution_plan', 'verification_fixture_handoff', 'acceptance_review_plan', 'verification_recovery_plan'],
				'controls'=>['data_sensitivity_summary', 'policy_decision_register', 'data_integrity_summary', 'access_control_handoff', 'tenant_identity_handoff'],
				'governance'=>['extension_boundary_summary', 'governance_lane', 'enterprise_audit'],
			],
			'open_rule'=>'Open only the page needed for the next edit, blocker, or elevated-risk decision.',
		];
	}

	/**
	 * Describes compact app-builder payload budgets for agent handoffs.
	 *
	 * @param string $surface App-builder surface name.
	 * @return array<string,mixed> Copy-safe payload budget metadata.
	 */
	private function mcp_app_builder_payload_budget(string $surface): array {
		$surface=strtolower(trim($surface));
		$limits=match($surface){
			'agent_brief'=>['max_response_chars'=>18000, 'max_first_read_chars'=>8000, 'default_payload'=>'first_page_only'],
			'task_start_builder'=>['max_response_chars'=>60000, 'max_first_read_chars'=>12000, 'default_payload'=>'first_page_plus_docs'],
			'app_builder_plan_compact'=>['max_response_chars'=>60000, 'max_first_read_chars'=>12000, 'default_payload'=>'first_page_plus_build_outline'],
			default=>['max_response_chars'=>60000, 'max_first_read_chars'=>12000, 'default_payload'=>'compact_builder_response'],
		};
		return $limits+[
			'surface'=>$surface!=='' ? $surface : 'app_builder',
			'detail_strategy'=>'page_detail_with_detail_pagination',
			'overflow_action'=>'open_dataphyre_app_builder_plan_generate_for_the_needed_page_only',
			'omitted_inline_detail'=>[
				'builder_view',
				'builder_plan',
				'app_builder_lane',
				'app_builder_summary',
				'raw handoff_fields',
				'code_skeleton_bodies',
			],
			'not_required'=>[
				'inline enterprise/governance binder for ordinary app work',
				'Dataphyre hot-path benchmark evidence for consuming app scaffolds',
				'MCP/release-surface validation for ordinary app scaffolds',
			],
			'escalation_policy'=>[
				'default_owner'=>'consuming_application',
				'default_verification'=>'focused_application_or_module_checks',
				'use_extension_points_first'=>[
					'application code',
					'install config',
					'dialbacks',
					'callbacks',
					'plugins',
					'MCP metadata',
					'application-owned adapters',
				],
				'do_not_escalate_for'=>[
					'ordinary CRUD scaffolds',
					'app-owned Panel resources',
					'app-owned SQL schema/repository/record artifacts',
					'app-owned policy decisions',
					'app-owned focused verification',
				],
				'escalate_only_for'=>$this->mcp_escalation_triggers(),
			],
		];
	}

	/**
	 * Builds the first-read builder summary used by lightweight app start packs.
	 *
	 * @param array<string,mixed> $lane App-builder lane payload.
	 * @return array<string,mixed> Compact first-read summary.
	 */
	private function mcp_app_builder_start_summary(array $lane): array {
		$plan=$this->app_builder_builder_plan($lane);
		$governance_notes=$this->app_builder_governance_notes($plan, $lane, true);
		if($governance_notes==='none triggered'){
			$governance_notes='none triggered for ordinary app-owned work';
		}
		$all_files=is_array($lane['files_to_create'] ?? null) ? $lane['files_to_create'] : [];
		$file_preview=$this->mcp_app_builder_compact_file_preview($all_files);
		$all_schema=is_array($plan['schema'] ?? null) ? $plan['schema'] : [];
		$schema_preview=array_values(array_slice($all_schema, 0, 6));
		$all_panel_fields=is_array($plan['panel_fields'] ?? null) ? $plan['panel_fields'] : [];
		$panel_fields_preview=array_values(array_slice($all_panel_fields, 0, 6));
		$all_filters=is_array($plan['filters'] ?? null) ? $plan['filters'] : [];
		$filters_preview=array_values(array_slice($all_filters, 0, 6));
		$all_actions=is_array($plan['actions'] ?? null) ? $plan['actions'] : [];
		$actions_preview=array_values(array_slice($all_actions, 0, 6));
		$all_verification_evidence=is_array($plan['verification_plan']['evidence_to_collect'] ?? null) ? $plan['verification_plan']['evidence_to_collect'] : [];
		$verification_evidence_preview=array_values(array_slice($all_verification_evidence, 0, 8));
		$verification_todo_preview=array_values(array_slice(is_array($plan['verification_plan']['verification_todo'] ?? null) ? $plan['verification_plan']['verification_todo'] : [], 0, 8));
		$write_readiness=is_array($plan['write_readiness'] ?? null) && $plan['write_readiness']!==[]
			? $plan['write_readiness']
			: $this->app_builder_write_readiness(is_array($plan['scaffold_completion_summary'] ?? null) ? $plan['scaffold_completion_summary'] : [], is_array($plan['prewrite_checklist'] ?? null) ? $plan['prewrite_checklist'] : []);
		$models=is_array($lane['data_model'] ?? null) ? $lane['data_model'] : [];
		$model_summary=[];
		foreach(array_slice($models, 0, 6) as $model){
			if(!is_array($model)){
				continue;
			}
			$artifact_paths=array_values(array_map('strval', is_array($model['artifact_paths'] ?? null) ? $model['artifact_paths'] : []));
			$model_summary[]=[
				'entity'=>(string)($model['entity'] ?? ''),
				'table'=>(string)($model['table'] ?? ''),
				'schema_artifact'=>$this->mcp_first_path_matching($artifact_paths, '/Schema/'),
				'repository_artifact'=>$this->mcp_first_path_matching($artifact_paths, '/Repository/'),
				'record_artifact'=>$this->mcp_first_path_matching($artifact_paths, '/Record/'),
				'artifact_paths'=>array_values(array_slice($artifact_paths, 0, 3)),
			];
		}
		return [
			'title'=>'Builder plan',
			'next_action'=>$this->app_builder_compact_next_action($plan),
			'entrypoint_tool'=>$lane['entrypoint_tool'] ?? 'dataphyre_app_builder_plan_generate',
			'files'=>$file_preview,
			'app_path_context'=>$plan['app_path_context'] ?? ($lane['app_path_context'] ?? []),
			'schema'=>$schema_preview,
			'naming_contract'=>$plan['naming_contract'] ?? [],
			'entity_input_contract'=>$lane['entity_input_contract'] ?? [],
			'entity_planning'=>$plan['entity_planning'] ?? ($lane['entity_planning'] ?? []),
			'scaffold_completion_summary'=>$plan['scaffold_completion_summary'] ?? [],
			'surface_execution_plan'=>$plan['surface_execution_plan'] ?? [],
			'companion_surface_handoff'=>$plan['companion_surface_handoff'] ?? [],
			'relationship_contract_summary'=>$plan['relationship_contract_summary'] ?? [],
			'relationship_adapter_handoff'=>$plan['relationship_adapter_handoff'] ?? [],
			'data_model'=>$model_summary,
			'data_model_handoff'=>$this->app_builder_compact_data_model_handoff($plan['data_model'] ?? []),
			'app_contract_summary'=>$plan['app_contract_summary'] ?? [],
			'data_sensitivity_summary'=>$plan['data_sensitivity_summary'] ?? [],
			'field_metadata_summary'=>$plan['field_metadata_summary'] ?? [],
			'policy_decision_register'=>$plan['policy_decision_register'] ?? [],
			'panel_fields'=>$panel_fields_preview,
			'filters'=>$filters_preview,
			'actions'=>$actions_preview,
			'next_edits'=>array_values(array_slice(array_map('strval', is_array($lane['next_edits'] ?? null) ? $lane['next_edits'] : []), 0, 6)),
			'verification'=>array_values(array_slice(array_map('strval', is_array($lane['verification'] ?? null) ? $lane['verification'] : []), 0, 8)),
			'verification_evidence'=>$verification_evidence_preview,
			'verification_todo'=>$verification_todo_preview,
			'verification_handoff'=>$plan['verification_plan']['handoff'] ?? [],
			'verification_execution_plan'=>$plan['verification_execution_plan'] ?? [],
			'acceptance_criteria'=>$plan['acceptance_criteria'] ?? [],
			'acceptance_review_plan'=>$plan['acceptance_review_plan'] ?? [],
			'diagnostic_handoff_hint'=>$plan['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint(is_array($plan['verification_plan'] ?? null) ? $plan['verification_plan'] : []),
			'verification_recovery_plan'=>$plan['verification_recovery_plan'] ?? [],
			'recovery_hints'=>$plan['verification_plan']['recovery_hints'] ?? [],
			'code_skeleton_summary'=>$plan['code_skeleton_summary'] ?? [],
			'local_convention_probe'=>$plan['local_convention_probe'] ?? [],
			'write_plan_summary'=>$plan['write_plan_summary'] ?? [],
			'implementation_matrix'=>$plan['implementation_matrix'] ?? [],
			'implementation_recipe'=>$plan['implementation_recipe'] ?? [],
			'write_handoff'=>$plan['write_handoff'] ?? [],
			'prewrite_checklist'=>$plan['prewrite_checklist'] ?? [],
			'write_readiness'=>$write_readiness,
			'agent_workload'=>$this->mcp_app_builder_workload_budget(),
			'extension_boundary_summary'=>$lane['extension_boundary_summary'] ?? [],
			'governance_notes'=>$governance_notes,
			'open_governance_when'=>$lane['governance_lane']['open_when'] ?? [],
			'preview_summaries'=>[
				'files'=>$this->mcp_compact_preview_summary($all_files, count($file_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning'),
				'schema'=>$this->mcp_compact_preview_summary($all_schema, count($schema_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning'),
				'panel_fields'=>$this->mcp_compact_preview_summary($all_panel_fields, count($panel_fields_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning'),
				'filters'=>$this->mcp_compact_preview_summary($all_filters, count($filters_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning'),
				'actions'=>$this->mcp_compact_preview_summary($all_actions, count($actions_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning'),
				'verification_evidence'=>$this->mcp_compact_preview_summary($all_verification_evidence, count($verification_evidence_preview), 'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification'),
			],
		];
	}

	/**
	 * Describes whether a compact list is complete or a preview.
	 *
	 * @param array<int,mixed> $all_items Full source list.
	 * @param int $shown Number of items returned in the compact payload.
	 * @param string $open_with Where agents can read the complete list.
	 * @return array<string,mixed> Preview metadata for compact handoffs.
	 */
	private function mcp_compact_preview_summary(array $all_items, int $shown, string $open_with): array {
		$total=count($all_items);
		$shown=max(0, min($shown, $total));
		return [
			'total'=>$total,
			'shown'=>$shown,
			'truncated'=>$shown<$total,
			'omitted_count'=>max(0, $total-$shown),
			'open_with'=>$open_with,
		];
	}

	/**
	 * Keeps compact app-builder file previews grouped by entity write bundles.
	 *
	 * @param array<int,mixed> $files Planned app-owned files.
	 * @return array<int,string> Compact path preview.
	 */
	private function mcp_app_builder_compact_file_preview(array $files): array {
		$files=array_values(array_unique(array_map('strval', $files)));
		if(count($files)<=12){
			return $files;
		}
		$resource_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/panel/resources/')));
		$preview=[];
		foreach(array_slice($resource_files, 0, 4) as $resource_file){
			$index=array_search($resource_file, $files, true);
			if(!is_int($index)){
				continue;
			}
			foreach(array_slice($files, $index, 3) as $file){
				$preview[]=$file;
			}
		}
		$preview=array_values(array_unique($preview));
		return $preview!==[] ? $preview : array_values(array_slice($files, 0, 12));
	}

	/**
	 * Gives app agents a compact overhead budget for ordinary builder work.
	 *
	 * @return array<string,mixed> Machine-readable progressive-disclosure budget.
	 */
	private function mcp_app_builder_workload_budget(): array {
		return [
			'goal'=>'Keep ordinary app scaffolds fast: use the first-read builder payload before opening broader context.',
			'default_lane'=>'builder_first_read',
			'read_first'=>[
				'next_action',
				'files_summary',
				'schema_summary',
				'app_path_context',
				'naming_contract',
				'write_readiness',
				'scaffold_completion_summary',
				'verification_handoff',
			],
			'phase_read_plan'=>[
				'first_pass'=>[
					'read'=>'read_first',
					'purpose'=>'Decide whether to continue chunks, resolve blockers, supply app_path_context, or start app-owned writes.',
				],
				'resolve_blockers'=>[
					'when'=>'write_readiness.status=resolve_prewrite_blockers',
					'read'=>['prewrite_checklist.prewrite_blockers', 'prewrite_checklist.resolution_plan', 'app_path_context', 'entity_input_contract'],
				],
				'prepare_writes'=>[
					'when'=>'write_readiness.status=ready_for_app_owned_writes',
					'read'=>['local_convention_probe.items', 'implementation_recipe.items', 'write_handoff.first_batch'],
				],
				'focused_verification'=>[
					'when'=>'after app-owned writes',
					'read'=>['verification_execution_plan.items', 'verification_handoff.post_write_handoff_template', 'diagnostic_handoff_hint'],
				],
				'done_review'=>[
					'when'=>'after focused checks',
					'read'=>['acceptance_review_plan.items', 'acceptance_review_plan.post_write_handoff_template'],
				],
				'escalation'=>[
					'when'=>'only for explicit release-facing, corporate-ready, security/governance-sensitive, framework/internal, reusable-framework, or shared hot-path work',
					'read'=>['context_links.governance_detail', 'dataphyre_mcp_enterprise_adoption_audit'],
				],
			],
			'read_now_compatibility'=>'read_now is a legacy compatibility list of useful app-owned sections; ordinary agents should follow phase_read_plan and compact_detail_policy.detail_counts instead of scanning every read_now field.',
			'read_now'=>[
				'next_action',
				'files/schema/Panel fields/filters/actions',
				'naming_contract',
				'scaffold_completion_summary',
				'surface_execution_plan',
				'companion_surface_handoff',
				'app_contract_summary',
				'relationship_contract_summary',
				'relationship_adapter_handoff',
				'data_sensitivity_summary',
				'policy_decision_register',
				'field_metadata_summary',
				'data_integrity_summary',
				'prewrite_checklist',
				'write_readiness',
				'local_convention_probe',
				'implementation_matrix',
				'implementation_recipe',
				'verification_evidence',
				'verification_handoff',
				'verification_execution_plan',
				'verification_fixture_handoff',
				'lifecycle_state_handoff',
				'audit_retention_handoff',
				'access_control_handoff',
				'operational_reliability_handoff',
				'support_observability_handoff',
				'change_management_handoff',
				'integration_boundary_handoff',
				'tenant_identity_handoff',
				'domain_workflow_handoff',
				'reporting_analytics_handoff',
				'notification_communication_handoff',
				'acceptance_review_plan',
				'verification_recovery_plan',
			],
			'open_next'=>[
				'code_skeletons'=>'Open full builder_plan.code_skeletons only when ready to adapt and write app-owned files.',
				'module_docs'=>'Use dataphyre_task_pack_generate payload_profile=builder only when focused Panel/SQL/module docs or a ready prompt are needed.',
				'continuations'=>'Follow entity_planning.continuation_calls when scaffold_completion_summary.complete=false.',
			],
			'chunk_budget'=>[
				'default_max_entities'=>4,
				'hard_cap'=>12,
				'policy'=>'Prefer the default chunk size for first-read app planning; raise max_entities only when the caller explicitly wants a larger first payload.',
				'large_payload_hint'=>'If the first response is becoming hard to scan, keep payload_profile=compact and continue through entity_planning.continuation_calls instead of opening broader context.',
			],
			'keep_collapsed_until_escalation'=>[
				'full application-agent contracts',
				'status board and safety boundary',
				'enterprise audit and governance baseline details',
				'MCP/release-surface publication validation',
			],
			'not_required_for_ordinary_app_work'=>[
				'dataphyre_mcp_verify_all',
				'Dataphyre project-wide release validation',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits to make one application work',
			],
			'escalate_only_when'=>$this->mcp_escalation_triggers(),
		];
	}

	/**
	 * Returns the first path containing a marker.
	 *
	 * @param array<int,string> $paths Candidate paths.
	 * @param string $marker Path marker.
	 * @return string Matching path or an empty string.
	 */
	private function mcp_first_path_matching(array $paths, string $marker): string {
		foreach($paths as $path){
			if(str_contains($path, $marker)){
				return $path;
			}
		}
		return '';
	}

	/**
	 * Returns the best compact data-model summary available for a builder lane.
	 *
	 * @param array<string,mixed> $lane App-builder lane payload.
	 * @return string Data-model summary.
	 */
	private function mcp_app_builder_data_model_summary(array $lane): string {
		$summary=trim((string)($lane['data_model_summary'] ?? ''));
		if($summary!==''){
			return $summary;
		}
		return $this->task_pack_data_model_summary($lane);
	}

	/**
	 * Builds a compact scaffold completion summary from entity planning metadata.
	 *
	 * @param mixed $entity_planning Entity planning metadata.
	 * @return array<string,mixed> Completion summary.
	 */
	private function mcp_app_builder_scaffold_completion_summary(mixed $entity_planning): array {
		$planning=is_array($entity_planning) ? $entity_planning : [];
		$planned=array_values(array_map('strval', is_array($planning['planned_entities'] ?? null) ? $planning['planned_entities'] : []));
		$deferred=array_values(array_map('strval', is_array($planning['deferred_entities'] ?? null) ? $planning['deferred_entities'] : []));
		$truncated=($planning['truncated'] ?? false)===true || $deferred!==[];
		$next_continuation=$this->mcp_app_builder_next_continuation_summary($planning);
		return [
			'owner'=>'consuming_application',
			'complete'=>$truncated===false,
			'status'=>$truncated ? 'incomplete_follow_continuations' : 'complete_single_chunk',
			'planned_entities'=>$planned,
			'deferred_entities'=>$deferred,
			'planned_count'=>count($planned),
			'deferred_count'=>count($deferred),
			'next_action'=>$truncated ? 'Run entity_planning.continuation_calls until deferred_entities is empty before treating the scaffold as complete.' : 'No deferred entity chunks are reported; continue with prewrite_checklist and focused verification after app-owned writes.',
			'next_continuation'=>$next_continuation,
		];
	}

	/**
	 * Summarizes the next app-builder continuation call for compact handoffs.
	 *
	 * @param array<string,mixed> $entity_planning Entity planning metadata.
	 * @return array<string,mixed> Continuation pointer.
	 */
	private function mcp_app_builder_next_continuation_summary(array $entity_planning): array {
		$calls=is_array($entity_planning['continuation_calls'] ?? null) ? array_values($entity_planning['continuation_calls']) : [];
		$next=is_array($calls[0] ?? null) ? $calls[0] : [];
		$arguments=is_array($next['arguments'] ?? null) ? $next['arguments'] : [];
		$entities=array_values(array_map('strval', is_array($arguments['entities'] ?? null) ? $arguments['entities'] : (is_array($next['entities'] ?? null) ? $next['entities'] : [])));
		if($next===[] || $entities===[]){
			return [
				'available'=>false,
				'reason'=>'No deferred entity continuation is required for this compact scaffold.',
			];
		}
		return [
			'available'=>true,
			'tool'=>(string)($next['tool'] ?? 'dataphyre_app_builder_plan_generate'),
			'chunk'=>(int)($next['chunk'] ?? 0),
			'entities'=>$entities,
			'field_scope'=>(string)($arguments['field_scope'] ?? ($arguments['reuse_fields_from_original'] ?? false ? 'reuse_fields_from_original' : 'unspecified')),
			'dependency_context_present'=>is_array($arguments['dependency_context'] ?? null),
			'argument_source'=>'entity_planning.continuation_calls[0].arguments',
			'repeat_until'=>'scaffold_completion_summary.deferred_entities is empty',
		];
	}

	/**
	 * Reduces discovery payloads to the names an app-building agent needs first.
	 *
	 * @param array<string,mixed> $finder Finder payload.
	 * @return array<string,mixed> Compact finder summary.
	 */
	private function mcp_compact_finder_summary(array $finder): array {
		$matches=[];
		foreach(array_slice(is_array($finder['matches'] ?? null) ? $finder['matches'] : [], 0, 4) as $match){
			if(!is_array($match)){
				continue;
			}
			$summary=[
				'name'=>(string)($match['name'] ?? $match['uri'] ?? $match['id'] ?? ''),
			];
			foreach(['id', 'kind', 'group', 'module', 'fetch_tool', 'audience_scope'] as $key){
				if(isset($match[$key]) && trim((string)$match[$key])!==''){
					$summary[$key]=(string)$match[$key];
				}
			}
			foreach(['title', 'description'] as $key){
				if(isset($match[$key]) && trim((string)$match[$key])!==''){
					$summary[$key]=$this->mcp_compact_text((string)$match[$key], 160);
				}
			}
			if(isset($match['path']) && trim((string)$match['path'])!==''){
				$summary['path']=(string)$match['path'];
			}
			if(is_array($match['match_reasons'] ?? null)){
				$summary['match_reasons']=array_values(array_slice(array_map('strval', $match['match_reasons']), 0, 4));
			}
			if(isset($match['score'])){
				$summary['score']=(int)$match['score'];
			}
			$matches[]=$summary;
		}
		return [
			'finder_type'=>(string)($finder['finder_type'] ?? ''),
			'collapsed'=>true,
			'matches'=>$matches,
			'compact_contract'=>'matches keep name plus at least one discriminator such as group, kind, description, module, path, fetch_tool, or match_reasons.',
			'open_full_with'=>'include_detail_context=true',
		];
	}

	private function mcp_compact_text(string $text, int $max): string {
		$text=trim(preg_replace('/\s+/', ' ', $text) ?? $text);
		if($max<4 || strlen($text)<=$max){
			return $text;
		}
		return rtrim(substr($text, 0, $max-3)).'...';
	}

	/**
	 * Summarizes workflow recommendation output without building a handoff pack.
	 *
	 * @param array<string,mixed> $recommendation Workflow recommendation payload.
	 * @param string $task Original task text.
	 * @param bool $include_frames Requested frame mode for a future full handoff.
	 * @return array<string,mixed> Compact workflow summary.
	 */
	private function mcp_workflow_recommendation_summary(array $recommendation, string $task, bool $include_frames): array {
		$top=is_array($recommendation['recommendations'][0] ?? null) ? $recommendation['recommendations'][0] : [];
		$app_builder_next_action=is_array($recommendation['app_builder_next_action'] ?? null)
			? $this->mcp_workflow_compact_app_builder_next_action_ref($recommendation['app_builder_next_action'])
			: ($recommendation['app_builder_next_action'] ?? null);
		$recommendations=[];
		foreach(array_slice(is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [], 0, 4) as $item){
			if(!is_array($item)){
				continue;
			}
			$recommendations[]=[
				'workflow'=>(string)($item['workflow'] ?? ''),
				'title'=>(string)($item['title'] ?? ''),
				'score'=>(int)($item['score'] ?? 0),
				'ready'=>($item['ready'] ?? false)===true,
				'matched_terms'=>array_values(array_map('strval', is_array($item['matched_terms'] ?? null) ? $item['matched_terms'] : [])),
				'recommended_tool'=>(string)($item['recommended_tool'] ?? 'dataphyre_mcp_workflow_handoff_pack_export'),
				'recommended_arguments'=>is_array($item['recommended_arguments'] ?? null) ? $item['recommended_arguments'] : [],
			];
		}
		return [
			'export_type'=>'dataphyre_mcp_workflow_recommendation_handoff_summary',
			'write_policy'=>$recommendation['write_policy'] ?? 'read_only',
			'execution'=>$recommendation['execution'] ?? 'not_executed',
			'protocol'=>$recommendation['protocol'] ?? '2025-11-25',
			'task'=>$task,
			'selected_workflow'=>(string)($top['workflow'] ?? 'generic'),
			'selected_score'=>(int)($top['score'] ?? 0),
			'ready_to_run'=>($top['ready'] ?? false)===true,
			'include_frames'=>$include_frames,
			'full_handoff_computed'=>false,
			'app_builder_entrypoint'=>$recommendation['app_builder_entrypoint'] ?? null,
			'app_builder_next_action'=>$app_builder_next_action,
			'recommendations'=>$recommendations,
			'fetch_tools'=>[
				'full_recommendation_handoff'=>'dataphyre_mcp_workflow_recommendation_handoff_export',
				'workflow_handoff_pack'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'workflow_session'=>'dataphyre_mcp_workflow_session_export',
				'transcript_schema'=>'dataphyre_mcp_workflow_transcript_schema_export',
			],
			'next_read'=>'For ordinary app work, use builder_first_read and app_builder_next_action first; fetch the full workflow handoff only when ready to run preflight workflow messages.',
		];
	}

	/**
	 * Keeps ordinary task start packs small while preserving the workflow handoff entrypoint.
	 *
	 * @param array<string,mixed> $handoff Full workflow recommendation handoff payload.
	 * @return array<string,mixed> Compact workflow summary safe for default app-builder startup.
	 */
	private function mcp_workflow_handoff_summary(array $handoff): array {
		$recommendations=[];
		foreach(array_slice(is_array($handoff['recommendation']['recommendations'] ?? null) ? $handoff['recommendation']['recommendations'] : [], 0, 4) as $recommendation){
			if(!is_array($recommendation)){
				continue;
			}
			$recommendations[]=[
				'workflow'=>(string)($recommendation['workflow'] ?? ''),
				'title'=>(string)($recommendation['title'] ?? ''),
				'score'=>(int)($recommendation['score'] ?? 0),
				'ready'=>($recommendation['ready'] ?? false)===true,
				'matched_terms'=>array_values(array_map('strval', is_array($recommendation['matched_terms'] ?? null) ? $recommendation['matched_terms'] : [])),
				'recommended_tool'=>(string)($recommendation['recommended_tool'] ?? 'dataphyre_mcp_workflow_handoff_pack_export'),
				'recommended_arguments'=>is_array($recommendation['recommended_arguments'] ?? null) ? $recommendation['recommended_arguments'] : [],
			];
		}
		return [
			'export_type'=>'dataphyre_mcp_workflow_recommendation_handoff_summary',
			'write_policy'=>$handoff['write_policy'] ?? 'read_only',
			'execution'=>$handoff['execution'] ?? 'not_executed',
			'protocol'=>$handoff['protocol'] ?? '2025-11-25',
			'task'=>(string)($handoff['task'] ?? ''),
			'selected_workflow'=>(string)($handoff['selected_workflow'] ?? 'generic'),
			'selected_score'=>(int)($handoff['selected_score'] ?? 0),
			'ready_to_run'=>($handoff['handoff_pack']['ready_to_run'] ?? false)===true,
			'include_frames'=>($handoff['include_frames'] ?? false)===true,
			'app_builder_entrypoint'=>$handoff['recommendation']['app_builder_entrypoint'] ?? null,
			'app_builder_next_action'=>is_array($handoff['app_builder_next_action'] ?? null)
				? $this->mcp_workflow_compact_app_builder_next_action_ref($handoff['app_builder_next_action'])
				: (is_array($handoff['recommendation']['app_builder_next_action'] ?? null) ? $this->mcp_workflow_compact_app_builder_next_action_ref($handoff['recommendation']['app_builder_next_action']) : ($handoff['recommendation']['app_builder_next_action'] ?? null)),
			'recommendations'=>$recommendations,
			'fetch_tools'=>[
				'full_recommendation_handoff'=>'dataphyre_mcp_workflow_recommendation_handoff_export',
				'workflow_handoff_pack'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'workflow_session'=>'dataphyre_mcp_workflow_session_export',
				'transcript_schema'=>'dataphyre_mcp_workflow_transcript_schema_export',
			],
			'next_read'=>'For ordinary app work, use builder_first_read and app_builder_next_action first; fetch the full workflow handoff only when ready to run preflight workflow messages.',
		];
	}

	/**
	 * Summarizes app-builder next-action inside workflow summaries without duplicating the full contract.
	 *
	 * @param array<string,mixed> $next_action Full app-builder next-action guidance.
	 * @return array<string,mixed> Compact reference to the primary builder-first fields.
	 */
	private function mcp_workflow_compact_app_builder_next_action_ref(array $next_action): array {
		$status=(string)($next_action['current_status'] ?? $next_action['status'] ?? '');
		return [
			'tool'=>$next_action['tool'] ?? 'dataphyre_app_builder_plan_generate',
			'argument_defaults'=>is_array($next_action['argument_defaults'] ?? null) ? $next_action['argument_defaults'] : ['payload_profile'=>'compact'],
			'decision_field'=>$next_action['decision_field'] ?? 'builder_response.first_read.next_action',
			'detail_decision_field'=>$next_action['detail_decision_field'] ?? 'builder_response.first_read.next_detail_page',
			'current_status'=>$status,
			'current_resume_cursor'=>$next_action['current_resume_cursor'] ?? [],
			'next_detail_page_ref'=>'builder_first_read.next_detail_page or builder_response.first_read.next_detail_page',
			'detail_pagination_ref'=>'builder_response.detail_pagination or top-level detail_pagination',
			'handoff_pages'=>is_array($next_action['handoff_pages'] ?? null) ? array_values(array_slice(array_map('strval', $next_action['handoff_pages']), 0, 4)) : [
				'detail_pagination.pages.planning',
				'detail_pagination.pages.implementation',
				'detail_pagination.pages.verification',
				'detail_pagination.pages.controls',
			],
			'full_contract_ref'=>'builder_first_read.next_action, builder_first_read.next_detail_page, and top-level app_builder_next_action',
			'contract_collapsed'=>true,
		];
	}
}
