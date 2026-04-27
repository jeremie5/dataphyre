<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Jwt;

final class JwtPayload {

	private string $token;
	private array $headers;
	private array $claims;

	public function __construct(string $token, array $headers, array $claims){
		$this->token=$token;
		$this->headers=$headers;
		$this->claims=$claims;
	}

	public function token(): string {
		return $this->token;
	}

	public function headers(): array {
		return $this->headers;
	}

	public function claims(): array {
		return $this->claims;
	}

	public function header(string $key, mixed $default=null): mixed {
		return $this->headers[$key] ?? $default;
	}

	public function claim(string $key, mixed $default=null): mixed {
		return $this->claims[$key] ?? $default;
	}
}
