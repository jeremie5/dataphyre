<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Contracts;

/**
 * Adapts project user storage to Dataphyre authentication.
 *
 * User providers are the Access module's persistence boundary. Implementations
 * know how to retrieve project-specific user records, validate submitted
 * credentials, and expose a stable authentication identifier without requiring
 * the auth guard to understand the application's user model.
 */
interface UserProvider {

	/**
	 * Retrieves a user by its authentication identifier.
	 *
	 * The identifier is the value previously returned by authIdentifier() and may
	 * come from a session, remember token, or guard-specific persistence layer.
	 *
	 * @param int|string $identifier Stored authentication identifier.
	 * @return mixed Project user record, or null/false when no user exists.
	 */
	public function retrieveById(int|string $identifier): mixed;

	/**
	 * Retrieves a candidate user for submitted credentials.
	 *
	 * Implementations should use non-secret credential fields for lookup and
	 * leave password or token verification to validateCredentials().
	 *
	 * @param array<string, mixed> $credentials Submitted credential payload.
	 * @return mixed Candidate user record, or null/false when credentials do not identify a user.
	 */
	public function retrieveByCredentials(array $credentials): mixed;

	/**
	 * Validates submitted credentials against a retrieved user.
	 *
	 * This method is responsible for password hashing, token comparison, account
	 * status checks, and any project-specific login policy that must run after a
	 * user candidate is found.
	 *
	 * @param mixed $user Candidate user returned by retrieveByCredentials().
	 * @param array<string, mixed> $credentials Submitted credential payload.
	 * @return bool True when the credentials authenticate the user.
	 */
	public function validateCredentials(mixed $user, array $credentials): bool;

	/**
	 * Returns the stable identifier used to persist authentication state.
	 *
	 * The identifier should be stable across requests and safe to store in the
	 * guard session. Null indicates that the supplied user cannot be persisted as
	 * an authenticated subject.
	 *
	 * @param mixed $user Authenticated project user record.
	 * @return int|string|null Stable auth identifier, or null when unavailable.
	 */
	public function authIdentifier(mixed $user): int|string|null;
}
