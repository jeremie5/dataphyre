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

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!defined('DP_ASYNC_CFG')){
	define('DP_ASYNC_CFG', [
		'framework'=>[
			'default_dispatcher'=>'inline',
			'pool_concurrency'=>3,
		],
	]);
}
$dp_async_framework_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/async/';
require_once $dp_async_framework_root.'Framework/Bootstrap.php';
require_once $dp_async_framework_root.'unit_tests/async_test_helpers.php';
require_once $dp_async_framework_root.'kernel/coroutine.php';

final class DpAsyncCallableTarget {
	public static function combine(string $left, string $right): string {
		return $left.'-'.$right;
	}
}

final class DpAsyncNonInvokableTarget {}

final class DpAsyncControlledDispatcher implements \Dataphyre\Async\Contracts\Dispatcher {
	/** @var list<array{resolve:callable,reject:callable}> */
	public array $settlers=[];

	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise {
		return new \dataphyre\async\promise(function(callable $resolve, callable $reject): void {
			$this->settlers[]=['resolve'=>$resolve, 'reject'=>$reject];
		});
	}
}

function dp_async_framework_summary(string $function): array {
	$json=$function();
	$decoded=json_decode($json, true);
	if(!is_array($decoded)){
		throw new RuntimeException('Async helper returned invalid JSON.');
	}
	return $decoded;
}

test('async framework native coverage exercises helper-backed tasks batches pools and extensions', static function(Context $t): void {
	$pending=dp_async_framework_summary('DataphyreUnitTests\\async_pending_task_state_summary_json');
	$t->same('fulfilled', $pending['fulfilled_state']);
	$t->same('ready', $pending['fulfilled_value']);
	$t->same('rejected', $pending['rejected_state']);
	$t->same('nope', $pending['rejected_reason']);

	$recovery=dp_async_framework_summary('DataphyreUnitTests\\async_rejection_recovery_summary_json');
	$t->same('rejected', $recovery['original_state']);
	$t->same('recovered:unit-failure', $recovery['recovered_value']);
	$t->same(1, $recovery['finalized']);

	$extension=dp_async_framework_summary('DataphyreUnitTests\\async_manager_extension_summary_json');
	$t->isTrue($extension['dispatcher_cached']);
	$t->same('job:21', $extension['task_value']);
	$t->contains('not registered', $extension['missing_driver_error']);

	$empty=dp_async_framework_summary('DataphyreUnitTests\\async_empty_batch_and_pool_summary_json');
	$t->same(0, $empty['batch_count']);
	$t->same([], $empty['all']);
	$t->same([], $empty['settled']);
	$t->same([], $empty['pool_empty']);

	$inline=dp_async_framework_summary('DataphyreUnitTests\\async_inline_dispatch_summary_json');
	$t->same(42, $inline['value']);
	$t->same('value:42', $inline['chained_value']);

	$batch=dp_async_framework_summary('DataphyreUnitTests\\async_batch_summary_json');
	$t->same(2, $batch['count']);
	$t->isTrue($batch['tasks_are_pending_tasks']);
	$t->same(['first', 'SECOND'], $batch['all']);

	$pool=dp_async_framework_summary('DataphyreUnitTests\\async_pool_summary_json');
	$t->same(['0:6', '1:2', '2:4'], $pool['mapped']);
	$t->same(null, $pool['each_value']);
	$t->isTrue($pool['each_fulfilled']);
})->tag('async', 'coverage')->group('framework-coverage');

