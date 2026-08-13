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
use Dataphyre\Test\PhpRuntime;
use Dataphyre\Test\ShardedCaseDiscoveryCache;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\Contracts\CaseDiscoveryCache;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/Runner.php';

suite('First-party runner execution')
	->tag('testing', 'runner', 'execution')
	->group('framework-coverage')
	->contract('testing.runner.execution', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/Runner.php', 'path:runtime/modules/testing/tooling/code_worker.php')
	->through('case discovery', 'isolation scheduler', 'worker process', 'result acceptance')
	->isolation('process')
	->maxMillis(30000);

function dp_runner_execution_workspace(Context $t, string $name): TempWorkspace {
	$workspace=$t->workspace($name);
	$workspace->installCodeWorkerTooling();
	return $workspace;
}

/** @return array{0:mixed,1:string} */
function dp_runner_execution_capture(Context $t, callable $operation): array {
	$capture=$t->captureOutput($operation);
	return [$capture->result(), $capture->output()];
}

/** @param array<int,array<string,mixed>> $cases */
function dp_runner_seed_cases(Context $t, DataphyreUnitTestRunner $runner, array $test_record, array $cases): void {
	$key=sha1((string)$test_record['scope'].'|'.(string)$test_record['owner'].'|'.(string)$test_record['manifest'].'|'.(string)($test_record['app_root'] ?? ''));
	$t->nonPublic($runner)->writeProperty('code_case_cache', [$key=>$cases]);
}

/** @return array<string,mixed> */
function dp_runner_case(int $index, string $name, array $replace=[]): array {
	return array_replace([
		'index'=>$index,
		'name'=>$name,
		'base_name'=>$name,
		'stable_id'=>'test.'.$index,
		'base_stable_id'=>'test.'.$index,
		'tags'=>['runner'],
		'groups'=>['execution'],
		'dependencies'=>[],
		'order'=>0,
		'only'=>false,
		'skipped'=>false,
		'todo'=>false,
		'isolation'=>'case',
		'isolation_explicit'=>false,
		'contract'=>['name'=>'runner.execution', 'version'=>'1'],
		'layer'=>'integration',
		'risk'=>'high',
		'watches'=>['module:testing'],
		'through'=>['scheduler', 'worker'],
		'rootpath_sandboxes'=>[],
		'memory_limit'=>null,
		'coverage_memory_limit'=>null,
		'repeat_index'=>1,
		'repeat_total'=>1,
	], $replace);
}

test('execution units preserve lifecycle metadata and validate dependency graphs', static function(Context $t): void {
	$workspace=$t->workspace('runner-unit-expansion');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/cases.test.php', '<?php declare(strict_types=1);');
	$test_record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>4, 'app_root'=>null];
	$cases=[
		dp_runner_case(0, 'strict file', ['isolation'=>'file', 'isolation_explicit'=>true, 'rootpath_sandboxes'=>['dataphyre']]),
		dp_runner_case(1, 'strict process', ['isolation'=>'process', 'isolation_explicit'=>true, 'rootpath_sandboxes'=>['cache'], 'memory_limit'=>'512M', 'coverage_memory_limit'=>'1G']),
		dp_runner_case(2, 'implicit one', ['rootpath_sandboxes'=>['cache'], 'memory_limit'=>'128M', 'coverage_memory_limit'=>'512M']),
		dp_runner_case(3, 'implicit two', ['rootpath_sandboxes'=>['dataphyre'], 'memory_limit'=>'384M', 'coverage_memory_limit'=>'768M']),
	];
	$runner=new DataphyreUnitTestRunner($workspace->root());
	dp_runner_seed_cases($t, $runner, $test_record, $cases);
	$access=$t->nonPublic($runner);
	$units=$access->invoke('expandExecutionUnits', [$test_record]);
	$t->count(3, $units);
	$file_units=array_values(array_filter($units, static fn(array $unit): bool=>$unit['isolation']==='file'));
	$t->count(2, $file_units);
	$t->isFalse($file_units[0]['adaptive_speculative']);
	$t->isTrue($file_units[1]['adaptive_speculative']);
	$t->same([2, 3], $file_units[1]['case_indexes']);
	$t->same(['implicit one', 'implicit two'], $file_units[1]['case_names']);
	$t->same(['test.2', 'test.3'], $file_units[1]['case_stable_ids']);
	$t->same(['case'], $file_units[1]['requested_isolations']);
	$t->same(['dataphyre'], $file_units[0]['rootpath_sandboxes']);
	$t->same(['cache', 'dataphyre'], $file_units[1]['rootpath_sandboxes']);
	$t->same('384M', $file_units[1]['memory_limit']);
	$t->same('768M', $file_units[1]['coverage_memory_limit']);
	$t->same(2, count($file_units[1]['adaptive_cases']));

	$case_unit=array_values(array_filter($units, static fn(array $unit): bool=>$unit['isolation']==='process'))[0];
	$t->same(1, $case_unit['case_index']);
	$t->same('strict process', $case_unit['case_base_name']);
	$t->same('test.1', $case_unit['case_stable_id']);
	$t->same('runner.execution', $case_unit['contract']['name']);
	$t->same('integration', $case_unit['layer']);
	$t->same('high', $case_unit['risk']);
	$t->same(['module:testing'], $case_unit['watches']);
	$t->same(['scheduler', 'worker'], $case_unit['through']);
	$t->same(['cache'], $case_unit['rootpath_sandboxes']);
	$t->same('512M', $case_unit['memory_limit']);
	$t->same('1G', $case_unit['coverage_memory_limit']);
	$t->same('512M', $access->invoke('workerMemoryLimit', $case_unit));
	$coverage_runner=new DataphyreUnitTestRunner($workspace->root(), ['coverage'=>true]);
	$t->same('1G', $t->nonPublic($coverage_runner)->invoke('workerMemoryLimit', $case_unit));
	$coverage_default_case=dp_runner_case(4, 'coverage default', ['memory_limit'=>'384M']);
	$coverage_default_runner=new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-memory-default'=>'1g',
	]);
	$coverage_default_access=$t->nonPublic($coverage_default_runner);
	$t->same('1G', $coverage_default_access->invoke('workerMemoryLimit', $coverage_default_case));
	$t->same('1G', $t->nonPublic(new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-memory-default'=>'2G',
	]))->invoke('workerMemoryLimit', $case_unit), 'A contract declaration is more specific than the run fallback.');
	$t->same('384M', $t->nonPublic(new DataphyreUnitTestRunner($workspace->root(), [
		'coverage-memory-default'=>'1G',
	]))->invoke('workerMemoryLimit', $coverage_default_case), 'The coverage fallback is inert in ordinary runs.');
	$t->throwsLike(static fn()=>$t->nonPublic(new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-memory-default'=>true,
	]))->invoke('workerMemoryLimit', $coverage_default_case), RuntimeException::class, '--coverage-memory-default');
	$t->throwsLike(static fn()=>$t->nonPublic(new DataphyreUnitTestRunner($workspace->root(), [
		'coverage'=>true,
		'coverage-memory-default'=>'unlimited',
	]))->invoke('workerMemoryLimit', $coverage_default_case), RuntimeException::class, 'positive PHP byte value');

	$case_runner=new DataphyreUnitTestRunner($workspace->root(), ['isolate'=>'case']);
	dp_runner_seed_cases($t, $case_runner, $test_record, $cases);
	$t->count(4, $t->nonPublic($case_runner)->invoke('expandExecutionUnits', [$test_record]));
	$file_runner=new DataphyreUnitTestRunner($workspace->root(), ['isolate'=>'file']);
	dp_runner_seed_cases($t, $file_runner, $test_record, $cases);
	$strict_file=$t->nonPublic($file_runner)->invoke('expandExecutionUnits', [$test_record]);
	$t->count(1, $strict_file);
	$t->same([0, 1, 2, 3], $strict_file[0]['case_indexes']);
	$t->same('512M', $strict_file[0]['memory_limit']);
	$t->same('1G', $strict_file[0]['coverage_memory_limit']);
	$cli_memory_runner=new DataphyreUnitTestRunner($workspace->root(), [
		'memory'=>'768M',
		'coverage'=>true,
		'coverage-memory-default'=>'2G',
	]);
	$t->same('768M', $t->nonPublic($cli_memory_runner)->invoke('workerMemoryLimit', $case_unit));
	$t->same(4 * 1024, $access->invoke('memoryLimitBytes', '4K'));
	$t->same(2 * 1024 * 1024 * 1024, $access->invoke('memoryLimitBytes', '2G'));
	$t->same(524288, $access->invoke('memoryLimitBytes', '524288'));
	$t->throwsLike(static fn()=>$access->invoke('memoryLimitBytes', 'unlimited'), RuntimeException::class, 'positive PHP byte value');

	$dependency_cases=[
		dp_runner_case(0, 'producer'),
		dp_runner_case(1, 'consumer', ['dependencies'=>['producer']]),
	];
	$dependency_runner=new DataphyreUnitTestRunner($workspace->root());
	dp_runner_seed_cases($t, $dependency_runner, $test_record, $dependency_cases);
	$dependency_units=$t->nonPublic($dependency_runner)->invoke('expandExecutionUnits', [$test_record]);
	$t->count(2, $dependency_units);
	$t->same(['producer'], $dependency_units[1]['case_dependencies']);

	$missing=$dependency_units;
	$missing[1]['case_dependencies']=['absent'];
	$t->throwsLike(static fn()=>$access->invoke('validateDependencyGraph', $missing), RuntimeException::class, 'depends on missing test');
	$cycle=$dependency_units;
	$cycle[0]['case_dependencies']=['consumer'];
	$t->throwsLike(static fn()=>$access->invoke('validateDependencyGraph', $cycle), RuntimeException::class, 'dependency cycle');
	$access->invoke('validateDependencyGraph', $dependency_units);

	$dpanel=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'dpanel', 'cases'=>3, 'app_root'=>null];
	$dpanel_runner=new DataphyreUnitTestRunner($workspace->root(), ['case'=>'1']);
	$dpanel_units=$t->nonPublic($dpanel_runner)->invoke('expandExecutionUnits', [$dpanel]);
	$t->count(1, $dpanel_units);
	$t->same(1, $dpanel_units[0]['case_index']);
	$descriptor=$dpanel;
	$descriptor['kind']='descriptor';
	$t->same([$descriptor], $t->nonPublic($dpanel_runner)->invoke('expandExecutionUnits', [$descriptor]));
});

