<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Worker-local channel for exact line maps produced by owned child processes. */
final class CoverageParts {
	private static ?CoverageAccumulator $accumulator=null;

	public static function reset(): void { self::$accumulator=new CoverageAccumulator(); }

	/** @param array<string,mixed> $part */
	public static function add(array $part): void { self::accumulator()->add($part); }

	/** @return list<array<string,mixed>> */
	public static function all(): array { return self::accumulator()->all(); }

	private static function accumulator(): CoverageAccumulator {
		return self::$accumulator ??=new CoverageAccumulator();
	}
}
