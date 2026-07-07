<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	if(!function_exists('tracelog')){
		/**
		 * Provides a no-op global trace hook for standalone regression runs.
		 *
		 * The runner can execute before the full Dataphyre kernel is loaded, so
		 * this shim lets bootstrap code call `tracelog()` safely in isolation.
		 *
		 * @param mixed ...$arguments Ignored trace payload.
		 * @return void
		 */
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		/**
		 * Defines module configuration constants during standalone execution.
		 *
		 * This fallback mirrors the kernel helper enough for the regression runner
		 * to load framework bootstrap files without redefining existing constants.
		 *
		 * @param string $module Module name retained for helper compatibility.
		 * @param string $constant Constant name to define.
		 * @param array<string, mixed> $defaults Default configuration payload.
		 * @return void
		 */
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			if(!defined($constant)){
				define($constant, $defaults);
			}
		}
	}
}

namespace dataphyre {
	if(!function_exists(__NAMESPACE__.'\\tracelog')){
		/**
		 * Forwards namespaced trace calls to the global standalone trace hook.
		 *
		 * Panel bootstrap code may call `dataphyre\tracelog()` before the runtime
		 * logger exists, so this bridge keeps CLI regression setup deterministic.
		 *
		 * @param mixed ...$arguments Trace payload forwarded to the global hook.
		 * @return void
		 */
		function tracelog(mixed ...$arguments): void {
			\tracelog(...$arguments);
		}
	}
	if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
		/**
		 * Forwards namespaced module-config definitions to the global shim.
		 *
		 * This preserves the framework bootstrap contract when the runner is
		 * invoked directly from CLI without the core kernel already loaded.
		 *
		 * @param string $module Module name retained for helper compatibility.
		 * @param string $constant Constant name to define.
		 * @param array<string, mixed> $defaults Default configuration payload.
		 * @return void
		 */
		function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
			\dp_define_module_config($module, $constant, $defaults);
		}
	}
}

