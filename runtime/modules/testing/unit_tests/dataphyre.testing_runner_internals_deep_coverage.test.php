<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/Runner.php';

suite('First-party runner internals')
	->tag('testing', 'runner', 'internals')
	->group('framework-coverage')
	->contract('testing.runner.internals', 1)
	->layer('unit')
	->risk('high')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/Runner.php')
	->through('Runner option normalization', 'managed fixture filesystem', 'selection contracts')
	->isolation('process')
	->maxMillis(15000);

/** Creates the minimum real testing runtime needed by nested Runner fixtures. */
function dp_runner_internal_workspace(Context $t, string $name): TempWorkspace {
	$workspace=$t->workspace($name);
	$workspace->installCodeWorkerTooling();
	return $workspace;
}

test('runner values paths and option parsing have explicit contracts', static function(Context $t): void {
	$workspace=$t->workspace('runner-values');
	$workspace->directory('common/dataphyre/runtime/modules');
	$workspace->file('common/dataphyre/runtime/modules/testing/tooling/code_worker.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'truth'=>true,
		'yes'=>'yes',
		'no'=>'off',
		'coverage'=>'false',
		'items'=>' first, ,second ',
	], [
		'display_name'=>' Fixture ',
		'entrypoint'=>' fixture test ',
	]);
	$access=$t->nonPublic($runner);
	$t->same(str_replace('\\', '/', realpath($workspace->root().'/common/dataphyre')), $access->readProperty('framework_root'));
	$t->same('Fixture', $access->readProperty('display_name'));
	$t->same('fixture test', $access->readProperty('entrypoint'));
	$t->same(str_replace('\\', '/', $workspace->root()).'/common/dataphyre/runtime/modules/testing/tooling/code_worker.php', $access->readProperty('code_worker_path'));

	$t->same('alpha/beta', $access->invoke('cleanRelativePath', './alpha//beta'));
	$t->same('', $access->invoke('cleanRelativePath', './'));
	$t->throwsLike(static fn()=>$access->invoke('cleanRelativePath', '../escape'), RuntimeException::class, 'traversal');
	$t->same('inside.php', $access->invoke('relativePath', $workspace->root().'/inside.php'));
	$t->same('C:/elsewhere.php', $access->invoke('relativePath', 'C:/elsewhere.php'));
	$t->isTrue($access->invoke('optionEnabled', 'truth'));
	$t->isTrue($access->invoke('optionEnabled', 'yes'));
	$t->isFalse($access->invoke('optionEnabled', 'no'));
	$t->isFalse($access->invoke('optionEnabled', 'missing'));
	$t->isFalse($access->invoke('coverageEnabled'));
	$t->same(['first', 'second'], $access->invoke('optionList', 'items'));
	$t->same([], $access->invoke('optionList', 'missing'));
	$t->isTrue($access->invoke('textSelectorMatches', 'Readable contract', 'contract'));
	$t->isTrue($access->invoke('textSelectorMatches', 'Readable contract', '/^readable/i'));
	$t->isFalse($access->invoke('textSelectorMatches', 'Readable contract', '/[/'));
	$t->same('&lt;runner &quot;value&quot;&gt;', $access->invoke('xml', '<runner "value">'));
	$t->same('safe[31m&lt;&amp;&gt;', $access->invoke('xml', "safe\x1B[31m<&>\x00"));
	$t->same("invalid\u{FFFD}", $access->invoke('xml', "invalid\xC3"));
	$t->same('1.25', $access->invoke('xmlNumber', 1.25));
	$t->same(' name="case"', $access->invoke('xmlAttributes', ['name'=>'case']));
	$t->same(str_replace('\\', '/', $workspace->root()).'/unit-tests.junit.xml', $access->invoke('outputPath', ''));
	$t->same(str_replace('\\', '/', $workspace->root()).'/reports/tests.xml', $access->invoke('outputPath', 'reports\\tests.xml'));
	$t->same('C:/reports/tests.xml', $access->invoke('outputPath', 'C:/reports/tests.xml'));
	$t->same('//server/tests.xml', $access->invoke('outputPath', '//server/tests.xml'));
	$t->isTrue($access->invoke('isList', [1, 2]));
	$t->isFalse($access->invoke('isList', ['name'=>'value']));
	$t->same([
		'scope'=>'framework',
		'owner'=>'testing',
		'manifest'=>'case.php',
		'kind'=>'code',
		'cases'=>2,
		'app_root'=>null,
	], $access->invoke('testRecord', 'framework', 'testing', 'case.php', 'code', 2));

	$t->same([
		'scope'=>'framework',
		'json'=>true,
		'parallel'=>'4',
	], dataphyre_unit_test_options(['position', '--scope=framework', '--json', '--parallel=4']));
	foreach([true, '1', 'yes', 'on'] as $enabled){$t->isTrue(dataphyre_unit_test_coverage_requested($enabled));}
	foreach([false, '', '0', 'false', 'no', 'off'] as $disabled){$t->isFalse(dataphyre_unit_test_coverage_requested($disabled));}

	$coverage_runner=new DataphyreUnitTestRunner($workspace->root(), ['coverage'=>true]);
	$t->isTrue($t->nonPublic($coverage_runner)->invoke('coverageEnabled'));
	$coverage_string_runner=new DataphyreUnitTestRunner($workspace->root(), ['coverage'=>'report.json']);
	$t->isTrue($t->nonPublic($coverage_string_runner)->invoke('coverageEnabled'));

	$temp=$access->invoke('temporaryRunFile', 'payload.json');
	$t->isTrue(is_dir(dirname($temp)));
	$t->throwsLike(static fn()=>$access->invoke('temporaryRunFile', '../payload.json'), LogicException::class, 'must not contain a directory');
	file_put_contents($temp, '{}');
	$access->invoke('cleanupTemporaryRunRoot');
	$t->isFalse(is_file($temp));

	$tree_runner=new DataphyreUnitTestRunner($workspace->root(),[],[
		'temporary_run_root'=>$workspace->path('runner-owned-temporary-tree'),
	]);
	$tree=$t->nonPublic($tree_runner);
	foreach(['','../escape','unsafe name'] as $invalid){
		$t->throwsLike(static fn()=>$tree->invoke('temporaryRunDirectory',$invalid),LogicException::class,'safe single path');
	}
	$existing_root=$tree->invoke('temporaryRunRoot');
	mkdir($existing_root.'/existing');
	$t->throwsLike(static fn()=>$tree->invoke('temporaryRunDirectory','existing'),RuntimeException::class,'already exists');
	rmdir($existing_root.'/existing');

	$owned=$tree->invoke('temporaryRunDirectory','owned');
	file_put_contents($owned.'/child.txt','owned');
	$tree->invoke('cleanupTemporaryPath',$owned);
	$t->isFalse(is_dir($owned));
	$tree->invoke('cleanupTemporaryRunDirectory',$workspace->path('never-registered'));
	$outside=$workspace->directory('outside-runner-root');
	$tree->writeProperty('temporary_run_directories',[$outside=>true]);
	$tree->invoke('cleanupTemporaryRunDirectory',$outside);
	$t->isTrue(is_dir($outside));

	$link=$tree->invoke('temporaryRunDirectory','link');
	rmdir($link);
	$link_target=$workspace->directory('runner-link-target');
	if(function_exists('symlink') && @symlink($link_target,$link)){
		$tree->invoke('cleanupTemporaryRunDirectory',$link);
		$t->isFalse(is_link($link));
	}else{
		$t->isTrue(true,'Symbolic-link cleanup is unavailable on this platform.');
	}
	$tree->invoke('removeTemporaryTree',$workspace->path('already-removed-tree'));
	$cleanup=$tree->invoke('temporaryRunDirectory','cleanup-on-root');
	$tree->invoke('cleanupTemporaryRunRoot');
	$t->isFalse(is_dir($cleanup));

	if(is_dir('/proc/self')){
		$read_only_runner=new DataphyreUnitTestRunner($workspace->root(),[],['temporary_run_root'=>'/proc/self']);
		$t->throwsLike(
			static fn()=>$t->nonPublic($read_only_runner)->invoke('temporaryRunDirectory','dataphyre-test-unwritable'),
			RuntimeException::class,
			'Unable to create unit-test temp directory'
		);
	}else{
		$t->isTrue(true,'The platform has no deterministic read-only process filesystem.');
	}
});

