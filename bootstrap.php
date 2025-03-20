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

set_time_limit(30);
$bootstrap_config=require __DIR__.'/config.php';
if(in_array($_SERVER['SERVER_ADDR'], ['localhost', '127.0.0.1', '192.168.0.1', '0.0.0.0'])){
	$_SERVER['SERVER_ADDR']=$bootstrap_config['public_ip_address'];
	$_SERVER['SELF_ADDR']=$_SERVER['SERVER_ADDR'].':'.$bootstrap_config['web_server_port'];
}
$rootpath['dataphyre']=__DIR__;
$initial_memory_usage=memory_get_usage();
$_SERVER["REQUEST_TIME_FLOAT"]=microtime(true);
date_default_timezone_set('UTC');
header_remove("X-Powered-By");
header_remove("Server");

function tracelog($filename=null, $line=null, $class=null, $function=null, $text=null, $type=null, $arguments=null){
	if(class_exists('\dataphyre\tracelog', false) && \dataphyre\tracelog::$constructed===true){
		if(\dataphyre\tracelog::$enable===true){
			return \dataphyre\tracelog::tracelog($filename, $line, $class, $function, $text, $type, $arguments);
		}
	}
	else
	{
		$GLOBALS['retroactive_tracelog']??=[];
		$GLOBALS['retroactive_tracelog'][]=[$filename, $line, $class, $function, $text, $type, $arguments, microtime(true), memory_get_usage()];
	}
	if($type==='fatal'){
		log_error('Fatal tracelog: '.$class.'/'.$function.'(): '.$text);
		// Eventuall move to pre_init_error()
	}
	return false;
}

if(isset($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'])){
	$bootstrap_config['app']=$_SERVER['HTTP_X_DATAPHYRE_APPLICATION'];
}
else
{
	if($bootstrap_config['prevent_keyless_direct_access']===true){
		if(!file_exists($file=__DIR__."/direct_access_key")){
			file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
		}
		if(!in_array($_SERVER['HTTP_X_TRAFFIC_SOURCE'], ["haproxy", "internal_traffic"])){
			$key=trim(file_get_contents(__DIR__."/direct_access_key"));
			if(empty($_REQUEST['direct_access_key']) || trim($_REQUEST['direct_access_key'])!==$key){
				http_response_code(403);
				die("<h1>Direct access requires authentication.</h1>");
			}
		}
		else
		{
			if(filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_IP)!==false){
				if(empty($_REQUEST['direct_access_key']) || trim($_REQUEST['direct_access_key'])!==$key){
					http_response_code(403);
					die("<h1>Direct access requires authentication.</h1>");
				}
			}
		}
	}
}
if($bootstrap_config['allow_app_override']===true){
	if(!file_exists($file=__DIR__."/app_override_key")){
		file_put_contents($file, bin2hex(openssl_random_pseudo_bytes(32)));
	}
	if(!empty($_COOKIE['app_override'])){
		$key=file_get_contents(__DIR__."/app_override_key");
		$user_app_cookie=explode(',', $_COOKIE['app_override']);
		if($user_app_cookie[1]===$key){
			$bootstrap_config['app']=$user_app_cookie[0];
		}
	}
	if(!empty($_GET['app_override'])){
		$key=file_get_contents(__DIR__."/app_override_key");
		$user_app_cookie=explode(',', $_GET['app_override']);
		if($user_app_cookie[1]===$key){
			$bootstrap_config['app']=$user_app_cookie[0];
		}
	}
}

unset($user_app_cookie, $key, $file);

function minified_font(){
	return "@font-face{font-family:Phyro-Bold;src:url('https://cdn.shopiro.ca/res/assets/genesis/fonts/Phyro-Bold.ttf')}.phyro-bold{font-family:'Phyro-Bold', sans-serif;font-weight:700;font-style:normal;line-height:1.15;letter-spacing:-.02em;-webkit-font-smoothing:antialiased}";
}

function log_error(string $error, ?object $exception=null){
	global $rootpath;
	$timestamp = gmdate("Y-m-d H:i:s T");
	$log_data='';
	if ($exception !== null) {
		$log_data .= '<div class="card bg-light mb-3">';
		$log_data .= '<div class="card-header">Exception: ' . htmlspecialchars(get_class($exception)) . '</div>';
		$log_data .= '<div class="card-body"><p class="card-text"><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
		$log_data .= '<p class="card-text"><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '</p>';
		$log_data .= '<p class="card-text"><strong>Line:</strong> ' . htmlspecialchars($exception->getLine()) . '</p>';
		$log_data .= '<pre class="card-text bg-dark text-white p-2"><strong>Trace:</strong> ' . htmlspecialchars($exception->getTraceAsString()) . '</pre></div></div>';
	}
	file_put_contents($rootpath['dataphyre']."logs/".gmdate("Y-m-d H:00").".log", "\n".$log_data.strip_tags($error), FILE_APPEND);
	$logfile = $rootpath['dataphyre'] . "logs/" . $log_date=gmdate("Y-m-d H:00") . ".html";
	$new_entry = "<tr><td>" . $timestamp . "</td><td>" . $error.$log_data . "</td></tr><!--ENDLOG-->";
	file_put_contents($logfile, "\n".$new_entry, FILE_APPEND);
}

function pre_init_error(?string $error_message=null, ?object $exception=null){
	while(ob_get_level()!==0){
		ob_end_clean();
	}
	if(isset($error_message)){
		log_error("Pre-init error: ".$error_message, $exception);
	}
	http_response_code(503);
	header('Retry-After: 300');
	header('Content-Type: text/html');
	header('Server: Dataphyre');
	echo'<!DOCTYPE html>';
	echo'<html>';
	echo'<head>';
	echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
	echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
	echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
	echo'<style>'.minified_font().'</style>';
	echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
	echo'</head>';
	echo'<body>';
	echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
	echo'<h3>Dataphyre has encountered a fatal error.</h3>';
	echo'<h3>Error description is available in Dataphyre\'s logs folder under '.gmdate("Y-m-d H:00").".log".' at '.gmdate("H:i:s T").'</h3>';
	echo'</body>';
	echo'</html>';
	exit();
}

try{
	include(__DIR__."/applications/".$bootstrap_config['app']."/application_bootstrap.php");
}catch(\Throwable $exception){
	pre_init_error('Fatal error: Unable to load application bootstrap', $exception);
}
