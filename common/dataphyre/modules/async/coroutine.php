<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */


namespace dataphyre\async;

class coroutine{
	
	protected static $tasks=[];
	protected static $id=0;
	protected static $waiting=[];
	protected static $fibers=[];
	protected static $event_loop_running=false;
	protected static $deferred=[];
	protected static $context=[]; 
	protected static $prioritized_tasks=[];

	public static function create(callable $callable, int $priority=0): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$id=self::$id++;
		self::$fibers[$id]=new \Fiber($callable);
		self::$prioritized_tasks[$priority][]=$id;
		return $id;
	}

	public static function run(): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(self::$event_loop_running){
			return;
		}
		self::$event_loop_running=true;
		while(!empty(self::$fibers) || !empty(self::$waiting) || !empty(self::$deferred)){
			krsort(self::$prioritized_tasks); // Sort by priority in descending order
			foreach(self::$prioritized_tasks as $priority=>$task_ids){
				foreach($task_ids as $id){
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
					}catch(Exception $e){
						unset(self::$fibers[$id]);
						unset(self::$prioritized_tasks[$priority][$id]);
					}
				}
			}
			foreach(self::$waiting as $id=>$waitingTask){
				if(time() >= $waitingTask['time']){
					self::$fibers[$id]=$waitingTask['task'];
					unset(self::$waiting[$id]);
				}
			}
			foreach(self::$deferred as $id=>$deferred_task){
				self::$fibers[$id]=$deferred_task['task'];
				unset(self::$deferred[$id]);
			}
			usleep(1000); // Sleep to prevent busy waiting
		}
		self::$event_loop_running=false;
	}

	public static function sleep(int $seconds): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$fiber=\Fiber::getCurrent();
		$id=self::$id++;
		self::$waiting[$id]=[
			'time'=>time()+$seconds,
			'task'=>$fiber
		];
		\Fiber::suspend();
	}

	public static function async(callable $callable): object {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return new \dataphyre\async\promise(function($resolve, $reject)use($callable){
			self::create(function()use($callable, $resolve, $reject){
				try{
					$result=yield from $callable();
					$resolve($result);
				}catch(Exception $e){
					$reject($e);
				}
			});
		});
	}

	public static function set_timeout(callable $callable, int $milliseconds): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::create(function()use($callable, $milliseconds){
			self::sleep($milliseconds/1000);
			$callable();
		});
	}

	public static function set_interval(callable $callable, int $milliseconds): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::create(function()use($callable, $milliseconds){
			while(true){
				self::sleep($milliseconds/1000);
				$callable();
			}
		});
	}

	public static function cancel(int $id): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(isset(self::$fibers[$id])){
			unset(self::$fibers[$id]);
		}
	}

	public static function defer(callable $callable): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$id=self::$id++;
		self::$deferred[$id]=['task'=>new \Fiber($callable)];
		return $id;
	}

	public static function await(callable $callable): mixed {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return self::async($callable)->then(function($result){
			return $result;
		});
	}

	public static function set_context(mixed $key, mixed $value): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		self::$context[$key]=$value;
	}

	public static function get_context(mixed $key) : mixed{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		return self::$context[$key]??null;
	}
	
}