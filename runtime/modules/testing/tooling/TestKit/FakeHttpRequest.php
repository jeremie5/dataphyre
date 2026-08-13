<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Immutable request observed by FakeHttp and dynamic response callbacks. */
final class FakeHttpRequest {

	/** @param array<string,string> $headers @param array<int,mixed> $context */
	public function __construct(
		private string $method,
		private string $url,
		private mixed $body=null,
		private array $headers=[],
		private array $context=[]
	) {
		$this->method=strtoupper($this->method);
	}

	public function method(): string { return $this->method; }
	public function url(): string { return $this->url; }
	public function body(): mixed { return $this->body; }
	/** @return array<string,string> */
	public function headers(): array { return $this->headers; }
	/** @return array<int,mixed> */
	public function context(): array { return $this->context; }

	/** @return array<string,mixed> */
	public function form(): array {
		if(is_array($this->body)){
			return $this->body;
		}
		$values=[];
		parse_str((string)$this->body, $values);
		return $values;
	}

	public function formValue(string $key, mixed $default=null): mixed {
		return $this->form()[$key] ?? $default;
	}

	/** @return array<string|int,mixed> */
	public function json(): array {
		if(is_array($this->body)){
			return $this->body;
		}
		$decoded=json_decode((string)$this->body, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function jsonValue(string|int $key, mixed $default=null): mixed {
		return $this->json()[$key] ?? $default;
	}

	public function header(string $name, ?string $default=null): ?string {
		foreach($this->headers as $header=>$value){
			if(strcasecmp($header, $name)===0){
				return $value;
			}
		}
		return $default;
	}

	/** @return array{method:string,url:string,payload:mixed,headers:array<string,string>} */
	public function toArray(): array {
		return ['method'=>$this->method, 'url'=>$this->url, 'payload'=>$this->body, 'headers'=>$this->headers];
	}
}
