<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre;

/** Canonical ASCII grammar for an opaque public application identifier. */
final class PublicApplicationIdentifier
{
	public const MAX_BYTES=120;

	public static function valid(string $value): bool
	{
		return preg_match('/\A[A-Za-z0-9:_-]{1,120}\z/D',$value)===1;
	}
}
