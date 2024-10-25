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


try{
	//Dataphyre common routes
	if($routefile=dataphyre\routing::check_route("/dataphyre/ping", $rootpath['common_dataphyre'])){ die("pong"); }
	if($routefile=dataphyre\routing::check_route("/dataphyre/logs", $rootpath['common_dataphyre']."modules/log_viewer/log_viewer.php")){ require($routefile); exit(); }
	if(file_exists($rootpath['common_dataphyre']."modules/cdn")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/cdn/{filename}",$rootpath['common_dataphyre']."modules/cdn/loader.php")){ $is_task=true; require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/sql")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/sql",$rootpath['common_dataphyre']."modules/sql/endpoint.php")){ $is_task=true; require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/adminer",$rootpath['common_dataphyre']."modules/sql/third_party/adminer/adminer.php")){ $is_task=true;  require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/adminer.css",$rootpath['common_dataphyre']."modules/sql/third_party/adminer/adminer.css")){ $is_task=true;  require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/scheduling")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/scheduler/{scheduler}",$rootpath['common_dataphyre']."modules/scheduling/task_runner.php")){ $is_task=true; require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/tracelog")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/tracelog",$rootpath['common_dataphyre']."modules/tracelog/viewer.php")){ $is_task=true;  require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/tracelog/plotter",$rootpath['common_dataphyre']."modules/tracelog/plotter.php")){ $is_task=true;  require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/dpanel")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/dpanel",$rootpath['common_dataphyre']."modules/dpanel/panel.php")){ $is_task=true;  require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/caspow")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/caspow.js",$rootpath['common_dataphyre']."modules/caspow/caspow.js")){ header('Content-Type: application/javascript'); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/caspow/{action}",$rootpath['common_dataphyre']."modules/caspow/endpoint.php")){ require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/health_report")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/health_report", $rootpath['common_dataphyre']."modules/health_report/index.php")){ require($rootpath['common_dataphyre']."modules/health_report/wrapper.php"); require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/datadoc")){
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc",$rootpath['common_dataphyre']."modules/datadoc/ui/index.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc/dynadoc_menu_processor",$rootpath['common_dataphyre']."modules/datadoc/ui/dynadoc_menu_processor.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc/{project}/",$rootpath['common_dataphyre']."modules/datadoc/ui/project_dashboard.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc/{project}/settings",$rootpath['common_dataphyre']."modules/datadoc/ui/project_settings.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc/{project}/dynadoc",$rootpath['common_dataphyre']."modules/datadoc/ui/dynamic_document.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/dataphyre/datadoc/{project}/manudoc/{documentid}",$rootpath['common_dataphyre']."modules/datadoc/ui/manual_document.php")){ require($rootpath['common_dataphyre']."modules/datadoc/wrapper.php"); require($routefile); exit(); }
	}
	if(file_exists($rootpath['common_dataphyre']."modules/stripe")){
		// Define the following routes under custom routes and load files containing your custom functions for callbacks if need be.
		//if($routefile=dataphyre\routing::check_route("/internal/webhooks/stripe/{platform_accountid}",$rootpath['common_dataphyre']."modules/stripe/webhook.php")){ $is_task=true; require($routefile); exit(); }
		//if($routefile=dataphyre\routing::check_route("/internal/webhooks/stripe",$rootpath['common_dataphyre']."modules/stripe/webhook.php")){ $is_task=true; require($routefile); exit(); }
	}
	// Custom common routes
	if(file_exists($rootpath['common_dataphyre']."modules/stripe")){
		if($routefile=dataphyre\routing::check_route("/internal/webhooks/stripe/{platform_accountid}",$rootpath['common_dataphyre']."modules/stripe/webhook.php")){ $is_task=true; require($rootpath['backend']."wrapper.php"); require($routefile); exit(); }
		if($routefile=dataphyre\routing::check_route("/internal/webhooks/stripe",$rootpath['common_dataphyre']."modules/stripe/webhook.php")){ $is_task=true; require($rootpath['backend']."wrapper.php"); require($routefile); exit(); }
	}
}catch(\Throwable $exception){
	pre_init_error('DataphyreRouting: Fatal error: Unable to load requested ressource ('.$routefile.')', $exception);
}