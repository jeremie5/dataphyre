<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Reactor;

final class Reactor {
	public static function manifest(): array {
		return ['version'=>'probe-without-trace','components'=>[],'trace'=>[]];
	}
}
