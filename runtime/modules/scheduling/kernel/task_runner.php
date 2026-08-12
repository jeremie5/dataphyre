<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/** Executes one persisted scheduler definition through the internal task route. */
final class dataphyre_scheduling_task_runner {

	/** Dispatches the mounted task route unless an embedding runtime disabled it. */
	public static function dispatch_entrypoint(
		bool $no_dispatch,
		?callable $terminator=null,
		?callable $shutdown_registrar=null,
		array $runtime=[],
	): void {
		self::loadSchedulingFramework($runtime);
		if($no_dispatch!==true){
			self::dispatch($terminator,$shutdown_registrar,$runtime);
		}
	}

	/** Loads the policy-enabled scheduler kernel for the internal task route. */
	private static function loadSchedulingFramework(array $runtime): void {
		$loaded=array_key_exists('scheduling_loaded', $runtime)
			? (bool)$runtime['scheduling_loaded']
			: class_exists('dataphyre\\scheduling', false);
		if($loaded) return;
		$loader=$runtime['framework_loader'] ?? static fn(string $module): bool => method_exists('dataphyre\\core', 'load_framework_module')
			? \dataphyre\core::load_framework_module($module)
			: false;
		$loader('scheduling');
	}

	/**
	 * Validates, executes, and schedules cleanup for one scheduler request.
	 *
	 * @param ?callable $terminator Deterministic replacement for the legacy exit boundary.
	 * @param ?callable $shutdown_registrar Deterministic shutdown callback registrar.
	 * @param array<string,mixed> $runtime Optional request and dependency observations.
	 */
	public static function dispatch(?callable $terminator=null, ?callable $shutdown_registrar=null, array $runtime=[]): void {
		$ignore_user_abort=$runtime['ignore_user_abort'] ?? static fn(bool $ignore): int => ignore_user_abort($ignore);
		$ignore_user_abort(true);
		$scheduler_name=(string)($runtime['scheduler_name'] ?? (\dataphyre\routing::$bindings['scheduler'] ?? ''));
		$scheduling_available=array_key_exists('scheduling_available',$runtime)
			? (bool)$runtime['scheduling_available']
			: class_exists('dataphyre\\scheduling', false);
		if($scheduling_available!==true || !\dataphyre\scheduling::valid_scheduler_name($scheduler_name)){
			http_response_code(400);
			echo 'Invalid scheduler';
			self::terminate($terminator);
			return;
		}

		$scheduler_path=\dataphyre\scheduling::scheduler_directory($scheduler_name);
		$running_lock_file=\dataphyre\scheduling::running_lock_file($scheduler_name);
		$is_file=$runtime['is_file'] ?? static fn(string $path): bool => is_file($path);
		$scheduler_properties_file=\dataphyre\scheduling::scheduler_properties_file($scheduler_name);
		$scheduler=\dataphyre\scheduling::read_scheduler($scheduler_name);
		if($scheduler===null){
			self::dialback_failure($scheduler);
			self::pre_init_failure(
				'Fatal error: scheduler does not exist ('.$scheduler_name.' at '.$scheduler_properties_file.')',
				null,
				$runtime,
			);
			echo 'Requested scheduler does not exist';
			self::terminate($terminator);
			return;
		}
		$dispatch_claim=trim((string)($runtime['scheduler_claim'] ?? ''));
		$claim_handle=self::claimRunningLock($running_lock_file, $dispatch_claim, $runtime);
		if(!is_resource($claim_handle)){
			http_response_code(409);
			self::dialback_failure($scheduler);
			self::pre_init_failure('Scheduler dispatch is not pending or was already claimed ('.$scheduler_name.')',null,$runtime);
			echo 'Scheduler not pending';
			self::terminate($terminator);
			return;
		}

		\dataphyre\scheduling::begin_task_runner($scheduler_name);
		try{
			$timeout=max(1,(int)ceil((float)($scheduler['timeout'] ?? 1)));
			@set_time_limit($timeout);
			@ini_set('max_execution_time',(string)$timeout);
			@ini_set('memory_limit',(string)($scheduler['memory_limit'] ?? '128M'));
			foreach($scheduler['dependencies'] as $dependency){
				if(!is_string($dependency) || $dependency==='' || $is_file($dependency)!==true){
					throw new \RuntimeException('Scheduler dependency does not exist: '.(string)$dependency);
				}
				if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){echo 'Including '.$dependency.'<br>';}
				require_once $dependency;
			}
			if(self::module_present('tracelog',$runtime)){
				new \dataphyre\tracelog();
				\dataphyre\tracelog::$enable=true;
			}
			if(self::module_present('sql',$runtime)){
				\dp_define_module_config('sql','DP_SQL_CFG');
				$default_cache_policy=is_array(DP_SQL_CFG['caching']['default_policy'] ?? null)
					? DP_SQL_CFG['caching']['default_policy']
					: ['type'=>'session','max_lifespan'=>'30 minute','hash_type'=>'md5'];
				$default_cache_policy['type']=self::module_present('cache',$runtime) ? 'shared_cache' : 'fs';
				if(!defined('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE')){
					define('DP_SQL_DEFAULT_CACHE_POLICY_OVERRIDE',$default_cache_policy);
				}
			}
			if(!is_string($scheduler['file_path']) || $scheduler['file_path']==='' || $is_file($scheduler['file_path'])!==true){
				throw new \RuntimeException('Scheduler file does not exist: '.(string)($scheduler['file_path'] ?? ''));
			}
			if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){echo 'Running '.$scheduler['file_path'].'<br>';}
			require_once $scheduler['file_path'];
		}catch(\Throwable $failure){
			self::dialback_failure($scheduler);
			self::pre_init_failure('Fatal error: scheduler task failed ('.$scheduler_name.')',$failure,$runtime);
			echo 'Execution error';
		}

		$callback=static fn()=>self::finalize($scheduler_path,$scheduler_name,$runtime,$claim_handle);
		if($shutdown_registrar===null){
			register_shutdown_function($callback);
		}else{
			$shutdown_registrar($callback);
		}
	}

	/** Performs the lock, timestamp, trace, and active-state shutdown cleanup. */
	public static function finalize(string $scheduler_path, string $scheduler_name, array $runtime=[], mixed $claim_handle=null): void {
		try{
			$running_lock_file=\dataphyre\scheduling::running_lock_file($scheduler_name);
			$last_run_file=\dataphyre\scheduling::last_run_file($scheduler_name);
			$writer=$runtime['writer'] ?? static fn(string $path,mixed $value,int $flags=0): int|false => file_put_contents($path,$value,$flags);
			$is_file=$runtime['is_file'] ?? static fn(string $path): bool => is_file($path);
			$file_exists=$runtime['file_exists'] ?? static fn(string $path): bool => file_exists($path);
			$unlink=$runtime['unlink'] ?? static fn(string $path): bool => @unlink($path);
			if(self::module_present('tracelog',$runtime)){
				$writer($scheduler_path.'/tracelog.html',\dataphyre\tracelog::$tracelog,LOCK_EX);
				echo '<br><br>';
				if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){echo \dataphyre\tracelog::$tracelog;}
			}
			$writer($last_run_file,$runtime['timestamp'] ?? time(),LOCK_EX);
			if($is_file($running_lock_file)){
				$unlink($running_lock_file);
			}
			if(is_resource($claim_handle)){
				@flock($claim_handle, LOCK_UN);
				@fclose($claim_handle);
			}
			if($file_exists($running_lock_file) && method_exists('dataphyre\\core','unavailable')){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed unsetting scheduler task lock', $T='safemode');
			}
			\dataphyre\scheduling::end_task_runner();
		}catch(\Throwable $failure){
			$logger=$runtime['shutdown_logger'] ?? static fn(string $message,\Throwable $exception): mixed => \dataphyre_shutdown_log($message,$exception);
			$logger('Fatal error on Dataphyre Scheduling (task runner) shutdown callback',$failure);
		}
	}

	/**
	 * Claims the one pending dispatch and holds the claim lock through cleanup.
	 *
	 * The HMAC-authenticated claim must match the exclusive-create lock contents.
	 * A non-blocking advisory lock makes a captured callback unrepeatable while
	 * the first runner is executing, including across separate PHP workers.
	 *
	 * @return resource|false Locked handle, or false when no exact pending claim exists.
	 */
	private static function claimRunningLock(string $path, string $dispatch_claim, array $runtime=[]): mixed {
		if(preg_match('/^[a-f0-9]{64}$/D', $dispatch_claim)!==1){
			return false;
		}
		$opener=$runtime['lock_opener'] ?? static fn(string $lock_path): mixed => @fopen($lock_path, 'r+');
		$handle=is_callable($opener) ? $opener($path) : false;
		if(!is_resource($handle)){
			return false;
		}
		$locker=$runtime['lock_acquirer'] ?? static fn(mixed $lock_handle): bool => @flock($lock_handle, LOCK_EX|LOCK_NB);
		if(!is_callable($locker) || $locker($handle)!==true){
			@fclose($handle);
			return false;
		}
		@rewind($handle);
		$stored=trim((string)stream_get_contents($handle));
		if(preg_match('/^[a-f0-9]{64}$/D', $stored)!==1 || !hash_equals($stored, $dispatch_claim)){
			@flock($handle, LOCK_UN);
			@fclose($handle);
			return false;
		}
		return $handle;
	}

	private static function module_present(string $module, array $runtime): bool {
		if(isset($runtime['module_present']) && is_callable($runtime['module_present'])){
			return (bool)$runtime['module_present']($module);
		}
		return function_exists('dp_module_present') && (bool)\dp_module_present($module);
	}

	private static function dialback_failure(?array $scheduler): void {
		if(method_exists('dataphyre\\core','dialback')){
			\dataphyre\core::dialback('CALL_SCHEDULING_TASK_FAILED',['scheduler'=>$scheduler]);
		}
	}

	private static function pre_init_failure(string $message, ?\Throwable $failure, array $runtime): void {
		$handler=$runtime['pre_init_error'] ?? (function_exists('pre_init_error') ? 'pre_init_error' : null);
		if(is_callable($handler)){
			$failure===null ? $handler($message) : $handler($message,$failure);
		}
	}

	private static function terminate(?callable $terminator): void {
		if($terminator===null){exit;}
		$terminator();
	}
}

dataphyre_scheduling_task_runner::dispatch_entrypoint(
	defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')===true,
);
