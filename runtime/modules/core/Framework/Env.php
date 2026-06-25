<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Env {

	private static array $values=[];

	public static function all(): array {
		return self::$values;
	}

	public static function get(string $key, mixed $default=null): mixed {
		return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
	}

	public static function has(string $key): bool {
		return array_key_exists($key, self::$values);
	}

	public static function set(string|array $key, mixed $value=null): void {
		if(is_array($key)){
			static::merge($key);
			return;
		}
		self::$values[$key]=$value;
	}

	public static function merge(array $values): void {
		foreach($values as $key=>$value){
			self::$values[(string)$key]=$value;
		}
	}

	public static function forget(string|array $key): void {
		$keys=is_array($key) ? $key : [$key];
		foreach($keys as $envKey){
			unset(self::$values[(string)$envKey]);
		}
	}

	public static function pull(string $key, mixed $default=null): mixed {
		$value=static::get($key, $default);
		static::forget($key);
		return $value;
	}

	public static function repository(?string $prefix=null, string $separator='/'): EnvRepository {
		return new EnvRepository(static::normalizePrefix($prefix, $separator), $separator);
	}

	public static function scope(string $prefix, string $separator='/'): EnvRepository {
		return static::repository($prefix, $separator);
	}

	public static function snapshot(?string $prefix=null, string $separator='/'): EnvSnapshot {
		return static::repository($prefix, $separator)->snapshot();
	}

	public static function only(array $keys): array {
		$selected=[];
		foreach($keys as $key){
			$key=(string)$key;
			if(!array_key_exists($key, self::$values)){
				continue;
			}
			$selected[$key]=self::$values[$key];
		}
		return $selected;
	}

	public static function except(array $keys): array {
		$env=static::all();
		foreach($keys as $key){
			unset($env[(string)$key]);
		}
		return $env;
	}

	public static function keys(): array {
		return array_keys(self::$values);
	}

	private static function normalizePrefix(?string $prefix, string $separator): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix, $separator." \t\n\r\0\x0B");
		return $prefix!=='' ? $prefix : null;
	}
}
