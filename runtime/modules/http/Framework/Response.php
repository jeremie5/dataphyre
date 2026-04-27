<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

final class Response {

	public int $status;
	public array $headers;
	public string $body;

	public function __construct(string $body='', int $status=200, array $headers=[]){
		$this->body=$body;
		$this->status=$status;
		$this->headers=$headers;
	}

	public static function make(string $body='', int $status=200, array $headers=[]): self {
		return new self($body, $status, $headers);
	}

	public static function json(array|\JsonSerializable $payload, int $status=200, array $headers=[]): self {
		$headers=array_replace(['Content-Type'=>'application/json; charset=utf-8'], $headers);
		$encoded=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return new self($encoded===false ? '{}' : $encoded, $status, $headers);
	}

	public static function html(string $html, int $status=200, array $headers=[]): self {
		$headers=array_replace(['Content-Type'=>'text/html; charset=utf-8'], $headers);
		return new self($html, $status, $headers);
	}

	public static function no_content(): self {
		return new self('', 204, []);
	}
}
