<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP client workflow, start-pack, and handoff surfaces.
 */
trait dataphyre_mcp_client_workflow_surfaces {

	use dataphyre_mcp_client_workflow_state_surfaces;
	use dataphyre_mcp_client_workflow_start_pack_surfaces;

	/**
	 * Exports the MCP workflow playbook for client-side orchestration.
	 *
	 * @param array{workflow?:'feature'|'routes'|'sql'|'diagnostics'|'client'|'release'|'all'|string} $args Workflow selection and detail options.
	 * @return array Workflow playbook payload.
	 */
	private function mcp_workflow_playbook_export(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'all')));
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release', 'all'], true)){
			$workflow='all';
		}
		$playbooks=[
			'feature'=>[
				'title'=>'Plan a Dataphyre feature safely',
				'prompt'=>'dataphyre_feature_plan',
				'goal'=>'Start ordinary app work with the builder plan, then collect local framework context and name focused verification before edits.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_app_builder_plan_generate', 'arguments'=>['task'=>'Build an internal Panel CRUD resource with schema, filters, actions, and verification'], 'purpose'=>'Use the app-builder golden path: get the app-builder first read for Panel, route/controller, SQL, and API endpoint scaffolds: builder_response.first_read.next_action, builder_response.first_read.next_detail_page, files_summary, schema_summary, naming_contract, write_readiness, scaffold_completion_summary.next_continuation for chunks, verification_handoff, and collapsed governance before opening the one detail page needed next.'],
					['order'=>2, 'tool'=>'dataphyre_mcp_resource_finder', 'arguments'=>['query'=>'Panel SQL app resource docs', 'kind'=>'all', 'limit'=>6], 'purpose'=>'Find focused Panel and SQL module docs after the builder shape is known.'],
					['order'=>3, 'tool'=>'dataphyre_task_pack_generate', 'arguments'=>['task'=>'Build an internal Panel CRUD resource with schema, filters, actions, and verification', 'modules'=>['panel', 'sql'], 'scaffold_type'=>'panel_resource', 'name'=>'Example Resource', 'max_chunks'=>6, 'payload_profile'=>'builder'], 'purpose'=>'Generate a builder-first prompt with module docs and app-owned verification guidance; for API endpoint work use scaffold_type=api_endpoint with path, methods, group, and auth hints.'],
					['order'=>4, 'tool'=>'dataphyre_verification_surface_catalog', 'arguments'=>['modules'=>['panel', 'sql'], 'limit'=>80], 'purpose'=>'Choose focused local verification surfaces for the planned app change.'],
				],
			],
			'routes'=>[
				'title'=>'Inspect routes without dispatching handlers',
				'prompt'=>'dataphyre_route_manifest_workflow',
				'goal'=>'Understand route sources, manifests, URL generation, and ambiguity while avoiding runtime dispatch.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_route_source_static_summary', 'arguments'=>['limit'=>40], 'purpose'=>'Find source-level route declarations statically.'],
					['order'=>2, 'tool'=>'dataphyre_list_routes', 'arguments'=>['limit'=>20], 'purpose'=>'Locate compiled route artifacts that can be inspected safely.'],
					['order'=>3, 'tool'=>'dataphyre_route_manifest_read', 'arguments'=>['manifest_path'=>'path/from/dataphyre_list_routes.php', 'limit'=>50], 'purpose'=>'Read a selected manifest without invoking handlers.'],
					['order'=>4, 'tool'=>'dataphyre_route_source_ambiguity_report', 'arguments'=>['limit'=>40], 'purpose'=>'Identify dynamic declarations that need source review or manifest confirmation.'],
				],
			],
			'sql'=>[
				'title'=>'Inspect SQL schema and query plans safely',
				'prompt'=>'dataphyre_sql_schema_workflow',
				'goal'=>'Review table metadata and classify read SQL without credentials or database execution.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_sql_tables_list', 'arguments'=>['include_runtime_manifest'=>true], 'purpose'=>'Discover known Dataphyre table definitions and cluster assignments.'],
					['order'=>2, 'tool'=>'dataphyre_sql_schema_read', 'arguments'=>['table'=>'dataphyre.mailer_outbox'], 'purpose'=>'Inspect schema metadata from first-party definitions.'],
					['order'=>3, 'tool'=>'dataphyre_sql_query_plan', 'arguments'=>['sql'=>'SELECT id FROM dataphyre.mailer_outbox', 'max_rows'=>25], 'purpose'=>'Classify a proposed read query without execution.'],
					['order'=>4, 'tool'=>'dataphyre_sql_query_runner_contract', 'arguments'=>[], 'purpose'=>'Review the unsafe-gated contract required before any future query runner executes SQL.'],
				],
			],
			'diagnostics'=>[
				'title'=>'Triage local diagnostics with redaction',
				'prompt'=>'dataphyre_diagnostics_workflow',
				'goal'=>'Find recent local errors and traces through bounded, redacted artifact reads.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_tracelog_artifacts_list', 'arguments'=>['scope'=>'common/dataphyre', 'limit'=>20], 'purpose'=>'List candidate logs and trace artifacts without reading them.'],
					['order'=>2, 'tool'=>'dataphyre_diagnostics_last_error', 'arguments'=>['scope'=>'common/dataphyre', 'limit'=>5], 'purpose'=>'Extract recent error-looking snippets with secret redaction.'],
					['order'=>3, 'tool'=>'dataphyre_tracelog_search', 'arguments'=>['query'=>'error', 'scope'=>'common/dataphyre', 'limit'=>8], 'purpose'=>'Search bounded trace previews for a specific symptom.'],
					['order'=>4, 'tool'=>'dataphyre_mcp_safety_boundary_report', 'arguments'=>[], 'purpose'=>'Confirm redaction and intentionally unexposed diagnostic boundaries.'],
				],
			],
			'client'=>[
				'title'=>'Onboard a stdio MCP client',
				'prompt'=>'dataphyre_runtime_guidelines',
				'goal'=>'Generate portable client setup, smoke tests, prompt packs, and config audits without product-local paths.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_mcp_client_onboarding_pack', 'arguments'=>['target'=>'generic', 'smoke_format'=>'all'], 'purpose'=>'Export config, checklist, smoke tests, prompt catalog, and validation plan.'],
					['order'=>2, 'tool'=>'dataphyre_mcp_client_config_audit', 'arguments'=>['config'=>['mcpServers'=>['dataphyre'=>['command'=>'php', 'args'=>['common/dataphyre/runtime/modules/mcp/kernel/dataphyre_mcp.php']]]]], 'purpose'=>'Audit proposed client config for portability and unsafe-mode issues.'],
					['order'=>3, 'tool'=>'dataphyre_mcp_tool_call_examples_export', 'arguments'=>['workflow'=>'client'], 'purpose'=>'Give client authors concrete tools/call examples for setup workflows.'],
					['order'=>4, 'tool'=>'dataphyre_mcp_live_validate', 'arguments'=>[], 'purpose'=>'Validate local MCP client wiring, stdio framing, tools, prompts, resources, and doctor output after setup changes.', 'audience_scope'=>'local_client_setup_not_app_behavior', 'not_app_behavior_proof'=>true, 'not_required_for'=>['ordinary application behavior proof', 'focused app/module verification']],
				],
			],
			'release'=>[
				'title'=>'Prepare MCP release validation',
				'prompt'=>'dataphyre_release_triage',
				'goal'=>'Summarize capability coverage, docs coverage, safety posture, and full verification before release notes.',
				'steps'=>[
					['order'=>1, 'tool'=>'dataphyre_mcp_status_board', 'arguments'=>[], 'purpose'=>'Read live counts, readiness, safety posture, and recommended next actions.'],
					['order'=>2, 'tool'=>'dataphyre_mcp_docs_coverage_report', 'arguments'=>[], 'purpose'=>'Ensure public MCP surfaces are documented.'],
					['order'=>3, 'tool'=>'dataphyre_mcp_verify_all', 'arguments'=>[], 'purpose'=>'Run lint, live validation, self-test, doctor, and app-coupling guard for MCP/release-surface claims, not ordinary application-agent verification.', 'audience_scope'=>'publication_validation_not_ordinary_app_work'],
					['order'=>4, 'tool'=>'dataphyre_mcp_release_notes_generate', 'arguments'=>['audience'=>'maintainers'], 'purpose'=>'Generate release notes from live capability and safety metadata.'],
				],
			],
		];
		$selected=$workflow==='all' ? $playbooks : [$workflow=>$playbooks[$workflow]];
		foreach($selected as $selected_key=>$selected_playbook){
			foreach(($selected_playbook['steps'] ?? []) as $step_key=>$selected_step){
				$selected[$selected_key]['steps'][$step_key]['arguments']=(object)($selected_step['arguments'] ?? []);
			}
		}
		$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		$prompt_names=array_map(static fn(array $prompt): string => (string)($prompt['name'] ?? ''), $this->list_prompts()['prompts']);
		$missing_tools=[];
		$missing_prompts=[];
		foreach($selected as $playbook){
			$prompt=(string)($playbook['prompt'] ?? '');
			if($prompt!=='' && !in_array($prompt, $prompt_names, true)){
				$missing_prompts[]=$prompt;
			}
			foreach($playbook['steps'] ?? [] as $step){
				$tool=(string)($step['tool'] ?? '');
				if($tool!=='' && !in_array($tool, $tool_names, true)){
					$missing_tools[]=$tool;
				}
			}
		}
		$includes_release=array_key_exists('release', $selected);
		$not_ordinary_app_requirements=$includes_release ? [
			'dataphyre_mcp_verify_all',
			'dev/tools helper scripts',
			'maintainer/source-checkout evidence',
			'Dataphyre hot-path benchmarks',
		] : [
			'MCP/release-surface publication validation',
			'maintainer-only Dataphyre runtime proof',
			'Dataphyre shared hot-path benchmark evidence',
		];
		$payload=[
			'export_type'=>'dataphyre_mcp_workflow_playbook_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'workflow'=>$workflow,
			'protocol'=>'2025-11-25',
			'playbooks'=>$selected,
			'playbook_count'=>count($selected),
			'step_count'=>array_sum(array_map(static fn(array $playbook): int => count($playbook['steps'] ?? []), $selected)),
			'missing_registered_tools'=>array_values(array_unique($missing_tools)),
			'missing_registered_prompts'=>array_values(array_unique($missing_prompts)),
			'playbook_policy'=>[
				'application_default_workflows'=>array_values(array_intersect(array_keys($selected), ['feature', 'routes', 'sql', 'diagnostics', 'client'])),
				'publication_validation_workflows'=>array_values(array_intersect(array_keys($selected), ['release'])),
				'ordinary_app_first_step'=>'dataphyre_app_builder_plan_generate',
				'ordinary_app_optional_context'=>'dataphyre_task_pack_generate payload_profile=builder',
				'ordinary_app_broader_cold_start'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'ordinary_app_detail_profile'=>'dataphyre_mcp_task_start_pack_export payload_profile=detail adds contracts and discovery while app-builder bulk stays paginated',
				'ordinary_app_deep_profile'=>'dataphyre_mcp_task_start_pack_export payload_profile=deep only for explicit escalation evidence such as release-facing, corporate-ready, security/governance-sensitive, or Dataphyre hot-path work',
				'ordinary_app_agent_verification'=>'focused application or module verification',
				'client_setup_validation'=>'dataphyre_mcp_live_validate',
				'client_setup_validation_boundary'=>'Use only after MCP client wiring, local server entrypoint, or stdio setup changes; not ordinary app behavior proof.',
				'ordinary_app_verification'=>'focused app/module checks from app-builder verification_handoff or dataphyre_verification_surface_catalog',
				'ordinary_app_chunking'=>'Follow entity_planning.continuation_calls until deferred_entities is empty for large app scaffolds.',
				'ordinary_app_first_page_fields'=>[
					'builder_response.first_read.next_action',
					'files_summary',
					'schema_summary',
					'naming_contract',
					'write_readiness',
					'scaffold_completion_summary',
					'verification_handoff',
					'detail_pagination',
				],
				'ordinary_app_detail_handoff_fields'=>[
					'entity_planning.continuation_calls',
					'surface_execution_plan',
					'companion_surface_handoff',
					'relationship_adapter_handoff',
					'tenant_identity_handoff',
					'data_model_handoff',
					'data_sensitivity_summary',
					'policy_decision_register',
					'prewrite_checklist.prewrite_blockers',
					'prewrite_checklist.implementation_obligations',
					'code_skeleton_summary',
					'local_convention_probe',
					'implementation_matrix',
					'implementation_recipe',
					'verification_evidence',
					'verification_handoff',
					'verification_execution_plan',
					'acceptance_review_plan',
					'verification_recovery_plan',
				],
				'ordinary_app_write_readiness'=>'Resolve prewrite_checklist.prewrite_blockers before writing app-owned files; complete implementation_obligations and prewrite_reminders such as adaptation_notes during app-owned edits.',
				'not_ordinary_app_agent_requirements'=>$not_ordinary_app_requirements,
			],
			'usage_notes'=>[
				'Playbooks default to application agents building apps; release playbooks are heavier and should be used for release-facing, public, corporate-ready, or MCP-surface claims.',
				'Playbooks are ordered read-only guidance; clients choose whether to execute each tools/call step.',
				'Placeholder arguments such as manifest_path should be replaced with values returned by earlier steps.',
				'Use dataphyre_mcp_tool_call_examples_export when a client needs raw JSON-RPC request payloads for these workflows.',
			],
		];
		if($includes_release){
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('workflow_playbook');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('workflow_playbook');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}else{
			$payload['governance_notes']=[
				'status'=>'not inlined for application playbooks',
				'default_lane'=>'application_workflow_playbook',
				'ordinary_app_work'=>'follow ordered read-only metadata steps before app-owned edits',
			];
			$payload['context_links']=array_replace($this->mcp_lightweight_discovery_context_links(), [
				'release_playbook'=>'dataphyre_mcp_workflow_playbook_export workflow=release',
			]);
		}
		return $payload;
	}

	/**
	 * Audits workflow readiness against available tools, resources, and prompts.
	 *
	 * @param array{workflow?:'feature'|'routes'|'sql'|'diagnostics'|'client'|'release'|'all'|string} $args Workflow and readiness options.
	 * @return array Workflow readiness audit payload.
	 */
	private function mcp_workflow_readiness_audit(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'all')));
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release', 'all'], true)){
			$workflow='all';
		}
		$playbook_export=$this->mcp_workflow_playbook_export(['workflow'=>$workflow]);
		$docs_coverage=$this->mcp_docs_coverage_report();
		$prompt_catalog=$this->mcp_prompt_catalog([]);
		$example_workflow_map=[
			'feature'=>'docs',
			'routes'=>'routes',
			'sql'=>'sql',
			'diagnostics'=>'diagnostics',
			'client'=>'client',
			'release'=>'validation',
		];
		$prompt_names=array_values(array_filter(array_map('strval', is_array($prompt_catalog['available_prompts'] ?? null) ? $prompt_catalog['available_prompts'] : []), static fn(string $name): bool => $name!==''));
		if($prompt_names===[]){
			foreach($prompt_catalog['prompts'] ?? [] as $prompt){
				$name=(string)($prompt['name'] ?? '');
				if($name!==''){
					$prompt_names[]=$name;
				}
			}
		}
		$audits=[];
		foreach($playbook_export['playbooks'] ?? [] as $key=>$playbook){
			$steps=is_array($playbook['steps'] ?? null) ? $playbook['steps'] : [];
			$tools=array_values(array_filter(array_map(static fn(array $step): string => (string)($step['tool'] ?? ''), $steps)));
			$example_workflow=$example_workflow_map[$key] ?? 'all';
			$examples=$this->mcp_tool_call_examples_export(['workflow'=>$example_workflow]);
			$prompt=(string)($playbook['prompt'] ?? '');
			$missing_tools=[];
			foreach($tools as $tool){
				if(in_array($tool, $playbook_export['missing_registered_tools'] ?? [], true)){
					$missing_tools[]=$tool;
				}
			}
			$checks=[
				'has_registered_prompt'=>$prompt!=='' && in_array($prompt, $prompt_names, true),
				'has_steps'=>count($steps)>0,
				'has_tool_examples'=>(int)($examples['example_count'] ?? 0)>0 && ($examples['missing_registered_tools'] ?? [])===[],
				'docs_cover_live_surfaces'=>(int)($docs_coverage['counts']['missing_tools'] ?? 1)===0 && (int)($docs_coverage['counts']['missing_core_resources'] ?? 1)===0,
				'registered_tools_complete'=>$missing_tools===[],
			];
			$audits[$key]=[
				'title'=>(string)($playbook['title'] ?? $key),
				'prompt'=>$prompt,
				'step_count'=>count($steps),
				'tools'=>$tools,
				'example_workflow'=>$example_workflow,
				'example_count'=>(int)($examples['example_count'] ?? 0),
				'checks'=>$checks,
				'ready'=>!in_array(false, $checks, true),
				'notes'=>[
					'Use dataphyre_mcp_workflow_playbook_export for ordered steps.',
					'Use dataphyre_mcp_tool_call_examples_export for concrete JSON-RPC request payloads.',
				],
			];
		}
		$ready_count=count(array_filter($audits, static fn(array $audit): bool => ($audit['ready'] ?? false)===true));
		return [
			'audit_type'=>'dataphyre_mcp_workflow_readiness_audit',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'workflow'=>$workflow,
			'protocol'=>'2025-11-25',
			'playbook_count'=>count($audits),
			'ready_count'=>$ready_count,
			'all_ready'=>$ready_count===count($audits),
			'missing_registered_tools'=>$playbook_export['missing_registered_tools'] ?? [],
			'missing_registered_prompts'=>$playbook_export['missing_registered_prompts'] ?? [],
			'docs_coverage_counts'=>$docs_coverage['counts'] ?? [],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('workflow_readiness_audit'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('workflow_readiness_audit'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'workflow_audits'=>$audits,
			'usage_notes'=>[
				'This audit does not execute playbook steps; it checks whether supporting MCP surfaces are registered and documented.',
				'Use focused application or module verification for app workflow results; use dataphyre_mcp_verify_all only for MCP/release-surface workflow claims.',
				'Keep workflow examples and playbooks generic; product-local paths belong only in private client configuration.',
			],
		];
	}

	/**
	 * Exports a workflow session scaffold for MCP-aware clients.
	 *
	 * @param array{workflow?:'feature'|'routes'|'sql'|'diagnostics'|'client'|'release'|string,include_frames?:bool} $args Session metadata and selected workflow options.
	 * @return array Workflow session payload.
	 */
	private function mcp_workflow_session_export(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'client')));
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release'], true)){
			$workflow='client';
		}
		$include_frames=($args['include_frames'] ?? true)!==false;
		$playbook_export=$this->mcp_workflow_playbook_export(['workflow'=>$workflow]);
		$readiness=$this->mcp_workflow_readiness_audit(['workflow'=>$workflow]);
		$playbook=$playbook_export['playbooks'][$workflow] ?? [];
		$messages=[
			[
				'name'=>'initialize',
				'message'=>[
					'jsonrpc'=>'2.0',
					'id'=>1,
					'method'=>'initialize',
					'params'=>[
						'protocolVersion'=>'2025-11-25',
						'capabilities'=>(object)[],
						'clientInfo'=>['name'=>'dataphyre-mcp-workflow-session', 'version'=>'1.0.0'],
					],
				],
				'purpose'=>'Negotiate MCP protocol and server capabilities.',
			],
			[
				'name'=>'tools/list',
				'message'=>['jsonrpc'=>'2.0', 'id'=>2, 'method'=>'tools/list', 'params'=>(object)[]],
				'purpose'=>'Confirm tool registration before workflow calls.',
			],
		];
		$id=10;
		foreach(($playbook['steps'] ?? []) as $step){
			$messages[]=[
				'name'=>(string)($step['tool'] ?? ''),
				'message'=>[
					'jsonrpc'=>'2.0',
					'id'=>$id++,
					'method'=>'tools/call',
					'params'=>[
						'name'=>(string)($step['tool'] ?? ''),
						'arguments'=>(object)($step['arguments'] ?? []),
					],
				],
				'purpose'=>(string)($step['purpose'] ?? ''),
				'order'=>(int)($step['order'] ?? 0),
			];
		}
		$session_messages=[];
		foreach($messages as $entry){
			$message=$entry['message'];
			$json=json_encode($message, JSON_UNESCAPED_SLASHES);
			$session=$entry;
			$session['json']=$json;
			if($include_frames){
				$session['frame']='Content-Length: '.strlen((string)$json)."\r\n\r\n".$json;
			}
			$session_messages[]=$session;
		}
		return [
			'export_type'=>'dataphyre_mcp_workflow_session_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'workflow'=>$workflow,
			'protocol'=>'2025-11-25',
			'transport'=>'stdio',
			'include_frames'=>$include_frames,
			'playbook_title'=>(string)($playbook['title'] ?? $workflow),
			'readiness'=>[
				'all_ready'=>$readiness['all_ready'] ?? false,
				'ready_count'=>$readiness['ready_count'] ?? 0,
				'playbook_count'=>$readiness['playbook_count'] ?? 0,
				'missing_registered_tools'=>$readiness['missing_registered_tools'] ?? [],
				'missing_registered_prompts'=>$readiness['missing_registered_prompts'] ?? [],
			],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('workflow_session'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('workflow_session'),
			'tool_audience_boundaries'=>$readiness['tool_audience_boundaries'] ?? $this->mcp_current_tool_audience_boundaries(),
			'message_count'=>count($session_messages),
			'session_messages'=>$session_messages,
			'usage_notes'=>[
				'Session export does not execute the messages; clients must send them to the stdio server in order.',
				'Content-Length values are byte counts for the JSON payloads in this export.',
				'Replace placeholder arguments, such as manifest_path, with values returned by earlier workflow calls before sending dependent messages.',
			],
		];
	}

	/**
	 * Returns the catalog of supported MCP workflow definitions.
	 *
	 * @return array Workflow catalog rows.
	 */
	private function mcp_workflow_catalog(): array {
		$playbook_export=$this->mcp_workflow_playbook_export(['workflow'=>'all']);
		$readiness=$this->mcp_workflow_readiness_audit(['workflow'=>'all']);
		$workflows=[];
		foreach($playbook_export['playbooks'] ?? [] as $name=>$playbook){
			$audit=$readiness['workflow_audits'][$name] ?? [];
			$steps=is_array($playbook['steps'] ?? null) ? $playbook['steps'] : [];
			$workflows[]=[
				'workflow'=>(string)$name,
				'title'=>(string)($playbook['title'] ?? $name),
				'goal'=>(string)($playbook['goal'] ?? ''),
				'prompt'=>(string)($playbook['prompt'] ?? ''),
				'ready'=>($audit['ready'] ?? false)===true,
				'step_count'=>count($steps),
				'example_workflow'=>(string)($audit['example_workflow'] ?? ''),
				'example_count'=>(int)($audit['example_count'] ?? 0),
				'primary_tools'=>array_values(array_slice(array_map(static fn(array $step): string => (string)($step['tool'] ?? ''), $steps), 0, 8)),
				'exports'=>[
					'playbook'=>'dataphyre_mcp_workflow_playbook_export',
					'readiness'=>'dataphyre_mcp_workflow_readiness_audit',
					'session'=>'dataphyre_mcp_workflow_session_export',
					'handoff_pack'=>'dataphyre_mcp_workflow_handoff_pack_export',
					'transcript_schema'=>'dataphyre_mcp_workflow_transcript_schema_export',
					'transcript_audit'=>'dataphyre_mcp_workflow_transcript_audit',
					'transcript_summary'=>'dataphyre_mcp_workflow_transcript_summary_export',
					'checkpoint'=>'dataphyre_mcp_workflow_checkpoint_export',
				],
			];
		}
		return [
			'catalog_type'=>'dataphyre_mcp_workflow_catalog',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'workflow_count'=>count($workflows),
			'ready_count'=>count(array_filter($workflows, static fn(array $workflow): bool => ($workflow['ready'] ?? false)===true)),
			'workflows'=>$workflows,
			'recommended_start'=>'dataphyre_mcp_workflow_handoff_pack_export',
			'post_run_handoff'=>'dataphyre_mcp_workflow_transcript_summary_export',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('workflow_catalog'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('workflow_catalog'),
			'usage_notes'=>[
				'Use the catalog to choose a workflow before exporting a full handoff pack.',
				'Catalog readiness is derived from live workflow readiness audits.',
				'The catalog does not execute sessions, capture transcripts, or store client data.',
			],
		];
	}

	/**
	 * Exports lifecycle phases, transitions, and expected artifacts for workflows.
	 *
	 * @param array<string,mixed> $args Workflow lifecycle options.
	 * @return array Workflow lifecycle payload.
	 */
	private function mcp_workflow_lifecycle_export(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'all')));
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release', 'all'], true)){
			$workflow='all';
		}
		$catalog=$this->mcp_workflow_catalog();
		$workflows=array_values(array_filter($catalog['workflows'] ?? [], static fn(array $entry): bool => $workflow==='all' || (string)($entry['workflow'] ?? '')===$workflow));
		$lifecycle=[
			[
				'phase'=>'start',
				'tool'=>'dataphyre_mcp_task_start_pack_export',
				'purpose'=>'Bundle task text, status, safety, guidance, discovery matches, and recommended workflow handoff.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'choose_workflow',
				'tool'=>'dataphyre_mcp_workflow_recommend',
				'purpose'=>'Rank workflows deterministically when task text is available.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'pre_run_handoff',
				'tool'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'purpose'=>'Export playbook, readiness, session messages, transcript schema, and post-run tools.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'client_run',
				'tool'=>'dataphyre_mcp_workflow_session_export',
				'purpose'=>'Client sends exported JSON-RPC messages in order; the export itself does not execute them.',
				'execution'=>'client_executed_outside_export',
			],
			[
				'phase'=>'capture',
				'tool'=>'dataphyre_mcp_workflow_transcript_schema_export',
				'purpose'=>'Capture bounded, redacted request/response summaries and result keys.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'audit',
				'tool'=>'dataphyre_mcp_workflow_transcript_audit',
				'purpose'=>'Check transcript shape, registered tool names, status values, and redaction risk.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'checkpoint',
				'tool'=>'dataphyre_mcp_workflow_checkpoint_export',
				'purpose'=>'Report progress counts, checkpoint status, safe handoff flags, and next actions.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'handoff_summary',
				'tool'=>'dataphyre_mcp_workflow_transcript_summary_export',
				'purpose'=>'Give agents compact step summaries, audit status, result keys, and next tools.',
				'execution'=>'not_executed',
			],
			[
				'phase'=>'verify',
				'tool'=>'dataphyre_verification_surface_catalog',
				'purpose'=>'Choose focused application or module verification for app behavior; use publication_validation for MCP/release-surface claims.',
				'execution'=>'read_only_catalog',
				'audience_scope'=>'ordinary_app_work',
			],
		];
		return [
			'export_type'=>'dataphyre_mcp_workflow_lifecycle_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'workflow'=>$workflow,
			'protocol'=>'2025-11-25',
			'workflow_count'=>count($workflows),
			'workflows'=>array_values(array_map(static fn(array $entry): array => [
				'workflow'=>(string)($entry['workflow'] ?? ''),
				'title'=>(string)($entry['title'] ?? ''),
				'ready'=>($entry['ready'] ?? false)===true,
				'step_count'=>(int)($entry['step_count'] ?? 0),
				'primary_tools'=>$entry['primary_tools'] ?? [],
			], $workflows)),
			'lifecycle'=>$lifecycle,
			'capture_policy'=>[
				'Store transcripts client-side only; the MCP server does not persist workflow runs.',
				'Capture summaries and result keys, not raw response bodies.',
				'Redact secrets, credentials, cookies, private keys, connection strings, and product-local paths before sharing.',
			],
			'recommended_entrypoint'=>'dataphyre_mcp_task_start_pack_export',
			'recommended_checkpoint'=>'dataphyre_mcp_workflow_checkpoint_export',
			'publication_validation'=>[
				'audience_scope'=>'publication_validation_not_ordinary_app_work',
				'recommended_gate'=>'dataphyre_mcp_verify_all',
				'applies_to'=>'MCP/release-surface workflow claims, published shared MCP setup docs, release notes, or MCP server wiring changes.',
				'app_behavior_verification'=>'Use focused application or module verification for application behavior.',
			],
			'release_gate_policy'=>[
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'claim_boundary'=>'Use dataphyre_mcp_verify_all only for MCP/release-surface workflow claims, published shared MCP setup docs, release notes, or MCP server wiring changes.',
				'app_behavior_verification'=>'Use focused application or module verification for application behavior.',
			],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('workflow_lifecycle'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('workflow_lifecycle'),
			'usage_notes'=>[
				'This lifecycle export is a runbook only; it does not send MCP frames or replay transcripts.',
				'Use workflow=all for client documentation and a specific workflow for focused agent handoff.',
				'Pair this with dataphyre_mcp_workflow_catalog when showing available workflow choices.',
			],
		];
	}

	/**
	 * Recommends the next workflow action from state and readiness evidence.
	 *
	 * @param array<string,mixed> $args Workflow state and recommendation options.
	 * @return array Next-action payload.
	 */
	private function mcp_workflow_next_action_export(array $args): array {
		$workflow=strtolower(trim((string)($args['workflow'] ?? 'generic')));
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release', 'generic'], true)){
			$workflow='generic';
		}
		$task=trim((string)($args['task'] ?? ''));
		$release_claim=$this->mcp_task_implies_release_claim($task);
		$app_builder_task=$this->mcp_task_implies_app_builder($task);
		$has_transcript=(isset($args['transcript']) && is_array($args['transcript'])) || trim((string)($args['transcript_json'] ?? ''))!=='';
		$has_state=(isset($args['state']) && is_array($args['state'])) || trim((string)($args['state_json'] ?? ''))!=='';
		$state_summary=$has_state ? $this->mcp_workflow_state_summary_export($args) : [];
		$checkpoint=$has_transcript ? $this->mcp_workflow_checkpoint_export($args) : [];
		if($has_state){
			$state_audit_passed=($state_summary['audit_passed'] ?? false)===true;
			$phase=(string)($state_summary['current_phase'] ?? '');
			$checkpoint_status=(string)($state_summary['checkpoint_status'] ?? '');
			if(!$state_audit_passed){
				$decision='review_transcript';
				$recommended_tool='dataphyre_mcp_workflow_state_audit';
			}
			elseif($phase==='done'){
				$decision='done';
				$recommended_tool='dataphyre_mcp_workflow_state_sync_pack_export';
			}
			elseif($checkpoint_status==='blocked'){
				$decision='fix_blocked_transcript';
				$recommended_tool='dataphyre_mcp_workflow_state_audit';
			}
			elseif(in_array($phase, ['checkpoint', 'handoff_summary', 'verify'], true)){
				$decision='summarize_or_verify';
				$recommended_tool='dataphyre_mcp_workflow_state_summary_export';
			}
			elseif(in_array($phase, ['client_run', 'capture', 'audit'], true)){
				$decision='review_transcript';
				$recommended_tool='dataphyre_mcp_workflow_transcript_audit';
			}
			else{
				$decision='start_workflow';
				$recommended_tool='dataphyre_mcp_workflow_handoff_pack_export';
			}
		}
		elseif($has_transcript){
			$status=(string)($checkpoint['checkpoint_status'] ?? 'needs_review');
			$decision=match($status){
				'healthy'=>'summarize_or_verify',
				'blocked'=>'fix_blocked_transcript',
				'empty'=>'start_workflow',
				default=>'review_transcript',
			};
			$recommended_tool=match($decision){
				'summarize_or_verify'=>'dataphyre_mcp_workflow_transcript_summary_export',
				'fix_blocked_transcript', 'review_transcript'=>'dataphyre_mcp_workflow_transcript_audit',
				default=>'dataphyre_mcp_task_start_pack_export',
			};
		}else{
			$decision='start_workflow';
			$recommended_tool=$app_builder_task && !$release_claim ? 'dataphyre_app_builder_plan_generate' : 'dataphyre_mcp_task_start_pack_export';
		}
		$brief=$this->mcp_agent_brief_export([
			'task'=>$args['task'] ?? '',
			'target'=>'generic',
			'limit'=>4,
		]);
		$payload=[
			'export_type'=>'dataphyre_mcp_workflow_next_action_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$task,
			'workflow'=>$has_state ? (string)($state_summary['workflow'] ?? $workflow) : ($has_transcript ? (string)($checkpoint['workflow'] ?? $workflow) : (string)($brief['selected_workflow'] ?? $workflow)),
			'has_state'=>$has_state,
			'has_transcript'=>$has_transcript,
			'decision'=>$decision,
			'recommended_tool'=>$recommended_tool,
			'recommended_arguments'=>$has_state
				? ['workflow'=>$workflow, 'state'=>'<client workflow state object>']
				: ($has_transcript
					? ['workflow'=>$workflow, 'transcript'=>'<client transcript object>']
					: ($app_builder_task && !$release_claim ? ['task'=>$task, 'payload_profile'=>'compact'] : ['task'=>$task, 'target'=>'generic', 'include_frames'=>false])),
			'enterprise_preflight'=>$release_claim ? [
				'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
				'arguments'=>['feature'=>$task, 'public_claim'=>true],
				'runtime_quality_contract'=>'maintainer/source-checkout runtime quality gates',
				'purpose'=>'Audit enterprise contract evidence before agent-first, corporate-ready, public, or release-facing claims.',
			] : null,
			'publication_validation'=>$release_claim ? $this->mcp_publication_validation_contract('workflow_next_action') : [],
			'state'=>$has_state ? [
				'state_id'=>$state_summary['state_id'] ?? '',
				'current_phase'=>$state_summary['current_phase'] ?? '',
				'last_decision'=>$state_summary['last_decision'] ?? '',
				'checkpoint_status'=>$state_summary['checkpoint_status'] ?? '',
				'audit_passed'=>$state_summary['audit_passed'] ?? false,
				'pending_tool_count'=>$state_summary['pending_tool_count'] ?? 0,
				'completed_tool_count'=>$state_summary['completed_tool_count'] ?? 0,
				'agent_handoff'=>$state_summary['agent_handoff'] ?? [],
			] : null,
			'checkpoint'=>$has_transcript ? [
				'checkpoint_status'=>$checkpoint['checkpoint_status'] ?? '',
				'safe_to_share'=>$checkpoint['safe_to_share'] ?? false,
				'progress'=>$checkpoint['progress'] ?? [],
				'audit'=>$checkpoint['audit'] ?? [],
			] : null,
			'brief'=>[
				'selected_workflow'=>$brief['selected_workflow'] ?? '',
				'ready_to_run'=>$brief['ready_to_run'] ?? false,
				'next_actions'=>$brief['next_actions'] ?? [],
			],
			'decision_notes'=>[
				'start_workflow'=>'No transcript was provided, or the transcript is empty; begin with a start pack and pre-run handoff.',
				'review_transcript'=>'Transcript metadata exists but needs audit review before handoff.',
				'fix_blocked_transcript'=>'A failed step or failed final status blocks safe handoff; inspect audit findings first.',
				'summarize_or_verify'=>'Transcript checkpoint is healthy; summarize for handoff, use focused application/module verification for app behavior, and reserve publication validation for MCP/release-surface claims.',
				'done'=>'Workflow state is marked done; use focused application/module verification for app behavior and publication validation only for MCP/release-surface claims.',
			],
			'usage_notes'=>[
				'This decision helper does not execute workflow messages or persist transcript or workflow state.',
				'Use client-captured transcripts and workflow state with summaries and result keys, not raw response bodies.',
				'When in doubt, follow the recommended_tool and keep safety_boundary guidance in scope.',
			],
		];
		if($app_builder_task && !$release_claim && !$has_state && !$has_transcript){
			$payload['app_builder_next_action']=$this->mcp_app_builder_next_action();
			$payload['governance_notes']=$this->mcp_workflow_app_builder_governance_notes($task);
			$payload['context_links']=[
				'start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'tool_audience_boundaries'=>'dataphyre_mcp_readiness_report',
				'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
				'safety_boundary'=>'dataphyre_mcp_safety_boundary_report',
			];
		}else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('workflow_next_action');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('workflow_next_action');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

	/**
	 * Returns the compact app-builder continuation contract for workflow routing surfaces.
	 *
	 * @param array<string,mixed> $next_action Optional live builder next_action to mirror.
	 * @return array<string,mixed> App-builder next action metadata.
	 */
	private function mcp_app_builder_next_action(array $next_action=[]): array {
		$payload=[
			'tool'=>'dataphyre_app_builder_plan_generate',
			'argument_defaults'=>['payload_profile'=>'compact'],
			'decision_field'=>'builder_response.first_read.next_action',
			'detail_decision_field'=>'builder_response.first_read.next_detail_page',
			'resume_cursor_source'=>'builder_response.first_read.next_action.resume_cursor or mirrored first-read next_action.resume_cursor',
			'current_status'=>(string)($next_action['status'] ?? ''),
			'current_resume_cursor'=>is_array($next_action['resume_cursor'] ?? null) ? $next_action['resume_cursor'] : [],
			'mirrored_fields'=>['builder_response.first_read.next_action', 'builder_response.first_read.next_detail_page', 'builder_first_read.next_action', 'builder_first_read.next_detail_page'],
			'statuses'=>['continue_entity_chunks', 'resolve_prewrite_blockers', 'ready_for_app_owned_writes'],
			'chunking'=>'Follow entity_planning.continuation_calls until deferred_entities is empty for large app scaffolds.',
			'chunk_budget'=>$this->mcp_app_builder_workload_budget()['chunk_budget'] ?? [],
			'argument_hint'=>$this->mcp_app_builder_argument_hint(),
			'detail_pagination'=>$this->mcp_app_builder_detail_pagination(),
			'handoff_fields'=>[
				'scaffold_completion_summary',
				'entity_planning.continuation_calls',
				'surface_execution_plan',
				'companion_surface_handoff',
				'relationship_adapter_handoff',
				'tenant_identity_handoff',
				'app_path_context',
				'field_metadata_summary',
				'data_model_handoff',
				'data_sensitivity_summary',
				'policy_decision_register',
				'prewrite_checklist.prewrite_blockers',
				'prewrite_checklist.implementation_obligations',
				'code_skeleton_summary',
				'local_convention_probe',
				'implementation_matrix',
				'implementation_recipe',
				'verification_evidence',
				'verification_handoff',
				'verification_execution_plan',
				'acceptance_review_plan',
				'verification_recovery_plan',
				'diagnostic_handoff_hint',
			],
			'apply_audit_handoff'=>[
				'tool'=>'dataphyre_apply_audit_plan',
				'when'=>'After write_readiness.status is ready_for_app_owned_writes and before any caller-owned write-capable apply workflow.',
				'arguments'=>[
					'task'=>'<same task>',
					'proposed_files'=>'builder_response.write_plan_summary or app_builder_summary.files_to_create',
					'verification'=>'builder_response.verification or verification_evidence tool names',
				],
				'decision_field'=>'apply_next_action.status',
				'not_required'=>[
					'maintainer release gate for ordinary app-owned files',
					'dataphyre_mcp_verify_all for ordinary application behavior',
					'Dataphyre runtime-internal review when writes stay app-owned',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			],
			'write_readiness'=>'Before writing app-owned files, resolve prewrite_checklist.prewrite_blockers, including placeholder paths when app_path_context.placeholder_mode=true, preserve continuation arguments when scaffold_completion_summary is incomplete, and complete implementation_obligations plus prewrite_reminders such as adaptation_notes during app-owned edits.',
			'verification'=>'After app-owned writes, follow verification_execution_plan.items, then acceptance_review_plan.items, and collect focused app/module verification_evidence and verification_handoff; if a focused check fails, use verification_recovery_plan.branches before broader diagnostics. MCP/release-surface validation is not ordinary app proof.',
			'governance'=>'Keep governance collapsed unless the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
		];
		return $this->mcp_app_builder_compact_handoff_field_refs($payload);
	}

	/**
	 * Recommends a workflow template for an incoming task description.
	 *
	 * @param array<string,mixed> $args Task description and selection options.
	 * @return array Workflow recommendation payload.
	 */
	private function mcp_workflow_recommend(array $args): array {
		$task_raw=trim((string)($args['task'] ?? ''));
		$task=strtolower($task_raw);
		$limit=max(1, min(6, (int)($args['limit'] ?? 3)));
		$release_claim=$this->mcp_task_implies_release_claim($task_raw);
		$app_builder_task=$this->mcp_task_implies_app_builder($task_raw);
		$catalog=$this->mcp_workflow_catalog();
		$keywords=[
			'feature'=>['feature', 'build', 'app', 'crud', 'resource', 'panel', 'plan', 'scaffold', 'implement', 'context', 'agent', 'verification', 'change'],
			'routes'=>['route', 'routing', 'url', 'controller', 'middleware', 'manifest', 'dispatch', 'path'],
			'sql'=>['sql', 'schema', 'table', 'query', 'database', 'cluster', 'migration', 'select'],
			'diagnostics'=>['diagnostic', 'debug', 'error', 'log', 'tracelog', 'trace', 'exception', 'failure'],
			'client'=>['client', 'setup', 'install', 'stdio', 'config', 'onboard', 'cursor', 'claude', 'codex'],
			'release'=>['release', 'verify', 'validation', 'ship', 'notes', 'coverage', 'check', 'publish'],
		];
		$recommendations=[];
		foreach($catalog['workflows'] ?? [] as $workflow){
			$name=(string)($workflow['workflow'] ?? '');
			$haystack=strtolower(implode(' ', [
				$name,
				(string)($workflow['title'] ?? ''),
				(string)($workflow['goal'] ?? ''),
				(string)($workflow['prompt'] ?? ''),
				implode(' ', is_array($workflow['primary_tools'] ?? null) ? $workflow['primary_tools'] : []),
			]));
			$score=0;
			$matched=[];
			foreach(($keywords[$name] ?? []) as $keyword){
				if($task!=='' && str_contains($task, $keyword)){
					$score+=3;
					$matched[]=$keyword;
				}
				elseif($task!=='' && str_contains($haystack, $keyword) && preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $task)===1){
					$score+=1;
					$matched[]=$keyword;
				}
			}
			foreach(preg_split('/[^a-z0-9_]+/', $task) ?: [] as $token){
				$token=trim($token);
				if(strlen($token)>=4 && str_contains($haystack, $token)){
					$score++;
					$matched[]=$token;
				}
			}
			if($name!=='' && str_contains($task, $name)){
				$score+=5;
				$matched[]=$name;
			}
			if(($workflow['ready'] ?? false)===true){
				$score++;
			}
			if($app_builder_task && !$release_claim){
				if($name==='feature'){
					$score+=6;
					$matched[]='app_builder';
				}
				if($name==='release'){
					$score=max(0, $score-3);
				}
			}
			$recommendations[]=[
				'workflow'=>$name,
				'title'=>(string)($workflow['title'] ?? ''),
				'score'=>$score,
				'ready'=>($workflow['ready'] ?? false)===true,
				'matched_terms'=>array_values(array_unique($matched)),
				'recommended_tool'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'recommended_arguments'=>['workflow'=>$name],
				'post_run_tool'=>'dataphyre_mcp_workflow_transcript_summary_export',
			];
		}
		usort($recommendations, static fn(array $a, array $b): int => (($b['score'] ?? 0)<=>($a['score'] ?? 0)) ?: strcmp((string)($a['workflow'] ?? ''), (string)($b['workflow'] ?? '')));
		$selected=array_values(array_slice($recommendations, 0, $limit));
		$payload=[
			'recommendation_type'=>'dataphyre_mcp_workflow_recommend',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$task,
			'limit'=>$limit,
			'app_builder_entrypoint'=>$app_builder_task ? [
				'tool'=>'dataphyre_app_builder_plan_generate',
				'arguments'=>['task'=>$task_raw, 'payload_profile'=>'compact'],
				'when'=>'ordinary application creation, admin Panel CRUD, resource, schema, table, filter, action, or verification work',
				'purpose'=>'Start with files, schema, data model, Panel fields, filters, actions, scaffold completion, sensitivity hints, prewrite checks, verification evidence, and collapsed governance before opening broader workflow context.',
			] : null,
			'app_builder_next_action'=>$app_builder_task && !$release_claim ? $this->mcp_app_builder_next_action() : null,
			'recommendations'=>$selected,
			'enterprise_preflight'=>$release_claim ? [
				'tool'=>'dataphyre_mcp_enterprise_adoption_audit',
				'arguments'=>['feature'=>$task_raw, 'public_claim'=>true],
				'runtime_quality_contract'=>'maintainer/source-checkout runtime quality gates',
				'purpose'=>'Run before describing the result as enterprise-ready, agent-first, corporate-ready, public, or release-facing.',
			] : null,
			'fallback_tool'=>'dataphyre_mcp_workflow_catalog',
			'usage_notes'=>[
				'Recommendations are deterministic keyword matches over the live workflow catalog.',
				$app_builder_task ? 'For ordinary app-building tasks, call dataphyre_app_builder_plan_generate before workflow handoff context.' : 'For ordinary app-building tasks, use dataphyre_app_builder_plan_generate as the golden-path planner.',
				'Use the recommended handoff pack before sending workflow messages.',
				'If scores are tied or low, inspect dataphyre_mcp_workflow_catalog before choosing.',
			],
		];
		if($app_builder_task && !$release_claim){
			$payload['governance_notes']=$this->mcp_workflow_app_builder_governance_notes($task_raw);
			$payload['context_links']=[
				'workflow_catalog'=>'dataphyre_mcp_workflow_catalog',
				'start_pack'=>'dataphyre_mcp_task_start_pack_export payload_profile=builder',
				'tool_audience_boundaries'=>'dataphyre_mcp_readiness_report',
				'enterprise_audit'=>'dataphyre_mcp_enterprise_adoption_audit',
			];
		}else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('workflow_recommend');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('workflow_recommend');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

	/**
	 * Detects ordinary app-building tasks that should start with the builder plan.
	 *
	 * @param string $task User task text.
	 * @return bool True when the task looks like application creation or CRUD/resource work.
	 */
	private function mcp_task_implies_app_builder(string $task): bool {
		$task=strtolower($task);
		if($task===''){
			return false;
		}
		$app_terms=['app', 'application', 'crud', 'admin', 'panel', 'resource', 'resources', 'schema', 'table', 'tables', 'filters', 'actions', 'verification', 'tracker', 'dashboard', 'internal tool'];
		$planning_app_terms=['app', 'application', 'crud', 'admin', 'panel', 'resource', 'resources', 'tracker', 'dashboard', 'internal tool'];
		$build_terms=['build', 'create', 'make', 'add', 'scaffold', 'implement', 'generate'];
		$planning_terms=['plan', 'design', 'draft', 'prototype'];
		$structure_terms=['crud', 'admin', 'panel', 'resource', 'resources', 'schema', 'table', 'tables', 'filters', 'actions', 'tracker'];
		$support_terms=['performance', 'optimize', 'optimise', 'speed up', 'slow', 'latency', 'cache', 'caching', 'debug', 'fix', 'bug', 'issue', 'support', 'diagnose', 'triage'];
		$has_app=false;
		foreach($app_terms as $term){
			if(str_contains($task, $term)){
				$has_app=true;
				break;
			}
		}
		if($has_app===false){
			return false;
		}
		$has_structure=false;
		foreach($structure_terms as $term){
			if(str_contains($task, $term)){
				$has_structure=true;
				break;
			}
		}
		if($has_structure===false){
			foreach($support_terms as $term){
				if(str_contains($task, $term)){
					return false;
				}
			}
		}
		foreach($build_terms as $term){
			if(str_contains($task, $term)){
				return true;
			}
		}
		$has_planning_app=false;
		foreach($planning_app_terms as $term){
			if(str_contains($task, $term)){
				$has_planning_app=true;
				break;
			}
		}
		if($has_planning_app){
			foreach($planning_terms as $term){
				if(str_contains($task, $term)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns proportional policy hints for app-builder workflow routing.
	 *
	 * @param string $task User task text.
	 * @return array<string,mixed> Compact governance note for workflow routing.
	 */
	private function mcp_workflow_app_builder_governance_notes(string $task): array {
		$lower=strtolower($task);
		$categories=[];
		foreach([$this->app_builder_sensitivity_rules(), $this->app_builder_entity_sensitivity_rules()] as $rules){
			foreach($rules as $category=>$needles){
				foreach($needles as $needle){
					$needle=strtolower((string)$needle);
					if($needle!=='' && str_contains($lower, $needle)){
						$categories[]=(string)$category;
						break;
					}
				}
			}
		}
		$categories=array_values(array_unique($categories));
		if($categories===[]){
			return [
				'status'=>'none triggered',
				'default_lane'=>'app_builder',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			];
		}
		return [
			'status'=>'app_owned_policy_attention',
			'mode'=>'lightweight_app_owned_policy',
			'default_lane'=>'app_builder',
			'categories'=>$categories,
			'recommended_actions'=>$this->app_builder_sensitive_recommended_actions($categories),
			'first_read'=>'builder_response.data_sensitivity_summary and builder_response.policy_decision_register',
			'next_tool'=>'dataphyre_app_builder_plan_generate',
			'purpose'=>'Keep app-builder routing lightweight while making policy-sensitive app-owned work visible before writes.',
			'open_only_for'=>$this->mcp_escalation_triggers(),
			'not_required'=>[
				'enterprise audit before ordinary app-builder planning',
				'MCP/release-surface publication validation for ordinary app routing',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Exports a handoff payload for a workflow recommendation.
	 *
	 * @param array<string,mixed> $args Recommendation and handoff options.
	 * @return array Recommendation handoff payload.
	 */
	private function mcp_workflow_recommendation_handoff_export(array $args): array {
		$task=trim((string)($args['task'] ?? ''));
		$limit=max(1, min(6, (int)($args['limit'] ?? 3)));
		$include_frames=($args['include_frames'] ?? true)!==false;
		$app_builder_task=$this->mcp_task_implies_app_builder($task);
		$release_claim=$this->mcp_task_implies_release_claim($task);
		$recommendation=$this->mcp_workflow_recommend(['task'=>$task, 'limit'=>$limit]);
		$top=$recommendation['recommendations'][0] ?? [];
		$workflow=(string)($top['workflow'] ?? 'client');
		if(!in_array($workflow, ['feature', 'routes', 'sql', 'diagnostics', 'client', 'release'], true)){
			$workflow='client';
		}
		$inline_handoff=!($app_builder_task && !$release_claim);
		$handoff=$inline_handoff ? $this->mcp_workflow_handoff_pack_export(['workflow'=>$workflow, 'include_frames'=>$include_frames]) : [];
		$payload=[
			'export_type'=>'dataphyre_mcp_workflow_recommendation_handoff_export',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'protocol'=>'2025-11-25',
			'task'=>$task,
			'selected_workflow'=>$workflow,
			'selected_score'=>(int)($top['score'] ?? 0),
			'selected_ready'=>($top['ready'] ?? false)===true,
			'include_frames'=>$include_frames,
			'recommendation'=>$recommendation,
			'app_builder_next_action'=>$recommendation['app_builder_next_action'] ?? null,
			'handoff_pack'=>$inline_handoff ? $handoff : null,
			'handoff_pack_ref'=>$inline_handoff ? null : [
				'tool'=>'dataphyre_mcp_workflow_handoff_pack_export',
				'arguments'=>['workflow'=>$workflow, 'include_frames'=>$include_frames],
				'open_when'=>'A client is ready to run workflow session messages; ordinary app builders should use app_builder_next_action first.',
			],
			'usage_notes'=>[
				'This export recommends a workflow and either bundles or links the handoff pack; it does not send or execute MCP session messages.',
				'Review recommendation scores when task text is vague or the selected_score is low.',
				$inline_handoff
					? 'Use the handoff_pack session messages in order, then capture and summarize responses with transcript tools.'
					: 'For ordinary app-builder starts, follow app_builder_next_action first; fetch handoff_pack_ref only when a client needs runnable workflow session messages.',
			],
		];
		$payload=array_filter($payload, static fn(mixed $value): bool => $value!==null);
		if($app_builder_task && !$release_claim){
			$payload['boundary_refs']=[
				'application_agent_operating_contract'=>'dataphyre_mcp_readiness_report',
				'ordinary_app_work'=>'dataphyre_mcp_readiness_report',
				'tool_audience_boundaries'=>'dataphyre_mcp_readiness_report',
				'policy'=>'Ordinary app-builder recommendation handoffs keep boundary contracts fetchable instead of inlining them; open only when explicitly requested for an escalation decision or when a client needs the full audience boundary.',
			];
		}
		else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('workflow_recommendation_handoff');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('workflow_recommendation_handoff');
			$payload['tool_audience_boundaries']=$handoff['tool_audience_boundaries'] ?? $this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

}
