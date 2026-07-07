<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Stores multiple logical values inside one secure Dataphyre cookie.
 *
 * Supercookie maintains a JSON object in the `__Secure-DATA` cookie and mirrors
 * changes into `$_COOKIE` for the current request. Dialbacks can override get,
 * set, and delete behavior for projects that need a custom storage strategy.
 */
class supercookie{
	
	static $cookie_name='DATA';
	
	/**
	 * Deletes one logical value from the aggregate cookie.
	 *
	 * The cookie is re-written with a 30 day expiry after the key is removed. A
	 * false result means the cookie was missing or PHP could not send the updated
	 * cookie header.
	 *
	 * @param string $name Logical value name inside the JSON cookie.
	 * @return bool True when the aggregate cookie was rewritten.
	 */
	static function del(string $name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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

	/**
	 * Reads one logical value from the aggregate cookie.
	 *
	 *
	 * @return mixed Stored value, null when the cookie or key is absent, or a dialback return value.
	 */
	static function get(string $name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SUPERCOOKIE_GET",...func_get_args())) return $early_return;
		if(isset($_COOKIE['__Secure-'.self::$cookie_name])){
			$params_array=json_decode($_COOKIE['__Secure-'.self::$cookie_name], true);
			if(isset($params_array[$name])){
				return $params_array[$name];
			}
		}
		return null;
	}

	/**
	 * Stores one logical value in the aggregate cookie.
	 *
	 * Cookie names with characters forbidden by Set-Cookie are rejected. The
	 * domain is derived from HTTP_HOST with port and invalid cookie characters
	 * removed, and the cookie is written as secure and HTTP-only.
	 *
	 * @param string $name Logical value name inside the JSON cookie.
	 * @param mixed $value JSON-encodable value to store.
	 * @return bool True when the aggregate cookie was rewritten.
	 */
	static function set(string $name, mixed $value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
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
