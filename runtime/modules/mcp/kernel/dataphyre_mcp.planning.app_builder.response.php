<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP app-builder compact response and input contract helpers.
 */
trait dataphyre_mcp_planning_app_builder_response_surfaces {

	/**
	 * Removes skeleton bodies for first-read app-builder payloads.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @return array<string,mixed> Builder plan with write sequencing but without code bodies.
	 */
	private function app_builder_compact_builder_plan_payload(array $builder_plan): array {
		$summary=is_array($builder_plan['code_skeleton_summary'] ?? null) ? $builder_plan['code_skeleton_summary'] : [];
		$builder_plan['code_skeletons_available']=[
			'included'=>false,
			'summary'=>$summary,
			'open_with'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full when ready to adapt app-owned code skeletons.',
		];
		unset($builder_plan['code_skeletons']);
		if(is_array($builder_plan['data_model'] ?? null)){
			foreach($builder_plan['data_model'] as $index=>$model){
				if(!is_array($model)){
					continue;
				}
				if(array_key_exists('code_skeletons', $model)){
					$model['code_skeletons_available']=[
						'included'=>false,
						'paths'=>array_values(array_map(static fn(array $skeleton): string => (string)($skeleton['path'] ?? ''), array_filter(is_array($model['code_skeletons'] ?? null) ? $model['code_skeletons'] : [], 'is_array'))),
						'open_with'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full for TableSchema, Repository, and Record skeleton bodies.',
					];
					unset($model['code_skeletons']);
					$builder_plan['data_model'][$index]=$model;
				}
			}
		}
		return $builder_plan;
	}

