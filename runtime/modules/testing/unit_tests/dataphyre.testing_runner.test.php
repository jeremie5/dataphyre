<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/Runner.php';

suite('First-party test runner')
	->tag('testing', 'runner')
	->group('framework-coverage')
	->maxMillis(15000);

test('standalone discovery narrows to the requested module before scanning test cases', static function(Context $t): void {
	$root=dataphyre_path();
	$runner=new DataphyreUnitTestRunner($root, ['owner'=>'testing', 'kind'=>'code']);
	$roots=$t->nonPublic($runner)->invoke('frameworkDiscoveryRoots', $root.'/runtime/modules', 'code');
	$t->same([$root.'/runtime/modules/testing'], $roots);
});

test('closed-world coverage inventory names every unobserved runtime source file', static function(Context $t): void {
	$runner=new DataphyreUnitTestRunner(dataphyre_path(), ['owner'=>'testing']);
	$summary=$t->nonPublic($runner)->invoke('coverageSummary', []);
	$t->greaterThan(0, $summary['source_inventory_file_count']);
	$t->same($summary['source_inventory_file_count'], $summary['source_inventory_missing_count']);
	$t->isFalse($summary['source_inventory_complete']);
	$t->isFalse($summary['line_coverage_complete']);
	$t->contains('runtime/modules/testing/tooling/bootstrap.php', $summary['missing_source_files']);
	$t->containsNone([
		'runtime/modules/testing/tooling/code_worker.php',
	], $summary['missing_source_files']);
	$t->contains('runtime/modules/testing/tooling/Runner.php', $summary['missing_source_files']);
	$t->contains(['target'=>'runtime/modules/testing/tooling/code_worker.php', 'reason'=>'the transport harness must snapshot coverage before it can serialize its own result'], $summary['source_inventory_exclusions']);
	$runner_file=dataphyre_path().'/runtime/modules/testing/tooling/Runner.php';
	$parts=$t->nonPublic($runner)->invoke('coverageSummary', [[
		'result'=>['coverage_parts'=>[['engine'=>'included_files', 'files'=>[$runner_file]]]],
	]]);
	$t->same(1, $parts['included_file_count']);
	$t->contains('runtime/modules/testing/tooling/Runner.php', $parts['included_files']);
	$scoped=$t->nonPublic($runner)->invoke('coverageSummary', [[
		'result'=>['coverage'=>[
			'engine'=>'phpdbg',
			'files'=>[
				$runner_file=>['executable_ranges'=>'10-11', 'covered_ranges'=>'10-11'],
				dataphyre_path().'/runtime/bootstrap.php'=>['executable_ranges'=>'1-100', 'covered_ranges'=>''],
			],
		]],
	]]);
	$t->same(1, $scoped['line_file_count']);
	$t->same(2, $scoped['observed_line_file_count']);
	$t->same(2, $scoped['executable_lines']);
	$t->same(2, $scoped['covered_lines']);
	$t->same(100.0, $scoped['line_coverage_percent']);
	$t->contains('runtime/bootstrap.php', $scoped['out_of_scope_line_files']);
	$orchestrated=new DataphyreUnitTestRunner(dataphyre_path(), ['owner'=>'testing'], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true,
			'included_before'=>[],
			'xdebug'=>false,
			'xdebug_owned'=>false,
			'phpdbg'=>false,
		],
	]);
	$orchestrated_access=$t->nonPublic($orchestrated);
	$orchestrated_access->invoke('captureOrchestratorCoverage');
	$orchestrated_summary=$orchestrated_access->invoke('coverageSummary', []);
	$t->contains('runtime/modules/testing/tooling/Runner.php', $orchestrated_summary['included_files']);
});

test('framework discovery fingerprints scan source once per runner invocation', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-source-fingerprint');
	$source='runtime/modules/example/Framework/Version.php';
	$workspace->file($source, '<?php return 1;');
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$first=$access->invoke('frameworkDiscoverySourceFingerprint');

	$workspace->file($source, '<?php return 2;');
	$t->same($first, $access->invoke('frameworkDiscoverySourceFingerprint'));

	$fresh_runner=new DataphyreUnitTestRunner($workspace->root());
	$fresh=$t->nonPublic($fresh_runner)->invoke('frameworkDiscoverySourceFingerprint');
	$t->notSame($first, $fresh);
});

