<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded JSON-safe sanitizer shared by public search contracts. */
final class PanelSearchSanitizer {

	private const MAX_DEPTH=5;
	private const MAX_ITEMS=100;
	private const MAX_STRING=2000;

	public static function value(mixed $value, int $depth=0, bool $redactSecrets=true): mixed {
		if($depth>=self::MAX_DEPTH){ return '[depth-limited]'; }
		if($value===null || is_bool($value) || is_int($value)){ return $value; }
		if(is_float($value)){ return is_finite($value) ? $value : 0.0; }
		if(is_string($value)){
			if(preg_match('//u', $value)!==1){
				$encoded=json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
				$value=is_string($encoded) ? (string)json_decode($encoded, true) : '';
			}
			return strlen($value)>self::MAX_STRING ? self::truncate($value, self::MAX_STRING).'...' : $value;
		}
		if(is_resource($value)){ return '[resource]'; }
		if($value instanceof \Closure){ return '[closure]'; }
		if($value instanceof \DateTimeInterface){ return $value->format(DATE_ATOM); }
		if($value instanceof \JsonSerializable){
			try { return self::value($value->jsonSerialize(), $depth+1, $redactSecrets); }
			catch(\Throwable){ return ['type'=>$value::class, 'serialization'=>'failed']; }
		}
		if(is_object($value)){
			return ['type'=>$value::class];
		}
		if(!is_array($value)){ return (string)$value; }
		$result=[];
		$count=0;
		foreach($value as $key=>$item){
			if($count++>=self::MAX_ITEMS){
				$result['__truncated__']=true;
				break;
			}
			$key=is_int($key) ? $key : self::key((string)$key);
			if($redactSecrets && is_string($key) && self::secretKey($key)){
				$result[$key]='[redacted]';
				continue;
			}
			$result[$key]=self::value($item, $depth+1, $redactSecrets);
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public static function map(array $value): array {
		$sanitized=self::value($value);
		return is_array($sanitized) ? $sanitized : [];
	}

	/** JSON-safe cursor payloads stay opaque and functional; adapters must sign them. */
	public static function cursor(string|array|null $value): string|array|null {
		$sanitized=self::value($value, 0, false);
		if(is_string($sanitized)){ return trim($sanitized)!=='' ? $sanitized : null; }
		if(is_array($sanitized)){ return $sanitized!==[] ? $sanitized : null; }
		return null;
	}

	/** Redacts secret-like array keys before cursors cross a public JSON boundary. */
	public static function publicCursor(string|array|null $value): string|array|null {
		$sanitized=self::value($value);
		if(is_string($sanitized)){ return trim($sanitized)!=='' ? $sanitized : null; }
		if(is_array($sanitized)){ return $sanitized!==[] ? $sanitized : null; }
		return null;
	}

	private static function secretKey(string $key): bool {
		return preg_match('/(?:secret|token|password|passwd|authorization|cookie|credential|private[_-]?key|api[_-]?key|csrf)/i', $key)===1;
	}

	private static function key(string $key): string {
		$value=self::value($key);
		return self::truncate(is_string($value) ? $value : '', 190);
	}

	private static function truncate(string $value, int $bytes): string {
		if(strlen($value)<=$bytes){ return $value; }
		$value=substr($value, 0, $bytes);
		while($value!=='' && preg_match('//u', $value)!==1){ $value=substr($value, 0, -1); }
		return $value;
	}
}
