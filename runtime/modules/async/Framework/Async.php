<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

/**
 * Static facade for Dataphyre async task dispatch, promise composition, pools, timers, retries, and cancellation.
 *
 * Async centralizes the public framework API around AsyncManager and the legacy dataphyre\async kernel. Methods return
 * PendingTask wrappers where promise-like behavior is expected, while timer methods expose kernel task ids for later
 * cancellation.
 */
final class Async {

	/**
	 * Returns the process-local async manager.
	 *
	 * @return AsyncManager Manager responsible for driver dispatch, batches, and pools.
	 */
	public static function manager(): AsyncManager {
		return AsyncManager::instance();
	}

	/**
	 * Dispatches a task through the selected async driver.
	 *
	 * Task target formats and driver names are normalized by AsyncManager. The returned PendingTask keeps the caller at the
	 * framework boundary instead of exposing raw kernel promises directly.
	 *
	 * @param mixed $task Callable, task descriptor, or driver-supported task reference.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @param ?string $driver Optional driver override.
	 * @return PendingTask Pending task wrapper for awaiting, chaining, or inspection.
	 */
	public static function dispatch(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return self::manager()->dispatch($task, $arguments, $driver);
	}

	/**
	 * Alias for dispatch() used by call sites that read better as immediate execution.
	 *
	 * @param mixed $task Callable, task descriptor, or driver-supported task reference.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @param ?string $driver Optional driver override.
	 * @return PendingTask Pending task wrapper.
	 */
	public static function run(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return self::dispatch($task, $arguments, $driver);
	}

	/**
	 * Dispatches a task through the inline driver.
	 *
	 * @param mixed $task Callable or task descriptor.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @return PendingTask Pending task wrapper produced by the inline driver.
	 */
	public static function inline(mixed $task, array $arguments=[]): PendingTask {
		return self::dispatch($task, $arguments, 'inline');
	}

	/**
	 * Dispatches a task through the coroutine driver.
	 *
	 * @param mixed $task Callable or coroutine-compatible task descriptor.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @return PendingTask Pending task wrapper produced by the coroutine driver.
	 */
	public static function coroutine(mixed $task, array $arguments=[]): PendingTask {
		return self::dispatch($task, $arguments, 'coroutine');
	}

	/**
	 * Wraps a legacy kernel promise in the framework PendingTask value.
	 *
	 * @param \dataphyre\async\promise $promise Raw async kernel promise.
	 * @return PendingTask Pending task wrapper around the supplied promise.
	 */
	public static function wrap(\dataphyre\async\promise $promise): PendingTask {
		return PendingTask::fromPromise($promise);
	}

	/**
	 * Dispatches a task batch and resolves when every task succeeds.
	 *
	 * @param array<int|string, mixed> $tasks Task descriptors passed to AsyncManager::batch().
	 * @param ?string $driver Optional driver override for the batch.
	 * @return PendingTask Pending task that resolves with all task results.
	 */
	public static function all(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->all();
	}

	/**
	 * Dispatches a task batch and resolves or rejects with the first completed task.
	 *
	 * @param array<int|string, mixed> $tasks Task descriptors passed to AsyncManager::batch().
	 * @param ?string $driver Optional driver override for the batch.
	 * @return PendingTask Pending task representing the race result.
	 */
	public static function race(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->race();
	}

	/**
	 * Dispatches a task batch and resolves after every task settles.
	 *
	 * @param array<int|string, mixed> $tasks Task descriptors passed to AsyncManager::batch().
	 * @param ?string $driver Optional driver override for the batch.
	 * @return PendingTask Pending task containing fulfilled and rejected outcomes.
	 */
	public static function settled(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->settled();
	}

	/**
	 * Creates a bounded-concurrency async pool.
	 *
	 * @param ?int $concurrency Optional maximum number of active tasks.
	 * @param ?string $driver Optional driver override for pool tasks.
	 * @return Pool Pool configured by the async manager.
	 */
	public static function pool(?int $concurrency=null, ?string $driver=null): Pool {
		return self::manager()->pool($concurrency, $driver);
	}

	/**
	 * Wraps a pending task or raw promise with a timeout.
	 *
	 * @param PendingTask|\dataphyre\async\promise $task Task or promise to guard.
	 * @param int $timeout Timeout in milliseconds.
	 * @return PendingTask Pending task that rejects or resolves according to the timeout wrapper.
	 */
	public static function timeout(PendingTask|\dataphyre\async\promise $task, int $timeout): PendingTask {
		$promise=$task instanceof PendingTask ? $task->rawPromise() : $task;
		return PendingTask::fromPromise(\dataphyre\async\promise::timeout($promise, $timeout));
	}

	/**
	 * Executes a callable through the promise retry helper.
	 *
	 * @param callable $task Callable attempted until success or retries are exhausted.
	 * @param int $retries Maximum retry attempts.
	 * @param int $delay Delay between attempts in milliseconds.
	 * @return PendingTask Pending task wrapping the retry promise.
	 */
	public static function retry(callable $task, int $retries, int $delay=0): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::retry($task, $retries, $delay));
	}

	/**
	 * Schedules a callable to run once after a delay.
	 *
	 * @param callable $task Callable scheduled by the kernel timer.
	 * @param int $milliseconds Delay before execution.
	 * @return int Kernel timer id that can be cancelled.
	 */
	public static function after(callable $task, int $milliseconds): int {
		return \dataphyre\async::set_timeout($task, $milliseconds);
	}

	/**
	 * Schedules a callable to run repeatedly at an interval.
	 *
	 * @param callable $task Callable scheduled by the kernel interval timer.
	 * @param int $milliseconds Interval between executions.
	 * @return int Kernel timer id that can be cancelled.
	 */
	public static function every(callable $task, int $milliseconds): int {
		return \dataphyre\async::set_interval($task, $milliseconds);
	}

	/**
	 * Cancels a kernel timer or scheduled async task by id.
	 *
	 * @param int $taskId Timer or scheduled task id returned by after(), every(), or the kernel.
	 */
	public static function cancel(int $taskId): void {
		\dataphyre\async::cancel($taskId);
	}
}
