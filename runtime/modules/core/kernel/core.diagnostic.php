<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\core;

if(class_exists(__NAMESPACE__.'\\diagnostic', false)){
	return;
}

class diagnostic{

	public static function pre_tests(): void {
		$verbose=[];
		// Runtime information
		if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])){
			$verbose[]=['module'=>'core', 'info'=>'You are connected to a load balancer or proxy using https.', 'time'=>time()];
			if($_SERVER['HTTP_X_FORWARDED_PROTO']==='https'){
				$verbose[]=['module'=>'core', 'info'=>'Traffic between web server and load balancer or proxy is encrypted.', 'time'=>time()];
			}
			else
			{
				$verbose[]=['module'=>'core', 'info'=>'Traffic between web server and load balancer or proxy is not encrypted.', 'time'=>time()];
			}
		}
		else
		{
			if(($_SERVER['HTTPS'] ?? '')==='on'){
				$verbose[]=['module'=>'core', 'info'=>'You ('.($_SERVER['REMOTE_ADDR'] ?? 'unknown').') are connected directly to the server using https.', 'time'=>time()];
			}
			else
			{
				$verbose[]=['module'=>'core', 'info'=>'You are connected directly to the server without https.', 'time'=>time()];
			}
		}
		// Check for rootpath definition
		if(!defined('ROOTPATH') || empty(ROOTPATH) || !is_array(ROOTPATH)){
			$verbose[]=['module'=>'core', 'error'=>'Rootpaths are not defined.', 'time'=>time()];
		}
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'core', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for core module
		$required_extensions=[
			'date',
			'mbstring',
			'pdo_sqlite',
			'openssl',
			'json',
			'session',
			'standard',
			'sockets'
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'core', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if($verbose!==[] && class_exists('\dataphyre\dpanel')){
			\dataphyre\dpanel::add_verbose($verbose);
		}
	}
	
    public static function post_tests(): void {
		$verbose=[];
		$config=\defined('DP_CORE_CFG') && \is_array(\DP_CORE_CFG) ? \DP_CORE_CFG : null;
        // Verify essential constants are defined and correctly assigned
        if(!defined('RUN_MODE')){
            $verbose[]=['module'=>'core', 'error'=>'Constant RUN_MODE constant is not defined.', 'time'=>time()];
        }
        if(!defined('REQUEST_IP_ADDRESS') || empty(REQUEST_IP_ADDRESS)){
            $verbose[]=['module'=>'core', 'error'=>'Constant REQUEST_IP_ADDRESS is undefined or empty.', 'time'=>time()];
        }
		if(!defined('REQUEST_USER_AGENT') || empty(REQUEST_USER_AGENT)){
            $verbose[]=['module'=>'core', 'error'=>'Constant REQUEST_USER_AGENT is undefined or empty.', 'time'=>time()];
        }
		// Validate dataphyre core configurations were loaded
		if(!is_array($config)){
            $verbose[]=[
				'module'=>'core',
				'level'=>'warning',
				'message'=>'Core configuration validation was skipped because DP_CORE_CFG is unavailable during this embedded diagnostic scan.',
				'time'=>time()
			];
		}
        // Validate session settings
        if(defined('RUN_MODE') && RUN_MODE==='diagnostic' && session_status()===PHP_SESSION_ACTIVE){
            $verbose[]=['module'=>'core', 'error'=>'Session was started in diagnostic run mode.', 'time'=>time()];
        }
        if(is_array($config)){
            // Check timezone setting
            if(isset($config['timezone']) && date_default_timezone_get() !== $config['timezone']){
                $verbose[]=['module'=>'core', 'error'=>'Timezone is not set according to dataphyre configuration.', 'time'=>time()];
            }
            // Validate memory and execution time limits
            if(isset($config['max_execution_memory']) && ini_get('memory_limit') !== $config['max_execution_memory']){
                $verbose[]=['module'=>'core', 'error'=>'Memory limit is not set according to configuration.', 'time'=>time()];
            }
            if(isset($config['max_execution_time']) && ini_get('max_execution_time') != $config['max_execution_time']){
                $verbose[]=['module'=>'core', 'error'=>'Max execution time is not set according to configuration.', 'time'=>time()];
            }
        }
        if($verbose!==[] && class_exists('\dataphyre\dpanel')){
        	\dataphyre\dpanel::add_verbose($verbose);
        }
    }

}
