<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

final class DpFlightdeckStackSqlProbe {
	/** @var array<string,list<mixed>> */
	private static array $responses=[];
	private static ?Throwable $failure=null;

	/** @param array<string,list<mixed>> $responses */
	public static function respond(array $responses): void {
		self::$responses=$responses;
		self::$failure=null;
	}

	public static function fail(Throwable $failure): void {
		self::$responses=[];
		self::$failure=$failure;
	}

	public static function select(string $location): mixed {
		if(self::$failure!==null){
			throw self::$failure;
		}
		return array_shift(self::$responses[$location]) ?? [];
	}
}

function sql_select(mixed $S='*',mixed $L='',mixed $P='',mixed $V=[],mixed $F=true): mixed {
	return DpFlightdeckStackSqlProbe::select((string)$L);
}
