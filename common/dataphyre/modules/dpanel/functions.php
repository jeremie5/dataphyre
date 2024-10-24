<?php
/*************************************************************************
* 2020-2022 Shopiro Ltd.
* All Rights Reserved.
* 
* NOTICE: All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

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

function check_php_file_syntax($file){
	$code=file_get_contents($file);
	$old=ini_set('display_errors', 1);
	try{
		token_get_all("\n$code", TOKEN_PARSE);
	}
	catch(Throwable $ex){
		$error=$ex->getMessage();
		$line=$ex->getLine()-1;
		$error="Line $line:\n\n$error";
	}
	finally{
		ini_set('display_errors', $old);
	}
	if(!empty($error)){
		return $error;
	}
	return true;
}

function json_validator($file){
	$json=file_get_contents($file);
	json_decode($json,true);
	switch(json_last_error()){
		case JSON_ERROR_NONE:
			return true;
		break;
		case JSON_ERROR_DEPTH:
			return ' - Maximum stack depth exceeded';
		break;
		case JSON_ERROR_STATE_MISMATCH:
			return ' - Underflow or the modes mismatch';
		break;
		case JSON_ERROR_CTRL_CHAR:
			return ' - Unexpected control character found';
		break;
		case JSON_ERROR_SYNTAX:
			return ' - Syntax error, malformed JSON';
		break;
		case JSON_ERROR_UTF8:
			return ' - Malformed UTF-8 characters, possibly incorrectly encoded';
		break;
		default:
			return ' - Unknown error';
		break;
	}
}

function core_diagnosis(){
	global $rootpath;
	global $configurations;
	$log='';
	$errors=[];
	if(function_exists("check_php_file_syntax")){
		if(false!==$error=check_php_file_syntax($rootpath['common_dataphyre']."core_functions.php")){
			$log.="No syntax issue with ".$rootpath['common_dataphyre']."core_functions.php<br>";
			if(false!==$error=check_php_file_syntax(__DIR__."/sql.main.php")){
				$log.="No syntax issue with ".__DIR__."/sql.main.php<br>";
			}
			else
			{
				$log.="<span class='text-danger'>".__DIR__."/sql.main.php has a syntax issue preventing module testing: ".$error."</span><br>";
				array_push($errors, __DIR__."/sql.main.php has a syntax issue preventing module testing: ".$error);
				return array("errors"=>$errors, "log"=>$log);
			}
		}
		else
		{
			$log.="<span class='text-danger'>".$rootpath['common_dataphyre']."core_functions.php has a syntax issue preventing module testing: ".$error."</span><br>";
			array_push($errors, $rootpath['common_dataphyre']."core_functions.php has a syntax issue preventing module testing: ".$error);
			return array("errors"=>$errors, "log"=>$log);
		}
	}
	else
	{
		$log.="<span class='text-danger'>Skipped integrity testing since missing required function.</span><br>";
	}
	$dpanel_mode=true;
	require_once($rootpath['common_dataphyre']."core_functions.php");
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}
	if(file_exists($rootpath['common_dataphyre']."config/core.php")){
		require_once($rootpath['common_dataphyre']."config/core.php");
		$catched_tracelog=get_tracelog_errors();
		if(!empty($catched_tracelog['info'])){
			$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
		}
		if(!empty($catched_tracelog['errors'])){
			$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
		}
	}
	if(file_exists($rootpath['dataphyre']."config/core.php")){
		require_once($rootpath['dataphyre']."config/core.php");
		$catched_tracelog=get_tracelog_errors();
		if(!empty($catched_tracelog['info'])){
			$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
		}
		if(!empty($catched_tracelog['errors'])){
			$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
		}
	}
	if(empty($configurations['dataphyre'])){
		$log.="<span class='text-danger'>dp_Core No configuration available</span><br>";
		array_push($errors, "dp_Core No configuration available");
	}
	if(empty($errors)){
		$log.="<span class='text-success'>DataphyreCore: All tests passed.</span><br>";
	}
	return array("errors"=>$errors, "log"=>$log);
}

function getSubDirectories($dir){
	$subDir = array();
	$directories = array_filter(glob($dir), 'is_dir');
	$subDir = array_merge($subDir, $directories);
	foreach ($directories as $directory) $subDir = array_merge($subDir, getSubDirectories($directory.'/*'));
	return $subDir;
}

function full_diagnosis(){
	global $rootpath;
	$log='';
	$errors=[];
	$log.='<span class="text-success">Running full project diagnosis...</span><br>';
	
	if(version_compare(PHP_VERSION, '8.0.0')>=0){
		$log.='<span class="text-success">PHP version is compatible with Dataphyre: PHP '.php_sapi_name().' '.PHP_VERSION."</span><br>";
	}
	else
	{
		$log.='<span class="text-danger">PHP version is incompatible with Dataphyre ('.PHP_VERSION.'). Cannot continue diagnosis</span><br>';
		array_push($errors, "PHP version is incompatible with Dataphyre");
		return array("log"=>$log, "errors"=>$errors);
	}
	
	if(!empty($rootpath)){
		$log.='<span class="text-success">Rootpaths appear properly configured</span><br>';
	}
	else
	{
		$log.='<span class="text-danger">Rootpaths not defined. Cannot continue diagnosis</span><br>';
		array_push($errors, "Rootpaths not defined. Cannot continue diagnosis");
		return array("log"=>$log, "errors"=>$errors);
	}
	
	if(file_exists($rootpath['dataphyre']."modules/routing")){
		if(!empty(dataphyre\routing::$page)){
			$log.='<span class="text-success">dp_Routing is properly reporting active page</span><br>';
		}
		else
		{
			$log.='<span class="text-danger">dp_Routing is not properly reporting active page</span><br>';
			array_push($errors, "Routing module is properly reporting active page");
		}
	}
	
	if(ini_get("opcache.enable")=="1"){
		$log.='<span class="text-success">PHP opcache is enabled</span><br>';
	}
	else
	{
		$log.='<span class="text-danger">PHP opcache is not enabled</span><br>';
	}
	
	if(date_default_timezone_get()){
		$log.='PHP timezone: '.date_default_timezone_get()."<br>";
	}
	else
	{
		$log.='<span class="text-danger">Failed getting default php timezone</span><br>';
		array_push($errors, "Failed getting default php timezone");
	}
	
	$project_folders=getSubDirectories(rtrim($rootpath['common_root'], '/'));

	$filecount=0;
	$unreadable_php=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.php');
		foreach($php_files as $file){
			$filecount++;
			if(!is_readable($file)){
				$log.='<span class="text-danger">Unable to read PHP file '.$file.'</span><br>';
				array_push($errors, "Unable to read PHP file ".$file);
				$unreadable_php++;
			}
		}
	}
	$log.='Done checking filesystem permission issues for PHP files in project:<br>';
	if($unreadable_php>0){
		$log.='&emsp;<span class="text-danger">'.$unreadable_php.' files cannot be read. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	$log.='Searching for possible compilation issues...<br>';
	$syntax_error_php=0;
	$filecount=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.php');
		foreach($php_files as $file){
			$filecount++;
			if(is_readable($file)){
				if(true!==$error=check_php_file_syntax($file)){
					$log.='<span class="text-danger">PHP file syntax error: '.$file.': '.$error.'</span><br>';
					array_push($errors, "PHP file error: ".$file.": ".$error);
					$syntax_error_php++;
				}
			}
		}
	}
	$log.='Done checking PHP files in this project for compilation issues:<br>';
	if($syntax_error_php>0){
		$log.='&emsp;<span class="text-danger">'.$syntax_error_php.' files have syntax errors and will fail compilation. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	$filecount=0;
	$unreadable_html=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.html');
		foreach($php_files as $file){
			$filecount++;
			if(!is_readable($file)){
				$log.='<span class="text-danger">Unable to read HTML file '.$file.'</span><br>';
				array_push($errors, "Unable to read HTML file ".$file);
				$unreadable_html++;
			}
		}
	}
	$log.='Done checking filesystem permission issues for HTML files in project:<br>';
	if($unreadable_html>0){
		$log.='&emsp;<span class="text-danger">'.$unreadable_html.' files cannot be read. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	$unreadable_xml=0;
	$filecount=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.xml');
		foreach($php_files as $file){
			$filecount++;
			if(!is_readable($file)){
				$log.='<span class="text-danger">Unable to read XML file '.$file.'</span><br>';
				array_push($errors, "Unable to read XML file ".$file);
				$unreadable_xml++;
			}
		}
	}
	$log.='Done checking filesystem permission issues for XML files in project:<br>';
	if($unreadable_xml>0){
		$log.='&emsp;<span class="text-danger">'.$unreadable_xml.' files cannot be read. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	$unreadable_json=0;
	$unwritable_json=0;
	$filecount=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.json');
		foreach($php_files as $file){
			$filecount++;
			if(!is_readable($file)){
				$log.='<span class="text-danger">Unable to read JSON file '.$file.'</span><br>';
				array_push($errors, "Unable to read JSON file".$file);
				$unreadable_json++;
			}
			if(!is_writable($file)){
				$log.='<span class="text-danger">Unable to write to JSON file '.$file.'</span><br>';
				array_push($errors, "Unable to write to JSON file ".$file);
				$unwritable_json++;
			}
		}
	}
	$log.='Done checking filesystem permission issues for JSON files in project:<br>';
	if($unreadable_php>0 || $unreadable_json>0){
		$log.='&emsp;<span class="text-danger">'.$unwritable_json.' JSON files cannot be written to, '.$unreadable_json.' cannot be read. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	$log.='Searching for possible JSON parsing issues...<br>';
	$syntax_error_json=0;
	$filecount=0;
	foreach($project_folders as $folder){
		$php_files=glob($folder.'/*.json');
		foreach($php_files as $file){
			$filecount++;
			if(is_readable($file)){
				if(true!==$error=json_validator($file)){
					$log.='<span class="text-danger">JSON file syntax error: '.$file.': '.$error.'</span><br>';
					array_push($errors, "JSON file error: ".$file.": ".$error);
					$syntax_error_json++;
				}
			}
		}
	}
	$log.='Done checking JSON files in this project for parsing issues:<br>';
	if($syntax_error_json>0){
		$log.='&emsp;<span class="text-danger">'.$syntax_error_json.' files have syntax errors and will fail parsing. Checked '.$filecount.' files</span><br>';
	}
	else
	{
		$log.='&emsp;<span class="text-success">No issues. Checked '.$filecount.' files</span><br>';
	}
	
	if(is_writable($rootpath['dataphyre']."cache")){
		$log.='<span class="text-success">No filesystem permission issue with dataphyre\'s root cache folder.</span><br>';
	}
	else
	{
		$log.='<span class="text-danger">Can\'t write to dataphyre\'s root cache folder.</span><br>';
		array_push($errors, "Can't write to dataphyre's root cache folder");
	}
	
	if(file_exists($rootpath['dataphyre']."modules/sql")){
		$log.='Running tests for dp_SQL (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/sql/sql_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/sql/sql_diagnosis.php");
			$result=sql_diagnosis();
			$log.="<div class='ml-4'>".$result['log']."</div>";
			$errors=array_merge_recursive($errors, $result['errors']);
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_SQL.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/sql")){
			$log.='Running tests for dp_SQL (common) ...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/sql/sql_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/sql/sql_diagnosis.php");
				$result=sql_diagnosis();
				$log.="<div class='ml-4'>".$result['log']."</div>";
				$errors=array_merge_recursive($errors, $result['errors']);
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_SQL.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/async")){
		$log.='Running tests for dp_Async (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/async/async_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/async/async_diagnosis.php");
			$result=async_diagnosis();
			$log.="<div class='ml-4'>".$result['log']."</div>";
			$errors=array_merge_recursive($errors, $result['errors']);
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Async.</span><br>';
		}
	}
	else
	{
		$log.='Running tests for dp_Async (common)...<br>';
		if(file_exists($rootpath['common_dataphyre']."modules/async")){
			if(file_exists($rootpath['common_dataphyre']."modules/async/async_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/async/async_diagnosis.php");
				$result=async_diagnosis();
				$log.="<div class='ml-4'>".$result['log']."</div>";
				$errors=array_merge_recursive($errors, $result['errors']);
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Async.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/tracelog")){
		$log.='Running tests for dp_Tracelog (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/tracelog/tracelog_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/tracelog/tracelog_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Tracelog.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/tracelog")){
			$log.='Running tests for dp_Tracelog (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/tracelog/tracelog_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/tracelog/tracelog_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Tracelog.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/firewall")){
		$log.='Running tests for dp_Firewall (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/firewall/firewall_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/firewall/firewall_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Firewall.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/firewall")){
			$log.='Running tests for dp_Firewall (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/firewall/firewall_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/firewall/firewall_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Firewall.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/date_translation")){
		$log.='Running tests for dp_DateTranslation (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/date_translation/date_translation_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/date_translation/date_translation_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_DateTranslation.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/date_translation")){
			$log.='Running tests for dp_DateTranslation (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/date_translation/date_translation_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/date_translation/date_translation_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_DateTranslation.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/currency")){
		$log.='Running tests for dp_Currency (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/currency/currency_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/currency/currency_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Currency.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/currency")){
			$log.='Running tests for dp_Currency (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/currency/currency_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/currency/currency_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Currency.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/cdn")){
		$log.='Running tests for dp_CDN (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/cdn/cdn_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/cdn/cdn_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_CDN.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/cdn")){
			$log.='Running tests for dp_CDN (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/cdn/cdn_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/cdn/cdn_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_CDN.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/google_authenticator")){
		$log.='Running tests for dp_GoogleAuthenticator (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/google_authenticator/google_authenticator_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/google_authenticator/google_authenticator_diagnosis.php");
			$result=authenticator_diagnosis();
			$log.="<div class='ml-4'>".$result['log']."</div>";
			$errors=array_merge_recursive($errors, $result['errors']);
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_GoogleAuthenticator.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/google_authenticator")){
			$log.='Running tests for dp_GoogleAuthenticator (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/google_authenticator/google_authenticator_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/google_authenticator/google_authenticator_diagnosis.php");
				$result=authenticator_diagnosis();
				$log.="<div class='ml-4'>".$result['log']."</div>";
				$errors=array_merge_recursive($errors, $result['errors']);
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_GoogleAuthenticator.</span><br>';
			}
		}
	}
	
	if(file_exists($rootpath['dataphyre']."modules/access")){
		$log.='Running tests for dp_Access (non-common)...<br>';
		if(file_exists($rootpath['dataphyre']."modules/access/access_diagnosis.php")){
			require_once($rootpath['dataphyre']."modules/access/access_diagnosis.php");
		}
		else
		{
			$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Access.</span><br>';
		}
	}
	else
	{
		if(file_exists($rootpath['common_dataphyre']."modules/access")){
			$log.='Running tests for dp_Access (common)...<br>';
			if(file_exists($rootpath['common_dataphyre']."modules/access/access_diagnosis.php")){
				require_once($rootpath['common_dataphyre']."modules/access/access_diagnosis.php");
			}
			else
			{
				$log.='&emsp;<span class="text-danger">No diagnosis toolset available for dp_Access.</span><br>';
			}
		}
	}
	
	$log.="<h4>Diagnosis finished</h4><br>";
	if(empty($errors)){
		$log.="<h1 style='font-size:150px;' class='text-success'><i>PASS</i></h1><br>";
	}
	else
	{
		$log.="<h1 style='font-size:150px;' class='text-danger'><i>FAIL</i></h1><br>";
	}
	return array("log"=>$log, "errors"=>$errors);
}