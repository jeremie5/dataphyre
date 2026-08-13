<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

use Dataphyre\Test\CaseDiscoveryCacheEntry;

/** Stores independently addressable code-test discovery results. */
interface CaseDiscoveryCache {
	public function find(string $key): ?CaseDiscoveryCacheEntry;
	public function store(string $key, CaseDiscoveryCacheEntry $entry): bool;
}
