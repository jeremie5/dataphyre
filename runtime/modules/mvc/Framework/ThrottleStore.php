<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Atomic storage contract for MVC throttle counters.
 *
 * Production implementations must make one increment visible to every worker
 * sharing the store and expire a newly created counter after the supplied
 * lifetime. Returning false tells the middleware that the policy store is
 * unavailable; the request is then rejected without invoking downstream
 * application code. LocalThrottleStore is the explicit test/single-host seam.
 */
interface ThrottleStore {

	/**
	 * Atomically increments a fixed-window counter.
	 *
	 * @param string $key Opaque, framework-namespaced counter key.
	 * @param int $ttlSeconds Lifetime for a newly created counter.
	 * @return int|false Updated count, or false when shared policy state is unavailable.
	 */
	public function increment(string $key, int $ttlSeconds): int|false;
}
