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


if(empty($err_string)){
	pre_init_error("NO_ERROR");
	exit();
}

if(!isset($GLOBALS['configurations']["dataphyre"]["core"]["unavailable"])){
	pre_init_error("NO_UNAVAILABLE_CONFIGURATION");
	exit();
}

if(ob_get_length() > 0) {
    ob_clean();
}

if(session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header("server: Dataphyre");

$configurations=$GLOBALS['configurations'];

$show_error_code=true;
$title='';
$content='';
if($_GET['t']==="maintenance"){
	$httpcode=503;
	$content.='<center><p style="font-size:40px">We\'re currently in maintenance mode</center>';
	$content.='<center><p style="font-size:25px">We\'re sorry for the inconvenience this may have caused you.</center><br><br>';
	if(!empty($configurations["dataphyre"]["core"]["unavailable"]["status_url"])){
		$content.='<center><p>You may find more information regarding this temporary outage below.</center>';
		$content.='<center><a class="button" href="'.$configurations["dataphyre"]["core"]["unavailable"]["status_url"].'">View Service Status Report</a></center><br><br><br><br><br>';
	}
}
elseif($_GET['t']==="country_blocked"){
	$httpcode=503;
	$show_error_code=false;
	$title='Information';
	$content.='<center><b style="font-size:40px">'.$configurations['dataphyre']['public_app_name'].' isn\'t currently available in your country.</b></center><br><br><br><br><br>';
}
elseif($_GET['t']==="loadlevel"){
	$httpcode=503;
	$title='Servers Are Currently Overloaded';
	$content.='<center><p style="font-size:40px">Our servers are currently at capacity 🙁</center>';
	$content.='<center><p style="font-size:25px">We\'re sorry for the inconvenience this may have caused you.</center><br>';
	$content.='<center><p style="font-size:25px">We are currently scaling our service so we can handle the demand.</center><br>';
	$content.='<center><p>Please come back later.</center>';
	if(!empty($configurations["dataphyre"]["core"]["unavailable"]["status_url"])){
		$content.='<center><a class="button" href="'.$configurations["dataphyre"]["core"]["unavailable"]["status_url"].'">View Service Status Report</a></center><br><br>';
	}
}
else
{
	$httpcode=503;
	$title='Something Went Wrong';
	$content.='<center><p style="font-size:40px">Something went wrong on our end 🙁</center>';
	$content.='<center><p style="font-size:25px">We\'re sorry for the inconvenience this may have caused you.</center><br><br>';
	if(!empty($configurations["dataphyre"]["core"]["unavailable"]["status_url"])){
		$content.='<center><p>You may find more information regarding this temporary outage below.</center>';
		$content.='<center><a class="button" href="'.$configurations["dataphyre"]["core"]["unavailable"]["status_url"].'">View Service Status Report</a></center><br><br>';
	}
}
if(is_integer($httpcode)){
	if($configurations["dataphyre"]["core"]["unavailable"]["use_httpcode"]===true){
		if(headers_sent()===false){
			http_response_code($httpcode);
		}
	}
}
echo'<!DOCTYPE html>';
echo'<meta charset="utf-8">';
echo'<title>'.$title.'</title>';
echo'<link href="'.$configurations["dataphyre"]["core"]["unavailable"]["icon_32px"].'" rel="icon" sizes="32x32" type="image/png">';
echo'<link href="'.$configurations["dataphyre"]["core"]["unavailable"]["icon_16px"].'" rel="icon" sizes="16x16" type="image/png">';
echo'<style>body{text-align:center;padding:50px}h1{font-size:50px}body{font:20px Helvetica,sans-serif;color:#333}article{display:block;text-align:left;width:1000px;margin:0 auto}a{color:#dc8100;text-decoration:none}a:hover{color:#333;text-decoration:none}.button{background-color:#008CBA;border:none;color:#fff;padding:15px 32px;text-align:center;text-decoration:none;display:inline-block;font-size:16px}</style>';
echo'<style>'.minified_font().'</style>';
echo'<article>';
echo'<center><h1 class="'.$configurations["dataphyre"]["core"]["unavailable"]["font_name"].'" style="font-size:80px"><i>'.$configurations['dataphyre']['public_app_name'].'</i></h1></center>';
echo $content;
if(!empty($_GET['err'])){
	if($show_error_code){
		echo'<center><img src="https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl='.htmlspecialchars(urldecode($_GET['err'])).'"></center><br><br><br>';
		$url=json_decode(base64_decode(urldecode($_GET['err'])), true)['@url'];
		if(!empty($url)){
			if(!isset($_SESSION['unavailable_redirect_attempted'])){
				$_SESSION['unavailable_redirect_attempted']=true;
				echo'<meta http-equiv="Refresh" content="30; url=\''.$url.'\'">';
			}
			else
			{
				echo'<meta http-equiv="Refresh" content="30; url=\''.dataphyre\core::url_self(true).'\'">';
			}
		}
	}
}
echo'<center><p style="font-size:20px">© '.date("Y").' '.$configurations["dataphyre"]["core"]["unavailable"]["copyright_notice"].'</p></center>';
echo'</article>';