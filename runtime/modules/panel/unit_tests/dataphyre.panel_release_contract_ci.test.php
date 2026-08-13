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

/** Returns one top-level GitHub Actions job without requiring a YAML extension. */
function dp_panel_release_contract_ci_job(string $workflow, string $name): string {
	$pattern='/^  '.preg_quote($name, '/').':\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:\R|\z)/ms';
	if(preg_match($pattern, $workflow, $matches)!==1){
		throw new RuntimeException('CI job was not found: '.$name.'.');
	}
	return (string)$matches['job'];
}

test('central Panel release contract binds capabilities matrices packaging and CI', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$contract=json_decode((string)file_get_contents($root.'/runtime/modules/panel/testing/panel_release_contract.json'), true, 32, JSON_THROW_ON_ERROR);
	$t->same('dataphyre_panel_release_contract', $contract['type'] ?? null);
	$t->same(2, $contract['schema_version'] ?? null);
	$t->same(['8.2','8.4'], $contract['php_tested'] ?? null);
	$capabilities=[];
	foreach($contract['assets']['capabilities'] ?? [] as $capability){
		$capabilities[$capability['name']]=$capability;
	}
	$t->same(count($capabilities), count($contract['assets']['capabilities'] ?? []));
	$t->same(['shell','collection-layout'], $capabilities['collection-layout']['bundle_capabilities'] ?? null);
	$t->same(['shell','form','upload'], $capabilities['upload']['capabilities'] ?? null);
	$t->same(['shell','form'], $capabilities['upload']['bundle_capabilities'] ?? null);
	$t->same('dependency_alias', $capabilities['upload']['delivery'] ?? null);
	$t->same('forbidden', $capabilities['data-surface']['host_asset'] ?? null);
	$t->same('forbidden', $capabilities['widget-runtime']['host_asset'] ?? null);
	$t->same(['shell','form','studio-editor'], $capabilities['studio-editor']['capabilities'] ?? null);
	$t->same('forbidden', $capabilities['studio-editor']['host_asset'] ?? null);
	$t->same('required', $capabilities['reactor']['host_asset'] ?? null);
	$t->same('vendor-map', $contract['assets']['external_probe']['name'] ?? null);
	$t->same([52,52,60,8,24], array_column($contract['browser']['reports'] ?? [], 'total'));
	$t->same(78, $contract['browser']['inclusive']['automated_total'] ?? null);
	$t->same(48, $contract['browser']['inclusive']['declared_manual_total'] ?? null);
	$t->same(52, $contract['browser']['committed_visual']['total'] ?? null);
	foreach([
		'runtime/modules/panel/Framework/Assets/PanelAssetCapabilityManifest.php',
		'runtime/modules/panel/Framework/Studio/Rendering/PanelStudioEditorAssets.php',
		'runtime/modules/panel/Framework/Studio/Rendering/Assets/panel-studio-editor.css',
		'runtime/modules/panel/Framework/Studio/Rendering/Assets/panel-studio-editor.js',
		'runtime/modules/panel/testing/panel_release_contract.js',
		'runtime/modules/panel/testing/panel_documentation_portal_test_runner.php',
		'runtime/modules/panel/testing/panel_documentation_portal_browser_regression.js',
		'runtime/modules/panel/testing/fixtures/panel_browser_showroom.php',
	] as $required){
		$t->contains($required, $contract['packaging']['required_paths'] ?? []);
	}
	foreach(['.codex-tmp','.tmp','.github','dev','vendor'] as $forbidden){
		$t->contains($forbidden, $contract['packaging']['forbidden_paths'] ?? []);
	}
	$t->same([
		'panel-release-contract', 'panel-unit', 'panel-exact-coverage', 'panel-assets',
		'panel-browser', 'panel-visual-regression', 'datadoc-documentation',
		'datadoc-documentation-browser',
	], $contract['ci']['aggregate_required_jobs'] ?? null);
	$t->same('panel-release', $contract['ci']['aggregate_job'] ?? null);

	$workflow=(string)file_get_contents($root.'/.github/workflows/ci.yml');
	$job=dp_panel_release_contract_ci_job($workflow, 'panel-release-contract');
	foreach(['php: ["8.2", "8.4"]','--self-test','--mode=source','--mode=package','prepare_public_export.ps1'] as $marker){
		$t->contains($marker, $job);
	}
	$t->contains('--mode=asset', dp_panel_release_contract_ci_job($workflow, 'panel-assets'));
	$t->contains('--mode=browser', dp_panel_release_contract_ci_job($workflow, 'panel-browser'));
	$t->contains('--mode=pixel', dp_panel_release_contract_ci_job($workflow, 'panel-visual-regression'));
	$unit=dp_panel_release_contract_ci_job($workflow, 'panel-unit');
	$t->contains('--owner=panel', $unit);
	$t->contains('--fail-skipped', $unit);
	$coverage=dp_panel_release_contract_ci_job($workflow, 'panel-exact-coverage');
	$t->contains('--coverage-closed-world', $coverage);
	$t->contains('--coverage-min-percent=100', $coverage);
	$documentation=dp_panel_release_contract_ci_job($workflow, 'datadoc-documentation');
	$t->contains('panel_documentation_portal_test_runner.php', $documentation);
	$documentationBrowser=dp_panel_release_contract_ci_job($workflow, 'datadoc-documentation-browser');
	$t->contains('panel_docs.php', $documentationBrowser);
	$t->contains('panel_documentation_portal_browser_regression.js', $documentationBrowser);
	$aggregate=dp_panel_release_contract_ci_job($workflow, 'panel-release');
	$t->contains('if: always()', $aggregate);
	$t->contains('--mode=aggregate', $aggregate);
	$t->contains('--job-results', $aggregate);
	foreach($contract['ci']['aggregate_required_jobs'] ?? [] as $requiredJob){
		$t->contains((string)$requiredJob, $aggregate);
	}
	$panelDocumentationBrowser=(string)file_get_contents($root.'/runtime/modules/panel/testing/panel_documentation_portal_browser_regression.js');
	$datadocDocumentationBrowser=(string)file_get_contents($root.'/runtime/modules/datadoc/testing/datadoc_documentation_portal_browser_regression.js');
	$t->contains('--allow-empty-content-assets', $panelDocumentationBrowser);
	$t->contains('contentImagesRequired', $datadocDocumentationBrowser);
	$t->contains('content_images_required', $datadocDocumentationBrowser);
})->tag('panel', 'release', 'ci', 'assets', 'browser', 'packaging')->maxMillis(2000);

