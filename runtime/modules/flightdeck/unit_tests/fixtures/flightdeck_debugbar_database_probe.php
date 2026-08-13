<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database;

final class DB {
	/** @var list<mixed> */
	private static array $traces=[];
	private static ?\Throwable $failure=null;

	/** @param list<mixed> $traces */
	public static function respond(array $traces): void {
		self::$traces=$traces;
		self::$failure=null;
	}

	public static function fail(\Throwable $failure): void {
		self::$traces=[];
		self::$failure=$failure;
	}

	/** @return list<mixed> */
	public static function recentTraces(int $limit): array {
		if(self::$failure!==null){
			throw self::$failure;
		}
		return array_slice(self::$traces,0,max(0,$limit));
	}
}
