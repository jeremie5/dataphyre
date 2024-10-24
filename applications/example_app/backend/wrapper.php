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

/*************** DATAPHYRE  INIT ***************/
$enable_tracelog_plotting=false;
$enable_tracelog=false;
if(isset($_GET['tracelog'])){
	$enable_tracelog=true;
	if(isset($_GET['plotting'])){
		$enable_tracelog_plotting=true;
	}
}

try{
	require_once($rootpath['common_dataphyre']."core.php");
}catch(\Throwable $exception){
	if(function_exists("log_error")){
		pre_init_error("Failed to initiate Dataphyre", $exception);
	}
	else
	{
		die("Panic: Failed to initiate Dataphyre");
	}
}

new dataphyre\access;

if(RUN_MODE==='request'){
	tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Starting output buffer");
	ob_start(function($buffer){
		global $dont_show_copyright;
		$_SESSION['listing_retrieved_from_cache']=0;
		if($dont_show_copyright!==true){
			if(function_exists("source_copyright")){
				return source_copyright().dataphyre\core::buffer_minify($buffer);
			}
		}
		return dataphyre\core::buffer_minify($buffer);	
	});
}

if(isset($_GET['tracelog']) && $_GET['tracelog']==config("dataphyre/tracelog/password")){
	dataphyre\tracelog::$enable=true;
	dataphyre\tracelog::$open=true;
}
else
{
	dataphyre\tracelog::$enable=false;
}

/********************************************/

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");
