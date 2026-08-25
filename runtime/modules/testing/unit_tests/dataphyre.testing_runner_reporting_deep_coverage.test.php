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

suite('First-party runner reporting')
	->tag('testing', 'runner', 'reporting')
	->group('framework-coverage')
	->contract('testing.runner.reporting', 1)
	->layer('contract')
	->risk('high')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/Runner.php')
	->through('Runner result model', 'human and JSON output', 'CI artifact serializers')
	->isolation('process')
	->maxMillis(15000);

/** @return array{0:mixed,1:string} */
function dp_runner_capture_output(Context $t, callable $operation): array {
	$capture=$t->captureOutput($operation);
	return [$capture->result(), $capture->output()];
}

/** @return array<string,mixed> */
function dp_runner_reporting_summary(array $replace=[]): array {
	return array_replace([
		'workers_total'=>2,
		'workers_passed'=>1,
		'workers_failed'=>1,
		'cases_declared'=>3,
		'skipped'=>1,
		'todo'=>1,
		'assertions'=>7,
		'duration_seconds'=>1.25,
		'discovery_seconds'=>0.25,
		'execution_seconds'=>1.0,
		'discovery_cache_hits'=>2,
		'discovery_cache_misses'=>1,
		'adaptive_isolation'=>['speculative_files'=>1, 'fallbacks'=>0, 'case_isolated_from_history'=>0, 'index'=>'cache/isolation.json', 'decisions'=>[]],
		'dynamic_skipped'=>2,
		'policy_failures'=>['todo'],
	], $replace);
}

/** @return array<int,array<string,mixed>> */
function dp_runner_reporting_results(string $manifest): array {
	return [
		[
			'passed'=>true,
			'test'=>[
				'scope'=>'framework', 'owner'=>'testing', 'manifest'=>$manifest, 'kind'=>'code',
				'case_name'=>'passing contract', 'case_stable_id'=>'test.pass', 'isolation'=>'case',
			],
			'result'=>[
				'duration_seconds'=>0.4,
				'trace'=>[[
					'test_name'=>'passing contract', 'stable_id'=>'test.pass', 'passed'=>true,
					'file'=>$manifest, 'line'=>12, 'execution_time'=>0.4, 'assertions'=>4,
					'layer'=>'unit', 'risk'=>'high', 'isolation'=>'case',
					'issue_policy'=>'fail', 'output_policy'=>'forbid', 'assertion_policy'=>'require',
					'contract'=>['name'=>'testing.runner', 'version'=>'2'],
					'repeat_index'=>1, 'repeat_total'=>2,
					'through'=>['fixture', 'worker'],
				]],
			],
			'exit_code'=>0, 'stdout'=>'', 'stderr'=>'',
		],
		[
			'passed'=>true,
			'test'=>['scope'=>'framework', 'owner'=>'testing', 'manifest'=>$manifest, 'kind'=>'code', 'case_name'=>'intentional skip'],
			'result'=>[
				'duration_seconds'=>0.1,
				'trace'=>[[
					'test_name'=>'intentional skip', 'passed'=>true, 'skipped'=>true, 'todo'=>false,
					'message'=>'Optional engine unavailable.', 'execution_time'=>0.1, 'assertions'=>0,
				]],
			],
			'exit_code'=>0, 'stdout'=>'', 'stderr'=>'',
		],
		[
			'passed'=>false,
			'test'=>['scope'=>'framework', 'owner'=>'testing', 'manifest'=>$manifest, 'kind'=>'code', 'case_name'=>'failing contract'],
			'result'=>[
				'duration_seconds'=>0.75,
				'trace'=>[[
					'test_name'=>'failing contract', 'passed'=>false, 'todo'=>false,
					'message'=>'Expected contract value.', 'file'=>$manifest, 'line'=>44,
					'execution_time'=>0.75, 'assertions'=>3,
					'details'=>['expected'=>'ready', 'actual'=>'pending', 'meta'=>['diff'=>"-ready\n+pending"]],
				]],
			],
			'exit_code'=>1, 'stdout'=>'worker output', 'stderr'=>'worker diagnostic',
		],
		[
			'passed'=>false,
			'test'=>['scope'=>'app', 'owner'=>'catalog', 'manifest'=>$manifest, 'kind'=>'descriptor'],
			'message'=>'Worker result was missing.',
			'exit_code'=>1, 'stdout'=>'missing output', 'stderr'=>'',
		],
	];
}

