<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelCoverageGate;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('coverage gate requires every production source file and every executable line', static function(Context $t): void {
	$workspace=$t->workspace('dp-panel-coverage-gate');
	$root=$workspace->directory('panel');
	$workspace->file('panel/Framework/Alpha.php', "<?php\nreturn true;\n");
	$workspace->file('panel/Framework/README.txt', 'not PHP');
	$workspace->file('panel/kernel/beta.php', "<?php\nreturn false;\n");
	$report=[
		'engines'=>['phpdbg'],
		'line_files'=>[
			$root.'/Framework/Alpha.php'=>['executable'=>1, 'covered'=>1, 'uncovered_lines'=>[]],
			'C:/mirror/runtime/modules/panel/kernel/beta.php'=>['executable'=>1, 'covered'=>1, 'uncovered_lines'=>[]],
		],
	];
	$gate=PanelCoverageGate::fromReport($report, $root);
	$t->isTrue($gate->passed());
	$t->same(2, $gate->sourceFileCount());
	$t->same(2, $gate->coveredLines());
	$t->same(2, $gate->executableLines());
	$t->same(100.0, $gate->coveragePercent());
	$t->same([], $gate->missingFiles());
	$t->same('panel_coverage_gate', $gate->jsonSerialize()['type']);
})->tag('panel', 'coverage', 'quality-gate')->maxMillis(1000);

test('coverage gate rejects included-file reports missing sources and uncovered lines', static function(Context $t): void {
	$workspace=$t->workspace('dp-panel-coverage-gate-fail');
	$root=$workspace->directory('panel');
	$workspace->file('panel/Framework/Alpha.php', "<?php\nreturn true;\n");
	$workspace->file('panel/Framework/Missing.php', "<?php\nreturn false;\n");
	$workspace->directory('panel/kernel');
	$gate=PanelCoverageGate::fromReport([
		'engines'=>['included_files'],
		'line_files'=>[
			'Framework/Alpha.php'=>['executable'=>3, 'covered'=>2, 'uncovered_lines'=>[8, 8, 0]],
			$root.'/Framework/Alpha.php'=>['executable'=>2, 'covered'=>2, 'uncovered_lines'=>[]],
		],
	], $root);
	$t->isFalse($gate->passed());
	$t->same(['Framework/Missing.php'], $gate->missingFiles());
	$t->same([8], $gate->uncoveredFiles()['Framework/Alpha.php']['uncovered_lines']);
	$t->same(['exact_engine_missing', 'source_files_missing', 'uncovered_lines', 'coverage_below_minimum'], array_column($gate->failures(), 'name'));
	$t->same(66.67, $gate->coveragePercent());
})->tag('panel', 'coverage', 'quality-gate', 'failure')->maxMillis(1000);

test('coverage gate validates reports roots engines and source directory containment', static function(Context $t): void {
	$workspace=$t->workspace('dp-panel-coverage-gate-validation');
	$root=$workspace->directory('panel');
	$workspace->directory('panel/Framework');
	$workspace->directory('panel/kernel');
	$coverage=$workspace->file('coverage.json', json_encode(['engines'=>['xdebug'], 'line_files'=>[]], JSON_THROW_ON_ERROR));
	$gate=PanelCoverageGate::fromFile($coverage, $root, ['require_all_sources'=>false, 'minimum_percent'=>0]);
	$t->isTrue($gate->passed());
	$t->throws(static fn()=>PanelCoverageGate::fromFile($workspace->path('missing.json'), $root), InvalidArgumentException::class);
	$invalid=$workspace->file('invalid.json', '{broken');
	$t->throws(static fn()=>PanelCoverageGate::fromFile($invalid, $root), InvalidArgumentException::class);
	$scalar=$workspace->file('scalar.json', 'true');
	$t->throws(static fn()=>PanelCoverageGate::fromFile($scalar, $root), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelCoverageGate::fromReport([], $workspace->path('missing-root')), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelCoverageGate::fromReport(['line_files'=>[]], $root, ['source_directories'=>['../outside']]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelCoverageGate::fromReport(['line_files'=>[]], $root, ['source_directories'=>['missing']]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelCoverageGate::fromReport(['line_files'=>'invalid'], $root), InvalidArgumentException::class);
	$ignored=PanelCoverageGate::fromReport([
		'engines'=>['xdebug'],
		'line_files'=>[
			'not-an-array'=>'ignored',
			'C:/outside/Unrelated.php'=>['executable'=>10, 'covered'=>10],
		],
	], $root, ['require_all_sources'=>false, 'minimum_percent'=>0]);
	$t->isTrue($ignored->passed());
	$t->throws(static fn()=> $t->nonPublic($ignored)->invoke('assertSourceEntry', $workspace->path('outside.php'), false), UnexpectedValueException::class);
	$t->throws(static fn()=> $t->nonPublic($ignored)->invoke('assertSourceEntry', $workspace->path('linked.php'), true), UnexpectedValueException::class);
	$required=PanelCoverageGate::fromReport(['engines'=>['xdebug'], 'line_files'=>[]], $root, ['require_engine'=>'phpdbg', 'require_all_sources'=>false, 'minimum_percent'=>0]);
	$t->same('required_engine_missing', $required->failures()[0]['name']??null);
})->tag('panel', 'coverage', 'quality-gate', 'validation')->maxMillis(1000);
