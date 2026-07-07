<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre Core initializing");

if(!defined('CFG')){
	heisenconstant('CFG', fn()=>[]);
}

foreach(['core.global.php', 'helper_functions.php', 'language_additions.php', 'core_functions.php'] as $file){
    require_once(__DIR__.'/'.$file);
}

$critical_core_helpers=['dp_module_present', 'dp_module_required', 'dpvks', 'dpvk'];
$missing_core_helpers=array_values(array_filter(
	$critical_core_helpers,
	static fn(string $function_name): bool => !function_exists($function_name)
));
if($missing_core_helpers!==[]){
	require_once(__DIR__.'/helper_functions.php');
	$missing_core_helpers=array_values(array_filter(
		$critical_core_helpers,
		static fn(string $function_name): bool => !function_exists($function_name)
	));
	if($missing_core_helpers!==[]){
		pre_init_error('Dataphyre core helper functions failed to load: '.implode(', ', $missing_core_helpers));
		return;
	}
}

if(!class_exists('\dataphyre\core', false)){
	require(__DIR__.'/core_functions.php');
}
if(!class_exists('\dataphyre\core', false)){
	pre_init_error('Dataphyre core class failed to load.');
	return;
}

if(!defined('ROOTPATH')) pre_init_error("ROOTPATH constant not defined");

dp_define_core_config('DP_CORE_CFG');

\dataphyre\core::load_plugins('pre_init');

if(!defined('BS_VERSION')){
	pre_init_error("Dataphyre Bootstrap version unknown");
}
else
{
	if(version_compare(BS_VERSION, $min_bs='2.0', '<')){
		pre_init_error("Dataphyre Core is incompatible with Dataphyre Bootstrap version ".BS_VERSION.". Please update to ".$min_bs);
	}
}

if(PHP_INT_SIZE<8){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre requires a 64 bit PHP build for production safety.", $S="warning");
	if(!defined('IS_PRODUCTION') || IS_PRODUCTION===true){
		pre_init_error("64-bit PHP build required in production.");
	}
}

if(!defined('ALLOW_OUTPUT_POSTPROCESSING')){
	if(!define('ALLOW_OUTPUT_POSTPROCESSING', true)) pre_init_error("Unable to assign ALLOW_OUTPUT_POSTPROCESSING constant");
}

if(!file_exists(ROOTPATH['dataphyre']."cache/verified")){
	require_once(__DIR__.'/flight_sheet.php');
	\dataphyre\flight_sheet::install(defined('APP') ? APP : null);
	clearstatcache(true, ROOTPATH['dataphyre']."cache/verified");
}

if(!file_exists(ROOTPATH['dataphyre']."cache/verified")){
	if(!defined('DP_VERIFIED') && !define('DP_VERIFIED', false)) pre_init_error("Unable to assign DP_VERIFIED constant as false");
}
else
{
	if(!defined('DP_VERIFIED') && !define('DP_VERIFIED', true)) pre_init_error("Unable to assign DP_VERIFIED constant as true");
}

if(!defined('RUN_MODE')){
	if(DP_VERIFIED===true){
		if(!define('RUN_MODE', 'request')) pre_init_error("Unable to assign RUN_MODE constant as request");
	}
	elseif(DP_VERIFIED===false){
		$install_error=class_exists('\dataphyre\flight_sheet', false) ? \dataphyre\flight_sheet::last_error() : null;
		pre_init_error("Dataphyre install must be verified or installed from the configured flight sheet.".($install_error ? " ".$install_error : ""));
	}
}
elseif(RUN_MODE==='request' && DP_VERIFIED!==true){
	$install_error=class_exists('\dataphyre\flight_sheet', false) ? \dataphyre\flight_sheet::last_error() : null;
	pre_init_error("Dataphyre install must be verified or installed from the configured flight sheet.".($install_error ? " ".$install_error : ""));
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Run mode is ".RUN_MODE);

if(!define('DP_CORE_LOADED', true)) pre_init_error("Unable to assign DP_CORE_LOADED constant");

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/core.diagnostic.php');
	if(class_exists('\dataphyre\core\diagnostic', false)){
		\dataphyre\core\diagnostic::pre_tests();
	}
}

