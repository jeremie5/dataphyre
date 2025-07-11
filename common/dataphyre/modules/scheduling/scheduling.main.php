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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class scheduling {

    public static function run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(!isset($app_override))$app_override=APP;
        $scheduler=[
            'name'=>$name,
            'file_path'=>$file_path,
            'frequency'=>$frequency,
            'dependencies'=>$dependencies,
            'timeout'=>$timeout,
            'memory_limit'=>$memory_limit
        ];
		$properties=ROOTPATH['dataphyre'].'cache/scheduling/'.$name.'/properties.json';
		if(!file_exists($properties)){
			core::file_put_contents_forced($properties, json_encode($scheduler));
		}
		if(self::can_run($scheduler)===true){
			$last_run_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$name.'/last_run';
			core::file_put_contents_forced($last_run_file, time(), LOCK_EX);
			$running_lock_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$name.'/running_lock';
			if(false!==core::file_put_contents_forced($running_lock_file,'', LOCK_EX)){
				register_shutdown_function(function($name, $app_override){
					try{
						$override_key=file_get_contents(ROOTPATH['common_root']."app_override_key");
						$ch=curl_init();
						curl_setopt($ch,CURLOPT_URL, $_SERVER['SELF_ADDR'].'/dataphyre/scheduler/'.$name.'?app_override='.$app_override.','.$override_key);
						curl_setopt($ch, CURLOPT_HTTPHEADER, array(
							'X-Traffic-Source: internal_traffic'
						));
						curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
						curl_setopt($ch,CURLOPT_TIMEOUT_MS, 1);
						curl_setopt($ch,CURLOPT_NOSIGNAL, 1);
						curl_exec($ch);
						curl_close($ch);
					}catch(\Throwable $exception){
						pre_init_error('Fatal error on Dataphyre Scheduling shutdown callback', $exception);
					}
				}, $name, $app_override);
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed locking scheduler');
			}
		}
		return true;
    }

	private static function can_run(array $scheduler) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution frequency is '.$scheduler['frequency']);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution timeout is '.$scheduler['timeout']);
		\dataphyre\core::get_server_load_level();
		if(\dataphyre\core::$server_load_level>2){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Server load too high for scheduler', "warning");
			return false;
		}
		$last_run_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler['name'].'/last_run';
		if(file_exists($last_run_file)){
			$last_run=file_get_contents($last_run_file);
		}
		$time_since_last_run=(time()-(int)$last_run ?? 999999);
		$running_lock_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler['name'].'/running_lock';
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