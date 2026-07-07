<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP safety, escalation, and application-agent boundary surfaces.
 */
trait dataphyre_mcp_client_safety_surfaces {

	private function mcp_safety_boundary_report(): array {
		$manifest=$this->mcp_manifest_export(['include_schemas'=>false, 'include_docs_resources'=>false]);
		$doctor=$this->mcp_doctor();
		return [
			'report_type'=>'dataphyre_mcp_safety_boundary_report',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'default_safety'=>'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('safety_boundary_report'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('safety_boundary_report'),
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'safety_next_action'=>$this->mcp_safety_next_action($manifest, $this->allow_unsafe),
			'unsafe_opt_in'=>[
				'cli_flag'=>'--allow-unsafe',
				'environment'=>'DATAPHYRE_MCP_ALLOW_UNSAFE=1',
				'php_binary_override'=>'DATAPHYRE_MCP_PHP_BINARY',
			],
			'allowed_by_default'=>[
				'static source, docs, manifest, and route artifact inspection',
				'redacted config shape and selected non-secret scalar previews',
				'route URL and match previews without dispatching handlers',
				'SQL schema and query planning without database connections',
				'bounded tracelog artifact listing, redacted reading, and search',
				'verification surface metadata and dry-run planning wrappers',
			],
			'unsafe_gated_or_bounded'=>[
				'dataphyre_run_panel_regression',
				'dataphyre_run_panel_field_catalog_check',
				'dataphyre_php_lint',
				'dataphyre_release_check',
				'dataphyre_mcp_live_validate',
				'dataphyre_mcp_verify_all',
			],
			'intentionally_not_exposed'=>$manifest['safety']['intentionally_not_exposed'] ?? [
				'SQL query execution',
				'route dispatch',
				'schema hydration',
				'config secret values',
				'app-specific local server scripts',
			],
			'redaction_policy'=>[
				'Config readers expose keys and shapes before values.',
				'Sensitive key names are denied for exact value previews.',
				'Tracelog readers redact token, password, secret, key, bearer, auth header, cookie, private key, connection string, and credential-looking values.',
				'SQL cluster summaries omit usernames, passwords, endpoints, and database names.',
				'Shared diagnostics should redact signed URLs, tenant names, product identifiers, and machine-local paths before sharing.',
			],
			'redaction_contract'=>$this->mcp_redaction_contract(),
			'transport_and_filesystem_boundary'=>$this->mcp_transport_filesystem_boundary_contract(),
			'unsafe_decision_contract'=>[
				'default_decision'=>'do_not_enable',
				'allowed_only_when'=>[
					'The caller explicitly requested a local unsafe-gated verification workflow.',
					'The tool is listed in unsafe_gated_or_bounded and has a route-free, bounded contract.',
					'The run is local to the Dataphyre worktree and does not require application secrets or tenant data.',
				],
				'still_never_exposes'=>$manifest['safety']['intentionally_not_exposed'] ?? [
					'SQL query execution',
					'route dispatch',
					'schema hydration',
					'config secret values',
					'app-specific local server scripts',
				],
				'required_before_suggestion'=>[
					'Read dataphyre_mcp_safety_boundary_report.',
					'Prefer read-only MCP tools and dry-run plans first.',
					'Name the bounded verification tool and expected artifact.',
					'Keep unsafe mode out of shared docs, generated client configs, and normal agent setup instructions.',
				],
			],
			'app_coupling_guard'=>[
				'passed'=>($doctor['passed'] ?? false)===true,
				'policy'=>'Shared MCP module and tools must not hardcode product-local application names, local PHP paths, or app server scripts.',
				'verification_tools'=>[
					'dataphyre_mcp_doctor',
					'dataphyre_mcp_verify_all',
				],
			],
			'recommended_workflow'=>[
				'Use read-only summary, catalog, and planning tools first.',
				'Use dry-run scaffold and audit plans before any future write-capable runner.',
				'Run dataphyre_mcp_live_validate after client setup changes.',
				'Run dataphyre_mcp_verify_all before release, shared MCP setup docs, or MCP/release-surface claims.',
			],
			'tool_group_counts'=>array_map(static fn(array $group): int => (int)($group['count'] ?? 0), $manifest['tool_groups'] ?? []),
		];
	}

