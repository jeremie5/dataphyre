<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\async;

/**
 * Cooperative Fiber scheduler used by Dataphyre async helpers.
 *
 * The coroutine kernel keeps an in-process event loop with prioritized runnable Fibers, sleeping Fibers, deferred Fibers,
 * and a small shared context bag. Work only progresses while run() is active; create(), defer(), set_timeout(), and
 * set_interval() enqueue work but do not start the loop by themselves. All scheduler queues are static and process-local.
 *
 * This scheduler is cooperative rather than preemptive. Tasks yield by calling sleep(), returning, throwing, or suspending
 * through Fiber APIs. Exceptions inside scheduled Fibers remove that task from the loop; promise wrappers convert thrown
 * exceptions into promise rejections.
 */
class coroutine{

	/** @var array<int, mixed> Legacy task storage retained for compatibility with older async internals. */
	protected static $tasks=[];
	/** @var int Monotonic identifier assigned to Fibers, timers, deferred work, and waiting entries. */
	protected static $id=0;
	/** @var array<int, array{time:float, task:\Fiber}> Sleeping Fibers keyed by scheduler id. */
	protected static $waiting=[];
	/** @var array<int, \Fiber> Runnable Fibers keyed by scheduler id. */
	protected static $fibers=[];
	/** @var bool Reentrancy guard for the event loop. */
	protected static $event_loop_running=false;
	/** @var array<int, array{task:\Fiber}> Deferred Fibers moved to the runnable queue after the current pass. */
	protected static $deferred=[];
	/** @var array<mixed, mixed> Process-local async context shared by scheduled tasks. */
	protected static $context=[];
	/** @var array<int, array<int, int>> Runnable task ids grouped by priority. */
	protected static $prioritized_tasks=[];

