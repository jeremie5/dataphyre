<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */if(!function_exists('dp_cache_unit_test_round_trip')){
	function dp_cache_unit_test_force_memory_fallback(): void {
		$reflection=new \ReflectionClass(\dataphyre\cache::class);
		foreach([
			'memcached'=>null,
			'memory_cache'=>[],
			'memory_fallback'=>true,
			'started'=>true,
		] as $property=>$value){
			$reflected_property=$reflection->getProperty($property);
			$reflected_property->setAccessible(true);
			$reflected_property->setValue(null, $value);
		}
	}

	function dp_cache_unit_test_round_trip(string $key, mixed $value): mixed {
		dp_cache_unit_test_force_memory_fallback();
		\dataphyre\cache::flush();
		\dataphyre\cache::set($key, $value);
		return \dataphyre\cache::get($key);
	}

	function dp_cache_unit_test_round_trip_json(string $key, mixed $value): string {
		return json_encode(dp_cache_unit_test_round_trip($key, $value), JSON_UNESCAPED_SLASHES);
	}
}

if(!function_exists('dp_cache_unit_test_delete_removes_key')){
	function dp_cache_unit_test_delete_removes_key(string $key, mixed $value): bool {
		dp_cache_unit_test_force_memory_fallback();
		\dataphyre\cache::flush();
		\dataphyre\cache::set($key, $value);
		\dataphyre\cache::delete($key);
		return \dataphyre\cache::get($key)===null;
	}
}

if(!function_exists('dp_cache_unit_test_increment_decrement')){
	function dp_cache_unit_test_increment_decrement_json(string $key): string {
		dp_cache_unit_test_force_memory_fallback();
		\dataphyre\cache::flush();
		return json_encode([
			'increment'=>\dataphyre\cache::increment($key, 3),
			'decrement'=>\dataphyre\cache::decrement($key, 2),
			'floor'=>\dataphyre\cache::decrement($key, 5),
		], JSON_UNESCAPED_SLASHES);
	}
}

if(!function_exists('dp_cache_unit_test_flush_clears_all_keys')){
	function dp_cache_unit_test_flush_clears_all_keys(): bool {
		dp_cache_unit_test_force_memory_fallback();
		\dataphyre\cache::set('unit:test:flush:a', 'a');
		\dataphyre\cache::set('unit:test:flush:b', 'b');
		$flushed=\dataphyre\cache::flush();
		return $flushed===true
			&& \dataphyre\cache::get('unit:test:flush:a')===null
			&& \dataphyre\cache::get('unit:test:flush:b')===null;
	}
}

if(!function_exists('dp_cache_unit_test_memory_expiration_json')){
	function dp_cache_unit_test_memory_expiration_json(string $key): string {
		dp_cache_unit_test_force_memory_fallback();
		\dataphyre\cache::set($key, 'expired');
		$reflection=new \ReflectionClass(\dataphyre\cache::class);
		$property=$reflection->getProperty('memory_cache');
		$property->setAccessible(true);
		$memory_cache=$property->getValue();
		$memory_cache[$key]['expires']=time()-1;
		$property->setValue(null, $memory_cache);
		$first=\dataphyre\cache::get($key);
		$second=\dataphyre\cache::get($key);
		return json_encode([
			'first'=>$first,
			'second'=>$second,
			'removed'=>!array_key_exists($key, $property->getValue()),
		], JSON_UNESCAPED_SLASHES);
	}
}
