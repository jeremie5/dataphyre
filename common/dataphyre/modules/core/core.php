<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Helper functions
function dp_modcache_save(): void {
	global $modcache, $modcache_file;
	$cache_data='<?php return '.var_export($modcache, true).';';
	file_put_contents($modcache_file, $cache_data);
}

function dp_module_present(string $module): string|bool {
	global $rootpath, $modcache;
	if(!is_array($modcache))$modcache=[];
	if(isset($modcache[$module]))return $modcache[$module];
	$p=$rootpath['dataphyre']."modules/$module/";
	$c=$rootpath['common_dataphyre']."modules/$module/$module.main.php";
	$modcache[$module]=file_exists($p."$module.main.php")?$p."$module.main.php":(!file_exists($p."-$module/")&&file_exists($c)?$c:false);
	dp_modcache_save();
	return $modcache[$module];
}

function dp_module_required(string $module, string $required_module): void {
	global $rootpath;
	if(!dp_module_present($required_module)){
		pre_init_error("Module '$module' requires module '$required_module'");
	}
}

function dpvks(): array {
	global $configurations;
	global $rootpath;
	if(false!=$keys=file_get_contents($rootpath['dataphyre']."config/static/dpvk")){
		return explode(",", $keys);
	}
	if(isset($configurations['dataphyre']['private_key'])){
		return $configurations['dataphyre']['private_key'];
	}
	pre_init_error("Failed getting private keys");
}

function dpvk(): string {
	global $configurations;
	if(!isset($configurations['dataphyre']['private_key'])){
		$keys=dpvks();
		$configurations['dataphyre']['private_key']=$keys[count($keys)-1];
	}
	return $configurations['dataphyre']['private_key'];
}
// End helper functions

set_error_handler(function(...$args){ return;}, E_ALL);

if(empty($rootpath)){
	pre_init_error("Rootpaths are not defined");
}

if(!isset($rootpath['common_dataphyre'])){
	$rootpath['common_dataphyre']=$rootpath['dataphyre'];
}

if(!defined('RUN_MODE') && !define('RUN_MODE', 'request')){
	pre_init_error("Unable to assign constant RUN_MODE constant");
}
	
if(!define('REQUEST_IP_ADDRESS', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0')){
	pre_init_error("Unable to assign constant REQUEST_IP_ADDRESS constant");
}
$ipaddress=REQUEST_IP_ADDRESS; // Temporary

if(!define('REQUEST_USER_AGENT', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown UA')){
	pre_init_error("Unable to assign constant REQUEST_USER_AGENT constant");
}
$useragent=REQUEST_USER_AGENT; // Temporary

$modcache=[];
$modcache_file=$rootpath['dataphyre']."modcache.php";
if(filemtime($modcache_file)+300>time()){
	$modcache=require($modcache_file);
}

foreach(['language_additions.php', 'core_functions.php'] as $file){
    $file_path=__DIR__.'/'.$file;
    if(file_exists($file_path)){
        require($file_path);
    }
	else
	{
        pre_init_error("DataphyreCore: $file is missing? Install is likely corrupted.");
    }
}

if(empty(dpvk())){ //Initializes private key
	pre_init_error("Failed initializing DPVK");
}

if(RUN_MODE==='request'){
	dataphyre\core::get_server_load_level();
	dataphyre\core::check_delayed_requests_lock();
	if(!isset($_SESSION)){
		if(dataphyre\core::$server_load_level===5){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, "Load shedding as visitor had no session and server load level is above 5", "loadlevel");
		}
	}
}

if(file_exists($file=$rootpath['common_dataphyre'].'config/core.php'))require($file);
if(file_exists($file=$rootpath['dataphyre'].'config/core.php'))require($file);

if(empty($configurations['dataphyre'])){
	pre_init_error('DataphyreCore: Core configuration is missing? Install is likely corrupted.');
}

if(!file_exists($rootpath['dataphyre']."cache/verified") && dataphyre\core::verify_install()===false){
	pre_init_error('Failed verification of dataphyre install. Please view log in failed_verfication.txt');
}

if(RUN_MODE==='request'){
	if(!isset($_SESSION) && $configurations['dataphyre']['core']['php_session']['enabled']!==false){
		if(ini_set('session.cookie_lifetime', $configurations['dataphyre']['core']['php_session']['lifespan'])===false){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie lifespan using ini_set()', $T='safemode');
		}
		if(ini_set('session.gc_maxlifetime', $configurations['dataphyre']['core']['php_session']['lifespan'])===false){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie max lifetime using ini_set()', $T='safemode');
		}
		if(ini_set('session.name', $configurations['dataphyre']['core']['php_session']['cookie']['name'])===false){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie name using ini_set()', $T='safemode');
		}
		if($configurations['dataphyre']['core']['php_session']['cookie']['secure']===true){
			if(ini_set('session.cookie_httponly', true)===false){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie httponly using ini_set()', $T='safemode');
			}
			if(ini_set('session.cookie_samesite', 'Strict')===false){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie strict using ini_set()', $T='safemode');
			}
			if(ini_set('session.cookie_secure', true)===false){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session cookie cookie secure using ini_set()', $T='safemode');
			}
			if(ini_set('session.use_only_cookies', true)===false){
				dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed to override php\'s session to use only cookies using ini_set()', $T='safemode');
			}
		}
		if(session_start()===false){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed starting php session', 'safemode');
		}
	}
}