test('case selectors and committed only markers fail safely unless explicitly allowed', static function(Context $t): void {
	$workspace=$t->workspace('runner-case-selectors');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/cases.test.php', '<?php declare(strict_types=1);');
	$test_record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>2, 'app_root'=>null];
	$cases=[
		dp_runner_case(0, 'primary contract', ['stable_id'=>'stable.primary', 'base_stable_id'=>'base.primary', 'tags'=>['runner', 'fast'], 'groups'=>['execution', 'ci'], 'only'=>true]),
		dp_runner_case(1, 'secondary contract', ['stable_id'=>'stable.secondary']),
	];
	$blocked=new DataphyreUnitTestRunner($workspace->root());
	dp_runner_seed_cases($t, $blocked, $test_record, $cases);
	$t->throwsLike(static fn()=>$t->nonPublic($blocked)->invoke('runnableCodeCases', $test_record), RuntimeException::class, 'contain ->only()');

	$allowed=new DataphyreUnitTestRunner($workspace->root(), [
		'allow-only'=>true,
		'id'=>'base.primary',
		'name'=>'/^primary/i',
		'tag'=>'runner,fast',
		'group'=>'execution,ci',
		'case'=>'0',
	]);
	dp_runner_seed_cases($t, $allowed, $test_record, $cases);
	$t->same([$cases[0]], $t->nonPublic($allowed)->invoke('runnableCodeCases', $test_record));
	foreach([
		['id'=>'missing'], ['name'=>'missing'], ['tag'=>'slow'], ['group'=>'nightly'], ['case'=>'1'],
	] as $options){
		$selector=new DataphyreUnitTestRunner($workspace->root(), $options+['allow-only'=>true]);
		dp_runner_seed_cases($t, $selector, $test_record, [$cases[0]]);
		$t->same([], $t->nonPublic($selector)->invoke('runnableCodeCases', $test_record));
	}
});

