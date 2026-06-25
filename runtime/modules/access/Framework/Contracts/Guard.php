<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Contracts;

use Dataphyre\Access\AuthContext;

/**
 * Contract for a framework authentication guard.
 *
 * A guard represents one authentication channel and mediates between framework
 * code and the access kernel. Implementations expose current session state,
 * hydrated principals, validation/recovery operations, and login/logout side
 * effects while keeping the guard name and kernel auth type explicit.
 */
interface Guard {

	/**
	 * Returns the framework-visible guard name.
	 *
	 * @return string Name used by framework configuration and captured auth contexts.
	 */
	public function name(): string;

	/**
	 * Returns the kernel authentication channel.
	 *
	 * @return string Auth type passed to Dataphyre access kernel calls.
	 */
	public function authType(): string;

	/**
	 * Checks whether the current request is authenticated for this guard.
	 *
	 * @return bool True when the guard has an active authenticated session.
	 */
	public function check(): bool;

	/**
	 * Checks whether the current request is unauthenticated for this guard.
	 *
	 * @return bool True when no authenticated session is active.
	 */
	public function guest(): bool;

	/**
	 * Returns the authenticated principal identifier.
	 *
	 * @return int|string|null Local user identifier, or null for guests/unresolved sessions.
	 */
	public function id(): int|string|null;

	/**
	 * Returns the hydrated authenticated principal.
	 *
	 * @return mixed Provider-specific user object/array, or null when no user can be resolved.
	 */
	public function user(): mixed;

	/**
	 * Captures a serializable authentication context for this guard.
	 *
	 * @return AuthContext Snapshot of guard name, auth type, identifier, and login state.
	 */
	public function context(): AuthContext;

	/**
	 * Validates the current session state.
	 *
	 * @param bool $cache Whether implementation may reuse cached validation state.
	 * @return bool True when the active session remains valid.
	 */
	public function validate(bool $cache=true): bool;

	/**
	 * Attempts to recover a remembered or resumable session.
	 *
	 * @return bool True when session recovery succeeds.
	 */
	public function recover(): bool;

	/**
	 * Creates a local session for a user-like value.
	 *
	 * @param mixed $user Principal value or identifier accepted by the implementation.
	 * @param bool $remember Whether the session should create persistent remember state.
	 * @return bool True when the session is created.
	 */
	public function login(mixed $user, bool $remember=false): bool;

	/**
	 * Creates a local session for a known user identifier.
	 *
	 * @param int|string $identifier Local user identifier.
	 * @param bool $remember Whether the session should create persistent remember state.
	 * @return bool True when the identifier is accepted and the session is created.
	 */
	public function loginUsingId(int|string $identifier, bool $remember=false): bool;

	/**
	 * Attempts credential-based authentication.
	 *
	 * @param array<string, mixed> $credentials Provider-specific credential payload.
	 * @param bool $remember Whether the session should create persistent remember state.
	 * @return bool True when credentials are valid and login succeeds.
	 */
	public function attempt(array $credentials, bool $remember=false): bool;

	/**
	 * Ends the current authenticated session for this guard.
	 *
	 * @return bool True when the session is disabled or cleared.
	 */
	public function logout(): bool;
}
