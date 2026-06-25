<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async\Contracts;

/**
 * Dispatches asynchronous tasks and returns a promise for their completion.
 *
 * Dispatchers may execute work in-process, queue it, hand it to an external
 * worker, or schedule it for later. Callers interact only with the returned
 * promise and should not rely on a specific execution backend.
 */
interface Dispatcher {

	/**
	 * Submits a task to the dispatcher.
	 *
	 * @param mixed $task Callable, task descriptor, or backend-specific task reference.
	 * @param list<mixed> $arguments Positional arguments supplied to the task when the backend executes it.
	 * @return \dataphyre\async\promise Promise representing completion, failure, or returned value.
	 */
	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise;
}