test('declaration failures report the originating framework source instead of mislabeling the test file', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-declaration-diagnostics');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/invalid-layer.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use function Dataphyre\Test\suite;
suite('invalid declaration')->layer('not-a-real-layer');
PHP);
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true, 'timeout'=>5]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($runner)->invoke('codeCases', $record),
		RuntimeException::class,
		"InvalidArgumentException: Unknown test layer 'not-a-real-layer'. in ".$workspace->path('runtime/modules/testing/tooling/TestKit/SuiteDefinition.php'),
	);
	$detail=$t->nonPublic($runner)->invoke('codeCaseDiscoveryFailureDetail',[
		'trace'=>[
			'not-an-entry',
			['message'=>''],
			['exception'=>'DomainException','message'=>'declaration failed','file'=>'fixture.php','line'=>7],
		],
		'output'=>'worker diagnostic',
	],['stderr'=>'transport diagnostic','stdout'=>'']);
	$t->contains('DomainException: declaration failed in fixture.php:7',$detail);
	$t->contains('worker output: worker diagnostic',$detail);
	$t->contains('stderr: transport diagnostic',$detail);
});

test('dependency scheduling skips consumers after a failed producer while unrelated cases still run', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-dependencies');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/dependencies.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('producer', static function(Context $t): void { $t->same('ready', 'failed'); });
test('consumer', static function(Context $t): void { $t->isTrue(true); })->dependsOn('producer');
test('unrelated', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true, 'parallel'=>2, 'timeout'=>5]);
	$access=$t->nonPublic($runner);
	$units=$access->invoke('expandExecutionUnits', [$record]);
	$t->isTrue($access->invoke('hasCodeDependencies', $units));
	[$independent, $dependent]=$access->invoke('partitionDependencyTests', $units);
	$t->count(1, $independent);
	$t->count(2, $dependent);
	$results=$access->invoke('runMany', $units);
	$t->count(3, $results);
	$by_name=[];
	foreach($results as $result){$by_name[$result['test']['case_name']]=$result;}
	$t->isTrue($by_name['unrelated']['passed']);
	$t->isFalse($by_name['producer']['passed']);
	$t->isTrue($by_name['consumer']['passed']);
	$t->isTrue($by_name['consumer']['result']['trace'][0]['skipped']);
	$t->contains('declared dependency did not pass', $by_name['consumer']['result']['trace'][0]['message']);
	$t->isFalse($access->invoke('dependenciesPassed', $by_name['consumer']['test'], ['producer'=>false]));
	$t->isTrue($access->invoke('dependenciesPassed', $by_name['consumer']['test'], ['producer'=>true]));
	$t->same(['consumer'], $access->invoke('caseStatusKeys', ['case_name'=>'consumer', 'case_base_name'=>'consumer']));
	$t->same([], $access->invoke('caseStatusKeys', []));
	$t->isFalse($access->invoke('hasCodeDependencies', $independent));

	$unresolvable=$dependent;
	$unresolvable[0]['case_dependencies']=['consumer'];
	$unresolvable[1]['case_dependencies']=['producer'];
	$t->throwsLike(static fn()=>$access->invoke('runManyWithDependencies', $unresolvable), RuntimeException::class, 'could not resolve a ready case');
});

test('parallel workers accept valid results and distinguish missing timeout and invalid manifests', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-workers');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/parallel.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('first parallel case', static function(Context $t): void { $t->same(2, 1 + 1); });
test('second parallel case', static function(Context $t): void { $t->same(4, 2 + 2); });
test('third parallel case', static function(Context $t): void { $t->same(6, 3 + 3); });
PHP);
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true, 'parallel'=>2, 'isolate'=>'case', 'timeout'=>5]);
	$access=$t->nonPublic($runner);
	$units=$access->invoke('expandExecutionUnits', [$record]);
	$results=$access->invoke('runManyIndependent', $units);
	$t->count(3, $results);
	$t->same([true, true, true], array_column($results, 'passed'));

	$invalid=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'invalid', 'cases'=>0, 'app_root'=>null];
	$invalid_result=$access->invoke('runOne', $invalid);
	$t->isFalse($invalid_result['passed']);
	$t->contains('not a supported', $invalid_result['message']);
	$t->count(2, $access->invoke('runManyIndependent', [$invalid, $invalid]));

	$missing_code_runner=new DataphyreUnitTestRunner($t->workspace('runner-missing-code-worker')->root());
	$t->throwsLike(static fn()=>$t->nonPublic($missing_code_runner)->invoke('workerJob', $record, 0), RuntimeException::class, 'Missing code unit-test worker');
	$json=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'dpanel', 'cases'=>1, 'app_root'=>null];
	$missing_json_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['framework_worker'=>$workspace->root().'/missing-worker.php']);
	$t->throwsLike(static fn()=>$t->nonPublic($missing_json_runner)->invoke('workerJob', $json, 0), RuntimeException::class, 'Missing unit-test worker');

	$result_path=$workspace->path('manual-result.json');
	$job=[
		'test'=>$record,
		'result_path'=>$result_path,
		'cleanup'=>[],
		'missing_result_message'=>'missing result',
		'timeout_message'=>'timed out result',
	];
	$missing=$access->invoke('workerJobResult', $job, ['exit_code'=>2, 'stdout'=>'out', 'stderr'=>'err', 'timed_out'=>false]);
	$t->isFalse($missing['passed']);
	$t->same('missing result', $missing['message']);
	$timeout=$access->invoke('workerJobResult', $job, ['exit_code'=>124, 'stdout'=>'', 'stderr'=>'', 'timed_out'=>true]);
	$t->same('timed out result', $timeout['message']);
	$workspace->file('manual-result.json', '{"passed":true,"trace":[],"duration_seconds":0.1}');
	$nonzero=$access->invoke('workerJobResult', $job, ['exit_code'=>3, 'stdout'=>'', 'stderr'=>'', 'timed_out'=>false]);
	$t->isFalse($nonzero['passed']);
	$t->same(3, $nonzero['exit_code']);
});

