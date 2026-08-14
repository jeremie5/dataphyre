<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre;

/** Canonical public identifier grammar for an application's deployment environment. */
final class ApplicationEnvironmentIdentifier
{
	public static function valid(string $value): bool
	{
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',$value)===1
			&& !in_array($value,['.','..'],true);
	}
}
