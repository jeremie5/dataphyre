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