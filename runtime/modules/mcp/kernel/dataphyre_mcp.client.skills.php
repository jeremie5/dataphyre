<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP skill catalog, registration, pack, and install-plan client surfaces.
 */
trait dataphyre_mcp_client_skill_surfaces {

	private function mcp_skill_definitions(): array {
		return [
			'dataphyre-app-builder'=>[
				'name'=>'dataphyre-app-builder',
				'title'=>'Dataphyre App Builder',
				'description'=>'Build ordinary Dataphyre applications through the app-builder planner, module docs, app-owned files, and focused verification before broader governance context.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'app_builder',
				'related_tools'=>['dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_resource_finder', 'dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check'],
				'related_prompts'=>['dataphyre_feature_plan', 'dataphyre_panel_workflow'],
				'related_resources'=>[
					'dataphyre://doc/common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
					'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
					'dataphyre://module-index',
				],
				'instructions'=>[
					'Start ordinary app creation with dataphyre_app_builder_plan_generate payload_profile=compact and read builder_response.first_read first, especially builder_response.first_read.next_action, next_detail_page, files_summary, schema_summary, naming_contract, write_readiness, scaffold_completion_summary, and verification_handoff. Treat open_details as the backing pointer map for builder_response.first_read.next_detail_page, not as default reading. Treat the agent overhead budget as part of the contract: keep status/safety/enterprise/publication validation collapsed until explicitly requested for an escalation decision.',
					'For large app scaffolds, follow builder_response.first_read.scaffold_completion_summary.next_continuation or entity_planning.continuation_calls until deferred_entities is empty; preserve dependency_context/dependency_summary and pass explicit entities, fields, and max_entities when the task already names them, using foreign_key_target for relationships, not_foreign_key for external ids, and json/jsonb for structured columns.',
					'Open detail pages only for the current phase: planning details for files/schema/relations, implementation details for local_convention_probe and implementation_recipe, verification details after app-owned writes, and controls details for sensitivity or policy decisions. No governance or release validation is needed for ordinary app work.',
					'Across continuations and handoffs, preserve the compact first-read fields plus the referenced detail page or continuation arguments rather than copying every app-builder field into the default context.',
					'Use builder_response.recovery_hints for placeholder replacement, focused Panel/SQL metadata checks, and redacted diagnostics before opening governance or MCP/release-surface validation.',
					'Open the controls detail page only when first_read or write_readiness points to app-owned policy, tenant, sensitivity, access, or lifecycle decisions; resolve those in app-owned code without opening the enterprise audit unless an explicit escalation decision requires it.',
					'Run prewrite_checklist before writing app-owned files; it is a short app-builder checklist, not a governance gate. Treat write_readiness as ready when prewrite_checklist.prewrite_blockers is empty and incomplete chunks have preserved continuation arguments; complete implementation_obligations and prewrite_reminders during app-owned edits.',
					'After app-owned writes, open the verification detail page for focused checks and copy-safe verification_handoff without collecting maintainer release evidence; open full code_skeleton previews only when adapting app-owned files. Add dataphyre_task_pack_generate with payload_profile=builder only when module docs or a ready-to-use prompt are needed.',
					'Use dataphyre_mcp_agent_brief_export for compact cold starts or handoffs; use dataphyre_mcp_task_start_pack_export payload_profile=builder only when broader bounded workflow context is needed.',
					'Read module docs and generated skeletons as planning context, then write only consuming-application files or extension metadata requested by the task.',
					'Use focused app or module verification such as PHP lint for generated paths, Panel field catalog checks, and route-free Panel regression manifests.',
					'Open governance, enterprise audit, release checks, or hot-path benchmark evidence only when the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
				],
			],
			'dataphyre-runtime-guidelines'=>[
				'name'=>'dataphyre-runtime-guidelines',
				'title'=>'Dataphyre Runtime Guidelines',
				'description'=>'Load baseline Dataphyre runtime editing rules, safety posture, and verification expectations before code changes.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'runtime',
				'related_tools'=>['dataphyre_agent_context_generate', 'dataphyre_mcp_resource_finder', 'dataphyre_mcp_safety_boundary_report', 'dataphyre_mcp_enterprise_adoption_audit', 'dataphyre_verification_surface_catalog'],
				'related_prompts'=>['dataphyre_runtime_guidelines', 'dataphyre_feature_plan'],
				'related_resources'=>['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://runtime-readme', 'dataphyre://mcp-capabilities'],
				'instructions'=>[
					'Start by reading Dataphyre AI guidelines, the agentic enterprise contract, and runtime README resources.',
					'Follow the Application-Agent Default Lane for app work: read-only metadata first, app-owned extension points, and focused app or module checks.',
					'Run the enterprise adoption audit before agent-first, corporate-ready, public, or release-facing claims.',
					'Prefer static inspection and dry-run planning before edits.',
					'Use focused application or module verification for app behavior; use aggregate MCP verification only before MCP/release-surface claims.',
				],
			],
			'dataphyre-mcp-client-setup'=>[
				'name'=>'dataphyre-mcp-client-setup',
				'title'=>'Dataphyre MCP Client Setup',
				'description'=>'Guide client authors through portable stdio setup, smoke tests, compatibility checks, and config audits.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'client',
				'related_tools'=>['dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_live_validate'],
				'related_prompts'=>['dataphyre_runtime_guidelines'],
				'related_resources'=>['dataphyre://mcp-plan', 'dataphyre://mcp-capabilities'],
				'instructions'=>[
					'Use portable PHP and server paths in shared examples.',
					'Audit client config snippets before sharing them.',
					'Validate stdio wiring with live validation after setup changes.',
				],
			],
			'dataphyre-route-inspection'=>[
				'name'=>'dataphyre-route-inspection',
				'title'=>'Dataphyre Route Inspection',
				'description'=>'Inspect route manifests, source declarations, URL previews, controllers, and middleware without dispatching handlers.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'routes',
				'related_tools'=>['dataphyre_route_source_static_summary', 'dataphyre_list_routes', 'dataphyre_route_manifest_read', 'dataphyre_route_match_preview', 'dataphyre_route_source_ambiguity_report'],
				'related_prompts'=>['dataphyre_route_manifest_workflow'],
				'related_resources'=>['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://mcp-capabilities'],
				'instructions'=>[
					'Read route artifacts and static source summaries before inferring behavior.',
					'Never dispatch route handlers from MCP inspection workflows.',
					'Use ambiguity reports when route declarations are dynamic or non-literal.',
				],
			],
			'dataphyre-sql-schema-safety'=>[
				'name'=>'dataphyre-sql-schema-safety',
				'title'=>'Dataphyre SQL Schema Safety',
				'description'=>'Inspect SQL schema metadata and read-only query plans without opening database connections or exposing credentials.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'sql',
				'related_tools'=>['dataphyre_sql_tables_list', 'dataphyre_sql_schema_read', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract', 'dataphyre_mcp_safety_boundary_report'],
				'related_prompts'=>['dataphyre_sql_schema_workflow'],
				'related_resources'=>['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://mcp-capabilities'],
				'instructions'=>[
					'Use schema reads and query plans instead of live SQL execution.',
					'Reject mutation statements and credential exposure.',
					'Review the query runner contract before proposing any future unsafe-gated adapter.',
				],
			],
			'dataphyre-workflow-continuity'=>[
				'name'=>'dataphyre-workflow-continuity',
				'title'=>'Dataphyre Workflow Continuity',
				'description'=>'Carry MCP workflow progress across agent turns with client-owned state, transcript summaries, checkpoints, and resume briefs.',
				'targets'=>['codex', 'claude', 'cursor', 'generic'],
				'theme'=>'agent_continuity',
				'related_tools'=>['dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_next_action_export'],
				'related_prompts'=>['dataphyre_feature_plan', 'dataphyre_debug_triage'],
				'related_resources'=>['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://mcp-capabilities'],
				'instructions'=>[
					'Keep workflow state client-owned; the MCP server does not persist state.',
					'Use resume briefs for short handoffs and sync packs for full continuity.',
					'Audit state and transcripts before sharing them across agents.',
				],
			],
		];
	}

	/**
	 * Selects skill definitions by requested names or targets.
	 *
	 * @param array<string,mixed> $args Skill selection options.
	 * @return array Selected skill definition rows.
	 */
	private function mcp_select_skills(array $args): array {
		$definitions=$this->mcp_skill_definitions();
		$names=$args['names'] ?? [];
		if(!is_array($names) || $names===[]){
			$names=array_keys($definitions);
		}
		$target=strtolower(trim((string)($args['target'] ?? 'all')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic', 'all'], true)){
			$target='all';
		}
		$selected=[];
		foreach($names as $name){
			$name=(string)$name;
			if($name==='' || !isset($definitions[$name])){
				throw new InvalidArgumentException('Unknown Dataphyre MCP skill: '.$name);
			}
			$skill=$definitions[$name];
			if($target!=='all' && !in_array($target, $skill['targets'] ?? [], true)){
				continue;
			}
			$selected[]=$skill;
		}
		return [$target, $selected, array_keys($definitions)];
	}

	/**
	 * Builds a catalog of available MCP skills.
	 *
	 * @param array<string,mixed> $args Skill catalog filter options.
	 * @return array Skill catalog payload.
	 */
	private function mcp_skill_catalog(array $args): array {
		[$target, $skills, $available]=$this->mcp_select_skills($args);
		$catalog=[];
		foreach($skills as $skill){
			$catalog[]=[
				'name'=>$skill['name'],
				'title'=>$skill['title'],
				'description'=>$skill['description'],
				'theme'=>$skill['theme'],
				'targets'=>$skill['targets'],
				'related_tools'=>$skill['related_tools'],
				'related_prompts'=>$skill['related_prompts'],
				'related_resources'=>$skill['related_resources'],
				'instructions'=>array_values(array_slice(is_array($skill['instructions'] ?? null) ? $skill['instructions'] : [], 0, 8)),
				'manifest_tool'=>'dataphyre_mcp_skill_manifest_export',
				'pack_tool'=>'dataphyre_mcp_skill_pack_export',
				'audit_tool'=>'dataphyre_mcp_skill_registration_audit',
			];
		}
		return [
			'catalog_type'=>'dataphyre_mcp_skill_catalog',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'target'=>$target,
			'skill_count'=>count($catalog),
			'available_skills'=>$available,
			'governance_notes'=>[
				'status'=>'none triggered',
				'default_lane'=>'skill_discovery',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			],
			'context_links'=>array_replace($this->mcp_lightweight_discovery_context_links(), [
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
				'runtime_guidelines_skill'=>'dataphyre-runtime-guidelines',
			]),
			'skills'=>$catalog,
			'usage_notes'=>[
				'Skills default to application agents building apps; framework-maintainer gates are release-facing, corporate-ready, security-sensitive, or Dataphyre hot-path concerns.',
				'Skills are registered metadata and instructions; this catalog does not install or write client files.',
				'Use skill packs when a client needs copyable instructions.',
				'Use the registration audit before publishing or packaging skills.',
			],
		];
	}

	/**
	 * Exports skill manifest metadata for selected MCP skills.
	 *
	 * @param array<string,mixed> $args Skill selection and manifest options.
	 * @return array Skill manifest payload.
	 */
	private function mcp_skill_manifest_export(array $args): array {
		[$target, $skills, $available]=$this->mcp_select_skills($args);
		return [
			'manifest_type'=>'dataphyre_mcp_skill_manifest',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'target'=>$target,
			'skill_count'=>count($skills),
			'available_skills'=>$available,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('skill_manifest'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('skill_manifest'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'skills'=>array_values(array_map(static fn(array $skill): array => [
				'name'=>$skill['name'],
				'title'=>$skill['title'],
				'description'=>$skill['description'],
				'targets'=>$skill['targets'],
				'theme'=>$skill['theme'],
				'related_tools'=>$skill['related_tools'],
				'related_prompts'=>$skill['related_prompts'],
				'related_resources'=>$skill['related_resources'],
			], $skills)),
			'registration_policy'=>[
				'Skill manifests default to application-agent app development; Dataphyre maintainer obligations remain separate from ordinary client skill registration.',
				'Skills must remain product-neutral in shared Dataphyre MCP metadata.',
				'Skills may reference registered Dataphyre MCP tools, prompts, and resources only.',
				'Skill exports are read-only payloads; client-specific installers are intentionally separate.',
			],
		];
	}

	/**
	 * Audits skill registration coverage and target alignment.
	 *
	 * @param array<string,mixed> $args Skill selection and audit options.
	 * @return array Skill registration audit payload.
	 */
	private function mcp_skill_registration_audit(array $args): array {
		[$target, $skills, $available]=$this->mcp_select_skills($args);
		$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		$prompt_names=array_map(static fn(array $prompt): string => (string)($prompt['name'] ?? ''), $this->list_prompts()['prompts']);
		$resource_uris=array_map(static fn(array $resource): string => (string)($resource['uri'] ?? ''), $this->list_resources()['resources']);
		$findings=[];
		$audits=[];
		foreach($skills as $skill){
			$missing_tools=array_values(array_diff($skill['related_tools'] ?? [], $tool_names));
			$missing_prompts=array_values(array_diff($skill['related_prompts'] ?? [], $prompt_names));
			$missing_resources=array_values(array_diff($skill['related_resources'] ?? [], $resource_uris));
			$serialized=json_encode($skill, JSON_UNESCAPED_SLASHES) ?: '';
			$coupling=[];
			foreach(['applications/', 'app/', 'tools/', '.local/', 'localhost:', '127.0.0.1:'] as $needle){
				if(str_contains($serialized, $needle)){
					$coupling[]=$needle;
				}
			}
			if($missing_tools!==[]){
				$findings[]=['severity'=>'error', 'skill'=>$skill['name'], 'code'=>'missing_tools', 'items'=>$missing_tools];
			}
			if($missing_prompts!==[]){
				$findings[]=['severity'=>'error', 'skill'=>$skill['name'], 'code'=>'missing_prompts', 'items'=>$missing_prompts];
			}
			if($missing_resources!==[]){
				$findings[]=['severity'=>'error', 'skill'=>$skill['name'], 'code'=>'missing_resources', 'items'=>$missing_resources];
			}
			if($coupling!==[]){
				$findings[]=['severity'=>'error', 'skill'=>$skill['name'], 'code'=>'product_local_coupling', 'items'=>array_values(array_unique($coupling))];
			}
			$audits[]=[
				'name'=>$skill['name'],
				'targets'=>$skill['targets'],
				'tool_count'=>count($skill['related_tools'] ?? []),
				'prompt_count'=>count($skill['related_prompts'] ?? []),
				'resource_count'=>count($skill['related_resources'] ?? []),
				'missing_tools'=>$missing_tools,
				'missing_prompts'=>$missing_prompts,
				'missing_resources'=>$missing_resources,
				'product_local_coupling'=>$coupling,
				'ready'=>$missing_tools===[] && $missing_prompts===[] && $missing_resources===[] && $coupling===[],
			];
		}
		$error_count=count(array_filter($findings, static fn(array $finding): bool => ($finding['severity'] ?? '')==='error'));
		return [
			'audit_type'=>'dataphyre_mcp_skill_registration_audit',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'target'=>$target,
			'skill_count'=>count($skills),
			'available_skills'=>$available,
			'passed'=>$error_count===0,
			'error_count'=>$error_count,
			'findings'=>$findings,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('skill_registration_audit'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('skill_registration_audit'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'skill_audits'=>$audits,
			'usage_notes'=>[
				'This audit checks registered skill metadata only; it does not install skills or read client files.',
				'Passing this audit supports portable skill publication; it is not an application-agent requirement for routine app edits.',
				'Fix missing related MCP surfaces before publishing skill packs.',
				'Keep shared skill metadata product-neutral.',
			],
		];
	}

	/**
	 * Exports selected MCP skills as a client-consumable pack.
	 *
	 * @param array<string,mixed> $args Skill selection and pack options.
	 * @return array Skill pack payload.
	 */
	private function mcp_skill_pack_export(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$args['target']=$target;
		[$resolved_target, $skills, $available]=$this->mcp_select_skills($args);
		$audit=$this->mcp_skill_registration_audit(['names'=>array_map(static fn(array $skill): string => $skill['name'], $skills), 'target'=>$resolved_target]);
		$packs=[];
		foreach($skills as $skill){
			$packs[]=[
				'name'=>$skill['name'],
				'title'=>$skill['title'],
				'target'=>$resolved_target,
				'description'=>$skill['description'],
				'instructions'=>$skill['instructions'],
				'related_tools'=>$skill['related_tools'],
				'related_prompts'=>$skill['related_prompts'],
				'related_resources'=>$skill['related_resources'],
				'install_policy'=>'copy_or_import_client_side_only',
			];
		}
		$app_builder_only=$packs!==[] && count(array_filter($packs, static fn(array $pack): bool => ($pack['name'] ?? null)==='dataphyre-app-builder'))===count($packs);
		$payload=[
			'pack_type'=>'dataphyre_mcp_skill_pack',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'target'=>$resolved_target,
			'skill_count'=>count($packs),
			'available_skills'=>$available,
			'audit_passed'=>($audit['passed'] ?? false)===true,
			'skills'=>$packs,
			'usage_notes'=>[
				'Skill packs follow the Application-Agent Default Lane for app work and keep Dataphyre-maintainer checks scoped to shared or release-facing claims.',
				'Skill packs are portable instruction payloads; this tool does not write SKILL.md files or install client plugins.',
				'Clients should decide their own skill file locations and installation workflow.',
				'Run dataphyre_mcp_skill_registration_audit before publishing generated skill packs.',
			],
		];
		if($app_builder_only){
			$payload['governance_notes']=[
				'status'=>'none triggered',
				'default_lane'=>'app_builder_skill',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			];
			$payload['context_links']=array_replace($this->mcp_lightweight_discovery_context_links(), [
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
				'runtime_guidelines_skill'=>'dataphyre-runtime-guidelines',
			]);
		}else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('skill_pack');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('skill_pack');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

	/**
	 * Builds an installation plan for selected MCP skills.
	 *
	 * @param array<string,mixed> $args Skill selection and install target options.
	 * @return array Skill install plan payload.
	 */
	private function mcp_skill_install_plan(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$args['target']=$target;
		[$resolved_target, $skills, $available]=$this->mcp_select_skills($args);
		$pack=$this->mcp_skill_pack_export(['names'=>array_map(static fn(array $skill): string => $skill['name'], $skills), 'target'=>$resolved_target]);
		$conventions=[
			'codex'=>[
				'registration_model'=>'client skill directory',
				'path_template'=>'$CODEX_HOME/skills/<skill-name>/SKILL.md',
				'content_format'=>'SKILL.md markdown instructions',
				'activation'=>'The client loads registered skills from its configured skill directory.',
			],
			'claude'=>[
				'registration_model'=>'client-managed skill import',
				'path_template'=>'<client-skill-root>/<skill-name>/SKILL.md',
				'content_format'=>'Markdown skill instructions and related MCP surface metadata',
				'activation'=>'Register or import the generated skill directory through the Claude client workflow.',
			],
			'cursor'=>[
				'registration_model'=>'client-managed reusable rule or skill import',
				'path_template'=>'<client-skill-root>/<skill-name>/SKILL.md',
				'content_format'=>'Markdown instructions suitable for client-owned skill or rule storage',
				'activation'=>'Attach the generated instructions through the Cursor workspace or user-level client workflow.',
			],
			'generic'=>[
				'registration_model'=>'client-owned skill registry',
				'path_template'=>'<client-skill-root>/<skill-name>/SKILL.md',
				'content_format'=>'Portable markdown instructions with MCP tool, prompt, and resource references',
				'activation'=>'Import the skill pack into the client registry chosen by the caller.',
			],
		];
		$target_convention=$conventions[$resolved_target] ?? $conventions['generic'];
		$plans=[];
		foreach($pack['skills'] ?? [] as $skill){
			$name=(string)($skill['name'] ?? '');
			$plans[]=[
				'name'=>$name,
				'title'=>(string)($skill['title'] ?? $name),
				'target'=>$resolved_target,
				'instructions'=>$skill['instructions'] ?? [],
				'registration_model'=>$target_convention['registration_model'],
				'proposed_files'=>[
					[
						'path_template'=>str_replace('<skill-name>', $name, $target_convention['path_template']),
						'content_source'=>'dataphyre_mcp_skill_pack_export',
						'content_format'=>$target_convention['content_format'],
						'write_policy'=>'caller_owned_write_only',
					],
					[
						'path_template'=>str_replace('<skill-name>', $name, '<client-skill-root>/<skill-name>/manifest.json'),
						'content_source'=>'dataphyre_mcp_skill_manifest_export',
						'content_format'=>'optional JSON metadata for client registries that support manifests',
						'write_policy'=>'caller_owned_write_only',
					],
				],
				'registration_steps'=>[
					'Export the skill pack with dataphyre_mcp_skill_pack_export for the selected target.',
					'Review dataphyre_mcp_skill_registration_audit and require a passing audit before publishing.',
					'Create client-owned skill files in the target client location chosen by the caller.',
					$target_convention['activation'],
					'Run client-side skill discovery and dataphyre_mcp_live_validate before claiming the local skill install is ready.',
				],
				'publication_validation'=>[
					'Use dataphyre_mcp_verify_all only before publishing shared skill guidance, release notes, or MCP/release-surface claims.',
				],
				'rollback_steps'=>[
					'Remove the client-owned skill directory or unregister the skill through the client workflow.',
					'Clear any client-side cached skill index if the client documents that cache.',
					'Rerun client discovery and confirm the removed skill is no longer advertised.',
				],
				'related_tools'=>$skill['related_tools'] ?? [],
				'related_prompts'=>$skill['related_prompts'] ?? [],
				'related_resources'=>$skill['related_resources'] ?? [],
			];
		}
		return [
			'plan_type'=>'dataphyre_mcp_skill_install_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'target'=>$resolved_target,
			'skill_count'=>count($plans),
			'available_skills'=>$available,
			'audit_passed'=>($pack['audit_passed'] ?? false)===true,
			'target_convention'=>$target_convention,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('skill_install_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('skill_install_plan'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'plans'=>$plans,
			'safety_notes'=>[
				'Application agents installing skills for app work should stay in the default lane: client-owned files, MCP metadata, and focused app/module validation.',
				'This plan does not create directories, write SKILL.md files, modify client config, or install plugins.',
				'Shared Dataphyre MCP code must stay product-neutral; client paths are caller-owned configuration.',
				'Do not enable unsafe MCP mode for skill registration unless a separate workflow explicitly requires it.',
			],
		];
	}

	/**
	 * Builds a file-level installation plan for selected MCP skills.
	 *
	 * @param array<string,mixed> $args Skill selection and filesystem target options.
	 * @return array Skill file install plan payload.
	 */
	private function mcp_skill_file_install_plan(array $args): array {
		$target=strtolower(trim((string)($args['target'] ?? 'generic')));
		if(!in_array($target, ['codex', 'claude', 'cursor', 'generic'], true)){
			$target='generic';
		}
		$skill_root=trim((string)($args['skill_root'] ?? ''));
		if($skill_root===''){
			$skill_root=match($target){
				'codex'=>'$CODEX_HOME/skills',
				'claude'=>'<claude-client-skill-root>',
				'cursor'=>'<cursor-client-skill-root>',
				default=>'<client-skill-root>',
			};
		}
		$args['target']=$target;
		[$resolved_target, $skills, $available]=$this->mcp_select_skills($args);
		$install_plan=$this->mcp_skill_install_plan([
			'names'=>array_map(static fn(array $skill): string => $skill['name'], $skills),
			'target'=>$resolved_target,
		]);
		$proposed_writes=[];
		foreach($install_plan['plans'] ?? [] as $plan){
			$name=(string)($plan['name'] ?? '');
			if($name===''){
				continue;
			}
			$proposed_writes[]=[
				'path'=>$skill_root.'/'.$name.'/SKILL.md',
				'owner'=>'caller_owned_skill_file',
				'write_policy'=>'caller_owned_write_only',
				'content_source'=>'dataphyre_mcp_skill_pack_export',
				'content_format'=>'SKILL.md markdown instructions',
			];
			$proposed_writes[]=[
				'path'=>$skill_root.'/'.$name.'/manifest.json',
				'owner'=>'caller_owned_skill_file',
				'write_policy'=>'caller_owned_write_only',
				'content_source'=>'dataphyre_mcp_skill_manifest_export',
				'content_format'=>'optional JSON metadata for client registries that support manifests',
			];
		}
		return [
			'plan_type'=>'dataphyre_mcp_skill_file_install_plan',
			'write_policy'=>'read_only_plan',
			'execution'=>'not_executed',
			'artifacts_written'=>false,
			'target'=>$resolved_target,
			'skill_root'=>$skill_root,
			'skill_count'=>count($skills),
			'available_skills'=>$available,
			'audit_passed'=>($install_plan['audit_passed'] ?? false)===true,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('skill_file_install_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('skill_file_install_plan'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'current_safe_surfaces'=>[
				'skill_catalog'=>'dataphyre_mcp_skill_catalog',
				'skill_manifest'=>'dataphyre_mcp_skill_manifest_export',
				'skill_audit'=>'dataphyre_mcp_skill_registration_audit',
				'skill_pack'=>'dataphyre_mcp_skill_pack_export',
				'skill_install_plan'=>'dataphyre_mcp_skill_install_plan',
				'local_validation'=>'client-side skill discovery',
			],
			'proposed_writes'=>$proposed_writes,
			'preconditions'=>[
				'skill root must be caller-provided or resolved by the client, not hardcoded in shared MCP code',
				'writer must create or update only the selected skill directories under the caller-owned skill root',
				'writer must back up existing skill files or preserve a rollback manifest before overwriting',
				'writer must run client-side skill discovery after writing',
			],
			'denied_writes'=>[
				'Dataphyre runtime or MCP module files',
				'application-specific rule or plugin files not selected by the caller',
				'client config files; use dataphyre_mcp_client_config_install_plan for config writers',
				'existing unrelated client skills',
				'unsafe flags or executable hooks inside skill files',
			],
			'rollback_plan'=>[
				'restore backed-up SKILL.md and manifest files for modified skills',
				'remove only newly-created selected skill directories when no prior files existed',
				'leave unrelated client skills and config untouched',
				'run client-side skill discovery after rollback',
			],
			'verification_steps'=>[
				'Run dataphyre_mcp_skill_pack_export and compare proposed writes against the exported pack.',
				'Use the target client skill discovery workflow to confirm only selected skills were added.',
			],
			'local_install_validation'=>[
				'Compare proposed writes against dataphyre_mcp_skill_pack_export for the selected skills.',
				'Use the target client skill discovery workflow to confirm only selected skills were added.',
				'Run dataphyre_mcp_live_validate only when the local client MCP server wiring changed.',
			],
			'publication_validation'=>[
				'Run dataphyre_mcp_skill_registration_audit before publishing shared skill packs or shared skill-writer guidance.',
				'Use dataphyre_mcp_verify_all only before publishing shared skill writer guidance, release notes, or MCP/release-surface claims.',
			],
			'safety_notes'=>[
				'Application-agent skill writers should keep writes client-owned and should not modify Dataphyre runtime or MCP module files to make an app work.',
				'This plan does not create directories, write SKILL.md files, modify client config, or install plugins.',
				'Concrete skill roots remain client-owned and target-specific.',
				'Keep shared MCP skill writer guidance product-neutral and avoid committing machine-local paths.',
			],
		];
	}

	/**
	 * Reports MCP documentation coverage across tools, prompts, resources, and skills.
	 *
	 * @return array Documentation coverage report payload.
	 */

}