	/**
	 * Builds the compact first-read builder response shared by app-builder surfaces.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @param array<string,string> $optional_context Optional context links.
	 * @return array<string,mixed> Small copy-friendly builder summary.
	 */
	private function app_builder_compact_response(array $builder_plan, array $optional_context=[]): array {
		return [
			'title'=>'Builder plan',
			'first_read'=>$this->mcp_app_builder_first_read($builder_plan, [], [
				'planning'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=planning',
				'implementation'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=implementation',
				'verification'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=verification',
				'controls'=>'dataphyre_app_builder_plan_generate payload_profile=compact detail_page=controls only when signaled by write_readiness or policy_decision_register',
				'full_plan'=>'dataphyre_app_builder_plan_generate payload_profile=full',
			]),
			'payload_budget'=>$this->mcp_app_builder_payload_budget('app_builder_plan_compact'),
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
			'endpoint_policy_metadata'=>$builder_plan['endpoint_policy_metadata'] ?? [],
			'data_sensitivity_summary'=>$builder_plan['data_sensitivity_summary'] ?? [],
			'relationship_contract_summary'=>$builder_plan['relationship_contract_summary'] ?? [],
			'relationship_adapter_handoff'=>$builder_plan['relationship_adapter_handoff'] ?? [],
			'field_metadata_summary'=>$builder_plan['field_metadata_summary'] ?? [],
			'data_model_handoff'=>$this->app_builder_compact_data_model_handoff($builder_plan['data_model'] ?? []),
			'data_integrity_summary'=>$builder_plan['data_integrity_summary'] ?? [],
			'lifecycle_policy_summary'=>$builder_plan['lifecycle_policy_summary'] ?? [],
			'lifecycle_state_handoff'=>$builder_plan['lifecycle_state_handoff'] ?? [],
			'audit_retention_summary'=>$this->app_builder_compact_optional_summary($builder_plan['audit_retention_summary'] ?? [], 'has_audit_retention_fields', 'audit_retention_summary'),
			'audit_retention_handoff'=>$builder_plan['audit_retention_handoff'] ?? [],
			'access_control_summary'=>$this->app_builder_compact_optional_summary($builder_plan['access_control_summary'] ?? [], 'has_access_control_fields', 'access_control_summary'),
			'access_control_handoff'=>$builder_plan['access_control_handoff'] ?? [],
			'operational_reliability_summary'=>$this->app_builder_compact_optional_summary($builder_plan['operational_reliability_summary'] ?? [], 'has_operational_reliability_signals', 'operational_reliability_summary'),
			'operational_reliability_handoff'=>$builder_plan['operational_reliability_handoff'] ?? [],
			'support_observability_summary'=>$this->app_builder_compact_optional_summary($builder_plan['support_observability_summary'] ?? [], 'has_support_observability_signals', 'support_observability_summary'),
			'support_observability_handoff'=>$builder_plan['support_observability_handoff'] ?? [],
			'change_management_summary'=>$this->app_builder_compact_optional_summary($builder_plan['change_management_summary'] ?? [], 'has_change_management_signals', 'change_management_summary'),
			'change_management_handoff'=>$builder_plan['change_management_handoff'] ?? [],
			'integration_boundary_summary'=>$this->app_builder_compact_optional_summary($builder_plan['integration_boundary_summary'] ?? [], 'has_integration_boundary_signals', 'integration_boundary_summary'),
			'integration_boundary_handoff'=>$builder_plan['integration_boundary_handoff'] ?? [],
			'tenant_identity_handoff'=>$builder_plan['tenant_identity_handoff'] ?? [],
			'business_policy_summary'=>$this->app_builder_compact_optional_summary($builder_plan['business_policy_summary'] ?? [], 'has_business_policy_signals', 'business_policy_summary'),
			'process_policy_summary'=>$this->app_builder_compact_optional_summary($builder_plan['process_policy_summary'] ?? [], 'has_process_policy_signals', 'process_policy_summary'),
			'domain_workflow_handoff'=>$builder_plan['domain_workflow_handoff'] ?? [],
			'reporting_analytics_summary'=>$this->app_builder_compact_optional_summary($builder_plan['reporting_analytics_summary'] ?? [], 'has_reporting_analytics_signals', 'reporting_analytics_summary'),
			'reporting_analytics_handoff'=>$builder_plan['reporting_analytics_handoff'] ?? [],
			'notification_communication_summary'=>$this->app_builder_compact_optional_summary($builder_plan['notification_communication_summary'] ?? [], 'has_notification_communication_signals', 'notification_communication_summary'),
			'notification_communication_handoff'=>$builder_plan['notification_communication_handoff'] ?? [],
			'panel_fields'=>$builder_plan['panel_fields'] ?? [],
			'filters'=>$builder_plan['filters'] ?? [],
			'actions'=>$builder_plan['actions'] ?? [],
			'implementation_sequence'=>$builder_plan['implementation_sequence'] ?? [],
			'verification'=>$builder_plan['verification'] ?? [],
			'verification_evidence'=>$builder_plan['verification_plan']['evidence_to_collect'] ?? [],
			'verification_todo'=>$builder_plan['verification_plan']['verification_todo'] ?? [],
			'verification_handoff'=>$builder_plan['verification_plan']['handoff'] ?? [],
			'verification_execution_plan'=>$builder_plan['verification_execution_plan'] ?? [],
			'verification_fixture_handoff'=>$builder_plan['verification_fixture_handoff'] ?? [],
			'acceptance_criteria'=>$builder_plan['acceptance_criteria'] ?? [],
			'acceptance_review_plan'=>$builder_plan['acceptance_review_plan'] ?? [],
			'diagnostic_handoff_hint'=>$builder_plan['diagnostic_handoff_hint'] ?? $this->app_builder_diagnostic_handoff_hint($builder_plan['verification_plan'] ?? []),
			'verification_recovery_plan'=>$builder_plan['verification_recovery_plan'] ?? [],
			'recovery_hints'=>$builder_plan['verification_plan']['recovery_hints'] ?? [],
			'app_contract_summary'=>$builder_plan['app_contract_summary'] ?? [],
			'policy_decision_register'=>$builder_plan['policy_decision_register'] ?? [],
			'next_edits'=>$builder_plan['next_edits'] ?? [],
			'code_skeleton_summary'=>$builder_plan['code_skeleton_summary'] ?? [],
			'local_convention_probe'=>$builder_plan['local_convention_probe'] ?? [],
			'write_plan_summary'=>$builder_plan['write_plan_summary'] ?? [],
			'implementation_matrix'=>$builder_plan['implementation_matrix'] ?? [],
			'implementation_recipe'=>$builder_plan['implementation_recipe'] ?? [],
			'write_handoff'=>$builder_plan['write_handoff'] ?? [],
			'prewrite_checklist'=>$builder_plan['prewrite_checklist'] ?? [],
			'write_readiness'=>$builder_plan['write_readiness'] ?? [],
			'extension_boundary_summary'=>$builder_plan['extension_boundary_summary'] ?? $this->app_builder_extension_boundary_summary(),
			'governance_notes'=>$this->app_builder_governance_notes($builder_plan, [], true),
			'optional_context'=>$optional_context,
		];
	}