namespace {
use Dataphyre\Panel\PanelRegressionReport;
use Dataphyre\Panel\PanelRegressionSuite;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelTableState;
use Dataphyre\Panel\PanelTestHarness;

if(PHP_SAPI!=='cli'){
	http_response_code(404);
	echo "Panel regression runner is only available from CLI.\n";
	exit(2);
}

try{
	$options=dp_panel_regression_options($argv ?? []);
	if(isset($options['help'])){
		dp_panel_regression_usage();
		exit(0);
	}
	dp_panel_regression_bootstrap();
	$suite=dp_panel_regression_load_suite($options);
	if(!$suite instanceof PanelRegressionSuite){
		throw new RuntimeException('Suite loader did not return a PanelRegressionSuite instance.');
	}
	if(isset($options['manifest'])){
		dp_panel_regression_write_manifest((string)$options['manifest'], $suite, [
			'runner'=>'panel_regression.php',
			'generated_at'=>date('c'),
		]);
		if(!empty($options['manifest_only'])){
			exit(0);
		}
	}
}
catch(Throwable $exception){
	fwrite(STDERR, '[ERROR] '.$exception->getMessage().PHP_EOL);
	exit(2);
}

$report=$suite->run([
	'runner'=>'panel_regression.php',
	'generated_at'=>date('c'),
]);

dp_panel_regression_print_report($report);

if(isset($options['json'])){
	try{
		dp_panel_regression_write_json((string)$options['json'], $report);
	}
	catch(Throwable $exception){
		fwrite(STDERR, '[ERROR] Unable to write JSON report: '.$exception->getMessage().PHP_EOL);
		exit(2);
	}
}

exit(dp_panel_regression_exit_code($report, !empty($options['fail_on_skip'])));

/**
 * Parses panel regression CLI flags into a normalized option map.
 *
 * The parser supports example-suite runs, external suite files, JSON report
 * output, manifest generation, manifest-only mode, and skip-sensitive exit
 * codes. Missing suite input defaults to the bundled route-free example suite.
 *
 * @param array<int, string> $argv Raw CLI argument vector including script name.
 * @return array{example: bool, suite: ?string, json: ?string, manifest: ?string, manifest_only: bool, fail_on_skip: bool, help?: bool} Parsed options.
 *
 * @throws InvalidArgumentException When an option is unknown, missing its value, or conflicts with another option.
 */
function dp_panel_regression_options(array $argv): array {
	$options=[
		'example'=>false,
		'suite'=>null,
		'json'=>null,
		'manifest'=>null,
		'manifest_only'=>false,
		'fail_on_skip'=>false,
	];
	$arguments=array_values(array_slice($argv, 1));
	for($i=0; $i<count($arguments); $i++){
		$argument=(string)$arguments[$i];
		if($argument==='--help' || $argument==='-h'){
			$options['help']=true;
			continue;
		}
		if($argument==='--example'){
			$options['example']=true;
			continue;
		}
		if($argument==='--fail-on-skip'){
			$options['fail_on_skip']=true;
			continue;
		}
		if($argument==='--manifest-only'){
			$options['manifest_only']=true;
			continue;
		}
		if(str_starts_with($argument, '--suite=')){
			$options['suite']=substr($argument, 8);
			continue;
		}
		if($argument==='--suite'){
			if(!isset($arguments[$i + 1])){
				throw new InvalidArgumentException('--suite requires a path.');
			}
			$options['suite']=(string)($arguments[++$i] ?? '');
			continue;
		}
		if(str_starts_with($argument, '--json=')){
			$options['json']=substr($argument, 7);
			continue;
		}
		if($argument==='--json'){
			if(!isset($arguments[$i + 1])){
				throw new InvalidArgumentException('--json requires a path.');
			}
			$options['json']=(string)($arguments[++$i] ?? '');
			continue;
		}
		if(str_starts_with($argument, '--manifest=')){
			$options['manifest']=substr($argument, 11);
			continue;
		}
		if($argument==='--manifest'){
			if(!isset($arguments[$i + 1])){
				throw new InvalidArgumentException('--manifest requires a path.');
			}
			$options['manifest']=(string)($arguments[++$i] ?? '');
			continue;
		}
		throw new InvalidArgumentException('Unknown option: '.$argument);
	}
	if(!$options['example'] && ($options['suite']===null || trim((string)$options['suite'])==='')){
		$options['example']=true;
	}
	if($options['example'] && $options['suite']!==null){
		throw new InvalidArgumentException('Use either --example or --suite=<path>, not both.');
	}
	if($options['manifest_only'] && ($options['manifest']===null || trim((string)$options['manifest'])==='')){
		throw new InvalidArgumentException('--manifest-only requires --manifest=<path>.');
	}
	return $options;
}

/**
 * Prints the CLI usage line for the panel regression runner.
 *
 * @return void
 */
function dp_panel_regression_usage(): void {
	echo "Usage: php runtime/modules/panel/kernel/panel_regression.php [--example|--suite=<path>] [--json=<path>] [--manifest=<path>] [--manifest-only] [--fail-on-skip]\n";
}

/**
 * Registers the framework autoloader and loads panel regression dependencies.
 *
 * The runner loads Panel unconditionally and Reactor when its framework
 * bootstrap is present, allowing panel checks to exercise reactive behavior
 * without requiring the full web entrypoint.
 *
 * @return void
 *
 * @throws RuntimeException When the Dataphyre autoloader cannot be found.
 */
function dp_panel_regression_bootstrap(): void {
	$modules_root=dirname(__DIR__, 2);
	$autoload=$modules_root.'/core/kernel/autoloader.php';
	if(!is_file($autoload)){
		throw new RuntimeException('Unable to locate Dataphyre autoloader at '.$autoload.'.');
	}
	require_once $autoload;
	\dataphyre\autoloader::register($modules_root);
	$frameworks=['panel'];
	if(is_file($modules_root.'/reactor/Framework/Bootstrap.php')){
		$frameworks[]='reactor';
	}
	\dataphyre\autoloader::register_framework_modules($frameworks);
	require_once $modules_root.'/panel/Framework/Bootstrap.php';
	if(in_array('reactor', $frameworks, true)){
		require_once $modules_root.'/reactor/Framework/Bootstrap.php';
	}
}

/**
 * Loads the regression suite selected by parsed CLI options.
 *
 * Example mode loads the bundled route-free example. Custom suites are resolved from
 * CLI paths and may either return a `PanelRegressionSuite` directly or return a
 * callable that constructs one after bootstrap.
 *
 * @param array{example?: bool, suite?: ?string} $options Parsed runner options.
 * @return PanelRegressionSuite Suite ready to execute.
 *
 * @throws RuntimeException When the suite file is missing or does not produce a suite.
 */
function dp_panel_regression_load_suite(array $options): PanelRegressionSuite {
	if(!empty($options['example'])){
		return dp_panel_regression_load_example_suite();
	}
	$path=dp_panel_regression_resolve_path((string)$options['suite']);
	if($path==='' || !is_file($path)){
		throw new RuntimeException('Suite file not found: '.(string)$options['suite']);
	}
	$suite=require $path;
	if($suite instanceof PanelRegressionSuite){
		return $suite;
	}
	if(is_callable($suite)){
		$suite=$suite();
	}
	if(!$suite instanceof PanelRegressionSuite){
		throw new RuntimeException('Suite file must return PanelRegressionSuite or a callable that returns one.');
	}
	return $suite;
}

/**
 * Loads the bundled route-free regression suite.
 *
 * This suite proves the runner, report writer, manifest writer, and Panel test
 * harness can execute from a clean source tree without a debug application.
 *
 * @return PanelRegressionSuite Bundled example suite.
 *
 * @throws RuntimeException When the suite cannot be built.
 */
function dp_panel_regression_load_example_suite(): PanelRegressionSuite {
	return PanelRegressionSuite::make('panel_cli_example')
		->meta([
			'module'=>'panel',
			'fixture'=>'route_free',
			'deterministic'=>true,
		])
		->check('html result assertions', static function(PanelTestHarness $test): string {
			$result=PanelPageResult::html('<main><h1>Orders</h1><p>Ready</p></main>');
			PanelTestHarness::assertOk($result);
			PanelTestHarness::assertSee($result, 'Orders');
			PanelTestHarness::assertDontSee($result, 'Customers');
			return 'HTML result accepted.';
		}, ['surface'=>'result'])
		->check('table state assertions', static function(): array {
			$state=PanelTableState::make(
				[['id'=>1, 'title'=>'Order 1001'], ['id'=>2, 'title'=>'Order 1002']],
				['id'=>['label'=>'ID'], 'title'=>['label'=>'Title']],
				['title'=>['label'=>'Title']],
				[],
				['total_records'=>2]
			);
			PanelTestHarness::assertTableTotal($state, 2);
			PanelTestHarness::assertTableColumn($state, 'title');
			PanelTestHarness::assertTableColumn($state, 'id', false);
			return ['message'=>'Table state accepted.', 'records'=>$state->totalRecords()];
		}, ['surface'=>'table'])
		->check('form state assertions', static function(): string {
			$valid=PanelFormState::make(['title'=>'Order 1001']);
			$invalid=PanelFormState::make(['title'=>''], ['title'=>'Required']);
			PanelTestHarness::assertFormValid($valid);
			PanelTestHarness::assertFormValue($valid, 'title', 'Order 1001');
			PanelTestHarness::assertFormInvalid($invalid, 'title');
			return 'Form state accepted.';
		}, ['surface'=>'form']);
}

/**
 * Resolves a CLI path relative to the current working directory.
 *
 * Absolute Unix paths and Windows drive paths pass through unchanged; blank
 * values remain blank so callers can reject missing artifact destinations with
 * specific error messages.
 *
 * @param string $path User-supplied path.
 * @return string Absolute or unchanged path suitable for file operations.
 */
function dp_panel_regression_resolve_path(string $path): string {
	$path=trim($path);
	if($path===''){
		return '';
	}
	$normalized=str_replace('\\', '/', $path);
	if(preg_match('/^[A-Za-z]:\//', $normalized)===1 || str_starts_with($normalized, '/')){
		return $path;
	}
	$cwd=getcwd();
	return (is_string($cwd) && $cwd!=='') ? $cwd.'/'.$path : $path;
}

/**
 * Returns the repository common root used to find bundled regression fixtures.
 *
 * @return string Absolute path to the Dataphyre common directory.
 */
function dp_panel_regression_common_root(): string {
	return dirname(__DIR__, 5);
}

/**
 * Prints a human-readable regression report to standard output.
 *
 * Each check is emitted as one status line with duration and optional message,
 * followed by the suite summary used by CI logs and local diagnostics.
 *
 * @param PanelRegressionReport $report Completed regression report.
 * @return void
 */
function dp_panel_regression_print_report(PanelRegressionReport $report): void {
	foreach($report->results() as $result){
		$status=strtoupper((string)($result['status'] ?? 'unknown'));
		$name=(string)($result['name'] ?? 'check');
		$duration=round((float)($result['duration_ms'] ?? 0.0), 3);
		$message=trim((string)($result['message'] ?? ''));
		echo '['.$status.'] '.$name.' ('.$duration.'ms)'.($message!=='' ? ' - '.$message : '').PHP_EOL;
	}
	echo 'Summary: '.$report->summary().PHP_EOL;
}

/**
 * Writes the completed regression report as pretty JSON.
 *
 * Parent directories are created on demand and write/encoding failures are
 * surfaced as runtime exceptions so the CLI exits as infrastructure failure.
 *
 * @param string $path Report destination from CLI.
 * @param PanelRegressionReport $report Completed regression report.
 * @return void
 *
 * @throws RuntimeException When the destination is blank, cannot be created, cannot be encoded, or cannot be written.
 */
function dp_panel_regression_write_json(string $path, PanelRegressionReport $report): void {
	$resolved=dp_panel_regression_resolve_path($path);
	if($resolved===''){
		throw new RuntimeException('JSON path is empty.');
	}
	$directory=dirname($resolved);
	if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
		throw new RuntimeException('Unable to create directory '.$directory.'.');
	}
	$json=json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if(!is_string($json)){
		throw new RuntimeException('Unable to encode report JSON.');
	}
	if(file_put_contents($resolved, $json.PHP_EOL)===false){
		throw new RuntimeException('Unable to write '.$resolved.'.');
	}
}

