<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 *
 * Runtime-inert first-party unit-test runner. Requiring this file only
 * declares the runner and CLI adapter; tests execute only through
 * dataphyre_unit_test_main().
 */

require_once __DIR__.'/CoverageLineNormalizer.php';
require_once __DIR__.'/CoverageLanes.php';
require_once __DIR__.'/PathSemantics.php';
require_once __DIR__.'/PhpdbgLineMap.php';
require_once __DIR__.'/TestKit/CaseDiscoveryCacheEntry.php';
require_once __DIR__.'/TestKit/Contracts/CaseDiscoveryCache.php';
require_once __DIR__.'/TestKit/CoverageAccumulator.php';
require_once __DIR__.'/TestKit/ShardedCaseDiscoveryCache.php';

/**
 * @param array<string,mixed> $runtime Injectable engine probes keep the coverage
 * transport testable without requiring every engine in one PHP process.
 * @return array<string,mixed>
 */
function dataphyre_unit_test_start_orchestrator_coverage(bool $enabled, array $runtime=[]): array {
	$state=['enabled'=>$enabled, 'included_before'=>get_included_files(), 'xdebug'=>false, 'xdebug_owned'=>false, 'phpdbg'=>false];
	if(!$enabled){return $state;}
	$xdebug_available=(bool)($runtime['xdebug_available'] ?? function_exists('xdebug_start_code_coverage'));
	if($xdebug_available){
		$xdebug_started_probe=$runtime['xdebug_started_probe'] ?? (function_exists('xdebug_code_coverage_started') ? 'xdebug_code_coverage_started' : null);
		$already_started=array_key_exists('xdebug_started', $runtime)
			? (bool)$runtime['xdebug_started']
			: (is_callable($xdebug_started_probe) && $xdebug_started_probe());
		$state['xdebug']=true;
		if(!$already_started){
			$flags=defined('XDEBUG_CC_UNUSED') ? XDEBUG_CC_UNUSED : 0;
			$flags|=defined('XDEBUG_CC_DEAD_CODE') ? XDEBUG_CC_DEAD_CODE : 0;
			$start=$runtime['xdebug_start'] ?? 'xdebug_start_code_coverage';
			@$start($flags);
			$state['xdebug_owned']=true;
		}
	}
	elseif((bool)($runtime['phpdbg_available'] ?? (function_exists('phpdbg_start_oplog') && function_exists('phpdbg_end_oplog') && function_exists('phpdbg_get_executable'))))
	{
		$start=$runtime['phpdbg_start'] ?? 'phpdbg_start_oplog';
		@$start();
		$state['phpdbg']=true;
	}
	return $state;
}

/** @param mixed $value */
function dataphyre_unit_test_coverage_requested(mixed $value): bool {
	if(is_bool($value)){return $value;}
	return !in_array(strtolower((string)$value), ['', '0', 'false', 'no', 'off'], true);
}

/**
 * @param array<int,string> $argv
 * @param array<string,mixed> $platform
 */
function dataphyre_unit_test_main(array $argv, string $root, array $platform=[]): int {
	if(!in_array((string)($platform['sapi'] ?? PHP_SAPI), ['cli', 'phpdbg'], true)){
		fwrite(STDERR, "Dataphyre unit-test tool must be run from the command line.\n");
		return 1;
	}
	$arguments=$argv;
	array_shift($arguments);
	$command=$arguments[0] ?? 'help';
	if(in_array($command, ['--help', '-h'], true)){
		$command='help';
		array_shift($arguments);
	}
	elseif(in_array($command, ['run', 'ci', 'list', 'help'], true)){
		array_shift($arguments);
	}
	else
	{
		$command='run';
	}
	if($command!=='help' && (in_array('--help', $arguments, true) || in_array('-h', $arguments, true))){
		$command='help';
		$arguments=[];
	}
	$options=dataphyre_unit_test_options($arguments);
	$coverage_requested=in_array($command, ['run', 'ci'], true)
		&& array_key_exists('coverage', $options)
		&& dataphyre_unit_test_coverage_requested($options['coverage']);
	$coverage_runtime=is_array($platform['orchestrator_coverage_runtime'] ?? null) ? $platform['orchestrator_coverage_runtime'] : [];
	$platform['orchestrator_coverage_state']=dataphyre_unit_test_start_orchestrator_coverage($coverage_requested, $coverage_runtime);
	try{
		$tool=new DataphyreUnitTestRunner($root, $options, $platform);
		return match($command){
			'run', 'ci'=>$tool->run(),
			'list'=>$tool->list(),
			default=>$tool->help(),
		};
	}catch(Throwable $exception){
		fwrite(STDERR, $exception->getMessage()."\n");
		return 1;
	}
}

final class DataphyreUnitTestRunner {
	private const FRAMEWORK_SOURCE_DISCOVERY_MARKER='@dataphyre-test-discovery-dependency framework-source';
	private const ROOTPATH_SANDBOX_FORMAT='dataphyre-test-rootpath-sandbox-v1';
	private const ROOTPATH_SANDBOX_MARKER='.dataphyre-test-rootpath-sandbox.json';
	/** @var list<string> */
	private const PROTECTED_ROOTPATH_KEYS=[
		'root',
		'common_root',
		'common_dataphyre',
		'common_dataphyre_runtime',
		'applications',
		'application_roots',
		'app',
		'app_override_key',
	];

	private string $framework_root;
	private string $display_name;
	private string $entrypoint;
	private bool $applications_enabled;
	private string $worker_path;
	private string $app_worker_path;
	private string $code_worker_path;
	private string $registry_path;
	private string $git_root;
	private string $git_prefix;
	private string $run_id;
	private string $temporary_run_root;
	/** @var (callable(string):string|false)|null */
	private mixed $rootpath_sandbox_resolver=null;
	/** @var (callable(string,string):int|false)|null */
	private mixed $rootpath_sandbox_marker_writer=null;
	/** @var array<string,mixed> */
	private array $process_runtime;
	private string $code_case_index_path;
	private \Dataphyre\Test\Contracts\CaseDiscoveryCache $case_discovery_cache;
	private string $timing_history_path;
	private string $isolation_index_path;
	/** @var array{enabled:bool,included_before:array<int,string>,xdebug:bool,xdebug_owned:bool,phpdbg:bool} */
	private array $orchestrator_coverage_state;
	/** @var array<string,mixed>|null */
	private ?array $orchestrator_coverage=null;
	private \Dataphyre\Test\CoverageAccumulator $coverage_accumulator;
	/** @var array<string,mixed>|null */
	private ?array $coverage_summary=null;
	/** @var ?array<string,float> */
	private ?array $timing_history=null;
	/** @var ?array<string,array{fingerprint:string,isolation:string,reason:string,manifest:string,updated_at:string}> */
	private ?array $isolation_index=null;
	private int $adaptive_speculative_files=0;
	private int $adaptive_fallbacks=0;
	private int $adaptive_quarantined_files=0;
	/** @var array<int,array<string,mixed>> */
	private array $adaptive_isolation_decisions=[];
	/** @var array<string, true> */
	private array $temporary_run_files=[];
	/** @var array<string, true> */
	private array $temporary_run_directories=[];
	/** @var array<string, array<int, array<string, mixed>>> */
	private array $code_case_cache=[];
	private ?string $code_case_runtime_fingerprint=null;
	private ?string $framework_discovery_source_fingerprint=null;
	/** @var array<string,bool> */
	private array $framework_source_discovery_dependencies=[];
	private int $code_case_cache_hits=0;
	private int $code_case_cache_misses=0;
	/** @var array<int,array{scope:string,owner:string,manifest:string,reasons:array<int,string>}> */
	private array $selection_report=[];
	/** @var ?array{exact:array<int,string>,modules:array<int,string>,apps:array<int,string>,paths:array<int,string>,all_framework:bool,all_code:bool} */
	private ?array $changed_test_selection=null;
	/** @var ?array{fingerprint:string,file_count:int,files:array<string,string>} */
	private ?array $source_epoch_before=null;
	/** @var ?array<string,mixed> */
	private ?array $source_epoch_result=null;
	/** @var list<string>|null */
	private ?array $explicit_coverage_source_roots=null;

	/** @param array<string, mixed> $options @param array<string, mixed> $platform */
	public function __construct(private string $root, private array $options=[], array $platform=[]) {
		$resolved_root=realpath($root);
		$this->root=str_replace('\\', '/', rtrim(is_string($resolved_root) ? $resolved_root : $root, '/\\'));
		$embedded_framework=$this->root.'/common/dataphyre';
		$default_framework=is_dir($embedded_framework.'/runtime/modules') ? $embedded_framework : $this->root;
		$this->framework_root=str_replace('\\', '/', rtrim((string)($platform['framework_root'] ?? $default_framework), '/\\'));
		$this->display_name=trim((string)($platform['display_name'] ?? 'Dataphyre')) ?: 'Dataphyre';
		$this->entrypoint=trim((string)($platform['entrypoint'] ?? 'php bin/dataphyre-test')) ?: 'php bin/dataphyre-test';
		$this->worker_path=(string)($platform['framework_worker'] ?? $this->framework_root.'/runtime/modules/dpanel/kernel/dpanel.worker.php');
		$this->app_worker_path=(string)($platform['app_worker'] ?? $this->root.'/tools/unit_test_worker.php');
		$this->code_worker_path=$this->framework_root.'/runtime/modules/testing/tooling/code_worker.php';
		$this->registry_path=(string)($platform['applications_registry'] ?? $this->root.'/applications/dataphyre.apps.json');
		$this->applications_enabled=is_file($this->registry_path);
		$environment_git_root=getenv('DATAPHYRE_TEST_GIT_ROOT');
		$configured_git_root=(string)($platform['git_root'] ?? (is_string($environment_git_root) && trim($environment_git_root)!=='' ? $environment_git_root : $this->root));
		$resolved_git_root=realpath($configured_git_root);
		$this->git_root=str_replace('\\', '/', rtrim(is_string($resolved_git_root) ? $resolved_git_root : $configured_git_root, '/\\'));
		$environment_git_prefix=getenv('DATAPHYRE_TEST_GIT_PREFIX');
		$this->git_prefix=$this->cleanRelativePath((string)($platform['git_prefix'] ?? (is_string($environment_git_prefix) ? $environment_git_prefix : '')));
		$this->code_case_index_path=(string)($platform['cache_path'] ?? $this->root.'/cache/unit-tests/code-case-index.json');
		$case_discovery_cache=$platform['case_discovery_cache'] ?? null;
		if($case_discovery_cache!==null && !$case_discovery_cache instanceof \Dataphyre\Test\Contracts\CaseDiscoveryCache){
			throw new InvalidArgumentException('case_discovery_cache must implement CaseDiscoveryCache.');
		}
		$this->case_discovery_cache=$case_discovery_cache ?? new \Dataphyre\Test\ShardedCaseDiscoveryCache($this->code_case_index_path);
		$this->coverage_accumulator=new \Dataphyre\Test\CoverageAccumulator();
		$this->timing_history_path=(string)($platform['timing_history_path'] ?? dirname($this->code_case_index_path).'/timing-history.json');
		$this->isolation_index_path=(string)($platform['isolation_index_path'] ?? dirname($this->code_case_index_path).'/isolation-index.json');
		$this->orchestrator_coverage_state=is_array($platform['orchestrator_coverage_state'] ?? null)
			? array_replace(['enabled'=>false, 'included_before'=>[], 'xdebug'=>false, 'xdebug_owned'=>false, 'phpdbg'=>false], $platform['orchestrator_coverage_state'])
			: ['enabled'=>false, 'included_before'=>[], 'xdebug'=>false, 'xdebug_owned'=>false, 'phpdbg'=>false];
		$this->run_id='dataphyre-unit-tests-'.bin2hex(random_bytes(4));
		$this->temporary_run_root=str_replace('\\', '/', rtrim((string)($platform['temporary_run_root'] ?? sys_get_temp_dir().'/'.$this->run_id), '/\\'));
		$this->rootpath_sandbox_resolver=is_callable($platform['rootpath_sandbox_resolver'] ?? null) ? $platform['rootpath_sandbox_resolver'] : null;
		$this->rootpath_sandbox_marker_writer=is_callable($platform['rootpath_sandbox_marker_writer'] ?? null) ? $platform['rootpath_sandbox_marker_writer'] : null;
		$this->process_runtime=is_array($platform['process_runtime'] ?? null) ? $platform['process_runtime'] : [];
	}

	public function __destruct() {
		$this->cleanupTemporaryRunRoot();
	}

	private function temporaryRunRoot(): string {
		$root=$this->temporary_run_root;
		if(!is_dir($root) && !mkdir($root, 0775) && !is_dir($root)){
			throw new RuntimeException('Unable to create unit-test temp directory.');
		}
		return $root;
	}

	private function temporaryRunFile(string $filename): string {
		if($filename==='' || basename($filename)!==$filename){
			throw new LogicException('Unit-test temp filenames must not contain a directory.');
		}
		$path=$this->temporaryRunRoot().'/'.$filename;
		$this->temporary_run_files[$path]=true;
		return $path;
	}

