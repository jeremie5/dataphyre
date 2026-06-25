<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async\Dispatchers;

use Dataphyre\Async\Contracts\Dispatcher;
use Dataphyre\Async\Support\TaskInvoker;

/**
 * Dispatches async tasks immediately in the current PHP process.
 *
 * Inline dispatch is useful for tests, lightweight workers, and environments
 * without an external queue. The dispatcher still returns a Dataphyre promise so
 * callers can use the same success/error contract as deferred dispatchers.
 */
final class InlineDispatcher implements Dispatcher {

	/**
	 * Invokes the task synchronously and resolves or rejects a promise.
	 *
	 * Task invocation is delegated to TaskInvoker so callable, invokable, and
	 * descriptor inputs follow the same contract as other dispatcher
	 * implementations.
	 *
	 * @param mixed $task Callable task or descriptor accepted by TaskInvoker.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @return \dataphyre\async\promise Promise resolved with the task result or rejected with the thrown error.
	 */
	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise {
		return new \dataphyre\async\promise(static function($resolve, $reject)use($task, $arguments){
			try{
				$resolve(TaskInvoker::invoke($task, $arguments));
			}catch(\Throwable $throwable){
				$reject($throwable);
			}
		});
	}
}
