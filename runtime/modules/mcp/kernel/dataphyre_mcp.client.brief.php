<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines compact MCP agent brief surfaces for cold starts and handoffs.
 */
trait dataphyre_mcp_client_brief_surfaces {

	/**
	 * Builds an agent brief with tools, resources, risks, and workflow guidance.
	 *
	 * @param array<string,mixed> $args Brief target and task options.
	 * @return array Agent brief payload.
	 */
	private function mcp_agent_brief_export(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$limit=max(1, min(8, (int)($args['limit'] ?? 4)));
		$app_builder_task=$this->mcp_task_implies_app_builder($task);
		if($app_builder_task){
			$workflow_recommendation=$this->mcp_workflow_recommend(['task'=>$task, 'limit'=>$limit]);
			$top_recommendation=is_array($workflow_recommendation['recommendations'][0] ?? null) ? $workflow_recommendation['recommendations'][0] : [];
			$selected_workflow=(string)($top_recommendation['workflow'] ?? 'feature');
			if($selected_workflow===''){
				$selected_workflow='feature';
			}
			$builder_args=$args;
			$builder_args['task']=$task;
			$app_builder_lane=$this->app_builder_lane($task, $builder_args);
			$builder_start=$this->mcp_app_builder_start_summary($app_builder_lane);
			$builder_preview_summaries=is_array($builder_start['preview_summaries'] ?? null) ? $builder_start['preview_summaries'] : [];
			$builder_first_read=$this->mcp_app_builder_first_read($builder_start, $builder_preview_summaries, [
				'planning'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'verification'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
				'full_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			]);
			$builder_governance_notes=is_array($builder_start['governance_notes'] ?? null)
				? $builder_start['governance_notes']
				: 'none triggered';
			$proportional_guidance=$this->mcp_task_proportional_guidance($task);
			return $this->mcp_agent_brief_compact_app_payload(
				$task,
				$target,
				$selected_workflow,
				($top_recommendation['ready'] ?? true)===true,
				$builder_first_read,
				$builder_start,
				$builder_governance_notes,
				($proportional_guidance['enterprise_review_required'] ?? false)===true
			);
		}
		$start_pack=$this->mcp_task_start_pack_export([
			'task'=>$task,
			'target'=>$target,
			'limit'=>$limit,
			'include_frames'=>false,
			'entities'=>$args['entities'] ?? null,
			'fields'=>$args['fields'] ?? null,
			'max_entities'=>$args['max_entities'] ?? null,
			'application_path'=>$args['application_path'] ?? null,
			'app_namespace'=>$args['app_namespace'] ?? null,
		]);
		$selected_workflow=(string)($start_pack['workflow_handoff']['selected_workflow'] ?? 'client');
		$lifecycle=$this->mcp_workflow_lifecycle_export(['workflow'=>$selected_workflow]);
		$workflow_entry=[];
		foreach(is_array($lifecycle['workflows'] ?? null) ? $lifecycle['workflows'] : [] as $entry){
			if(is_array($entry) && (string)($entry['workflow'] ?? '')===$selected_workflow){
				$workflow_entry=$entry;
				break;
			}
		}
		$app_builder_lane=is_array($start_pack['app_builder_lane'] ?? null) ? $start_pack['app_builder_lane'] : $this->app_builder_lane($task, $args);
		$builder_start=is_array($start_pack['builder_start'] ?? null) ? $start_pack['builder_start'] : $this->mcp_app_builder_start_summary($app_builder_lane);
		$builder_write_readiness=is_array($builder_start['write_readiness'] ?? null) && $builder_start['write_readiness']!==[]
			? $builder_start['write_readiness']
			: $this->app_builder_write_readiness($builder_start['scaffold_completion_summary'] ?? $this->mcp_app_builder_scaffold_completion_summary($app_builder_lane['entity_planning'] ?? []), $builder_start['prewrite_checklist'] ?? []);
		$builder_governance_notes=is_array($builder_start['governance_notes'] ?? null)
			? $builder_start['governance_notes']
			: 'none triggered';
		$builder_preview_summaries=is_array($builder_start['preview_summaries'] ?? null) ? $builder_start['preview_summaries'] : [];
		$builder_first_read=is_array($start_pack['builder_first_read'] ?? null) ? $start_pack['builder_first_read'] : [];
		$proportional_guidance=is_array($start_pack['proportional_guidance'] ?? null) ? $start_pack['proportional_guidance'] : $this->mcp_task_proportional_guidance($task);
		$enterprise_required=($proportional_guidance['enterprise_review_required'] ?? false)===true;
		$compact_app_builder_lane=$app_builder_task
			? $this->mcp_app_builder_compact_lane(
				$app_builder_lane,
				$builder_start,
				$builder_first_read,
				$builder_preview_summaries,
				$builder_write_readiness,
				$builder_governance_notes
			)
			: [];
		$compact_builder_view=$app_builder_task
			? $this->mcp_app_builder_compact_builder_view(
				$builder_start,
				$app_builder_lane,
				$builder_first_read,
				$builder_preview_summaries,
				$builder_write_readiness,
				$builder_governance_notes
			)
			: [];
		if(!$enterprise_required && is_array($builder_first_read) && ($builder_first_read['title'] ?? null)==='Builder first read'){
			return $this->mcp_agent_brief_compact_app_payload(
				$task,
				$target,
				$selected_workflow,
				($start_pack['workflow_handoff']['ready_to_run'] ?? false)===true,
				$builder_first_read,
				$builder_start,
				$builder_governance_notes,
				false
			);
		}
		$enterprise_audit=$enterprise_required ? [
			'collapsed'=>!isset($start_pack['enterprise_audit']),
			'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
			'contract_resource'=>$start_pack['enterprise_audit']['contract_resource'] ?? $start_pack['deep_context']['enterprise_summary']['contract_resource'] ?? 'dataphyre://agentic-enterprise',
			'proportional_guidance'=>$proportional_guidance,
			'attention_count'=>$start_pack['enterprise_audit']['attention_count'] ?? $start_pack['deep_context']['enterprise_summary']['attention_count'] ?? 0,
			'attention_ids'=>$start_pack['enterprise_audit']['attention_ids'] ?? $start_pack['deep_context']['enterprise_summary']['attention_ids'] ?? [],
			'runtime_quality'=>[
				'contract'=>$start_pack['enterprise_audit']['runtime_quality_gates']['contract'] ?? 'maintainer/source-checkout runtime quality gates',
				'ready'=>$start_pack['enterprise_audit']['runtime_quality_gates']['ready'] ?? null,
				'attention_ids'=>$start_pack['enterprise_audit']['runtime_quality_gates']['attention_ids'] ?? [],
			],
			'governance'=>[
				'contract'=>$start_pack['enterprise_audit']['governance_baseline']['contract'] ?? 'docs/AGENTIC_ENTERPRISE.md#governance-baseline',
				'ready'=>$start_pack['enterprise_audit']['governance_baseline']['ready'] ?? null,
				'attention_ids'=>$start_pack['enterprise_audit']['governance_baseline']['attention_ids'] ?? [],
				'claim_rule'=>$start_pack['enterprise_audit']['governance_baseline']['claim_rule'] ?? null,
			],
			'recommended_verification'=>$start_pack['enterprise_audit']['recommended_verification'] ?? [],
		] : [
			'collapsed'=>true,
			'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
			'contract_resource'=>$start_pack['deep_context']['enterprise_summary']['contract_resource'] ?? 'dataphyre://agentic-enterprise',
			'proportional_guidance'=>$proportional_guidance,
			'open_when'=>$start_pack['deep_context']['open_when'] ?? [],
			'next_read'=>'Skip for ordinary app work unless the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
		];
		$payload=[
			'inspection_view'=>$start_pack['inspection_view'] ?? [
				'title'=>'Inspection plan',
				'active'=>$app_builder_task===false,
				'task'=>$task,
				'workflow'=>$selected_workflow,
				'tool_matches'=>[],
				'resource_matches'=>[],
				'verification'=>'focused application or module checks',
				'write_policy'=>'read_only',
				'next_reads'=>['Use focused read-only discovery before editing.'],
			],
			'builder_view'=>$start_pack['builder_view'] ?? [
				'title'=>'Builder plan',
				'first_read'=>$builder_first_read,
				'next_action'=>$builder_start['next_action'] ?? [],
				'files'=>$this->mcp_app_builder_compact_file_preview(is_array($app_builder_lane['files_to_create'] ?? null) ? $app_builder_lane['files_to_create'] : []),
				'files_summary'=>$builder_preview_summaries['files'] ?? [],
				'app_path_context'=>$app_builder_task ? ($builder_start['app_path_context'] ?? ($app_builder_lane['app_path_context'] ?? [])) : [],
				'schema'=>[],
				'schema_summary'=>$builder_preview_summaries['schema'] ?? [],
				'panel_fields'=>[],
				'panel_fields_summary'=>$builder_preview_summaries['panel_fields'] ?? [],
				'filters'=>[],
				'filters_summary'=>$builder_preview_summaries['filters'] ?? [],
				'actions'=>[],
				'actions_summary'=>$builder_preview_summaries['actions'] ?? [],
				'field_metadata_summary'=>[],
				'verification'=>array_values(array_slice(array_map('strval', is_array($app_builder_lane['verification'] ?? null) ? $app_builder_lane['verification'] : []), 0, 8)),
				'verification_todo'=>$builder_start['verification_todo'] ?? [],
				'verification_evidence_summary'=>$builder_preview_summaries['verification_evidence'] ?? [],
				'next_edits'=>array_values(array_slice(array_map('strval', is_array($app_builder_lane['next_edits'] ?? null) ? $app_builder_lane['next_edits'] : []), 0, 6)),
				'governance_notes'=>$builder_governance_notes,
				'secondary_context'=>'Use first_read for the default pass; app_builder_summary, app_builder_lane, workflow summary, and collapsed enterprise link follow.',
			],
			'export_type'=>'dataphyre_mcp_agent_brief_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$task,
			'target'=>$target,
			'selected_workflow'=>$selected_workflow,
			'selected_score'=>(int)($start_pack['workflow_handoff']['selected_score'] ?? 0),
			'ready_to_run'=>($start_pack['workflow_handoff']['ready_to_run'] ?? false)===true,
			'builder_first_read'=>$builder_first_read,
			'app_builder_summary'=>$app_builder_task ? array_replace($compact_app_builder_lane, [
				'default_lane'=>'app_builder_lane',
				'payload_profiles'=>[
					'start_pack'=>[
						'builder'=>'compact ordinary app startup',
						'detail'=>'full contracts, tool audience boundaries, and discovery matches',
						'deep'=>'detail plus status board, safety boundary, enterprise audit, and full workflow handoff',
					],
					'task_pack'=>[
						'builder'=>'builder_first_read, compact builder_response, docs, and focused verification',
						'governance'=>'builder plus extension boundary, publication validation, and guardrails',
					],
				],
				'first_read_ref'=>'builder_first_read',
				'next_action'=>$this->mcp_app_builder_next_action(is_array($builder_start['next_action'] ?? null) ? $builder_start['next_action'] : []),
				'agent_workload'=>$this->mcp_app_builder_workload_budget(),
				'detail_refs'=>[
					'planning'=>'detail_pagination.pages.planning',
					'implementation'=>'detail_pagination.pages.implementation',
					'verification'=>'detail_pagination.pages.verification',
					'controls'=>'detail_pagination.pages.controls',
				],
			]) : [
				'default_lane'=>'app_builder_lane',
				'active'=>false,
				'payload_profiles'=>[
					'start_pack'=>[
						'builder'=>'compact ordinary app startup',
						'detail'=>'full contracts, tool audience boundaries, and discovery matches',
						'deep'=>'detail plus status board, safety boundary, enterprise audit, and full workflow handoff',
					],
					'task_pack'=>[
						'builder'=>'builder_first_read, compact builder_response, docs, and focused verification',
						'governance'=>'builder plus extension boundary, publication validation, and guardrails',
					],
				],
				'next_action'=>'Use inspection_view first for read-only discovery.',
				'agent_workload'=>$this->mcp_app_builder_workload_budget(),
				'files_to_create'=>[],
				'governance_collapsed'=>true,
				'entrypoint_tool'=>'dataphyre_app_builder_plan_generate',
				'scaffold_tool'=>'dataphyre_scaffold_plan_generate',
				'follow_up_tools'=>['dataphyre_task_pack_generate'],
			],
			'app_builder_next_action'=>$app_builder_task ? $this->mcp_app_builder_next_action(is_array($builder_start['next_action'] ?? null) ? $builder_start['next_action'] : []) : null,
			'app_builder_lane'=>$app_builder_task ? $compact_app_builder_lane : [
				'active'=>false,
				'lane'=>'inspection',
				'purpose'=>'Not active for read-only inspection tasks.',
				'progressive_disclosure'=>'Use inspection_view first; app-builder detail remains collapsed unless task becomes build/scaffold work.',
			],
			'agent_context'=>[
				'recommended_path'=>$start_pack['agent_context']['recommended_path'] ?? $this->agent_context_path($target),
				'source_documents'=>$start_pack['agent_context']['source_documents'] ?? [],
				'contracts_collapsed'=>$start_pack['agent_context']['contracts_collapsed'] ?? false,
				'open_detail_with'=>$start_pack['agent_context']['open_detail_with'] ?? 'include_detail_context=true',
				'application_agent_operating_contract'=>$start_pack['agent_context']['application_agent_operating_contract'] ?? ['collapsed'=>true, 'owner'=>'consuming_application', 'open_with'=>'include_detail_context=true'],
				'ordinary_app_work'=>$start_pack['agent_context']['ordinary_app_work'] ?? ['collapsed'=>true, 'owner'=>'consuming_application', 'verification'=>'focused application or module checks'],
			],
			'safety'=>[
				'default_safety'=>$start_pack['safety_boundary']['default_safety'] ?? 'read_only',
				'unsafe_enabled'=>$start_pack['safety_boundary']['unsafe_enabled'] ?? false,
				'redaction_policy'=>$start_pack['safety_boundary']['redaction_policy'] ?? [],
			],
			'application_agent_operating_contract'=>$start_pack['application_agent_operating_contract'] ?? $this->mcp_application_agent_operating_contract('agent_brief'),
			'ordinary_app_work'=>$start_pack['ordinary_app_work'] ?? $this->mcp_ordinary_app_work_contract('agent_brief'),
			'tool_audience_boundaries'=>$start_pack['tool_audience_boundaries'] ?? $this->mcp_current_tool_audience_boundaries(),
			'workflow_summary'=>[
				'title'=>(string)($workflow_entry['title'] ?? $selected_workflow),
				'goal'=>(string)($workflow_entry['goal'] ?? ''),
				'step_count'=>(int)($workflow_entry['step_count'] ?? 0),
				'primary_tools'=>array_values(array_slice(array_map('strval', is_array($workflow_entry['primary_tools'] ?? null) ? $workflow_entry['primary_tools'] : []), 0, 8)),
				'full_handoff_computed'=>false,
				'fetch_tools'=>[
					'workflow_handoff_pack'=>'dataphyre_mcp_workflow_handoff_pack_export',
					'workflow_session'=>'dataphyre_mcp_workflow_session_export',
					'transcript_schema'=>'dataphyre_mcp_workflow_transcript_schema_export',
				],
				'next_read'=>'For ordinary app work, keep the compact brief; fetch the full workflow handoff only when ready to run workflow messages.',
			],
			'enterprise_audit'=>$enterprise_audit,
			'next_actions'=>array_values(array_filter([
				$app_builder_task ? 'Use builder_first_read first; open only the needed detail_pagination page or dataphyre_app_builder_plan_generate detail/full response when blockers, continuation calls, companion surfaces, implementation, or verification need expansion.' : 'Use inspection_view first for read-only discovery; do not create files unless the task changes into build/scaffold work.',
				'Use dataphyre_mcp_agent_brief_export for compact cold starts or handoffs; use dataphyre_mcp_task_start_pack_export payload_profile=builder only when broader bounded workflow context is needed. Open payload_profile=detail for full contracts/discovery and payload_profile=deep only for elevated governance/status context.',
				'Use dataphyre_task_pack_generate payload_profile=builder only when focused module docs or a ready prompt are needed; use payload_profile=governance only when inline extension boundary and publication guardrails must be inline.',
				'Review safety default and redaction policy before sharing diagnostics, configs, or paths.',
				$enterprise_required ? 'Review enterprise audit, runtime quality gate, and governance baseline attention IDs before the elevated-risk claim or framework work.' : 'Keep heavier enterprise audit review optional unless explicitly requested for an escalation decision: '.$this->mcp_escalation_trigger_summary().'.',
				$enterprise_required ? 'Use dataphyre_mcp_workflow_handoff_pack_export for full pre-run workflow sessions when orchestration is needed.' : null,
				$enterprise_required ? 'Capture responses with dataphyre_mcp_workflow_transcript_schema_export, then audit and checkpoint them.' : null,
				'Use focused application or module verification for app behavior; use publication validation only for MCP/release-surface claims.',
			])),
			'discovery'=>[
				'tool_matches'=>array_values(array_slice($start_pack['tool_matches']['matches'] ?? [], 0, $limit)),
				'resource_matches'=>array_values(array_slice($start_pack['resource_matches']['matches'] ?? [], 0, $limit)),
			],
			'lifecycle_phases'=>array_values(array_map(static fn(array $phase): array => [
				'phase'=>(string)($phase['phase'] ?? ''),
				'tool'=>(string)($phase['tool'] ?? ''),
				'execution'=>(string)($phase['execution'] ?? ''),
			], is_array($lifecycle['lifecycle'] ?? null) ? $lifecycle['lifecycle'] : [])),
			'links'=>[
				'start_pack'=>'dataphyre_mcp_task_start_pack_export',
				'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
				'lifecycle'=>'dataphyre_mcp_workflow_lifecycle_export',
				'handoff'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'checkpoint'=>'dataphyre_mcp_workflow_checkpoint_export',
			],
			'usage_notes'=>[
				'This brief is intentionally compact and omits raw stdio frames and full instruction content.',
				'Use the linked tools when a client needs complete schemas, sessions, or transcript details.',
				'The brief does not execute tools, write client files, or persist workflow state.',
			],
		];
		if(!$enterprise_required){
			unset($payload['application_agent_operating_contract'], $payload['ordinary_app_work'], $payload['tool_audience_boundaries'], $payload['enterprise_audit']);
			unset($payload['agent_context']['source_documents'], $payload['agent_context']['application_agent_operating_contract'], $payload['agent_context']['ordinary_app_work']);
			$payload['governance_notes']=is_array($builder_governance_notes) ? $builder_governance_notes+[
				'default_lane'=>$app_builder_task ? 'builder' : 'inspection',
				'open_only_for'=>$start_pack['governance_notes']['open_only_for'] ?? $this->mcp_escalation_triggers(),
			] : [
				'status'=>'none triggered',
				'default_lane'=>$app_builder_task ? 'builder' : 'inspection',
				'open_only_for'=>$start_pack['governance_notes']['open_only_for'] ?? $this->mcp_escalation_triggers(),
			];
			$payload['context_links']=[
				'compact_app_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=compact',
				'broader_builder_context'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				'escalation_readiness_details'=>'dataphyre_mcp_readiness_report',
			];
		}
		return $payload;
	}

	/**
	 * Builds the first-page-only app-builder brief without assembling detail lanes.
	 *
	 * @param string $task Task text.
	 * @param string $target Agent target.
	 * @param string $selected_workflow Recommended workflow.
	 * @param bool $ready_to_run Whether the workflow can be run directly.
	 * @param array<string,mixed> $builder_first_read Full builder first-read payload.
	 * @param array<string,mixed> $builder_start Builder start summary.
	 * @param array<string,mixed>|string $builder_governance_notes Compact policy attention.
	 * @param bool $enterprise_required Whether elevated review was triggered.
	 * @return array<string,mixed> Compact agent brief payload.
	 */
	private function mcp_agent_brief_compact_app_payload(string $task, string $target, string $selected_workflow, bool $ready_to_run, array $builder_first_read, array $builder_start, array|string $builder_governance_notes, bool $enterprise_required): array {
		$agent_first_read=$this->mcp_agent_brief_compact_first_read($builder_first_read);
		if(is_array($builder_start['app_path_context'] ?? null)){
			$agent_first_read['app_path_context']=$this->mcp_app_builder_compact_app_path_context($builder_start['app_path_context']);
		}
		if(is_array($agent_first_read['next_action'] ?? null)){
			$agent_first_read['next_action']=$this->mcp_agent_brief_compact_next_action($agent_first_read['next_action'], true);
		}
		$brief_next_action=$this->mcp_agent_brief_compact_next_action(is_array($agent_first_read['next_action'] ?? null) ? $agent_first_read['next_action'] : (is_array($builder_start['next_action'] ?? null) ? $builder_start['next_action'] : []), false);
		$policy_attention=null;
		if(is_array($builder_governance_notes) && ($builder_governance_notes['status'] ?? null)==='app_owned_policy_attention'){
			$policy_attention=[
				'status'=>$builder_governance_notes['status'] ?? null,
				'mode'=>$builder_governance_notes['mode'] ?? null,
				'categories'=>array_values(array_slice(array_map('strval', is_array($builder_governance_notes['categories'] ?? null) ? $builder_governance_notes['categories'] : []), 0, 8)),
				'details'=>'Open detail_pagination.pages.controls with dataphyre_app_builder_plan_generate.',
			];
		}
		return array_filter([
			'export_type'=>'dataphyre_mcp_agent_brief_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$this->shorten_text($task, 30),
			'target'=>$target,
			'selected_workflow'=>$selected_workflow,
			'ready_to_run'=>$ready_to_run,
			'builder_first_read'=>$agent_first_read,
			'app_builder_next_action'=>$brief_next_action,
			'next_actions'=>[
				'Read builder_first_read only; omitted lanes stay paginated.',
				'Open dataphyre_app_builder_plan_generate payload_profile=compact detail_page=<page> for one needed detail page.',
			],
			'context_links'=>[
				'compact_app_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=compact',
				'planning_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'verification_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				'controls_detail_page'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls',
				'broader_builder_context'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
			],
			'policy_attention'=>$policy_attention,
			'elevated_review'=>$enterprise_required ? [
				'required'=>true,
				'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
				'details'=>'Open only after reading builder_first_read and only for the elevated security/governance-sensitive decision.',
			] : null,
		], static fn(mixed $value): bool => $value!==null);
	}

	/**
	 * Keeps agent-brief payload-budget guidance compact and page-oriented.
	 *
	 * @return array<string,mixed> Compact payload budget metadata.
	 */
	private function mcp_agent_brief_payload_budget(): array {
		$budget=$this->mcp_app_builder_payload_budget('agent_brief');
		$policy=is_array($budget['escalation_policy'] ?? null) ? $budget['escalation_policy'] : [];
		$use_extension_points=is_array($policy['use_extension_points_first'] ?? null) ? $policy['use_extension_points_first'] : [];
		$do_not_escalate_for=is_array($policy['do_not_escalate_for'] ?? null) ? $policy['do_not_escalate_for'] : [];
		$escalate_only_for=is_array($policy['escalate_only_for'] ?? null) ? $policy['escalate_only_for'] : [];
		return [
			'surface'=>$budget['surface'] ?? 'agent_brief',
			'max_response_chars'=>$budget['max_response_chars'] ?? 18000,
			'max_first_read_chars'=>$budget['max_first_read_chars'] ?? 8000,
			'default_payload'=>$budget['default_payload'] ?? 'first_page_only',
			'detail_strategy'=>$budget['detail_strategy'] ?? 'page_detail_with_detail_pagination',
			'overflow_action'=>$budget['overflow_action'] ?? 'open_dataphyre_app_builder_plan_generate_for_the_needed_page_only',
			'omitted_inline_detail'=>$budget['omitted_inline_detail'] ?? [
				'builder_view',
				'builder_plan',
				'app_builder_lane',
				'app_builder_summary',
				'raw handoff_fields',
				'code_skeleton_bodies',
			],
			'escalation_policy'=>[
				'default_owner'=>$policy['default_owner'] ?? 'consuming_application',
				'default_verification'=>$policy['default_verification'] ?? 'focused_application_or_module_checks',
				'use_extension_points_first_count'=>count($use_extension_points),
				'do_not_escalate_for_count'=>count($do_not_escalate_for),
				'escalate_only_for_count'=>count($escalate_only_for),
				'full_policy_ref'=>'dataphyre_app_builder_plan_generate builder_response.payload_budget.escalation_policy',
			],
		];
	}

	/**
	 * Removes wall-of-fields detail from the public agent brief first read.
	 *
	 * @param array<string,mixed> $first_read Full first-read payload.
	 * @return array<string,mixed> Compact public first-read payload.
	 */
	private function mcp_agent_brief_compact_first_read(array $first_read): array {
		foreach([
			'files_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'schema_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'panel_fields_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'filters_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'actions_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
			'verification_evidence_summary'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
		] as $summary_field=>$open_with){
			if(is_array($first_read[$summary_field] ?? null)){
				$first_read[$summary_field]['open_with']=$open_with;
			}
		}
		if(is_array($first_read['naming_contract'] ?? null)){
			$naming=$first_read['naming_contract'];
			$mappings=is_array($naming['mappings'] ?? null) ? $naming['mappings'] : [];
			$first_read['naming_contract']=[
				'owner'=>$naming['owner'] ?? 'consuming_application',
				'class_names'=>$naming['class_names'] ?? null,
				'paths_and_tables'=>$naming['paths_and_tables'] ?? null,
				'mapping_count'=>count($mappings),
				'mappings'=>array_values(array_map(static fn(array $mapping): array => array_filter([
					'entity'=>$mapping['entity'] ?? null,
					'class_base'=>$mapping['class_base'] ?? null,
					'table'=>$mapping['table'] ?? null,
					'panel_resource'=>$mapping['panel_resource'] ?? null,
					'panel_manifest'=>$mapping['panel_manifest'] ?? null,
				], static fn(mixed $value): bool => $value!==null && $value!==''), array_slice($mappings, 0, 4))),
				'full_contract'=>'dataphyre_app_builder_plan_generate builder_response.naming_contract',
			];
		}
		if(is_array($first_read['write_readiness']['handoff_fields'] ?? null)){
			$first_read['write_readiness']['handoff_pages']=[
				'detail_pagination.pages.planning',
				'detail_pagination.pages.implementation',
				'detail_pagination.pages.verification',
				'detail_pagination.pages.controls',
			];
			$first_read['write_readiness']['handoff_fields_count']=count($first_read['write_readiness']['handoff_fields']);
			unset($first_read['write_readiness']['handoff_fields']);
		}
		if(is_array($first_read['write_readiness'] ?? null)){
			unset($first_read['write_readiness']['not_required']);
		}
		if(is_array($first_read['scaffold_completion_summary'] ?? null)){
			$summary=$first_read['scaffold_completion_summary'];
			$next=is_array($summary['next_continuation'] ?? null) ? $summary['next_continuation'] : [];
			$queue=is_array($summary['continuation_queue'] ?? null) ? $summary['continuation_queue'] : [];
			$first_read['scaffold_completion_summary']=[
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
					'argument_source'=>$next['argument_source'] ?? null,
					'repeat_until'=>$next['repeat_until'] ?? null,
				], static fn(mixed $value): bool => $value!==null && $value!==[]),
				'continuation_count'=>count($queue),
				'full_queue'=>'dataphyre_app_builder_plan_generate builder_response.entity_planning.continuation_calls',
			];
		}
		if(is_array($first_read['verification_handoff'] ?? null)){
			$handoff=$first_read['verification_handoff'];
			$first_read['verification_handoff']=[
				'owner'=>$handoff['owner'] ?? 'consuming_application',
				'status'=>$handoff['status'] ?? null,
				'tools'=>array_values(array_slice(array_map('strval', is_array($handoff['tools'] ?? null) ? $handoff['tools'] : []), 0, 6)),
				'copy_safe_fields_count'=>count(is_array($handoff['copy_safe_fields'] ?? null) ? $handoff['copy_safe_fields'] : []),
				'done_when'=>$handoff['done_when'] ?? null,
				'full_handoff'=>'dataphyre_app_builder_plan_generate builder_response.verification_handoff',
			];
		}
		if(is_array($first_read['app_path_context'] ?? null)){
			$first_read['app_path_context']=$this->mcp_app_builder_compact_app_path_context($first_read['app_path_context']);
		}
		if(is_array($first_read['open_details'] ?? null)){
			$first_read['open_details']=[
				'files'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'schema'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'planning'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'verification'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
				'full_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				'governance'=>'dataphyre_mcp_task_start_pack_export payload_profile=deep only for elevated tasks',
			];
		}
		return $first_read;
	}

