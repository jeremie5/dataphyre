<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRegressionReport;
use Dataphyre\Panel\PanelRegressionSuite;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

if(!defined('DATAPHYRE_PANEL_FIELD_CATALOG_EMBEDDED')){
	define('DATAPHYRE_PANEL_FIELD_CATALOG_EMBEDDED', true);
}
if(!defined('DATAPHYRE_PANEL_REGRESSION_EMBEDDED')){
	define('DATAPHYRE_PANEL_REGRESSION_EMBEDDED', true);
}
require_once dirname(__DIR__).'/kernel/panel_field_catalog_check.php';
require_once dirname(__DIR__).'/kernel/panel_regression.php';

test('field catalog kernel audit and every embedded CLI outcome remain executable', static function(Context $t): void {
	$failures=panel_field_catalog_run();
	$t->same([], $failures);

	$probe=[];
	panel_field_catalog_assert(false, 'forced catalog failure', $probe);
	panel_field_catalog_assert(true, 'not recorded', $probe);
	$t->same(['forced catalog failure'], $probe);

	$output=$t->captureOutput(static function() use($t): void {
		$t->same(2, panel_field_catalog_main([], [], 'fpm-fcgi'));
		$t->same(0, panel_field_catalog_main(['catalog', '--help'], [], 'cli'));
		$t->same(0, panel_field_catalog_main(['catalog', '-h'], [], 'cli'));
		$t->same(0, panel_field_catalog_main(['catalog', 'help'], [], 'cli'));
		$t->same(1, panel_field_catalog_main(['catalog'], ['forced catalog failure'], 'cli'));
		$t->same(0, panel_field_catalog_main(['catalog'], [], 'cli'));
		$t->same(0, panel_field_catalog_main(['catalog'], null, 'cli'));
		$t->same(PHP_SAPI==='cli' ? 0 : 2, panel_field_catalog_main(['catalog'], [], null));
	})->output();
	$t->contains('only available from CLI', $output);
	$t->contains('Usage: php runtime/modules/panel/kernel/panel_field_catalog_check.php', $output);
})->tag('panel', 'coverage', 'kernel', 'catalog', 'exact')->maxMillis(10000);

test('regression CLI option parser accepts every form and rejects every invalid contract', static function(Context $t): void {
	$defaults=dp_panel_regression_options(['panel_regression.php']);
	$t->isTrue($defaults['example']);
	$t->same(null, $defaults['suite']);

	$options=dp_panel_regression_options([
		'panel_regression.php', '--help', '--fail-on-skip', '--manifest-only',
		'--suite=fixture.php', '--json=report.json', '--manifest=manifest.json',
	]);
	$t->isTrue($options['help']);
	$t->isTrue($options['fail_on_skip']);
	$t->isTrue($options['manifest_only']);
	$t->same('fixture.php', $options['suite']);
	$t->same('report.json', $options['json']);
	$t->same('manifest.json', $options['manifest']);

	$spaced=dp_panel_regression_options([
		'panel_regression.php', '--suite', 'suite.php', '--json', 'report.json', '--manifest', 'manifest.json',
	]);
	$t->same('suite.php', $spaced['suite']);
	$t->same('report.json', $spaced['json']);
	$t->same('manifest.json', $spaced['manifest']);

	$shortHelp=dp_panel_regression_options(['panel_regression.php', '-h']);
	$t->isTrue($shortHelp['help']);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--suite']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--json']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--manifest']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--unknown']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--example', '--suite=x.php']), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_regression_options(['panel_regression.php', '--manifest-only']), InvalidArgumentException::class);

	$usage=$t->captureOutput(static fn()=>dp_panel_regression_usage())->output();
	$t->contains('--manifest-only', $usage);
})->tag('panel', 'coverage', 'kernel', 'cli', 'options', 'exact')->maxMillis(3000);

