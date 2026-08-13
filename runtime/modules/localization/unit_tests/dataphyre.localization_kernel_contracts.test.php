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
		'custom_parameters'=>['configured'=>'kept'],
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
		'tanslation_callback'=>null,
		'translation_callback'=>null,
		'available_languages'=>['en-CA'=>'English'],
		'available_themes'=>['light'=>'Light'],
		'user_theme'=>'light',
		'global_locale_path'=>null,
		'theme_locale_path'=>null,
		'local_locale_path'=>null,
	]);
}

$localization_kernel_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
require_once $localization_kernel_runtime.'/modules/localization/kernel/localization.main.php';

suite('Localization legacy kernel contracts')
	->contract('localization.kernel-lifecycle', 1)
	->layer('integration')
	->risk('high')
	->watches('module:localization')
	->through('state', 'files', 'definitions', 'unknown-locales', 'sql-sync', 'rebuild')
	->isolation('case')
	->tag('localization', 'kernel-contract')
	->group('framework-coverage');

test('kernel initialization exposes restorable state paths languages themes and source metadata', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internal=$scenario->internals();
	$t->isFalse(\dataphyre\localization::database_backed());
	$t->same('en-CA', \dataphyre\localization::default_language());
	$t->same('en-CA', \dataphyre\localization::user_language());
	$t->same('light', \dataphyre\localization::user_theme());
	$t->same(['en-CA'=>'English', 'fr-CA'=>'French'], \dataphyre\localization::available_languages());
	$t->same(['light'=>'Light', 'dark'=>'Dark'], \dataphyre\localization::available_themes());
	$t->same('en-CA', \dataphyre\core::$display_language);
	$t->same('/orders', \dataphyre\localization::active_page('orders'));
	$t->same('', \dataphyre\localization::active_page());
	$t->greaterThan(0, $scenario->runtime()->get('unavailable_calls'));
	$t->same('en-CA', \dataphyre\localization::validate_language_code('missing'));
	$t->same('fr-CA', \dataphyre\localization::validate_language_code('fr-CA'));

	$state=\dataphyre\localization::state();
	$t->same('feature/locales', $state['source']['branch']);
	$t->same('abc123', $state['source']['commit']);
	$t->same($state['source'], \dataphyre\localization::source_snapshot());
	$t->same(
		str_replace('\\', '/', $scenario->workspace()->root()),
		str_replace('\\', '/', (string)$state['source']['repository'])
	);
	$t->same(null, $internal->invoke('normalize_source_value', '   '));
	$t->same('value', $internal->invoke('normalize_source_value', ' value '));
	$t->same(null, $internal->invoke('normalize_source_value', null));

	$t->setEnvironmentForTest([
		'DATAPHYRE_SOURCE_BRANCH'=>' env-branch ',
		'DATAPHYRE_SOURCE_COMMIT'=>' env-commit ',
	]);
	\dataphyre\localization::apply_state([
		...$state,
		'source_branch'=>null,
		'source_commit'=>null,
		'detect_source_from_git'=>false,
	]);
	$environmentSource=\dataphyre\localization::source_snapshot();
	$t->same('env-branch', $environmentSource['branch']);
	$t->same('env-commit', $environmentSource['commit']);
	$t->same(null, $internal->invoke('first_environment_value', ['DATAPHYRE_MISSING_SOURCE_VALUE']));

	$instance=\dataphyre\localization::init([
		'database_backed'=>false,
		'custom_parameters'=>['runtime'=>'added'],
		'global_locale_path'=>$scenario->workspace()->path('global/%language%.json'),
	]);
	$t->instanceOf(\dataphyre\localization::class, $instance);
	$t->same('kept', \dataphyre\localization::state()['custom_parameters']['configured']);
	$t->same('added', \dataphyre\localization::state()['custom_parameters']['runtime']);
});