test('declared ROOTPATH sandboxes are disposable while unowned project roots fail closed', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-rootpath-sandbox');
	$project_guard=$workspace->file('project-guard.txt', 'repository-survives');
	$declared=$workspace->file('runtime/modules/example/unit_tests/declared-sandbox.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use Dataphyre\Test\RootpathSandbox;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Owned legacy filesystem')
	->sandboxesRootpath('dataphyre')
	->isolation('case');

test('the worker exposes only its disposable legacy data root', static function(Context $t): void {
	$root=RootpathSandbox::root('dataphyre');
	$t->notSame(rtrim(str_replace('\\', '/', (string)ROOTPATH['common_dataphyre']), '/'), rtrim($root, '/'));
	$t->isTrue(is_file($root.RootpathSandbox::MARKER));
	$victim=RootpathSandbox::path('dataphyre', 'nested/victim.txt');
	mkdir(dirname($victim), 0775, true);
	file_put_contents($victim, 'disposable');
	$t->same('disposable', file_get_contents($victim));
	$t->throws(static fn()=>RootpathSandbox::path('dataphyre', '../../escape.txt'), InvalidArgumentException::class);
	$t->same($root, RootpathSandbox::reset('dataphyre'));
	$t->isFalse(file_exists($victim));
	$t->isTrue(is_file($root.RootpathSandbox::MARKER));
});
PHP);
	$undeclared=$workspace->file('runtime/modules/example/unit_tests/undeclared-sandbox.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use Dataphyre\Test\RootpathSandbox;
use function Dataphyre\Test\test;

test('an undeclared project root cannot impersonate a disposable sandbox', static function(Context $t): void {
	RootpathSandbox::reset('dataphyre');
	$t->fail('An immutable project root was accepted as disposable.');
});
PHP);

	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true, 'isolate'=>'case', 'timeout'=>5]);
	$access=$t->nonPublic($runner);
	$declared_record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$declared, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$declared_cases=$access->invoke('codeCases', $declared_record);
	$t->same(['dataphyre'], $declared_cases[0]['rootpath_sandboxes']);
	$declared_units=$access->invoke('expandExecutionUnits', [$declared_record]);
	$t->same(['dataphyre'], $declared_units[0]['rootpath_sandboxes']);
	$declared_result=$access->invoke('runOne', $declared_units[0]);
	$t->isTrue($declared_result['passed'], json_encode($declared_result));
	$t->same('repository-survives', file_get_contents($project_guard));

	$run_id=$access->readProperty('run_id');
	$workspace->file('.dataphyre-test-rootpath-sandbox.json', json_encode([
		'format'=>'dataphyre-test-rootpath-sandbox-v1',
		'rootpath_key'=>'dataphyre',
		'root'=>str_replace('\\', '/', $workspace->root()),
		'run_id'=>$run_id,
		'token'=>str_repeat('a', 64),
	]));
	$undeclared_record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$undeclared, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$undeclared_units=$access->invoke('expandExecutionUnits', [$undeclared_record]);
	$t->same([], $undeclared_units[0]['rootpath_sandboxes']);
	$undeclared_result=$access->invoke('runOne', $undeclared_units[0]);
	$t->isFalse($undeclared_result['passed']);
	$t->contains('immutable ROOTPATH', $undeclared_result['result']['trace'][0]['message']);
	$t->same('repository-survives', file_get_contents($project_guard));

	$temporary_root=$access->readProperty('temporary_run_root');
	$access->invoke('cleanupTemporaryRunRoot');
	$t->isFalse(is_dir($temporary_root));
	$t->throwsLike(
		static fn()=>$access->invoke('rootpathSandboxKeys', ['rootpath_sandboxes'=>['common_dataphyre']]),
		RuntimeException::class,
		'immutable'
	);
	$t->throwsLike(static fn()=>$access->invoke('rootpathSandboxKeys',['rootpath_sandboxes'=>'cache']),RuntimeException::class,'must be a list');
	$t->throwsLike(static fn()=>$access->invoke('rootpathSandboxKeys',['rootpath_sandboxes'=>[7]]),RuntimeException::class,'must be strings');
	$t->throwsLike(static fn()=>$access->invoke('rootpathSandboxKeys',['rootpath_sandboxes'=>['not valid']]),RuntimeException::class,'PHP-style identifiers');

	$resolver_runner=new DataphyreUnitTestRunner($workspace->root(),[],[
		'temporary_run_root'=>$workspace->path('resolver-failure-root'),
		'rootpath_sandbox_resolver'=>static fn(string $path): false=>false,
	]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($resolver_runner)->invoke('workerRootpath',$declared_units[0],'resolver-failure'),
		RuntimeException::class,
		'Unable to resolve a newly created ROOTPATH sandbox'
	);
	$encoding_runner=new DataphyreUnitTestRunner($workspace->root(),[],[
		'temporary_run_root'=>$workspace->path('encoding-failure-root'),
		'rootpath_sandbox_resolver'=>static fn(string $path): string=>"invalid-\xFF-path",
	]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($encoding_runner)->invoke('workerRootpath',$declared_units[0],'encoding-failure'),
		RuntimeException::class,
		'Unable to encode ROOTPATH'
	);
	$writer_runner=new DataphyreUnitTestRunner($workspace->root(),[],[
		'temporary_run_root'=>$workspace->path('writer-failure-root'),
		'rootpath_sandbox_marker_writer'=>static fn(string $path,string $contents): false=>false,
	]);
	$t->throwsLike(
		static fn()=>$t->nonPublic($writer_runner)->invoke('workerRootpath',$declared_units[0],'writer-failure'),
		RuntimeException::class,
		'Unable to mark ROOTPATH'
	);
})->contract('testing.runner.rootpath-sandbox', 1);