	/**
	 * Builds a first-read data-model handoff without including skeleton bodies.
	 *
	 * @param mixed $data_model Data-model artifacts from the full builder plan.
	 * @return array<string,mixed> Compact TableSchema/repository handoff.
	 */
	private function app_builder_compact_data_model_handoff(mixed $data_model): array {
		if(!is_array($data_model) || $data_model===[]){
			return [
				'owner'=>'consuming_application',
				'has_data_model_artifacts'=>false,
				'items'=>[],
				'full_source'=>'builder_plan.data_model',
			];
		}
		$items=[];
		foreach($data_model as $model){
			if(!is_array($model)){
				continue;
			}
			$item=[
				'entity'=>(string)($model['entity'] ?? ''),
				'table'=>(string)($model['table'] ?? ''),
				'primary_key'=>(string)($model['primary_key'] ?? 'id'),
				'artifact_paths'=>array_values(array_map('strval', is_array($model['artifact_paths'] ?? null) ? $model['artifact_paths'] : [])),
			];
			foreach(['columns', 'casts', 'relationships', 'schema_field_metadata'] as $key){
				if(is_array($model[$key] ?? null) && $model[$key]!==[]){
					$item[$key]=$model[$key];
				}
			}
			$items[]=$item;
		}
		return [
			'owner'=>'consuming_application',
			'has_data_model_artifacts'=>$items!==[],
			'artifact_count'=>count($items),
			'items'=>$items,
			'full_source'=>'builder_plan.data_model',
			'policy'=>'Use this first-read handoff for app-owned TableSchema, repository, and record adaptation; open full skeletons only when ready to write code bodies.',
		];
	}

	/**
	 * Keeps active enterprise summaries detailed while shrinking inactive summaries.
	 *
	 * @param mixed $summary Summary payload.
	 * @param string $signal_key Boolean signal key.
	 * @param string $summary_name Summary field name.
	 * @return array<string,mixed> Compact summary payload.
	 */
	private function app_builder_compact_optional_summary(mixed $summary, string $signal_key, string $summary_name): array {
		if(!is_array($summary) || $summary===[]){
			return [
				'owner'=>'consuming_application',
				$signal_key=>false,
				'compact'=>true,
				'status'=>'not_triggered',
				'open_with'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full if this concern becomes relevant.',
			];
		}
		if(($summary[$signal_key] ?? null)===true){
			return $summary;
		}
		return [
			'owner'=>(string)($summary['owner'] ?? 'consuming_application'),
			$signal_key=>false,
			'compact'=>true,
			'status'=>'not_triggered',
			'policy'=>(string)($summary['policy'] ?? 'No app-owned policy signals were inferred.'),
			'open_with'=>'Use payload_profile=full only if '.$summary_name.' becomes relevant to the app task.',
		];
	}

