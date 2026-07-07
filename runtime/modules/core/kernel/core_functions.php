<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

use \Datetime;
use \DateTimeZone;

/**
 * Core runtime utilities for bootstrapping, configuration, security, and requests.
 *
 * The core kernel owns dialback callbacks, framework module loading, shared
 * request tokens, default HTTP headers, server load checks, date conversion,
 * configuration access, filesystem helpers,
 * encryption, CSRF tokens, URL helpers, delayed-request locks, and client IP
 * resolution.
 */
class core {
	
	public static $server_load_level=null; // null or 1 to 5, 5 representing highest server load.
	
	public static $server_load_bottleneck=null; // String cause of resource bottlenecking.
	
	public static $used_packaged_config=false;
	
	public static $dialbacks=[];
	public static array $dialback_calls=[];
	
	public static $display_language="en";

	/**
	 * Returns the mutable raw configuration store by reference.
	 *
	 * The helper normalizes non-array CFG payloads to an empty array before
	 * returning the reference, so callers can merge or assign nested config
	 * without re-reading the global configuration wrapper.
	 *
	 * @return array<string,mixed> Reference to the process-local CFG raw store.
	 */
	private static function &config_store(): array {
		$cfg=&CFG->raw();
		if(!is_array($cfg)){
			$cfg=[];
		}
		return $cfg;
	}

	/**
	 * Normalizes a dotted configuration path before lookup or mutation.
	 *
	 * @param string $path Raw configuration path.
	 * @return string Trimmed configuration path.
	 */
	private static function normalize_config_path(string $path): string {
		return trim($path);
	}

	/**
	 * Normalizes configuration payloads before merging them into the store.
	 *
	 * @param array<string,mixed> $config Raw configuration payload.
	 * @return array<string,mixed> Normalized configuration payload.
	 */
	private static function normalize_config_payload(array $config): array {
		return $config;
	}
	
