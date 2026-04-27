<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async\Support;

final class TaskInvoker {

	public static function invoke(mixed $task, array $arguments=[]): mixed {
		if(is_array($task) && is_callable($task)){
			return $task(...$arguments);
		}
		if(is_callable($task)){
			return $task(...$arguments);
		}
		if(is_string($task) && class_exists($task)){
			$instance=new $task();
			if(is_callable($instance)){
				return $instance(...$arguments);
			}
		}
		throw new \InvalidArgumentException('Async task is not invokable.');
	}
}