	/**
	 * Builds proportional governance notes for ordinary app-builder work.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @param array<string,mixed> $lane Optional app-builder lane metadata.
	 * @param bool $compact_string_none Preserve compact legacy string when no attention is needed.
	 * @return array<string,mixed>|string Lightweight governance status.
	 */
	private function app_builder_governance_notes(array $builder_plan, array $lane=[], bool $compact_string_none=false): array|string {
		$sensitivity=is_array($builder_plan['data_sensitivity_summary'] ?? null) ? $builder_plan['data_sensitivity_summary'] : [];
		$policy_register=is_array($builder_plan['policy_decision_register'] ?? null) ? $builder_plan['policy_decision_register'] : [];
		$has_sensitive=($sensitivity['has_sensitive_signals'] ?? false)===true;
		$required_count=(int)($policy_register['required_count'] ?? 0);
		$open_when=is_array($lane['governance_lane']['open_when'] ?? null) ? $lane['governance_lane']['open_when'] : $this->mcp_escalation_triggers();
		if($has_sensitive){
			return [
				'triggered'=>true,
				'status'=>'app_owned_policy_attention',
				'mode'=>'lightweight_app_owned_policy',
				'notes'=>[
					'Resolve data_sensitivity_summary and policy_decision_register as app-owned prewrite decisions.',
					'Keep enterprise/governance detail collapsed unless the task matches escalation triggers.',
				],
				'categories'=>array_values(array_map('strval', is_array($sensitivity['categories'] ?? null) ? $sensitivity['categories'] : [])),
				'policy_required_count'=>$required_count,
				'open_governance_detail_only_for'=>$open_when,
				'not_required'=>[
					'framework/release escalation for ordinary app-owned policy decisions',
				],
			];
		}
		if($compact_string_none){
			return 'none triggered';
		}
		return [
			'triggered'=>false,
			'status'=>'none_triggered',
			'notes'=>['none triggered for ordinary application builder work'],
			'open_when'=>$open_when,
		];
	}

	/**
	 * Places the immediate app-builder decision at the top of compact payloads.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @return array<string,mixed> Copy-friendly next action.
	 */
	private function app_builder_compact_next_action(array $builder_plan): array {
		$write_readiness=is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [];
		$status=(string)($write_readiness['status'] ?? 'inspect_builder_plan');
		$action=(string)($write_readiness['next_action'] ?? 'Review builder_response, then continue with app-owned files or focused verification.');
		$next_tool=null;
		if($status==='continue_entity_chunks'){
			$next_tool='dataphyre_app_builder_plan_generate';
		}
		$handoff_fields=array_values(array_map('strval', is_array($write_readiness['handoff_fields'] ?? null) ? $write_readiness['handoff_fields'] : ['write_readiness', 'prewrite_checklist', 'verification_handoff']));
		foreach(['verification_handoff.post_write_handoff_template', 'acceptance_review_plan', 'acceptance_review_plan.post_write_handoff_template'] as $required_field){
			if(!in_array($required_field, $handoff_fields, true)){
				$handoff_fields[]=$required_field;
			}
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$status,
			'action'=>$action,
			'next_tool'=>$next_tool,
			'argument_source'=>$status==='continue_entity_chunks' ? 'entity_planning.continuation_calls[0].arguments' : null,
			'resume_cursor'=>$this->app_builder_compact_resume_cursor($builder_plan, $status),
			'write_start_packet'=>$this->app_builder_compact_write_start_packet($builder_plan, $status),
			'handoff_fields'=>$handoff_fields,
			'not_required'=>array_values(array_map('strval', is_array($write_readiness['not_required'] ?? null) ? $write_readiness['not_required'] : [])),
		];
	}