test('async framework covers facade manager invoker coroutine and pool edge paths', static function(Context $t): void {
	\Dataphyre\Async\AsyncManager::flush();
	$manager=\Dataphyre\Async\Async::manager();
	$t->same('inline', $manager->defaultDispatcher());
	$t->same(3, $manager->poolConcurrency());
	$t->same(\Dataphyre\Async\Dispatchers\CoroutineDispatcher::class, $manager->dispatcher('coroutine')::class);

	$t->same('run:7', \Dataphyre\Async\Async::run(static fn(int $value): string=>'run:'.$value, [7])->value());
	$coroutineTask=\Dataphyre\Async\Async::coroutine(static fn(string $value): string=>strtoupper($value), ['scheduled']);
	$t->same('pending', $coroutineTask->state());
	\dataphyre\async\coroutine::run();
	$t->same('SCHEDULED', $coroutineTask->value());

	$raw=new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve('raw'));
	$t->same('raw', \Dataphyre\Async\Async::wrap($raw)->value());
	$t->same(['one', 'two'], \Dataphyre\Async\Async::all([
		static fn(): string=>'one',
		static fn(): string=>'two',
	], 'inline')->value());
	$t->same('winner', \Dataphyre\Async\Async::race([static fn(): string=>'winner'], 'inline')->value());
	$settled=\Dataphyre\Async\Async::settled([
		static fn(): string=>'ok',
		static function(): never { throw new RuntimeException('settled-failure'); },
	], 'inline')->value();
	$t->same('fulfilled', $settled[0]['status']);
	$t->same('rejected', $settled[1]['status']);

	$pending=\Dataphyre\Async\PendingTask::fromPromise(new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve('existing')));
	$t->same($pending, $manager->batch([$pending])->tasks()[0]);
	$pending->cancel();

	$t->same('left-right', \Dataphyre\Async\Support\TaskInvoker::invoke([DpAsyncCallableTarget::class, 'combine'], ['left', 'right']));
	$invalidTask='';
	try{
		\Dataphyre\Async\Support\TaskInvoker::invoke(DpAsyncNonInvokableTarget::class);
	}catch(InvalidArgumentException $exception){
		$invalidTask=$exception->getMessage();
	}
	$t->same('Async task is not invokable.', $invalidTask);

	$blankDriver='';
	try{
		$manager->extend('  ', static fn(): mixed=>null);
	}catch(InvalidArgumentException $exception){
		$blankDriver=$exception->getMessage();
	}
	$t->same('Async dispatcher name cannot be empty.', $blankDriver);
	$manager->extend('invalid-result', static fn(): stdClass=>new stdClass());
	$invalidDriver='';
	try{
		$manager->dispatcher('invalid-result');
	}catch(RuntimeException $exception){
		$invalidDriver=$exception->getMessage();
	}
	$t->contains('did not return a dispatcher', $invalidDriver);

	$controlled=new DpAsyncControlledDispatcher();
	$manager->extend('controlled', static fn(): DpAsyncControlledDispatcher=>$controlled);
	$failedPool=$manager->pool(2, 'controlled')->map(['a', 'b'], static fn(string $value): string=>$value);
	($controlled->settlers[0]['reject'])(new RuntimeException('pool-first-failure'));
	($controlled->settlers[1]['resolve'])('late-success');
	$t->same('rejected', $failedPool->state());

	$controlledAgain=new DpAsyncControlledDispatcher();
	$manager->extend('controlled', static fn(): DpAsyncControlledDispatcher=>$controlledAgain);
	$twiceFailedPool=$manager->pool(2, 'controlled')->map(['a', 'b'], static fn(string $value): string=>$value);
	($controlledAgain->settlers[0]['reject'])(new RuntimeException('pool-first'));
	($controlledAgain->settlers[1]['reject'])(new RuntimeException('pool-second'));
	$t->same('pool-first', $twiceFailedPool->reason()->getMessage());
})->tag('async', 'coverage')->group('framework-coverage');