test('manifest classification normalization and filesystem discovery stay deterministic', static function(Context $t): void {
	$workspace=$t->workspace('runner-manifests');
	$workspace->directory('runtime/modules/catalog/unit_tests/fixtures');
	$list=$workspace->file('runtime/modules/catalog/unit_tests/list.json', '[{"name":"one"},{"name":"two"}]');
	$single=$workspace->file('runtime/modules/catalog/unit_tests/single.json', '{"function":"catalog_check","args":[]}');
	$entry=$workspace->file('runtime/modules/catalog/unit_tests/check.php', '<?php function catalog_check_passed(): bool { return true; }');
	$descriptor=$workspace->file('runtime/modules/catalog/unit_tests/descriptor.json', '{"type":"php","entry":"check.php","name":"catalog descriptor"}');
	$absolute_descriptor=$workspace->file('runtime/modules/catalog/unit_tests/absolute.json', json_encode(['type'=>'php', 'entry'=>str_replace('\\', '/', $entry), 'callable'=>'catalog_check_passed']));
	$invalid_json=$workspace->file('runtime/modules/catalog/unit_tests/broken.json', '{');
	$invalid_shape=$workspace->file('runtime/modules/catalog/unit_tests/shape.json', '{"name":"unsupported"}');
	$php_test=$workspace->file('runtime/modules/catalog/unit_tests/catalog.test.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/catalog/unit_tests/fixtures/fixture.json', '[]');
	$meta=$workspace->file('runtime/modules/catalog/unit_tests/catalog.meta.json', '[]');
	$mock=$workspace->file('runtime/modules/catalog/unit_tests/dpanel_mock_catalog.json', '[]');
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);

	$t->same('dpanel', $access->invoke('manifestKind', $list));
	$t->same('dpanel_single', $access->invoke('manifestKind', $single));
	$t->same('descriptor', $access->invoke('manifestKind', $descriptor));
	$t->same('invalid', $access->invoke('manifestKind', $invalid_json));
	$t->same('invalid', $access->invoke('manifestKind', $invalid_shape));
	$t->same(2, $access->invoke('caseCount', $list, 'dpanel'));
	$t->same(1, $access->invoke('caseCount', $single, 'dpanel_single'));
	$t->same(1, $access->invoke('caseCount', $descriptor, 'descriptor'));
	$t->same(0, $access->invoke('caseCount', $invalid_json, 'invalid'));
	$t->same(0, $access->invoke('caseCount', $invalid_shape, 'invalid'));

	$json_files=$access->invoke('jsonFiles', $workspace->root().'/runtime/modules/catalog');
	$t->contains(str_replace('\\', '/', $list), $json_files);
	$t->contains(str_replace('\\', '/', $descriptor), $json_files);
	$t->same([], $access->invoke('jsonFiles', $workspace->root().'/missing'));
	$t->same([str_replace('\\', '/', $php_test)], $access->invoke('phpTestFiles', $workspace->root().'/runtime/modules/catalog'));
	$t->same([], $access->invoke('phpTestFiles', $workspace->root().'/missing'));
	$t->isTrue($access->invoke('isMetaOrFixture', $workspace->root().'/runtime/modules/catalog/unit_tests/fixtures/fixture.json'));
	$t->isTrue($access->invoke('isMetaOrFixture', $meta));
	$t->isTrue($access->invoke('isMetaOrFixture', $mock));
	$t->isFalse($access->invoke('isMetaOrFixture', $list));
	$t->same('catalog', $access->invoke('moduleName', $list));
	$t->same('manifest', $access->invoke('moduleName', $workspace->root().'/other/list.json'));
	$t->same('catalog', $access->invoke('frameworkOwner', $php_test));
	$t->same('testing', $access->invoke('frameworkOwner', $workspace->root().'/runtime/modules/testing/unit_tests/canonical.test.php'));
	$t->same('dataphyre', $access->invoke('frameworkOwner', $workspace->root().'/other.test.php'));

	$t->same('C:/absolute.php', $access->invoke('descriptorEntryPath', dirname($descriptor), 'C:/absolute.php'));
	$t->same('/opt/dataphyre/check.php', $access->invoke('descriptorEntryPath', dirname($descriptor), '/opt/dataphyre/check.php'));
	$t->same('//server/check.php', $access->invoke('descriptorEntryPath', dirname($descriptor), '//server/check.php'));
	$t->same('applications/catalog/check.php', $access->invoke('descriptorEntryPath', dirname($descriptor), 'applications/catalog/check.php'));
	$t->same('common/catalog/check.php', $access->invoke('descriptorEntryPath', dirname($descriptor), 'common/catalog/check.php'));
	$t->same(str_replace('\\', '/', dirname($descriptor).'/check.php'), $access->invoke('descriptorEntryPath', dirname($descriptor), 'check.php'));

	$single_test=['scope'=>'framework', 'owner'=>'catalog', 'manifest'=>$single, 'kind'=>'dpanel_single', 'app_root'=>null];
	$normalized_single=$access->invoke('normalizedManifest', $single_test);
	$t->same([['function'=>'catalog_check', 'args'=>[]]], json_decode((string)file_get_contents($normalized_single), true));
	$descriptor_test=['scope'=>'framework', 'owner'=>'catalog', 'manifest'=>$descriptor, 'kind'=>'descriptor', 'app_root'=>null];
	$normalized_descriptor=$access->invoke('normalizedManifest', $descriptor_test);
	$descriptor_cases=json_decode((string)file_get_contents($normalized_descriptor), true);
	$t->same('catalog descriptor', $descriptor_cases[0]['name']);
	$t->same(str_replace('\\', '/', $entry), $descriptor_cases[0]['file']);
	$t->same('check_passed', $descriptor_cases[0]['function']);
	$absolute_cases=json_decode((string)file_get_contents($access->invoke('normalizedManifest', array_replace($descriptor_test, ['manifest'=>$absolute_descriptor]))), true);
	$t->same(str_replace('\\', '/', $entry), $absolute_cases[0]['file']);

	$t->throwsLike(static fn()=>$access->invoke('normalizedManifest', array_replace($single_test, ['manifest'=>$invalid_json])), RuntimeException::class, 'became unreadable');
	$t->throwsLike(static fn()=>$access->invoke('normalizedManifest', array_replace($descriptor_test, ['manifest'=>$invalid_json])), RuntimeException::class, 'became unreadable');
});

