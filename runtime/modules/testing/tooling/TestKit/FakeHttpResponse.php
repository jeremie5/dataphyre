<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Immutable semantic response returned by FakeHttp callbacks. */
final class FakeHttpResponse {

	/** @param array<string,string> $headers */
	public function __construct(private int $status=200, private mixed $body=null, private array $headers=[]) {}

	public static function json(mixed $body, int $status=200, array $headers=[]): self {
		return new self($status, json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES), array_replace(['content-type'=>'application/json'], $headers));
	}

	public static function form(array $body, int $status=200, array $headers=[]): self {
		return new self($status, http_build_query($body), array_replace(['content-type'=>'application/x-www-form-urlencoded'], $headers));
	}

	public static function text(string $body='', int $status=200, array $headers=[]): self {
		return new self($status, $body, $headers);
	}

	public static function empty(int $status=204, array $headers=[]): self {
		return new self($status, '', $headers);
	}

	public static function failure(int $status=500, string $body='error', array $headers=[]): self {
		return self::text($body, $status, $headers);
	}

	/** @return array{status:int,body:mixed,headers:array<string,string>} */
	public function toArray(): array {
		return ['status'=>$this->status, 'body'=>$this->body, 'headers'=>$this->headers];
	}
}
