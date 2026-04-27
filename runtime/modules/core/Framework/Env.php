<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Env {

	public static function all(): array {
		return \dataphyre\core::env_all();
	}

	public static function get(string $key, mixed $default=null): mixed {
		$env=static::all();
		return array_key_exists($key, $env) ? $env[$key] : $default;
	}

	public static function has(string $key): bool {
		return array_key_exists($key, static::all());
	}

	public static function set(string|array $key, mixed $value=null): void {
		\dataphyre\core::set_env($key, $value);
	}

	public static function merge(array $values): void {
		\dataphyre\core::set_env($values);
	}

	public static function forget(string|array $key): void {
		\dataphyre\core::forget_env($key);
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
			if(!static::has($key)){
				continue;
			}
			$selected[$key]=static::get($key);
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
		return array_keys(static::all());
	}

	private static function normalizePrefix(?string $prefix, string $separator): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix, $separator." \t\n\r\0\x0B");
		return $prefix!=='' ? $prefix : null;
	}
}