test('CLI help list and empty run paths remain useful without installed tests', static function(Context $t): void {
	$workspace=$t->workspace('runner-cli-reporting');
	$state=dataphyre_unit_test_start_orchestrator_coverage(false);
	$t->isFalse($state['enabled']);
	$t->isFalse($state['xdebug']);
	$t->isFalse($state['phpdbg']);

	[$help_exit, $help]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', 'help'], $workspace->root(), [
		'display_name'=>'Fixture', 'entrypoint'=>'fixture tests',
	]));
	$t->same(0, $help_exit);
	$t->contains('Fixture unit-test tool', $help);
	$t->contains('fixture tests run', $help);
	$t->contains('Mutation diagnostics', $help);
	$t->contains('--timeout=N', $help);
	$t->contains('--memory=limit', $help);
	$t->contains('--coverage-memory-default=limit', $help);
	$t->contains('12-second timeout and 256M memory limit', $help);
	$t->contains('coverageMemoryLimit()', $help);
	[$short_help_exit, $short_help]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', '--help'], $workspace->root()));
	$t->same(0, $short_help_exit);
	$t->contains('Dataphyre unit-test tool', $short_help);
	foreach([
		['run', '--help'],
		['ci', '-h'],
		['list', '--help'],
		['--scope=framework', '--help'],
	] as $help_arguments){
		[$subcommand_help_exit, $subcommand_help]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(
			array_merge(['fixture'], $help_arguments),
			$workspace->root(),
		));
		$t->same(0, $subcommand_help_exit, implode(' ', $help_arguments));
		$t->contains('Dataphyre unit-test tool', $subcommand_help, implode(' ', $help_arguments));
		$t->notContains('No unit-test cases matched', $subcommand_help, implode(' ', $help_arguments));
	}

	[$list_exit, $list]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', 'list', '--scope=framework'], $workspace->root()));
	$t->same(0, $list_exit);
	$t->same("Matched 0 unit-test manifests.\n", $list);
	[$json_list_exit, $json_list]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', 'list', '--scope=framework', '--json'], $workspace->root()));
	$t->same(0, $json_list_exit);
	$t->same(0, json_decode($json_list, true)['matched']);

	[$run_exit, $run]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', 'run', '--scope=framework'], $workspace->root()));
	$t->same(0, $run_exit);
	$t->same("No unit-test cases matched the requested scope.\n", $run);
	[$implicit_exit, $implicit]=dp_runner_capture_output($t, static fn()=>dataphyre_unit_test_main(['fixture', '--scope=framework', '--json'], $workspace->root()));
	$t->same(0, $implicit_exit);
	$t->same(0, json_decode($implicit, true)['summary']['workers_total']);
	$t->same(1, dataphyre_unit_test_main(['fixture', 'run', '--scope=wrong'], $workspace->root()));
});

