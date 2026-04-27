<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
$scheduler_name=(string)(\dataphyre\routing::$bindings['scheduler'] ?? '');
if(!class_exists('dataphyre\\scheduling', false) || !dataphyre\scheduling::valid_scheduler_name($scheduler_name)){
	http_response_code(400);
	echo'Invalid scheduler';
	die();
}
$scheduler_path=dataphyre\scheduling::scheduler_directory($scheduler_name);
$running_lock_file=dataphyre\scheduling::running_lock_file($scheduler_name);
if(!is_file($running_lock_file)){
	if(method_exists("dataphyre\core", "unavailable")){
		dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed setting scheduler task lock at runtime', $T='safemode');
	}
}
$scheduler_properties_file=dataphyre\scheduling::scheduler_properties_file($scheduler_name);
$scheduler=dataphyre\scheduling::read_scheduler($scheduler_name);
if($scheduler===null){
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

dataphyre\scheduling::begin_task_runner($scheduler_name);
	
try{
	$timeout=max(1, (int)ceil((float)($scheduler['timeout'] ?? 1)));
	@set_time_limit($timeout);
	@ini_set('max_execution_time', (string)$timeout);
	@ini_set('memory_limit', (string)($scheduler['memory_limit'] ?? '128M'));
	foreach($scheduler['dependencies'] as $dependency){
		if(!is_string($dependency) || $dependency==='' || !is_file($dependency)){
			throw new \RuntimeException('Scheduler dependency does not exist: '.(string)$dependency);
		}
		if(defined('IS_PRODUCTION') && IS_PRODUCTION===false) echo'Including '.$dependency.'<br>';
		require_once($dependency);
	}
	if(function_exists("dp_module_present") && dp_module_present('tracelog')){
		new dataphyre\tracelog;
		dataphyre\tracelog::$enable=true;
	}
	if(function_exists("dp_module_present") && dp_module_present('sql')){
		\dp_define_module_config('sql', 'DP_SQL_CFG');
		$default_cache_policy=is_array(DP_SQL_CFG['caching']['default_policy'] ?? null)
			? DP_SQL_CFG['caching']['default_policy']
			: ['type'=>'session', 'max_lifespan'=>'30 minute', 'hash_type'=>'md5'];
		$default_cache_policy['type']=dp_module_present('cache') ? 'shared_cache' : 'fs';
		if(!defined('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE')){
			define('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE', $default_cache_policy);
		}
	}
	if(!is_string($scheduler['file_path']) || $scheduler['file_path']==='' || !is_file($scheduler['file_path'])){
		throw new \RuntimeException('Scheduler file does not exist: '.(string)($scheduler['file_path'] ?? ''));
	}
	if(defined('IS_PRODUCTION') && IS_PRODUCTION===false) echo 'Running '.$scheduler['file_path'].'<br>';
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

register_shutdown_function(function()use($scheduler_path, $scheduler_name){
	try{
		$running_lock_file=dataphyre\scheduling::running_lock_file($scheduler_name);
		$last_run_file=dataphyre\scheduling::last_run_file($scheduler_name);
		if(\dp_module_present('tracelog')){
			file_put_contents($scheduler_path.'/tracelog.html', dataphyre\tracelog::$tracelog, LOCK_EX);
			echo '<br>';
			echo '<br>';
			if(defined('IS_PRODUCTION') && IS_PRODUCTION===false) echo dataphyre\tracelog::$tracelog;
		}
		file_put_contents($last_run_file, time(), LOCK_EX);
		if(is_file($running_lock_file)){
			@unlink($running_lock_file);
		}
		if(file_exists($running_lock_file)){
			if(method_exists("dataphyre\core", "unavailable")){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed unsetting scheduler task lock', $T='safemode');
			}
		}
		dataphyre\scheduling::end_task_runner();
	}catch(\Throwable $exception){
		pre_init_error('Fatal error on Dataphyre Scheduling (task runner) shutdown callback', $exception);
	}
});
