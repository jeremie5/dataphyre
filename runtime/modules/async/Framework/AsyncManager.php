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

final class AsyncManager {

	private static ?self $instance=null;

	/** @var array<string, Dispatcher> */
	private array $dispatchers=[];

	/** @var array<string, callable> */
	private array $dispatcher_factories=[];

	private function __construct(){
		$this->dispatcher_factories['inline']=static function(): Dispatcher {
			return new InlineDispatcher();
		};
		$this->dispatcher_factories['sync']=$this->dispatcher_factories['inline'];
		$this->dispatcher_factories['coroutine']=static function(): Dispatcher {
			return new CoroutineDispatcher();
		};
	}

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function defaultDispatcher(): string {
		$default=(string)(DP_ASYNC_CFG['framework']['default_dispatcher'] ?? '');
		$default=strtolower(trim($default));
		return $default!=='' ? $default : 'coroutine';
	}

	public function poolConcurrency(): int {
		$concurrency=(int)(DP_ASYNC_CFG['framework']['pool_concurrency'] ?? 10);
		return max(1, $concurrency);
	}

	public function extend(string $driver, callable $resolver): void {
		$driver=strtolower(trim($driver));
		if($driver===''){
			throw new \InvalidArgumentException('Async dispatcher name cannot be empty.');
		}
		$this->dispatcher_factories[$driver]=$resolver;
		unset($this->dispatchers[$driver]);
	}

	public function dispatcher(?string $driver=null): Dispatcher {
		$driver=$driver!==null && trim($driver)!=='' ? strtolower(trim($driver)) : $this->defaultDispatcher();
		if(isset($this->dispatchers[$driver])){
			return $this->dispatchers[$driver];
		}
		if(!isset($this->dispatcher_factories[$driver])){
			throw new \RuntimeException("Async dispatcher '{$driver}' is not registered.");
		}
		$dispatcher=($this->dispatcher_factories[$driver])();
		if(!$dispatcher instanceof Dispatcher){
			throw new \RuntimeException("Async dispatcher '{$driver}' resolver did not return a dispatcher.");
		}
		return $this->dispatchers[$driver]=$dispatcher;
	}

	public function dispatch(mixed $task, array $arguments=[], ?string $driver=null): PendingTask {
		return PendingTask::fromPromise($this->dispatcher($driver)->dispatch($task, $arguments));
	}

	public function batch(array $tasks, ?string $driver=null): Batch {
		$pending_tasks=[];
		foreach($tasks as $task){
			if($task instanceof PendingTask){
				$pending_tasks[]=$task;
				continue;
			}
			if(is_array($task) && array_key_exists('task', $task)){
				$pending_tasks[]=$this->dispatch(
					$task['task'],
					is_array($task['arguments'] ?? null) ? array_values($task['arguments']) : [],
					is_string($task['driver'] ?? null) ? (string)$task['driver'] : $driver
				);
				continue;
			}
			$pending_tasks[]=$this->dispatch($task, [], $driver);
		}
		return new Batch($pending_tasks);
	}

	public function pool(?int $concurrency=null, ?string $driver=null): Pool {
		return new Pool($this, $concurrency ?? $this->poolConcurrency(), $driver);
	}
}
