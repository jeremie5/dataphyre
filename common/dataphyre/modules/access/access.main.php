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

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

if(file_exists($filepath=ROOTPATH['common_dataphyre']."config/access.php")){
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre']."config/access.php")){
	require_once($filepath);
}

$configurations['dataphyre']['access']['sessions_table_name']??="dataphyre.sessions";

\heisenconstant('DPID', fn()=>$_SESSION['dp_access']['dpid']);

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/access.diagnostic.php');
}

class access{
	
	private static $session_cookie='__Secure-DPID';
	private static $fingerprint=[];
	static $useragent_mismatch=false;
	
	public  function __construct(){
		global $configurations;
		if(isset($configurations['dataphyre']['access']['sessions_cookie_name'])){
			self::$session_cookie='__Secure-'.$configurations['dataphyre']['access']['sessions_cookie_name'];
		}
		if(isset($_SESSION)){
			if(isset($_SESSION['dp_access']['previous_useragent'])){
				if($configurations['dataphyre']['access']['sanction_on_useragent_change']===true){
					if(REQUEST_USER_AGENT!==$_SESSION['dp_access']['previous_useragent']){
						self::$useragent_mismatch=true;
						$_SESSION['dp_access']['minimum_security_alert']=true;
						self::disable_session();
						if(dp_module_present('firewall')===true){
							firewall::captcha_block_user('useragent_mismatch');
						}
					}
				}
			}
			$_SESSION['dp_access']['previous_useragent']=REQUEST_USER_AGENT;
		}
		self::$fingerprint=[
			'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? '',
			'accept_language'=>$_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
			'ip_subnet'=>self::extract_subnet(REQUEST_IP_ADDRESS),
			'cf_country'=>$_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
			'cf_connecting_ip'=>self::extract_subnet($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
			'dnt'=>$_SERVER['HTTP_DNT'] ?? '',
		];
		if(self::logged_in()===true){
			if(self::validate_session()===false){
				if(self::recover_session()===false){
					if(self::disable_session()===false){
						core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: User session is invalid, unrecoverable and couldn\'t be destroyed.', 'safemode');
						exit();
					}
				}
			}
		}
		else
		{
			self::recover_session();
		}
		self::enforce_fingerprint_drift();
		DPID->reset();
	}
	
	public static function get_session_cookie_name(): string {
		return self::$session_cookie;
	}
	
