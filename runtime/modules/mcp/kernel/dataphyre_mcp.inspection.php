<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * Defines Mcp kernel trait responsibilities for dataphyre mcp inspection surfaces.
 *
 * Mcp kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait dataphyre_mcp_inspection_surfaces {

	use dataphyre_mcp_inspection_routing_surfaces;
	use dataphyre_mcp_inspection_mvc_surfaces;
	use dataphyre_mcp_inspection_data_surfaces;
	use dataphyre_mcp_inspection_verification_surfaces;

	/**
	 * Runs the shared Dataphyre release check script through a fixed command boundary.
	 *
	 * the script path is first-party and fixed, the Dataphyre root is passed explicitly, and no caller
	 * shell fragments are accepted. The command may inspect release hygiene but remains bounded by run_command.
	 *
	 * @return array<string, mixed> Release check command result.
	 *
	 * @throws RuntimeException When the release check script is missing.
	 */
	private function run_release_check(): array {
		$script=$this->common_root.'/dataphyre/dev/tools/release_check';
		if(!is_file($script)){
			throw new RuntimeException('Release check script not found at '.$script.'.');
		}
		$result=$this->run_command(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Root', $this->common_root.'/dataphyre'], 180000, true);
		$result['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('release_check');
		$result['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('release_check');
		$result['maintainer_tool_boundary']=[
			'tool_scope'=>'source_checkout_release_surface_validation',
			'app_agent_default'=>'not_required_for_ordinary_application_work',
			'claim_boundary'=>'Release-check evidence supports Dataphyre release-surface and public framework claims; application behavior still needs focused app or module verification.',
		];
		return $result;
	}

	/**
	 * Runs the MCP live stdio validator and wraps its result with validated surface metadata.
	 *
	 * executes the fixed first-party validator script with the configured PHP binary. The response
	 * documents which protocol and Dataphyre surfaces were exercised by the child process.
	 *
	 * @return array<string, mixed> Live MCP validation result.
	 *
	 * @throws RuntimeException When the validator script is missing.
	 */
	private function mcp_live_validate(): array {
		$script=$this->common_root.'/dataphyre/dev/tools/mcp_live_validate.php';
		if(!is_file($script)){
			throw new RuntimeException('MCP live validator not found at '.$script.'.');
		}
		$result=$this->run_command([$this->php_binary(), $script], 120000, true);
		return [
			'validation_type'=>'dataphyre_mcp_live_validate',
			'write_policy'=>'read_only',
			'execution'=>'stdio_server_spawned',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('mcp_live_validate'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('mcp_live_validate'),
			'evidence'=>'maintainer/source-checkout live validation evidence',
			'maintainer_tool_boundary'=>[
				'tool_scope'=>'source_checkout_mcp_stdio_validation',
				'app_agent_default'=>'use_for_local_client_setup_or_mcp_surface_changes_not_app_behavior_proof',
				'claim_boundary'=>'Live validation proves MCP framing and registered server surfaces, not application runtime behavior.',
			],
			'internal_step'=>'fixed first-party MCP live validation helper',
			'passed'=>($result['exit_code'] ?? 1)===0,
			'result'=>$result,
			'validated_surfaces'=>[
				'initialize',
				'tools/list',
				'prompts/list',
				'resources/list',
				'dataphyre_mcp_doctor',
				'dataphyre_mcp_prompt_catalog',
				'dataphyre://mcp-capabilities',
			],
		];
	}

	/**
	 * Runs the MCP verification suite: lint, live validation, self-test, doctor, and coupling guard.
	 *
	 * each executable step uses fixed first-party scripts or helpers, and the aggregate result exposes
	 * pass/fail state plus step evidence. The self-test is invoked as a child process to avoid recursive tool calls.
	 *
	 * @return array<string, mixed> Full MCP verification report.
	 */
	private function mcp_verify_all(): array {
		$steps=[];
		$lint_paths=array_values(array_merge(
			$this->mcp_kernel_surface_files(),
			$this->mcp_source_checkout_support_files()
		));
		$lint_results=[];
		foreach($lint_paths as $path){
			$lint_results[]=[
				'path'=>$path,
				'result'=>$this->run_command([$this->php_binary(), '-l', $this->safe_repo_path($path)], 30000, true),
			];
		}
		$steps[]=[
			'name'=>'php_lint',
			'passed'=>count(array_filter($lint_results, static fn(array $entry): bool => (($entry['result']['exit_code'] ?? 1)!==0)))===0,
			'results'=>$lint_results,
		];
		$live=$this->mcp_live_validate();
		$steps[]=[
			'name'=>'live_stdio_validation',
			'passed'=>($live['passed'] ?? false)===true,
			'result'=>$live,
		];
		$self_test_script=$this->common_root.'/dataphyre/dev/tools/mcp_self_test.php';
		$self_test=$this->run_command([$this->php_binary(), $self_test_script], 180000, true);
		$steps[]=[
			'name'=>'full_self_test',
			'passed'=>($self_test['exit_code'] ?? 1)===0,
			'evidence'=>'Dataphyre MCP publication evidence',
			'internal_step'=>'fixed first-party MCP self-test helper',
			'result'=>$self_test,
		];
		$doctor=$this->mcp_doctor();
		$steps[]=[
			'name'=>'mcp_doctor',
			'passed'=>($doctor['passed'] ?? false)===true,
			'result'=>$doctor,
		];
		$leaks=$this->mcp_app_coupling_leaks();
		$steps[]=[
			'name'=>'app_coupling_guard',
			'passed'=>$leaks===[],
			'leaks'=>$leaks,
		];
		$failed=array_values(array_filter($steps, static fn(array $step): bool => ($step['passed'] ?? false)!==true));
		return [
			'verification_type'=>'dataphyre_mcp_verify_all',
			'write_policy'=>'read_only',
			'execution'=>'bounded_verification_commands',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('mcp_verify_all'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('mcp_verify_all'),
			'maintainer_tool_boundary'=>[
				'tool_scope'=>'source_checkout_mcp_release_surface_verification',
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'claim_boundary'=>'Aggregate MCP verification supports MCP/release-surface claims only; application behavior still needs focused app or module verification.',
			],
			'passed'=>$failed===[],
			'step_count'=>count($steps),
			'failed_count'=>count($failed),
			'steps'=>$steps,
			'enterprise_verification_policy'=>[
				'execution_scope'=>'bounded_first_party_commands',
				'route_free'=>true,
				'allowed_commands'=>[
					'php -l on fixed MCP PHP files',
					'fixed first-party MCP live validation helper',
					'fixed first-party MCP self-test helper',
					'dataphyre_mcp_doctor',
					'MCP app-coupling guard scan',
				],
				'still_not_executed'=>[
					'SQL query execution',
					'route dispatch',
					'application controller invocation',
					'config secret reads',
					'scaffold writes',
				],
				'claim_boundary'=>'Passing aggregate verification supports MCP/release-surface claims only; runtime feature behavior still needs focused module tests or diagnostics.',
			],
			'notes'=>[
				'This tool intentionally runs the self-test script as a child process and is not itself invoked by the self-test to avoid recursive verification.',
				'The app-coupling guard scans MCP module files and shared MCP tools for product-specific strings.',
				'Use DATAPHYRE_MCP_PHP_BINARY to choose a portable PHP binary for command-backed checks.',
			],
		];
	}

	/**
	 * Converts release check output into categorized failure counts and examples.
	 *
	 * executes the standard release check, parses FAIL lines into stable categories, and returns a
	 * summary suitable for planning without mutating the workspace.
	 *
	 * @return array{exit_code: mixed, total_failures: int, categories: array<string, array>} Release triage summary.
	 */
	private function release_triage_summary(): array {
		$result=$this->run_release_check();
		$output=trim((string)($result['stdout'] ?? '')."\n".(string)($result['stderr'] ?? ''));
		$categories=$this->categorize_release_failures($output);
		$summary=[];
		foreach($categories as $key=>$items){
			$summary[$key]=[
				'count'=>count($items),
				'examples'=>array_slice($items, 0, 12),
			];
		}
		return [
			'exit_code'=>$result['exit_code'] ?? null,
			'total_failures'=>array_sum(array_map('count', $categories)),
			'categories'=>$summary,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('release_triage_summary'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('release_triage_summary'),
			'maintainer_tool_boundary'=>[
				'tool_scope'=>'source_checkout_release_failure_triage',
				'app_agent_default'=>'not_required_for_ordinary_application_work',
				'claim_boundary'=>'Release triage summarizes Dataphyre release hygiene failures and is not application behavior proof.',
			],
		];
	}

	/**
	 * Produces a prioritized repair plan from release check output.
	 *
	 * accepts caller-provided output or executes the release check, then groups failures by category
	 * with recommended actions and verification gates. It does not apply fixes.
	 *
	 * @param array{release_output?: string, max_examples_per_batch?: int} $args Planning options.
	 * @return array<string, mixed> Release repair plan.
	 */
	private function release_fix_plan(array $args): array {
		$max_examples=max(1, min((int)($args['max_examples_per_batch'] ?? 8) ?: 8, 30));
		$source='release_check';
		$exit_code=null;
		if(isset($args['release_output']) && trim((string)$args['release_output'])!==''){
			$output=(string)$args['release_output'];
			$source='provided_output';
		}else{
			$result=$this->run_release_check();
			$output=trim((string)($result['stdout'] ?? '')."\n".(string)($result['stderr'] ?? ''));
			$exit_code=$result['exit_code'] ?? null;
		}
		$categories=$this->categorize_release_failures($output);
		$batches=[];
		$order=[
			'module_index',
			'module_docs',
			'invalid_json',
			'missing_spdx_headers',
			'license_wording',
			'release_hygiene',
			'other',
		];
		foreach($order as $category){
			$items=$categories[$category] ?? [];
			if($items===[]){
				continue;
			}
			$batches[]=[
				'category'=>$category,
				'failure_count'=>count($items),
				'priority'=>$this->release_fix_priority($category),
				'action'=>$this->release_fix_action($category),
				'verification'=>$this->release_fix_verification($category),
				'examples'=>array_slice($items, 0, $max_examples),
			];
		}
		return [
			'write_policy'=>'read_only_plan',
			'execution'=>$source==='release_check' ? 'release_check_executed' : 'not_executed',
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('release_fix_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('release_fix_plan'),
			'source'=>$source,
			'exit_code'=>$exit_code,
			'total_failures'=>array_sum(array_map('count', $categories)),
			'batch_count'=>count($batches),
			'batches'=>$batches,
			'global_guardrails'=>[
				'Fix one category at a time and rerun the release check after each batch.',
				'Avoid broad formatting churn while repairing release hygiene.',
				'Do not change runtime behavior while fixing documentation, JSON, license wording, or headers.',
			],
		];
	}

	/**
	 * Categorizes FAIL lines emitted by the release check.
	 *
	 * preserves original failure messages under stable category keys so triage and fix planning can
	 * share the same classification rules.
	 *
	 * @param string $output Combined release check stdout and stderr.
	 * @return array{module_docs: array<int, string>, module_index: array<int, string>, invalid_json: array<int, string>, license_wording: array<int, string>, release_hygiene: array<int, string>, missing_spdx_headers: array<int, string>, other: array<int, string>} Categorized failures.
	 */
	private function categorize_release_failures(string $output): array {
		$categories=[
			'module_docs'=>[],
			'module_index'=>[],
			'invalid_json'=>[],
			'license_wording'=>[],
			'release_hygiene'=>[],
			'missing_spdx_headers'=>[],
			'other'=>[],
		];
		foreach(preg_split('/\R/', $output) ?: [] as $line){
			$line=trim($line);
			if(!str_starts_with($line, 'FAIL: ')){
				continue;
			}
			$message=substr($line, 6);
			$key='other';
			if(str_contains($message, 'has no markdown documentation')){
				$key='module_docs';
			}elseif(str_contains($message, 'MODULES.md is missing') || str_contains($message, 'MODULES.md lists')){
				$key='module_index';
			}elseif(str_contains($message, 'Invalid JSON')){
				$key='invalid_json';
			}elseif(str_contains($message, 'Stale proprietary/license wording')){
				$key='license_wording';
			}elseif(str_contains($message, 'Release hygiene issue')){
				$key='release_hygiene';
			}elseif(str_contains($message, 'missing MIT/SPDX header')){
				$key='missing_spdx_headers';
			}
			$categories[$key][]=$message;
		}
		return $categories;
	}

	/**
	 * Maps a release failure category to an ordered repair priority.
	 *
	 * priority text is intentionally stable for planning payloads and does not inspect workspace
	 * contents beyond the category already assigned by the release parser.
	 *
	 * @param string $category Release failure category.
	 * @return string Priority label.
	 */
	private function release_fix_priority(string $category): string {
		return match($category){
			'module_index'=>'P1: shared index consistency',
			'module_docs'=>'P1: missing public documentation',
			'invalid_json'=>'P1: machine-readable fixture validity',
			'missing_spdx_headers'=>'P2: release metadata compliance',
			'license_wording'=>'P2: license clarity',
			'release_hygiene'=>'P3: workspace hygiene',
			default=>'P3: inspect manually',
		};
	}

	/**
	 * Maps a release failure category to the expected repair action.
	 *
	 * action text guides a human or agent workflow but does not perform edits or relax release
	 * requirements.
	 *
	 * @param string $category Release failure category.
	 * @return string Recommended repair action.
	 */
	private function release_fix_action(string $category): string {
		return match($category){
			'module_index'=>'Update docs/MODULES.md to match existing runtime module directories and remove stale entries.',
			'module_docs'=>'Add concise markdown documentation for each listed module, covering purpose, public surface, safety notes, and verification.',
			'invalid_json'=>'Repair malformed JSON manifests without changing their semantic intent.',
			'missing_spdx_headers'=>'Add the standard Dataphyre MIT/SPDX header to first-party PHP/JS/CSS files that require it.',
			'license_wording'=>'Replace stale proprietary wording with current MIT/Dataphyre release language.',
			'release_hygiene'=>'Remove temporary artifacts or address explicit hygiene warnings without touching unrelated files.',
			default=>'Read the failure, identify the owning file, make the narrowest repair, and rerun the release check.',
		};
	}

	/**
	 * Lists verification gates appropriate for a release failure category.
	 *
	 * always includes the release check and adds category-specific focused checks where the current
	 * release policy expects them.
	 *
	 * @param string $category Release failure category.
	 * @return array<int, string> Verification steps.
	 */
	private function release_fix_verification(string $category): array {
		$common=['dataphyre_release_check'];
		return match($category){
			'invalid_json'=>array_merge(['JSON parse check for touched manifests'], $common),
			'missing_spdx_headers'=>array_merge(['focused header scan for touched files'], $common),
			'module_docs', 'module_index'=>array_merge(['review docs/MODULES.md and documentation links'], $common),
			default=>$common,
		};
	}

	/**
	 * Checks MCP registration, documentation links, required files, tool exposure, and app-coupling policy.
	 *
	 * combines filesystem presence checks, documentation index checks, tool registration inspection,
	 * and coupling leak scanning. It does not repair missing files or run the full self-test suite.
	 *
	 * @return array{passed: bool, checks: array<int, array>, failed_count: int} MCP doctor report.
	 */
	private function mcp_doctor(): array {
		$checks=[];
		$required_files=$this->mcp_kernel_surface_files()+[
			'docs'=>'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
			'guidelines'=>'common/dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
		];
		foreach($required_files as $name=>$path){
			$checks[]=[
				'name'=>'file:'.$name,
				'passed'=>is_file($this->root.'/'.$path),
				'detail'=>$path,
			];
		}
		$source_checkout_support=$this->mcp_source_checkout_support_files();
		foreach($source_checkout_support as $name=>$path){
			$checks[]=[
				'name'=>'source-checkout-support:'.$name,
				'passed'=>true,
				'detail'=>[
					'path'=>$path,
					'present'=>is_file($this->root.'/'.$path),
					'required_in_release'=>false,
				],
			];
		}
		$module_index=$this->read_repo_text('common/dataphyre/docs/MODULES.md', 120000);
		$runtime_readme=$this->read_repo_text('common/dataphyre/runtime/README.md', 120000);
		$docs_index=$this->read_repo_text('common/dataphyre/docs/README.md', 120000);
		$checks[]=[
			'name'=>'module-index-entry',
			'passed'=>str_contains($module_index, '| `mcp` |'),
			'detail'=>'common/dataphyre/docs/MODULES.md includes mcp',
		];
		$checks[]=[
			'name'=>'runtime-readme-entry',
			'passed'=>str_contains($runtime_readme, 'modules/mcp/documentation/Dataphyre_MCP.md'),
			'detail'=>'runtime README links MCP docs',
		];
		$checks[]=[
			'name'=>'documentation-index-entry',
			'passed'=>str_contains($docs_index, '../runtime/modules/mcp/documentation/Dataphyre_MCP.md'),
			'detail'=>'documentation index links MCP docs',
		];
		$tool_names=array_map(static fn(array $tool): string => (string)($tool['name'] ?? ''), $this->list_tools()['tools']);
		foreach(['dataphyre_application_catalog', 'dataphyre_package_metadata_read', 'dataphyre_api_docs_static_summary', 'dataphyre_api_scaffold_plan', 'dataphyre_api_recipe_catalog', 'dataphyre_api_cache_static_summary', 'dataphyre_openapi_static_contract_summary', 'dataphyre_openapi_runtime_readiness_plan', 'dataphyre_source_api_summary', 'dataphyre_module_dependency_map', 'dataphyre_runtime_version_summary', 'dataphyre_module_docs_pack', 'dataphyre_docs_chunks_export', 'dataphyre_docs_index_plan', 'dataphyre_embeddings_readiness_plan', 'dataphyre_remote_docs_readiness_plan', 'dataphyre_datadoc_static_summary', 'dataphyre_datadoc_runtime_readiness_plan', 'dataphyre_config_shape_read', 'dataphyre_config_value_preview', 'dataphyre_storage_config_summary', 'dataphyre_storage_driver_catalog', 'dataphyre_sql_schema_read', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract', 'dataphyre_route_source_static_summary', 'dataphyre_route_source_ambiguity_report', 'dataphyre_route_runtime_provenance_plan', 'dataphyre_controller_source_summary', 'dataphyre_middleware_source_summary', 'dataphyre_mvc_config_static_summary', 'dataphyre_mvc_route_cache_summary', 'dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error', 'dataphyre_browser_diagnostics_readiness_plan', 'dataphyre_flightdeck_surfaces_list', 'dataphyre_unit_tests_list', 'dataphyre_unit_test_manifest_read', 'dataphyre_browser_regression_manifest_summary', 'dataphyre_verification_surface_catalog', 'dataphyre_agent_context_generate', 'dataphyre_scaffold_plan_generate', 'dataphyre_app_builder_plan_generate', 'dataphyre_panel_scaffold_catalog', 'dataphyre_panel_package_manifest_summary', 'dataphyre_panel_theme_manifest_summary', 'dataphyre_panel_documentation_catalog_summary', 'dataphyre_panel_media_manifest_summary', 'dataphyre_task_pack_generate', 'dataphyre_apply_audit_plan', 'dataphyre_apply_runtime_readiness_plan', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_manifest_export', 'dataphyre_prompt_pack_export', 'dataphyre_mcp_prompt_catalog', 'dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_install_checklist', 'dataphyre_mcp_client_config_install_plan', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_troubleshoot', 'dataphyre_mcp_client_compatibility_matrix', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_safety_boundary_report', 'dataphyre_mcp_status_board', 'dataphyre_mcp_capability_matrix', 'dataphyre_mcp_release_notes_generate', 'dataphyre_mcp_surface_changelog', 'dataphyre_mcp_tool_call_examples_export', 'dataphyre_mcp_workflow_playbook_export', 'dataphyre_mcp_workflow_readiness_audit', 'dataphyre_mcp_workflow_session_export', 'dataphyre_mcp_workflow_transcript_schema_export', 'dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_summary_export', 'dataphyre_mcp_workflow_state_transition_export', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_timeline_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_transcript_audit', 'dataphyre_mcp_workflow_transcript_summary_export', 'dataphyre_mcp_workflow_checkpoint_export', 'dataphyre_mcp_workflow_handoff_pack_export', 'dataphyre_mcp_workflow_catalog', 'dataphyre_mcp_workflow_lifecycle_export', 'dataphyre_mcp_workflow_next_action_export', 'dataphyre_mcp_workflow_recommend', 'dataphyre_mcp_workflow_recommendation_handoff_export', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder', 'dataphyre_mcp_docs_coverage_report', 'dataphyre_mcp_readiness_report', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor'] as $tool){
			$checks[]=[
				'name'=>'tool:'.$tool,
				'passed'=>in_array($tool, $tool_names, true),
				'detail'=>'tool is registered',
			];
		}
		$leaks=$this->mcp_app_coupling_leaks();
		$checks[]=[
			'name'=>'app-coupling-guard',
			'passed'=>$leaks===[],
			'detail'=>$leaks===[] ? 'no app-specific strings found' : $leaks,
		];
		$failed=array_values(array_filter($checks, static fn(array $check): bool => $check['passed']!==true));
		return [
			'passed'=>$failed===[],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('mcp_doctor'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('mcp_doctor'),
			'maintainer_tool_boundary'=>[
				'tool_scope'=>'source_checkout_mcp_health_check',
				'app_agent_default'=>'use_after_mcp_surface_changes_not_app_behavior_proof',
				'claim_boundary'=>'Doctor checks MCP wiring, docs, tool registration, and app-coupling guardrails; application behavior still needs focused app or module verification.',
			],
			'checks'=>$checks,
			'failed_count'=>count($failed),
		];
	}

	/**
	 * Scans shared MCP code for product-specific coupling strings.
	 *
	 * the guard keeps shared MCP surfaces product-neutral by reporting files that mention known local
	 * application identifiers. String fragments are intentionally split in source so the guard does not flag itself.
	 *
	 * @return array<int, string> Repo-relative files containing coupling leaks.
	 */
	private function mcp_app_coupling_leaks(): array {
		$leaks=[];
		$app_pattern='/'.implode('|', [
			'sho'.'piro',
			'applications\\/sho'.'piro',
			'tools\\/sho'.'piro',
			'\\.local\\/sho'.'piro',
		]).'/i';
		foreach($this->all_files($this->root.'/common/dataphyre/runtime/modules/mcp', 200) as $path){
			$text=(string)file_get_contents($path);
			if(preg_match($app_pattern, $text)===1){
				$leaks[]=$this->relative_path($path);
			}
		}
		foreach(['common/dataphyre/dev/tools/mcp_self_test.php', 'common/dataphyre/dev/tools/mcp_config.php', 'common/dataphyre/dev/tools/mcp_live_validate.php'] as $path){
			$text=$this->read_repo_text($path, 120000);
			if(preg_match($app_pattern, $text)===1){
				$leaks[]=$path;
			}
		}
		return array_values(array_unique($leaks));
	}


}