	/**
	 * Keeps only path hints needed for the first app-builder read.
	 *
	 * @param array<string,mixed> $context Full app path context.
	 * @return array<string,mixed> Compact app path context.
	 */
	private function mcp_agent_brief_compact_app_path_context(array $context): array {
		return $this->mcp_app_builder_compact_app_path_context($context);
	}

	/**
	 * Keeps agent-brief next-action guidance small and page-oriented.
	 *
	 * @param array<string,mixed> $next_action Full next-action payload.
	 * @return array<string,mixed> Compact next-action payload.
	 */
	private function mcp_agent_brief_compact_next_action(array $next_action, bool $include_write_start_packet=false): array {
		$resume_cursor=is_array($next_action['resume_cursor'] ?? null) ? $this->mcp_agent_brief_compact_resume_cursor($next_action['resume_cursor']) : [];
		$payload=[
			'status'=>$next_action['status'] ?? null,
			'action'=>$next_action['action'] ?? null,
			'next_tool'=>$next_action['next_tool'] ?? 'dataphyre_app_builder_plan_generate',
			'argument_source'=>$next_action['argument_source'] ?? null,
			'resume_cursor'=>$include_write_start_packet && $resume_cursor!==[] ? $resume_cursor : null,
			'resume_cursor_ref'=>!$include_write_start_packet && $resume_cursor!==[] ? 'builder_first_read.next_action.resume_cursor' : null,
			'resume_cursor_phase'=>!$include_write_start_packet ? ($resume_cursor['phase'] ?? null) : null,
			'copy_forward_count'=>!$include_write_start_packet && isset($resume_cursor['copy_forward_count']) ? (int)$resume_cursor['copy_forward_count'] : null,
			'handoff_pages'=>$include_write_start_packet ? [
				'builder_first_read',
				'detail_pagination.pages.planning',
				'detail_pagination.pages.implementation',
				'detail_pagination.pages.verification',
				'detail_pagination.pages.controls',
			] : null,
			'handoff_pages_count'=>!$include_write_start_packet ? 5 : null,
			'handoff_pages_ref'=>!$include_write_start_packet ? 'builder_first_read.next_action.handoff_pages' : null,
			'full_next_action'=>'dataphyre_app_builder_plan_generate',
		];
		if($include_write_start_packet && is_array($next_action['write_start_packet'] ?? null)){
			$packet=$next_action['write_start_packet'];
			if(($packet['can_write_now'] ?? false)===true){
				$payload['write_start_packet']=$this->mcp_agent_brief_compact_write_start_packet($packet);
			}else{
				$payload['write_start_packet_ref']='builder_response.first_read.next_action.write_start_packet';
				$payload['write_start_packet_status']=$packet['status'] ?? ($next_action['status'] ?? null);
				$payload['write_start_packet_inline']='omitted_until_ready_for_app_owned_writes';
			}
		}
		return array_filter($payload, static fn(mixed $value): bool => $value!==null);
	}

