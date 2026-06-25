<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP capability-matrix and audience-lane surfaces.
 */
trait dataphyre_mcp_client_capability_surfaces {
	/**
	 * Builds the capability matrix across MCP tool families.
	 *
	 * @return array Capability matrix payload.
	 */
	private function mcp_capability_matrix(): array {
		$manifest=$this->mcp_manifest_export(['include_schemas'=>false, 'include_docs_resources'=>false]);
		$groups=$manifest['tool_groups'] ?? [];
		$matrix=[];
		foreach($groups as $group=>$details){
			$tools=is_array($details['tools'] ?? null) ? $details['tools'] : [];
			$matrix[]=[
				'family'=>(string)$group,
				'tool_count'=>count($tools),
				'tools'=>$tools,
				'safety_level'=>$this->capability_family_safety_level((string)$group),
				'execution_posture'=>$this->capability_family_execution_posture((string)$group),
				'verification'=>$this->capability_family_verification((string)$group),
				'audience_lanes'=>$this->capability_family_audience_lanes((string)$group, $tools),
				'publication_validation'=>$this->capability_family_publication_validation((string)$group),
				'release_note'=>$this->capability_family_release_note((string)$group, count($tools)),
			];
		}
		return [
			'matrix_type'=>'dataphyre_mcp_capability_matrix',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'server'=>$manifest['server'] ?? 'dataphyre-mcp',
			'protocol'=>$manifest['protocol'] ?? '2025-11-25',
			'default_safety'=>$manifest['default_safety'] ?? 'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'family_count'=>count($matrix),
			'tool_count'=>$manifest['counts']['tools'] ?? count($this->list_tools()['tools']),
			'families'=>$matrix,
			'intentionally_not_exposed'=>$manifest['safety']['intentionally_not_exposed'] ?? [],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('capability_matrix'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('capability_matrix'),
			'enterprise_verification_policy'=>[
				'verification_tool'=>'dataphyre_mcp_verify_all',
				'execution_scope'=>'bounded_first_party_commands',
				'route_free'=>true,
				'claim_boundary'=>'Passing aggregate verification supports MCP/release-surface claims only; runtime feature behavior still needs focused module tests or diagnostics.',
			],
			'command_output_policy'=>[
				'returned_outputs'=>'stdout and stderr from bounded first-party command helpers',
				'redacted'=>true,
				'redaction_policy'=>'Credential, signed URL, tenant/customer/product, and machine-local path patterns are redacted before command output is returned through MCP.',
				'claim_boundary'=>'Redacted command output is maintainer/source-checkout evidence, not ordinary application-agent proof.',
			],
			'app_first_verification_policy'=>[
				'default'=>'Application behavior uses focused application or module verification; capability readiness is not proof of app behavior.',
				'client_setup'=>'Use config audit, smoke-test export, and live stdio validation for ordinary app-client setup.',
				'publication'=>'Use dataphyre_mcp_verify_all for MCP/release-surface claims, published shared MCP setup docs, release notes, or MCP server wiring changes.',
			],
			'verification_lanes'=>[
				'ordinary_app_work'=>[
					'audience'=>'application_agents_building_apps',
					'tools'=>['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check', 'dataphyre_verification_surface_catalog'],
					'tool_boundaries'=>$this->capability_tool_boundaries(['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check', 'dataphyre_verification_surface_catalog']),
					'claim_boundary'=>'Focused app/module behavior only; not MCP release proof.',
				],
				'publication_validation'=>[
					'audience'=>'maintainers_and_client_authors_publishing_shared_surfaces',
					'tools'=>['dataphyre_release_check', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor', 'dataphyre_mcp_docs_coverage_report'],
					'tool_boundaries'=>$this->capability_tool_boundaries(['dataphyre_release_check', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor', 'dataphyre_mcp_docs_coverage_report']),
					'claim_boundary'=>'MCP/release-surface claims, published setup docs, release notes, or MCP server wiring changes.',
				],
			],
			'release_guardrails'=>[
				'Keep capability claims tied to live tool registration, not hand-maintained lists.',
				'Use dataphyre_mcp_doctor and maintainer/source-checkout MCP self-test evidence after changing MCP surfaces.',
				'Run the app-coupling guard before release so product-specific names stay out of shared MCP code.',
			],
		];
	}

	/**
	 * Resolves the safety level for a capability family.
	 *
	 * @param string $family Capability family key.
	 * @return string Safety level label.
	 */
	private function capability_family_safety_level(string $family): string {
		return match($family){
			'publication_validation'=>'bounded_execution_for_publication_claims',
			'verification'=>'read_only_or_focused_app_execution',
			'agent_and_planning', 'skill_registration', 'panel_helpers', 'api_and_openapi'=>'read_only_or_dry_run',
			default=>'read_only',
		};
	}

	/**
	 * Resolves the execution posture for a capability family.
	 *
	 * @param string $family Capability family key.
	 * @return string Execution posture label.
	 */
	private function capability_family_execution_posture(string $family): string {
		return match($family){
			'publication_validation'=>'Bounded first-party commands for MCP/release-surface claims; not ordinary app behavior proof.',
			'verification'=>'Focused app/module verification and catalogs; release gates are separated into publication_validation.',
			'skill_registration'=>'Skill catalogs, manifests, audits, packs, and install plans are generated for client-side use but not installed or written.',
			'agent_and_planning'=>'Plans, manifests, prompt packs, and client checklists are generated but not written.',
			'config_storage_sql'=>'Config, storage, and SQL tools inspect metadata and plans without exposing secrets or connecting to databases.',
			'routes'=>'Route tools read manifests, tokenize source, and preview matches without dispatching handlers.',
			'diagnostics'=>'Diagnostics read bounded artifacts and source metadata without running collectors or browser sessions.',
			default=>'Static inspection only; source files are read but runtime code is not invoked.',
		};
	}

	/**
	 * Lists verification expectations for a capability family.
	 *
	 * @param string $family Capability family key.
	 * @return array Verification guidance rows.
	 */
	private function capability_family_verification(string $family): array {
		return match($family){
			'verification'=>['dataphyre_verification_surface_catalog'],
			'publication_validation'=>[],
			'routes'=>['dataphyre_route_manifest_read', 'dataphyre_route_source_ambiguity_report'],
			'config_storage_sql'=>['dataphyre_sql_query_plan', 'dataphyre_config_shape_read'],
			'skill_registration'=>['dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan'],
			'agent_and_planning'=>['dataphyre_mcp_manifest_export', 'dataphyre_mcp_status_board'],
			default=>['focused application or module verification selected by affected surface'],
		};
	}

	/**
	 * Separates ordinary app-agent tools from publication/release validation tools.
	 *
	 * @param string $family Capability family key.
	 * @param array<int,string> $tools Registered tools in the family.
	 * @return array<string,mixed> Audience-scoped tool lanes.
	 */
	private function capability_family_audience_lanes(string $family, array $tools): array {
		if($family==='publication_validation'){
			return [
				'ordinary_app_work'=>[
					'audience'=>'application_agents_building_apps',
					'tools'=>[],
					'tool_boundaries'=>[],
					'claim_boundary'=>'Publication validation tools are not ordinary app behavior proof.',
				],
				'publication_validation'=>[
					'audience'=>'maintainers_and_client_authors_publishing_shared_surfaces',
					'tools'=>array_values($tools),
					'tool_boundaries'=>$this->capability_tool_boundaries($tools),
					'claim_boundary'=>'MCP/release-surface claims, published setup docs, release notes, or MCP server wiring changes.',
				],
			];
		}
		if($family!=='verification'){
			return [
				'ordinary_app_work'=>[
					'audience'=>'application_agents_building_apps',
					'tools'=>$tools,
					'tool_boundaries'=>$this->capability_tool_boundaries($tools),
					'claim_boundary'=>'Use focused checks for the affected app/module surface.',
				],
				'publication_validation'=>[
					'audience'=>'maintainers_and_client_authors_publishing_shared_surfaces',
					'tools'=>[],
					'tool_boundaries'=>[],
					'claim_boundary'=>'Open publication validation only when release or MCP-surface claims are being made.',
				],
			];
		}
		$ordinary=['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check', 'dataphyre_verification_surface_catalog'];
		return [
			'ordinary_app_work'=>[
				'audience'=>'application_agents_building_apps',
				'tools'=>array_values(array_intersect($ordinary, $tools)),
				'tool_boundaries'=>$this->capability_tool_boundaries(array_values(array_intersect($ordinary, $tools))),
				'claim_boundary'=>'Focused app/module behavior only; not MCP release proof.',
			],
			'publication_validation'=>[
				'audience'=>'maintainers_and_client_authors_publishing_shared_surfaces',
				'tools'=>[],
				'tool_boundaries'=>[],
				'claim_boundary'=>'MCP/release-surface claims, published setup docs, release notes, or MCP server wiring changes.',
			],
		];
	}

	/**
	 * Lists maintainer/source-checkout evidence for publishing MCP capability claims.
	 *
	 * @param string $family Capability family key.
	 * @return array Publication validation guidance rows.
	 */
	private function capability_family_publication_validation(string $family): array {
		$common=[
			'dataphyre_mcp_doctor',
			'maintainer/source-checkout MCP self-test evidence',
		];
		return match($family){
			'publication_validation'=>array_merge(['dataphyre_mcp_verify_all for MCP/release-surface verification claims'], $common),
			'verification'=>['Use the publication_validation family for MCP/release-surface proof; ordinary verification stays focused on app/module checks.'],
			'routes'=>array_merge(['confirm route tools remain read-only and do not dispatch handlers'], $common),
			'config_storage_sql'=>array_merge(['confirm SQL/config/storage tools expose metadata only and do not reveal credentials'], $common),
			'skill_registration'=>array_merge(['confirm skill pack/install surfaces remain plan-only unless explicitly installed by the client'], $common),
			'agent_and_planning'=>array_merge(['confirm generated guidance keeps Application-Agent Default Lane first'], $common),
			default=>$common,
		};
	}

	/**
	 * Describes capability-matrix tool proof boundaries while preserving compact tool lists.
	 *
	 * @param array<int,string> $tools Tool names from a capability lane.
	 * @return array<string,array<string,mixed>> Tool-name keyed boundary metadata.
	 */
	private function capability_tool_boundaries(array $tools): array {
		return $this->mcp_tool_boundary_map($tools);
	}

	/**
	 * Builds a release-note sentence for a capability family.
	 *
	 * @param string $family Capability family key.
	 * @param int $tool_count Number of tools in the family.
	 * @return string Release-note text.
	 */
	private function capability_family_release_note(string $family, int $tool_count): string {
		$label=str_replace('_', ' ', $family);
		return ucfirst($label).' exposes '.$tool_count.' registered MCP tools with '.$this->capability_family_safety_level($family).' safety posture.';
	}
}
