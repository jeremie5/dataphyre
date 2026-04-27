<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

final class AuthContext {

	private ?string $guard_name;
	private string $auth_type;
	private bool $logged_in;
	private int|string|null $user_id;
	private ?string $identifier;
	private ?string $cookie_name;

	private function __construct(
		?string $guard_name,
		string $auth_type,
		bool $logged_in,
		int|string|null $user_id,
		?string $identifier,
		?string $cookie_name
	){
		$this->guard_name=$guard_name;
		$this->auth_type=$auth_type;
		$this->logged_in=$logged_in;
		$this->user_id=$user_id;
		$this->identifier=$identifier;
		$this->cookie_name=$cookie_name;
	}

	public static function capture(?string $auth_type=null, ?string $guard_name=null): self {
		$context=\dataphyre\access::auth_context($auth_type);
		return new self(
			$guard_name,
			(string)($context['auth_type'] ?? AuthType::SESSION),
			(bool)($context['logged_in'] ?? false),
			(isset($context['userid']) && (is_int($context['userid']) || is_string($context['userid'])))
				? $context['userid']
				: null,
			isset($context['id']) ? (string)$context['id'] : null,
			isset($context['cookie_name']) && $context['cookie_name']!==null
				? (string)$context['cookie_name']
				: null
		);
	}

	public function guardName(): ?string {
		return $this->guard_name;
	}

	public function authType(): string {
		return $this->auth_type;
	}

	public function loggedIn(): bool {
		return $this->logged_in;
	}

	public function userId(): int|string|null {
		return $this->user_id;
	}

	public function identifier(): ?string {
		return $this->identifier;
	}

	public function cookieName(): ?string {
		return $this->cookie_name;
	}
}