if(!isset($configurations['dataphyre'])){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: No configurations. Make sure $configurations is accessible globally.', 'safemode');
}

if(!date_default_timezone_set($configurations['dataphyre']['timezone'])){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed setting php\'s timezone according to dataphyre configuration.', 'safemode');
}

if(ini_set('memory_limit',$configurations['dataphyre']['max_execution_memory'])===false){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed setting maximum execution memory.', 'safemode');
}

if(ini_set('max_execution_time', $configurations['dataphyre']['max_execution_time'])===false){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Failed setting maximum execution time.', 'safemode');
}

if(version_compare(PHP_VERSION, $ver='8.1.0')<=0){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: PHP version is too old for Dataphyre, at least '.$ver.' is required.', 'safemode');
}

if(RUN_MODE==='request'){
	if($configurations['dataphyre']['force_https_for_non_headless']!==false){
		if(!str_starts_with(dataphyre\core::url_self(), "https")){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Non headless execution requires the use of HTTPS.', 'safemode');
		}
	}
}
	
if(RUN_MODE==='headless'){
	if($configurations['dataphyre']['force_https_for_headless']!==false){
		if(!str_starts_with(dataphyre\core::url_self(), "https")){
			dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCore: Headless execution requires the use of HTTPS.', 'safemode');
		}
	}
}

// Logs will be visible in tracelog from here on out

if($file=dp_module_present('tracelog'))require($file);
if($enable_tracelog===true){
	dataphyre\tracelog::$enable=true;	
	if($enable_tracelog_plotting===true){
		dataphyre\tracelog::setPlotting(true);
	}
}
function tracelog($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null){
	if(dp_module_present('tracelog')){
		if(dataphyre\tracelog::$enable===true){
			return dataphyre\tracelog::tracelog($filename, $line, $class, $function, $text, $type, $arguments);
		}
	}
	if($type==='fatal'){
		log_error('Fatal tracelog: '.$class.'/'.$function.'(): '.$text);
	}
	return false;
}

if($file=dp_module_present('cache')){
	require($file);
	new dataphyre\cache();
}

if($file=dp_module_present('contingency')){
	require($file);
	dataphyre\contingency::set_handler();
	function attempt($a=null){ 
		return dataphyre\contingency::attempt($a);
	}
	function end_attempt(){ 
		return dataphyre\contingency::end_attempt();
	}
	function define_optional($a=null,$b=null){ 
		return dataphyre\contingency::define_optional($a,$b);
	}
}
else
{
	function attempt($a){ 
		return true;
	}
	function end_attempt(){ 
		return false;
	}
	function define_optional($a, $b){
		return false;
	}
}

if($file=dp_module_present('sql')){
	require($file);
	new dataphyre\sql();
}

if(RUN_MODE==='request'){
	if($file=dp_module_present('async'))require($file);
	if($file=dp_module_present('google_authenticator'))require($file);
	if($file=dp_module_present('firewall'))require($file);
	if($file=dp_module_present('perfstats'))require($file);
	if($file=dp_module_present('country_blocking'))require($file);
	if($file=dp_module_present('caspow'))require($file);
}

if($file=dp_module_present('scheduling'))require($file);
if($file=dp_module_present('datadoc'))require($file);
if($file=dp_module_present('date_translation'))require($file);
if($file=dp_module_present('currency'))require($file);
if($file=dp_module_present('templating'))require($file);
if($file=dp_module_present('geoposition'))require($file);
if($file=dp_module_present('cdn'))require($file);
if($file=dp_module_present('sanitation'))require($file);
if($file=dp_module_present('stripe'))require($file);
if($file=dp_module_present('fulltext_engine'))require($file);
if($file=dp_module_present('profanity'))require($file);
if($file=dp_module_present('access'))require($file);
if($file=dp_module_present('time_machine'))require($file);
if($file=dp_module_present('supercookie'))require($file);
if($file=dp_module_present('fraudar'))require($file);
if($file=dp_module_present('cdn_server'))require($file);

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Looking for plugins");
foreach(glob($rootpath['common_dataphyre'].'plugins/*.php') as $plugin){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loading plugin at $plugin");
    require($plugin);
}
foreach(glob($rootpath['dataphyre'].'plugins/*.php') as $plugin){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loading plugin at $plugin");
    require($plugin);
}

if(RUN_MODE==='request'){
	dataphyre\core::set_http_headers();
}

unset($file);
unset($plugin);

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Dataphyre has finishined initializing, ".$configurations['dataphyre']['public_app_name']." will now take over");