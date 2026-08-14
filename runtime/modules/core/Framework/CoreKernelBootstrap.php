<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Composable decisions and side-effect boundaries for the legacy core kernel entrypoint.
 *
 * Each method names one bootstrap concern and accepts the host operations it needs.
 * This keeps core.main.php thin while allowing exact tests to describe failures without
 * redefining constants, changing the host PHP build, or relying on eval-based fixtures.
 */
final class CoreKernelBootstrap {
	/** @return array<string,mixed> */
	public static function requireRootpaths(mixed $rootpaths, callable $fail): array {
		if(!is_array($rootpaths) || $rootpaths===[]){
			$fail('ROOTPATH constant not defined');
			return [];
		}
		return $rootpaths;
	}

	public static function ensureConstant(
		string $name,
		mixed $value,
		callable $defined,
		callable $define,
		callable $fail
	): void {
		if($defined($name)){
			return;
		}
		if($define($name, $value)===false){
			$fail('Unable to assign '.$name.' constant');
		}
	}

	/** @param list<string> $helpers */
	public static function validateSymbols(
		array $helpers,
		callable $functionExists,
		callable $coreClassExists,
		callable $fail
	): bool {
		$missing=array_values(array_filter(
			$helpers,
			static fn(string $name): bool=>!$functionExists($name)
		));
		if($missing!==[]){
			$fail('Dataphyre core helper functions failed to load: '.implode(', ', $missing));
			return false;
		}
		if(!$coreClassExists()){
			$fail('Dataphyre core class failed to load.');
			return false;
		}
		return true;
	}

	public static function validateBootstrapVersion(?string $version, string $minimum, callable $fail): void {
		if($version===null || trim($version)===''){
			$fail('Dataphyre Bootstrap version unknown');
			return;
		}
		if(version_compare($version, $minimum, '<')){
			$fail("Dataphyre Core is incompatible with Dataphyre Bootstrap version {$version}. Please update to {$minimum}");
		}
	}

	public static function validatePlatform(
		int $integerSize,
		bool $production,
		callable $warn,
		callable $fail
	): void {
		if($integerSize>=8){
			return;
		}
		$warn('Dataphyre requires a 64 bit PHP build for production safety.');
		if($production){
			$fail('64-bit PHP build required in production.');
		}
	}

	public static function ensureVerified(
		string $appRoot,
		?string $application,
		callable $fileExists,
		callable $install,
		callable $clearStatCache
	): bool {
		$verifiedPath=rtrim($appRoot, '/\\').'/cache/verified';
		if(!$fileExists($verifiedPath)){
			$install($application);
			$clearStatCache($verifiedPath);
		}
		return (bool)$fileExists($verifiedPath);
	}

	public static function resolveRunMode(
		?string $currentMode,
		bool $verified,
		?string $installError,
		callable $fail
	): string {
		if($currentMode===null){
			if($verified){
				return 'request';
			}
			$fail(self::verificationFailure($installError));
			return 'diagnostic';
		}
		if(in_array($currentMode,['request','scheduler-task'],true) && !$verified){
			$fail(self::verificationFailure($installError));
		}
		return $currentMode;
	}

	public static function verificationFailure(?string $installError): string {
		$message='Dataphyre install must be verified or installed from the configured flight sheet.';
		return $installError===null || trim($installError)==='' ? $message : $message.' '.$installError;
	}

	public static function validatePrivateKey(mixed $key, callable $fail): void {
		if(empty($key)){
			$fail('Failed initializing DPVK');
		}
	}

	/** @param array<string,mixed> $config */
	public static function configuredMemoryLimit(string|false $override, array $config): string {
		return $override!==false && trim($override)!==''
			? $override
			: (string)($config['max_execution_memory'] ?? '16M');
	}

	/** @param array<string,mixed> $config @return array{enabled:bool,lifespan:string,name:string,secure:bool} */
	public static function sessionPlan(array $config): array {
		$session=is_array($config['core']['php_session'] ?? null) ? $config['core']['php_session'] : [];
		return [
			'enabled'=>(($session['enabled'] ?? true)!==false),
			'lifespan'=>(string)max(60, (int)(
				$session['lifespan']
				?? $session['cookie']['lifespan']
				?? $config['php_session_lifespan']
				?? 900
			)),
			'name'=>(string)($session['cookie']['name'] ?? 'PHPSESSID'),
			'secure'=>(($session['cookie']['secure'] ?? true)===true),
		];
	}

	/** @param array<string,mixed> $config */
	public static function configureSession(
		string $runMode,
		array $config,
		callable $sessionStatus,
		callable $iniSet,
		callable $sessionStart,
		callable $fail,
		callable $warn,
		callable $unavailable
	): void {
		if($runMode!=='request' && $runMode!=='diagnostic'){
			return;
		}
		$plan=self::sessionPlan($config);
		if($sessionStatus()===PHP_SESSION_ACTIVE || !$plan['enabled']){
			return;
		}
		$settings=[
			'session.cookie_lifetime'=>$plan['lifespan'],
			'session.gc_maxlifetime'=>$plan['lifespan'],
			'session.name'=>$plan['name'],
			'session.cookie_httponly'=>'1',
			'session.cookie_samesite'=>'Strict',
			'session.use_only_cookies'=>'1',
		];
		if($plan['secure']){
			$settings['session.cookie_secure']='1';
		}
		$configured=true;
		foreach($settings as $name=>$value){
			if($iniSet($name, $value)===false){
				$configured=false;
			}
		}
		if(!$configured){
			if($runMode==='request'){
				$fail('Failed to ini_set() session parameters');
			}else{
				$warn('DataphyreCore: Unable to apply PHP session ini parameters in diagnostic mode; continuing without session bootstrap changes.');
			}
		}
		if($runMode==='request' && $sessionStatus()!==PHP_SESSION_ACTIVE && $sessionStart()===false){
			$unavailable('DataphyreCore: Failed starting php session', 'safemode');
		}
	}

