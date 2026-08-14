<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

require_once dirname(__DIR__,2).'/core/kernel/application_runtime_scheduler_protocol.php';

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Registers and dispatches Dataphyre scheduler tasks through persisted runtime state.
 *
 * The scheduling kernel stores one JSON definition per scheduler under Dataphyre's cache/scheduling directory, tracks the
 * last successful run timestamp, and uses a running_lock file to prevent overlapping execution. Scheduler registration is
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
	/** @var ?string Optional embedded-runtime state root override. */
	private static ?string $state_root=null;
	/** @var ?string Optional deterministic activation-policy override. */
	private static ?string $activation_mode=null;
	/** @var ?array<string,mixed> Framework-owned evidence for one signed runtime tick. */
	private static ?array $runtime_tick_state=null;
	/** Fixed transport allowance for the legacy self-hosted callback route. */
	private const RUNTIME_CALLBACK_MARGIN_MILLISECONDS=1000;

	/** Selects an alternate scheduler state root for embedded and isolated runtimes. */
	public static function use_state_root(?string $root): void {
		if(self::managed_pool()) return;
		self::$state_root=$root===null ? null : rtrim($root, '/\\').'/';
	}

	/**
	 * Selects how registrations are allowed to create dispatch claims.
	 *
	 * `default` preserves the historical request-driven scheduler. `record_only`
	 * persists validated definitions without locks, timestamps, or callbacks.
	 * `supervisor` permits dispatch only inside the framework-owned scheduler
	 * loopback pool; ordinary web, health, and preflight requests still record
	 * definitions but cannot run application tasks.
	 */
	public static function use_activation_mode(?string $mode): void {
		if(self::managed_pool()) return;
		self::$activation_mode=$mode===null ? null : strtolower(trim($mode));
	}

	/** Returns the normalized scheduler activation mode for this process. */
	public static function activation_mode(): string {
		if(defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')) return 'supervisor';
		if(self::managed_pool()) return 'record_only';
		$mode=self::$activation_mode;
		if($mode===null){
			$value=getenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE');
			$mode=is_string($value) ? strtolower(trim($value)) : '';
			$runtime_role=strtolower(trim((string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')));
			if($mode==='' && in_array($runtime_role, ['web','scheduler','realtime'], true)){
				$mode='record_only';
			}
		}
		return match($mode){
			'', 'default'=>'default',
			'record_only'=>'record_only',
			'supervisor'=>'supervisor',
			default=>'disabled',
		};
	}

	/** Reports whether this process may turn due definitions into callbacks. */
	public static function dispatch_enabled(): bool {
		return match(self::activation_mode()){
			'default'=>true,
			default=>false,
		};
	}

	/**
	 * Returns bounded, path-free evidence for the current signed scheduler tick.
	 *
	 * The supervisor accepts a cadence only when every registration produced one
	 * unique immutable definition, every due definition obtained a one-time claim,
	 * every callback completed successfully, and every claim lock was removed.
	 * Ordinary web, preflight, and legacy request-driven scheduling never create
	 * this evidence.
	 *
	 * @return array<string,mixed>
	 */
	public static function runtime_registration_report(): array {
		self::initialize_runtime_tick_state();
		$state=self::$runtime_tick_state;
		if(!is_array($state)){
			return self::empty_runtime_tick_report(false);
		}
		$definitions=array_values($state['definitions']);
		usort($definitions,static fn(array $left,array $right): int=>$left['name']<=>$right['name']);
		$encoded=json_encode(
			$definitions,
			JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR,
		);
		$definition_count=count($definitions);
		$ok=$state['registration_failure_count']===0
			&& $state['registration_attempt_count']===$state['registration_accepted_count']
			&& $state['registration_accepted_count']===$definition_count;
		$report=[
			'contract'=>'dataphyre.scheduler_registration.v1',
			'ok'=>$ok,
			'registration_attempt_count'=>$state['registration_attempt_count'],
			'registration_accepted_count'=>$state['registration_accepted_count'],
			'registration_failure_count'=>$state['registration_failure_count'],
			'definition_count'=>$definition_count,
			'definition_sha256'=>'sha256:'.hash('sha256',$encoded),
			'definitions'=>$definitions,
		];
		$transport=json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return strlen($transport)<=\DataphyreApplicationRuntimeSchedulerProtocol::MAX_TRANSPORT_BYTES
			? $report
			: self::empty_runtime_tick_report(false);
	}

	/** Backward-compatible method name; managed semantics are registration-only. */
	public static function runtime_tick_report(): array {
		return self::runtime_registration_report();
	}

	/** Returns one full definition re-derived during this immutable request bootstrap. */
	public static function runtime_definition(string $name): ?array {
		self::initialize_runtime_tick_state();
		if(!is_array(self::$runtime_tick_state) || !self::valid_scheduler_name($name)) return null;
		$value=self::$runtime_tick_state['full_definitions'][$name] ?? null;
		return is_array($value) ? $value : null;
	}

	/**
	 * Resolves the host-owned secret file used for scheduler callback signatures.
	 *
	 * Ordinary applications keep the existing project-relative
	 * `app_override_key`. The managed scheduler accepts only the fixed
	 * supervisor-owned file; request and tenant environment data cannot select it.
	 */
	public static function dispatch_secret_file(): string {
		$value=getenv('DATAPHYRE_SCHEDULER_DISPATCH_SECRET_FILE');
		if(!is_string($value) || trim($value)===''){
			return 'app_override_key';
		}
		$value=trim($value);
		$absolute=$value[0]==='/' || $value[0]==='\\' || preg_match('/^[A-Za-z]:[\\\/]/D', $value)===1;
		return $absolute && !is_link($value) && is_file($value) && is_readable($value)
			? $value
			: 'app_override_key';
	}

    /**
     * Registers a scheduler definition and dispatches it after shutdown when it is due.
     *
	 * The method validates the scheduler name, task file, and dependency files before writing properties.json. If the task
	 * can run now, a running_lock file is created and a shutdown callback performs an internal HTTP request to the scheduler
	 * route. The task runner updates last_run only after successful execution; the exclusive running lock prevents concurrent
	 * requests from enqueueing the same task while its callback is pending.
     *
     * @param string $name Scheduler name used for cache paths and route dispatch.
     * @param string $file_path PHP task file that will be executed by the scheduler route.
     * @param float $frequency Minimum seconds between attempted dispatches.
     * @param float $timeout Seconds after which an existing running lock is considered stale.
     * @param string $memory_limit Memory limit stored with the scheduler definition.
     * @param array<int, string> $dependencies Files that must exist before the scheduler is accepted.
     * @param ?string $app_override Application override to preserve in the internal dispatch request.
     * @param ?callable $shutdown_registrar Optional shutdown registrar used by embedded runtimes and tests.
     * @return bool Whether the scheduler definition was accepted and persisted.
     */
	public static function run(string $name, string $file_path, float $frequency, float $timeout, string $memory_limit, array $dependencies, ?string $app_override=null, ?callable $shutdown_registrar=null) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!isset($app_override))$app_override=APP;
		if(self::activation_mode()==='supervisor') $app_override='';
		$name=self::normalize_scheduler_name($name);
		if($name===''){
			self::record_preflight_registration(false);
			self::record_runtime_tick_registration(null,false);
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
			self::record_preflight_registration(false);
			self::record_runtime_tick_registration(null,false);
			return false;
		}
		if(self::activation_mode()==='supervisor'){
			self::record_preflight_registration(true);
			return self::record_runtime_tick_registration($scheduler,true);
		}
		if(self::persist_scheduler_definition($scheduler)!==true){
			self::record_preflight_registration(false);
			self::record_runtime_tick_registration(null,false);
			return false;
		}
		self::record_preflight_registration(true);
		if(self::record_runtime_tick_registration($scheduler,true)!==true){
			return false;
		}
		if(self::dispatch_enabled()!==true){
			return true;
		}
		$run_decision=self::can_run($scheduler);
		if($run_decision===true){
			self::record_runtime_tick_counter('due_count');
			try{
				$dispatch_claim=bin2hex(random_bytes(32));
			}catch(\Throwable $failure){
				self::record_runtime_tick_counter('dispatch_failure_count');
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed creating scheduler dispatch claim', $T='warning');
				return false;
			}
			if(self::acquire_running_lock($name, $dispatch_claim)!==true){
				self::record_runtime_tick_counter('dispatch_failure_count');
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed atomically locking scheduler', $T='warning');
				return false;
			}
			if(self::clear_success_state($name)!==true){
				self::release_dispatch_claim($name,$dispatch_claim);
				self::record_runtime_tick_counter('dispatch_failure_count');
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed clearing the previous scheduler success state', $T='warning');
				return false;
			}
			self::record_runtime_tick_counter('dispatch_claim_count');
			if(self::activation_mode()==='supervisor'){
				$dispatched=self::dispatch_registered_scheduler($name,$app_override,$dispatch_claim);
				self::record_runtime_tick_counter($dispatched ? 'dispatch_success_count' : 'dispatch_failure_count');
				if($dispatched){
					self::record_runtime_tick_counter('lock_cleanup_count');
				}
				return $dispatched;
			}
			$shutdown_registrar ??= static function(mixed $callback, mixed ...$arguments): void {
				register_shutdown_function($callback, ...$arguments);
			};
			$shutdown_registrar([self::class, 'dispatch_registered_scheduler'], $name, $app_override, $dispatch_claim);
		}elseif($run_decision===false){
			self::record_runtime_tick_counter('suppressed_count');
		}else{
			self::record_runtime_tick_counter('dispatch_failure_count');
		}
		return true;
	}

	/**
	 * Creates one pending-dispatch lock without overwriting a concurrent claim.
	 *
	 * The exclusive-create filesystem primitive is the scheduler's process boundary:
	 * only the request that creates the lock receives the claim and may register the
	 * shutdown dispatch. The task runner later takes an advisory lock on the same
	 * file, which prevents a valid signed callback from being replayed concurrently.
	 */
	private static function acquire_running_lock(string $name, string $dispatch_claim): bool {
		if(preg_match('/^[a-f0-9]{64}$/D', $dispatch_claim)!==1){
			return false;
		}
		$path=self::running_lock_file($name);
		$handle=@fopen($path, 'x');
		if(!is_resource($handle)){
			return false;
		}
		$written=@fwrite($handle, $dispatch_claim);
		$flushed=$written===strlen($dispatch_claim) && @fflush($handle);
		@fclose($handle);
		if($flushed!==true){
			@unlink($path);
			return false;
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
		$root=self::$state_root;
		if($root===null){
			$environment_root=getenv('DATAPHYRE_SCHEDULER_STATE_ROOT');
			$root=is_string($environment_root) && trim($environment_root)!==''
				? trim($environment_root)
				: (string)ROOTPATH['dataphyre'];
		}
		return rtrim($root, '/\\').'/'.self::CACHE_PATH.($name!=='' ? $name.'/' : '');
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

	/** Returns the exact one-time claim completed by the latest successful task. */
	public static function last_success_file(string $name): string {
		return self::scheduler_directory($name).'last_success';
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
	 * A scheduler is deferred when server load is high or a non-stale running lock exists. Only a recent successful run with
	 * a regular claim receipt is cadence-suppressed. Stale locks are aged from their own filesystem timestamp and removed
	 * after timeout so failed work cannot make a later runtime tick look like an ordinary cadence suppression.
	 *
	 * @param array{name:string, frequency:float, timeout:float} $scheduler Normalized scheduler definition.
	 * @return ?bool True when due, false only for receipt-backed cadence suppression, or null when dispatch is deferred.
	 */
	private static function can_run(array $scheduler) : ?bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution frequency is '.$scheduler['frequency']);
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Execution timeout is '.$scheduler['timeout']);
		\dataphyre\core::get_server_load_level();
		if(\dataphyre\core::$server_load_level>2){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Server load too high for scheduler', "warning");
			return null;
		}
		$last_run_file=self::last_run_file((string)$scheduler['name']);
		$running_lock_file=self::running_lock_file((string)$scheduler['name']);
		clearstatcache(true, $last_run_file);
		clearstatcache(true, $running_lock_file);
		if(file_exists($running_lock_file) || is_link($running_lock_file)){
			if(self::reclaim_stale_running_lock($running_lock_file,(float)$scheduler['timeout'])!==true){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler has a live, locked, or invalid running claim', $T='warning');
				return null;
			}
		}
		$last_run=self::read_last_run_timestamp($last_run_file);
		if($last_run===null || self::has_success_state((string)$scheduler['name'],$last_run)!==true){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler has no complete successful state pair and must retry', $T='warning');
			return true;
		}
		$time_since_last_run=max(0,time()-$last_run);
		if($time_since_last_run>=$scheduler['frequency']){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler is due for execution (it has been '.$time_since_last_run.'s since last execution)');
			return true;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Scheduler is not due for execution (it has been '.$time_since_last_run.'s since last execution)');
		return false;
	}

	/** Reclaims an expired claim only after acquiring and proving its exact inode. */
	private static function reclaim_stale_running_lock(string $path,float $timeout): bool {
		if(is_link($path)) return false;
		$handle=@fopen($path,'r+');
		if(!is_resource($handle)) return false;
		if(@flock($handle,LOCK_EX|LOCK_NB)!==true){@fclose($handle);return false;}
		@rewind($handle);
		$stored=trim((string)stream_get_contents($handle));
		$handle_stat=@fstat($handle);
		$path_stat=@lstat($path);
		$same=is_array($handle_stat) && is_array($path_stat)
			&& (($path_stat['mode'] ?? 0)&0170000)===0100000
			&& ($path_stat['nlink'] ?? 0)===1
			&& ($handle_stat['dev'] ?? null)===($path_stat['dev'] ?? null)
			&& ($handle_stat['ino'] ?? null)===($path_stat['ino'] ?? null);
		$mtime=is_array($handle_stat) ? ($handle_stat['mtime'] ?? null) : null;
		$age=is_int($mtime) ? max(0,time()-$mtime) : 0;
		$removed=$same
			&& preg_match('/^[a-f0-9]{64}$/D',$stored)===1
			&& $age>=max(1.0,$timeout)
			&& @unlink($path);
		@flock($handle,LOCK_UN);
		@fclose($handle);
		return $removed;
	}

	/** Sends one signed, budget-bound callback to the fixed scheduler pool. */
	private static function dispatch_registered_scheduler(string $name,string $app_override,string $dispatch_claim,?bool $curl_available=null,?callable $signer=null): bool {
		try{
			$url=self::scheduler_dispatch_url($name,$app_override);
			if($url===null || preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)!==1){
				throw new \RuntimeException('Unable to resolve a valid scheduler callback');
			}
			$scheduler=self::read_scheduler($name);
			if(!is_array($scheduler)) throw new \RuntimeException('Unable to read scheduler callback timeout');
			$budget=self::runtime_callback_budget_milliseconds((float)($scheduler['timeout'] ?? 1.0));
			if($budget<1) throw new \RuntimeException('Scheduler tick has no remaining application work budget');
			$issued_at=time();
			$signature_context=$name.'|'.$dispatch_claim.'|'.$budget.'|'.$issued_at;
			$signer ??= function_exists('dp_shared_request_key')
				? static fn(string $context,int $timestamp): string|false=>dp_shared_request_key(
					self::dispatch_secret_file(),'scheduler_dispatch_v2',$context,$timestamp,1,
				)
				: null;
			$request_key=is_callable($signer) ? $signer($signature_context,$issued_at) : false;
			if(!is_string($request_key) || preg_match('/^[a-f0-9]{64}$/D',$request_key)!==1){
				throw new \RuntimeException('Unable to sign internal scheduler dispatch');
			}
			$callback_timeout=$budget+self::RUNTIME_CALLBACK_MARGIN_MILLISECONDS;
			$headers=[
				'X-Traffic-Source: internal_traffic',
				'X-Dataphyre-Scheduler-Claim: '.$dispatch_claim,
				'X-Dataphyre-Scheduler-Budget-Ms: '.$budget,
				'X-Dataphyre-Scheduler-Issued-At: '.$issued_at,
				'X-Dataphyre-Scheduler-Key: '.$request_key,
			];
			$curl_available ??= function_exists('curl_init');
			if($curl_available){
				$ch=curl_init();
				curl_setopt($ch,CURLOPT_URL,$url);
				curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
				curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
				curl_setopt($ch,CURLOPT_TIMEOUT_MS,$callback_timeout);
				curl_setopt($ch,CURLOPT_CONNECTTIMEOUT_MS,150);
				curl_setopt($ch,CURLOPT_NOSIGNAL,1);
				$result=curl_exec($ch);
				$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
				curl_close($ch);
			}else{
				$context=stream_context_create(['http'=>[
					'method'=>'GET','timeout'=>$callback_timeout/1000,
					'header'=>implode("\r\n",$headers)."\r\n",
				]]);
				$result=@file_get_contents($url,false,$context);
				$status=null;
				foreach(($http_response_header ?? []) as $response_header){
					if(preg_match('/^HTTP\/\S+\s+(\d{3})\b/i',(string)$response_header,$matches)===1){$status=(int)$matches[1];break;}
				}
			}
			if($result===false || !is_int($status) || $status<200 || $status>=300){
				throw new \RuntimeException('Scheduler callback failed with HTTP status '.($status ?? 'unavailable'));
			}
			if(self::dispatch_claim_completed($name,$dispatch_claim)!==true){
				throw new \RuntimeException('Scheduler callback did not publish its complete success state');
			}
			return true;
		}catch(\Throwable $exception){
			self::abandon_dispatch_claim($name,$dispatch_claim);
			\dataphyre_shutdown_log('Fatal error on Dataphyre Scheduling shutdown callback',$exception);
			return false;
		}
	}

	/** Verifies the task runner's timestamp/receipt pair and lock cleanup. */
	public static function dispatch_claim_completed(string $name,string $dispatch_claim): bool {
		if(self::valid_scheduler_name($name)!==true || preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)!==1) return false;
		$last_run=self::last_run_file($name);
		$lock=self::running_lock_file($name);
		$timestamp=self::read_last_run_timestamp($last_run);
		clearstatcache(true,$lock);
		if($timestamp===null || self::has_success_state($name,$timestamp)!==true || file_exists($lock) || is_link($lock)) return false;
		$contents=@file_get_contents(self::last_success_file($name));
		return is_string($contents) && hash_equals(trim($contents),$dispatch_claim);
	}

	/** Confirms cadence suppression is backed by two exact regular state files. */
	private static function has_success_state(string $name,int $timestamp): bool {
		if(self::valid_scheduler_name($name)!==true) return false;
		$last_run=self::last_run_file($name);
		$receipt=self::last_success_file($name);
		clearstatcache(true,$last_run);clearstatcache(true,$receipt);
		if(is_link($last_run) || !is_file($last_run) || is_link($receipt) || !is_file($receipt)) return false;
		$run_stat=@lstat($last_run);$receipt_stat=@lstat($receipt);
		$run_contents=@file_get_contents($last_run);$contents=@file_get_contents($receipt);
		return is_array($run_stat) && is_array($receipt_stat)
			&& (($run_stat['mode'] ?? 0)&0170000)===0100000
			&& (($receipt_stat['mode'] ?? 0)&0170000)===0100000
			&& ($run_stat['nlink'] ?? 0)===1 && ($receipt_stat['nlink'] ?? 0)===1
			&& is_string($run_contents) && hash_equals((string)$timestamp,trim($run_contents))
			&& is_string($contents) && preg_match('/^[a-f0-9]{64}$/D',trim($contents))===1;
	}

	/** Removes both superseded success files before a new claim can dispatch. */
	private static function clear_success_state(string $name): bool {
		if(self::valid_scheduler_name($name)!==true) return false;
		foreach([self::last_run_file($name),self::last_success_file($name)] as $path){
			clearstatcache(true,$path);
			if(!file_exists($path) && !is_link($path)) continue;
			$stat=is_link($path) ? false : @lstat($path);
			if(!is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0100000
				|| ($stat['nlink'] ?? 0)!==1 || !@unlink($path)) return false;
		}
		return true;
	}

	/** Clears incomplete success state and releases only this exact pending claim. */
	public static function abandon_dispatch_claim(string $name,string $dispatch_claim): bool {
		$state_cleared=self::clear_success_state($name);
		$lock=self::running_lock_file($name);
		$deadline=hrtime(true)+500_000_000;
		$released=false;
		do{
			clearstatcache(true,$lock);
			$released=(!file_exists($lock) && !is_link($lock))
				|| self::release_dispatch_claim($name,$dispatch_claim);
			if($released) break;
			usleep(5000);
		}while(hrtime(true)<$deadline);
		return $state_cleared && $released;
	}

	/** Removes only the still-pending lock created for this exact failed dispatch. */
	private static function release_dispatch_claim(string $name,string $dispatch_claim): bool {
		if(self::valid_scheduler_name($name)!==true || preg_match('/^[a-f0-9]{64}$/D',$dispatch_claim)!==1) return false;
		$path=self::running_lock_file($name);
		if(is_link($path)) return false;
		$handle=@fopen($path,'r+');
		if(!is_resource($handle)) return false;
		if(@flock($handle,LOCK_EX|LOCK_NB)!==true){@fclose($handle);return false;}
		@rewind($handle);
		$stored=trim((string)stream_get_contents($handle));
		$handle_stat=@fstat($handle);$path_stat=@lstat($path);
		$same=is_array($handle_stat) && is_array($path_stat)
			&& (($path_stat['mode'] ?? 0)&0170000)===0100000 && ($path_stat['nlink'] ?? 0)===1
			&& ($handle_stat['dev'] ?? null)===($path_stat['dev'] ?? null)
			&& ($handle_stat['ino'] ?? null)===($path_stat['ino'] ?? null);
		$removed=$same && preg_match('/^[a-f0-9]{64}$/D',$stored)===1
			&& hash_equals($stored,$dispatch_claim) && @unlink($path);
		@flock($handle,LOCK_UN);@fclose($handle);
		return $removed;
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
		$owned_address=getenv('DATAPHYRE_SCHEDULER_SELF_ADDRESS');
		$self_addr=is_string($owned_address) && trim($owned_address)!==''
			? trim($owned_address)
			: trim((string)($_SERVER['SELF_ADDR'] ?? ''));
		if($self_addr===''){
			return null;
		}
		if(preg_match('/^(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\]):[1-9][0-9]{0,4}$/D', $self_addr)!==1){
			return null;
		}
		$owned_scheme=getenv('DATAPHYRE_SCHEDULER_SELF_SCHEME');
		$scheme=is_string($owned_scheme) && in_array(strtolower(trim($owned_scheme)), ['http','https'], true)
			? strtolower(trim($owned_scheme))
			: (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['REQUEST_SCHEME'] ?? '')==='https')) ? 'https' : 'http');
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
		if(
			$name==='' || strlen($name)>128 || in_array($name, ['.', '..'], true)
			|| preg_match('/^[A-Za-z0-9._-]+$/D', $name)!==1
		){
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
			$dependency=(string)$dependency;
			if(trim($dependency)===''){
				continue;
			}
			$dependency_path=realpath($dependency) ?: $dependency;
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
	private static function persist_scheduler_definition(array $scheduler): bool {
		$properties_file=self::scheduler_properties_file((string)$scheduler['name']);
		$payload=json_encode($scheduler, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		if(!is_string($payload)){
			return false;
		}
		$existing=@file_get_contents($properties_file);
		if($existing===$payload){
			return true;
		}
		return core::file_put_contents_forced($properties_file, $payload)!==false;
	}

	/** Records ignored registration failures only inside the fixed isolated preflight child. */
	private static function record_preflight_registration(bool $accepted): void {
		if(!function_exists('dp_application_release_preflight_context')
			|| dp_application_release_preflight_context()===null){
			return;
		}
		$context=&$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT'];
		$context['scheduler_attempt_count']=is_int($context['scheduler_attempt_count'] ?? null)
			? $context['scheduler_attempt_count']+1
			: 1;
		if($accepted!==true){
			$context['scheduler_failure_count']=is_int($context['scheduler_failure_count'] ?? null)
				? $context['scheduler_failure_count']+1
				: 1;
		}
	}

	/** Initializes the private per-request runtime tick collector when appropriate. */
	private static function initialize_runtime_tick_state(): void {
		if(self::$runtime_tick_state!==null
			|| !defined('DATAPHYRE_INTERNAL_SCHEDULER_REGISTRATION')
			|| constant('DATAPHYRE_INTERNAL_SCHEDULER_REGISTRATION')!==true
			|| self::activation_mode()!=='supervisor'
			|| !defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')
			|| constant('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')!=='scheduler'){
			return;
		}
		self::$runtime_tick_state=[
			'registration_attempt_count'=>0,
			'registration_accepted_count'=>0,
			'registration_failure_count'=>0,
			'definitions'=>[],
			'full_definitions'=>[],
		];
	}

	/** @return array<string,mixed> */
	private static function empty_runtime_tick_report(bool $ok): array {
		$encoded=json_encode([],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return [
			'contract'=>'dataphyre.scheduler_registration.v1',
			'ok'=>$ok,
			'registration_attempt_count'=>0,
			'registration_accepted_count'=>0,
			'registration_failure_count'=>0,
			'definition_count'=>0,
			'definition_sha256'=>'sha256:'.hash('sha256',$encoded),
			'definitions'=>[],
		];
	}

	/** Legacy request-driven dispatch accounting; managed registration never enters this path. */
	private static function record_runtime_tick_counter(string $counter): void {
		if(!is_array(self::$runtime_tick_state) || !array_key_exists($counter,self::$runtime_tick_state)) return;
		self::$runtime_tick_state[$counter]++;
	}

	/** Legacy request-driven callback bound retained outside managed pool operation. */
	private static function runtime_callback_budget_milliseconds(float $task_timeout): int {
		return max(1,min(295000,(int)ceil($task_timeout*1000)));
	}

	/** Records one accepted or rejected definition in the private tick collector. */
	private static function record_runtime_tick_registration(?array $scheduler,bool $accepted): bool {
		self::initialize_runtime_tick_state();
		if(!is_array(self::$runtime_tick_state)){
			return true;
		}
		self::$runtime_tick_state['registration_attempt_count']++;
		if($accepted!==true || !is_array($scheduler)){
			self::$runtime_tick_state['registration_failure_count']++;
			return false;
		}
		$definition=self::runtime_tick_definition($scheduler);
		if($definition===null){
			self::$runtime_tick_state['registration_failure_count']++;
			return false;
		}
		self::$runtime_tick_state['registration_accepted_count']++;
		self::$runtime_tick_state['definitions'][$definition['name']]=$definition;
		self::$runtime_tick_state['full_definitions'][$definition['name']]=$scheduler;
		return true;
	}

	/** @return ?array{name:string,task_sha256:string,dependency_sha256:list<string>,frequency_milliseconds:int,timeout_milliseconds:int,memory_limit:string} */
	private static function runtime_tick_definition(array $scheduler): ?array {
		$name=(string)($scheduler['name'] ?? '');
		$task=(string)($scheduler['file_path'] ?? '');
		if(!self::valid_scheduler_name($name) || $task==='' || is_link($task) || !is_file($task)){
			return null;
		}
		$task_hash=hash_file('sha256',$task);
		if(!is_string($task_hash) || preg_match('/^[a-f0-9]{64}$/D',$task_hash)!==1){
			return null;
		}
		$dependency_hashes=[];
		foreach(($scheduler['dependencies'] ?? []) as $dependency){
			if(!is_string($dependency) || $dependency==='' || is_link($dependency) || !is_file($dependency)){
				return null;
			}
			$hash=hash_file('sha256',$dependency);
			if(!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){
				return null;
			}
			$dependency_hashes[]='sha256:'.$hash;
		}
		return [
			'name'=>$name,
			'task_sha256'=>'sha256:'.$task_hash,
			'dependency_sha256'=>$dependency_hashes,
			'frequency_milliseconds'=>max(0,min(2147483647,(int)ceil(((float)($scheduler['frequency'] ?? 0.0))*1000))),
			'timeout_milliseconds'=>max(1000,min(300000,(int)ceil(((float)($scheduler['timeout'] ?? 1.0))*1000))),
			'memory_limit'=>(string)($scheduler['memory_limit'] ?? ''),
		];
	}

	private static function managed_pool(): bool {
		if(defined('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')){
			return constant('DATAPHYRE_INTERNAL_MANAGED_SCHEDULER_ROLE')==='scheduler';
		}
		return in_array(strtolower(trim((string)(getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: ''))),['web','realtime'],true);
	}

	/**
	 * Reads a positive Unix timestamp from the last_run file.
	 *
	 * @param string $last_run_file Absolute last_run path.
	 * @return ?int Positive timestamp, or null when missing or invalid.
	 */
	private static function read_last_run_timestamp(string $last_run_file, ?callable $reader=null): ?int {
		if(is_link($last_run_file) || !is_file($last_run_file)){
			return null;
		}
		$stat=@lstat($last_run_file);
		if(!is_array($stat) || ($stat['nlink'] ?? 0)!==1){
			return null;
		}
		$reader ??= static fn(string $path): string|false => @file_get_contents($path);
		$contents=$reader($last_run_file);
		if(!is_string($contents) || preg_match('/^[1-9][0-9]{0,18}$/D',trim($contents))!==1){
			return null;
		}
		$timestamp=(int)trim($contents);
		return $timestamp>0 && $timestamp<=time() ? $timestamp : null;
	}
	
}