test('module bootstrap content participates in code case discovery fingerprints', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-bootstrap');
	$test_file=$workspace->file('runtime/modules/example/unit_tests/example.test.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/testing/tooling/code_worker.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/testing/tooling/bootstrap.php', '<?php declare(strict_types=1);');
	$bootstrap=$workspace->file('runtime/modules/example/testing/bootstrap.php', '<?php // version one');
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$test_file, 'kind'=>'code', 'cases'=>1, 'app_root'=>null];
	$access=$t->nonPublic($runner);
	$t->same([str_replace('\\', '/', $bootstrap)], $access->invoke('testBootstrapFiles', $test));
	$first=$access->invoke('codeCaseFingerprint', $test);
	$workspace->file('runtime/modules/example/testing/bootstrap.php', '<?php // version two');
	$second=$access->invoke('codeCaseFingerprint', $test);
	$t->notSame($first, $second);
});

test('every autoloaded TestKit component participates in code case discovery fingerprints', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-testkit-fingerprint')->installCodeWorkerTooling();
	$testFile=$workspace->file('runtime/modules/example/unit_tests/example.test.php', '<?php declare(strict_types=1);');
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$testFile, 'kind'=>'code', 'cases'=>1, 'app_root'=>null];
	$firstRunner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$first=$t->nonPublic($firstRunner)->invoke('codeCaseFingerprint', $test);

	$component='runtime/modules/testing/tooling/TestKit/AssertionFailed.php';
	$source=file_get_contents($workspace->path($component));
	$t->isTrue(is_string($source));
	$workspace->file($component, (string)$source."\n// fingerprint transition\n");

	$secondRunner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$second=$t->nonPublic($secondRunner)->invoke('codeCaseFingerprint', $test);
	$t->notSame($first, $second);
});

test('changed-run watches express module path application and framework impact directly', static function(Context $t): void {
	$runner=new DataphyreUnitTestRunner(dataphyre_path());
	$selection=[
		'exact'=>[],
		'modules'=>['panel'],
		'apps'=>['shopiro'],
		'paths'=>['runtime/modules/panel/Framework/Core/Panel.php', 'applications/shopiro/src/Orders.php'],
		'all_framework'=>false,
		'all_code'=>false,
	];
	$access=$t->nonPublic($runner);
	$t->isTrue($access->invoke('watchTargetMatches', 'module:*', $selection));
	$t->isTrue($access->invoke('watchTargetMatches', 'module:panel', $selection));
	$t->isTrue($access->invoke('watchTargetMatches', 'path:runtime/modules/**/Panel.php', $selection));
	$t->isTrue($access->invoke('watchTargetMatches', 'app:shop*', $selection));
	$t->isTrue($access->invoke('watchTargetMatches', 'framework', $selection));
	$t->isFalse($access->invoke('watchTargetMatches', 'module:sql', $selection));
});

test('file isolation sends every dependency-free case through one worker with its module DSL bootstrap', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-file-isolation');
	$workspace->installCodeWorkerTooling();
	$workspace->file('runtime/modules/example/testing/bootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
\Dataphyre\Test\Context::extend('example', static fn(): object=>(object)['ready'=>true]);
PHP);
	$test_file=$workspace->file('runtime/modules/example/unit_tests/example.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('module extension is available', static function(Context $t): void { $t->isTrue($t->example()->ready); })->isolation('file');
test('second case shares the file worker', static function(Context $t): void { $t->same(4, 2 + 2); })->isolation('file');
PHP);
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'owner'=>'example',
		'kind'=>'code',
		'no-test-cache'=>true,
		'timeout'=>5,
	]);
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$test_file, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$units=$t->nonPublic($runner)->invoke('expandExecutionUnits', [$test]);
	$t->count(1, $units);
	$t->same('file', $units[0]['isolation']);
	$t->same([0, 1], $units[0]['case_indexes']);
	$t->same(2, $units[0]['cases']);
	$job=$t->nonPublic($runner)->invoke('workerJob', $units[0], 0);
	$result=$t->nonPublic($runner)->invoke('runWorkerJob', $job);
	$t->isTrue($result['passed']);
	$t->count(2, $result['result']['trace']);
});

