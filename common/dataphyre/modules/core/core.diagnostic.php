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
 
namespace dataphyre\core;

class diagnostic{

	public static function pre_tests(array &$verbose=[]): bool {
		$all_passed=true;
		// Runtime information
		if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])){
			$verbose[]=['module'=>'core', 'info'=>'You are connected to a load balancer or proxy using https.', 'time'=>time()];
			if($_SERVER['HTTP_X_FORWARDED_PROTO']==='https'){
				$verbose[]=['module'=>'core', 'info'=>'Traffic between web server and load balancer or proxy is encrypted.', 'time'=>time()];
			}
			else
			{
				$verbose[]=['module'=>'core', 'info'=>'Traffic between web server and load balancer or proxy is encrypted.', 'time'=>time()];
			}
		}
		else
		{
			if($_SERVER['HTTPS']==='on'){
				$verbose[]=['module'=>'core', 'info'=>'You ('.$_SERVER['REMOTE_ADDR'].') are connected directly to the server using https.', 'time'=>time()];
			}
			else
			{
				$verbose[]=['module'=>'core', 'info'=>'Traffic between web server and load balancer or proxy is encrypted.', 'time'=>time()];
			}
		}
		// Check for rootpath definition
		if(empty($GLOBALS['rootpath'])){
			$verbose[]=['module'=>'core', 'error'=>'Rootpaths are not defined.', 'time'=>time()];
			$all_passed=false;
		}
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'core', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
			$all_passed=false;
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
				$all_passed=false;
			}
		}
		return $all_passed;
	}
	
    public static function post_tests(array &$verbose=[]): bool {
        $all_passed=true;
        // Verify essential constants are defined and correctly assigned
        if(!defined('RUN_MODE')){
            $verbose[]=['module'=>'core', 'error'=>'Constant RUN_MODE constant is not defined.', 'time'=>time()];
            $all_passed=false;
        }
        if(!defined('REQUEST_IP_ADDRESS') || empty(REQUEST_IP_ADDRESS)){
            $verbose[]=['module'=>'core', 'error'=>'Constant REQUEST_IP_ADDRESS is undefined or empty.', 'time'=>time()];
            $all_passed=false;
        }
        if(!defined('REQUEST_USER_AGENT') || empty(REQUEST_USER_AGENT)){
            $verbose[]=['module'=>'core', 'error'=>'Constant REQUEST_USER_AGENT is undefined or empty.', 'time'=>time()];
            $all_passed=false;
        }
		// Validate dataphyre core configurations were loaded
		if(!isset($GLOBALS['configurations']['dataphyre'])){
            $verbose[]=['module'=>'core', 'error'=>'No configurations loaded.', 'time'=>time()];
            $all_passed=false;
		}
        // Validate session settings
        if(RUN_MODE !== 'diagnostic' && isset($_SESSION)){
            $verbose[]=['module'=>'core', 'error'=>'Session was started in diagnostic run mode.', 'time'=>time()];
            $all_passed=false;
        }
        // Check timezone setting
        if(date_default_timezone_get() !== $GLOBALS['configurations']['dataphyre']['timezone']){
            $verbose[]=['module'=>'core', 'error'=>'Timezone is not set according to dataphyre configuration.', 'time'=>time()];
            $all_passed=false;
        }
        // Validate memory and execution time limits
        if(ini_get('memory_limit') !== $GLOBALS['configurations']['dataphyre']['max_execution_memory']){
            $verbose[]=['module'=>'core', 'error'=>'Memory limit is not set according to configuration.', 'time'=>time()];
            $all_passed=false;
        }
        if(ini_get('max_execution_time') != $GLOBALS['configurations']['dataphyre']['max_execution_time']){
            $verbose[]=['module'=>'core', 'error'=>'Max execution time is not set according to configuration.', 'time'=>time()];
            $all_passed=false;
        }
        return $all_passed;
    }

}