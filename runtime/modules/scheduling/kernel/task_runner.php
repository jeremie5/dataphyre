<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/** Executes one persisted scheduler definition through the fixed scheduler CGI. */
final class dataphyre_scheduling_task_runner {
	private static ?array $managedRegistrationReport=null;

	/** Derives path-free definitions inside this one-request scheduler CGI. */
	public static function execute_managed_registration(int $budget_milliseconds=10000): ?array {
		if($budget_milliseconds<1000 || $budget_milliseconds>30000) return null;
		try{
			self::assertManagedSchedulerCgi();
			@set_time_limit(max(1,(int)ceil($budget_milliseconds/1000)));
			return self::managedRegistrationReport();
		}catch(\Throwable){return null;}
	}

	/** Runs one PID-1-authorized callback inside this fresh claimed CGI. */
	public static function execute_managed_callback(
		string $scheduler_name,
		string $definition_sha256,
		int $budget_milliseconds,
	): bool {
		if(preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$scheduler_name)!==1
			|| in_array($scheduler_name,['.','..'],true)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$definition_sha256)!==1
			|| $budget_milliseconds<1 || $budget_milliseconds>300000){
			return false;
		}
		try{
			self::assertManagedSchedulerCgi();
			@set_time_limit(max(1,(int)ceil($budget_milliseconds/1000)));
			$report=self::managedRegistrationReport();
			$definition=\dataphyre\scheduling::runtime_definition($scheduler_name);
			$evidence=null;
			foreach(($report['definitions'] ?? []) as $candidate){
				if(is_array($candidate) && ($candidate['name'] ?? null)===$scheduler_name){
					$evidence=$candidate;
					break;
				}
			}
			if(!is_array($definition) || !is_array($evidence)
				|| !hash_equals(
					$definition_sha256,
					'sha256:'.hash('sha256',json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))
				)){
				return false;
			}
			\dataphyre\scheduling::begin_task_runner($scheduler_name);
			try{
				self::executeTask($definition,static fn(string $path): bool=>is_file($path),[]);
				return true;
			}finally{
				\dataphyre\scheduling::end_task_runner();
			}
		}catch(\Throwable){
			return false;
		}
	}

	/** Exercises the claimed scheduler CGI without booting application code. */
	public static function execute_managed_noop(int $budget_milliseconds=2000): bool {
		if($budget_milliseconds<1 || $budget_milliseconds>5000) return false;
		try{self::assertManagedSchedulerCgi();return true;}
		catch(\Throwable){return false;}
	}

	/** Confirms this code can run only inside the bound UID10001 scheduler CGI. */
	private static function assertManagedSchedulerCgi(): void {
		if(!class_exists('DataphyreApplicationRuntimeChildEnvironment',false)){
			throw new \RuntimeException('Managed scheduler context is unavailable.');
		}
		$context=\DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation();
		if(!is_array($context)
			|| ($context['contract'] ?? null)!==\DataphyreApplicationRuntimeChildEnvironment::MANAGED_BOOTSTRAP_CONTRACT
			|| ($context['role'] ?? null)!=='scheduler'
			|| ($context['sapi'] ?? null)!=='cgi-fcgi'
			|| !function_exists('posix_geteuid') || posix_geteuid()!==10001
			|| !function_exists('posix_getegid') || posix_getegid()!==10001){
			throw new \RuntimeException('Managed scheduler CGI boundary is invalid.');
		}
	}

	/** Loads the ordinary application exactly once and returns sanitized definitions. */
	private static function managedRegistrationReport(): array {
		if(is_array(self::$managedRegistrationReport)) return self::$managedRegistrationReport;
		self::assertManagedSchedulerCgi();
		$context=\DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation();
		$projectRoot=realpath((string)($context['project_root'] ?? ''));
		$application=(string)(getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: '');
		$bootstrapPath=dirname(__DIR__,3).'/bootstrap.php';
		$bootstrap=is_link($bootstrapPath) ? false : realpath($bootstrapPath);
		if(!is_string($projectRoot) || !is_dir($projectRoot) || is_link($projectRoot)
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1
			|| !is_string($bootstrap) || !is_file($bootstrap)
			|| defined('RUN_MODE') || defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')
			|| defined('DATAPHYRE_INTERNAL_SCHEDULER_REGISTRATION')){
			throw new \RuntimeException('Managed scheduler application bootstrap is invalid.');
		}
		$_SERVER['DATAPHYRE_PROJECT_ROOT']=$projectRoot;
		$_SERVER['HTTP_X_DATAPHYRE_APPLICATION']=$application;
		$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
		$_SERVER['REQUEST_METHOD']='GET';
		$_SERVER['REQUEST_URI']='/health';
		$_GET=['uri'=>'health'];$_POST=[];$_REQUEST=$_GET;
		define('RUN_MODE','scheduler-task');
		define('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE','scheduler');
		define('DATAPHYRE_INTERNAL_SCHEDULER_REGISTRATION',true);
		(static function(string $bootstrap): void {
			ob_start();
			try{require $bootstrap;}
			finally{ob_end_clean();}
		})($bootstrap);
		if(!class_exists('dataphyre\\scheduling',false)){
			throw new \RuntimeException('Managed scheduler application did not load scheduling.');
		}
		$report=\dataphyre\scheduling::runtime_registration_report();
		if(!is_array($report) || ($report['ok'] ?? null)!==true){
			throw new \RuntimeException('Managed scheduler application registration failed.');
		}
		self::$managedRegistrationReport=$report;
		return $report;
	}

	/** Executes a definition already re-derived by the fresh scheduler CGI. */
	public static function execute_definition(array $scheduler): void {
		self::executeTask($scheduler,static fn(string $path): bool=>is_file($path),[]);
	}

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
		$loaded=array_key_exists('scheduling_loaded',$runtime)
			? (bool)$runtime['scheduling_loaded']
			: class_exists('dataphyre\\scheduling',false);
		if($loaded) return;
		$loader=$runtime['framework_loader'] ?? static fn(string $module): bool=>method_exists('dataphyre\\core','load_framework_module')
			? \dataphyre\core::load_framework_module($module)
			: false;
		$loader('scheduling');
	}

	/**
	 * Validates and executes one scheduler request.
	 *
	 * Managed Cloud callbacks never execute application code inside a long-lived
	 * PHP process. The fresh scheduler CGI owns that one claimed callback only.
	 * The legacy/default runtime keeps its in-process shutdown lifecycle.
	 */
	public static function dispatch(?callable $terminator=null,?callable $shutdown_registrar=null,array $runtime=[]): bool {
		$ignore_user_abort=$runtime['ignore_user_abort'] ?? static fn(bool $ignore): int=>ignore_user_abort($ignore);
		$ignore_user_abort(true);
		$scheduler_name=(string)($runtime['scheduler_name'] ?? (\dataphyre\routing::$bindings['scheduler'] ?? ''));
		$scheduling_available=array_key_exists('scheduling_available',$runtime)
			? (bool)$runtime['scheduling_available']
			: class_exists('dataphyre\\scheduling',false);
		if($scheduling_available!==true || !\dataphyre\scheduling::valid_scheduler_name($scheduler_name)){
			http_response_code(400);
			echo 'Invalid scheduler';
			self::terminate($terminator);
			return false;
		}

		$scheduler_path=\dataphyre\scheduling::scheduler_directory($scheduler_name);
		$running_lock_file=\dataphyre\scheduling::running_lock_file($scheduler_name);
		$is_file=$runtime['is_file'] ?? static fn(string $path): bool=>is_file($path);
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
			return false;
		}
		$dispatch_claim=trim((string)($runtime['scheduler_claim'] ?? ''));
		if(self::managedRuntime()){
			http_response_code(404);
			return false;
		}

		$claim_handle=self::claimRunningLock($running_lock_file,$dispatch_claim,$runtime);
		if(!is_resource($claim_handle)){
			http_response_code(409);
			self::dialback_failure($scheduler);
			self::pre_init_failure('Scheduler dispatch is not pending or was already claimed ('.$scheduler_name.')',null,$runtime);
			echo 'Scheduler not pending';
			self::terminate($terminator);
			return false;
		}

		\dataphyre\scheduling::begin_task_runner($scheduler_name);
		$task_succeeded=false;
		$finalized=false;
		$callback=static function() use (
			$scheduler_path,$scheduler_name,$runtime,$claim_handle,$dispatch_claim,&$task_succeeded,&$finalized,
		): bool {
			if($finalized) return false;
			$finalized=true;
			return self::finalize(
				$scheduler_path,$scheduler_name,$runtime,$claim_handle,$dispatch_claim,$task_succeeded,
			);
		};
		if($shutdown_registrar===null){
			register_shutdown_function($callback);
		}else{
			$shutdown_registrar($callback);
		}
		try{
			self::executeTask($scheduler,$is_file,$runtime);
			$task_succeeded=true;
			return true;
		}catch(\Throwable $failure){
			http_response_code(500);
			self::dialback_failure($scheduler);
			self::pre_init_failure('Fatal error: scheduler task failed ('.$scheduler_name.')',$failure,$runtime);
			echo 'Execution error';
			return false;
		}
	}

	/** Executes one validated task in the current, short-lived worker process. */
	private static function executeTask(array $scheduler,callable $is_file,array $runtime): void {
		$timeout=max(1,min(300,(int)ceil((float)($scheduler['timeout'] ?? 1))));
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
	}

	/** Returns whether this request belongs to the fixed framework scheduler pool. */
	private static function managedRuntime(): bool {
		return \dataphyre\scheduling::activation_mode()==='supervisor'
			&& defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')
			&& constant('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')==='scheduler';
	}

	/** Performs the claim-bound success-pair and lock cleanup. */
	public static function finalize(
		string $scheduler_path,
		string $scheduler_name,
		array $runtime=[],
		mixed $claim_handle=null,
		string $dispatch_claim='',
		bool $task_succeeded=false,
	): bool {
		$completed=false;
		try{
			$running_lock_file=\dataphyre\scheduling::running_lock_file($scheduler_name);
			$last_run_file=\dataphyre\scheduling::last_run_file($scheduler_name);
			$last_success_file=\dataphyre\scheduling::last_success_file($scheduler_name);
			$writer=$runtime['writer'] ?? static fn(string $path,mixed $value,int $flags=0): int|false=>file_put_contents($path,$value,$flags);
			$unlink=$runtime['unlink'] ?? static fn(string $path): bool=>@unlink($path);
			if(self::module_present('tracelog',$runtime)){
				$writer($scheduler_path.'/tracelog.html',\dataphyre\tracelog::$tracelog,LOCK_EX);
				echo '<br><br>';
				if(defined('IS_PRODUCTION') && IS_PRODUCTION===false){echo \dataphyre\tracelog::$tracelog;}
			}
			$pair_ok=false;
			if($task_succeeded && preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)===1
				&& self::clearStatePath($last_run_file,$runtime)
				&& self::clearStatePath($last_success_file,$runtime)){
				$timestamp=(string)($runtime['timestamp'] ?? time());
				$run_written=$writer($last_run_file,$timestamp,LOCK_EX);
				$success_written=$run_written===strlen($timestamp)
					? $writer($last_success_file,$dispatch_claim,LOCK_EX)
					: false;
				$pair_ok=$success_written===strlen($dispatch_claim)
					&& self::exactStateFile($last_run_file,$timestamp,$runtime)
					&& self::exactStateFile($last_success_file,$dispatch_claim,$runtime);
			}
			if(!$pair_ok){
				self::clearStatePath($last_run_file,$runtime);
				self::clearStatePath($last_success_file,$runtime);
			}
			$lock_removed=false;
			if(self::claimedPathMatches($running_lock_file,$claim_handle,$dispatch_claim,$runtime)){
				$lock_removed=$unlink($running_lock_file)===true;
			}
			if(is_resource($claim_handle)){
				@flock($claim_handle,LOCK_UN);
				@fclose($claim_handle);
			}
			$completed=$pair_ok && $lock_removed;
			if(!$completed){
				self::clearStatePath($last_run_file,$runtime);
				self::clearStatePath($last_success_file,$runtime);
			}
			clearstatcache(true,$running_lock_file);
			if((file_exists($running_lock_file) || is_link($running_lock_file)) && method_exists('dataphyre\\core','unavailable')){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$S='Failed unsetting scheduler task lock',$T='safemode');
			}
		}catch(\Throwable $failure){
			try{
				self::clearStatePath(\dataphyre\scheduling::last_run_file($scheduler_name),$runtime);
				self::clearStatePath(\dataphyre\scheduling::last_success_file($scheduler_name),$runtime);
				$lock=\dataphyre\scheduling::running_lock_file($scheduler_name);
				$unlink=$runtime['unlink'] ?? static fn(string $path): bool=>@unlink($path);
				if(self::claimedPathMatches($lock,$claim_handle,$dispatch_claim,$runtime)) $unlink($lock);
				if(is_resource($claim_handle)){
					@flock($claim_handle,LOCK_UN);
					@fclose($claim_handle);
				}
			}catch(\Throwable){}
			$logger=$runtime['shutdown_logger'] ?? static fn(string $message,\Throwable $exception): mixed=>\dataphyre_shutdown_log($message,$exception);
			$logger('Fatal error on Dataphyre Scheduling (task runner) shutdown callback',$failure);
		}finally{
			\dataphyre\scheduling::end_task_runner();
		}
		return $completed;
	}

	/** Confirms the pathname still names the exact claim held by this runner. */
	private static function claimedPathMatches(string $path,mixed $claim_handle,string $dispatch_claim,array $runtime=[]): bool {
		if(!is_resource($claim_handle) || preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)!==1 || is_link($path)) return false;
		@rewind($claim_handle);
		$stored=trim((string)stream_get_contents($claim_handle));
		$handle_stat=@fstat($claim_handle);
		$path_stat=isset($runtime['lstat']) && is_callable($runtime['lstat']) ? $runtime['lstat']($path) : @lstat($path);
		return preg_match('/^[a-f0-9]{64}$/D',$stored)===1
			&& hash_equals($stored,$dispatch_claim)
			&& is_array($handle_stat) && is_array($path_stat)
			&& (($path_stat['mode'] ?? 0)&0170000)===0100000
			&& ($path_stat['nlink'] ?? 0)===1
			&& ($handle_stat['dev'] ?? null)===($path_stat['dev'] ?? null)
			&& ($handle_stat['ino'] ?? null)===($path_stat['ino'] ?? null);
	}

	/** Claims the one pending dispatch and holds it through cleanup. */
	private static function claimRunningLock(string $path,string $dispatch_claim,array $runtime=[]): mixed {
		if(preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)!==1 || is_link($path)) return false;
		$opener=$runtime['lock_opener'] ?? static fn(string $lock_path): mixed=>@fopen($lock_path,'r+');
		$handle=is_callable($opener) ? $opener($path) : false;
		if(!is_resource($handle)) return false;
		$locker=$runtime['lock_acquirer'] ?? static fn(mixed $lock_handle): bool=>@flock($lock_handle,LOCK_EX|LOCK_NB);
		if(!is_callable($locker) || $locker($handle)!==true){@fclose($handle);return false;}
		@rewind($handle);
		$stored=trim((string)stream_get_contents($handle));
		$handle_stat=@fstat($handle);
		$path_stat=@lstat($path);
		if(preg_match('/^[a-f0-9]{64}$/D',$stored)!==1 || !hash_equals($stored,$dispatch_claim)
			|| !is_array($handle_stat) || !is_array($path_stat)
			|| (($path_stat['mode'] ?? 0)&0170000)!==0100000 || ($path_stat['nlink'] ?? 0)!==1
			|| ($handle_stat['dev'] ?? null)!==($path_stat['dev'] ?? null)
			|| ($handle_stat['ino'] ?? null)!==($path_stat['ino'] ?? null)){
			@flock($handle,LOCK_UN);@fclose($handle);return false;
		}
		return $handle;
	}

	/** Removes one scheduler state file only when it is a regular single-link path. */
	private static function clearStatePath(string $path,array $runtime=[]): bool {
		$file_exists=$runtime['file_exists'] ?? static fn(string $candidate): bool=>file_exists($candidate);
		$is_link=$runtime['is_link'] ?? static fn(string $candidate): bool=>is_link($candidate);
		$lstat=$runtime['lstat'] ?? static fn(string $candidate): array|false=>@lstat($candidate);
		$unlink=$runtime['unlink'] ?? static fn(string $candidate): bool=>@unlink($candidate);
		clearstatcache(true,$path);
		if(!$file_exists($path) && !$is_link($path)) return true;
		$stat=$is_link($path) ? false : $lstat($path);
		return is_array($stat)
			&& (($stat['mode'] ?? 0)&0170000)===0100000
			&& ($stat['nlink'] ?? 0)===1
			&& $unlink($path)===true;
	}

	/** Verifies exact bytes in one regular single-link state path. */
	private static function exactStateFile(string $path,string $expected,array $runtime=[]): bool {
		$is_link=$runtime['is_link'] ?? static fn(string $candidate): bool=>is_link($candidate);
		$lstat=$runtime['lstat'] ?? static fn(string $candidate): array|false=>@lstat($candidate);
		$reader=$runtime['reader'] ?? static fn(string $candidate): string|false=>@file_get_contents($candidate);
		if($is_link($path)) return false;
		$stat=$lstat($path);
		$contents=$reader($path);
		return is_array($stat)
			&& (($stat['mode'] ?? 0)&0170000)===0100000
			&& ($stat['nlink'] ?? 0)===1
			&& is_string($contents)
			&& hash_equals($expected,$contents);
	}

	private static function module_present(string $module,array $runtime): bool {
		if(isset($runtime['module_present']) && is_callable($runtime['module_present'])) return (bool)$runtime['module_present']($module);
		return function_exists('dp_module_present') && (bool)\dp_module_present($module);
	}

	private static function dialback_failure(?array $scheduler): void {
		if(method_exists('dataphyre\\core','dialback')) \dataphyre\core::dialback('CALL_SCHEDULING_TASK_FAILED',['scheduler'=>$scheduler]);
	}

	private static function pre_init_failure(string $message,?\Throwable $failure,array $runtime): void {
		$handler=$runtime['pre_init_error'] ?? (function_exists('pre_init_error') ? 'pre_init_error' : null);
		if(is_callable($handler)) $failure===null ? $handler($message) : $handler($message,$failure);
	}

	private static function terminate(?callable $terminator): void {
		if($terminator===null){exit;}
		$terminator();
	}
}

dataphyre_scheduling_task_runner::dispatch_entrypoint(
	defined('DATAPHYRE_SCHEDULING_TASK_RUNNER_NO_DISPATCH')===true,
);
