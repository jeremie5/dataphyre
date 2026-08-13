<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Access;

final class Auth {
	public static bool $authenticated=false;
	public static function id(?string $guard=null): int|string|null { return 404; }
	public static function check(?string $guard=null): bool { return self::$authenticated; }
	public static function user(?string $guard=null): mixed { return ['id'=>404,'permissions'=>['auth.view']]; }
}
