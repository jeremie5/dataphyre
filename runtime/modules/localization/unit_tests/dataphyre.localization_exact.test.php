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

require_once __DIR__.'/localization_kernel_testkit.php';

if(!defined('RUN_MODE')){
	define('RUN_MODE', 'unit_test');
}
if(!defined('IS_PRODUCTION')){
	define('IS_PRODUCTION', false);
}
if(!defined('DP_LOCALIZATION_CFG')){
	define('DP_LOCALIZATION_CFG', [
		'custom_parameters'=>[],
		'enable_theme_locales'=>true,
		'enable_global_locales'=>true,
		'database_backed'=>false,
		'locales_table'=>'locales',
		'source_branch'=>null,
		'source_commit'=>null,
		'source_repository_path'=>null,
		'detect_source_from_git'=>false,
		'default_language'=>'en-CA',
		'user_language'=>'en-CA',
		'translation_callback'=>null,
		'available_languages'=>['en-CA'=>'English'],
		'available_themes'=>['light'=>'Light'],
		'user_theme'=>'light',
		'global_locale_path'=>null,
		'theme_locale_path'=>null,
		'local_locale_path'=>null,
	]);
}

$localizationExactRuntime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
require_once $localizationExactRuntime.'/modules/localization/kernel/localization.main.php';

suite('Localization exact kernel behavior')
	->contract('localization.exact-kernel', 1)
	->layer('integration')
	->risk('high')
	->watches('module:localization')
	->through('source-detection', 'lookup-recovery', 'mutation-failures', 'sync-pagination', 'rebuild-diagnostics')
	->isolation('case')
	->tag('localization', 'exact-coverage')
	->group('framework-coverage');

test('language readiness local path validation and git source detection fail safely without environment assumptions', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internals=$scenario->internals();
	$internals->writeProperty('available_languages', null);
	$before=$scenario->unavailableCalls();
	$t->same('en-CA', \dataphyre\localization::validate_language_code('unknown'));
	$t->same($before+1, $scenario->unavailableCalls());
	$t->same(null, $internals->invoke('resolve_locale_file_path', 'local', 'en-CA', 'light', ''));
	$repository=$t->workspace('localization-source-repository');
	$repository->directory('project/.git');
	$searchStart=$repository->directory('project/runtime/modules/localization');
	$internals->writeProperty('source_repository_path', null);
	$t->same(realpath($repository->path('project')), $internals->invoke('source_repository_path', $searchStart));

	$t->setEnvironmentForTest([
		'DATAPHYRE_SOURCE_BRANCH'=>null,
		'GITHUB_REF_NAME'=>null,
		'CI_COMMIT_REF_NAME'=>null,
		'BRANCH_NAME'=>null,
		'GIT_BRANCH'=>null,
		'DATAPHYRE_SOURCE_COMMIT'=>null,
		'GITHUB_SHA'=>null,
		'CI_COMMIT_SHA'=>null,
		'GIT_COMMIT'=>null,
	]);
	$scenario->configure([
		'source_branch'=>null,
		'source_commit'=>null,
		'source_repository_path'=>$scenario->workspace()->root(),
		'detect_source_from_git'=>true,
	]);
	$source=\dataphyre\localization::source_snapshot();
	$t->same(null, $source['branch']);
	$t->same(null, $source['commit']);
	$t->same(realpath($scenario->workspace()->root()), $source['repository']);
});

test('lookup cache and database rebuilds cover every global theme and local recovery path', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	$scenario->cache(['light'=>['CACHED_THEME'=>'Cached theme']]);
	$t->same('Cached theme', \dataphyre\localization::locale('theme:cached_theme', null, null, null, '/orders'));

	$scenario->cache([]);
	LocalizationSqlProbe::queueSelect([]);
	$t->same('MISSING_GLOBAL', \dataphyre\localization::locale('global:missing_global', null, null, null, '/orders'));

	$scenario->cache([]);
	$scenario->writeRaw('theme/light/en-CA.json', '{corrupt');
	LocalizationSqlProbe::queueSelect([]);
	$t->same('MISSING_THEME', \dataphyre\localization::locale('theme:missing_theme', null, null, null, '/orders'));

	$scenario->cache([]);
	$scenario->writeLocal(['OTHER'=>'Other']);
	$t->same('MISSING_LOCAL', \dataphyre\localization::locale('local:missing_local', null, null, null, '/orders'));

	$scenario->cache([]);
	$scenario->writeRaw('local/light/en-CA/orders.json', '{corrupt');
	LocalizationSqlProbe::queueSelect([]);
	$t->same('CORRUPT_LOCAL', \dataphyre\localization::locale('local:corrupt_local', null, null, null, '/orders'));
});