test('scope-aware file lookup caches dictionaries replaces parameters and records unknown fallbacks', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internal=$scenario->internals();
	$scenario->writeGlobal(['GREETING'=>'Hello <{name}>']);
	$scenario->writeTheme(['TITLE'=>'Light title']);
	$scenario->writeLocal(['WELCOME'=>'Welcome <{name}>']);

	$t->same(['GREETING'=>'Hello <{name}>'], \dataphyre\localization::get_locales('global', '', 'en-CA'));
	$t->same([], \dataphyre\localization::get_locales('unknown', '', 'en-CA'));
	$t->same('Hello Ada', \dataphyre\localization::locale(' global: greeting ', null, ['name'=>'Ada'], null, '/orders'));
	$t->same('Hello Grace', \locale('global:greeting', null, ['name'=>'Grace'], null, '/orders'));
	$t->same('Hello Lin', \__('global:greeting', null, ['name'=>'Lin'], null, '/orders'));
	$t->same('Light title', \dataphyre\localization::locale('theme:title', null, null, null, '/orders'));
	$t->same('Welcome Turing', \dataphyre\localization::locale('local:welcome', null, ['name'=>'Turing'], null, '/orders'));
	$t->same('Welcome Hopper', \dataphyre\localization::locale('welcome', null, ['name'=>'Hopper'], null, '/orders'));

	$t->globalMap('_SESSION')->put('show_locale_names', true);
	$t->same('WELCOME', \dataphyre\localization::locale('local:welcome', null, null, null, '/orders'));
	$t->globalMap('_SESSION')->forget('show_locale_names');
	$t->same('Fallback Dataphyre', \dataphyre\localization::locale('global:missing', 'Fallback <{application}>', null, null, '/orders'));
	$t->same('MISSING_WITHOUT_FALLBACK', \dataphyre\localization::locale('global:missing_without_fallback', null, null, null, '/orders'));
	$t->same('Empty Ada', \dataphyre\localization::locale('', 'Empty <{name}>', ['name'=>'Ada'], null, '/orders'));
	$t->same(
		'https://localization.example.test/current '.date('Y').' Ada',
		\dataphyre\localization::locale_parameters('&lt;{website_url}&gt; <{current_year}> <{name}>', ['name'=>'Ada'])
	);

	$t->isTrue(\dataphyre\localization::has_unknown_locale('missing'));
	$t->same('Fallback <{application}>', \dataphyre\localization::unknown_locale(' missing ')['string']);
	$t->same(null, \dataphyre\localization::unknown_locale(null));
	$t->same(null, \dataphyre\localization::unknown_locale('absent'));
	$t->isTrue(\dataphyre\localization::clear_unknown_locale('absent'));
	$t->isTrue(\dataphyre\localization::clear_unknown_locale('missing'));
	$t->isTrue(\dataphyre\localization::clear_unknown_locale());
	$t->same([], $t->readJsonArray($scenario->unknownPath()));

	$internal->writeProperty('enable_theme_locales', false)->writeProperty('enable_global_locales', false);
	$t->same('Missing theme', \dataphyre\localization::locale('theme:not_there', 'Missing theme', null, null, '/orders'));
	$t->same('Missing global', \dataphyre\localization::locale('global:not_there', 'Missing global', null, null, '/orders'));
	$t->greaterThanOrEqual(2, $scenario->runtime()->get('unavailable_calls'));
});

