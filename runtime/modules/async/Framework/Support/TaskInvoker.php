<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async\Support;

/**
 * Invokes async task descriptors using a uniform callable contract.
 *
 * Task descriptors may be direct callables, callable arrays, or class names for
 * invokable task objects. The invoker performs no queue orchestration; it only
 * normalizes the final in-process call boundary used by async workers.
 */
final class TaskInvoker {

	/**
	 * Invokes a task descriptor with positional arguments.
	 *
	 * Class-name descriptors are instantiated with a no-argument constructor and
	 * must produce an invokable object.
	 *
	 * @param mixed $task Callable, callable array, or invokable class name.
	 * @param list<mixed> $arguments Positional arguments expanded into the task callable in order.
	 * @return mixed value returned by the callable, callable array, or invokable task object.
	 * @throws \InvalidArgumentException When the descriptor cannot be invoked.
	 */
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
