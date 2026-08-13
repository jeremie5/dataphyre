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

suite('First-party runner source epochs')
	->tag('testing', 'runner', 'source-epoch', 'coverage')
	->group('framework-coverage')
	->contract('testing.runner.source-epoch', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/Runner.php')
	->through('coverage-source inventory', 'content fingerprint', 'worker execution', 'certification artifacts')
	->isolation('process')
	->maxMillis(30000);

function dp_runner_epoch_workspace(Context $t, string $name, bool $with_worker=false): TempWorkspace {
	$workspace=$t->workspace($name);
	if($with_worker){
		$workspace->installCodeWorkerTooling();
	}
	return $workspace;
}

/** @return array{0:mixed,1:string} */
function dp_runner_epoch_capture(Context $t, callable $operation): array {
	$capture=$t->captureOutput($operation);
	return [$capture->result(), $capture->output()];
}

test('activation is explicit for ordinary runs and non-bypassable for exact coverage', static function(Context $t): void {
	$workspace=dp_runner_epoch_workspace($t, 'runner-source-epoch-activation');
	$workspace->file('runtime/modules/example/Framework/Stable.php', "<?php\ndeclare(strict_types=1);\nreturn 'stable';\n");
	$options=['coverage-source'=>'runtime/modules/example/Framework'];

	$disabled=new DataphyreUnitTestRunner($workspace->root(), $options);
	$disabled_access=$t->nonPublic($disabled);
	$t->same(null, $disabled_access->invoke('sourceEpochActivation'));
	$t->isFalse($disabled_access->invoke('sourceEpochEnabled'));
	$disabled_access->invoke('beginSourceEpoch');
	$disabled_access->invoke('finishSourceEpoch');
	$t->same(false, $disabled_access->invoke('sourceEpochMetadata')['enabled']);

	$explicit=new DataphyreUnitTestRunner($workspace->root(), $options+['source-epoch'=>true]);
	$explicit_access=$t->nonPublic($explicit);
	$t->same('explicit', $explicit_access->invoke('sourceEpochActivation'));
	$t->isTrue($explicit_access->invoke('sourceEpochEnabled'));
	$t->same('explicit', $explicit_access->invoke('sourceEpochMetadata')['activation']);
	$explicit_access->invoke('finishSourceEpoch');
	$explicit_access->invoke('beginSourceEpoch');
	$before=$explicit_access->invoke('sourceEpochMetadata');
	$t->isFalse($before['evaluated']);
	$t->same(64, strlen((string)$before['before_fingerprint']));
	$explicit_access->invoke('finishSourceEpoch');
	$stable=$explicit_access->invoke('sourceEpochMetadata');
	$t->isTrue($stable['stable']);
	$t->same($stable['before_fingerprint'], $stable['after_fingerprint']);
	$t->same(1, $stable['before_file_count']);
	$t->same([], $stable['changed_paths']);

	$closed=new DataphyreUnitTestRunner($workspace->root(), $options+['coverage'=>true, 'coverage-closed-world'=>true]);
	$t->same('coverage-closed-world', $t->nonPublic($closed)->invoke('sourceEpochActivation'));
	$threshold=new DataphyreUnitTestRunner($workspace->root(), $options+['coverage'=>true, 'coverage-min-percent'=>'0.01']);
	$t->same('coverage-line-threshold', $t->nonPublic($threshold)->invoke('sourceEpochActivation'));
	foreach(['xdebug', 'phpdbg'] as $engine){
		$exact=new DataphyreUnitTestRunner($workspace->root(), $options+[
			'coverage'=>true,
			'coverage-require'=>$engine,
			'source-epoch'=>'false',
		]);
		$t->same('coverage-exact-engine', $t->nonPublic($exact)->invoke('sourceEpochActivation'), $engine);
		$t->isTrue($t->nonPublic($exact)->invoke('sourceEpochEnabled'), $engine.' cannot disable certification');
	}
	$included=new DataphyreUnitTestRunner($workspace->root(), $options+['coverage'=>true, 'coverage-require'=>'included_files']);
	$t->same(null, $t->nonPublic($included)->invoke('sourceEpochActivation'));
	$coverage_off=new DataphyreUnitTestRunner($workspace->root(), $options+['coverage'=>'off', 'coverage-closed-world'=>true]);
	$t->same(null, $t->nonPublic($coverage_off)->invoke('sourceEpochActivation'));

	$snapshot=$explicit_access->invoke('sourceEpochSnapshot');
	$t->same($stable['after_fingerprint'], $snapshot['fingerprint']);
	$t->same('runtime/modules/example/Framework/Stable.php', array_key_first($snapshot['files']));
	$t->same('!unreadable', $explicit_access->invoke('sourceEpochContentHash', $workspace->path('missing.php')));
	$embedded=new DataphyreUnitTestRunner($workspace->root(), $options, ['framework_root'=>$workspace->path('common/dataphyre')]);
	$t->same('runtime/modules/example/Framework/Stable.php', $t->nonPublic($embedded)->invoke(
		'sourceEpochRelativePath',
		$workspace->path('runtime/modules/example/Framework/Stable.php'),
	));
});

test('path-level comparison names changed added and removed product sources', static function(Context $t): void {
	$workspace=dp_runner_epoch_workspace($t, 'runner-source-epoch-deltas');
	$changed=$workspace->file('runtime/modules/example/Framework/Changed.php', "<?php\nreturn 'before';\n");
	$removed=$workspace->file('runtime/modules/example/Framework/Removed.php', "<?php\nreturn 'remove';\n");
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'source-epoch'=>true,
		'coverage-source'=>'runtime/modules/example/Framework',
	]);
	$access=$t->nonPublic($runner);
	$access->invoke('beginSourceEpoch');
	file_put_contents($changed, "<?php\nreturn 'after';\n");
	unlink($removed);
	$workspace->file('runtime/modules/example/Framework/Added.php', "<?php\nreturn 'added';\n");
	$access->invoke('finishSourceEpoch');
	$epoch=$access->invoke('sourceEpochMetadata');

	$t->isFalse($epoch['stable']);
	$t->same(['runtime/modules/example/Framework/Changed.php'], $epoch['changed_paths']);
	$t->same(['runtime/modules/example/Framework/Added.php'], $epoch['added_paths']);
	$t->same(['runtime/modules/example/Framework/Removed.php'], $epoch['removed_paths']);
	$t->same(2, $epoch['before_file_count']);
	$t->same(2, $epoch['after_file_count']);
	$t->notSame($epoch['before_fingerprint'], $epoch['after_fingerprint']);
	$t->same(
		'changed=runtime/modules/example/Framework/Changed.php; added=runtime/modules/example/Framework/Added.php; removed=runtime/modules/example/Framework/Removed.php',
		$access->invoke('sourceEpochChangeSummary', $epoch),
	);
	$t->same(
		'changed=a.php, b.php, c.php (+2 more)',
		$access->invoke('sourceEpochChangeSummary', ['changed_paths'=>['a.php', 'b.php', 'c.php', 'd.php', 'e.php']], 3),
	);
	$t->same(
		'inventory fingerprint changed without a path-level delta',
		$access->invoke('sourceEpochChangeSummary', []),
	);
});

