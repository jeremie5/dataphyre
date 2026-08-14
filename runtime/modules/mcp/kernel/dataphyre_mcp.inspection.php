<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Release\ApplicationReleasePreflightEvidence;

require_once dirname(__DIR__, 2).'/core/Release/ApplicationReleasePreflightEvidence.php';

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
	 * Executes the fixed application release preflight and returns its boolean verdict.
	 *
	 * Callers select only a repository-local project root, application id, and
	 * environment. The executable owns migration dry-run, application-resolved
	 * managed database identity verification, application boot, the loopback
	 * health probe, and deterministic realtime callback plus scheduler definition registration; arbitrary
	 * release commands are never accepted.
	 *
	 * @param array<string,mixed> $args
	 * @param null|callable(list<string>):array<string,mixed> $runner Test seam for the fixed command.
	 * @return array<string,mixed>
	 */
	private function run_release_check(array $args=[], ?callable $runner=null): array {
		$application=trim((string)($args['application'] ?? ''));
		$environment=trim((string)($args['environment'] ?? ''));
		$typedTarget=ApplicationReleasePreflightEvidence::applicationIdentifier($application)
			&& ApplicationReleasePreflightEvidence::environmentIdentifier($environment);
		$expectedApplication=$typedTarget ? $application : null;
		$expectedEnvironment=$typedTarget ? $environment : null;
		try{
			$projectRoot=array_key_exists('project_root', $args)
				? $this->safe_repo_path((string)$args['project_root'])
				: $this->root;
		}catch(Throwable){
			return $this->release_preflight_result($this->release_preflight_failure(
				$application,
				$environment,
				66,
				'configuration',
				'project_unavailable',
				'The selected application project root is unavailable.'
			));
		}

		$script=dirname(__DIR__, 2).'/core/kernel/application_release_preflight.php';
		if(!is_file($script) || !is_readable($script)){
			return $this->release_preflight_result($this->release_preflight_failure(
				$application,
				$environment,
				69,
				'dependency',
				'preflight_executable_unavailable',
				'The fixed Dataphyre application preflight executable is unavailable.'
			));
		}
		$command=[
			$this->php_binary(),
			$script,
			'--project-root='.$projectRoot,
			'--application='.$application,
			'--environment='.$environment,
		];
		try{
			$process=$runner!==null
				? $runner($command)
				: $this->run_release_preflight_command(
					$command,
					ApplicationReleasePreflightEvidence::COMMAND_TIMEOUT_MILLISECONDS
						+ApplicationReleasePreflightEvidence::MCP_TRANSPORT_OVERHEAD_MILLISECONDS
				);
		}catch(Throwable){
			return $this->release_preflight_result($this->release_preflight_failure(
				$application,
				$environment,
				69,
				'dependency',
				'preflight_runner_unavailable',
				'The fixed Dataphyre application preflight could not be executed.'
			));
		}
		$process=is_array($process) ? $process : [];
		$stdout=is_string($process['stdout'] ?? null) ? $process['stdout'] : '';
		$stdoutLimitExceeded=($process['stdout_limit_exceeded'] ?? null)===true
			|| strlen($stdout)>ApplicationReleasePreflightEvidence::MAX_OUTPUT_BYTES;
		$stdout=$stdoutLimitExceeded ? '' : trim($stdout);
		try{
			$preflight=json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
		}catch(Throwable){
			$preflight=null;
		}
		$processExit=$process['exit_code'] ?? null;
		$preflight=$stdoutLimitExceeded ? null : ApplicationReleasePreflightEvidence::validate(
			$preflight,
			$processExit,
			$expectedApplication,
			$expectedEnvironment
		);
		if($preflight===null){
			$preflight=$this->release_preflight_failure(
				$application,
				$environment,
				70,
				'verification',
				'preflight_result_invalid',
				'The fixed Dataphyre application preflight returned an invalid result.'
			);
		}
		return $this->release_preflight_result($preflight);
	}

	/** @param array<string,mixed> $preflight @return array<string,mixed> */
	private function release_preflight_result(array $preflight): array {
		$likely=$preflight['likely_to_deploy']===true;
		$failures=is_array($preflight['failures'] ?? null) ? array_values($preflight['failures']) : [];
		$first=is_array($failures[0] ?? null) ? $failures[0] : [];
		$result=[
			'check_type'=>'dataphyre_release_check',
			'contract_type'=>'dataphyre.application-release-prediction',
			'contract_version'=>2,
			'write_policy'=>(string)($preflight['write_policy'] ?? 'isolated_database_preflight_and_ephemeral_application_boot'),
			'execution'=>'local_preflight_executed',
			'passed'=>$likely,
			'prediction'=>[
				'available'=>true,
				'likely_to_deploy'=>$likely,
				'reason_code'=>$likely ? 'application_preflight_passed' : (string)($first['code'] ?? 'application_preflight_failed'),
			],
			'preflight'=>$preflight,
			'message'=>$likely
				? 'The fixed local application release preflight passed.'
				: (string)($first['message'] ?? 'The fixed local application release preflight failed.'),
		];
		$result['application_agent_operating_contract']=$this->mcp_application_agent_operating_contract('release_check');
		$result['ordinary_app_work']=$this->mcp_ordinary_app_work_contract('release_check');
		$result['maintainer_tool_boundary']=[
			'tool_scope'=>'application_release_preflight',
			'app_agent_default'=>'run_before_proposing_or_promoting_an_application_release',
			'claim_boundary'=>ApplicationReleasePreflightEvidence::claimBoundary(),
		];
		return $result;
	}

	/** @return array<string,mixed> */
	private function release_preflight_failure(
		string $application,
		string $environment,
		int $exitStatus,
		string $kind,
		string $code,
		string $message
	): array {
		return ApplicationReleasePreflightEvidence::failure(
			$application,
			$environment,
			$exitStatus,
			$kind,
			$code,
			$message
		);
	}

	/** @param list<string> $command @return array{exit_code:int|null,stdout:string,stderr:string,stdout_limit_exceeded:bool} */
	private function run_release_preflight_command(array $command, int $timeoutMilliseconds): array {
		if(!is_dir($this->root)){
			throw new RuntimeException('Unable to start application preflight.');
		}
		$descriptor=[
			1=>['pipe', 'w'],
			2=>['pipe', 'w'],
		];
		$process=@proc_open($command, $descriptor, $pipes, $this->root);
		if(!is_resource($process)){
			throw new RuntimeException('Unable to start application preflight.');
		}
		foreach($pipes as $pipe){
			if(is_resource($pipe)) stream_set_blocking($pipe, false);
		}
		$stdout='';
		$stdoutLimitExceeded=false;
		$discard=null;
		$discardLimit=false;
		$exitCode=null;
		$started=microtime(true);
		try{
			while(true){
				$this->drain_release_preflight_pipe($pipes[1] ?? null, $stdout, $stdoutLimitExceeded);
				$this->drain_release_preflight_pipe($pipes[2] ?? null, $discard, $discardLimit);
				if($stdoutLimitExceeded){
					$this->stop_release_preflight_process($process, $pipes);
					return [
						'exit_code'=>null,
						'stdout'=>'',
						'stderr'=>'',
						'stdout_limit_exceeded'=>true,
					];
				}
				$status=proc_get_status($process);
				if(!is_array($status) || ($status['running'] ?? false)!==true){
					$candidate=is_array($status) ? ($status['exitcode'] ?? null) : null;
					$exitCode=is_int($candidate) && $candidate!==-1 ? $candidate : null;
					break;
				}
				if((microtime(true)-$started)*1000>$timeoutMilliseconds){
					throw new RuntimeException('Application preflight timed out.');
				}
				usleep(10000);
			}
			$this->drain_release_preflight_pipe($pipes[1] ?? null, $stdout, $stdoutLimitExceeded);
			$this->drain_release_preflight_pipe($pipes[2] ?? null, $discard, $discardLimit);
			foreach($pipes as $pipe){
				if(is_resource($pipe)) fclose($pipe);
			}
			$closedExit=proc_close($process);
			if($stdoutLimitExceeded){
				return [
					'exit_code'=>null,
					'stdout'=>'',
					'stderr'=>'',
					'stdout_limit_exceeded'=>true,
				];
			}
			if($exitCode===null || $exitCode===-1){
				$exitCode=is_int($closedExit) ? $closedExit : 127;
			}
			return [
				'exit_code'=>$exitCode,
				'stdout'=>trim($stdout),
				'stderr'=>'',
				'stdout_limit_exceeded'=>false,
			];
		}catch(Throwable $error){
			$this->stop_release_preflight_process($process, $pipes);
			throw $error;
		}
	}

	/** @param resource|null $pipe */
	private function drain_release_preflight_pipe(mixed $pipe, ?string &$output, bool &$limitExceeded): void {
		if(!is_resource($pipe)) return;
		for($read=0;$read<128;$read++){
			$chunk=fread($pipe, 8192);
			if(!is_string($chunk) || $chunk==='') break;
			if($output===null || $limitExceeded) continue;
			$remaining=ApplicationReleasePreflightEvidence::MAX_OUTPUT_BYTES-strlen($output);
			if(strlen($chunk)>$remaining){
				$output='';
				$limitExceeded=true;
				continue;
			}
			$output.=$chunk;
		}
	}

	/** @param resource $process @param array<int,resource> $pipes */
	private function stop_release_preflight_process(mixed $process, array $pipes): void {
		if(is_resource($process)){
			$status=proc_get_status($process);
			if(is_array($status) && ($status['running'] ?? false)===true){
				@proc_terminate($process);
				$deadline=microtime(true)+0.5;
				do{
					usleep(10000);
					$status=proc_get_status($process);
				}while(is_array($status) && ($status['running'] ?? false)===true && microtime(true)<$deadline);
				if(is_array($status) && ($status['running'] ?? false)===true) @proc_terminate($process, 9);
			}
		}
		$discard=null;
		$limit=false;
		foreach($pipes as $pipe){
			$this->drain_release_preflight_pipe($pipe, $discard, $limit);
			if(is_resource($pipe)) fclose($pipe);
		}
		if(is_resource($process)) @proc_close($process);
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
		$script=$this->common_root.'/dataphyre/dev/tools/public/mcp_live_validate.php';
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
			'evidence'=>'Dataphyre maintainer live validation evidence',
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
				'dataphyre_contract_catalog',
				'dataphyre_contract_describe',
				'dataphyre://contracts',
				'dataphyre_panel_capability_catalog',
				'dataphyre_panel_capability_describe',
				'dataphyre://panel',
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
		$self_test_script=$this->common_root.'/dataphyre/dev/tools/public/mcp_self_test.php';
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
	 * Groups the executable application preflight failures by actionable kind.
	 *
	 * @param array<string,mixed> $args
	 * @param null|callable(list<string>):array<string,mixed> $runner Test seam for the fixed command.
	 * @return array<string,mixed>
	 */
	private function release_triage_summary(array $args=[], ?callable $runner=null): array {
		$result=$this->run_release_check($args, $runner);
		$preflight=is_array($result['preflight'] ?? null) ? $result['preflight'] : [];
		$failures=is_array($preflight['failures'] ?? null) ? array_values($preflight['failures']) : [];
		$summary=[];
		foreach(['configuration','dependency','verification'] as $kind){
			$items=array_values(array_filter($failures, static fn(mixed $failure): bool=>is_array($failure) && ($failure['kind'] ?? null)===$kind));
			$summary[$kind]=[
				'count'=>count($items),
				'failures'=>$items,
			];
		}
		return [
			'exit_code'=>$preflight['exit_status'] ?? null,
			'total_failures'=>count($failures),
			'categories'=>$summary,
			'release_check_execution'=>$result['execution'],
			'release_prediction'=>$result['prediction'],
			'checks'=>$preflight['checks'] ?? [],
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('release_triage_summary'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('release_triage_summary'),
			'maintainer_tool_boundary'=>[
				'tool_scope'=>'application_release_preflight_triage',
				'app_agent_default'=>'use_the_failure_kind_and_code_to_fix_the_application_before_release',
				'claim_boundary'=>'Release triage summarizes only failures returned by the fixed application preflight.',
			],
		];
	}

	/**
	 * Produces a prioritized repair plan from release check output.
	 *
	 * accepts caller-provided maintainer output and groups failures by category
	 * with recommended actions and verification gates. Without supplied output it
	 * returns an explicit no-op plan; it does not run a package or Cloud check.
	 *
	 * @param array{release_output?: string, max_examples_per_batch?: int} $args Planning options.
	 * @return array<string, mixed> Release repair plan.
	 */
	private function release_fix_plan(array $args): array {
		$max_examples=max(1, min((int)($args['max_examples_per_batch'] ?? 8) ?: 8, 30));
		$source='none';
		$exit_code=null;
		$execution='not_executed';
		if(isset($args['release_output']) && trim((string)$args['release_output'])!==''){
			$output=(string)$args['release_output'];
			$source='provided_output';
		}else{$output='';}
		$categories=$this->categorize_release_failures($output);
		$batches=[];
		$order=$this->release_fix_category_order();
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
			'execution'=>$execution,
			'application_agent_operating_contract'=>$this->mcp_application_agent_operating_contract('release_fix_plan'),
			'ordinary_app_work'=>$this->mcp_ordinary_app_work_contract('release_fix_plan'),
			'source'=>$source,
			'exit_code'=>$exit_code,
			'total_failures'=>array_sum(array_map('count', $categories)),
			'batch_count'=>count($batches),
			'batches'=>$batches,
			'global_guardrails'=>[
				'Fix one category at a time and rerun the external maintainer package check that produced the supplied output; MCP does not execute it.',
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
		$categories=array_fill_keys($this->release_failure_categories(),[]);
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

	/** @return list<string> Stable release-failure order shared by parsing and repair batches. */
	private function release_failure_categories(): array {
		return ['module_docs','module_index','invalid_json','license_wording','release_hygiene','missing_spdx_headers','other'];
	}

	/** @return list<string> Repair order keeps blocking consistency and validity failures first. */
	private function release_fix_category_order(): array {
		return ['module_index','module_docs','invalid_json','missing_spdx_headers','license_wording','release_hygiene','other'];
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
		return $this->release_fix_contract($category)['priority'];
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
		return $this->release_fix_contract($category)['action'];
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
		return $this->release_fix_contract($category)['verification'];
	}

	/** @return array{priority:string,action:string,verification:list<string>} */
	private function release_fix_contract(string $category): array {
		$release_check=['rerun the external maintainer package check that produced the supplied output'];
		$catalog=[
			'module_index'=>[
				'priority'=>'P1: shared index consistency',
				'action'=>'Update docs/MODULES.md to match existing runtime module directories and remove stale entries.',
				'verification'=>['review docs/MODULES.md and documentation links',...$release_check],
			],
			'module_docs'=>[
				'priority'=>'P1: missing public documentation',
				'action'=>'Add concise markdown documentation for each listed module, covering purpose, public surface, safety notes, and verification.',
				'verification'=>['review docs/MODULES.md and documentation links',...$release_check],
			],
			'invalid_json'=>[
				'priority'=>'P1: machine-readable fixture validity',
				'action'=>'Repair malformed JSON manifests without changing their semantic intent.',
				'verification'=>['JSON parse check for touched manifests',...$release_check],
			],
			'missing_spdx_headers'=>[
				'priority'=>'P2: release metadata compliance',
				'action'=>'Add the standard Dataphyre MIT/SPDX header to first-party PHP/JS/CSS files that require it.',
				'verification'=>['focused header scan for touched files',...$release_check],
			],
			'license_wording'=>[
				'priority'=>'P2: license clarity',
				'action'=>'Replace stale proprietary wording with current MIT/Dataphyre release language.',
				'verification'=>$release_check,
			],
			'release_hygiene'=>[
				'priority'=>'P3: workspace hygiene',
				'action'=>'Remove temporary artifacts or address explicit hygiene warnings without touching unrelated files.',
				'verification'=>$release_check,
			],
		];
		return $catalog[$category] ?? [
			'priority'=>'P3: inspect manually',
			'action'=>'Read the failure, identify the owning file, make the narrowest repair, and rerun the external maintainer package check that produced the supplied output.',
			'verification'=>$release_check,
		];
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
			'docs'=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_MCP.md',
			'guidelines'=>'dataphyre/runtime/modules/mcp/documentation/Dataphyre_AI_Guidelines.md',
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
				'name'=>'contributor-support:'.$name,
				'passed'=>true,
				'detail'=>[
					'path'=>$path,
					'present'=>is_file($this->root.'/'.$path),
					'required_in_release'=>false,
				],
			];
		}
		$module_index=$this->read_repo_text('dataphyre/docs/MODULES.md', 120000);
		$runtime_readme=$this->read_repo_text('dataphyre/runtime/README.md', 120000);
		$docs_index=$this->read_repo_text('dataphyre/docs/README.md', 120000);
		$checks[]=[
			'name'=>'module-index-entry',
			'passed'=>str_contains($module_index, '| `mcp` |'),
			'detail'=>'dataphyre/docs/MODULES.md includes mcp',
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
			foreach(['dataphyre_application_catalog', 'dataphyre_package_metadata_read', 'dataphyre_api_docs_static_summary', 'dataphyre_api_scaffold_plan', 'dataphyre_api_recipe_catalog', 'dataphyre_api_cache_static_summary', 'dataphyre_openapi_static_contract_summary', 'dataphyre_openapi_runtime_readiness_plan', 'dataphyre_source_api_summary', 'dataphyre_module_dependency_map', 'dataphyre_runtime_version_summary', 'dataphyre_module_docs_pack', 'dataphyre_docs_chunks_export', 'dataphyre_docs_index_plan', 'dataphyre_embeddings_readiness_plan', 'dataphyre_remote_docs_readiness_plan', 'dataphyre_datadoc_static_summary', 'dataphyre_datadoc_runtime_readiness_plan', 'dataphyre_config_shape_read', 'dataphyre_config_value_preview', 'dataphyre_storage_config_summary', 'dataphyre_storage_driver_catalog', 'dataphyre_sql_schema_read', 'dataphyre_sql_query_plan', 'dataphyre_sql_query_runner_contract', 'dataphyre_route_source_static_summary', 'dataphyre_route_source_ambiguity_report', 'dataphyre_route_runtime_provenance_plan', 'dataphyre_controller_source_summary', 'dataphyre_middleware_source_summary', 'dataphyre_mvc_config_static_summary', 'dataphyre_mvc_route_cache_summary', 'dataphyre_tracelog_artifacts_list', 'dataphyre_tracelog_read', 'dataphyre_tracelog_search', 'dataphyre_diagnostics_last_error', 'dataphyre_browser_diagnostics_readiness_plan', 'dataphyre_flightdeck_surfaces_list', 'dataphyre_contract_catalog', 'dataphyre_contract_describe', 'dataphyre_unit_tests_list', 'dataphyre_unit_test_manifest_read', 'dataphyre_browser_regression_manifest_summary', 'dataphyre_verification_surface_catalog', 'dataphyre_agent_context_generate', 'dataphyre_scaffold_plan_generate', 'dataphyre_app_builder_plan_generate', 'dataphyre_panel_capability_catalog', 'dataphyre_panel_capability_describe', 'dataphyre_panel_surface_graph', 'dataphyre_panel_recipe_plan', 'dataphyre_panel_integration_plan', 'dataphyre_panel_verification_plan', 'dataphyre_panel_scaffold_catalog', 'dataphyre_panel_package_manifest_summary', 'dataphyre_panel_theme_manifest_summary', 'dataphyre_panel_documentation_catalog_summary', 'dataphyre_panel_media_manifest_summary', 'dataphyre_task_pack_generate', 'dataphyre_apply_audit_plan', 'dataphyre_apply_runtime_readiness_plan', 'dataphyre_release_triage_summary', 'dataphyre_release_fix_plan', 'dataphyre_mcp_manifest_export', 'dataphyre_prompt_pack_export', 'dataphyre_mcp_prompt_catalog', 'dataphyre_mcp_skill_catalog', 'dataphyre_mcp_skill_manifest_export', 'dataphyre_mcp_skill_registration_audit', 'dataphyre_mcp_skill_pack_export', 'dataphyre_mcp_skill_install_plan', 'dataphyre_mcp_skill_file_install_plan', 'dataphyre_mcp_client_config_summary', 'dataphyre_mcp_client_install_checklist', 'dataphyre_mcp_client_config_install_plan', 'dataphyre_mcp_smoke_test_export', 'dataphyre_mcp_client_onboarding_pack', 'dataphyre_mcp_client_troubleshoot', 'dataphyre_mcp_client_compatibility_matrix', 'dataphyre_mcp_client_config_audit', 'dataphyre_mcp_safety_boundary_report', 'dataphyre_mcp_status_board', 'dataphyre_mcp_capability_matrix', 'dataphyre_mcp_release_notes_generate', 'dataphyre_mcp_surface_changelog', 'dataphyre_mcp_tool_call_examples_export', 'dataphyre_mcp_workflow_playbook_export', 'dataphyre_mcp_workflow_readiness_audit', 'dataphyre_mcp_workflow_session_export', 'dataphyre_mcp_workflow_transcript_schema_export', 'dataphyre_mcp_workflow_state_schema_export', 'dataphyre_mcp_workflow_state_audit', 'dataphyre_mcp_workflow_state_summary_export', 'dataphyre_mcp_workflow_state_transition_export', 'dataphyre_mcp_workflow_state_sync_pack_export', 'dataphyre_mcp_workflow_state_timeline_export', 'dataphyre_mcp_workflow_state_resume_brief_export', 'dataphyre_mcp_workflow_transcript_audit', 'dataphyre_mcp_workflow_transcript_summary_export', 'dataphyre_mcp_workflow_checkpoint_export', 'dataphyre_mcp_workflow_handoff_pack_export', 'dataphyre_mcp_workflow_catalog', 'dataphyre_mcp_workflow_lifecycle_export', 'dataphyre_mcp_workflow_next_action_export', 'dataphyre_mcp_workflow_recommend', 'dataphyre_mcp_workflow_recommendation_handoff_export', 'dataphyre_mcp_task_start_pack_export', 'dataphyre_mcp_agent_brief_export', 'dataphyre_mcp_tool_finder', 'dataphyre_mcp_resource_finder', 'dataphyre_mcp_docs_coverage_report', 'dataphyre_mcp_readiness_report', 'dataphyre_mcp_live_validate', 'dataphyre_mcp_verify_all', 'dataphyre_mcp_doctor'] as $tool){
				$checks[]=[
					'name'=>'tool:'.$tool,
					'passed'=>in_array($tool, $tool_names, true),
					'detail'=>'tool is registered',
				];
			}
			foreach([
				'dataphyre_sql_migration_catalog',
				'dataphyre_sql_migration_describe',
				'dataphyre_sql_migration_manifest_validate',
				'dataphyre_sql_migration_scaffold_plan',
			] as $tool){
				$checks[]=[
					'name'=>'tool:'.$tool,
					'passed'=>in_array($tool, $tool_names, true),
					'detail'=>'SQL migration tool is registered',
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
		foreach($this->all_files($this->root.'/dataphyre/runtime/modules/mcp', 200) as $path){
			$text=(string)file_get_contents($path);
			if($this->mcp_app_coupling_text_has_leak($text,$app_pattern)){
				$leaks[]=$this->relative_path($path);
			}
		}
		foreach(['dataphyre/dev/tools/public/mcp_self_test.php', 'dataphyre/dev/tools/public/mcp_config.php', 'dataphyre/dev/tools/public/mcp_live_validate.php'] as $path){
			$text=$this->read_repo_text($path, 120000);
			if($this->mcp_app_coupling_text_has_leak($text,$app_pattern)){
				$leaks[]=$path;
			}
		}
		return array_values(array_unique($leaks));
	}

	/** Ignores legal attribution lines while retaining coupling checks for shipped source and fixtures. */
	private function mcp_app_coupling_text_has_leak(string $text,string $pattern): bool {
		$body=preg_replace('/^\s*\*?\s*Copyright\s+\(c\).*$/mi','',$text) ?? $text;
		return preg_match($pattern,$body)===1;
	}


}
