<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\async;

/**
 * Launches and tracks file-backed background PHP tasks.
 *
 * The async process kernel extracts a task body from the caller file, writes a generated task
 * script into the Dataphyre task cache, starts it as a background PHP process, and polls for a
 * JSON result file. Dialbacks can replace wait, result, and create behavior for runtimes that
 * provide their own async process manager.
 *
 * Generated task files execute PHP copied from the source file and receive serialized caller
 * variables. Callers should pass trusted task sources and serializable values only; this helper
 * does not sandbox generated code, validate dependency paths, or escape data beyond PHP
 * serialization inside the generated wrapper.
 */
class process {

	/**
	 * @var array<string, int> Task ids mapped to process ids that should be killed after wait/result.
	 */
	public static $task_kill_list=[];

	/**
	 * @var array<int, string> Task ids queued in this request.
	 */
	public static $queued_tasks=[];

	/**
	 * Maximum seconds to wait for a generated task result file.
	 */
	public static $execution_timeout=10; // in seconds

	/**
	 * Poll interval in microseconds while waiting for task completion.
	 */
	public static $waitfor_loop_time=1000; // in microseconds

	/**
	 * Waits for every queued task in this request to finish or time out.
	 *
	 * A `CALL_ASYNC_WAITFOR_ALL` dialback can replace the built-in polling loop. Without a
	 * dialback, queued task ids are processed in insertion order.
	 *
	 * @return mixed Dialback return value when overridden, otherwise null.
	 */
	static function waitfor_all(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=\dataphyre\core::dialback("CALL_ASYNC_WAITFOR_ALL",...func_get_args())) return $early_return;
		if(!empty(self::$queued_tasks)){
			foreach(self::$queued_tasks as $taskid){
				self::waitfor($taskid);
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="All tasks have finished or timed-out");
	}

