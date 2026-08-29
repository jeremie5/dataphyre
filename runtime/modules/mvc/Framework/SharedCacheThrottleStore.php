<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Stores throttle counters in Dataphyre's process-shared cache backend.
 *
 * The cache facade normally degrades to request-local memory when Memcached is
 * unavailable. Security policy cannot use that availability fallback, so this
 * adapter requires both an explicitly shared backend and the cache facade's
 * atomic shared-counter operation. Any loss of that capability returns false.
 */
final class SharedCacheThrottleStore implements ThrottleStore {
	private const MAX_RELATIVE_EXPIRATION=2592000;

	/**
	 * Atomically increments a shared counter with creation-time expiration.
	 *
	 * @param string $key Opaque throttle counter key.
	 * @param int $ttlSeconds Lifetime for a newly created counter.
	 * @return int|false Updated shared count, or false when shared cache is unavailable.
	 */
	public function increment(string $key, int $ttlSeconds): int|false {
		if(
			!class_exists('\dataphyre\cache', false)
			|| !method_exists('\dataphyre\cache', 'isShared')
			|| !method_exists('\dataphyre\cache', 'incrementShared')
		){
			return false;
		}
		try{
			if(\dataphyre\cache::isShared()!==true){
				return false;
			}
			$expiration=max(1, $ttlSeconds);
			if($expiration>self::MAX_RELATIVE_EXPIRATION){
				$expiration=time()+$expiration;
			}
			$count=\dataphyre\cache::incrementShared($key, 1, $expiration);
			if(!is_int($count) || $count<1 || \dataphyre\cache::isShared()!==true){
				return false;
			}
			return $count;
		}catch(\Throwable){
			return false;
		}
	}
}
