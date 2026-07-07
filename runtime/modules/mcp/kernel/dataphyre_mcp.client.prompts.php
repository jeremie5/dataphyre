<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines prompt export and prompt catalog helpers for Dataphyre MCP clients.
 *
 * Mcp kernel boundary: client-facing prompt metadata, not runtime execution.
 */
trait dataphyre_mcp_client_prompt_surfaces {

	/**
	 * Exports selected MCP prompts with their text and client usage metadata.
	 *
	 * @param array{names?:list<string>} $args Optional names list limiting exported prompts.
	 * @return array{pack_type:string,write_policy:string,execution:string,prompt_count:int,available_prompts:list<string>,prompts:list<array{name:string,description:string,role:string,text:string}>,usage_notes:list<string>} Prompt pack payload.
	 */
	private function prompt_pack_export(array $args): array {
		$available=$this->list_prompts()['prompts'];
		$descriptions=[];
		foreach($available as $prompt){
			$descriptions[(string)($prompt['name'] ?? '')]=(string)($prompt['description'] ?? '');
		}
		$names=$args['names'] ?? [];
		if(!is_array($names) || $names===[]){
			$names=array_keys($descriptions);
		}
		$selected=[];
		foreach($names as $name){
			$name=(string)$name;
			if($name==='' || !isset($descriptions[$name])){
				throw new InvalidArgumentException('Unknown prompt: '.$name);
			}
			$selected[]=$name;
		}
		$selected=array_values(array_unique($selected));
		$prompts=[];
		foreach($selected as $name){
			$prompts[]=[
				'name'=>$name,
				'description'=>$descriptions[$name],
				'role'=>'user',
				'text'=>$this->prompt_text($name),
			];
		}
		$app_prompt_pack=$this->mcp_prompt_pack_is_app_first($selected);
		$payload=[
			'pack_type'=>'dataphyre_prompt_pack',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'prompt_count'=>count($prompts),
			'available_prompts'=>array_keys($descriptions),
			'prompts'=>$prompts,
			'usage_notes'=>[
				'Prompt packs default to application agents building apps: read-only MCP metadata first, app-owned extension points, and focused app/module verification.',
				'Use prompt packs as client-side workflow templates.',
				'Prompt export does not run tools, read application state, dispatch routes, or execute commands.',
				'Pair prompt packs with dataphyre_mcp_manifest_export when a client needs current tool/resource metadata.',
			],
		];
		if($app_prompt_pack){
			$payload['governance_notes']=[
				'status'=>'none triggered',
				'default_lane'=>'app_prompt_pack',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			];
			$payload['context_links']=[
				'compact_app_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=compact',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				'escalation_readiness_details'=>'dataphyre_mcp_readiness_report',
				'runtime_guidelines_prompt'=>'dataphyre_runtime_guidelines',
			];
		}else{
			$payload['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('prompt_pack');
			$payload['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('prompt_pack');
			$payload['tool_audience_boundaries']=$this->mcp_current_tool_audience_boundaries();
		}
		return $payload;
	}

	/**
	 * Builds a catalog of MCP prompts and their related tools/resources.
	 *
	 * @param array{names?:list<string>} $args Optional names list limiting catalog rows.
	 * @return array{catalog_type:string,write_policy:string,execution:string,prompt_count:int,available_prompts:list<string>,prompts:list<array{name:string,description:string,theme:string,related_tools:list<string>,related_resources:list<string>,export_tool:string}>,usage_notes:list<string>} Prompt catalog payload.
	 */
	private function mcp_prompt_catalog(array $args): array {
		$available=$this->list_prompts()['prompts'];
		$descriptions=[];
		foreach($available as $prompt){
			$descriptions[(string)($prompt['name'] ?? '')]=(string)($prompt['description'] ?? '');
		}
		$names=$args['names'] ?? [];
		$default_catalog=false;
		if(!is_array($names) || $names===[]){
			$default_catalog=true;
			$names=array_values(array_filter(array_keys($descriptions), static fn(string $name): bool => !in_array($name, ['dataphyre_runtime_guidelines', 'dataphyre_release_triage'], true)));
		}
		$catalog=[];
		foreach($names as $name){
			$name=(string)$name;
			if($name==='' || !isset($descriptions[$name])){
				throw new InvalidArgumentException('Unknown prompt: '.$name);
			}
			$catalog[]=[
				'name'=>$name,
				'description'=>$descriptions[$name],
				'theme'=>$this->prompt_catalog_theme($name),
				'first_action'=>$this->prompt_catalog_first_action($name),
				'related_tools'=>$this->prompt_catalog_tools($name, $default_catalog),
				'related_resources'=>$this->prompt_catalog_resources($name),
				'export_tool'=>'dataphyre_prompt_pack_export',
			];
		}
		return [
			'catalog_type'=>'dataphyre_mcp_prompt_catalog',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'prompt_count'=>count($catalog),
			'available_prompts'=>array_keys($descriptions),
			'governance_notes'=>[
				'status'=>'none triggered',
				'default_lane'=>'prompt_discovery',
				'open_only_for'=>$this->mcp_escalation_triggers(),
			],
			'context_links'=>[
				'compact_app_builder_plan'=>'dataphyre_app_builder_plan_generate payload_profile=compact',
				'focused_docs'=>'dataphyre_task_pack_generate payload_profile=builder',
				'escalation_readiness_details'=>'dataphyre_mcp_readiness_report',
				'escalation_prompts'=>'Request dataphyre_runtime_guidelines or dataphyre_release_triage by name only when the task matches escalation triggers.',
			],
			'prompts'=>$catalog,
			'usage_notes'=>[
				'Prompt catalog guidance is application-agent first; Dataphyre maintainer gates apply only when escalation triggers match: '.$this->mcp_escalation_trigger_summary().'.',
				'Use prompts/get for one prompt when the client supports MCP prompts.',
				'Use dataphyre_prompt_pack_export when a client needs prompt text in one payload.',
				'Use dataphyre_mcp_resource_finder to locate supporting resources before fetching prompt text.',
			],
		];
	}

	/**
	 * Maps a prompt name to a workflow theme.
	 *
	 * @param string $name Prompt name.
	 * @return string Prompt theme label.
	 */
	private function prompt_catalog_theme(string $name): string {
		return match($name){
			'dataphyre_release_triage'=>'release',
			'dataphyre_sql_schema_workflow'=>'sql',
			'dataphyre_route_manifest_workflow'=>'routing',
			'dataphyre_diagnostics_workflow'=>'diagnostics',
			'dataphyre_panel_workflow'=>'panel',
			'dataphyre_runtime_guidelines'=>'guidelines',
			default=>'planning',
		};
	}

	/**
	 * Returns compact first-action guidance for prompt discovery rows.
	 *
	 * @param string $name Prompt name.
	 * @return string First action summary.
	 */
	private function prompt_catalog_first_action(string $name): string {
		return match($name){
			'dataphyre_panel_workflow'=>'Call dataphyre_app_builder_plan_generate with payload_profile=compact first; read builder_response.first_read before opening detail pages. Follow builder_response.first_read.next_action and first_read.scaffold_completion_summary.next_continuation until deferred_entities is empty, preserving dependency_context; open planning, implementation, verification, or controls details only when the first read points there.',
			'dataphyre_feature_plan'=>'Call dataphyre_app_builder_plan_generate with payload_profile=compact first for app creation; read builder_response.first_read and follow builder_response.first_read.next_action before opening full planning details. Pass explicit entities, fields, and max_entities when known, using foreign_key_target for relationships, not_foreign_key for external ids, and json/jsonb for structured columns; open implementation_recipe, verification_execution_plan, acceptance_review_plan, or verification_recovery_plan only when ready for that phase.',
			'dataphyre_release_triage'=>'Use Dataphyre maintainer release triage only for release-surface work, not ordinary app verification.',
			'dataphyre_runtime_guidelines'=>'Read runtime guidelines when touching Dataphyre internals or elevated governance/security/release scope.',
			'dataphyre_sql_schema_workflow'=>'Inspect SQL metadata and query plans only; do not execute SQL.',
			'dataphyre_route_manifest_workflow'=>'Inspect route manifests and URL previews only; do not dispatch handlers.',
			'dataphyre_diagnostics_workflow'=>'List and read bounded diagnostics with redaction before drawing conclusions.',
			default=>'Use the related tools as read-only planning context before app-owned edits.',
		};
	}

	/**
	 * Lists tools that are most relevant to a prompt workflow.
	 *
	 * @param string $name Prompt name.
	 * @param bool $default_catalog True when exporting unfiltered app-first prompt discovery.
	 * @return array Related MCP tool names.
	 */
	private function prompt_catalog_tools(string $name, bool $default_catalog=false): array {
		return match($name){
			'dataphyre_release_triage'=>$default_catalog ? ['MCP/release-surface release validation tools'] : ['dataphyre_release_check', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan'],
			'dataphyre_sql_schema_workflow'=>['dataphyre_sql_tables_list', 'dataphyre_sql_schema_read', 'dataphyre_sql_clusters_list', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract'],
			'dataphyre_route_manifest_workflow'=>['dataphyre_list_routes', 'dataphyre_route_manifest_read', 'dataphyre_route_url_preview', 'dataphyre_route_match_preview', 'dataphyre_route_source_ambiguity_report'],
			'dataphyre_diagnostics_workflow'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error'],
			'dataphyre_panel_workflow'=>['dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_panel_scaffold_catalog', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check'],
			'dataphyre_runtime_guidelines'=>['dataphyre_mcp_status_board', 'dataphyre_mcp_enterprise_adoption_audit', 'dataphyre_mcp_docs_coverage_report', 'dataphyre_mcp_doctor'],
			default=>['dataphyre_app_builder_plan_generate', 'dataphyre_task_pack_generate', 'dataphyre_module_docs_pack', 'dataphyre_scaffold_plan_generate'],
		};
	}

	/**
	 * Lists MCP resources that support a prompt workflow.
	 *
	 * @param string $name Prompt name.
	 * @return array Related MCP resource URIs.
	 */
	private function prompt_catalog_resources(string $name): array {
		return match($name){
			'dataphyre_runtime_guidelines'=>['dataphyre://ai-guidelines', 'dataphyre://agentic-enterprise', 'dataphyre://mcp-plan'],
			'dataphyre_release_triage'=>['dataphyre://module-index', 'dataphyre://mcp-plan'],
			'dataphyre_panel_workflow'=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
				'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
				'dataphyre://module-index',
			],
			'dataphyre_sql_schema_workflow'=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
				'dataphyre://module-index',
			],
			'dataphyre_route_manifest_workflow'=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'dataphyre://module-index',
			],
			'dataphyre_diagnostics_workflow'=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/tracelog/documentation/Dataphyre_Tracelog.md',
				'dataphyre://doc/common/dataphyre/runtime/modules/issue/documentation/Dataphyre_Issue.md',
				'dataphyre://module-index',
			],
			default=>[
				'dataphyre://doc/common/dataphyre/runtime/modules/panel/documentation/Dataphyre_Panel.md',
				'dataphyre://doc/common/dataphyre/runtime/modules/sql/documentation/Dataphyre_SQL.md',
				'dataphyre://doc/common/dataphyre/runtime/modules/routing/documentation/Dataphyre_Routing.md',
				'dataphyre://module-index',
			],
		};
	}

}
