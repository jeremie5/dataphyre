<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\CaseDiscoveryCacheEntry;
use Dataphyre\Test\ShardedCaseDiscoveryCache;
use Dataphyre\Test\TempWorkspace;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/Runner.php';

suite('First-party runner residual contracts')
	->tag('testing', 'runner', 'residual')
	->group('framework-coverage')
	->contract('testing.runner.portable-residuals', 1)
	->layer('system')
	->risk('critical')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/Runner.php')
	->through('platform probes', 'Git change graph', 'worker transport', 'failure boundaries')
	->isolation('process')
	->maxMillis(30000);

/** @return array{0:mixed,1:string} */
function dp_runner_residual_capture(Context $t, callable $operation): array {
	$capture=$t->captureOutput($operation);
	return [$capture->result(), $capture->output()];
}

function dp_runner_residual_runtime(Context $t, string $name): TempWorkspace {
	$workspace=$t->workspace($name);
	$workspace->installCodeWorkerTooling();
	return $workspace;
}

/** @param array<int,array<string,mixed>> $cases */
function dp_runner_residual_seed_cases(Context $t, DataphyreUnitTestRunner $runner, array $record, array $cases): void {
	$key=sha1((string)$record['scope'].'|'.(string)$record['owner'].'|'.(string)$record['manifest'].'|'.(string)($record['app_root'] ?? ''));
	$access=$t->nonPublic($runner);
	$cache=$access->readProperty('code_case_cache');
	$cache[$key]=$cases;
	$access->writeProperty('code_case_cache', $cache);
}

/** @return array<string,mixed> */
function dp_runner_residual_case(int $index, string $name): array {
	return [
		'index'=>$index, 'name'=>$name, 'base_name'=>$name,
		'stable_id'=>'residual.'.$index, 'base_stable_id'=>'residual.'.$index,
		'tags'=>[], 'groups'=>[], 'dependencies'=>[], 'order'=>0, 'only'=>false,
		'isolation'=>'case', 'isolation_explicit'=>false,
		'watches'=>[], 'through'=>[], 'repeat_index'=>1, 'repeat_total'=>1,
	];
}

test('coverage engine probes are deterministic without installing every engine together', static function(Context $t): void {
	$xdebug_calls=[];
	$xdebug=dataphyre_unit_test_start_orchestrator_coverage(true, [
		'xdebug_available'=>true,
		'xdebug_started'=>false,
		'xdebug_start'=>static function(int $flags)use(&$xdebug_calls): void {$xdebug_calls[]=$flags;},
	]);
	$t->isTrue($xdebug['enabled']);
	$t->isTrue($xdebug['xdebug']);
	$t->isTrue($xdebug['xdebug_owned']);
	$t->same([0], $xdebug_calls);
	$already_started=dataphyre_unit_test_start_orchestrator_coverage(true, [
		'xdebug_available'=>true,
		'xdebug_started'=>true,
		'xdebug_start'=>static function(): void {throw new LogicException('already-started coverage must not restart');},
	]);
	$t->isTrue($already_started['xdebug']);
	$t->isFalse($already_started['xdebug_owned']);
	$probe_calls=0;
	$probed=dataphyre_unit_test_start_orchestrator_coverage(true, [
		'xdebug_available'=>true,
		'xdebug_started_probe'=>static function()use(&$probe_calls): bool {$probe_calls++; return false;},
		'xdebug_start'=>static function(): void {},
	]);
	$t->isTrue($probed['xdebug_owned']);
	$t->same(1, $probe_calls);

	$phpdbg_calls=0;
	$phpdbg=dataphyre_unit_test_start_orchestrator_coverage(true, [
		'xdebug_available'=>false,
		'phpdbg_available'=>true,
		'phpdbg_start'=>static function()use(&$phpdbg_calls): void {$phpdbg_calls++;},
	]);
	$t->isTrue($phpdbg['phpdbg']);
	$t->same(1, $phpdbg_calls);

	$workspace=$t->workspace('runner-main-probes');
	$t->same(1, dataphyre_unit_test_main(['fixture', 'help'], $workspace->root(), ['sapi'=>'apache2handler']));
	$main_starts=0;
	[$exit, $json]=dp_runner_residual_capture($t, static fn()=>dataphyre_unit_test_main(
		['fixture', 'run', '--scope=framework', '--coverage=false', '--json'],
		$workspace->root(),
		['orchestrator_coverage_runtime'=>[
			'xdebug_available'=>false,
			'phpdbg_available'=>true,
			'phpdbg_start'=>static function()use(&$main_starts): void {$main_starts++;},
		]],
	));
	$t->same(0, $exit);
	$t->same(0, json_decode($json, true)['summary']['workers_total']);
	$t->same(0, $main_starts);
});