	/**
	 * Loads plugin PHP files for a plugin type from common and app roots.
	 *
	 * Files under ROOTPATH common_dataphyre/plugins/{type} and dataphyre/plugins/{type}
	 * are required in glob order. Loading is immediate and may execute plugin side
	 * effects such as registrations or configuration changes.
	 *
	 * @param string $type Plugin category directory name.
	 * @return void
	 */
	public static function load_plugins(string $type): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Looking for $type plugins");
		foreach(['common_dataphyre', 'dataphyre'] as $plugin_path){
			foreach(glob(ROOTPATH[$plugin_path].'plugins/'.$type.'/*.php') as $plugin){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loading $type plugin");
				require($plugin);
			}
		}
	}

	private static array $framework_modules_loaded=[];

	/**
	 * Loads the autoloader and module registry required by framework module loading.
	 */
	private static function ensure_framework_loader_dependencies(): void {
		require_once(__DIR__.'/autoloader.php');
		require_once(__DIR__.'/module_registry.php');
	}

	/**
	 * Registers framework autoload paths and loads one framework module entrypoint.
	 *
	 * Module names are lowercased and cached after the first attempt. A true result
	 * means either autoload registration found framework classes or the module
	 * registry located and required a framework entry file.
	 *
	 * @param string $module Runtime module name.
	 * @return bool True when framework support for the module was found.
	 */
	public static function load_framework_module(string $module): bool {
		$module=strtolower(trim($module));
		if($module===''){
			return false;
		}
		if(isset(self::$framework_modules_loaded[$module])){
			return self::$framework_modules_loaded[$module];
		}
		self::ensure_framework_loader_dependencies();
		if(defined('ROOTPATH')){
			foreach(['common_dataphyre_runtime', 'common_dataphyre', 'dataphyre'] as $root_key){
				if(empty(ROOTPATH[$root_key])){
					continue;
				}
				$modules_root=rtrim((string)ROOTPATH[$root_key], '/\\').'/modules';
				if(is_dir($modules_root)){
					\dataphyre\autoloader::register($modules_root);
				}
			}
		}
		$autoload_registered=\dataphyre\autoloader::register_framework_modules($module)!==[];
		$framework_entry=\dataphyre\module_registry::framework_module_present($module);
		if($framework_entry!==false){
			require_once($framework_entry);
		}
		$loaded=($autoload_registered || $framework_entry!==false);
		self::$framework_modules_loaded[$module]=$loaded;
		return $loaded;
	}

	/**
	 * Loads multiple framework modules and returns the successful names.
	 *
	 * Input may be one module name or a list. Returned names are lowercased,
	 * deduplicated, and include only modules where load_framework_module() succeeded.
	 *
	 * @param array<int, string>|string $modules Module name or names.
	 * @return array<int, string> Successfully loaded module names.
	 */
	public static function load_framework_modules(array|string $modules): array {
		$modules=is_array($modules) ? $modules : [$modules];
		$loaded=[];
		foreach($modules as $module){
			if(self::load_framework_module((string)$module)===true){
				$loaded[]=strtolower(trim((string)$module));
			}
		}
		return array_values(array_unique($loaded));
	}

	/**
	 * Creates a time-windowed shared request token for a purpose and context.
	 *
	 * Token generation is delegated to the bootstrap helper when available. Missing
	 * helper support returns false instead of throwing.
	 *
	 * @param string $secret_file Secret identifier used by the helper.
	 * @param string $purpose Purpose namespace for the token.
	 * @param string $context Context string bound into the token.
	 * @param int|null $timestamp Optional timestamp used for deterministic generation.
	 * @param int|null $period Optional validity period override.
	 * @return string|false Generated token or false when helper support is unavailable.
	 */
	public static function shared_request_key(
		string $secret_file,
		string $purpose,
		string $context='',
		?int $timestamp=null,
		?int $period=null
	): string|false {
		if(!function_exists('dp_shared_request_key')){
			return false;
		}
		return dp_shared_request_key($secret_file, $purpose, $context, $timestamp, $period);
	}

	/**
	 * Verifies a shared request token against purpose, context, and time window.
	 *
	 * Verification is delegated to the bootstrap helper when available. Missing helper
	 * support returns false.
	 *
	 * @param string $token Token to verify.
	 * @param string $secret_file Secret identifier used by the helper.
	 * @param string $purpose Purpose namespace expected in the token.
	 * @param string $context Context string expected in the token.
	 * @param int $window Number of adjacent periods accepted by verification.
	 * @param int|null $timestamp Optional timestamp used as verification anchor.
	 * @param int|null $period Optional validity period override.
	 * @return bool True when the token verifies.
	 */
	public static function verify_shared_request_key(
		string $token,
		string $secret_file,
		string $purpose,
		string $context='',
		int $window=1,
		?int $timestamp=null,
		?int $period=null
	): bool {
		if(!function_exists('dp_verify_shared_request_key')){
			return false;
		}
		return dp_verify_shared_request_key($token, $secret_file, $purpose, $context, $window, $timestamp, $period);
	}

	/**
	 * Creates an application override token for one application name.
	 *
	 * Blank application names are rejected. The token is a shared request key bound to
	 * the app_override purpose and application context.
	 *
	 * @param string $application Application identifier.
	 * @param int|null $timestamp Optional timestamp used for deterministic generation.
	 * @param int|null $period Optional validity period override.
	 * @return string|false Override token or false when unavailable.
	 */
	public static function app_override_key_token(string $application, ?int $timestamp=null, ?int $period=null): string|false {
		$application=trim($application);
		if($application===''){
			return false;
		}
		return self::shared_request_key('app_override_key', 'app_override', $application, $timestamp, $period);
	}

	/**
	 * Builds the request value used to submit an application override.
	 *
	 * The value is application,token. Blank applications or failed token generation
	 * return false.
	 *
	 * @param string $application Application identifier.
	 * @param int|null $timestamp Optional timestamp used for deterministic generation.
	 * @param int|null $period Optional validity period override.
	 * @return string|false Comma-delimited request value or false.
	 */
	public static function app_override_request_value(string $application, ?int $timestamp=null, ?int $period=null): string|false {
		$application=trim($application);
		if($application===''){
			return false;
		}
		$key=self::app_override_key_token($application, $timestamp, $period);
		if($key===false){
			return false;
		}
		return $application.','.$key;
	}

	/**
	 * Verifies an application override token.
	 *
	 * Tokens are checked with app_override purpose and application context. Blank
	 * application names are rejected.
	 *
	 * @param string $application Application identifier.
	 * @param string $token Submitted override token.
	 * @param int $window Number of adjacent periods accepted by verification.
	 * @param int|null $timestamp Optional timestamp used as verification anchor.
	 * @param int|null $period Optional validity period override.
	 * @return bool True when the override token verifies.
	 */
	public static function verify_app_override_key_token(
		string $application,
		string $token,
		int $window=1,
		?int $timestamp=null,
		?int $period=null
	): bool {
		$application=trim($application);
		if($application===''){
			return false;
		}
		return self::verify_shared_request_key($token, 'app_override_key', 'app_override', $application, $window, $timestamp, $period);
	}

	/**
	 * Creates a direct-access token bound to an optional scope.
	 *
	 * The token uses the direct_access purpose and the provided scope string as
	 * context.
	 *
	 * @param string|null $scope Optional direct-access scope.
	 * @param int|null $timestamp Optional timestamp used for deterministic generation.
	 * @param int|null $period Optional validity period override.
	 * @return string|false Direct-access token or false when unavailable.
	 */
	public static function direct_access_key_token(?string $scope=null, ?int $timestamp=null, ?int $period=null): string|false {
		return self::shared_request_key('direct_access_key', 'direct_access', (string)($scope ?? ''), $timestamp, $period);
	}

	/**
	 * Verifies a direct-access token against an optional scope.
	 *
	 * @param string $token Submitted direct-access token.
	 * @param string|null $scope Optional expected direct-access scope.
	 * @param int $window Number of adjacent periods accepted by verification.
	 * @param int|null $timestamp Optional timestamp used as verification anchor.
	 * @param int|null $period Optional validity period override.
	 * @return bool True when the token verifies.
	 */
	public static function verify_direct_access_key_token(
		string $token,
		?string $scope=null,
		int $window=1,
		?int $timestamp=null,
		?int $period=null
	): bool {
		return self::verify_shared_request_key($token, 'direct_access_key', 'direct_access', (string)($scope ?? ''), $window, $timestamp, $period);
	}

	/**
	 * Flushes the HTTP response to the client while allowing PHP work to continue.
	 *
	 * The method closes output buffers, sends Content-Length and Connection: close,
	 * flushes FastCGI when available, and closes the active session to release locks.
	 *
	 * @param string|null $output Explicit response body, or current buffer when null.
	 * @return void
	 */
	public static function end_client_connection(?string $output=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		ignore_user_abort(true);
		if(ob_get_level()===0) ob_start();
		if($output===null) $output=ob_get_contents();
		ob_end_clean();
		ob_start();
		header('Connection: close');
		header('Content-Length: '.strlen($output));
		echo $output;
		ob_end_flush();
		flush();
		if(function_exists('fastcgi_finish_request')) fastcgi_finish_request();
		if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
	}
	
	/**
	 * Triggers a dialback function associated with a given event name, passing any provided data as arguments.
	 *
	 * @package dataphyre\core
	 * 
	 * @param string $event_name The name of the event to trigger.
	 * @param mixed ...$data Variable number of arguments to pass to the dialback function.
	 * 
	 * @return mixed Returns the result of the last executed dialback function or `null` if no such event is registered.
	 *
	 * @example
	 * // Assuming 'CALL_CORE_EXAMPLE' has been registered with a dialback
	 * $result = dataphyre\core::dialback('CALL_CORE_EXAMPLE', 'arg1', 'arg2');
	 * if ($result !== null) {
	 *     echo "Dialback executed with result: $result";
	 * }
	 * 
	 * @common_pitfalls
	 * 1. If multiple dialback functions are registered for the same event, only the result of the last function is returned.
	 * 2. Ensure that the registered dialback functions are capable of handling the arguments you pass.
	 * 3. This method does not throw errors or exceptions if the event name does not exist. It simply returns `null`.
	 */
	public static function dialback(string $event_name, ...$data) : mixed {
		self::$dialback_calls[]=[
			'hook'=>$event_name,
			'args'=>$data,
		];
		$result=null;
		if(isset(core::$dialbacks[$event_name])){
			foreach(core::$dialbacks[$event_name] as $function){
				$result=$function(...$data);
			}
		}
		return $result;
	}
	
	/**
	 * Registers a dialback function to be executed when a specific event occurs.
	 *
	 * @package dataphyre\core
	 * 
	 * @param string $event_name The name of the event to associate with the dialback function.
	 * @param callable $dialback_function The dialback function to be registered.
	 * 
	 * @return bool Returns `true` if the registration is successful, otherwise triggers an error and enters safemode.
	 *
	 * @example
	 * // Register a callable for an explicit module-scoped event
	 * if (dataphyre\core::register_dialback('CALL_CORE_EXAMPLE', static fn(): bool=>true)) {
	 *     echo "Dialback registered successfully.";
	 * }
	 * 
	 * @common_pitfalls
	 * 1. Ensure that the dialback function exists and is callable, otherwise an error will be logged and the application enters safemode.
	 * 2. Event names are case-sensitive; use existing names exactly when extending framework hooks.
	 * 3. New framework-facing events should use `CALL_<MODULE>_<ACTION>` names.
	 */
	public static function register_dialback(string $event_name, callable $dialback_function){
		if(is_callable($dialback_function)){
			if(!isset(core::$dialbacks[$event_name])){
				core::$dialbacks[$event_name]=array($dialback_function);
				return true;
			}
			core::$dialbacks[$event_name][]=$dialback_function;
			return true;
		}
		log_error("Dialback function $dialback_function does not exist");
		core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Dialback function does not exist.", $T="safemode");
	}
	
	/**
	 * Sends Dataphyre default security and platform HTTP headers when possible.
	 *
	 * Headers include server identity, browser hardening, referrer policy, conditional
	 * HSTS for non-local HTTPS requests, permissions policy, and CSP.
	 *
	 * @return void
	 */
	public static function set_http_headers(): void {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(!headers_sent()){
			header_remove("X-Powered-By");
			header("X-XSS-Protection: 1; mode=block");
			header("X-Frame-Options: deny");
			header("X-Content-Type-Options: nosniff");
			header("X-Permitted-Cross-Domain-Policies: none");
			header("Referrer-Policy: strict-origin-when-cross-origin");
			$request_https=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https') || (($_SERVER['REQUEST_SCHEME'] ?? '')==='https');
			$host=strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
			if(str_starts_with($host, '[') && preg_match('/^\[([^\]]+)\]/', $host, $host_matches)===1){
				$host_without_port=$host_matches[1];
			}else{
				$host_without_port=explode(':', $host, 2)[0];
			}
			$is_local_host=in_array($host_without_port, ['127.0.0.1', 'localhost', '::1'], true) || str_ends_with($host_without_port, '.localhost');
			if($request_https && !$is_local_host){
				header("Strict-Transport-Security: max-age=31536000");
				header("Upgrade-Insecure-Requests: 1");
			}
			header("Permissions-Policy: autoplay=*");
			header("Content-Security-Policy: script-src 'self' 'unsafe-inline' 'unsafe-eval' https: blob: data:;");
		}
	}
	
	/**
	 * Retrieves the server's current load level based on CPU and memory usage.
	 * 
	 * @package dataphyre\core
	 * @static
	 * 
	 * @global int $server_load_level Caches the server load level once it's calculated.
	 * @global string $server_load_bottleneck Indicates which resource (CPU or memory) is the bottleneck if the server is overloaded.
	 * 
	 * @uses core::dialback Allows for early return or modification via a dialback mechanism.
	 * @uses core::unavailable Handles server unavailability scenarios based on resource overload.
	 * 
	 * @return int Returns the server load level (from 0 to 5), or the result of the dialback if it provides an early return.
	 * 
	 * @example
	 *  // Retrieve the current server load level
	 *  $loadLevel = \dataphyre\core::get_server_load_level();  // Output could be an integer between 0 and 5
	 * 
	 * @commonpitfalls
	 *  1. This method uses shell commands and sys_getloadavg(), which might not work on all environments or require special permissions.
	 *  2. High CPU or memory usage will trigger an unavailable status, effectively shutting down the service. Ensure proper resource management.
	 *  3. Caching the server load level can result in stale or inaccurate data.
	 */
	public static function get_server_load_level() : int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=self::dialback("CALL_CORE_GET_SERVER_LOAD_LEVEL", ...func_get_args())) return $early_return;
		if(self::$server_load_level!==null) return self::$server_load_level;
		$cache_file=ROOTPATH['common_dataphyre'].'cache/load_level.php';
		if(file_exists($cache_file) && is_readable($cache_file)){
			$cache=include($cache_file);
			if(is_array($cache) && isset($cache['level'], $cache['timestamp'], $cache['bottleneck'])){
				if(time()-$cache['timestamp']<5){
					self::$server_load_bottleneck=$cache['bottleneck'];
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Level: ".$cache['level']." | Bottleneck: ".$cache['bottleneck']);
					return self::$server_load_level=$cache['level'];
				}
			}
		}
		$meminfo=[];
		$fh=is_readable('/proc/meminfo') ? fopen('/proc/meminfo', 'r') : false;
		if($fh){
			while(($line=fgets($fh))!==false){
				if(preg_match('/^(\w+):\s+(\d+)/', $line, $matches)){
					$meminfo[$matches[1]]=(int)$matches[2];
				}
			}
			fclose($fh);
		}
		$cpu_load=CPU_USAGE;
		$memory_usage=0;
		if(!empty($meminfo['MemAvailable']) && !empty($meminfo['MemTotal'])){
			$used=$meminfo['MemTotal']-$meminfo['MemAvailable'];
			$memory_usage=round(($used/$meminfo['MemTotal'])*100, 1);
		}
		if($cpu_load>=85){
			$level=5;
			$bottleneck='cpu';
		}
		elseif($memory_usage>=85){
			$level=5;
			$bottleneck='memory';
		}
		else
		{
			$collective_average=($memory_usage+$cpu_load) / 2;
			$level=round(($collective_average*5) / 100);
			$bottleneck='balanced';
		}
		self::$server_load_level=$level;
		self::$server_load_bottleneck=$bottleneck;
		$tracelog="Level: $level | Bottleneck: $bottleneck | CPU: ".round($cpu_load,1)." % | Memory: ".round($memory_usage,1)." %";
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $tracelog);
		$payload="<?php return ['level'=>".var_export($level,true).", 'bottleneck'=>".var_export($bottleneck,true).", 'timestamp'=>".time()."];";
		self::file_put_contents_forced($cache_file, $payload, LOCK_EX);
		return $level;
	}

	/**
	 * Creates the filesystem lock that signals delayed request handling.
	 *
	 * Failure to create the lock enters Dataphyre unavailable/safemode handling.
	 *
	 * @return void
	 */
	public static function delayed_requests_lock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(fopen(ROOTPATH['dataphyre']."delaying_lock", 'w+')===false){
			self::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Failed to create delaying lock", $T="safemode");
		}
	}
	
	/**
	 * Removes the delayed request filesystem lock.
	 *
	 * Failure to remove the lock enters Dataphyre unavailable/safemode handling.
	 *
	 * @return void
	 */
	public static function delayed_requests_unlock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(!unlink(ROOTPATH['dataphyre']."delaying_lock")){
			self::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Failed to remove delaying lock", $T="safemode");
		}
	}

	/**
	 * Blocks while the delayed request lock exists.
	 *
	 * The caller waits until another process removes the lock file, allowing coarse
	 * request serialization during maintenance or throttled sections.
	 *
	 * @return void
	 */
	public static function check_delayed_requests_lock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		$timer=0;
		while($timer<5){
			if(!is_file(ROOTPATH['dataphyre']."delaying_lock")){
				break;
			}
			usleep(100000);
			$timer++;
		}
		if(is_file(ROOTPATH['dataphyre']."delaying_lock")){
			core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Delaying lock is active", $T="maintenance");
			core::unavailable("DPE-004", "safemode");
		}
	}
	
	/**
	 * Returns the inline minified font stylesheet used by core fallback UI.
	 *
	 * @return string inline CSS declaring the compact system-font class used by core fallback UI.
	 */
	public static function minified_font() : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		return ".phyro-bold{font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-weight:700;font-style:normal;line-height:1.15;-webkit-font-smoothing:antialiased}";
	}
	
	/**
	 * Generates an encrypted password from a given string.
	 * This method is part of the Dataphyre project.
	 * 
	 * @static
	 * 
	 * @param string $string The string to be encrypted.
	 * 
	 * @return string Returns the encrypted password.
	 * 
	 * @uses core::encrypt_data To encrypt the input string.
	 * @uses core::get_config To retrieve the private key for encryption.
	 * @uses core::dialback To potentially modify the behavior of this method dynamically.
	 * 
	 * @example
	 *  // Get encrypted password from string 'my_password'
	 *  $encrypted_password = \dataphyre\core::get_password('my_password');
	 * 
	 * @commonpitfalls
	 *  1. Ensure that the private key used for encryption is securely stored and managed.
	 *  2. Make sure that `core::dialback` behavior is as expected when it is in use.
	 */
	public static function get_password(#[\SensitiveParameter] string $string) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_GET_PASSWORD",...func_get_args())) return $early_return;
		$salting_data=[dpvk()];
		$key=substr(hash('sha256', base64_encode($salting_data[0])), 0, 16);
		$password=openssl_encrypt($string, "AES-256-CBC", $key, 0, $key);
		$password=str_replace('=', '', base64_encode($password));
		return $password;
	}
	
	/**
	 * Returns a high-precision date and time string formatted according to the provided format string.
	 * This function leverages PHP's DateTime object to generate a date-time string based on the server's
	 * configured time zone. 
	 *
	 * @package dataphyre\core
	 *
	 * @param string $format The format string that the date-time should adhere to.
	 *                       Default is 'Y-m-d H:i:s.u', which corresponds to full date, hours, minutes,
	 *                       seconds, and microseconds.
	 * 
	 * @return string Returns the formatted date string.
	 *
	 * @throws Exception Throws an exception if the server timezone is invalid or if date-time creation fails.
	 *                   The function will also fall back to safemode if an error occurs.
	 *
	 * @example
	 * // Standard usage:
	 * $result = dataphyre\core::high_precision_server_date();
	 * // Output might look like: "2023-10-08 12:34:56.789"
	 *
	 * // Custom formatting:
	 * $result = dataphyre\core::high_precision_server_date('Y-m-d');
	 * // Output might look like: "2023-10-08"
	 *
	 * @see DateTime::createFromFormat()
	 * @see DateTimeZone
	 *
	 * @commonpitfalls
	 * - Make sure to provide a valid format string, or else the function will not return the expected results.
	 * - Ensure that the server timezone is set and valid. Otherwise, the function will throw an exception and fall back to safemode.
	 */
	public static function high_precision_server_date(string $format='Y-m-d H:i:s.u'): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_HIGH_PRECISION_SERVER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('base_timezone');
		$valid_timezones=timezone_identifiers_list();
		if(in_array($server_timezone, $valid_timezones)){
			try{
				$datetime=DateTime::createFromFormat('U.u', microtime(true));
				$datetime->setTimezone(new DateTimeZone($server_timezone));
				return substr($datetime->format($format), 0, -3);
			}catch(Exception $e){
				core::unavailable("DPE-025", "safemode");
			}
		}
		else
		{
			core::unavailable("DPE-024", "safemode");
		}
	}
	
	/**
	 * Formats a given date according to a specified format string, with optional translation.
	 * This function takes a date (either as a string or Unix timestamp) and returns a formatted date-time string.
	 *
	 * @package dataphyre\core
	 *
	 * @param mixed  $date        The date to be formatted. Can be a date string or a Unix timestamp.
	 * @param string $format      The format string that defines the output format of the date.
	 *                            Default is 'n/j/Y g:i A'.
	 * @param bool   $translation If set to true, the function will attempt to translate the date using
	 *                            the "dataphyre\date_translation" class. Default is true.
	 *
	 * @return string Returns the formatted (and possibly translated) date string.
	 *
	 * @example
	 * // Formatting a date string:
	 * $result = dataphyre\core::format_date("2023-10-08 12:34:56");
	 * // Output might look like: "10/8/2023 12:34 PM"
	 *
	 * // Formatting a Unix timestamp:
	 * $result = dataphyre\core::format_date(1678882496);
	 * // Output might look like: "10/8/2023 12:34 PM"
	 *
	 * // Disabling translation:
	 * $result = dataphyre\core::format_date("2023-10-08 12:34:56", 'n/j/Y g:i A', false);
	 * // Output will not be translated
	 *
	 * @commonpitfalls
	 * - Be cautious when using translations. Ensure that the "dataphyre\date_translation" class exists and functions as expected.
	 * - Providing an invalid or unsupported date will result in unexpected behavior.
	 * - Make sure to pass a valid format string.
	 */
	public static function format_date(string $date, string $format='n/j/Y g:i A', bool $translation=true) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_FORMAT_DATE",...func_get_args())) return $early_return;
		if(is_numeric($date)){
			$date=date('Y-m-d H:i:s', (int)$date);
		}
		$datetime=new DateTime($date);
		$result=$datetime->format($format);
		if($translation===true){
			if(class_exists("dataphyre\date_translation")){
				$result=date_translation::translate_date($result, self::$display_language, $format);
			}
		}
		return $result;
	}
	
	/**
	 * Converts a given date to a user-specified time zone and format, with optional translation.
	 *
	 * This function takes a date (either as a string or a Unix timestamp) and a user-specified time zone.
	 * It then returns a date-time string formatted according to a provided format string and the user's time zone.
	 *
	 * @package dataphyre\core
	 *
	 * @param string|int $date          The date to be converted and formatted. Can be a date string or a Unix timestamp.
	 * @param string     $user_timezone The desired time zone for the user.
	 * @param string     $format        The format string that defines the output format of the date.
	 *                                  Default is 'n/j/Y g:i A'.
	 * @param bool       $translation   If set to true, the function will attempt to translate the date using
	 *                                  the "dataphyre\date_translation" class. Default is true.
	 *
	 * @return string Returns the converted, formatted (and possibly translated) date string.
	 *
	 * @throws Exception Throws an exception if any of the time zones are invalid or if date-time creation fails.
	 *                   The function will also fall back to safemode if an error occurs.
	 *
	 * @example
	 * // Converting a date string to user time zone:
	 * $result = dataphyre\core::convert_to_user_date("2023-10-08 12:34:56", "America/New_York");
	 * // Output might look like: "10/8/2023 8:34 AM" if the server time zone is UTC.
	 *
	 * // Converting a Unix timestamp to user time zone:
	 * $result = dataphyre\core::convert_to_user_date(1678882496, "America/New_York");
	 * // Output might look like: "10/8/2023 8:34 AM" if the server time zone is UTC.
	 *
	 * @commonpitfalls
	 * - Make sure to pass valid server and user time zones. Invalid time zones will trigger fallbacks or errors.
	 * - Providing an invalid or unsupported date will result in unexpected behavior.
	 * - Ensure that the "dataphyre\date_translation" class exists and functions as expected if using translations.
	 */
	public static function convert_to_user_date(string|int $date, string $user_timezone, string $format='n/j/Y g:i A', bool $translation=true) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_CONVERT_TO_USER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('base_timezone');
		$valid_timezones=timezone_identifiers_list();
		if(in_array($server_timezone, $valid_timezones)){
			if(!in_array($user_timezone, $valid_timezones)){
				$user_timezone=core::get_config('default_timezone');
				if(!in_array($user_timezone, $valid_timezones)){
					$user_timezone=$server_timezone;
				}
			}
			try{
				if(is_numeric($date))$date=date('Y-m-d H:i:s', $date);
				$datetime=new DateTime($date, new DateTimeZone($server_timezone));
				$datetime->setTimezone(new DateTimeZone($user_timezone));
				$result=$datetime->format($format);
				if($translation===true){
					if(class_exists("dataphyre\date_translation")){
						$result=date_translation::translate_date($result, self::$display_language, $format);
					}
				}
				return $result;
			} catch(Exception $e){
				core::unavailable("DPE-025", "safemode");
			}
		}
		else
		{
			core::unavailable("DPE-024", "safemode");
		}
	}

	/**
	 * Convert a given date to server timezone.
	 *
	 * This function takes a date in a specific user timezone and converts it to the server timezone. 
	 * It allows multiple types for the $date parameter (string or integer) and has defaults for timezone and format.
	 * The function also performs logging and allows for dialback functionality.
	 * 
	 * @package dataphyre\core
	 *
	 * @param string|int $date          The date to be converted. Can be either UNIX timestamp or date string.
	 * @param mixed      $user_timezone The user's timezone.
	 * @param string     $format        The format in which the date should be returned. Defaults to 'n/j/Y g:i A'.
	 * 
	 * @return string|mixed Returns the date in the server's timezone and in the format specified. Could also return early
	 *                      if dialback function CALL_CORE_CONVERT_TO_SERVER_DATE is defined.
	 * 
	 * @throws Exception When DateTime conversion fails, the function will trigger safemode with code "DPE-025".
	 *                   When the server timezone is not valid, the function will trigger safemode with code "DPE-024".
	 * 
	 * @example
	 * // Example 1: Convert UNIX timestamp to server date
	 * $convertedDate = dataphyre\core::convert_to_server_date(1633701243, 'America/New_York');
	 * 
	 * // Example 2: Convert date string to server date with custom format
	 * $convertedDate = dataphyre\core::convert_to_server_date('2022-01-01 00:00:00', 'America/New_York', 'Y-m-d');
	 *
	 * @common_pitfalls
	 * 1. Not providing a valid timezone will default to application's default timezone, if that's also invalid,
	 *    the server's timezone will be used.
	 * 2. Providing a non-existing dialback function name will result in the function returning early.
	 */
	public static function convert_to_server_date(string|int $date, string $user_timezone, string $format='n/j/Y g:i A') : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_CONVERT_TO_SERVER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('base_timezone');
		if(in_array($server_timezone, timezone_identifiers_list())){
			if(!in_array($user_timezone, timezone_identifiers_list())){
				$user_timezone=core::get_config('default_timezone');
				if(!in_array($user_timezone, timezone_identifiers_list())){
					$user_timezone=$server_timezone;
				}
			}
			try{
				if(is_numeric($date))$date=date('Y-m-d H:i:s', $date);
				$datetime=new DateTime($date, new DateTimeZone($user_timezone));
				$datetime->setTimezone(new DateTimeZone($server_timezone));
				return $datetime->format($format);
			} catch(Exception $e){
				core::unavailable("DPE-025", "safemode");
			}
		}
		else
		{
			core::unavailable("DPE-024", "safemode");
		}
	}
	
	/**
	 * Adds or updates configuration settings in the CFG store.
	 * This function provides a way to dynamically set or update configuration settings for the Dataphyre project.
	 * You can either provide a key-value pair to add a single configuration or provide an associative array
	 * to add or update multiple configurations at once.
	 *
	 * @package dataphyre\core
	 *
	 * @param string|array $config The configuration key as a string or multiple keys as an associative array.
	 * @param mixed        $value  The value to be set for the given configuration key. Default is null.
	 *
	 * @return bool Returns true if the configuration(s) was successfully added or updated, otherwise returns false.
	 *
	 * @example
	 * // Adding a single configuration:
	 * dataphyre\core::add_config('app_name', 'Dataphyre');
	 * // The CFG store will now have 'app_name' => 'Dataphyre'
	 *
	 * // Adding multiple configurations:
	 * dataphyre\core::add_config(['app_name' => 'Dataphyre', 'version' => '1.0']);
	 * // The CFG store will now have 'app_name' => 'Dataphyre' and 'version' => '1.0'
	 *
	 * @commonpitfalls
	 * - Ensure that the CFG store has been initialized before using this function.
	 * - Be cautious when updating configurations dynamically as it may override existing settings.
	 * - Passing null as the value will result in the function returning false.
	 */
	public static function add_config(string|array $config, mixed $value=null) : bool {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(null!==$early_return=core::dialback('CALL_CORE_ADD_CONFIG',...func_get_args())) return $early_return;
		$cfg=&self::config_store();
		if($value!==null){
			if(!is_string($config)){
				return false;
			}
			$config=self::normalize_config_path($config);
			if($config===''){
				if(is_array($value)){
					$cfg=array_replace_recursive($cfg, self::normalize_config_payload($value));
					return true;
				}
				return false;
			}
			$segments=explode('/', $config);
			if(count($segments)===1){
				$cfg[$segments[0]]=$value;
				return true;
			}
			$wrapped=$value;
			for($index=count($segments)-1; $index>=0; $index--){
				$wrapped=[$segments[$index]=>$wrapped];
			}
			$cfg=array_replace_recursive($cfg, self::normalize_config_payload($wrapped));
			return true;
		}
		else
		{
			if(is_array($config)){
				$cfg=array_replace_recursive($cfg, self::normalize_config_payload($config));
				return true;
			}
			return false;
		}
	}

	/**
	 * Returns the mutable root configuration store by reference.
	 *
	 * Mutating the returned array mutates CFG->raw(), so callers should use this
	 * only for low-level configuration tooling and bootstrap integration.
	 *
	 * @return array<string, mixed> Reference to the complete configuration store.
	 */
	public static function &config_all(): array {
		$cfg=&self::config_store();
		return $cfg;
	}
	
	/**
	 * Retrieves a configuration setting from the CFG store by its key.
	 * This function allows you to fetch a specific configuration setting based on its key index.
	 * If the configuration is nested, you can specify the path using the '/' delimiter.
	 *
	 * @package dataphyre\core
	 *
	 * @param string $index The configuration key or a path to a nested configuration.
	 *
	 * @return mixed Returns the value of the specified configuration key, or the result of a nested lookup.
	 *               Returns null if the specified configuration does not exist.
	 *
	 * @example
	 * // Getting a single-level configuration:
	 * $result = dataphyre\core::get_config('app_name');
	 * // The function will return the value set for 'app_name' in the CFG store.
	 *
	 * // Getting a nested configuration:
	 * $result = dataphyre\core::get_config('settings/version');
	 * // The function will look for the 'version' key inside the 'settings' array in the root CFG store.
	 *
	 * @commonpitfalls
	 * - Ensure that the CFG store is initialized before using this function.
	 * - Attempting to access an undefined configuration index will return null.
	 * - Be cautious when specifying nested configurations; an incorrect path will return null.
	 */
	public static function get_config(string $index): mixed {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(null!==$early_return=core::dialback('CALL_CORE_GET_CONFIG',...func_get_args())) return $early_return;
		$cfg=&self::config_store();
		$index=self::normalize_config_path($index);
		if($index===''){
			return $cfg;
		}
		if(isset(CFG[$index])){
			return CFG[$index];
		}
		else
		{
			$index=explode('/', $index);
			return core::get_config_value($index, $cfg);
		}
	}
	
	/**
	 * Internal method to fetch a nested configuration setting from a given array.
	 * This function is primarily used by the public `get_config()` method to retrieve nested configurations.
	 * It performs recursive lookups to extract the configuration value for the provided key path.
	 *
	 * @package dataphyre\core
	 *
	 * @param array|string $index  The key or array of keys to look for in the configuration array.
	 * @param array        $value  The array containing the configurations to search in.
	 *
	 * @return mixed Returns the value for the specified configuration key, or null if it does not exist.
	 *
	 * @example
	 * // Usage example within get_config()
	 * $result = core::get_config_value(['settings', 'version'], CFG);
	 * // This will return the value of the 'version' key inside the 'settings' array in CFG.
	 *
	 * @commonpitfalls
	 * - This method is intended for internal use. Use the public `get_config()` method for retrieving configurations.
	 * - Passing an empty or null index array or a non-array value will likely result in undefined behavior.
	 */
	private static function get_config_value(array|string $index, array $value): mixed {
		if(null!==$early_return=core::dialback('CALL_CORE_GET_CONFIG_VALUE',...func_get_args())) return $early_return;
		if(is_array($index) && count($index)){
			$current_index=array_shift($index);
		}
		if(is_array($index) && count($index) && isset($value[$current_index]) && is_array($value[$current_index]) && count($value[$current_index])){
			return core::get_config_value($index, $value[$current_index]);
		}
		else
		{
			if(isset($value[$current_index])){
				return $value[$current_index];
			}
		}
		return null;
	}
	
	/**
	 * Checks whether an event has any registered dialback callbacks.
	 *
	 * @param string $event_name Dialback event name.
	 * @return bool True when at least one callback is registered.
	 */
	public static function has_dialback(string $event_name): bool {
		return isset(self::$dialbacks[$event_name]) && self::$dialbacks[$event_name]!==[];
	}

	/**
	 * Returns callbacks registered for one dialback event.
	 *
	 * The returned array is a copy of the event callback list.
	 *
	 * @param string $event_name Dialback event name.
	 * @return array<int, callable> Registered callbacks for the event.
	 */
	public static function dialback_callbacks(string $event_name): array {
		return array_values(self::$dialbacks[$event_name] ?? []);
	}

	/**
	 * Returns the complete dialback registry.
	 *
	 * @return array<string, array<int, callable>> Dialbacks keyed by event name.
	 */
	public static function dialback_all(): array {
		$events=[];
		foreach(self::$dialbacks as $event_name=>$callbacks){
			if(!is_string($event_name)){
				continue;
			}
			$events[$event_name]=array_values(is_array($callbacks) ? $callbacks : []);
		}
		ksort($events);
		return $events;
	}

	/**
	 * Returns all event names present in the dialback registry.
	 *
	 * @return array<int, string> Registered dialback event names.
	 */
	public static function dialback_event_names(): array {
		return array_keys(self::dialback_all());
	}
	
	/**
	 * Generate a random hexadecimal color code.
	 *
	 * This function generates a random color code based on provided or default RGB ranges.
	 * The function also has an option to add a dash at the beginning, generally used for CSS styling.
	 * Dialback functionality is also incorporated in the function.
	 * 
	 * @package dataphyre\core
	 *
	 * @param array{0:int,1:int} $red_range   Inclusive minimum and maximum values for the red component. Defaults to [20, 150].
	 * @param array{0:int,1:int} $green_range Inclusive minimum and maximum values for the green component. Defaults to [50, 175].
	 * @param array{0:int,1:int} $blue_range  Inclusive minimum and maximum values for the blue component. Defaults to [50, 255].
	 * @param bool  $add_dash    Whether to add a dash ('#') at the beginning of the color code. Defaults to true.
	 *
	 * @return string|mixed Returns the randomly generated hexadecimal color code. Could also return early
	 *                      if dialback function CALL_CORE_RANDOM_HEX_COLOR is defined.
	 * 
	 * @example
	 * // Example 1: Generate random color with default settings
	 * $color = dataphyre\core::random_hex_color();
	 * 
	 * // Example 2: Generate random color with custom red range and without dash
	 * $color = dataphyre\core::random_hex_color([50, 100], null, null, false);
	 *
	 * @common_pitfalls
	 * 1. Not specifying the RGB ranges will result in default values being used, which may not suit all use-cases.
	 * 2. Providing invalid range arrays (e.g., non-numeric, out of bounds, etc.) could lead to unpredictable output.
	 */
	public static function random_hex_color(array $red_range=[20,150], array $green_range=[50,175], array $blue_range=[50,255], bool $add_dash=true) : string {
		if(null!==$early_return=core::dialback("CALL_CORE_RANDOM_HEX_COLOR",...func_get_args())) return $early_return;
		$r=rand($red_range[0], $red_range[1]);
		$g=rand($green_range[0], $green_range[1]);
		$b=rand($blue_range[0], $blue_range[1]);
		$c=($r<<16)+($g<< 8)+$b;
		if($add_dash===true){
			$hex='#';
		}
		$hex.=dechex($c);
		return $hex;
	}
	
	/**
	 * Terminates execution through Dataphyre unavailable handling.
	 *
	 * The method logs context, optional exception details, and error classification,
	 * then exits through the configured safemode/unavailable response path.
	 *
	 * @param string $file Source file reporting the unavailable condition.
	 * @param string $line Source line reporting the unavailable condition.
	 * @param string $class Source class reporting the unavailable condition.
	 * @param string $function Source function reporting the unavailable condition.
	 * @param string $error_description Human-readable failure description.
	 * @param string $error_type Failure category.
	 * @param object|null $exception Optional exception object for diagnostics.
	 * @return never
	 */
	public static function unavailable(string $file, string $line, string $class, string $function, string $error_description='unknown', string $error_type='unknown', ?object $exception=null) : never {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(RUN_MODE!=='diagnostic'){
			if(class_exists('dataphyre\internal_module')){
				\dataphyre\internal_module::trigger('unavailable', [
					'error'=>func_get_args(),
					'collect_tracelog'=>true
				], 5);
			}
		}
		log_error("Service unavailability: ".$error_description, $exception ?? new \Exception(json_encode(func_get_args())));
		$error_code=substr(strtoupper(md5($error_description.$error_type.$file.$class.$function)), 0, 8);
		$known_error_conditions=json_decode(file_get_contents($known_error_conditions_file=ROOTPATH['dataphyre']."cache/known_error_conditions.json"),true);
		$known_error_conditions??=[];
		if(!isset($known_error_conditions[$error_code])){
			$known_error_conditions[$error_code]=array(
				"file"=>$file, 
				"class"=>$class, 
				"function"=>$function, 
				"type"=>$error_type, 
				"description"=>$error_description
			);
			core::file_put_contents_forced($known_error_conditions_file, json_encode($known_error_conditions));
		}
		if(RUN_MODE==='diagnostic'){
			if(class_exists('dataphyre\tracelog')){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="<h1>Service unavailability: $error_code ($error_type): $error_description</h1>", $S="fatal");
			}
			exit();
		}
		if(RUN_MODE!=='request'){
			if(class_exists('dataphyre\tracelog')){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="<h1>Service unavailability: $error_code ($error_type): $error_description</h1>", $S="fatal");
			}
		}
		else
		{
			ob_clean(); //Clean the output buffer to make sure no data will be sent to browser prior to the redirect header
			core::set_http_headers();
			$_COOKIE['__Secure-SRV']??='N/A';
			$unavailable_config=is_array(DP_CORE_CFG['core']['unavailable'] ?? null) ? DP_CORE_CFG['core']['unavailable'] : [];
			$unavailable_file=(string)($unavailable_config['file_path'] ?? '');
			if($unavailable_file===''){
				if(defined('ROOTPATH') && !empty(ROOTPATH['dataphyre'])){
					$unavailable_file=(string)ROOTPATH['dataphyre'].'unavailable.php';
				}
				elseif(defined('ROOTPATH') && !empty(ROOTPATH['views'])){
					$unavailable_file=(string)ROOTPATH['views'].'problem.php';
				}
			}
			$unavailable_redirection=(bool)($unavailable_config['redirection'] ?? false);
			$err_string=json_encode(array(
				'err'=>$error_code, 
				'utime'=>microtime(true),
				'srv'=>$_COOKIE['__Secure-SRV'], 
				'@url'=>core::url_self(true)
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if($unavailable_file!=='' && file_exists($unavailable_file)){
				if($unavailable_redirection===false){
					$_GET['err']=urlencode(base64_encode($err_string));
					$_GET['t']=$error_type;
					try{
						extract($GLOBALS);
						require($unavailable_file);
					}catch(\Throwable $e){
						pre_init_error("UNAVAILABLE_FILE_FAILED: Original error: ".$err_string, $e, true);
					}
				}
				else
				{
					header('Location: '.core::url_self().'unavailable?err='.urlencode(base64_encode($err_string)).'&t='.$error_type);
				}
			}
			else
			{
				pre_init_error("Unavailability: ".$error_description, $exception ?? new \Exception(json_encode(func_get_args())), true);
			}
		}
		exit();
	}
	
		/**
		 * Retrieve the current URL of the application.
		 *
		 * This function constructs the URL of the current request, optionally including the full path and query string.
		 * It determines the correct protocol and host, ensuring compatibility with Docker environments and proxies.
		 * The function prioritizes `HTTP_X_FORWARDED_PROTO` for detecting the protocol, then falls back to `HTTPS` or `http`.
		 * Additionally, it removes the `uri` parameter from the query string to prevent unintended parameter leakage.
		 *
		 * @package dataphyre\core
		 *
		 * @param bool $full Whether to include the full URL path and query string. Defaults to false.
		 *
		 * @return string The generated URL of the current request, ensuring proper protocol and host resolution.
		 *
		 * @example
		 * // Example 1: Get the base URL of the current request (e.g., "https://example.com/")
		 * $baseUrl = dataphyre\core::url_self();
		 * 
		 * // Example 2: Get the full URL of the current request, including the path and query string
		 * // e.g., "https://example.com/some/path?foo=bar"
		 * $fullUrl = dataphyre\core::url_self(true);
		 *
		 * @common_pitfalls
		 * 1. **Proxy Handling:** If running behind a proxy that does not set `HTTP_X_FORWARDED_PROTO`, 
		 *    the function may default to `http` even if the request was originally made over `https`.
		 * 2. **Docker Environment Considerations:** In a containerized setup without a domain, 
		 *    `HTTP_HOST` may be missing. The function falls back to `SERVER_NAME` or `'localhost'` to prevent crashes.
		 * 3. **Query Parameter Handling:** The function removes the `uri` parameter from the query string 
		 *    to prevent potential conflicts with internal routing.
		 * 4. **Security Implications:** The function does not sanitize the URL, so avoid using its output 
		 *    directly in security-sensitive contexts without proper validation.
		 */
		public static function url_self(bool $full = false): string {
			static $cache=[];
			if(($cache[$full] ?? null)!==null)return $cache[$full];
			$protocol=$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((($_SERVER['HTTPS'] ?? '')==='on') ? 'https' : 'http');
			$host=$_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
			$request_uri=$_SERVER['REQUEST_URI'] ?? '/';
			if($full){
				$parsed_url=parse_url($request_uri);
				$path=$parsed_url['path'] ?? '/';
				$query_string=$parsed_url['query'] ?? '';
				if(!empty($query_string)){
					parse_str($query_string, $query_string_array);
					unset($query_string_array['uri']);
					$query_string=!empty($query_string_array) ? '?' . http_build_query($query_string_array, '', '&', PHP_QUERY_RFC3986) : '';
				}
				return $cache[$full]=$protocol.'://'.$host.$path.$query_string;
			}
			return $cache[$full]=$protocol.'://'.$host.'/';
		}
	
	/**
	 * Update the query string of a given URL.
	 *
	 * This function takes an existing URL and updates its query string parameters based on the given key-value pairs.
	 * Optionally, it can also remove certain query string parameters.
	 * Dialback functionality is integrated into the function.
	 *
	 * @package dataphyre\core
	 *
	 * @param string       $url    The URL whose query string needs to be updated.
	 * @param array|null   $value  The key-value pairs to be added or updated in the query string.
	 * @param array|null|bool $remove The keys to be removed from the query string. Pass true to remove all.
	 *
	 * @return string|mixed Returns the URL with the updated query string. Could also return early if dialback function CALL_CORE_URL_UPDATED_QUERYSTRING is defined.
	 * 
	 * @example
	 * // Example 1: Update query string with new values
	 * $updatedUrl = dataphyre\core::url_updated_querystring('https://example.com/?a=1', ['b' => 2]);
	 * // Output: 'https://example.com/?a=1&b=2'
	 *
	 * // Example 2: Remove specific keys from query string
	 * $updatedUrl = dataphyre\core::url_updated_querystring('https://example.com/?a=1&b=2', null, ['b']);
	 * // Output: 'https://example.com/?a=1'
	 *
	 * // Example 3: Remove all query parameters
	 * $updatedUrl = dataphyre\core::url_updated_querystring('https://example.com/?a=1&b=2', null, true);
	 * // Output: 'https://example.com/'
	 *
	 * @common_pitfalls
	 * 1. Using null or incorrect types for $value or $remove may result in an unexpected URL structure.
	 * 2. The function does not validate the initial URL, so ensure that a valid URL is passed as the parameter.
	 * 3. Be cautious when removing all query string parameters with $remove=true, as this may lead to unexpected behavior in some cases.
	 */
	public static function url_updated_querystring(string $url, array|null $value=null, array|null|bool $remove=false) : string{
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(null!==$early_return=core::dialback("CALL_CORE_URL_UPDATED_QUERYSTRING",...func_get_args())) return $early_return;
		if(empty($value) && empty($remove))return $url;
		$parsed_url=parse_url($url);
		$parsed_url['query']??='';
		parse_str($parsed_url['query'], $query_string);
		unset($query_string['uri']);
		if(!is_array($value)){
			$value=[];
		}
		if($remove===true){ 
			$query_string=[];
		}
		else
		{
			$query_string=array_merge($query_string, $value);
			if(is_array($remove)){
				foreach($remove as $name){
					unset($query_string[$name]);
				}
			}
		}
		foreach($query_string as $key=>$val){
			$query_string[$key]=htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
		}
		return strtok($url, '?')."?".http_build_query($query_string);
	}
	
	/**
	 * Update the query string of the current URL.
	 * This function retrieves the current URL and updates its query string parameters based on the provided key-value pairs.
	 * Optionally, it can also remove certain query string parameters.
	 * Dialback functionality is integrated into the function.
	 *
	 * @package dataphyre\core
	 *
	 * @param array|null   $value  The key-value pairs to be added or updated in the query string.
	 * @param array|null|bool $remove The keys to be removed from the query string. Pass true to remove all.
	 *
	 * @return string|mixed Returns the current URL with the updated query string. Could also return early if dialback function CALL_CORE_URL_SELF_UPDATED_QUERYSTRING is defined.
	 * 
	 * @example
	 * // Example 1: Update query string of current URL with new values
	 * $updatedUrl = dataphyre\core::url_self_updated_querystring(['b' => 2]);
	 * 
	 * // Example 2: Remove specific keys from query string of current URL
	 * $updatedUrl = dataphyre\core::url_self_updated_querystring(null, ['b']);
	 * 
	 * // Example 3: Remove all query parameters from current URL
	 * $updatedUrl = dataphyre\core::url_self_updated_querystring(null, true);
	 *
	 * @common_pitfalls
	 * 1. Using null or incorrect types for $value or $remove may result in an unexpected URL structure.
	 * 2. Be cautious when removing all query string parameters with $remove=true, as this may lead to unexpected behavior in some cases.
	 * 3. If the current URL contains sensitive data in its query string, take care to properly handle it when updating or removing parameters.
	 */
	public static function url_self_updated_querystring(array|null $value, array|null|bool $remove=false) : string{
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		}
		if(null!==$early_return=core::dialback("CALL_CORE_URL_SELF_UPDATED_QUERYSTRING",...func_get_args())) return $early_return;
		if(empty($value) && empty($remove))return core::url_self(true);
		parse_str($_SERVER['QUERY_STRING'], $query_string);
		unset($query_string['uri']);
		if(!is_array($value)){
			$value=[];
		}
		if($remove===true){ 
			$query_string=[];
		}
		else
		{
			$query_string=array_merge($query_string, $value);
			if(is_array($remove)){
				foreach($remove as $name){
					unset($query_string[$name]);
				}
			}
		}
		foreach($query_string as $key=>$val){
			$query_string[$key]=htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
		}
		return strtok(core::url_self(true), '?')."?".http_build_query($query_string);
	}
	
	/**
	 * Minifies the given buffer, optionally appending a tracelogs popup script if enabled.
	 * 
	 * This method performs minification by removing unnecessary characters from the buffer string. 
	 * It also handles some logging and debug functionalities when `dataphyre\tracelog` class exists.
	 * 
	 * @param string $buffer The input buffer to be minified.
	 * 
	 * @return string Minified version of the input buffer.
	 * 
	 * @example
	 * $input = "<html>    \n    <body></body></html>";
	 * $output = dataphyre\core::buffer_minify($input); 
	 * // $output will be "<html><body></body></html>"
	 * 
	 * @common_pitfalls
	 * 1. Be cautious when using this function in a context where whitespace or comments are significant.
	 * 2. If the private key as set in `dataphyre/private_key` is found in the buffer, the function will return an 'unavailable' page.
	 */
	public static function buffer_minify(mixed $buffer) : mixed {
		if(class_exists('dataphyre\tracelog')){
			$buffer=\dataphyre\tracelog::buffer_callback($buffer);
		}
		if(class_exists('dataphyre\sql')===true){
			$_SESSION['queries_retrieved_from_cache']=0;
			$_SESSION['db_cache_count']=0;
		}
		$minify_enabled=defined('DP_CORE_MINIFY_OVERRIDE')
			? DP_CORE_MINIFY_OVERRIDE
			: (DP_CORE_CFG['core']['minify'] ?? false);
		if($minify_enabled===true){
			$buffer=preg_replace('/(?:(?:^|\s)\/\/(?!\'|").*|\/\*[\s\S]*?\*\/)/m', '', $buffer);
			$buffer=str_replace(["\r\n", "\n", "\r", "\t", '    ', '    '], '', $buffer);
			$buffer=str_replace(['=""', ';">'], ['', '">'], $buffer);
			$buffer=preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $buffer);
			$buffer=preg_replace('/<!--.*?-->/', '', $buffer);
		}
		return $buffer;
	}

	/**
	 * Schedules a recrypt task once per request.
	 *
	 * This helper keeps recrypt scheduling centralized in core so callers do not have to
	 * duplicate request-local "only queue once" bookkeeping. The actual scheduling
	 * implementation stays with the caller.
	 */
	public static function defer_recrypt(string $scope, string|int $identifier, callable $scheduler, string $queue='end') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null);
		if(null!==$early_return=core::dialback("CALL_CORE_DEFER_RECRYPT",...func_get_args())) return $early_return;
		static $scheduled_recrypts=[];
		$key=$scope.':'.(string)$identifier;
		if(isset($scheduled_recrypts[$key])){
			return false;
		}
		$scheduled_recrypts[$key]=true;
		try{
			$scheduler($queue);
		}catch(\Throwable $exception){
			unset($scheduled_recrypts[$key]);
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Failed scheduling recrypt task: '.$exception->getMessage(), $S='warning');
			return false;
		}
		return true;
	}
	
	/**
	 * Encrypts a given string using various encryption methods.
	 * This function is part of the Dataphyre project and is responsible for encrypting data.
	 * The encryption method version to use is determined by the project configuration.
	 *
	 * @package dataphyre\core
	 *
	 * @param string|null $string The string to encrypt. If the string is empty or null, an empty string will be returned.
	 * @param array|null $salting_data An array of salting data to make the encryption more secure. It can be null.
	 * @return string Returns the encrypted string.
	 * 
	 * @example
	 * // Using the latest version of encryption as specified in project configuration
	 * $encrypted = dataphyre\core::encrypt_data('mySecretString', ['salt1', 'salt2']);
	 *
	 * @commonpitfalls
	 * 1. Inconsistent Salting Data: Using different salting data for encryption and decryption will yield incorrect results.
	 * 2. Versioning: The encryption method version is determined by the project configuration; make sure it is set correctly.
	 * 3. Function Return: This function may return an empty string if the input string is empty or null.
	 */
	public static function encrypt_data(#[\SensitiveParameter] ?string $string, #[\SensitiveParameter] ?array $salting_data=[]) : string{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_ENCRYPT_DATA",...func_get_args())) return $early_return;
		if($string==='')return'';
		if(empty($salting_data))$salting_data=['arbitrary_value'];
		$latest_version=DP_CORE_CFG['encryption_version'] ?? 0;
		if($latest_version===0){
			$iv=bin2hex(openssl_random_pseudo_bytes(2));
			$result='0:'.(count(dpvks())-1).':'.$iv.openssl_encrypt($string, "AES-256-CBC", dpvk(), 0, substr(md5(implode('',$salting_data).$iv), 0, 16));
		}
		elseif($latest_version===1){
			// Future proofing
		}
		return $result;
	}

	/**
	 * Decrypts a given encrypted string using various decryption methods.
	 * This function is part of the Dataphyre project and is responsible for decrypting data.
	 * The decryption method to be used is inferred from the format of the input string.
	 * It also includes a deprecation callback for automatic re-encryption using the latest method.
	 *
	 * @package dataphyre\core
	 *
	 * @param string|null $string The encrypted string to decrypt. If the string is empty or null, an empty string will be returned.
	 * @param array|null $salting_data An array of salting data that was used during the encryption. It can be null.
	 * @param callable|null $deprecation_callback A callback function invoked when the encrypted string uses an older version of encryption. This allows for automatic updating of encryption methods.
	 * @return string Returns the decrypted string. If decryption fails, it returns "[DecryptFail]".
	 * 
	 * @example
	 * // Decryption for version 0
	 * $decrypted = dataphyre\core::decrypt_data('encryptedStringV0', ['salt1', 'salt2'], function($newString) {
	 *   // Re-encrypt using the latest method
	 * });
	 *
	 * // Decryption for version 1
	 * $decrypted = dataphyre\core::decrypt_data('encryptedStringV1', ['salt1', 'salt2'], null);
	 *
	 * @commonpitfalls
	 * 1. Incorrect Salting Data: Using different salting data for encryption and decryption will not correctly decrypt the string.
	 * 2. Inconsistent Versioning: Ensure that the encrypted string is generated using the version of the algorithm that the decrypt function expects.
	 * 3. Error Handling: This function returns "[DecryptFail]" for unsuccessful decryption attempts, make sure to handle this case.
	 * 4. Deprecation Callback: Make sure the deprecation callback function correctly re-encrypts the data with the latest encryption method.
	 */
	public static function decrypt_data(#[\SensitiveParameter] ?string $string, #[\SensitiveParameter] ?array $salting_data=[], callable|string|null $deprecation_callback=null) : string{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call;
		if(null!==$early_return=core::dialback("CALL_CORE_DECRYPT_DATA",...func_get_args())) return $early_return;
		$latest_version=DP_CORE_CFG['encryption_version'] ?? 0;
		if($string==='')return'';
		if(empty($salting_data))$salting_data=['arbitrary_value'];
		if(str_contains($string, ":")){
			$exploded=explode(":", $string);
			$version=$exploded[0];
			$string=$exploded[1];
		}
		if(!is_null($version)){
			if($version==='0'){
				$private_keyid=$exploded[1];
				$iv=substr($exploded[2], 0, 4);
				$string=substr($exploded[2], 4);
				$result=openssl_decrypt($string, "AES-256-CBC", dpvks()[$private_keyid], 0, substr(md5(implode('',$salting_data).$iv), 0, 16));
			}
			else
			{
				$result=DP_CORE_CFG['recryption_fallback'] ?? '[RecryptFail]';
			}
		}
		if($latest_version!==$version){
			if($deprecation_callback==='return')return core::encrypt_data($result, $salting_data);
			if($deprecation_callback!==null)$deprecation_callback(core::encrypt_data($result, $salting_data));
		}
		if($result!==null && $result!==false){
			return $result;
		}
		return DP_CORE_CFG['encryption_fallback'] ?? '[DecryptFail]';
	}
	
	/**
	 * Manages CSRF tokens for form protection.
	 * This function is part of the Dataphyre project and is designed to handle the creation and validation
	 * of CSRF tokens associated with different forms to prevent Cross-Site Request Forgery attacks.
	 *
	 * @package dataphyre\core
	 *
	 * @param string $form_name The name of the form for which the CSRF token will be generated or validated.
	 * @param string|null $token The token to validate. If null, a new token for the given form name will be generated.
	 * @return string|bool Returns the CSRF token as a string if $token is null, otherwise returns true if validation is successful, or false otherwise.
	 *
	 * @example
	 * // Generating a CSRF token for a login form
	 * $csrfToken = dataphyre\core::csrf('login_form');
	 *
	 * // Validating a received CSRF token for the login form
	 * $isValid = dataphyre\core::csrf('login_form', 'receivedToken');
	 *
	 * @commonpitfalls
	 * 1. Form Name Missing: Always specify the $form_name when generating or validating tokens, or the function will return false.
	 * 2. Session Issues: Make sure the session is started before calling this function to ensure token storage and retrieval.
	 * 3. Token Reuse: After successful validation, the token is unset. Trying to validate the same token again will result in a false return value.
	 */
	public static function csrf(string $form_name, #[\SensitiveParameter] mixed $token=null) : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S="function_call",$A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_CSRF",...func_get_args()))return$early_return;
		if(!isset($_SESSION['token'][$form_name]) && $token===null){
			$_SESSION['token'][$form_name]=bin2hex(openssl_random_pseudo_bytes(16));
		}
		if($token!==null){
			if(isset($_SESSION['token'][$form_name]) && hash_equals($_SESSION['token'][$form_name], $token)){
				return true;
			}
			return false; 
		}
		return $_SESSION['token'][$form_name]??false;
	}
	
	/**
	 * Writes data to a file, creating any necessary directories.
	 *
	 * This function is part of the Dataphyre project and provides a robust way to write data to a file. 
	 * If the specified directory does not exist, it will be created.
	 *
	 * @package dataphyre\core
	 *
	 * @param string $path The directory path along with the filename where the data should be written.
	 * @param string $contents The data to write to the file.
	 * @return int|bool Returns the number of bytes written to the file, or false on failure.
	 *
	 * @example
	 * // Write data to a file, creating directories if needed
	 * $bytesWritten = dataphyre\core::file_put_contents_forced('/path/to/dir/file.txt', 'Hello, World!');
	 *
	 * @commonpitfalls
	 * 1. Permission Issues: The function may fail if PHP doesn't have enough permissions to create directories or files.
	 * 2. Incorrect Directory Separator: Make sure to use the correct directory separator for the underlying operating system.
	 * 3. Overwriting: The function will overwrite the file if it already exists, so ensure that's the desired behavior.
	 */
	public static function file_put_contents_forced(string $dir, string $contents=''): int|bool {
		if(null!==$early_return=core::dialback("CALL_CORE_FILE_PUT_CONTENTS_FORCED",...func_get_args())) return $early_return;
		$directory=dirname($dir);
		if($directory!=='' && $directory!=='.' && !is_dir($directory)){
			if(!mkdir($directory, 0777, true) && !is_dir($directory)){
				return false;
			}
		}
		if(false!==$bytes=file_put_contents($dir, $contents, LOCK_EX)){
			return $bytes;
		}
		return false;
	}

	/**
	 * Recursively removes a file or directory and its contents.
	 *
	 * This function uses native PHP filesystem operations so it works across
	 * platforms and does not invoke a shell.
	 *
	 * @package dataphyre\core
	 *
	 * @param string $path The file or directory path to be removed.
	 * @return bool True when the path was removed or did not exist.
	 *
	 * @example
	 * // Remove a directory and its contents.
	 * dataphyre\core::force_rmdir('/path/to/directory');
	 *
	 * @commonpitfalls
	 * 1. Permission Issues: The function may fail if PHP does not have enough permissions to remove files.
	 * 2. Data Loss: The function will remove all files and directories under the given path. Use it carefully.
	 */
	public static function force_rmdir(string $path): bool {
		$path=rtrim(trim($path), '/\\');
		if($path===''){
			return false;
		}
		if($path==='/' || $path==='\\' || preg_match('/^[A-Za-z]:$/', $path)===1){
			return false;
		}
		if(!file_exists($path) && !is_link($path)){
			return true;
		}
		if(is_file($path) || is_link($path)){
			return @unlink($path);
		}
		if(!is_dir($path)){
			return false;
		}
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($iterator as $item){
			$item_path=$item->getPathname();
			if($item->isDir() && !$item->isLink()){
				if(!@rmdir($item_path)){
					return false;
				}
				continue;
			}
			if(!@unlink($item_path)){
				return false;
			}
		}
		return @rmdir($path);
	}
	
	/**
	 * Converts a file size to a human-readable storage unit.
	 *
	 * This function is part of the Dataphyre project and converts a given size in bytes to the most appropriate unit (b, kb, mb, gb, tb, pb) for easier readability.
	 *
	 * @package dataphyre\core
	 *
	 * @param int|float $size The file size in bytes.
	 * @return string Returns a human-readable file size with the appropriate unit.
	 *
	 * @example
	 * // Converting bytes to a readable format
	 * $readableSize = dataphyre\core::convert_storage_unit(10240);
	 * // Returns: '10 kb'
	 *
	 * @commonpitfalls
	 * 1. Precision Loss: The function rounds the size to two decimal places, so there might be slight discrepancies in the exact size.
	 * 2. Logarithmic Error: When the input is zero or negative, the function might behave unpredictably.
	 * 3. Overhead: The function uses logarithmic and rounding calculations, which could have performance implications for large data sets.
	 */
	public static function convert_storage_unit(int|float $size): string {
		// DO NOT TRACELOG, RECURSION
		if(!is_finite($size) || $size<=0){
			return '0 b';
		}
		$i=(int)floor(log($size, 1024));
		$i=max(0, min($i, 5));
		return round($size / pow(1024, $i), 2).' '.array('b','kb','mb','gb','tb','pb')[$i];
	}
	
	/**
	 * Returns the client's IP address, trusting forwarded headers only if the source IP is in a trusted list.
	 */
	public static function get_client_ip() : string {
		return self::get_client_ip_details()['ip'];
	}

	/**
	 * Resolves the client IP and explains which source was trusted.
	 *
	 * Forwarded headers are honored only when REMOTE_ADDR matches a configured trusted
	 * proxy or CIDR range. Otherwise REMOTE_ADDR is returned as the client IP.
	 *
	 * @return array{ip:string, remote_addr:string, source:string, source_header:?string, trusted_proxy:bool, trusted_headers:array, trusted_proxies:array} Client IP decision payload.
	 */
	public static function get_client_ip_details(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=null); // Log the function call
		$core_config=DP_CORE_CFG['core']['client_ip_identification'] ?? [];
		$remote_addr=$_SERVER['REMOTE_ADDR'] ?? $core_config['default_ip'] ?? '0.0.0.0';
		$trusted_proxies=$core_config['trusted_proxies'] ?? [];
		$trusted_headers=$core_config['trusted_ip_headers'] ?? [];
		$ip_to_binary=function($ip) {
			$packed=@inet_pton($ip);
			return $packed===false?null:unpack('A*', $packed)[1];
		};
		$cidr_match=function($ip, $cidr)use($ip_to_binary){
			[$subnet, $bits]=explode('/', $cidr);
			$ip_bin=$ip_to_binary($ip);
			$subnet_bin=$ip_to_binary($subnet);
			if($ip_bin===null || $subnet_bin===null || strlen($ip_bin)!==strlen($subnet_bin)){
				return false;
			}
			$bit_count=(int)$bits;
			$byte_len=strlen($subnet_bin);
			$ip_bits='';
			$subnet_bits='';
			for($i=0; $i<$byte_len; $i++){
				$ip_bits.=str_pad(decbin(ord($ip_bin[$i])), 8, '0', STR_PAD_LEFT);
				$subnet_bits.=str_pad(decbin(ord($subnet_bin[$i])), 8, '0', STR_PAD_LEFT);
			}
			return substr($ip_bits, 0, $bit_count)===substr($subnet_bits, 0, $bit_count);
		};
		$ip_in_trusted_list=function($ip)use($trusted_proxies, $cidr_match){
			foreach($trusted_proxies as $trusted){
				if(strpos($trusted, '/')!==false){
					if($cidr_match($ip, $trusted))return true;
				}
				else
				{
					if($ip===$trusted)return true;
				}
			}
			return false;
		};
		$trusted_proxy=$ip_in_trusted_list($remote_addr);
		if($trusted_proxy){
			foreach($trusted_headers as $header){
				if(!empty($_SERVER[$header])){
					$ip_list=explode(',', $_SERVER[$header]);
					$ip=trim($ip_list[0]);
					if(filter_var($ip, FILTER_VALIDATE_IP)){
						return [
							'ip'=>$ip,
							'remote_addr'=>$remote_addr,
							'source'=>'header',
							'source_header'=>$header,
							'trusted_proxy'=>true,
							'trusted_headers'=>$trusted_headers,
							'trusted_proxies'=>$trusted_proxies,
						];
					}
				}
			}
		}
		return [
			'ip'=>$remote_addr,
			'remote_addr'=>$remote_addr,
			'source'=>'remote_addr',
			'source_header'=>null,
			'trusted_proxy'=>$trusted_proxy,
			'trusted_headers'=>$trusted_headers,
			'trusted_proxies'=>$trusted_proxies,
		];
	}

}
