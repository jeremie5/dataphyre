<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Registers and dispatches Dataphyre scheduler tasks through persisted runtime state.
 *
 * The scheduling kernel stores one JSON definition per scheduler under Dataphyre's cache/scheduling directory, tracks the
 * last attempted run timestamp, and uses a running_lock file to prevent overlapping execution. Scheduler registration is
 * intentionally cheap: run() refreshes the definition and only schedules a shutdown dispatch when frequency, timeout,
 * server load, and lock state allow it.
 *
 * Names are limited to alphanumeric characters, dot, underscore, and dash so cache paths cannot escape the scheduler
 * directory. Task execution is delegated to the internal scheduler HTTP route with an internal traffic header rather than
 * running the task inline in the registering request.
 */
class scheduling {

	/** @var string Cache path, relative to ROOTPATH['dataphyre'], where scheduler state is persisted. */
	private const CACHE_PATH='cache/scheduling/';
	/** @var ?string Scheduler currently being executed by a task runner route in this process. */
	private static ?string $active_scheduler_name=null;

    /**
     * Registers a scheduler definition and dispatches it after shutdown when it is due.
     *
     * The method validates the scheduler name, task file, and dependency files before writing properties.json. If the task
     * can run now, last_run is updated immediately, a running_lock file is created, and a shutdown callback performs a
     * short internal HTTP request to the scheduler route. Updating last_run before dispatch prevents concurrent requests
     * from enqueueing the same task while the shutdown callback is pending.
     *
     * @param string $name Scheduler name used for cache paths and route dispatch.
     * @param string $file_path PHP task file that will be executed by the scheduler route.
     * @param float $frequency Minimum seconds between attempted dispatches.
     * @param float $timeout Seconds after which an existing running lock is considered stale.
     * @param string $memory_limit Memory limit stored with the scheduler definition.
     * @param array<int, string> $dependencies Files that must exist before the scheduler is accepted.
     * @param ?string $app_override Application override to preserve in the internal dispatch request.
     * @return bool Whether the scheduler definition was accepted and persisted.
     */
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

	/**
	 * Reports whether a scheduler name can safely be used for persisted state.
	 *
	 * @param string $name Candidate scheduler name.
	 * @return bool Whether the normalized name is non-empty and path-safe.
	 */
	public static function valid_scheduler_name(string $name): bool {
		return self::normalize_scheduler_name($name)!=='';
	}

	/**
	 * Marks this process as executing a scheduler task.
	 *
	 * The task runner route uses this state so scheduler-aware code can tell whether it is running inside a scheduled task.
	 * Invalid names clear the active scheduler marker.
	 *
	 * @param string $name Scheduler name being executed.
	 */
	public static function begin_task_runner(string $name): void {
		self::$active_scheduler_name=self::valid_scheduler_name($name) ? $name : null;
	}

	/**
	 * Clears the active scheduler marker for this process.
	 */
	public static function end_task_runner(): void {
		self::$active_scheduler_name=null;
	}

	/**
	 * Reports whether this process is currently executing a scheduler task.
	 *
	 * @return bool Whether begin_task_runner() has set an active scheduler name.
	 */
	public static function in_task_runner(): bool {
		return self::$active_scheduler_name!==null;
	}

	/**
	 * Returns the scheduler currently being executed by this process.
	 *
	 * @return ?string Active scheduler name, or null outside task runner execution.
	 */
	public static function current_scheduler_name(): ?string {
		return self::$active_scheduler_name;
	}

	/**
	 * Returns the cache directory used for one scheduler.
	 *
	 * Invalid names resolve to the scheduler cache root rather than a child directory.
	 *
	 * @param string $name Scheduler name.
	 * @return string Absolute scheduler state directory path.
	 */
	public static function scheduler_directory(string $name): string {
		$name=self::normalize_scheduler_name($name);
		return ROOTPATH['dataphyre'].self::CACHE_PATH.($name!=='' ? $name.'/' : '');
	}

	/**
	 * Returns the JSON properties file path for one scheduler.
	 *
	 * @param string $name Scheduler name.
	 * @return string Absolute properties.json path.
	 */
	public static function scheduler_properties_file(string $name): string {
		return self::scheduler_directory($name).'properties.json';
	}

	/**
	 * Returns the lock file path that marks a scheduler as running.
	 *
	 * @param string $name Scheduler name.
	 * @return string Absolute running_lock path.
	 */
	public static function running_lock_file(string $name): string {
		return self::scheduler_directory($name).'running_lock';
	}