test('orchestrator capture normalizes synthetic Xdebug and phpdbg maps through one contract', static function(Context $t): void {
	$root=dataphyre_path();
	$runner_file=$root.'/runtime/modules/testing/tooling/Runner.php';
	$outside=$t->tempFile('<?php declare(strict_types=1);', 'runner-coverage-outside');
	$stops=[];
	$xdebug_runner=new DataphyreUnitTestRunner($root, [], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true,
			'included_before'=>[],
			'xdebug'=>true,
			'xdebug_owned'=>true,
			'phpdbg'=>false,
			'xdebug_get'=>static fn(): array=>[
				$runner_file=>[10=>1, 11=>0, 12=>-1, 13=>-2],
				$outside=>[1=>1],
				'not-an-array'=>'invalid',
			],
			'xdebug_stop'=>static function(bool $cleanup)use(&$stops): void {$stops[]=$cleanup;},
		],
	]);
	$xdebug_access=$t->nonPublic($xdebug_runner);
	$xdebug_access->invoke('captureOrchestratorCoverage');
	$xdebug=$xdebug_access->readProperty('orchestrator_coverage');
	$t->same('xdebug', $xdebug['engine']);
	$t->same([10, 11, 12], $xdebug['files']['runtime/modules/testing/tooling/Runner.php']['executable_lines']);
	$t->same([10], $xdebug['files']['runtime/modules/testing/tooling/Runner.php']['covered_lines']);
	$t->same([false], $stops);

	$backslash=str_replace('/', '\\', $runner_file);
	$phpdbg_calls=[];
	$phpdbg_runner=new DataphyreUnitTestRunner($root, [], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true,
			'included_before'=>[],
			'xdebug'=>false,
			'xdebug_owned'=>false,
			'phpdbg'=>true,
			'phpdbg_end'=>static function()use(&$phpdbg_calls,$backslash): array {$phpdbg_calls[]='oplog';return [$backslash=>[10=>1, 99=>1, 0=>1]];},
			'phpdbg_get'=>static function()use(&$phpdbg_calls,$runner_file,$outside): array {
				$phpdbg_calls[]='executable';
				return [
					$runner_file=>[10=>[], 11=>[], 0=>[], -1=>[]],
					$outside=>[1=>[]],
					'invalid'=>'not-an-array',
				];
			},
		],
	]);
	$phpdbg_access=$t->nonPublic($phpdbg_runner);
	$phpdbg_access->invoke('captureOrchestratorCoverage');
	$phpdbg=$phpdbg_access->readProperty('orchestrator_coverage');
	$t->same('phpdbg', $phpdbg['engine']);
	$t->same([10, 11], $phpdbg['files']['runtime/modules/testing/tooling/Runner.php']['executable_lines']);
	$t->same([10], $phpdbg['files']['runtime/modules/testing/tooling/Runner.php']['covered_lines']);
	$t->same(['executable','oplog'], $phpdbg_calls);

	$direct_xdebug=$phpdbg_access->invoke('normalizeXdebugCoverage', [$runner_file=>[2=>1], $outside=>[1=>1], 'invalid'=>'value']);
	$t->same([2], $direct_xdebug['runtime/modules/testing/tooling/Runner.php']['covered_lines']);
	$direct_phpdbg=$phpdbg_access->invoke('normalizePhpdbgCoverage', [$runner_file=>[2=>[]], $outside=>[1=>[]], 'invalid'=>'value'], [$runner_file=>'invalid']);
	$t->same([], $direct_phpdbg['runtime/modules/testing/tooling/Runner.php']['covered_lines']);
});

