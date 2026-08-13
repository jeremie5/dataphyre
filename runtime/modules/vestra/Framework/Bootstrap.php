<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Vestra;

/** Idempotent marker for applications that load the optional Vestra facade. */
final class Bootstrap {
	public static function boot(): bool {
		if(!defined('DATAPHYRE_VESTRA_FRAMEWORK_BOOTSTRAPPED')){
			define('DATAPHYRE_VESTRA_FRAMEWORK_BOOTSTRAPPED', true);
		}
		return true;
	}
}

Bootstrap::boot();