	/**
	 * Returns the timestamp file path used for frequency and timeout checks.
	 *
	 * @param string $name Scheduler name.
	 * @return string Absolute last_run path.
	 */
	public static function last_run_file(string $name): string {
		return self::scheduler_directory($name).'last_run';
	}

	/**
	 * Reads and normalizes a persisted scheduler definition.
	 *
	 * Invalid names, missing files, blank files, and malformed JSON all return null. Existing definitions are normalized so
	 * older cache files still expose the current scheduler definition shape.
	 *
	 * @param string $name Scheduler name.
	 * @return ?array{name:string, file_path:string, frequency:float, dependencies:array<int, string>, timeout:float, memory_limit:string, app_override:string} Scheduler definition, or null when unavailable.
	 */
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

	/**
	 * Decides whether a scheduler should dispatch during this request.
	 *
	 * A scheduler is blocked when server load is high, a non-stale running lock exists, or frequency has not elapsed since
	 * last_run. Stale locks are removed when timeout has elapsed so a failed task does not block future executions forever.
	 *
	 * @param array{name:string, frequency:float, timeout:float} $scheduler Normalized scheduler definition.
	 * @return bool Whether run() should register a shutdown dispatch.
	 */
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

	/**
	 * Sends the shutdown-time internal dispatch request.
	 *
	 * The request is intentionally short-lived and best-effort. Any thrown exception is written through the shutdown logger
	 * because this method runs after the main response lifecycle has effectively ended.
	 *
	 * @param string $name Scheduler name to dispatch.
	 * @param string $app_override Application override to include when resolvable.
	 */
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
			\dataphyre_shutdown_log('Fatal error on Dataphyre Scheduling shutdown callback', $exception);
		}
	}

	/**
	 * Builds the internal scheduler route URL for the current host.
	 *
	 * SELF_ADDR is required because scheduler dispatch must target the same application host that registered the task. The
	 * app override is encoded only when core::app_override_request_value() can convert it into a request-safe value.
	 *
	 * @param string $name Scheduler name.
	 * @param string $app_override Application override configured for the scheduler.
	 * @return ?string Internal scheduler dispatch URL, or null when SELF_ADDR is unavailable.
	 */
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

	/**
	 * Normalizes and validates a scheduler name.
	 *
	 * @param string $name Raw scheduler name.
	 * @return string Path-safe scheduler name, or an empty string when invalid.
	 */
	private static function normalize_scheduler_name(string $name): string {
		$name=trim($name);
		if($name==='' || preg_match('/^[A-Za-z0-9._-]+$/', $name)!==1){
			return '';
		}
		return $name;
	}

	/**
	 * Validates a scheduler registration and returns its persisted definition.
	 *
	 * Task and dependency paths are resolved with realpath() when possible and must point to existing files. Frequency is
	 * clamped to zero or higher, timeout to at least one second, and blank memory limits fall back to 128M.
	 *
	 * @param string $name Normalized scheduler name.
	 * @param string $file_path Scheduler task file.
	 * @param float $frequency Minimum seconds between attempted dispatches.
	 * @param float $timeout Seconds before a running lock is stale.
	 * @param string $memory_limit Memory limit stored with the task definition.
	 * @param array<int, string> $dependencies Dependency file paths.
	 * @param string $app_override Application override stored with the definition.
	 * @return ?array{name:string, file_path:string, frequency:float, dependencies:array<int, string>, timeout:float, memory_limit:string, app_override:string} Definition, or null when validation fails.
	 */
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

	/**
	 * Normalizes a scheduler definition loaded from properties.json.
	 *
	 * This path is intentionally more forgiving than registration: missing dependency files are ignored instead of making
	 * the loaded definition unreadable, because stale cache files should remain inspectable by diagnostics.
	 *
	 * @param string $name Scheduler name associated with the cache directory.
	 * @param array<string, mixed> $scheduler Decoded persisted definition.
	 * @return array{name:string, file_path:string, frequency:float, dependencies:array<int, string>, timeout:float, memory_limit:string, app_override:string} Normalized scheduler definition.
	 */
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

	/**
	 * Writes a scheduler definition when the persisted JSON has changed.
	 *
	 * @param array<string, mixed> $scheduler Normalized scheduler definition.
	 */
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

	/**
	 * Reads a positive Unix timestamp from the last_run file.
	 *
	 * @param string $last_run_file Absolute last_run path.
	 * @return ?int Positive timestamp, or null when missing or invalid.
	 */
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