test('changed selection is derived from a real Git worktree and cached for focused reruns', static function(Context $t): void {
	$workspace=$t->workspace('runner-git-selection');
	$t->defer(static function()use($workspace): void {
		$git=$workspace->path('.git');
		if(!is_dir($git)){return;}
		$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($git, FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $entry){if($entry instanceof SplFileInfo && $entry->isFile()){@chmod($entry->getPathname(), 0666);}}
	});
	$paths=[
		'runtime/modules/panel/unit_tests/exact.test.php',
		'runtime/modules/testing/unit_tests/helper.php',
		'runtime/modules/testing/tooling/Runner.php',
		'runtime/modules/core/Framework/Core.php',
		'runtime/modules/sql/Framework/Sql.php',
		'applications/catalog/src/App.php',
		'common/dataphyre/runtime/modules/mail/Framework/Mail.php',
		'composer.json',
		'.codex-tmp/ignored.php',
		'cache/ignored.php',
	];
	foreach($paths as $path){$workspace->file($path, "baseline\n");}
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['changed'=>'HEAD']);
	$access=$t->nonPublic($runner);
	foreach([
		['git', 'init'],
		['git', 'config', 'user.email', 'runner@example.test'],
		['git', 'config', 'user.name', 'Runner Contract'],
		['git', 'add', '.'],
		['git', 'commit', '-m', 'baseline'],
	] as $command){
		$result=$access->invoke('runProcess', $command, 15);
		$t->same(0, $result['exit_code'], implode(' ', $command));
	}
	foreach($paths as $path){$workspace->file($path, "changed\n");}
	$workspace->file('runtime/modules/new_module/Framework/NewThing.php', "new\n");

	$changed=$access->invoke('gitChangedPaths');
	$t->contains('runtime/modules/panel/unit_tests/exact.test.php', $changed);
	$t->contains('applications/catalog/src/App.php', $changed);
	$t->contains('runtime/modules/new_module/Framework/NewThing.php', $changed);
	$selection=$access->invoke('changedTestSelection');
	$t->contains('runtime/modules/panel/unit_tests/exact.test.php', $selection['exact']);
	$t->contains('sql', $selection['modules']);
	$t->contains('mail', $selection['modules']);
	$t->contains('new_module', $selection['modules']);
	$t->contains('catalog', $selection['apps']);
	$t->isTrue($selection['all_framework']);
	$t->isTrue($selection['all_code']);
	$t->contains('runtime/modules/mail/Framework/Mail.php', $selection['paths']);
	$t->containsNone(['.codex-tmp/ignored.php', 'cache/ignored.php'], $selection['paths']);
	$t->same($selection, $access->invoke('changedTestSelection'));
	$nested_root=$workspace->path('common/dataphyre');
	$nested_runner=new DataphyreUnitTestRunner($nested_root, ['changed'=>'HEAD'], [
		'git_root'=>$workspace->root(),
		'git_prefix'=>'common/dataphyre',
	]);
	$nested_access=$t->nonPublic($nested_runner);
	$t->same(['runtime/modules/mail/Framework/Mail.php'], $nested_access->invoke('gitChangedPaths'));
	$t->same(
		['runtime/modules/mail/Framework/Mail.php'],
		$nested_access->invoke('normalizeGitChangedPaths',[
			'',
			'outside/framework.php',
			'common/dataphyre/',
			'common/dataphyre/runtime/modules/mail/Framework/Mail.php',
			'common\\dataphyre\\runtime\\modules\\mail\\Framework\\Mail.php',
		])
	);
	$nested_selection=$nested_access->invoke('changedTestSelection');
	$t->same(['mail'], $nested_selection['modules']);
	$t->same([], $nested_selection['apps']);
	$t->isFalse($nested_selection['all_framework']);
	$t->isFalse($nested_selection['all_code']);
	$modules=$workspace->root().'/runtime/modules';
	$all_code_runner=new DataphyreUnitTestRunner($workspace->root(), ['changed'=>true]);
	$all_code_access=$t->nonPublic($all_code_runner);
	$all_code_access->writeProperty('changed_test_selection', ['exact'=>[], 'modules'=>[], 'apps'=>[], 'paths'=>[], 'all_framework'=>false, 'all_code'=>true]);
	$t->same([$modules], $all_code_access->invoke('frameworkDiscoveryRoots', $modules, 'code'));
	$exact_runner=new DataphyreUnitTestRunner($workspace->root(), ['changed'=>true]);
	$exact_access=$t->nonPublic($exact_runner);
	$exact_access->writeProperty('changed_test_selection', ['exact'=>['runtime/modules/panel/unit_tests/exact.test.php'], 'modules'=>[], 'apps'=>[], 'paths'=>[], 'all_framework'=>false, 'all_code'=>false]);
	$t->same([str_replace('\\', '/', dirname($workspace->path('runtime/modules/panel/unit_tests/exact.test.php')))], $exact_access->invoke('frameworkDiscoveryRoots', $modules, 'json'));
	$t->throwsLike(static fn()=>$access->invoke('runGitPathCommand', $workspace->root().'/missing', ['status']), RuntimeException::class, 'Unable to read changed files');
});

