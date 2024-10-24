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

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

dp_module_required('access', 'sql');
dp_module_required('access', 'firewall');

if(file_exists($filepath=$rootpath['common_dataphyre']."config/access.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/access.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['access'])){
	//core::unavailable("ACCESS_NO_CONFIG", "safemode");
}

if(empty($configurations['dataphyre']['access']['sessions_table_name'])){
	$configurations['dataphyre']['access']['sessions_table_name']="dataphyre.sessions";
}

class access{
	
	static $useragent_mismatch=false;
	
	function __construct(){
		global $configurations;
		if(isset($_SESSION)){
			if(isset($_SESSION['previous_useragent'])){
				if($configurations['dataphyre']['access']['sanction_on_useragent_change']===true){
					if(REQUEST_USER_AGENT!==$_SESSION['previous_useragent']){
						self::$useragent_mismatch=true;
						$_SESSION['minimum_security_reqs_alert']=true;
						access::disable_session();
						if(dp_module_present('firewall')===true){
							firewall::captcha_block_user('useragent_mismatch');
						}
					}
				}
			}
			$_SESSION['previous_useragent']=REQUEST_USER_AGENT;
		}
		if(access::logged_in()===true){
			if(access::validate_session()===false){
				if(access::recover_session()===false){
					if(access::disable_session()===false){
						core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: User session is invalid, unrecoverable and couldn\'t be destroyed.', 'safemode');
						exit();
					}
				}
			}
		}
		else
		{
			access::recover_session();
		}
	}
	
	/**
	  * Create an user session
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @param int 			$userid
	  * @param bool 		$keepalive
	  * @return bool			True on success, false on failure
	  */
	public static function create_session(int $userid, bool $keepalive=false) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_CREATE_SESSION",...func_get_args())) return $early_return;
		global $configurations;
		if(false!==sql::db_insert(
			$L=$configurations["dataphyre"]["access"]["sessions_table_name"], 
			$F=[
				"id"=>$id=access::create_id(), 
				"userid"=>$userid,
				"useragent"=>REQUEST_USER_AGENT,
				"ipaddress"=>REQUEST_IP_ADDRESS, 
				"keepalive"=>$keepalive, 
				"active"=>true
			],
			$V=null,
			$CC=true
		)){
			$website_name=strtolower(parse_url($_SERVER['HTTP_HOST'], PHP_URL_HOST));
			setcookie('__Secure-'.$configurations["dataphyre"]["access"]["sessions_cookie_name"], $id, time()+(86400*7), '/', strtolower($website_name), true, true);
			$_SESSION['userid']=$userid;
			$_SESSION['id']=$id;
			$_SESSION['ipaddress']=REQUEST_IP_ADDRESS;
			unset($_SESSION['access_no_known_recoverable_session']);
			return true;
		}
		return false;
	}
	
	/**
	  * Create a cryptographically secure session identifier
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return string Session identifier
	  */
	public static function create_id() : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_CREATE_DPID",...func_get_args())) return $early_return;
		$id=bin2hex(openssl_random_pseudo_bytes(32)); //Generate a 64 character long cryptographically secure string to be used as a session ID
		if(strlen($id)===64){
			return $id;
		}
		core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: Failed creating a DPID.', 'safemode');
	}
	
	/**
	  * Get the userid of a current user session
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return mixed		Userid if user is logged in otherwise false
	  */
	public static function userid() : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_USERID",...func_get_args())) return $early_return;
		if(access::logged_in()===true){
			return $_SESSION['userid'];
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed, user not logged-in");
		return false;
	}
	
	/**
	  * Search for clues identifying an user as a web crawler
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		True if positive, false on negative
	  */
	public static function is_bot() : bool{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		static $cache=null;
		if($cache!==null)return $cache;
		if(null!==$early_return=core::dialback("CALL_ACCESS_IS_BOT",...func_get_args())) return $early_return;
		foreach($configurations['dataphyre']['access']['botlist'] as $bl){
			if(stripos(strtolower(REQUEST_USER_AGENT), strtolower($bl))!==false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is a bot");
				$cache=true;
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not a bot");
		$cache=false;
		return false;
	}
	
	/**
	  * Search for clues identifying an user as using a mobile device
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function is_mobile() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		static $cache=null;
		if($cache!==null)return $cache;
		if(null!==$early_return=core::dialback("CALL_ACCESS_IS_MOBILE",...func_get_args())) return $early_return;
		$mobile_list=['Android', 'iOS', 'iPhone', 'iPad', 'Windows Phone', 'Opera Mini', 'IEMobile', 'Mobile'];
		foreach($mobile_list as $mobile){
			if(stripos(REQUEST_USER_AGENT, $mobile)!==false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is on a mobile device");
				$cache=true;
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not using a mobile device");
		$cache=false;
		return false;
	}
	
	/**
	  * Delete session variables and destroy user session in database
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function disable_session() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_DISABLE_SESSION",...func_get_args())) return $early_return;
		if(isset($_COOKIE['__Secure-'.core::get_config("dataphyre/access/sessions_cookie_name")])){
			$id=$_COOKIE['__Secure-'.core::get_config("dataphyre/access/sessions_cookie_name")];
			if(false!==sql::db_update(
				$L=core::get_config("dataphyre/access/sessions_table_name"), 
				$F=[
					"mysql"=>"active=0", 
					"postgresql"=>"active=false"
				],
				$P="WHERE id=?", 
				$V=array($id), 
				$CC=true
			)){
				$_SESSION=[];
				session_destroy();
				$_SESSION['dp']['access_cache']['no_known_recoverable_session']=true;
				unset($_SESSION['last_valid_session']);
				setcookie("__Secure-DPID", "", time()-3600, '/');
				setcookie("__Secure-SID", "", time()-3600, '/');
			}
		}
		return true;
	}
	
	/**
	  * Delete session variables and destroy user session in database
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		True on success, false on failure
	  */
	public static function disable_all_sessions_of_user(int $userid) : bool {
		if(null!==$early_return=core::dialback("CALL_ACCESS_DISABLE_ALL_SESSIONS_OF_USER",...func_get_args())) return $early_return;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(false!==sql::db_update(
			$L=core::get_config("dataphyre/access/sessions_table_name"), 
			$F="active=?", 
			$P="WHERE userid=?", 
			$V=array(false, $userid), 
			$CC=true
		)){
			$_SESSION['dp']['access_cache']['no_known_recoverable_session']=true;
			return true;
		}
		return false;
	}
	
	public static function validate_session(bool $cache=true) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_VALIDATE_SESSION",...func_get_args())) return $early_return;
		global $configurations;
		if($cache===true && isset($_SESSION['last_valid_session'])){
			if($_SESSION['last_valid_session']>strtotime("-30 seconds")){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session was known as valid less than 30 seconds ago");
				return true;
			}
		}
		if(isset($_COOKIE['__Secure-'.$configurations['dataphyre']['access']['sessions_cookie_name']])){
			$id=$_COOKIE['__Secure-'.$configurations['dataphyre']['access']['sessions_cookie_name']];
			if(!empty($_SESSION['userid']) && !empty($_SESSION['id'])){
				if($_SESSION['ipaddress']!==REQUEST_IP_ADDRESS){
					sql::db_update(
						$L=$configurations['dataphyre']['access']['sessions_table_name'], 
						$F="ipaddress=?", 
						$P=[
							"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
							"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
						],
						$V=array(REQUEST_IP_ADDRESS,$id,$_SESSION['userid'],REQUEST_USER_AGENT,$_SESSION['ipaddress']), 
						$CC=true
					);
				}
				if(false!==$row=sql::db_select(
					$S="*", 
					$L=$configurations['dataphyre']['access']['sessions_table_name'], 
					$P=[
						"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
						"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
					],
					$V=array($id, $_SESSION['userid'], REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
					$F=false, 
					$C=false
				)){
					if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
						$_SESSION['ipaddress']=REQUEST_IP_ADDRESS;
						$_SESSION['last_valid_session']=time();
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session is valid");
						return true;
					}
				}
			}
			access::disable_session();
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No session");
		return false;
	}
	
	/**
	  * Search for an active user session using the dataphyre id, ipaddress and useragent
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		 True on success, false on failure
	  */
	public static function recover_session() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_RECOVER_SESSION",...func_get_args())) return $early_return;
		if(!isset($_SESSION['dp']['access_cache']['no_known_recoverable_session'])){
			if(isset($_COOKIE['__Secure-'.core::get_config("dataphyre/access/sessions_cookie_name")])){
				$id=$_COOKIE['__Secure-'.core::get_config("dataphyre/access/sessions_cookie_name")];
				if(!isset($_SESSION['id']) || !isset($_SESSION['userid'])){
					if(false!==$row=sql::db_select(
						$S="*", 
						$L=core::get_config("dataphyre/access/sessions_table_name"), 
						$P=[
							"mysql"=>"WHERE id=? AND active=1 AND keepalive=1 AND useragent=? AND ipaddress=?", 
							"postgresql"=>"WHERE id=? AND active=true AND keepalive=true AND useragent=? AND ipaddress=?"
						],
						$V=array($id, REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
						$F=false, 
						$C=false
					)){
						if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
							$_SESSION['userid']=$row['userid'];
							$_SESSION['id']=$row['id'];
							$_SESSION['ipaddress']=REQUEST_IP_ADDRESS;
							return true;
						}
					}
				}
			}
		}
		$_SESSION['dp']['access_cache']['no_known_recoverable_session']=true;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No session to recover");
		return false;
	}
	
	/**
	  * Helper function to verify if an user is logged in or not
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @return bool		True on positive, false on negative
	  */
	public static function logged_in() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_LOGGED_IN",...func_get_args())) return $early_return;
		if(isset($_SESSION)){
			if(!empty($_SESSION['userid']) && !empty($_SESSION['id'])){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is logged in");
				return true;
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User isn't logged in");
		return false;
	}
	
	/**
	  * Verify if content can be displayed to the user
	  *
	  * @version 	1.0.0
	  * @author	Jérémie Fréreault <jeremie@phyro.ca>
	  *
	  * @param bool $session_required		If user must be logged in to display the page
	  * @param bool $must_no_session		If user must not be logged in to display the page
	  * @param bool $prevent_mobile		If user must not be using a mobile device to display the page
	  * @param bool $prevent_robot			If user must not be a robot to display the page
	  * @return bool		True on success, false on failure
	  */
	public static function access(bool $session_required=true, bool $must_no_session=false, bool $prevent_mobile=false, bool $prevent_robot=false) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_ACCESS",...func_get_args())) return $early_return;
		/*
		if(isset($_SERVER['HTTP_HOST']) && filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_IP)){
			ob_end_clean();
			http_response_code(403);
			header('Content-Type:text/html; charset=UTF-8');
			header('Server: Dataphyre');
			echo'<!DOCTYPE html>';
			echo'<html>';
			echo'<head>';
			echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
			echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
			echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
			echo'<style>'.minified_font().'</style>';
			echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
			echo'</head>';
			echo'<body>';
			echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
			echo'<h3>This page cannot be displayed while using direct connection</h3>';
			exit();
		}
		*/
		if($prevent_robot===true && access::is_bot()===true){
			if(!empty(core::get_config("dataphyre/access/requires_app_redirect"))){
				header('Location: '.core::get_config("dataphyre/access/robot_redirect"));
				exit();
			}
			ob_end_clean();
			http_response_code(403);
			header('Content-Type:text/html; charset=UTF-8');
			header('Server: Dataphyre');
			echo'<!DOCTYPE html>';
			echo'<html>';
			echo'<head>';
			echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
			echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
			echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
			echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
			echo'<style>'.minified_font().'</style>';
			echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
			echo'</head>';
			echo'<body>';
			echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
			echo'<h3>This page cannot be accessed by robots.</h3>';
			exit();
		}
		else
		{
			if($prevent_mobile===true && access::is_mobile()===true){
				if(!empty(core::get_config("dataphyre/access/requires_app_redirect"))){
					header('Location: '.core::get_config("dataphyre/access/requires_app_redirect"));
					exit();
				}
				ob_end_clean();
				http_response_code(403);
				header('Content-Type:text/html; charset=UTF-8');
				header('Server: Dataphyre');
				echo'<!DOCTYPE html>';
				echo'<html>';
				echo'<head>';
				echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
				echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
				echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
				echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
				echo'<style>'.minified_font().'</style>';
				echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
				echo'</head>';
				echo'<body>';
				echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
				echo'<h3>This page cannot be accessed by mobile devices without an application.</h3>';
				exit();
			}
			else
			{
				if($must_no_session===true){
					if(access::logged_in()===true){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can't be loaded as user is logged in, redirecting to homepage");
						if(!empty(core::get_config("dataphyre/access/must_no_session_redirect"))){
							header('Location: '.core::get_config("dataphyre/access/must_no_session_redirect"));
							exit();
						}
						ob_end_clean();
						http_response_code(401);
						header('Content-Type:text/html; charset=UTF-8');
						header('Server: Dataphyre');
						echo'<!DOCTYPE html>';
						echo'<html>';
						echo'<head>';
						echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
						echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
						echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
						echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
						echo'<style>'.minified_font().'</style>';
						echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
						echo'</head>';
						echo'<body>';
						echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
						echo'<h3>This page requires you to not have an active session.</h3>';
						exit();
					}
					else
					{
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, not logged in");
						return true;
					}
				}
				else
				{	
					if($session_required===false){
						if(access::logged_in()===false){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, not logged in");
							return true;
						}
						else
						{
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, logged in");
							return true;
						}
					}
					else
					{
						if(access::logged_in()===false){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User needs to be logged in, redirecting to login page");
							if(!empty(core::get_config("dataphyre/access/require_session_redirect"))){
								header('Location: '.core::get_config("dataphyre/access/require_session_redirect").'?redir='.base64_encode(ltrim($_SERVER["REQUEST_URI"], "/")));
								exit();
							}
							ob_end_clean();
							http_response_code(401);
							header('Content-Type:text/html; charset=UTF-8');
							header('Server: Dataphyre');
							echo'<!DOCTYPE html>';
							echo'<html>';
							echo'<head>';
							echo'<link rel="preconnect" href="https://fonts.googleapis.com">';
							echo'<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
							echo'<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">';
							echo'<style>@import url("https://fonts.googleapis.com/css2?family=Roboto&display=swap");</style>';
							echo'<style>'.minified_font().'</style>';
							echo'<style>h1,h2,h3,h4,h5.h6{font-family:"Roboto", sans-serif;}</style>';
							echo'</head>';
							echo'<body>';
							echo'<h1 style="font-size:60px" class="phyro-bold"><i><b>DATAPHYRE</b></i></h1>';
							echo'<h3>This page requires authentication.</h3>';
							exit();
						}
						else
						{
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can be loaded, logged in");
							return true;
						}
					}
				}
			}
		}
	}
	
}