test('file-backed definition CRUD preserves source sidecars filtering pagination batches and noops', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internal=$scenario->internals();
	$t->isTrue(\dataphyre\localization::save_locale_definition('global', 'en-CA', ' greeting ', 'Hello'));
	$t->isTrue(\dataphyre\localization::save_locale_definition('theme', 'en-CA', 'title', 'Title', 'light'));
	$t->isTrue(\dataphyre\localization::save_locale_definition('local', 'en-CA', 'welcome', 'Welcome', 'light', 'orders'));
	$t->same('Hello', \dataphyre\localization::locale_definition('global', 'en-CA', 'greeting')['string']);
	$t->same('feature/locales', \dataphyre\localization::locale_definition('global', 'en-CA', 'greeting')['source_branch']);
	$t->same(null, \dataphyre\localization::locale_definition('global', 'en-CA', 'missing'));

	$globalRows=\dataphyre\localization::locale_definitions(['type'=>'global'], 5001, -5);
	$t->same(['GREETING'], array_column($globalRows, 'name'));
	$themeRows=\dataphyre\localization::locale_definitions(['type'=>'theme', 'theme'=>'light', 'name'=>'title'], 1, 0);
	$t->same(['TITLE'], array_column($themeRows, 'name'));
	$localRows=\dataphyre\localization::locale_definitions(['type'=>'local', 'theme'=>'light', 'path'=>'orders']);
	$t->same(['WELCOME'], array_column($localRows, 'name'));
	$t->same([], \dataphyre\localization::locale_definitions(['type'=>'local']));

	$batch=\dataphyre\localization::save_locale_definitions([
		['type'=>'global', 'lang'=>'en-CA', 'name'=>'one', 'value'=>'One'],
		['type'=>'global', 'language'=>'en-CA', 'name'=>'two', 'string'=>'Two'],
		'invalid',
	], true);
	$t->same(['ok'=>true, 'requested'=>2, 'processed'=>2, 'skipped'=>0, 'rebuilt'=>true, 'rebuild_targets'=>1], $batch);
	$t->same(['ok'=>true, 'requested'=>0, 'processed'=>0, 'skipped'=>0, 'rebuilt'=>false, 'rebuild_targets'=>0], \dataphyre\localization::save_locale_definitions(['invalid']));
	$t->same(3, count(\dataphyre\localization::locale_definitions(['type'=>'global'])));

	$deleted=\dataphyre\localization::delete_locale_definitions([
		['type'=>'global', 'language'=>'en-CA', 'name'=>'one'],
		['type'=>'global', 'language'=>'en-CA', 'name'=>'absent'],
		'invalid',
	], true);
	$t->same(1, $deleted['processed']);
	$t->same(1, $deleted['skipped']);
	$t->isTrue($deleted['rebuilt']);
	$t->same(['ok'=>true, 'requested'=>0, 'processed'=>0, 'skipped'=>0, 'rebuilt'=>false, 'rebuild_targets'=>0], \dataphyre\localization::delete_locale_definitions(['invalid']));
	$t->isTrue(\dataphyre\localization::delete_locale_definition('global', 'en-CA', 'absent'));
	$t->isTrue(\dataphyre\localization::delete_locale_definition('theme', 'en-CA', 'title', 'light'));

	$t->same('', $internal->invoke('normalize_local_path', ''));
	$t->same('/orders', $internal->invoke('normalize_local_path', ' orders '));
	$t->same('global', $internal->invoke('normalize_locale_type', 'invalid'));
	$t->same(
		['params'=>'WHERE type=? AND lang=? AND name=? AND theme=? AND path=?', 'vars'=>['local', 'en-CA', 'WELCOME', 'light', '/orders']],
		$internal->invoke('locale_definition_where', ['type'=>'local', 'language'=>'en-CA', 'name'=>'WELCOME', 'theme'=>'light', 'path'=>'/orders'])
	);
	$t->same(
		['params'=>'WHERE 1=1 AND type=? AND lang=?', 'vars'=>['global', 'en-CA']],
		$internal->invoke('locale_definition_filters_where', ['type'=>'global', 'lang'=>'en-CA'])
	);
	$t->same([], $internal->invoke('locale_definition_target_map', ['invalid']));
	$t->isTrue($internal->invoke('rebuild_locale_definition_targets', ['invalid']));
});

test('file storage helpers fail closed for missing paths corrupt data metadata and write failures', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$internal=$scenario->internals();
	$t->same([], $internal->invoke('read_locale_file_data', $scenario->workspace()->path('missing.json')));
	$corrupt=$scenario->workspace()->file('corrupt.json', '{not-json');
	$t->same([], $internal->invoke('read_locale_file_data', $corrupt));
	$t->same(null, $internal->invoke('read_locale_file_source_metadata', $corrupt, 'MISSING'));
	$scenario->workspace()->file('corrupt.json.meta.json', '{not-json');
	$t->same(null, $internal->invoke('read_locale_file_source_metadata', $corrupt, 'MISSING'));
	$scenario->workspace()->file('corrupt.json.meta.json', json_encode(['source'=>['branch'=>'fallback', 'commit'=>'source']]));
	$t->same('fallback', $internal->invoke('read_locale_file_source_metadata', $corrupt, 'MISSING')['branch']);

	$state=\dataphyre\localization::state();
	\dataphyre\localization::apply_state([...$state, 'global_locale_path'=>null, 'theme_locale_path'=>null, 'local_locale_path'=>null]);
	$t->same(null, $internal->invoke('resolve_locale_file_path', 'global', 'en-CA'));
	$t->same(null, $internal->invoke('resolve_locale_file_path', 'theme', 'en-CA', null));
	$t->same(null, $internal->invoke('resolve_locale_file_path', 'local', 'en-CA', 'light', ''));
	$t->same(null, $internal->invoke('resolve_locale_file_path', 'unknown', 'en-CA'));
	$definition=['type'=>'global', 'language'=>'en-CA', 'name'=>'NAME', 'theme'=>null, 'path'=>null];
	$t->same(null, $internal->invoke('file_locale_definition', $definition));
	$t->isFalse($internal->invoke('save_file_locale_definition', $definition, 'Value'));
	$t->isFalse($internal->invoke('delete_file_locale_definition', $definition));

	\dataphyre\localization::apply_state($state);
	$scenario->failWritesContaining('global');
	$t->isFalse(\dataphyre\localization::save_locale_definition('global', 'en-CA', 'blocked', 'Blocked'));
	$scenario->failWritesContaining(false);
	$t->isTrue(\dataphyre\localization::save_locale_definition('global', 'en-CA', 'saved', 'Saved'));
	$scenario->failWritesContaining('.meta.json');
	$t->isFalse(\dataphyre\localization::save_locale_definition('global', 'en-CA', 'metadata_blocked', 'Blocked'));
});

