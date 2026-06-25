<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Exposes MCP readiness report surfaces for client and publication checks.
 */
trait dataphyre_mcp_client_readiness_surfaces {

	/**
	 * Reports overall MCP server readiness, safety posture, and coverage status.
	 *
	 * @return array MCP readiness report payload.
	 */
	private function mcp_readiness_report(): array {
		$tools=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		$prompts=array_map(static fn(array $prompt): string => (string)($prompt['name'] ?? ''), $this->list_prompts()['prompts']);
		$resources=array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $this->list_resources()['resources']);
		$skills=array_keys($this->mcp_skill_definitions());
		$coverage=[
			'application_intelligence'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_application_info', 'dataphyre_application_catalog', 'dataphyre_package_metadata_read', 'dataphyre_source_api_summary', 'dataphyre_module_describe', 'dataphyre_module_dependency_map', 'dataphyre_runtime_version_summary'],
				'remaining'=>[],
			],
			'api_and_openapi'=>[
				'status'=>'implemented_static',
				'tools'=>['dataphyre_api_docs_static_summary', 'dataphyre_source_api_summary', 'dataphyre_api_scaffold_plan', 'dataphyre_api_recipe_catalog', 'dataphyre_api_cache_static_summary', 'dataphyre_openapi_static_contract_summary', 'dataphyre_openapi_runtime_readiness_plan'],
				'remaining'=>['unsafe-gated runtime OpenAPI document reader only after safe application bootstrap boundaries are enforceable'],
			],
			'documentation_api'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_search_docs', 'dataphyre_read_doc', 'dataphyre_module_docs_pack', 'dataphyre_docs_chunks_export', 'dataphyre_docs_index_plan', 'dataphyre_embeddings_readiness_plan', 'dataphyre_remote_docs_readiness_plan', 'dataphyre_datadoc_static_summary', 'dataphyre_datadoc_runtime_readiness_plan'],
				'resources'=>['dataphyre://module-index', 'dataphyre://runtime-readme', 'dataphyre://mcp-plan', 'dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://mcp-capabilities'],
				'remaining'=>['client-owned embeddings adapter only after embeddings readiness contract is enforceable', 'unsafe-gated remote documentation fetcher only after remote docs readiness contract is enforceable', 'unsafe-gated Datadoc SQL-backed reader only after Datadoc runtime readiness contract is enforceable'],
			],
			'routes_and_urls'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_list_routes', 'dataphyre_route_manifest_read', 'dataphyre_route_url_preview', 'dataphyre_route_match_preview', 'dataphyre_route_source_static_summary', 'dataphyre_route_source_ambiguity_report', 'dataphyre_route_runtime_provenance_plan', 'dataphyre_controller_source_summary', 'dataphyre_middleware_source_summary', 'dataphyre_mvc_config_static_summary', 'dataphyre_mvc_route_cache_summary'],
				'remaining'=>['unsafe-gated runtime route provenance reader only after safe application bootstrap boundaries are enforceable'],
			],
			'database_and_schema'=>[
				'status'=>'implemented_read_only',
				'tools'=>['dataphyre_sql_tables_list', 'dataphyre_sql_schema_read', 'dataphyre_sql_clusters_list', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract', 'dataphyre_sql_runtime_readiness_plan'],
				'remaining'=>['unsafe-gated read-only query execution only after a runtime DB adapter can enforce the readiness contract without exposing credentials'],
			],
			'config_and_security'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_list_config_keys', 'dataphyre_config_shape_read', 'dataphyre_config_value_preview', 'dataphyre_storage_config_summary', 'dataphyre_storage_driver_catalog', 'dataphyre_mcp_safety_boundary_report'],
				'remaining'=>[],
			],
			'diagnostics'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error', 'dataphyre_browser_diagnostics_readiness_plan', 'dataphyre_flightdeck_surfaces_list', 'dataphyre_unit_tests_list', 'dataphyre_unit_test_manifest_read', 'dataphyre_browser_regression_manifest_summary', 'dataphyre_verification_surface_catalog', 'dataphyre_mcp_doctor'],
				'remaining'=>['unsafe-gated external browser runner only after browser diagnostics readiness contract is enforceable'],
			],
			'code_generation_helpers'=>[
				'status'=>'dry_run_implemented',
				'tools'=>['dataphyre_app_builder_plan_generate', 'dataphyre_scaffold_plan_generate', 'dataphyre_api_recipe_catalog', 'dataphyre_panel_scaffold_catalog', 'dataphyre_panel_package_manifest_summary', 'dataphyre_panel_theme_manifest_summary', 'dataphyre_panel_documentation_catalog_summary', 'dataphyre_panel_media_manifest_summary', 'dataphyre_task_pack_generate', 'dataphyre_apply_audit_plan', 'dataphyre_apply_runtime_readiness_plan'],
				'remaining'=>['unsafe-gated apply workflow only after apply runtime readiness contract is enforceable by the runner'],
			],
			'agent_context'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_agent_context_generate', 'dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_install_checklist', 'dataphyre_mcp_client_config_install_plan', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_troubleshoot', 'dataphyre_mcp_client_compatibility_matrix', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_surface_changelog', 'dataphyre_mcp_tool_call_examples_export', 'dataphyre_mcp_workflow_playbook_export', 'dataphyre_mcp_workflow_readiness_audit', 'dataphyre_mcp_workflow_session_export', 'dataphyre_mcp_workflow_transcript_schema_export', 'dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_summary_export', 'dataphyre_mcp_workflow_state_transition_export', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_timeline_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_transcript_audit', 'dataphyre_mcp_workflow_transcript_summary_export', 'dataphyre_mcp_workflow_checkpoint_export', 'dataphyre_mcp_workflow_handoff_pack_export', 'dataphyre_mcp_workflow_catalog', 'dataphyre_mcp_workflow_lifecycle_export', 'dataphyre_mcp_workflow_next_action_export', 'dataphyre_mcp_workflow_recommend', 'dataphyre_mcp_workflow_recommendation_handoff_export', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder', 'dataphyre_mcp_readiness_report'],
				'prompts'=>['dataphyre_feature_plan', 'dataphyre_debug_triage', 'dataphyre_panel_workflow', 'dataphyre_runtime_guidelines', 'dataphyre_release_triage', 'dataphyre_sql_schema_workflow', 'dataphyre_route_manifest_workflow', 'dataphyre_diagnostics_workflow'],
				'remaining'=>['unsafe-gated client config file writer only after config install plan is enforceable'],
			],
			'skill_registration'=>[
				'status'=>'implemented_read_only',
				'tools'=>['dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan'],
				'skills'=>$skills,
				'remaining'=>['unsafe-gated skill file writer only after skill file install plan is enforceable'],
			],
			'verification'=>[
				'status'=>'implemented',
				'tools'=>['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check', 'dataphyre_verification_surface_catalog'],
				'remaining'=>['additional executable adapters only after safe route-free boundaries are explicit'],
			],
			'publication_validation'=>[
				'status'=>'implemented',
				'audience'=>'maintainers_and_release_surface_claims',
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'tools'=>['dataphyre_release_check', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor', 'dataphyre_mcp_docs_coverage_report'],
				'remaining'=>[],
			],
		];
		foreach($coverage as $area=>$details){
			$missing_tools=array_values(array_diff($details['tools'] ?? [], $tools));
			$missing_prompts=array_values(array_diff($details['prompts'] ?? [], $prompts));
			$missing_resources=array_values(array_diff($details['resources'] ?? [], $resources));
			$missing_skills=array_values(array_diff($details['skills'] ?? [], $skills));
			$coverage[$area]['ready']=$missing_tools===[] && $missing_prompts===[] && $missing_resources===[] && $missing_skills===[];
			$coverage[$area]['missing_registered_surfaces']=array_filter([
				'tools'=>$missing_tools,
				'prompts'=>$missing_prompts,
				'resources'=>$missing_resources,
				'skills'=>$missing_skills,
			]);
		}
		$enterprise_gates=[
			'agentic_contract_resource'=>[
				'status'=>in_array('dataphyre://agentic-enterprise', $resources, true) ? 'ready' : 'missing',
				'evidence'=>'dataphyre://agentic-enterprise',
				'purpose'=>'Ground agent-first corporate claims in the shared Dataphyre contract.',
			],
			'enterprise_adoption_audit'=>[
				'status'=>in_array('dataphyre_mcp_enterprise_adoption_audit', $tools, true) ? 'ready' : 'missing',
				'evidence'=>'dataphyre_mcp_enterprise_adoption_audit',
				'purpose'=>'Check extension boundaries, proof, portability, and release-facing evidence before enterprise claims.',
			],
			'extension_strategy'=>[
				'status'=>in_array('dataphyre_mcp_enterprise_adoption_audit', $tools, true) ? 'ready' : 'missing',
				'evidence'=>'enterprise audit extension_strategy',
				'purpose'=>'Prefer config, dialbacks/callbacks, plugins, local MCP metadata, and reusable module contracts before runtime-internal edits for one app.',
			],
			'workflow_continuity'=>[
				'status'=>in_array('dataphyre_mcp_workflow_state_audit', $tools, true) && in_array('dataphyre_mcp_workflow_transcript_audit', $tools, true) ? 'ready' : 'missing',
				'evidence'=>['dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_transcript_audit'],
				'purpose'=>'Let corporate agent workflows hand off safely without raw, unbounded transcripts or unsafe state.',
			],
			'release_verification'=>[
				'status'=>in_array('dataphyre_mcp_verify_all', $tools, true) && in_array('dataphyre_mcp_docs_coverage_report', $tools, true) ? 'ready' : 'missing',
				'evidence'=>['dataphyre_mcp_verify_all', 'dataphyre_mcp_docs_coverage_report'],
				'purpose'=>'Tie public claims to route-free validation, docs coverage, doctor checks, and app-coupling guardrails.',
			],
			'hot_path_benchmark_policy'=>[
				'status'=>'documented',
				'evidence'=>'maintainer/source-checkout performance contract',
				'purpose'=>'Require benchmark proof for Dataphyre shared production hot-path changes without pushing that requirement onto applications.',
			],
			'governance_baseline'=>[
				'status'=>'documented',
				'evidence'=>'docs/AGENTIC_ENTERPRISE.md#governance-baseline',
				'purpose'=>'Make tenant boundaries, access policy, audit evidence, redaction, data classification, and verification ownership visible before corporate-ready claims.',
			],
		];
		$enterprise_ready=array_reduce(
			$enterprise_gates,
			static fn(bool $ready, array $gate): bool => $ready && in_array((string)$gate['status'], ['ready', 'documented'], true),
			true
		);
		$app_builder_required=[
			'tools'=>['dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder'],
			'prompts'=>['dataphyre_feature_plan', 'dataphyre_panel_workflow'],
			'resources'=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
				'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
				'dataphyre://module-index',
			],
			'skills'=>['dataphyre-app-builder'],
		];
		$app_builder_missing=[
			'tools'=>array_values(array_diff($app_builder_required['tools'], $tools)),
			'prompts'=>array_values(array_diff($app_builder_required['prompts'], $prompts)),
			'resources'=>array_values(array_diff($app_builder_required['resources'], $resources)),
			'skills'=>array_values(array_diff($app_builder_required['skills'], $skills)),
		];
		$app_builder_missing=array_filter($app_builder_missing, static fn(array $items): bool => $items!==[]);
		$apply_required_tools=['dataphyre_apply_audit_plan', 'dataphyre_apply_runtime_readiness_plan', 'dataphyre_mcp_enterprise_adoption_audit'];
		$apply_missing_tools=array_values(array_diff($apply_required_tools, $tools));
		$recommended_next_slices=[
			[
				'audience'=>'application_agents_building_apps',
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>['payload_profile'=>'compact'],
				'purpose'=>'Start ordinary app creation with builder_response.first_read: files/schema summaries, scaffold_completion_summary.next_continuation, write_readiness/prewrite blockers, verification_handoff, and next_detail_page. Open implementation, verification, controls, or governance detail only when the first read points there.',
			],
			[
				'audience'=>'application_agents_needing_context',
				'tool'=>'dataphyre_task_pack_generate',
				'arguments'=>['payload_profile'=>'builder'],
				'purpose'=>'Optional context only: add focused module docs and a ready prompt without inline publication guardrails.',
				'optional'=>true,
			],
			[
				'audience'=>'application_agents_needing_cold_start_or_handoff',
				'tool'=>'dataphyre_mcp_agent_brief_export',
				'purpose'=>'Optional compact cold-start or handoff context; use builder_first_read.next_detail_page, app_builder_next_action, and collapsed governance before opening broader workflow context.',
				'optional'=>true,
			],
			[
				'audience'=>'application_agents_needing_broader_workflow_context',
				'tool'=>'dataphyre_mcp_task_start_pack_export',
				'arguments'=>['payload_profile'=>'builder'],
				'purpose'=>'Optional broader bounded workflow context only after the compact builder plan or agent brief; use task-specific first views while full governance/detail stays collapsed.',
				'optional'=>true,
			],
			[
				'audience'=>'client_or_agent_setup',
				'tool'=>'dataphyre_mcp_skill_catalog',
				'purpose'=>'Use the dataphyre-app-builder skill for ordinary app work; keep runtime-guidelines for tasks that match escalation triggers.',
			],
		];
		$publication_next_slices=[
			[
				'audience'=>'publication_validation_not_ordinary_app_work',
				'tool'=>'dataphyre_mcp_verify_all',
				'purpose'=>'Use only before MCP/release-surface claims, published shared setup docs, release notes, or MCP server wiring changes.',
			],
		];
		$default_app_workflow=[
			'owner'=>'application_agents_building_apps',
			'policy'=>'One bounded app-builder lane for ordinary application work; open broader context only when the current step points there.',
			'steps'=>[
				[
					'id'=>'start_builder',
					'tool'=>'dataphyre_app_builder_plan_generate',
					'arguments'=>['payload_profile'=>'compact'],
					'read'=>'builder_response.first_read',
					'decision_field'=>'builder_response.first_read.next_action',
					'detail_decision_field'=>'builder_response.first_read.next_detail_page',
				],
				[
					'id'=>'continue_chunks',
					'when'=>'builder_response.scaffold_completion_summary.complete=false',
					'tool'=>'dataphyre_app_builder_plan_generate',
					'arguments_source'=>'builder_response.entity_planning.continuation_calls[*].arguments',
					'stop_when'=>'builder_response.scaffold_completion_summary.complete=true',
				],
				[
					'id'=>'resolve_prewrite_blockers',
					'when'=>'builder_response.write_readiness.status=resolve_prewrite_blockers',
					'read'=>'builder_response.prewrite_checklist.prewrite_blockers',
					'common_sources'=>['builder_response.app_path_context', 'builder_response.policy_decision_register', 'builder_response.detail_pagination'],
				],
				[
					'id'=>'prepare_app_owned_writes',
					'when'=>'builder_response.write_readiness.status=ready_for_app_owned_writes',
					'read'=>'builder_response.first_read.next_action.write_start_packet',
					'open_detail_only_if_needed'=>'dataphyre_app_builder_plan_generate payload_profile=full or detail_page=implementation',
				],
				[
					'id'=>'focused_verification',
					'when'=>'after app-owned writes',
					'read'=>'builder_response.verification_handoff',
					'detail_source'=>'builder_response.verification_execution_plan',
					'evidence_shape'=>'builder_response.verification_handoff.focused_completion_packet',
				],
				[
					'id'=>'done_review',
					'when'=>'after focused verification',
					'read'=>'builder_response.acceptance_review_plan.post_write_handoff_template',
					'copy_safe_output'=>'changed app-owned files, focused pass/fail summaries, acceptance results, and remaining app follow-ups',
				],
			],
			'optional_context'=>[
				'dataphyre_task_pack_generate payload_profile=builder',
				'dataphyre_mcp_agent_brief_export',
				'dataphyre_mcp_task_start_pack_export payload_profile=builder',
			],
			'not_default'=>[
				'dataphyre_mcp_verify_all',
				'source-checkout dev tools',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits for one application',
			],
		];
		return [
			'server'=>'dataphyre-mcp',
			'protocol'=>'2025-11-25',
			'generated_from'=>'live tool, prompt, resource, and skill registration',
			'default_safety'=>'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'server_entrypoint_contract'=>$this->mcp_server_entrypoint_contract(),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'tool_count'=>count($tools),
			'prompt_count'=>count($prompts),
			'resource_count'=>count($resources),
			'skill_count'=>count($skills),
			'package_release_boundary'=>[
				'ready'=>true,
				'boundary'=>$this->mcp_package_release_boundary(),
				'source_alignment'=>[
					'composer_extra_dataphyre_agent_boundary'=>'composer.json extra.dataphyre.agent-boundary',
					'release_manifest_boundary'=>'RELEASE_MANIFEST.json release_boundary',
					'release_manifest_schema'=>'docs/RELEASE_MANIFEST.schema.json $defs.release_boundary',
					'compact_mcp_surfaces'=>['dataphyre_mcp_manifest_export', 'dataphyre://mcp-capabilities', 'dataphyre_package_metadata_read'],
				],
				'policy'=>'Early MCP and package metadata expose the same compact app-agent boundary: app-builder compact first, focused app/module checks, app-owned extension points, escalation triggers, and non-ordinary app ceremony.',
			],
			'default_app_workflow'=>$default_app_workflow,
			'app_builder_readiness'=>[
				'ready'=>$app_builder_missing===[],
				'default_entrypoint'=>'dataphyre_app_builder_plan_generate',
				'secondary_context'=>'dataphyre_task_pack_generate payload_profile=builder',
				'secondary_context_budget'=>'Builder task packs cap focused docs at 8 chunks and keep raw continuation queues, copy-forward arrays, and handoff fields collapsed; use governance/profile detail only for explicit escalation context.',
				'compact_handoff'=>'dataphyre_mcp_agent_brief_export',
				'compact_budget_policy'=>'Agent briefs do not inline payload_budget or escalation-policy lists; use dataphyre_app_builder_plan_generate detail/full payloads when payload-budget or extension/escalation policy detail is the next decision.',
				'broader_start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'skill'=>'dataphyre-app-builder',
				'supported_scaffold_types'=>['panel_resource', 'routing_controller', 'api_endpoint', 'sql_table', 'mvc_controller', 'runtime_module'],
				'module_resources'=>$app_builder_required['resources'],
				'missing_registered_surfaces'=>$app_builder_missing,
				'ordinary_verification'=>'focused application or module checks',
				'chunking_contract'=>[
					'default_max_entities'=>4,
					'max_entities_cap'=>12,
					'planning_field'=>'entity_planning',
					'dependency_field'=>'entity_planning.dependency_summary',
					'continuation_policy'=>'Follow entity_planning.continuation_calls until deferred_entities is empty; continuation calls are executable planner calls and may carry chunk-scoped fields with field_scope=chunk_entities, application_path, app_namespace, payload_profile, and dependency_context for cross-chunk relationship stitching plus tenant/actor/entitlement policy_context.',
				],
				'app_path_context_contract'=>[
					'first_read'=>'builder_first_read.app_path_context and builder_response.app_path_context are present on direct app-builder plans and build-shaped start packs.',
					'placeholder_discovery'=>'When app_path_context.placeholder_mode=true, follow app_path_context.discovery_hint.next_tool=dataphyre_application_catalog for bounded app candidates, then supply repo-relative application_path and optional app_namespace back to the builder. Use dataphyre_application_info only when broader startup context is needed. When app_path_context.discovery_hint.status=concrete_app_path_not_found or invalid_application_path, use dataphyre_application_catalog to correct application_path and rerun the builder before writes.',
					'concrete_paths'=>'When application_path is supplied, app_path_context carries concrete app-owned Dataphyre root, Framework path, Panel resource namespace, and Framework namespace.',
					'invalid_paths'=>'application_path rejects absolute paths, URLs, and .. traversal; invalid input sets path_input_valid=false, discovery_hint.status=invalid_application_path, and replace_placeholders remains a prewrite blocker.',
					'namespace_hints'=>'app_namespace must be a valid PHP namespace such as App, AcmePortal, or Acme\\Portal; invalid input sets namespace_input_valid=false, discovery_hint.status=invalid_app_namespace, falls generated namespace hints back to App, and stays a prewrite blocker until corrected.',
					'policy'=>'Path discovery is ordinary app setup and stays lightweight; it does not require governance context, MCP/release-surface validation, or Dataphyre hot-path benchmark evidence.',
				],
				'entity_input_contract'=>[
					'first_read'=>'builder_response.entity_input_contract is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.entity_input_contract and app_builder_summary.entity_input_contract repeat explicit-vs-inferred entity source metadata, model_completeness, task_mentioned_entities, unmodeled_task_entities, blocking_unmodeled_task_entities, and should_confirm_partial_model for handoffs.',
					'policy'=>'Explicit entities and fields remain preferred when the agent knows the model; inferred prose entities are starter plans that should be confirmed or overridden before broad multi-resource writes. If explicit fields are partial but task text names additional domain resources, confirm blocking_unmodeled_task_entities or rerun with nested fields before broad writes; soft-covered policy/support concepts remain lightweight design notes.',
				],
				'field_metadata_contract'=>[
					'first_read'=>'builder_response.schema, builder_response.data_model_handoff, builder_plan.schema, and builder_plan.data_model[].schema_field_metadata preserve bounded field options, casts, required flags, relationship hints, and typed default metadata when supplied.',
					'input_forms'=>'Structured required, options, choices, enum, default, default_value, json/jsonb types, explicit relationship targets such as foreign_key_target, non-relationship markers such as not_foreign_key or foreign_key=false, unique/unique_with integrity hints, and phrase-style required/nullable/enum/default/foreign-key hints such as string required or enum active,maintenance,retired default active are accepted.',
					'policy'=>'Use field options/defaults, explicit relationship metadata, non-relationship identifier metadata, integrity hints, and json types for app-owned schema, validation, Panel controls, relationship adapters, and focused tests; do not edit Dataphyre internals for one app-specific field option set.',
				],
				'naming_contract'=>[
					'first_read'=>'builder_response.naming_contract is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.naming_contract and app_builder_summary.naming_contract repeat entity-to-artifact mappings for handoffs.',
					'policy'=>'Preserve PascalCase compound and enterprise acronym class names while using snake_case tables, Panel manifest paths, and route-free regression manifest names.',
				],
				'agent_workload_contract'=>[
					'first_read'=>'builder_response.first_read is the default app-builder surface; builder_response.first_read.next_detail_page names the one detail page to open next; builder_response.agent_workload remains available on direct app-builder plans and build-shaped start packs for overhead-budget details.',
					'phase_plan'=>'builder_response.agent_workload.phase_read_plan is the default app-agent sequence: first_pass, resolve_blockers, prepare_writes, focused_verification, done_review, then escalation only when explicitly triggered.',
					'compact_lane'=>'app_builder_lane.agent_workload and app_builder_summary.agent_workload repeat the overhead budget for broader start/task-pack contexts; compact agent briefs use builder_first_read and top-level refs only.',
					'policy'=>'Use agent_workload as the ordinary app overhead budget: read first-response fields, follow phase_read_plan, open code_skeletons or payload_profile=builder docs only when needed, and keep status/safety/enterprise/publication validation collapsed until explicitly requested for an escalation decision.',
				],
				'compact_detail_count_contract'=>[
					'first_read'=>'builder_response.compact_detail_policy.detail_counts is present on compact direct app-builder plans.',
					'purpose'=>'Treat detail_counts as a compact table of contents: counts plus detail refs for files, schema, implementation_recipe.items, verification_execution_plan.items, acceptance_review_plan.items, and collapsed_sections.',
					'policy'=>'Use detail_counts to decide which detail page to open next without scanning every inline section; open payload_profile=full only for the specific app-owned planning, implementation, verification, controls, or skeleton detail needed next.',
				],
				'optional_summary_compaction_contract'=>[
					'first_read'=>'Inactive optional enterprise summaries in builder_response use compact=true, status=not_triggered, and omit fields_by_category.',
					'active_summary_policy'=>'When a summary has task or field signals, keep controls, fields_by_category, policy, and verification_focus inline so implementation obligations stay actionable.',
					'policy'=>'Use compact inactive summaries to keep ordinary CRUD app agents lightweight; rerun with payload_profile=full only when a concern becomes relevant or code skeleton bodies are needed.',
				],
				'scaffold_completion_summary_contract'=>[
					'first_read'=>'builder_response.scaffold_completion_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.scaffold_completion_summary and app_builder_summary.scaffold_completion_summary repeat complete/incomplete chunk state for handoffs.',
					'next_continuation'=>'scaffold_completion_summary.next_continuation points to the next executable continuation call without duplicating full arguments.',
					'continuation_queue'=>'scaffold_completion_summary.continuation_queue lists every deferred chunk with tool, chunk, entities, field scope, dependency-context presence, and the entity_planning.continuation_calls[n].arguments pointer.',
					'policy'=>'Treat a scaffold as complete only when complete=true and deferred_entities is empty; otherwise follow entity_planning.continuation_calls without opening governance context.',
				],
				'surface_execution_plan_contract'=>[
					'first_read'=>'builder_response.surface_execution_plan is present on direct app-builder plans and names the primary surface, companion surface, ordered steps, companion_surface_handoff argument pointer, and companion_surface_handoff.endpoint_queue with follow_up_arguments for mixed Panel/API work.',
					'policy'=>'Use surface_execution_plan to finish entity chunks, plan companion API/routing surfaces from companion_surface_handoff.arguments and endpoint_queue follow_up_arguments, write app-owned files, and run focused verification without opening governance, release validation, or Dataphyre internals.',
				],
				'recovery_hints_contract'=>[
					'first_read'=>'builder_response.recovery_hints is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.recovery_hints and app_builder_summary.recovery_hints repeat the same ordinary recovery hints for cold-start, brief, and handoff payloads.',
					'policy'=>'Use placeholder replacement, focused Panel/SQL metadata, and redacted diagnostics for ordinary app recovery; keep MCP/release-surface validation out unless the task escalates.',
				],
				'diagnostic_handoff_hint_contract'=>[
					'first_read'=>'builder_response.diagnostic_handoff_hint is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.diagnostic_handoff_hint and app_builder_summary.diagnostic_handoff_hint repeat the focused-check failure branch for handoffs.',
					'policy'=>'Use dataphyre_diagnostics_last_error only after a focused app/module check fails, copy diagnostic_summary.copy_safe_evidence, and pair it with verification_handoff without raw logs, maintainer proof, aggregate MCP validation, or benchmark output.',
				],
				'verification_recovery_plan_contract'=>[
					'first_read'=>'builder_response.verification_recovery_plan maps each focused verification tool to copy-safe failure evidence, copy_safe_failure_handoff, likely app-owned fix scope, safe next reads, and the diagnostic_summary.copy_safe_evidence branch; failure_branch pointers use "branches where tool=<tool>" because branches is a numeric list.',
					'policy'=>'Use verification_recovery_plan after focused app-check failures; it keeps ordinary app recovery in app-owned files/config/tests and excludes governance, release validation, raw logs, and Dataphyre hot-path benchmark evidence.',
				],
				'verification_evidence_contract'=>[
					'first_read'=>'builder_response.verification_evidence is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.verification_evidence and app_builder_summary.verification_evidence repeat focused evidence items without publication validation.',
					'policy'=>'Capture command/tool name, concrete app-owned arguments, and pass/fail summaries for focused app/module checks; do not collect maintainer release proof for ordinary app work.',
				],
				'verification_handoff_contract'=>[
					'first_read'=>'builder_response.verification_handoff is present on direct app-builder plans and build-shaped start packs; post_write_handoff_template names the copy-safe completion fields to fill after app-owned writes, and focused_completion_packet defines the compact closure packet shape.',
					'compact_lane'=>'app_builder_lane.verification_handoff and app_builder_summary.verification_handoff repeat copy-safe completion evidence guidance, post_write_handoff_template, and focused_completion_packet for handoffs.',
					'completion_decision'=>'focused_completion_packet.completion_decision classifies ordinary app closure as ready_to_share, incomplete_missing_evidence, or failed_with_app_followups without opening release validation.',
					'policy'=>'Share tool, concrete app paths or arguments, pass/fail summary, failing check names, and app-owned follow-up edits in the focused completion packet; do not paste raw logs, secrets, tenant/customer identifiers, maintainer release proof, or Dataphyre benchmark output.',
				],
				'verification_execution_plan_contract'=>[
					'first_read'=>'builder_response.verification_execution_plan is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.verification_execution_plan and app_builder_summary.verification_execution_plan repeat ordered focused tool calls, concrete arguments, related recipe paths, and failure branches without opening release validation.',
					'policy'=>'Follow verification_execution_plan.items after app-owned writes and write_readiness blockers are resolved; it is ordinary app verification and excludes dataphyre_mcp_verify_all, source-checkout dev tools, release validation, and Dataphyre hot-path benchmarks.',
				],
				'local_convention_probe_contract'=>[
					'first_read'=>'builder_response.local_convention_probe is present on direct app-builder plans and build-shaped start packs; builder_response.first_read.next_action.write_start_packet.first_probe mirrors one bounded actionable probe with inspect_globs, signals, capture_fields, and apply_to while full local_convention_probe.items remains the detail source.',
					'compact_lane'=>'app_builder_lane.local_convention_probe and app_builder_summary.local_convention_probe repeat inspect_globs, signals, capture_fields, apply_to, and feeds for local app style discovery before app-owned writes.',
					'policy'=>'Use local_convention_probe.items to capture observed_patterns and style_decisions_to_apply before adapting implementation_recipe.items; it is application inspection guidance, not governance, release readiness, dataphyre_mcp_verify_all, or benchmark proof.',
				],
				'acceptance_review_plan_contract'=>[
					'first_read'=>'builder_response.acceptance_review_plan is present on direct app-builder plans and build-shaped start packs; post_write_handoff_template maps changed files, focused verification, acceptance results, copy-safe notes, and remaining app risk.',
					'compact_lane'=>'app_builder_lane.acceptance_review_plan and app_builder_summary.acceptance_review_plan repeat criterion-by-criterion done review evidence sources plus obligation_review_items without opening governance or release validation.',
					'policy'=>'Follow acceptance_review_plan.items, obligation_review_items, and post_write_handoff_template after implementation_recipe.items and verification_execution_plan.items; copy pass/fail acceptance evidence with verification_handoff for ordinary app work.',
				],
				'app_contract_summary_contract'=>[
					'first_read'=>'builder_response.app_contract_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.app_contract_summary and app_builder_summary.app_contract_summary repeat schema-aware ownership, scope, lifecycle, audit, relationship hints, and decision_prompts without inlining governance audits.',
					'policy'=>'Use the summary and decision_prompts for ordinary app-owned data and policy contracts; open enterprise audit only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
				],
				'data_sensitivity_summary_contract'=>[
					'first_read'=>'builder_response.data_sensitivity_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.data_sensitivity_summary and app_builder_summary.data_sensitivity_summary repeat schema-derived sensitivity hints without inlining governance audits.',
					'policy'=>'Use the summary for app-owned access, redaction, storage, validation, and focused checks; open enterprise/governance review only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
				],
				'policy_decision_register_contract'=>[
					'first_read'=>'builder_response.policy_decision_register is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.policy_decision_register and app_builder_summary.policy_decision_register repeat app-owned policy decisions before writes.',
					'policy'=>'Resolve ownership, tenant/workspace scope, lifecycle, audit, relationship, and sensitive-data decisions as application choices; open enterprise audit only when task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
				],
				'extension_boundary_summary_contract'=>[
					'first_read'=>'builder_response.extension_boundary_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.extension_boundary_summary and app_builder_summary.extension_boundary_summary repeat placement_decision and app_owned_extension_targets without inlining governance audits.',
					'placement_checklist'=>'builder_response.extension_boundary_summary.app_owned_placement_checklist maps ordinary behavior to application_code, configuration, dialbacks_callbacks, plugins, or application_adapter before any runtime-internal idea.',
					'policy'=>'Use placement_decision.runtime_internal_allowed=false, app_owned_extension_targets, and app_owned_placement_checklist for ordinary app work; choose app-owned layers first and escalate only with reusable Dataphyre framework evidence.',
				],
				'relationship_contract_summary_contract'=>[
					'first_read'=>'builder_response.relationship_contract_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.relationship_contract_summary and app_builder_summary.relationship_contract_summary repeat relationship target, external reference, and cross-chunk dependency hints.',
					'policy'=>'Use this summary for app-owned repository/query adapters, relation UI, external reference resolution, and focused relationship verification before opening governance context.',
				],
				'relationship_adapter_handoff_contract'=>[
					'first_read'=>'builder_response.relationship_adapter_handoff is present on direct app-builder plans and build-shaped start packs when relationships are inferred or supplied.',
					'compact_lane'=>'app_builder_lane.relationship_adapter_handoff and app_builder_summary.relationship_adapter_handoff repeat concrete app-owned adapter stems, panel_field_source values, repository_touchpoints, verification_focus items, and write touchpoints without inlining governance.',
					'policy'=>'Use relationship_adapter_handoff.adapters before wiring Panel relationship fields, filters, or relation UI; implement lookup, labels, empty states, permissions, and tenant/workspace constraints in app-owned repositories, callbacks, dialbacks, plugins, or adapters.',
				],
				'field_metadata_summary_contract'=>[
					'first_read'=>'builder_response.field_metadata_summary is present on direct app-builder plans and build-shaped start packs when field options/default hints exist.',
					'compact_lane'=>'app_builder_lane.field_metadata_summary and app_builder_summary.field_metadata_summary repeat bounded options/default obligations without opening governance context.',
					'policy'=>'Use this summary to preserve app-owned validation, Panel select controls, filters, defaults, and focused tests for supplied options/defaults.',
				],
				'data_model_handoff_contract'=>[
					'first_read'=>'builder_response.data_model_handoff is present on direct app-builder plans and build-shaped start packs when data-model artifacts are planned.',
					'compact_lane'=>'app_builder_lane.data_model_handoff and builder_view.data_model_handoff repeat app-owned TableSchema/repository/record artifact paths, casts, relationships, and schema_field_metadata without skeleton bodies; compact agent briefs expose counts and detail-pagination pointers instead of inlining data-model handoff detail.',
					'policy'=>'Use data_model_handoff for app-owned TableSchema, repository, and record adaptation; open full skeleton bodies only when ready to write app-owned code.',
				],
				'data_integrity_summary_contract'=>[
					'first_read'=>'builder_response.data_integrity_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.data_integrity_summary and app_builder_summary.data_integrity_summary repeat app-owned index, uniqueness, required-field, foreign-key, scope, and business-identifier hints without opening governance context.',
					'policy'=>'Use this summary to adapt app-owned TableSchema/migrations, repository uniqueness checks, tenant-scoped lookup indexes, and focused schema verification; it does not execute migrations or require MCP/release-surface validation for ordinary app work.',
				],
				'lifecycle_policy_summary_contract'=>[
					'first_read'=>'builder_response.lifecycle_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.lifecycle_policy_summary and app_builder_summary.lifecycle_policy_summary repeat status/stage/decision/priority defaults, terminal options, default-filter hints, transition actions, and focused negative-check guidance without opening governance context.',
					'policy'=>'Use this summary to implement app-owned lifecycle defaults, transition policy, Panel filters/actions, and focused tests in callbacks, dialbacks, plugins, or app adapters; do not add a Dataphyre runtime workflow engine for ordinary app status fields.',
				],
				'audit_retention_summary_contract'=>[
					'first_read'=>'builder_response.audit_retention_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.audit_retention_summary and app_builder_summary.audit_retention_summary repeat actor, approval, effective-date, retention, legal-hold, export, residency, and classification controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned actor provenance, approval mutations, hold/purge rules, export permissions, region/classification behavior, and focused negative checks; ordinary corporate-record fields do not require enterprise audit unless the task explicitly escalates.',
				],
				'access_control_summary_contract'=>[
					'first_read'=>'builder_response.access_control_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.access_control_summary and app_builder_summary.access_control_summary repeat tenant/workspace scope, ownership, actor, role/permission, visibility/classification, and relationship exposure controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned access policy, repository scopes, Panel/API visibility, relationship lookup permissions, and focused allow/deny checks; ordinary access fields do not require enterprise audit unless the task explicitly escalates.',
				],
				'operational_reliability_summary_contract'=>[
					'first_read'=>'builder_response.operational_reliability_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.operational_reliability_summary and app_builder_summary.operational_reliability_summary repeat idempotency, request-hash, retry/delivery, import/export, provider-reference, queue/job, webhook, and external side-effect controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned idempotency, retry, outbox/job, import/export, webhook, and reconciliation behavior with focused replay/failure checks; ordinary reliability fields do not require enterprise audit unless the task explicitly escalates.',
				],
				'support_observability_summary_contract'=>[
					'first_read'=>'builder_response.support_observability_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.support_observability_summary and app_builder_summary.support_observability_summary repeat support ticket, incident, severity/SLA, health, diagnostic evidence, alert, and copy-safe handoff controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned support triage, incident state, health status, alert acknowledgement, SLA escalation, and copy-safe diagnostic evidence with focused support/redaction checks; ordinary support fields do not require enterprise audit unless the task explicitly escalates.',
				],
				'change_management_summary_contract'=>[
					'first_read'=>'builder_response.change_management_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.change_management_summary and app_builder_summary.change_management_summary repeat feature flag, rollout wave, migration/backfill, rollback, versioning, compatibility, and change-approval controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned rollout, migration/backfill, rollback evidence, version compatibility, and feature-flag behavior with focused rollout/recovery checks; ordinary change fields do not require package release validation or enterprise audit unless the task explicitly escalates.',
				],
				'integration_boundary_summary_contract'=>[
					'first_read'=>'builder_response.integration_boundary_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.integration_boundary_summary and app_builder_summary.integration_boundary_summary repeat external-id, provider, webhook, sync, idempotency, retry/dead-letter, credential-reference, and reconciliation controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned external provider adapters, webhook ingestion, sync resume state, idempotent side effects, retry/dead-letter handling, credential references, and reconciliation with focused duplicate/replay/recovery checks; ordinary integration fields do not require package release validation or enterprise audit unless the task explicitly escalates.',
				],
				'tenant_identity_handoff_contract'=>[
					'first_read'=>'builder_response.tenant_identity_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.tenant_identity_handoff and app_builder_summary.tenant_identity_handoff repeat tenant scope, actor identity, permission, plan, entitlement, quota, enforcement order, fixture-case links, and negative checks without opening governance context.',
					'policy'=>'Use tenant_identity_handoff and builder_response.verification_fixture_handoff.tenant_identity_cases as the concrete app-owned SaaS boundary contract; ordinary tenant/identity app work does not require Dataphyre runtime tenant/identity engine edits, release validation, enterprise audit, or Dataphyre hot-path benchmark evidence unless the task escalates.',
				],
				'business_policy_summary_contract'=>[
					'first_read'=>'builder_response.business_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.business_policy_summary and app_builder_summary.business_policy_summary repeat entitlement, quota, eligibility, approval/delegation, exception/waiver, contract-term, and commercial-rule controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned entitlements, quotas, eligibility rules, approval/delegation, policy exceptions, waivers, contract terms, and commercial rules with focused allow/deny and override checks; ordinary business-policy fields do not require package release validation or enterprise audit unless the task explicitly escalates.',
				],
				'process_policy_summary_contract'=>[
					'first_read'=>'builder_response.process_policy_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.process_policy_summary and app_builder_summary.process_policy_summary repeat assignment, queue, handoff, SLA/deadline, escalation, dependency, and completion-evidence controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned assignments, queues, handoffs, SLA/deadline clocks, escalations, dependencies, and completion evidence with focused progression and negative-state checks; ordinary process fields do not require package release validation, global queue/scheduler changes, or enterprise audit unless the task explicitly escalates.',
				],
				'reporting_analytics_summary_contract'=>[
					'first_read'=>'builder_response.reporting_analytics_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.reporting_analytics_summary and app_builder_summary.reporting_analytics_summary repeat metric, dimension, snapshot, freshness, drilldown, dashboard-visibility, and report-export controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned KPIs, dimensions, snapshots, freshness, drilldowns, dashboard visibility, and report exports with focused calculation, scope, and export checks; ordinary reporting fields do not require package release validation, data warehouse setup, BI platform setup, or enterprise audit unless the task explicitly escalates.',
				],
				'reporting_analytics_handoff_contract'=>[
					'first_read'=>'builder_response.reporting_analytics_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.reporting_analytics_handoff and app_builder_summary.reporting_analytics_handoff repeat metric, dimension, snapshot, freshness, drilldown, dashboard-visibility, and report-export write order, negative checks, and calculation contracts without opening governance context.',
					'policy'=>'Use reporting_analytics_handoff as the concrete app-owned dashboard/reporting implementation contract; ordinary reporting work does not require Dataphyre analytics engine edits, BI/data warehouse setup, release validation, enterprise audit, or Dataphyre hot-path benchmark evidence unless the task escalates.',
				],
				'notification_communication_summary_contract'=>[
					'first_read'=>'builder_response.notification_communication_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.notification_communication_summary and app_builder_summary.notification_communication_summary repeat template, channel, recipient, preference, suppression/quiet-hour, delivery-receipt, and escalation-communication controls without opening governance context.',
					'policy'=>'Use this summary to implement app-owned notification templates, channel selection, recipient resolution, preferences, suppression windows, delivery receipts, and escalation communications with focused delivery and suppression checks; ordinary communication fields do not require package release validation, external provider setup, or enterprise audit unless the task explicitly escalates.',
				],
				'notification_communication_handoff_contract'=>[
					'first_read'=>'builder_response.notification_communication_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.notification_communication_handoff and app_builder_summary.notification_communication_handoff repeat template, channel/provider, recipient, preference, suppression/quiet-hour, delivery-receipt, and escalation fallback write order, negative checks, and delivery contracts without opening governance context.',
					'policy'=>'Use notification_communication_handoff as the concrete app-owned notification implementation contract; ordinary communication work does not require Dataphyre notification engine edits, external provider setup, release validation, enterprise audit, or Dataphyre hot-path benchmark evidence unless the task escalates.',
				],
				'code_skeleton_summary_contract'=>[
					'first_read'=>'builder_response.code_skeleton_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.code_skeleton_summary repeats kind groups, write_order, paths_by_kind, field_metadata_policy, and sensitive_field_policy categories/path_reasons without inlining skeleton bodies.',
					'policy'=>'Use the summary to sequence app-owned writes and identify which paths/fields need sensitive handling; open full builder_plan/code_skeletons when content previews, adaptation_notes, or per-skeleton sensitive_field_policy details are needed.',
				],
				'write_plan_summary_contract'=>[
					'first_read'=>'builder_response.write_plan_summary is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.write_plan_summary and app_builder_summary.write_plan_summary repeat app-owned write order by concern without skeleton bodies.',
					'policy'=>'Use the summary to write app-owned data model artifacts, Panel resources, manifests, regression manifests, and focused verification in order; open full code_skeletons for adaptation_notes before writing.',
				],
				'implementation_matrix_contract'=>[
					'first_read'=>'builder_response.implementation_matrix maps field metadata, relationship adapters, data integrity, app contract decisions, tenant_identity_handoff, sensitive-data obligations, and active corporate-control summaries to source summaries, skeleton groups, paths, and focused verification tools.',
					'policy'=>'Use implementation_matrix as the compact implementation checklist for ordinary app writes; it reorganizes existing app-owned obligations, tenant/actor/entitlement enforcement, and corporate-control summaries without requiring governance, release validation, or Dataphyre hot-path benchmark evidence.',
				],
				'implementation_recipe_contract'=>[
					'first_read'=>'builder_response.implementation_recipe maps app-owned skeleton paths to edit_tasks, obligation_ids, relationship adapter touchpoints, focused verification tools, and failure branches without inlining skeleton bodies.',
					'compact_lane'=>'app_builder_lane.implementation_recipe and app_builder_summary.implementation_recipe repeat the capped file-by-file edit queue for resume and handoff flows.',
					'policy'=>'Use implementation_recipe.items as the immediate file edit queue for ordinary app work; open full code_skeletons only when ready to copy and adapt app-owned skeleton bodies.',
				],
				'write_handoff_contract'=>[
					'first_read'=>'builder_response.write_handoff is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.write_handoff and builder_response.write_handoff repeat readiness status, first write batch, skeleton rerun hint, obligation context handoff fields, and after-write verification reminder; compact agent briefs expose pointers instead of inlining write-handoff detail.',
					'policy'=>'Copy write_handoff across resume/handoff boundaries when an agent is ready to perform app-owned edits; preserve app_contract_summary, relationship_contract_summary, relationship_adapter_handoff, implementation_recipe, field_metadata_summary, data_model_handoff, data_integrity_summary, lifecycle_policy_summary, lifecycle_state_handoff, audit_retention_summary, audit_retention_handoff, access_control_summary, access_control_handoff, operational_reliability_summary, operational_reliability_handoff, support_observability_summary, support_observability_handoff, change_management_summary, change_management_handoff, integration_boundary_summary, integration_boundary_handoff, tenant_identity_handoff, business_policy_summary, process_policy_summary, domain_workflow_handoff, reporting_analytics_summary, reporting_analytics_handoff, notification_communication_summary, notification_communication_handoff, data_sensitivity_summary, policy_decision_register, and prewrite reminders so implementation obligations stay actionable without opening governance. It stays in the ordinary app lane and excludes maintainer release proof, aggregate MCP validation, and Dataphyre hot-path benchmark evidence.',
				],
				'prewrite_checklist_contract'=>[
					'first_read'=>'builder_response.prewrite_checklist is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.prewrite_checklist and app_builder_summary.prewrite_checklist repeat hard gates such as confirm/chunk/placeholder checks as prewrite_blockers, elevated task-scoped sensitive-data checks as blockers only when task text asks for security/privacy/compliance/governance policy, app-owned relationship/field/app-contract/sensitive-field work as implementation_obligations, plus adaptation/app-owned/focused-verification items as prewrite_reminders and ready_to_write.',
					'resolution_plan'=>'builder_response.prewrite_checklist.resolution_plan maps blockers, implementation_obligations, and prewrite_reminders to resolution_sources plus acceptable_resolutions so agents can unblock app-owned writes without opening governance or maintainer validation.',
					'policy'=>'Use prewrite_blockers and ready_to_write immediately before writing app-owned files; unresolved placeholder paths stay blocked until application_path or concrete app-owned paths are supplied. Ordinary schema-derived sensitive fields stay in implementation_obligations for app-owned access/redaction/storage/validation work unless the task explicitly escalates security or governance behavior, then complete implementation_obligations and prewrite_reminders during app-owned edits using resolution_plan when useful. This is not a governance gate and does not require maintainer/release proof for ordinary app work.',
				],
				'write_readiness_contract'=>[
					'first_read'=>'builder_response.write_readiness is present on direct app-builder plans and build-shaped start packs.',
					'compact_lane'=>'app_builder_lane.write_readiness and app_builder_summary.write_readiness reduce scaffold completion plus prewrite blockers to continue_entity_chunks, resolve_prewrite_blockers, or ready_for_app_owned_writes while keeping verification_handoff.post_write_handoff_template and acceptance_review_plan.post_write_handoff_template visible; blocker_scope clarifies that prewrite_blockers gate app-owned writes, not read-only planning continuations.',
					'policy'=>'Use write_readiness as the immediate app-owned write decision; it does not open governance, MCP/release-surface validation, or Dataphyre hot-path benchmark proof for ordinary app work.',
				],
				'apply_audit_handoff_contract'=>[
					'field'=>'app_builder_next_action.apply_audit_handoff',
					'tool'=>'dataphyre_apply_audit_plan',
					'also_available_on'=>'builder_response.write_handoff.apply_audit_handoff; compact agent briefs point to dataphyre_app_builder_plan_generate for the full bridge',
					'when'=>'After write_readiness.status=ready_for_app_owned_writes and before any caller-owned write-capable apply workflow.',
					'decision_field'=>'apply_next_action.status',
					'policy'=>'Use the apply audit bridge as write preflight for app-owned changes without turning ordinary app writes into maintainer release gates, dataphyre_mcp_verify_all, runtime-internal review, or Dataphyre benchmark proof.',
				],
				'compact_handoff_contract'=>[
					'start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder exposes builder_first_read, one compact builder_response, write_readiness, scaffold_completion_summary, verification_handoff, compact policy attention when needed, workflow_handoff summary with app_builder_next_action contract_collapsed=true, and context_policy links to the compact app-builder first call and builder docs instead of inlining builder_view, builder_start, app_builder_lane, raw handoff_fields, full/detail/deep profiles, duplicated app-builder next-action contracts, or every app-builder contract.',
					'task_pack'=>'dataphyre_task_pack_generate payload_profile=builder exposes builder_first_read, one compact builder_response, focused docs, optional scaffold guidance, and focused verification; it omits builder_view, builder_plan, app_builder_lane, and raw handoff_fields unless a caller opens the app-builder detail plan or governance profile.',
					'agent_brief'=>'dataphyre_mcp_agent_brief_export is the direct app-builder first-page fast lane: it exposes builder_first_read.next_action, builder_first_read.next_detail_page, shortened top-level app_builder_next_action refs, focused next_actions, context_links, and compact policy_attention for app-building tasks without wrapping the broader task start pack and without inlining builder_view/app_builder_lane/app_builder_summary, detail_pagination, payload_budget, full resume_cursor bodies, or escalation-policy lists; app_builder_summary is a broader start/task-pack context, not a field agents should expect on compact agent briefs.',
					'policy'=>'Cold-start and resume payloads surface the continuation hint without requiring deep workflow handoff or governance context.',
				],
				'escalate_only_for'=>$this->mcp_escalation_triggers(),
			],
			'apply_readiness'=>[
				'ready'=>$apply_missing_tools===[],
				'default_surface'=>'dataphyre_apply_audit_plan',
				'readiness_surface'=>'dataphyre_apply_runtime_readiness_plan',
				'escalation_surface'=>'dataphyre_mcp_enterprise_adoption_audit',
				'missing_registered_surfaces'=>['tools'=>$apply_missing_tools],
				'write_policy'=>'read_only_plan',
				'future_runner_status'=>'not_exposed',
				'next_action_contract'=>[
					'field'=>'apply_next_action',
					'ordinary_status'=>'use_app_owned_extension_point',
					'framework_status'=>'escalate_framework_change',
					'runtime_internal_allowed'=>false,
					'policy'=>'Use apply_next_action before any future write-capable runner; ordinary app changes stay in app-owned files or extension points, while Dataphyre runtime/MCP/dev/docs paths escalate before writes.',
				],
				'ordinary_app_policy'=>'Application agents should use app-owned code, config, callbacks, dialbacks, plugins, MCP metadata, or application-owned adapters before proposing Dataphyre runtime internals.',
				'not_ordinary_app_ceremony'=>[
					'unsafe MCP mode',
					'Dataphyre runtime-internal edits to make one application work',
					'dataphyre_mcp_verify_all for ordinary app behavior',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			],
			'agentic_capability_coverage'=>$coverage,
			'boost_parity_coverage'=>$coverage,
			'compatibility_aliases'=>[
				'boost_parity_coverage'=>'agentic_capability_coverage',
			],
			'agentic_enterprise_readiness'=>[
				'ready'=>$enterprise_ready,
				'gates'=>$enterprise_gates,
				'recommended_gate'=>'dataphyre_mcp_enterprise_adoption_audit',
				'benchmark_scope'=>'Dataphyre shared production hot paths only; application changes using Dataphyre do not need MCP-imposed microbenchmarks by default.',
				'governance_baseline'=>[
					'contract'=>'docs/AGENTIC_ENTERPRISE.md#governance-baseline',
					'checks'=>[
						'tenant_application_boundary',
						'access_permission_policy',
						'audit_trace_evidence',
						'redaction_data_classification',
						'framework_vs_application_verification',
					],
					'claim_rule'=>'If these boundaries are not inspectable yet, report missing evidence instead of calling the feature corporate-ready.',
				],
			],
			'app_first_verification_policy'=>[
				'default'=>'Application behavior uses focused application or module verification; MCP readiness is not proof of app behavior.',
				'local_client_setup'=>'Use config audit, smoke-test export, and live stdio validation for ordinary app-client setup.',
				'mcp_publication'=>'Use dataphyre_mcp_verify_all for MCP/release-surface claims, published shared MCP setup docs, release notes, or MCP server wiring changes.',
				'ordinary_app_exclusion'=>'Do not use dataphyre_mcp_verify_all, maintainer release proof, or Dataphyre benchmark output as ordinary application behavior evidence.',
				'hot_paths'=>'Use maintainer/source-checkout benchmark evidence only for Dataphyre shared production hot-path changes.',
				'next_action_contract'=>'dataphyre_verification_surface_catalog exposes verification_next_action to select run_bounded_mcp_wrapper, inspect_unit_manifest, triage_diagnostic_surface, or no_focused_surface_selected without turning discovery into release proof.',
			],
			'diagnostic_handoff_policy'=>[
				'default'=>'Share diagnostic_summary.copy_safe_evidence instead of raw Tracelog/log payloads.',
				'redaction_contract_ref'=>'dataphyre_mcp_safety_boundary_report.redaction_contract',
				'redaction_contract_summary'=>'Credential assignments, compound/camelCase secret keys, signed URLs, scoped tenant/customer identifiers, connection strings, private keys, and local paths are redacted or flagged before copy-safe handoff.',
				'available_on'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error'],
				'copy_safe_shape'=>[
					'surface',
					'owner',
					'share_default',
					'internal_share_default',
					'external_share_default',
					'safe_to_paste_externally',
					'handoff_status',
					'redaction',
					'finding',
					'evidence',
					'next_reads',
					'diagnostic_next_action',
					'copy_fields',
					'not_included',
				],
				'next_action_contract'=>'diagnostic_summary.diagnostic_next_action reduces redacted evidence to inspect_redacted_artifact, inspect_redacted_matches, triage_redacted_error, or broaden_bounded_diagnostic_search without raw log sharing.',
				'handoff_template'=>'diagnostic_summary.copy_safe_evidence with handoff_status=copy_safe_summary_ready',
				'not_included'=>[
					'raw full logs',
					'unredacted snippets',
					'secrets, tokens, cookies, auth headers, signed URLs, or connection strings',
					'tenant names, product identifiers, local usernames, or machine-local absolute paths',
				],
				'escalate_only_for'=>[
					'security, credential, tenant, privacy, compliance, or billing-sensitive diagnostics',
					'corporate-ready, public, release-facing, or Dataphyre framework claims',
					'requests to execute browser, route, SQL, or external-service diagnostics',
				],
			],
			'agent_workload_policy'=>[
				'goal'=>'Enterprise safety is progressive disclosure, not mandatory ceremony for every app edit.',
				'default_lane'=>'single-purpose first-read payloads',
				'ordinary_app_start'=>'Call dataphyre_app_builder_plan_generate with payload_profile=compact first for build-shaped app work; add task packs, start packs, compact briefs, readiness, or enterprise audit only when the task needs that context.',
				'inline_for_ordinary_app_work'=>[
					'files/schema/Panel fields/filters/actions',
					'scaffold_completion_summary',
					'app_contract_summary and data_sensitivity_summary',
					'policy_decision_register',
					'prewrite_checklist',
					'prewrite_checklist.prewrite_blockers',
					'prewrite_checklist.implementation_obligations',
					'prewrite_checklist.prewrite_reminders',
					'prewrite_checklist.ready_to_write',
					'verification_evidence and focused verification_plan',
					'verification_handoff copy-safe completion template',
				],
				'linked_or_collapsed_by_default'=>[
					'full application-agent contracts',
					'status board and safety boundary',
					'enterprise audit and governance baseline details',
					'full workflow handoff/session/transcript schemas',
					'MCP/release-surface publication validation',
				],
				'not_ordinary_app_ceremony'=>[
					'dataphyre_mcp_verify_all',
					'source-checkout dev tools',
					'Dataphyre hot-path benchmarks',
					'runtime-internal edits to make one application work',
				],
				'escalate_only_when'=>$this->mcp_escalation_triggers(),
			],
			'application_coupling_policy'=>[
				'status'=>'generic',
				'description'=>'The MCP module must not hardcode product-specific application names, paths, local PHP binaries, or server scripts.',
				'verification'=>'Search the MCP module and shared MCP tools for product-specific names before release.',
			],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('readiness_report'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('readiness_report'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'recommended_next_slices'=>$recommended_next_slices,
			'publication_next_slices'=>$publication_next_slices,
			'publication_next_action'=>$this->mcp_publication_next_action(['recommended_next_slices'=>$publication_next_slices]),
			'intentionally_not_exposed'=>[
				'SQL query execution',
				'route dispatch',
				'schema hydration',
				'config secret values',
				'app-specific local server scripts',
			],
		];
	}


}
