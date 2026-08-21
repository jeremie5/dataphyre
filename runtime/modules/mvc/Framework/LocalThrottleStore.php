<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Explicit process-local throttle store for tests and single-process hosts.
 *
 * This store is never selected automatically. Multi-worker applications must
 * use SharedCacheThrottleStore or bind their own durable ThrottleStore.
 */
final class LocalThrottleStore implements ThrottleStore {

	/** @var array<string, array{count:int, expires_at:int}> Process-local counters. */
	private static array $counters=[];

	/**
	 * Increments one process-local counter.
	 *
	 * @param string $key Opaque throttle counter key.
	 * @param int $ttlSeconds Lifetime for a newly created counter.
	 * @return int Updated count.
	 */
	public function increment(string $key, int $ttlSeconds): int|false {
		$now=time();
		$counter=self::$counters[$key] ?? null;
		if(!is_array($counter) || (int)($counter['expires_at'] ?? 0)<=$now){
			$counter=[
				'count'=>0,
				'expires_at'=>$now+max(1, $ttlSeconds),
			];
		}
		$counter['count']=(int)$counter['count']+1;
		self::$counters[$key]=$counter;
		return $counter['count'];
	}

	/** Clears process-local counters used by tests or explicit local hosts. */
	public static function flush(): void {
		self::$counters=[];
	}
}
