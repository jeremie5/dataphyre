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


/*************** START DATAPHYRE  INIT ***************/

if(isset($_GET['tracelog'])){
	define('TRACELOG_BOOT_ENABLE', true);
	if(isset($_GET['plotting'])){
		define('TRACELOG_BOOT_PLOTTING_ENABLE', true);
	}
}

try{
	require_once($rootpath['common_dataphyre']."modules/core/core.main.php");
}catch(\Throwable $exception){
	if(function_exists("log_error")){
		pre_init_error("Failed to initiate Dataphyre", $exception);
	}
	else
	{
		die("Panic: Failed to initiate Dataphyre");
	}
}

/*************** END DATAPHYRE  INIT ***************/

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");
