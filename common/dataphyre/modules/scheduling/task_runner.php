<?php
/*************************************************************************
*  2020-2023 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

$scheduler_name=$_PARAM['scheduler'];
$scheduler_path = $rootpath['dataphyre'].'cache/scheduling/'.$scheduler_name;
if(!file_exists($running_lock_file)){
	if(method_exists("dataphyre\core", "unavailable")){
		dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed setting scheduler task lock at runtime', $T='safemode');
	}
}
$scheduler=file_get_contents($scheduler_properties_file=$rootpath['dataphyre'].'cache/scheduling/'.$scheduler_name.'/properties.json');
if(null!==$scheduler=json_decode($scheduler,true)){
	try {
		set_time_limit($scheduler['timeout']);
		ini_set('max_execution_time', $scheduler['timeout']);
		ini_set('memory_limit', $scheduler['memory_limit']);
		$is_task=true;
		define('RUN_MODE', 'headless');
		foreach($scheduler['dependencies'] as $dependency){
			echo'Including '.$dependency.'<br>';
			require_once($dependency);
		}
		if(function_exists("dp_module_present") && dp_module_present('tracelog')){
			new dataphyre\tracelog;
			dataphyre\tracelog::$enable=true;
		}
		if(method_exists("dataphyre\core", "add_config")){
			if(function_exists("dp_module_present") && dp_module_present('sql')){
				if(dp_module_present('cache')){
					dataphyre\core::add_config('dataphyre/sql/caching/default_policy', 'shared_cache');
				}
				else
				{
					dataphyre\core::add_config('dataphyre/sql/caching/default_policy', 'fs');
				}
			}
		}
		echo 'Running '.$scheduler['file_path'].'<br>';
		require_once($scheduler['file_path']);
	} catch(Throwable $e) {
		if(method_exists("dataphyre\core", "dialback")){
			$task=["scheduler"=>$scheduler];
			dataphyre\core::dialback("SCHEDULING_TASK_FAILED", $task);
		}
		if(function_exists("pre_init_error")){
			pre_init_error('Fatal error: scheduler task failed ('.$scheduler_name.')', $e);
		}
		echo'Execution error';
	}
}
else
{
	if(method_exists("dataphyre\core", "dialback")){
		$task=["scheduler"=>$scheduler];
		dataphyre\core::dialback("SCHEDULING_TASK_FAILED",$task);
	}
	if(function_exists("pre_init_error")){
		pre_init_error('Fatal error: scheduler does not exist ('.$scheduler_name.' at '.$scheduler_properties_file.')');
	}
	echo'Requested scheduler does not exist';
}

register_shutdown_function(function()use($scheduler_path){
	global $rootpath, $scheduler_name;
	$running_lock_file=$rootpath['dataphyre'].'cache/scheduling/'.$scheduler_name.'/running_lock';
	$last_run_file=$rootpath['dataphyre'].'cache/scheduling/'.$scheduler_name.'/last_run';
    if(dp_module_present('tracelog')){
        file_put_contents($scheduler_path.'/tracelog.html', dataphyre\tracelog::$tracelog, LOCK_EX);
		echo '<br>';
		echo '<br>';
		echo dataphyre\tracelog::$tracelog;
    }
    file_put_contents($last_run_file, time(), LOCK_EX);
	unlink($running_lock_file);
	if(file_exists($running_lock_file)){
		if(method_exists("dataphyre\core", "unavailable")){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed unsetting scheduler task lock', $T='safemode');
		}
	}
});