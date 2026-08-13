<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class, false)){
	require_once dirname(__DIR__, 2).'/core/kernel/core_functions.php';
}
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

test('package artifact paths reject traversal and Windows aliases', static function(Context $t): void {
	$plan=PanelPackageInstallPlan::make(PanelPackageTemplate::make('boundary'));

	$t->same('', $t->nonPublic($plan)->invoke('normalizeArtifactPath', '../../escape.php'));
	$t->same('inside.php', $t->nonPublic($plan)->invoke('normalizeArtifactPath', 'safe/../inside.php'));
	$t->same('', $t->nonPublic($plan)->invoke('normalizeArtifactPath', 'safe.txt:payload.php'));
	$t->same('', $t->nonPublic($plan)->invoke('normalizeArtifactPath', 'CON.txt'));
	$t->same('', $t->nonPublic($plan)->invoke('normalizeArtifactPath', "bad\0name.php"));
})->tag('panel', 'security', 'package', 'filesystem')->maxMillis(1000);

test('package containment resolves existing symlink ancestors', static function(Context $t): void {
	$plan=PanelPackageInstallPlan::make(PanelPackageTemplate::make('boundary'));
	$workspace=$t->workspace('dp-panel-package');
	$root=$workspace->directory('root');
	$outside=$workspace->directory('outside');
	$link=$root.DIRECTORY_SEPARATOR.'linked';
	$t->same(true, $t->nonPublic($plan)->invoke('pathWithinRoot', $root.DIRECTORY_SEPARATOR.'safe'.DIRECTORY_SEPARATOR.'file.php', $root));
	$t->same(false, $t->nonPublic($plan)->invoke('pathWithinRoot', $outside.DIRECTORY_SEPARATOR.'file.php', $root));
	if(@symlink($outside, $link)){
		$t->same(false, $t->nonPublic($plan)->invoke('pathWithinRoot', $link.DIRECTORY_SEPARATOR.'file.php', $root));
	}
})->tag('panel', 'security', 'package', 'filesystem')->maxMillis(1000);

test('package manifests omit invalid custom artifacts', static function(Context $t): void {
	$template=PanelPackageTemplate::make('boundary')
		->plugin(false)
		->provider(false)
		->docs(false)
		->tests(false)
		->with('marketplace', false)
		->file('../../escape.php', 'unsafe')
		->file('safe.txt:payload.php', 'unsafe')
		->file('src/Safe.php', '<?php return true;');
	$steps=PanelPackageInstallPlan::make($template)->manifest()['steps'] ?? [];
	$paths=array_values(array_map(static fn(array $step): string => (string)($step['path'] ?? ''), $steps));

	$t->contains('src/Safe.php', $paths);
	$t->notContains('../../escape.php', $paths);
	$t->notContains('safe.txt:payload.php', $paths);
})->tag('panel', 'security', 'package', 'manifest')->maxMillis(1000);

test('package apply writes complete artifacts beneath the resolved root', static function(Context $t): void {
	$root=$t->workspace('dp-panel-package-apply')->root();
	$template=PanelPackageTemplate::make('boundary')
		->plugin(false)
		->provider(false)
		->docs(false)
		->tests(false)
		->with('marketplace', false)
		->file('src/Safe.php', '<?php return "complete";');
	$target=$root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Safe.php';
	$packageManifest=$root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json';
	$result=PanelPackageInstallPlan::make($template)->apply($root);
	$t->same(true, $result->ok());
	$t->same('<?php return "complete";', is_file($target) ? file_get_contents($target) : null);
	$t->same(2, count($result->written()));
	$t->same(true, is_file($packageManifest));
})->tag('panel', 'security', 'package', 'filesystem')->maxMillis(1000);
