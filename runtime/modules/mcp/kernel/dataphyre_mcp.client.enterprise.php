<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP enterprise, safety, capability, and release-note client surfaces.
 */
trait dataphyre_mcp_client_enterprise_surfaces {

	/**
	 * Builds a status board summarizing MCP server capabilities and readiness.
	 *
	 * @return array MCP status board payload.
	 */
	private function mcp_status_board(): array {
		$readiness=$this->mcp_readiness_report();
		$doctor=$this->mcp_doctor();
		$core_resources=$this->mcp_core_resource_uris();
		$coverage=$readiness['agentic_capability_coverage'] ?? $readiness['boost_parity_coverage'] ?? [];
		$areas=[];
		$ready_count=0;
		$remaining=[];
		foreach($coverage as $area=>$details){
			$ready=($details['ready'] ?? false)===true;
			if($ready){
				$ready_count++;
			}
			$area_remaining=is_array($details['remaining'] ?? null) ? $details['remaining'] : [];
			foreach($area_remaining as $item){
				$item=trim((string)$item);
				if($item!==''){
					$remaining[]=$area.': '.$item;
				}
			}
			$areas[]=[
				'area'=>(string)$area,
				'status'=>(string)($details['status'] ?? 'unknown'),
				'ready'=>$ready,
				'tool_count'=>is_array($details['tools'] ?? null) ? count($details['tools']) : 0,
				'remaining_count'=>count($area_remaining),
			];
		}
		return [
			'board_type'=>'dataphyre_mcp_status_board',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'server'=>$readiness['server'] ?? 'dataphyre-mcp',
			'protocol'=>$readiness['protocol'] ?? '2025-11-25',
			'default_safety'=>$readiness['default_safety'] ?? 'read_only',
			'unsafe_enabled'=>$this->allow_unsafe,
			'counts'=>[
				'tools'=>$readiness['tool_count'] ?? count($this->list_tools()['tools']),
				'prompts'=>$readiness['prompt_count'] ?? count($this->list_prompts()['prompts']),
				'resources'=>$readiness['resource_count'] ?? count($this->list_resources()['resources']),
				'skills'=>$readiness['skill_count'] ?? count($this->mcp_skill_definitions()),
				'coverage_areas'=>count($areas),
				'ready_areas'=>$ready_count,
				'doctor_failed_checks'=>$doctor['failed_count'] ?? null,
			],
			'doctor_passed'=>($doctor['passed'] ?? false)===true,
			'coverage_contract'=>[
				'preferred_field'=>'agentic_capability_coverage',
				'compatibility_aliases'=>$readiness['compatibility_aliases'] ?? ['boost_parity_coverage'=>'agentic_capability_coverage'],
				'policy'=>'Use agentic capability coverage in public summaries; keep legacy coverage keys only as compatibility aliases.',
			],
			'coverage_areas'=>$areas,
			'top_remaining'=>array_slice(array_values(array_unique($remaining)), 0, 8),
			'intentionally_not_exposed'=>$readiness['intentionally_not_exposed'] ?? [],
			'core_resources'=>$core_resources,
			'agentic_enterprise_contract'=>in_array('dataphyre://agentic-enterprise', $core_resources, true) ? 'dataphyre://agentic-enterprise' : null,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('status_board'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('status_board'),
			'tool_audience_boundaries'=>$readiness['tool_audience_boundaries'] ?? $this->mcp_current_tool_audience_boundaries(),
			'default_app_workflow'=>$readiness['default_app_workflow'] ?? [],
			'app_builder_readiness'=>$readiness['app_builder_readiness'] ?? [],
			'apply_readiness'=>$readiness['apply_readiness'] ?? [],
			'app_first_verification_policy'=>$readiness['app_first_verification_policy'] ?? [],
			'recommended_next_slices'=>$readiness['recommended_next_slices'] ?? [],
			'publication_next_slices'=>$readiness['publication_next_slices'] ?? [],
			'publication_next_action'=>is_array($readiness['publication_next_action'] ?? null) ? $readiness['publication_next_action'] : $this->mcp_publication_next_action(['recommended_next_slices'=>$readiness['publication_next_slices'] ?? []]),
			'recommended_next_actions'=>[
				'For ordinary application work, start with dataphyre_app_builder_plan_generate with payload_profile=compact, read builder_response.first_read, and use focused application or module verification.',
				'Optionally add dataphyre_task_pack_generate with payload_profile=builder only when focused module docs or a ready prompt are needed.',
				'Use dataphyre_mcp_agent_brief_export for compact cold starts or handoffs; use dataphyre_mcp_task_start_pack_export payload_profile=builder only when broader bounded workflow context is needed; build-shaped tasks start with builder_first_read and inspection-shaped tasks start with inspection_view.',
				'Use the dataphyre-app-builder skill for client-installed ordinary app guidance.',
			],
			'publication_or_framework_context'=>[
				'default_visible'=>false,
				'open_when'=>$this->mcp_escalation_triggers(),
				'next_actions'=>[
					'Read dataphyre://agentic-enterprise before framework-level edits, corporate-ready work, or release-facing agent claims.',
					'Run dataphyre_mcp_enterprise_adoption_audit before agent-first, corporate-ready, public, or release-facing Dataphyre framework claims.',
					'Use dataphyre_mcp_manifest_export for full tool, prompt, resource, and schema metadata.',
					'Use dataphyre_mcp_readiness_report for detailed agentic capability coverage and gap notes.',
					'Use dataphyre_mcp_doctor after MCP code or docs changes.',
					'Keep shared MCP code app-agnostic and run the coupling guard before release.',
				],
			],
		];
	}

	/**
	 * Reduces publication validation guidance to one bounded next action.
	 *
	 * @param array<string,mixed> $readiness Readiness report payload.
	 * @return array<string,mixed> Publication/release-surface next action.
	 */
	private function mcp_publication_next_action(array $readiness): array {
		$slices=is_array($readiness['recommended_next_slices'] ?? null) ? array_values($readiness['recommended_next_slices']) : [];
		foreach($slices as $index=>$slice){
			if(!is_array($slice) || ($slice['audience'] ?? null)!=='publication_validation_not_ordinary_app_work'){
				continue;
			}
			return [
				'owner'=>'Dataphyre maintainer or client author publishing shared MCP/release surfaces',
				'status'=>'run_before_publication_claim',
				'tool'=>(string)($slice['tool'] ?? 'dataphyre_mcp_verify_all'),
				'arguments'=>is_array($slice['arguments'] ?? null) ? $slice['arguments'] : [],
				'action'=>(string)($slice['purpose'] ?? 'Use only before MCP/release-surface claims, published shared setup docs, release notes, or MCP server wiring changes.'),
				'argument_source'=>'recommended_next_slices['.$index.']',
				'handoff_fields'=>['publication_next_action', 'recommended_next_slices', 'tool_audience_boundaries.publication_validation_tools', 'app_first_verification_policy'],
				'not_required'=>[
					'ordinary application behavior proof',
					'application agents running publication validation for routine app work',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			];
		}
		return [
			'owner'=>'Dataphyre maintainer or client author publishing shared MCP/release surfaces',
			'status'=>'no_publication_validation_slice',
			'tool'=>null,
			'arguments'=>[],
			'action'=>'No publication-validation next slice is advertised by this readiness payload.',
			'argument_source'=>null,
			'handoff_fields'=>['publication_next_action', 'recommended_next_slices'],
			'not_required'=>[
				'ordinary application behavior proof',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**

	 * Describes the lightweight verification and extension owner for ordinary app work.
	 *
	 * @param string $surface MCP surface name requesting the contract.
	 * @return array<string,mixed> Machine-readable ordinary app-work boundary.
	 */
	private function mcp_ordinary_app_work_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'default_audience'=>'application_agents_building_apps',
			'owner'=>'consuming_application',
			'extension_points'=>[
				'application code',
				'install config',
				'dialbacks',
				'callbacks',
				'plugins',
				'MCP metadata',
				'application-owned adapters',
				'reusable modules',
			],
			'verification_owner'=>'consuming_application',
			'verification'=>'focused application or module checks',
			'not_required_for_ordinary_app_work'=>[
				'dataphyre_mcp_verify_all',
				'maintainer/source-checkout evidence',
				'Dataphyre hot-path benchmarks',
				'Dataphyre runtime-internal edits',
			],
			'escalate_only_for'=>[
				'MCP/release-surface claims',
				'corporate-ready or public framework claims',
					'security/identity/access/tenant/privacy/compliance/data-residency/retention-sensitive work',
				'Dataphyre framework internals',
				'Dataphyre shared production hot-path changes',
			],
		];
	}

	/**
	 * Generates release notes for MCP tool families and safety posture changes.
	 *
	 * @param array<string,mixed> $args Release-note generation options.
	 * @return array Release notes payload.
	 */
	private function mcp_release_notes_generate(array $args): array {
		$audience=strtolower(trim((string)($args['audience'] ?? 'agents')));
		if(!in_array($audience, ['maintainers', 'client_authors', 'agents'], true)){
			$audience='agents';
		}
		$status=$this->mcp_status_board();
		$matrix=$this->mcp_capability_matrix();
		$readiness=$this->mcp_readiness_report();
		$enterprise_readiness=is_array($readiness['agentic_enterprise_readiness'] ?? null) ? $readiness['agentic_enterprise_readiness'] : [];
		$app_builder_readiness=is_array($readiness['app_builder_readiness'] ?? null) ? $readiness['app_builder_readiness'] : [];
		$apply_readiness=is_array($readiness['apply_readiness'] ?? null) ? $readiness['apply_readiness'] : [];
		$app_first_verification_policy=is_array($readiness['app_first_verification_policy'] ?? null) ? $readiness['app_first_verification_policy'] : [];
		$governance_baseline=is_array($enterprise_readiness['governance_baseline'] ?? null) ? $enterprise_readiness['governance_baseline'] : [];
		$governance_checks=array_values(array_filter(array_map(static fn(mixed $check): string => (string)$check, $governance_baseline['checks'] ?? [])));
		$highlights=[];
		foreach($matrix['families'] ?? [] as $family){
			if(!is_array($family)){
				continue;
			}
			$highlights[]=(string)($family['release_note'] ?? '');
		}
		$highlights=array_values(array_filter($highlights, static fn(string $line): bool => $line!==''));
		$notes=[
			'# Dataphyre MCP Release Notes',
			'',
			'## Summary',
			'- Server: '.($status['server'] ?? 'dataphyre-mcp').' using MCP protocol '.($status['protocol'] ?? '2025-11-25').'.',
			'- Registered surfaces: '.(string)($status['counts']['tools'] ?? 0).' tools, '.(string)($status['counts']['prompts'] ?? 0).' prompts, '.(string)($status['counts']['resources'] ?? 0).' resources.',
			'- Core agent resources: '.implode(', ', array_map(static fn(string $resource): string => '`'.$resource.'`', $status['core_resources'] ?? [])).'.',
			'- Safety: default '.($status['default_safety'] ?? 'read_only').'; unsafe mode '.(($status['unsafe_enabled'] ?? false) ? 'enabled for this process' : 'disabled for this process').'.',
			'- Doctor: '.(($status['doctor_passed'] ?? false) ? 'passing' : 'has failing checks').'.',
			'',
			'## Enterprise Readiness',
			'- Agentic enterprise gates: '.(($enterprise_readiness['ready'] ?? false)===true ? 'ready' : 'needs review').'.',
			'- Recommended gate: `'.(string)($enterprise_readiness['recommended_gate'] ?? 'dataphyre_mcp_enterprise_adoption_audit').'`.',
			'- Governance baseline: '.(string)($governance_baseline['contract'] ?? 'docs/AGENTIC_ENTERPRISE.md#governance-baseline').' checks '.implode(', ', array_slice($governance_checks, 0, 5)).'.',
			'- Benchmark scope: '.(string)($enterprise_readiness['benchmark_scope'] ?? 'Dataphyre shared production hot paths only.'),
			'- App-builder lane: '.(($app_builder_readiness['ready'] ?? false)===true ? 'ready' : 'needs review').' via `'.(string)($app_builder_readiness['default_entrypoint'] ?? 'dataphyre_app_builder_plan_generate').'`.',
			'- Apply readiness: future write-capable apply runner '.(string)($apply_readiness['future_runner_status'] ?? 'not_exposed').'; use `apply_next_action` before any future apply workflow.',
			'- App verification: '.(string)($app_first_verification_policy['default'] ?? 'Application behavior uses focused application or module verification.'),
			'',
			'## Next-Action Contracts',
			'- `apply_next_action`: ordinary app changes use app-owned extension points; Dataphyre runtime/MCP/dev/docs paths escalate before writes.',
			'- `governance_next_action`: elevated enterprise audits name the next tenant/access/audit/redaction/verification proof item without opening governance for routine app work.',
			'- `publication_next_action`: publication validation is for MCP/release-surface claims, not ordinary application behavior proof.',
			'',
			'## Capability Families',
		];
		foreach(array_slice($highlights, 0, 12) as $line){
			$notes[]='- '.$line;
		}
		$notes[]='';
		$notes[]='## Not Exposed';
		foreach($status['intentionally_not_exposed'] ?? [] as $item){
			$notes[]='- '.(string)$item;
		}
		$notes[]='';
		$notes[]='## Verification';
		$notes[]='- Run `php -l` on touched MCP PHP files.';
		$notes[]='- Confirm maintainer/source-checkout MCP self-test evidence for touched MCP surfaces.';
		$notes[]='- Run `dataphyre_mcp_doctor` after MCP tool, prompt, resource, or documentation changes.';
		$notes[]='- Run the MCP app-coupling guard so product-specific paths and names stay out of shared MCP code.';
		$notes[]='- Run `dataphyre_mcp_enterprise_adoption_audit` before agent-first, corporate-ready, public, or release-facing Dataphyre framework claims.';
		$notes[]='- Treat `dataphyre_mcp_verify_all` as proof for MCP/release-surface claims only; focused runtime behavior still needs module tests or diagnostics.';
		$notes[]='- Keep ordinary application work on focused app/module verification; do not use MCP readiness as proof of app behavior.';
		if($audience==='client_authors'){
			$notes[]='';
			$notes[]='## Client Author Notes';
			$notes[]='- Use `dataphyre_mcp_manifest_export` for schemas and grouped tool metadata.';
			$notes[]='- Use `dataphyre_mcp_client_install_checklist` for target-aware setup plans.';
			$notes[]='- Keep unsafe mode disabled for normal read-only client installation.';
		}elseif($audience==='agents'){
			$notes[]='';
			$notes[]='## Agent Notes';
			$notes[]='- For ordinary app work, first copy `dataphyre_app_builder_plan_generate`; add `dataphyre_task_pack_generate` with `payload_profile=builder` only when focused module docs or a ready prompt are needed.';
			$notes[]='- Read `builder_response.first_read` before opening extra context; it tells agents the next action, compact summaries, what to open only when needed, and what stays collapsed until explicitly requested for an escalation decision.';
			$notes[]='- For large app scaffolds, follow `entity_planning.continuation_calls` until `deferred_entities` is empty; pass explicit `entities`, `fields`, and `max_entities` when known, using `foreign_key_target` for relationships, `not_foreign_key` for external ids, and `json/jsonb` for structured columns.';
			$notes[]='- Use `dataphyre_mcp_agent_brief_export` for compact cold starts or handoffs; use `dataphyre_mcp_task_start_pack_export payload_profile=builder` only when broader bounded workflow context is needed; build-shaped tasks start with `builder_first_read`, while read-only inspection tasks start with `inspection_view`.';
			$notes[]='- Use `dataphyre_mcp_status_board` or `dataphyre_mcp_readiness_report` only when an agent needs broader MCP health or release-surface context.';
			$notes[]='- Follow the Application-Agent Default Lane: read-only metadata first, app-owned extension points, and focused app/module verification.';
			$notes[]='- Use docs packs, builder task packs, or audit plans as optional read-only context before editing app-owned code or proposing reusable runtime work.';
			$notes[]='- Do not execute SQL, dispatch routes, expose secrets, or write scaffold output from read-only tools.';
		}
		return [
			'notes_type'=>'dataphyre_mcp_release_notes',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'audience'=>$audience,
			'generated_from'=>['dataphyre_mcp_status_board', 'dataphyre_mcp_capability_matrix', 'dataphyre_mcp_readiness_report'],
			'tool_count'=>$status['counts']['tools'] ?? null,
			'family_count'=>$matrix['family_count'] ?? null,
			'coverage_contract'=>$status['coverage_contract'] ?? [],
			'markdown'=>implode("\n", $notes),
			'structured_highlights'=>$highlights,
			'publication_next_action'=>$readiness['publication_next_action'] ?? $this->mcp_publication_next_action($readiness),
			'apply_readiness'=>$apply_readiness,
			'next_action_contracts'=>[
				'apply_next_action'=>$apply_readiness['next_action_contract'] ?? [],
				'governance_next_action'=>'dataphyre_mcp_enterprise_adoption_audit.governance_next_action',
				'publication_next_action'=>'publication_next_action',
			],
			'enterprise_readiness'=>$enterprise_readiness,
			'app_builder_readiness'=>$app_builder_readiness,
			'app_first_verification_policy'=>$app_first_verification_policy,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('release_notes'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('release_notes'),
			'governance_baseline'=>$governance_baseline,
			'enterprise_verification_policy'=>$matrix['enterprise_verification_policy'] ?? [],
			'tool_audience_boundaries'=>$this->mcp_current_tool_audience_boundaries(),
			'safety'=>$status['intentionally_not_exposed'] ?? [],
			'core_resources'=>$status['core_resources'] ?? [],
		];
	}
}
