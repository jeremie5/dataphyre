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
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

class supercookie{
	
	static $cookie_name='DATA';
	
	static function del(string $name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SUPERCOOKIE_DEL",...func_get_args())) return $early_return;
		if(isset($_COOKIE['__Secure-'.self::$cookie_name])){
			$params_array=json_decode($_COOKIE['__Secure-'.self::$cookie_name], true);
			if(isset($params_array[$name])){
				unset($params_array[$name]);
			}
			$exp_days=30;
			if(!setcookie('__Secure-'.self::$cookie_name, json_encode($params_array), time()+(86400*$exp_days), '/', $_SERVER['HTTP_HOST'], $secure=true, $httponly=true)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Cannot modify cookies, output buffer is not empty.', $S='fatal');
				return false;
			}
			$_COOKIE['__Secure-'.self::$cookie_name]=json_encode($params_array);
			return true;
		}
		return false;
	}

	static function get(string $name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SUPERCOOKIE_GET",...func_get_args())) return $early_return;
		if(isset($_COOKIE['__Secure-'.self::$cookie_name])){
			$params_array=json_decode($_COOKIE['__Secure-'.self::$cookie_name], true);
			if(isset($params_array[$name])){
				return $params_array[$name];
			}
		}
		return null;
	}

	static function set(string $name, mixed $value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback('CALL_SUPERCOOKIE_SET',...func_get_args())) return $early_return;
		if(preg_match('/[=,; \t\r\n\013\014]/', $name)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Cannot set cookie, name is not allowed.', $S='fatal');
			return false;
		}
		$params_array=[];
		if(!isset($_COOKIE['__Secure-'.self::$cookie_name])){
			$_COOKIE['__Secure-'.self::$cookie_name]=[];
		}
		else
		{
			if(false===$params_array=json_decode($_COOKIE['__Secure-'.self::$cookie_name], true)){
				$params_array=[];
			}
		}
		$params_array[$name]=$value;
		$exp_days=30;
		$new_params=json_encode($params_array);
		$domain=preg_replace('/:[0-9]+/', '', $_SERVER['HTTP_HOST']);
		$domain=preg_replace('/[=,; \t\r\n\013\014]/', '', $domain);
		if(!setcookie('__Secure-'.self::$cookie_name, $new_params, time()+(86400*$exp_days), '/', $domain, $secure=true, $httponly=true)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Cannot modify cookies, output buffer is not empty.', $S='fatal');
			return false;
		}
		$_COOKIE['__Secure-'.self::$cookie_name]=$new_params;
		return true;
	}
}