test('changed reasons cover naming source and non-code fallthrough contracts', static function(Context $t): void {
	$workspace=$t->workspace('runner-change-reasons');
	$naming=$workspace->file('other/unit_tests/dataphyre.panel.behavior.test.php', '<?php declare(strict_types=1);');
	$namespace=$workspace->file('other/unit_tests/namespace.test.php', '<?php use Dataphyre\\Panel\\Core\\Panel;');
	$registration=$workspace->file('other/unit_tests/registration.test.php', '<?php framework(["panel"]);');
	$unrelated=$workspace->file('other/unit_tests/unrelated.test.php', '<?php declare(strict_types=1);');
	$selection=['exact'=>[], 'modules'=>['panel'], 'apps'=>[], 'paths'=>[], 'all_framework'=>false, 'all_code'=>false];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['changed'=>true, 'why-selected'=>true]);
	$access=$t->nonPublic($runner);
	$access->writeProperty('changed_test_selection', $selection);
	$records=[];
	foreach([$naming, $namespace, $registration, $unrelated] as $file){
		$record=['scope'=>'framework', 'owner'=>'other', 'manifest'=>$file, 'kind'=>'code', 'cases'=>1, 'app_root'=>null];
		$records[]=$record;
		dp_runner_residual_seed_cases($t, $runner, $record, []);
	}
	$t->same(['test naming contract references changed module: panel'], $access->invoke('changedTestReasons', $records[0]));
	$t->same(['test source references changed module: panel'], $access->invoke('changedTestReasons', $records[1]));
	$t->same(['test source references changed module: panel'], $access->invoke('changedTestReasons', $records[2]));
	$t->same([], $access->invoke('changedTestReasons', $records[3]));
	$t->same([], $access->invoke('changedTestReasons', ['scope'=>'framework', 'owner'=>'other', 'manifest'=>$unrelated.'.json', 'kind'=>'dpanel']));
	$t->isFalse($access->invoke('watchTargetMatches', 'other/path/*.php', $selection));

	$selected=$access->invoke('filterTests', [$records[0], $records[3]]);
	$t->count(1, $selected);
	$t->contains('test naming contract references changed module: panel', $selected[0]['selection_reasons']);
	$access->writeProperty('selection_report', [[
		'scope'=>'framework', 'owner'=>'other', 'manifest'=>'other/unit_tests/dataphyre.panel.behavior.test.php',
		'reasons'=>['test naming contract references changed module: panel'],
	]]);
	[, $output]=dp_runner_residual_capture($t, static fn()=>$access->invoke('writeSelectionReasons'));
	$t->contains('SELECT framework other', $output);
});

