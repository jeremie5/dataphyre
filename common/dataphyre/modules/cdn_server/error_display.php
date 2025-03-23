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

namespace dataphyre\cdn_server;

class error_display{

	public static function cannot_display_content(string $error_string='Unknown error', int $status_code=500) : void {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		ob_clean();
		header_remove();
		$status_code=500; //temporary
		http_response_code($status_code);
		header('Content-Type: text/html');
		header('server: DataphyreCDN ('.\dataphyre\cdn_server::$cdn_server_name.')');
		header('error: '.$error_string);
		$cdn_server_name=\dataphyre\cdn_server::$cdn_server_name;
		echo <<<HTML
			<!DOCTYPE HTML>
			<html>
			<head>
				<meta charset="utf-8">
				<meta name="robots" content="noindex">
				<link href="https://cdn.shopiro.ca/res/assets/img/favicon-32x32.png" rel="icon" sizes="32x32" type="image/png">
				<link href="https://cdn.shopiro.ca/res/assets/img/favicon-16x16.png" rel="icon" sizes="16x16" type="image/png">
				<style>
				@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@500&display=swap');
				@font-face { 
					font-family:Phyro-Bold; src: url('https://cdn.shopiro.ca/res/assets/universal/fonts/Phyro-Bold.ttf'); 
				} 
				.phyro-bold {
				  font-family: 'Phyro-Bold', sans-serif;
				  font-weight: 700;
				  font-style: normal;
				  line-height: 1.15;
				  letter-spacing: -.02em;
				  -webkit-font-smoothing: antialiased;
				}
				body { text-align: center; margin-top: 250px; font-family: Arial, sans-serif; }
				</style>
				<title>Dataphyre CDN</title>
			</head>
			<body>
				<span style='font-size:100px;color:black;' class='phyro-bold'><a href="https://shopiro.ca/" style="text-decoration:none;color:black;"><i>Shopiro</i></a> CDN</span><br>
				<p style='max-width:50%;font-size:20px;font-family:"Roboto",sans-serif;'>
					<center>Failed to display requested content: $error_string</center>
				</p>
				<p>($cdn_server_name)</p>
				<span style='font-size:30px;font-family:"Roboto",sans-serif;'>Powered by</span><br>
				<span style='font-size:60px;' class='phyro-bold'>Dataphyre™</span>
			</body>
			</html>
			HTML;
		exit();
	}	
		
}