test('failure rendering and summary output preserve actionable context', static function(Context $t): void {
	$workspace=$t->workspace('runner-human-output');
	$manifest=$workspace->file('runtime/modules/testing/unit_tests/failing.test.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$results=dp_runner_reporting_results($manifest);

	[, $failure]=dp_runner_capture_output($t, static fn()=>$access->invoke('printFailure', $results[2]));
	$t->contains('FAIL framework testing', $failure);
	$t->contains('Expected contract value.', $failure);
	$t->contains('expected: "ready"', $failure);
	$t->contains('actual: "pending"', $failure);
	$t->contains('diff:', $failure);
	$t->contains('stderr: worker diagnostic', $failure);
	$t->contains('stdout: worker output', $failure);

	$exception_result=$results[2];
	$exception_result['result']['trace']=[
		'not-a-trace',
		['function'=>'fixture_check', 'message'=>'fixture failed', 'details'=>['exception'=>'RuntimeException: fixture']],
	];
	[, $exception_failure]=dp_runner_capture_output($t, static fn()=>$access->invoke('printFailure', $exception_result));
	$t->contains('fixture_check: fixture failed', $exception_failure);
	$t->contains('exception: RuntimeException: fixture', $exception_failure);

	[, $empty]=dp_runner_capture_output($t, static fn()=>$access->invoke('writeRunOutput', dp_runner_reporting_summary(['workers_total'=>0]), []));
	$t->same("No unit-test cases matched the requested scope.\n", $empty);
	[, $summary]=dp_runner_capture_output($t, static fn()=>$access->invoke('writeRunOutput', dp_runner_reporting_summary(), [$results[2]]));
	$t->contains('Unit tests: 1/2 workers passed, 3 cases declared', $summary);
	$t->contains('skipped=1', $summary);
	$t->contains('todo=1', $summary);
	$t->contains('assertions=7', $summary);
	$t->contains('Dynamic/generated manifests skipped by default: 2', $summary);
	$t->contains('Policy failure: todo', $summary);
	$t->contains('Failed workers: 1', $summary);
	$single_summary=dp_runner_reporting_summary(['workers_total'=>1, 'workers_passed'=>1, 'workers_failed'=>0, 'cases_declared'=>1, 'skipped'=>0, 'todo'=>0, 'assertions'=>0, 'dynamic_skipped'=>0, 'policy_failures'=>[]]);
	[, $single]=dp_runner_capture_output($t, static fn()=>$access->invoke('writeRunOutput', $single_summary, []));
	$t->contains('1/1 worker passed, 1 case declared', $single);

	$json_runner=new DataphyreUnitTestRunner($workspace->root(), ['json'=>true]);
	[, $json]=dp_runner_capture_output($t, static fn()=>$t->nonPublic($json_runner)->invoke('writeRunOutput', dp_runner_reporting_summary(), [$results[2]]));
	$decoded=json_decode($json, true);
	$t->same(2, $decoded['summary']['workers_total']);
	$t->same('runtime/modules/testing/unit_tests/failing.test.php', $decoded['failures'][0]['test']['relative_manifest']);
});

test('result helpers expose stable statistics paths and failure text', static function(Context $t): void {
	$workspace=$t->workspace('runner-result-helpers');
	$manifest=$workspace->file('runtime/modules/testing/unit_tests/case.test.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$results=dp_runner_reporting_results($manifest);

	$t->same(['skipped'=>0, 'todo'=>0, 'assertions'=>4], $access->invoke('resultStats', $results[0]));
	$t->same(['skipped'=>1, 'todo'=>0, 'assertions'=>0], $access->invoke('resultStats', $results[1]));
	$t->same(['skipped'=>0, 'todo'=>0, 'assertions'=>0], $access->invoke('resultStats', $results[3]));
	$t->same($results[0]['result']['trace'][0], $access->invoke('primaryTrace', $results[0]));
	$t->same(null, $access->invoke('primaryTrace', $results[3]));
	$t->same('Expected contract value.', $access->invoke('failureMessage', $results[2], $results[2]['result']['trace'][0]));
	$t->same('Worker result was missing.', $access->invoke('failureMessage', $results[3], null));
	$t->same('Unit-test worker failed.', $access->invoke('failureMessage', ['passed'=>false], null));
	$failure_text=$access->invoke('failureText', $results[2], $results[2]['result']['trace'][0]);
	$t->contains('details: {"expected":"ready"', $failure_text);
	$t->contains('stderr: worker diagnostic', $failure_text);
	$t->contains('stdout: worker output', $failure_text);

	$relative=$access->invoke('relativeFailures', [$results[2], ['passed'=>false, 'test'=>'invalid']]);
	$t->same('runtime/modules/testing/unit_tests/case.test.php', $relative[0]['test']['relative_manifest']);
	$t->same('invalid', $relative[1]['test']);
	$cases=$access->invoke('relativeCodeCases', [['file'=>$manifest], ['name'=>'without file']]);
	$t->same('runtime/modules/testing/unit_tests/case.test.php', $cases[0]['file']);
	$t->isFalse(isset($cases[1]['file']));
	$t->isTrue($access->invoke('primaryTraceSkipped', $results[1]));
	$t->isFalse($access->invoke('primaryTraceSkipped', $results[0]));
});

test('JUnit profile annotations and aggregate CI artifacts retain semantic metadata', static function(Context $t): void {
	$workspace=$t->workspace('runner-ci-artifacts');
	$manifest=$workspace->file('runtime/modules/testing/unit_tests/case.test.php', '<?php declare(strict_types=1);');
	$results=dp_runner_reporting_results($manifest);
	$results[2]['stdout']="worker \x1B[31mred\x1B[0m output\x00";
	$summary=dp_runner_reporting_summary(['workers_total'=>4, 'workers_passed'=>2, 'workers_failed'=>2, 'cases_declared'=>4]);
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['profile-top'=>2]);
	$access=$t->nonPublic($runner);

	$junit=$workspace->path('reports/unit-tests.xml');
	$access->invoke('writeJUnitReport', $summary, $results, $junit);
	$xml=(string)file_get_contents($junit);
	$t->contains('<testsuite name="Dataphyre unit tests" tests="4" assertions="7" failures="2" skipped="1" time="1.25">', $xml);
	$t->contains('classname="framework.testing"', $xml);
	$t->contains('file="runtime/modules/testing/unit_tests/case.test.php" line="12"', $xml);
	$t->contains('<property name="dataphyre.stable_id" value="test.pass" />', $xml);
	$t->contains('<property name="dataphyre.contract" value="testing.runner" />', $xml);
	$t->contains('<property name="dataphyre.repeat" value="1/2" />', $xml);
	$t->contains('<property name="dataphyre.through" value="fixture -&gt; worker" />', $xml);
	$t->contains('<skipped message="Optional engine unavailable." />', $xml);
	$t->contains('<failure message="Expected contract value.">', $xml);
	$t->contains('<failure message="Worker result was missing.">', $xml);
	$t->notContains("\x1B", $xml);
	$t->notContains("\x00", $xml);
	$t->contains('worker [31mred[0m output', $xml);

	$normalized_results=[
		[
			'passed'=>true,
			'test'=>[
				'scope'=>'framework', 'owner'=>'testing', 'manifest'=>$manifest, 'kind'=>'descriptor',
				'case_name'=>'expected diagnostic failure',
			],
			'result'=>[
				'duration_seconds'=>0.1,
				'trace'=>[[
					'test_name'=>'nested diagnostic', 'passed'=>false,
					'message'=>'Expected inner failure.', 'execution_time'=>0.1,
				]],
			],
			'exit_code'=>0, 'stdout'=>'', 'stderr'=>'',
		],
		[
			'passed'=>false,
			'test'=>[
				'scope'=>'framework', 'owner'=>'testing', 'manifest'=>$manifest, 'kind'=>'code',
				'case_name'=>'worker crashed after trace',
			],
			'message'=>'Worker wrapper failed.',
			'result'=>[
				'duration_seconds'=>0.2,
				'trace'=>[[
					'test_name'=>'completed trace before crash', 'passed'=>true,
					'execution_time'=>0.1,
				]],
			],
			'exit_code'=>1, 'stdout'=>'', 'stderr'=>'worker crash',
		],
	];
	$normalized_junit=$workspace->path('reports/normalized-outcomes.xml');
	$access->invoke(
		'writeJUnitReport',
		dp_runner_reporting_summary(['assertions'=>0, 'duration_seconds'=>0.3]),
		$normalized_results,
		$normalized_junit,
	);
	$normalized_xml=(string)file_get_contents($normalized_junit);
	$t->contains('<testsuite name="Dataphyre unit tests" tests="3" assertions="0" failures="1" skipped="0" time="0.3">', $normalized_xml);
	$t->same(1, substr_count($normalized_xml, '<failure '));
	$t->contains(
		'<property name="dataphyre.outcome_normalization" value="passing-worker-overrides-non-authoritative-trace" />',
		$normalized_xml,
	);
	$t->contains(
		'<property name="dataphyre.outcome_normalization" value="worker-failure-without-failing-trace" />',
		$normalized_xml,
	);
	$t->contains('<failure message="Worker wrapper failed.">', $normalized_xml);

	$profile=$workspace->path('reports/profile.json');
	[, $profile_output]=dp_runner_capture_output($t, static fn()=>$access->invoke('writeProfileReport', $summary, $results, $profile));
	$profile_data=json_decode((string)file_get_contents($profile), true);
	$t->same(4, count($profile_data['cases']));
	$t->same('failing contract', $profile_data['cases'][0]['name']);
	$t->same('test.pass', $profile_data['cases'][1]['stable_id']);
	$t->same('testing.runner', $profile_data['cases'][1]['contract']['name']);
	$t->same(['fixture', 'worker'], $profile_data['cases'][1]['through']);
	$t->contains('SLOW 750.0ms testing failing contract', $profile_output);
	$t->contains('Unit-test profile written to reports/profile.json', $profile_output);

	$access->invoke('writeGithubAnnotations', $results);
	$json_runner=new DataphyreUnitTestRunner($workspace->root(), [
		'json'=>true,
		'junit'=>'reports/all.xml',
		'coverage'=>'reports/coverage.json',
		'profile'=>'reports/all-profile.json',
		'github-annotations'=>true,
		'coverage-source'=>'runtime/modules/testing/tooling',
	]);
	$t->nonPublic($json_runner)->invoke('writeCiArtifacts', $summary, $results);
	foreach(['reports/all.xml', 'reports/coverage.json', 'reports/all-profile.json'] as $artifact){
		$t->isTrue(is_file($workspace->path($artifact)), $artifact);
	}
});

test('coverage aggregation scopes line engines and enforces only requested policies', static function(Context $t): void {
	$workspace=$t->workspace('runner-coverage-reporting');
	$a=$workspace->file('runtime/modules/example/Framework/A.php', '<?php declare(strict_types=1);');
	$b=$workspace->file('runtime/modules/example/Framework/B.php', '<?php declare(strict_types=1);');
	$c=$workspace->file('runtime/modules/example/Framework/C.php', "<?php\ntry { \$covered=true; }\nfinally {\n}\n");
	$workspace->file('runtime/modules/example/unit_tests/A.test.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/example/documentation/Example.md.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/example/static-analysis/fixtures/Builder.php', '<?php declare(strict_types=1);');
	$outside=$workspace->file('runtime/bootstrap.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-source'=>' runtime/modules/example, missing ',
		'coverage-exclude'=>'*B.php, ignored',
	]);
	$access=$t->nonPublic($runner);

	$inventory=$access->invoke('coverageSourceInventory');
	$t->contains(str_replace('\\', '/', realpath($a)), $inventory);
	$t->containsNone([str_replace('\\', '/', realpath($b))], $inventory);
	$t->contains(str_replace('\\', '/', realpath($c)), $inventory);
	$t->count(2, $inventory);
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/example/unit_tests/A.test.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/example/documentation/Example.md.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/example/static-analysis/fixtures/Builder.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/testing/tooling/code_worker.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/dpanel/kernel/dpanel.worker.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/testing/tooling/WorkerCoverage.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/testing/tooling/CoverageSubprocess.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'common/dataphyre/runtime/modules/testing/tooling/code_worker.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', '/workspace/vendor/dataphyre/dataphyre/runtime/modules/testing/tooling/WorkerCoverage.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/stripe/src/lib/Stripe.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/'.'cj'.'dropshipping/'.'cj'.'dropshipping-client/src/CJClient.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/sql/third_party/adminer/adminer.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/fulltext_engine/stopwords/en_stopwords.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/profanity/datasets/en/product.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/panel/testing/panel_test_runner.php'));
	$t->isTrue($access->invoke('coverageSourceExcluded', 'runtime/modules/example/Framework/B.php'));
	$t->isFalse($access->invoke('coverageSourceExcluded', 'runtime/modules/example/Framework/A.php'));
	$t->contains([
		'target'=>'**/static-analysis/**',
		'reason'=>'analyzer fixtures and generated contracts describe source types; they are not request-time product code',
	], $access->invoke('coverageSourceExclusions'));
	$t->contains(['target'=>'*B.php', 'reason'=>'explicit CLI exclusion'], $access->invoke('coverageSourceExclusions'));

	$t->same('', $access->invoke('coverageAbsolutePath', ''));
	$t->same(str_replace('\\', '/', realpath($a)), $access->invoke('coverageAbsolutePath', 'runtime/modules/example/Framework/A.php'));
	if(str_starts_with(str_replace('\\','/',$a),'/')){
		$t->same(str_replace('\\','/',realpath($a)),$access->invoke('coverageAbsolutePath',$a),'POSIX absolute coverage paths must never be re-rooted as relative paths.');
	}
	$t->same(str_replace('\\', '/', $workspace->root()).'/missing.php', $access->invoke('coverageAbsolutePath', 'missing.php'));
	$t->same([1, 2, 3, 5, 9], iterator_to_array($access->invoke('coverageRangeLines', '1-3, 5, 9, 7-6, bad, '), false));

	$results=[
		['result'=>['coverage'=>['engine'=>'included_files', 'files'=>[$a, $outside]]]],
		['result'=>['coverage_parts'=>[
			['engine'=>'xdebug', 'files'=>[
				$a=>['executable_ranges'=>'1-3,5', 'covered_ranges'=>'1,3'],
			], 'included_files'=>[$c]],
			['engine'=>'phpdbg', 'files'=>[
				$c=>[
					'raw_executable_lines'=>[1,2,3],
					'executable_lines'=>[1,2],
					'covered_lines'=>[1],
					'ignored_lines'=>[3],
					'ignored_by_reason'=>['finally-header'=>[3]],
				],
			]],
		]]],
	];
	$summary=$access->invoke('coverageSummary', $results);
	$t->same(['included_files', 'xdebug', 'phpdbg'], $summary['engines']);
	$t->same(2, $summary['included_file_count']);
	$t->same(3, $summary['observed_included_file_count']);
	$t->contains('runtime/modules/example/Framework/C.php', $summary['included_files']);
	$t->same(1, $summary['out_of_scope_included_file_count']);
	$t->contains('runtime/bootstrap.php', $summary['out_of_scope_included_files']);
	$t->same(2, $summary['line_file_count']);
	$t->same(3, $summary['covered_lines']);
	$t->same(6, $summary['executable_lines']);
	$t->same(7, $summary['raw_executable_lines']);
	$t->same(1, $summary['ignored_executable_lines']);
	$t->same([
		'contract'=>'phpdbg-structural-token-only',
		'ignored_executable_lines'=>1,
		'ignored_by_reason'=>['finally-header'=>1],
	], $summary['coverage_normalization']);
	$t->same(50.0, $summary['line_coverage_percent']);
	$t->same([2, 5], $summary['line_files']['runtime/modules/example/Framework/A.php']['uncovered_lines']);
	$t->same([2], $summary['line_files']['runtime/modules/example/Framework/C.php']['uncovered_lines']);
	$t->same([3], $summary['line_files']['runtime/modules/example/Framework/C.php']['ignored_executable_lines']);
	$t->isTrue($summary['source_inventory_complete']);
	$t->isFalse($summary['line_coverage_complete']);
	$t->isTrue($summary['coverage_lanes']['assignment_complete']);
	$t->same(6,$summary['coverage_lanes']['source_file_count']);
	$t->same(3,$summary['coverage_lanes']['line_coverage_file_count']);
	$t->same(3,$summary['coverage_lanes']['contract_file_count']);
	$t->same(3,$summary['coverage_lanes']['lanes']['first-party-exact']['file_count']);
	$t->same(3,$summary['coverage_lanes']['lanes']['test-harness']['file_count']);

	$report=$workspace->path('reports/coverage.json');
	$access->invoke('writeCoverageReport', $results, $report);
	$t->equals(50.0, json_decode((string)file_get_contents($report), true)['line_coverage_percent']);

	$disabled=new DataphyreUnitTestRunner($workspace->root());
	$t->same([], $t->nonPublic($disabled)->invoke('coveragePolicyFailures', []));
	$strict=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-source'=>'runtime/modules/example',
		'coverage-min-files'=>5,
		'coverage-min-percent'=>100,
		'coverage-closed-world'=>true,
		'coverage-require'=>'xdebug',
	]);
	$failures=$t->nonPublic($strict)->invoke('coveragePolicyFailures', []);
	$t->contains('coverage-min-files', $failures);
	$t->contains('coverage-line-engine-missing', $failures);
	$t->contains('coverage-source-files-missing', $failures);
	$t->contains('coverage-require-xdebug', $failures);
	$percent=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-source'=>'runtime/modules/example',
		'coverage-min-percent'=>40,
	]);
	$t->same([], $t->nonPublic($percent)->invoke('coveragePolicyFailures', $results));
	$exact=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-source'=>'runtime/modules/example',
		'coverage-min-percent'=>100,
	]);
	$t->contains('coverage-min-percent', $t->nonPublic($exact)->invoke('coveragePolicyFailures', $results));
});