	/**
	 * Polls a task until its result file exists or the timeout is reached.
	 *
	 * Tasks registered in the kill list are terminated and have their generated files removed
	 * after waiting. Polling is file-based and process-local; it does not inspect child process
	 * status directly.
	 *
	 * @param string|null $taskid Task identifier returned by `create()`.
	 * @return mixed Dialback return value when overridden, otherwise null.
	 */
	static function waitfor(string|null $taskid){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=\dataphyre\core::dialback("CALL_ASYNC_WAITFOR",...func_get_args())) return $early_return;
		$time=0;
		if(!is_null($taskid)){
			if(in_array($taskid, self::$queued_tasks)){
				while(true){
					clearstatcache();
					if(file_exists(__DIR__."/../../cache/tasks/".$taskid."_done.php")){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Task $taskid finished");
						break;
					}
					usleep(self::$waitfor_loop_time);
					$time=$time+(self::$waitfor_loop_time/1000000);
					if($time>self::$execution_timeout){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Task $taskid timed-out");
						break;
					}
				}
				if(isset(self::$task_kill_list[$taskid])){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Cleaning up task $taskid");
					posix_kill(intval(self::$task_kill_list[$taskid]));
					unlink(__DIR__."/../../cache/tasks/".$taskid."_done.php");
					unlink(__DIR__."/../../cache/tasks/".$taskid.".php");
				}
			}
		}
	}

	/**
	 * Reads and optionally removes a completed task result.
	 *
	 * Result files contain JSON produced by the generated task wrapper. Unfinished tasks return
	 * the literal `task_unfinished` sentinel, preserving the legacy polling contract. A wiped
	 * result is deleted after decoding and removed from the queued task list.
	 *
	 * @param string|null $taskid Task identifier returned by `create()`.
	 * @param bool $wipe Whether to delete the result file after reading.
	 * @return mixed Decoded task result, `task_unfinished`, null for null task ids, or dialback return value.
	 */
	static function result(string|null $taskid, $wipe=true){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=\dataphyre\core::dialback("CALL_ASYNC_RESULT",...func_get_args())) return $early_return;
		if(!is_null($taskid)){
			if(false!==$result=file_get_contents(__DIR__."/../../cache/tasks/".$taskid."_done.php")){
				if($wipe===true){
					unlink(__DIR__."/../../cache/tasks/".$taskid."_done.php");
				}
				unset(self::$queued_tasks[array_search($taskid, self::$queued_tasks)]);
				return json_decode($result, true);
			}
			if(isset(self::$task_kill_list[$taskid])){
				posix_kill(intval(self::$task_kill_list[$taskid]));
				unlink(__DIR__."/../../cache/tasks/".$taskid."_done.php");
				unlink(__DIR__."/../../cache/tasks/".$taskid.".php");
			}
			return "task_unfinished";
		}
	}

	/**
	 * Creates and starts a generated background task script.
	 *
	 * The task body is copied from `$file` starting at `$start_line` until a `TASK-END` marker.
	 * Variables are serialized into the wrapper, configured dependencies are required before the
	 * task runs, and the result is written as JSON to the task cache. The generated script is
	 * started through the system shell with the `php` executable available on PATH.
	 *
	 * @param int $start_line Zero-based source line where the task body begins.
	 * @param string $file Source file containing the task body and `TASK-END` marker.
	 * @param array<string, mixed>|null $variables Variables injected into the task function scope.
	 * @param bool $logging Whether generated task tracelogging should be enabled.
	 * @return string Generated task identifier.
	 */
	static function create(int $start_line, string $file, array|null $variables=array(), $logging=false) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=\dataphyre\core::dialback("CALL_ASYNC_CREATE",...func_get_args())) return $early_return;
		$lines="";
		$i=0;
		if(!empty(DP_ASYNC_CFG['included_vars'])){
			$variables=array_merge($variables,DP_ASYNC_CFG['included_vars']);
		}
		if(!empty(DP_ASYNC_CFG['excluded_vars'])){
			$variables=array_diff_key($variables,DP_ASYNC_CFG['excluded_vars']);
		}
		$variables??=[];
		while(true){
			if(!in_array($taskid=time()."_".rand(1,99999999999999),self::$queued_tasks)){
				break;
			}
		}
		$file=file($file);
		while(true){
			if(strpos($file[$start_line+$i], "TASK-END")!==false){
				break;
			}
			else
			{
				$lines.=$file[$start_line+$i];
			}
			$i++;
		}
		$pre='<?php'.PHP_EOL;
		$pre.='$is_task=true;'.PHP_EOL;
		$pre.='$taskid="'.$taskid.'";'.PHP_EOL;
		if($logging==true){
			$pre.='$task_enable_tracelog=true;'.PHP_EOL;
		}
		foreach((DP_ASYNC_CFG['dependencies'] ?? []) as $depedency){
			$pre.='require("'.$depedency.'");'.PHP_EOL;
		}
		$pre.='function task($vars){'.PHP_EOL;
		$pre.='foreach($vars as $var_name=>$value){${$var_name}=$value;}'.PHP_EOL;
		$post='}'.PHP_EOL;
		$post.='$result'."=json_encode(task(unserialize('".serialize($variables)."')));".PHP_EOL;
		$post.='file_put_contents("'.__DIR__.'/../../cache/tasks/'.$taskid.'_done.php",$result);'.PHP_EOL;
		$post.='unlink(__FILE__);'.PHP_EOL;
		if(false===\dataphyre\core::file_put_contents_forced(__DIR__."/../../cache/tasks/".$taskid.".php", $pre.$lines.$post)){
			\dataphyre\core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAsync: Unable to write task file to cache task folder.', 'safemode');
		}
		exec("php ".__DIR__."/../../cache/tasks/".$taskid.".php > /dev/null 2> /dev/null &", $process_pid);
		self::$queued_tasks[]=$taskid;
		if($continue_in_background==false){
			self::$task_kill_list[]=[$taskid=>$process_pid];
		}
		return $taskid;
	}
	
}
