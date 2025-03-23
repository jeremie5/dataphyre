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
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

define('RUN_MODE', 'sessionless_request');

try{
	if(!isset($rootpath['common_dataphyre'])){
		require($rootpath['dataphyre']."modules/core/core.main.php");
	}
	else
	{
		require($rootpath['common_dataphyre']."modules/core/core.main.php");
	}
}catch(\Throwable $exception){
	pre_init_error('Fatal error: Unable to load dataphyre core. ', $exception);
}

\dataphyre\scheduling::run(
	$name="cdn_server_traversal_gc",
	$filepath=__DIR__.'/traversal_gc.php',
	$frequency=$configurations['dataphyre']['cdn_server']['gc']['traversal_timeout'],
	$timeout=$configurations['dataphyre']['cdn_server']['gc']['traversal_timeout'],
	$memory='32M',
	$dependencies=[],
);

$uri_parts=explode('/', $_REQUEST['uri']);

$allowed_referrers=array(
	"https://shopiro.ca/",
	"https://cs.shopiro.ca/",
	"https://cdn.shopiro.ca/",
	"https://shopiro.us/",
	"https://cs.shopiro.us/",
	"https://cdn.shopiro.us/",
	"https://shopiro.com/",
	"https://cs.shopiro.com/",
	"https://cdn.shopiro.com/"
);

$referrer_is_allowed=true;
//$referrer_is_allowed=false;
if(isset($_SERVER['HTTP_REFERER'])){
	foreach($allowed_referrers as $referrer){
		if(str_contains($_SERVER['HTTP_REFERER'], $referrer)){
			$referrer_is_allowed=true; 
			break;
		}
	}
}

if($uri_parts[0]==='res'){
	if($referrer_is_allowed===true){
		$file_path=substr($_REQUEST['uri'], strlen('res/'));
		if(!empty($file_path)){
			$file_path=$rootpath['dataphyre']."cdn_content/direct/".$file_path;
			$file_path=str_replace('../', '', $file_path); // Mitigation of directory traversal attacks
			if(is_readable($file_path)){
				header('server: DataphyreCDN ('.dataphyre\cdn_server::$cdn_server_name.')');
				header('Access-Control-Allow-Origin: *');
				header('Access-Control-Allow-Methods: GET, OPTIONS');
				header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
				header('Cache-Control: max-age=31536000, immutable');
				header('Expires: '.gmdate('D, d M Y H:i:s', strtotime("+1 year")).' GMT');
				header('pragma: cache');
				header('Content-Type: '.dataphyre\cdn_server\utils::get_mime_type($file_path));
				header('Content-Length: '.filesize($file_path));
				header('Content-Disposition: inline; filename="'.basename($file_path).'"');
				$file=fopen($file_path, 'rb');
				if($file){
					fpassthru($file);
					fclose($file);
					exit();
				}
			}
		}
	}
	dataphyre\cdn_server\error_display::cannot_display_content("Unauthorized", 502);
	exit();
}
elseif($uri_parts[0]==='cdn_api'){
	header('Content-Type: application/json');
	if($_REQUEST['pvk']===$configurations['dataphyre']['private_key']){
		if($_REQUEST['action']==='push'){
			$encryption=(bool)$_REQUEST['encryption']??=false;
			$result=dataphyre\cdn_server\storage_operations::add_content(base64_decode($_REQUEST['origin']), 0, $encryption);
		}
		elseif($_REQUEST['action']==='purge'){ 
			$result=dataphyre\cdn_server\storage_operations::purge_content($_REQUEST['blockid']);
		}
		elseif($_REQUEST['action']==='discard'){
			$result=dataphyre\cdn_server\storage_operations::discard_content($_REQUEST['blockid']);
		}
		else
		{
			$result=["errors"=>"unknown_action"];
		}
		die(json_encode($result));
	}
	die(json_encode(array(
		'status'=>"failed", 
		"errors"=>"bad_private_key"
	)));
}
elseif($uri_parts[0]==='vault'){
	if($referrer_is_allowed===true){
		if(!empty($uri_parts[1])){
			$blockpath=$uri_parts[count($uri_parts)-1];
			if(is_numeric($blockpath)){
				$blockpath=dataphyre\cdn_server\utils::encode_blockpath(dataphyre\cdn_server\utils::blockid_to_blockpath($blockpath));
			}
			$parameters=$_REQUEST;
			dataphyre\cdn_server\content_display::display_file_content($blockpath, $parameters);
		}
		require(__DIR__."/facade.php");
		exit();
	}
}
elseif($uri_parts[0]==='robots.txt'){
	header('server: DataphyreCDN ('.dataphyre\cdn_server::$cdn_server_name.')');
	header('Content-Type: text/plain');
	echo"User-agent: *\n";
	echo"Disallow: /\n";
	exit();
}
elseif($uri_parts[0]==='dataphyre' && $uri_parts[1]==='tracelog'){
	require($rootpath['common_dataphyre']."modules/tracelog/viewer.php");
	exit();
}
elseif($uri_parts[0]==='dataphyre' && $uri_parts[1]==='logs'){
	require($rootpath['common_dataphyre']."modules/log_viewer/log_viewer.php");
	exit();
}
elseif($uri_parts[0]==='traversal_gc'){
	require(__DIR__."/traversal_gc.php");
	exit();
}
else
{
	require(__DIR__."/facade.php");
	exit();
}
require(__DIR__."/facade.php");
exit();