test('explicit coverage roots resolve through the host without changing framework compatibility or inventory epochs', static function(Context $t): void {
	$workspace=$t->workspace('runner-explicit-coverage-roots');
	$legacy=$workspace->file('common/dataphyre/runtime/modules/example/Framework/Legacy.php', '<?php final class Legacy {}');
	$appA=$workspace->file('applications/catalog/api/lib/Product.php', '<?php final class Product {}');
	$appB=$workspace->file('applications/catalog/api/lib/Order.php', '<?php final class Order {}');
	$workspace->file('applications/catalog/api/lib/unit_tests/Product.test.php', '<?php return true;');
	$appRoot=$workspace->path('applications/catalog/api/lib');
	$absoluteSource=str_replace('\\', '/', (string)realpath($appA));
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage-source'=>$absoluteSource.', applications/catalog/api/lib, runtime/modules/example/Framework',
	]);
	$access=$t->nonPublic($runner);
	$resolvedRoots=[
		$absoluteSource,
		str_replace('\\', '/', (string)realpath($appRoot)),
		str_replace('\\', '/', (string)realpath(dirname($legacy))),
	];
	$t->same($resolvedRoots, $access->invoke('coverageSourceRoots'));
	$workspace->file('runtime/modules/example/Framework/Shadow.php', '<?php final class Shadow {}');
	$t->same($resolvedRoots, $access->invoke('coverageSourceRoots'), 'Resolved roots must not switch from framework to host ownership during a run.');

	$appOnly=new DataphyreUnitTestRunner($workspace->root(), ['coverage-source'=>'applications/catalog/api/lib']);
	$appAccess=$t->nonPublic($appOnly);
	$inventory=$appAccess->invoke('coverageSourceInventory');
	$t->same([
		str_replace('\\', '/', (string)realpath($appB)),
		str_replace('\\', '/', (string)realpath($appA)),
	], $inventory);
	$t->same($inventory, $appAccess->invoke('coverageSourceInventory'));
	$epoch=$appAccess->invoke('sourceEpochSnapshot');
	$t->same($epoch, $appAccess->invoke('sourceEpochSnapshot'));
	$t->same(3, $epoch['file_count']);
	$t->same([
		'applications/catalog/api/lib/Order.php',
		'applications/catalog/api/lib/Product.php',
		'applications/catalog/api/lib/unit_tests/Product.test.php',
	], array_keys($epoch['files']));
});