test('canonical framework and application discovery ignore retired root-level tests', static function(Context $t): void {
	$workspace=dp_runner_residual_runtime($t, 'runner-residual-discovery');
	$workspace->file('runtime/modules/example/stray.json', '[]');
	$workspace->file('testing/unit_tests/legacy.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('legacy testing case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$workspace->file('runtime/modules/example/unit_tests/local.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('local case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$workspace->file('runtime/modules/global/unit_tests/watcher.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('global watcher', static function(Context $t): void { $t->isTrue(true); })->watches('module:example');
PHP);
	$app_root=$workspace->directory('applications/catalog');
	$workspace->file('applications/catalog/unit_tests/invalid.json', '{"unsupported":true}');
	$workspace->file('applications/catalog/config.json', '{}');
	$workspace->file('applications/catalog/unit_tests/dynamic/generated.json', '[]');
	$workspace->file('applications/catalog/unit_tests/fixture.meta.json', '[]');
	$workspace->file('applications/catalog/unit_tests/fixtures/fixture.json', '[]');
	$workspace->file('applications/catalog/backend/dataphyre/unit_tests/app.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('application case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$workspace->file('applications/catalog/backend/dataphyre/unit_tests/dynamic/generated.test.php', '<?php declare(strict_types=1);');
	$registry=$workspace->file('applications/dataphyre.apps.json', json_encode(['applications'=>[['name'=>'catalog', 'path'=>'applications/catalog']]]));

	$canonical=new DataphyreUnitTestRunner($workspace->root(), ['kind'=>'code', 'no-test-cache'=>true]);
	$canonical_files=array_column($t->nonPublic($canonical)->invoke('discoverFramework'), 'manifest');
	$t->contains(str_replace('\\', '/', $workspace->root()).'/runtime/modules/example/unit_tests/local.test.php', $canonical_files);
	$t->notContains(str_replace('\\', '/', $workspace->root()).'/testing/unit_tests/legacy.test.php', $canonical_files);
	$changed=new DataphyreUnitTestRunner($workspace->root(), ['kind'=>'code', 'changed'=>true, 'no-test-cache'=>true]);
	$changed_access=$t->nonPublic($changed);
	$changed_access->writeProperty('changed_test_selection', ['exact'=>[], 'modules'=>['example'], 'apps'=>[], 'paths'=>[], 'all_framework'=>false, 'all_code'=>false]);
	$changed_files=array_column($changed_access->invoke('discoverFramework'), 'manifest');
	$t->contains(str_replace('\\', '/', $workspace->root()).'/runtime/modules/global/unit_tests/watcher.test.php', $changed_files);

	$app_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$registry]);
	$app_tests=$t->nonPublic($app_runner)->invoke('discoverApps');
	$t->same(['invalid', 'code'], array_column($app_tests, 'kind'));
	$t->same(str_replace('\\', '/', $app_root), $app_tests[0]['app_root']);
	$all=$t->nonPublic($app_runner)->invoke('discover');
	$t->isTrue(count($all)>count($app_tests));

	$only_file=$workspace->file('runtime/modules/only/unit_tests/only.test.php', '<?php declare(strict_types=1);');
	$only_runner=new DataphyreUnitTestRunner($workspace->root(), ['owner'=>'only', 'kind'=>'code', 'cases'=>true]);
	$only_record=['scope'=>'framework', 'owner'=>'only', 'manifest'=>str_replace('\\', '/', $only_file), 'kind'=>'code', 'cases'=>1, 'app_root'=>null];
	$only_case=dp_runner_residual_case(0, 'focused metadata');
	$only_case['only']=true;
	dp_runner_residual_seed_cases($t, $only_runner, $only_record, [$only_case]);
	[, $only_output]=dp_runner_residual_capture($t, static fn()=>$only_runner->list());
	$t->contains('[only]', $only_output);
});

test('JSON workers normalize descriptors and carry selected case indexes', static function(Context $t): void {
	$workspace=$t->workspace('runner-json-workers');
	$worker=$workspace->file('worker.php', <<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents($argv[1]), true);
file_put_contents($payload['output_path'], json_encode([
	'passed'=>true,
	'trace'=>[['passed'=>true, 'assertions'=>1]],
	'duration_seconds'=>0.01,
]));
PHP);
	$entry=$workspace->file('runtime/modules/example/unit_tests/check.php', '<?php function check_passed(): bool { return true; }');
	$list=$workspace->file('runtime/modules/example/unit_tests/list.json', '[{"name":"one"}]');
	$single=$workspace->file('runtime/modules/example/unit_tests/single.json', '{"function":"check_passed","args":[]}');
	$descriptor=$workspace->file('runtime/modules/example/unit_tests/descriptor.json', '{"type":"php","entry":"check.php","callable":"check_passed"}');
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['timeout'=>2, 'coverage'=>$workspace->path('coverage.json')], [
		'framework_worker'=>$worker,
		'app_worker'=>$worker,
	]);
	$access=$t->nonPublic($runner);
	$list_test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$list, 'kind'=>'dpanel', 'cases'=>1, 'case_index'=>0, 'app_root'=>null];
	$job=$access->invoke('workerJob', $list_test, 1);
	$payload=$t->readJsonArray($job['cleanup'][0]);
	$t->isTrue($payload['coverage']);
	$t->isTrue($access->invoke('runWorkerJob', $job)['passed']);
	foreach([
		['scope'=>'framework', 'owner'=>'example', 'manifest'=>$single, 'kind'=>'dpanel_single', 'cases'=>1, 'app_root'=>null],
		['scope'=>'framework', 'owner'=>'example', 'manifest'=>$descriptor, 'kind'=>'descriptor', 'cases'=>1, 'app_root'=>null],
		['scope'=>'app', 'owner'=>'example', 'manifest'=>$list, 'kind'=>'dpanel', 'cases'=>1, 'app_root'=>$workspace->root()],
	] as $index=>$test_record){
		$created=$access->invoke('workerJob', $test_record, $index+2);
		$t->isTrue(isset($created['command'], $created['result_path']));
		$t->isTrue($access->invoke('runWorkerJob', $created)['passed']);
	}
	$t->isTrue(is_file($entry));
});

