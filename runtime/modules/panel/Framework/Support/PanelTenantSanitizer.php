<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded JSON-safe normalization shared by public tenant contracts. */
final class PanelTenantSanitizer {

	private const MAX_DEPTH=5;
	private const MAX_ITEMS=100;
	private const MAX_STRING=2000;

	public static function value(mixed $value, int $depth=0): mixed {
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
			try { return self::value($value->jsonSerialize(), $depth+1); }
			catch(\Throwable){ return ['type'=>$value::class, 'serialization'=>'failed']; }
		}
		if(is_object($value)){ return ['type'=>$value::class]; }
		if(!is_array($value)){ return (string)$value; }
		$result=[];
		$count=0;
		foreach($value as $key=>$item){
			if($count++>=self::MAX_ITEMS){ $result['__truncated__']=true; break; }
			$key=is_int($key) ? $key : self::key((string)$key);
			if(is_string($key) && self::secretKey($key)){
				$result[$key]='[redacted]';
				continue;
			}
			$result[$key]=self::value($item, $depth+1);
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public static function map(array $value): array {
		$sanitized=self::value($value);
		return is_array($sanitized) ? $sanitized : [];
	}

	public static function text(mixed $value, int $max=self::MAX_STRING): string {
		if(!is_scalar($value) && !$value instanceof \Stringable){ return ''; }
		try { $value=(string)$value; }
		catch(\Throwable){ return ''; }
		$value=self::value(trim($value));
		$value=is_string($value) ? $value : '';
		return self::truncate($value, max(1, min(self::MAX_STRING, $max)));
	}

	public static function tenantKey(mixed $value): ?string {
		$value=self::text($value, 100);
		if($value==='' || preg_match('~[\x00-\x1F\x7F\\\\/:%]~', $value)===1){ return null; }
		if(preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,98}[A-Za-z0-9])?$/', $value)!==1){ return null; }
		$value=Resource::normalizeName($value);
		return $value!=='' ? $value : null;
	}

	public static function url(mixed $value): ?string {
		$url=self::text($value);
		if($url===''){ return null; }
		$url=preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '';
		if($url==='' || str_starts_with($url, '//') || str_contains($url, '\\')){ return null; }
		$parts=parse_url($url);
		if($parts===false || isset($parts['user']) || isset($parts['pass'])){ return null; }
		$scheme=strtolower((string)($parts['scheme'] ?? ''));
		return $scheme==='' || in_array($scheme, ['http', 'https'], true) ? $url : null;
	}

	public static function badge(mixed $value): string|int|float|bool|null {
		if($value===null || is_bool($value) || is_int($value)){ return $value; }
		if(is_float($value)){ return is_finite($value) ? $value : null; }
		if(is_string($value) || $value instanceof \Stringable){
			$value=self::text($value, 200);
			return $value!=='' ? $value : null;
		}
		return null;
	}

	/** @return array<string,mixed> */
	public static function diagnostic(string $code, string $message, ?\Throwable $exception=null, array $meta=[]): array {
		$diagnostic=array_replace([
			'code'=>Resource::normalizeName($code) ?: 'tenant_error',
			'message'=>self::text($message, 300),
			'severity'=>'error',
		], self::map($meta));
		if($exception!==null){ $diagnostic['exception']=$exception::class; }
		return $diagnostic;
	}

	private static function secretKey(string $key): bool {
		return preg_match('/(?:secret|token|password|passwd|authorization|cookie|credential|private[_-]?key|api[_-]?key|csrf|session)/i', $key)===1;
	}

	private static function key(string $key): string {
		return self::truncate(self::text($key), 190);
	}

	private static function truncate(string $value, int $bytes): string {
		if(strlen($value)<=$bytes){ return $value; }
		$value=substr($value, 0, $bytes);
		while($value!=='' && preg_match('//u', $value)!==1){ $value=substr($value, 0, -1); }
		return $value;
	}
}