test('host application roots registry and bootstrap rules are self describing', static function(Context $t): void {
	$workspace=$t->workspace('runner-app-roots');
	$workspace->directory('runtime/modules/catalog/testing');
	$module_bootstrap=$workspace->file('runtime/modules/catalog/testing/bootstrap.php', '<?php declare(strict_types=1);');
	$workspace->directory('applications/shared/themes');
	$workspace->directory('applications/shared/debug');
	$workspace->directory('applications/shared/backend');
	$app_root=$workspace->directory('applications/catalog');
	$themed_root=$workspace->directory('applications/themed');
	$workspace->directory('applications/catalog/backend/dataphyre');
	$app_bootstrap=$workspace->file('applications/catalog/testing/bootstrap.php', '<?php declare(strict_types=1);');
	$applications=[
		[
			'name'=>'catalog','path'=>'applications/catalog',
			'test_rootpaths'=>[
				'shared_root'=>'applications/shared',
				'common_debug'=>'applications/shared/debug',
				'common_backend'=>'applications/shared/backend',
				'common_themes'=>'applications/shared/themes',
			],
		],
		[
			'name'=>'themed','path'=>'applications/themed',
			'test_rootpaths'=>['themes'=>'applications/shared/themes'],
		],
	];
	$registry=$workspace->file('applications/dataphyre.apps.json', json_encode([
		'applications'=>$applications,
	]));
	$runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$registry]);
	$access=$t->nonPublic($runner);

	$t->same($applications, $access->invoke('applications'));
	$t->same($app_root.'/backend/dataphyre/', $access->invoke('dataphyreRootForApp', $app_root));
	$direct=$workspace->directory('applications/direct/dataphyre');
	$t->same(dirname($direct).'/dataphyre/', $access->invoke('dataphyreRootForApp', dirname($direct)));
	$fallback=$workspace->directory('applications/fallback');
	$t->same($fallback.'/backend/dataphyre/', $access->invoke('dataphyreRootForApp', $fallback));

	$framework=['scope'=>'framework', 'owner'=>'catalog', 'manifest'=>'catalog.json', 'kind'=>'dpanel', 'app_root'=>null];
	$t->same([str_replace('\\', '/', $module_bootstrap)], $access->invoke('testBootstrapFiles', $framework));
	$t->same([], $access->invoke('testBootstrapFiles', array_replace($framework, ['owner'=>'manifest'])));
	$t->same([], $access->invoke('testBootstrapFiles', array_replace($framework, ['owner'=>'dataphyre'])));
	$app=['scope'=>'app', 'owner'=>'catalog', 'manifest'=>'catalog.test.php', 'kind'=>'code', 'app_root'=>$app_root];
	$t->same([$app_bootstrap], $access->invoke('testBootstrapFiles', $app));

	$framework_paths=$access->invoke('rootpathFor', $framework);
	$t->same('dataphyre', $framework_paths['app']);
	$t->same(str_replace('\\', '/', $workspace->root()).'/', $framework_paths['root']);
	$app_paths=$access->invoke('rootpathFor', $app);
	$t->same('catalog', $app_paths['app']);
	$t->same($app_root.'/', $app_paths['root']);
	$t->same(str_replace('\\', '/', $workspace->root()).'/applications/shared/', $app_paths['shared_root']);
	$t->same(str_replace('\\', '/', $workspace->root()).'/applications/shared/debug/', $app_paths['common_debug']);
	$t->same(str_replace('\\', '/', $workspace->root()).'/applications/shared/backend/', $app_paths['common_backend']);
	$t->same(str_replace('\\', '/', $workspace->root()).'/applications/shared/themes/', $app_paths['common_themes']);
	$themed_paths=$access->invoke('rootpathFor', array_replace($app, ['owner'=>'themed','app_root'=>$themed_root]));
	$t->same(str_replace('\\', '/', $workspace->root()).'/applications/shared/themes/', $themed_paths['themes']);
	$fingerprint_before=$access->invoke('codeCaseFingerprint',$app);
	$workspace->directory('applications/shared-v2/backend');
	$changed_applications=$applications;
	$changed_applications[0]['test_rootpaths']['common_backend']='applications/shared-v2/backend';
	$workspace->file('applications/dataphyre.apps.json',json_encode(['applications'=>$changed_applications]));
	$changed_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$registry]);
	$t->notSame($fingerprint_before,$t->nonPublic($changed_runner)->invoke('codeCaseFingerprint',$app));

	$t->isFalse($access->invoke('shouldLoadFrameworkModule', $app));
	$t->isFalse($access->invoke('shouldLoadFrameworkModule', $framework));
	$caspow=array_replace($framework, ['manifest'=>$workspace->root().'/runtime/modules/caspow/unit_tests/verify_payload.json']);
	$t->isTrue($access->invoke('shouldLoadFrameworkModule', $caspow));
	$load_runner=new DataphyreUnitTestRunner($workspace->root(), ['load-framework-modules'=>true], ['applications_registry'=>$registry]);
	$t->isTrue($t->nonPublic($load_runner)->invoke('shouldLoadFrameworkModule', $framework));

	$invalid_registry=$workspace->file('applications/invalid.json', '{"wrong":[]}');
	$invalid_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$invalid_registry]);
	$t->throwsLike(static fn()=>$t->nonPublic($invalid_runner)->invoke('applications'), RuntimeException::class, 'Invalid applications');
	$protected_registry=$workspace->file('applications/protected.json', json_encode(['applications'=>[[
		'name'=>'catalog','path'=>'applications/catalog','test_rootpaths'=>['root'=>'applications/shared'],
	]]]));
	$protected_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$protected_registry]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($protected_runner)->invoke('rootpathFor',$app),
		RuntimeException::class,
		'cannot replace protected rootpath',
	);
	$outside_registry=$workspace->file('applications/outside.json', json_encode(['applications'=>[[
		'name'=>'catalog','path'=>'applications/catalog','test_rootpaths'=>['shared_root'=>'../outside'],
	]]]));
	$outside_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$outside_registry]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($outside_runner)->invoke('rootpathFor',$app),
		RuntimeException::class,
		'traversal is not allowed',
	);
});