	public static function memoryLimitToBytes(string $value): int {
		$value=trim($value);
		if($value==='' || $value==='-1'){
			return -1;
		}
		if(!preg_match('/^(\d+(?:\.\d+)?)([gmk])?$/i', $value, $matches)){
			return 0;
		}
		$number=(float)$matches[1];
		return (int)match(strtolower($matches[2] ?? '')){
			'g'=>$number * 1073741824,
			'm'=>$number * 1048576,
			'k'=>$number * 1024,
			default=>$number,
		};
	}

	/**
	 * @param array{debugbar_available?:bool,debugbar_enabled?:callable,apply_debugbar?:callable,ini_get:callable,ini_set:callable,memory_usage:callable,warn:callable,fail:callable} $runtime
	 */
	public static function configureMemory(string $configuredLimit, array $runtime): string {
		$target=$configuredLimit;
		$debugbarAvailable=($runtime['debugbar_available'] ?? false)===true;
		if($debugbarAvailable){
			try{
				if(($runtime['debugbar_enabled'])()===true){
					($runtime['apply_debugbar'])();
					$flightdeckLimit=(string)($runtime['ini_get'])('memory_limit');
					$flightdeckBytes=self::memoryLimitToBytes($flightdeckLimit);
					$targetBytes=self::memoryLimitToBytes($target);
					if($flightdeckLimit!=='' && ($flightdeckBytes<=0 || ($targetBytes>0 && $flightdeckBytes>$targetBytes))){
						$target=$flightdeckLimit;
					}
				}
			}catch(\Throwable){
			}
		}

		$current=(string)($runtime['ini_get'])('memory_limit');
		$currentBytes=self::memoryLimitToBytes($current);
		$targetBytes=self::memoryLimitToBytes($target);
		if($targetBytes>0 && $targetBytes<=($runtime['memory_usage'])()){
			($runtime['warn'])('DataphyreCore: Skipped lowering PHP memory_limit below current request usage.');
		}elseif(($runtime['ini_set'])('memory_limit', $target)===false){
			if($currentBytes<=0 || ($targetBytes>0 && $currentBytes>=$targetBytes)){
				($runtime['warn'])('DataphyreCore: Unable to change PHP memory_limit; continuing with existing limit '.$current.'.');
			}else{
				($runtime['fail'])('Failed to ini_set() memory_limit');
			}
		}

		if($debugbarAvailable){
			try{
				if(($runtime['debugbar_enabled'])()===true){
					($runtime['apply_debugbar'])();
				}
			}catch(\Throwable){
			}
		}
		return $target;
	}

	public static function configureExecutionTime(int|string $seconds, callable $iniSet, callable $fail): void {
		if($iniSet('max_execution_time', $seconds)===false){
			$fail('Failed to ini_set() max_execution_time');
		}
	}

	public static function configureTimezone(string $timezone, callable $timezoneSet, callable $fail): void {
		if($timezoneSet($timezone)===false){
			$fail('Invalid timezone: '.$timezone);
		}
	}

	/** @return list<string> */
	public static function modulesForRunMode(string $runMode): array {
		if($runMode==='diagnostic'){
			return [];
		}
		$modules=['tracelog','cache','sql','vestra'];
		if($runMode==='request'){
			array_push($modules, 'async','google_authenticator','firewall','perfstats','country_blocking','caspow');
		}
		return array_merge($modules, [
			'localization','issue','scheduling','datadoc','date_translation','currency',
			'templating','mailer','geoposition','sanitation','stripe','fulltext_engine',
			'access','time_machine','supercookie','fraudar',
		]);
	}

	public static function loadModules(string $runMode, callable $present, callable $load): void {
		foreach(self::modulesForRunMode($runMode) as $module){
			$descriptor=$present($module);
			if(!is_array($descriptor) || empty($descriptor[0])){
				continue;
			}
			$load((string)$descriptor[0], $module==='datadoc');
		}
	}

	public static function prepareRequest(
		string $runMode,
		callable $getServerLoad,
		callable $checkDelayedLock,
		callable $sessionStatus,
		callable $serverLoadLevel,
		callable $unavailable,
		bool $loadSheddingEnabled=true
	): void {
		if($runMode!=='request'){
			return;
		}
		if($loadSheddingEnabled){
			$getServerLoad();
			$checkDelayedLock();
		}
		if($loadSheddingEnabled && $sessionStatus()!==PHP_SESSION_ACTIVE && $serverLoadLevel()===5){
			$unavailable('Load shedding as visitor had no session and server load level is above 5', 'loadlevel');
		}
	}

	/** @param array<string,mixed> $config */
	public static function loadSheddingEnabled(array $config): bool {
		$policy=is_array($config['core']['load_shedding'] ?? null)
			? $config['core']['load_shedding']
			: [];
		return ($policy['enabled'] ?? true)!==false;
	}

	public static function runDiagnostic(string $runMode, callable $load, callable $run): void {
		if($runMode!=='diagnostic'){
			return;
		}
		$load();
		$run();
	}

	public static function finishRequest(string $runMode, callable $setHeaders): void {
		if($runMode==='request'){
			$setHeaders();
		}
	}
}
