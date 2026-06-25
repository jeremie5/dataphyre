<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines MCP verification catalog and bounded verification helper surfaces.
 */
trait dataphyre_mcp_inspection_verification_surfaces {

	/**
	 * Describes reusable verification catalog safety boundaries.
	 *
	 * @param string $surface Verification metadata surface label.
	 * @return array<string,mixed> Verification safety metadata for app-agent consumption.
	 */
	private function verification_safety_contract(string $surface): array {
		return [
			'surface'=>$surface,
			'classification'=>'verification_metadata_or_bounded_wrapper_contract',
			'application_default'=>'safe_for_selecting_focused_app_or_module_verification_without_execution',
			'ordinary_app_summary'=>[
				'owner'=>'consuming_application',
				'verification'=>'focused application or module checks',
				'default_posture'=>'read_only_metadata_first',
			],
			'boundary_refs'=>[
				'application_agent_operating_contract'=>'dataphyre_mcp_readiness_report',
				'ordinary_app_work'=>'dataphyre_mcp_readiness_report',
				'tool_audience_boundaries'=>'dataphyre_mcp_readiness_report',
			],
			'allowed_for_app_agents'=>[
				'unit-test manifest discovery without executing helpers',
				'browser regression manifest schema review without launching browsers',
				'Flightdeck surface inventory without dispatching surfaces',
				'verification catalog selection metadata',
				'known bounded wrapper names for deliberate local validation',
			],
			'not_proof_of'=>[
				'application behavior correctness',
				'route dispatch success',
				'browser rendering success',
				'SQL-backed behavior',
				'enterprise-ready public claims',
			],
			'not_performed'=>[
				'test helper execution',
				'custom script execution',
				'browser launch',
				'route dispatch',
				'HTTP requests',
				'SQL queries',
				'file writes',
			],
			'escalate_only_for'=>'Use maintainer/source-checkout aggregate verification only for MCP/release-surface claims, published shared MCP setup docs, public enterprise claims, Dataphyre framework work, or shared hot-path changes.',
		];
	}

