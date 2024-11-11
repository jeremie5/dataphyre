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
	
	public static $server_load_level=1; // 1 to 5, 5 representing highest server load. Dynamic value. Jérémie Fréreault - Prior to 2024-07-22 
	
	public static $server_load_bottleneck=null; // String cause of resource bottlenecking. Jérémie Fréreault - Prior to 2024-07-22
	
	public static $used_packaged_config=false;
	
	private static $env=[];
	
	public static $dialbacks=[];
	
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
				$result=$function($data);
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
		if(function_exists($dialback_function)){
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
	 * Sets various HTTP security headers for the response.
	 * This method is part of the Datahyre project and is designed to improve the security of web applications.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * @static
	 * 
	 * @global string $nonce A unique cryptographic token used for security.
	 * 
	 * @uses \dataphyre\tracelog Traces the function call if the tracelog class exists.
	 * @uses core::dialback Allows for early return or modification via a dialback mechanism.
	 * 
	 * @return mixed The result of the dialback if it provides an early return, otherwise void.
	 * 
	 * @example
	 *  // In a bootstrap or front-controller file:
	 *  \dataphyre\core::set_http_headers();
	 * 
	 * @commonpitfalls
	 *  1. Ensure this function is called before any output is sent to avoid "headers already sent" errors.
	 *  2. Custom headers set after calling this function may override these settings.
	 *  3. This function sets a strict Content-Security-Policy, which might break inline scripts or styles if not properly accounted for.
	 */
	public static function set_http_headers(){
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		}
		global $nonce;
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
	 * @return int|mixed Returns the server load level (from 0 to 5), or the result of the dialback if it provides an early return.
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
	public static function get_server_load_level() : string {
		if(null!==$early_return=core::dialback("CALL_CORE_GET_SERVER_LOAD_LEVEL",...func_get_args())) return $early_return;
		if(isset(core::$server_load_level)){
			return core::$server_load_level;
		}
		else
		{
			$mem=array_merge(array_filter(explode(" ", explode("\n", (string)trim(shell_exec('free')))[1])));
			$memory_usage=round($mem[2]/$mem[1], 3);
			$cpu_load=sys_getloadavg()[0];
			if($cpu_load>=85){
				$level=5;
				core::$server_load_bottleneck="cpu";
			}
			elseif($memory_usage>=85){
				$level=5;
				core::$server_load_bottleneck="memory";
			}
			else
			{
				$collective_average=($memory_usage+$cpu_load)/2;
				$level=round(($collective_average*5)/100);
			}
			return core::$server_load_level=$level;
		}
	}
	
	/**
	 * Creates a lock file to indicate that delayed requests are being processed.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * @static
	 * 
	 * @global array $rootpath Contains the root paths used within the Datahyre application.
	 * 
	 * @uses log_error Logs errors related to lock file creation.
	 * @uses core::unavailable Handles server unavailability scenarios.
	 * 
	 * @return void
	 * 
	 * @example
	 *  // Lock the system for delayed requests processing
	 *  \dataphyre\core::delayed_requests_lock();
	 * 
	 * @commonpitfalls
	 *  1. Make sure you have write permission to the directory specified in $rootpath['dataphyre'] to avoid failure in lock creation.
	 *  2. Not handling the lock properly can block further delayed requests.
	 *  3. If the lock file is not deleted after use, it may cause false positives in system availability checks.
	 */
	static function delayed_requests_lock() : void {
		global $rootpath;
		if(fopen($rootpath['dataphyre']."delaying_lock", 'w+')===false){
			log_error("Failed to create delaying lock");
			core::unavailable("DPE-003", "safemode");
		}
	}
	
	/**
	 * Removes the lock file indicating that delayed requests are being processed.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * @static
	 * 
	 * @global array $rootpath Contains the root paths used within the Datahyre application.
	 * 
	 * @uses log_error Logs errors related to lock file removal.
	 * @uses core::unavailable Handles server unavailability scenarios.
	 * 
	 * @return void
	 * 
	 * @example
	 *  // Unlock the system after processing delayed requests
	 *  \dataphyre\core::delayed_requests_unlock();
	 * 
	 * @commonpitfalls
	 *  1. Ensure you have write permission to the directory specified in $rootpath['dataphyre'] to avoid failure in lock removal.
	 *  2. Not handling the lock properly can block further delayed requests.
	 *  3. Failure to remove the lock can lead to system unavailability.
	 */
	static function delayed_requests_unlock() : void {
		global $rootpath;
		if(!unlink($rootpath['dataphyre']."delaying_lock")){
			log_error("Failed to remove delaying lock");
			core::unavailable("DPE-003", "safemode");
		}
	}
	
	/**
	 * Checks for the presence of a lock file and waits if it exists, indicating that delayed requests are being processed.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * @static
	 * 
	 * @global array $rootpath Contains the root paths used within the Datahyre application.
	 * 
	 * @uses core::unavailable Handles server unavailability scenarios if the lock file persists.
	 * 
	 * @return void
	 * 
	 * @example
	 *  // Check and wait for the delayed requests lock to be released
	 *  \dataphyre\core::check_delayed_requests_lock();
	 * 
	 * @commonpitfalls
	 *  1. Ensure that this method is used cautiously to avoid waiting unnecessarily, which can block other operations.
	 *  2. A maximum of 15 seconds wait is implemented; make sure delayed requests are processed within this time frame to avoid system unavailability.
	 *  3. Failure to remove the lock from a previous operation can lead to false system unavailability.
	 */
	static function check_delayed_requests_lock() : void {
		global $rootpath;
		$timer=0;
		while($timer<15){
			if(!is_file($rootpath['dataphyre']."delaying_lock")){
				break;
			}
			sleep(1);
			$timer++;
		}
		if(is_file($rootpath['dataphyre']."delaying_lock")){
			core::unavailable("DPE-004", "safemode");
		}
	}
	
	/**
	 * Returns a minified CSS string to load and apply the Phyro-Bold font from a CDN.
	 * This method is part of the Datahyre project.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 * @static
	 * 
	 * @uses tracelog Logs the function call for debugging and traceability.
	 * 
	 * @return string Minified CSS for applying the Phyro-Bold font.
	 * 
	 * @example
	 *  // Get the minified CSS string for Phyro-Bold font
	 *  $cssString = \dataphyre\core::minified_font();  // Output would be the minified CSS
	 * 
	 * @commonpitfalls
	 *  1. The font is loaded from a CDN, make sure the URL is reachable from the client's location.
	 *  2. This function returns the CSS as a string. Make sure to properly inject it into your HTML or CSS file.
	 *  3. Be cautious about Content Security Policy (CSP) settings that might block external font resources.
	 */
	static function minified_font() : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		return "@font-face{font-family:Phyro-Bold;src:url('https://cdn.shopiro.ca/assets/universal/fonts/Phyro-Bold.ttf')}.phyro-bold{font-family:'Phyro-Bold', sans-serif;font-weight:700;font-style:normal;line-height:1.15;letter-spacing:-.02em;-webkit-font-smoothing:antialiased}";
	}
	
	/**
	 * Imports CSV file content into a database table.
	 * This method is part of the Datahyre project.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @static
	 * 
	 * @param string $input_file_path The path to the CSV file to be imported.
	 * @param string $output_table The name of the database table where the CSV data will be inserted.
	 * @param array $fields The list of fields in the table to which the CSV data will be mapped.
	 * 
	 * @uses sql_select To check if the table already has data.
	 * @uses sql_insert To insert new rows into the table.
	 * 
	 * @return bool|void Returns false if the file doesn't exist or is unreadable. Void otherwise.
	 * 
	 * @example
	 *  // Import data from 'data.csv' into 'my_table' with specified fields
	 *  \dataphyre\core::csv_to_db('path/to/data.csv', 'my_table', ['field1', 'field2']);
	 * 
	 * @commonpitfalls
	 *  1. Ensure that the CSV file and the database table have compatible columns and types.
	 *  2. The function only supports ';' as the field delimiter in the CSV.
	 *  3. If the table already has data, this function will not proceed with the import.
	 *  4. Make sure the file path is correct and the file is readable.
	 */
	static function csv_to_db(string $input_file_path, string $output_table, array $fields): bool {
		if(!file_exists($input_file_path) || !is_readable($input_file_path)){
			return false;
		}
		$header=null;
		if(false===sql_select(
			$S="*", 
			$L=$output_table, 
			$F="LIMIT 1"
		)){
			if(($handle=fopen($input_file_path, 'r'))!==false){
				while(($row=fgetcsv($handle, 1000, ';'))!==false){
					if(!$header){
						$header=$row;
					}
					else
					{
						$data=array_combine($header, $row);
						sql_insert(
							$L=$output_table, 
							$F=$fields, 
							$V=array_values($data)
						);
					}
				}
				fclose($handle);
			}
		}
		return true;
	}
	
	/**
	 * Imports CSV file content into a SQLite database.
	 * This method is part of the Datahyre project.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @static
	 * 
	 * @param string $input_file_path The path to the CSV file to be imported.
	 * @param string $output_file_path The path to the SQLite database file where the CSV data will be inserted.
	 * 
	 * @return bool|void Returns false if the input file doesn't exist or is unreadable. Void otherwise.
	 * 
	 * @example
	 *  // Import data from 'data.csv' into 'my_data.db' SQLite database
	 *  \dataphyre\core::csv_to_sqlite('path/to/data.csv', 'path/to/my_data.db');
	 * 
	 * @commonpitfalls
	 *  1. Ensure that the CSV file exists and is readable. The function will return false otherwise.
	 *  2. This function uses ';' as the field delimiter in the CSV.
	 *  3. Ensure that the SQLite database file path is writable.
	 *  4. Exception handling for database operations is minimal; consider adding more robust error handling.
	 */
    static function csv_to_sqlite(string $input_file_path, string $output_file_path) : bool {
        if(!file_exists($input_file_path) || !is_readable($input_file_path)){
            return false;
        }
        $header=null;
        $pdo=new \SQLite3($output_file_path);
        if(($handle=fopen($input_file_path, 'r')) !== false){
            while(($row=fgetcsv($handle, 1000, ';')) !== false){
                if(!$header){
                    $header=$row;
                    $createTableQuery="CREATE TABLE IF NOT EXISTS csv_data (" . implode(", ", array_map(function($el) { return "$el TEXT"; }, $header)) . ")";
                    $pdo->exec($createTableQuery);
                }
                else
				{
                    $data=array_combine($header, $row);
                    $placeholders=str_repeat("?,", count($data) - 1) . "?";
                    $insertQuery="INSERT INTO csv_data (" . implode(", ", $header) . ") VALUES ($placeholders)";
                    try {
                        $stmt=$pdo->prepare($insertQuery);
                        $stmt->execute(array_values($data));
                    } catch(PDOException $e){
                    }
                }
            }
            fclose($handle);
        }
		return true;
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
	static function get_password(#[\SensitiveParameter] string $string) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		global $configurations;
		if(null!==$early_return=core::dialback("CALL_CORE_GET_PASSWORD",...func_get_args())) return $early_return;
		$salting_data = array($configurations['dataphyre']['private_key']);
		$key = substr(hash('sha256', base64_encode($salting_data[0])), 0, 16);
		$password = openssl_encrypt($string, "AES-256-CBC", $key, 0, $key);
		$password = str_replace('=', '', base64_encode($password));
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
	static function high_precision_server_date(string $format='Y-m-d H:i:s.u'): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	static function format_date(string $date, string $format='n/j/Y g:i A', bool $translation=true) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_FORMAT_DATE",...func_get_args())) return $early_return;
		if(is_numeric($date)){
			$date=date('Y-m-d H:i:s', (int)$date);
		}
		$datetime=new DateTime($date);
		$result=$datetime->format($format);
		if($translation===true){
			if(class_exists("dataphyre\date_translation")){
				global $lang;
				$result=date_translation::translate_date($result, $lang, $format);
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
	static function convert_to_user_date(string|int $date, string $user_timezone, string $format='n/j/Y g:i A', bool $translation=true) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
						global $lang;
						$result=date_translation::translate_date($result, $lang, $format);
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
	static function convert_to_server_date(string|int $date, string $user_timezone, string $format='n/j/Y g:i A') : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	static function add_config(string|array $config, mixed $value=null) : bool {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	static function get_config(string $index): mixed {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	static function random_hex_color(array $red_range=[20,150], array $green_range=[50,175], array $blue_range=[50,255], bool $add_dash=true) : string {
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
	 * Handle unavailability or errors in the Datahyre application.
	 *
	 * This function is used to log and handle fatal errors or unavailability situations in the application.
	 * It incorporates various mechanisms for different modes like task mode and dpanel mode.
	 * The function also allows for dialback functionality.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param int|string $errno The error number or code.
	 * @param string     $type  The type or description of the error.
	 * 
	 * @return void|mixed The function may exit the script, display an error, or redirect the user.
	 *                    Could also return early if dialback function CALL_CORE_UNAVAILABLE is defined.
	 *
	 * @example
	 * // Example 1: Trigger an unavailable situation with a generic error code and type
	 * dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Failed aligning fluxes", 'safemode');
	 *
	 * @common_pitfalls
	 * 1. Not providing a valid error code or type could result in a less informative error message.
	 * 2. Inaccurate global variables like $dpanel_mode, $is_task, etc., could lead to unexpected behavior.
	 * 3. Make sure that the configuration for 'dataphyre/core/unavailable/file_path' points to an existing file.
	 */
	public static function unavailable(string $file, string $line, string $class, string $function, string $error_description='unknown', string $error_type='unknown') : void {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		}
		global $is_task;
		global $dpanel_mode;
		global $rootpath;
		$error_code=substr(strtoupper(md5($error_description.$error_type.$file.$class.$function)), 0, 8);
		$known_error_conditions=json_decode(file_get_contents($known_error_conditions_file=$rootpath['dataphyre']."cache/known_error_conditions.json"),true);
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
		if($dpanel_mode===true){
			if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="<h1>FATAL ERROR: $error_code $error_type</h1>", $S="fatal");
			}
			return;
		}
		if($is_task===true){
			if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="<h1>FATAL ERROR: $error_code $error_type</h1>", $S="fatal");
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
			));
			if(file_exists(config("dataphyre/core/unavailable/file_path"))){
				if(config("dataphyre/core/unavailable/redirection")===false){
					$_GET['err']=urlencode(base64_encode($err_string));
					$_GET['t']=$error_type;
					try{
						require(config("dataphyre/core/unavailable/file_path"));
					}catch(\Throwable $e){
						pre_init_error("UNAVAILABLE_FILE_INVALID: Error".$err_string);
					}
				}
				else
				{
					header('Location: '.core::url_self().'unavailable?err='.urlencode(base64_encode($err_string)).'&t='.$error_type);
				}
			}
			else
			{
				pre_init_error($err_string);
			}
		}
		exit();
	}
	
	/**
	 * Retrieve the current URL of the application.
	 *
	 * This function generates the URL of the current request, optionally including the full path and query string.
	 * It takes into account various server variables to determine the correct protocol and host.
	 * Dialback functionality is also integrated in the function.
	 * 
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param bool $full Whether to include the full URL path and query string. Defaults to false.
	 *
	 * @return string|mixed Returns the generated URL. Could also return early if dialback function CALL_CORE_URL_SELF is defined.
	 * 
	 * @example
	 * // Example 1: Get the base URL of the current request
	 * $baseUrl = dataphyre\core::url_self();
	 * 
	 * // Example 2: Get the full URL of the current request including path and query string
	 * $fullUrl = dataphyre\core::url_self(true);
	 *
	 * @common_pitfalls
	 * 1. Relying on this function behind a proxy that doesn't set HTTP_X_FORWARDED_PROTO could result in an incorrect protocol.
	 * 2. The function doesn't sanitize the URL, so be cautious when using its output in security-sensitive contexts.
	 * 3. Using this function for generating callback or return URLs may expose sensitive information if the URL contains query parameters.
	 */
	public static function url_self(bool $full=false) : string {
		if(function_exists('tracelog') && method_exists('dataphyre\tracelog', 'tracelog')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		}
		if(null!==$early_return=core::dialback("CALL_CORE_URL_SELF",...func_get_args())) return $early_return;
		static $cache=[];
		if(isset($cache[$full])){
			return $cache[$full];
		}
		if($full===true && str_contains($_SERVER['QUERY_STRING'], '?')){
			parse_str($_SERVER['QUERY_STRING'], $query_string);
			unset($query_string['uri']);
			$query_string=array_map(function($val){return $cache[$full]=htmlspecialchars($val, ENT_QUOTES, 'UTF-8');}, $query_string);
			$query_string='?'.http_build_query($query_string);
		}
		$protocol=isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http');
		if(!isset($_SERVER['HTTP_HOST'])) return $cache[$full]=$full ? $query_string : '/';
		return $cache[$full]=$protocol.'://'.$_SERVER['HTTP_HOST'].($full ? $_SERVER['REQUEST_URI'] : '/');
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
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		}
		if(null!==$early_return=core::dialback("CALL_CORE_URL_UPDATED_QUERYSTRING",...func_get_args())) return $early_return;
		if(empty($value) && empty($remove))return $url;
		$parsed_url=parse_url($url);
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
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	 * 3. If PHP tags are found in the buffer, the function will return an 'unavailable' page. 
	 */
	public static function buffer_minify(mixed $buffer) : mixed {
		global $initial_memory_usage;
		if(class_exists('dataphyre\tracelog')){
			if(tracelog::$open===true){
				$_SESSION['memory_used']=memory_get_usage();
				$_SESSION['memory_used_peak']=memory_get_peak_usage();
				$_SESSION['defined_user_function_count']=count(get_defined_functions()['user']);
				$_SESSION['exec_time']=microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"];
				$_SESSION['included_files']=count(get_included_files());
				if(tracelog::getPlotting()===true){
					return $buffer."<script>window.open('".core::url_self()."dataphyre/tracelog/plotter', '_blank', 'width=1000, height=1000');</script>";
				}
				return $buffer."<script>window.open('".core::url_self()."dataphyre/tracelog?log=".tracelog::$file."', '_blank', 'width=1000, height=1000');</script>";
			}
		}
		if(class_exists('dataphyre\sql')===true){
			$_SESSION['queries_retrieved_from_cache']=0;
			$_SESSION['db_cache_count']=0;
		}
		if(core::get_config('dataphyre/core/minify')===true){
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=[]); // Log the function call;
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__,$T=null,$S="function_call",$A=func_get_args()); // Log the function call
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
	 * @param string $dir The directory path along with the filename where the data should be written.
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
	public static function file_put_contents_forced(string $dir, string $contents): int|bool {
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
	 * Attempts to correct the syntax of a malformed JSON string.
	 *
	 * This function is part of the Datahyre project and tries to correct unbalanced curly braces `{}` and square brackets `[]` in a JSON string.
	 *
	 * @author Jérémie Fréreault <jeremie@phyro.ca>
	 * @package dataphyre\core
	 *
	 * @param string $json The malformed JSON string that needs syntax correction.
	 * @return string Returns a JSON string with balanced curly braces and square brackets.
	 *
	 * @example
	 * // Correcting a malformed JSON string
	 * $correctedJson = dataphyre\core::attempt_json_syntax_correction('{ "key": [ "value1", "value2" }');
	 * // Returns: '{ "key": [ "value1", "value2" ] }'
	 *
	 * @commonpitfalls
	 * 1. Not a JSON Validator: This function only corrects the balance of curly braces and square brackets. It doesn't validate the JSON.
	 * 2. Incomplete Correction: The function may not correct all types of JSON syntax errors, only unbalanced braces and brackets.
	 * 3. Data Integrity: While the function aims to correct the JSON, there's no guarantee that the corrected JSON will represent the same data structure as intended.
	 */
	static function attempt_json_syntax_correction(string $json): string{
		$curlyDepth=0;
		$bracketDepth=0;
		$result="";
		$length=strlen($json);
		for($i=0; $i<$length; $i++){
			$char=$json[$i];
			if($char==="{"){
				$curlyDepth++;
			}
			if($char==="}"){
				$curlyDepth--;
				if($curlyDepth < 0){
					$result="{".$result;
					$curlyDepth=0; // Reset depth
				}
			}
			if($char==="["){
				$bracketDepth++;
			}
			if($char==="]"){
				$bracketDepth--;
				if($bracketDepth<0){
					$result="[".$result;
					$bracketDepth=0; // Reset depth
				}
			}
			$result.=$char;
		}
		while($curlyDepth>0){
			$result.="}";
			$curlyDepth--;
		}
		while($bracketDepth>0){
			$result.="]";
			$bracketDepth--;
		}
		return $result;
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
	static function force_rmdir(string $path): void {
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
	static function convert_storage_unit(int|float $size): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
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
	static function splash(): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S="function_call", $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_CORE_SPLASH",...func_get_args())) return $early_return;
		return '	██████╗   ███╗ ████████╗ ███╗  ██████╗ ██╗  ██╗██╗   ██╗██████╗ ███████╗
	██╔══██╗ ██║██╗╚══██╔══╝██║██╗ ██╔══██║██║  ██║╚██╗ ██╔╝██╔══██╗██╔════╝
	██║  ██║██║  ██║  ██║  ██║  ██║██║████║██║  ██║  ╚╝██║  ██║  ██║███████╗  
	██║  ██║██║  ██║  ██║  ██║  ██║██║═══╝ ███████║   ██╔╝  ██║████║██╔════╝  
	██║███╔╝██║  ██║  ██║  ██║  ██║██║     ██║  ██║   ██║   ██║  ██║███████╗
	╚═╝╚══╝ ╚═╝  ╚═╝  ╚═╝  ╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝   ╚═╝   ╚═╝  ╚═╝╚══════╝
	';
	}
	
}