test('public export boundary excludes generated trees while retaining the cache module', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$dist=(string)file_get_contents($root.'/.distignore');
	$gitIgnore=(string)file_get_contents($root.'/.gitignore');
	$attributes=(string)file_get_contents($root.'/.gitattributes');
	$installer=json_decode((string)file_get_contents($root.'/dataphyre.manifest.json'), true, 16, JSON_THROW_ON_ERROR);
	$prepare=(string)file_get_contents($root.'/dev/tools/private/release/prepare_public_export.ps1');
	$publicCheck=(string)file_get_contents($root.'/dev/tools/private/release/check_public_export.ps1');
	$releaseCheck=(string)file_get_contents($root.'/dev/tools/private/release/check_release.ps1');
	$publicExportDocs=(string)file_get_contents($root.'/dev/PUBLIC_EXPORT.md');
	$releaseManifest=json_decode(
		(string)file_get_contents($root.'/RELEASE_MANIFEST.json'),
		true,
		64,
		JSON_THROW_ON_ERROR
	);
	foreach(['.codex-tmp','.tmp','hosttmp','tmp'] as $directory){
		$t->contains('/'.$directory.'/', $dist);
		$t->contains('/'.$directory.' export-ignore', $attributes);
		$t->contains($directory, $installer['exclude'] ?? []);
		$t->contains("'".$directory."/'", $publicCheck);
	}
	$t->notContains('/runtime/modules/cache/', $dist);
	$t->notContains('`runtime/modules/cache/`', $publicExportDocs);
	$t->notContains("'runtime/modules/cache/'", $publicCheck);
	$t->notContains("'runtime/modules/cache/'", $releaseCheck);
	$t->notContains("'/runtime/modules/cache/'", $releaseCheck);
	$t->isTrue(is_file($root.'/runtime/modules/cache/kernel/cache.main.php'));
	$t->isTrue(is_file($root.'/runtime/modules/cache/documentation/Dataphyre_Cache.md'));
	$t->same([[
		'name'=>'cache',
		'status'=>'optional',
		'runtime_critical'=>false,
		'docs'=>'../runtime/modules/cache/documentation/Dataphyre_Cache.md',
		'purpose'=>'Fail-open Memcached cache facade with request-local fallback and an explicit shared-backend health contract.',
	]], array_values(array_filter(
		$releaseManifest['modules'] ?? [],
		static fn(mixed $entry): bool=>is_array($entry) && ($entry['name'] ?? null)==='cache'
	)));
	$releaseFiles=[];
	foreach($releaseManifest['files'] ?? [] as $entry){
		if(is_array($entry) && is_string($entry['path'] ?? null)) $releaseFiles[$entry['path']]=$entry;
	}
	foreach([
		'config/cache.example.php',
		'runtime/modules/cache/documentation/Dataphyre_Cache.md',
		'runtime/modules/cache/kernel/cache.main.php',
		'runtime/modules/cache/unit_tests/dataphyre.cache.fail_open.test.php',
		'runtime/modules/cache/version',
	] as $cacheFile){
		$t->isTrue(isset($releaseFiles[$cacheFile]), $cacheFile.' is represented in the public release manifest');
		$t->same((int)filesize($root.'/'.$cacheFile), $releaseFiles[$cacheFile]['bytes'] ?? null, $cacheFile.' byte count');
		$t->same((string)hash_file('sha256', $root.'/'.$cacheFile), $releaseFiles[$cacheFile]['sha256'] ?? null, $cacheFile.' digest');
	}
	foreach([
		'.dockerignore',
		'bin/dataphyre-mutate',
		'bin/dataphyre-test',
		'bin/dataphyre-test-docker',
		'docker/testing/Dockerfile',
		'docker/testing/browser/package.json',
		'runtime/modules/testing/tooling/Runner.php',
		'runtime/modules/testing/tooling/bootstrap.php',
		'runtime/modules/testing/tooling/code_worker.php',
		'runtime/modules/testing/version',
	] as $testingReleaseFile){
		$t->isTrue(is_file($root.'/'.$testingReleaseFile), $testingReleaseFile.' exists');
		$t->contains("'".$testingReleaseFile."'", $publicCheck, $testingReleaseFile.' is required by the public-export checker');
		$t->isTrue(isset($releaseFiles[$testingReleaseFile]), $testingReleaseFile.' is represented in the public release manifest');
	}
	$t->contains('canonical test CLI smoke', $publicCheck);
	$archiveCheck=(string)file_get_contents($root.'/dev/tools/private/release/check_release_archive.ps1');
	$t->contains("'bin/dataphyre-test'", $archiveCheck);
	$t->contains("'bin/dataphyre-test-docker'", $archiveCheck);
	$t->contains("-ne '100755'", $archiveCheck);
	$t->contains('rev-parse --verify', $archiveCheck);
	$t->contains('archive --format=zip --output=$zipPath $resolvedRef', $archiveCheck);
	$t->notContains('--worktree-attributes', $archiveCheck);
	$t->contains('check_release_archive.ps1', $releaseCheck);
	$t->contains('immutable executable-mode term', $releaseCheck);
	$t->contains(
		'DATAPHYRE_TEST_SKIP_BUILD=1 sh bin/dataphyre-test-docker --help',
		(string)file_get_contents($root.'/.github/workflows/ci.yml')
	);
	$t->contains(
		'-Ref $env:GITHUB_SHA',
		(string)file_get_contents($root.'/.github/workflows/ci.yml')
	);
	$t->contains('Test-Excluded $relative $script:GeneratedPrefixes', $releaseCheck);
	$fixtureCache='runtime/modules/core/unit_tests/fixtures/core-functions-unavailable-missing/cache/';
	$t->contains('/'.$fixtureCache, $dist);
	$t->contains('/'.$fixtureCache, $gitIgnore);
	$t->contains("'".$fixtureCache."'", $publicCheck);
	$t->contains("'".$fixtureCache."'", $releaseCheck);
	$t->contains("'/".$fixtureCache."'", $releaseCheck);
	$t->same([], array_values(array_filter(
		$releaseManifest['files'] ?? [],
		static fn(mixed $entry): bool=>is_array($entry)
			&& str_starts_with((string)($entry['path'] ?? ''), $fixtureCache)
	)));
	$t->contains('$Path.StartsWith($rootPrefix', $prepare);
	$t->contains('ignore rules run before any reopen', $prepare);
})->tag('panel', 'release', 'packaging', 'security')->maxMillis(1000);

test('release documentation distinguishes central proof from application requirements', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$testing=(string)file_get_contents($root.'/docs/TESTING.md');
	$package=(string)file_get_contents($root.'/docs/PACKAGE.md');
	$t->contains('Every capability that appears in an aggregate cache', $testing);
	$t->contains('shares the', $testing);
	$t->contains('PHP 8.2 and 8.4', $testing);
	$t->contains('packaged path, byte count, file digest', $testing);
	$t->contains('Contract schema v2', $testing);
	$t->contains('Missing, extra, duplicate, malformed, failed, cancelled, or skipped', $testing);
	$t->contains('does not replace `RELEASE_MANIFEST.json`', $package);
	$t->contains('recomputes every represented byte count', $package);
	$t->contains('Release-contract schema v2', $package);
})->tag('panel', 'release', 'documentation')->maxMillis(1000);
