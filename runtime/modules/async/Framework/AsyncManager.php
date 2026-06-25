<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async;

use Dataphyre\Async\Contracts\Dispatcher;
use Dataphyre\Async\Dispatchers\CoroutineDispatcher;
use Dataphyre\Async\Dispatchers\InlineDispatcher;

/**
 * Coordinates framework-level async dispatchers, tasks, batches, and pools.
 *
 * AsyncManager is the shared registry for dispatcher factories and dispatcher instances. It ships with inline/sync and coroutine dispatchers, resolves the configured default driver lazily, wraps dispatched promises in PendingTask objects, and normalizes batch/pool creation for callers that should not need to know the concrete dispatcher implementation.
 */
final class AsyncManager {

	private static ?self $instance=null;

	/** @var array<string, Dispatcher> */
	private array $dispatchers=[];

	/** @var array<string, callable> */
	private array $dispatcherFactories=[];

	/**
	 * Registers built-in dispatcher factories for a fresh manager instance.
	 *
	 * Inline and sync share the same dispatcher implementation, while coroutine dispatch delegates work to the async kernel coroutine runtime.
	 */
	private function __construct(){
		$this->dispatcherFactories['inline']=static function(): Dispatcher {
			return new InlineDispatcher();
		};
		$this->dispatcherFactories['sync']=$this->dispatcherFactories['inline'];
		$this->dispatcherFactories['coroutine']=static function(): Dispatcher {
			return new CoroutineDispatcher();
		};
	}

	/**
	 * Resolves the process-local async manager singleton.
	 *
	 * The instance owns dispatcher factories and cached dispatcher objects for the current runtime. Use flush() in tests or reconfiguration paths when dispatcher registrations need to be rebuilt from scratch.
	 *
	 * @return self Shared async manager instance.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Clears the shared async manager instance and cached dispatchers.
	 *
	 * The next instance() call creates a new registry with only built-in dispatcher factories. This is a runtime reset hook, not a cancellation mechanism for already dispatched work.
	 *
	 * @return void Singleton state is reset in place.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Resolves the configured default dispatcher name.
	 *
	 * Configuration is read from DP_ASYNC_CFG['framework']['default_dispatcher']. Blank or missing values fall back to coroutine, giving framework callers a non-blocking default when async support is loaded.
	 *
	 * @return string Lowercase dispatcher name.
	 */
	public function defaultDispatcher(): string {
		$default=(string)(DP_ASYNC_CFG['framework']['default_dispatcher'] ?? '');
		$default=strtolower(trim($default));
		return $default!=='' ? $default : 'coroutine';
	}

	/**
	 * Resolves the default concurrency for async pools.
	 *
	 * Configuration is read from DP_ASYNC_CFG['framework']['pool_concurrency'] and clamped to at least one worker so Pool instances always have a usable execution budget.
	 *
	 * @return int Positive pool concurrency.
	 */
	public function poolConcurrency(): int {
		$concurrency=(int)(DP_ASYNC_CFG['framework']['pool_concurrency'] ?? 10);
		return max(1, $concurrency);
	}

	/**
	 * Registers or replaces a dispatcher factory.
	 *
	 * Driver names are normalized to lowercase. Replacing a factory invalidates the cached dispatcher for that driver so future dispatches use the new implementation. Resolver callbacks must return objects implementing Dispatcher.
	 *
	 * @param string $driver Dispatcher driver name.
	 * @param callable $resolver Factory returning a Dispatcher instance.
	 * @return void Factory registry is updated in place.
	 * @throws \InvalidArgumentException When the driver name is blank.
	 */
	public function extend(string $driver, callable $resolver): void {
		$driver=strtolower(trim($driver));
		if($driver===''){
			throw new \InvalidArgumentException('Async dispatcher name cannot be empty.');
		}
		$this->dispatcherFactories[$driver]=$resolver;
		unset($this->dispatchers[$driver]);
	}

	/**
	 * Resolves and caches a dispatcher by driver name.
	 *
	 * Blank driver names use defaultDispatcher(). Factories are invoked lazily and the resulting dispatcher is cached for subsequent calls, preserving dispatcher-local state for the current manager lifetime.
	 *
	 * @param ?string $driver Optional dispatcher driver override.
	 * @return Dispatcher Resolved dispatcher instance.
	 * @throws \RuntimeException When the driver is unregistered or the factory returns a non-dispatcher value.
	 */
	public function dispatcher(?string $driver=null): Dispatcher {
		$driver=$driver!==null && trim($driver)!=='' ? strtolower(trim($driver)) : $this->defaultDispatcher();
		if(isset($this->dispatchers[$driver])){
			return $this->dispatchers[$driver];
		}
		if(!isset($this->dispatcherFactories[$driver])){
			throw new \RuntimeException("Async dispatcher '{$driver}' is not registered.");
		}
		$dispatcher=($this->dispatcherFactories[$driver])();
		if(!$dispatcher instanceof Dispatcher){
			throw new \RuntimeException("Async dispatcher '{$driver}' resolver did not return a dispatcher.");
		}
		return $this->dispatchers[$driver]=$dispatcher;
	}

	/**
	 * Dispatches one task through a resolved dispatcher.
	 *
	 * The selected dispatcher owns the execution model and returns a promise-like object. AsyncManager wraps that promise in PendingTask so framework callers get a consistent observation and waiting API.
	 *
	 * @param mixed $task Callable, command, or dispatcher-supported task payload.
	 * @param array<int,mixed> $arguments Positional arguments supplied to the task.
	 * @param ?string $driver Optional dispatcher driver override.
	 * @return PendingTask Pending task wrapper for the dispatched work.
	 */
	public function dispatch(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return PendingTask::fromPromise($this->dispatcher($driver)->dispatch($task, $arguments));
	}

	/**
	 * Normalizes many task declarations into a Batch.
	 *
	 * Existing PendingTask instances are preserved. Array declarations with a task key can supply arguments and an optional per-task driver. Any other value is dispatched as a task with no arguments through the batch driver.
	 *
	 * @param array<int,mixed> $tasks Pending tasks, task declarations, or raw tasks.
	 * @param ?string $driver Default dispatcher driver for tasks without their own driver.
	 * @return Batch Batch wrapping normalized pending tasks.
	 */
	public function batch(array $tasks, ?string $driver=null): Batch {
		$pendingTasks=[];
		foreach($tasks as $task){
			if($task instanceof PendingTask){
				$pendingTasks[]=$task;
				continue;
			}
			if(is_array($task) && array_key_exists('task', $task)){
				$pendingTasks[]=$this->dispatch(
					$task['task'],
					is_array($task['arguments'] ?? null) ? array_values($task['arguments']) : [],
					is_string($task['driver'] ?? null) ? (string)$task['driver'] : $driver
				);
				continue;
			}
			$pendingTasks[]=$this->dispatch($task, [], $driver);
		}
		return new Batch($pendingTasks);
	}

	/**
	 * Creates a pool for bounded-concurrency async dispatch.
	 *
	 * A null concurrency uses poolConcurrency(), while a provided value is passed through to Pool so pool-specific validation remains centralized there. The pool keeps a reference to this manager for task dispatch.
	 *
	 * @param ?int $concurrency Optional concurrency override.
	 * @param ?string $driver Optional dispatcher driver used by the pool.
	 * @return Pool Pool configured for this manager.
	 */
	public function pool(?int $concurrency=null, ?string $driver=null): Pool {
		return new Pool($this, $concurrency ?? $this->poolConcurrency(), $driver);
	}
}