	/**
	 * Packs the app-owned write go/no-go, first batch, and evidence pointers.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @param string $status Current write-readiness status.
	 * @return array<string,mixed> Small copy-forward write packet.
	 */
	private function app_builder_compact_write_start_packet(array $builder_plan, string $status): array {
		$write_readiness=is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [];
		$write_handoff=is_array($builder_plan['write_handoff'] ?? null) ? $builder_plan['write_handoff'] : [];
		$first_batch=is_array($write_handoff['first_batch'] ?? null) ? $write_handoff['first_batch'] : [];
		$compact_first_batch=[];
		foreach(['concern', 'source'] as $field){
			if(($first_batch[$field] ?? '')!==''){
				$compact_first_batch[$field]=(string)$first_batch[$field];
			}
		}
		if(is_array($first_batch['paths'] ?? null)){
			$compact_first_batch['paths']=array_values(array_slice(array_map('strval', $first_batch['paths']), 0, 4));
		}
		if(is_array($first_batch['tools'] ?? null)){
			$compact_first_batch['tools']=array_values(array_slice(array_map('strval', $first_batch['tools']), 0, 4));
		}
		if(is_array($first_batch['probe'] ?? null)){
			$probe_ids=[];
			foreach($first_batch['probe'] as $probe){
				if(is_array($probe) && ($probe['id'] ?? '')!==''){
					$probe_ids[]=(string)$probe['id'];
				}
			}
			$compact_first_batch['probe_ids']=array_values(array_slice($probe_ids, 0, 4));
		}
		$first_probe=null;
		if(is_array($first_batch['probe'][0] ?? null)){
			$probe=$first_batch['probe'][0];
			$first_probe=[
				'id'=>(string)($probe['id'] ?? ''),
				'inspect_globs'=>array_values(array_slice(array_map('strval', is_array($probe['inspect_globs'] ?? null) ? $probe['inspect_globs'] : []), 0, 3)),
				'signals'=>array_values(array_slice(array_map('strval', is_array($probe['signals'] ?? null) ? $probe['signals'] : []), 0, 4)),
				'capture_fields'=>array_values(array_slice(array_map('strval', is_array($probe['capture_fields'] ?? null) ? $probe['capture_fields'] : []), 0, 4)),
				'apply_to'=>array_values(array_slice(array_map('strval', is_array($probe['apply_to'] ?? null) ? $probe['apply_to'] : []), 0, 4)),
				'full_probe'=>'builder_response.local_convention_probe.items[0]',
			];
		}
		$can_write_now=($write_readiness['ready_for_app_owned_writes'] ?? false)===true;
		$packet=[
			'can_write_now'=>$can_write_now,
			'status'=>$status,
			'first_batch'=>$compact_first_batch,
			'write_queue'=>'builder_response.implementation_recipe.items',
			'evidence_to_collect'=>'builder_response.verification_evidence',
			'handoff_templates'=>[
				'verification'=>'builder_response.verification_handoff.post_write_handoff_template',
				'acceptance'=>'builder_response.acceptance_review_plan.post_write_handoff_template',
			],
			'not_required'=>[
				'framework/release escalation for ordinary app-owned writes',
			],
		];
		if(is_array($first_probe)){
			$packet['first_probe']=$first_probe;
		}
		return $packet;
	}

	/**
	 * Applies the compact app-builder envelope used by default app-agent payloads.
	 *
	 * @param array<string,mixed> $payload Response payload being shaped.
	 * @param array<string,mixed> $builder_response Compact builder response to expose.
	 * @param array<int,string> $omitted_fields Top-level fields to omit from the default payload.
	 * @param array<string,mixed> $context_policy Context-policy fields to merge.
	 * @return array<string,mixed> Payload with compact builder response and omitted details.
	 */
	private function mcp_app_builder_apply_compact_envelope(array $payload, array $builder_response, array $omitted_fields, array $context_policy=[]): array {
		$payload['builder_response']=$builder_response;
		foreach($omitted_fields as $field){
			unset($payload[$field]);
		}
		if($context_policy!==[]){
			$payload['context_policy']=array_merge(
				is_array($payload['context_policy'] ?? null) ? $payload['context_policy'] : [],
				$context_policy
			);
		}
		return $payload;
	}

