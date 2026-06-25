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
 * Dispatches tasks through Dataphyre's coroutine scheduler.
 *
 * The dispatcher preserves the shared Dispatcher contract while letting
 * coroutine::async control scheduling, promise resolution, and exception
 * propagation.
 */
final class CoroutineDispatcher implements Dispatcher {

	/**
	 * Schedules a task invocation and returns its promise.
	 *
	 * Task invocation is delegated to TaskInvoker so coroutine dispatch accepts
	 * the same callable and descriptor shapes as inline or queue dispatchers.
	 *
	 * @param mixed $task Callable task or descriptor accepted by TaskInvoker.
	 * @param array<int|string, mixed> $arguments Arguments passed to the task.
	 * @return \dataphyre\async\promise Promise returned by the coroutine scheduler.
	 */
	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise {
		return \dataphyre\async\coroutine::async(static function()use($task, $arguments){
			return TaskInvoker::invoke($task, $arguments);
		});
	}
}