test('invalid definitions and unknown-locale storage report each actionable failure contract', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internals=$scenario->internals();
	$internals->writeProperty('available_languages', null);
	$before=$scenario->unavailableCalls();

	$internals->invoke('normalize_locale_definition', 'global', '', 'name');
	$internals->invoke('normalize_locale_definition', 'theme', 'en-CA', 'name');
	$internals->invoke('normalize_locale_definition', 'local', 'en-CA', 'name');
	$internals->invoke('normalize_locale_definition_payload', [], true);
	$t->same(['lang'=>'raw-language'], $internals->invoke('normalize_locale_definition_filters', ['lang'=>' raw-language ']));
	$t->greaterThanOrEqual($before+5, $scenario->unavailableCalls());

	$scenario->writeUnknown(['KNOWN'=>['string'=>'Known']]);
	$t->same([], $internals->invoke('read_unknown_locales_data', static fn(string $path): false=>false));
	$t->hasKey('KNOWN', \dataphyre\localization::unknown_locales());

	$scenario->configure(['user_language'=>'en-CA', 'available_languages'=>['en-CA'=>'English']]);
	$scenario->failWritesContaining('unknown_locales.json');
	$t->isFalse($internals->invoke('create_unknown_locale_data', '/orders', 'local', 'blocked', 'Blocked'));
	$scenario->failWritesContaining(false);
	$scenario->configure(['user_language'=>null]);
	$t->same(null, $internals->invoke('create_unknown_locale_data', '/orders', 'local', 'missing_language', 'Missing language'));
});

test('file definition listing explains language selection name filtering and unresolved storage targets', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$scenario->writeGlobal(['KEEP'=>'Keep', 'SKIP'=>'Skip']);
	$rows=\dataphyre\localization::locale_definitions([
		'type'=>'global',
		'language'=>'en-CA',
		'name'=>'keep',
	]);
	$t->same(['KEEP'], array_column($rows, 'name'));

	$scenario->configure([
		'available_languages'=>null,
		'global_locale_path'=>null,
	]);
	$t->same([], \dataphyre\localization::locale_definitions(['type'=>'global', 'lang'=>'raw-language']));
});

test('SQL definition mutations expose direct rebuilds and precise batch failure summaries', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);

	LocalizationSqlProbe::queueSelect(false, []);
	$t->isTrue(\dataphyre\localization::save_locale_definition('global', 'en-CA', 'direct_save', 'Saved'));
	LocalizationSqlProbe::queueDelete(true);
	LocalizationSqlProbe::queueSelect([]);
	$t->isTrue(\dataphyre\localization::delete_locale_definition('theme', 'en-CA', 'direct_delete', 'light'));

	$scenario->failWritesContaining($scenario->globalPath());
	LocalizationSqlProbe::queueSelect(false, []);
	$saveFailure=\dataphyre\localization::save_locale_definitions([
		['type'=>'global', 'language'=>'en-CA', 'name'=>'batch_save', 'string'=>'Saved'],
	]);
	$t->hasPathValues([
		'ok'=>false,
		'processed'=>1,
		'rebuilt'=>false,
		'rebuild_targets'=>1,
	], $saveFailure);

	LocalizationSqlProbe::queueSelect(['id'=>10, 'string'=>'Delete me']);
	LocalizationSqlProbe::queueDelete(false);
	$deleteFailure=\dataphyre\localization::delete_locale_definitions([
		['type'=>'global', 'language'=>'en-CA', 'name'=>'delete_failure'],
	]);
	$t->hasPathValues(['ok'=>false, 'processed'=>1, 'rebuild_targets'=>1], $deleteFailure);

	LocalizationSqlProbe::queueSelect(['id'=>11, 'string'=>'Rebuild me']);
	LocalizationSqlProbe::queueDelete(true);
	LocalizationSqlProbe::queueSelect([]);
	$rebuildFailure=\dataphyre\localization::delete_locale_definitions([
		['type'=>'global', 'language'=>'en-CA', 'name'=>'rebuild_failure'],
	]);
	$t->hasPathValues([
		'ok'=>false,
		'processed'=>1,
		'rebuilt'=>false,
		'rebuild_targets'=>1,
	], $rebuildFailure);
});