test('process transport handles ordinary completion timeout and asynchronous worker finishing', static function(Context $t): void {
	$workspace=$t->workspace('runner-process-transport');
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$php=PhpRuntime::binary();
	$success=$access->invoke('runProcess', [$php, '-r', 'fwrite(STDOUT,"visible"); fwrite(STDERR,"diagnostic");'], 5);
	$t->same(0, $success['exit_code']);
	$t->same('visible', $success['stdout']);
	$t->same('diagnostic', $success['stderr']);
	$t->isFalse($success['timed_out']);
	$timeout=$access->invoke('runProcess', [$php, '-r', 'sleep(3);'], 0);
	$t->same(124, $timeout['exit_code']);
	$t->isTrue($timeout['timed_out']);

	$worker=$workspace->file('worker.php', <<<'PHP'
<?php
declare(strict_types=1);
$payload=json_decode((string)file_get_contents($argv[1]), true);
file_put_contents($payload['output_path'], json_encode(['passed'=>true, 'trace'=>[], 'duration_seconds'=>0.01]));
PHP);
	$payload_path=$workspace->file('payload.json', json_encode(['output_path'=>$workspace->path('worker-result.json')]));
	$job=[
		'sequence'=>4,
		'test'=>['scope'=>'framework', 'owner'=>'example', 'manifest'=>'fixture', 'kind'=>'dpanel'],
		'command'=>$access->invoke('phpWorkerCommand', $worker, $payload_path),
		'timeout_seconds'=>5,
		'result_path'=>$workspace->path('worker-result.json'),
		'cleanup'=>[],
		'missing_result_message'=>'missing',
		'timeout_message'=>'timeout',
	];
	$started=$access->invoke('startWorkerProcess', $job);
	$t->isTrue(isset($started['resource']) && is_resource($started['resource']));
	$finished=$access->invoke('finishWorkerProcess', $started);
	$t->isTrue($finished['passed']);
});

test('timing history and adaptive isolation index survive only matching fingerprints', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-history');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/history.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('history case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>1, 'app_root'=>null, 'case_index'=>0, 'case_name'=>'history case'];
	$timing=$workspace->path('cache/timing.json');
	$isolation=$workspace->path('cache/isolation.json');
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true], [
		'timing_history_path'=>$timing,
		'isolation_index_path'=>$isolation,
	]);
	$access=$t->nonPublic($runner);
	$t->same([], $access->invoke('timingHistory'));
	$key=$access->invoke('timingKey', $record);
	$t->contains('framework|example|runtime/modules/example/unit_tests/history.test.php|0', $key);
	$access->invoke('saveTimingHistory', [[
		'passed'=>true, 'test'=>$record, 'result'=>['duration_seconds'=>2.0],
	], ['passed'=>true]]);
	$t->isTrue(is_file($timing));
	$t->equals(2.0, json_decode((string)file_get_contents($timing), true)['tests'][$key]);
	$access->invoke('saveTimingHistory', [[
		'passed'=>true, 'test'=>$record, 'result'=>['duration_seconds'=>1.0],
	]]);
	$t->same(1.7, json_decode((string)file_get_contents($timing), true)['tests'][$key]);
	$sorted=$access->invoke('sortExecutionUnits', [
		array_replace($record, ['case_index'=>1, 'case_order'=>1]),
		array_replace($record, ['case_index'=>0, 'case_order'=>0]),
	]);
	$t->same(0, $sorted[0]['case_index']);

	$t->same([], $access->invoke('isolationIndex'));
	$t->same(null, $access->invoke('isAdaptiveQuarantined', $record));
	$t->isTrue($access->invoke('rememberAdaptiveCaseIsolation', $record, 'shared state detected'));
	$remembered=$access->invoke('isAdaptiveQuarantined', $record);
	$t->same('case', $remembered['isolation']);
	$t->same('shared state detected', $remembered['reason']);
	$t->same($access->invoke('isolationIndexKey', $record), array_key_first(json_decode((string)file_get_contents($isolation), true)['entries']));
	$workspace->file('runtime/modules/example/unit_tests/history.test.php', (string)file_get_contents($manifest)."\n// fingerprint changed");
	$t->same(null, $access->invoke('isAdaptiveQuarantined', $record));

	$invalid_timing=$workspace->file('cache/invalid-timing.json', '{"tests":{"valid":2,"invalid":"no"}}');
	$read_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['timing_history_path'=>$invalid_timing]);
	$t->same(['valid'=>2.0], $t->nonPublic($read_runner)->invoke('timingHistory'));
	$disabled_runner=new DataphyreUnitTestRunner($workspace->root(), ['no-timing-history'=>true], ['timing_history_path'=>$invalid_timing]);
	$t->same([], $t->nonPublic($disabled_runner)->invoke('timingHistory'));
	$t->nonPublic($disabled_runner)->invoke('saveTimingHistory', []);

	$invalid_isolation=$workspace->file('cache/invalid-isolation.json', '{"version":1,"entries":{"bad":{"fingerprint":false}}}');
	$invalid_runner=new DataphyreUnitTestRunner($workspace->root(), [], ['isolation_index_path'=>$invalid_isolation]);
	$t->same([], $t->nonPublic($invalid_runner)->invoke('isolationIndex'));
	$summary=$access->invoke('adaptiveIsolationSummary');
	$t->same('cache/isolation.json', $summary['index']);
});

