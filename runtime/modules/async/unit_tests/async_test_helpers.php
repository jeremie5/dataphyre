<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

$async_runtime_root=rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/async/';
require_once $async_runtime_root.'kernel/promise.php';
require_once $async_runtime_root.'Framework/Contracts/Dispatcher.php';
require_once $async_runtime_root.'Framework/Support/TaskInvoker.php';
require_once $async_runtime_root.'Framework/Dispatchers/InlineDispatcher.php';
require_once $async_runtime_root.'Framework/Dispatchers/CoroutineDispatcher.php';
require_once $async_runtime_root.'Framework/PendingTask.php';
require_once $async_runtime_root.'Framework/Batch.php';
require_once $async_runtime_root.'Framework/Pool.php';
require_once $async_runtime_root.'Framework/AsyncManager.php';
require_once $async_runtime_root.'Framework/Async.php';

final class AsyncInvokableTask {
	public function __invoke(string $prefix, int $value): string {
		return $prefix.':'.($value * 3);
	}
}

function async_pending_task_state_summary_json(): string {
	$fulfilled=\Dataphyre\Async\PendingTask::fromPromise(new \dataphyre\async\promise(static function($resolve): void {
		$resolve('ready');
	}));
	$rejected=\Dataphyre\Async\PendingTask::fromPromise(new \dataphyre\async\promise(static function($resolve, $reject): void {
		$reject('nope');
	}));
	return json_encode([
		'fulfilled_state'=>$fulfilled->state(),
		'fulfilled_value'=>$fulfilled->value('fallback'),
		'fulfilled_reason_default'=>$fulfilled->reason('none'),
		'fulfilled_settled'=>$fulfilled->settled(),
		'fulfilled_pending'=>$fulfilled->pending(),
		'rejected_state'=>$rejected->state(),
		'rejected_value_default'=>$rejected->value('fallback'),
		'rejected_reason'=>$rejected->reason('none'),
		'rejected_settled'=>$rejected->settled(),
	], JSON_UNESCAPED_SLASHES);
}

function async_rejection_recovery_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$finalized=0;
	$task=\Dataphyre\Async\Async::inline(static function(): void {
		throw new \RuntimeException('unit-failure');
	});
	$recovered=$task
		->catch(static fn(\Throwable $throwable): string=>'recovered:'.$throwable->getMessage())
		->finally(static function()use(&$finalized): void {
			$finalized++;
		});
	return json_encode([
		'original_state'=>$task->state(),
		'original_reason_class'=>get_class($task->reason()),
		'recovered_state'=>$recovered->state(),
		'recovered_value'=>$recovered->value(),
		'finalized'=>$finalized,
	], JSON_UNESCAPED_SLASHES);
}

function async_manager_extension_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$manager=\Dataphyre\Async\Async::manager();
	$manager->extend(' UnitInline ', static fn(): \Dataphyre\Async\Contracts\Dispatcher=>new \Dataphyre\Async\Dispatchers\InlineDispatcher());
	$first=$manager->dispatcher('unitinline');
	$second=$manager->dispatcher('UNITINLINE');
	$task=$manager->dispatch(AsyncInvokableTask::class, ['job', 7], 'unitinline');
	$error='';
	try{
		$manager->dispatcher('missing-driver');
	}catch(\RuntimeException $exception){
		$error=$exception->getMessage();
	}
	return json_encode([
		'dispatcher_cached'=>$first===$second,
		'task_state'=>$task->state(),
		'task_value'=>$task->value(),
		'missing_driver_error'=>$error,
	], JSON_UNESCAPED_SLASHES);
}

function async_empty_batch_and_pool_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$batch=\Dataphyre\Async\Async::manager()->batch([], 'inline');
	$pool=\Dataphyre\Async\Async::pool(0, 'inline');
	return json_encode([
		'batch_count'=>$batch->count(),
		'all'=>$batch->all()->value(),
		'race'=>$batch->race()->value('fallback'),
		'settled'=>$batch->settled()->value(),
		'pool_empty'=>$pool->map([], static fn($value): mixed=>$value)->value(),
	], JSON_UNESCAPED_SLASHES);
}

function async_inline_dispatch_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$task=\Dataphyre\Async\Async::inline(static function(int $left, int $right): int {
		return ($left * 10)+$right;
	}, [4, 2]);
	$chained=$task->then(static fn(int $value): string=>'value:'.$value);
	return json_encode([
		'state'=>$task->state(),
		'value'=>$task->value(),
		'chained_state'=>$chained->state(),
		'chained_value'=>$chained->value(),
	], JSON_UNESCAPED_SLASHES);
}

function async_batch_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$batch=\Dataphyre\Async\Async::manager()->batch([
		static fn(): string=>'first',
		['task'=>static fn(string $value): string=>strtoupper($value), 'arguments'=>['second']],
	], 'inline');
	return json_encode([
		'count'=>$batch->count(),
		'tasks_are_pending_tasks'=>$batch->tasks()[0] instanceof \Dataphyre\Async\PendingTask && $batch->tasks()[1] instanceof \Dataphyre\Async\PendingTask,
		'all'=>$batch->all()->value(),
		'race'=>$batch->race()->value(),
	], JSON_UNESCAPED_SLASHES);
}

function async_pool_summary_json(): string {
	\Dataphyre\Async\AsyncManager::flush();
	$pool=\Dataphyre\Async\Async::pool(0, 'inline');
	$mapped=$pool->map([3, 1, 2], static fn(int $value, int $index): string=>$index.':'.($value * 2));
	$each=$pool->each(['a', 'b'], static fn(string $value): string=>$value.'!');
	return json_encode([
		'mapped'=>$mapped->value(),
		'each_value'=>$each->value('not-null'),
		'each_fulfilled'=>$each->fulfilled(),
	], JSON_UNESCAPED_SLASHES);
}