test('an invalidated run fails while preserving JSON profile and coverage evidence', static function(Context $t): void {
	$workspace=dp_runner_epoch_workspace($t, 'runner-source-epoch-end-to-end', true);
	$workspace->file('runtime/modules/example/Framework/Changed.php', "<?php\ndeclare(strict_types=1);\nreturn 'before';\n");
	$workspace->file('runtime/modules/example/Framework/Removed.php', "<?php\ndeclare(strict_types=1);\nreturn 'remove';\n");
	$workspace->file('runtime/modules/example/unit_tests/mutates-source.test.php', <<<'PHP'
<?php
declare(strict_types=1);
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;
test('source-writing fixture proves epoch invalidation', static function(Context $t): void {
	$framework=dirname(__DIR__).'/Framework';
	file_put_contents($framework.'/Changed.php', "<?php\ndeclare(strict_types=1);\nreturn 'after';\n");
	unlink($framework.'/Removed.php');
	file_put_contents($framework.'/Added.php', "<?php\ndeclare(strict_types=1);\nreturn 'added';\n");
	$t->isTrue(true);
});
PHP);
	$runner=new DataphyreUnitTestRunner($workspace->root(), [
		'scope'=>'framework',
		'owner'=>'example',
		'kind'=>'code',
		'isolate'=>'case',
		'parallel'=>1,
		'timeout'=>5,
		'no-test-cache'=>true,
		'source-epoch'=>true,
		'coverage-source'=>'runtime/modules/example/Framework',
		'coverage'=>'reports/source-epoch.coverage.json',
		'profile'=>'reports/source-epoch.profile.json',
		'json'=>true,
	]);
	[$exit, $output]=dp_runner_epoch_capture($t, static fn()=>$runner->run());
	$run=json_decode($output, true);
	$t->same(1, $exit);
	$t->same(1, $run['summary']['workers_passed']);
	$t->same(0, $run['summary']['workers_failed']);
	$t->same(['source-epoch-changed'], $run['summary']['policy_failures']);
	$t->same([], $run['failures']);
	$t->same('explicit', $run['summary']['source_epoch']['activation']);
	$t->same(['runtime/modules/example/Framework/Changed.php'], $run['summary']['source_epoch']['changed_paths']);
	$t->same(['runtime/modules/example/Framework/Added.php'], $run['summary']['source_epoch']['added_paths']);
	$t->same(['runtime/modules/example/Framework/Removed.php'], $run['summary']['source_epoch']['removed_paths']);

	$coverage=json_decode((string)file_get_contents($workspace->path('reports/source-epoch.coverage.json')), true);
	$profile=json_decode((string)file_get_contents($workspace->path('reports/source-epoch.profile.json')), true);
	$t->same($run['summary']['source_epoch'], $coverage['source_epoch']);
	$t->same($run['summary']['source_epoch'], $profile['source_epoch']);
	$t->notContains('reports/source-epoch.coverage.json', $coverage['source_epoch']['added_paths']);
	$t->notContains('reports/source-epoch.profile.json', $coverage['source_epoch']['added_paths']);

	$human_runner=new DataphyreUnitTestRunner($workspace->root());
	[, $human]=dp_runner_epoch_capture($t, static fn()=>$t->nonPublic($human_runner)->invoke('writeRunOutput', $run['summary'], []));
	$t->contains('Policy failure: source-epoch-changed', $human);
	$t->contains('Source epoch invalidated: changed=runtime/modules/example/Framework/Changed.php', $human);
	$t->contains('added=runtime/modules/example/Framework/Added.php', $human);
	$t->contains('removed=runtime/modules/example/Framework/Removed.php', $human);
});

test('help documents source epochs without discovering or running tests', static function(Context $t): void {
	$workspace=dp_runner_epoch_workspace($t, 'runner-source-epoch-help');
	foreach([['run', '--source-epoch', '--help'], ['ci', '-h']] as $arguments){
		[$exit, $output]=dp_runner_epoch_capture($t, static fn()=>dataphyre_unit_test_main(
			array_merge(['fixture'], $arguments),
			$workspace->root(),
		));
		$t->same(0, $exit, implode(' ', $arguments));
		$t->contains('[--source-epoch]', $output, implode(' ', $arguments));
		$t->contains('changing source tree cannot be certified', $output, implode(' ', $arguments));
		$t->notContains('No unit-test cases matched', $output, implode(' ', $arguments));
	}
});