	private static function enforce_fingerprint_drift() : void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(isset($_SESSION['dp_access']['fingerprint'])){
			if(1<self::fingerprint_drift_score(self::$fingerprint, $_SESSION['dp_access']['fingerprint'])){
				$_SESSION['dp_access']['minimum_security_alert']=true;
				if(self::disable_session()===false){
					core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreAccess: User session is invalid (fingerprint drift) and couldn\'t be destroyed..', 'safemode');
				}
				if(dp_module_present('firewall')===true){
					firewall::captcha_block_user('fingerprint_drift');
				}
			}
		}
		$_SESSION['dp_access']['fingerprint']=self::$fingerprint;
	}
	
	private static function extract_subnet(string $ip): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
			return implode('.', array_slice(explode('.', $ip), 0, 3)); // Class C
		}
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return implode(':', array_slice(explode(':', $ip), 0, 4)); // Heuristic
		}
		return $ip;
	}
	
	private static function fingerprint_drift_score(array $stored, array $current): int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$diffs=0;
		foreach($stored as $key=>$value){
			if(!isset($current[$key]) || $current[$key] !== $value){
				$diffs++;
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Fingerprint drift detected for ".$key, $S="warning");
			}
			else
			{
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="No fingerprint drift for ".$key);
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Returning $diffs mismatches");
		return $diffs;
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
		if(session_status()!==PHP_SESSION_ACTIVE){
			session_start();
		}
		if(false!==sql_insert(
			$L=$configurations["dataphyre"]["access"]["sessions_table_name"], 
			$F=[
				"id"=>$dpid=self::create_id(), 
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
			setcookie(self::$session_cookie, $dpid, time()+(86400*7), '/', strtolower($website_name), true, true);
			$_SESSION['dp_access']['userid']=$userid;
			$_SESSION['dp_access']['dpid']=$dpid;
			$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
			unset($_SESSION['dp_access']['no_known_recoverable_session']);
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$dpid=rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$signature=substr(hash_hmac('sha256', $dpid, dpvk()), 0, 8);
		return 'DPID_'.$dpid.'_'.$signature;
	}
	
	public static function validate_id(string $dpid) : bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_ACCESS_VALIDATE_ID", ...func_get_args())) return $early_return;
		$valid=false;
		if(preg_match('/^DPID_([A-Za-z0-9\-_]{43})_([a-f0-9]{8})$/', $dpid, $matches)){
			$dpid=$matches[1];
			$signature=$matches[2];
			$expected_signature=substr(hash_hmac('sha256', $dpid, dpvk()), 0, 8);
			if(hash_equals($expected_signature, $signature)){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Valid DPID");
				return true;
			}
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Invalid DPID signature");
		}
		else
		{
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Invalid DPID format: $dpid");
		}
		$_SESSION['dp_access']['minimum_security_alert']=true;
		self::disable_session();
		if(dp_module_present('firewall')===true){
			firewall::captcha_block_user('forged_dpid');
		}
		return false;
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_USERID",...func_get_args())) return $early_return;
		if(self::logged_in()===true){
			return $_SESSION['dp_access']['userid'];
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
	public static function is_bot() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
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
		if(isset($_COOKIE[self::$session_cookie])){
			$dpid=$_COOKIE[self::$session_cookie];
			if(false!==sql_update(
				$L=config("dataphyre/access/sessions_table_name"), 
				$F=[
					"mysql"=>"active=0", 
					"postgresql"=>"active=false"
				],
				$P="WHERE id=?", 
				$V=array($dpid), 
				$CC=true
			)){
				unset($_SESSION['dp_access']['userid']);
				unset($_SESSION['dp_access']['dpid']);
				$_SESSION['dp_access']['no_known_recoverable_session']=true;
				unset($_SESSION['dp_access']['last_valid_session']);
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
		if(false!==sql_update(
			$L=config("dataphyre/access/sessions_table_name"), 
			$F="active=?", 
			$P="WHERE userid=?", 
			$V=array(false, $userid), 
			$CC=true
		)){
			$_SESSION['dp_access']['no_known_recoverable_session']=true;
			return true;
		}
		return false;
	}
	
	public static function validate_session(bool $cache=true) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_VALIDATE_SESSION",...func_get_args())) return $early_return;
		global $configurations;
		if($cache===true && isset($_SESSION['dp_access']['last_valid_session'])){
			if($_SESSION['dp_access']['last_valid_session']>strtotime("-30 seconds")){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session was known as valid less than 30 seconds ago");
				return true;
			}
		}
		if(isset($_COOKIE[self::$session_cookie])){
			$dpid=$_COOKIE[self::$session_cookie];
			if(!empty($_SESSION['dp_access']['userid']) && !empty($_SESSION['dp_access']['dpid'])){
				if(self::validate_id($dpid)){
					if($_SESSION['dp_access']['ip_address']!==REQUEST_IP_ADDRESS){
						sql_update(
							$L=$configurations['dataphyre']['access']['sessions_table_name'], 
							$F="ipaddress=?", 
							$P=[
								"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
								"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
							],
							$V=array(REQUEST_IP_ADDRESS,$dpid,$_SESSION['dp_access']['userid'],REQUEST_USER_AGENT,$_SESSION['dp_access']['ip_address']), 
							$CC=true
						);
					}
					if(false!==$row=sql_select(
						$S="*", 
						$L=$configurations['dataphyre']['access']['sessions_table_name'], 
						$P=[
							"mysql"=>"WHERE id=? AND userid=? AND active=1 AND useragent=? AND ipaddress=?", 
							"postgresql"=>"WHERE id=? AND userid=? AND active=true AND useragent=? AND ipaddress=?"
						],
						$V=array($dpid, $_SESSION['dp_access']['userid'], REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
						$F=false, 
						$C=false
					)){
						if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
							$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
							$_SESSION['dp_access']['last_valid_session']=time();
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Session is valid");
							return true;
						}
					}
				}
			}
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_RECOVER_SESSION",...func_get_args())) return $early_return;
		global $configurations;
		if(!isset($_SESSION['dp_access']['no_known_recoverable_session'])){
			if(isset($_COOKIE[self::$session_cookie])){
				$dpid=$_COOKIE[self::$session_cookie];
				if(self::validate_id($dpid)){
					if(!isset($_SESSION['dp_access']['dpid']) || !isset($_SESSION['dp_access']['userid'])){
						if(false!==$row=sql_select(
							$S="*", 
							$L=config("dataphyre/access/sessions_table_name"), 
							$P=[
								"mysql"=>"WHERE id=? AND active=1 AND keepalive=1 AND useragent=? AND ipaddress=?", 
								"postgresql"=>"WHERE id=? AND active=true AND keepalive=true AND useragent=? AND ipaddress=?"
							],
							$V=array($dpid, REQUEST_USER_AGENT, REQUEST_IP_ADDRESS), 
							$F=false, 
							$C=false
						)){
							if($row['date']>strtotime('-7 days') && $row['keepalive']==true || $row['date']>strtotime('-30 minutes')){
								$_SESSION['dp_access']['userid']=$row['userid'];
								$_SESSION['dp_access']['dpid']=$row['id'];
								$_SESSION['dp_access']['ip_address']=REQUEST_IP_ADDRESS;
								return true;
							}
						}
					}
				}
				self::disable_session();
			}
		}
		$_SESSION['dp_access']['no_known_recoverable_session']=true;
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_ACCESS_LOGGED_IN",...func_get_args())) return $early_return;
		if(isset($_SESSION)){
			if(!empty($_SESSION['dp_access']['userid']) && !empty($_SESSION['dp_access']['dpid'])){
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
		if(null!==$early_return=core::dialback("CALL_ACCESS_ACCESS",...func_get_args()))return $early_return;
		$error=function(string $error_string='Unknown error', int $response_code=403){
			ob_end_clean();
			flush();
			http_response_code($response_code);
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
			echo'<h3>'.$error_string.'</h3>';
			exit();
		};
		if($prevent_robot===true && self::is_bot()===true){
			if(!empty(config("dataphyre/access/requires_app_redirect"))){
				header('Location: '.config("dataphyre/access/robot_redirect"));
				exit();
			}
			$error('This page cannot be selfed by robots.', 403);
		}
		else
		{
			if($prevent_mobile===true && self::is_mobile()===true){
				if(!empty(config("dataphyre/access/requires_app_redirect"))){
					header('Location: '.config("dataphyre/access/requires_app_redirect"));
					exit();
				}
				$error('This page cannot be selfed by mobile devices without an application.', 403);
			}
			else
			{
				if($must_no_session===true){
					if(self::logged_in()===true){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="File ".basename($_SERVER["SCRIPT_FILENAME"])." can't be loaded as user is logged in, redirecting to homepage");
						if(!empty(config("dataphyre/access/must_no_session_redirect"))){
							header('Location: '.config("dataphyre/access/must_no_session_redirect"));
							exit();
						}
						$error('This page requires you to not have an active session.', 401);
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
						if(self::logged_in()===false){
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
						if(self::logged_in()===false){
							tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User needs to be logged in, redirecting to login page");
							if(!empty(config("dataphyre/access/require_session_redirect"))){
								header('Location: '.config("dataphyre/access/require_session_redirect").'?redir='.rtrim(base64_encode(ltrim($_SERVER["REQUEST_URI"], "/")), '='));
								exit();
							}
							$error('This page requires authentication.', 401);
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