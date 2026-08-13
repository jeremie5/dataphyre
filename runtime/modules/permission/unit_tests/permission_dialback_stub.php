<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace dataphyre;

final class core {
	public static function dialback(string $event, mixed ...$arguments): mixed {
		return match($event){
			'CALL_PERMISSION_RESOLVE_SUBJECT_PERMISSIONS'=>['dialback.view'],
			'CALL_PERMISSION_RESOLVE_SUBJECT_ROLES'=>['dialback-role'],
			default=>null,
		};
	}
}