if(!define('REQUEST_USER_AGENT', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown UA')){
	pre_init_error("Unable to assign REQUEST_USER_AGENT constant");
}

if(empty(dpvk())) pre_init_error("Failed initializing DPVK");

if(RUN_MODE==='request'){
	\dataphyre\core::get_server_load_level();
	\dataphyre\core::check_delayed_requests_lock();
	if(session_status()!==PHP_SESSION_ACTIVE){
		if(\dataphyre\core::$server_load_level===5){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Load shedding as visitor had no session and server load level is above 5", "loadlevel");
		}
	}
}

if(!define('REQUEST_IP_ADDRESS', \dataphyre\core::get_client_ip())) pre_init_error("Unable to assign REQUEST_IP_ADDRESS constant");

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Client IP is ".REQUEST_IP_ADDRESS);

if(RUN_MODE==='request' || RUN_MODE==='diagnostic'){
	$php_session_config=DP_CORE_CFG['core']['php_session'] ?? [];
	$php_session_enabled=(($php_session_config['enabled'] ?? true)!==false);
	if(session_status()!==PHP_SESSION_ACTIVE && $php_session_enabled){
		$session_lifespan=(string)max(60, (int)(
			$php_session_config['lifespan']
			?? $php_session_config['cookie']['lifespan']
			?? DP_CORE_CFG['php_session_lifespan']
			?? 900
		));
		$session_name=(string)($php_session_config['cookie']['name'] ?? 'PHPSESSID');
		$session_ini_ok=
			false!==ini_set('session.cookie_lifetime', $session_lifespan)
			&& false!==ini_set('session.gc_maxlifetime', $session_lifespan)
			&& false!==ini_set('session.name', $session_name);
		if((($php_session_config['cookie']['secure'] ?? true)===true)){
			$session_ini_ok=$session_ini_ok
				&& false!==ini_set('session.cookie_httponly', '1')
				&& false!==ini_set('session.cookie_samesite', 'Strict')
				&& false!==ini_set('session.cookie_secure', '1')
				&& false!==ini_set('session.use_only_cookies', '1');
		}
		if($session_ini_ok!==true){
			if(RUN_MODE==='request'){
				pre_init_error("Failed to ini_set() session parameters");
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='DataphyreCore: Unable to apply PHP session ini parameters in diagnostic mode; continuing without session bootstrap changes.', $S='warning');
			}
		}
		if(RUN_MODE==='request'){
			if(session_status()!==PHP_SESSION_ACTIVE && session_start()===false){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed starting php session', 'safemode');
			}
		}
	}
}