	/**
	 * Builds a compact resume cursor for the current app-builder phase.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @param string $status Current write-readiness status.
	 * @return array<string,mixed> Resume pointer.
	 */
	private function app_builder_compact_resume_cursor(array $builder_plan, string $status): array {
		$write_readiness=is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [];
		$first_blocker=is_array($write_readiness['first_blocker'] ?? null) ? $write_readiness['first_blocker'] : [];
		if($status==='continue_entity_chunks'){
			return [
				'phase'=>'continue_entity_chunks',
				'read'=>'builder_response.entity_planning.continuation_calls[0]',
				'next_tool'=>'dataphyre_app_builder_plan_generate',
				'argument_source'=>'builder_response.entity_planning.continuation_calls[0].arguments',
				'copy_forward'=>['dependency_context', 'dependency_summary', 'application_path', 'app_namespace'],
				'write_start_packet'=>'available_after_continuations_complete',
			];
		}
		if($status==='resolve_prewrite_blockers'){
			return [
				'phase'=>'resolve_prewrite_blockers',
				'read'=>'builder_response.prewrite_checklist.prewrite_blockers[0]',
				'blocker_id'=>(string)($first_blocker['id'] ?? ''),
				'action_source'=>'builder_response.write_readiness.first_blocker.action',
				'then_read'=>'builder_response.local_convention_probe.items',
				'write_source'=>'builder_response.implementation_recipe.items after blockers are resolved',
				'write_start_packet'=>'builder_response.first_read.next_action.write_start_packet',
			];
		}
		if($status==='ready_for_app_owned_writes'){
			return [
				'phase'=>'inspect_then_write_app_owned_files',
				'read'=>'builder_response.local_convention_probe.items',
				'first_batch'=>'builder_response.first_read.next_action.write_start_packet.first_batch',
				'write_source'=>'builder_response.write_handoff.first_batch plus builder_response.implementation_recipe.items',
				'open_full_skeletons'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full only when ready to adapt app-owned code bodies.',
				'after_write'=>'builder_response.verification_execution_plan.items, builder_response.verification_handoff.post_write_handoff_template, then builder_response.acceptance_review_plan.post_write_handoff_template',
			];
		}
		return [
			'phase'=>'inspect_builder_plan',
			'read'=>'builder_response.first_read.next_action, builder_response.first_read.write_readiness, builder_response.first_read.open_details',
			'write_source'=>'none_until_write_readiness_is_ready',
		];
	}

