<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP enterprise adoption audit and proportional task-guidance surfaces.
 */
trait dataphyre_mcp_client_enterprise_audit_surfaces {

	/**
	 * Audits a proposed feature against the agentic enterprise contract.
	 *
	 * This tool turns docs/AGENTIC_ENTERPRISE.md into a bounded planning checklist
	 * that agents can use before making release-facing claims. It classifies only
	 * caller-provided paths and live MCP metadata; it does not execute app code.
	 *
	 * @param array{feature?:string,module?:string,files?:array<int,string>,public_claim?:bool} $args Audit arguments.
	 * @return array<string,mixed> Enterprise adoption audit payload.
	 */
	private function mcp_enterprise_adoption_audit(array $args): array {
		$feature=trim((string)($args['feature'] ?? ''));
		$module=trim((string)($args['module'] ?? ''));
		$public_claim=($args['public_claim'] ?? false)===true;
		$files=[];
		foreach((array)($args['files'] ?? []) as $file){
			$file=$this->mcp_enterprise_normalize_audit_path((string)$file);
			if($file!==''){
				$files[]=$file;
			}
		}
		$files=array_values(array_unique($files));
		$path_portability_signals=$this->mcp_enterprise_path_portability_signals($files);
		$runtime_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'runtime/')));
		$module_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'runtime/modules/')));
		$doc_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'docs/') || str_contains($file, '/documentation/')));
		$dev_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'dev/')));
		$app_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'applications/') || str_starts_with($file, 'app/')));
		$test_files=array_values(array_filter($files, static fn(string $file): bool => str_contains($file, '/unit_tests/') || str_contains($file, '/Testing/') || str_ends_with($file, '_test.php')));
		$plugin_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'plugins/')));
		$config_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'config/')));
		$mcp_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'runtime/modules/mcp/')));
		$release_surface_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'docs/') || in_array($file, ['.distignore', 'composer.json'], true)));
		$source_checkout_support_files=array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, 'dev/tools/')));
		$hot_path_files=$this->mcp_enterprise_hot_path_files($files);
		$module_evidence=$this->mcp_enterprise_module_evidence($module);
		$change_classification=$this->mcp_enterprise_change_classification([
			'app_files'=>count($app_files),
			'runtime_files'=>count($runtime_files),
			'module_files'=>count($module_files),
			'doc_files'=>count($doc_files),
			'dev_files'=>count($dev_files),
			'test_files'=>count($test_files),
			'plugin_files'=>count($plugin_files),
			'config_files'=>count($config_files),
			'mcp_files'=>count($mcp_files),
			'hot_path_files'=>count($hot_path_files),
		], $module_evidence, $public_claim);
		$extension_strategy=$this->mcp_enterprise_extension_strategy([
			'config_files'=>count($config_files),
			'plugin_files'=>count($plugin_files),
			'runtime_files'=>count($runtime_files),
			'module_files'=>count($module_files),
			'doc_files'=>count($doc_files),
			'test_files'=>count($test_files),
			'mcp_files'=>count($mcp_files),
		], $module_evidence);
		$runtime_quality_gates=$this->mcp_enterprise_runtime_quality_gates([
			'runtime_files'=>count($runtime_files),
			'module_files'=>count($module_files),
			'doc_files'=>count($doc_files),
			'dev_files'=>count($dev_files),
			'test_files'=>count($test_files),
			'config_files'=>count($config_files),
			'plugin_files'=>count($plugin_files),
			'portability_signal_count'=>count($path_portability_signals),
		], $module_evidence);
		$governance_baseline=$this->mcp_enterprise_governance_baseline([
			'app_files'=>count($app_files),
			'runtime_files'=>count($runtime_files),
			'module_files'=>count($module_files),
			'doc_files'=>count($doc_files),
			'dev_files'=>count($dev_files),
			'test_files'=>count($test_files),
			'config_files'=>count($config_files),
			'plugin_files'=>count($plugin_files),
			'portability_signal_count'=>count($path_portability_signals),
		], $module_evidence, $change_classification);
		$governance_next_action=$this->mcp_enterprise_governance_next_action($governance_baseline, $change_classification);
		$doctor=$this->mcp_doctor();
		$coverage=$this->mcp_docs_coverage_report();
		$resource_uris=$this->mcp_core_resource_uris();
		$checklist=[
			[
				'id'=>'enterprise_contract_loaded',
				'label'=>'Agentic enterprise contract is available',
				'status'=>in_array('dataphyre://agentic-enterprise', $resource_uris, true) ? 'ready' : 'missing',
				'evidence'=>['dataphyre://agentic-enterprise'],
				'action'=>'Read the contract before framework-level edits or enterprise-ready claims.',
			],
			[
				'id'=>'extension_boundary',
				'label'=>'Application-specific behavior stays out of core internals',
				'status'=>$runtime_files===[] || $config_files!==[] || $plugin_files!==[] ? 'ready' : 'needs_review',
				'evidence'=>[
					'config_files'=>count($config_files),
					'plugin_files'=>count($plugin_files),
					'runtime_files'=>count($runtime_files),
					'recommended_next_layer'=>$extension_strategy['recommended_next_layer'],
				],
				'action'=>'Prefer config, dialbacks, callbacks, plugins, or reusable modules before patching runtime for one app.',
			],
			[
				'id'=>'discoverability',
				'label'=>'Feature can be inspected through docs, manifests, typed contracts, or MCP',
				'status'=>$doc_files!==[] || $mcp_files!==[] || $module!=='' ? 'ready' : 'unknown',
				'evidence'=>[
					'module'=>$module!=='' ? $module : null,
					'doc_files'=>count($doc_files),
					'mcp_files'=>count($mcp_files),
				],
				'action'=>'Add or update public docs, module docs, manifests, or MCP summaries for reusable behavior.',
			],
			[
				'id'=>'module_contract',
				'label'=>'Named module has inspectable contract surfaces',
				'status'=>$module==='' ? 'not_applicable' : (($module_evidence['known'] ?? false)===true && ($module_evidence['documentation_files'] ?? 0)>0 ? 'ready' : 'needs_review'),
				'evidence'=>$module_evidence,
				'action'=>'Use dataphyre_module_describe and dataphyre_module_docs_pack before editing or claiming module readiness.',
			],
			[
				'id'=>'path_portability',
				'label'=>'Path inputs are portable and safe to share',
				'status'=>$path_portability_signals===[] ? 'ready' : 'needs_review',
				'evidence'=>[
					'signal_count'=>count($path_portability_signals),
					'signals'=>$path_portability_signals,
				],
				'action'=>'Use repo-relative paths and redact machine-local paths, hardcoded URLs, signed URL parameters, or token-like fragments before sharing audit output.',
			],
			[
				'id'=>'bounded_safety',
				'label'=>'Diagnostics and agent surfaces are bounded and read-oriented by default',
				'status'=>($doctor['passed'] ?? false)===true ? 'ready' : 'needs_review',
				'evidence'=>[
					'mcp_doctor_passed'=>($doctor['passed'] ?? false)===true,
					'intentionally_not_exposed'=>['SQL query execution', 'route dispatch', 'config secret values'],
				],
				'action'=>'Keep MCP/diagnostic outputs redacted, limited, and non-mutating unless an unsafe workflow is explicitly gated.',
			],
			[
				'id'=>'proof',
				'label'=>'Public behavior has focused tests or verification evidence',
				'status'=>$test_files!==[] || $dev_files!==[] ? 'ready' : 'unknown',
				'evidence'=>[
					'test_files'=>count($test_files),
					'dev_files'=>count($dev_files),
				],
				'action'=>'Add focused unit manifests, route-free harnesses, MCP self-tests, or release checks for the public contract.',
			],
			[
				'id'=>'release_claim',
				'label'=>'Enterprise-ready claims are backed by release-facing checks',
				'status'=>$public_claim ? (($release_surface_files!==[] || $source_checkout_support_files!==[]) && (($coverage['counts']['missing_tools'] ?? 1)===0) ? 'needs_verification' : 'needs_evidence') : 'not_applicable',
				'evidence'=>[
					'public_claim'=>$public_claim,
					'release_surface_files'=>count($release_surface_files),
					'source_checkout_support_files'=>count($source_checkout_support_files),
					'mcp_docs_missing_tools'=>$coverage['counts']['missing_tools'] ?? null,
					'mcp_docs_missing_core_resources'=>$coverage['counts']['missing_core_resources'] ?? null,
				],
				'action'=>'Collect maintainer/source-checkout MCP self-test and release check evidence before claiming enterprise-ready status.',
			],
		];
		$statuses=array_count_values(array_map(static fn(array $item): string => (string)$item['status'], $checklist));
		$attention=array_values(array_filter(
			$checklist,
			static fn(array $item): bool => !in_array((string)$item['status'], ['ready', 'not_applicable'], true)
		));
		$attention_ids=array_values(array_unique(array_merge(
			array_map(static fn(array $item): string => (string)$item['id'], $attention),
			$runtime_quality_gates['attention_ids'] ?? [],
			$governance_baseline['attention_ids'] ?? []
		)));
		$missing_evidence=[];
		foreach($attention as $item){
			$missing_evidence[]=(string)$item['label'].': '.(string)$item['action'];
		}
		foreach($runtime_quality_gates['gates'] ?? [] as $gate){
			if(!in_array((string)($gate['status'] ?? ''), ['ready', 'not_applicable'], true)){
				$missing_evidence[]=(string)($gate['label'] ?? $gate['id'] ?? 'runtime_quality_gate').': '.(string)($gate['action'] ?? 'Add focused evidence for this gate.');
			}
		}
		foreach($governance_baseline['checks'] ?? [] as $check){
			if(!in_array((string)($check['status'] ?? ''), ['ready', 'not_applicable'], true)){
				$missing_evidence[]=(string)($check['label'] ?? $check['id'] ?? 'governance_check').': '.(string)($check['action'] ?? 'Report missing governance evidence.');
			}
		}
		$missing_evidence=array_values(array_unique(array_filter($missing_evidence)));
		$claim_ready=$attention_ids===[] && ($runtime_quality_gates['ready'] ?? false)===true && ($governance_baseline['ready'] ?? false)===true;
		$recommended_verification=array_values(array_unique(array_filter([
			$mcp_files!==[] ? 'php -l runtime/modules/mcp/kernel/dataphyre_mcp.php runtime/modules/mcp/kernel/dataphyre_mcp.registry.php runtime/modules/mcp/kernel/dataphyre_mcp.client.php runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.state.php runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.start_pack.php runtime/modules/mcp/kernel/dataphyre_mcp.client.workflow.php runtime/modules/mcp/kernel/dataphyre_mcp.client.enterprise.php runtime/modules/mcp/kernel/dataphyre_mcp.client.skills.php runtime/modules/mcp/kernel/dataphyre_mcp.client.setup.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.contract.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.response.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.app_builder.schema.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.docs.php runtime/modules/mcp/kernel/dataphyre_mcp.planning.task_pack.php runtime/modules/mcp/kernel/dataphyre_mcp.inspection.data.php runtime/modules/mcp/kernel/dataphyre_mcp.inspection.routing.php runtime/modules/mcp/kernel/dataphyre_mcp.inspection.mvc.php runtime/modules/mcp/kernel/dataphyre_mcp.inspection.verification.php' : null,
			$mcp_files!==[] ? 'Dataphyre MCP publication evidence for MCP surface changes' : null,
			$hot_path_files!==[] ? 'maintainer/source-checkout benchmark evidence required before keeping Dataphyre shared hot-path changes' : null,
			$hot_path_files!==[] ? 'do not ask application agents to run contributor benchmark tooling' : null,
			$public_claim ? 'maintainer/source-checkout release check evidence before public claims' : null,
		])));
		$evidence_next_action=$this->mcp_enterprise_evidence_next_action($checklist, $runtime_quality_gates, $governance_baseline, $change_classification, $recommended_verification);
		$claim_summary=[
			'claim'=>$public_claim ? 'release_or_public_enterprise_claim' : 'internal_or_planning_claim',
			'disposition'=>$claim_ready ? 'ready_to_claim' : 'report_missing_evidence',
			'safe_statement'=>$claim_ready
				? 'Evidence supports the requested enterprise/corporate-ready claim for the inspected scope.'
				: 'Do not call this enterprise-ready or corporate-ready yet; report missing evidence and attention IDs first.',
			'missing_evidence_count'=>count($missing_evidence),
			'attention_count'=>count($attention_ids),
			'attention_ids'=>$attention_ids,
			'next_evidence'=>array_slice($missing_evidence, 0, 5),
			'evidence_next_action'=>$evidence_next_action,
			'verification_boundary'=>'Application behavior stays application-owned; Dataphyre framework, release-facing, and shared hot-path claims require source-checkout maintainer evidence.',
		];
		return [
			'audit_type'=>'dataphyre_mcp_enterprise_adoption_audit',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'feature'=>$feature!=='' ? $feature : null,
			'module'=>$module!=='' ? $module : null,
			'contract_resource'=>'dataphyre://agentic-enterprise',
			'checklist'=>$checklist,
			'change_classification'=>$change_classification,
			'extension_strategy'=>$extension_strategy,
			'runtime_quality_gates'=>$runtime_quality_gates,
			'governance_baseline'=>$governance_baseline,
			'governance_next_action'=>$governance_next_action,
			'claim_summary'=>$claim_summary,
			'status_counts'=>$statuses,
			'attention_count'=>count($attention_ids),
			'checklist_attention_count'=>count($attention),
			'attention_ids'=>$attention_ids,
			'path_summary'=>[
				'files'=>count($files),
				'app_files'=>count($app_files),
				'runtime_files'=>count($runtime_files),
				'module_files'=>count($module_files),
				'doc_files'=>count($doc_files),
				'dev_files'=>count($dev_files),
				'test_files'=>count($test_files),
				'plugin_files'=>count($plugin_files),
				'config_files'=>count($config_files),
				'mcp_files'=>count($mcp_files),
				'hot_path_files'=>count($hot_path_files),
				'portability_signal_count'=>count($path_portability_signals),
			],
			'module_summary'=>$module_evidence,
			'evidence_next_action'=>$evidence_next_action,
			'recommended_verification'=>$recommended_verification,
		];
	}

	/**
	 * Picks the next focused governance proof for elevated enterprise work.
	 *
	 * @param array<string,mixed> $governance_baseline Governance baseline summary.
	 * @param array<string,mixed> $change_classification Change classification summary.
	 * @return array<string,mixed> Copy-safe governance next action.
	 */
	private function mcp_enterprise_governance_next_action(array $governance_baseline, array $change_classification): array {
		foreach($governance_baseline['checks'] ?? [] as $check){
			if(!is_array($check) || in_array((string)($check['status'] ?? ''), ['ready', 'not_applicable'], true)){
				continue;
			}
			$id=(string)($check['id'] ?? 'governance_check');
			return [
				'owner'=>'elevated_enterprise_agent_or_maintainer',
				'status'=>'collect_governance_evidence',
				'id'=>$id,
				'label'=>(string)($check['label'] ?? $id),
				'action'=>(string)($check['action'] ?? 'Collect focused governance evidence for this check.'),
				'required_evidence'=>array_values(array_map('strval', is_array($check['required_evidence'] ?? null) ? $check['required_evidence'] : [])),
				'suggested_tools'=>array_values(array_map('strval', is_array($check['suggested_tools'] ?? null) ? $check['suggested_tools'] : [])),
				'field_source'=>'governance_baseline.checks.'.$id,
				'change_classification'=>(string)($change_classification['primary'] ?? 'needs_context'),
				'handoff_fields'=>['governance_next_action', 'governance_baseline.attention_ids', 'claim_summary.attention_ids', 'evidence_next_action'],
				'not_required'=>[
					'opening the full enterprise audit for ordinary app work',
					'raw logs, secrets, tenant/customer identifiers, signed URLs, or machine-local paths',
					'dataphyre_mcp_verify_all for ordinary app behavior',
					'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
				],
			];
		}
		return [
			'owner'=>'elevated_enterprise_agent_or_maintainer',
			'status'=>'governance_ready_for_inspected_scope',
			'id'=>'no_missing_governance_evidence',
			'action'=>'No missing governance-baseline evidence is reported for this inspected scope; keep the claim boundary attached to any handoff.',
			'required_evidence'=>[],
			'suggested_tools'=>[],
			'field_source'=>'governance_baseline',
			'change_classification'=>(string)($change_classification['primary'] ?? 'needs_context'),
			'handoff_fields'=>['governance_next_action', 'governance_baseline', 'claim_summary'],
			'not_required'=>[
				'new governance ceremony beyond the inspected claim scope',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Picks the next concrete evidence item for an enterprise adoption audit.
	 *
	 * @param array<int,array<string,mixed>> $checklist Audit checklist rows.
	 * @param array<string,mixed> $runtime_quality_gates Runtime quality gate summary.
	 * @param array<string,mixed> $governance_baseline Governance baseline summary.
	 * @param array<string,mixed> $change_classification Change classification summary.
	 * @param array<int,string> $recommended_verification Verification hints.
	 * @return array<string,mixed> Bounded next evidence action.
	 */
	private function mcp_enterprise_evidence_next_action(array $checklist, array $runtime_quality_gates, array $governance_baseline, array $change_classification, array $recommended_verification): array {
		foreach([
			['source'=>'checklist', 'items'=>$checklist],
			['source'=>'runtime_quality_gates', 'items'=>is_array($runtime_quality_gates['gates'] ?? null) ? $runtime_quality_gates['gates'] : []],
			['source'=>'governance_baseline', 'items'=>is_array($governance_baseline['checks'] ?? null) ? $governance_baseline['checks'] : []],
		] as $group){
			foreach($group['items'] as $item){
				if(!is_array($item) || in_array((string)($item['status'] ?? ''), ['ready', 'not_applicable'], true)){
					continue;
				}
				$source=(string)$group['source'];
				$id=(string)($item['id'] ?? 'unknown_evidence');
				return [
					'owner'=>$source==='governance_baseline' ? 'elevated_enterprise_agent_or_maintainer' : 'Dataphyre maintainer or scoped application agent',
					'status'=>'collect_missing_evidence',
					'source'=>$source,
					'id'=>$id,
					'label'=>(string)($item['label'] ?? $id),
					'action'=>(string)($item['action'] ?? 'Collect focused evidence for this audit item.'),
					'required_evidence'=>array_values(array_map('strval', is_array($item['required_evidence'] ?? null) ? $item['required_evidence'] : [])),
					'suggested_tools'=>array_values(array_map('strval', is_array($item['suggested_tools'] ?? null) ? $item['suggested_tools'] : [])),
					'field_source'=>$source.'.'.$id,
					'verification_hint'=>$recommended_verification[0] ?? 'focused evidence appropriate to the changed surface',
					'handoff_fields'=>['claim_summary.attention_ids', 'claim_summary.next_evidence', 'evidence_next_action', $source],
					'not_required'=>[
						'raw logs, secrets, tenant/customer identifiers, signed URLs, or machine-local paths',
						'application agents running maintainer release validation for ordinary app behavior',
						'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
					],
				];
			}
		}
		return [
			'owner'=>'Dataphyre maintainer or elevated enterprise agent for the inspected scope',
			'status'=>'ready_to_claim',
			'source'=>'claim_summary',
			'id'=>'no_missing_evidence',
			'action'=>'No missing audit evidence is reported for this inspected scope; keep the verification boundary attached to any claim.',
			'required_evidence'=>[],
			'suggested_tools'=>[],
			'verification_hint'=>($change_classification['benchmark_required'] ?? false)===true
				? 'maintainer/source-checkout benchmark evidence remains required for Dataphyre shared production hot-path claims'
				: 'focused verification appropriate to the changed surface',
			'handoff_fields'=>['claim_summary', 'recommended_verification'],
			'not_required'=>[
				'new governance ceremony beyond the inspected claim scope',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
		];
	}

	/**
	 * Maps proposed work to the contributor runtime quality gates.
	 *
	 * @param array<string,int> $counts Classified touched-file counts.
	 * @param array<string,mixed> $module_evidence Static module evidence from describe_module().
	 * @return array<string,mixed> Runtime quality gate summary.
	 */
	private function mcp_enterprise_runtime_quality_gates(array $counts, array $module_evidence): array {
		$module_known=($module_evidence['known'] ?? false)===true;
		$module_documented=(int)($module_evidence['documentation_files'] ?? 0)>0;
		$module_has_tests=(int)($module_evidence['unit_test_files'] ?? 0)>0;
		$has_docs=($counts['doc_files'] ?? 0)>0 || $module_documented;
		$has_proof=($counts['test_files'] ?? 0)>0 || ($counts['dev_files'] ?? 0)>0 || $module_has_tests;
		$has_runtime=($counts['runtime_files'] ?? 0)>0;
		$has_extension_layer=($counts['config_files'] ?? 0)>0 || ($counts['plugin_files'] ?? 0)>0;
		$gates=[
			[
				'id'=>'reusable_concept',
				'label'=>'Reusable Concept',
				'status'=>$has_runtime && !$module_known && !$has_extension_layer ? 'needs_evidence' : 'ready',
				'evidence'=>[
					'runtime_files'=>$counts['runtime_files'] ?? 0,
					'module_known'=>$module_known,
					'extension_layer_files'=>($counts['config_files'] ?? 0)+($counts['plugin_files'] ?? 0),
				],
				'action'=>'Show the behavior applies across modules, applications, adapters, or installs before putting it in Dataphyre.',
			],
			[
				'id'=>'inspectable_contract',
				'label'=>'Inspectable Contract',
				'status'=>$has_docs || $module_known ? 'ready' : 'unknown',
				'evidence'=>[
					'doc_files'=>$counts['doc_files'] ?? 0,
					'module_known'=>$module_known,
					'module_documented'=>$module_documented,
				],
				'action'=>'Name and document public or framework-facing behavior through docs, manifests, diagnostics, or MCP.',
			],
			[
				'id'=>'provenance',
				'label'=>'Provenance',
				'status'=>($counts['portability_signal_count'] ?? 0)===0 ? 'ready' : 'needs_review',
				'evidence'=>[
					'portability_signal_count'=>$counts['portability_signal_count'] ?? 0,
					'config_files'=>$counts['config_files'] ?? 0,
					'plugin_files'=>$counts['plugin_files'] ?? 0,
				],
				'action'=>'Trace runtime decisions to config, tenant, module default, plugin, callback, request, or external service instead of copied local values.',
			],
			[
				'id'=>'verification',
				'label'=>'Verification',
				'status'=>$has_proof ? 'ready' : 'unknown',
				'evidence'=>[
					'test_files'=>$counts['test_files'] ?? 0,
					'dev_files'=>$counts['dev_files'] ?? 0,
					'module_has_tests'=>$module_has_tests,
				],
				'action'=>'Add targeted tests, release checks, diagnostics, or a reproducible manual check for behavior changes.',
			],
			[
				'id'=>'small_surface',
				'label'=>'Small Surface',
				'status'=>$has_runtime && ($counts['module_files'] ?? 0)===0 && !$has_docs ? 'needs_review' : 'ready',
				'evidence'=>[
					'runtime_files'=>$counts['runtime_files'] ?? 0,
					'module_files'=>$counts['module_files'] ?? 0,
					'doc_files'=>$counts['doc_files'] ?? 0,
				],
				'action'=>'Prefer one reusable contract over module-local special cases, and avoid long-lived state or dependencies without clear ownership.',
			],
		];
		$attention=array_values(array_filter(
			$gates,
			static fn(array $gate): bool => !in_array((string)$gate['status'], ['ready', 'not_applicable'], true)
		));
		return [
			'contract'=>'maintainer/source-checkout runtime quality gates',
			'gates'=>$gates,
			'attention_ids'=>array_map(static fn(array $gate): string => (string)$gate['id'], $attention),
			'ready'=>count($attention)===0,
		];
	}

	/**
	 * Maps proposed work to the corporate governance baseline.
	 *
	 * @param array<string,int> $counts Classified touched-file counts.
	 * @param array<string,mixed> $module_evidence Static module evidence from describe_module().
	 * @param array<string,mixed> $change_classification Enterprise change classification.
	 * @return array<string,mixed> Governance baseline summary.
	 */
	private function mcp_enterprise_governance_baseline(array $counts, array $module_evidence, array $change_classification): array {
		$module_known=($module_evidence['known'] ?? false)===true;
		$module_documented=(int)($module_evidence['documentation_files'] ?? 0)>0;
		$has_docs=($counts['doc_files'] ?? 0)>0 || $module_documented;
		$has_config_or_plugin=(($counts['config_files'] ?? 0)+($counts['plugin_files'] ?? 0))>0;
		$has_proof=(($counts['test_files'] ?? 0)+($counts['dev_files'] ?? 0))>0 || (int)($module_evidence['unit_test_files'] ?? 0)>0;
		$classification=(string)($change_classification['primary'] ?? 'needs_context');
		$checks=[
			[
				'id'=>'tenant_application_boundary',
				'label'=>'Tenant and application boundaries are explicit',
				'status'=>$has_config_or_plugin || $has_docs || in_array($classification, ['framework_control_plane', 'application_extension'], true) ? 'ready' : 'needs_evidence',
				'evidence'=>[
					'config_files'=>$counts['config_files'] ?? 0,
					'plugin_files'=>$counts['plugin_files'] ?? 0,
					'doc_files'=>$counts['doc_files'] ?? 0,
					'change_classification'=>$classification,
				],
				'action'=>'Identify tenant, application, and provider boundaries through config, module contracts, typed references, or docs before corporate-ready claims.',
				'required_evidence'=>[
					'tenant/application ownership source or explicit non-tenant statement',
					'config, module contract, typed reference, or docs path naming the boundary',
					'provider or external-service boundary when data leaves the application',
				],
				'suggested_tools'=>['dataphyre_config_shape_read', 'dataphyre_module_docs_pack', 'dataphyre_source_api_summary'],
			],
			[
				'id'=>'access_permission_policy',
				'label'=>'Access and permission policy is owned by reusable contracts',
				'status'=>$module_known || $has_config_or_plugin ? 'ready' : 'needs_evidence',
				'evidence'=>[
					'module_known'=>$module_known,
					'config_files'=>$counts['config_files'] ?? 0,
					'plugin_files'=>$counts['plugin_files'] ?? 0,
				],
				'action'=>'Point policy decisions at reusable modules, callbacks, dialbacks, plugins, or documented adapters instead of controller-local rules.',
				'required_evidence'=>[
					'policy owner and enforcement surface',
					'callback, dialback, plugin, module contract, or application adapter reference',
					'focused verification for allow/deny or scoped-access behavior',
				],
				'suggested_tools'=>['dataphyre_module_docs_pack', 'dataphyre_source_api_summary', 'dataphyre_verification_surface_catalog'],
			],
			[
				'id'=>'audit_trace_evidence',
				'label'=>'Audit and trace evidence is planned for sensitive decisions',
				'status'=>$has_proof || $has_docs || $classification==='framework_control_plane' ? 'ready' : 'unknown',
				'evidence'=>[
					'dev_files'=>$counts['dev_files'] ?? 0,
					'test_files'=>$counts['test_files'] ?? 0,
					'doc_files'=>$counts['doc_files'] ?? 0,
					'module_has_tests'=>(int)($module_evidence['unit_test_files'] ?? 0)>0,
				],
				'action'=>'Make billing, security, storage, messaging, and data-movement decisions traceable through tests, diagnostics, docs, or runtime traces.',
				'required_evidence'=>[
					'event source and actor/account identifier shape',
					'decision fields for billing/security/storage/messaging/data movement',
					'retention or lifecycle policy for audit data',
					'focused test, diagnostic, trace summary, or docs proving the trace path',
				],
				'suggested_tools'=>['dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_search', 'dataphyre_unit_tests_list', 'dataphyre_verification_surface_catalog'],
			],
			[
				'id'=>'redaction_data_classification',
				'label'=>'Redaction and data classification boundaries are visible',
				'status'=>($counts['portability_signal_count'] ?? 0)===0 ? 'ready' : 'needs_review',
				'evidence'=>[
					'portability_signal_count'=>$counts['portability_signal_count'] ?? 0,
				],
				'action'=>'Redact paths, tenant names, credentials, signed URLs, auth material, and product identifiers before sharing diagnostics or package artifacts.',
				'required_evidence'=>[
					'data categories handled by the feature',
					'redaction policy for diagnostics, logs, MCP payloads, and package artifacts',
					'copy-safe evidence shape that excludes secrets, tenant/customer identifiers, signed URLs, and local paths',
				],
				'suggested_tools'=>['dataphyre_mcp_safety_boundary_report', 'dataphyre_tracelog_read', 'dataphyre_diagnostics_last_error'],
			],
			[
				'id'=>'framework_vs_application_verification',
				'label'=>'Framework claims are separated from application-owned behavior',
				'status'=>($change_classification['benchmark_required'] ?? false)===true && ($counts['dev_files'] ?? 0)===0 ? 'needs_evidence' : ($has_proof || in_array($classification, ['application_extension', 'framework_control_plane'], true) ? 'ready' : 'unknown'),
				'evidence'=>[
					'change_classification'=>$classification,
					'test_files'=>$counts['test_files'] ?? 0,
					'dev_files'=>$counts['dev_files'] ?? 0,
					'benchmark_required'=>($change_classification['benchmark_required'] ?? false)===true,
				],
				'action'=>'Use Dataphyre tests, diagnostics, release checks, and hot-path benchmarks for framework claims; keep application behavior verification application-owned.',
				'required_evidence'=>[
					'Dataphyre framework proof for framework claims',
					'application-owned verification handoff for application behavior',
					'maintainer/source-checkout benchmark evidence only for Dataphyre shared production hot-path changes',
				],
				'suggested_tools'=>['dataphyre_verification_surface_catalog', 'dataphyre_mcp_readiness_report', 'dataphyre_mcp_enterprise_adoption_audit'],
			],
		];
		$attention=array_values(array_filter(
			$checks,
			static fn(array $check): bool => !in_array((string)$check['status'], ['ready', 'not_applicable'], true)
		));
		return [
			'contract'=>'docs/AGENTIC_ENTERPRISE.md#governance-baseline',
			'checks'=>$checks,
			'attention_ids'=>array_map(static fn(array $check): string => (string)$check['id'], $attention),
			'ready'=>count($attention)===0,
			'claim_rule'=>'If these boundaries are not inspectable yet, report missing evidence instead of calling the feature corporate-ready.',
			'evidence_handoff'=>[
				'owner'=>'Dataphyre maintainer or elevated enterprise agent for the inspected scope',
				'copy_safe_fields'=>['check_id', 'status', 'required_evidence', 'suggested_tools', 'focused pass/fail summary', 'remaining gaps'],
				'not_included'=>['raw logs', 'secrets', 'tenant/customer identifiers', 'signed URLs', 'machine-local absolute paths', 'Dataphyre hot-path benchmark output'],
				'use'=>'Copy attention_ids plus each matching check required_evidence list when handing off corporate-ready, enterprise-readiness, security, governance, or access-policy work.',
			],
		];
	}

	/**
	 * Classifies proposed work so agents do not push application rules into Dataphyre.
	 *
	 * @param array<string,int> $counts Classified touched-file counts.
	 * @param array<string,mixed> $module_evidence Static module evidence from describe_module().
	 * @param bool $public_claim Whether the caller intends release-facing claims.
	 * @return array<string,mixed> Change classification and proof expectations.
	 */
	private function mcp_enterprise_change_classification(array $counts, array $module_evidence, bool $public_claim): array {
		$has_app=($counts['app_files'] ?? 0)>0;
		$has_runtime=($counts['runtime_files'] ?? 0)>0;
		$has_mcp=($counts['mcp_files'] ?? 0)>0;
		$has_dev_or_docs=(($counts['dev_files'] ?? 0)+($counts['doc_files'] ?? 0))>0;
		$has_extension_layer=(($counts['config_files'] ?? 0)+($counts['plugin_files'] ?? 0))>0;
		$has_hot_path=($counts['hot_path_files'] ?? 0)>0;
		$module_known=($module_evidence['known'] ?? false)===true;
		$primary='needs_context';
		if($has_hot_path){
			$primary='dataphyre_hot_path_candidate';
		}elseif($has_app && !$has_runtime){
			$primary='application_extension';
		}elseif($has_mcp || ($has_dev_or_docs && !$has_runtime)){
			$primary='framework_control_plane';
		}elseif($has_runtime && ($module_known || ($counts['module_files'] ?? 0)>0)){
			$primary='dataphyre_reusable_contract';
		}elseif($has_extension_layer){
			$primary='install_extension_layer';
		}elseif($public_claim){
			$primary='release_claim_review';
		}
		$benchmark_required=$has_hot_path;
		return [
			'primary'=>$primary,
			'benchmark_required'=>$benchmark_required,
			'benchmark_scope'=>'Dataphyre shared production hot paths only; application changes using Dataphyre and MCP/dev/docs control-plane changes do not need MCP-imposed microbenchmarks by default.',
			'proof_contract'=>$benchmark_required ? 'Source-checkout maintainer benchmark evidence, including CLI/opcache/opcache-JIT profiles, is required before keeping Dataphyre shared hot-path changes.' : 'Focused verification appropriate to the changed surface.',
			'application_boundary'=>$has_app ? 'Application behavior should stay in application code, config, callbacks, dialbacks, plugins, or an application-owned adapter unless the behavior is reusable Dataphyre framework work.' : 'No application file inputs were provided.',
			'framework_edit_rule'=>'Edit Dataphyre runtime internals only for reusable framework behavior, diagnostics, safety, public APIs, or performance work with proof.',
			'evidence'=>[
				'app_files'=>$counts['app_files'] ?? 0,
				'runtime_files'=>$counts['runtime_files'] ?? 0,
				'mcp_files'=>$counts['mcp_files'] ?? 0,
				'dev_files'=>$counts['dev_files'] ?? 0,
				'doc_files'=>$counts['doc_files'] ?? 0,
				'config_files'=>$counts['config_files'] ?? 0,
				'plugin_files'=>$counts['plugin_files'] ?? 0,
				'hot_path_files'=>$counts['hot_path_files'] ?? 0,
			],
		];
	}

	/**
	 * Finds Dataphyre shared production hot-path file inputs from caller paths.
	 *
	 * @param array<int,string> $files Caller-provided path strings.
	 * @return array<int,string> Repo-relative file paths that need benchmark proof if changed.
	 */
	private function mcp_enterprise_hot_path_files(array $files): array {
		$hot_path_prefixes=[
			'runtime/bootstrap',
			'runtime/modules/core/kernel/',
			'runtime/modules/core/Framework/',
			'runtime/modules/routing/kernel/compiled_route_dispatcher.php',
			'runtime/modules/routing/Framework/RouteCompiler.php',
			'runtime/modules/routing/Framework/RouteManifest.php',
			'runtime/modules/sql/kernel/',
			'runtime/modules/sql/Framework/',
			'runtime/modules/templating/kernel/parsing.php',
			'runtime/modules/templating/kernel/rendering.php',
			'runtime/modules/templating/kernel/render_helpers.php',
			'runtime/modules/templating/Framework/TemplatingManager.php',
		];
		$matches=[];
		foreach($files as $file){
			foreach($hot_path_prefixes as $prefix){
				if(str_starts_with($file, $prefix)){
					$matches[]=$file;
					break;
				}
			}
		}
		return array_values(array_unique($matches));
	}

	/**
	 * Normalizes caller file paths for Dataphyre-root enterprise classification.
	 *
	 * @param string $file Caller-provided file path.
	 * @return string Path relative to the Dataphyre package root when possible.
	 */
	private function mcp_enterprise_normalize_audit_path(string $file): string {
		$file=trim(str_replace('\\', '/', $file));
		while(str_contains($file, '//')){
			$file=str_replace('//', '/', $file);
		}
		while(str_starts_with($file, './')){
			$file=substr($file, 2);
		}
		foreach(['common/dataphyre/', 'dataphyre/'] as $prefix){
			if(str_starts_with($file, $prefix)){
				return substr($file, strlen($prefix));
			}
		}
		return $file;
	}

	/**
	 * Builds the extension ladder agents should inspect before framework edits.
	 *
	 * @param array<string,int> $counts Classified touched-file counts.
	 * @param array<string,mixed> $module_evidence Static module evidence from describe_module().
	 * @return array<string,mixed> Ordered extension strategy for agent use.
	 */
	private function mcp_enterprise_extension_strategy(array $counts, array $module_evidence): array {
		$module_known=($module_evidence['known'] ?? false)===true;
		$module_documented=(int)($module_evidence['documentation_files'] ?? 0)>0;
		$module_has_framework=(int)($module_evidence['framework_files'] ?? 0)>0;
		$module_has_tests=(int)($module_evidence['unit_test_files'] ?? 0)>0;
		$layers=[
			[
				'id'=>'review_extension_boundary',
				'label'=>'Extension boundary review',
				'status'=>'available',
				'use_when'=>'Runtime files are proposed without config, plugin, docs, or reusable-contract evidence.',
				'agent_action'=>'Pause and prove why config, dialbacks, callbacks, plugins, MCP metadata, or an application adapter cannot carry the behavior before editing internals.',
			],
			[
				'id'=>'config',
				'label'=>'Install configuration',
				'status'=>($counts['config_files'] ?? 0)>0 ? 'observed' : 'available',
				'use_when'=>'Tenant, environment, provider, driver, and policy choices vary by install.',
				'agent_action'=>'Prefer config shape and redacted previews before changing Dataphyre defaults.',
			],
			[
				'id'=>'dialbacks_callbacks',
				'label'=>'Module dialbacks and callbacks',
				'status'=>$module_known && $module_documented ? 'inspect' : 'unknown',
				'use_when'=>'The module already owns an event, resolver, policy, adapter, or lifecycle decision.',
				'agent_action'=>'Read module docs and describe_module output for callback or dialback contracts before editing internals.',
			],
			[
				'id'=>'install_plugins',
				'label'=>'Install plugin hooks',
				'status'=>($counts['plugin_files'] ?? 0)>0 ? 'observed' : 'available',
				'use_when'=>'An application needs install-local boot behavior without changing reusable framework code.',
				'agent_action'=>'Use plugins/pre_init, plugins/post_init, or plugins/mcp metadata for local integration.',
			],
			[
				'id'=>'mcp_metadata',
				'label'=>'Local MCP metadata',
				'status'=>($counts['mcp_files'] ?? 0)>0 ? 'framework_mcp_surface' : 'available',
				'use_when'=>'Agents need local visibility into redacted or install-specific modules without public release exposure.',
				'agent_action'=>'Use plugins/mcp declarations for install-local tool visibility; keep them out of public exports.',
			],
			[
				'id'=>'reusable_module_contract',
				'label'=>'Reusable module contract',
				'status'=>$module_known && $module_documented && ($module_has_framework || ($counts['module_files'] ?? 0)>0) ? 'inspect' : 'needs_evidence',
				'use_when'=>'The behavior belongs to Dataphyre as a reusable public API, typed contract, diagnostic, or module capability.',
				'agent_action'=>'Pair runtime/module edits with docs, tests, diagnostics, and release checks.',
			],
		];
		$recommended='config';
		if(($counts['runtime_files'] ?? 0)>0 && ($counts['config_files'] ?? 0)===0 && ($counts['plugin_files'] ?? 0)===0 && ($counts['doc_files'] ?? 0)===0){
			$recommended='review_extension_boundary';
		}elseif(($counts['config_files'] ?? 0)>0){
			$recommended='config';
		}elseif(($counts['plugin_files'] ?? 0)>0){
			$recommended='install_plugins';
		}elseif($module_known && $module_documented){
			$recommended='dialbacks_callbacks';
		}elseif(($counts['module_files'] ?? 0)>0 || $module_has_tests){
			$recommended='reusable_module_contract';
		}
		return [
			'preferred_order'=>array_column($layers, 'id'),
			'recommended_next_layer'=>$recommended,
			'layers'=>$layers,
			'framework_edit_rule'=>'Only edit Dataphyre runtime internals when the behavior is reusable framework work; app-specific behavior belongs in config, callbacks, dialbacks, plugins, MCP metadata, or application adapters.',
		];
	}

	/**
	 * Builds bounded module evidence for enterprise-readiness planning.
	 *
	 * @param string $module Optional module name supplied by the caller.
	 * @return array<string,mixed> Redaction-safe module evidence.
	 */
	private function mcp_enterprise_module_evidence(string $module): array {
		if($module===''){
			return ['module'=>null, 'known'=>false];
		}
		try{
			$description=$this->describe_module($module, 80);
		}catch(Throwable){
			return [
				'module'=>$module,
				'known'=>false,
				'documentation_files'=>0,
				'framework_files'=>0,
				'kernel_files'=>0,
				'unit_test_files'=>0,
			];
		}
		return [
			'module'=>(string)($description['module'] ?? $module),
			'known'=>true,
			'visibility'=>(string)($description['visibility'] ?? ''),
			'release'=>(string)($description['release'] ?? ''),
			'has_framework'=>($description['has_framework'] ?? false)===true,
			'has_kernel'=>($description['has_kernel'] ?? false)===true,
			'has_unit_tests'=>($description['has_unit_tests'] ?? false)===true,
			'documentation_files'=>count($description['files']['documentation'] ?? []),
			'framework_files'=>count($description['files']['framework'] ?? []),
			'kernel_files'=>count($description['files']['kernel'] ?? []),
			'unit_test_files'=>count($description['files']['unit_tests'] ?? []),
		];
	}

	/**
	 * Classifies non-portable path input without returning raw path values.
	 *
	 * @param array<int,string> $files Caller-provided path strings.
	 * @return array<int,string> Redaction-safe portability signal codes.
	 */
	private function mcp_enterprise_path_portability_signals(array $files): array {
		$signals=[];
		foreach($files as $file){
			$lower=strtolower($file);
			if(preg_match('/^[a-z]:\//i', $file)===1 || str_starts_with($file, '/') || str_starts_with($file, '~/')){
				$signals[]='absolute_or_home_path';
			}
			if(str_contains($lower, '.local/') || str_contains($lower, 'localhost:') || str_contains($lower, '127.0.0.1')){
				$signals[]='machine_local_reference';
			}
			if(str_contains($lower, 'http://') || str_contains($lower, 'https://')){
				$signals[]='hardcoded_url';
			}
			foreach(['token=', 'totp=', 'signature=', 'passkey=', 'secret=', 'password=', 'plan=', 'plan_id=', 'subscription_id=', 'entitlement_id='] as $needle){
				if(str_contains($lower, $needle)){
					$signals[]='url_secret_parameter';
					break;
				}
			}
		}
		return array_values(array_unique($signals));
	}

	/**
	 * Infers whether task text is asking for a release-facing claim.
	 *
	 * @param string $task Task or feature text.
	 * @return bool True when the wording implies enterprise, corporate, or release positioning.
	 */
	private function mcp_task_implies_release_claim(string $task): bool {
		$task=strtolower($task);
		foreach(['enterprise-ready', 'corporate-ready', 'release-facing', 'release summary', 'release notes', 'public claim', 'public release', 'publish', 'publication', 'agent-first claim', 'agent-first release'] as $needle){
			if(str_contains($task, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Classifies how much enterprise review an agent task should carry by default.
	 *
	 * @param string $task Task or feature text.
	 * @return array<string,mixed> Proportional guidance for start packs and briefs.
	 */
	private function mcp_task_proportional_guidance(string $task): array {
		$lower=strtolower($task);
		$signals=[];
		$required_reviews=[];
		if($this->mcp_task_implies_release_claim($task)){
			$signals[]='release_or_enterprise_claim';
			$required_reviews[]='enterprise_audit';
			$required_reviews[]='governance_baseline';
		}
		foreach(['security', 'permission', 'authorization', 'authorize', 'rbac', 'acl', 'access', 'auth', 'authentication', 'oauth', 'openid', 'oidc', 'sso', 'saml', 'mfa', '2fa', 'jwt', 'session', 'cookie', 'password', 'role', 'policy', 'tenant', 'workspace isolation', 'tenant isolation', 'totp', 'token', 'secret', 'secrets management', 'credential', 'key', 'kms', 'encryption', 'privacy', 'pii', 'gdpr', 'hipaa', 'soc2', 'sox', 'compliance', 'governance', 'billing', 'audit', 'data-boundary', 'data boundary', 'data residency', 'data retention', 'retention policy', 'records retention', 'legal hold'] as $needle){
			if(str_contains($lower, $needle)){
				$signals[]='security_governance_or_data_boundary';
				$required_reviews[]='enterprise_audit';
				$required_reviews[]='governance_baseline';
				break;
			}
		}
		foreach(['dataphyre runtime', 'framework internals', 'core runtime', 'shared runtime', 'shared production hot path', 'shared production hot-path', 'dataphyre hot path', 'dataphyre hot-path', 'dataphyre performance', 'dataphyre benchmark', 'dataphyre opcache', 'dataphyre jit', 'runtime benchmark', 'framework benchmark', 'source-checkout benchmark'] as $needle){
			if(str_contains($lower, $needle)){
				$signals[]='framework_or_hot_path_work';
				$required_reviews[]='enterprise_audit';
				$required_reviews[]='runtime_quality_gates';
				break;
			}
		}
		if($required_reviews===[] && preg_match('/\b(performance|benchmark|hot path|hot-path|opcache|jit)\b/', $lower)===1){
			$signals[]='application_performance_tuning';
		}
		$required_reviews=array_values(array_unique($required_reviews));
		$required=$required_reviews!==[];
		return [
			'tier'=>$required ? 'elevated' : 'lightweight',
			'enterprise_review_required'=>$required,
			'required_reviews'=>$required_reviews,
			'signals'=>array_values(array_unique($signals)),
			'default_rule'=>$required
				? 'Use the named enterprise reviews before making the elevated-risk claim or framework change.'
				: 'Keep the default workflow lightweight; use focused verification and extension boundaries unless the task matches escalation triggers: '.$this->mcp_escalation_trigger_summary().'.',
			'escalate_when'=>[
				'release-facing or corporate-ready claims',
				'security, identity/access, governance, tenant isolation, billing, privacy, compliance, data residency, retention, or data-boundary work',
				'Dataphyre framework internals or shared production hot-path work',
			],
		];
	}

}
