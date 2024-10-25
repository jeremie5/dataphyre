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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

dp_module_required('firewall', 'sql');

if(file_exists($filepath=$rootpath['common_dataphyre']."config/firewall.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/firewall.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['firewall'])){
	//core::unavailable("MOD_FIREWALL_NO_CONFIG", "safemode");
}

firewall::flooding_check();
firewall::captcha();
	
class firewall{

	public static function captcha(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_CAPTCHA",...func_get_args())) return $early_return;
		$ipaddress=$_SERVER['REMOTE_ADDR'];
		if($_SESSION['captcha_unblock']===true){
			if(dp_module_present("cache")){
				cache::delete("fwcb".md5($ipaddress));
			}
			else
			{
				sql_delete(
					$L="dataphyre.captcha_blocks", 
					$P="WHERE ip_address=?", 
					$V=array($ipaddress), 
					$CC=true
				);
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Captcha block removed for IP $ipaddress");
			$_SESSION['captcha_unblock']=false;
			$_SESSION['captcha_blocked']=false;
			unset($_SESSION['last_requests']);
		}
		else
		{
			self::check_if_captcha_blocked();
		}
	}
	
	public static function flooding_threshold(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_FLOODING_CHECK",...func_get_args())) return $early_return;
		$threshold=3;
		if(dp_module_present("access")){
			$threshold=$threshold+1;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Flooding threshold calculated to be $threshold");
		return $threshold;
	}

	public static function flooding_check(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_FLOODING_CHECK",...func_get_args())) return $early_return;
		global $configurations;
		if($configurations['dataphyre']['firewall']['throttling']['min_time']!==0){
			if(!empty($_SESSION['last_requests'])){
				array_unshift($_SESSION['last_requests'], microtime(true));
				$i=0;
				foreach($_SESSION['last_requests'] as $value){
					if(microtime(true)-$value<($min_time_per_request=$configurations['dataphyre']['firewall']['throttling']['min_time'])/1000){
						$i++;
					}
				}
				if($i>=$threshold=self::flooding_threshold()){
					$action=$configurations['dataphyre']['firewall']['throttling']['min_time']??'throttle';
					if($action==='throttle'){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Request flooding detected, $i requests within $min_time_per_request ms; throttling request");
						sleep(strtotime($configurations['dataphyre']['firewall']['throttling']['throttle_time']));
					}
					elseif($action==='captcha'){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Request flooding detected, $i requests within $min_time_per_request ms; captcha blocking");
						self::captcha_block_user('request_flooding');
					}
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No request flooding detected, $i requests within $min_time_per_request ms");
				}
			}
			else 
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="No record of last requests timings");
				$_SESSION['last_requests'][]=microtime(true);
			}
			while(count($_SESSION['last_requests'])>10){
				array_pop($_SESSION['last_requests']);
			}
		}
	}

	public static function rps_limiter(int $timing) : bool {
		return true;
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_RPS_LIMITER",...func_get_args())) return $early_return;
		if(isset($_SESSION['last_requests'][0])){
			if(microtime(true)-$_SESSION['last_requests'][0]<$timing/1000){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Request flooding detected");
				return false;
			}
		}
		return true;
	}

	public static function check_if_captcha_blocked() : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_CHECK_IF_CAPTCHA_BLOCKED",...func_get_args())) return $early_return;
		$ipaddress=$_SERVER['REMOTE_ADDR'];
		if(dp_module_present("cache")){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Using cache module if it's started");
			if(cache::$started===true){
				if(null!==cache::get("fwcb".md5($ipaddress))){
					$_SESSION['captcha_blocked']=true;
				}
			}
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Using sql module");
			if(false!==$row=sql::db_select(
				$S="*", 
				$L="dataphyre.captcha_blocks", 
				$P="WHERE expiry>now() AND ip_address=?", 
				$V=array($ipaddress), 
				$F=false, 
				$C=true
			)){
				$_SESSION['captcha_blocked']=true;
			}
		}
		if(isset($_SESSION['captcha_blocked']) && $_SESSION['captcha_blocked']===true){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is captcha blocked");
			if(!strpos($_SERVER["REQUEST_URI"], 'captcha')){
				header('Location: '.core::url_self().'captcha?redir='.base64_encode(ltrim($_SERVER["REQUEST_URI"], "/")));
				exit();
			}
			return true;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="User is not captcha blocked");
		return false;
	}
	 
	public static function captcha_block_user(string $reason='unknown') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_FIREWALL_CAPTCHA_BLOCK_USER",...func_get_args())) return $early_return;
		$ipaddress=$_SERVER['REMOTE_ADDR'];
		$expiry=strtotime("+6 hours");
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Checking if IP isn't already captcha blocked");
		if(dp_module_present("cache")){
			cache::set("fwcb".md5($ipaddress), $reason, $expiry);
		}
		else
		{
			$expiry=date('Y-m-d H:i:s', $expiry);
			if(false!==sql::db_select(
				$S="*", 
				$L="dataphyre.captcha_blocks", 
				$P="WHERE expiry>now() AND ip_address=?", 
				$V=array($ipaddress), 
				$F=false, 
				$C=true
			)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="IP is already captcha blocked");
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Created captcha block"); 
				sql::db_insert(
					$L="dataphyre.captcha_blocks", 
					$F=[
						"ip_address"=>$ipaddress,
						"expiry"=>$expiry,
						"reason"=>$reason
					],
					$V=null, 
					$CC=true
				);
			}
		}
		self::check_if_captcha_blocked();
		return true;
	}
	
}