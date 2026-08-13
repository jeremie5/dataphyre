<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';

/**
 * Returns one top-level GitHub Actions job without requiring a YAML extension.
 */
function dp_panel_ci_job(string $workflow, string $name): string {
	$pattern='/^  '.preg_quote($name, '/').':\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:\R|\z)/ms';
	if(preg_match($pattern, $workflow, $matches)!==1){
		throw new RuntimeException('CI job was not found: '.$name.'.');
	}
	return (string)$matches['job'];
}

test('panel CI executes structural browser matrices and committed pixel comparisons', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$workflow=(string)file_get_contents($root.'/.github/workflows/ci.yml');
	$browser=dp_panel_ci_job($workflow, 'panel-browser');
	$pixels=dp_panel_ci_job($workflow, 'panel-visual-regression');

	$t->contains('runs-on: ubuntu-latest', $browser);
	$t->contains('panel_release_gate.js', $browser);
	$t->contains('--lanes=interaction,visual', $browser);
	$t->contains('--audit-only', $browser);
	$t->contains("exact(read('cache/ci/panel-browser/default/interaction.json'),52", $browser);
	$t->contains("exact(read('cache/ci/panel-browser/default/visual/report.json'),52", $browser);
	$t->contains("exact(read('cache/ci/panel-browser/theme-direction/report.json'),60", $browser);
	$t->contains("exact(media,8,'200%/media')", $browser);
	$t->contains("exact(read('cache/ci/panel-browser/container/report.json'),24", $browser);

	$t->contains('runs-on: windows-latest', $pixels);
	$t->contains('panel_browser_showroom.php', $pixels);
	$t->contains('panel_visual_regression.js', $pixels);
	$t->contains('cache/ci/panel-pixel/report.json', $pixels);
	$t->contains('$report.summary.total -ne 52', $pixels);
	$t->contains('$report.summary.failed -ne 0', $pixels);
	$t->contains('panel-committed-visual-regression', $pixels);
	$t->notContains('--audit-only', $pixels);
	$t->notContains('--update-baselines', $pixels);
	$t->same(true, is_file($root.'/runtime/modules/panel/testing/baselines/command_center__desktop.png'));
	$t->same(true, is_file($root.'/runtime/modules/panel/testing/baselines/mobile_drawer__mobile.png'));
})->tag('panel', 'ci', 'browser', 'visual-regression')->maxMillis(1000);

test('panel testing guide keeps baseline approval separate from CI comparison', static function(Context $t): void {
	$guide=(string)file_get_contents(dirname(__DIR__, 4).'/docs/TESTING.md');

	$t->contains('A separate `panel-visual-regression` Windows job', $guide);
	$t->contains('52-result default matrix', $guide);
	$t->contains('without `--audit-only` or `--update-baselines`', $guide);
	$t->contains('cannot create, replace, or', $guide);
})->tag('panel', 'documentation', 'ci', 'visual-regression')->maxMillis(1000);
