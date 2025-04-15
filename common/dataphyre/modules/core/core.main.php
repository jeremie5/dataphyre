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
 
tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre Core initializing");

foreach(['core.global.php', 'helper_functions.php', 'language_additions.php', 'core_functions.php'] as $file){
    require(__DIR__.'/'.$file);
}

\dataphyre\core::load_plugins('pre_init');

if(!defined('ROOTPATH')) pre_init_error("ROOTPATH constant not defined");

if(!defined('BS_VERSION')){
	pre_init_error("Dataphyre Bootstrap version unknown");
}
else
{
	if(version_compare(BS_VERSION, $min_bs='1.0.1', '<')){
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
	if(!define('DP_VERIFIED', false)) pre_init_error("Unable to assign DP_VERIFIED constant as false");
}
else
{
	if(!define('DP_VERIFIED', true)) pre_init_error("Unable to assign DP_VERIFIED constant as true");
}

if(!defined('RUN_MODE') || RUN_MODE==='request'){
	if(DP_VERIFIED===true){
		if(!define('RUN_MODE', 'request')) pre_init_error("Unable to assign RUN_MODE constant as request");
	}
	else
	{
		if(!define('DP_VERIFIED', false)) pre_init_error("Dataphyre install must be verified by Dpanel.");
	}
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Run mode is ".RUN_MODE);

if(RUN_MODE==='diagnostic'){
	if(!define('DP_CORE_LOADED', true)) pre_init_error("Unable to assign DP_CORE_LOADED constant");
	require(__DIR__.'/core.diagnostic.php');
	\dataphyre\core\diagnostic::pre_tests();
}

if(!define('REQUEST_USER_AGENT', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown UA')){
	pre_init_error("Unable to assign REQUEST_USER_AGENT constant");
}

if(empty(dpvk())) pre_init_error("Failed initializing DPVK");

if(RUN_MODE==='request'){
	\dataphyre\core::get_server_load_level();
	\dataphyre\core::check_delayed_requests_lock();
	if(!isset($_SESSION)){
		if(\dataphyre\core::$server_load_level===5){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Load shedding as visitor had no session and server load level is above 5", "loadlevel");
		}
	}
}

if(file_exists($file=ROOTPATH['common_dataphyre'].'config/core.php')) require($file);
if(file_exists($file=ROOTPATH['dataphyre'].'config/core.php')) require($file);

global $configurations;

if(!define('REQUEST_IP_ADDRESS', \dataphyre\core::get_client_ip())) pre_init_error("Unable to assign REQUEST_IP_ADDRESS constant");

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Client IP is ".REQUEST_IP_ADDRESS);

if(RUN_MODE==='request' || RUN_MODE==='diagnostic'){
	if(!isset($_SESSION) && $configurations['dataphyre']['core']['php_session']['enabled'] ?? true !==false){
		if(false===ini_set('session.cookie_lifetime', $configurations['dataphyre']['core']['php_session']['lifespan'])
		|| false===ini_set('session.gc_maxlifetime', $configurations['dataphyre']['core']['php_session']['lifespan'])
		|| false===ini_set('session.name', $configurations['dataphyre']['core']['php_session']['cookie']['name'])) pre_init_error("Failed to ini_set() session parameters");
		if($configurations['dataphyre']['core']['php_session']['cookie']['secure']===true){
			if(false===ini_set('session.cookie_httponly', true)
			|| false===ini_set('session.cookie_samesite', 'Strict')
			|| false===ini_set('session.cookie_secure', true)
			|| false===ini_set('session.use_only_cookies', true)) pre_init_error("Failed to ini_set() session parameters");
		}
		if(RUN_MODE==='request'){
			if(session_start()===false) \dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed starting php session', 'safemode');
		}
	}
}

if(false===ini_set('memory_limit', $configurations['dataphyre']['max_execution_memory'] ?? '16M')) pre_init_error("Failed to ini_set() memory_limit");
if(false===ini_set('max_execution_time', $configurations['dataphyre']['max_execution_time'] ?? 30)) pre_init_error("Failed to ini_set() max_execution_time");

if(!@date_default_timezone_set($tz=$configurations['dataphyre']['timezone'])) pre_init_error("Invalid timezone: $tz");

if(RUN_MODE!=='diagnostic'){
	if($mod=dp_module_present('tracelog'))require($mod[0]);
	if($mod=dp_module_present('cache'))require($mod[0]);
	if($mod=dp_module_present('contingency'))require($mod[0]);
	if($mod=dp_module_present('sql'))require($mod[0]);
	if(RUN_MODE==='request'){
		if($mod=dp_module_present('async'))require($mod[0]);
		if($mod=dp_module_present('google_authenticator'))require($mod[0]);
		if($mod=dp_module_present('firewall'))require($mod[0]);
		if($mod=dp_module_present('perfstats'))require($mod[0]);
		if($mod=dp_module_present('country_blocking'))require($mod[0]);
		if($mod=dp_module_present('caspow'))require($mod[0]);
	}
	if($mod=dp_module_present('localization'))require($mod[0]);
	if($mod=dp_module_present('issue'))require($mod[0]);
	if($mod=dp_module_present('scheduling'))require($mod[0]);
	if($mod=dp_module_present('datadoc'))require($mod[0]);
	if($mod=dp_module_present('date_translation'))require($mod[0]);
	if($mod=dp_module_present('currency'))require($mod[0]);
	if($mod=dp_module_present('templating'))require($mod[0]);
	if($mod=dp_module_present('geoposition'))require($mod[0]);
	if($mod=dp_module_present('sanitation'))require($mod[0]);
	if($mod=dp_module_present('stripe'))require($mod[0]);
	if($mod=dp_module_present('fulltext_engine'))require($mod[0]);
	if($mod=dp_module_present('profanity'))require($mod[0]);
	if($mod=dp_module_present('access'))require($mod[0]);
	if($mod=dp_module_present('time_machine'))require($mod[0]);
	if($mod=dp_module_present('supercookie'))require($mod[0]);
	if($mod=dp_module_present('fraudar'))require($mod[0]);
	if($mod=dp_module_present('cdn_server'))require($mod[0]);
	if($mod=dp_module_present('cdn'))require($mod[0]);
}

\dataphyre\core::load_plugins('post_init');

if(RUN_MODE==='request'){
	\dataphyre\core::set_http_headers();
}

unset($mod, $file, $min_bs, $tz, $D, $T, $S);

if(RUN_MODE==='diagnostic'){
	\dataphyre\core\diagnostic::post_tests();
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre has finishined initializing, ".$configurations['dataphyre']['public_app_name']." will now take over");