	/**
	 * Describes MCP transport and repository filesystem boundary hardening.
	 *
	 * @return array<string,mixed> Transport and filesystem boundary contract.
	 */
	private function mcp_transport_filesystem_boundary_contract(): array {
		return [
			'stdio_transport'=>[
				'accepted_forms'=>['newline-delimited JSON', 'Content-Length framed JSON-RPC'],
				'malformed_json_policy'=>'Return a JSON-RPC parse error and continue reading the next request instead of silently terminating the server loop.',
				'invalid_request_shape_policy'=>'Return a JSON-RPC invalid-request error for non-object JSON messages and methodless objects, then continue reading the next request.',
				'missing_content_length_policy'=>'Return an invalid-request error for framed messages without a strictly decimal positive Content-Length header.',
				'max_frame_bytes'=>4194304,
				'oversized_frame_policy'=>'Reject framed requests whose Content-Length exceeds max_frame_bytes before reading the declared body.',
				'response_framing'=>'Responses use the active request transport style: newline-delimited responses for line input and Content-Length frames for framed input.',
			],
			'filesystem_boundary'=>[
				'path_resolver'=>'safe_repo_path',
				'allowed_roots'=>'Dataphyre workspace root and common root only',
				'boundary_policy'=>'Allowed paths must equal an allowed root or be below it with a path separator; slash- and backslash-normalized sibling paths that only share a prefix are rejected.',
				'missing_leaf_policy'=>'Missing leaf paths are allowed only when the parent resolves inside an allowed root.',
			],
			'evidence'=>[
				'transport_hardening'=>'MCP self-test sends malformed line JSON, malformed framed JSON, non-object JSON, methodless objects, missing/invalid/non-decimal/oversized Content-Length, and then a valid request to confirm recovery.',
				'path_boundary'=>'MCP self-test creates a sibling directory named like the repo root plus a suffix and verifies safe_repo_path rejects slash, backslash, and missing-leaf sibling paths.',
			],
			'ordinary_app_policy'=>'This hardening is server/client safety metadata; ordinary application agents use focused app verification and do not run aggregate MCP verification or Dataphyre hot-path benchmarks for app scaffolds.',
		];
	}

