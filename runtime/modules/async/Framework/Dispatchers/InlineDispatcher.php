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

final class InlineDispatcher implements Dispatcher {

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
