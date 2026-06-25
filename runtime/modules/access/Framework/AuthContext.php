<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

/**
 * Immutable snapshot of the current Dataphyre authentication context.
 *
 * The context captures the guard name requested by framework code and the
 * auth state returned by the legacy access module: auth type, logged-in flag,
 * user id, stable identifier, and cookie name. It is a read model for guards,
 * policies, and diagnostics; it does not refresh sessions, validate tokens, or
 * mutate authentication state after capture.
 */
final class AuthContext {

	private ?string $guardName;
	private string $authType;
	private bool $loggedIn;
	private int|string|null $userId;
	private ?string $identifier;
	private ?string $cookieName;

	/**
	 * Stores a captured authentication snapshot.
	 *
	 * @param ?string $guardName Guard or policy context that requested the snapshot.
	 * @param string $authType Authentication mechanism reported by the access module.
	 * @param bool $loggedIn Whether the access module reported an authenticated user.
	 * @param int|string|null $userId Authenticated user id when it is a scalar id.
	 * @param ?string $identifier Stable session/token identifier reported by the access module.
	 * @param ?string $cookieName Cookie name associated with the auth context, when available.
	 */
	private function __construct(
		?string $guardName,
		string $authType,
		bool $loggedIn,
		int|string|null $userId,
		?string $identifier,
		?string $cookieName
	){
		$this->guardName=$guardName;
		$this->authType=$authType;
		$this->loggedIn=$loggedIn;
		$this->userId=$userId;
		$this->identifier=$identifier;
		$this->cookieName=$cookieName;
	}

	/**
	 * Captures the current access module authentication state.
	 *
	 * The method delegates to `\dataphyre\access::auth_context()` and normalizes
	 * the returned array into scalar-safe fields. Missing auth type falls back
	 * to session auth. Non-scalar user ids are discarded so policy code does not
	 * accidentally receive nested or object-shaped identity data.
	 *
	 * @param ?string $authType Optional auth mechanism requested from the access module.
	 * @param ?string $guardName Optional guard name carried alongside the captured state.
	 * @return self Immutable authentication snapshot.
	 */
	public static function capture(?string $authType=null, ?string $guardName=null): self {
		$context=\dataphyre\access::auth_context($authType);
		return new self(
			$guardName,
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

	/**
	 * Returns the guard name associated with this snapshot.
	 *
	 * @return ?string Guard name supplied at capture time, or `null` for unscoped captures.
	 */
	public function guardName(): ?string {
		return $this->guardName;
	}

	/**
	 * Returns the authentication mechanism reported by the access module.
	 *
	 * @return string Auth type such as session auth, token auth, or the module default.
	 */
	public function authType(): string {
		return $this->authType;
	}

	/**
	 * Reports whether the captured context represents an authenticated user.
	 *
	 *
	 * @return bool `true` when the access module reported a logged-in identity.
	 */
	public function loggedIn(): bool {
		return $this->loggedIn;
	}

	/**
	 * Returns the scalar user id from the captured auth context.
	 *
	 *
	 * @return int|string|null User id when supplied as an integer or string, otherwise `null`.
	 */
	public function userId(): int|string|null {
		return $this->userId;
	}

	/**
	 * Returns the stable auth identifier from the captured context.
	 *
	 * @return ?string Session, token, or context identifier, or `null` when unavailable.
	 */
	public function identifier(): ?string {
		return $this->identifier;
	}

	/**
	 * Returns the auth cookie name associated with the captured context.
	 *
	 * @return ?string Cookie name, or `null` when the auth mechanism does not expose one.
	 */
	public function cookieName(): ?string {
		return $this->cookieName;
	}
}
