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


$catched_tracelog['errors']='';
$catched_tracelog['info']='';
if(!function_exists('tracelog')){
	function tracelog($a=null, $b=null, $c=null, $d=null, $e=null, $f=null, $g=null, $h=null){
		global $catched_tracelog;
		if(empty($catched_tracelog['info'])){ $catched_tracelog['info']=''; }
		if(empty($catched_tracelog['errors'])){ $catched_tracelog['errors']=''; }
		if(!empty($f)){
			if($g=='warning' || $g=='fatal'){
				$catched_tracelog['errors'].=$f."<br>";
				return;
			}
			$catched_tracelog['info'].=$f."<br>";
		}
	}
}
if(!function_exists('get_tracelog_errors')){
	function get_tracelog_errors(){
		global $catched_tracelog;
		$result=$catched_tracelog;
		$catched_tracelog['errors']='';
		$catched_tracelog['info']='';
		return $result;
	}
}

function authenticator_diagnosis(){
	global $rootpath;
	global $dpanel_mode;
	global $configurations;
	$log='';
	$errors=[];

	if(function_exists("core_diagnosis")){
		$result=core_diagnosis();
		$log.="<div class='ml-4'>".$result['log']."</div>";
		$errors=array_merge_recursive($errors, $result['errors']);
	}

	require_once(__DIR__."/google_authenticator.main.php");
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}
	
	$secret=rand(0,9999999);
	$username=rand(0,9999999);

	$log.="Creating a QR code using API<br>";
	$qr=dataphyre\google_authenticator::get_pairing_image($secret,$username); 
	if(false==$qr){
		array_push($errors, "Failed getting a qr link from API");
	}
	$log.="Resulting QR code (username:$username, secret:$secret):<br>";
	$log.="<img height='100' width='100' src='".$qr."'><br>";
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}

	$log.="Attempting a failed verification using API<br>";
	$verify=dataphyre\google_authenticator::verify($secret,111111);
	if($verify===true){
		$log.="<span class='text-danger'>Verification that was meant to fail has passed</span><br>";
		array_push($errors, "Verification that was meant to fail has passed");
	}
	else
	{
		$log.="<span class='text-success'>Verification has failed as expected</span><br>";
	}
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}

	if(empty($errors)){
		$log.="<span class='text-success'>All tests passed.</span><br>";
	}
	return array("errors"=>$errors, "log"=>$log);
}