/**
 * Writes the selected regression suite manifest as pretty JSON.
 *
 * Manifests describe checks before execution and may include runner metadata,
 * allowing automation to inspect coverage without running browser or panel
 * assertions.
 *
 * @param string $path Manifest destination from CLI.
 * @param PanelRegressionSuite $suite Suite to describe.
 * @param array<string, mixed> $meta Additional manifest metadata.
 * @return void
 *
 * @throws RuntimeException When the destination is blank, cannot be created, cannot be encoded, or cannot be written.
 */
function dp_panel_regression_write_manifest(string $path, PanelRegressionSuite $suite, array $meta=[]): void {
	$resolved=dp_panel_regression_resolve_path($path);
	if($resolved===''){
		throw new RuntimeException('Manifest path is empty.');
	}
	$directory=dirname($resolved);
	if(!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)){
		throw new RuntimeException('Unable to create directory '.$directory.'.');
	}
	$json=json_encode($suite->manifest($meta), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if(!is_string($json)){
		throw new RuntimeException('Unable to encode manifest JSON.');
	}
	if(file_put_contents($resolved, $json.PHP_EOL)===false){
		throw new RuntimeException('Unable to write '.$resolved.'.');
	}
}

/**
 * Computes the process exit code for CI and local scripts.
 *
 * Failed checks always exit non-zero. Skipped checks only fail the process when
 * the caller requested strict skip handling.
 *
 * @param PanelRegressionReport $report Completed regression report.
 * @param bool $fail_on_skip Whether skipped checks should fail the run.
 * @return int Zero for success, one for failed or disallowed skipped checks.
 */
function dp_panel_regression_exit_code(PanelRegressionReport $report, bool $fail_on_skip): int {
	if(!$report->ok()){
		return 1;
	}
	if($fail_on_skip && $report->hasSkipped()){
		return 1;
	}
	return 0;
}
}
