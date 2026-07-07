<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP task-pack, apply-audit, and task verification planning surfaces.
 */
trait dataphyre_mcp_planning_task_pack_surfaces {

	/**
	 * Generates a bounded task pack for agent execution.
	 *
	 * task packs combine inferred modules, optional scaffold context,
	 * prompt text, source documents, and verification guidance into a read-only
	 * payload for downstream coding agents.
	 */
	private function generate_task_pack(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		if($task===''){
			throw new InvalidArgumentException('task is required.');
		}
		$modules=$args['modules'] ?? [];
		if(!is_array($modules) || $modules===[]){
			$modules=$this->infer_task_modules($task);
		}
		$proportional_guidance=$this->mcp_task_proportional_guidance($task);
		$payload_profile=strtolower(trim((string)($args['payload_profile'] ?? '')));
		if(!in_array($payload_profile, ['builder', 'governance'], true)){
			$payload_profile='builder';
		}
		$max_chunks=max(1, min((int)($args['max_chunks'] ?? 10) ?: 10, 30));
		if($payload_profile==='builder'){
			$max_chunks=min($max_chunks, 8);
		}
		$docs=$this->export_docs_chunks([
			'modules'=>$modules,
			'max_chunks'=>$max_chunks,
			'max_chars_per_chunk'=>2200,
			'guidelines_position'=>$payload_profile==='governance' ? 'after_modules' : 'none',
			'include_reference'=>$payload_profile==='governance',
			'docs_profile'=>$payload_profile==='builder' ? 'builder' : 'governance',
		]);
		$scaffold=null;
		$scaffold_type=trim((string)($args['scaffold_type'] ?? ''));
		if($scaffold_type!==''){
			$scaffold=$this->generate_scaffold_plan([
				'type'=>$scaffold_type,
				'name'=>trim((string)($args['name'] ?? '')) ?: $task,
				'path'=>trim((string)($args['path'] ?? '')),
				'methods'=>is_array($args['methods'] ?? null) ? $args['methods'] : [],
				'group'=>trim((string)($args['group'] ?? '')),
				'auth'=>trim((string)($args['auth'] ?? '')),
			]);
		}
		$verification=$this->task_pack_verification($modules, $scaffold);
		$app_builder_lane=$this->app_builder_lane($task, $args);
		$include_governance=($args['include_governance'] ?? null);
		$include_governance=is_bool($include_governance)
			? $include_governance
			: $payload_profile==='governance';
		$builder_plan=$this->app_builder_builder_plan($app_builder_lane);
		$builder_governance_notes=$this->app_builder_governance_notes($builder_plan, $app_builder_lane, true);
		if($builder_governance_notes==='none triggered'){
			$builder_governance_notes='none triggered for ordinary app-owned work';
		}
		$builder_plan['governance_notes']=$builder_governance_notes;
		$builder_view_governance_notes=is_array($builder_governance_notes) ? $builder_governance_notes : 'none triggered';
		$builder_first_read=$this->mcp_app_builder_first_read($builder_plan, [], [
			'planning'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
			'verification'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
			'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
			'full_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
		]);
		if(!$include_governance){
			$builder_first_read=$this->mcp_agent_brief_compact_first_read($builder_first_read);
			if(is_array($builder_first_read['next_action'] ?? null)){
				$builder_first_read['next_action']=$this->mcp_agent_brief_compact_next_action($builder_first_read['next_action'], true);
			}
		}
		$scaffold_payload=null;
		if(is_array($scaffold)){
			$scaffold_payload=$include_governance ? $scaffold : [
				'type'=>$scaffold['type'] ?? $scaffold_type,
				'name'=>$scaffold['name'] ?? (trim((string)($args['name'] ?? '')) ?: $task),
				'summary'=>$scaffold['summary'] ?? null,
				'write_policy'=>$scaffold['write_policy'] ?? 'dry_run_only',
				'unsafe_required_to_apply'=>$scaffold['unsafe_required_to_apply'] ?? false,
				'proposed_files'=>$scaffold['proposed_files'] ?? [],
				'field_hints'=>$scaffold['field_hints'] ?? [],
				'steps'=>$scaffold['steps'] ?? [],
				'verification'=>$scaffold['verification'] ?? [],
				'detail_profile'=>'Use payload_profile=governance or include_governance=true for extension_boundary and publication guardrails.',
			];
		}
		$builder_view=[
			'title'=>'Builder plan',
			'first_read'=>$builder_first_read,
			'next_action'=>$this->app_builder_compact_next_action($builder_plan),
			'agent_workload'=>$this->mcp_app_builder_workload_budget(),
			'files'=>$builder_plan['files'] ?? [],
			'app_path_context'=>$builder_plan['app_path_context'] ?? $this->app_builder_path_context([]),
			'schema'=>$builder_plan['schema'] ?? [],
			'naming_contract'=>$builder_plan['naming_contract'] ?? [],
			'entity_input_contract'=>$builder_plan['entity_input_contract'] ?? [],
			'entity_planning'=>$builder_plan['entity_planning'] ?? [],
			'scaffold_completion_summary'=>$builder_plan['scaffold_completion_summary'] ?? [],
			'surface_execution_plan'=>$builder_plan['surface_execution_plan'] ?? [],
			'companion_surface_handoff'=>$builder_plan['companion_surface_handoff'] ?? [],
			'data_sensitivity_summary'=>$builder_plan['data_sensitivity_summary'] ?? [],
			'relationship_contract_summary'=>$builder_plan['relationship_contract_summary'] ?? [],
			'relationship_adapter_handoff'=>$builder_plan['relationship_adapter_handoff'] ?? [],
			'field_metadata_summary'=>$builder_plan['field_metadata_summary'] ?? [],
			'data_model_handoff'=>$this->app_builder_compact_data_model_handoff($builder_plan['data_model'] ?? []),
			'data_integrity_summary'=>$builder_plan['data_integrity_summary'] ?? [],
			'lifecycle_policy_summary'=>$builder_plan['lifecycle_policy_summary'] ?? [],
			'lifecycle_state_handoff'=>$builder_plan['lifecycle_state_handoff'] ?? [],
			'audit_retention_summary'=>$builder_plan['audit_retention_summary'] ?? [],
			'audit_retention_handoff'=>$builder_plan['audit_retention_handoff'] ?? [],
			'access_control_summary'=>$builder_plan['access_control_summary'] ?? [],
			'access_control_handoff'=>$builder_plan['access_control_handoff'] ?? [],
			'operational_reliability_summary'=>$builder_plan['operational_reliability_summary'] ?? [],
			'operational_reliability_handoff'=>$builder_plan['operational_reliability_handoff'] ?? [],
			'support_observability_summary'=>$builder_plan['support_observability_summary'] ?? [],
			'support_observability_handoff'=>$builder_plan['support_observability_handoff'] ?? [],
			'change_management_summary'=>$builder_plan['change_management_summary'] ?? [],
			'change_management_handoff'=>$builder_plan['change_management_handoff'] ?? [],
			'integration_boundary_summary'=>$builder_plan['integration_boundary_summary'] ?? [],
			'integration_boundary_handoff'=>$builder_plan['integration_boundary_handoff'] ?? [],
			'tenant_identity_handoff'=>$builder_plan['tenant_identity_handoff'] ?? [],
			'business_policy_summary'=>$builder_plan['business_policy_summary'] ?? [],
			'process_policy_summary'=>$builder_plan['process_policy_summary'] ?? [],
			'domain_workflow_handoff'=>$builder_plan['domain_workflow_handoff'] ?? [],
			'reporting_analytics_summary'=>$builder_plan['reporting_analytics_summary'] ?? [],
			'reporting_analytics_handoff'=>$builder_plan['reporting_analytics_handoff'] ?? [],
			'notification_communication_summary'=>$builder_plan['notification_communication_summary'] ?? [],
			'notification_communication_handoff'=>$builder_plan['notification_communication_handoff'] ?? [],
			'panel_fields'=>$builder_plan['panel_fields'] ?? [],
			'filters'=>$builder_plan['filters'] ?? [],
			'actions'=>$builder_plan['actions'] ?? [],
			'verification'=>$builder_plan['verification'] ?? [],
			'verification_evidence'=>$builder_plan['verification_plan']['evidence_to_collect'] ?? [],
			'verification_todo'=>$builder_plan['verification_plan']['verification_todo'] ?? [],
			'verification_handoff'=>$builder_plan['verification_plan']['handoff'] ?? [],
			'verification_execution_plan'=>$builder_plan['verification_execution_plan'] ?? [],
			'verification_fixture_handoff'=>$builder_plan['verification_fixture_handoff'] ?? [],
			'acceptance_criteria'=>$builder_plan['acceptance_criteria'] ?? [],
			'acceptance_review_plan'=>$builder_plan['acceptance_review_plan'] ?? [],
			'diagnostic_handoff_hint'=>$builder_plan['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint(is_array($builder_plan['verification_plan'] ?? null) ? $builder_plan['verification_plan'] : []),
			'verification_recovery_plan'=>$builder_plan['verification_recovery_plan'] ?? [],
			'recovery_hints'=>$builder_plan['verification_plan']['recovery_hints'] ?? [],
			'implementation_sequence'=>$builder_plan['implementation_sequence'] ?? [],
			'app_contract_summary'=>$builder_plan['app_contract_summary'] ?? [],
			'policy_decision_register'=>$builder_plan['policy_decision_register'] ?? [],
			'next_edits'=>$builder_plan['next_edits'] ?? [],
			'local_convention_probe'=>$builder_plan['local_convention_probe'] ?? [],
			'write_plan_summary'=>$builder_plan['write_plan_summary'] ?? [],
			'implementation_matrix'=>$builder_plan['implementation_matrix'] ?? [],
			'implementation_recipe'=>$builder_plan['implementation_recipe'] ?? [],
			'write_handoff'=>$builder_plan['write_handoff'] ?? [],
			'prewrite_checklist'=>$builder_plan['prewrite_checklist'] ?? [],
			'write_readiness'=>$builder_plan['write_readiness'] ?? [],
			'extension_boundary_summary'=>$builder_plan['extension_boundary_summary'] ?? $this->app_builder_extension_boundary_summary(),
			'governance_notes'=>$builder_view_governance_notes,
			'secondary_context'=>'Use first_read for the default pass; module docs and focused verification follow; open the full app-builder plan or governance profile only when the next detail page or escalation decision requires it.',
		];
		$app_builder_lane_payload=$include_governance ? $app_builder_lane : [
			'lane'=>$app_builder_lane['lane'] ?? 'builder',
			'scaffold_type'=>$app_builder_lane['scaffold_type'] ?? '',
			'entities'=>$app_builder_lane['entities'] ?? [],
			'first_read'=>$builder_first_read,
			'app_path_context'=>$builder_plan['app_path_context'] ?? ($app_builder_lane['app_path_context'] ?? $this->app_builder_path_context([])),
			'agent_workload'=>$this->mcp_app_builder_workload_budget(),
			'entity_input_contract'=>$app_builder_lane['entity_input_contract'] ?? [],
			'naming_contract'=>$builder_plan['naming_contract'] ?? [],
			'relationship_contract_summary'=>$builder_plan['relationship_contract_summary'] ?? [],
			'relationship_adapter_handoff'=>$builder_plan['relationship_adapter_handoff'] ?? [],
			'field_metadata_summary'=>$builder_plan['field_metadata_summary'] ?? [],
			'data_model_handoff'=>$this->app_builder_compact_data_model_handoff($builder_plan['data_model'] ?? []),
			'data_integrity_summary'=>$builder_plan['data_integrity_summary'] ?? [],
			'lifecycle_policy_summary'=>$builder_plan['lifecycle_policy_summary'] ?? [],
			'lifecycle_state_handoff'=>$builder_plan['lifecycle_state_handoff'] ?? [],
			'audit_retention_summary'=>$builder_plan['audit_retention_summary'] ?? [],
			'audit_retention_handoff'=>$builder_plan['audit_retention_handoff'] ?? [],
			'access_control_summary'=>$builder_plan['access_control_summary'] ?? [],
			'access_control_handoff'=>$builder_plan['access_control_handoff'] ?? [],
			'operational_reliability_summary'=>$builder_plan['operational_reliability_summary'] ?? [],
			'operational_reliability_handoff'=>$builder_plan['operational_reliability_handoff'] ?? [],
			'support_observability_summary'=>$builder_plan['support_observability_summary'] ?? [],
			'support_observability_handoff'=>$builder_plan['support_observability_handoff'] ?? [],
			'change_management_summary'=>$builder_plan['change_management_summary'] ?? [],
			'change_management_handoff'=>$builder_plan['change_management_handoff'] ?? [],
			'integration_boundary_summary'=>$builder_plan['integration_boundary_summary'] ?? [],
			'integration_boundary_handoff'=>$builder_plan['integration_boundary_handoff'] ?? [],
			'tenant_identity_handoff'=>$builder_plan['tenant_identity_handoff'] ?? [],
			'business_policy_summary'=>$builder_plan['business_policy_summary'] ?? [],
			'process_policy_summary'=>$builder_plan['process_policy_summary'] ?? [],
			'domain_workflow_handoff'=>$builder_plan['domain_workflow_handoff'] ?? [],
			'reporting_analytics_summary'=>$builder_plan['reporting_analytics_summary'] ?? [],
			'reporting_analytics_handoff'=>$builder_plan['reporting_analytics_handoff'] ?? [],
			'notification_communication_summary'=>$builder_plan['notification_communication_summary'] ?? [],
			'notification_communication_handoff'=>$builder_plan['notification_communication_handoff'] ?? [],
			'files_to_create'=>$app_builder_lane['files_to_create'] ?? [],
			'entity_planning'=>$app_builder_lane['entity_planning'] ?? [],
			'scaffold_completion_summary'=>$builder_plan['scaffold_completion_summary'] ?? [],
			'surface_execution_plan'=>$builder_plan['surface_execution_plan'] ?? [],
			'companion_surface_handoff'=>$builder_plan['companion_surface_handoff'] ?? [],
			'data_model_summary'=>$this->task_pack_data_model_summary($app_builder_lane),
			'app_contract_summary'=>$builder_plan['app_contract_summary'] ?? [],
			'data_sensitivity_summary'=>$builder_plan['data_sensitivity_summary'] ?? [],
			'policy_decision_register'=>$builder_plan['policy_decision_register'] ?? [],
			'verification'=>$app_builder_lane['verification'] ?? [],
			'verification_evidence'=>$builder_plan['verification_plan']['evidence_to_collect'] ?? [],
			'verification_todo'=>$builder_plan['verification_plan']['verification_todo'] ?? [],
			'verification_handoff'=>$builder_plan['verification_plan']['handoff'] ?? [],
			'verification_execution_plan'=>$builder_plan['verification_execution_plan'] ?? [],
			'verification_fixture_handoff'=>$builder_plan['verification_fixture_handoff'] ?? [],
			'acceptance_criteria'=>$builder_plan['acceptance_criteria'] ?? [],
			'acceptance_review_plan'=>$builder_plan['acceptance_review_plan'] ?? [],
			'diagnostic_handoff_hint'=>$builder_plan['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint(is_array($builder_plan['verification_plan'] ?? null) ? $builder_plan['verification_plan'] : []),
			'verification_recovery_plan'=>$builder_plan['verification_recovery_plan'] ?? [],
			'recovery_hints'=>$builder_plan['verification_plan']['recovery_hints'] ?? [],
			'next_edits'=>$app_builder_lane['next_edits'] ?? [],
			'follow_up_tools'=>$app_builder_lane['follow_up_tools'] ?? [],
			'code_skeleton_summary'=>$builder_plan['code_skeleton_summary'] ?? [],
			'local_convention_probe'=>$builder_plan['local_convention_probe'] ?? [],
			'write_plan_summary'=>$builder_plan['write_plan_summary'] ?? [],
			'implementation_matrix'=>$builder_plan['implementation_matrix'] ?? [],
			'implementation_recipe'=>$builder_plan['implementation_recipe'] ?? [],
			'write_handoff'=>$builder_plan['write_handoff'] ?? [],
			'prewrite_checklist'=>$builder_plan['prewrite_checklist'] ?? [],
			'write_readiness'=>$builder_plan['write_readiness'] ?? [],
			'extension_boundary_summary'=>$app_builder_lane['extension_boundary_summary'] ?? $this->app_builder_extension_boundary_summary(),
			'governance_lane'=>$app_builder_lane['governance_lane'] ?? [],
			'governance_notes'=>$builder_view_governance_notes,
			'code_skeleton_policy'=>$app_builder_lane['code_skeleton_policy'] ?? '',
			'full_plan'=>'Use dataphyre_app_builder_plan_generate for full app-builder lane details and code_skeletons.',
		];
		$builder_response=$this->app_builder_compact_response($builder_plan, [
				'module_docs'=>'docs_chunks',
				'full_builder_plan'=>'builder_plan',
				'compact_lane'=>'app_builder_lane',
				'governance'=>'include_governance=true or payload_profile=governance only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary(),
		]);
		$payload=[
			'builder_first_read'=>$builder_first_read,
			'builder_response'=>$builder_response,
			'builder_view'=>$builder_view,
			'builder_plan'=>$builder_plan,
			'app_builder_lane'=>$app_builder_lane_payload,
			'task'=>$task,
			'write_policy'=>'context_only',
			'payload_profile'=>$payload_profile,
			'context_policy'=>[
				'default_lane'=>$payload_profile==='builder' ? 'builder_first_read' : 'builder_plan',
				'app_builder_entrypoint'=>'dataphyre_app_builder_plan_generate',
				'payload_profile'=>$payload_profile,
				'profiles'=>[
					'builder'=>'builder_first_read, compact builder_response refs, module docs, focused verification, collapsed governance',
					'governance'=>'builder profile plus extension boundary, publication validation, and guardrails',
				],
				'docs_priority'=>$payload_profile==='builder'
					? 'practical Panel/SQL builder sections before MCP governance or status/audit docs'
					: 'requested module documentation before MCP governance guidelines',
				'docs_chunks_limit'=>$max_chunks,
				'governance'=>'collapsed unless include_governance=true or payload_profile=governance is requested for escalation triggers: '.$this->mcp_escalation_trigger_summary(),
				'governance_inline'=>$include_governance,
			],
			'modules'=>$docs['modules'] ?? [],
			'prompt'=>$this->task_pack_prompt($task, $docs['modules'] ?? [], $verification, $app_builder_lane),
			'docs_chunks'=>$docs['chunks'] ?? [],
			'scaffold_plan'=>$scaffold_payload,
			'verification_policy'=>[
				'default'=>'focused_application_or_module_verification',
				'app_behavior_claim'=>'Use the verification list for affected app/module behavior only; MCP health is not proof of app behavior.',
				'escalate_only_for'=>'Use MCP doctor/self-test evidence only when MCP surfaces, published shared MCP setup docs, release notes, or MCP/release-surface claims change.',
			],
			'verification'=>$verification,
			'governance_lane'=>[
				'collapsed'=>!$include_governance,
				'open_with'=>'include_governance=true or payload_profile=governance',
				'open_when'=>$app_builder_lane['governance_lane']['open_when'] ?? [],
			],
		];
		if(!$include_governance){
			$compact_builder_view=$this->mcp_app_builder_compact_builder_view(
				$builder_plan,
				$app_builder_lane,
				$builder_first_read,
				[],
				is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [],
				$builder_view_governance_notes
			);
			$payload=$this->mcp_app_builder_apply_compact_envelope($payload, $compact_builder_view+[
				'first_read_ref'=>'builder_first_read',
				'detail_pagination'=>$this->mcp_app_builder_detail_pagination(),
				'secondary_context'=>'Default task-pack builder profile is compact; fetch dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for the page needed next.',
			], [
				'builder_view',
				'builder_plan',
				'app_builder_lane',
			], [
				'default_lane'=>'builder_first_read',
				'details_collapsed'=>true,
				'omitted_default_fields'=>[
					'builder_view',
					'builder_plan',
					'app_builder_lane',
					'raw handoff_fields',
				],
				'open_detail_page_with'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page>',
				'open_full_plan_with'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				'profiles'=>array_merge(
					is_array($payload['context_policy']['profiles'] ?? null) ? $payload['context_policy']['profiles'] : [],
					['builder'=>'builder_first_read, compact builder_response refs, module docs, focused verification, collapsed governance']
				),
			]);
		}
		if($include_governance){
			$payload['extension_boundary']=$scaffold['extension_boundary'] ?? $this->planning_extension_boundary('task_pack');
			$payload['publication_validation']=[
				'dataphyre_mcp_doctor',
				'Use dataphyre_mcp_verify_all only before publishing shared MCP/release-surface claims.',
			];
			$payload['guardrails']=[
				'Read docs before editing.',
				'Keep shared runtime code app-agnostic.',
				'Use application code, configuration, dialbacks, callbacks, plugins, MCP metadata, or application-owned adapters before Dataphyre runtime internals.',
				'Do not execute SQL queries, dispatch routes, expose config secrets, or write scaffold files from this pack alone.',
			];
		}
		return $payload;
	}

	/**
	 * Builds a static apply-audit plan for a proposed change.
	 *
	 * the plan classifies touched files, infers verification, risk, and
	 * readiness steps, and remains read-only so MCP can prepare implementation
	 * guidance without applying patches.
	 */
	private function apply_audit_plan(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		if($task===''){
			throw new InvalidArgumentException('task is required.');
		}
		$summary=trim((string)($args['change_summary'] ?? ''));
		$files=[];
		$file_inputs=is_array($args['proposed_files'] ?? null) ? $args['proposed_files'] : [];
		foreach($file_inputs as $file){
			$file=trim(str_replace('\\', '/', (string)$file));
			if($file!==''){
				$files[]=$this->apply_audit_file_entry($file);
			}
		}
		$verification=[];
		if(is_array($args['verification'] ?? null)){
			foreach($args['verification'] as $item){
				$item=trim((string)$item);
				if($item!==''){
					$verification[]=$item;
				}
			}
		}
		foreach($this->apply_audit_inferred_verification($files) as $item){
			$verification[]=$item;
		}
		$verification=array_values(array_unique($verification));
		$publication_validation=$this->apply_audit_publication_validation($files);
		$risk_hint=strtolower(trim((string)($args['risk_level'] ?? '')));
		if(!in_array($risk_hint, ['low', 'medium', 'high', 'critical'], true)){
			$risk_hint='';
		}
		$risks=$this->apply_audit_risks($files, $summary);
		$risk_level=$risk_hint!=='' ? $risk_hint : $this->apply_audit_risk_level($risks, $files);
		$audit_source=json_encode([
			'task'=>$task,
			'summary'=>$summary,
			'files'=>array_map(static fn(array $file): string => (string)($file['path'] ?? ''), $files),
			'verification'=>$verification,
			'risk'=>$risk_level,
		], JSON_UNESCAPED_SLASHES);
		return [
			'plan_type'=>'dataphyre_apply_audit_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'unsafe_required_to_apply'=>true,
			'audit_id'=>substr(hash('sha256', (string)$audit_source), 0, 16),
			'task'=>$task,
			'change_summary'=>$summary,
			'risk_level'=>$risk_level,
			'proposed_files'=>$files,
			'extension_boundary'=>$this->planning_extension_boundary('apply_audit'),
			'apply_next_action'=>$this->apply_next_action('apply_audit', $files),
			'verification'=>$verification,
			'publication_validation'=>$publication_validation,
			'risks'=>$risks,
			'apply_contract'=>[
				'future_runner_must_revalidate_paths'=>true,
				'future_runner_must_capture_diff'=>true,
				'future_runner_must_run_requested_verification'=>true,
				'future_runner_must_report_unrelated_dirty_files'=>true,
				'future_runner_must_not_revert_user_changes'=>true,
				'future_runner_must_enforce_extension_boundary'=>true,
			],
			'runtime_internal_write_gate'=>[
				'applies_when'=>'proposed_files include common/dataphyre/runtime internals, kernel, Framework, or shared hot-path files',
				'required_review'=>'Confirm app code, config, dialbacks, callbacks, plugins, MCP metadata, or an application-owned adapter cannot carry the behavior.',
				'maintainer_gate'=>'Use dataphyre_mcp_enterprise_adoption_audit for Dataphyre-internal, release-facing, corporate-ready, or shared hot-path work before any write-capable workflow.',
				'application_rule'=>'Do not modify Dataphyre internals just to make one application work.',
			],
			'rollback_notes'=>[
				'Prefer a focused follow-up patch that restores the previous behavior over broad checkout/reset operations.',
				'Record touched files and verification output so rollback can be reviewed without guessing.',
				'Do not delete or revert unrelated user changes while undoing an applied change set.',
			],
		];
	}

	/**
	 * Produces runtime readiness guidance for an apply request.
	 *
	 * the plan names source inspections, bounded checks, forbidden
	 * side effects, and release gates required before applying or publishing
	 * runtime-impacting Dataphyre changes.
	 */
	private function apply_runtime_readiness_plan(array $args): array {
		$task=trim((string)($args['task'] ?? '<task>'));
		if($task===''){
			$task='<task>';
		}
		return [
			'plan_type'=>'dataphyre_apply_runtime_readiness_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'files_written'=>false,
			'commands_run'=>false,
			'git_mutations'=>false,
			'task'=>$task,
			'current_safe_surfaces'=>[
				'audit_envelope'=>'dataphyre_apply_audit_plan',
				'scaffold_plan'=>'dataphyre_scaffold_plan_generate',
				'task_pack'=>'dataphyre_task_pack_generate',
				'verification_catalog'=>'dataphyre_verification_surface_catalog',
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
			],
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('apply_runtime_readiness'),
			'extension_boundary'=>$this->planning_extension_boundary('apply_runtime_readiness'),
			'apply_next_action'=>$this->apply_next_action('apply_runtime_readiness', []),
			'future_runner_preconditions'=>[
				'unsafe opt-in must be explicit and visible in the call envelope',
				'dataphyre_apply_audit_plan must be generated and passed unchanged to the runner',
				'audit extension_boundary must be reviewed before runtime-internal writes',
				'audit_id, proposed files, risk level, verification, and rollback notes must be revalidated before writes',
				'each write path must resolve inside the repository and outside vendor, dependency, and sensitive-file boundaries',
				'the runner must detect unrelated dirty files before writing and must not revert user changes',
				'the runner must capture before/after content hashes and a bounded diff for every touched file',
				'requested verification must run or be reported as skipped with reasons before success is claimed',
			],
			'allowed_future_outputs'=>[
				'audit id and accepted file list',
				'before and after content hashes',
				'bounded unified diff summaries',
				'verification command/tool results',
				'unrelated dirty-file report',
				'rollback plan and touched-file manifest',
			],
			'denied_future_behaviors'=>[
				'git reset, broad checkout, or reverting unrelated user changes',
				'writing outside repo-relative paths',
				'editing credentials, private keys, dependency directories, or generated vendor assets without explicit separate approval',
				'modifying Dataphyre runtime internals just to make one application work when extension points can carry the behavior',
				'running SQL, dispatching routes, launching browsers, or starting servers as part of a file apply',
				'silently skipping requested verification',
				'hardcoding product-specific paths or local binaries into shared Dataphyre MCP code',
			],
			'audit_envelope_required_fields'=>[
				'audit_id',
				'task',
				'change_summary',
				'risk_level',
				'proposed_files',
				'verification',
				'publication_validation',
				'risks',
				'apply_contract',
				'rollback_notes',
			],
			'client_steps'=>[
				'Generate dataphyre_apply_audit_plan for the proposed change set before any write-capable workflow.',
				'Review extension_boundary and prefer app code, config, dialbacks, callbacks, plugins, MCP metadata, or application adapters before runtime internals.',
				'Review proposed files, risk level, verification, warnings, and rollback notes with the caller or policy engine.',
				'Require the future runner to revalidate path scope and dirty-worktree state immediately before writing.',
				'Require the future runner to run focused app/module verification for ordinary changes, and reserve dataphyre_mcp_verify_all for MCP/release-surface publication validation.',
				'Publish apply results only with touched files, bounded diffs, verification outcomes, and rollback guidance.',
			],
			'safety_notes'=>[
				'This plan does not write files, run shell commands, mutate git state, or apply scaffold output.',
				'Unsafe apply remains intentionally outside default read-only MCP behavior.',
				'Keep shared MCP plans product-neutral; project-specific file choices belong in caller-owned audit envelopes.',
			],
		];
	}

	/**
	 * Reduces apply planning boundaries to one placement decision.
	 *
	 * @param string $surface Apply planning surface.
	 * @param array<int,array<string,mixed>> $files Normalized proposed file entries.
	 * @return array<string,mixed> Compact next action for future apply workflows.
	 */
	private function apply_next_action(string $surface, array $files): array {
		$paths=array_map(static fn(array $file): string => (string)($file['scope_path'] ?? $file['path'] ?? ''), $files);
		$has_framework_scope=false;
		$has_runtime_internal=false;
		foreach($paths as $path){
			if(
				$this->apply_audit_path_is_in_scope($path, 'runtime')
				|| $this->apply_audit_path_is_in_scope($path, 'dev')
				|| $this->apply_audit_path_is_in_scope($path, 'docs')
			){
				$has_framework_scope=true;
			}
			if(
				$this->apply_audit_path_is_in_scope($path, 'runtime')
				|| str_contains($path, '/kernel/')
				|| str_contains($path, '/Framework/')
			){
				$has_runtime_internal=true;
			}
		}
		$boundary=$this->planning_extension_boundary($surface);
		$base=[
			'owner'=>'consuming_application',
			'surface'=>$surface,
			'preferred_layers'=>array_values(array_filter(
				$boundary['preferred_order'] ?? [],
				static fn(string $layer): bool => $layer!=='runtime_internals'
			)),
			'handoff_fields'=>['apply_next_action', 'extension_boundary.decision_ladder', 'runtime_internal_write_gate', 'verification', 'publication_validation'],
			'not_required'=>[
				'Dataphyre runtime-internal edits to make one application work',
				'dataphyre_mcp_verify_all for ordinary app behavior',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
		if($has_framework_scope || $has_runtime_internal){
			return $base+[
				'status'=>'escalate_framework_change',
				'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
				'arguments'=>['task'=>'<framework or MCP change>', 'files'=>'<same proposed_files>', 'public_claim'=>false],
				'runtime_internal_allowed'=>false,
				'action'=>'Pause before any future write-capable runner and prove why app code, config, callbacks, dialbacks, plugins, MCP metadata, or an application-owned adapter cannot carry the behavior.',
				'evidence_required'=>[
					'reusable Dataphyre framework behavior or MCP/release-surface rationale',
					'focused verification for touched files',
					'publication validation only for shared MCP/release-surface claims',
					'maintainer/source-checkout benchmark evidence only for Dataphyre shared production hot-path changes',
				],
			];
		}
		return $base+[
			'status'=>'use_app_owned_extension_point',
			'tool'=>'dataphyre_apply_audit_plan',
			'arguments'=>['task'=>'<ordinary app change>', 'proposed_files'=>['applications/<app>/...']],
			'runtime_internal_allowed'=>false,
			'action'=>'Keep behavior in app-owned files or extension points, generate an audit envelope, then run focused app/module verification before any caller-owned write.',
			'evidence_required'=>[
				'app-owned placement decision',
				'focused verification for changed app/module files',
				'copy-safe pass/fail summary without raw logs or secrets',
			],
		];
	}

	/**
	 * Classifies one file for apply-audit output.
	 *
	 * file entries normalize paths, assign module/category metadata, and
	 * attach likely verification expectations without reading or mutating the file
	 * beyond safe repository path checks.
	 */
	private function apply_audit_file_entry(string $file): array {
		$normalized=ltrim(trim(str_replace('\\', '/', $file)), '/');
		while(str_contains($normalized, '//')){
			$normalized=str_replace('//', '/', $normalized);
		}
		$scope_path=$this->apply_audit_scope_path($normalized);
		$entry=[
			'path'=>$normalized,
			'scope_path'=>$scope_path,
			'package_scope'=>$this->apply_audit_package_scope($scope_path),
			'repo_relative'=>true,
			'exists'=>false,
			'extension'=>strtolower(pathinfo($normalized, PATHINFO_EXTENSION)),
			'category'=>$this->apply_audit_file_category($normalized),
			'warnings'=>[],
		];
		try{
			$safe=$this->safe_repo_path($normalized);
			$entry['exists']=is_file($safe);
		}
		catch(Throwable){
			$entry['repo_relative']=false;
			$entry['warnings'][]='path_is_not_repo_relative';
		}
		if(str_contains($normalized, '..')){
			$entry['warnings'][]='path_contains_parent_segment';
		}
		if(preg_match('/\.(env|pem|key|p12|pfx)$/i', $normalized)===1){
			$entry['warnings'][]='sensitive_file_type';
		}
		$lower=strtolower($normalized);
		if(str_contains($lower, '/vendor/') || str_contains($lower, '/node_modules/')){
			$entry['warnings'][]='third_party_directory';
		}
		return $entry;
	}

	/**
	 * Normalizes common Dataphyre package prefixes for scope classification.
	 *
	 * caller paths are preserved in output, but internal routing uses a package
	 * relative path so common/dataphyre/runtime, dataphyre/runtime, and runtime
	 * classify consistently.
	 */
	private function apply_audit_scope_path(string $path): string {
		$normalized=ltrim(trim(str_replace('\\', '/', $path)), '/');
		while(str_contains($normalized, '//')){
			$normalized=str_replace('//', '/', $normalized);
		}
		while(str_starts_with($normalized, './')){
			$normalized=substr($normalized, 2);
		}
		foreach(['common/dataphyre/', 'dataphyre/'] as $prefix){
			if(str_starts_with($normalized, $prefix)){
				return substr($normalized, strlen($prefix));
			}
		}
		return $normalized;
	}

	/**
	 * Tests exact path scope membership without prefix-sibling false positives.
	 */
	private function apply_audit_path_is_in_scope(string $path, string $scope): bool {
		$scope=rtrim($this->apply_audit_scope_path($scope), '/');
		$path=$this->apply_audit_scope_path($path);
		return $path===$scope || str_starts_with($path, $scope.'/');
	}

	/**
	 * Labels whether a proposed file belongs to the Dataphyre package surface.
	 */
	private function apply_audit_package_scope(string $scope_path): string {
		foreach(['runtime', 'dev', 'docs', 'documentation'] as $scope){
			if($this->apply_audit_path_is_in_scope($scope_path, $scope)){
				return 'dataphyre_package';
			}
		}
		return 'caller';
	}

	/**
	 * Infers the audit category for a repository path.
	 *
	 * categories distinguish runtime source, docs, tests, generated
	 * artifacts, configuration, and unknown files so apply plans can communicate
	 * risk without deep semantic analysis.
	 */
	private function apply_audit_file_category(string $file): string {
		$normalized=strtolower(str_replace('\\', '/', $file));
		return match(true){
			str_contains($normalized, '/documentation/') || str_ends_with($normalized, '.md') => 'documentation',
			str_contains($normalized, '/unit_tests/') || str_contains($normalized, 'test') => 'test',
			str_ends_with($normalized, '.php') => 'php_source',
			str_ends_with($normalized, '.json') => 'json_manifest',
			str_ends_with($normalized, '.ps1') || str_ends_with($normalized, '.sh') => 'script',
			default => 'other',
		};
	}

	/**
	 * Infers verification commands or checks from changed files.
	 *
	 * verification is derived from file categories and modules, giving
	 * callers a conservative checklist while avoiding command execution inside the
	 * read-only planning tool.
	 */
	private function apply_audit_inferred_verification(array $files): array {
		$verification=[];
		foreach($files as $file){
			$extension=(string)($file['extension'] ?? '');
			$path=(string)($file['scope_path'] ?? $file['path'] ?? '');
			if($extension==='php'){
				$verification[]='dataphyre_php_lint';
			}
			if($this->apply_audit_path_is_in_scope($path, 'runtime/modules/panel')){
				$verification[]='dataphyre_run_panel_field_catalog_check';
			}
			if($extension==='json'){
				$verification[]='JSON parse check';
			}
		}
		return array_values(array_unique($verification));
	}

	/**
	 * Infers Dataphyre project evidence needed before publishing changed surfaces.
	 *
	 * @param array<int,array<string,mixed>> $files Proposed file entries.
	 * @return array<int,string> Publication validation guidance.
	 */
	private function apply_audit_publication_validation(array $files): array {
		$publication_validation=[];
		foreach($files as $file){
			$path=(string)($file['scope_path'] ?? $file['path'] ?? '');
			if(
				$this->apply_audit_path_is_in_scope($path, 'runtime/modules/mcp')
				|| str_starts_with($path, 'dev/tools/mcp_')
			){
				$publication_validation[]='dataphyre_mcp_doctor';
				$publication_validation[]='Dataphyre MCP publication evidence';
				$publication_validation[]='MCP app-coupling guard scan';
				$publication_validation[]='dataphyre_mcp_verify_all only before publishing shared MCP/release-surface claims';
			}
			if($this->apply_audit_path_is_in_scope($path, 'docs') || $path==='RELEASE_MANIFEST'){
				$publication_validation[]='maintainer/source-checkout release check evidence before public claims';
			}
		}
		return array_values(array_unique($publication_validation));
	}

	/**
	 * Infers implementation risks for an apply plan.
	 *
	 * risks are derived from changed file categories and the user summary,
	 * highlighting runtime, SQL, routing, API, generated-code, and documentation
	 * concerns without claiming test coverage.
	 */
	private function apply_audit_risks(array $files, string $summary): array {
		$risks=[];
		foreach($files as $file){
			$path=(string)($file['scope_path'] ?? $file['path'] ?? '');
			$category=(string)($file['category'] ?? 'other');
			if(($file['repo_relative'] ?? true)!==true){
				$risks[]='non_repo_relative_path';
			}
			foreach($file['warnings'] ?? [] as $warning){
				$risks[]=(string)$warning;
			}
			if(str_contains($path, '/config/') || str_contains($path, '.env')){
				$risks[]='configuration_surface';
			}
			if($category==='script'){
				$risks[]='script_surface';
			}
			if(str_contains($path, '/kernel/') || str_contains($path, '/Framework/') || $this->apply_audit_path_is_in_scope($path, 'runtime')){
				$risks[]='runtime_surface';
			}
		}
		if(preg_match('/\b(sql|query|dispatch|route|auth|permission|secret|token|password|credential|delete|remove)\b/i', $summary)===1){
			$risks[]='sensitive_behavior_summary';
		}
		return array_values(array_unique($risks));
	}

	/**
	 * Collapses inferred risks into a coarse risk level.
	 *
	 * high-level risk is intentionally conservative, combining risk
	 * classes with file count and runtime-sensitive categories to guide review
	 * depth rather than approve changes automatically.
	 */
	private function apply_audit_risk_level(array $risks, array $files): string {
		if(in_array('non_repo_relative_path', $risks, true) || in_array('sensitive_file_type', $risks, true)){
			return 'critical';
		}
		if(in_array('configuration_surface', $risks, true) || in_array('runtime_surface', $risks, true) || in_array('sensitive_behavior_summary', $risks, true)){
			return 'high';
		}
		if(count($files)>5 || in_array('script_surface', $risks, true)){
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Infers likely Dataphyre modules from a task description.
	 *
	 * keyword matching supplies lightweight module hints for task packs
	 * and generated context; it is advisory only and does not replace source
	 * inspection before editing.
	 */
	private function infer_task_modules(string $task): array {
		$lower=strtolower($task);
		$modules=[];
		$app_builder_task=false;
		foreach(['app', 'application', 'admin', 'crud', 'panel', 'resource', 'resources', 'schema', 'table', 'tables', 'filter', 'filters', 'action', 'actions', 'tracker', 'ticket', 'project', 'internal tool'] as $needle){
			if(str_contains($lower, $needle)){
				$app_builder_task=true;
				break;
			}
		}
		if($app_builder_task){
			$modules[]='panel';
			$modules[]='sql';
		}
		foreach([
			'panel'=>['panel', 'admin', 'crud', 'resource', 'resources', 'form', 'table', 'field', 'filter', 'filters', 'action', 'actions'],
			'routing'=>['route', 'controller', 'url', 'middleware'],
			'sql'=>['sql', 'table', 'schema', 'database', 'migration'],
			'mvc'=>['mvc', 'view'],
			'tracelog'=>['tracelog', 'log', 'diagnostic', 'trace'],
			'mcp'=>['mcp', 'stdio', 'client', 'prompt', 'tool call', 'tool list', 'workflow pack'],
		] as $module=>$needles){
			foreach($needles as $needle){
				if(str_contains($lower, $needle)){
					$modules[]=$module;
					break;
				}
			}
		}
		if($modules===[]){
			$modules[]='mcp';
		}
		return array_values(array_unique($modules));
	}

	/**
	 * Renders the task-pack prompt text.
	 *
	 * the prompt combines the requested task, inferred modules, and
	 * verification expectations into portable agent guidance while preserving the
	 * user's wording as task data.
	 */
	private function task_pack_prompt(string $task, array $modules, array $verification, array $app_builder_lane=[]): string {
		$files=array_values(array_slice(array_map('strval', is_array($app_builder_lane['files_to_create'] ?? null) ? $app_builder_lane['files_to_create'] : []), 0, 8));
		$edits=array_values(array_slice(array_map('strval', is_array($app_builder_lane['next_edits'] ?? null) ? $app_builder_lane['next_edits'] : []), 0, 5));
		$entities=array_values(array_slice(array_map('strval', is_array($app_builder_lane['entities'] ?? null) ? $app_builder_lane['entities'] : []), 0, 6));
		$entity_planning=is_array($app_builder_lane['entity_planning'] ?? null) ? $app_builder_lane['entity_planning'] : [];
		$deferred_entities=array_values(array_map('strval', is_array($entity_planning['deferred_entities'] ?? null) ? $entity_planning['deferred_entities'] : []));
		$chunk_note=($entity_planning['truncated'] ?? false)===true
			? 'yes; deferred entities: '.implode(', ', $deferred_entities).'. Run entity_planning.continuation_calls before treating the scaffold as complete.'
			: 'no';
		$lines=[
			'You are working in Dataphyre.',
			'Task: '.$task,
			'',
			'Builder first read:',
			'- Entities: '.($entities!==[] ? implode(', ', $entities) : 'infer from task and local app conventions'),
			'- Chunked scaffold: '.$chunk_note,
			'- Files: '.($files!==[] ? implode(', ', $files) : 'inspect the consuming application and propose app-owned files'),
			'- Data model: '.$this->task_pack_data_model_summary($app_builder_lane),
			'- Done when: '.$this->task_pack_acceptance_summary($app_builder_lane),
			'- Next edits: '.($edits!==[] ? implode(' ', $edits) : 'inspect local app conventions, then create the smallest app-owned change set'),
			'- Verification: '.implode(', ', $verification),
			'- Profile: use payload_profile=builder for ordinary app work; open detail/full app-builder pages only for the next needed planning, implementation, verification, controls, or skeleton detail; use payload_profile=governance only when extension boundary, publication validation, and guardrails must be inline.',
			'',
			'Use the included module docs chunks for local API shape, then inspect local files before editing.',
			'Keep reusable runtime changes app-agnostic and route-free unless the Routing/MVC surface owns that behavior.',
			'Respect MCP safety: no SQL query execution, route dispatch, config secret exposure, or scaffold file writes from this context pack alone.',
			'',
			'Focus modules: '.implode(', ', $modules),
			'Governance notes: none for ordinary app-owned work; escalate only for release-facing, corporate-ready, security/governance-sensitive, Dataphyre framework, or shared hot-path work.',
		];
		return implode("\n", $lines);
	}

	/**
	 * Summarizes data-model artifacts for the task-pack prompt.
	 *
	 * @param array<string,mixed> $app_builder_lane Builder lane payload.
	 * @return string Compact data-model summary.
	 */
	private function task_pack_data_model_summary(array $app_builder_lane): string {
		$models=is_array($app_builder_lane['data_model'] ?? null) ? $app_builder_lane['data_model'] : [];
		if($models===[]){
			return 'inspect existing app schema/repository conventions before adding data artifacts';
		}
		$parts=[];
		foreach(array_slice($models, 0, 3) as $model){
			if(!is_array($model)){
				continue;
			}
			$paths=is_array($model['artifact_paths'] ?? null) ? $model['artifact_paths'] : [];
			$parts[]=(string)($model['table'] ?? '').' via '.implode(', ', array_slice(array_map('strval', $paths), 0, 3));
		}
		return $parts!==[] ? implode('; ', $parts) : 'inspect existing app schema/repository conventions before adding data artifacts';
	}

	/**
	 * Summarizes app-builder acceptance criteria for the prompt.
	 *
	 * @param array<string,mixed> $app_builder_lane Builder lane payload.
	 * @return string Compact acceptance summary.
	 */
	private function task_pack_acceptance_summary(array $app_builder_lane): string {
		$criteria=is_array($app_builder_lane['acceptance_criteria'] ?? null) ? $app_builder_lane['acceptance_criteria'] : [];
		$criteria=array_values(array_filter(array_map('strval', $criteria), static fn(string $item): bool => $item!==''));
		if($criteria===[]){
			return 'focused app/module checks pass and changes stay app-owned';
		}
		return implode(' ', array_slice($criteria, 0, 3));
	}

	/**
	 * Builds verification guidance for a generated task pack.
	 *
	 * checks are assembled from inferred modules and optional scaffold
	 * type, producing a bounded list of suggested commands/tools without executing
	 * them.
	 */
	private function task_pack_verification(array $modules, ?array $scaffold): array {
		$verification=['dataphyre_php_lint'];
		foreach($modules as $module){
			if($module==='panel'){
				$verification[]='dataphyre_run_panel_regression';
				$verification[]='dataphyre_run_panel_field_catalog_check';
			}
			if($module==='routing' || $module==='mvc'){
				$verification[]='dataphyre_route_manifest_read';
				$verification[]='dataphyre_route_match_preview';
			}
			if($module==='sql'){
				$verification[]='dataphyre_sql_schema_read';
				$verification[]='dataphyre_sql_tables_list';
			}
			if($module==='tracelog'){
				$verification[]='dataphyre_tracelog_artifacts_list';
			}
		}
		if(is_array($scaffold)){
			foreach($scaffold['verification'] ?? [] as $tool){
				$verification[]=(string)$tool;
			}
		}
		return array_values(array_unique($verification));
	}
}
