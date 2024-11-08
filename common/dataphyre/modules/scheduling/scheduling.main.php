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

namespace dataphyre;

class scheduling {

    public static function run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $rootpath, $app;
		if(!isset($app_override))$app_override=$app;
        $scheduler=[
            'name'=>$name,
            'file_path'=>$file_path,
            'frequency'=>$frequency,
            'dependencies'=>$dependencies,
            'timeout'=>$timeout,
            'memory_limit'=>$memory_limit
        ];
		$properties=$rootpath['dataphyre'].'cache/scheduling/'.$name.'/properties.json';
		if(!file_exists($properties)){
			core::file_put_contents_forced($properties, json_encode($scheduler));
		}
		if (self::can_run($scheduler)===true) {
			clearstatcache();
			$last_run_file=$rootpath['dataphyre'].'cache/scheduling/'.$name.'/last_run';
			file_put_contents($last_run_file, time(), LOCK_EX);
			$running_lock_file=$rootpath['dataphyre'].'cache/scheduling/'.$name.'/running_lock';
			if(false!==file_put_contents($running_lock_file,'', LOCK_EX)){
				register_shutdown_function(function($rootpath, $name, $app_override){
					$override_key=file_get_contents($rootpath['common_root']."app_override_key");
					$url=$_SERVER['SERVER_ADDR'].'/dataphyre/scheduler/'.$name.'?app_override='.$app_override.','.$override_key;
					$ch=curl_init();
					curl_setopt($ch,CURLOPT_URL,$url);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array(
						'X-Traffic-Source: internal_traffic'
					));
					curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
					curl_setopt($ch,CURLOPT_TIMEOUT_MS,1); // Timeout after 1 millisecond
					curl_setopt($ch,CURLOPT_NOSIGNAL,1);
					curl_exec($ch);
					curl_close($ch);
				}, $rootpath, $name, $app_override);
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed locking scheduler');
			}
		}
		return true;
    }

	private static function can_run(array $scheduler) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $rootpath, $is_task;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution frequency is '.$scheduler['frequency']);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution timeout is '.$scheduler['timeout']);
		if($is_task===true){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Tasks cannot trigger other tasks');
			return false;
		}
		\dataphyre\core::get_server_load_level();
		if(\dataphyre\core::$server_load_level>2){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Server load too high for scheduler', "warning");
			return false;
		}
		clearstatcache();
		$last_run=999999;
		$last_run_file=$rootpath['dataphyre'].'cache/scheduling/'.$scheduler['name'].'/last_run';
		if(file_exists($last_run_file)){
			$last_run=file_get_contents($last_run_file);
		}
		$time_since_last_run=(time()-(int)$last_run);
		$running_lock_file=$rootpath['dataphyre'].'cache/scheduling/'.$scheduler['name'].'/running_lock';
		if(file_exists($running_lock_file)){
			if($time_since_last_run>=$scheduler['timeout']){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler execution forced as it has timed out (it has been '.$time_since_last_run.'s since last execution)');
				return true;
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler is locked but not timed out (it has been '.$time_since_last_run.'s since last execution)');
			return false;
		}
		if($time_since_last_run>=$scheduler['frequency']){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler is due for execution (it has been '.$time_since_last_run.'s since last execution)');
			return true;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler is not due for execution (it has been '.$time_since_last_run.'s since last execution)');
		return false;
	}
	
}