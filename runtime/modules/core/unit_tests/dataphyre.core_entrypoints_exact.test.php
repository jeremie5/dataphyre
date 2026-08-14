<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Core compatibility entrypoints')
	->contract('core.entrypoints', 1)
	->layer('integration')
	->risk('high')
	->watches('module:core')
	->through('legacy-wrappers', 'kernel-bootstrap')
	->isolation('process')
	->tag('core', 'entrypoint', 'exact-coverage')
	->group('framework-coverage');

test('legacy global wrappers preserve arguments and return the core service result', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/core_global_wrappers_probe.php',
		[dirname(__DIR__).'/kernel/core.global.php'],
		framework_root:$root,
	))->json();
	$t->same(['encrypt','plain',['scope']], $result['encrypt']);
	$t->same(['decrypt','cipher',['scope']], $result['decrypt']);
	$t->same(['storage','10MB'], $result['storage']);
	$t->same(['config','core/timezone'], $result['config']);
});

test('kernel bootstrap publishes the complete application runtime surface', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/core_kernel_bootstrap_probe.php',
		[dirname(__DIR__).'/kernel/bootstrap.php'],
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'application_definition'=>true,
		'app_locator'=>true,
		'autoloader'=>true,
		'runtime'=>true,
	], $result);
});

test('core main executes the diagnostic lifecycle through its real bootstrap entrypoint', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/core_main_diagnostic_probe.php',
		[dirname(__DIR__).'/kernel/core.main.php'],
		framework_root:$root,
	));
	$t->hasPathValues([
		'core_loaded'=>true,
		'diagnostic_loaded'=>true,
		'run_mode'=>'diagnostic',
	], $result->json());
});

test('core config tracing executes only through the configured trace boundary', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/core_functions_trace_config_probe.php',
		[dirname(__DIR__).'/kernel/core_functions.php'],
		framework_root:$root,
	))->json();
	$t->same('traced-value', $result['value']);
	$t->greaterThan(0, $result['trace_calls']);
});

test('flight-sheet default path resolves from the framework install root without process constants', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flight_sheet_default_path_probe.php',
		[dirname(__DIR__).'/kernel/flight_sheet.php'],
		framework_root:$root,
	))->json();
	$t->same(true, str_ends_with((string)$result['path'], '/flight_sheet.php'));
});
