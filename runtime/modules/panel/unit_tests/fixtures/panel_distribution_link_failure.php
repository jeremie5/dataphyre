<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Test-only link adapter controlled by the owning TestState scenario. */
function link(string $from,string $to): bool {
	$scenario=\Dataphyre\Test\TestState::channelIfActive('panel.package-distribution.link');
	if($scenario?->get('fail',false)===true){
		return false;
	}
	return \link($from,$to);
}
