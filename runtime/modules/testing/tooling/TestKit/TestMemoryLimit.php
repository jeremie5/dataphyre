<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Normalizes bounded per-worker PHP memory declarations used by test metadata. */
final class TestMemoryLimit {

	public static function normalize(string $limit): string {
		$limit=strtoupper(trim($limit));
		if(preg_match('/^[1-9][0-9]*[KMG]?$/', $limit)!==1){
			throw new \InvalidArgumentException('Test memory limits must be positive PHP byte values such as 256M, 1G, or 524288.');
		}
		return $limit;
	}

	public static function bytes(string $limit): int {
		$limit=self::normalize($limit);
		$unit=substr($limit, -1);
		$value=(int)$limit;
		return match($unit){
			'K'=>$value * 1024,
			'M'=>$value * 1024 * 1024,
			'G'=>$value * 1024 * 1024 * 1024,
			default=>$value,
		};
	}
}