test('adaptive fallback reports confirmed failures and merges nested coverage parts', static function(Context $t): void {
	$workspace=$t->workspace('runner-adaptive-failure');
	$workspace->file('runtime/modules/testing/tooling/code_worker.php', <<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents($argv[1]), true);
file_put_contents($payload['output_path'], json_encode([
	'passed'=>false,
	'trace'=>[['passed'=>false, 'test_name'=>'isolated failure', 'message'=>'still failing']],
	'duration_seconds'=>0.02,
	'coverage_parts'=>[['engine'=>'included_files', 'files'=>[]]],
]));
exit(1);
PHP);
	$manifest=$workspace->file('runtime/modules/example/unit_tests/failure.test.php', '<?php declare(strict_types=1);');
	$case=dp_runner_residual_case(0, 'isolated failure');
	$batch=[
		'scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>1, 'app_root'=>null,
		'adaptive_speculative'=>true, 'adaptive_cases'=>[$case],
	];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['timeout'=>2]);
	$result=$t->nonPublic($runner)->invoke('retryAdaptiveCases', $batch, [
		'passed'=>false, 'test'=>$batch, 'result'=>['duration_seconds'=>0.1], 'stdout'=>'', 'stderr'=>'',
	]);
	$t->isFalse($result['passed']);
	$t->same('fallback-confirmed-failure', $t->nonPublic($runner)->invoke('adaptiveIsolationSummary')['decisions'][0]['decision']);
	$t->count(1, $result['result']['coverage_parts']);
	$t->contains('case-isolated retries failed', $result['message']);

	$blocked=$workspace->file('blocked', 'not a directory');
	$blocked_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['isolation_index_path'=>$blocked.'/index.json']);
	$t->isFalse($t->nonPublic($blocked_runner)->invoke('rememberAdaptiveCaseIsolation', $batch, 'cannot persist'));
});