test('selection watches filters and changed reasons describe why every test was selected', static function(Context $t): void {
	$workspace=dp_runner_internal_workspace($t, 'runner-selection');
	$panel_test=$workspace->file('runtime/modules/panel/unit_tests/dataphyre.panel.contract.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('panel contract', static function(Context $t): void { $t->isTrue(true); })->watches('module:panel');
PHP);
	$sql_test=$workspace->file('runtime/modules/sql/unit_tests/dataphyre.sql.contract.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('sql contract', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$selection=[
		'exact'=>['runtime/modules/panel/unit_tests/exact.json'],
		'modules'=>['panel'],
		'apps'=>['catalog'],
		'paths'=>['runtime/modules/panel/Framework/Core/Panel.php', 'applications/catalog/src/Orders.php'],
		'all_framework'=>false,
		'all_code'=>false,
	];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$access=$t->nonPublic($runner);
	$access->writeProperty('changed_test_selection', $selection);

	foreach([
		'framework', 'module:*', 'module:panel', 'panel',
		'path:runtime/modules/**/Panel.php', 'runtime/modules/**/Panel.php',
		'app:cat?log',
	] as $watch){$t->isTrue($access->invoke('watchTargetMatches', $watch, $selection), $watch);}
	foreach(['testing', 'module:sql', 'app:missing', 'path:missing/**', 'missing'] as $watch){
		$t->isFalse($access->invoke('watchTargetMatches', $watch, $selection), $watch);
	}
	$t->same(['panel'], $access->invoke('watchChangedModules', $selection));
	$t->isTrue($access->invoke('globMatches', 'runtime/**/Panel.?hp', 'runtime/modules/panel/Panel.php'));
	$t->isFalse($access->invoke('globMatches', 'runtime/*/Panel.php', 'runtime/modules/panel/Panel.php'));

	$panel=['scope'=>'framework', 'owner'=>'panel', 'manifest'=>$panel_test, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$t->same(['watch target matched: module:panel'], $access->invoke('changedTestReasons', $panel));
	$t->isTrue($access->invoke('changedTestMatches', $panel));
	$exact=['scope'=>'framework', 'owner'=>'panel', 'manifest'=>$workspace->root().'/runtime/modules/panel/unit_tests/exact.json', 'kind'=>'dpanel', 'cases'=>1, 'app_root'=>null];
	$t->same(['changed test file runtime/modules/panel/unit_tests/exact.json'], $access->invoke('changedTestReasons', $exact));
	$app=['scope'=>'app', 'owner'=>'catalog', 'manifest'=>$workspace->root().'/applications/catalog/unit_tests/case.json', 'kind'=>'dpanel', 'cases'=>1, 'app_root'=>$workspace->root().'/applications/catalog'];
	$t->same(['application source changed: catalog'], $access->invoke('changedTestReasons', $app));
	$module=['scope'=>'framework', 'owner'=>'panel', 'manifest'=>$workspace->root().'/runtime/modules/panel/unit_tests/case.json', 'kind'=>'dpanel', 'cases'=>1, 'app_root'=>null];
	$t->same(['module source changed: panel'], $access->invoke('changedTestReasons', $module));

	$all_framework=$selection;
	$all_framework['all_framework']=true;
	$access->writeProperty('changed_test_selection', $all_framework);
	$t->same(['framework-wide source changed'], $access->invoke('changedTestReasons', $module));
	$all_code=$selection;
	$all_code['all_code']=true;
	$access->writeProperty('changed_test_selection', $all_code);
	$sql=['scope'=>'framework', 'owner'=>'sql', 'manifest'=>$sql_test, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$t->same(['testing infrastructure changed'], $access->invoke('changedTestReasons', $sql));

	$t->isFalse($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/fixtures/case.json'));
	$t->isTrue($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/case.test.php'));
	$t->isFalse($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/helper.php'));
	$t->isTrue($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/case.json'));
	$t->isFalse($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/case.meta.json'));
	$t->isFalse($access->invoke('isChangedTestManifestPath', 'runtime/modules/panel/unit_tests/dpanel_mock_case.json'));
	$t->isTrue($access->invoke('isGlobalWatchTestFile', $panel_test));
	$t->isFalse($access->invoke('isGlobalWatchTestFile', $sql_test));
	$t->isFalse($access->invoke('isGlobalWatchTestFile', $workspace->root().'/missing.test.php'));

	$filter_runner=new DataphyreUnitTestRunner($workspace->root(), ['owner'=>'panel', 'path'=>'panel', 'kind'=>'code']);
	$same_owner_wrong_path=array_replace($panel, ['manifest'=>$workspace->root().'/runtime/modules/other/unit_tests/unrelated.test.php']);
	$selected=$t->nonPublic($filter_runner)->invoke('filterTests', [$panel, $sql, $same_owner_wrong_path, $exact]);
	$t->count(1, $selected);
	$t->same(['owner=panel', 'path contains panel', 'kind=code'], $selected[0]['selection_reasons']);
	$default_runner=new DataphyreUnitTestRunner($workspace->root());
	$default_selected=$t->nonPublic($default_runner)->invoke('filterTests', [$exact]);
	$t->same(['selected by scope'], $default_selected[0]['selection_reasons']);
});

test('discovery roots reject unsafe or unavailable scopes before expensive case listing', static function(Context $t): void {
	$workspace=$t->workspace('runner-discovery-roots');
	$panel=$workspace->directory('runtime/modules/panel/unit_tests');
	$file=$workspace->file('runtime/modules/panel/unit_tests/case.test.php', '<?php declare(strict_types=1);');
	$modules=$workspace->root().'/runtime/modules';

	$invalid=new DataphyreUnitTestRunner($workspace->root(), ['scope'=>'unknown']);
	$t->throwsLike(static fn()=>$t->nonPublic($invalid)->invoke('discover'), RuntimeException::class, 'Invalid --scope');
	$apps=new DataphyreUnitTestRunner($workspace->root(), ['scope'=>'apps']);
	$t->throwsLike(static fn()=>$t->nonPublic($apps)->invoke('discover'), RuntimeException::class, 'Application tests require');
	$bad_isolation=new DataphyreUnitTestRunner($workspace->root(), ['isolate'=>'thread']);
	$t->throwsLike(static fn()=>$t->nonPublic($bad_isolation)->invoke('expandExecutionUnits', []), RuntimeException::class, 'Invalid --isolate');

	$owner=new DataphyreUnitTestRunner($workspace->root(), ['owner'=>'panel']);
	$t->same([$workspace->root().'/runtime/modules/panel'], $t->nonPublic($owner)->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$missing_owner=new DataphyreUnitTestRunner($workspace->root(), ['owner'=>'missing']);
	$t->same([], $t->nonPublic($missing_owner)->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$path_file=new DataphyreUnitTestRunner($workspace->root(), ['path'=>'runtime/modules/panel/unit_tests/case.test.php']);
	$t->same([str_replace('\\', '/', dirname($file))], $t->nonPublic($path_file)->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$path_dir=new DataphyreUnitTestRunner($workspace->root(), ['path'=>'runtime/modules/panel/unit_tests']);
	$t->same([str_replace('\\', '/', $panel)], $t->nonPublic($path_dir)->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$path_module=new DataphyreUnitTestRunner($workspace->root(), ['path'=>'prefix/runtime/modules/panel/anything']);
	$t->same([$workspace->root().'/runtime/modules/panel'], $t->nonPublic($path_module)->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$default=new DataphyreUnitTestRunner($workspace->root());
	$t->same([$modules], $t->nonPublic($default)->invoke('frameworkDiscoveryRoots', $modules, 'json'));
});