	/**
	 * Creates a runnable Fiber task and returns its scheduler id.
	 *
	 * Higher priority values run earlier within each event-loop pass. The callable is wrapped directly in a Fiber and is
	 * not invoked until run() starts or resumes the task.
	 *
	 * @param callable $callable Fiber body.
	 * @param int $priority Priority bucket, sorted descending.
	 * @return int Scheduler id that can be passed to cancel().
	 */
	public static function create(callable $callable, int $priority=0): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$id=self::$id++;
		self::$fibers[$id]=new \Fiber($callable);
		self::$prioritized_tasks[$priority][$id]=$id;
		return $id;
	}

	/**
	 * Runs the cooperative event loop until all queued work is finished.
	 *
	 * The loop starts unstarted Fibers, resumes suspended Fibers, promotes sleeping Fibers whose wake time has elapsed, and
	 * moves deferred Fibers into the runnable queue. Reentrant calls are ignored so tasks can safely call helpers that might
	 * attempt to run the loop again.
	 *
	 * Failed Fibers are discarded after their Throwable is caught. The loop sleeps briefly between passes to avoid spinning
	 * when all remaining work is timer-bound.
	 */
	public static function run(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if(self::$event_loop_running){
			return;
		}
		self::$event_loop_running=true;
		while(!empty(self::$fibers) || !empty(self::$waiting) || !empty(self::$deferred)){
			krsort(self::$prioritized_tasks);
			foreach(self::$prioritized_tasks as $priority=>$task_ids){
				foreach($task_ids as $id){
					if(!isset(self::$fibers[$id])){
						unset(self::$prioritized_tasks[$priority][$id]);
						continue;
					}
					$fiber=self::$fibers[$id];
					try{
						if(!$fiber->isStarted()){
							$fiber->start();
						} elseif($fiber->isSuspended()){
							$fiber->resume();
						}
						if($fiber->isTerminated()){
							unset(self::$fibers[$id]);
							unset(self::$prioritized_tasks[$priority][$id]);
						}
					}catch(\Throwable $e){
						unset(self::$fibers[$id]);
						unset(self::$prioritized_tasks[$priority][$id]);
					}
				}
				if(empty(self::$prioritized_tasks[$priority])){
					unset(self::$prioritized_tasks[$priority]);
				}
			}
			foreach(self::$waiting as $id=>$waiting_task){
				if(microtime(true) >= $waiting_task['time']){
					self::$fibers[$id]=$waiting_task['task'];
					unset(self::$waiting[$id]);
				}
			}
			foreach(self::$deferred as $id=>$deferred_task){
				self::$fibers[$id]=$deferred_task['task'];
				unset(self::$deferred[$id]);
			}
			usleep(1000);
		}
		self::$event_loop_running=false;
	}

	/**
	 * Suspends the current Fiber for at least the requested number of seconds.
	 *
	 * The current Fiber is moved to the waiting queue and will be made runnable by run() once the wake timestamp has passed.
	 * Calling sleep() outside a Fiber records a null task in current PHP versions, so callers should only use it from
	 * scheduled coroutine work.
	 *
	 * @param float $seconds Delay before the Fiber may resume.
	 */
	public static function sleep(float $seconds): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$fiber=\Fiber::getCurrent();
		$id=self::$id++;
		self::$waiting[$id]=[
			'time'=>microtime(true)+$seconds,
			'task'=>$fiber
		];
		\Fiber::suspend();
	}

	/**
	 * Wraps callable execution in a Dataphyre async promise.
	 *
	 * The callable is scheduled as a Fiber. Generator results are consumed with yield from before the promise is resolved.
	 * Throwables reject the promise, keeping error propagation explicit for promise consumers.
	 *
	 * @param callable $callable Work to execute asynchronously.
	 * @return object Promise that resolves with the callable result or rejects with a Throwable.
	 */
	public static function async(callable $callable): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return new \dataphyre\async\promise(function($resolve, $reject)use($callable){
			self::create(function()use($callable, $resolve, $reject){
				try{
					$result=$callable();
					if($result instanceof \Generator){
						$result=yield from $result;
					}
					$resolve($result);
				}catch(\Throwable $throwable){
					$reject($throwable);
				}
			});
		});
	}

	/**
	 * Schedules a callable to run once after a delay.
	 *
	 * @param callable $callable Work to run after the delay.
	 * @param int $milliseconds Delay in milliseconds.
	 * @return int Scheduler id for the timeout task.
	 */
	public static function set_timeout(callable $callable, int $milliseconds): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return self::create(function()use($callable, $milliseconds){
			self::sleep($milliseconds/1000);
			$callable();
		});
	}

	/**
	 * Schedules a callable to run repeatedly with a fixed delay between executions.
	 *
	 * The interval task is an infinite coroutine. It must be cancelled or allowed to terminate through an exception to leave
	 * the event loop.
	 *
	 * @param callable $callable Work to run after each delay.
	 * @param int $milliseconds Delay in milliseconds.
	 * @return int Scheduler id for the interval task.
	 */
	public static function set_interval(callable $callable, int $milliseconds): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return self::create(function()use($callable, $milliseconds){
			while(true){
				self::sleep($milliseconds/1000);
				$callable();
			}
		});
	}

	/**
	 * Cancels a runnable task by scheduler id.
	 *
	 * Current implementation removes only runnable Fibers; sleeping and deferred entries with the same id are not removed.
	 * It does not interrupt a currently executing Fiber or invoke cancellation hooks.
	 *
	 * @param int $id Scheduler id returned by create(), set_timeout(), set_interval(), or defer().
	 */
	public static function cancel(int $id): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if(isset(self::$fibers[$id])){
			unset(self::$fibers[$id]);
		}
	}

	/**
	 * Defers a callable until after the current event-loop pass.
	 *
	 * Deferred work is promoted to the runnable queue by run() after current runnable and waiting queues have been checked.
	 *
	 * @param callable $callable Work to run later in the loop.
	 * @return int Scheduler id for the deferred task.
	 */
	public static function defer(callable $callable): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$id=self::$id++;
		self::$deferred[$id]=['task'=>new \Fiber($callable)];
		return $id;
	}

	/**
	 * Creates a promise and maps its resolution through a then() callback.
	 *
	 * Despite the name, this method does not block the event loop or synchronously unwrap the result; it returns the chained
	 * promise produced by promise::then().
	 *
	 * @param callable $callable Work to wrap in async().
	 * @return mixed Chained promise returned by promise::then() after registering the pass-through continuation.
	 */
	public static function await(callable $callable): mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return self::async($callable)->then(function($result){
			return $result;
		});
	}

	/**
	 * Stores a value in the process-local async context bag.
	 *
	 * Context is shared across all scheduled tasks in the current PHP process and is not scoped per Fiber. Values remain
	 * until overwritten because this helper does not expose a clear/remove operation.
	 *
	 * @param int|string $key Context key.
	 * @param mixed $value Context value stored without cloning or serialization.
	 */
	public static function set_context(mixed $key, mixed $value): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		self::$context[$key]=$value;
	}

	/**
	 * Reads a value from the process-local async context bag.
	 *
	 * @param int|string $key Context key.
	 * @return mixed Stored value, or null when the key is absent.
	 */
	public static function get_context(mixed $key) : mixed{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		return self::$context[$key]??null;
	}
	
}