test('process runtime seams make parallel launch and timeout failures testable without waiting', static function(Context $t): void {
	$workspace=$t->workspace('runner-process-seams');
	$workspace->file('runtime/modules/testing/tooling/code_worker.php', '<?php declare(strict_types=1);');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/process.test.php', '<?php declare(strict_types=1);');
	$opened=[];
	$terminated=0;
	$now=0;
	$runtime=[
		'open'=>static function(array $command, array $descriptor, array &$pipes)use(&$opened): mixed {
			$pipes=[fopen('php://temp', 'r+')];
			$process=fopen('php://temp', 'r+');
			$opened[]=$command;
			return $process;
		},
		'status'=>static fn(mixed $process): array=>['running'=>true, 'exitcode'=>-1],
		'terminate'=>static function(mixed $process)use(&$terminated): bool {$terminated++; return true;},
		'close'=>static function(mixed $process): int {fclose($process); return 0;},
		'now'=>static function()use(&$now): int {return $now+=100;},
	];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['parallel'=>2], ['process_runtime'=>$runtime]);
	$tests=[];
	foreach([0, 1] as $index){
		$tests[]=[
			'scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>1, 'app_root'=>null,
			'case_index'=>$index, 'case_name'=>'timeout '.$index,
		];
	}
	$results=$t->nonPublic($runner)->invoke('runManyIndependent', $tests);
	$t->count(2, $results);
	$t->same([false, false], array_column($results, 'passed'));
	$t->same(2, $terminated);
	$t->count(2, $opened);
	foreach($results as $result){$t->contains('timed out', $result['message']);}

	$failure_runtime=[
		'open'=>static function(array $command, array $descriptor, array &$pipes): mixed {$pipes=[]; return false;},
	];
	$failure_runner=new DataphyreUnitTestRunner($workspace->root(), ['parallel'=>2], ['process_runtime'=>$failure_runtime]);
	$failure_access=$t->nonPublic($failure_runner);
	$job=[
		'setup'=>'fixture', 'sequence'=>0, 'test'=>$tests[0], 'command'=>['unavailable'], 'timeout_seconds'=>1,
		'result_path'=>$workspace->path('missing-result.json'), 'cleanup'=>[],
		'missing_result_message'=>'missing', 'timeout_message'=>'timeout',
	];
	$start_failure=$failure_access->invoke('startWorkerProcess', $job);
	$t->isFalse($start_failure['result']['passed']);
	$t->contains('Unable to start', $start_failure['result']['message']);
	$parallel_failures=$failure_access->invoke('runManyIndependent', $tests);
	$t->count(2, $parallel_failures);
	$t->same([false, false], array_column($parallel_failures, 'passed'));
	$t->throwsLike(static fn()=>$failure_access->invoke('runProcess', ['unavailable'], 1), RuntimeException::class, 'Unable to start unit-test worker');
});

test('human failures and coverage policies both influence the public run exit code', static function(Context $t): void {
	$workspace=dp_runner_residual_runtime($t, 'runner-public-failures');
	$workspace->file('runtime/modules/example/unit_tests/public.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('failing public case', static function(Context $t): void { $t->same('ready', 'pending'); });
test('passing public case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$human=new DataphyreUnitTestRunner($workspace->root(), [
		'owner'=>'example', 'kind'=>'code', 'name'=>'failing public case',
		'no-test-cache'=>true, 'isolate'=>'case', 'timeout'=>3,
	]);
	[$human_exit, $human_output]=dp_runner_residual_capture($t, static fn()=>$human->run());
	$t->same(1, $human_exit);
	$t->contains('FAIL framework example', $human_output);
	$t->contains('Failed workers: 1', $human_output);

	$policy=new DataphyreUnitTestRunner($workspace->root(), [
		'owner'=>'example', 'kind'=>'code', 'name'=>'passing public case',
		'no-test-cache'=>true, 'isolate'=>'case', 'timeout'=>3, 'json'=>true,
		'coverage'=>true, 'coverage-source'=>'runtime/modules/example', 'coverage-require'=>'xdebug',
	]);
	[$policy_exit, $policy_output]=dp_runner_residual_capture($t, static fn()=>$policy->run());
	$t->same(1, $policy_exit);
	$t->contains('coverage-require-xdebug', json_decode($policy_output, true)['summary']['policy_failures']);
});