test('PHPDBG normalization runs after isolated worker coverage is unioned',static function(Context $t): void {
	$workspace=$t->workspace('runner-union-normalization');
	$source=$workspace->file('runtime/modules/example/Framework/SwitchContract.php',<<<'PHP'
<?php
switch($state){
	case 'ready':
		return true;
	default:
		return false;
}
PHP);
	$runner=new DataphyreUnitTestRunner($workspace->root(),['coverage-source'=>'runtime/modules/example']);
	$summary=$t->nonPublic($runner)->invoke('coverageSummary',[[
		'result'=>['coverage_parts'=>[
			['engine'=>'phpdbg','included_files'=>[$source],'files'=>[$source=>[
				'raw_executable_ranges'=>'2-7','executable_ranges'=>'2-6','covered_ranges'=>'2','ignored_ranges'=>'7','ignored_reasons'=>['brace-only'=>'7'],
			]]],
			['engine'=>'phpdbg','included_files'=>[$source],'files'=>[$source=>[
				'raw_executable_ranges'=>'2-7','executable_ranges'=>'2,4-6','covered_ranges'=>'2,4','ignored_ranges'=>'3,7','ignored_reasons'=>['brace-only'=>'7','covered-switch-label'=>'3'],
			]]],
		]],
	]]);
	$file=$summary['line_files']['runtime/modules/example/Framework/SwitchContract.php'];
	$t->same([5,6],$file['uncovered_lines'],'The genuinely untested default arm remains certifying evidence.');
	$t->same([3,7],$file['ignored_executable_lines']);
	$t->same(['brace-only'=>[7],'covered-switch-label'=>[3]],$file['ignored_executable_reasons']);
	$t->same(2,$file['covered']);
	$t->same(4,$file['executable']);
});

