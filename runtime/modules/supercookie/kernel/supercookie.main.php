<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');

/** Stores multiple logical values inside one secure aggregate cookie. */
class supercookie {
	public static string $cookie_name='DATA';

	/** @param array<string,mixed> $runtime */
	public static function del(string $name, array $runtime=[]): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$earlyReturn=core::dialback('CALL_SUPERCOOKIE_DEL', $name)){
			return (bool)$earlyReturn;
		}
		$encoded=self::readAggregate($runtime);
		if($encoded===null){
			return false;
		}
		$values=self::decodeAggregate($encoded);
		unset($values[$name]);
		return self::persist($values, $runtime);
	}

	/** @param array<string,mixed> $runtime */
	public static function get(string $name, array $runtime=[]): mixed {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$earlyReturn=core::dialback('CALL_SUPERCOOKIE_GET', $name)){
			return $earlyReturn;
		}
		$encoded=self::readAggregate($runtime);
		if($encoded===null){
			return null;
		}
		$values=self::decodeAggregate($encoded);
		return array_key_exists($name, $values) ? $values[$name] : null;
	}

	/** @param array<string,mixed> $runtime */
	public static function set(string $name, mixed $value, array $runtime=[]) : bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$earlyReturn=core::dialback('CALL_SUPERCOOKIE_SET', $name, $value)){
			return (bool)$earlyReturn;
		}
		if(preg_match('/[=,; \t\r\n\013\014]/', $name)===1){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Cannot set cookie, name is not allowed.', $S='fatal');
			return false;
		}
		$values=self::decodeAggregate(self::readAggregate($runtime) ?? '{}');
		$values[$name]=$value;
		return self::persist($values, $runtime);
	}

	/** @param array<string,mixed> $runtime */
	private static function readAggregate(array $runtime): ?string {
		$cookieName='__Secure-'.self::$cookie_name;
		if(is_callable($runtime['read_cookie'] ?? null)){
			$value=$runtime['read_cookie']($cookieName);
			return $value===null ? null : (string)$value;
		}
		return isset($_COOKIE[$cookieName]) ? (string)$_COOKIE[$cookieName] : null;
	}

	/** @return array<string,mixed> */
	private static function decodeAggregate(string $encoded): array {
		$decoded=json_decode($encoded, true);
		return is_array($decoded) ? $decoded : [];
	}

	/** @param array<string,mixed> $values @param array<string,mixed> $runtime */
	private static function persist(array $values, array $runtime): bool {
		$cookieName='__Secure-'.self::$cookie_name;
		$encoded=json_encode($values, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		$encoded=is_string($encoded) ? $encoded : '{}';
		$host=(string)($runtime['host'] ?? ($_SERVER['HTTP_HOST'] ?? ''));
		$domain=self::cookieDomain($host);
		$clock=is_callable($runtime['clock'] ?? null) ? $runtime['clock'] : 'time';
		$writer=is_callable($runtime['write_cookie'] ?? null) ? $runtime['write_cookie'] : 'setcookie';
		$written=$writer($cookieName, $encoded, (int)$clock()+2592000, '/', $domain, true, true);
		if($written!==true){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Cannot modify cookies, output buffer is not empty.', $S='fatal');
			return false;
		}
		if(is_callable($runtime['mirror_cookie'] ?? null)){
			$runtime['mirror_cookie']($cookieName, $encoded);
		}else{
			$_COOKIE[$cookieName]=$encoded;
		}
		return true;
	}

	private static function cookieDomain(string $host): string {
		$domain=preg_replace('/:[0-9]+$/', '', trim($host)) ?? '';
		return preg_replace('/[=,; \t\r\n\013\014]/', '', $domain) ?? '';
	}
}
