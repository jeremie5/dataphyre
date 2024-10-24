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

dataphyre\core::add_config(array(
	'dataphyre'=>array(
		'public_app_name'=>"example_app",
		'datacenter'=>"",
		'timezone'=>"UTC",
		'force_https_for_non_headless'=>true,
		'force_https_for_headless'=>false,
		'max_execution_time'=>5,
		'max_execution_memory'=>"16M",
		'encryption_version'=>0,
		'encryption_fallback'=>'[DecryptFail]',
		'recryption_fallback'=>'[RecryptFail]',
		'core'=>array(
			'php_session'=>array(
				'enabled'=>True,
				'cookie'=>array(
					'name'=>'__Secure-SID',
					'secure'=>true,
					'lifespan'=>1440
				)
			),
			'unavailable'=>array(
				'icon_32px'=>'',
				'icon_16px'=>'',
				'copyright_notice'=>'',
				'status_url'=>'',
				'font_name'=>'phyro-bold',
				'redirection'=>false,
				'use_httpcode'=>false,
				'file_path'=>$rootpath['common_dataphyre']."unavailable.php"
			),
			'minify'=>true,
		)
	)
));