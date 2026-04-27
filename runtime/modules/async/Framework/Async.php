<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

final class Async {

	public static function manager(): AsyncManager {
		return AsyncManager::instance();
	}

	public static function dispatch(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return self::manager()->dispatch($task, $arguments, $driver);
	}

	public static function run(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return self::dispatch($task, $arguments, $driver);
	}

	public static function inline(mixed $task, array $arguments=[]): PendingTask {
		return self::dispatch($task, $arguments, 'inline');
	}

	public static function coroutine(mixed $task, array $arguments=[]): PendingTask {
		return self::dispatch($task, $arguments, 'coroutine');
	}

	public static function wrap(\dataphyre\async\promise $promise): PendingTask {
		return PendingTask::fromPromise($promise);
	}

	public static function all(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->all();
	}

	public static function race(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->race();
	}

	public static function settled(array $tasks, ?string $driver=null): PendingTask {
		return self::manager()->batch($tasks, $driver)->settled();
	}

	public static function pool(?int $concurrency=null, ?string $driver=null): Pool {
		return self::manager()->pool($concurrency, $driver);
	}

	public static function timeout(PendingTask|\dataphyre\async\promise $task, int $timeout): PendingTask {
		$promise=$task instanceof PendingTask ? $task->rawPromise() : $task;
		return PendingTask::fromPromise(\dataphyre\async\promise::timeout($promise, $timeout));
	}

	public static function retry(callable $task, int $retries, int $delay=0): PendingTask {
		return PendingTask::fromPromise(\dataphyre\async\promise::retry($task, $retries, $delay));
	}

	public static function after(callable $task, int $milliseconds): int {
		return \dataphyre\async::set_timeout($task, $milliseconds);
	}

	public static function every(callable $task, int $milliseconds): int {
		return \dataphyre\async::set_interval($task, $milliseconds);
	}

	public static function cancel(int $task_id): void {
		\dataphyre\async::cancel($task_id);
	}
}