	/**
	 * Describes how app-builder entity names were selected.
	 *
	 * @param array<string,mixed> $lane Built app-builder lane.
	 * @param array<string,mixed> $args Original tool arguments.
	 * @return array<string,mixed> Compact entity-input contract.
	 */
	private function app_builder_entity_input_contract(array $lane, array $args): array {
		$entities=array_values(array_map('strval', is_array($lane['entities'] ?? null) ? $lane['entities'] : []));
		$raw_entities=is_array($args['entities'] ?? null)
			? array_values(array_filter(array_map('strval', $args['entities']), static fn(string $entity): bool => trim($entity)!==''))
			: [];
		$name=trim((string)($args['name'] ?? ''));
		$task=trim((string)($args['task'] ?? ''));
		$phrase_entities=$this->app_builder_entities_from_task_phrases($task);
		$field_entities=(is_array($args['fields'] ?? null) && $this->app_builder_fields_input_is_nested($args['fields']))
			? $this->app_builder_entities_from_fields_input($args['fields'])
			: [];
		$provided=$raw_entities!==[] || $name!=='' || $field_entities!==[];
		$input_mode='inferred_from_task';
		$source='task_text';
		$inference='keyword_or_fallback_defaults';
		if($raw_entities!==[]){
			$input_mode='explicit_entities';
			$source='arguments.entities';
			$inference='not_used';
		}elseif($name!==''){
			$input_mode='explicit_name';
			$source='arguments.name';
			$inference='not_used';
		}elseif($field_entities!==[]){
			$input_mode='explicit_field_entities';
			$source='arguments.fields';
			$inference='not_used';
		}elseif($phrase_entities!==[]){
			$inference='bounded_enterprise_phrase_map';
		}
		$normalized=[];
		foreach($raw_entities as $entity){
			$normalized[$entity]=$this->app_builder_normalize_entity_name($entity);
		}
		foreach($field_entities as $entity){
			$normalized[$entity]=$this->app_builder_normalize_entity_name($entity);
		}
		$task_mentioned_entities=[];
		$unmodeled_task_entities=[];
		$blocking_unmodeled_task_entities=[];
		foreach($phrase_entities as $entity){
			$entity=$this->app_builder_normalize_entity_name((string)$entity);
			if($entity===''){
				continue;
			}
			$task_mentioned_entities[]=$entity;
			if($provided && !$this->app_builder_has_entity_or_specialization($entities, $entity)){
				$unmodeled_task_entities[]=$entity;
				if(!$this->app_builder_unmodeled_task_entity_is_soft_covered($entities, $entity)){
					$blocking_unmodeled_task_entities[]=$entity;
				}
			}
		}
		$task_mentioned_entities=array_values(array_unique($task_mentioned_entities));
		$unmodeled_task_entities=array_values(array_unique($unmodeled_task_entities));
		$blocking_unmodeled_task_entities=array_values(array_unique($blocking_unmodeled_task_entities));
		$should_block_partial_model=count($blocking_unmodeled_task_entities)>=2;
		$model_completeness=$provided
			? ($unmodeled_task_entities===[] ? 'explicit_model_complete_for_detected_task_entities' : 'partial_explicit_model_with_unmodeled_task_entities')
			: 'inferred_model_requires_confirmation';
		return [
			'purpose'=>'Show whether scaffold entities came from explicit input or bounded task-text inference so agents can confirm ambiguous app models without opening governance context.',
			'provided'=>$provided,
			'input_mode'=>$input_mode,
			'source'=>$source,
			'inference'=>$inference,
			'entities'=>$entities,
			'normalized_from_explicit'=>$normalized,
			'task_mentioned_entities'=>$task_mentioned_entities,
			'unmodeled_task_entities'=>$unmodeled_task_entities,
			'blocking_unmodeled_task_entities'=>$blocking_unmodeled_task_entities,
			'should_confirm_partial_model'=>$should_block_partial_model,
			'model_completeness'=>$model_completeness,
			'hybrid_model_warning'=>$provided && $unmodeled_task_entities!==[]
				? ($should_block_partial_model ? 'Task text mentions additional entities that are not in the explicit entity/field input. Treat the explicit model as partial until the user confirms omission or the agent reruns with nested fields for the missing entities.' : 'Task text mentions additional entities outside the explicit entity/field input, but they look like soft-covered policy/support concepts for the current model. Keep them as app-owned design notes unless the user wants separate resources.')
				: '',
			'policy'=>'Explicit entities and fields are preferred when the agent knows the model; inferred prose entities are a starter plan, not a domain authority.',
			'confirmation_hint'=>$provided
				? ($should_block_partial_model ? 'Confirm omitted task-mentioned entities or rerun with explicit entities and nested fields before broad multi-resource app writes.' : 'Use these explicit entities as the source of truth unless the user changes the app model; review unmodeled_task_entities as optional app-owned design notes when present.')
				: 'Confirm or override inferred entities with explicit entities and nested fields before writing broad multi-resource app files.',
		];
	}