	/**
	 * Reduces safety-boundary policy to one immediate safe action.
	 *
	 * @param array<string,mixed> $manifest MCP manifest snapshot.
	 * @param bool $unsafe_enabled Whether unsafe-gated mode is enabled.
	 * @return array<string,mixed> Safety next-action decision.
	 */
	private function mcp_safety_next_action(array $manifest, bool $unsafe_enabled): array {
		$denied=is_array($manifest['safety']['intentionally_not_exposed'] ?? null) ? array_values(array_map('strval', $manifest['safety']['intentionally_not_exposed'])) : [
			'SQL query execution',
			'route dispatch',
			'schema hydration',
			'config secret values',
			'app-specific local server scripts',
		];
		if($unsafe_enabled){
			return [
				'owner'=>'local maintainer or explicitly authorized elevated agent',
				'status'=>'unsafe_enabled_use_bounded_tools_only',
				'tool'=>'dataphyre_verification_surface_catalog',
				'action'=>'Select a bounded verification surface before running command-backed helpers; do not use unsafe mode for denied surfaces.',
				'allowed_next_tools'=>['dataphyre_php_lint', 'dataphyre_run_panel_regression', 'dataphyre_run_panel_field_catalog_check'],
				'publication_validation_tools'=>['dataphyre_mcp_live_validate'],
				'publication_validation_boundary'=>'Use MCP live validation only for client setup changes, MCP publication checks, or shared MCP/release-surface claims, not as focused app behavior proof.',
				'still_never_exposes'=>$denied,
				'handoff_fields'=>['safety_next_action', 'unsafe_decision_contract', 'tool_audience_boundaries', 'redaction_policy'],
				'not_required'=>[
					'unsafe mode for ordinary app discovery or planning',
					'SQL execution, route dispatch, config secret values, or schema hydration',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			];
		}
		return [
			'owner'=>'application agent or client author',
			'status'=>'stay_read_only',
			'tool'=>'dataphyre_mcp_tool_finder',
			'action'=>'Use read-only metadata, dry-run planning, redacted diagnostics, and focused verification catalogs before considering any unsafe opt-in.',
			'allowed_next_tools'=>['dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder', 'dataphyre_app_builder_plan_generate', 'dataphyre_verification_surface_catalog'],
			'still_never_exposes'=>$denied,
			'handoff_fields'=>['safety_next_action', 'unsafe_decision_contract', 'tool_audience_boundaries', 'redaction_policy'],
			'not_required'=>[
				'unsafe mode for ordinary app work',
				'dataphyre_mcp_verify_all for ordinary application behavior proof',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				'Dataphyre runtime-internal edits to make one application work',
			],
		];
	}

	/**
	 * Describes the default operating lane for MCP users building applications.
	 *
	 * @param string $surface MCP surface label.
	 * @return array<string,mixed> Application-agent operating contract.
	 */
	private function mcp_application_agent_operating_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'default_audience'=>'application_agents_building_apps',
			'default_posture'=>'read_only_metadata_first',
			'ordinary_work'=>[
				'read module docs and registered MCP metadata',
				'inspect safe route, config, storage, SQL schema, and diagnostic metadata',
				'plan application changes through app code, config, callbacks, dialbacks, plugins, MCP metadata, or application adapters',
				'use focused application or module verification for app behavior',
			],
			'not_default_requirements'=>[
				'Dataphyre runtime-internal edits',
				'Dataphyre project-wide release validation',
				'dataphyre_mcp_verify_all',
				'Dataphyre project evidence',
				'Dataphyre hot-path benchmarks',
				'unsafe MCP mode',
			],
			'escalate_only_for'=>$this->mcp_escalation_triggers(),
		];
	}

	/**
	 * Returns the precise task triggers that justify heavier enterprise/release review.
	 *
	 * @return array<int,string> Escalation trigger labels.
	 */
	private function mcp_escalation_triggers(): array {
		return [
			'release-facing or public Dataphyre framework claims',
			'corporate-ready or enterprise-readiness claims',
			'security, identity/access, session, credential, governance, tenant isolation, billing, privacy, compliance, data residency, retention, legal-hold, or access-policy work',
			'Dataphyre framework internals or reusable framework contributions',
			'Dataphyre shared production hot-path changes',
		];
	}

	/**
	 * Returns a compact prose version of the escalation taxonomy.
	 *
	 * @return string Escalation trigger summary for compact guidance.
	 */
	private function mcp_escalation_trigger_summary(): string {
		return 'release-facing/public framework claims; corporate or enterprise-readiness claims; security, identity/access, session, credential, governance, tenant isolation, billing, privacy, compliance, data residency, retention, legal-hold, or access-policy work; Dataphyre framework internals or reusable framework contributions; shared production hot-path changes';
	}

	/**
	 * Returns the canonical package/release app-agent boundary.
	 *
	 * @return array<string,mixed> Release manifest compatible boundary.
	 */
	private function mcp_package_release_boundary(): array {
		return [
			'release_manifest'=>'RELEASE_MANIFEST.json',
			'default_audience'=>'application_agents_building_apps',
			'intended_use'=>'runtime_docs_examples_and_release_attestation',
			'ordinary_app_entrypoint'=>'dataphyre_app_builder_plan_generate',
			'ordinary_app_payload_profile'=>'compact',
			'ordinary_agent_verification'=>'focused application or module checks owned by the consuming application',
			'app_owned_extension_points'=>[
				'config',
				'dialbacks',
				'callbacks',
				'plugins',
				'MCP metadata',
				'application-owned adapters',
				'reusable runtime modules',
			],
			'escalate_only_for'=>$this->mcp_escalation_triggers(),
			'not_ordinary_app_ceremony'=>[
				'dataphyre_mcp_verify_all',
				'Dataphyre project-wide release validation',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits to make one application work',
			],
			'project_evidence_scope'=>[
				'Dataphyre framework changes',
				'MCP/release-surface claims',
				'public release preparation',
				'shared production hot-path changes',
			],
		];
	}

}