test('learning distinguishes locale dictionary failures from unknown queue failures', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$scenario->writeUnknown([
		'BLOCKED'=>['string'=>'Blocked', 'theme'=>'light', 'scope'=>'global', 'path'=>''],
	]);
	$scenario->failWritesContaining($scenario->globalPath());
	$t->same('locale_file_unwritable', \dataphyre\localization::learn_unknown_locales());

	$scenario->failWritesContaining('unknown_locales.json');
	$t->same('unknown_locales_unwritable', \dataphyre\localization::learn_unknown_locales());
	$t->greaterThan(0, $scenario->unavailableCalls());
});

test('incremental sync handles empty pages failed rebuilds and a complete five-hundred-row page', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	$internals=$scenario->internals();
	$t->same([], $internals->invoke('read_last_synced_locale_ids'));
	$scenario->writeSyncIds(" \n\t ");
	$t->same([], $internals->invoke('read_last_synced_locale_ids'));

	LocalizationSqlProbe::queueSelect([]);
	\dataphyre\localization::sync_locales(true);

	LocalizationSqlProbe::reset();
	$scenario->failWritesContaining($scenario->globalPath());
	LocalizationSqlProbe::queueSelect([
		['id'=>1, 'type'=>'global', 'lang'=>'en-CA', 'theme'=>'', 'path'=>'', 'edit_time'=>'2026-01-02 03:04:05'],
	], []);
	\dataphyre\localization::sync_locales(true);
	$t->isFalse($scenario->artifactExists($scenario->syncTimestampPath()));

	LocalizationSqlProbe::reset();
	$scenario->failWritesContaining(false);
	$rows=[];
	for($id=1; $id<=500; $id++){
		$rows[]=['id'=>$id, 'type'=>'global', 'lang'=>'en-CA', 'theme'=>'', 'path'=>'', 'edit_time'=>'2026-01-02 03:04:06'];
	}
	LocalizationSqlProbe::queueSelect($rows, [], []);
	\dataphyre\localization::sync_locales(true);
	$t->contains([0, 500, 500], LocalizationSqlProbe::selectBindings());
	$t->same((string)strtotime('2026-01-02 03:04:06'), trim($scenario->readArtifact($scenario->syncTimestampPath())));
});

test('rebuild expands languages discovers local paths and distinguishes file creation from overwrite failures', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);

	LocalizationSqlProbe::queueSelect([]);
	$t->same(null, \dataphyre\localization::rebuild_locale(['global'], [], [], []));

	LocalizationSqlProbe::queueSelect(
		[['path'=>'/orders']],
		[['name'=>'LOCAL', 'string'=>'Local']]
	);
	$t->same(null, \dataphyre\localization::rebuild_locale(['local'], ['en-CA'], ['light'], []));
	$t->same(['LOCAL'=>'Local'], $t->readJsonArray($scenario->localPath()));

	$scenario->failWritesContaining($scenario->themePath());
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['theme'], ['en-CA'], ['light'], []));
	$scenario->writeTheme(['EXISTING'=>'Theme']);
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['theme'], ['en-CA'], ['light'], []));

	$scenario->failWritesContaining($scenario->localPath('/blocked'));
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['local'], ['en-CA'], ['light'], ['/blocked']));
	$scenario->writeLocal(['EXISTING'=>'Local'], '/blocked');
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['local'], ['en-CA'], ['light'], ['/blocked']));
});
