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

class google_authenticator{

	static function create_secret(){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_GOOGLE_AUTHENTICATOR_CREATE_SECRET",...func_get_args())) return $early_return;
		return bin2hex(openssl_random_pseudo_bytes(24));	
	}

	static function get_pairing_image(string $secret, string $username) : bool|string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_GOOGLE_AUTHENTICATOR_GET_PAIRING_IMAGE",...func_get_args())) return $early_return;
		if(false!==$link=file_get_contents("https://www.authenticatorApi.com/pair.aspx?AppName=".urlencode(core::get_config("dataphyre/public_app_name"))."&AppInfo=".urlencode($username)."&SecretCode=".$secret)){
			$link=explode("src='", $link)[1];
			$link=explode("' bo", $link)[0];
			if(strpos($link, 'chart.googleapis.com')!==false){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="A seemingly valid link was obtained from API");
				return $link;
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed getting a QR code, API returned an invalid link", $S="warning");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed getting a QR code, API request has failed", $S="warning");
		return false;
	}

	static function verify(string $secret, string $code){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_GOOGLE_AUTHENTICATOR_VERIFY",...func_get_args())) return $early_return;
		if(is_numeric($code) && strlen($code)===6){
			if(false!==$result=file_get_contents("https://www.authenticatorApi.com/Validate.aspx?Pin=".$code."&SecretCode=".$secret)){
				if($result==="True"){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Code validated)");
					return true;
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Verification failed (API did not return True)", $S="warning");
				return false;
			}
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Verification failed (API request has failed)", $S="warning");
			return false;
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Verification failed (code is not formatted like it should be)", $S="warning");
		return false;
	}

}