test('filesystem and malformed-worker failures name the exact unavailable boundary', static function(Context $t): void {
	$workspace=$t->workspace('runner-explicit-failures');
	$blocked=$workspace->file('blocked', 'not a directory');
	$temp_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['temporary_run_root'=>$blocked]);
	$t->throwsLike(static fn()=>$t->nonPublic($temp_runner)->invoke('temporaryRunRoot'), RuntimeException::class, 'Unable to create unit-test temp directory');

	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$t->throwsLike(static fn()=>$access->invoke('writeProfileReport', [], [], $blocked.'/profile.json'), RuntimeException::class, 'profile output directory');
	$t->throwsLike(static fn()=>$access->invoke('writeCoverageReport', [], $blocked.'/coverage.json'), RuntimeException::class, 'coverage output directory');
	$t->throwsLike(static fn()=>$access->invoke('writeJUnitReport', ['assertions'=>0, 'duration_seconds'=>0.0], [], $blocked.'/junit.xml'), RuntimeException::class, 'JUnit output directory');

	$app_root=$workspace->directory('applications/catalog/backend/dataphyre');
	$workspace->file('applications/catalog/backend/dataphyre/cache', 'blocked cache');
	$app_test=['scope'=>'app', 'owner'=>'catalog', 'manifest'=>$workspace->path('descriptor.json'), 'kind'=>'descriptor', 'cases'=>1, 'app_root'=>dirname(dirname($app_root))];
	$t->throwsLike(static fn()=>$access->invoke('temporaryManifestPath', $app_test), RuntimeException::class, 'unit-test cache directory');

	$blockedCache=new ShardedCaseDiscoveryCache($blocked.'/case-index.json');
	$t->isFalse($blockedCache->store('entry',new CaseDiscoveryCacheEntry('hash',[])));
	$t->isFalse(is_dir($blocked.'/case-index.d'));

	$manifest=$workspace->file('runtime/modules/example/unit_tests/case.test.php', '<?php declare(strict_types=1);');
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$missing_worker=new DataphyreUnitTestRunner($workspace->root());
	$t->throwsLike(static fn()=>$t->nonPublic($missing_worker)->invoke('codeCases', $record), RuntimeException::class, 'Missing code unit-test worker');

	$workspace->file('runtime/modules/testing/tooling/code_worker.php', '<?php fwrite(STDERR, "listing failed"); exit(2);');
	$bad_worker=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$t->throwsLike(static fn()=>$t->nonPublic($bad_worker)->invoke('codeCases', $record), RuntimeException::class, 'listing failed');
	$workspace->file('runtime/modules/testing/tooling/code_worker.php', '<?php fwrite(STDOUT, "stdout failure"); exit(2);');
	$stdout_worker=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$t->throwsLike(static fn()=>$t->nonPublic($stdout_worker)->invoke('codeCases', $record), RuntimeException::class, 'stdout failure');
	$workspace->file('runtime/modules/testing/tooling/code_worker.php', <<<'PHP'
<?php
$payload=json_decode((string)file_get_contents($argv[1]), true);
file_put_contents($payload['output_path'], json_encode([
	'passed'=>false,
	'trace'=>[[
		'message'=>'declaration exploded',
		'exception'=>'RuntimeException',
		'file'=>'fixture.test.php',
		'line'=>17,
	]],
	'output'=>'boot transcript',
]));
exit(1);
PHP);
	$result_worker=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($result_worker)->invoke('codeCases', $record),
		RuntimeException::class,
		'RuntimeException: declaration exploded in fixture.test.php:17 | worker output: boot transcript',
	);
});

test('remaining result and coverage shapes degrade predictably instead of hiding data', static function(Context $t): void {
	$workspace=$t->workspace('runner-shape-residuals');
	$source=$workspace->file('runtime/modules/example/Framework/Source.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['coverage-source'=>'runtime/modules/example']);
	$access=$t->nonPublic($runner);
	$t->same(null, $access->invoke('primaryTrace', ['result'=>['trace'=>['invalid', 42]]]));
	$t->same(['skipped'=>0, 'todo'=>0, 'assertions'=>0], $access->invoke('resultStats', ['result'=>['trace'=>['invalid']]]));

	$summary=$access->invoke('coverageSummary', [[
		'result'=>['coverage'=>[
			'engine'=>'xdebug',
			'files'=>[
				'invalid'=>'not-an-array',
				$source=>['executable'=>4, 'covered'=>3],
			],
		]],
	]]);
	$t->same(4, $summary['executable_lines']);
	$t->same(3, $summary['covered_lines']);
	$t->same(75.0, $summary['line_coverage_percent']);

	[, $failure]=dp_runner_residual_capture($t, static fn()=>$access->invoke('printFailure', [
		'passed'=>false,
		'test'=>['scope'=>'framework', 'owner'=>'example', 'manifest'=>$source, 'case_index'=>2],
		'message'=>'top-level worker failure',
	]));
	$t->contains('top-level worker failure', $failure);
});