test('non-PHPDBG coverage preserves only ignored reasons backed by ignored lines',static function(Context $t): void {
	$workspace=$t->workspace('runner-xdebug-ignore-reasons');
	$source=$workspace->file('runtime/modules/example/Framework/XdebugContract.php',"<?php\nreturn true;\n");
	$runner=new DataphyreUnitTestRunner($workspace->root(),['coverage-source'=>'runtime/modules/example']);
	$summary=$t->nonPublic($runner)->invoke('coverageSummary',[[
		'result'=>['coverage'=>[
			'engine'=>'xdebug',
			'included_files'=>[$source],
			'files'=>[$source=>[
				'raw_executable_ranges'=>'1-3',
				'executable_ranges'=>'1',
				'covered_ranges'=>'1',
				'ignored_ranges'=>'2-3',
				'ignored_reasons'=>[
					'proven-transport-artifact'=>'2-3',
					'not-ignored'=>'1',
				],
			]],
		]],
	]]);
	$file=$summary['line_files']['runtime/modules/example/Framework/XdebugContract.php'];
	$t->same([2,3],$file['ignored_executable_lines']);
	$t->same(['proven-transport-artifact'=>[2,3]],$file['ignored_executable_reasons']);
});

test('coverage inventory accepts one source file and orchestrator paths stay inside framework ownership', static function(Context $t): void {
	$workspace=$t->workspace('runner-orchestrator-reporting');
	$source=$workspace->file('runtime/modules/example/Framework/Only.php', '<?php declare(strict_types=1);');
	$outside=$t->tempFile('<?php declare(strict_types=1);', 'runner-outside');
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['coverage-source'=>'runtime/modules/example/Framework/Only.php'], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true, 'included_before'=>[], 'xdebug'=>false, 'xdebug_owned'=>false, 'phpdbg'=>false,
		],
	]);
	$access=$t->nonPublic($runner);
	$t->same([str_replace('\\', '/', realpath($source))], $access->invoke('coverageSourceInventory'));
	$t->same('runtime/modules/example/Framework/Only.php', $access->invoke('orchestratorCoverageRelative', $source));
	$t->same(null, $access->invoke('orchestratorCoverageRelative', $outside));
	$actual_runner=new DataphyreUnitTestRunner(dataphyre_path(), [], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true, 'included_before'=>[], 'xdebug'=>false, 'xdebug_owned'=>false, 'phpdbg'=>false,
		],
	]);
	$actual_access=$t->nonPublic($actual_runner);
	$actual_access->invoke('captureOrchestratorCoverage');
	$coverage=$actual_access->readProperty('orchestrator_coverage');
	$t->same('included_files', $coverage['engine']);
	$t->contains('runtime/modules/testing/tooling/Runner.php', $coverage['files']);
	$actual_access->invoke('captureOrchestratorCoverage');

	$disabled=new DataphyreUnitTestRunner($workspace->root());
	$disabled_access=$t->nonPublic($disabled);
	$disabled_access->invoke('captureOrchestratorCoverage');
	$t->same([], $disabled_access->readProperty('orchestrator_coverage'));
});