	/**
	 * Keeps the resume cursor as an actionable pointer instead of a handoff payload.
	 *
	 * @param array<string,mixed> $cursor Full app-builder resume cursor.
	 * @return array<string,mixed> Compact cursor for agent briefs.
	 */
	private function mcp_agent_brief_compact_resume_cursor(array $cursor): array {
		$compact=[];
		foreach(['phase', 'read', 'next_tool', 'argument_source', 'blocker_id', 'action_source', 'then_read', 'first_batch'] as $field){
			if(($cursor[$field] ?? null)!==null && $cursor[$field]!==''){
				$compact[$field]=$cursor[$field];
			}
		}
		if(is_array($cursor['copy_forward'] ?? null)){
			$compact['copy_forward_count']=count($cursor['copy_forward']);
			$compact['copy_forward_ref']='dataphyre_app_builder_plan_generate builder_response.first_read.next_action.resume_cursor.copy_forward';
		}elseif(isset($cursor['copy_forward_count'])){
			$compact['copy_forward_count']=(int)$cursor['copy_forward_count'];
			$compact['copy_forward_ref']=(string)($cursor['copy_forward_ref'] ?? 'dataphyre_app_builder_plan_generate builder_response.first_read.next_action.resume_cursor.copy_forward');
		}
		foreach(['write_start_packet', 'write_source', 'open_full_skeletons', 'after_write'] as $field){
			if(($cursor[$field] ?? null)!==null && $cursor[$field]!==''){
				$compact[$field]=$cursor[$field];
			}
		}
		return $compact;
	}

