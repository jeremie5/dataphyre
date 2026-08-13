<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\PhpStub;
use Dataphyre\Test\PhpRuntime;
use Dataphyre\Test\ProcessResult;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('TestKit execution and artifact probes')
	->tag('testkit', 'execution', 'artifacts')
	->group('framework')
	->layer(TestLayer::Unit)
	->risk(TestRisk::High)
	->isolation(TestIsolation::CaseScope)
	->requiresAssertions();

test('captured executions preserve nested output return values and failures', static function(Context $t): void {
	$returned=$t->captureOutput(static function(): int {
		echo 'outer';
		ob_start(); // dataphyre-test-architecture: exempt[raw-output-buffer] reason="Nested native buffer creation is the behavior captureOutput must flatten."
		echo '-inner';
		return 42;
	});

	$t->same('outer-inner', $returned->output());
	$t->same(42, $returned->result());
	$t->isTrue($returned->returned());
	$t->isFalse($returned->threw());
	$t->isNull($returned->throwable());
	$t->same(42, $returned->unwrap());

	$failed=$t->captureExecution(static function(): never {
		echo 'before-failure';
		throw new RuntimeException('captured failure');
	});
	$t->same('before-failure', $failed->output());
	$t->isTrue($failed->threw());
	$t->isFalse($failed->returned());
	$t->instanceOf(RuntimeException::class, $failed->throwable());
	$t->throwsLike(static fn()=>$failed->unwrap(), RuntimeException::class, 'captured failure');

	$closed=$t->captureExecution(static fn(): bool=>ob_end_clean());
	$t->same('', $closed->output());
	$t->throwsLike(static fn()=>$closed->unwrap(), RuntimeException::class, 'closed the TestKit-owned output buffer');
	$t->throwsLike(
		static fn()=>$t->captureOutput(static fn()=>throw new RuntimeException('propagated output failure')),
		RuntimeException::class,
		'propagated output failure'
	);
});

test('JSON artifact helpers make successful and malformed shapes explicit', static function(Context $t): void {
	$workspace=$t->workspace('json-artifact-probe');
	$object=$workspace->file('object.json', '{"id":7,"ready":true}');
	$scalar=$workspace->file('scalar.json', '7');

	$t->same(['id'=>7, 'ready'=>true], $t->jsonArray('{"id":7,"ready":true}'));
	$t->same(['id'=>7, 'ready'=>true], $t->tryJsonArray('{"id":7,"ready":true}'));
	$t->isNull($t->tryJsonArray('human-readable output'));
	$t->isNull($t->tryJsonArray('7'));
	$t->same(['id'=>7, 'ready'=>true], $t->readJsonArray($object));
	$t->same(7, $t->readJson($scalar));
	$t->same(7, $t->decodeJson('7'));
	$t->same(7, $t->decodeJson('{"id":7}', false)->id);
	$t->throws(static fn()=>$t->decodeJson('{'), JsonException::class);
	$t->throws(static fn()=>$t->jsonArray('7'), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->readJsonArray($scalar), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->readJson($workspace->path('missing.json')), RuntimeException::class);
});