test('regression suite loading paths and bootstrap boundaries are deterministic', static function(Context $t): void {
	$t->throws(
		static fn()=>dp_panel_regression_bootstrap($t->tempDirectory('panel-kernel-missing-autoloader')),
		RuntimeException::class
	);
	dp_panel_regression_bootstrap();

	$directory=$t->tempDirectory('panel-kernel-suite-loaders');
	$direct=$directory.'/direct.php';
	$callable=$directory.'/callable.php';
	$invalid=$directory.'/invalid.php';
	$invalidCallable=$directory.'/invalid-callable.php';
	file_put_contents($direct, '<?php return \\Dataphyre\\Panel\\PanelRegressionSuite::make("direct_suite");');
	file_put_contents($callable, '<?php return static fn()=>\\Dataphyre\\Panel\\PanelRegressionSuite::make("callable_suite");');
	file_put_contents($invalid, '<?php return "invalid";');
	file_put_contents($invalidCallable, '<?php return static fn()=>"invalid";');

	$t->same('panel_cli_example', dp_panel_regression_load_suite(['example'=>true])->name());
	$t->same('direct_suite', dp_panel_regression_load_suite(['example'=>false, 'suite'=>$direct])->name());
	$t->same('callable_suite', dp_panel_regression_load_suite(['example'=>false, 'suite'=>$callable])->name());
	$t->throws(static fn()=>dp_panel_regression_load_suite(['example'=>false, 'suite'=>$directory.'/missing.php']), RuntimeException::class);
	$t->throws(static fn()=>dp_panel_regression_load_suite(['example'=>false, 'suite'=>$invalid]), RuntimeException::class);
	$t->throws(static fn()=>dp_panel_regression_load_suite(['example'=>false, 'suite'=>$invalidCallable]), RuntimeException::class);

	$t->same('', dp_panel_regression_resolve_path('  '));
	$t->same('/tmp/report.json', dp_panel_regression_resolve_path('/tmp/report.json'));
	$t->same('C:\\temp\\report.json', dp_panel_regression_resolve_path('C:\\temp\\report.json'));
	$t->same('C:/workspace/report.json', dp_panel_regression_resolve_path('report.json', 'C:/workspace'));
	$t->same('report.json', dp_panel_regression_resolve_path('report.json', false));
	$t->isTrue(is_dir(dp_panel_regression_common_root()));
})->tag('panel', 'coverage', 'kernel', 'suite', 'exact')->maxMillis(5000);