	/**
	 * Keeps the app-write go/no-go packet actionable without inlining full handoff detail.
	 *
	 * @param array<string,mixed> $packet Full app-builder write-start packet.
	 * @return array<string,mixed> Compact agent-brief packet.
	 */
	private function mcp_agent_brief_compact_write_start_packet(array $packet): array {
		$compact=[
			'can_write_now'=>($packet['can_write_now'] ?? false)===true,
			'status'=>$packet['status'] ?? null,
			'first_batch'=>is_array($packet['first_batch'] ?? null) ? $packet['first_batch'] : [],
			'write_queue'=>$packet['write_queue'] ?? 'builder_response.implementation_recipe.items',
			'evidence_to_collect'=>$packet['evidence_to_collect'] ?? 'builder_response.verification_evidence',
		];
		if(is_array($packet['first_probe'] ?? null)){
			$probe=$packet['first_probe'];
			$compact['first_probe']=[
				'id'=>(string)($probe['id'] ?? ''),
				'inspect_globs'=>array_values(array_slice(array_map('strval', is_array($probe['inspect_globs'] ?? null) ? $probe['inspect_globs'] : []), 0, 3)),
				'signals'=>array_values(array_slice(array_map('strval', is_array($probe['signals'] ?? null) ? $probe['signals'] : []), 0, 4)),
				'capture_fields'=>array_values(array_slice(array_map('strval', is_array($probe['capture_fields'] ?? null) ? $probe['capture_fields'] : []), 0, 4)),
				'apply_to'=>array_values(array_slice(array_map('strval', is_array($probe['apply_to'] ?? null) ? $probe['apply_to'] : []), 0, 4)),
				'full_probe'=>$probe['full_probe'] ?? 'builder_response.local_convention_probe.items[0]',
			];
		}
		return $compact;
	}

}
