<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Deterministic Flightdeck form-token boundary for DataDoc surface tests. */
final class dataphyre_flightdeck_auth {
	public static bool $valid=true;

	public static function verify_csrf(mixed $token): bool {
		return self::$valid && $token==='valid-token';
	}

	public static function csrf_token(): string {
		return 'valid-token';
	}
}
