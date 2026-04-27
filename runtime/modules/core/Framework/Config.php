<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class Config {

	public static function get(string $key, mixed $default=null): mixed {
		$value=\dataphyre\core::get_config($key);
		return $value===null ? $default : $value;
	}

	public static function has(string $key): bool {
		if(trim($key)===''){
			return false;
		}
		$config=static::all();
		if(array_key_exists($key, $config)){
			return true;
		}
		static::pathValue($config, static::segments($key), $exists);
		return $exists;
	}

	public static function set(string|array $config, mixed $value=null): bool {
		if(is_string($config)){
			$path=static::segments($config);
			if(count($path)>1){
				return \dataphyre\core::add_config(static::wrapPath($path, $value));
			}
		}
		return \dataphyre\core::add_config($config, $value);
	}

	public static function merge(array $config): bool {
		return \dataphyre\core::add_config($config);
	}

	public static function all(): array {
		return \dataphyre\core::config_all();
	}

	public static function repository(?string $path=null): ConfigRepository {
		return new ConfigRepository(static::normalizePath($path));
	}

	public static function scope(string $path): ConfigRepository {
		return static::repository($path);
	}

	public static function snapshot(?string $path=null): ConfigSnapshot {
		return static::repository($path)->snapshot();
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
		$config=static::all();
		foreach($keys as $key){
			static::unsetPath($config, static::segments((string)$key));
		}
		return $config;
	}

	public static function keys(?string $path=null): array {
		$value=$path!==null ? static::get($path, []) : static::all();
		return is_array($value) ? array_keys($value) : [];
	}

	private static function normalizePath(?string $path): ?string {
		if(!is_string($path)){
			return null;
		}
		$path=trim($path, " \t\n\r\0\x0B/");
		return $path!=='' ? $path : null;
	}

	private static function segments(string $path): array {
		return array_values(array_filter(
			explode('/', trim($path)),
			static fn(string $segment): bool => $segment!==''
		));
	}

	private static function wrapPath(array $path, mixed $value): array {
		$wrapped=$value;
		for($index=count($path)-1; $index>=0; $index--){
			$wrapped=[$path[$index]=>$wrapped];
		}
		return $wrapped;
	}

	private static function pathValue(array $value, array $path, ?bool &$exists=false): mixed {
		if($path===[]){
			$exists=true;
			return $value;
		}
		$current=$value;
		foreach($path as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				$exists=false;
				return null;
			}
			$current=$current[$segment];
		}
		$exists=true;
		return $current;
	}

	private static function unsetPath(array &$value, array $path): void {
		if($path===[]){
			return;
		}
		$key=array_shift($path);
		if($key===null || !array_key_exists($key, $value)){
			return;
		}
		if($path===[]){
			unset($value[$key]);
			return;
		}
		if(!is_array($value[$key])){
			return;
		}
		static::unsetPath($value[$key], $path);
	}
}