test('async promise covers retry timeout chaining cancellation and delayed settlement paths', static function(Context $t): void {
	$constructorFailure=new \dataphyre\async\promise(static function(): never {
		throw new RuntimeException('executor-failure');
	});
	$t->same('executor-failure', $constructorFailure->value()->getMessage());

	$attempts=0;
	$retried=\dataphyre\async\promise::retry(static function()use(&$attempts): \dataphyre\async\promise {
		$attempts++;
		return new \dataphyre\async\promise(static function(callable $resolve, callable $reject)use(&$attempts): void {
			$attempts===1 ? $reject('retry-once') : $resolve('retry-success');
		});
	}, 2);
	$t->same('retry-success', $retried->value());

	$finalRetry=\dataphyre\async\promise::retry(static fn(): \dataphyre\async\promise=>new \dataphyre\async\promise(
		static fn(callable $resolve, callable $reject): mixed=>$reject('retry-final')
	), 1);
	$t->same('retry-final', $finalRetry->value());

	$delayedAttempts=0;
	$delayedRetry=\dataphyre\async\promise::retry(static function()use(&$delayedAttempts): \dataphyre\async\promise {
		$delayedAttempts++;
		return new \dataphyre\async\promise(static function(callable $resolve, callable $reject)use(&$delayedAttempts): void {
			$delayedAttempts===1 ? $reject('wait') : $resolve('after-wait');
		});
	}, 2, 1);
	\dataphyre\async\coroutine::run();
	$t->same('after-wait', $delayedRetry->value());

	$fulfilled=new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve('fulfilled'));
	$rejected=new \dataphyre\async\promise(static fn(callable $resolve, callable $reject): mixed=>$reject(new RuntimeException('rejected')));
	$allSettled=\dataphyre\async\promise::all_settled([$fulfilled, $rejected]);
	$t->same('fulfilled', $allSettled->value()[0]['status']);
	$t->same('rejected', $allSettled->value()[1]['status']);

	$fast=\dataphyre\async\promise::with_timeout(static fn(callable $resolve): mixed=>$resolve('fast'), 100);
	$t->same('fast', $fast->value());
	$fastFailure=\dataphyre\async\promise::with_timeout(static fn(callable $resolve, callable $reject): mixed=>$reject('fast-failure'), 100);
	$t->same('fast-failure', $fastFailure->value());
	$timedOut=\dataphyre\async\promise::with_timeout(static function(): void {}, 1);
	\dataphyre\async\coroutine::run();
	$t->same('Promise timed out', $timedOut->value()->getMessage());

	$t->same('fulfilled', \Dataphyre\Async\Async::timeout($fulfilled, 100)->value());
	$t->same('fulfilled', \Dataphyre\Async\Async::timeout(\Dataphyre\Async\PendingTask::fromPromise($fulfilled), 100)->value());
	$t->same('retry-facade', \Dataphyre\Async\Async::retry(static fn(): \dataphyre\async\promise=>new \dataphyre\async\promise(
		static fn(callable $resolve): mixed=>$resolve('retry-facade')
	), 1)->value());

	$t->same('fulfilled', $fulfilled->then()->value());
	$t->same('rejected', $rejected->then()->state());
	$fulfilledThrow=$fulfilled->then(static function(): never { throw new RuntimeException('fulfilled-handler'); });
	$t->same('fulfilled-handler', $fulfilledThrow->value()->getMessage());
	$rejectedThrow=$rejected->then(null, static function(): never { throw new RuntimeException('rejected-handler'); });
	$t->same('rejected-handler', $rejectedThrow->value()->getMessage());
	$finalized=0;
	$finallyRejected=$rejected->finally(static function()use(&$finalized): void { $finalized++; });
	$t->same(1, $finalized);
	$t->same('rejected', $finallyRejected->state());

	$cancelCallbacks=0;
	$cancelled=new \dataphyre\async\promise(static function(): void {}, static function()use(&$cancelCallbacks): void { $cancelCallbacks+=10; });
	$t->same($cancelled, $cancelled->on_cancel(static function()use(&$cancelCallbacks): void { $cancelCallbacks++; }));
	$cancelled->cancel();
	$t->same(11, $cancelCallbacks);
	$t->isTrue($cancelled->is_cancelled());
	$t->isFalse($cancelled->settled());
	$t->same('pending', $cancelled->state());
	$t->same(null, $cancelled->value());
	$t->same('pending', $cancelled->then(static fn(): string=>'unreachable')->state());

	$resolvePending=null;
	$pendingResolution=new \dataphyre\async\promise(static function(callable $resolve)use(&$resolvePending): void { $resolvePending=$resolve; });
	$queuedFulfillment=$pendingResolution->then(static fn(string $value): string=>'queued-'.$value);
	$resolvePending('value');
	$resolvePending('ignored');
	$t->same('queued-value', $queuedFulfillment->value());

	$rejectPending=null;
	$pendingRejection=new \dataphyre\async\promise(static function(callable $resolve, callable $reject)use(&$rejectPending): void { $rejectPending=$reject; });
	$queuedRejection=$pendingRejection->catch(static fn(string $reason): string=>'caught-'.$reason);
	$rejectPending('reason');
	$rejectPending('ignored');
	$t->same('caught-reason', $queuedRejection->value());

	$inner=new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve('nested'));
	$outer=new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve($inner));
	$t->same('nested', $outer->value());
})->tag('async', 'coverage')->group('framework-coverage');
