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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Checking if user is country blocked");

if($ipaddress!==$_SESSION['country_blocking_validated']){
	$_SESSION['country_blocking_validated']=$ipaddress;
	if(file_exists($file=$rootpath['common_dataphyre']."config/country_blocks.php")){
		require_once($file);
	}
	if(file_exists($file=$rootpath['dataphyre']."config/country_blocks.php")){
		require_once($file);
	}
	$formatted_ip=ip2long($ipaddress);
	foreach($country_blocks as $range_start=>$range_end){
		if(ip2long($range_start)<=$formatted_ip && ip2long($range_end)>=$formatted_ip){
			unset($_SESSION['country_blocking_validated']);
		}
	}
	unset($country_blocks);
	unset($formatted_ip);
}
if($ipaddress!==$_SESSION['country_blocking_validated']){
	dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreCountryBlocking: User\'s IP address is part of the country block list.', 'country_blocked');
}

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not country blocked");