	/**
	 * Returns true when a task-mentioned entity is likely covered by an existing broader explicit resource.
	 *
	 * @param array<int,string> $entities Explicit or planned entities.
	 * @param string $unmodeled_entity Task-mentioned entity not present in the explicit model.
	 * @return bool True when the missing entity should stay informational rather than block writes.
	 */
	private function app_builder_unmodeled_task_entity_is_soft_covered(array $entities, string $unmodeled_entity): bool {
		$entities=array_values(array_map('strval', $entities));
		if(in_array($unmodeled_entity, ['Tenant', 'Dashboard', 'Report', 'AnalyticsReport'], true)){
			return true;
		}
		$soft_coverage=[
			'NotificationMessage'=>['NotificationTemplate', 'NotificationPreference', 'DeliveryReceipt', 'Escalation'],
			'AuditEvent'=>['AuditLog', 'AuditTrail'],
			'Organization'=>['Tenant'],
			'Workspace'=>['Tenant'],
		];
		foreach($soft_coverage as $covering_entity=>$covered_entities){
			if(in_array($covering_entity, $entities, true) && in_array($unmodeled_entity, $covered_entities, true)){
				return true;
			}
		}
		foreach($entities as $entity){
			if($entity!=='' && str_starts_with($unmodeled_entity, $entity) && $unmodeled_entity!==$entity){
				return true;
			}
			if($entity!=='' && str_starts_with($entity, $unmodeled_entity) && $unmodeled_entity!==$entity){
				return true;
			}
		}
		if(count($entities)===1){
			$entity=$entities[0] ?? '';
			foreach(['Access', 'Policy', 'Message', 'Event', 'Log', 'Rule', 'Setting', 'Config', 'Configuration', 'Record', 'Workflow', 'Incident', 'Sync', 'Job', 'Request', 'Case', 'Task', 'Assessment', 'Rollout'] as $suffix){
				if($entity!=='' && str_ends_with($entity, $suffix)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Describes accepted app-builder field input shapes and the current parse.
	 *
	 * @param array<string,mixed> $lane Built app-builder lane.
	 * @param array<string,mixed> $args Original tool arguments.
	 * @return array<string,mixed> Compact field-input contract.
	 */
	private function app_builder_field_input_contract(array $lane, array $args): array {
		$raw_fields=is_array($args['fields'] ?? null) ? $args['fields'] : [];
		$explicit_entities=[];
		if($raw_fields!==[]){
			foreach(array_map('strval', is_array($lane['entities'] ?? null) ? $lane['entities'] : []) as $entity){
				if($this->app_builder_entity_fields_input($entity, $raw_fields)!==null){
					$explicit_entities[]=$entity;
				}
			}
		}
		return [
			'purpose'=>'Let agents pass different fields per entity without applying one generic field list everywhere.',
			'provided'=>$raw_fields!==[],
			'input_mode'=>$raw_fields===[]
				? 'inferred_defaults'
				: ($this->app_builder_fields_input_is_nested($raw_fields) ? 'nested_per_entity' : 'flat_single_entity'),
			'explicit_entities'=>$explicit_entities,
			'accepted_forms'=>[
				'flat_single_entity_map'=>'fields={"name":{"type":"string"},"status":"string"}',
				'flat_single_entity_list'=>'fields=["name","description","status"]',
				'nested_entity_map'=>'fields={"Project":{"name":"string required","owner_id":"foreign key to users nullable"},"Ticket":{"project_id":{"type":"integer","foreign_key_target":"projects"},"external_id":"string nullable not a foreign key","payload":{"type":"json"}}}',
				'nested_entity_entries'=>'fields=[{"entity":"Ticket","fields":{"project_id":{"type":"integer"},"title":"string"}}]',
			],
			'field_metadata'=>[
				'type'=>'Optional string type such as string, text, integer, date, datetime, boolean, decimal, json, jsonb, or phrases like foreign key to users nullable.',
				'required'=>'Optional boolean required flag or phrase-style required metadata; explicit false and not required stay optional.',
				'options'=>'Optional bounded select/enum values from options, choices, enum, or phrase-style enum metadata.',
				'default'=>'Optional default/default_value or phrase-style default value for app-owned schema, validation, and Panel adaptation.',
				'relationships'=>'Use foreign_key_target, references, relation, or phrase-style foreign key to/belongs to metadata when a field is a relationship; _id suffixes alone are not treated as relationships.',
				'not_foreign_key'=>'Use not_foreign_key=true, foreign_key=false, relationship=false, references=false, or phrase text like not a foreign key for external_id/provider_id values that are identifiers but not local relationships.',
				'integrity'=>'Use unique=true or unique_with=[...] for app-owned uniqueness hints; the builder may also infer index candidates for relationships, tenant/workspace scope, lifecycle filters, timelines, and business identifiers.',
			],
			'accepted_metadata'=>[
				'type',
				'required',
				'options',
				'choices',
				'enum',
				'default',
				'default_value',
				'json',
				'jsonb',
				'foreign_key_target',
				'references',
				'relation',
				'not_foreign_key',
				'foreign_key=false',
				'unique',
				'unique_with',
				'unique_scope',
				'phrase-style required/nullable/enum/default/foreign-key hints',
			],
		];
	}

}
