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
			'client_ip_identification'=>array(
				'default_ip'=>'0.0.0.0',
				'trusted_proxies'=>array(
					'127.0.0.1',
					'::1',
					'192.168.1.0/24',
					'173.245.48.0/20',
					'103.21.244.0/22',
					'103.22.200.0/22',
					'103.31.4.0/22',
					'141.101.64.0/18',
					'108.162.192.0/18',
					'190.93.240.0/20',
					'188.114.96.0/20',
					'197.234.240.0/22',
					'198.41.128.0/17',
					'162.158.0.0/15',
					'104.16.0.0/13',
					'104.24.0.0/14',
					'2400:cb00::/32',
					'2606:4700::/32',
					'2803:f800::/32',
					'2405:b500::/32',
					'2405:8100::/32',
					'2a06:98c0::/29',
					'2c0f:f248::/32',
				),
				'trusted_ip_headers'=>array(
					'HTTP_CF_CONNECTING_IP',
					'HTTP_X_FORWARDED_FOR',
					'HTTP_X_REAL_IP'
				),
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