test('orchestrator and every code-worker payload recognize resolved application coverage roots', static function(Context $t): void {
	$workspace=$t->workspace('runner-application-coverage-evidence');
	$appSource=$workspace->file('applications/catalog/api/lib/Product.php', "<?php\nreturn true;\n");
	$appRoot=str_replace('\\', '/', (string)realpath(dirname($appSource)));
	$frameworkSource=$workspace->file('common/dataphyre/runtime/modules/example/Framework/Outside.php', "<?php\nreturn true;\n");
	$worker=$workspace->file('common/dataphyre/runtime/modules/testing/tooling/code_worker.php', <<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents($argv[count($argv)-1]),true);
file_put_contents($payload['rootpath']['root'].'captured-code-worker-payload.json',json_encode($payload));
file_put_contents($payload['output_path'],json_encode(['passed'=>true,'cases'=>[]]));
PHP);
	$manifest=$workspace->file('common/dataphyre/runtime/modules/example/unit_tests/payload.test.php', '<?php declare(strict_types=1);');
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage-source'=>'applications/catalog/api/lib',
		'no-test-cache'=>true,
	], [
		'orchestrator_coverage_state'=>[
			'enabled'=>true,
			'included_before'=>[],
			'xdebug'=>true,
			'xdebug_owned'=>false,
			'phpdbg'=>false,
			'xdebug_get'=>static fn(): array=>[
				$appSource=>[2=>1],
				$frameworkSource=>[2=>1],
			],
		],
	]);
	$access=$t->nonPublic($runner);
	$t->same('applications/catalog/api/lib/Product.php', $access->invoke('orchestratorCoverageRelative', $appSource));
	$t->same(null, $access->invoke('orchestratorCoverageRelative', $frameworkSource));
	$access->invoke('captureOrchestratorCoverage');
	$coverage=$access->readProperty('orchestrator_coverage');
	$t->same(['applications/catalog/api/lib/Product.php'], array_keys($coverage['files']));

	$record=[
		'scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>1, 'app_root'=>null,
		'case_index'=>0, 'case_name'=>'payload contract',
	];
	$job=$access->invoke('workerJob', $record, 0);
	$payloadPath=(string)end($job['command']);
	$executionPayload=json_decode((string)file_get_contents($payloadPath), true);
	$t->same([$appRoot], $executionPayload['coverage_roots']);

	$t->same([], $access->invoke('codeCases', array_replace($record, ['cases'=>0])));
	$listPayload=json_decode((string)file_get_contents($workspace->path('captured-code-worker-payload.json')), true);
	$t->same('list', $listPayload['mode']);
	$t->same([$appRoot], $listPayload['coverage_roots']);
	$t->same(str_replace('\\', '/', $worker), str_replace('\\', '/', $job['command'][count($job['command'])-2]));
});