test('worker eligibility and dependency result helpers keep unsafe JSON manifests sequential', static function(Context $t): void {
	$workspace=$t->workspace('runner-worker-eligibility');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/case.json', '[]');
	$code=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code'];
	$json=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'dpanel'];
	$runner=new DataphyreUnitTestRunner($workspace->root());
	$access=$t->nonPublic($runner);
	$t->isTrue($access->invoke('canRunParallel', $code));
	$t->isFalse($access->invoke('canRunParallel', $json));
	$enabled=new DataphyreUnitTestRunner($workspace->root(), ['parallel-json'=>true]);
	$t->isFalse($t->nonPublic($enabled)->invoke('canRunParallel', $json));
	$allowed=new DataphyreUnitTestRunner($workspace->root(), ['parallel-json'=>true, 'parallel-json-allow'=>'runtime/modules/example,apps/safe']);
	$allowed_access=$t->nonPublic($allowed);
	$t->isTrue($allowed_access->invoke('canRunParallel', $json));
	$t->isFalse($allowed_access->invoke('canRunParallel', array_replace($json, ['manifest'=>$workspace->root().'/elsewhere/case.json'])));
	$t->isFalse($allowed_access->invoke('canRunParallel', array_replace($json, ['kind'=>'invalid'])));

	$skip=$access->invoke('dependencySkipResult', [
		'scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest,
		'case_name'=>'consumer', 'case_index'=>3, 'case_dependencies'=>['producer'],
	]);
	$t->isTrue($skip['passed']);
	$t->same('consumer', $skip['result']['trace'][0]['test_name']);
	$t->same(['producer'], $skip['result']['trace'][0]['details']['dependencies']);
});

test('case discovery cache shards fingerprints tests bootstraps and runtime tooling independently', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-case-cache');
	$bootstrap=$workspace->file('runtime/modules/example/testing/bootstrap.php', '<?php declare(strict_types=1);');
	$manifest=$workspace->file('runtime/modules/example/unit_tests/cache.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('cached case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$record=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$cache=$workspace->path('cache/code-cases.json');
	$shards=new ShardedCaseDiscoveryCache($cache);
	$key=sha1((string)$record['scope'].'|'.(string)$record['owner'].'|'.(string)$record['manifest'].'|'.(string)($record['app_root'] ?? ''));
	$runner=new DataphyreUnitTestRunner($workspace->root(), [], ['cache_path'=>$cache]);
	$access=$t->nonPublic($runner);
	$first=$access->invoke('codeCases', $record);
	$t->count(1, $first);
	$t->same(1, $access->readProperty('code_case_cache_misses'));
	$t->same($first, $access->invoke('codeCases', $record));
	$t->isFalse(is_file($cache));
	$t->isTrue(is_file($shards->path($key)));
	$t->same($workspace->path('cache/code-cases.d'),$shards->directory());
	$t->same($shards->directory(),dirname($shards->path($key)));

	$next=new DataphyreUnitTestRunner($workspace->root(), [], ['cache_path'=>$cache]);
	$next_access=$t->nonPublic($next);
	$t->same($first, $next_access->invoke('codeCases', $record));
	$t->same(1, $next_access->readProperty('code_case_cache_hits'));
	$t->same([str_replace('\\', '/', $bootstrap)], $next_access->invoke('testBootstrapFiles', $record));
	$before=$next_access->invoke('codeCaseFingerprint', $record);
	$workspace->file('runtime/modules/example/testing/bootstrap.php', (string)file_get_contents($bootstrap)."\n// changed");
	$t->notSame($before, $next_access->invoke('codeCaseFingerprint', $record));

	$t->throws(static fn()=>new CaseDiscoveryCacheEntry('',[]),InvalidArgumentException::class);
	$t->throws(static fn()=>new CaseDiscoveryCacheEntry('fingerprint',[false]),InvalidArgumentException::class);
	$t->throws(static fn()=>new ShardedCaseDiscoveryCache(''),InvalidArgumentException::class);
	$t->throws(static fn()=>$shards->path(''),InvalidArgumentException::class);
	$t->same($workspace->path('cache/plain-index.d'),(new ShardedCaseDiscoveryCache($workspace->path('cache/plain-index')))->directory());
	$t->isNull($shards->find('missing'));

	$corruptKey='corrupt-entry';
	$corruptRelative=substr($shards->path($corruptKey),strlen($workspace->root())+1);
	foreach([
		'not json',
		'{"version":2,"key":"corrupt-entry","fingerprint":"hash","cases":[]}',
		'{"version":1,"key":"wrong","fingerprint":"hash","cases":[]}',
		'{"version":1,"key":"corrupt-entry","fingerprint":false,"cases":[]}',
		'{"version":1,"key":"corrupt-entry","fingerprint":"","cases":[]}',
		'{"version":1,"key":"corrupt-entry","fingerprint":"hash","cases":false}',
		'{"version":1,"key":"corrupt-entry","fingerprint":"hash","cases":[false]}',
	] as $corrupt){
		$workspace->file($corruptRelative,$corrupt);
		$t->isNull($shards->find($corruptKey));
	}

	$spy=new class implements CaseDiscoveryCache {
		public int $finds=0;
		public int $stores=0;
		public function find(string $key): ?CaseDiscoveryCacheEntry { $this->finds++; return null; }
		public function store(string $key,CaseDiscoveryCacheEntry $entry): bool { $this->stores++; return true; }
	};
	$noCache=new DataphyreUnitTestRunner($workspace->root(),['no-test-cache'=>true],['cache_path'=>$cache,'case_discovery_cache'=>$spy]);
	$t->count(1,$t->nonPublic($noCache)->invoke('codeCases',$record));
	$t->same(0,$spy->finds);
	$t->same(0,$spy->stores);
	$t->throws(
		static fn()=>new DataphyreUnitTestRunner($workspace->root(),[],['case_discovery_cache'=>new stdClass()]),
		InvalidArgumentException::class,
	);
});