	/**
	 * Lists Flightdeck surface files and their static dispatch, route, asset, and class metadata.
	 *
	 * reads source from the Flightdeck surfaces directory and optionally includes source summaries.
	 * Surface dispatch is reported as source metadata and is never executed by this MCP tool.
	 *
	 * @param array{include_source_summary?: bool} $args Surface listing options.
	 * @return array{surface_count: int, surfaces: array<int, array>, dispatch_policy: string} Flightdeck surface inventory.
	 */
	private function list_flightdeck_surfaces(array $args): array {
		$surfaces_root=$this->common_root.'/dataphyre/runtime/modules/flightdeck/kernel/surfaces';
		$include_source=(bool)($args['include_source_summary'] ?? false);
		$surfaces=[];
		foreach(glob($surfaces_root.'/*.php') ?: [] as $path){
			$text=(string)file_get_contents($path);
			$entry=[
				'name'=>basename($path, '.php'),
				'path'=>$this->relative_path($path),
				'classes'=>$this->php_source_api_file_summary($path)['classes'] ?? [],
				'routes'=>$this->extract_flightdeck_route_strings($text),
				'assets'=>$this->extract_flightdeck_asset_names($text),
				'dispatches'=>str_contains($text, '::dispatch()'),
			];
			if($include_source){
				$entry['source_summary']=$this->php_source_api_file_summary($path);
			}
			$surfaces[]=$entry;
		}
		usort($surfaces, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
		return [
			'surface_count'=>count($surfaces),
			'surfaces'=>$surfaces,
			'dispatch_policy'=>'not_dispatched_by_mcp',
			'verification_safety'=>$this->verification_safety_contract('flightdeck_surfaces'),
		];
	}

	/**
	 * Lists Dataphyre unit-test JSON manifests with lightweight execution metadata.
	 *
	 * discovers manifest files under runtime modules, applies optional module filters, and summarizes
	 * case counts and helper/custom-script presence without running test helpers.
	 *
	 * @param array{modules?: array<int, string>, limit?: int} $args Manifest listing options.
	 * @return array{modules: array<int, string>, manifest_count: int, manifests: array<int, array>} Unit-test manifest inventory.
	 */
	private function list_unit_test_manifests(array $args): array {
		$requested=$args['modules'] ?? [];
		$module_filter=[];
		if(is_array($requested)){
			foreach($requested as $module){
				$module=trim((string)$module);
				if($module!==''){
					$module_filter[]=$module;
				}
			}
		}
		$limit=max(1, min((int)($args['limit'] ?? 80) ?: 80, 300));
		$manifests=[];
		$modules_root=$this->common_root.'/dataphyre/runtime/modules';
		foreach($this->all_files($modules_root, 30000) as $path){
			$relative=$this->relative_path($path);
			if(!$this->is_unit_test_manifest($relative)){
				continue;
			}
			$module=$this->module_from_unit_test_path($relative);
			if($module_filter!==[] && !in_array($module, $module_filter, true)){
				continue;
			}
			$summary=$this->unit_test_manifest_summary($path, 3, false);
			$manifests[]=[
				'path'=>$relative,
				'module'=>$module,
				'case_count'=>$summary['case_count'],
				'helper_files'=>$summary['helper_files'],
				'has_custom_script'=>$summary['has_custom_script'],
				'modified_at'=>$this->file_modified_iso($path),
			];
			if(count($manifests)>=$limit){
				break;
			}
		}
		return [
			'modules'=>$module_filter,
			'manifest_count'=>count($manifests),
			'manifests'=>$manifests,
			'verification_safety'=>$this->verification_safety_contract('unit_tests_list'),
		];
	}

	/**
	 * Reads a bounded Dataphyre unit-test manifest summary.
	 *
	 * accepts only repo-local unit_tests JSON manifests, reports module ownership and selected cases,
	 * and leaves helper scripts unexecuted.
	 *
	 * @param array{path?: string, max_cases?: int, include_expected?: bool} $args Manifest read options.
	 * @return array<string, mixed> Manifest summary.
	 *
	 * @throws InvalidArgumentException When the path is not an allowed unit-test manifest.
	 */
	private function read_unit_test_manifest(array $args): array {
		$path=$this->safe_repo_path((string)($args['path'] ?? ''));
		$relative=$this->relative_path($path);
		if(!is_file($path) || !$this->is_unit_test_manifest($relative)){
			throw new InvalidArgumentException('path must point to a repo-local unit_tests/*.json manifest.');
		}
		$max_cases=max(1, min((int)($args['max_cases'] ?? 40) ?: 40, 200));
		return array_replace([
			'path'=>$relative,
			'module'=>$this->module_from_unit_test_path($relative),
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'verification_safety'=>$this->verification_safety_contract('unit_test_manifest'),
		], $this->unit_test_manifest_summary($path, $max_cases, (bool)($args['include_expected'] ?? false)));
	}

	/**
	 * Runs the registered route-free Panel regression command boundary.
	 *
	 * command construction is explicit, paths are resolved through repo-safe helpers, and the optional
	 * suite path is required when the example suite is disabled. This surface executes a bounded local verification
	 * command rather than arbitrary shell text.
	 *
	 * @param array{example?: bool, suite_path?: string, json_path?: string} $args Panel regression run options.
	 * @return array<string, mixed> Bounded command result.
	 *
	 * @throws InvalidArgumentException When a custom suite run omits suite_path.
	 */
	private function run_panel_regression(array $args): array {
		$php=$this->php_binary();
		$script=$this->common_root.'/dataphyre/runtime/modules/panel/kernel/panel_regression.php';
		$use_example=!array_key_exists('example', $args) || $args['example'];
		$command=[$php, $script];
		if($use_example){
			$command[]='--example';
		}else{
			$suite_path=trim((string)($args['suite_path'] ?? ''));
			if($suite_path===''){
				throw new InvalidArgumentException('suite_path is required when example is false.');
			}
			$command[]='--suite';
			$command[]=$this->safe_repo_path($suite_path);
		}
		if(isset($args['json_path']) && trim((string)$args['json_path'])!==''){
			$command[]='--json';
			$command[]=$this->safe_repo_path((string)$args['json_path']);
		}
		return $this->run_command($command, 120000, true);
	}

	/**
	 * Runs the Panel field catalog verification command.
	 *
	 * uses the configured PHP binary and a fixed first-party script path, producing a bounded command
	 * result without accepting caller-provided shell fragments.
	 *
	 * @return array<string, mixed> Bounded command result.
	 */
	private function run_panel_field_catalog_check(): array {
		$script=$this->common_root.'/dataphyre/runtime/modules/panel/kernel/panel_field_catalog_check.php';
		return $this->run_command([$this->php_binary(), $script], 120000, true);
	}

	/**
	 * Summarizes Panel browser regression manifest classes and contract fields.
	 *
	 * tokenizes first-party testing classes and documents the manifest schema used by browser
	 * workflows. It does not launch browsers or execute regression manifests.
	 *
	 * @return array<string, mixed> Browser regression manifest contract summary.
	 */
	private function browser_regression_manifest_summary(): array {
		$paths=[
			'common/dataphyre/runtime/modules/panel/Framework/Testing/PanelBrowserRegressionManifest.php',
			'common/dataphyre/runtime/modules/panel/Framework/Testing/PanelAccessibilityAudit.php',
			'common/dataphyre/runtime/modules/panel/Framework/Testing/PanelRegressionSuite.php',
			'common/dataphyre/runtime/modules/panel/Framework/Testing/PanelRegressionReport.php',
		];
		$classes=[];
		foreach($paths as $path){
			$absolute=$this->safe_repo_path($path);
			if(!is_file($absolute)){
				continue;
			}
			$summary=$this->php_source_api_file_summary($absolute);
			foreach($summary['classes'] ?? [] as $class){
				$methods=[];
				foreach($class['methods'] ?? [] as $method){
					if(($method['visibility'] ?? 'public')!=='public'){
						continue;
					}
					$methods[]=[
						'name'=>$method['name'] ?? '',
						'static'=>$method['static'] ?? false,
						'signature'=>$method['signature'] ?? '',
					];
				}
				$classes[]=[
					'file'=>$path,
					'name'=>$class['fqcn'] ?? $class['name'] ?? '',
					'kind'=>$class['kind'] ?? 'class',
					'public_methods'=>$methods,
				];
			}
		}
		return [
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'module'=>'panel',
			'verification_safety'=>$this->verification_safety_contract('browser_regression_manifest'),
			'classes'=>$classes,
			'manifest_contract'=>[
				'type'=>'panel_browser_regression_manifest',
				'fields'=>['name', 'url', 'viewport', 'interactions', 'screenshot_path', 'console_policy', 'expected_selectors', 'accessibility', 'result', 'meta'],
				'interaction_fields'=>['type', 'selector', 'text', 'value', 'key', 'url', 'path', 'timeout_ms', 'delay_ms', 'options'],
				'accessibility_defaults'=>[
					'enabled'=>true,
					'fail_on'=>['critical', 'serious'],
				],
				'console_defaults'=>[
					'fail_on'=>['error'],
				],
			],
			'recommended_workflow'=>[
				'Use route-free Panel regression first for server-side behavior.',
				'Use this manifest summary to generate or review browser runner inputs.',
				'Keep actual browser execution outside MCP unless an explicit safe browser runner is added later.',
			],
		];
	}

	/**
	 * Catalogs verification surfaces across runtime modules and shared MCP tools.
	 *
	 * discovers manifests, diagnostic files, regression/check scripts, and known tool wrappers while
	 * keeping every surface non-executed. The catalog separates what exists from what is safe to run through registered
	 * bounded MCP wrappers.
	 *
	 * @param array{modules?: array<int, string>, include_diagnostics?: bool, limit?: int} $args Catalog filters.
	 * @return array<string, mixed> Verification surface catalog.
	 */
	private function verification_surface_catalog(array $args): array {
		$requested=$args['modules'] ?? [];
		$module_filter=[];
		if(is_array($requested)){
			foreach($requested as $module){
				$module=trim((string)$module);
				if($module!==''){
					$module_filter[]=$module;
				}
			}
		}
		$module_filter=array_values(array_unique($module_filter));
		$include_diagnostics=($args['include_diagnostics'] ?? true)!==false;
		$limit=max(1, min((int)($args['limit'] ?? 120) ?: 120, 400));
		$surfaces=[];
		$module_counts=[];
		$category_counts=[];
		$modules_root=$this->common_root.'/dataphyre/runtime/modules';
		foreach($this->all_files($modules_root, 50000) as $path){
			$relative=$this->relative_path($path);
			$module=$this->module_from_runtime_module_path($relative);
			if($module===null || ($module_filter!==[] && !in_array($module, $module_filter, true))){
				continue;
			}
			$entry=$this->verification_surface_entry($path, $relative, $module, $include_diagnostics);
			if($entry===null){
				continue;
			}
			$surfaces[]=$entry;
			$module_counts[$module]=($module_counts[$module] ?? 0)+1;
			$category=(string)$entry['category'];
			$category_counts[$category]=($category_counts[$category] ?? 0)+1;
			if(count($surfaces)>=$limit){
				break;
			}
		}
		if($module_filter===[]){
			foreach([
				'common/dataphyre/dev/tools/mcp_self_test.php',
				'common/dataphyre/dev/tools/mcp_live_validate.php',
				'common/dataphyre/dev/tools/mcp_config.php',
				'common/dataphyre/dev/tools/release_check',
			] as $tool_path){
				$absolute=$this->safe_repo_path($tool_path);
				if(!is_file($absolute)){
					continue;
				}
				$entry=$this->verification_tool_surface_entry($absolute, $tool_path);
				$surfaces[]=$entry;
				$module_counts['tools']=($module_counts['tools'] ?? 0)+1;
				$category=(string)$entry['category'];
				$category_counts[$category]=($category_counts[$category] ?? 0)+1;
				if(count($surfaces)>=$limit){
					break;
				}
			}
		}
		usort($surfaces, static function(array $a, array $b): int {
			return strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
		});
		$focused_surfaces=[];
		$publication_surfaces=[];
		foreach($surfaces as $surface){
			$module=(string)($surface['module'] ?? '');
			$category=(string)($surface['category'] ?? '');
			if($module==='tools' || in_array($category, ['mcp_self_test', 'mcp_live_validate', 'mcp_config', 'release_check'], true)){
				$publication_surfaces[]=$surface;
				continue;
			}
			$focused_surfaces[]=$surface;
		}
		ksort($module_counts);
		ksort($category_counts);
		return [
			'catalog_type'=>'dataphyre_verification_surface_catalog',
			'write_policy'=>'read_only',
			'execution'=>'not_executed',
			'modules'=>$module_filter,
			'include_diagnostics'=>$include_diagnostics,
			'limit'=>$limit,
			'surface_count'=>count($surfaces),
			'module_counts'=>$module_counts,
			'category_counts'=>$category_counts,
			'verification_safety'=>$this->verification_safety_contract('verification_surface_catalog'),
			'verification_handoff'=>$this->verification_handoff_contract($focused_surfaces),
			'verification_next_action'=>$this->verification_next_action($focused_surfaces),
			'focused_app_verification'=>[
				'audience'=>'application_agents_building_apps',
				'owner'=>'consuming_application',
				'surface_count'=>count($focused_surfaces),
				'surfaces'=>$focused_surfaces,
				'recommended_mcp_tools'=>[
					'dataphyre_unit_tests_list',
					'dataphyre_unit_test_manifest_read',
					'dataphyre_browser_regression_manifest_summary',
					'dataphyre_run_panel_regression',
					'dataphyre_run_panel_field_catalog_check',
					'dataphyre_php_lint',
				],
				'next_action'=>$this->verification_next_action($focused_surfaces),
				'handoff'=>$this->verification_handoff_contract($focused_surfaces),
				'not_release_proof'=>true,
			],
			'publication_validation'=>[
				'audience'=>'maintainers_and_release_surface_claims',
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'surface_count'=>count($publication_surfaces),
				'surfaces'=>$publication_surfaces,
				'tools'=>[
					'dataphyre_release_triage_summary',
					'dataphyre_release_check',
					'dataphyre_mcp_live_validate',
					'dataphyre_mcp_verify_all',
				],
				'next_action'=>[
					'status'=>'use_only_for_publication_or_mcp_claim',
					'tool'=>'dataphyre_release_triage_summary',
					'action'=>'Use publication validation only for Dataphyre release, MCP/release-surface, public framework, or maintainer-requested source-checkout evidence.',
					'not_required'=>['ordinary app behavior proof', 'focused app/module verification', 'Dataphyre hot-path benchmarks unless shared production hot paths changed'],
				],
			],
			'surfaces'=>$surfaces,
			'recommended_mcp_tools'=>[
				'dataphyre_unit_tests_list',
				'dataphyre_unit_test_manifest_read',
				'dataphyre_browser_regression_manifest_summary',
				'dataphyre_run_panel_regression',
				'dataphyre_run_panel_field_catalog_check',
				'dataphyre_php_lint',
			],
			'publication_validation_tools'=>[
				'dataphyre_release_triage_summary',
			],
			'publication_validation_boundary'=>'Use publication_validation_tools only for Dataphyre release or MCP/release-surface claims, not ordinary app behavior proof.',
			'safety_notes'=>[
				'This catalog reads filenames and lightweight manifest metadata only.',
				'JSON unit-test manifests can reference helper files or custom scripts; inspect them before execution.',
				'Diagnostic PHP files may bootstrap modules, inspect runtime state, or touch environment-dependent resources if run directly.',
				'Only use executable MCP wrappers where a route-free and bounded command boundary is already registered.',
			],
		];
	}

	/**
	 * Builds copy-safe focused verification handoff guidance for catalog-selected checks.
	 *
	 * @param array<int,array<string,mixed>> $surfaces Cataloged verification surfaces.
	 * @return array<string,mixed> Copy-safe verification handoff contract.
	 */
	private function verification_handoff_contract(array $surfaces): array {
		$tools=[];
		foreach($surfaces as $surface){
			if(!is_array($surface)){
				continue;
			}
			$tool=(string)($surface['known_mcp_wrapper'] ?? $surface['recommended_next_tool'] ?? '');
			if($tool!==''){
				$tools[]=$tool;
			}
		}
		return [
			'owner'=>'consuming_application',
			'status'=>'pending_until_focused_checks_run',
			'purpose'=>'Copy-safe completion evidence for focused app/module verification selected from this catalog.',
			'tools'=>array_values(array_unique($tools)),
			'copy_safe_fields'=>[
				'tool',
				'surface',
				'concrete_app_paths_or_arguments',
				'pass_fail_summary',
				'failing_check_names_when_any',
				'follow_up_app_owned_edits_when_any',
			],
			'not_included'=>[
				'raw full logs',
				'secrets, tokens, cookies, auth headers, or signed URLs',
				'tenant/customer/product identifiers unless already public test fixtures',
				'maintainer/source-checkout release proof',
				'Dataphyre hot-path benchmark output',
				'dataphyre_mcp_verify_all output',
			],
			'done_when'=>'Every selected focused check has a concrete app/module target and a pass/fail summary; unresolved failures name the app-owned follow-up edit.',
			'not_release_proof'=>true,
			'escalate_only_for'=>'MCP/release-surface claims, public Dataphyre framework claims, security/governance-sensitive claims, or Dataphyre shared production hot-path changes.',
		];
	}

	/**
	 * Selects the safest focused verification follow-up from cataloged surfaces.
	 *
	 * @param array<int,array<string,mixed>> $surfaces Cataloged verification surfaces.
	 * @return array<string,mixed> Machine-readable focused verification next action.
	 */
	private function verification_next_action(array $surfaces): array {
		$wrapped=null;
		$manifest=null;
		$diagnostic=null;
		foreach($surfaces as $surface){
			if(!is_array($surface)){
				continue;
			}
			$tool=(string)($surface['known_mcp_wrapper'] ?? '');
			if($wrapped===null && $tool!==''){
				$wrapped=$surface;
			}
			if($manifest===null && (string)($surface['category'] ?? '')==='json_unit_manifest'){
				$manifest=$surface;
			}
			if($diagnostic===null && (string)($surface['category'] ?? '')==='diagnostic_php'){
				$diagnostic=$surface;
			}
		}
		if(is_array($wrapped)){
			$status='run_bounded_mcp_wrapper';
			$next_tool=(string)($wrapped['known_mcp_wrapper'] ?? $wrapped['recommended_next_tool'] ?? 'dataphyre_verification_surface_catalog');
			$surface_path=(string)($wrapped['path'] ?? '');
			$action='Run the bounded MCP wrapper for the concrete app/module target, then copy verification_handoff pass/fail evidence.';
		}elseif(is_array($manifest)){
			$status='inspect_unit_manifest';
			$next_tool='dataphyre_unit_test_manifest_read';
			$surface_path=(string)($manifest['path'] ?? '');
			$action='Read the unit-test manifest before selecting any executable focused check.';
		}elseif(is_array($diagnostic)){
			$status='triage_diagnostic_surface';
			$next_tool='dataphyre_diagnostics_last_error';
			$surface_path=(string)($diagnostic['path'] ?? '');
			$action='Use redacted diagnostics before running environment-dependent diagnostic scripts directly.';
		}else{
			$status='no_focused_surface_selected';
			$next_tool='dataphyre_verification_surface_catalog';
			$surface_path='';
			$action='Narrow modules or inspect app-owned manifests before claiming verification coverage.';
		}
		return [
			'owner'=>'consuming_application',
			'status'=>$status,
			'next_tool'=>$next_tool,
			'surface'=>$surface_path,
			'action'=>$action,
			'handoff_fields'=>['verification_handoff', 'tool', 'surface', 'concrete_app_paths_or_arguments', 'pass_fail_summary', 'failing_check_names_when_any'],
			'not_required'=>[
				'MCP/release-surface validation for ordinary app behavior',
				'maintainer/source-checkout release proof',
				'Dataphyre hot-path benchmark evidence unless Dataphyre shared production hot paths are changed',
			],
			'ordinary_app_exclusion'=>'Do not use release triage, dataphyre_mcp_verify_all, maintainer release proof, or Dataphyre benchmark output as ordinary application behavior evidence.',
		];
	}

	/**
	 * Builds a catalog entry for one runtime verification-related file.
	 *
	 * unit manifests, diagnostic PHP files, and regression/check scripts are classified by filename
	 * and lightweight source inspection. Unknown or unrelated files return null rather than broadening the catalog.
	 *
	 * @param string $path Absolute filesystem path.
	 * @param string $relative Repo-relative path.
	 * @param string $module Runtime module name.
	 * @param bool $include_diagnostics Whether diagnostic PHP files may be cataloged.
	 * @return array<string, mixed>|null Verification surface entry or null for non-surfaces.
	 */
	private function verification_surface_entry(string $path, string $relative, string $module, bool $include_diagnostics): ?array {
		$normalized=str_replace('\\', '/', $relative);
		$basename=basename($normalized);
		if($this->is_unit_test_manifest($normalized)){
			$summary=$this->unit_test_manifest_summary($path, 0, false);
			return [
				'category'=>'json_unit_manifest',
				'module'=>$module,
				'path'=>$normalized,
				'execution'=>'not_executed',
				'case_count'=>$summary['case_count'] ?? 0,
				'helper_files'=>$summary['helper_files'] ?? [],
				'has_custom_script'=>$summary['has_custom_script'] ?? false,
				'recommended_next_tool'=>'dataphyre_unit_test_manifest_read',
				'modified_at'=>$this->file_modified_iso($path),
			];
		}
		if(str_ends_with($basename, '.diagnostic.php')){
			if(!$include_diagnostics){
				return null;
			}
			return [
				'category'=>'diagnostic_php',
				'module'=>$module,
				'path'=>$normalized,
				'execution'=>'not_executed',
				'route_free_candidate'=>true,
				'caution'=>'Cataloged only; direct execution may bootstrap diagnostics or environment-specific checks.',
				'modified_at'=>$this->file_modified_iso($path),
			];
		}
		if(!str_ends_with($basename, '.php')){
			return null;
		}
		$lower=strtolower($basename);
		$is_regression=str_contains($lower, 'regression');
		$is_check=str_contains($lower, 'check');
		if(!$is_regression && !$is_check){
			return null;
		}
		$text=$this->read_repo_text($relative, 60000);
		$category=$is_regression ? 'regression_php' : 'check_php';
		$scope_path=$this->verification_package_scope_path($normalized);
		$known_wrapper=match($scope_path){
			'runtime/modules/panel/kernel/panel_regression.php'=>'dataphyre_run_panel_regression',
			'runtime/modules/panel/kernel/panel_field_catalog_check.php'=>'dataphyre_run_panel_field_catalog_check',
			default=>null,
		};
		return [
			'category'=>$category,
			'module'=>$module,
			'path'=>$normalized,
			'execution'=>'not_executed',
			'route_free_candidate'=>str_contains($text, 'PHP_SAPI') || $known_wrapper!==null,
			'known_mcp_wrapper'=>$known_wrapper,
			'declared_classes'=>$this->simple_php_declared_class_names($text),
			'modified_at'=>$this->file_modified_iso($path),
		];
	}

	/**
	 * Builds a verification catalog entry for a shared Dataphyre tool script.
	 *
	 * classifies known MCP and release tooling, identifies whether a route-free command boundary is
	 * plausible, and links known MCP wrappers without executing the tool.
	 *
	 * @param string $path Absolute tool path.
	 * @param string $relative Repo-relative tool path.
	 * @return array<string, mixed> Tool verification surface entry.
	 */
	private function verification_tool_surface_entry(string $path, string $relative): array {
		$normalized=str_replace('\\', '/', $relative);
		$scope_path=$this->verification_package_scope_path($normalized);
		$extension=strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
		$text=$extension==='php' ? $this->read_repo_text($normalized, 40000) : '';
		$category=match($scope_path){
			'dev/tools/mcp_self_test.php'=>'mcp_self_test',
			'dev/tools/mcp_live_validate.php'=>'mcp_live_validate',
			'dev/tools/mcp_config.php'=>'mcp_config',
			'dev/tools/release_check'=>'release_check',
			default=>'tool_script',
		};
		return [
			'category'=>$category,
			'module'=>'tools',
			'path'=>$normalized,
			'execution'=>'not_executed',
			'route_free_candidate'=>$extension==='php' ? str_contains($text, 'PHP_SAPI') : true,
			'known_mcp_wrapper'=>$scope_path==='dev/tools/release_check' ? 'dataphyre_release_check' : null,
			'modified_at'=>$this->file_modified_iso($path),
		];
	}

	/**
	 * Normalizes Dataphyre package path variants for verification classification.
	 *
	 * responses keep repository-relative paths, but ownership, known wrapper, and
	 * tool classifications use package-relative scope so common/dataphyre/*,
	 * dataphyre/*, and already-package-relative paths behave the same.
	 */
	private function verification_package_scope_path(string $path): string {
		$normalized=ltrim(trim(str_replace('\\', '/', $path)), '/');
		while(str_contains($normalized, '//')){
			$normalized=str_replace('//', '/', $normalized);
		}
		while(str_starts_with($normalized, './')){
			$normalized=substr($normalized, 2);
		}
		foreach(['common/dataphyre/', 'dataphyre/'] as $prefix){
			if(str_starts_with($normalized, $prefix)){
				return substr($normalized, strlen($prefix));
			}
		}
		return $normalized;
	}

	/**
	 * Extracts the runtime module name from a repo-relative Dataphyre module path.
	 *
	 * path parsing is limited to common/dataphyre/runtime/modules/* ownership and returns null for
	 * shared tools, documentation, or unrelated files.
	 *
	 * @param string $relative Repo-relative path.
	 * @return string|null Runtime module name when the path belongs to a module.
	 */
	private function module_from_runtime_module_path(string $relative): ?string {
		$normalized=$this->verification_package_scope_path($relative);
		if(preg_match('#^runtime/modules/([^/]+)/#', $normalized, $match)!==1){
			return null;
		}
		return (string)$match[1];
	}

	/**
	 * Extracts a bounded list of declared PHP type names from source text.
	 *
	 * regex extraction supports verification catalogs with lightweight hints only; it does not parse
	 * namespaces, autoload classes, or validate declarations.
	 *
	 * @param string $text PHP source text.
	 * @return array<int, string> Unique declared type names, capped for catalog payload size.
	 */
	private function simple_php_declared_class_names(string $text): array {
		preg_match_all('/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $text, $matches);
		return array_values(array_unique(array_slice($matches[1] ?? [], 0, 20)));
	}

	/**
	 * Runs PHP lint through the registered bounded command helper for repo-local PHP files.
	 *
	 * every requested path must resolve inside the repository and end in .php. The command boundary is
	 * fixed to php -l and does not accept caller-provided shell text.
	 *
	 * @param array<int, string> $paths Repo-relative PHP files to lint.
	 * @return array{results: array<int, array{path: string, result: array}>} Lint results.
	 *
	 * @throws InvalidArgumentException When any path is not a repo-local PHP file.
	 */
	private function php_lint(array $paths): array {
		$results=[];
		foreach($paths as $path){
			$safe=$this->safe_repo_path((string)$path);
			if(!is_file($safe) || strtolower(pathinfo($safe, PATHINFO_EXTENSION))!=='php'){
				throw new InvalidArgumentException('php_lint paths must be repo-local PHP files.');
			}
			$results[]=['path'=>$this->relative_path($safe), 'result'=>$this->run_command([$this->php_binary(), '-l', $safe], 30000, true)];
		}
		return ['results'=>$results];
	}

}
