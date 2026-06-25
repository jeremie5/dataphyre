<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP app-builder scaffold completion, prewrite, and write-readiness helpers.
 */
trait dataphyre_mcp_planning_app_builder_readiness_surfaces {
/**
	 * Summarizes whether a compact scaffold plan is complete or needs continuation.
	 *
	 * @param array<string,mixed> $entity_planning Chunking metadata.
	 * @return array<string,mixed> Completion state for app-agent handoffs.
	 */
	private function app_builder_scaffold_completion_summary(array $entity_planning): array {
		$planned=array_values(array_map('strval', is_array($entity_planning['planned_entities'] ?? null) ? $entity_planning['planned_entities'] : []));
		$deferred=array_values(array_map('strval', is_array($entity_planning['deferred_entities'] ?? null) ? $entity_planning['deferred_entities'] : []));
		$truncated=($entity_planning['truncated'] ?? false)===true || $deferred!==[];
		$next_continuation=$this->app_builder_next_continuation_summary($entity_planning);
		$continuation_queue=$this->app_builder_continuation_queue_summary($entity_planning);
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
			'continuation_queue'=>$continuation_queue,
			'not_required'=>[
				'framework/release escalation for ordinary app chunk continuation',
			],
		];
	}

	/**
	 * Summarizes the next continuation call without duplicating its arguments.
	 *
	 * @param array<string,mixed> $entity_planning Chunking metadata.
	 * @return array<string,mixed> Compact continuation pointer for handoffs.
	 */
	private function app_builder_next_continuation_summary(array $entity_planning): array {
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
	 * Summarizes every deferred continuation without duplicating heavy arguments.
	 *
	 * @param array<string,mixed> $entity_planning Chunking metadata.
	 * @return array<int,array<string,mixed>> Copy-safe continuation queue.
	 */
	private function app_builder_continuation_queue_summary(array $entity_planning): array {
		$calls=is_array($entity_planning['continuation_calls'] ?? null) ? array_values($entity_planning['continuation_calls']) : [];
		$queue=[];
		foreach($calls as $index=>$call){
			if(!is_array($call)){
				continue;
			}
			$arguments=is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
			$entities=array_values(array_map('strval', is_array($arguments['entities'] ?? null) ? $arguments['entities'] : (is_array($call['entities'] ?? null) ? $call['entities'] : [])));
			if($entities===[]){
				continue;
			}
			$chunk_context=$this->app_builder_continuation_queue_context($entities, $arguments);
			$queue[]=[
				'index'=>$index,
				'tool'=>(string)($call['tool'] ?? 'dataphyre_app_builder_plan_generate'),
				'chunk'=>(int)($call['chunk'] ?? ($index+2)),
				'entities'=>$entities,
				'field_scope'=>(string)($arguments['field_scope'] ?? (($arguments['reuse_fields_from_original'] ?? false) ? 'reuse_fields_from_original' : 'unspecified')),
				'dependency_context_present'=>is_array($arguments['dependency_context'] ?? null),
				'relationship_summary'=>$chunk_context['relationship_summary'],
				'sensitive_categories'=>$chunk_context['sensitive_categories'],
				'policy_decision_count'=>$chunk_context['policy_decision_count'],
				'argument_source'=>'entity_planning.continuation_calls['.$index.'].arguments',
				'action'=>'Run this planner call before writing app-owned files for the full scaffold.',
			];
		}
		return $queue;
	}

	/**
	 * Builds compact context for one deferred continuation without copying full args.
	 *
	 * @param array<int,string> $entities Continuation entities.
	 * @param array<string,mixed> $arguments Continuation call arguments.
	 * @return array<string,mixed> Relationship, sensitivity, and policy hints.
	 */
	private function app_builder_continuation_queue_context(array $entities, array $arguments): array {
		$fields_by_entity=is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];
		$dependency_context=is_array($arguments['dependency_context'] ?? null) ? $arguments['dependency_context'] : [];
		$schemas=[];
		foreach($entities as $entity){
			$fields=is_array($fields_by_entity[$entity] ?? null) ? $fields_by_entity[$entity] : [];
			$field_hints=$this->field_hints($fields);
			$schemas[]=[
				'entity'=>$entity,
				'table'=>str_replace('-', '_', $this->slug_name($entity)),
				'fields'=>$this->app_builder_schema_fields($field_hints),
				'relationships'=>$this->app_builder_relationships($field_hints),
			];
		}
		$data_sensitivity_summary=$this->app_builder_data_sensitivity_summary($schemas);
		$app_contract_summary=$this->app_builder_app_contract_summary($schemas, (string)($arguments['task'] ?? ''));
		$policy_decision_register=$this->app_builder_policy_decision_register($app_contract_summary, $data_sensitivity_summary);
		$relationships=[];
		$dependency_scopes=[];
		foreach($schemas as $schema){
			$entity=(string)($schema['entity'] ?? '');
			foreach(is_array($schema['relationships'] ?? null) ? $schema['relationships'] : [] as $relationship){
				if(!is_array($relationship)){
					continue;
				}
				$scope='same_chunk_or_external';
				foreach(is_array($dependency_context['dependencies'] ?? null) ? $dependency_context['dependencies'] : [] as $dependency){
					if(!is_array($dependency)){
						continue;
					}
					if((string)($dependency['entity'] ?? '')===$entity && (string)($dependency['field'] ?? '')===(string)($relationship['field'] ?? '')){
						$scope=(string)($dependency['scope'] ?? $scope);
						break;
					}
				}
				$dependency_scopes[$scope]=($dependency_scopes[$scope] ?? 0)+1;
				$relationships[]=[
					'entity'=>$entity,
					'field'=>(string)($relationship['field'] ?? ''),
					'target_entity'=>(string)($relationship['target_entity'] ?? ''),
					'scope'=>$scope,
				];
			}
		}
		return [
			'relationship_summary'=>[
				'total'=>count($relationships),
				'dependency_scopes'=>$dependency_scopes,
				'relationships'=>array_slice($relationships, 0, 6),
			],
			'sensitive_categories'=>array_values(array_map('strval', is_array($data_sensitivity_summary['categories'] ?? null) ? $data_sensitivity_summary['categories'] : [])),
			'policy_decision_count'=>(int)($policy_decision_register['required_count'] ?? 0),
		];
	}

	/**
	 * Builds a compact checklist to run before writing app-owned files.
	 *
	 * @param array<string,mixed> $entity_input_contract Entity-source metadata.
	 * @param array<string,mixed> $entity_planning Chunking metadata.
	 * @param array<string,mixed> $app_contract_summary App-owned policy/data contract hints.
	 * @param array<string,mixed> $data_sensitivity_summary Schema-derived sensitivity hints.
	 * @param array<string,mixed> $relationship_contract_summary Relationship metadata.
	 * @param array<string,mixed> $field_metadata_summary Options/default metadata summary.
	 * @param array<string,mixed> $app_path_context Path and namespace hints.
	 * @param array<string,mixed> $write_plan_summary App-owned write order.
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @param array<string,mixed> $sensitivity_policy Task-derived sensitivity handling policy.
	 * @return array<string,mixed> Prewrite checklist.
	 */
	private function app_builder_prewrite_checklist(array $entity_input_contract, array $entity_planning, array $app_contract_summary, array $data_sensitivity_summary, array $relationship_contract_summary, array $field_metadata_summary, array $app_path_context, array $write_plan_summary, array $verification_plan, array $sensitivity_policy=[]): array {
		$inferred=($entity_input_contract['provided'] ?? false)!==true;
		$unmodeled_task_entities=array_values(array_map('strval', is_array($entity_input_contract['unmodeled_task_entities'] ?? null) ? $entity_input_contract['unmodeled_task_entities'] : []));
		$blocking_unmodeled_task_entities=array_values(array_map('strval', is_array($entity_input_contract['blocking_unmodeled_task_entities'] ?? null) ? $entity_input_contract['blocking_unmodeled_task_entities'] : $unmodeled_task_entities));
		$partial_explicit_model=($entity_input_contract['should_confirm_partial_model'] ?? false)===true && $blocking_unmodeled_task_entities!==[];
		$truncated=($entity_planning['truncated'] ?? false)===true;
		$has_relationships=(int)($relationship_contract_summary['total_relationships'] ?? 0)>0;
		$has_field_metadata=($field_metadata_summary['has_field_metadata'] ?? false)===true;
		$placeholder_mode=($app_path_context['placeholder_mode'] ?? true)!==false;
		$missing_contract_fields=[];
		foreach(is_array($app_contract_summary['missing_common_fields'] ?? null) ? $app_contract_summary['missing_common_fields'] : [] as $fields){
			foreach(is_array($fields) ? $fields : [] as $field){
				$missing_contract_fields[]=(string)$field;
			}
		}
		$has_feature_intent_decisions=false;
		foreach(is_array($app_contract_summary['decision_prompts'] ?? null) ? $app_contract_summary['decision_prompts'] : [] as $prompt){
			if(is_array($prompt) && (string)($prompt['status'] ?? '')==='needs_app_owned_design'){
				$has_feature_intent_decisions=true;
				break;
			}
		}
		$has_contract_decisions=$missing_contract_fields!==[] || $has_relationships || $has_feature_intent_decisions;
		$has_sensitive_signals=($data_sensitivity_summary['has_sensitive_signals'] ?? false)===true;
		$hard_block_sensitive_writes=($sensitivity_policy['hard_block_sensitive_writes'] ?? false)===true;
		$sensitive_categories=array_values(array_map('strval', is_array($data_sensitivity_summary['categories'] ?? null) ? $data_sensitivity_summary['categories'] : []));
		$sensitive_category_policies=is_array($data_sensitivity_summary['category_policies'] ?? null) ? $data_sensitivity_summary['category_policies'] : [];
		$sensitive_policy_metadata=$this->app_builder_sensitive_policy_metadata($sensitive_categories, $sensitive_category_policies, $hard_block_sensitive_writes);
		$sensitivity_status=$has_sensitive_signals
			? ($hard_block_sensitive_writes ? 'confirm_app_owned_redaction_and_access' : 'implement_app_owned_sensitive_policy')
			: 'no_sensitive_field_signals_detected';
		$sensitivity_action=$has_sensitive_signals
			? ($hard_block_sensitive_writes
				? 'Task scope explicitly asks for elevated security/governance-sensitive behavior; review builder_response.data_sensitivity_summary and confirm app-owned access, redaction, storage, validation, and focused checks before writing files.'
				: 'Review builder_response.data_sensitivity_summary and implement app-owned access, redaction, storage, validation, and focused checks during app-owned edits before verification.')
			: 'No credential, identity, billing, tenant/access, or regulated personal-data field names were inferred.';
		$path_exists=($app_path_context['path_exists'] ?? null)===true;
		$path_input_valid=($app_path_context['path_input_valid'] ?? true)===true;
		$namespace_input_valid=($app_path_context['namespace_input_valid'] ?? true)===true;
		$path_status=!$path_input_valid ? 'invalid_application_path' : (!$namespace_input_valid ? 'invalid_app_namespace' : ($placeholder_mode ? 'requires_concrete_app_paths' : ($path_exists ? 'concrete_app_paths_available' : 'verify_concrete_app_path_exists')));
		$path_action=!$path_input_valid
			? 'Caller supplied an invalid application_path; use dataphyre_application_catalog and rerun with a repo-relative applications/<app> or applications/<app>/backend/dataphyre path before writing or verifying.'
			: (!$namespace_input_valid
				? 'Caller supplied an invalid app_namespace; rerun with a valid PHP namespace such as App, AcmePortal, or Acme\\Portal before writing generated skeletons.'
				: ($placeholder_mode
				? 'Supply application_path or replace <app>, <resource>, and <app framework> placeholders with concrete consuming-application paths before writing or verifying.'
				: ($path_exists
					? 'Concrete app-owned path hints are available in builder_response.app_path_context; verify they match local conventions before writing.'
					: 'Caller supplied a concrete application_path, but it was not found locally; use dataphyre_application_catalog or correct application_path, then rerun the builder before writing.')));
		$checks=[
			[
				'id'=>'confirm_entity_model',
				'status'=>$partial_explicit_model ? 'confirm_partial_explicit_model' : ($inferred ? 'needs_confirmation_or_explicit_override' : 'explicit_input_available'),
				'action'=>$partial_explicit_model
					? 'Task text mentions additional entities not present in explicit entities/fields: '.implode(', ', $blocking_unmodeled_task_entities).'. Confirm they are intentionally omitted or rerun with explicit entities and nested fields before broad multi-resource writes.'
					: ($inferred ? 'Confirm inferred entities or rerun with explicit entities and nested fields before broad multi-resource writes.' : 'Use explicit entities and field hints as the current source of truth.'),
			],
			[
				'id'=>'preserve_chunk_context',
				'status'=>$truncated ? 'required_for_deferred_entities' : 'not_required_for_single_chunk',
				'action'=>$truncated ? 'Follow entity_planning.continuation_calls and preserve dependency_context before treating the scaffold as complete.' : 'No deferred entity chunks are reported.',
			],
			[
				'id'=>'replace_placeholders',
				'status'=>$path_status,
				'action'=>$path_action,
			],
			[
				'id'=>'open_adaptation_notes',
				'status'=>'prewrite_reminder',
				'action'=>'Open full code_skeletons and apply adaptation_notes for namespaces, query adapters, manifests, SQL config registration, and regression checks.',
			],
			[
				'id'=>'relationship_adapters',
				'status'=>$has_relationships ? 'implement_app_owned_relationship_adapters' : 'no_relationships_detected',
				'action'=>$has_relationships ? 'Implement relationship lookup, permission, tenant, and relation UI behavior in app-owned repositories/adapters before verification.' : 'No relationship adapter work was inferred from the current schema.',
			],
			[
				'id'=>'app_contract_decisions',
				'status'=>$has_contract_decisions ? 'decide_app_owned_policy_during_edits' : 'no_common_contract_gaps_detected',
				'action'=>$has_contract_decisions ? 'Review builder_response.app_contract_summary and decide app-owned ownership, tenant/workspace scope, lifecycle, audit, dashboard/reporting, and relationship policy during app-owned edits before verification.' : 'No common ownership, scope, audit, lifecycle, dashboard/reporting, or relationship contract gaps were inferred.',
			],
			[
				'id'=>'data_sensitivity',
				'status'=>$sensitivity_status,
				'action'=>$sensitivity_action,
				'policy_metadata'=>$sensitive_policy_metadata,
			],
			[
				'id'=>'stay_app_owned',
				'status'=>'guardrail_reminder',
				'action'=>'Use app code, config, callbacks, dialbacks, plugins, MCP metadata, or app adapters; do not edit Dataphyre runtime internals for one application.',
			],
			[
				'id'=>'focused_verification',
				'status'=>'required_after_writes',
				'action'=>'Run the focused app/module checks from verification_plan after concrete paths are in place; MCP/release-surface proof is not required for ordinary app work.',
			],
		];
		if($has_field_metadata){
			array_splice($checks, 4, 0, [[
				'id'=>'field_metadata',
				'status'=>'preserve_app_owned_options_defaults',
				'action'=>'Review builder_response.field_metadata_summary and preserve options/defaults in app-owned schema, validation, Panel select controls, filters, and focused tests.',
			]]);
		}
		$blocking_statuses=[
			'needs_confirmation_or_explicit_override',
			'confirm_partial_explicit_model',
			'required_for_deferred_entities',
			'requires_concrete_app_paths',
			'invalid_application_path',
			'invalid_app_namespace',
			'verify_concrete_app_path_exists',
			'confirm_app_owned_redaction_and_access',
		];
		$implementation_statuses=[
			'preserve_app_owned_options_defaults',
			'implement_app_owned_relationship_adapters',
			'decide_app_owned_policy_during_edits',
			'implement_app_owned_sensitive_policy',
		];
		$prewrite_blockers=[];
		$implementation_obligations=[];
		$prewrite_reminders=[];
		foreach($checks as $check){
			$status=(string)($check['status'] ?? '');
			if(in_array($status, $blocking_statuses, true)){
				$prewrite_blockers[]=[
					'id'=>(string)($check['id'] ?? ''),
					'status'=>$status,
					'action'=>(string)($check['action'] ?? ''),
				];
				if(($check['id'] ?? null)==='data_sensitivity' && is_array($check['policy_metadata'] ?? null)){
					$prewrite_blockers[array_key_last($prewrite_blockers)]['policy_metadata']=$check['policy_metadata'];
				}
				continue;
			}
			if(in_array($status, $implementation_statuses, true)){
				$implementation_obligations[]=[
					'id'=>(string)($check['id'] ?? ''),
					'status'=>$status,
					'action'=>(string)($check['action'] ?? ''),
				];
				if(($check['id'] ?? null)==='data_sensitivity' && is_array($check['policy_metadata'] ?? null)){
					$implementation_obligations[array_key_last($implementation_obligations)]['policy_metadata']=$check['policy_metadata'];
				}
				continue;
			}
			if(in_array($status, ['prewrite_reminder', 'guardrail_reminder', 'required_after_writes'], true)){
				$prewrite_reminders[]=[
					'id'=>(string)($check['id'] ?? ''),
					'status'=>$status,
					'action'=>(string)($check['action'] ?? ''),
				];
			}
		}
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Short prewrite checks for ordinary app scaffolds; hard gates become blockers, while app-owned implementation obligations and procedural reminders stay visible without blocking clean plans.',
			'checks'=>$checks,
			'sensitivity_gate_policy'=>[
				'hard_block_sensitive_writes'=>$hard_block_sensitive_writes,
				'tier'=>$sensitivity_policy['tier'] ?? 'lightweight',
				'signals'=>array_values(array_map('strval', is_array($sensitivity_policy['signals'] ?? null) ? $sensitivity_policy['signals'] : [])),
				'ordinary_sensitive_status'=>'implement_app_owned_sensitive_policy',
				'elevated_sensitive_status'=>'confirm_app_owned_redaction_and_access',
				'policy_metadata'=>$sensitive_policy_metadata,
				'reason'=>(string)($sensitivity_policy['reason'] ?? ($hard_block_sensitive_writes ? 'Task requested elevated sensitive behavior.' : 'Schema-derived sensitive fields are ordinary app-owned implementation obligations.')),
			],
			'prewrite_blockers'=>$prewrite_blockers,
			'implementation_obligations'=>$implementation_obligations,
			'prewrite_reminders'=>$prewrite_reminders,
			'resolution_plan'=>$this->app_builder_prewrite_resolution_plan($prewrite_blockers, $implementation_obligations, $prewrite_reminders),
			'ready_to_write'=>$prewrite_blockers===[],
			'ready_to_write_rule'=>'Resolve every prewrite_blockers item with concrete app-owned choices before writing files; complete implementation_obligations and prewrite_reminders during app-owned edits, then collect focused_verification after writes.',
			'write_plan'=>'builder_response.write_plan_summary',
			'verification_policy'=>(string)($verification_plan['policy'] ?? 'focused_application_or_module_verification'),
			'not_required'=>is_array($write_plan_summary['not_required'] ?? null) ? $write_plan_summary['not_required'] : [],
		];
	}

	/**
	 * Builds compact resolution guidance for prewrite blockers and obligations.
	 *
	 * @param array<int,array<string,mixed>> $prewrite_blockers Blocking checks.
	 * @param array<int,array<string,mixed>> $implementation_obligations App-owned obligations.
	 * @param array<int,array<string,mixed>> $prewrite_reminders Procedural reminders.
	 * @return array<string,mixed> Resolution plan.
	 */
	private function app_builder_prewrite_resolution_plan(array $prewrite_blockers, array $implementation_obligations, array $prewrite_reminders): array {
		$items=[];
		foreach([
			'blocker'=>$prewrite_blockers,
			'implementation_obligation'=>$implementation_obligations,
			'reminder'=>$prewrite_reminders,
		] as $type=>$checks){
			foreach($checks as $check){
				if(!is_array($check)){
					continue;
				}
				$id=(string)($check['id'] ?? '');
				if($id===''){
					continue;
				}
				$items[]=[
					'id'=>$id,
					'type'=>$type,
					'status'=>(string)($check['status'] ?? ''),
					'action'=>(string)($check['action'] ?? ''),
					'resolution_sources'=>$this->app_builder_prewrite_resolution_sources($id),
					'acceptable_resolutions'=>$this->app_builder_prewrite_acceptable_resolutions($id),
				];
			}
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$prewrite_blockers===[] ? 'ready_for_app_owned_resolution_during_edits' : 'resolve_blockers_before_writes',
			'purpose'=>'Machine-readable resolution hints for prewrite blockers, implementation obligations, and reminders without opening governance context.',
			'items'=>$items,
			'copy_forward'=>'builder_response.prewrite_checklist.resolution_plan',
			'after_blockers_resolved'=>'Inspect builder_response.local_convention_probe, open full code_skeletons for adaptation_notes, then follow builder_response.implementation_recipe.items.',
			'not_required'=>[
				'enterprise audit for ordinary app prewrite resolution',
				'MCP/release-surface validation for ordinary app prewrite resolution',
				'Dataphyre runtime-internal edits to resolve one app blocker',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Returns builder fields that help resolve a prewrite item.
	 *
	 * @param string $id Prewrite item id.
	 * @return array<int,string> Resolution sources.
	 */
	private function app_builder_prewrite_resolution_sources(string $id): array {
		return match($id){
			'confirm_entity_model'=>['builder_response.entity_input_contract', 'builder_response.entity_planning', 'builder_response.schema'],
			'preserve_chunk_context'=>['builder_response.scaffold_completion_summary.next_continuation', 'builder_response.entity_planning.continuation_calls'],
			'replace_placeholders'=>['builder_response.app_path_context', 'dataphyre_application_info', 'dataphyre_application_catalog'],
			'open_adaptation_notes'=>['builder_response.code_skeleton_summary', 'builder_plan.code_skeletons when payload_profile=full'],
			'field_metadata'=>['builder_response.field_metadata_summary', 'builder_response.data_model_handoff', 'builder_response.panel_fields', 'builder_response.filters'],
			'relationship_adapters'=>['builder_response.relationship_contract_summary', 'builder_response.relationship_adapter_handoff', 'builder_response.implementation_recipe.items'],
			'app_contract_decisions'=>['builder_response.app_contract_summary', 'builder_response.policy_decision_register'],
			'data_sensitivity'=>['builder_response.data_sensitivity_summary', 'builder_response.policy_decision_register', 'builder_response.prewrite_checklist.sensitivity_gate_policy'],
			'stay_app_owned'=>['builder_response.extension_boundary_summary', 'builder_response.extension_decision_ladder'],
			'focused_verification'=>['builder_response.verification_execution_plan', 'builder_response.verification_handoff', 'builder_response.acceptance_review_plan'],
			default=>['builder_response.prewrite_checklist'],
		};
	}

	/**
	 * Returns acceptable app-agent resolutions for a prewrite item.
	 *
	 * @param string $id Prewrite item id.
	 * @return array<int,string> Acceptable resolutions.
	 */
	private function app_builder_prewrite_acceptable_resolutions(string $id): array {
		return match($id){
			'confirm_entity_model'=>['User confirms inferred entities', 'Agent reruns builder with explicit entities and nested fields'],
			'preserve_chunk_context'=>['Agent runs continuation call and carries dependency_context until deferred_entities is empty'],
			'replace_placeholders'=>['Agent supplies application_path/app_namespace', 'Agent verifies caller-supplied application_path exists locally or corrects it from dataphyre_application_catalog', 'Agent replaces placeholders with concrete app-owned paths before writes and checks'],
			'open_adaptation_notes'=>['Agent opens full payload only when ready to adapt app-owned code bodies'],
			'field_metadata'=>['Agent preserves options/defaults/required metadata in app-owned schema/TableSchema, Panel fields, filters, and tests'],
			'relationship_adapters'=>['Agent implements app-owned relationship option sources, repository touchpoints, and permission/scoping checks'],
			'app_contract_decisions'=>['Agent makes app-owned ownership, scope, lifecycle, audit, reporting, or relationship policy decisions in app code/config/adapters'],
			'data_sensitivity'=>['Agent implements app-owned access, redaction, storage, validation, and focused checks', 'For elevated tasks, user/app owner confirms sensitive handling before writes'],
			'stay_app_owned'=>['Agent uses app code, config, callbacks, dialbacks, plugins, MCP metadata, or app adapters'],
			'focused_verification'=>['Agent runs verification_execution_plan.items and records copy-safe verification_handoff plus acceptance_review_plan results'],
			default=>['Agent resolves the item with app-owned evidence before claiming completion'],
		};
	}

	/**
	 * Reduces scaffold completion and prewrite checks to one write decision.
	 *
	 * @param array<string,mixed> $scaffold_completion_summary Chunk completion metadata.
	 * @param array<string,mixed> $prewrite_checklist App-owned prewrite checklist.
	 * @return array<string,mixed> Machine-readable write-readiness summary.
	 */
	private function app_builder_write_readiness(array $scaffold_completion_summary, array $prewrite_checklist): array {
		$complete=($scaffold_completion_summary['complete'] ?? false)===true;
		$blockers=is_array($prewrite_checklist['prewrite_blockers'] ?? null) ? array_values($prewrite_checklist['prewrite_blockers']) : [];
		$first_blocker=is_array($blockers[0] ?? null) ? $blockers[0] : null;
		$status=!$complete ? 'continue_entity_chunks' : ($blockers===[] ? 'ready_for_app_owned_writes' : 'resolve_prewrite_blockers');
		$can_write_now=$complete && $blockers===[];
		return [
			'owner'=>'consuming_application',
			'status'=>$status,
			'ready_for_app_owned_writes'=>$can_write_now,
			'scaffold_complete'=>$complete,
			'deferred_entities'=>array_values(array_map('strval', is_array($scaffold_completion_summary['deferred_entities'] ?? null) ? $scaffold_completion_summary['deferred_entities'] : [])),
			'blocker_count'=>count($blockers),
			'blocker_scope'=>'prewrite_blockers_gate_app_owned_writes_not_read_only_planning_continuations',
			'first_blocker_applies_after'=>$complete ? 'now' : 'scaffold_completion_summary.deferred_entities is empty',
			'first_blocker'=>$first_blocker,
			'next_action'=>!$complete
				? 'Run entity_planning.continuation_calls[0] and preserve dependency_context before writing files.'
				: ($first_blocker!==null ? (string)($first_blocker['action'] ?? 'Resolve prewrite_checklist.prewrite_blockers before writing files.') : 'Write app-owned files in write_plan_summary order while carrying prewrite_checklist.implementation_obligations and prewrite_reminders, then collect focused verification_evidence, verification_handoff.post_write_handoff_template, and acceptance_review_plan.post_write_handoff_template.'),
			'write_start_contract'=>[
				'can_write_now'=>$can_write_now,
				'decision_source'=>'builder_response.write_readiness.ready_for_app_owned_writes',
				'if_blocked_read'=>!$complete ? 'builder_response.scaffold_completion_summary.next_continuation' : ($first_blocker!==null ? 'builder_response.prewrite_checklist.prewrite_blockers[0]' : null),
				'if_ready_read'=>'builder_response.write_handoff.first_batch',
				'write_queue'=>'builder_response.implementation_recipe.items',
				'after_write_evidence'=>[
					'builder_response.verification_handoff.post_write_handoff_template',
					'builder_response.acceptance_review_plan.post_write_handoff_template',
				],
				'ordinary_app_boundary'=>'app-owned files only; focused app/module verification after writes',
			],
			'handoff_fields'=>[
				'scaffold_completion_summary',
				'entity_planning.continuation_calls',
				'app_path_context',
				'surface_execution_plan',
				'companion_surface_handoff',
				'relationship_contract_summary',
				'relationship_adapter_handoff',
				'field_metadata_summary',
				'data_model_handoff',
				'data_integrity_summary',
				'lifecycle_state_handoff',
				'audit_retention_handoff',
				'data_sensitivity_summary',
				'access_control_handoff',
				'operational_reliability_handoff',
				'support_observability_handoff',
				'change_management_handoff',
				'integration_boundary_handoff',
				'tenant_identity_handoff',
				'policy_decision_register',
				'domain_workflow_handoff',
				'reporting_analytics_handoff',
				'notification_communication_handoff',
				'prewrite_checklist.prewrite_blockers',
				'prewrite_checklist.implementation_obligations',
				'code_skeleton_summary',
				'local_convention_probe',
				'write_plan_summary',
				'implementation_matrix',
				'implementation_recipe',
				'verification_evidence',
				'verification_handoff',
				'verification_handoff.post_write_handoff_template',
				'verification_execution_plan',
				'verification_fixture_handoff',
				'acceptance_review_plan',
				'acceptance_review_plan.post_write_handoff_template',
				'verification_recovery_plan',
				'diagnostic_handoff_hint',
			],
			'not_required'=>[
				'framework/release escalation for ordinary app write readiness',
			],
		];
	}

	/**
	 * Summarizes multi-surface app work without forcing agents into governance docs.
	 *
	 * @param string $scaffold_type Primary scaffold family.
	 * @param array<string,mixed> $scaffold_completion_summary Chunk completion metadata.
	 * @param array<string,mixed> $companion_surface_handoff Optional companion surface handoff.
	 * @return array<string,mixed> Ordered surface plan for app-owned writes.
	 */
	private function app_builder_surface_execution_plan(string $scaffold_type, array $scaffold_completion_summary, array $companion_surface_handoff): array {
		$complete=($scaffold_completion_summary['complete'] ?? false)===true;
		$has_companion=$companion_surface_handoff!==[];
		$primary_surface=$scaffold_type==='api_endpoint'
			? 'api_endpoint'
			: ($scaffold_type==='routing_controller' || $scaffold_type==='mvc_controller' ? 'routing_controller' : 'panel_data_model');
		$steps=[];
		if(!$complete){
			$steps[]=[
				'id'=>'complete_entity_chunks',
				'status'=>'required_before_writes',
				'action'=>'Run entity_planning.continuation_calls until scaffold_completion_summary.deferred_entities is empty, preserving dependency_context.',
				'source'=>'builder_response.entity_planning.continuation_calls',
			];
		}
		$steps[]=[
			'id'=>'write_primary_surface',
			'status'=>$complete ? 'ready_after_prewrite_blockers' : 'blocked_until_entity_chunks_complete',
			'surface'=>$primary_surface,
			'action'=>'Adapt app-owned data, Panel, route, or API skeletons from payload_profile=full after prewrite blockers are resolved.',
			'source'=>'builder_response.write_plan_summary',
		];
		if($has_companion){
			$steps[]=[
				'id'=>'plan_companion_surface',
				'status'=>$complete ? 'ready_after_primary_model' : 'queued_after_entity_chunks',
				'surface'=>'api_endpoint',
				'action'=>'Run companion_surface_handoff.arguments to plan the API/self-service surface against the completed app model.',
				'source'=>'builder_response.companion_surface_handoff.arguments',
			];
		}
		$steps[]=[
			'id'=>'focused_surface_verification',
			'status'=>'required_after_app_owned_writes',
			'action'=>$has_companion ? 'Verify the primary app surface and companion API/routing surface with focused app/module checks.' : 'Verify the primary app surface with focused app/module checks.',
			'source'=>'builder_response.verification_handoff',
		];
		return [
			'owner'=>'consuming_application',
			'purpose'=>'First-view execution order for app-owned multi-surface builds; keeps chunking, companion API work, writes, and verification visible without opening governance context.',
			'primary_surface'=>$primary_surface,
			'has_companion_surface'=>$has_companion,
			'status'=>!$complete ? 'complete_entity_chunks_first' : ($has_companion ? 'primary_and_companion_surfaces_ready_to_plan' : 'primary_surface_ready_to_plan'),
			'steps'=>$steps,
			'companion_surface'=>[
				'available'=>$has_companion,
				'status'=>$has_companion ? (string)($companion_surface_handoff['status'] ?? 'companion_surface_available') : 'not_requested',
				'next_tool'=>$has_companion ? (string)($companion_surface_handoff['next_tool'] ?? 'dataphyre_app_builder_plan_generate') : null,
				'argument_source'=>$has_companion ? 'builder_response.companion_surface_handoff.arguments' : null,
				'when'=>$has_companion ? (string)($companion_surface_handoff['when'] ?? 'After entity chunks are complete and before treating the app scaffold as ready to write.') : null,
			],
			'not_required'=>[
				'governance context for mixed Panel/API app planning',
				'MCP/release-surface validation for ordinary app-owned companion surfaces',
				'Dataphyre runtime-internal edits for one application surface',
			],
		];
	}

	/**
	 * Builds a compact write sequence for app-owned scaffold work.
	 *
	 * @param array<int,string> $files Planned app-owned files.
	 * @param array<int,array<string,mixed>> $data_model Planned data-model previews.
	 * @param array<int,array<string,mixed>> $implementation_sequence Ordered implementation steps.
	 * @param array<string,mixed> $code_skeleton_summary Compact skeleton summary.
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @param array<string,mixed> $local_convention_probe Local convention inspection plan.
	 * @return array<string,mixed> App-owned write plan summary.
	 */
	private function app_builder_write_plan_summary(array $files, array $data_model, array $implementation_sequence, array $code_skeleton_summary, array $verification_plan, array $local_convention_probe=[]): array {
		$paths_by_kind=is_array($code_skeleton_summary['paths_by_kind'] ?? null) ? $code_skeleton_summary['paths_by_kind'] : [];
		$probe_items=is_array($local_convention_probe['items'] ?? null) ? $local_convention_probe['items'] : [];
		$data_paths=[];
		foreach($data_model as $model){
			if(!is_array($model)){
				continue;
			}
			foreach(is_array($model['artifact_paths'] ?? null) ? $model['artifact_paths'] : [] as $path){
				$data_paths[]=(string)$path;
			}
		}
		$verification_tools=[];
		foreach(is_array($verification_plan['steps'] ?? null) ? $verification_plan['steps'] : [] as $step){
			if(is_array($step) && isset($step['tool'])){
				$verification_tools[]=(string)$step['tool'];
			}
		}
		$entity_build_order=[];
		foreach($implementation_sequence as $step){
			if(is_array($step) && ($step['id'] ?? null)==='define_app_data_contract' && is_array($step['entity_build_order'] ?? null)){
				$entity_build_order=$step['entity_build_order'];
				break;
			}
		}
		$data_model_batch=['concern'=>'data_model_artifacts', 'source'=>'builder_plan.data_model', 'paths'=>array_values(array_unique(array_slice($data_paths, 0, 12)))];
		if($entity_build_order!==[]){
			$data_model_batch['entity_build_order']=$entity_build_order;
		}
		$not_required=is_array($verification_plan['not_required'] ?? null) ? $verification_plan['not_required'] : [];
		$not_required=array_values(array_unique(array_map(static function(mixed $item): string {
			$value=(string)$item;
			return $value==='Dataphyre shared hot-path benchmark evidence' ? 'framework/release escalation for ordinary app write planning' : $value;
		}, $not_required)));
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Compact app-owned write sequence for ordinary scaffold work without inlining skeleton bodies or governance context.',
			'write_order'=>array_values(array_filter([
				['concern'=>'inspect_local_conventions', 'source'=>'builder_response.local_convention_probe', 'paths'=>[], 'probe'=>$probe_items],
				$data_model_batch,
				['concern'=>'panel_resources', 'source'=>'code_skeleton_summary.paths_by_kind.panel_resource', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['panel_resource'] ?? null) ? $paths_by_kind['panel_resource'] : []))],
				['concern'=>'panel_manifests', 'source'=>'code_skeleton_summary.paths_by_kind.panel_manifest', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['panel_manifest'] ?? null) ? $paths_by_kind['panel_manifest'] : []))],
				['concern'=>'route_free_regression', 'source'=>'code_skeleton_summary.paths_by_kind.panel_regression_manifest', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['panel_regression_manifest'] ?? null) ? $paths_by_kind['panel_regression_manifest'] : []))],
				['concern'=>'api_routes', 'source'=>'code_skeleton_summary.paths_by_kind.api_route', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['api_route'] ?? null) ? $paths_by_kind['api_route'] : []))],
				['concern'=>'api_endpoint_handlers', 'source'=>'code_skeleton_summary.paths_by_kind.api_endpoint_handler', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['api_endpoint_handler'] ?? null) ? $paths_by_kind['api_endpoint_handler'] : []))],
				['concern'=>'api_regression_manifests', 'source'=>'code_skeleton_summary.paths_by_kind.api_regression_manifest', 'paths'=>array_values(array_map('strval', is_array($paths_by_kind['api_regression_manifest'] ?? null) ? $paths_by_kind['api_regression_manifest'] : []))],
				['concern'=>'focused_verification', 'source'=>'verification_plan.steps', 'tools'=>array_values(array_unique($verification_tools))],
			], static fn(array $batch): bool => (($batch['concern'] ?? null)==='inspect_local_conventions') || (($batch['concern'] ?? null)==='focused_verification') || (is_array($batch['paths'] ?? null) && $batch['paths']!==[]) || (is_array($batch['tools'] ?? null) && $batch['tools']!==[]))),
			'policy'=>'Write only app-owned files; open full code_skeletons for adaptation_notes before writing, replace placeholders with concrete app paths, then run focused app/module verification for Panel, SQL, route, or API endpoint work.',
			'not_required'=>$not_required,
		];
	}

	/**
	 * Maps app-owned obligations to write targets and focused proof.
	 *
	 * @param array<string,mixed> $prewrite_checklist Prewrite blockers and obligations.
	 * @param array<string,mixed> $write_plan_summary App-owned write order.
	 * @param array<string,mixed> $verification_plan Focused verification plan.
	 * @param array<string,mixed> $code_skeleton_summary Skeleton paths by kind.
	 * @param array<string,mixed> $app_contract_summary App-owned policy summary.
	 * @param array<string,mixed> $policy_decision_register Policy decision register.
	 * @param array<string,mixed> $relationship_contract_summary Relationship summary.
	 * @param array<string,mixed> $field_metadata_summary Field options/default summary.
	 * @param array<string,mixed> $data_integrity_summary Integrity summary.
	 * @param array<string,mixed> $data_sensitivity_summary Sensitivity summary.
	 * @param array<string,mixed> $tenant_identity_handoff Tenant, actor, permission, and entitlement handoff.
	 * @param array<string,array<string,mixed>> $corporate_summaries Existing app-owned corporate control summaries.
	 * @return array<string,mixed> Compact implementation matrix.
	 */
	private function app_builder_implementation_matrix(array $prewrite_checklist, array $write_plan_summary, array $verification_plan, array $code_skeleton_summary, array $app_contract_summary, array $policy_decision_register, array $relationship_contract_summary, array $field_metadata_summary, array $data_integrity_summary, array $data_sensitivity_summary, array $tenant_identity_handoff=[], array $corporate_summaries=[]): array {
		$paths_by_kind=is_array($code_skeleton_summary['paths_by_kind'] ?? null) ? $code_skeleton_summary['paths_by_kind'] : [];
		$verification_tools=[];
		foreach(is_array($verification_plan['verification_todo'] ?? null) ? $verification_plan['verification_todo'] : [] as $todo){
			if(is_array($todo) && isset($todo['tool'])){
				$verification_tools[]=(string)$todo['tool'];
			}
		}
		$verification_tools=array_values(array_unique($verification_tools));
		$obligation_ids=[];
		foreach(is_array($prewrite_checklist['implementation_obligations'] ?? null) ? $prewrite_checklist['implementation_obligations'] : [] as $obligation){
			if(is_array($obligation) && isset($obligation['id'])){
				$obligation_ids[(string)$obligation['id']]=true;
			}
		}
		$items=[];
		if(($field_metadata_summary['has_field_metadata'] ?? false)===true || isset($obligation_ids['field_metadata'])){
			$items[]=$this->app_builder_implementation_matrix_item(
				'field_metadata',
				'preserve_options_defaults',
				['field_metadata_summary', 'data_model_handoff'],
				['table_schema', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Preserve bounded options and defaults in app-owned schema, validation, Panel controls, filters, and focused tests.',
				[
					'field_count'=>(int)($field_metadata_summary['field_count'] ?? 0),
					'entities'=>array_values(array_map('strval', is_array($field_metadata_summary['entities'] ?? null) ? $field_metadata_summary['entities'] : [])),
				]
			);
		}
		if((int)($relationship_contract_summary['total_relationships'] ?? 0)>0 || isset($obligation_ids['relationship_adapters'])){
			$items[]=$this->app_builder_implementation_matrix_item(
				'relationship_adapters',
				'map_relationship_lookup_scope_and_empty_states',
				['relationship_contract_summary', 'data_integrity_summary.foreign_key_constraints'],
				['table_repository', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Implement app-owned relationship lookup, permission, tenant/scope filtering, labels, and empty states.',
				[
					'total_relationships'=>(int)($relationship_contract_summary['total_relationships'] ?? 0),
					'planned_targets'=>array_values(array_map('strval', is_array($relationship_contract_summary['planned_targets'] ?? null) ? $relationship_contract_summary['planned_targets'] : [])),
					'external_targets'=>array_values(array_map('strval', is_array($relationship_contract_summary['external_targets'] ?? null) ? $relationship_contract_summary['external_targets'] : [])),
				]
			);
		}
		if(($data_integrity_summary['has_integrity_work'] ?? false)===true){
			$items[]=$this->app_builder_implementation_matrix_item(
				'data_integrity',
				'apply_indexes_uniqueness_required_fields_and_fk_hints',
				['data_integrity_summary'],
				['table_schema', 'table_repository', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Adapt app-owned TableSchema/migration metadata and repository checks for indexes, uniqueness, required fields, foreign keys, scope fields, and business identifiers.',
				[
					'index_count'=>count(is_array($data_integrity_summary['indexes'] ?? null) ? $data_integrity_summary['indexes'] : []),
					'unique_constraint_count'=>count(is_array($data_integrity_summary['unique_constraints'] ?? null) ? $data_integrity_summary['unique_constraints'] : []),
					'foreign_key_constraint_count'=>count(is_array($data_integrity_summary['foreign_key_constraints'] ?? null) ? $data_integrity_summary['foreign_key_constraints'] : []),
				]
			);
		}
		if(($policy_decision_register['required_count'] ?? 0)>0 || isset($obligation_ids['app_contract_decisions'])){
			$items[]=$this->app_builder_implementation_matrix_item(
				'app_contract_decisions',
				'apply_app_owned_policy_choices',
				['app_contract_summary', 'policy_decision_register'],
				['table_repository', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Resolve ownership, tenant/workspace scope, lifecycle, audit, feature-intent, and relationship policy decisions in app-owned code or config before writing broad behavior.',
				[
					'required_decision_count'=>(int)($policy_decision_register['required_count'] ?? 0),
					'decision_ids'=>array_values(array_map(static fn(array $decision): string => (string)($decision['id'] ?? ''), array_filter(is_array($policy_decision_register['decisions'] ?? null) ? $policy_decision_register['decisions'] : [], 'is_array'))),
				]
			);
		}
		if(($data_sensitivity_summary['has_sensitive_signals'] ?? false)===true || isset($obligation_ids['data_sensitivity'])){
			$items[]=$this->app_builder_implementation_matrix_item(
				'data_sensitivity',
				'apply_access_redaction_storage_and_validation_policy',
				['data_sensitivity_summary', 'data_sensitivity_summary.policy_metadata'],
				['table_schema', 'table_repository', 'table_record', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Apply app-owned access, redaction, storage, validation, exposure, and focused negative checks for sensitive field categories.',
				[
					'categories'=>array_values(array_map('strval', is_array($data_sensitivity_summary['categories'] ?? null) ? $data_sensitivity_summary['categories'] : [])),
					'highest_sensitivity_level'=>(string)($data_sensitivity_summary['policy_metadata']['highest_sensitivity_level'] ?? ''),
				]
			);
		}
		if(($tenant_identity_handoff['status'] ?? null)==='ready_for_app_owned_tenant_identity_design' || isset($obligation_ids['tenant_identity'])){
			$items[]=$this->app_builder_implementation_matrix_item(
				'tenant_identity',
				'apply_tenant_actor_permission_and_entitlement_enforcement',
				['tenant_identity_handoff', 'access_control_handoff', 'app_contract_summary', 'business_policy_summary'],
				['table_schema', 'table_repository', 'panel_resource', 'panel_manifest', 'panel_regression_manifest', 'api_endpoint_handler', 'api_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Implement app-owned tenant/workspace scope, authenticated actor context, permission/visibility rules, and plan/entitlement/quota gates before rendering, mutating, exporting, or notifying.',
				[
					'tenant_scope_fields'=>array_values(array_map('strval', is_array($tenant_identity_handoff['tenant_scope']['fields'] ?? null) ? $tenant_identity_handoff['tenant_scope']['fields'] : [])),
					'ownership_fields'=>array_values(array_map('strval', is_array($tenant_identity_handoff['actor_identity']['ownership_fields'] ?? null) ? $tenant_identity_handoff['actor_identity']['ownership_fields'] : [])),
					'access_fields'=>array_values(array_map('strval', is_array($tenant_identity_handoff['actor_identity']['access_fields'] ?? null) ? $tenant_identity_handoff['actor_identity']['access_fields'] : [])),
					'billing_or_plan_fields'=>array_values(array_map('strval', is_array($tenant_identity_handoff['entitlement_context']['billing_or_plan_fields'] ?? null) ? $tenant_identity_handoff['entitlement_context']['billing_or_plan_fields'] : [])),
					'enforcement_order'=>array_values(array_map('strval', is_array($tenant_identity_handoff['enforcement_order'] ?? null) ? $tenant_identity_handoff['enforcement_order'] : [])),
				]
			);
		}
		$corporate_controls=$this->app_builder_corporate_control_matrix_signals($corporate_summaries);
		if($corporate_controls['source_summaries']!==[]){
			$items[]=$this->app_builder_implementation_matrix_item(
				'app_owned_corporate_controls',
				'apply_existing_policy_summary_controls',
				$corporate_controls['source_summaries'],
				['table_schema', 'table_repository', 'panel_resource', 'panel_manifest', 'panel_regression_manifest'],
				$paths_by_kind,
				$verification_tools,
				'Apply the active lifecycle, audit/retention, access, reliability, support, change, integration, business, process, reporting, and notification controls already summarized in builder_response as app-owned schema, repository, Panel, and focused-test edits.',
				[
					'active_summary_count'=>count($corporate_controls['source_summaries']),
					'active_signal_keys'=>$corporate_controls['signal_keys'],
				]
			);
		}
		return [
			'owner'=>'consuming_application',
			'purpose'=>'Maps existing app-owned obligations to write targets and focused proof so agents can implement without rereading every summary or opening governance context.',
			'status'=>$items===[] ? 'no_app_owned_obligation_matrix_needed' : 'app_owned_obligations_ready',
			'work_items'=>$items,
			'write_order_source'=>'builder_response.write_plan_summary.write_order',
			'verification_source'=>'builder_response.verification_todo',
			'ready_to_write'=>($prewrite_checklist['ready_to_write'] ?? false)===true,
			'not_required'=>array_values(array_unique(array_merge(
				is_array($write_plan_summary['not_required'] ?? null) ? $write_plan_summary['not_required'] : [],
				[
					'governance context for ordinary app implementation sequencing',
					'MCP/release-surface validation for ordinary app-owned obligation mapping',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				]
			))),
		];
	}

	/**
	 * Identifies active corporate app-owned summaries for implementation mapping.
	 *
	 * @param array<string,array<string,mixed>> $summaries Summary payloads keyed by builder_response field.
	 * @return array{source_summaries:array<int,string>,signal_keys:array<int,string>}
	 */
	private function app_builder_corporate_control_matrix_signals(array $summaries): array {
		$signal_map=[
			'lifecycle_policy_summary'=>'has_lifecycle_fields',
			'audit_retention_summary'=>'has_audit_retention_fields',
			'access_control_summary'=>'has_access_control_fields',
			'operational_reliability_summary'=>'has_operational_reliability_signals',
			'support_observability_summary'=>'has_support_observability_signals',
			'change_management_summary'=>'has_change_management_signals',
			'integration_boundary_summary'=>'has_integration_boundary_signals',
			'business_policy_summary'=>'has_business_policy_signals',
			'process_policy_summary'=>'has_process_policy_signals',
			'reporting_analytics_summary'=>'has_reporting_analytics_signals',
			'notification_communication_summary'=>'has_notification_communication_signals',
		];
		$source_summaries=[];
		$signal_keys=[];
		foreach($signal_map as $summary_name=>$signal_key){
			$summary=is_array($summaries[$summary_name] ?? null) ? $summaries[$summary_name] : [];
			if(($summary[$signal_key] ?? false)!==true){
				continue;
			}
			$source_summaries[]=$summary_name;
			$signal_keys[]=$signal_key;
		}
		return [
			'source_summaries'=>$source_summaries,
			'signal_keys'=>$signal_keys,
		];
	}

	/**
	 * Builds one implementation matrix work item.
	 *
	 * @param string $id Work item id.
	 * @param string $status Work item status.
	 * @param array<int,string> $source_summaries Source summary names.
	 * @param array<int,string> $skeleton_kinds Skeleton groups to inspect.
	 * @param array<string,mixed> $paths_by_kind Skeleton path groups.
	 * @param array<int,string> $verification_tools Focused verification tools.
	 * @param string $action Work item action.
	 * @param array<string,mixed> $signals Compact signals.
	 * @return array<string,mixed> Matrix work item.
	 */
	private function app_builder_implementation_matrix_item(string $id, string $status, array $source_summaries, array $skeleton_kinds, array $paths_by_kind, array $verification_tools, string $action, array $signals=[]): array {
		$paths=[];
		foreach($skeleton_kinds as $kind){
			foreach(is_array($paths_by_kind[$kind] ?? null) ? $paths_by_kind[$kind] : [] as $path){
				$paths[]=(string)$path;
			}
		}
		return [
			'id'=>$id,
			'status'=>$status,
			'action'=>$action,
			'source_summaries'=>$source_summaries,
			'skeleton_kinds'=>$skeleton_kinds,
			'paths'=>array_values(array_unique(array_filter($paths, static fn(string $path): bool => $path!==''))),
			'verification_tools'=>$verification_tools,
			'signals'=>$signals,
		];
	}

	/**
	 * Builds a compact copy-forward object for the next app-owned write step.
	 *
	 * @param array<string,mixed> $builder_plan Full builder-plan payload.
	 * @return array<string,mixed> Resume-friendly write handoff.
	 */
	private function app_builder_write_handoff(array $builder_plan): array {
		$write_readiness=is_array($builder_plan['write_readiness'] ?? null) ? $builder_plan['write_readiness'] : [];
		$write_plan=is_array($builder_plan['write_plan_summary'] ?? null) ? $builder_plan['write_plan_summary'] : [];
		$code_summary=is_array($builder_plan['code_skeleton_summary'] ?? null) ? $builder_plan['code_skeleton_summary'] : [];
		$data_sensitivity=is_array($builder_plan['data_sensitivity_summary'] ?? null) ? $builder_plan['data_sensitivity_summary'] : [];
		$policy_metadata=is_array($data_sensitivity['policy_metadata'] ?? null) ? $data_sensitivity['policy_metadata'] : [];
		$first_batch=null;
		foreach(is_array($write_plan['write_order'] ?? null) ? $write_plan['write_order'] : [] as $batch){
			if(!is_array($batch)){
				continue;
			}
			$paths=is_array($batch['paths'] ?? null) ? array_values(array_filter(array_map('strval', $batch['paths']), static fn(string $path): bool => $path!=='')) : [];
			$tools=is_array($batch['tools'] ?? null) ? array_values(array_filter(array_map('strval', $batch['tools']), static fn(string $tool): bool => $tool!=='')) : [];
			if($paths===[] && $tools===[] && ($batch['concern'] ?? null)!=='inspect_local_conventions'){
				continue;
			}
			$first_batch=[
				'concern'=>(string)($batch['concern'] ?? 'inspect_local_conventions'),
				'source'=>(string)($batch['source'] ?? ''),
			];
			if($paths!==[]){
				$first_batch['paths']=$paths;
			}
			if($tools!==[]){
				$first_batch['tools']=$tools;
			}
			if(($batch['concern'] ?? null)==='inspect_local_conventions' && is_array($batch['probe'] ?? null)){
				$first_batch['probe']=$batch['probe'];
			}
			break;
		}
		$can_write_now=($write_readiness['ready_for_app_owned_writes'] ?? false)===true;
		return [
			'owner'=>'consuming_application',
			'status'=>(string)($write_readiness['status'] ?? 'inspect_builder_plan'),
			'ready_for_app_owned_writes'=>$can_write_now,
			'first_batch'=>$first_batch,
			'write_start_contract'=>[
				'can_write_now'=>$can_write_now,
				'decision_source'=>'builder_response.write_readiness',
				'first_batch_source'=>'builder_response.write_handoff.first_batch',
				'first_batch_concern'=>is_array($first_batch) ? (string)($first_batch['concern'] ?? '') : '',
				'write_queue'=>'builder_response.implementation_recipe.items',
				'local_convention_probe'=>'builder_response.local_convention_probe.items',
				'open_full_skeletons'=>'dataphyre_app_builder_plan_generate payload_profile=full',
				'after_write_evidence'=>[
					'builder_response.verification_handoff.post_write_handoff_template',
					'builder_response.acceptance_review_plan.post_write_handoff_template',
				],
				'if_blocked_read'=>$write_readiness['write_start_contract']['if_blocked_read'] ?? ($can_write_now ? null : 'builder_response.write_readiness.first_blocker'),
			],
			'open_full_skeletons_when'=>'Rerun dataphyre_app_builder_plan_generate with payload_profile=full only when ready to adapt app-owned code skeleton bodies.',
			'skeleton_write_order'=>array_values(array_map('strval', is_array($code_summary['write_order'] ?? null) ? $code_summary['write_order'] : [])),
			'handoff_fields'=>[
				'write_handoff',
				'write_readiness',
				'surface_execution_plan',
				'local_convention_probe',
				'write_plan_summary',
				'implementation_matrix',
				'implementation_recipe',
				'code_skeleton_summary.paths_by_kind',
				'app_contract_summary',
				'relationship_contract_summary',
				'relationship_adapter_handoff',
				'field_metadata_summary',
				'data_model_handoff',
				'data_integrity_summary',
				'lifecycle_policy_summary',
				'lifecycle_state_handoff',
				'audit_retention_summary',
				'audit_retention_handoff',
				'access_control_summary',
				'access_control_handoff',
				'operational_reliability_summary',
				'operational_reliability_handoff',
				'support_observability_summary',
				'support_observability_handoff',
				'change_management_summary',
				'change_management_handoff',
				'integration_boundary_summary',
				'integration_boundary_handoff',
				'tenant_identity_handoff',
				'business_policy_summary',
				'process_policy_summary',
				'domain_workflow_handoff',
				'reporting_analytics_summary',
				'reporting_analytics_handoff',
				'notification_communication_summary',
				'notification_communication_handoff',
				'data_sensitivity_summary',
				'data_sensitivity_summary.policy_metadata',
				'policy_decision_register',
				'prewrite_checklist.implementation_obligations',
				'prewrite_checklist.prewrite_reminders',
				'verification_handoff',
				'verification_handoff.post_write_handoff_template',
				'verification_execution_plan',
				'verification_fixture_handoff',
				'acceptance_review_plan',
				'acceptance_review_plan.post_write_handoff_template',
				'verification_recovery_plan',
				'apply_audit_handoff',
			],
			'policy_metadata'=>$policy_metadata,
			'apply_audit_handoff'=>[
				'tool'=>'dataphyre_apply_audit_plan',
				'when'=>'After write_readiness.status=ready_for_app_owned_writes and before any caller-owned write-capable apply workflow.',
				'ready_now'=>($write_readiness['status'] ?? null)==='ready_for_app_owned_writes',
				'arguments'=>[
					'task'=>'<same app-building task>',
					'proposed_files'=>'builder_response.files or builder_response.write_plan_summary',
					'verification'=>'builder_response.verification_evidence tool names and concrete app-owned paths',
				],
				'decision_field'=>'apply_next_action.status',
				'not_required'=>[
					'maintainer release gate for ordinary app-owned files',
					'dataphyre_mcp_verify_all for ordinary application behavior',
					'Dataphyre runtime-internal review when writes stay app-owned',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			],
			'after_write'=>'Run focused app/module checks from verification_evidence, fill verification_handoff.post_write_handoff_template and acceptance_review_plan.post_write_handoff_template, and share copy-safe completion evidence rather than raw logs or maintainer release proof.',
			'not_required'=>is_array($write_readiness['not_required'] ?? null) ? $write_readiness['not_required'] : [],
		];
	}
}
