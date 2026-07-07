<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines Mcp kernel trait responsibilities for dataphyre mcp client surfaces.
 *
 * Mcp kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait dataphyre_mcp_client_surfaces {

	use dataphyre_mcp_client_workflow_surfaces;
	use dataphyre_mcp_client_enterprise_surfaces;
	use dataphyre_mcp_client_skill_surfaces;
	use dataphyre_mcp_client_setup_surfaces;
	use dataphyre_mcp_client_prompt_surfaces;

	/**
	 * Exports the client-facing MCP manifest.
	 *
	 * The payload includes tools, prompts, resources, and skill definitions, with
	 * optional input schemas and optional documentation resources. Documentation
	 * resources are hidden by default to keep client bootstrap manifests compact and
	 * avoid exposing large repo documentation catalogs unless explicitly requested.
	 *
	 * @param array{include_schemas?:bool,include_docs_resources?:bool} $args Export options controlling schema detail and documentation resource exposure.
	 * @return array<string, mixed> Manifest payload for MCP client setup and discovery.
	 */
	private function mcp_manifest_export(array $args): array {
		$include_schemas=($args['include_schemas'] ?? true)!==false;
		$include_docs_resources=($args['include_docs_resources'] ?? false)===true;
		$tools=$this->list_tools()['tools'];
		$prompts=$this->list_prompts()['prompts'];
		$resources=$this->list_resources()['resources'];
		$skills=array_values($this->mcp_skill_definitions());
		if(!$include_docs_resources){
			$resources=array_values(array_filter(
				$resources,
				static fn(array $resource): bool => !str_starts_with((string)($resource['uri'] ?? ''), 'dataphyre://doc/')
			));
		}
		$exported_tools=[];
		foreach($tools as $tool){
			$entry=[
				'name'=>(string)($tool['name'] ?? ''),
				'description'=>(string)($tool['description'] ?? ''),
			];
			if($include_schemas){
				$entry['inputSchema']=$tool['inputSchema'] ?? ['type'=>'object'];
			}else{
				$schema=$tool['inputSchema'] ?? [];
				$entry['required']=is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];
				$entry['argument_count']=is_array($schema['properties'] ?? null) ? count($schema['properties']) : 0;
			}
			$exported_tools[]=$entry;
		}
		$groups=[
			'application_intelligence'=>['dataphyre_application_info', 'dataphyre_application_catalog', 'dataphyre_package_metadata_read', 'dataphyre_source_api_summary', 'dataphyre_module_describe', 'dataphyre_module_dependency_map', 'dataphyre_runtime_version_summary'],
			'api_and_openapi'=>['dataphyre_api_docs_static_summary', 'dataphyre_api_scaffold_plan', 'dataphyre_api_recipe_catalog', 'dataphyre_api_cache_static_summary', 'dataphyre_openapi_static_contract_summary', 'dataphyre_openapi_runtime_readiness_plan'],
			'documentation'=>['dataphyre_search_docs', 'dataphyre_read_doc', 'dataphyre_module_docs_pack', 'dataphyre_docs_chunks_export', 'dataphyre_docs_index_plan', 'dataphyre_embeddings_readiness_plan', 'dataphyre_remote_docs_readiness_plan', 'dataphyre_datadoc_static_summary', 'dataphyre_datadoc_runtime_readiness_plan'],
			'routes'=>['dataphyre_list_routes', 'dataphyre_route_manifest_read', 'dataphyre_route_url_preview', 'dataphyre_route_match_preview', 'dataphyre_route_source_static_summary', 'dataphyre_route_source_ambiguity_report', 'dataphyre_route_runtime_provenance_plan', 'dataphyre_controller_source_summary', 'dataphyre_middleware_source_summary', 'dataphyre_mvc_config_static_summary', 'dataphyre_mvc_route_cache_summary'],
			'config_storage_sql'=>['dataphyre_list_config_keys', 'dataphyre_config_shape_read', 'dataphyre_config_value_preview', 'dataphyre_storage_config_summary', 'dataphyre_storage_driver_catalog', 'dataphyre_sql_tables_list', 'dataphyre_sql_schema_read', 'dataphyre_sql_clusters_list', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract', 'dataphyre_sql_runtime_readiness_plan'],
			'diagnostics'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error', 'dataphyre_browser_diagnostics_readiness_plan', 'dataphyre_flightdeck_surfaces_list', 'dataphyre_unit_tests_list', 'dataphyre_unit_test_manifest_read', 'dataphyre_browser_regression_manifest_summary', 'dataphyre_verification_surface_catalog'],
			'panel_helpers'=>['dataphyre_panel_scaffold_catalog', 'dataphyre_panel_package_manifest_summary', 'dataphyre_panel_theme_manifest_summary', 'dataphyre_panel_documentation_catalog_summary', 'dataphyre_panel_media_manifest_summary'],
			'agent_and_planning'=>['dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_agent_context_generate', 'dataphyre_scaffold_plan_generate', 'dataphyre_apply_audit_plan', 'dataphyre_apply_runtime_readiness_plan', 'dataphyre_mcp_manifest_export', 'dataphyre_prompt_pack_export', 'dataphyre_mcp_prompt_catalog', 'dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_install_checklist', 'dataphyre_mcp_client_config_install_plan', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_troubleshoot', 'dataphyre_mcp_client_compatibility_matrix', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_safety_boundary_report', 'dataphyre_mcp_status_board', 'dataphyre_mcp_enterprise_adoption_audit', 'dataphyre_mcp_capability_matrix', 'dataphyre_mcp_release_notes_generate', 'dataphyre_mcp_surface_changelog', 'dataphyre_mcp_tool_call_examples_export', 'dataphyre_mcp_workflow_playbook_export', 'dataphyre_mcp_workflow_readiness_audit', 'dataphyre_mcp_workflow_session_export', 'dataphyre_mcp_workflow_transcript_schema_export', 'dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_summary_export', 'dataphyre_mcp_workflow_state_transition_export', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_timeline_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_transcript_audit', 'dataphyre_mcp_workflow_transcript_summary_export', 'dataphyre_mcp_workflow_checkpoint_export', 'dataphyre_mcp_workflow_handoff_pack_export', 'dataphyre_mcp_workflow_catalog', 'dataphyre_mcp_workflow_lifecycle_export', 'dataphyre_mcp_workflow_next_action_export', 'dataphyre_mcp_workflow_recommend', 'dataphyre_mcp_workflow_recommendation_handoff_export', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder', 'dataphyre_mcp_readiness_report'],
			'skill_registration'=>['dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan'],
			'verification'=>['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check', 'dataphyre_verification_surface_catalog'],
			'publication_validation'=>['dataphyre_release_check', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor', 'dataphyre_mcp_docs_coverage_report'],
		];
		$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $tools);
		$grouped=[];
		foreach($groups as $group=>$names){
			$present=array_values(array_intersect($names, $tool_names));
			$grouped[$group]=[
				'count'=>count($present),
				'tools'=>$present,
				'audience_scope'=>$this->mcp_tool_group_audience_scope((string)$group),
				'tool_boundaries'=>$this->mcp_tool_boundary_map($present),
			];
		}
		return [
			'manifest_type'=>'dataphyre_mcp_manifest',
			'server'=>'dataphyre-mcp',
			'version'=>'2.0.3',
			'protocol'=>'2025-11-25',
			'generated_from'=>'live tool, prompt, and resource registration',
			'default_safety'=>'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'server_entrypoint_contract'=>$this->mcp_server_entrypoint_contract(),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'include_schemas'=>$include_schemas,
			'include_docs_resources'=>$include_docs_resources,
			'counts'=>[
				'tools'=>count($tools),
				'prompts'=>count($prompts),
				'resources'=>count($resources),
				'skills'=>count($skills),
			],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('manifest_export'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('manifest_export'),
			'tool_audience_boundaries'=>$this->mcp_tool_audience_boundaries($tool_names),
			'package_release_boundary'=>$this->mcp_package_release_boundary(),
			'tool_groups'=>$grouped,
			'tools'=>$exported_tools,
			'prompts'=>$prompts,
			'resources'=>$resources,
			'skills'=>array_values(array_map(static fn(array $skill): array => [
				'name'=>$skill['name'],
				'title'=>$skill['title'],
				'description'=>$skill['description'],
				'targets'=>$skill['targets'],
				'theme'=>$skill['theme'],
			], $skills)),
			'safety'=>[
				'read_only_by_default'=>true,
				'unsafe_flag'=>'--allow-unsafe or DATAPHYRE_MCP_ALLOW_UNSAFE=1',
				'app_coupling_policy'=>'No product-specific application names, paths, local PHP binaries, or server scripts in shared MCP code.',
				'intentionally_not_exposed'=>[
					'SQL query execution',
					'route dispatch',
					'schema hydration',
					'config secret values',
					'app-specific local server scripts',
				],
			],
		];
	}

	/**
	 * Returns the client-visible MCP entrypoint contract.
	 *
	 * @return array<string,string> Stdio server and module-bootstrap paths with policy notes.
	 */
	private function mcp_server_entrypoint_contract(): array {
		return [
			'stdio_server'=>'common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php',
			'module_bootstrap'=>'common/dataphyre/runtime/modules/mcp/kernel/mcp.main.php',
			'client_policy'=>'MCP stdio clients must launch dataphyre_mcp.php from the project root; mcp.main.php is the Dataphyre runtime module bootstrap and is not a stdio MCP server.',
			'validation_tool'=>'common/dataphyre/dev/tools/mcp_live_validate.php',
			'validation_policy'=>'The dev/tools validator is a maintainer/source-checkout fallback for MCP wiring checks, not ordinary application-agent release or app-behavior proof.',
		];
	}

	/**
	 * Summarizes client configuration guidance for a target MCP client.
	 *
	 * @param array{include_cwd?:bool,php_command?:string,allow_unsafe?:bool} $args Target client and output options.
	 * @return array Client configuration summary payload.
	 */
	private function mcp_client_config_summary(array $args): array {
		$include_cwd=($args['include_cwd'] ?? false)===true;
		$php_command=trim((string)($args['php_command'] ?? 'php'));
		if($php_command===''){
			$php_command='php';
		}
		$entrypoint_contract=$this->mcp_server_entrypoint_contract();
		$server=$entrypoint_contract['stdio_server'];
		$base_args=[$server];
		$unsafe_args=$base_args;
		$unsafe_args[]='--allow-unsafe';
		$base_config=[
			'mcpServers'=>[
				'dataphyre'=>[
					'command'=>$php_command,
					'args'=>$base_args,
				],
			],
		];
		$unsafe_config=[
			'mcpServers'=>[
				'dataphyre'=>[
					'command'=>$php_command,
					'args'=>(($args['allow_unsafe'] ?? false)===true ? $unsafe_args : $base_args),
				],
			],
		];
		if($include_cwd){
			$base_config['mcpServers']['dataphyre']['cwd']=$this->root;
			$unsafe_config['mcpServers']['dataphyre']['cwd']=$this->root;
		}
		return [
			'summary_type'=>'mcp_client_config',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'server'=>'dataphyre',
			'transport'=>'stdio',
			'protocol'=>'2025-11-25',
			'server_entrypoint_contract'=>$entrypoint_contract,
			'client_audience'=>$this->mcp_client_audience_contract('client_config_summary'),
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('client_config_summary'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('client_config_summary'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'client_setup_next_action'=>$this->mcp_client_setup_next_action('client_config_summary'),
			'app_after_setup_next_action'=>$this->mcp_client_app_after_setup_next_action('client_config_summary'),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'config_generator'=>[
				'scope'=>'maintainer/source-checkout helper only',
				'release_guidance'=>'Use manual_config in released installs; dev helpers are not release artifacts.',
				'app_agent_boundary'=>'Metadata only, not an app-agent checklist or ordinary application setup requirement.',
				'ordinary_app_action'=>'Use manual_config, client_setup_next_action, and app_after_setup_next_action; do not run source-checkout helper scripts for ordinary app work.',
				'default_tool'=>'dataphyre_mcp_client_config_summary',
				'unsafe_mode'=>'Use allow_unsafe=true only for a deliberate unsafe profile.',
			],
			'manual_config'=>$base_config,
			'unsafe_config_example'=>$unsafe_config,
			'environment'=>[
				'DATAPHYRE_MCP_ALLOW_UNSAFE'=>'Set to 1 to enable unsafe-gated tools when the server process is started intentionally for that purpose.',
				'DATAPHYRE_MCP_PHP_BINARY'=>'Optional PHP binary override used by MCP verification helpers that spawn PHP.',
			],
			'client_notes'=>[
				'Run the stdio server from the project root so repo-relative paths resolve correctly.',
				'Use common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php as the stdio server; common/dataphyre/runtime/modules/mcp/kernel/mcp.main.php is only the Dataphyre runtime module bootstrap.',
				'Keep command examples generic; do not commit product-local PHP paths into shared MCP docs or code.',
				'Use dataphyre_mcp_manifest_export to discover current tools, prompts, resources, schemas, and safety posture.',
				'Use dataphyre_prompt_pack_export for reusable workflow prompt bundles.',
			],
			'safety'=>[
				'default'=>'read_only',
				'unsafe_flag'=>'--allow-unsafe or DATAPHYRE_MCP_ALLOW_UNSAFE=1',
				'intentionally_not_exposed'=>[
					'SQL query execution',
					'route dispatch',
					'schema hydration',
					'config secret values',
					'app-specific local server scripts',
				],
			],
		];
	}

	/**
	 * Points application agents from completed MCP setup into the app-builder lane.
	 *
	 * @param string $surface Client setup surface.
	 * @return array<string,mixed> App-builder next action after setup.
	 */
	private function mcp_client_app_after_setup_next_action(string $surface): array {
		return [
			'surface'=>$surface,
			'status'=>'ready_for_ordinary_app_work_after_setup',
			'tool'=>'dataphyre_app_builder_plan_generate',
			'arguments'=>['task'=>'<describe the app feature to build>', 'payload_profile'=>'compact'],
			'first_read'=>'builder_response.first_read',
			'decision_fields'=>[
				'builder_response.first_read.next_action',
				'builder_response.first_read.next_detail_page',
				'builder_response.first_read.write_readiness',
			],
			'action'=>'After the local MCP client can initialize and list tools, start ordinary app work with the compact app-builder plan; read first_read, follow next_action, and open only the page named by next_detail_page.',
			'optional_context'=>[
				'dataphyre_task_pack_generate payload_profile=builder only when focused module docs are needed',
				'dataphyre_mcp_agent_brief_export only for compact handoff or cold-start direction',
			],
			'not_required'=>[
				'full manifest scraping before ordinary app work',
				'prompt pack export before ordinary app work',
				'dataphyre_mcp_verify_all for ordinary app behavior',
				'publication validation for ordinary app work',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths changed',
			],
		];
	}

	/**
	 * Reduces client setup payloads to one ordinary next action.
	 *
	 * @param string $surface Client setup surface.
	 * @param array<string,mixed> $context Surface-specific context.
	 * @return array<string,mixed> Client setup next action.
	 */
	private function mcp_client_setup_next_action(string $surface, array $context=[]): array {
		$target=(string)($context['target'] ?? 'generic');
		$audit_passed=$context['audit_passed'] ?? null;
		$base=[
			'owner'=>'client author or application agent configuring local MCP client',
			'surface'=>$surface,
			'target'=>$target,
			'handoff_fields'=>['client_setup_next_action', 'client_audience', 'tool_audience_boundaries', 'ordinary_app_work'],
			'not_required'=>[
				'dataphyre_mcp_verify_all for ordinary local client setup',
				'Dataphyre MCP publication evidence for routine app-client setup',
				'Dataphyre hot-path benchmark evidence',
				'Dataphyre runtime-internal edits',
			],
		];
		if($surface==='client_config_audit'){
			if($audit_passed===true){
				return $base+[
					'status'=>'run_smoke_test',
					'tool'=>'dataphyre_mcp_smoke_test_export',
					'arguments'=>['format'=>'all'],
					'action'=>'Use portable smoke fixtures; run live stdio validation only when local MCP wiring changed or the smoke frame does not prove the client can see the server.',
				];
			}
			return $base+[
				'status'=>'fix_config_and_reaudit',
				'tool'=>'dataphyre_mcp_client_config_summary',
				'arguments'=>['php_command'=>'php', 'allow_unsafe'=>false],
				'action'=>'Fix the portable stdio config issues, then rerun dataphyre_mcp_client_config_audit before writing client-owned config.',
			];
		}
		return match($surface){
			'client_config_summary'=> $base+[
				'status'=>'audit_config_before_write',
				'tool'=>'dataphyre_mcp_client_config_audit',
				'arguments'=>['config'=>'<proposed mcpServers.dataphyre config>'],
				'action'=>'Audit the proposed client config before writing caller-owned client files.',
			],
			'client_install_checklist', 'client_config_install_plan', 'client_onboarding_pack'=> $base+[
				'status'=>'audit_then_smoke_test',
				'tool'=>'dataphyre_mcp_client_config_audit',
				'arguments'=>['config'=>'<merged caller-owned MCP client config>'],
				'action'=>'Audit the merged config, export smoke fixtures, and keep live stdio validation optional unless local MCP wiring changed or smoke output is inconclusive.',
			],
			'smoke_test_export'=> $base+[
				'status'=>'smoke_done_optional_live_validation',
				'tool'=>'dataphyre_mcp_client_troubleshoot',
				'arguments'=>[],
				'action'=>'If the client can run the smoke frame locally, continue app work; use troubleshoot or live stdio validation only for wiring changes, failures, or inconclusive smoke output.',
			],
			'client_troubleshoot'=> $base+[
				'status'=>'run_baseline_setup_checks',
				'tool'=>'dataphyre_mcp_smoke_test_export',
				'arguments'=>['format'=>'all'],
				'action'=>'Use smoke fixtures and live validation to isolate client wiring before opening publication validation.',
			],
			'client_compatibility_matrix'=> $base+[
				'status'=>'start_onboarding_pack',
				'tool'=>'dataphyre_mcp_client_onboarding_pack',
				'arguments'=>['target'=>$target],
				'action'=>'Generate the target onboarding pack, audit config, run a smoke fixture, and reserve live validation for changed wiring or inconclusive local smoke output.',
			],
			default=> $base+[
				'status'=>'inspect_client_setup',
				'tool'=>'dataphyre_mcp_client_onboarding_pack',
				'arguments'=>['target'=>$target],
				'action'=>'Start with the portable onboarding pack for the target client.',
			],
		};
	}

	/**
	 * Exports a changelog-style summary of MCP surface changes.
	 *
	 * @param array{audience?:'maintainers'|'client_authors'|'agents'|string} $args Optional filters for changelog generation.
	 * @return array Surface changelog payload.
	 */
	private function mcp_surface_changelog(array $args): array {
		$audience=strtolower(trim((string)($args['audience'] ?? 'agents')));
		if(!in_array($audience, ['maintainers', 'client_authors', 'agents'], true)){
			$audience='agents';
		}
		$manifest=$this->mcp_manifest_export(['include_schemas'=>false, 'include_docs_resources'=>false]);
		$safety=$this->mcp_safety_boundary_report();
		$readiness=$this->mcp_readiness_report();
		$tool_groups=$manifest['tool_groups'] ?? [];
		$client_tools=array_values(array_filter($tool_groups['agent_and_planning']['tools'] ?? [], static fn(string $tool): bool => str_contains($tool, 'mcp_client') || str_contains($tool, 'mcp_smoke')));
		$verification_tools=array_values($tool_groups['verification']['tools'] ?? []);
		$themes=[];
		foreach([
			'client_onboarding'=>[
				'dataphyre_mcp_client_config_summary',
				'dataphyre_mcp_client_install_checklist',
				'dataphyre_mcp_smoke_test_export',
				'dataphyre_mcp_client_onboarding_pack',
				'dataphyre_mcp_client_troubleshoot',
				'dataphyre_mcp_client_compatibility_matrix',
				'dataphyre_mcp_client_config_audit',
			],
			'server_validation'=>[
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_verify_all',
				'dataphyre_mcp_doctor',
				'dataphyre_mcp_docs_coverage_report',
			],
			'safety_and_release'=>[
				'dataphyre_mcp_safety_boundary_report',
				'dataphyre_mcp_capability_matrix',
				'dataphyre_mcp_release_notes_generate',
				'dataphyre_mcp_readiness_report',
			],
			'discovery'=>[
				'dataphyre_mcp_manifest_export',
				'dataphyre_mcp_tool_finder',
				'dataphyre_mcp_resource_finder',
				'dataphyre_mcp_prompt_catalog',
				'dataphyre_prompt_pack_export',
			],
		] as $theme=>$names){
			$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $manifest['tools'] ?? []);
			$present=array_values(array_intersect($names, $tool_names));
			$themes[$theme]=[
				'count'=>count($present),
				'tools'=>$present,
			];
		}
		$coverage_areas=$readiness['agentic_capability_coverage'] ?? $readiness['boost_parity_coverage'] ?? [];
		$ready_areas=count(array_filter($coverage_areas, static fn(array $area): bool => ($area['ready'] ?? false)===true));
		return [
			'changelog_type'=>'dataphyre_mcp_surface_changelog',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'audience'=>$audience,
			'protocol'=>$manifest['protocol'] ?? '2025-11-25',
			'generated_from'=>'live MCP registration, readiness, and safety reports',
			'counts'=>$manifest['counts'] ?? [],
			'default_safety'=>$manifest['default_safety'] ?? 'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('surface_changelog'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('surface_changelog'),
			'surface_themes'=>$themes,
			'client_author_highlights'=>[
				'Client onboarding, compatibility, troubleshooting, config audit, and smoke-test export tools are available as read-only surfaces.',
				'Use config audit, smoke-test export, and live stdio validation for ordinary local client setup.',
				'Config examples remain portable; product-local PHP paths belong only in private client configuration.',
			],
			'maintainer_highlights'=>[
				'Docs coverage, doctor, live validation, and aggregate verification are registered MCP surfaces.',
				'The safety boundary report centralizes unsafe opt-in, redaction, and intentionally unexposed surface policy.',
				'Readiness coverage reports '.$ready_areas.' ready agentic capability areas.',
			],
			'agent_highlights'=>[
				'Call dataphyre_app_builder_plan_generate with payload_profile=compact first for ordinary app work, read builder_response.first_read.next_action and the compact first-read summaries, and open task packs, start packs, or briefs only as optional context.',
				'Use tool/resource/prompt finder surfaces before scraping full manifests.',
				'Use safety boundary and config audit tools before suggesting unsafe or client-specific setup changes.',
				'Start from the Application-Agent Default Lane: read-only metadata, app-owned extension points, and focused app/module verification.',
				'Use focused application or module verification for app behavior; reserve publication validation for MCP/release-surface summaries.',
			],
			'client_tools'=>$client_tools,
			'verification_tools'=>$verification_tools,
			'denied_surfaces'=>$safety['intentionally_not_exposed'] ?? [],
			'recommended_validation'=>[
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_docs_coverage_report',
				'dataphyre_mcp_safety_boundary_report',
			],
			'publication_validation'=>$this->mcp_publication_validation_contract('surface_changelog'),
		];
	}

	/**
	 * Determines whether a prompt pack is ordinary app-agent guidance.
	 *
	 * @param list<string> $names Selected prompt names.
	 * @return bool True when selected prompts should stay on the lightweight app lane.
	 */
	private function mcp_prompt_pack_is_app_first(array $names): bool {
		if($names===[]){
			return false;
		}
		foreach($names as $name){
			if(in_array($this->prompt_catalog_theme((string)$name), ['release', 'guidelines'], true)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Describes which MCP tools are default app-agent surfaces versus publication gates.
	 *
	 * @param array<int, mixed> $tool_names Live tool names exposed by the current server.
	 * @return array<string, mixed> Audience boundary contract for MCP clients.
	 */
	private function mcp_tool_audience_boundaries(array $tool_names): array {
		$tool_names=array_values(array_unique(array_filter(
			array_map(static fn(mixed $tool): string => (string)$tool, $tool_names),
			static fn(string $tool): bool => $tool!==''
		)));
		$publication_validation_tools=array_values(array_intersect([
			'dataphyre_release_check',
			'dataphyre_release_triage_summary',
			'dataphyre_release_fix_plan',
			'dataphyre_mcp_live_validate',
			'dataphyre_mcp_verify_all',
			'dataphyre_mcp_doctor',
			'dataphyre_mcp_docs_coverage_report',
		], $tool_names));
		return [
			'default_audience'=>'application_agents_building_apps',
			'ordinary_app_tool_policy'=>'Use read-only discovery, planning, safety, config, route, SQL metadata, diagnostics previews, workflow, and client setup tools for ordinary application work.',
			'ordinary_app_verification'=>'focused application or module checks',
			'publication_validation_tools'=>$publication_validation_tools,
			'publication_validation_tool_boundaries'=>$this->mcp_tool_boundary_map($publication_validation_tools),
			'publication_validation_scope'=>'MCP/release-surface claims, published shared MCP setup docs, release notes, MCP server wiring changes, or maintainer-requested source-checkout evidence.',
			'not_default_for_ordinary_app_work'=>[
				'dataphyre_mcp_verify_all',
				'Dataphyre project evidence',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits',
			],
		];
	}

	/**
	 * Classifies a manifest tool group for compact client discovery.
	 *
	 * @param string $group Manifest tool group key.
	 * @return string Audience scope for the group.
	 */
	private function mcp_tool_group_audience_scope(string $group): string {
		return match($group){
			'verification'=>'focused_app_or_module_verification',
			'publication_validation'=>'publication_validation_not_ordinary_app_work',
			'agent_and_planning', 'skill_registration'=>'application_agents_building_apps_with_collapsed_escalation',
			default=>'application_agents_building_apps',
		};
	}

	/**
	 * Describes what a flat manifest tool list proves.
	 *
	 * @param array<int,mixed> $tool_names Tool names to describe.
	 * @return array<string,array<string,mixed>> Tool-name keyed boundary metadata.
	 */
	private function mcp_tool_boundary_map(array $tool_names): array {
		$catalog=[
			'dataphyre_php_lint'=>[
				'audience_scope'=>'focused_app_or_module_verification',
				'claim_boundary'=>'PHP syntax and touched-file lint evidence only; not MCP release proof.',
			],
			'dataphyre_run_panel_regression'=>[
				'audience_scope'=>'focused_app_or_module_verification',
				'claim_boundary'=>'Panel behavior regression evidence for the affected app/module only.',
			],
			'dataphyre_run_panel_field_catalog_check'=>[
				'audience_scope'=>'focused_app_or_module_verification',
				'claim_boundary'=>'Panel field catalog compatibility evidence for the affected app/module only.',
			],
			'dataphyre_verification_surface_catalog'=>[
				'audience_scope'=>'focused_app_or_module_verification',
				'claim_boundary'=>'Catalog selection helper for focused checks; not proof by itself.',
				'not_app_behavior_proof'=>true,
			],
			'dataphyre_mcp_live_validate'=>[
				'audience_scope'=>'local_client_setup_not_app_behavior',
				'claim_boundary'=>'Local MCP client/server wiring validation; not application behavior proof.',
				'not_app_behavior_proof'=>true,
				'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
			],
			'dataphyre_mcp_verify_all'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'claim_boundary'=>'Aggregate MCP/source-checkout validation for MCP or release-surface claims only.',
				'not_app_behavior_proof'=>true,
				'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification'],
			],
			'dataphyre_release_check'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'claim_boundary'=>'Release-facing package evidence, not ordinary app behavior proof.',
				'not_app_behavior_proof'=>true,
			],
			'dataphyre_release_triage_summary'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'claim_boundary'=>'Release-triage evidence for maintainer workflows, not ordinary app behavior proof.',
				'not_app_behavior_proof'=>true,
			],
			'dataphyre_release_fix_plan'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'claim_boundary'=>'Release-fix planning for maintainers, not ordinary app behavior proof.',
				'not_app_behavior_proof'=>true,
			],
			'dataphyre_mcp_doctor'=>[
				'audience_scope'=>'mcp_surface_diagnostics_not_app_behavior',
				'claim_boundary'=>'MCP server diagnostic evidence, not proof of application behavior.',
				'not_app_behavior_proof'=>true,
			],
			'dataphyre_mcp_docs_coverage_report'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'claim_boundary'=>'MCP documentation coverage evidence for shared surface publication only.',
				'not_app_behavior_proof'=>true,
			],
		];
		$boundaries=[];
		foreach($tool_names as $tool){
			$tool_name=(string)$tool;
			if($tool_name===''){
				continue;
			}
			$boundaries[$tool_name]=$catalog[$tool_name] ?? [
				'audience_scope'=>'application_agents_building_apps',
				'claim_boundary'=>'Registered MCP capability; use focused app/module checks for application behavior.',
			];
		}
		return $boundaries;
	}

	/**
	 * Builds the audience boundary contract from the currently registered tools.
	 *
	 * @return array<string, mixed> Audience boundary contract for live client surfaces.
	 */
	private function mcp_current_tool_audience_boundaries(): array {
		return $this->mcp_tool_audience_boundaries(array_map(
			static fn(array $tool): string => (string)($tool['name'] ?? ''),
			$this->list_tools()['tools']
		));
	}

	/**
	 * Describes publication validation as a collapsed maintainer lane.
	 *
	 * @param string $surface Surface exposing the lane.
	 * @return array<string,mixed> Publication validation boundary.
	 */
	private function mcp_publication_validation_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'audience_scope'=>'publication_validation_not_ordinary_app_work',
			'app_agent_default'=>'not_required_for_ordinary_application_work',
			'recommended_gate'=>'dataphyre_mcp_verify_all',
			'tools'=>[
				'dataphyre_mcp_verify_all',
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_doctor',
				'dataphyre_mcp_docs_coverage_report',
			],
			'applies_to'=>'MCP/release-surface claims, published shared MCP setup docs, release notes, MCP server wiring changes, or maintainer-requested source-checkout evidence.',
			'app_behavior_verification'=>'Use focused application or module verification for ordinary app behavior.',
			'not_required'=>[
				'ordinary local client setup',
				'ordinary application behavior proof',
				'application agents running publication validation for routine app work',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Describes client setup audience boundaries so application agents do not inherit maintainer gates.
	 *
	 * @param string $surface Client setup surface label.
	 * @return array<string,mixed> Audience contract for client setup payloads.
	 */
	private function mcp_client_audience_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'default_audience'=>'application_agents_building_apps',
			'default_work'=>'install_or_validate_portable_read_only_mcp_client_setup',
			'ordinary_validation'=>[
				'portable config review',
				'client config audit',
				'stdio smoke test export',
				'local live stdio validation',
				'focused application or module checks for app behavior',
			],
			'not_required_for_app_agents'=>[
				'Dataphyre project-wide release validation',
				'dataphyre_mcp_verify_all',
				'Dataphyre MCP publication evidence',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits',
			],
			'maintainer_only_when'=>[
				'publishing shared MCP setup docs',
				'publishing release notes',
				'changing MCP server wiring',
				'making MCP/release-surface claims',
				'contributing reusable Dataphyre framework changes',
			],
		];
	}

}

