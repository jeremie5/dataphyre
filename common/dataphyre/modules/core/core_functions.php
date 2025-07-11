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

namespace dataphyre;

use \Datetime;
use \DateTimeZone;

class core {
	
	public static $server_load_level=null; // null or 1 to 5, 5 representing highest server load. Dynamic value. Jérémie Fréreault - Prior to 2024-07-22 
	
	public static $server_load_bottleneck=null; // String cause of resource bottlenecking. Jérémie Fréreault - Prior to 2024-07-22
	
	public static $used_packaged_config=false;
	
	private static $env=[];
	
	public static $dialbacks=[];
	
	public static $display_language="en";
	
	public static function load_plugins(string $type): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Looking for $type plugins");
		foreach(['common_dataphyre', 'dataphyre'] as $plugin_path){
			foreach(glob(ROOTPATH[$plugin_path].'plugins/'.$type.'/*.php') as $plugin){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loading $type plugin at $plugin");
				require($plugin);
			}
		}
	}

	public static function end_client_connection(?string $output=null): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * 
	 * @param string $event_name The name of the event to trigger.
	 * @param mixed ...$data Variable number of arguments to pass to the dialback function.
	 * 
	 * @return mixed Returns the result of the last executed dialback function or `null` if no such event is registered.
	 *
	 * @example
	 * // Assuming 'my_event' has been registered with a dialback
	 * $result = dataphyre\core::dialback('my_event', 'arg1', 'arg2');
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * 
	 * @param string $event_name The name of the event to associate with the dialback function.
	 * @param callable $dialback_function The dialback function to be registered.
	 * 
	 * @return bool Returns `true` if the registration is successful, otherwise triggers an error and enters safemode.
	 *
	 * @example
	 * // Register a function named 'my_callback' for an event named 'my_event'
	 * if (dataphyre\core::register_dialback('my_event', 'my_callback')) {
	 *     echo "Dialback registered successfully.";
	 * }
	 * 
	 * @common_pitfalls
	 * 1. Ensure that the dialback function exists and is callable, otherwise an error will be logged and the application enters safemode.
	 * 2. Event names are case-sensitive, make sure to use the correct case when registering or triggering events.
	 * 3. No validation is done on the event name; avoid using special characters or reserved words.
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
	
	public static function set_http_headers(){
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		}
		global $nonce;
		header_remove("X-Powered-By");
		header("server: Dataphyre");
		header("X-XSS-Protection: 1; mode=block");
		header("X-Frame-Options: deny");
		header("X-Content-Type-Options: nosniff");
		header("X-Permitted-Cross-Domain-Policies: none");
		header("Referrer-Policy: strict-origin-when-cross-origin");
		header("Strict-Transport-Security: max-age=31536000");
		header("Permissions-Policy: autoplay=*");
		header("Upgrade-Insecure-Requests: 1");
		header("Content-Security-Policy: script-src 'self' 'unsafe-inline' 'unsafe-eval' https: blob: data:;");
		header("Nonce: ".$nonce);
	}
	
	/**
	 * Retrieves the server's current load level based on CPU and memory usage.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
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
		$fh=fopen('/proc/meminfo', 'r');
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

	public static function delayed_requests_lock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		if(fopen(ROOTPATH['dataphyre']."delaying_lock", 'w+')===false){
			self::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Failed to create delaying lock", $T="safemode");
		}
	}
	
	public static function delayed_requests_unlock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		if(!unlink(ROOTPATH['dataphyre']."delaying_lock")){
			self::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Failed to remove delaying lock", $T="safemode");
		}
	}

	public static function check_delayed_requests_lock() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	
	public static function minified_font() : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		return "@font-face{font-family:Phyro-Bold;src:url('https://cdn.shopiro.ca/assets/universal/fonts/Phyro-Bold.ttf')}.phyro-bold{font-family:'Phyro-Bold', sans-serif;font-weight:700;font-style:normal;line-height:1.15;letter-spacing:-.02em;-webkit-font-smoothing:antialiased}";
	}
	
	/**
	 * Generates an encrypted password from a given string.
	 * This method is part of the Datahyre project.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		global $configurations;
		$salting_data = array($configurations['dataphyre']['private_key']);
		$shuffle1 = '';
		if (!empty($salting_data[0])) {
			$shuffle1 = str_replace(array(1, 'a', 4, 6, 9, 7, 5), array(5, 1, 9, 7, 6, 4, 'a'), base64_encode($salting_data[0]));
		}
		$shuffle2 = '';
		$key = substr(hash('sha256', $shuffle1 . $shuffle2), 0, 16);
		$password = openssl_encrypt($string, "AES-256-CBC", $key, 0, $key);
		$password = str_replace('=', '', base64_encode($password));
		return $password;
		
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_CORE_GET_PASSWORD",...func_get_args())) return $early_return;
		$salting_data=array($configurations['dataphyre']['private_key']);
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_HIGH_PRECISION_SERVER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('app/base_timezone');
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_CONVERT_TO_USER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('app/base_timezone');
		$valid_timezones=timezone_identifiers_list();
		if(in_array($server_timezone, $valid_timezones)){
			if(!in_array($user_timezone, $valid_timezones)){
				$user_timezone=core::get_config('app/default_timezone');
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_CONVERT_TO_SERVER_DATE",...func_get_args())) return $early_return;
		$server_timezone=core::get_config('app/base_timezone');
		if(in_array($server_timezone, timezone_identifiers_list())){
			if(!in_array($user_timezone, timezone_identifiers_list())){
				$user_timezone=core::get_config('app/default_timezone');
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
	 * Adds or updates configuration settings in the global $configurations array.
	 * This function provides a way to dynamically set or update configuration settings for the Datahyre project.
	 * You can either provide a key-value pair to add a single configuration or provide an associative array
	 * to add or update multiple configurations at once.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param string|array $config The configuration key as a string or multiple keys as an associative array.
	 * @param mixed        $value  The value to be set for the given configuration key. Default is null.
	 *
	 * @return bool Returns true if the configuration(s) was successfully added or updated, otherwise returns false.
	 *
	 * @example
	 * // Adding a single configuration:
	 * dataphyre\core::add_config('app_name', 'Datahyre');
	 * // The global $configurations array will now have 'app_name' => 'Datahyre'
	 *
	 * // Adding multiple configurations:
	 * dataphyre\core::add_config(['app_name' => 'Datahyre', 'version' => '1.0']);
	 * // The global $configurations array will now have 'app_name' => 'Datahyre' and 'version' => '1.0'
	 *
	 * @commonpitfalls
	 * - Ensure that the global $configurations array is defined and accessible before using this function.
	 * - Be cautious when updating configurations dynamically as it may override existing settings.
	 * - Passing null as the value will result in the function returning false.
	 */
	public static function add_config(string|array $config, mixed $value=null) : bool {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		}
		if(null!==$early_return=core::dialback('CALL_CORE_ADD_CONFIG',...func_get_args())) return $early_return;
		global $configurations;
		$configurations??=[];
		if($value!==null){
			$configurations[$config]=$value;
			return true;
		}
		else
		{
			if(is_array($config)){
				$configurations=array_replace_recursive($configurations, $config);
				return true;
			}
			return false;
		}
	}
	
	/**
	 * Retrieves a configuration setting from the global $configurations array by its key.
	 * This function allows you to fetch a specific configuration setting based on its key index.
	 * If the configuration is nested, you can specify the path using the '/' delimiter.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
	 * // The function will return the value set for 'app_name' in the global $configurations array.
	 *
	 * // Getting a nested configuration:
	 * $result = dataphyre\core::get_config('app/settings/version');
	 * // The function will look for the 'version' key inside the 'settings' array, which itself is inside the 'app' array.
	 *
	 * @commonpitfalls
	 * - Ensure that the global $configurations array is defined and accessible before using this function.
	 * - Attempting to access an undefined configuration index will return null.
	 * - Be cautious when specifying nested configurations; an incorrect path will return null.
	 */
	public static function get_config(string $index): mixed {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		}
		if(null!==$early_return=core::dialback('CALL_CORE_GET_CONFIG',...func_get_args())) return $early_return;
		global $configurations;
		if(isset($configurations[$index])){
			return $configurations[$index];
		}
		else
		{
			$index=explode('/', $index);
			return core::get_config_value($index, $configurations);
		}
	}
	
	/**
	 * Internal method to fetch a nested configuration setting from a given array.
	 * This function is primarily used by the public `get_config()` method to retrieve nested configurations.
	 * It performs recursive lookups to extract the configuration value for the provided key path.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param array|string $index  The key or array of keys to look for in the configuration array.
	 * @param array        $value  The array containing the configurations to search in.
	 *
	 * @return mixed Returns the value for the specified configuration key, or null if it does not exist.
	 *
	 * @example
	 * // Usage example within get_config()
	 * $result = core::get_config_value(['app', 'settings', 'version'], $configurations);
	 * // This will return the value of the 'version' key inside the 'settings' array, which is itself inside the 'app' array in $configurations.
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
	 * Set an environment variable within the dataphyre\core class.
	 *
	 * This function allows you to set a value in the static $env array.
	 * It accepts either a string as an index and a value, or an associative array to set multiple values at once.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param string|array $index The key (or keys if array) in the $env array to set.
	 * @param mixed        $value The value to set for the given $index. Ignored if $index is an array.
	 * 
	 * @example
	 * // Example 1: Set a single environment variable
	 * dataphyre\core::set_env('key', 'value');
	 * 
	 * // Example 2: Set multiple environment variables at once
	 * dataphyre\core::set_env(['key1' => 'value1', 'key2' => 'value2']);
	 *
	 * @common_pitfalls
	 * 1. If an array is passed as $index, the $value parameter will be ignored.
	 * 2. Providing a non-string key when $index is a string could lead to unexpected behavior.
	 */
    public static function set_env(string|array $index, mixed $value=null) : void {
        self::$env[$index]=$value;
    }
	
	/**
	 * Retrieve an environment variable from the dataphyre\core class.
	 *
	 * This function allows you to get a value from the static $env array using the given index.
	 * If the index does not exist in the $env array, it returns false.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param mixed $index The key in the $env array to retrieve.
	 *
	 * @return mixed|false Returns the value if the $index exists, otherwise returns false.
	 * 
	 * @example
	 * // Example 1: Retrieve a single environment variable
	 * $value = dataphyre\core::get_env('key');
	 * 
	 * // Example 2: Attempt to retrieve a non-existing key
	 * $value = dataphyre\core::get_env('non_existing_key');  // Will return false
	 *
	 * @common_pitfalls
	 * 1. Querying a non-existing index will return false, which might be confusing if the actual stored value is also false.
	 * 2. Not explicitly checking for the boolean false could lead to incorrect logic in conditionals.
	 */
    public static function get_env(mixed $index) : mixed {
        if(isset(self::$env[$index])){
            return self::$env[$index];
        }
        return false;
    }
	
	/**
	 * Generate a random hexadecimal color code.
	 *
	 * This function generates a random color code based on provided or default RGB ranges.
	 * The function also has an option to add a dash at the beginning, generally used for CSS styling.
	 * Dialback functionality is also incorporated in the function.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param array $red_range   An array containing the minimum and maximum values for the red component. Defaults to [20, 150].
	 * @param array $green_range An array containing the minimum and maximum values for the green component. Defaults to [50, 175].
	 * @param array $blue_range  An array containing the minimum and maximum values for the blue component. Defaults to [50, 255].
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
	
	public static function unavailable(string $file, string $line, string $class, string $function, string $error_description='unknown', string $error_type='unknown', ?object $exception=null) : never {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		}
		if(RUN_MODE!=='diagnostic'){
			if(class_exists('dataphyre\sentinel')){
				\dataphyre\sentinel::trigger('unavailable', [
					'error'=>func_get_args(),
					'collect_tracelog'=>true
				], 5);
			}
			if(class_exists('dataphyre\contingency')){
				if(!defined('IS_CONTINGENCY')){
					\dataphyre\contingency::replay([
						'trigger'=>[
							'type'=>'unavailable',
							'unavailable_file'=>$file,
							'unavailable_line'=>$line,
							'unavailable_class'=>$class,
							'unavailable_function'=>$function,
							'unavailable_message'=>$error_description,
							'unavailable_type'=>$error_type
						],
						'prevent_attempt'=>\dataphyre\contingency::$current_attempt,
						'revoke'=>\dataphyre\contingency::$unassignable
					]);
				}
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
			$err_string=json_encode(array(
				'err'=>$error_code, 
				'µtime'=>microtime(true), 
				'srv'=>$_COOKIE['__Secure-SRV'], 
				'@url'=>core::url_self(true)
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if(file_exists(config("dataphyre/core/unavailable/file_path"))){
				if(config("dataphyre/core/unavailable/redirection")===false){
					$_GET['err']=urlencode(base64_encode($err_string));
					$_GET['t']=$error_type;
					try{
						extract($GLOBALS);
						require(config("dataphyre/core/unavailable/file_path"));
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
		 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
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
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
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
		if(\dataphyre\core::get_config('dataphyre/core/minify')===true){
			$buffer=preg_replace('/(?:(?:^|\s)\/\/(?!\'|").*|\/\*[\s\S]*?\*\/)/m', '', $buffer);
			$buffer=str_replace(["\r\n", "\n", "\r", "\t", '    ', '    '], '', $buffer);
			$buffer=str_replace(['=""', ';">'], ['', '">'], $buffer);
			$buffer=preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $buffer);
			$buffer=preg_replace('/<!--.*?-->/', '', $buffer);
		}
		return $buffer;
	}
	
	/**
	 * Encrypts a given string using various encryption methods.
	 * This function is part of the Datahyre project and is responsible for encrypting data.
	 * The encryption method version to use is determined by the project configuration.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_ENCRYPT_DATA",...func_get_args())) return $early_return;
		global $configurations;
		if($string==='')return'';
		if(empty($salting_data))$salting_data=['arbitrary_value'];
		$latest_version=$configurations['dataphyre']['encryption_version'];
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
	 * This function is part of the Datahyre project and is responsible for decrypting data.
	 * The decryption method to be used is inferred from the format of the input string.
	 * It also includes a deprecation callback for automatic re-encryption using the latest method.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=[]); // Log the function call;
		if(null!==$early_return=core::dialback("CALL_CORE_DECRYPT_DATA",...func_get_args())) return $early_return;
		global $configurations;
		$latest_version=$configurations['dataphyre']['encryption_version'];
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
				$result=$configurations['dataphyre']['recryption_fallback'];
			}
		}
		if($latest_version!==$version){
			if($deprecation_callback==='return')return core::encrypt_data($result, $salting_data);
			if($deprecation_callback!==null)$deprecation_callback(core::encrypt_data($result, $salting_data));
		}
		if($result!==null && $result!==false){
			return $result;
		}
		return $configurations['dataphyre']['encryption_fallback'];
	}
	
	/**
	 * Manages CSRF tokens for form protection.
	 * This function is part of the Datahyre project and is designed to handle the creation and validation
	 * of CSRF tokens associated with different forms to prevent Cross-Site Request Forgery attacks.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S="function_call_with_test",$A=func_get_args()); // Log the function call
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
	 * This function is part of the Datahyre project and provides a robust way to write data to a file. 
	 * If the specified directory does not exist, it will be created.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		$parts=explode('/', $dir);
		$file=array_pop($parts);
		$dir='';
		foreach($parts as $part){
			if(!is_dir($dir.="/$part")){
				mkdir($dir);
			}
		}
		if(false!==$bytes=file_put_contents("$dir/$file", $contents, LOCK_EX)){
			return $bytes;
		}
		return false;
	}

	/**
	 * Recursively removes a directory and its contents.
	 *
	 * This function is part of the Datahyre project and utilizes the `rm -rf` shell command to forcefully remove a directory and its contents.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param string $path The directory path to be removed.
	 * @return void
	 *
	 * @example
	 * // Remove a directory and its contents
	 * dataphyre\core::force_rmdir('/path/to/directory');
	 *
	 * @commonpitfalls
	 * 1. Permission Issues: The function may fail if PHP doesn't have enough permissions to execute shell commands or remove directories.
	 * 2. Security Risks: Using `exec` for file operations can expose the system to risks if not handled carefully.
	 * 3. Data Loss: The function will remove all files and directories under the given path. Use it carefully.
	 */
	public static function force_rmdir(string $path): void {
		if(!empty($path)){
			exec('rm -rf "'.$path.'"');
		}
	}
	
	/**
	 * Converts a file size to a human-readable storage unit.
	 *
	 * This function is part of the Datahyre project and converts a given size in bytes to the most appropriate unit (b, kb, mb, gb, tb, pb) for easier readability.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
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
		return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.array('b','kb','mb','gb','tb','pb')[$i];
	}
	
	/**
	 * Outputs an ASCII art splash screen for the Datahyre project.
	 *
	 * This function is part of the Datahyre project and returns an ASCII art representation of the project name to be used as a splash screen or initialization header.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @return string Returns an ASCII art representation of the Datahyre project name.
	 *
	 * @example
	 * // Display the splash screen
	 * echo dataphyre\core::splash();
	 *
	 * @commonpitfalls
	 * 1. Encoding Issues: The ASCII art may not render properly in all text editors or consoles.
	 * 2. Output Control: Since this function only returns the ASCII art string, it's up to the calling code to manage how it's displayed or stored.
	 */
	public static function splash(int $padding=1): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_SPLASH",...func_get_args())) return $early_return;
		$splash=str_repeat("\t", $padding).'██████╗   ███╗ ████████╗ ███╗  ██████╗ ██╗  ██╗██╗   ██╗██████╗ ███████╗'.PHP_EOL;
		$splash.=str_repeat("\t", $padding).'██╔══██╗ ██║██╗╚══██╔══╝██║██╗ ██╔══██║██║  ██║╚██╗ ██╔╝██╔══██╗██╔════╝'.PHP_EOL;
		$splash.=str_repeat("\t", $padding).'██║  ██║██║  ██║  ██║  ██║  ██║██║████║██║  ██║  ╚╝██║  ██║  ██║███████╗'.PHP_EOL;
		$splash.=str_repeat("\t", $padding).'██║  ██║██║  ██║  ██║  ██║  ██║██║═══╝ ███████║   ██╔╝  ██║████║██╔════╝'.PHP_EOL;
		$splash.=str_repeat("\t", $padding).'██║███╔╝██║  ██║  ██║  ██║  ██║██║     ██║  ██║   ██║   ██║  ██║███████╗'.PHP_EOL;
		$splash.=str_repeat("\t", $padding).'╚═╝╚══╝ ╚═╝  ╚═╝  ╚═╝  ╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝   ╚═╝   ╚═╝  ╚═╝╚══════╝'.PHP_EOL;
		return $splash;
	}

	/**
	 * Returns the client's IP address, trusting forwarded headers only if the source IP is in a trusted list.
	 */
	public static function get_client_ip() : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call_with_test", $A=func_get_args()); // Log the function call
		global $configurations;
		$core_config=$configurations['dataphyre']['core']['client_ip_identification'] ?? [];
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
		if($ip_in_trusted_list($remote_addr)){
			foreach($trusted_headers as $header){
				if(!empty($_SERVER[$header])){
					$ip_list=explode(',', $_SERVER[$header]);
					$ip=trim($ip_list[0]);
					if(filter_var($ip, FILTER_VALIDATE_IP)){
						return $ip;
					}
				}
			}
		}
		return $remote_addr;
	}

}