test('process probes are shell-free inspectable and portable across stdout stderr stdin cwd and environment', static function(Context $t): void {
	$working=$t->workspace('process-working-directory')->directory('cwd');
	$script=<<<'PHP'
$payload=[
	'input'=>stream_get_contents(STDIN),
	'cwd'=>str_replace('\\','/',getcwd()),
	'enabled'=>getenv('DP_PROBE_ENABLED'),
	'disabled'=>getenv('DP_PROBE_DISABLED'),
	'count'=>getenv('DP_PROBE_COUNT'),
	'removed'=>getenv('DP_PROBE_REMOVED'),
];
fwrite(STDERR,'diagnostic');
echo json_encode($payload,JSON_THROW_ON_ERROR);
PHP;
	$command=PhpRuntime::command(['-r', $script]);
	$result=$t->phpProcess(['-r', $script], 'request-body', $working, [
		'DP_PROBE_ENABLED'=>true,
		'DP_PROBE_DISABLED'=>false,
		'DP_PROBE_COUNT'=>7,
		'DP_PROBE_REMOVED'=>null,
	], 5000);

	$t->same($command, $result->command());
	$t->same(0, $result->exitCode());
	$t->same('diagnostic', $result->stderr());
	$t->isFalse($result->timedOut());
	$t->isTrue($result->succeeded());
	$t->greaterThanOrEqual(0, $result->durationSeconds());
	$t->hasPathValues([
		'input'=>'request-body',
		'cwd'=>str_replace('\\', '/', $working),
		'enabled'=>'1',
		'disabled'=>'0',
		'count'=>'7',
		'removed'=>false,
	], $result->json());
	$t->same($result->json(), $t->decodeJson($result->stdout()));

	$largeInput=str_repeat('managed-stdin-',65536);
	$large=$t->phpProcess(['-r','echo hash("sha256",stream_get_contents(STDIN));'],$largeInput,timeout_millis:10000);
	$t->same(hash('sha256',$largeInput),$large->stdout());
});

test('process probes retain nonzero exits and enforce a deterministic timeout status', static function(Context $t): void {
	$failed=$t->phpProcess(['-r', 'fwrite(STDERR,"failed"); exit(7);']);
	$t->same(7, $failed->exitCode());
	$t->same('failed', $failed->stderr());
	$t->isFalse($failed->succeeded());
	$t->isFalse($failed->timedOut());
	$error=new ProcessResult([], 1, '', '{"error":"failed"}', false, 0.0);
	$t->same(['error'=>'failed'], $error->stderrJson());
	$t->throws(static fn()=>$failed->stderrJson(), JsonException::class);

	$timed=$t->phpProcess(['-r', 'usleep(250000); echo "late";'], timeout_millis:20);
	$t->same(124, $timed->exitCode());
	$t->isTrue($timed->timedOut());
	$t->isFalse($timed->succeeded());
});

test('started process probes run concurrently and cache one bounded wait outcome', static function(Context $t): void {
	$processes=[];
	foreach(['alpha', 'beta', 'gamma', 'delta'] as $name){
		$processes[$name]=$t->startPhpProcess(['-r', 'usleep(50000); echo $argv[1];', $name]);
	}
	foreach($processes as $name=>$process){
		$result=$process->wait();
		$t->same(0, $result->exitCode());
		$t->same($name, $result->stdout());
		$t->same($result, $process->wait());
		$process->terminate();
	}

	$validated=$t->startPhpProcess(['-r', 'echo "validated";']);
	$t->throws(static fn()=>$validated->wait(0), InvalidArgumentException::class);
	$t->same('validated', $validated->wait()->stdout());

	$terminated=$t->startPhpProcess(['-r', 'usleep(250000);']);
	$terminated->terminate();
	$t->isTrue($terminated->wait()->timedOut());
});

test('process probes reject ambiguous unsafe or unbounded launch contracts before execution', static function(Context $t): void {
	$missing=$t->workspace('process-validation')->path('missing');
	$t->throws(static fn()=>$t->process([]), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([1=>PhpRuntime::binary()]), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process(['']), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process(["bad\0argument"]), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], str_repeat('x', 4194305)), LengthException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], working_directory:$missing), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], timeout_millis:0), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], timeout_millis:300001), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], environment:['bad=name'=>'value']), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], environment:[''=>'value']), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->process([PhpRuntime::binary()], environment:["bad\0name"=>'value']), InvalidArgumentException::class);
});

test('process startup failures become bounded framework exceptions', static function(Context $t): void {
	PhpStub::define(<<<'PHP'
namespace Dataphyre\Test {
	function proc_open(mixed ...$arguments): mixed { return false; }
}
PHP);
	$t->throwsLike(
		static fn()=>$t->phpProcess(['-r', 'echo "never";']),
		RuntimeException::class,
		'Unable to start test subprocess'
	);
});
