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

final class CoroutineDispatcher implements Dispatcher {

	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise {
		return \dataphyre\async\coroutine::async(static function()use($task, $arguments){
			return TaskInvoker::invoke($task, $arguments);
		});
	}
}
