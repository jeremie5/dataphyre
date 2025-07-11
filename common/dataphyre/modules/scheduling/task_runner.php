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
 
$scheduler_name=$_PARAM['scheduler'];
$scheduler_path=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler_name;
$running_lock_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler_name.'/running_lock';
if(!file_exists($running_lock_file)){
	if(method_exists("dataphyre\core", "unavailable")){
		dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed setting scheduler task lock at runtime', $T='safemode');
	}
}
$scheduler=file_get_contents($scheduler_properties_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler_name.'/properties.json');
if(null===$scheduler=json_decode($scheduler,true)){
	if(method_exists("dataphyre\core", "dialback")){
		$task=["scheduler"=>$scheduler];
		dataphyre\core::dialback("SCHEDULING_TASK_FAILED",$task);
	}
	if(function_exists("pre_init_error")){
		pre_init_error('Fatal error: scheduler does not exist ('.$scheduler_name.' at '.$scheduler_properties_file.')');
	}
	echo'Requested scheduler does not exist';
	die();
}
	
try{
	set_time_limit($scheduler['timeout']);
	ini_set('max_execution_time', $scheduler['timeout']);
	ini_set('memory_limit', $scheduler['memory_limit']);
	define('RUN_MODE', 'headless');
	foreach($scheduler['dependencies'] as $dependency){
		if(IS_PRODUCTION===false) echo'Including '.$dependency.'<br>';
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
	if(IS_PRODUCTION===false) echo 'Running '.$scheduler['file_path'].'<br>';
	require_once($scheduler['file_path']);
}catch(Throwable $e){
	if(method_exists("dataphyre\core", "dialback")){
		$task=["scheduler"=>$scheduler];
		dataphyre\core::dialback("SCHEDULING_TASK_FAILED", $task);
	}
	if(function_exists("pre_init_error")){
		pre_init_error('Fatal error: scheduler task failed ('.$scheduler_name.')', $e);
	}
	echo'Execution error';
}

register_shutdown_function(function()use($scheduler_path){
	try{
		global $scheduler_name;
		$running_lock_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler_name.'/running_lock';
		$last_run_file=ROOTPATH['dataphyre'].'cache/scheduling/'.$scheduler_name.'/last_run';
		if(dp_module_present('tracelog')){
			file_put_contents($scheduler_path.'/tracelog.html', dataphyre\tracelog::$tracelog, LOCK_EX);
			echo '<br>';
			echo '<br>';
			if(IS_PRODUCTION===false) echo dataphyre\tracelog::$tracelog;
		}
		file_put_contents($last_run_file, time(), LOCK_EX);
		unlink($running_lock_file);
		if(file_exists($running_lock_file)){
			if(method_exists("dataphyre\core", "unavailable")){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed unsetting scheduler task lock', $T='safemode');
			}
		}
	}catch(\Throwable $exception){
		pre_init_error('Fatal error on Dataphyre Scheduling (task runner) shutdown callback', $exception);
	}
});