test('SQL-backed definitions bind scoped predicates and distinguish inserts updates deletes and empty reads', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	$t->isTrue(\dataphyre\localization::database_backed());
	$t->greaterThan(0, count(LocalizationSqlProbe::tableDefinitions()));

	LocalizationSqlProbe::queueSelect([
		['id'=>1, 'lang'=>'en-CA', 'type'=>'global', 'name'=>'ONE', 'string'=>'One'],
	]);
	$t->same(1, count(\dataphyre\localization::locale_definitions(['type'=>'global'], 20, 2)));
	LocalizationSqlProbe::queueSelect(false);
	$t->same([], \dataphyre\localization::locale_definitions());
	LocalizationSqlProbe::queueSelect(['id'=>1, 'string'=>'One']);
	$t->same(1, \dataphyre\localization::locale_definition('global', 'en-CA', 'one')['id']);
	LocalizationSqlProbe::queueSelect(false);
	$t->same(null, \dataphyre\localization::locale_definition('theme', 'en-CA', 'missing', 'light'));

	LocalizationSqlProbe::queueSelect(false, ['id'=>1, 'string'=>'Same'], ['id'=>2, 'string'=>'Old']);
	$t->isTrue(\dataphyre\localization::save_locale_definition('global', 'en-CA', 'new', 'New', null, null, false));
	$t->isTrue(\dataphyre\localization::save_locale_definition('theme', 'en-CA', 'same', 'Same', 'light', null, false));
	$t->isTrue(\dataphyre\localization::save_locale_definition('local', 'en-CA', 'changed', 'Changed', 'light', '/orders', false));
	$t->same(1, count(LocalizationSqlProbe::inserts()));
	$t->same(1, count(LocalizationSqlProbe::updates()));

	LocalizationSqlProbe::queueDelete(false, true, true);
	$t->isFalse(\dataphyre\localization::delete_locale_definition('global', 'en-CA', 'one', null, null, false));
	$t->isTrue(\dataphyre\localization::delete_locale_definition('theme', 'en-CA', 'one', 'light', null, false));
	$t->isTrue(\dataphyre\localization::delete_locale_definition('local', 'en-CA', 'one', 'light', '/orders', false));
	$t->same(3, count(LocalizationSqlProbe::deletes()));
});

test('unknown locale learning handles locks language readiness translation file mode and SQL scopes', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t);
	$t->same('no_locales_to_learn', \dataphyre\localization::learn_unknown_locales());
	$scenario->workspace()->file('cache/locks/locale_learning', (string)time());
	$t->same('already_learning_locales', \dataphyre\localization::learn_unknown_locales());
	$scenario->workspace()->file('cache/locks/locale_learning', (string)strtotime('-2 minutes'));
	$scenario->writeUnknown([
		'GLOBAL_ONE'=>['string'=>'Hello', 'theme'=>'light', 'scope'=>'global', 'path'=>''],
		'THEME_ONE'=>['string'=>'Title', 'theme'=>'light', 'scope'=>'theme', 'path'=>''],
		'LOCAL_ONE'=>['string'=>'Welcome', 'theme'=>'light', 'scope'=>'local', 'path'=>'/orders'],
	]);
	$t->same(3, \dataphyre\localization::learn_unknown_locales());
	$t->same([], $t->readJsonArray($scenario->unknownPath()));
	$t->same('fr-CA:Hello', \dataphyre\localization::locale_definition('global', 'fr-CA', 'GLOBAL_ONE')['string']);

	$state=\dataphyre\localization::state();
	$scenario->writeUnknown(['ONE'=>['string'=>'One', 'theme'=>'light', 'scope'=>'global', 'path'=>'']]);
	\dataphyre\localization::apply_state([...$state, 'available_languages'=>[]]);
	$t->same('no_language_to_learn', \dataphyre\localization::learn_unknown_locales());
	$scenario->writeUnknown(['ONE'=>['string'=>'One', 'theme'=>'light', 'scope'=>'global', 'path'=>'']]);
	\dataphyre\localization::apply_state([...$state, 'translation_callback'=>null]);
	$t->same('no_translation_callback', \dataphyre\localization::learn_unknown_locales());

	$sqlScenario=new LocalizationKernelScenario($t, true);
	$sqlScenario->writeUnknown([
		'GLOBAL_SQL'=>['string'=>'Global', 'theme'=>'light', 'scope'=>'global', 'path'=>''],
		'THEME_SQL'=>['string'=>'Theme', 'theme'=>'light', 'scope'=>'theme', 'path'=>''],
		'LOCAL_SQL'=>['string'=>'Local', 'theme'=>'light', 'scope'=>'local', 'path'=>'/orders'],
	]);
	LocalizationSqlProbe::queueSelect(false, false, false, false, false, false);
	$t->same(3, \dataphyre\localization::learn_unknown_locales());
	$t->same(6, count(LocalizationSqlProbe::inserts()));
});

