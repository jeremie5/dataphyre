<?php
/*************************************************************************
*  Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

namespace dataphyre\async;

class process {

	public static $task_kill_list=[];
	public static $queued_tasks=[];
	public static $execution_timeout=10; // in seconds
	public static $waitfor_loop_time=1000; // in microseconds

	static function waitfor_all(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ASYNC_WAITFOR_ALL",...func_get_args())) return $early_return;
		if(!empty(self::$queued_tasks)){
			foreach(self::$queued_tasks as $taskid){
				self::waitfor($taskid);
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="All tasks have finished or timed-out");
	}

	static function waitfor(string|null $taskid){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ASYNC_WAITFOR",...func_get_args())) return $early_return;
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
	
	static function result(string|null $taskid, $wipe=true){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ASYNC_RESULT",...func_get_args())) return $early_return;
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
	
	static function create(int $start_line, string $file, array|null $variables=array(), $logging=false) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ASYNC_CREATE",...func_get_args())) return $early_return;
		global $configurations;
		$lines="";
		$i=0;
		if(!empty($configurations['dataphyre']['async']['included_vars'])){
			$variables=array_merge($variables,$configurations['dataphyre']['async']['included_vars']);
		}
		if(!empty($configurations['dataphyre']['async']['excluded_vars'])){
			$variables=array_diff_key($variables,$configurations['dataphyre']['async']['excluded_vars']);
		}
		$variables??=[];
		// Find a taskid that currently doesn't exist using epoch and a huge random number as to make it almost impossible for two tasks to have the same taskid
		while(true){
			if(!in_array($taskid=time()."_".rand(1,99999999999999),self::$queued_tasks)){
				break;
			}
		}
		$file=file($file); // Load file into memory
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
		foreach($configurations['dataphyre']['async']['dependencies'] as $depedency){
			$pre.='require("'.$depedency.'");'.PHP_EOL;
		}
		$pre.='function task($vars){'.PHP_EOL;
		$pre.='foreach($vars as $var_name=>$value){${$var_name}=$value;}'.PHP_EOL;
		$post='}'.PHP_EOL;
		$post.='$result'."=json_encode(task(unserialize('".serialize($variables)."')));".PHP_EOL;
		$post.='file_put_contents("'.__DIR__.'/../../cache/tasks/'.$taskid.'_done.php",$result);'.PHP_EOL;
		$post.='unlink(__FILE__);'.PHP_EOL;
		if(false===core::file_put_contents_forced(__DIR__."/../../cache/tasks/".$taskid.".php", $pre.$lines.$post)){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAsync: Unable to write task file to cache task folder.', 'safemode');
		}
		exec("php ".__DIR__."/../../cache/tasks/".$taskid.".php > /dev/null 2> /dev/null &", $process_pid);
		self::$queued_tasks[]=$taskid;
		if($continue_in_background==false){
			self::$task_kill_list[]=[$taskid=>$process_pid];
		}
		return $taskid;
	}
	
}