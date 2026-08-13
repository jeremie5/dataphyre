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

/** Collects and publishes early and late runtime findings for the core module. */
class diagnostic {

	/**
	 * Checks connection, root-path, PHP-version, and extension prerequisites.
	 *
	 * The optional observation map is a deterministic diagnostic boundary. Normal
	 * runtime callers omit it; tests and embedders may describe another host without
	 * mutating process globals.
	 *
	 * @param array<string,mixed> $observations
	 * @return list<array<string,mixed>>
	 */
	public static function pre_tests(array $observations=[]): array {
		$server=is_array($observations['server'] ?? null) ? $observations['server'] : $_SERVER;
		$clock=is_callable($observations['clock'] ?? null) ? $observations['clock'] : 'time';
		$rootpaths_defined=array_key_exists('rootpaths_defined', $observations)
			? (bool)$observations['rootpaths_defined']
			: defined('ROOTPATH');
		$rootpaths=$observations['rootpaths'] ?? ($rootpaths_defined ? ROOTPATH : null);
		$php_version=(string)($observations['php_version'] ?? PHP_VERSION);
		$extension_probe=is_callable($observations['extension_loaded'] ?? null)
			? $observations['extension_loaded']
			: 'extension_loaded';
		$verbose=[];

		if(isset($server['HTTP_X_FORWARDED_PROTO'])){
			$verbose[]=self::finding('info', 'You are connected to a load balancer or proxy using https.', $clock);
			$verbose[]=self::finding(
				'info',
				$server['HTTP_X_FORWARDED_PROTO']==='https'
					? 'Traffic between web server and load balancer or proxy is encrypted.'
					: 'Traffic between web server and load balancer or proxy is not encrypted.',
				$clock
			);
		}elseif(($server['HTTPS'] ?? '')==='on'){
			$verbose[]=self::finding('info', 'You ('.($server['REMOTE_ADDR'] ?? 'unknown').') are connected directly to the server using https.', $clock);
		}else{
			$verbose[]=self::finding('info', 'You are connected directly to the server without https.', $clock);
		}

		if(!$rootpaths_defined || empty($rootpaths) || !is_array($rootpaths)){
			$verbose[]=self::finding('error', 'Rootpaths are not defined.', $clock);
		}
		if(version_compare($php_version, $minimum='8.1.0')<0){
			$verbose[]=self::finding('error', 'PHP version '.$minimum.' or higher is required.', $clock);
		}
		foreach(['date','mbstring','pdo_sqlite','openssl','json','session','standard','sockets'] as $extension){
			if(!$extension_probe($extension)){
				$verbose[]=self::finding('error', "PHP extension '{$extension}' is not loaded.", $clock);
			}
		}

		self::publish($verbose, $observations);
		return $verbose;
	}

	/**
	 * Checks request constants, session state, timezone, and execution limits.
	 *
	 * @param array<string,mixed> $observations
	 * @return list<array<string,mixed>>
	 */
	public static function post_tests(array $observations=[]): array {
		$clock=is_callable($observations['clock'] ?? null) ? $observations['clock'] : 'time';
		$constant_defined=is_callable($observations['constant_defined'] ?? null)
			? $observations['constant_defined']
			: 'defined';
		$constant_value=is_callable($observations['constant_value'] ?? null)
			? $observations['constant_value']
			: 'constant';
		$run_mode=$constant_defined('RUN_MODE') ? $constant_value('RUN_MODE') : null;
		$request_ip=$constant_defined('REQUEST_IP_ADDRESS') ? $constant_value('REQUEST_IP_ADDRESS') : null;
		$request_agent=$constant_defined('REQUEST_USER_AGENT') ? $constant_value('REQUEST_USER_AGENT') : null;
		$config=array_key_exists('config', $observations)
			? $observations['config']
			: ($constant_defined('DP_CORE_CFG') ? $constant_value('DP_CORE_CFG') : null);
		$session_status=is_callable($observations['session_status'] ?? null)
			? $observations['session_status']()
			: session_status();
		$timezone=(string)($observations['timezone'] ?? date_default_timezone_get());
		$ini_probe=is_callable($observations['ini_get'] ?? null) ? $observations['ini_get'] : 'ini_get';
		$verbose=[];

		if(!$constant_defined('RUN_MODE')){
			$verbose[]=self::finding('error', 'Constant RUN_MODE constant is not defined.', $clock);
		}
		if(!$constant_defined('REQUEST_IP_ADDRESS') || empty($request_ip)){
			$verbose[]=self::finding('error', 'Constant REQUEST_IP_ADDRESS is undefined or empty.', $clock);
		}
		if(!$constant_defined('REQUEST_USER_AGENT') || empty($request_agent)){
			$verbose[]=self::finding('error', 'Constant REQUEST_USER_AGENT is undefined or empty.', $clock);
		}
		if(!is_array($config)){
			$verbose[]=[
				'module'=>'core',
				'level'=>'warning',
				'message'=>'Core configuration validation was skipped because DP_CORE_CFG is unavailable during this embedded diagnostic scan.',
				'time'=>(int)$clock(),
			];
		}
		if($run_mode==='diagnostic' && $session_status===PHP_SESSION_ACTIVE){
			$verbose[]=self::finding('error', 'Session was started in diagnostic run mode.', $clock);
		}
		if(is_array($config)){
			if(isset($config['timezone']) && $timezone!==$config['timezone']){
				$verbose[]=self::finding('error', 'Timezone is not set according to dataphyre configuration.', $clock);
			}
			if(isset($config['max_execution_memory']) && $ini_probe('memory_limit')!==$config['max_execution_memory']){
				$verbose[]=self::finding('error', 'Memory limit is not set according to configuration.', $clock);
			}
			if(isset($config['max_execution_time']) && $ini_probe('max_execution_time')!=$config['max_execution_time']){
				$verbose[]=self::finding('error', 'Max execution time is not set according to configuration.', $clock);
			}
		}

		self::publish($verbose, $observations);
		return $verbose;
	}

	/** @return array<string,mixed> */
	private static function finding(string $level, string $message, callable $clock): array {
		return ['module'=>'core', $level=>$message, 'time'=>(int)$clock()];
	}

	/** @param list<array<string,mixed>> $findings @param array<string,mixed> $observations */
	private static function publish(array $findings, array $observations): void {
		if($findings===[]){
			return;
		}
		if(array_key_exists('publish', $observations)){
			if(is_callable($observations['publish'])){
				$observations['publish']($findings);
			}
			return;
		}
		if(class_exists('\\dataphyre\\dpanel')){
			\dataphyre\dpanel::add_verbose($findings);
		}
	}
}
