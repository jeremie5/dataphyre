<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(!function_exists('getallheaders')){
	function getallheaders(): array {
		return [
			'X-Flightdeck-Probe'=>'ready',
			'Authorization'=>'Bearer hidden',
		];
	}
}
