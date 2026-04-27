<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

class scheduling {

	private const CACHE_PATH='cache/scheduling/';
	private static ?string $active_scheduler_name=null;

    public static function run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(!isset($app_override))$app_override=APP;
		$name=self::normalize_scheduler_name($name);
		if($name===''){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler name is invalid', $T='warning');
			return false;
		}
		$scheduler=self::normalize_scheduler_definition(
			$name,
			$file_path,
			$frequency,
			$timeout,
			$memory_limit,
			$dependencies,
			$app_override,
		);
		if($scheduler===null){
			return false;
		}
		self::persist_scheduler_definition($scheduler);
		if(self::can_run($scheduler)===true){
			core::file_put_contents_forced(self::last_run_file($name), (string)time());
			if(false!==core::file_put_contents_forced(self::running_lock_file($name), '')){
				register_shutdown_function([self::class, 'dispatch_registered_scheduler'], $name, $app_override);
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed locking scheduler');
				return false;
			}
		}
		return true;
    }

	public static function valid_scheduler_name(string $name): bool {
		return self::normalize_scheduler_name($name)!=='';
	}

	public static function begin_task_runner(string $name): void {
		self::$active_scheduler_name=self::valid_scheduler_name($name) ? $name : null;
	}

	public static function end_task_runner(): void {
		self::$active_scheduler_name=null;
	}

	public static function in_task_runner(): bool {
		return self::$active_scheduler_name!==null;
	}

	public static function current_scheduler_name(): ?string {
		return self::$active_scheduler_name;
	}

	public static function scheduler_directory(string $name): string {
		$name=self::normalize_scheduler_name($name);
		return ROOTPATH['dataphyre'].self::CACHE_PATH.($name!=='' ? $name.'/' : '');
	}

	public static function scheduler_properties_file(string $name): string {
		return self::scheduler_directory($name).'properties.json';
	}

	public static function running_lock_file(string $name): string {
		return self::scheduler_directory($name).'running_lock';
	}

	public static function last_run_file(string $name): string {
		return self::scheduler_directory($name).'last_run';
	}

	public static function read_scheduler(string $name): ?array {
		if(!self::valid_scheduler_name($name)){
			return null;
		}
		$properties_file=self::scheduler_properties_file($name);
		if(!is_file($properties_file)){
			return null;
		}
		$contents=@file_get_contents($properties_file);
		if(!is_string($contents) || trim($contents)===''){
			return null;
		}
		$scheduler=json_decode($contents, true);
		if(!is_array($scheduler)){
			return null;
		}
		return self::normalize_loaded_scheduler_definition($name, $scheduler);
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
		$last_run_file=self::last_run_file((string)$scheduler['name']);
		$running_lock_file=self::running_lock_file((string)$scheduler['name']);
		clearstatcache(true, $last_run_file);
		clearstatcache(true, $running_lock_file);
		$last_run=self::read_last_run_timestamp($last_run_file);
		$time_since_last_run=$last_run===null ? 999999 : max(0, time()-$last_run);
		if(is_file($running_lock_file)){
			if($time_since_last_run>=$scheduler['timeout']){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler execution forced as it has timed out (it has been '.$time_since_last_run.'s since last execution)');
				@unlink($running_lock_file);
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

	private static function dispatch_registered_scheduler(string $name, string $app_override): void {
		try{
			$url=self::scheduler_dispatch_url($name, $app_override);
			if($url===null){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to resolve scheduler dispatch URL', $T='warning');
				return;
			}
			$headers=['X-Traffic-Source: internal_traffic'];
			if(function_exists('curl_init')){
				$ch=curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_TIMEOUT_MS, 150);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 150);
				curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
				curl_exec($ch);
				curl_close($ch);
				return;
			}
			$context=stream_context_create([
				'http'=>[
					'method'=>'GET',
					'timeout'=>0.15,
					'header'=>implode("\r\n", $headers)."\r\n",
				],
			]);
			@file_get_contents($url, false, $context);
		}catch(\Throwable $exception){
			pre_init_error('Fatal error on Dataphyre Scheduling shutdown callback', $exception);
		}
	}

	private static function scheduler_dispatch_url(string $name, string $app_override): ?string {
		$self_addr=trim((string)($_SERVER['SELF_ADDR'] ?? ''));
		if($self_addr===''){
			return null;
		}
		$scheme=((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['REQUEST_SCHEME'] ?? '')==='https')) ? 'https' : 'http';
		$url=$scheme.'://'.$self_addr.'/dataphyre/scheduler/'.rawurlencode($name);
		if($app_override!==''){
			$override_value=core::app_override_request_value($app_override);
			if($override_value!==false && $override_value!==''){
				$url.='?'.http_build_query([
					'app_override'=>$override_value,
				]);
			}
		}
		return $url;
	}

	private static function normalize_scheduler_name(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z0-9._-]+$/', $name)!==1){
			return '';
		}
		return $name;
	}

	private static function normalize_scheduler_definition(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, string $app_override): ?array {
		$file_path=realpath($file_path) ?: $file_path;
		if(!is_file($file_path)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler file does not exist: '.$file_path, $T='warning');
			return null;
		}
		$normalized_dependencies=[];
		foreach($dependencies as $dependency){
			$dependency_path=realpath((string)$dependency) ?: (string)$dependency;
			if($dependency_path==='' || !is_file($dependency_path)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler dependency does not exist: '.$dependency_path, $T='warning');
				return null;
			}
			$normalized_dependencies[$dependency_path]=true;
		}
		return [
            'name'=>$name,
            'file_path'=>$file_path,
            'frequency'=>max(0.0, $frequency),
            'dependencies'=>array_keys($normalized_dependencies),
            'timeout'=>max(1.0, $timeout),
            'memory_limit'=>trim($memory_limit)==='' ? '128M' : $memory_limit,
			'app_override'=>$app_override,
        ];
	}

	private static function normalize_loaded_scheduler_definition(string $name, array $scheduler): array {
		$file_path=realpath((string)($scheduler['file_path'] ?? '')) ?: (string)($scheduler['file_path'] ?? '');
		$dependencies=[];
		foreach((array)($scheduler['dependencies'] ?? []) as $dependency){
			$dependency_path=realpath((string)$dependency) ?: (string)$dependency;
			if($dependency_path!==''){
				$dependencies[$dependency_path]=true;
			}
		}
		return [
			'name'=>$name,
			'file_path'=>$file_path,
			'frequency'=>max(0.0, (float)($scheduler['frequency'] ?? 0.0)),
			'dependencies'=>array_keys($dependencies),
			'timeout'=>max(1.0, (float)($scheduler['timeout'] ?? 1.0)),
			'memory_limit'=>trim((string)($scheduler['memory_limit'] ?? ''))==='' ? '128M' : (string)$scheduler['memory_limit'],
			'app_override'=>(string)($scheduler['app_override'] ?? ''),
		];
	}

	private static function persist_scheduler_definition(array $scheduler): void {
		$properties_file=self::scheduler_properties_file((string)$scheduler['name']);
		$payload=json_encode($scheduler, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		if(!is_string($payload)){
			return;
		}
		$existing=@file_get_contents($properties_file);
		if($existing===$payload){
			return;
		}
		core::file_put_contents_forced($properties_file, $payload);
	}

	private static function read_last_run_timestamp(string $last_run_file): ?int {
		if(!is_file($last_run_file)){
			return null;
		}
		$contents=@file_get_contents($last_run_file);
		if(!is_string($contents)){
			return null;
		}
		$timestamp=(int)trim($contents);
		return $timestamp>0 ? $timestamp : null;
	}
	
}