test('declared performance budgets extend worker deadlines without hidden runner flags', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-declared-deadline');
	$workspace->installCodeWorkerTooling();
	$test_file=$workspace->file('runtime/modules/example/unit_tests/deadline.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;
suite('Declared deadline')->maxMillis(300000);
test('broad contract owns its process budget', static function(Context $t): void { $t->isTrue(true); })->isolation('process');
PHP);
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'owner'=>'example',
		'kind'=>'code',
		'no-test-cache'=>true,
		'timeout'=>2,
	]);
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$test_file, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$access=$t->nonPublic($runner);
	$t->same(2,$access->invoke('caseWorkerTimeoutSeconds',[]));
	$t->same(2,$access->invoke('caseWorkerTimeoutSeconds',['max_millis'=>500]));
	$t->same(4,$access->invoke('caseWorkerTimeoutSeconds',['max_millis'=>2500]));
	$units=$access->invoke('expandExecutionUnits',[$test]);
	$t->count(1,$units);
	$t->same(301,$units[0]['worker_timeout_seconds']);
	$job=$access->invoke('workerJob',$units[0],0);
	$t->same(306,$job['timeout_seconds']);
	$payload=$t->readJsonArray($job['cleanup'][0]);
	$t->same(301,$payload['timeout_seconds']);
});

test('auto isolation treats explicit lifecycle metadata as a strict contract', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-explicit-isolation');
	$workspace->installCodeWorkerTooling();
	$test_file=$workspace->file('runtime/modules/example/unit_tests/lifecycle.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('strict case', static function(Context $t): void { $t->isTrue(true); })->isolation('case');
test('strict process', static function(Context $t): void { $t->isTrue(true); })->isolation('process');
test('strict file one', static function(Context $t): void { $t->isTrue(true); })->isolation('file');
test('strict file two', static function(Context $t): void { $t->isTrue(true); })->isolation('file');
test('single implicit case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['owner'=>'example', 'kind'=>'code', 'no-test-cache'=>true]);
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$test_file, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$units=$t->nonPublic($runner)->invoke('expandExecutionUnits', [$test]);
	$t->count(4, $units);
	$isolations=array_column($units, 'isolation');
	sort($isolations);
	$t->same(['case', 'case', 'file', 'process'], $isolations);
	$file_units=array_values(array_filter($units, static fn(array $unit): bool=>$unit['isolation']==='file'));
	$t->count(1, $file_units);
	$t->same(2, $file_units[0]['cases']);
	$t->isFalse($file_units[0]['adaptive_speculative']);
	foreach($units as $unit){$t->isFalse($unit['adaptive_speculative']);}
});

test('auto isolation remembers files whose speculative batch only fails from shared process state', static function(Context $t): void {
	$workspace=$t->workspace('dataphyre-runner-adaptive-isolation');
	$workspace->installCodeWorkerTooling();
	$test_file=$workspace->file('runtime/modules/example/unit_tests/adaptive.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('first case leaves unmanaged process state', static function(Context $t): void {
	$t->isTrue(define('DP_ADAPTIVE_RUNNER_SENTINEL', true));
});
test('second case expects a clean process', static function(Context $t): void {
	$t->isFalse(defined('DP_ADAPTIVE_RUNNER_SENTINEL'));
});
PHP);
	$options=['owner'=>'example', 'kind'=>'code', 'no-test-cache'=>true, 'timeout'=>5];
	$test=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$test_file, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$runner=new DataphyreUnitTestRunner($workspace->root(), $options);
	$access=$t->nonPublic($runner);
	$units=$access->invoke('expandExecutionUnits', [$test]);
	$t->count(1, $units);
	$t->isTrue($units[0]['adaptive_speculative']);
	$job=$access->invoke('workerJob', $units[0], 0);
	$result=$access->invoke('runWorkerJob', $job);
	$t->isTrue($result['passed']);
	$t->isTrue($result['test']['adaptive_fallback']);
	$t->isTrue($result['result']['adaptive_isolation']['fingerprint_remembered']);
	$t->count(2, $result['result']['trace']);
	$t->same(
		$result['result']['speculative_duration_seconds'] + $result['result']['isolated_retry_duration_seconds'],
		$result['result']['duration_seconds'],
	);
	$t->isTrue(is_array($access->invoke('isAdaptiveQuarantined', $test)));

	$next_runner=new DataphyreUnitTestRunner($workspace->root(), $options);
	$next_units=$t->nonPublic($next_runner)->invoke('expandExecutionUnits', [$test]);
	$t->count(2, $next_units);
	foreach($next_units as $unit){
		$t->same('case', $unit['isolation']);
		$t->isFalse($unit['adaptive_speculative']);
	}
});