test('regression report writers and main runner cover success failure skip and artifact outcomes', static function(Context $t): void {
	$passed=new PanelRegressionReport('passed', [
		['name'=>'message', 'status'=>'passed', 'message'=>'Ready', 'duration_ms'=>1.25],
		['name'=>'blank', 'status'=>'passed', 'message'=>'', 'duration_ms'=>0],
	], 1.25);
	$failed=new PanelRegressionReport('failed', [['name'=>'failure', 'status'=>'failed', 'message'=>'No', 'duration_ms'=>0]], 0);
	$skipped=new PanelRegressionReport('skipped', [['name'=>'skip', 'status'=>'skipped', 'message'=>'Later', 'duration_ms'=>0]], 0);
	$t->same(0, dp_panel_regression_exit_code($passed, false));
	$t->same(1, dp_panel_regression_exit_code($failed, false));
	$t->same(0, dp_panel_regression_exit_code($skipped, false));
	$t->same(1, dp_panel_regression_exit_code($skipped, true));

	$printed=$t->captureOutput(static fn()=>dp_panel_regression_print_report($passed))->output();
	$t->contains('[PASSED] message', $printed);
	$t->contains('Summary: 2 checks', $printed);

	$directory=$t->tempDirectory('panel-kernel-artifacts');
	$json=$directory.'/nested/report.json';
	$manifest=$directory.'/nested/manifest.json';
	$suite=PanelRegressionSuite::make('writer_suite')->check('pass', static fn(): string=>'ok');
	dp_panel_regression_write_json($json, $passed);
	dp_panel_regression_write_manifest($manifest, $suite, ['release'=>'coverage']);
	$t->isTrue(is_file($json));
	$t->isTrue(is_file($manifest));
	$t->throws(static fn()=>dp_panel_regression_write_json('', $passed), RuntimeException::class);
	$t->throws(static fn()=>dp_panel_regression_write_manifest('', $suite), RuntimeException::class);

	$resource=fopen('php://memory', 'r');
	try{
		$invalidReport=new PanelRegressionReport('invalid_json', [], 0, ['resource'=>$resource]);
		$invalidSuite=PanelRegressionSuite::make('invalid_manifest')->meta('resource', $resource);
		$t->throws(static fn()=>dp_panel_regression_write_json($directory.'/invalid-report.json', $invalidReport), RuntimeException::class);
		$t->throws(static fn()=>dp_panel_regression_write_manifest($directory.'/invalid-manifest.json', $invalidSuite), RuntimeException::class);
	}
	finally{
		fclose($resource);
	}

	$blocker=$directory.'/blocker';
	file_put_contents($blocker, 'not a directory');
	set_error_handler(static fn(): bool=>true);
	try{
		$t->throws(static fn()=>dp_panel_regression_write_json($blocker.'/report.json', $passed), RuntimeException::class);
		$t->throws(static fn()=>dp_panel_regression_write_manifest($blocker.'/manifest.json', $suite), RuntimeException::class);
		$t->throws(static fn()=>dp_panel_regression_write_json($directory, $passed), RuntimeException::class);
		$t->throws(static fn()=>dp_panel_regression_write_manifest($directory, $suite), RuntimeException::class);
	}
	finally{
		restore_error_handler();
	}

	$failedSuite=$directory.'/failed-suite.php';
	$skippedSuite=$directory.'/skipped-suite.php';
	file_put_contents($failedSuite, '<?php return \\Dataphyre\\Panel\\PanelRegressionSuite::make("failed")->check("failure", static fn()=>false);');
	file_put_contents($skippedSuite, '<?php return \\Dataphyre\\Panel\\PanelRegressionSuite::make("skipped")->skip("skip", "Later");');
	$mainOutput=$t->captureOutput(static function() use($t,$directory,$failedSuite,$skippedSuite): void {
		$t->same(2, dp_panel_regression_main(['panel_regression.php'], 'fpm-fcgi'));
		$t->same(0, dp_panel_regression_main(['panel_regression.php', '--help'], 'cli'));
		$t->same(2, dp_panel_regression_main(['panel_regression.php', '--unknown'], 'cli'));
		$t->same(0, dp_panel_regression_main(['panel_regression.php', '--example'], 'cli'));
		$t->same(0, dp_panel_regression_main(['panel_regression.php', '--example', '--json', $directory.'/main-report.json'], 'cli'));
		$t->same(0, dp_panel_regression_main(['panel_regression.php', '--example', '--manifest', $directory.'/main-manifest.json', '--manifest-only'], 'cli'));
		$t->same(2, dp_panel_regression_main(['panel_regression.php', '--example', '--manifest', $directory], 'cli'));
		$t->same(2, dp_panel_regression_main(['panel_regression.php', '--example', '--json', $directory], 'cli'));
		$t->same(1, dp_panel_regression_main(['panel_regression.php', '--suite', $failedSuite], 'cli'));
		$t->same(0, dp_panel_regression_main(['panel_regression.php', '--suite', $skippedSuite], 'cli'));
		$t->same(1, dp_panel_regression_main(['panel_regression.php', '--suite', $skippedSuite, '--fail-on-skip'], 'cli'));
	})->output();
	$t->contains('only available from CLI', $mainOutput);
	$t->contains('Summary:', $mainOutput);
})->tag('panel', 'coverage', 'kernel', 'artifacts', 'exact')->maxMillis(10000);