test('incremental SQL sync skips invalid and previously processed rows then persists the newest cursor', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	$timestamp=strtotime('2026-01-02 03:04:05');
	$scenario->workspace()->file('cache/last_locale_sync', (string)$timestamp);
	$scenario->workspace()->file('cache/last_locales_file', '2, invalid, 7');
	$internal=$scenario->internals();
	$t->same([2=>true, 7=>true], $internal->invoke('read_last_synced_locale_ids'));
	$rows=[
		['id'=>0, 'type'=>'global', 'lang'=>'en-CA', 'theme'=>'', 'path'=>'', 'edit_time'=>'invalid'],
		['id'=>2, 'type'=>'global', 'lang'=>'en-CA', 'theme'=>'', 'path'=>'', 'edit_time'=>'2026-01-02 03:04:05'],
		['id'=>3, 'type'=>'global', 'lang'=>'en-CA', 'theme'=>'', 'path'=>'', 'edit_time'=>'2026-01-02 03:04:06'],
	];
	LocalizationSqlProbe::queueSelect($rows, [['name'=>'SYNCED', 'string'=>'Synced']]);
	\dataphyre\localization::sync_locales(true);
	$t->same((string)strtotime('2026-01-02 03:04:06'), trim($scenario->readArtifact($scenario->syncTimestampPath())));
	$t->same('3', trim($scenario->readArtifact($scenario->syncIdsPath())));
	$t->same(['SYNCED'=>'Synced'], $t->readJsonArray($scenario->globalPath()));

	$scenario->workspace()->file('cache/locks/locale_rebuilding', 'busy');
	$before=count(LocalizationSqlProbe::selects());
	\dataphyre\localization::sync_locales(true);
	$t->same($before, count(LocalizationSqlProbe::selects()));
	$internal->writeProperty('database_backed', false);
	\dataphyre\localization::sync_locales(true);
});

test('SQL rebuild expands global theme and local targets and fails closed on unresolved or unwritable output', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	LocalizationSqlProbe::queueSelect(
		[['name'=>'GLOBAL', 'string'=>'Global']],
		[['name'=>'THEME', 'string'=>'Theme']],
		[['name'=>'LOCAL', 'string'=>'Local']]
	);
	$t->same(null, \dataphyre\localization::rebuild_locale(['global', 'theme', 'local'], ['en-CA'], ['light'], ['/orders']));
	$t->same(['GLOBAL'=>'Global'], $t->readJsonArray($scenario->globalPath()));
	$t->same(['THEME'=>'Theme'], $t->readJsonArray($scenario->themePath()));
	$t->same(['LOCAL'=>'Local'], $t->readJsonArray($scenario->localPath()));
	$t->isFalse(is_file($scenario->rebuildLockPath()));

	$state=\dataphyre\localization::state();
	\dataphyre\localization::apply_state([...$state, 'local_locale_path'=>null]);
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['local'], ['en-CA'], ['light'], ['/orders']));
	\dataphyre\localization::apply_state($state);
	$scenario->failWritesContaining('global/en-CA.json');
	LocalizationSqlProbe::queueSelect([]);
	$t->isFalse(\dataphyre\localization::rebuild_locale(['global'], ['en-CA'], [], []));
	$scenario->internals()->writeProperty('database_backed', false);
	$t->same(null, \dataphyre\localization::rebuild_locale());
});

test('wildcard theme rebuild uses configured theme identifiers instead of display labels', static function(Context $t): void {
	$scenario=new LocalizationKernelScenario($t, true);
	LocalizationSqlProbe::queueSelect(
		[['name'=>'TITLE', 'string'=>'Light title']],
		[['name'=>'TITLE', 'string'=>'Dark title']]
	);
	$t->same(null, \dataphyre\localization::rebuild_locale(['theme'], ['en-CA'], ['*'], []));
	$t->same([['light', 'en-CA'], ['dark', 'en-CA']], LocalizationSqlProbe::selectBindings());
	$t->isTrue($scenario->artifactExists($scenario->themePath('light')));
	$t->isTrue($scenario->artifactExists($scenario->themePath('dark')));
});