	private function temporaryRunDirectory(string $dirname): string {
		if($dirname==='' || basename($dirname)!==$dirname || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $dirname)!==1){
			throw new LogicException('Unit-test temp directory names must be safe single path segments.');
		}
		$path=$this->temporaryRunRoot().'/'.$dirname;
		if(file_exists($path) || is_link($path)){
			throw new RuntimeException('Unit-test temp directory already exists: '.$path);
		}
		if(!mkdir($path, 0775) && !is_dir($path)){
			throw new RuntimeException('Unable to create unit-test temp directory: '.$path);
		}
		$resolved=realpath($path);
		$path=str_replace('\\', '/', rtrim(is_string($resolved) ? $resolved : $path, '/\\'));
		$this->temporary_run_directories[$path]=true;
		return $path;
	}

	private function cleanupTemporaryRunRoot(): void {
		$root=$this->temporary_run_root;
		$normalized_root=str_replace('\\', '/', $root);
		$directories=array_keys($this->temporary_run_directories);
		usort($directories, static fn(string $left, string $right): int=>strlen($right) <=> strlen($left));
		foreach($directories as $directory){
			$this->cleanupTemporaryRunDirectory($directory);
		}
		foreach(array_keys($this->temporary_run_files) as $path){
			if(str_replace('\\', '/', dirname($path))===$normalized_root){
				@unlink($path);
			}
		}
		$this->temporary_run_files=[];
		@rmdir($root);
	}

	private function cleanupTemporaryPath(string $path): void {
		$path=str_replace('\\', '/', $path);
		if(isset($this->temporary_run_directories[$path])){
			$this->cleanupTemporaryRunDirectory($path);
			return;
		}
		@unlink($path);
		unset($this->temporary_run_files[$path]);
	}

	private function cleanupTemporaryRunDirectory(string $directory): void {
		$directory=str_replace('\\', '/', rtrim($directory, '/\\'));
		if(!isset($this->temporary_run_directories[$directory])){
			return;
		}
		$root=str_replace('\\', '/', rtrim($this->temporary_run_root, '/\\'));
		if(dirname($directory)!==$root){
			unset($this->temporary_run_directories[$directory]);
			return;
		}
		if(is_link($directory)){
			@unlink($directory);
		}elseif(is_dir($directory)){
			$this->removeTemporaryTree($directory);
		}
		unset($this->temporary_run_directories[$directory]);
	}

	private function removeTemporaryTree(string $directory): void {
		try{
			$iterator=new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach($iterator as $entry){
				$path=$entry->getPathname();
				$entry->isLink() || !$entry->isDir() ? @unlink($path) : @rmdir($path);
			}
		}catch(UnexpectedValueException){
			// A worker may remove its own disposable root before orchestration cleanup.
		}
		@rmdir($directory);
	}

	public function run(): int {
		$run_started=microtime(true);
		$discovery_started=microtime(true);
		$discovered=$this->discover();
		$this->writeSelectionReasons();
		$tests=$this->expandExecutionUnits($discovered);
		$discovery_seconds=microtime(true)-$discovery_started;
		$total=count($tests);
		if($total===0){
			$this->writeRunOutput([
				'workers_total'=>0,
				'workers_passed'=>0,
				'workers_failed'=>0,
				'cases_declared'=>0,
				'skipped'=>0,
				'todo'=>0,
				'assertions'=>0,
				'duration_seconds'=>microtime(true)-$run_started,
				'discovery_seconds'=>$discovery_seconds,
				'execution_seconds'=>0.0,
				'discovery_cache_hits'=>$this->code_case_cache_hits,
				'discovery_cache_misses'=>$this->code_case_cache_misses,
				'adaptive_isolation'=>$this->adaptiveIsolationSummary(),
				'dynamic_skipped'=>$this->countDynamicSkipped(),
				'selection'=>$this->optionEnabled('why-selected') ? $this->selection_report : [],
			], []);
			return 0;
		}
		$case_total=0;
		$failed=[];
		$passed=0;
		$skipped=0;
		$todo=0;
		$assertions=0;
		foreach($tests as $test){
			$case_total+=(int)$test['cases'];
		}
		$execution_started=microtime(true);
		$this->beginSourceEpoch();
		$results=$this->runMany($tests);
		$this->captureOrchestratorCoverage();
		$this->finishSourceEpoch();
		$this->saveTimingHistory($results);
		foreach($results as $result){
			$stats=$this->resultStats($result);
			$skipped+=$stats['skipped'];
			$todo+=$stats['todo'];
			$assertions+=$stats['assertions'];
			if($result['passed']===true){
				$passed++;
				continue;
			}
			$failed[]=$result;
			if(!$this->optionEnabled('json')){
				$this->printFailure($result);
			}
		}
		$execution_seconds=microtime(true)-$execution_started;
		$duration=microtime(true)-$run_started;
		$dynamic_skipped=$this->countDynamicSkipped();
		$policy_failures=[];
		if($this->optionEnabled('fail-skipped') && $skipped>0){
			$policy_failures[]='skipped';
		}
		if($this->optionEnabled('fail-todo') && $todo>0){
			$policy_failures[]='todo';
		}
		foreach($this->coveragePolicyFailures($results) as $failure){
			$policy_failures[]=$failure;
		}
		if(($this->sourceEpochMetadata()['stable'] ?? null)===false){
			$policy_failures[]='source-epoch-changed';
		}
		$summary=[
			'workers_total'=>$total,
			'workers_passed'=>$passed,
			'workers_failed'=>count($failed),
			'cases_declared'=>$case_total,
			'skipped'=>$skipped,
			'todo'=>$todo,
			'assertions'=>$assertions,
			'duration_seconds'=>$duration,
			'discovery_seconds'=>$discovery_seconds,
			'execution_seconds'=>$execution_seconds,
			'discovery_cache_hits'=>$this->code_case_cache_hits,
			'discovery_cache_misses'=>$this->code_case_cache_misses,
			'adaptive_isolation'=>$this->adaptiveIsolationSummary(),
			'dynamic_skipped'=>$dynamic_skipped,
			'policy_failures'=>$policy_failures,
			'source_epoch'=>$this->sourceEpochMetadata(),
			'selection'=>$this->optionEnabled('why-selected') ? $this->selection_report : [],
		];
		$this->writeCiArtifacts($summary, $results);
		$this->writeRunOutput($summary, $failed);
		return $failed!==[] || $policy_failures!==[] ? 1 : 0;
	}

	public function list(): int {
		$tests=$this->discover();
		if($this->optionEnabled('json')){
			$entries=[];
			foreach($tests as $test){
				$entry=[
					'scope'=>$test['scope'],
					'owner'=>$test['owner'],
					'kind'=>$test['kind'],
					'manifest'=>$this->relativePath((string)$test['manifest']),
					'cases'=>(int)$test['cases'],
				];
				if($this->optionEnabled('why-selected')){
					$entry['selection_reasons']=(array)($test['selection_reasons'] ?? ['selected by scope']);
				}
				if($test['kind']==='code'){
					$entry['code_cases']=$this->relativeCodeCases($this->codeCases($test));
				}
				$entries[]=$entry;
			}
			echo json_encode([
				'matched'=>count($tests),
				'tests'=>$entries,
			], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
			return 0;
		}
		foreach($tests as $test){
			$because=$this->optionEnabled('why-selected') ? ' because '.implode('; ', (array)($test['selection_reasons'] ?? ['selected by scope'])) : '';
			echo $test['scope'].' '.$test['owner'].' '.$test['kind'].' '.$this->relativePath((string)$test['manifest']).' cases='.(int)$test['cases'].$because."\n";
			if($test['kind']==='code' && $this->optionEnabled('cases')){
				foreach($this->codeCases($test) as $case){
					$suite=trim((string)($case['suite'] ?? ''));
					$suite_label=$suite!=='' ? ' suite='.json_encode($suite, JSON_UNESCAPED_SLASHES) : '';
					$tags=isset($case['tags']) && is_array($case['tags']) && $case['tags']!==[] ? ' tags='.implode(',', $case['tags']) : '';
					$groups=isset($case['groups']) && is_array($case['groups']) && $case['groups']!==[] ? ' groups='.implode(',', $case['groups']) : '';
					$depends=isset($case['dependencies']) && is_array($case['dependencies']) && $case['dependencies']!==[] ? ' depends='.implode(',', $case['dependencies']) : '';
					$flags=[];
					if(($case['skipped'] ?? false)===true){
						$flags[]='skip';
					}
					if(($case['todo'] ?? false)===true){
						$flags[]='todo';
					}
					if(($case['only'] ?? false)===true){
						$flags[]='only';
					}
					echo '  #'.(int)$case['index'].' '.(string)$case['name'].$suite_label.$tags.$groups.$depends.($flags!==[] ? ' ['.implode(',', $flags).']' : '')."\n";
				}
			}
		}
		echo "Matched ".count($tests)." unit-test manifest".(count($tests)===1 ? '' : 's').".\n";
		return 0;
	}

	public function help(): int {
		$title=$this->display_name.' unit-test tool';
		$entrypoint=$this->entrypoint;
		echo <<<TXT
{$title}

Usage:
	  {$entrypoint} run [--scope=all|framework|apps] [--app=name] [--owner=name] [--path=substring] [--changed[=base]] [--why-selected] [--kind=code|json] [--id=stable-id] [--name=text|/regex/] [--tag=tag] [--group=group] [--parallel=N] [--isolate=auto|case|file] [--timeout=N] [--memory=limit] [--coverage-memory-default=limit] [--no-test-cache] [--source-epoch] [--profile[=path]] [--junit=path] [--coverage=path] [--coverage-require=xdebug|phpdbg|included_files] [--coverage-source=path,...] [--coverage-min-percent=N] [--coverage-min-files=N] [--coverage-closed-world] [--json]
  {$entrypoint} ci [--parallel=N] [--timeout=N] [--memory=limit] [--coverage-memory-default=limit] [--junit=path] [--coverage=path] [--json]
  {$entrypoint} list [--scope=all|framework|apps] [--app=name] [--owner=name] [--path=substring] [--changed[=base]] [--why-selected] [--kind=code|json] [--cases] [--no-test-cache] [--json]

The default lane runs Dataphyre JSON manifests and code-defined PHP tests after
application repositories and dependencies have been installed. PHP tests are
plain `*.test.php` files in unit_tests folders using the Dataphyre\Test DSL.
Generated tests under unit_tests/dynamic are intentionally opt-in because they
are diagnostic artifacts, not the fast CI default.
Use --load-framework-modules only when you want diagnostics to load module
entrypoints before manifest execution.
Committed code tests marked with ->only() fail unless --allow-only is supplied.
Use --fail-skipped or --fail-todo when a stricter CI lane should reject them.
Each worker defaults to a 12-second timeout and 256M memory limit. Override
those per process with --timeout=N and --memory=limit for intentionally broad
coverage or performance contracts instead of changing the host PHP globally.
For exact runs, --coverage-memory-default=limit supplies a coverage-only worker
fallback; a suite's coverageMemoryLimit() is more specific, while --memory
remains the final operator override for every worker.
Use --parallel=N to bound code-test worker concurrency. JSON dpanel manifests
stay sequential unless --parallel-json and --parallel-json-allow=path-prefix are
supplied for a proven isolated diagnostic lane. Use --junit=path and
--coverage=path for CI artifacts without making developers read raw JSON.
Use --path=substring to select test files before PHP case discovery starts;
combine it with --name, --tag, or --group for a cheap focused run.
Code-case discovery is content-addressed across runs; use --no-test-cache only
when diagnosing environment-dependent test declarations.
Successful timing history automatically schedules historically slow independent
workers first. Use --profile[=path] for a per-case timing artifact and
--no-timing-history when measuring an unscheduled baseline.
Auto isolation speculatively batches safe unannotated files once. If shared
lifecycle state breaks that batch but every isolated case passes, the file's
content fingerprint is remembered and future runs go directly to case workers.
Use --changed for working-tree tests affected by modified modules, applications,
or test files. --changed=main also includes committed changes since main.
The same scope, owner, path, changed, and kind filters apply to `list`.
Code tests can declare ->group(), ->order(), and ->dependsOn(); dependency
chains run sequentially while unrelated cases continue to use the parallel pool.
	Coverage reports inventory runtime source files that no worker observed.
	Use --coverage-closed-world to fail on any missing source file; thresholds fail
	the run only when explicitly requested. Exact and closed-world coverage also
	fingerprint the coverage-source inventory before and after worker execution so
	a changing source tree cannot be certified. Use --source-epoch to apply the
	same consistency contract to an ordinary focused run.
Mutation diagnostics are available separately through `php bin/dataphyre-mutate`.

TXT;
		return 0;
	}

	/** @return array<int, array<string, mixed>> */
	private function discover(): array {
		$scope=(string)($this->options['scope'] ?? 'all');
		if(!in_array($scope, ['all', 'framework', 'apps'], true)){
			throw new RuntimeException('Invalid --scope value. Use all, framework, or apps.');
		}
		$tests=[];
		if($scope==='all' || $scope==='framework'){
			$tests=array_merge($tests, $this->discoverFramework());
		}
		if($scope==='apps' && !$this->applications_enabled){
			throw new RuntimeException('Application tests require a host applications registry; standalone Dataphyre provides framework tests only.');
		}
		if(($scope==='all' || $scope==='apps') && $this->applications_enabled){
			$tests=array_merge($tests, $this->discoverApps());
		}
		$tests=$this->filterTests($tests);
		$this->selection_report=[];
		foreach($tests as $test){
			$this->selection_report[]=[
				'scope'=>(string)$test['scope'],
				'owner'=>(string)$test['owner'],
				'manifest'=>$this->relativePath((string)$test['manifest']),
				'reasons'=>array_values(array_map('strval', (array)($test['selection_reasons'] ?? ['selected by scope']))),
			];
		}
		foreach($tests as $index=>$test){
			if(($test['kind'] ?? '')==='code'){
				$tests[$index]['cases']=max(1, count($this->codeCases($test)));
			}
		}
		usort($tests, static fn(array $a, array $b): int=>strcmp((string)$a['manifest'], (string)$b['manifest']));
		return $tests;
	}

	private function writeSelectionReasons(): void {
		if(!$this->optionEnabled('why-selected') || $this->optionEnabled('json')){
			return;
		}
		foreach($this->selection_report as $entry){
			echo 'SELECT '.$entry['scope'].' '.$entry['owner'].' '.$entry['manifest'].' because '.implode('; ', $entry['reasons']).".\n";
		}
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function filterTests(array $tests): array {
		$kind=(string)($this->options['kind'] ?? '');
		$owner=(string)($this->options['owner'] ?? '');
		$path=(string)($this->options['path'] ?? '');
		$changed=array_key_exists('changed', $this->options);
		$selected=[];
		foreach($tests as $test){
			$reasons=[];
			if($owner!=='' && (string)$test['owner']!==$owner){
				continue;
			}
			if($owner!==''){$reasons[]='owner='.$owner;}
			if($path!=='' && !str_contains($this->relativePath((string)$test['manifest']), str_replace('\\', '/', $path))){
				continue;
			}
			if($path!==''){$reasons[]='path contains '.str_replace('\\', '/', $path);}
			if($changed){
				$changed_reasons=$this->changedTestReasons($test);
				if($changed_reasons===[]){
					continue;
				}
				$reasons=array_merge($reasons, $changed_reasons);
			}
			if($kind!=='' && !($kind==='json' ? (string)$test['kind']!=='code' : (string)$test['kind']===$kind)){
				continue;
			}
			if($kind!==''){$reasons[]='kind='.$kind;}
			if($reasons===[]){$reasons[]='selected by scope';}
			$test['selection_reasons']=array_values(array_unique($reasons));
			$selected[]=$test;
		}
		return $selected;
	}

	/** @param array<string,mixed> $test */
	private function changedTestMatches(array $test): bool {
		return $this->changedTestReasons($test)!==[];
	}

	/** @param array<string,mixed> $test @return array<int,string> */
	private function changedTestReasons(array $test): array {
		$selection=$this->changedTestSelection();
		$kind=(string)($test['kind'] ?? '');
		$scope=(string)($test['scope'] ?? '');
		$owner=(string)($test['owner'] ?? '');
		$manifest=str_replace('\\', '/', (string)($test['manifest'] ?? ''));
		if($kind==='code'){
			foreach($this->codeCases($test) as $case){
				foreach((array)($case['watches'] ?? []) as $target){
					$target=trim((string)$target);
					if($target!=='' && $this->watchTargetMatches($target, $selection)){
						return ['watch target matched: '.$target];
					}
				}
			}
		}
		if($selection['all_code'] && $kind==='code'){
			return ['testing infrastructure changed'];
		}
		if($selection['all_framework'] && $scope==='framework'){
			return ['framework-wide source changed'];
		}
		foreach($selection['exact'] as $path){
			if($path!=='' && (str_ends_with($manifest, '/'.$path) || str_ends_with($manifest, $path))){
				return ['changed test file '.$path];
			}
		}
		if($scope==='app' && in_array($owner, $selection['apps'], true)){
			return ['application source changed: '.$owner];
		}
		foreach($selection['modules'] as $module){
			if(str_contains($manifest, '/runtime/modules/'.$module.'/') || ($scope==='framework' && $owner===$module)){
				return ['module source changed: '.$module];
			}
			if($kind!=='code'){
				continue;
			}
			$base=basename($manifest);
			if(preg_match('/^dataphyre\.'.preg_quote($module, '/').'(?:[._]|$)/i', $base)===1){
				return ['test naming contract references changed module: '.$module];
			}
			$source=is_file($manifest) ? (string)file_get_contents($manifest) : '';
			$namespace=str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $module)));
			if($source!=='' && (
				preg_match('/Dataphyre\\\\'.preg_quote($namespace, '/').'\\\\/i', $source)===1
				|| preg_match('/(?:framework|register_framework_modules)\s*\([^;]*[\'\"]'.preg_quote($module, '/').'[\'\"]/is', $source)===1
			)){
				return ['test source references changed module: '.$module];
			}
		}
		return [];
	}

	/** @param array{exact:array<int,string>,modules:array<int,string>,apps:array<int,string>,paths:array<int,string>,all_framework:bool,all_code:bool} $selection */
	private function watchTargetMatches(string $target, array $selection): bool {
		$target=str_replace('\\', '/', trim($target));
		if($target==='framework'){
			return $selection['all_framework'] || $selection['modules']!==[] || $this->watchChangedModules($selection)!==[];
		}
		if($target==='testing'){
			return $selection['all_code'];
		}
		if(str_starts_with($target, 'app:')){
			$pattern=substr($target, 4);
			foreach($selection['apps'] as $app){if($this->globMatches($pattern, $app)){return true;}}
			return false;
		}
		if(str_starts_with($target, 'module:')){
			if($selection['all_framework']){return true;}
			$pattern=substr($target, 7);
			foreach($this->watchChangedModules($selection) as $module){if($this->globMatches($pattern, $module)){return true;}}
			return false;
		}
		if(str_starts_with($target, 'path:')){
			$pattern=ltrim(substr($target, 5), '/');
			foreach($selection['paths'] as $path){if($this->globMatches($pattern, $path)){return true;}}
			return false;
		}
		if(!str_contains($target, '/') && !str_contains($target, '*') && !str_contains($target, '?')){
			return in_array($target, $this->watchChangedModules($selection), true);
		}
		foreach($selection['paths'] as $path){if($this->globMatches(ltrim($target, '/'), $path)){return true;}}
		return false;
	}

	/** @param array{paths:array<int,string>,modules:array<int,string>} $selection @return array<int,string> */
	private function watchChangedModules(array $selection): array {
		$modules=$selection['modules'];
		foreach($selection['paths'] as $path){
			$framework_path=str_starts_with($path, 'common/dataphyre/') ? substr($path, strlen('common/dataphyre/')) : $path;
			if(preg_match('#^runtime/modules/([^/]+)/#', $framework_path, $matches)===1){$modules[]=(string)$matches[1];}
		}
		return array_values(array_unique($modules));
	}

	private function globMatches(string $pattern, string $value): bool {
		$quoted=preg_quote($pattern, '#');
		$quoted=str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);
		return preg_match('#^'.$quoted.'$#i', $value)===1;
	}

	/** @return array{exact:array<int,string>,modules:array<int,string>,apps:array<int,string>,paths:array<int,string>,all_framework:bool,all_code:bool} */
	private function changedTestSelection(): array {
		if($this->changed_test_selection!==null){
			return $this->changed_test_selection;
		}
		$selection=[
			'exact'=>[],
			'modules'=>[],
			'apps'=>[],
			'paths'=>[],
			'all_framework'=>false,
			'all_code'=>false,
		];
		foreach($this->gitChangedPaths() as $original_path){
			$path=ltrim(str_replace('\\', '/', trim($original_path)), '/');
			if($path==='' || str_starts_with($path, '.codex-tmp/') || str_starts_with($path, 'cache/')){
				continue;
			}
			$selection['paths'][]=$path;
			if(str_starts_with($path, 'common/dataphyre/')){$selection['paths'][]=substr($path, strlen('common/dataphyre/'));}
			$framework_path=str_starts_with($path, 'common/dataphyre/') ? substr($path, strlen('common/dataphyre/')) : $path;
			$inside_unit_tests=str_contains($framework_path, '/unit_tests/');
			if($inside_unit_tests && $this->isChangedTestManifestPath($framework_path)){
				$selection['exact'][]=$path;
				$selection['exact'][]=$framework_path;
				continue;
			}
			if(str_starts_with($framework_path, 'runtime/modules/testing/tooling/TestKit/') || in_array($framework_path, [
				'runtime/modules/testing/tooling/bootstrap.php',
				'runtime/modules/testing/tooling/code_worker.php',
				'runtime/modules/testing/tooling/Runner.php',
				'bin/dataphyre-test',
			], true) || $path==='tools/unit_tests.php'){
				$selection['all_code']=true;
				continue;
			}
			if(preg_match('#^runtime/modules/([^/]+)/#', $framework_path, $matches)===1){
				$module=(string)$matches[1];
				if($module==='core'){
					$selection['all_framework']=true;
				}
				else
				{
					$selection['modules'][]=$module;
				}
				continue;
			}
			if(preg_match('#^applications/([^/]+)/#', $path, $matches)===1){
				$selection['apps'][]=(string)$matches[1];
				continue;
			}
			if(str_ends_with(strtolower($framework_path), '.php') || in_array($framework_path, ['dataphyre.project.json','composer.json','composer.lock'], true)){
				$selection['all_framework']=true;
			}
		}
		$selection['exact']=array_values(array_unique($selection['exact']));
		$selection['modules']=array_values(array_unique($selection['modules']));
		$selection['apps']=array_values(array_unique($selection['apps']));
		$selection['paths']=array_values(array_unique($selection['paths']));
		return $this->changed_test_selection=$selection;
	}

	private function isChangedTestManifestPath(string $path): bool {
		$path=str_replace('\\', '/', $path);
		if(str_contains('/'.ltrim($path, '/'), '/unit_tests/fixtures/')){
			return false;
		}
		if(str_ends_with($path, '.test.php')){
			return true;
		}
		if(!str_ends_with($path, '.json')){
			return false;
		}
		$base=basename($path);
		return !str_ends_with($base, '.meta.json') && !str_starts_with($base, 'dpanel_mock_');
	}

	/** @return array<int,string> */
	private function gitChangedPaths(): array {
		$paths=[];
		$prefix=$this->git_prefix!=='' ? $this->git_prefix.'/' : '';
		$untracked_pathspecs=$this->git_prefix!==''
			? [$prefix.'testing', $prefix.'runtime']
			: ['testing', 'runtime', 'applications', 'common/dataphyre/testing', 'common/dataphyre/runtime'];
		$commands=[
			['diff', '--name-only'],
			['diff', '--cached', '--name-only'],
			array_merge(['ls-files', '--others', '--exclude-standard', '--'], $untracked_pathspecs),
		];
		foreach($commands as $arguments){
			$paths=array_merge($paths, $this->runGitPathCommand($this->git_root, $arguments));
		}
		$base=$this->options['changed'] ?? true;
		if(is_string($base) && !in_array(strtolower($base), ['', '1', 'true', 'yes'], true)){
			$paths=array_merge($paths, $this->runGitPathCommand($this->git_root, ['diff', '--name-only', $base.'...HEAD']));
		}
		return $this->normalizeGitChangedPaths($paths);
	}

	/** @param array<int,string> $paths @return array<int,string> */
	private function normalizeGitChangedPaths(array $paths): array {
		$normalized=[];
		foreach(array_unique($paths) as $path){
			$path=ltrim(str_replace('\\', '/', trim((string)$path)), '/');
			if($path===''){
				continue;
			}
			if($this->git_prefix!==''){
				$framework_prefix=$this->git_prefix.'/';
				if(!str_starts_with($path, $framework_prefix)){
					continue;
				}
				$path=substr($path, strlen($framework_prefix));
			}
			if($path!==''){
				$normalized[$path]=true;
			}
		}
		return array_keys($normalized);
	}

	/** @param array<int,string> $arguments @return array<int,string> */
	private function runGitPathCommand(string $root, array $arguments): array {
		$null=PHP_OS_FAMILY==='Windows' ? 'NUL' : '/dev/null';
		$command=array_merge([
			'git', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath='.$null, '-c', 'core.quotepath=false', '-C', $root,
		], $arguments);
		$process=$this->runProcess($command, 15);
		if($process['timed_out'] || $process['exit_code']!==0){
			throw new RuntimeException('Unable to read changed files from Git.');
		}
		$output=preg_split('/\R/', (string)$process['stdout']) ?: [];
		return array_values(array_filter(array_map('trim', $output), static fn(string $path): bool=>$path!==''));
	}

	/** @return array<int, array<string, mixed>> */
	private function discoverFramework(): array {
		$tests=[];
		$modules_root=$this->framework_root.'/runtime/modules';
		if($this->wantsJsonTests()){
			$json_files=[];
			foreach($this->frameworkDiscoveryRoots($modules_root, 'json') as $discovery_root){
				foreach($this->jsonFiles($discovery_root.'/') as $file){$json_files[$file]=true;}
			}
			foreach(array_keys($json_files) as $file){
				$normalized=str_replace('\\', '/', $file);
				if(!str_contains($normalized, '/unit_tests/')){
					continue;
				}
				if($this->includeDynamic()!==true && str_contains($normalized, '/unit_tests/dynamic/')){
					continue;
				}
				if($this->isMetaOrFixture($file)){
					continue;
				}
				$kind=$this->manifestKind($file);
				if($kind==='invalid'){
					$tests[]=$this->testRecord('framework', $this->moduleName($file), $file, 'invalid', 0);
					continue;
				}
				$tests[]=$this->testRecord('framework', $this->moduleName($file), $file, $kind, $this->caseCount($file, $kind));
			}
		}
		if($this->wantsCodeTests()){
			$code_roots=$this->frameworkDiscoveryRoots($modules_root, 'code');
			$code_files=[];
			foreach($code_roots as $code_root){
				foreach($this->phpTestFiles($code_root) as $file){
					$code_files[$file]=true;
				}
			}
			if(array_key_exists('changed', $this->options)){
				foreach($this->phpTestFiles($modules_root) as $file){
					if($this->isGlobalWatchTestFile($file)){$code_files[$file]=true;}
				}
			}
			foreach(array_keys($code_files) as $file){
				$normalized=str_replace('\\', '/', $file);
				if($this->includeDynamic()!==true && str_contains($normalized, '/unit_tests/dynamic/')){
					continue;
				}
				$tests[]=$this->testRecord('framework', $this->frameworkOwner($file), $file, 'code', 0);
			}
		}
		return $tests;
	}

	private function isGlobalWatchTestFile(string $file): bool {
		$source=is_file($file) ? file_get_contents($file) : false;
		return is_string($source) && (preg_match('/->watches\s*\(/', $source)===1 || str_contains($source, '@dataphyre-changed-run-sentinel'));
	}

	/** @return array<int,string> */
	private function frameworkDiscoveryRoots(string $modules_root, string $kind): array {
		$owner=trim((string)($this->options['owner'] ?? ''));
		if($owner!==''){
			$owner_root=$modules_root.'/'.$this->cleanRelativePath($owner);
			return is_dir($owner_root) ? [$owner_root] : [];
		}
		$path=trim(str_replace('\\', '/', (string)($this->options['path'] ?? '')), '/');
		if($path!==''){
			foreach([$this->root.'/'.$path, $this->framework_root.'/'.$path] as $candidate){
				if(is_file($candidate)){return [dirname($candidate)];}
				if(is_dir($candidate)){return [$candidate];}
			}
			if(preg_match('#(?:^|/)runtime/modules/([^/]+)(?:/|$)#', $path, $matches)===1){
				$candidate=$modules_root.'/'.(string)$matches[1];
				return is_dir($candidate) ? [$candidate] : [];
			}
		}
		if(array_key_exists('changed', $this->options)){
			$selection=$this->changedTestSelection();
			if($selection['all_framework'] || ($kind==='code' && $selection['all_code'])){
				return [$modules_root];
			}
			$roots=[];
			foreach($selection['modules'] as $module){
				$candidate=$modules_root.'/'.$this->cleanRelativePath($module);
				if(is_dir($candidate)){$roots[$candidate]=true;}
			}
			foreach($selection['exact'] as $exact){
				$exact=ltrim(str_replace('\\', '/', $exact), '/');
				foreach([$this->root.'/'.$exact, $this->framework_root.'/'.$exact] as $candidate){
					if(is_file($candidate)){$roots[dirname($candidate)]=true;}
				}
			}
			return array_keys($roots);
		}
		return [$modules_root];
	}

	/** @return array<int, array<string, mixed>> */
	private function discoverApps(): array {
		$tests=[];
		$app_filter=(string)($this->options['app'] ?? $this->options['owner'] ?? '');
		foreach($this->applications() as $app){
			$name=(string)$app['name'];
			if($app_filter!=='' && $app_filter!==$name){
				continue;
			}
			$app_root=$this->root.'/'.$this->cleanRelativePath((string)$app['path']);
			if(!is_dir($app_root)){
				throw new RuntimeException("Application '{$name}' is not installed at {$app['path']}.");
			}
			if($this->wantsJsonTests()){
				foreach($this->jsonFiles($app_root.'/') as $file){
					$normalized=str_replace('\\', '/', $file);
					if(!str_contains($normalized, '/unit_tests/')){
						continue;
					}
					if($this->includeDynamic()!==true && str_contains($normalized, '/unit_tests/dynamic/')){
						continue;
					}
					if($this->isMetaOrFixture($file)){
						continue;
					}
					$kind=$this->manifestKind($file);
					if($kind==='invalid'){
						$tests[]=$this->testRecord('app', $name, $file, 'invalid', 0, $app_root);
						continue;
					}
					$tests[]=$this->testRecord('app', $name, $file, $kind, $this->caseCount($file, $kind), $app_root);
				}
			}
			if($this->wantsCodeTests()){
				foreach($this->phpTestFiles($this->dataphyreRootForApp($app_root).'unit_tests/') as $file){
					$normalized=str_replace('\\', '/', $file);
					if($this->includeDynamic()!==true && str_contains($normalized, '/unit_tests/dynamic/')){
						continue;
					}
					$tests[]=$this->testRecord('app', $name, $file, 'code', 0, $app_root);
				}
			}
		}
		return $tests;
	}

	/** @return array<string, mixed> */
	private function runOne(array $test): array {
		$job=$this->workerJob($test, 0);
		if(isset($job['result']) && is_array($job['result'])){
			return $this->retainCoverage($job['result']);
		}
		return $this->retainCoverage($this->runWorkerJob($job));
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function runMany(array $tests): array {
		$tests=$this->sortExecutionUnits($tests);
		if($this->hasCodeDependencies($tests)){
			[$independent, $dependent]=$this->partitionDependencyTests($tests);
			return array_merge(
				$this->runManyIndependent($independent),
				$this->runManyWithDependencies($dependent)
			);
		}
		return $this->runManyIndependent($tests);
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function runManyIndependent(array $tests): array {
		$parallel=max(1, min(32, (int)($this->options['parallel'] ?? 1)));
		if($parallel===1 || count($tests)<2){
			$results=[];
			foreach($tests as $test){
				$results[]=$this->runOne($test);
			}
			return $results;
		}
		$results=[];
		$parallel_tests=[];
		foreach($tests as $test){
			if($this->canRunParallel($test)){
				$parallel_tests[]=$test;
				continue;
			}
			$results[]=$this->runOne($test);
		}
		if($parallel_tests===[]){
			return $results;
		}
		$jobs=[];
		$parallel_results=[];
		foreach(array_values($parallel_tests) as $sequence=>$test){
			$jobs[]=$this->workerJob($test, $sequence);
		}
		$active=[];
		while($jobs!==[] || $active!==[]){
			while($jobs!==[] && count($active)<$parallel){
				$job=array_shift($jobs);
					$started=$this->startWorkerProcess($job);
					if(isset($started['result']) && is_array($started['result'])){
						$parallel_results[(int)$job['sequence']]=$this->retainCoverage($started['result']);
						continue;
				}
				$active[(int)$job['sequence']]=$started;
			}
			foreach($active as $sequence=>$process){
				$status=$this->processStatus($process['resource']);
				$running=($status['running'] ?? false)===true;
				$timed_out=false;
				if($running && $this->processNow()-(int)$process['started']>(int)$process['job']['timeout_seconds']){
					$timed_out=true;
					$this->terminateProcess($process['resource']);
					$running=false;
				}
				if($running){
					$active[$sequence]=$process;
					continue;
				}
				$process['timed_out']=$timed_out;
				$process['observed_exit_code']=(int)($status['exitcode'] ?? -1);
					$parallel_results[$sequence]=$this->retainCoverage($this->finishWorkerProcess($process));
					unset($active[$sequence]);
			}
			if($active!==[]){
				usleep(50000);
			}
		}
		ksort($parallel_results);
		return array_merge($results, array_values($parallel_results));
	}

	/** Unions bulky worker maps immediately and retains only reportable test evidence. */
	private function retainCoverage(array $result): array {
		if(!$this->coverageEnabled() || !is_array($result['result'] ?? null)){
			return $result;
		}
		$this->coverage_summary=null;
		if(is_array($result['result']['coverage'] ?? null)){
			$this->coverage_accumulator->add($result['result']['coverage']);
		}
		foreach(is_array($result['result']['coverage_parts'] ?? null) ? $result['result']['coverage_parts'] : [] as $coverage){
			if(is_array($coverage)){$this->coverage_accumulator->add($coverage);}
		}
		unset($result['result']['coverage'],$result['result']['coverage_parts']);
		return $result;
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function runManyWithDependencies(array $tests): array {
		$results=[];
		$status=[];
		$remaining=[];
		foreach($tests as $test){
			foreach($this->caseStatusKeys($test) as $key){
				$remaining[$key]=($remaining[$key] ?? 0)+1;
			}
		}
		$pending=array_values($tests);
		while($pending!==[]){
			$ready_index=null;
			foreach($pending as $index=>$candidate){
				$waiting=false;
				foreach((array)($candidate['case_dependencies'] ?? []) as $dependency){
					if(($remaining[(string)$dependency] ?? 0)>0){
						$waiting=true;
						break;
					}
				}
				if(!$waiting){
					$ready_index=$index;
					break;
				}
			}
			if($ready_index===null){
				throw new RuntimeException('Code-defined test dependency scheduler could not resolve a ready case.');
			}
			$test=$pending[$ready_index];
			array_splice($pending, $ready_index, 1);
			if(($test['kind'] ?? '')==='code' && !$this->dependenciesPassed($test, $status)){
				$result=$this->dependencySkipResult($test);
			}
			else
			{
				$result=$this->runOne($test);
			}
			$results[]=$result;
			if(($test['kind'] ?? '')==='code'){
				$passed=($result['passed'] ?? false)===true && !$this->primaryTraceSkipped($result);
				foreach($this->caseStatusKeys($test) as $key){
					$status[$key]=array_key_exists($key, $status) ? ($status[$key] && $passed) : $passed;
					$remaining[$key]=max(0, ($remaining[$key] ?? 1)-1);
				}
			}
		}
		return $results;
	}

	/** @param array<int, array<string, mixed>> $tests */
	private function hasCodeDependencies(array $tests): bool {
		foreach($tests as $test){
			if(($test['kind'] ?? '')==='code' && isset($test['case_dependencies']) && is_array($test['case_dependencies']) && $test['case_dependencies']!==[]){
				return true;
			}
		}
		return false;
	}

	/**
	 * Keeps dependency chains together while allowing every unrelated execution
	 * unit to remain in the normal parallel pool.
	 *
	 * @param array<int, array<string, mixed>> $tests
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
	 */
	private function partitionDependencyTests(array $tests): array {
		$related=[];
		$names=[];
		foreach($tests as $index=>$test){
			$dependencies=(array)($test['case_dependencies'] ?? []);
			if(($test['kind'] ?? '')!=='code' || $dependencies===[]){
				continue;
			}
			$related[$index]=true;
			foreach([...$dependencies, ...$this->caseStatusKeys($test)] as $name){
				$names[(string)$name]=true;
			}
		}
		do{
			$changed=false;
			foreach($tests as $index=>$test){
				if(isset($related[$index]) || ($test['kind'] ?? '')!=='code'){
					continue;
				}
				$keys=$this->caseStatusKeys($test);
				$matches=false;
				foreach($keys as $key){
					if(isset($names[$key])){
						$matches=true;
						break;
					}
				}
				if(!$matches){
					continue;
				}
				$related[$index]=true;
				foreach([...(array)($test['case_dependencies'] ?? []), ...$keys] as $name){
					$names[(string)$name]=true;
				}
				$changed=true;
			}
		}while($changed);
		$independent=[];
		$dependent=[];
		foreach($tests as $index=>$test){
			if(isset($related[$index])){
				$dependent[]=$test;
			}
			else
			{
				$independent[]=$test;
			}
		}
		return [$independent, $dependent];
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function sortExecutionUnits(array $tests): array {
		$history=$this->timingHistory();
		usort($tests, function(array $a, array $b)use($history): int {
			$order=(int)($a['case_order'] ?? 0) <=> (int)($b['case_order'] ?? 0);
			if($order!==0){return $order;}
			$duration=($history[$this->timingKey($b)] ?? 0.0) <=> ($history[$this->timingKey($a)] ?? 0.0);
			if($duration!==0){return $duration;}
			return [
				(string)($a['scope'] ?? ''),
				(string)($a['owner'] ?? ''),
				(string)($a['manifest'] ?? ''),
				(int)($a['case_index'] ?? -1),
			] <=> [
				(string)($b['scope'] ?? ''),
				(string)($b['owner'] ?? ''),
				(string)($b['manifest'] ?? ''),
				(int)($b['case_index'] ?? -1),
			];
		});
		return $tests;
	}

	/** @return array<string,float> */
	private function timingHistory(): array {
		if($this->timing_history!==null){return $this->timing_history;}
		$this->timing_history=[];
		if($this->optionEnabled('no-timing-history') || !is_file($this->timing_history_path)){
			return $this->timing_history;
		}
		$decoded=json_decode((string)file_get_contents($this->timing_history_path), true);
		foreach(is_array($decoded['tests'] ?? null) ? $decoded['tests'] : [] as $key=>$duration){
			if(is_string($key) && is_numeric($duration)){$this->timing_history[$key]=(float)$duration;}
		}
		return $this->timing_history;
	}

	/** @param array<string,mixed> $test */
	private function timingKey(array $test): string {
		$indexes=isset($test['case_indexes']) && is_array($test['case_indexes']) ? implode(',', $test['case_indexes']) : (string)($test['case_index'] ?? '*');
		return (string)($test['scope'] ?? '').'|'.(string)($test['owner'] ?? '').'|'.$this->relativePath((string)($test['manifest'] ?? '')).'|'.$indexes;
	}

	/** @param array<int,array<string,mixed>> $results */
	private function saveTimingHistory(array $results): void {
		if($this->optionEnabled('no-timing-history')){return;}
		$history=$this->timingHistory();
		foreach($results as $result){
			$test=is_array($result['test'] ?? null) ? $result['test'] : null;
			if($test===null){continue;}
			$duration=(float)($result['result']['duration_seconds'] ?? 0.0);
			$key=$this->timingKey($test);
			$history[$key]=isset($history[$key]) ? round(($history[$key] * 0.7) + ($duration * 0.3), 6) : round($duration, 6);
		}
		$directory=dirname($this->timing_history_path);
		if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){return;}
		ksort($history);
		$payload=json_encode(['version'=>1, 'updated_at'=>gmdate(DATE_ATOM), 'tests'=>$history], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		if(is_string($payload) && file_put_contents($this->timing_history_path, $payload."\n", LOCK_EX)!==false){$this->timing_history=$history;}
	}

	/** @return array<string,mixed> */
	private function adaptiveIsolationSummary(): array {
		return [
			'speculative_files'=>$this->adaptive_speculative_files,
			'fallbacks'=>$this->adaptive_fallbacks,
			'case_isolated_from_history'=>$this->adaptive_quarantined_files,
			'index'=>$this->relativePath($this->isolation_index_path),
			'decisions'=>$this->adaptive_isolation_decisions,
		];
	}

	/** @param array<string,mixed> $test */
	private function isolationIndexKey(array $test): string {
		return hash('sha256', implode('|', [
			(string)($test['scope'] ?? ''),
			(string)($test['owner'] ?? ''),
			$this->relativePath((string)($test['manifest'] ?? '')),
		]));
	}

	/** @return array<string,array{fingerprint:string,isolation:string,reason:string,manifest:string,updated_at:string}> */
	private function isolationIndex(): array {
		if($this->isolation_index!==null){return $this->isolation_index;}
		$this->isolation_index=[];
		if(!is_file($this->isolation_index_path)){return $this->isolation_index;}
		$decoded=json_decode((string)file_get_contents($this->isolation_index_path), true);
		$entries=is_array($decoded) && ($decoded['version'] ?? null)===1 && is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
		foreach($entries as $key=>$entry){
			if(
				is_string($key)
				&& is_array($entry)
				&& is_string($entry['fingerprint'] ?? null)
				&& is_string($entry['isolation'] ?? null)
			){
				$this->isolation_index[$key]=[
					'fingerprint'=>$entry['fingerprint'],
					'isolation'=>$entry['isolation'],
					'reason'=>(string)($entry['reason'] ?? ''),
					'manifest'=>(string)($entry['manifest'] ?? ''),
					'updated_at'=>(string)($entry['updated_at'] ?? ''),
				];
			}
		}
		return $this->isolation_index;
	}

	/** @param array<string,mixed> $test @return array{fingerprint:string,isolation:string,reason:string,manifest:string,updated_at:string}|null */
	private function isAdaptiveQuarantined(array $test): ?array {
		$entry=$this->isolationIndex()[$this->isolationIndexKey($test)] ?? null;
		if(!is_array($entry) || ($entry['isolation'] ?? '')!=='case'){
			return null;
		}
		return hash_equals((string)$entry['fingerprint'], $this->codeCaseFingerprint($test)) ? $entry : null;
	}

	/** @param array<string,mixed> $test */
	private function rememberAdaptiveCaseIsolation(array $test, string $reason): bool {
		$directory=dirname($this->isolation_index_path);
		if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
			return false;
		}
		$handle=@fopen($this->isolation_index_path, 'c+');
		if(!is_resource($handle)){return false;}
		$written=false;
		if(flock($handle, LOCK_EX)){
			rewind($handle);
			$decoded=json_decode((string)stream_get_contents($handle), true);
			$disk_entries=is_array($decoded) && ($decoded['version'] ?? null)===1 && is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
			$entries=array_replace($disk_entries, $this->isolationIndex());
			$key=$this->isolationIndexKey($test);
			$entries[$key]=[
				'fingerprint'=>$this->codeCaseFingerprint($test),
				'isolation'=>'case',
				'reason'=>$reason,
				'manifest'=>$this->relativePath((string)($test['manifest'] ?? '')),
				'updated_at'=>gmdate(DATE_ATOM),
			];
			ksort($entries);
			$payload=json_encode(['version'=>1, 'updated_at'=>gmdate(DATE_ATOM), 'entries'=>$entries], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
			if(is_string($payload)){
				rewind($handle);
				if(ftruncate($handle, 0) && fwrite($handle, $payload."\n")!==false){
					fflush($handle);
					$this->isolation_index=$entries;
					$written=true;
				}
			}
			flock($handle, LOCK_UN);
		}
		fclose($handle);
		return $written;
	}

	/** @param array<string, mixed> $test */
	private function canRunParallel(array $test): bool {
		if(($test['kind'] ?? '')==='code'){
			return true;
		}
		if(!$this->optionEnabled('parallel-json') || !in_array((string)($test['kind'] ?? ''), ['dpanel', 'dpanel_single', 'descriptor'], true)){
			return false;
		}
		$allowed=$this->optionList('parallel-json-allow');
		if($allowed===[]){
			return false;
		}
		$relative=$this->relativePath((string)($test['manifest'] ?? ''));
		foreach($allowed as $prefix){
			if($prefix!=='' && str_starts_with($relative, str_replace('\\', '/', $prefix))){
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $test @param array<string, bool> $status */
	private function dependenciesPassed(array $test, array $status): bool {
		foreach((array)($test['case_dependencies'] ?? []) as $dependency){
			if(($status[(string)$dependency] ?? false)!==true){
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $test @return array<int, string> */
	private function caseStatusKeys(array $test): array {
		$keys=[];
		foreach(['case_name', 'case_base_name'] as $field){
			$value=trim((string)($test[$field] ?? ''));
			if($value!=='' && !in_array($value, $keys, true)){
				$keys[]=$value;
			}
		}
		return $keys;
	}

	/** @param array<string, mixed> $test @return array<string, mixed> */
	private function dependencySkipResult(array $test): array {
		return [
			'passed'=>true,
			'test'=>$test,
			'result'=>[
				'passed'=>true,
				'trace'=>[[
					'type'=>'code_unit_test',
					'test_name'=>(string)($test['case_name'] ?? 'dependency skipped test'),
					'case_index'=>(int)($test['case_index'] ?? 0),
					'file'=>(string)($test['manifest'] ?? ''),
					'assertions'=>0,
					'execution_time'=>0.0,
					'message'=>'Skipped because a declared dependency did not pass.',
					'details'=>['dependencies'=>(array)($test['case_dependencies'] ?? [])],
					'skipped'=>true,
					'todo'=>false,
					'passed'=>true,
				]],
				'duration_seconds'=>0.0,
			],
			'exit_code'=>0,
			'stdout'=>'',
			'stderr'=>'',
		];
	}

	/** @param array<string, mixed> $result */
	private function primaryTraceSkipped(array $result): bool {
		$trace=$this->primaryTrace($result);
		return is_array($trace) && (($trace['skipped'] ?? false)===true || ($trace['todo'] ?? false)===true);
	}

	/** @param array<string, mixed> $test @return array<string, mixed> */
	private function workerJob(array $test, int $sequence): array {
		if($test['kind']==='invalid'){
			return [
				'result'=>[
					'passed'=>false,
					'test'=>$test,
					'message'=>'Unit-test manifest is not a supported dpanel case list or smoke descriptor.',
				],
			];
		}
		$timeout=(int)($this->options['timeout'] ?? 12) + 5;
		$cleanup=[];
		if($test['kind']==='code'){
			if(!is_file($this->code_worker_path)){
				throw new RuntimeException('Missing code unit-test worker: '.$this->relativePath($this->code_worker_path));
			}
			$test_file=(string)$test['manifest'];
			$case_indexes=isset($test['case_indexes']) && is_array($test['case_indexes'])
				? array_values(array_map('intval', $test['case_indexes']))
				: [isset($test['case_index']) && is_int($test['case_index']) ? $test['case_index'] : 0];
			$case_index=$case_indexes[0] ?? 0;
			$case_timeout=max(
				max(1, (int)($this->options['timeout'] ?? 12)) * max(1, count($case_indexes)),
				(int)($test['worker_timeout_seconds'] ?? 0),
			);
			$timeout=$case_timeout + 5;
			$hash=sha1($test_file.'#'.implode(',', $case_indexes).'#'.$sequence);
			$payload_path=$this->temporaryRunFile('payload-code-'.$hash.'.json');
			$result_path=$this->temporaryRunFile('result-code-'.$hash.'.json');
			$worker_rootpath=$this->workerRootpath($test, $hash);
			$payload=[
				'rootpath'=>$worker_rootpath['rootpath'],
				'mode'=>'run',
				'test_file'=>$test_file,
				'manifest_path'=>$test_file,
				'case_index'=>$case_index,
				'case_indexes'=>$case_indexes,
				'bootstrap_files'=>$this->testBootstrapFiles($test),
				'timeout_seconds'=>$case_timeout,
				'memory_limit'=>$this->workerMemoryLimit($test),
				'output_path'=>$result_path,
				'coverage'=>$this->coverageEnabled(),
				'coverage_roots'=>$this->explicitCoverageSourceRoots(),
			];
			file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
			return [
				'sequence'=>$sequence,
				'test'=>$test,
				'command'=>$this->phpWorkerCommand($this->code_worker_path, $payload_path),
				'timeout_seconds'=>$timeout,
				'result_path'=>$result_path,
				'cleanup'=>array_merge([$payload_path, $result_path], $worker_rootpath['cleanup']),
				'missing_result_message'=>'Code unit-test worker did not write a valid result.',
				'timeout_message'=>'Code unit-test worker timed out before writing a valid result.',
			];
		}
		$worker=$test['scope']==='app' ? $this->app_worker_path : $this->worker_path;
		if(!is_file($worker)){
			throw new RuntimeException('Missing unit-test worker: '.$this->relativePath($worker));
		}
		$manifest=(string)$test['manifest'];
		if($test['kind']==='descriptor' || $test['kind']==='dpanel_single'){
			$manifest=$this->normalizedManifest($test);
			$cleanup[]=$manifest;
		}
		$hash=sha1($manifest.'#'.(string)($test['case_index'] ?? '').'#'.$sequence);
		$payload_path=$this->temporaryRunFile('payload-'.$hash.'.json');
		$result_path=$this->temporaryRunFile('result-'.$hash.'.json');
		$worker_rootpath=$this->workerRootpath($test, $hash);
		$payload=[
			'rootpath'=>$worker_rootpath['rootpath'],
			'module'=>$this->shouldLoadFrameworkModule($test) ? (string)$test['owner'] : 'manifest',
			'manifest_path'=>$manifest,
			'timeout_seconds'=>(int)($this->options['timeout'] ?? 12),
			'memory_limit'=>$this->workerMemoryLimit($test),
			'output_path'=>$result_path,
			'coverage'=>$this->coverageEnabled(),
		];
		if(isset($test['case_index']) && is_int($test['case_index'])){
			$payload['case_index']=$test['case_index'];
		}
		file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		return [
			'sequence'=>$sequence,
			'test'=>$test,
			'command'=>$this->phpWorkerCommand($worker, $payload_path),
			'timeout_seconds'=>$timeout,
			'result_path'=>$result_path,
			'cleanup'=>array_merge([$payload_path, $result_path], $cleanup, $worker_rootpath['cleanup']),
			'missing_result_message'=>'Worker did not write a valid result.',
			'timeout_message'=>'Worker timed out before writing a valid result.',
		];
	}

	/** @param array<string, mixed> $job @return array<string, mixed> */
	private function runWorkerJob(array $job): array {
		$process=$this->runProcess($job['command'], (int)$job['timeout_seconds']);
		return $this->workerJobResult($job, $process);
	}

	/** @param array<string, mixed> $job @return array<string, mixed> */
	private function startWorkerProcess(array $job): array {
		$token=sha1(json_encode($job['command'] ?? [], JSON_UNESCAPED_SLASHES).'#'.(string)($job['sequence'] ?? 0));
		$stdout_path=$this->temporaryRunFile('worker-stdout-'.$token.'.log');
		$stderr_path=$this->temporaryRunFile('worker-stderr-'.$token.'.log');
		$descriptor=[
			0=>['pipe', 'r'],
			1=>['file', $stdout_path, 'w'],
			2=>['file', $stderr_path, 'w'],
		];
		$pipes=[];
		$process=$this->openProcess($job['command'], $descriptor, $pipes);
		if(!is_resource($process)){
			@unlink($stdout_path);
			@unlink($stderr_path);
			return [
				'result'=>[
					'passed'=>false,
					'test'=>$job['test'],
					'message'=>'Unable to start unit-test worker.',
				],
			];
		}
		fclose($pipes[0]);
		return [
			'job'=>$job,
			'resource'=>$process,
			'stdout_path'=>$stdout_path,
			'stderr_path'=>$stderr_path,
			'started'=>$this->processNow(),
			'timed_out'=>false,
			'observed_exit_code'=>-1,
		];
	}

	/** @param array<string, mixed> $process @return array<string, mixed> */
	private function finishWorkerProcess(array $process): array {
		$exit=$this->closeProcess($process['resource']);
		$observed_exit=(int)($process['observed_exit_code'] ?? -1);
		if($exit===-1 && $observed_exit>=0){$exit=$observed_exit;}
		$stdout=is_file((string)$process['stdout_path']) ? (string)file_get_contents((string)$process['stdout_path']) : '';
		$stderr=is_file((string)$process['stderr_path']) ? (string)file_get_contents((string)$process['stderr_path']) : '';
		@unlink((string)$process['stdout_path']);
		@unlink((string)$process['stderr_path']);
		return $this->workerJobResult($process['job'], [
			'exit_code'=>($process['timed_out'] ?? false)===true ? 124 : $exit,
			'stdout'=>$stdout,
			'stderr'=>$stderr,
			'timed_out'=>($process['timed_out'] ?? false)===true,
		]);
	}

	/** @param array<string, mixed> $job @param array{exit_code:int, stdout:string, stderr:string, timed_out:bool} $process @return array<string, mixed> */
	private function workerJobResult(array $job, array $process): array {
		$result=is_file((string)$job['result_path']) ? json_decode((string)file_get_contents((string)$job['result_path']), true) : null;
		foreach(($job['cleanup'] ?? []) as $path){
			$this->cleanupTemporaryPath((string)$path);
		}
		if(!is_array($result)){
			$wrapped=[
				'passed'=>false,
				'test'=>$job['test'],
				'message'=>$process['timed_out'] ? (string)$job['timeout_message'] : (string)$job['missing_result_message'],
				'exit_code'=>$process['exit_code'],
				'stdout'=>$process['stdout'],
				'stderr'=>$process['stderr'],
			];
		}
		else
		{
			$wrapped=[
				'passed'=>($result['passed'] ?? false)===true && $process['exit_code']===0,
				'test'=>$job['test'],
				'result'=>$result,
				'exit_code'=>$process['exit_code'],
				'stdout'=>$process['stdout'],
				'stderr'=>$process['stderr'],
			];
		}
		if(($wrapped['passed'] ?? false)!==true && ($job['test']['adaptive_speculative'] ?? false)===true){
			return $this->retryAdaptiveCases($job['test'], $wrapped);
		}
		return $wrapped;
	}

	/** @param array<string,mixed> $batch @param array<string,mixed> $speculative_result @return array<string,mixed> */
	private function retryAdaptiveCases(array $batch, array $speculative_result): array {
		$cases=isset($batch['adaptive_cases']) && is_array($batch['adaptive_cases']) ? array_values(array_filter($batch['adaptive_cases'], 'is_array')) : [];
		if($cases===[]){return $speculative_result;}
		$this->adaptive_fallbacks++;
		$isolated_results=[];
		foreach($cases as $sequence=>$case){
			$unit=$this->caseExecutionUnit($batch, $case, 'case-isolated retry after speculative file lifecycle failure');
			$unit['adaptive_retry']=true;
			$job=$this->workerJob($unit, 100000 + $sequence);
			$isolated_results[]=isset($job['result']) && is_array($job['result']) ? $job['result'] : $this->runWorkerJob($job);
		}
		$passed=true;
		$trace=[];
		$coverage_parts=[];
		$speculative_duration=(float)($speculative_result['result']['duration_seconds'] ?? 0.0);
		$isolated_duration=0.0;
		$stdout=[];
		$stderr=[];
		foreach($isolated_results as $result){
			if(($result['passed'] ?? false)!==true){$passed=false;}
			foreach(is_array($result['result']['trace'] ?? null) ? $result['result']['trace'] : [] as $entry){
				if(is_array($entry)){$trace[]=$entry;}
			}
			if(is_array($result['result']['coverage'] ?? null)){$coverage_parts[]=$result['result']['coverage'];}
			foreach(is_array($result['result']['coverage_parts'] ?? null) ? $result['result']['coverage_parts'] : [] as $coverage){
				if(is_array($coverage)){$coverage_parts[]=$coverage;}
			}
			$isolated_duration+=(float)($result['result']['duration_seconds'] ?? 0.0);
			if(trim((string)($result['stdout'] ?? ''))!==''){$stdout[]=trim((string)$result['stdout']);}
			if(trim((string)($result['stderr'] ?? ''))!==''){$stderr[]=trim((string)$result['stderr']);}
		}
		$reason='speculative file lifecycle failed';
		if($passed){
			$reason.=' while every case-isolated retry passed';
			$this->rememberAdaptiveCaseIsolation($batch, $reason);
		}
		else
		{
			$reason.=' and at least one case-isolated retry also failed';
		}
		$this->adaptive_isolation_decisions[]=[
			'manifest'=>$this->relativePath((string)($batch['manifest'] ?? '')),
			'decision'=>$passed ? 'fallback-passed-and-remembered' : 'fallback-confirmed-failure',
			'cases'=>count($cases),
			'reason'=>$reason,
		];
		$accepted_test=$batch;
		unset($accepted_test['adaptive_cases']);
		$accepted_test['adaptive_speculative']=false;
		$accepted_test['adaptive_fallback']=true;
		$accepted_test['adaptive_quarantined']=$passed;
		$accepted_test['isolation']='case';
		$accepted_test['isolation_reason']=$reason;
		$accepted_result=[
			'passed'=>$passed,
			'trace'=>$trace,
			'duration_seconds'=>$speculative_duration + $isolated_duration,
			'speculative_duration_seconds'=>$speculative_duration,
			'isolated_retry_duration_seconds'=>$isolated_duration,
			'coverage_parts'=>$coverage_parts,
			'adaptive_isolation'=>[
				'speculative_failed'=>true,
				'fallback'=>'case',
				'accepted_isolated_outcomes'=>true,
				'fingerprint_remembered'=>$passed,
				'reason'=>$reason,
			],
		];
		return [
			'passed'=>$passed,
			'test'=>$accepted_test,
			'result'=>$accepted_result,
			'message'=>$passed ? null : 'One or more case-isolated retries failed.',
			'exit_code'=>$passed ? 0 : 1,
			'stdout'=>implode("\n", $stdout),
			'stderr'=>implode("\n", $stderr),
		];
	}

	/** @param array<string, mixed> $result */
	private function printFailure(array $result): void {
		$test=$result['test'];
		$case=isset($test['case_index']) && is_int($test['case_index']) ? '#'.$test['case_index'].' ' : '';
		echo "FAIL ".$test['scope'].' '.$test['owner'].' '.$case.$this->relativePath((string)$test['manifest'])."\n";
		if(isset($result['message'])){
			echo "  ".$result['message']."\n";
		}
		if(isset($result['result']['trace']) && is_array($result['result']['trace'])){
			foreach(array_slice($result['result']['trace'], 0, 5) as $entry){
				if(!is_array($entry)){
					continue;
				}
				$message=(string)($entry['message'] ?? 'failed');
				$name=(string)($entry['test_name'] ?? $entry['function'] ?? '');
				echo "  ".($name!=='' ? $name.': ' : '').$message."\n";
				if(isset($entry['details']) && is_array($entry['details']) && $entry['details']!==[]){
					if(array_key_exists('expected', $entry['details']) || array_key_exists('actual', $entry['details'])){
						echo "    expected: ".substr(json_encode($entry['details']['expected'] ?? null, JSON_UNESCAPED_SLASHES), 0, 180)."\n";
						echo "    actual: ".substr(json_encode($entry['details']['actual'] ?? null, JSON_UNESCAPED_SLASHES), 0, 180)."\n";
						if(isset($entry['details']['meta']['diff'])){
							echo "    diff:\n".preg_replace('/^/m', '      ', substr((string)$entry['details']['meta']['diff'], 0, 1200))."\n";
						}
					}
					elseif(isset($entry['details']['exception']))
					{
						echo "    exception: ".$entry['details']['exception']."\n";
					}
				}
			}
		}
		foreach(['stderr', 'stdout'] as $stream){
			$text=trim((string)($result[$stream] ?? ''));
			if($text!==''){
				echo "  {$stream}: ".substr($text, 0, 600)."\n";
			}
		}
	}

	/** @param array<string, mixed> $summary @param array<int, array<string, mixed>> $failed */
	private function writeRunOutput(array $summary, array $failed): void {
		if($this->optionEnabled('json')){
			echo json_encode([
				'summary'=>$summary,
				'failures'=>$this->relativeFailures($failed),
			], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
			return;
		}
		if((int)$summary['workers_total']===0){
			echo "No unit-test cases matched the requested scope.\n";
			return;
		}
		echo "Unit tests: ".$summary['workers_passed'].'/'.$summary['workers_total']." worker".((int)$summary['workers_total']===1 ? '' : 's')." passed, ".$summary['cases_declared']." case".((int)$summary['cases_declared']===1 ? '' : 's')." declared in ".number_format((float)$summary['duration_seconds'], 2)."s.\n";
		$extra=[];
		if((int)$summary['skipped']>0){
			$extra[]='skipped='.(int)$summary['skipped'];
		}
		if((int)$summary['todo']>0){
			$extra[]='todo='.(int)$summary['todo'];
		}
		if((int)$summary['assertions']>0){
			$extra[]='assertions='.(int)$summary['assertions'];
		}
		if($extra!==[]){
			echo "Code test detail: ".implode(', ', $extra).".\n";
		}
		if((int)$summary['dynamic_skipped']>0 && $this->includeDynamic()!==true){
			echo "Dynamic/generated manifests skipped by default: ".$summary['dynamic_skipped'].". Use --include-dynamic for that heavier diagnostic lane.\n";
		}
		if(isset($summary['policy_failures']) && is_array($summary['policy_failures']) && $summary['policy_failures']!==[]){
			echo "Policy failure: ".implode(', ', $summary['policy_failures'])." present in this run.\n";
		}
		$source_epoch=is_array($summary['source_epoch'] ?? null) ? $summary['source_epoch'] : [];
		if(($source_epoch['stable'] ?? null)===false){
			echo 'Source epoch invalidated: '.$this->sourceEpochChangeSummary($source_epoch).".\n";
		}
		if($failed!==[]){
			echo "Failed workers: ".count($failed)."\n";
		}
	}

	/** @param array<string, mixed> $summary @param array<int, array<string, mixed>> $results */
	private function writeCiArtifacts(array $summary, array $results): void {
		$junit=$this->options['junit'] ?? '';
		if($junit!=='' && $junit!==false){
			$path=$junit===true ? 'unit-tests.junit.xml' : (string)$junit;
			$this->writeJUnitReport($summary, $results, $this->outputPath($path));
		}
		if($this->coverageEnabled()){
			$coverage=$this->options['coverage'] ?? true;
			$path=$coverage===true ? 'cache/ci/unit-tests.coverage.json' : (string)$coverage;
			$this->writeCoverageReport($results, $this->outputPath($path));
		}
		if(array_key_exists('profile', $this->options) && $this->options['profile']!==false){
			$profile=$this->options['profile'];
			$path=$profile===true ? 'cache/ci/unit-tests.profile.json' : (string)$profile;
			$this->writeProfileReport($summary, $results, $this->outputPath($path));
		}
		if($this->optionEnabled('github-annotations')){
			$this->writeGithubAnnotations($results);
		}
	}

	/** @param array<string,mixed> $summary @param array<int,array<string,mixed>> $results */
	private function writeProfileReport(array $summary, array $results, string $path): void {
		$dir=dirname($path);
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new RuntimeException('Unable to create unit-test profile output directory: '.$dir);
		}
		$cases=[];
		foreach($results as $result){
			$test=is_array($result['test'] ?? null) ? $result['test'] : [];
			$traces=isset($result['result']['trace']) && is_array($result['result']['trace'])
				? array_values(array_filter($result['result']['trace'], 'is_array'))
				: [];
			if($traces===[]){$traces=[[]];}
			$worker_duration=(float)($result['result']['duration_seconds'] ?? 0.0);
			foreach($traces as $trace){
				$cases[]=[
					'scope'=>(string)($test['scope'] ?? 'unknown'),
					'owner'=>(string)($test['owner'] ?? 'unknown'),
					'manifest'=>$this->relativePath((string)($test['manifest'] ?? '')),
					'name'=>(string)($trace['test_name'] ?? $test['case_name'] ?? basename((string)($test['manifest'] ?? 'unit-test'))),
					'duration_seconds'=>isset($trace['execution_time']) ? (float)$trace['execution_time'] : $worker_duration / max(1, count($traces)),
					'assertions'=>(int)($trace['assertions'] ?? 0),
					'passed'=>($trace['passed'] ?? $result['passed'] ?? false)===true,
					'isolation'=>(string)($trace['isolation'] ?? $test['isolation'] ?? 'manifest'),
					'stable_id'=>(string)($trace['stable_id'] ?? $test['case_stable_id'] ?? ''),
					'contract'=>$trace['contract'] ?? $test['contract'] ?? null,
					'layer'=>$trace['layer'] ?? $test['layer'] ?? null,
					'risk'=>$trace['risk'] ?? $test['risk'] ?? null,
					'through'=>isset($trace['through']) && is_array($trace['through']) ? array_values(array_map('strval', $trace['through'])) : (array)($test['through'] ?? []),
					'isolation_reason'=>(string)($test['isolation_reason'] ?? ''),
					'adaptive_fallback'=>($test['adaptive_fallback'] ?? false)===true,
					'repeat_index'=>(int)($trace['repeat_index'] ?? $test['repeat_index'] ?? 1),
					'repeat_total'=>(int)($trace['repeat_total'] ?? $test['repeat_total'] ?? 1),
				];
			}
		}
		usort($cases, static function(array $a, array $b): int {
			$duration=(float)$b['duration_seconds'] <=> (float)$a['duration_seconds'];
			return $duration!==0 ? $duration : [$a['manifest'], $a['name']] <=> [$b['manifest'], $b['name']];
		});
		$payload=[
			'version'=>1,
			'generated_at'=>gmdate(DATE_ATOM),
			'duration_seconds'=>(float)($summary['duration_seconds'] ?? 0.0),
			'discovery_seconds'=>(float)($summary['discovery_seconds'] ?? 0.0),
			'execution_seconds'=>(float)($summary['execution_seconds'] ?? 0.0),
			'discovery_cache_hits'=>(int)($summary['discovery_cache_hits'] ?? 0),
			'discovery_cache_misses'=>(int)($summary['discovery_cache_misses'] ?? 0),
			'adaptive_isolation'=>$summary['adaptive_isolation'] ?? $this->adaptiveIsolationSummary(),
			'source_epoch'=>$summary['source_epoch'] ?? $this->sourceEpochMetadata(),
			'cases'=>$cases,
		];
		file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
		if(!$this->optionEnabled('json')){
			$top=max(0, (int)($this->options['profile-top'] ?? 10));
			foreach(array_slice($cases, 0, $top) as $case){
				echo 'SLOW '.number_format((float)$case['duration_seconds'] * 1000, 1).'ms '.$case['owner'].' '.$case['name']."\n";
			}
			echo 'Unit-test profile written to '.$this->relativePath($path).".\n";
		}
	}

	private function captureOrchestratorCoverage(): void {
		if($this->orchestrator_coverage!==null){return;}
		$this->coverage_summary=null;
		$this->orchestrator_coverage=[];
		if(($this->orchestrator_coverage_state['enabled'] ?? false)!==true){return;}
		$included_before=is_array($this->orchestrator_coverage_state['included_before'] ?? null) ? $this->orchestrator_coverage_state['included_before'] : [];
		$included_candidates=array_values(array_diff(get_included_files(), $included_before));
		$included_candidates[]=__FILE__;
		$included=[];
		foreach($included_candidates as $file){
			$relative=$this->orchestratorCoverageRelative((string)$file);
			if($relative!==null){$included[$relative]=true;}
		}
		$xdebug_reader=$this->orchestrator_coverage_state['xdebug_get'] ?? (function_exists('xdebug_get_code_coverage') ? 'xdebug_get_code_coverage' : null);
		if(($this->orchestrator_coverage_state['xdebug'] ?? false)===true && is_callable($xdebug_reader)){
			$coverage=$xdebug_reader() ?: [];
			$line_files=$this->normalizeXdebugCoverage(is_array($coverage) ? $coverage : []);
			$xdebug_stop=$this->orchestrator_coverage_state['xdebug_stop'] ?? (function_exists('xdebug_stop_code_coverage') ? 'xdebug_stop_code_coverage' : null);
			if(($this->orchestrator_coverage_state['xdebug_owned'] ?? false)===true && is_callable($xdebug_stop)){
				@$xdebug_stop(false);
			}
			$this->orchestrator_coverage=['engine'=>'xdebug', 'files'=>$line_files, 'included_files'=>array_keys($included), 'source'=>'orchestrator'];
			return;
		}
		$phpdbg_end=$this->orchestrator_coverage_state['phpdbg_end'] ?? (function_exists('phpdbg_end_oplog') ? 'phpdbg_end_oplog' : null);
		$phpdbg_get=$this->orchestrator_coverage_state['phpdbg_get'] ?? (function_exists('phpdbg_get_executable') ? 'phpdbg_get_executable' : null);
		if(($this->orchestrator_coverage_state['phpdbg'] ?? false)===true && is_callable($phpdbg_end) && is_callable($phpdbg_get)){
			$executable=\Dataphyre\Test\PhpdbgLineMap::detach(@$phpdbg_get());
			$oplog=\Dataphyre\Test\PhpdbgLineMap::detach(@$phpdbg_end());
			$line_files=$this->normalizePhpdbgCoverage($executable, $oplog);
			$this->orchestrator_coverage=['engine'=>'phpdbg', 'files'=>$line_files, 'included_files'=>array_keys($included), 'source'=>'orchestrator'];
			return;
		}
		$files=array_keys($included);
		sort($files);
		$this->orchestrator_coverage=['engine'=>'included_files', 'files'=>$files, 'source'=>'orchestrator'];
	}

	/** @param array<string,mixed> $coverage @return array<string,array{executable_lines:array<int,int>,covered_lines:array<int,int>}> */
	private function normalizeXdebugCoverage(array $coverage): array {
		$line_files=[];
		foreach($coverage as $file=>$lines){
			$relative=$this->orchestratorCoverageRelative((string)$file);
			if($relative===null || !is_array($lines)){continue;}
			$executable=[];
			$covered=[];
			foreach($lines as $line=>$hit){
				if((int)$hit===-2){continue;}
				$executable[]=(int)$line;
				if((int)$hit>0){$covered[]=(int)$line;}
			}
			$executable=array_values(array_unique($executable));
			$covered=array_values(array_unique($covered));
			sort($executable, SORT_NUMERIC);
			sort($covered, SORT_NUMERIC);
			$line_files[$relative]=['executable_lines'=>$executable, 'covered_lines'=>$covered];
		}
		ksort($line_files);
		return $line_files;
	}

	/** @param array<string,mixed> $executable @param array<string,mixed> $oplog @return array<string,array{executable_lines:array<int,int>,covered_lines:array<int,int>}> */
	private function normalizePhpdbgCoverage(array $executable, array $oplog): array {
		$line_files=[];
		foreach($executable as $file=>$lines){
			$relative=$this->orchestratorCoverageRelative((string)$file);
			if($relative===null || !is_array($lines)){continue;}
			$executable_lines=[];
			foreach(array_keys($lines) as $line){if((int)$line>0){$executable_lines[]=(int)$line;}}
			$hits=$oplog[$file] ?? $oplog[str_replace('/', '\\', (string)$file)] ?? [];
			$covered_lines=[];
			foreach(is_array($hits) ? array_keys($hits) : [] as $line){if((int)$line>0){$covered_lines[]=(int)$line;}}
			$normalized=\Dataphyre\Test\CoverageLineNormalizer::phpdbg((string)(realpath((string)$file) ?: $file), $executable_lines, $covered_lines);
			$line_files[$relative]=[
				'executable_lines'=>$normalized['executable_lines'],
				'covered_lines'=>$normalized['covered_lines'],
				'raw_executable_lines'=>$normalized['raw_executable_lines'],
				'ignored_lines'=>$normalized['ignored_lines'],
				'ignored_by_reason'=>$normalized['ignored_by_reason'],
			];
		}
		ksort($line_files);
		return $line_files;
	}

	private function orchestratorCoverageRelative(string $file): ?string {
		$resolved=realpath($file);
		$file=str_replace('\\', '/', is_string($resolved) ? $resolved : $file);
		$explicit_roots=$this->explicitCoverageSourceRoots();
		if($explicit_roots!==[]){
			if(!$this->coverageFileWithinRoots($file, $explicit_roots)){return null;}
			$relative=$this->relativePath($file);
			return $this->coverageSourceExcluded($relative) ? null : $relative;
		}
		$root=str_replace('\\', '/', rtrim((string)(realpath($this->framework_root) ?: $this->framework_root), '/\\')).'/';
		if(!str_starts_with(strtolower($file), strtolower($root))){return null;}
		$relative=substr($file, strlen($root));
		return $this->coverageSourceExcluded($relative) ? null : $relative;
	}

	/** @param array<int, array<string, mixed>> $results */
	private function writeCoverageReport(array $results, string $path): void {
		$dir=dirname($path);
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new RuntimeException('Unable to create coverage output directory: '.$dir);
		}
		file_put_contents($path, json_encode($this->coverageSummary($results), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
	}

	/** @param array<int, array<string, mixed>> $results @return array<string, mixed> */
	private function coverageSummary(array $results): array {
		$cacheable=true;
		foreach($results as $result){
			if(is_array($result['result']['coverage'] ?? null) || (is_array($result['result']['coverage_parts'] ?? null) && $result['result']['coverage_parts']!==[])){
				$cacheable=false;
				break;
			}
		}
		if($cacheable && $this->coverage_summary!==null){return $this->coverage_summary;}
		foreach($this->coverage_accumulator->all() as $coverage){
			$results[]=['result'=>['coverage'=>$coverage]];
		}
		if($this->orchestrator_coverage!==null && $this->orchestrator_coverage!==[]){
			$results[]=['result'=>['coverage'=>$this->orchestrator_coverage]];
		}
		$engines=[];
		$included=[];
		$line_files=[];
		$line_maps=[];
		foreach($results as $result){
			$coverages=[];
			if(is_array($result['result']['coverage'] ?? null)){$coverages[]=$result['result']['coverage'];}
			foreach(is_array($result['result']['coverage_parts'] ?? null) ? $result['result']['coverage_parts'] : [] as $coverage){
				if(is_array($coverage)){$coverages[]=$coverage;}
			}
			foreach($coverages as $coverage){
				$engine=(string)($coverage['engine'] ?? 'included_files');
				$engines[$engine]=true;
				foreach(is_array($coverage['included_files'] ?? null) ? $coverage['included_files'] : [] as $included_file){
					$absolute=$this->coverageAbsolutePath((string)$included_file);
					$included[$absolute!=='' ? $absolute : (string)$included_file]=true;
				}
				$files=$coverage['files'] ?? [];
				if(in_array($engine, ['xdebug', 'phpdbg'], true) && is_array($files)){
					foreach($files as $file=>$stats){
						if(!is_array($stats)){
							continue;
						}
						$absolute=$this->coverageAbsolutePath((string)$file);
						$file=$absolute!=='' ? $absolute : (string)$file;
						$executable_ranges=$stats['executable_ranges'] ?? null;
						$covered_ranges=$stats['covered_ranges'] ?? null;
						if(is_string($executable_ranges) && is_string($covered_ranges)){
							$line_maps[$file] ??=['executable'=>[], 'covered'=>[], 'raw_executable'=>[], 'ignored'=>[], 'ignored_reasons'=>[], 'engines'=>[]];
							$line_maps[$file]['raw_executable'] ??=[];$line_maps[$file]['ignored'] ??=[];$line_maps[$file]['ignored_reasons'] ??=[];
							$line_maps[$file]['engines'][$engine]=true;
							foreach($this->coverageRangeLines($executable_ranges) as $line){
								$line_maps[$file]['executable'][$line]=true;
							}
							foreach($this->coverageRangeLines($covered_ranges) as $line){
								$line_maps[$file]['covered'][$line]=true;
							}
							$raw_ranges=is_string($stats['raw_executable_ranges'] ?? null) ? $stats['raw_executable_ranges'] : $executable_ranges;
							foreach($this->coverageRangeLines($raw_ranges) as $line){$line_maps[$file]['raw_executable'][$line]=true;}
							foreach($this->coverageRangeLines(is_string($stats['ignored_ranges'] ?? null) ? $stats['ignored_ranges'] : '') as $line){$line_maps[$file]['ignored'][$line]=true;}
							foreach(is_array($stats['ignored_reasons'] ?? null) ? $stats['ignored_reasons'] : [] as $reason=>$ranges){
								if(!is_string($ranges)){continue;}
								foreach($this->coverageRangeLines($ranges) as $line){$line_maps[$file]['ignored_reasons'][(string)$reason][$line]=true;}
							}
							continue;
						}
						$executable_lines=$stats['executable_lines'] ?? null;
						$covered_lines=$stats['covered_lines'] ?? null;
						if(is_array($executable_lines) && is_array($covered_lines)){
							$line_maps[$file] ??=['executable'=>[], 'covered'=>[], 'raw_executable'=>[], 'ignored'=>[], 'ignored_reasons'=>[], 'engines'=>[]];
							$line_maps[$file]['raw_executable'] ??=[];$line_maps[$file]['ignored'] ??=[];$line_maps[$file]['ignored_reasons'] ??=[];
							$line_maps[$file]['engines'][$engine]=true;
							foreach($executable_lines as $line){
								$line_maps[$file]['executable'][(int)$line]=true;
							}
							foreach($covered_lines as $line){
								$line_maps[$file]['covered'][(int)$line]=true;
							}
							$raw_lines=is_array($stats['raw_executable_lines'] ?? null) ? $stats['raw_executable_lines'] : $executable_lines;
							foreach($raw_lines as $line){$line_maps[$file]['raw_executable'][(int)$line]=true;}
							foreach(is_array($stats['ignored_lines'] ?? null) ? $stats['ignored_lines'] : [] as $line){$line_maps[$file]['ignored'][(int)$line]=true;}
							foreach(is_array($stats['ignored_by_reason'] ?? null) ? $stats['ignored_by_reason'] : [] as $reason=>$lines){
								if(!is_array($lines)){continue;}
								foreach($lines as $line){$line_maps[$file]['ignored_reasons'][(string)$reason][(int)$line]=true;}
							}
							continue;
						}
						$line_files[$file]=[
							'raw_executable'=>(int)($stats['raw_executable'] ?? $stats['executable'] ?? 0),
							'executable'=>(int)($stats['executable'] ?? 0),
							'covered'=>(int)($stats['covered'] ?? 0),
							'ignored_executable'=>(int)($stats['ignored'] ?? 0),
							'uncovered_lines'=>[],
							'ignored_executable_lines'=>[],
							'ignored_executable_reasons'=>[],
						];
					}
					continue;
				}
				foreach(is_array($files) ? $files : [] as $file){
					$absolute=$this->coverageAbsolutePath((string)$file);
					$included[$absolute!=='' ? $absolute : (string)$file]=true;
				}
			}
		}
		foreach($line_maps as $file=>$maps){
			$executable_lines=array_keys($maps['executable']);
			$covered_lines=array_keys($maps['covered']);
			$raw_executable_lines=array_keys($maps['raw_executable'] ?? $maps['executable']);
			$file_engines=array_keys(is_array($maps['engines'] ?? null) ? $maps['engines'] : []);
			sort($file_engines,SORT_STRING);
			$union_phpdbg=$file_engines===['phpdbg'];
			if($union_phpdbg){
				$normalized=\Dataphyre\Test\CoverageLineNormalizer::phpdbg((string)$file,$raw_executable_lines,$covered_lines);
				$executable_lines=$normalized['executable_lines'];
				$covered_lines=$normalized['covered_lines'];
				$raw_executable_lines=$normalized['raw_executable_lines'];
				$ignored_lines=$normalized['ignored_lines'];
			}else{
				$ignored_lines=array_values(array_diff(array_keys($maps['ignored'] ?? []), $executable_lines));
			}
			sort($executable_lines, SORT_NUMERIC);
			sort($covered_lines, SORT_NUMERIC);
			sort($raw_executable_lines, SORT_NUMERIC);
			sort($ignored_lines, SORT_NUMERIC);
			$ignored_reasons=[];
			if($union_phpdbg){
				$ignored_reasons=$normalized['ignored_by_reason'];
			}else{
				foreach($maps['ignored_reasons'] ?? [] as $reason=>$lines){
					$reason_lines=array_values(array_intersect(array_keys($lines), $ignored_lines));
					sort($reason_lines, SORT_NUMERIC);
					if($reason_lines!==[]){$ignored_reasons[(string)$reason]=$reason_lines;}
				}
			}
			ksort($ignored_reasons, SORT_STRING);
			$line_files[$file]=[
				'raw_executable'=>count($raw_executable_lines),
				'executable'=>count($executable_lines),
				'covered'=>count($covered_lines),
				'ignored_executable'=>count($ignored_lines),
				'uncovered_lines'=>array_values(array_diff($executable_lines, $covered_lines)),
				'ignored_executable_lines'=>$ignored_lines,
				'ignored_executable_reasons'=>$ignored_reasons,
			];
		}
		ksort($included);
		ksort($line_files);
		$inventory=$this->coverageSourceInventory();
		$inventory_lookup=[];
		foreach($inventory as $file){$inventory_lookup[strtolower($file)]=$file;}
		$observed=[];
		$scoped_included=[];
		$out_of_scope_included=[];
		foreach(array_keys($included) as $file){
			$absolute=$this->coverageAbsolutePath((string)$file);
			if($absolute===''){continue;}
			$key=strtolower($absolute);
			$observed[$key]=true;
			if(isset($inventory_lookup[$key])){$scoped_included[$this->relativePath($inventory_lookup[$key])]=true;}
			else{$out_of_scope_included[$this->relativePath($absolute)]=true;}
		}
		$scoped_line_files=[];
		$out_of_scope_line_files=[];
		foreach($line_files as $file=>$stats){
			$absolute=$this->coverageAbsolutePath((string)$file);
			if($absolute===''){continue;}
			$key=strtolower($absolute);
			$observed[$key]=true;
			if(isset($inventory_lookup[$key])){$scoped_line_files[$this->relativePath($inventory_lookup[$key])]=$stats;}
			else{$out_of_scope_line_files[$this->relativePath($absolute)]=true;}
		}
		ksort($scoped_included);
		ksort($scoped_line_files);
		ksort($out_of_scope_included);
		ksort($out_of_scope_line_files);
		$covered_lines=array_sum(array_column($scoped_line_files, 'covered'));
		$executable_lines=array_sum(array_column($scoped_line_files, 'executable'));
		$raw_executable_lines=array_sum(array_column($scoped_line_files, 'raw_executable'));
		$ignored_executable_lines=array_sum(array_column($scoped_line_files, 'ignored_executable'));
		$ignored_by_reason=[];
		foreach($scoped_line_files as $stats){
			foreach($stats['ignored_executable_reasons'] ?? [] as $reason=>$lines){$ignored_by_reason[$reason]=($ignored_by_reason[$reason] ?? 0)+count($lines);}
		}
		ksort($ignored_by_reason, SORT_STRING);
		$missing=[];
		foreach($inventory as $file){
			if(!isset($observed[strtolower($file)])){$missing[]=$this->relativePath($file);}
		}
		sort($missing);
		$inventory_complete=$inventory!==[] && $missing===[];
		$scoped_complete=$executable_lines>0 && $covered_lines===$executable_lines;
		$summary=[
			'engines'=>array_keys($engines),
			'included_file_count'=>count($scoped_included),
			'included_files'=>array_keys($scoped_included),
			'observed_included_file_count'=>count($included),
			'out_of_scope_included_file_count'=>count($out_of_scope_included),
			'out_of_scope_included_files'=>array_keys($out_of_scope_included),
			'line_file_count'=>count($scoped_line_files),
			'observed_line_file_count'=>count($line_files),
			'out_of_scope_line_file_count'=>count($out_of_scope_line_files),
			'out_of_scope_line_files'=>array_keys($out_of_scope_line_files),
			'covered_lines'=>$covered_lines,
			'executable_lines'=>$executable_lines,
			'raw_executable_lines'=>$raw_executable_lines,
			'ignored_executable_lines'=>$ignored_executable_lines,
			'coverage_normalization'=>[
				'contract'=>'phpdbg-structural-token-only',
				'ignored_executable_lines'=>$ignored_executable_lines,
				'ignored_by_reason'=>$ignored_by_reason,
			],
			'line_coverage_percent'=>$executable_lines>0 ? round(($covered_lines/$executable_lines)*100, 2) : null,
			'line_coverage_complete'=>$scoped_complete && $inventory_complete,
			'observed_line_coverage_complete'=>$scoped_complete,
			'scoped_line_coverage_complete'=>$scoped_complete,
			'source_inventory_file_count'=>count($inventory),
			'source_inventory_observed_count'=>count($inventory)-count($missing),
			'source_inventory_missing_count'=>count($missing),
			'source_inventory_complete'=>$inventory_complete,
			'missing_source_files'=>$missing,
			'source_inventory_exclusions'=>$this->coverageSourceExclusions(),
			'coverage_lanes'=>$this->coverageLaneSummary(),
			'source_epoch'=>$this->sourceEpochMetadata(),
			'line_files'=>$scoped_line_files,
		];
		if($cacheable){$this->coverage_summary=$summary;}
		return $summary;
	}

	private function sourceEpochActivation(): ?string {
		if($this->optionEnabled('source-epoch')){
			return 'explicit';
		}
		if(!$this->coverageEnabled()){
			return null;
		}
		if($this->optionEnabled('coverage-closed-world')){
			return 'coverage-closed-world';
		}
		if((float)($this->options['coverage-min-percent'] ?? 0)>0.0){
			return 'coverage-line-threshold';
		}
		if(in_array(strtolower((string)($this->options['coverage-require'] ?? '')), ['xdebug', 'phpdbg'], true)){
			return 'coverage-exact-engine';
		}
		return null;
	}

	private function sourceEpochEnabled(): bool {
		return $this->sourceEpochActivation()!==null;
	}

	private function beginSourceEpoch(): void {
		if(!$this->sourceEpochEnabled()){
			return;
		}
		$this->source_epoch_before=$this->sourceEpochSnapshot();
		$this->source_epoch_result=null;
	}

	private function finishSourceEpoch(): void {
		if(!$this->sourceEpochEnabled() || $this->source_epoch_before===null){
			return;
		}
		$before=$this->source_epoch_before;
		$after=$this->sourceEpochSnapshot();
		$changed=[];
		foreach(array_intersect_key($before['files'], $after['files']) as $path=>$hash){
			if($after['files'][$path]!==$hash){$changed[]=$path;}
		}
		$added=array_keys(array_diff_key($after['files'], $before['files']));
		$removed=array_keys(array_diff_key($before['files'], $after['files']));
		sort($changed, SORT_STRING);
		sort($added, SORT_STRING);
		sort($removed, SORT_STRING);
		$this->source_epoch_result=[
			'version'=>1,
			'enabled'=>true,
			'evaluated'=>true,
			'stable'=>$changed===[] && $added===[] && $removed===[],
			'activation'=>$this->sourceEpochActivation(),
			'inventory'=>'coverage-lane-manifest',
			'algorithm'=>'sha256',
			'before_fingerprint'=>$before['fingerprint'],
			'after_fingerprint'=>$after['fingerprint'],
			'before_file_count'=>$before['file_count'],
			'after_file_count'=>$after['file_count'],
			'changed_paths'=>$changed,
			'added_paths'=>$added,
			'removed_paths'=>$removed,
		];
	}

	/** @return array<string,mixed> */
	private function sourceEpochMetadata(): array {
		if($this->source_epoch_result!==null){
			return $this->source_epoch_result;
		}
		$activation=$this->sourceEpochActivation();
		return [
			'version'=>1,
			'enabled'=>$activation!==null,
			'evaluated'=>false,
			'stable'=>null,
			'activation'=>$activation,
			'inventory'=>'coverage-lane-manifest',
			'algorithm'=>'sha256',
			'before_fingerprint'=>$this->source_epoch_before['fingerprint'] ?? null,
			'after_fingerprint'=>null,
			'before_file_count'=>$this->source_epoch_before['file_count'] ?? 0,
			'after_file_count'=>0,
			'changed_paths'=>[],
			'added_paths'=>[],
			'removed_paths'=>[],
		];
	}

	/** @return array{fingerprint:string,file_count:int,files:array<string,string>} */
	private function sourceEpochSnapshot(): array {
		$files=[];
		foreach($this->coverageSourceCandidates() as $absolute){
			$files[$this->sourceEpochRelativePath($absolute)]=$this->sourceEpochContentHash($absolute);
		}
		ksort($files, SORT_STRING);
		$manifest='';
		foreach($files as $relative=>$hash){
			$manifest.=strlen($relative).':'.$relative."\0".$hash."\n";
		}
		return ['fingerprint'=>hash('sha256', $manifest), 'file_count'=>count($files), 'files'=>$files];
	}

	private function sourceEpochRelativePath(string $absolute): string {
		$absolute=str_replace('\\', '/', $absolute);
		$framework_prefix=rtrim(str_replace('\\', '/', $this->framework_root), '/').'/';
		return str_starts_with($absolute, $framework_prefix)
			? substr($absolute, strlen($framework_prefix))
			: $this->relativePath($absolute);
	}

	private function sourceEpochContentHash(string $absolute): string {
		$hash=@hash_file('sha256', $absolute);
		return is_string($hash) ? $hash : '!unreadable';
	}

	/** @param array<string,mixed> $metadata */
	private function sourceEpochChangeSummary(array $metadata, int $limit=8): string {
		$parts=[];
		foreach(['changed_paths'=>'changed', 'added_paths'=>'added', 'removed_paths'=>'removed'] as $key=>$label){
			$paths=array_values(array_map('strval', is_array($metadata[$key] ?? null) ? $metadata[$key] : []));
			if($paths===[]){continue;}
			$shown=array_slice($paths, 0, max(1, $limit));
			$remaining=count($paths)-count($shown);
			$parts[]=$label.'='.implode(', ', $shown).($remaining>0 ? ' (+'.$remaining.' more)' : '');
		}
		return $parts!==[] ? implode('; ', $parts) : 'inventory fingerprint changed without a path-level delta';
	}

	/** @return array<int,string> */
	private function coverageSourceInventory(): array {
		$files=[];
		foreach($this->coverageSourceCandidates() as $absolute){
			$relative=$this->relativePath($absolute);
			if(!$this->coverageSourceExcluded($relative)){$files[$absolute]=true;}
		}
		$files=array_keys($files);
		sort($files);
		return $files;
	}

	/** @return list<string> */
	private function coverageSourceRoots(): array {
		$explicit=$this->explicitCoverageSourceRoots();
		if($explicit!==[]){return $explicit;}
		$owner=trim((string)($this->options['owner'] ?? ''));
		$owner_root=$owner!=='' ? $this->framework_root.'/runtime/modules/'.$owner : '';
		return [$owner_root!=='' && is_dir($owner_root) ? $owner_root : $this->framework_root.'/runtime'];
	}

	/**
	 * Resolves CLI roots once into native absolute protocol paths.
	 *
	 * Embedded hosts naturally address their own application tree from the host
	 * workspace. Historical framework-relative values such as runtime/modules/*
	 * continue to fall back to framework_root when no host path exists.
	 *
	 * @return list<string>
	 */
	private function explicitCoverageSourceRoots(): array {
		if($this->explicit_coverage_source_roots!==null){return $this->explicit_coverage_source_roots;}
		$roots=[];
		foreach($this->optionList('coverage-source') as $path){
			$path=\Dataphyre\Test\PathSemantics::normalize($path);
			if($path===''){continue;}
			if(\Dataphyre\Test\PathSemantics::isAbsolute($path)){
				$candidate=$path;
			}else{
				$host_candidate=\Dataphyre\Test\PathSemantics::resolve($this->root, $path);
				$candidate=file_exists($host_candidate)
					? $host_candidate
					: \Dataphyre\Test\PathSemantics::resolve($this->framework_root, $path);
			}
			$resolved=realpath($candidate);
			$candidate=str_replace('\\', '/', rtrim(is_string($resolved) ? $resolved : $candidate, '/\\'));
			if($candidate!==''){$roots[$candidate]=true;}
		}
		return $this->explicit_coverage_source_roots=array_keys($roots);
	}

	/** @param list<string> $roots */
	private function coverageFileWithinRoots(string $file, array $roots): bool {
		$resolved=realpath($file);
		$file=str_replace('\\', '/', is_string($resolved) ? $resolved : $file);
		$file_compare=strtolower(rtrim($file, '/'));
		foreach($roots as $root){
			$resolved_root=realpath($root);
			$root=str_replace('\\', '/', rtrim(is_string($resolved_root) ? $resolved_root : $root, '/\\'));
			if($root===''){continue;}
			$root_compare=strtolower($root);
			if(is_file($root) || str_ends_with(strtolower($root), '.php')){
				if($file_compare===$root_compare){return true;}
				continue;
			}
			if($file_compare===$root_compare || str_starts_with($file_compare, $root_compare.'/')){return true;}
		}
		return false;
	}

	/** @return list<string> */
	private function coverageSourceCandidates(): array {
		$files=[];
		foreach($this->coverageSourceRoots() as $root){
			if(is_file($root) && str_ends_with(strtolower($root), '.php')){
				$candidates=[$root];
			}
			elseif(is_dir($root))
			{
				$candidates=[];
				$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
				foreach($iterator as $entry){
					if($entry instanceof SplFileInfo && $entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.php')){
						$candidates[]=$entry->getPathname();
					}
				}
			}
			else
			{
				continue;
			}
			foreach($candidates as $candidate){
				$absolute=str_replace('\\', '/', (string)(realpath($candidate) ?: $candidate));
				$files[$absolute]=true;
			}
		}
		$files=array_keys($files);
		sort($files);
		return $files;
	}

	private function coverageSourceExcluded(string $relative): bool {
		$relative=\Dataphyre\Test\CoverageLanes::canonicalPath($relative);
		if(!(bool)\Dataphyre\Test\CoverageLanes::assign($relative)['line_coverage']){return true;}
		foreach($this->optionList('coverage-exclude') as $pattern){
			$pattern=str_replace('\\', '/', trim($pattern));
			if($pattern!=='' && (str_contains($relative, $pattern) || (function_exists('fnmatch') && fnmatch($pattern, $relative)))){
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array{target:string,reason:string}> */
	private function coverageSourceExclusions(): array {
		$exclusions=[];
		foreach(\Dataphyre\Test\CoverageLanes::exclusionRules() as $rule){
			$exclusions[]=['target'=>$rule['target'],'reason'=>$rule['reason']];
		}
		foreach($this->optionList('coverage-exclude') as $pattern){
			$exclusions[]=['target'=>$pattern, 'reason'=>'explicit CLI exclusion'];
		}
		return $exclusions;
	}

	/** @return array<string,mixed> */
	private function coverageLaneSummary(): array {
		$definitions=\Dataphyre\Test\CoverageLanes::definitions();
		$lanes=[];
		foreach($definitions as $lane=>$definition){$lanes[$lane]=$definition+['file_count'=>0];}
		$lineFiles=0;$contractFiles=0;
		foreach($this->coverageSourceCandidates() as $absolute){
			$assignment=\Dataphyre\Test\CoverageLanes::assign($this->relativePath($absolute));
			$lane=$assignment['lane'];
			$lanes[$lane]['file_count']++;
			if($assignment['line_coverage']){$lineFiles++;}else{$contractFiles++;}
		}
		return [
			'version'=>\Dataphyre\Test\CoverageLanes::VERSION,
			'assignment_complete'=>true,
			'source_file_count'=>$lineFiles+$contractFiles,
			'line_coverage_file_count'=>$lineFiles,
			'contract_file_count'=>$contractFiles,
			'lanes'=>$lanes,
		];
	}

	private function coverageAbsolutePath(string $file): string {
		$file=str_replace('\\', '/', trim($file));
		if($file===''){
			return '';
		}
		$candidates=preg_match('#^(?:/|[A-Za-z]:/)#', $file)===1
			? [$file]
			: [$this->root.'/'.ltrim($file, '/'), $this->framework_root.'/'.ltrim($file, '/')];
		foreach($candidates as $candidate){
			$resolved=realpath($candidate);
			if(is_string($resolved)){
				return str_replace('\\', '/', $resolved);
			}
		}
		return str_replace('\\', '/', $candidates[0]);
	}

	/** @return \Generator<int,int> */
	private function coverageRangeLines(string $encoded): \Generator {
		foreach(explode(',', trim($encoded)) as $range){
			$range=trim($range);
			if($range===''){
				continue;
			}
			if(preg_match('/^(\d+)-(\d+)$/', $range, $matches)===1){
				$start=(int)$matches[1];
				$end=(int)$matches[2];
				if($end<$start){
					continue;
				}
				for($line=$start; $line<=$end; $line++){
					yield $line;
				}
				continue;
			}
			if(ctype_digit($range)){
				yield (int)$range;
			}
		}
	}

	/** @param array<int, array<string, mixed>> $results @return array<int, string> */
	private function coveragePolicyFailures(array $results): array {
		if(!$this->coverageEnabled()){
			return [];
		}
		$summary=$this->coverageSummary($results);
		$failures=[];
		$min_files=(int)($this->options['coverage-min-files'] ?? 0);
		$coverage_file_count=array_intersect(['xdebug', 'phpdbg'], (array)$summary['engines'])!==[]
			? (int)$summary['line_file_count']
			: (int)$summary['included_file_count'];
		if($min_files>0 && $coverage_file_count<$min_files){
			$failures[]='coverage-min-files';
		}
		$min_percent=(float)($this->options['coverage-min-percent'] ?? 0);
		if($min_percent>0){
			$executable_lines=(int)$summary['executable_lines'];
			$covered_lines=(int)$summary['covered_lines'];
			if($executable_lines===0){
				$failures[]='coverage-line-engine-missing';
			}
			elseif(
				($min_percent>=100.0 && ($summary['line_coverage_complete'] ?? false)!==true)
				|| (($covered_lines/$executable_lines)*100)<$min_percent
			)
			{
				$failures[]='coverage-min-percent';
			}
		}
		if($this->optionEnabled('coverage-closed-world') && ($summary['source_inventory_complete'] ?? false)!==true){
			$failures[]='coverage-source-files-missing';
		}
		$require=(string)($this->options['coverage-require'] ?? '');
		if($require!=='' && !in_array($require, (array)$summary['engines'], true)){
			$failures[]='coverage-require-'.$require;
		}
		return $failures;
	}

	/** @param array<string, mixed> $summary @param array<int, array<string, mixed>> $results */
	private function writeJUnitReport(array $summary, array $results, string $path): void {
		$dir=dirname($path);
		if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)){
			throw new RuntimeException('Unable to create JUnit output directory: '.$dir);
		}
		$cases=$this->junitCases($results);
		$failures=0;
		$skipped=0;
		foreach($cases as $case){
			$trace=$case['trace'];
			if($this->junitTraceSkipped($trace)){$skipped++;}
			elseif($case['passed']!==true){$failures++;}
		}
		$xml=[];
		$xml[]='<?xml version="1.0" encoding="UTF-8"?>';
		$xml[]='<testsuite name="'.$this->xml($this->display_name.' unit tests').'" tests="'.count($cases).'" assertions="'.(int)$summary['assertions'].'" failures="'.$failures.'" skipped="'.$skipped.'" time="'.$this->xmlNumber((float)$summary['duration_seconds']).'">';
		foreach($cases as $case){
			$result=$case['result'];
			$test=$result['test'] ?? [];
			$trace=$case['trace'];
			$scope=is_array($test) ? (string)($test['scope'] ?? 'unknown') : 'unknown';
			$owner=is_array($test) ? (string)($test['owner'] ?? 'unknown') : 'unknown';
			$name=is_array($test) ? (string)($test['case_name'] ?? basename((string)($test['manifest'] ?? 'unit-test'))) : 'unit-test';
			if(is_array($trace) && isset($trace['test_name'])){
				$name=(string)$trace['test_name'];
			}
			$file=is_array($trace) && isset($trace['file']) ? (string)$trace['file'] : (is_array($test) ? (string)($test['manifest'] ?? '') : '');
			$line=is_array($trace) && isset($trace['line']) ? (int)$trace['line'] : 0;
			$time=is_array($trace) && isset($trace['execution_time']) ? (float)$trace['execution_time'] : (float)($result['result']['duration_seconds'] ?? 0.0);
			$attributes=[
				'classname'=>$scope.'.'.$owner,
				'name'=>$name,
				'time'=>$this->xmlNumber($time),
			];
			if($file!==''){
				$attributes['file']=$this->relativePath($file);
			}
			if($line>0){
				$attributes['line']=(string)$line;
			}
			$xml[]='  <testcase'.$this->xmlAttributes($attributes).'>';
			$properties=[];
			if(is_array($trace)){
				foreach(['stable_id', 'layer', 'risk', 'isolation', 'issue_policy', 'output_policy', 'assertion_policy'] as $property){
					$value=$trace[$property] ?? null;
					if(is_scalar($value) && (string)$value!==''){$properties['dataphyre.'.$property]=(string)$value;}
				}
				$contract=$trace['contract'] ?? null;
				if(is_array($contract) && isset($contract['name'])){
					$properties['dataphyre.contract']=(string)$contract['name'];
					$properties['dataphyre.contract_version']=(string)($contract['version'] ?? '1');
				}
				if(isset($trace['repeat_index'], $trace['repeat_total'])){
					$properties['dataphyre.repeat']=(int)$trace['repeat_index'].'/'.(int)$trace['repeat_total'];
				}
				if(isset($trace['through']) && is_array($trace['through']) && $trace['through']!==[]){
					$properties['dataphyre.through']=implode(' -> ', array_map('strval', $trace['through']));
				}
			}
			if($case['outcome_note']!==null){
				$properties['dataphyre.outcome_normalization']=$case['outcome_note'];
			}
			if($properties!==[]){
				$xml[]='    <properties>';
				foreach($properties as $property=>$value){$xml[]='      <property name="'.$this->xml($property).'" value="'.$this->xml($value).'" />';}
				$xml[]='    </properties>';
			}
			if($this->junitTraceSkipped($trace)){
				$xml[]='    <skipped message="'.$this->xml((string)($trace['message'] ?? 'Skipped.')).'" />';
			}
			elseif($case['passed']!==true)
			{
				$message=$this->failureMessage($result, $trace);
				$xml[]='    <failure message="'.$this->xml($message).'">'.$this->xml($this->failureText($result, $trace)).'</failure>';
			}
			$xml[]='  </testcase>';
		}
		$xml[]='</testsuite>';
		file_put_contents($path, implode("\n", $xml)."\n");
	}

	/**
	 * JUnit must agree with the runner's authoritative worker outcome. Legacy adapters can
	 * retain failed diagnostic traces inside a passing expected-failure case; conversely,
	 * a worker-level crash can fail without producing a failed trace.
	 *
	 * @param array<int, array<string, mixed>> $results
	 * @return array<int, array{result:array<string,mixed>,trace:?array<string,mixed>,passed:bool,outcome_note:?string}>
	 */
	private function junitCases(array $results): array {
		$cases=[];
		foreach($results as $result){
			$result_passed=($result['passed'] ?? false)===true;
			$traces=isset($result['result']['trace']) && is_array($result['result']['trace'])
				? array_values(array_filter($result['result']['trace'], 'is_array'))
				: [];
			if($traces===[]){
				$cases[]=['result'=>$result, 'trace'=>null, 'passed'=>$result_passed, 'outcome_note'=>null];
				continue;
			}
			$has_failed_trace=false;
			foreach($traces as $trace){
				$trace_passed=($trace['passed'] ?? false)===true;
				$skipped=$this->junitTraceSkipped($trace);
				$failed_trace=!$skipped && !$trace_passed;
				$has_failed_trace=$has_failed_trace || $failed_trace;
				$cases[]=[
					'result'=>$result,
					'trace'=>$trace,
					'passed'=>$result_passed || $trace_passed,
					'outcome_note'=>$result_passed && $failed_trace
						? 'passing-worker-overrides-non-authoritative-trace'
						: null,
				];
			}
			if(!$result_passed && !$has_failed_trace){
				$cases[]=[
					'result'=>$result,
					'trace'=>null,
					'passed'=>false,
					'outcome_note'=>'worker-failure-without-failing-trace',
				];
			}
		}
		return $cases;
	}

	/** @param array<string, mixed>|null $trace */
	private function junitTraceSkipped(?array $trace): bool {
		return is_array($trace)
			&& (($trace['skipped'] ?? false)===true || ($trace['todo'] ?? false)===true);
	}

	/** @param array<int, array<string, mixed>> $results */
	private function writeGithubAnnotations(array $results): void {
		foreach($results as $result){
			if(($result['passed'] ?? false)===true){
				continue;
			}
			$trace=$this->primaryTrace($result);
			$test=$result['test'] ?? [];
			$file=is_array($trace) && isset($trace['file']) ? (string)$trace['file'] : (is_array($test) ? (string)($test['manifest'] ?? '') : '');
			$line=is_array($trace) && isset($trace['line']) ? (int)$trace['line'] : 0;
			$message=str_replace(["\r", "\n"], [' ', ' '], $this->failureMessage($result, $trace));
			$annotation='::error';
			if($file!==''){
				$annotation.=' file='.$this->relativePath($file);
			}
			if($line>0){
				$annotation.=',line='.$line;
			}
			$annotation.='::'.$message;
			fwrite(STDERR, $annotation."\n");
		}
	}

	/** @param array<string, mixed> $result @return array<string, mixed>|null */
	private function primaryTrace(array $result): ?array {
		if(!isset($result['result']['trace']) || !is_array($result['result']['trace'])){
			return null;
		}
		foreach($result['result']['trace'] as $entry){
			if(is_array($entry)){
				return $entry;
			}
		}
		return null;
	}

	/** @param array<string, mixed>|null $trace */
	private function failureMessage(array $result, ?array $trace): string {
		if(is_array($trace) && isset($trace['message'])){
			return (string)$trace['message'];
		}
		if(isset($result['message'])){
			return (string)$result['message'];
		}
		return 'Unit-test worker failed.';
	}

	/** @param array<string, mixed>|null $trace */
	private function failureText(array $result, ?array $trace): string {
		$lines=[$this->failureMessage($result, $trace)];
		if(is_array($trace) && isset($trace['details']) && is_array($trace['details']) && $trace['details']!==[]){
			$lines[]='details: '.json_encode($trace['details'], JSON_UNESCAPED_SLASHES);
		}
		foreach(['stderr', 'stdout'] as $stream){
			$text=trim((string)($result[$stream] ?? ''));
			if($text!==''){
				$lines[]=$stream.': '.substr($text, 0, 2000);
			}
		}
		return implode("\n", $lines);
	}

	/** @param array<string, string> $attributes */
	private function xmlAttributes(array $attributes): string {
		$text='';
		foreach($attributes as $name=>$value){
			$text.=' '.$name.'="'.$this->xml($value).'"';
		}
		return $text;
	}

	private function xml(string $value): string {
		$escaped=htmlspecialchars($value, ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		return (string)preg_replace(
			'~[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]~u',
			'',
			$escaped
		);
	}

	private function xmlNumber(float $value): string {
		return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
	}

	private function outputPath(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		if($path===''){
			return $this->root.'/unit-tests.junit.xml';
		}
		if(preg_match('#^[A-Za-z]:/#', $path)===1 || str_starts_with($path, '//')){
			return $path;
		}
		return $this->root.'/'.ltrim($path, '/');
	}

	/** @param array<string, mixed> $result @return array{skipped:int,todo:int,assertions:int} */
	private function resultStats(array $result): array {
		$stats=[
			'skipped'=>0,
			'todo'=>0,
			'assertions'=>0,
		];
		if(!isset($result['result']['trace']) || !is_array($result['result']['trace'])){
			return $stats;
		}
		foreach($result['result']['trace'] as $entry){
			if(!is_array($entry)){
				continue;
			}
			if(($entry['skipped'] ?? false)===true){
				$stats['skipped']++;
			}
			if(($entry['todo'] ?? false)===true){
				$stats['todo']++;
			}
			if(isset($entry['assertions']) && is_int($entry['assertions'])){
				$stats['assertions']+=$entry['assertions'];
			}
		}
		return $stats;
	}

	/** @param array<int, array<string, mixed>> $failed @return array<int, array<string, mixed>> */
	private function relativeFailures(array $failed): array {
		$relative=[];
		foreach($failed as $failure){
			$test=$failure['test'] ?? [];
			if(is_array($test) && isset($test['manifest'])){
				$failure['test']=$test+[
					'relative_manifest'=>$this->relativePath((string)$test['manifest']),
				];
			}
			$relative[]=$failure;
		}
		return $relative;
	}

	/** @param array<int, array<string, mixed>> $cases @return array<int, array<string, mixed>> */
	private function relativeCodeCases(array $cases): array {
		foreach($cases as $index=>$case){
			if(isset($case['file'])){
				$cases[$index]['file']=$this->relativePath((string)$case['file']);
			}
		}
		return $cases;
	}

	/** @return array<string, mixed> */
	private function testRecord(string $scope, string $owner, string $manifest, string $kind, int $cases, ?string $app_root=null): array {
		return [
			'scope'=>$scope,
			'owner'=>$owner,
			'manifest'=>$manifest,
			'kind'=>$kind,
			'cases'=>$cases,
			'app_root'=>$app_root,
		];
	}

	/** @param array<int, array<string, mixed>> $tests @return array<int, array<string, mixed>> */
	private function expandExecutionUnits(array $tests): array {
		$units=[];
		$isolate=(string)($this->options['isolate'] ?? 'auto');
		if(!in_array($isolate, ['auto', 'case', 'file'], true)){
			throw new RuntimeException('Invalid --isolate value. Use auto, case, or file.');
		}
		foreach($tests as $test){
			if($test['kind']==='code'){
				$cases=$this->runnableCodeCases($test);
				$has_dependencies=array_filter($cases, static fn(array $case): bool=>isset($case['dependencies']) && is_array($case['dependencies']) && $case['dependencies']!==[])!==[];
				if($cases===[]){continue;}
				if($has_dependencies || $isolate==='case'){
					$reason=$has_dependencies ? 'declared dependencies require independently scheduled cases' : 'strict case isolation requested by CLI';
					foreach($cases as $case){$units[]=$this->caseExecutionUnit($test, $case, $reason);}
					continue;
				}
				if($isolate==='file'){
					$units[]=$this->fileExecutionUnit($test, $cases, false, 'strict file isolation requested by CLI');
					continue;
				}
				$strict_file=[];
				$strict_case=[];
				$implicit=[];
				foreach($cases as $case){
					if(($case['isolation_explicit'] ?? false)!==true){
						$implicit[]=$case;
						continue;
					}
					if(in_array((string)($case['isolation'] ?? 'case'), ['file', 'shared'], true)){$strict_file[]=$case;}
					else{$strict_case[]=$case;}
				}
				if($strict_file!==[]){$units[]=$this->fileExecutionUnit($test, $strict_file, false, 'file lifecycle explicitly declared by test metadata');}
				foreach($strict_case as $case){$units[]=$this->caseExecutionUnit($test, $case, 'case or process lifecycle explicitly declared by test metadata');}
				if($implicit!==[]){
					$quarantine=$this->isAdaptiveQuarantined($test);
					if(count($implicit)>1 && $quarantine===null){
						$this->adaptive_speculative_files++;
						$this->adaptive_isolation_decisions[]=[
							'manifest'=>$this->relativePath((string)$test['manifest']),
							'decision'=>'speculative-file',
							'cases'=>count($implicit),
							'reason'=>'dependency-free cases have no explicit lifecycle declaration',
						];
						$units[]=$this->fileExecutionUnit($test, $implicit, true, 'adaptive speculative file batch for unannotated cases');
					}
					else
					{
						$reason=$quarantine!==null
							? 'current file fingerprint previously failed speculative file lifecycle and passed case retries'
							: 'a single unannotated case has no useful file-batching opportunity';
						if($quarantine!==null){
							$this->adaptive_quarantined_files++;
							$this->adaptive_isolation_decisions[]=[
								'manifest'=>$this->relativePath((string)$test['manifest']),
								'decision'=>'case-from-history',
								'cases'=>count($implicit),
								'reason'=>(string)($quarantine['reason'] ?? $reason),
							];
						}
						foreach($implicit as $case){$units[]=$this->caseExecutionUnit($test, $case, $reason);}
					}
				}
				continue;
			}
			if($test['scope']==='framework' && $test['kind']==='dpanel' && in_array($isolate, ['auto', 'case'], true)){
				for($index=0; $index<(int)$test['cases']; $index++){
					if(!$this->caseIndexMatches($index)){
						continue;
					}
					$unit=$test;
					$unit['case_index']=$index;
					$unit['cases']=1;
					$units[]=$unit;
				}
				continue;
			}
			$units[]=$test;
		}
		$this->validateDependencyGraph($units);
		return $units;
	}

	/** @param array<string,mixed> $test @param array<int,array<string,mixed>> $cases @return array<string,mixed> */
	private function fileExecutionUnit(array $test, array $cases, bool $adaptive, string $reason): array {
		$unit=$test;
		$unit['case_indexes']=array_values(array_map(static fn(array $case): int=>(int)$case['index'], $cases));
		$unit['case_names']=array_values(array_map(static fn(array $case): string=>(string)$case['name'], $cases));
		$unit['case_stable_ids']=array_values(array_map(static fn(array $case): string=>(string)($case['stable_id'] ?? ''), $cases));
		$unit['cases']=count($cases);
		$unit['isolation']='file';
		$unit['isolation_reason']=$reason;
		$unit['requested_isolations']=array_values(array_unique(array_map(static fn(array $case): string=>(string)($case['isolation'] ?? 'case'), $cases)));
		$declared_sandboxes=[];
		$worker_timeout_seconds=0;
		foreach($cases as $case){
			$declared_sandboxes=array_merge($declared_sandboxes, is_array($case['rootpath_sandboxes'] ?? null) ? $case['rootpath_sandboxes'] : []);
			$worker_timeout_seconds+=$this->caseWorkerTimeoutSeconds($case);
		}
		$unit['rootpath_sandboxes']=$this->rootpathSandboxKeys(['rootpath_sandboxes'=>$declared_sandboxes]);
		$unit['worker_timeout_seconds']=max(1,$worker_timeout_seconds);
		$unit['memory_limit']=$this->largestCaseMemoryLimit($cases);
		$unit['coverage_memory_limit']=$this->largestCaseMemoryLimit($cases, 'coverage_memory_limit');
		$unit['adaptive_speculative']=$adaptive;
		if($adaptive){
			$unit['adaptive_cases']=array_values($cases);
			$unit['adaptive_fingerprint']=$this->codeCaseFingerprint($test);
		}
		return $unit;
	}

	/** @param array<string,mixed> $test @param array<string,mixed> $case @return array<string,mixed> */
	private function caseExecutionUnit(array $test, array $case, string $reason): array {
		$unit=$test;
		foreach(['case_indexes', 'case_names', 'case_stable_ids', 'requested_isolations', 'adaptive_cases', 'adaptive_fingerprint'] as $field){unset($unit[$field]);}
		$unit['adaptive_speculative']=false;
		$unit['case_index']=(int)$case['index'];
		$unit['case_name']=(string)$case['name'];
		$unit['case_base_name']=(string)($case['base_name'] ?? $case['name']);
		$unit['case_dependencies']=isset($case['dependencies']) && is_array($case['dependencies']) ? array_values(array_map('strval', $case['dependencies'])) : [];
		$unit['case_order']=(int)($case['order'] ?? 0);
		$unit['case_stable_id']=(string)($case['stable_id'] ?? '');
		$unit['contract']=$case['contract'] ?? null;
		$unit['layer']=$case['layer'] ?? null;
		$unit['risk']=$case['risk'] ?? null;
		$unit['watches']=isset($case['watches']) && is_array($case['watches']) ? array_values(array_map('strval', $case['watches'])) : [];
		$unit['through']=isset($case['through']) && is_array($case['through']) ? array_values(array_map('strval', $case['through'])) : [];
		$unit['rootpath_sandboxes']=$this->rootpathSandboxKeys($case);
		$unit['memory_limit']=isset($case['memory_limit']) && is_string($case['memory_limit']) && trim($case['memory_limit'])!==''
			? strtoupper(trim($case['memory_limit']))
			: null;
		$unit['coverage_memory_limit']=isset($case['coverage_memory_limit']) && is_string($case['coverage_memory_limit']) && trim($case['coverage_memory_limit'])!==''
			? strtoupper(trim($case['coverage_memory_limit']))
			: null;
		$unit['worker_timeout_seconds']=$this->caseWorkerTimeoutSeconds($case);
		$unit['repeat_index']=(int)($case['repeat_index'] ?? 1);
		$unit['repeat_total']=(int)($case['repeat_total'] ?? 1);
		$unit['isolation_explicit']=($case['isolation_explicit'] ?? false)===true;
		$unit['cases']=1;
		$unit['isolation']=in_array((string)($case['isolation'] ?? 'case'), ['process', 'case'], true) ? (string)($case['isolation'] ?? 'case') : 'case';
		$unit['isolation_reason']=$reason;
		return $unit;
	}

	/** @param array<int, array<string, mixed>> $units */
	private function validateDependencyGraph(array $units): void {
		$nodes=[];
		$edges=[];
		$aliases=[];
		foreach($units as $unit){
			if(($unit['kind'] ?? '')!=='code'){
				continue;
			}
			$name=(string)($unit['case_name'] ?? '');
			if($name===''){
				continue;
			}
			$nodes[$name]=true;
			foreach($this->caseStatusKeys($unit) as $key){
				$aliases[$key][]=$name;
			}
			$edges[$name]=array_values(array_filter(array_map('strval', (array)($unit['case_dependencies'] ?? [])), static fn(string $dependency): bool=>$dependency!==''));
		}
		foreach($edges as $name=>$dependencies){
			foreach($dependencies as $dependency){
				if(!isset($nodes[$dependency]) && !isset($aliases[$dependency])){
					throw new RuntimeException('Code-defined test "'.$name.'" depends on missing test "'.$dependency.'".');
				}
			}
		}
		$visiting=[];
		$visited=[];
		$visit=function(string $node)use(&$visit, &$visiting, &$visited, $edges, $aliases): void {
			if(isset($visited[$node])){
				return;
			}
			if(isset($visiting[$node])){
				throw new RuntimeException('Code-defined test dependency cycle includes "'.$node.'".');
			}
			$visiting[$node]=true;
			foreach($edges[$node] ?? [] as $dependency){
				foreach($aliases[$dependency] ?? [$dependency] as $resolved_dependency){
					$visit($resolved_dependency);
				}
			}
			unset($visiting[$node]);
			$visited[$node]=true;
		};
		foreach(array_keys($edges) as $node){
			$visit($node);
		}
	}

	/** @param array<string, mixed> $test @return array<int, array<string, mixed>> */
	private function runnableCodeCases(array $test): array {
		$cases=$this->codeCases($test);
		$only=array_values(array_filter($cases, static fn(array $case): bool=>($case['only'] ?? false)===true));
		if($only!==[]){
			if(!$this->optionEnabled('allow-only')){
				throw new RuntimeException('Code-defined tests contain ->only() in '.$this->relativePath((string)$test['manifest']).'. Use --allow-only for a local focused run, or remove the marker before CI.');
			}
			$cases=$only;
		}
		return array_values(array_filter($cases, fn(array $case): bool=>$this->codeCaseMatches($case)));
	}

	/** @param array<string, mixed> $case */
	private function codeCaseMatches(array $case): bool {
		if(!$this->caseIndexMatches((int)$case['index'])){
			return false;
		}
		$id=trim((string)($this->options['id'] ?? ''));
		if($id!=='' && !in_array($id, [(string)($case['stable_id'] ?? ''), (string)($case['base_stable_id'] ?? '')], true)){
			return false;
		}
		$name=(string)($this->options['name'] ?? '');
		if($name!=='' && !$this->textSelectorMatches((string)$case['name'], $name)){
			return false;
		}
		$tags=$this->optionList('tag');
		if($tags!==[]){
			$case_tags=isset($case['tags']) && is_array($case['tags']) ? array_map('strval', $case['tags']) : [];
			foreach($tags as $tag){
				if(!in_array($tag, $case_tags, true)){
					return false;
				}
			}
		}
		$groups=$this->optionList('group');
		if($groups!==[]){
			$case_groups=isset($case['groups']) && is_array($case['groups']) ? array_map('strval', $case['groups']) : [];
			foreach($groups as $group){
				if(!in_array($group, $case_groups, true)){
					return false;
				}
			}
		}
		return true;
	}

	private function caseIndexMatches(int $index): bool {
		$case=(string)($this->options['case'] ?? '');
		return $case==='' || $index===(int)$case;
	}

	private function manifestKind(string $file): string {
		$data=json_decode((string)file_get_contents($file), true);
		if(!is_array($data)){
			return 'invalid';
		}
		if($this->isList($data)){
			return 'dpanel';
		}
		if(isset($data['function'], $data['args'])){
			return 'dpanel_single';
		}
		if(isset($data['entry']) && (isset($data['callable']) || ($data['type'] ?? null)==='php')){
			return 'descriptor';
		}
		return 'invalid';
	}

	private function caseCount(string $file, string $kind): int {
		$data=json_decode((string)file_get_contents($file), true);
		if(!is_array($data)){
			return 0;
		}
		return match($kind){
			'dpanel'=>count($data),
			'dpanel_single', 'descriptor'=>1,
			default=>0,
		};
	}

	/** @param array<string, mixed> $test @return array<int, array<string, mixed>> */
	private function codeCases(array $test): array {
		$key=sha1((string)$test['scope'].'|'.(string)$test['owner'].'|'.(string)$test['manifest'].'|'.(string)($test['app_root'] ?? ''));
		if(isset($this->code_case_cache[$key])){
			return $this->code_case_cache[$key];
		}
		$test_file=(string)$test['manifest'];
		$fingerprint=$this->codeCaseFingerprint($test);
		if(!$this->optionEnabled('no-test-cache')){
			$entry=$this->case_discovery_cache->find($key);
			if($entry!==null && $entry->fingerprint()===$fingerprint){
				$this->code_case_cache[$key]=$entry->cases();
				$this->code_case_cache_hits++;
				return $this->code_case_cache[$key];
			}
		}
		$this->code_case_cache_misses++;
		if(!is_file($this->code_worker_path)){
			throw new RuntimeException('Missing code unit-test worker: '.$this->relativePath($this->code_worker_path));
		}
		$payload_path=$this->temporaryRunFile('payload-code-list-'.sha1($test_file).'.json');
		$result_path=$this->temporaryRunFile('result-code-list-'.sha1($test_file).'.json');
		$payload=[
			'rootpath'=>$this->rootpathFor($test),
			'mode'=>'list',
			'test_file'=>$test_file,
			'manifest_path'=>$test_file,
			'bootstrap_files'=>$this->testBootstrapFiles($test),
			'timeout_seconds'=>(int)($this->options['timeout'] ?? 12),
			'memory_limit'=>(string)($this->options['memory'] ?? '256M'),
			'output_path'=>$result_path,
			'coverage_roots'=>$this->explicitCoverageSourceRoots(),
		];
		file_put_contents($payload_path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		$process=$this->runProcess($this->phpWorkerCommand($this->code_worker_path, $payload_path), (int)$payload['timeout_seconds'] + 5);
		$result=is_file($result_path) ? json_decode((string)file_get_contents($result_path), true) : null;
		@unlink($payload_path);
		@unlink($result_path);
		if(!is_array($result) || ($result['passed'] ?? false)!==true || !isset($result['cases']) || !is_array($result['cases'])){
			$detail=$this->codeCaseDiscoveryFailureDetail($result, $process);
			throw new RuntimeException('Unable to list code-defined unit tests in '.$this->relativePath($test_file).($detail!=='' ? ': '.substr($detail, 0, 600) : '.'));
		}
		$this->code_case_cache[$key]=$result['cases'];
		if(!$this->optionEnabled('no-test-cache')){
			$this->case_discovery_cache->store($key,new \Dataphyre\Test\CaseDiscoveryCacheEntry($fingerprint,$result['cases']));
		}
		return $this->code_case_cache[$key];
	}

	/** @param mixed $result @param array<string,mixed> $process */
	private function codeCaseDiscoveryFailureDetail(mixed $result, array $process): string {
		$details=[];
		if(is_array($result)){
			$trace=$result['trace'] ?? [];
			if(is_array($trace)){
				foreach($trace as $entry){
					if(!is_array($entry)){
						continue;
					}
					$message=trim((string)($entry['message'] ?? ''));
					if($message===''){
						continue;
					}
					$exception=trim((string)($entry['exception'] ?? ''));
					$location=trim((string)($entry['file'] ?? ''));
					$line=(int)($entry['line'] ?? 0);
					$details[]=($exception!=='' ? $exception.': ' : '').$message.($location!=='' ? ' in '.$location.($line>0 ? ':'.$line : '') : '');
				}
			}
			$output=trim((string)($result['output'] ?? ''));
			if($output!==''){
				$details[]='worker output: '.$output;
			}
		}
		foreach(['stderr', 'stdout'] as $channel){
			$output=trim((string)($process[$channel] ?? ''));
			if($output!==''){
				$details[]=$channel.': '.$output;
			}
		}
		return implode(' | ', array_values(array_unique($details)));
	}

	private function codeCaseFingerprint(array $test): string {
		$test_file=(string)$test['manifest'];
		if($this->code_case_runtime_fingerprint===null){
			$tooling_root=dirname($this->code_worker_path);
			$this->code_case_runtime_fingerprint=hash('sha256', implode('|', [
				(string)PHP_VERSION_ID,
				PHP_BINARY,
				is_file($this->code_worker_path) ? (string)hash_file('sha256', $this->code_worker_path) : 'missing-worker',
				$this->testKitSourceFingerprint($tooling_root),
			]));
		}
		$fingerprint_parts=[$this->code_case_runtime_fingerprint, is_file($test_file) ? (string)hash_file('sha256', $test_file) : 'missing-test'];
		foreach($this->testBootstrapFiles($test) as $bootstrap_file){
			$fingerprint_parts[]=$bootstrap_file.':'.(is_file($bootstrap_file) ? (string)hash_file('sha256', $bootstrap_file) : 'missing-bootstrap');
		}
		if($this->codeCaseDependsOnFrameworkSource($test_file)){
			$fingerprint_parts[]='framework-source:'.$this->frameworkDiscoverySourceFingerprint();
		}
		return hash('sha256', implode('|', $fingerprint_parts));
	}

	private function testKitSourceFingerprint(string $tooling_root): string {
		$tooling_root=rtrim(str_replace('\\', '/', $tooling_root), '/');
		$files=[];
		foreach(['bootstrap.php','PhpRuntime.php','TypeInventory.php','PathSemantics.php'] as $file){
			$files[]=$tooling_root.'/'.$file;
		}
		$source_root=$tooling_root.'/TestKit';
		if(is_dir($source_root)){
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_root, FilesystemIterator::SKIP_DOTS));
			foreach($iterator as $entry){
				if($entry instanceof SplFileInfo && $entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.php')){
					$files[]=str_replace('\\', '/', $entry->getPathname());
				}
			}
		}
		sort($files, SORT_STRING);
		$manifest='';
		foreach($files as $file){
			$relative=str_starts_with($file, $tooling_root.'/') ? substr($file, strlen($tooling_root)+1) : $file;
			$hash=is_file($file) ? $this->sourceEpochContentHash($file) : '!missing';
			$manifest.=strlen($relative).':'.$relative."\0".$hash."\n";
		}
		return hash('sha256', $manifest);
	}

	private function codeCaseDependsOnFrameworkSource(string $test_file): bool {
		if(array_key_exists($test_file, $this->framework_source_discovery_dependencies)){
			return $this->framework_source_discovery_dependencies[$test_file];
		}
		$source=is_file($test_file) ? file_get_contents($test_file) : false;
		if(!is_string($source)){
			return $this->framework_source_discovery_dependencies[$test_file]=false;
		}
		if(!str_contains($source, self::FRAMEWORK_SOURCE_DISCOVERY_MARKER)){
			return $this->framework_source_discovery_dependencies[$test_file]=false;
		}
		foreach(token_get_all($source) as $token){
			if(
				is_array($token)
				&& in_array($token[0], [T_COMMENT,T_DOC_COMMENT], true)
				&& str_contains($token[1], self::FRAMEWORK_SOURCE_DISCOVERY_MARKER)
			){
				return $this->framework_source_discovery_dependencies[$test_file]=true;
			}
		}
		return $this->framework_source_discovery_dependencies[$test_file]=false;
	}

	private function frameworkDiscoverySourceFingerprint(): string {
		if($this->framework_discovery_source_fingerprint!==null){
			return $this->framework_discovery_source_fingerprint;
		}
		$modules_root=$this->framework_root.'/runtime/modules';
		if(!is_dir($modules_root)){
			return $this->framework_discovery_source_fingerprint=hash('sha256', '!missing-framework-source');
		}
		$files=[];
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules_root, FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $entry){
			if(!$entry instanceof SplFileInfo || !$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), '.php')){
				continue;
			}
			$absolute=str_replace('\\', '/', (string)(realpath($entry->getPathname()) ?: $entry->getPathname()));
			$relative=$this->relativePath($absolute);
			if($this->coverageSourceExcluded($relative)){
				continue;
			}
			$files[$relative]=$this->sourceEpochContentHash($absolute);
		}
		ksort($files, SORT_STRING);
		$context=hash_init('sha256');
		foreach($files as $relative=>$content_hash){
			hash_update($context, strlen($relative).':'.$relative."\0".$content_hash."\n");
		}
		return $this->framework_discovery_source_fingerprint=hash_final($context);
	}

	/** @param array<string,mixed> $test @return array<int,string> */
	private function testBootstrapFiles(array $test): array {
		$candidates=[];
		if(($test['scope'] ?? '')==='framework'){
			$owner=trim((string)($test['owner'] ?? ''));
			if($owner!=='' && !in_array($owner, ['manifest', 'dataphyre'], true)){
				$candidates[]=$this->framework_root.'/runtime/modules/'.$owner.'/testing/bootstrap.php';
			}
		}
		elseif(($test['scope'] ?? '')==='app' && is_string($test['app_root'] ?? null))
		{
			$candidates[]=rtrim((string)$test['app_root'], '/\\').'/testing/bootstrap.php';
		}
		return array_values(array_filter($candidates, 'is_file'));
	}

	/** @param array<string, mixed> $test */
	private function normalizedManifest(array $test): string {
		if($test['kind']==='dpanel_single'){
			$data=json_decode((string)file_get_contents((string)$test['manifest']), true);
			if(!is_array($data)){
				throw new RuntimeException('Unit-test manifest became unreadable: '.$this->relativePath((string)$test['manifest']));
			}
			$tmp_path=$this->temporaryManifestPath($test);
			file_put_contents($tmp_path, json_encode([$data], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
			return $tmp_path;
		}
		$data=json_decode((string)file_get_contents((string)$test['manifest']), true);
		if(!is_array($data)){
			throw new RuntimeException('Descriptor manifest became unreadable: '.$this->relativePath((string)$test['manifest']));
		}
		$base=dirname((string)$test['manifest']);
		$entry=(string)$data['entry'];
		$entry_path=$this->descriptorEntryPath($base, $entry);
		$callable=(string)($data['callable'] ?? basename($entry, '.php').'_passed');
		$name=(string)($data['name'] ?? basename((string)$test['manifest'], '.json'));
		$tmp_path=$this->temporaryManifestPath($test);
		$case=[[
			'name'=>$name,
			'file'=>$entry_path,
			'function'=>$callable,
			'args'=>[],
			'expected'=>[true],
		]];
		file_put_contents($tmp_path, json_encode($case, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		return $tmp_path;
	}

	/** @param array<string, mixed> $test */
	private function temporaryManifestPath(array $test): string {
		$dataphyre_root=$test['scope']==='app'
			? $this->dataphyreRootForApp((string)$test['app_root'])
			: $this->framework_root.'/';
		$tmp_dir=rtrim($dataphyre_root, '/\\').'/cache/ci-unit-tests';
		if(!is_dir($tmp_dir) && !mkdir($tmp_dir, 0775, true) && !is_dir($tmp_dir)){
			throw new RuntimeException('Unable to create unit-test cache directory.');
		}
		return $tmp_dir.'/'.sha1((string)$test['manifest']).'.json';
	}

	private function descriptorEntryPath(string $base, string $entry): string {
		$entry=\Dataphyre\Test\PathSemantics::normalize($entry);
		if(\Dataphyre\Test\PathSemantics::isAbsolute($entry)){
			return $entry;
		}
		if(str_starts_with($entry, 'applications/') || str_starts_with($entry, 'common/')){
			return $entry;
		}
		return \Dataphyre\Test\PathSemantics::resolve($base, $entry);
	}

	/**
	 * Allocates one disposable, runner-owned directory for every ROOTPATH key
	 * declared by the execution unit.
	 *
	 * @param array<string,mixed> $test
	 * @return array{rootpath:array<string,mixed>,cleanup:list<string>}
	 */
	private function workerRootpath(array $test, string $hash): array {
		$rootpath=$this->rootpathFor($test);
		$cleanup=[];
		foreach($this->rootpathSandboxKeys($test) as $key){
			$suffix=substr(hash('sha256', $hash."\0".$key."\0".bin2hex(random_bytes(8))), 0, 24);
			$directory=$this->temporaryRunDirectory('rootpath-'.$key.'-'.$suffix);
			$resolved=$this->rootpath_sandbox_resolver!==null
				? ($this->rootpath_sandbox_resolver)($directory)
				: realpath($directory);
			if(!is_string($resolved)){
				$this->cleanupTemporaryRunDirectory($directory);
				throw new RuntimeException('Unable to resolve a newly created ROOTPATH sandbox.');
			}
			$resolved=str_replace('\\', '/', rtrim($resolved, '/\\'));
			try{
				$marker=json_encode([
					'format'=>self::ROOTPATH_SANDBOX_FORMAT,
					'rootpath_key'=>$key,
					'root'=>$resolved,
					'run_id'=>$this->run_id,
					'token'=>bin2hex(random_bytes(32)),
				],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
			}catch(JsonException $failure){
				$this->cleanupTemporaryRunDirectory($directory);
				throw new RuntimeException("Unable to encode ROOTPATH['{$key}'] sandbox ownership.",0,$failure);
			}
			$written=$this->rootpath_sandbox_marker_writer!==null
				? ($this->rootpath_sandbox_marker_writer)($resolved.'/'.self::ROOTPATH_SANDBOX_MARKER,$marker)
				: file_put_contents($resolved.'/'.self::ROOTPATH_SANDBOX_MARKER,$marker,LOCK_EX);
			if($written===false){
				$this->cleanupTemporaryRunDirectory($directory);
				throw new RuntimeException("Unable to mark ROOTPATH['{$key}'] as a runner-owned sandbox.");
			}
			$rootpath[$key]=$resolved.'/';
			$cleanup[]=$resolved;
		}
		return ['rootpath'=>$rootpath, 'cleanup'=>$cleanup];
	}

	/** @param array<string,mixed> $metadata @return list<string> */
	private function rootpathSandboxKeys(array $metadata): array {
		$declared=$metadata['rootpath_sandboxes'] ?? [];
		if(!is_array($declared)){
			throw new RuntimeException('Code-defined test rootpath_sandboxes metadata must be a list.');
		}
		$keys=[];
		foreach($declared as $key){
			if(!is_string($key)){
				throw new RuntimeException('Code-defined test ROOTPATH sandbox keys must be strings.');
			}
			$key=trim($key);
			if(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)!==1){
				throw new RuntimeException('Code-defined test ROOTPATH sandbox keys must be PHP-style identifiers.');
			}
			if(in_array($key, self::PROTECTED_ROOTPATH_KEYS, true)){
				throw new RuntimeException("ROOTPATH['{$key}'] is immutable and cannot be replaced by a test sandbox.");
			}
			if(!in_array($key, $keys, true)){
				$keys[]=$key;
			}
		}
		return $keys;
	}

	/** @param array<int,array<string,mixed>> $cases */
	private function largestCaseMemoryLimit(array $cases, string $field='memory_limit'): ?string {
		$largest=null;
		$largest_bytes=0;
		foreach($cases as $case){
			$limit=isset($case[$field]) && is_string($case[$field])
				? strtoupper(trim($case[$field]))
				: '';
			if($limit===''){
				continue;
			}
			$bytes=$this->memoryLimitBytes($limit);
			if($bytes>$largest_bytes){
				$largest=$limit;
				$largest_bytes=$bytes;
			}
		}
		return $largest;
	}

	/** @param array<string,mixed> $case */
	private function caseWorkerTimeoutSeconds(array $case): int {
		$default=max(1,(int)($this->options['timeout'] ?? 12));
		$max_millis=(int)($case['max_millis'] ?? 0);
		if($max_millis<=0){return $default;}
		return max($default,(int)ceil($max_millis/1000)+1);
	}

	private function memoryLimitBytes(string $limit): int {
		$limit=strtoupper(trim($limit));
		if(preg_match('/^[1-9][0-9]*[KMG]?$/', $limit)!==1){
			throw new RuntimeException('Code-defined test memory_limit metadata must be a positive PHP byte value.');
		}
		$value=(int)$limit;
		return match(substr($limit, -1)){
			'K'=>$value * 1024,
			'M'=>$value * 1024 * 1024,
			'G'=>$value * 1024 * 1024 * 1024,
			default=>$value,
		};
	}

	/** @param array<string,mixed> $test */
	private function workerMemoryLimit(array $test): string {
		if(array_key_exists('memory', $this->options)){
			return (string)$this->options['memory'];
		}
		if($this->coverageEnabled()){
			$coverage_declared=isset($test['coverage_memory_limit']) && is_string($test['coverage_memory_limit'])
				? strtoupper(trim($test['coverage_memory_limit']))
				: '';
			if($coverage_declared!==''){
				return $coverage_declared;
			}
			if(array_key_exists('coverage-memory-default', $this->options)){
				$coverage_default=$this->options['coverage-memory-default'];
				if(!is_string($coverage_default)){
					throw new RuntimeException('The --coverage-memory-default option requires a positive PHP byte value.');
				}
				$coverage_default=strtoupper(trim($coverage_default));
				$this->memoryLimitBytes($coverage_default);
				return $coverage_default;
			}
		}
		$declared=isset($test['memory_limit']) && is_string($test['memory_limit'])
			? strtoupper(trim($test['memory_limit']))
			: '';
		return $declared!=='' ? $declared : '256M';
	}

	/** @param array<string, mixed> $test @return array<string, mixed> */
	private function rootpathFor(array $test): array {
		$common_dataphyre=$this->framework_root.'/';
		$paths=[
			'root'=>$this->root.'/',
			'common_root'=>$this->root.'/',
			'app_override_key'=>$this->root.'/.local/app_override_key',
			'applications'=>$this->root.'/applications/',
			'common_dataphyre'=>$common_dataphyre,
			'common_dataphyre_runtime'=>$common_dataphyre.'runtime/',
			'dataphyre'=>$common_dataphyre,
			'app'=>'dataphyre',
			'application_roots'=>[],
		];
		if($test['scope']!=='app'){
			return $paths;
		}
		$app_root=(string)$test['app_root'];
		$name=(string)$test['owner'];
		$paths['root']=$app_root.'/';
		$paths['backend']=$app_root.'/backend/';
		$paths['views']=$app_root.'/views/';
		$paths['themes']=$app_root.'/themes/';
		$paths['dataphyre']=$this->dataphyreRootForApp($app_root);
		$paths['app']=$name;
		$shopiro_root=$this->root.'/applications/shopiro/';
		if(is_dir($shopiro_root)){
			$paths['shopiro_root']=$shopiro_root;
			$paths['shopiro_shared']=$shopiro_root.'shared/';
			$paths['common_debug']=$shopiro_root.'shared/debug/';
			$paths['common_backend']=$shopiro_root.'shared/backend/';
			$paths['common_themes']=$shopiro_root.'shared/themes/';
			if(in_array($name, ['shopirocs'], true)){
				$paths['themes']=$shopiro_root.'shared/themes/';
			}
		}
		return $paths;
	}

	private function dataphyreRootForApp(string $app_root): string {
		foreach([$app_root.'/backend/dataphyre/', $app_root.'/dataphyre/'] as $candidate){
			if(is_dir($candidate)){
				return $candidate;
			}
		}
		return $app_root.'/backend/dataphyre/';
	}

	/** @return array<int, array<string, mixed>> */
	private function applications(): array {
		$data=json_decode((string)file_get_contents($this->registry_path), true);
		if(!is_array($data) || !isset($data['applications']) || !is_array($data['applications'])){
			throw new RuntimeException('Invalid applications/dataphyre.apps.json.');
		}
		return $data['applications'];
	}

	/** @return array<int, string> */
	private function jsonFiles(string $root): array {
		if(!is_dir($root)){
			return [];
		}
		$files=[];
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			$path=str_replace('\\', '/', $file->getPathname());
			if(str_ends_with($path, '.json')){
				$files[]=$path;
			}
		}
		sort($files);
		return $files;
	}

	/** @return array<int, string> */
	private function phpTestFiles(string $root): array {
		if(!is_dir($root)){
			return [];
		}
		$files=[];
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			$path=str_replace('\\', '/', $file->getPathname());
			if(str_ends_with($path, '.test.php')){
				$files[]=$path;
			}
		}
		sort($files);
		return $files;
	}

	private function isMetaOrFixture(string $file): bool {
		$normalized='/'.ltrim(str_replace('\\', '/', $file), '/');
		if(str_contains($normalized, '/unit_tests/fixtures/')){
			return true;
		}
		$base=basename($file);
		if(str_ends_with($base, '.meta.json')){
			return true;
		}
		return str_starts_with($base, 'dpanel_mock_');
	}

	private function moduleName(string $file): string {
		$normalized=str_replace('\\', '/', $file);
		$needle=str_replace('\\', '/', rtrim($this->framework_root, '/\\')).'/runtime/modules/';
		if(!str_starts_with($normalized, $needle)){
			return 'manifest';
		}
		$relative=substr($normalized, strlen($needle));
		return strtok($relative, '/') ?: 'manifest';
	}

	private function frameworkOwner(string $file): string {
		$module=$this->moduleName($file);
		return $module!=='manifest' ? $module : 'dataphyre';
	}

	private function includeDynamic(): bool {
		return $this->optionEnabled('include-dynamic');
	}

	private function wantsJsonTests(): bool {
		$kind=(string)($this->options['kind'] ?? '');
		return $kind==='' || $kind==='json' || in_array($kind, ['dpanel', 'dpanel_single', 'descriptor', 'invalid'], true);
	}

	private function wantsCodeTests(): bool {
		$kind=(string)($this->options['kind'] ?? '');
		return $kind==='' || $kind==='code';
	}

	private function loadFrameworkModules(): bool {
		return $this->optionEnabled('load-framework-modules');
	}

	/** @param array<string, mixed> $test */
	private function shouldLoadFrameworkModule(array $test): bool {
		if($test['scope']!=='framework'){
			return false;
		}
		if($this->loadFrameworkModules()){
			return true;
		}
		$relative=$this->relativePath((string)$test['manifest']);
		return str_ends_with($relative, 'runtime/modules/caspow/unit_tests/verify_payload.json');
	}

	private function countDynamicSkipped(): int {
		if($this->includeDynamic()){
			return 0;
		}
		if((string)($this->options['kind'] ?? '')!==''){
			return 0;
		}
		$count=0;
		$roots=[$this->framework_root];
		if($this->applications_enabled){
			$roots=array_merge($roots, array_map(
			fn(array $app): string=>$this->root.'/'.$this->cleanRelativePath((string)$app['path']),
			$this->applications()
			));
		}
		foreach($roots as $root){
			foreach($this->jsonFiles($root.'/') as $file){
				$normalized=str_replace('\\', '/', $file);
				if(str_contains($normalized, '/unit_tests/dynamic/') && !$this->isMetaOrFixture($file)){
					$count++;
				}
			}
			foreach($this->phpTestFiles($root.'/') as $file){
				$normalized=str_replace('\\', '/', $file);
				if(str_contains($normalized, '/unit_tests/dynamic/')){
					$count++;
				}
			}
		}
		return $count;
	}

	/** @return array<int,string> PHP or phpdbg command for an isolated worker script. */
	private function phpWorkerCommand(string $script, string ...$arguments): array {
		$command=[PHP_BINARY];
		if(PHP_SAPI==='phpdbg' || str_contains(strtolower(basename(PHP_BINARY)), 'phpdbg')){
			$command[]='-qrr';
		}
		$command[]=$script;
		return array_merge($command, $arguments);
	}

	/** @param array<int,string> $command @param array<int,array<int,string>> $descriptor @param array<int,resource> $pipes */
	private function openProcess(array $command, array $descriptor, array &$pipes): mixed {
		$opener=$this->process_runtime['open'] ?? 'proc_open';
		return $opener($command, $descriptor, $pipes, $this->root);
	}

	/** @return array<string,mixed> */
	private function processStatus(mixed $process): array {
		$status=($this->process_runtime['status'] ?? 'proc_get_status')($process);
		return is_array($status) ? $status : [];
	}

	private function terminateProcess(mixed $process): bool {
		return (bool)($this->process_runtime['terminate'] ?? 'proc_terminate')($process);
	}

	private function closeProcess(mixed $process): int {
		return (int)($this->process_runtime['close'] ?? 'proc_close')($process);
	}

	private function processNow(): int {
		return (int)($this->process_runtime['now'] ?? 'time')();
	}

	/** @param array<int, string> $command @return array{exit_code:int, stdout:string, stderr:string, timed_out:bool} */
	private function runProcess(array $command, int $timeout_seconds): array {
		$token=sha1(json_encode($command, JSON_UNESCAPED_SLASHES).'#'.bin2hex(random_bytes(4)));
		$stdout_path=$this->temporaryRunFile('process-stdout-'.$token.'.log');
		$stderr_path=$this->temporaryRunFile('process-stderr-'.$token.'.log');
		$descriptor=[
			0=>['pipe', 'r'],
			1=>['file', $stdout_path, 'w'],
			2=>['file', $stderr_path, 'w'],
		];
		$pipes=[];
		$process=$this->openProcess($command, $descriptor, $pipes);
		if(!is_resource($process)){
			@unlink($stdout_path);
			@unlink($stderr_path);
			throw new RuntimeException('Unable to start unit-test worker.');
		}
		fclose($pipes[0]);
		$started=$this->processNow();
		$timed_out=false;
		$status=[];
		while(true){
			$status=$this->processStatus($process);
			if(($status['running'] ?? false)!==true){
				break;
			}
			if($this->processNow()-$started>$timeout_seconds){
				$timed_out=true;
				$this->terminateProcess($process);
				break;
			}
			usleep(50000);
		}
		$exit=$this->closeProcess($process);
		$observed_exit=(int)($status['exitcode'] ?? -1);
		if($exit===-1 && $observed_exit>=0){$exit=$observed_exit;}
		$stdout=is_file($stdout_path) ? (string)file_get_contents($stdout_path) : '';
		$stderr=is_file($stderr_path) ? (string)file_get_contents($stderr_path) : '';
		@unlink($stdout_path);
		@unlink($stderr_path);
		return [
			'exit_code'=>$timed_out ? 124 : $exit,
			'stdout'=>$stdout,
			'stderr'=>$stderr,
			'timed_out'=>$timed_out,
		];
	}

	private function cleanRelativePath(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		$parts=[];
		foreach(explode('/', $path) as $part){
			if($part==='' || $part==='.'){
				continue;
			}
			if($part==='..'){
				throw new RuntimeException('Relative path traversal is not allowed.');
			}
			$parts[]=$part;
		}
		return implode('/', $parts);
	}

	private function relativePath(string $path): string {
		$normalized=str_replace('\\', '/', $path);
		$root=str_replace('\\', '/', $this->root).'/';
		return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
	}

	/** @param array<mixed> $value */
	private function isList(array $value): bool {
		return array_is_list($value);
	}

	private function optionEnabled(string $name): bool {
		$value=$this->options[$name] ?? false;
		if(is_bool($value)){
			return $value;
		}
		return in_array(strtolower((string)$value), ['1', 'true', 'yes'], true);
	}

	private function coverageEnabled(): bool {
		if(!array_key_exists('coverage', $this->options)){
			return false;
		}
		$value=$this->options['coverage'];
		if(is_bool($value)){
			return $value;
		}
		return !in_array(strtolower((string)$value), ['', '0', 'false', 'no', 'off'], true);
	}

	/** @return array<int, string> */
	private function optionList(string $name): array {
		$value=(string)($this->options[$name] ?? '');
		if($value===''){
			return [];
		}
		$items=[];
		foreach(explode(',', $value) as $item){
			$item=trim($item);
			if($item!==''){
				$items[]=$item;
			}
		}
		return $items;
	}

	private function textSelectorMatches(string $value, string $selector): bool {
		if(strlen($selector)>2 && $selector[0]==='/' && strrpos($selector, '/')!==0){
			$last=strrpos($selector, '/');
			$pattern=substr($selector, 0, $last + 1);
			$flags=substr($selector, $last + 1);
			return @preg_match($pattern.$flags, $value)===1;
		}
		return stripos($value, $selector)!==false;
	}
}

/** @return array<string, mixed> */
function dataphyre_unit_test_options(array $arguments): array {
	$options=[];
	foreach($arguments as $argument){
		if(!str_starts_with($argument, '--')){
			continue;
		}
		$argument=substr($argument, 2);
		if(str_contains($argument, '=')){
			[$key, $value]=explode('=', $argument, 2);
			$options[$key]=$value;
			continue;
		}
		$options[$argument]=true;
	}
	return $options;
}