test('dynamic case discovery fingerprints its declared framework source dependency', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-discovery-dependency');
	$dynamic_manifest=$workspace->file('runtime/modules/example/unit_tests/dynamic-inventory.test.php', <<<'PHP'
<?php
declare(strict_types=1);
// @dataphyre-test-discovery-dependency framework-source
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('dynamic inventory', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$plain_manifest=$workspace->file('runtime/modules/example/unit_tests/plain.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
$marker='@dataphyre-test-discovery-dependency framework-source';
test('plain case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$product=$workspace->file('runtime/modules/catalog/Framework/Product.php', '<?php declare(strict_types=1); final class Product {}');
	$workspace->file('runtime/modules/catalog/documentation/Product.md', 'v1');
	$workspace->file('runtime/modules/catalog/unit_tests/helper.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/catalog/Framework/schema.txt', 'not executable PHP');
	$dynamic=['scope'=>'framework', 'owner'=>'example', 'manifest'=>$dynamic_manifest, 'kind'=>'code', 'cases'=>0, 'app_root'=>null];
	$plain=array_replace($dynamic, ['manifest'=>$plain_manifest]);
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$access=$t->nonPublic($runner);
	$t->isTrue($access->invoke('codeCaseDependsOnFrameworkSource', $dynamic_manifest));
	$t->isFalse($access->invoke('codeCaseDependsOnFrameworkSource', $plain_manifest));
	$t->isFalse($access->invoke('codeCaseDependsOnFrameworkSource', $workspace->path('missing.test.php')));

	$dynamic_before=$access->invoke('codeCaseFingerprint', $dynamic);
	$plain_before=$access->invoke('codeCaseFingerprint', $plain);
	$workspace->file('runtime/modules/catalog/documentation/Product.md', 'v2');
	$workspace->file('runtime/modules/catalog/unit_tests/helper.php', '<?php declare(strict_types=1); // changed');
	$unchanged_runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$unchanged_access=$t->nonPublic($unchanged_runner);
	$t->same($dynamic_before, $unchanged_access->invoke('codeCaseFingerprint', $dynamic));
	$t->same($plain_before, $unchanged_access->invoke('codeCaseFingerprint', $plain));

	$workspace->file('runtime/modules/catalog/Framework/Product.php', (string)file_get_contents($product)."\n// product changed");
	$changed_runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$changed_access=$t->nonPublic($changed_runner);
	$dynamic_changed=$changed_access->invoke('codeCaseFingerprint', $dynamic);
	$t->notSame($dynamic_before, $dynamic_changed);
	$t->same($plain_before, $changed_access->invoke('codeCaseFingerprint', $plain));
	$workspace->file('runtime/modules/catalog/Framework/Added.php', '<?php declare(strict_types=1); final class Added {}');
	$added_runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true]);
	$t->notSame($dynamic_changed, $t->nonPublic($added_runner)->invoke('codeCaseFingerprint', $dynamic));

	$empty=$t->workspace('runner-missing-discovery-source');
	$missing_runner=new DataphyreUnitTestRunner($empty->root(), ['no-test-cache'=>true]);
	$t->matches('/^[a-f0-9]{64}$/', $t->nonPublic($missing_runner)->invoke('frameworkDiscoverySourceFingerprint'));
});

test('framework and application discovery classify manifests before running workers', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-full-discovery');
	$workspace->file('runtime/modules/example/unit_tests/list.json', '[{"name":"one"}]');
	$workspace->file('runtime/modules/example/unit_tests/single.json', '{"function":"example_check","args":[]}');
	$workspace->file('runtime/modules/example/unit_tests/descriptor.json', '{"type":"php","entry":"check.php"}');
	$workspace->file('runtime/modules/example/unit_tests/invalid.json', '{"unsupported":true}');
	$workspace->file('runtime/modules/example/unit_tests/example.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('discovered code case', static function(Context $t): void { $t->isTrue(true); })
	->tag('discoverable')->group('runner')->skip('discovery metadata only');
PHP);
	$workspace->file('runtime/modules/example/unit_tests/dynamic/generated.json', '[]');
	$workspace->file('runtime/modules/example/unit_tests/dynamic/generated.test.php', '<?php declare(strict_types=1);');
	$workspace->file('runtime/modules/example/unit_tests/example.meta.json', '[]');
	$workspace->file('runtime/modules/example/unit_tests/dpanel_mock_example.json', '[]');

	$app_root=$workspace->directory('applications/catalog/backend/dataphyre/unit_tests');
	$workspace->file('applications/catalog/backend/dataphyre/unit_tests/catalog.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('application code case', static function(Context $t): void { $t->isTrue(true); });
PHP);
	$workspace->file('applications/catalog/unit_tests/catalog.json', '[]');
	$registry=$workspace->file('applications/dataphyre.apps.json', json_encode(['applications'=>[
		['name'=>'catalog', 'path'=>'applications/catalog'],
	]]));
	$runner=new DataphyreUnitTestRunner($workspace->root(), ['no-test-cache'=>true], ['applications_registry'=>$registry]);
	$access=$t->nonPublic($runner);
	$framework=$access->invoke('discoverFramework');
	$t->same(['descriptor', 'invalid', 'dpanel', 'dpanel_single', 'code'], array_values(array_unique(array_column($framework, 'kind'))));
	$t->same(5, count($framework));
	$apps=$access->invoke('discoverApps');
	$t->count(2, $apps);
	$t->same(['app'], array_values(array_unique(array_column($apps, 'scope'))));
	$t->same('catalog', $apps[0]['owner']);
	$t->same(2, $access->invoke('countDynamicSkipped'));

	$dynamic=new DataphyreUnitTestRunner($workspace->root(), ['include-dynamic'=>true], ['applications_registry'=>$registry]);
	$t->same(0, $t->nonPublic($dynamic)->invoke('countDynamicSkipped'));
	$kind=new DataphyreUnitTestRunner($workspace->root(), ['kind'=>'code'], ['applications_registry'=>$registry]);
	$t->same(0, $t->nonPublic($kind)->invoke('countDynamicSkipped'));
	$t->isTrue($access->invoke('wantsJsonTests'));
	$t->isTrue($access->invoke('wantsCodeTests'));
	$json_only=new DataphyreUnitTestRunner($workspace->root(), ['kind'=>'json']);
	$t->isTrue($t->nonPublic($json_only)->invoke('wantsJsonTests'));
	$t->isFalse($t->nonPublic($json_only)->invoke('wantsCodeTests'));
	$code_only=new DataphyreUnitTestRunner($workspace->root(), ['kind'=>'code']);
	$t->isFalse($t->nonPublic($code_only)->invoke('wantsJsonTests'));
	$t->isTrue($t->nonPublic($code_only)->invoke('wantsCodeTests'));

	$filtered_app=new DataphyreUnitTestRunner($workspace->root(), ['app'=>'missing'], ['applications_registry'=>$registry]);
	$t->same([], $t->nonPublic($filtered_app)->invoke('discoverApps'));
	$missing_registry=$workspace->file('applications/missing-apps.json', json_encode(['applications'=>[['name'=>'absent', 'path'=>'applications/absent']]]));
	$missing_app=new DataphyreUnitTestRunner($workspace->root(), [], ['applications_registry'=>$missing_registry]);
	$t->throwsLike(static fn()=>$t->nonPublic($missing_app)->invoke('discoverApps'), RuntimeException::class, 'is not installed');
});

