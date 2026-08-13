<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Backward-compatible array factories for callback-style HTTP clients. */
final class HttpResponseStub {

	/** @return array{status:int,body:string,headers:array<string,string>} */
	public static function json(mixed $body, int $status=200, array $headers=[]): array {
		return FakeHttpResponse::json($body, $status, $headers)->toArray();
	}

	/** @return array{status:int,body:string,headers:array<string,string>} */
	public static function text(string $body='', int $status=200, array $headers=[]): array {
		return FakeHttpResponse::text($body, $status, $headers)->toArray();
	}

	/** @return array{status:int,body:string,headers:array<string,string>} */
	public static function failure(int $status=500, string $body='error', array $headers=[]): array {
		return self::text($body, $status, $headers);
	}
}