if(!defined('DP_MEMORY_LIMIT_INITIALIZED')){
	$memory_limit_override=getenv('DATAPHYRE_MEMORY_LIMIT');
	$memory_limit=$memory_limit_override!==false && trim((string)$memory_limit_override)!=='' ? (string)$memory_limit_override : (DP_CORE_CFG['max_execution_memory'] ?? '16M');
	$memory_limit_to_bytes=static function(string $value): int {
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
	};
	if(class_exists('dataphyre_flightdeck_debugbar', false)){
		try{
			if(dataphyre_flightdeck_debugbar::enabled()===true){
				dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
				$flightdeck_memory_limit=(string)ini_get('memory_limit');
				$flightdeck_memory_limit_bytes=$memory_limit_to_bytes($flightdeck_memory_limit);
				$memory_limit_bytes=$memory_limit_to_bytes((string)$memory_limit);
				if($flightdeck_memory_limit!=='' && ($flightdeck_memory_limit_bytes<=0 || ($memory_limit_bytes>0 && $flightdeck_memory_limit_bytes>$memory_limit_bytes))){
					$memory_limit=$flightdeck_memory_limit;
				}
			}
		}catch(\Throwable){
		}
	}
	$current_memory_limit=(string)ini_get('memory_limit');
	$current_memory_limit_bytes=$memory_limit_to_bytes($current_memory_limit);
	$target_memory_limit_bytes=$memory_limit_to_bytes((string)$memory_limit);
	if($target_memory_limit_bytes>0 && $target_memory_limit_bytes<=memory_get_usage(true)){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='DataphyreCore: Skipped lowering PHP memory_limit below current request usage.', $S='warning');
	}
	elseif(false===ini_set('memory_limit', $memory_limit)){
		if($current_memory_limit_bytes<=0 || ($target_memory_limit_bytes>0 && $current_memory_limit_bytes>=$target_memory_limit_bytes)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='DataphyreCore: Unable to change PHP memory_limit; continuing with existing limit '.$current_memory_limit.'.', $S='warning');
		}
		else
		{
			pre_init_error("Failed to ini_set() memory_limit");
		}
	}
	if(class_exists('dataphyre_flightdeck_debugbar', false)){
		try{
			if(dataphyre_flightdeck_debugbar::enabled()===true){
				dataphyre_flightdeck_debugbar::apply_configured_memory_limit();
			}
		}catch(\Throwable){
		}
	}
	if(!define('DP_MEMORY_LIMIT_INITIALIZED', true)) pre_init_error("Unable to assign DP_MEMORY_LIMIT_INITIALIZED constant");
}
if(!defined('DP_MAX_EXECUTION_TIME_INITIALIZED')){
	if(false===ini_set('max_execution_time', DP_CORE_CFG['max_execution_time'] ?? 30)) pre_init_error("Failed to ini_set() max_execution_time");
	if(!define('DP_MAX_EXECUTION_TIME_INITIALIZED', true)) pre_init_error("Unable to assign DP_MAX_EXECUTION_TIME_INITIALIZED constant");
}

if(!@date_default_timezone_set($tz=DP_CORE_CFG['timezone'] ?? 'UTC')) pre_init_error("Invalid timezone: $tz");

if(RUN_MODE!=='diagnostic'){
	if($mod=dp_module_present('tracelog')) require($mod[0]);
	if($mod=dp_module_present('cache')) require($mod[0]);
	if($mod=dp_module_present('sql')) require($mod[0]);
	if($mod=dp_module_present('vestra')) require($mod[0]);
	if(RUN_MODE==='request'){
		if($mod=dp_module_present('async')) require($mod[0]);
		if($mod=dp_module_present('google_authenticator')) require($mod[0]);
		if($mod=dp_module_present('firewall')) require($mod[0]);
		if($mod=dp_module_present('perfstats')) require($mod[0]);
		if($mod=dp_module_present('country_blocking')) require($mod[0]);
		if($mod=dp_module_present('caspow')) require($mod[0]);
	}
	if($mod=dp_module_present('localization')) require($mod[0]);
	if($mod=dp_module_present('issue')) require($mod[0]);
	if($mod=dp_module_present('scheduling')) require($mod[0]);
	if($mod=dp_module_present('datadoc')) require_once($mod[0]);
	if($mod=dp_module_present('date_translation')) require($mod[0]);
	if($mod=dp_module_present('currency')) require($mod[0]);
	if($mod=dp_module_present('templating')) require($mod[0]);
	if($mod=dp_module_present('mailer')) require($mod[0]);
	if($mod=dp_module_present('geoposition')) require($mod[0]);
	if($mod=dp_module_present('sanitation')) require($mod[0]);
	if($mod=dp_module_present('stripe')) require($mod[0]);
	if($mod=dp_module_present('fulltext_engine')) require($mod[0]);
	if($mod=dp_module_present('access')) require($mod[0]);
	if($mod=dp_module_present('time_machine')) require($mod[0]);
	if($mod=dp_module_present('supercookie')) require($mod[0]);
	if($mod=dp_module_present('fraudar')) require($mod[0]);
}

\dataphyre\core::load_plugins('post_init');

if(RUN_MODE==='request'){
	\dataphyre\core::set_http_headers();
}

unset($mod, $file, $min_bs, $tz, $D, $T, $S);

if(RUN_MODE==='diagnostic'){
	if(class_exists('\dataphyre\core\diagnostic', false)){
		\dataphyre\core\diagnostic::post_tests();
	}
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre has finishined initializing, ".(DP_CORE_CFG['public_app_name'] ?? 'the application')." will now take over");
