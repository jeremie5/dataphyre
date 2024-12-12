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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_error_handler(function(...$args){ return;}, E_ALL);

foreach(['helper_functions.php', 'language_additions.php', 'core_functions.php'] as $file){
    require(__DIR__.'/'.$file);
}

\dataphyre\core::load_plugins('pre_init');

if(!defined('RUN_MODE')){
	if(!define('RUN_MODE', 'request')){
		pre_init_error("Unable to assign constant RUN_MODE constant");
	}
}
else
{
	if(RUN_MODE==='diagnostic'){
		define('DP_CORE_LOADED', true);
		require(__DIR__.'/core.diagnostic.php');
		\dataphyre\core\diagnostic::pre_tests();
	}
	else
	{
		if(!file_exists($rootpath['dataphyre']."cache/verified")){
			pre_init_error('Failed verification of dataphyre install.');
		}
	}
}

if(!isset($rootpath['common_dataphyre'])){
	$rootpath['common_dataphyre']=$rootpath['dataphyre'];
}

if(!define('REQUEST_IP_ADDRESS', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0')){
	pre_init_error("Unable to assign constant REQUEST_IP_ADDRESS constant");
}

if(!define('REQUEST_USER_AGENT', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown UA')){
	pre_init_error("Unable to assign constant REQUEST_USER_AGENT constant");
}

$modcache=[];
$modcache_file=$rootpath['dataphyre']."modcache.php";
if(filemtime($modcache_file)+300>time()){
	$modcache=require($modcache_file);
}

if(empty(dpvk())){ //Initializes private key
	pre_init_error("Failed initializing DPVK");
}

if(RUN_MODE==='request'){
	\dataphyre\core::get_server_load_level();
	\dataphyre\core::check_delayed_requests_lock();
	if(!isset($_SESSION)){
		if(\dataphyre\core::$server_load_level===5){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Load shedding as visitor had no session and server load level is above 5", "loadlevel");
		}
	}
}

if(file_exists($file=$rootpath['common_dataphyre'].'config/core.php'))require($file);
if(file_exists($file=$rootpath['dataphyre'].'config/core.php'))require($file);

if(RUN_MODE==='request' || RUN_MODE==='diagnostic'){
	if(!isset($_SESSION) && $configurations['dataphyre']['core']['php_session']['enabled']!==false){
		ini_set('session.cookie_lifetime', $configurations['dataphyre']['core']['php_session']['lifespan']);
		ini_set('session.gc_maxlifetime', $configurations['dataphyre']['core']['php_session']['lifespan']);
		ini_set('session.name', $configurations['dataphyre']['core']['php_session']['cookie']['name']);
		if($configurations['dataphyre']['core']['php_session']['cookie']['secure']===true){
			ini_set('session.cookie_httponly', true);
			ini_set('session.cookie_samesite', 'Strict');
			ini_set('session.cookie_secure', true);
			ini_set('session.use_only_cookies', true);
		}
		if(RUN_MODE==='request'){
			if(session_start()===false){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed starting php session', 'safemode');
			}
		}
	}
}

date_default_timezone_set($configurations['dataphyre']['timezone']);

ini_set('memory_limit',$configurations['dataphyre']['max_execution_memory']);

ini_set('max_execution_time', $configurations['dataphyre']['max_execution_time']);

if(RUN_MODE==='request'){
	if($configurations['dataphyre']['force_https_for_non_headless']!==false){
		if(!str_starts_with($_SERVER['REQUEST_URI'], "https")){
			//dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Non headless execution requires the use of HTTPS.', 'safemode');
		}
	}
}
	
if(RUN_MODE==='headless'){
	if($configurations['dataphyre']['force_https_for_headless']!==false){
		if(!str_starts_with($_SERVER['REQUEST_URI'], "https")){
			//dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Headless execution requires the use of HTTPS.', 'safemode');
		}
	}
}

if(RUN_MODE!=='diagnostic'){

	if($mod=dp_module_present('tracelog')){
		require($mod[0]);
		new \dataphyre\tracelog();
		if($enable_tracelog===true){
			\dataphyre\tracelog::$enable=true;	
			if($enable_tracelog_plotting===true){
				\dataphyre\tracelog::setPlotting(true);
			}
		}
	}

	if($mod=dp_module_present('cache')){
		require($mod[0]);
		new \dataphyre\cache();
	}

	if($mod=dp_module_present('contingency')){
		require($mod[0]);
		\dataphyre\contingency::set_handler();
		function attempt($a=null){ return \dataphyre\contingency::attempt($a);}
		function end_attempt(){return \dataphyre\contingency::end_attempt();}
		function define_optional($a=null,$b=null){return \dataphyre\contingency::define_optional($a,$b);}
	}
	else
	{
		function attempt($a){return true;}
		function end_attempt(){return false;}
		function define_optional($a, $b){return false;}
	}

	if($mod=dp_module_present('sql')){
		require($mod[0]);
		new \dataphyre\sql();
		function sql_count($a=null,$b=null,$c=null, $d=null, $e=null, $f=null){return dataphyre\sql::db_count($a,$b,$c,$d,$e,$f);}
		function sql_select($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null,$h=null){return dataphyre\sql::db_select($a,$b,$c,$d,$e,$f,$g,$h);}
		function sql_delete($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){return dataphyre\sql::db_delete($a,$b,$c,$d,$e,$f);}
		function sql_update($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null){return dataphyre\sql::db_update($a,$b,$c,$d,$e,$f,$g);}
		function sql_insert($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){return dataphyre\sql::db_insert($a,$b,$c,$d,$e,$f);}
		function sql_query($a=null,$b=null,$c=null,$d=null,$e=null,$f=null, $g=null, $h=null){return dataphyre\sql::db_query($a,$b,$c,$d,$e,$f,$g,$h);}
	}

	if(RUN_MODE==='request'){
		if($mod=dp_module_present('async'))require($mod[0]);
		if($mod=dp_module_present('google_authenticator'))require($mod[0]);
		if($mod=dp_module_present('firewall'))require($mod[0]);
		if($mod=dp_module_present('perfstats'))require($mod[0]);
		if($mod=dp_module_present('country_blocking'))require($mod[0]);
		if($mod=dp_module_present('caspow'))require($mod[0]);
	}

	if($mod=dp_module_present('scheduling'))require($mod[0]);
	if($mod=dp_module_present('datadoc'))require($mod[0]);
	if($mod=dp_module_present('date_translation'))require($mod[0]);
	if($mod=dp_module_present('currency'))require($mod[0]);
	if($mod=dp_module_present('templating'))require($mod[0]);
	if($mod=dp_module_present('geoposition'))require($mod[0]);
	if($mod=dp_module_present('cdn'))require($mod[0]);
	if($mod=dp_module_present('sanitation'))require($mod[0]);
	if($mod=dp_module_present('stripe'))require($mod[0]);
	if($mod=dp_module_present('fulltext_engine'))require($mod[0]);
	if($mod=dp_module_present('profanity'))require($mod[0]);
	if($mod=dp_module_present('access'))require($mod[0]);
	if($mod=dp_module_present('time_machine'))require($mod[0]);
	if($mod=dp_module_present('supercookie'))require($mod[0]);
	if($mod=dp_module_present('fraudar'))require($mod[0]);
	if($mod=dp_module_present('cdn_server'))require($mod[0]);

}

\dataphyre\core::load_plugins('post_init');

if(RUN_MODE==='request'){
	\dataphyre\core::set_http_headers();
}

if(is_array($retroactive_tracelog)){
	if(class_exists('\dataphyre\tracelog')){
		if(\dataphyre\tracelog::$enable===true){
			foreach(array_reverse($retroactive_tracelog) as $log){
				\dataphyre\tracelog::tracelog(...$log);
			}
		}
	}
}

unset($retroactive_tracelog, $mod, $plugin, $file, $modcache_file);

if(RUN_MODE==='diagnostic'){
	\dataphyre\core\diagnostic::post_tests();
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre has finishined initializing, ".$configurations['dataphyre']['public_app_name']." will now take over");