test('list and run expose discovered case metadata and policy failures end to end', static function(Context $t): void {
	$workspace=dp_runner_execution_workspace($t, 'runner-end-to-end');
	$workspace->file('runtime/modules/example/unit_tests/end-to-end.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('passing case', static function(Context $t): void { $t->same(4, 2 + 2); })
	->tag('runner')->group('end-to-end');
test('skipped case', static function(Context $t): void { $t->isTrue(true); })->skip('optional engine');
test('todo case', static function(Context $t): void { $t->isTrue(true); })->todo('contract pending');
test('failing case', static function(Context $t): void { $t->same('ready', 'pending'); });
PHP);
	$list_runner=new DataphyreUnitTestRunner($workspace->root(), [
		'scope'=>'framework', 'owner'=>'example', 'kind'=>'code', 'cases'=>true,
		'why-selected'=>true, 'no-test-cache'=>true,
	]);
	[$list_exit, $list_output]=dp_runner_execution_capture($t, static fn()=>$list_runner->list());
	$t->same(0, $list_exit);
	$t->contains('cases=4 because owner=example; kind=code', $list_output);
	$t->contains('passing case tags=runner groups=end-to-end', $list_output);
	$t->contains('[skip]', $list_output);
	$t->contains('[todo]', $list_output);
	$t->contains('Matched 1 unit-test manifest.', $list_output);

	$json_list=new DataphyreUnitTestRunner($workspace->root(), [
		'scope'=>'framework', 'owner'=>'example', 'kind'=>'code', 'json'=>true,
		'why-selected'=>true, 'no-test-cache'=>true,
	]);
	[, $json_output]=dp_runner_execution_capture($t, static fn()=>$json_list->list());
	$listed=json_decode($json_output, true);
	$t->same(4, count($listed['tests'][0]['code_cases']));
	$t->same(['owner=example', 'kind=code'], $listed['tests'][0]['selection_reasons']);

	$run_runner=new DataphyreUnitTestRunner($workspace->root(), [
		'scope'=>'framework', 'owner'=>'example', 'kind'=>'code', 'json'=>true,
		'no-test-cache'=>true, 'isolate'=>'case', 'parallel'=>2,
		'fail-skipped'=>true, 'fail-todo'=>true, 'timeout'=>5,
	]);
	[$run_exit, $run_output]=dp_runner_execution_capture($t, static fn()=>$run_runner->run());
	$t->same(1, $run_exit);
	$run=json_decode($run_output, true);
	$t->same(4, $run['summary']['workers_total']);
	$t->same(3, $run['summary']['workers_passed']);
	$t->same(1, $run['summary']['workers_failed']);
	$t->same(2, $run['summary']['skipped']);
	$t->same(1, $run['summary']['todo']);
	$t->same(['skipped', 'todo'], $run['summary']['policy_failures']);
	$t->count(1, $run['failures']);
});
