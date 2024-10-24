<?php 
session_start();
error_reporting(0);
header("server: Dataphyre");

$website_url="https://shopiro.ca/";
$cdn_url="https://cdn.shopiro.ca/";
$cdn_ressource_url="https://cdn.shopiro.ca/res/";

$title='';
$content='';
if($_GET['t']==="maintenance"){
	$httpcode=503;
	$content.='<center><h1 class="phyro-bold" style="font-size:80px"><i>Shopiro</i></h1></center>';
	$content.='<center><p style="font-size:40px">We are currently in maintenance mode</center>';
	$content.='<center><p style="font-size:25px">We are sorry for the inconvenience this may have caused you.</center><br><br>';
	$content.='<center><p>You may find more information regarding this temporary outage below.</center>';
	$content.='<center><a class="button" href="https://status.shopiro.ca/">View Service Status Report</a></center><br><br><br><br><br>';
}
elseif($_GET['t']==="country_blocked"){
	$httpcode=503;
	$title='Information';
	$content.='<center><h1 class="phyro-bold" style="font-size:80px"><i>Shopiro</i></h1></center>';
	$content.='<center><b style="font-size:40px">Shopiro is currently not available in your country.</b></center><br><br><br><br><br>';
}
elseif($_GET['t']==="loadlevel"){
	$httpcode=503;
	$title='Servers Are Currently Overloaded';
	$content.='<center><h1 class="phyro-bold" style="font-size:80px"><i>Shopiro</i></h1></center>';
	$content.='<center><p style="font-size:40px">Our servers are currently at capacity 🙁</center>';
	$content.='<center><p style="font-size:25px">We are sorry for the inconvenience this may have caused you.</center><br>';
	$content.='<center><p style="font-size:25px">We are currently scaling our service so we can handle the demand.</center><br>';
	$content.='<center><p>Please come back later.</center>';
	$content.='<center><a class="button" href="https://status.shopiro.ca/">View Service Status Report</a></center><br><br>';
}
else
{
	$httpcode=503;
	$title='Something Went Wrong';
	$content.='<center><h1 class="phyro-bold" style="font-size:80px"><i>Shopiro</i></h1></center>';
	$content.='<center><p style="font-size:40px">Something went wrong on our end 🙁</center>';
	$content.='<center><p style="font-size:25px">We are sorry for the inconvenience this may have caused you.</center><br><br>';
	$content.='<center><p>You may find more information regarding this temporary outage below.</center>';
	$content.='<center><a class="button" href="https://status.shopiro.ca/">View Service Status Report</a></center><br><br>';
}
if(!empty($httpcode)){
	if($configurations["dataphyre"]["core"]["unavailable"]["use_httpcode"]===true){
		http_response_code($httpcode);
	}
}
echo'<!DOCTYPE html>';
echo'<meta charset="utf-8">';
echo'<title>'.$title.'</title>';
echo'<link href="'.$cdn_ressource_url.'assets/universal/img/favicon-32x32.png" rel="icon" sizes="32x32" type="image/png">';
echo'<link href="'.$cdn_ressource_url.'assets/universal/img/favicon-16x16.png" rel="icon" sizes="16x16" type="image/png">';
echo'<style>body{text-align:center;padding:50px}h1{font-size:50px}body{font:20px Helvetica,sans-serif;color:#333}article{display:block;text-align:left;width:1000px;margin:0 auto}a{color:#dc8100;text-decoration:none}a:hover{color:#333;text-decoration:none}@font-face{font-family:Phyro-Bold;src:url(https://cdn.shopiro.ca/res/assets/universal/fonts/Phyro-Bold.ttf)}.phyro-bold{font-family:Phyro-Bold,sans-serif;font-weight:700;font-style:normal;line-height:1.15;letter-spacing:-.02em;-webkit-font-smoothing:antialiased}.button{background-color:#008CBA;border:none;color:#fff;padding:15px 32px;text-align:center;text-decoration:none;display:inline-block;font-size:16px}</style>';
echo'<article>';
echo $content;
if(!empty($_GET['err'])){
	echo'<center><img src="https://chart.googleapis.com/chart?chs=150x150&cht=qr&chl='.htmlspecialchars(urldecode($_GET['err'])).'"></center><br><br><br>';
	$url=json_decode(base64_decode(urldecode($_GET['err'])), true)['@url'];
	if(!empty($url)){
		if(!isset($_SESSION['unavailable_redirect_attempted'])){
			$_SESSION['unavailable_redirect_attempted']=true;
			echo'<meta http-equiv="Refresh" content="30; url=\''.$url.'\'">';
		}
		else
		{
			echo'<meta http-equiv="Refresh" content="30; url=\''.$website_url.'\'">';
		}
	}
}
echo'<center><p style="font-size:20px">© '.date("Y").' Shopiro Ltd. All Rights Reserved.</p></center>';
echo'</article>';