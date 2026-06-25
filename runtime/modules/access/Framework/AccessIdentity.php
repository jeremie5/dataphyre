<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

/**
 * Static facade for identity lookup, credential mutation, and token access.
 *
 * AccessIdentity keeps controller and guard code independent from the configured identity repository implementation. The
 * facade does not validate, hydrate, or persist users directly; every operation is delegated to AccessIdentityRepository or
 * AccessTokenBroker so database schemas, password hashing policy, email verification rules, and token storage remain
 * centralized.
 *
 * The mixed user parameters represent the application's identity shape: an entity, array row, DTO, scalar id wrapper, or
 * any other value understood by the active repository.
 */
final class AccessIdentity {

	/**
	 * Returns the singleton identity repository used by access guards.
	 *
	 * @return AccessIdentityRepository Repository responsible for identity lookup and credential state.
	 */
	public static function repository(): AccessIdentityRepository {
		return AccessIdentityRepository::instance();
	}

	/**
	 * Returns the singleton token broker used for access token lifecycle operations.
	 *
	 * @return AccessTokenBroker Broker responsible for issuing, resolving, and revoking access tokens.
	 */
	public static function tokens(): AccessTokenBroker {
		return AccessTokenBroker::instance();
	}

	/**
	 * Looks up an identity by email address through the configured repository.
	 *
	 * Email normalization is owned by the repository so deployments can choose case folding, tenant scoping, and uniqueness
	 * behavior that matches their storage model.
	 *
	 * @param string $email Email address supplied by a login or recovery flow.
	 * @return mixed Repository-specific user value, or the repository's missing-user sentinel.
	 */
	public static function findByEmail(string $email): mixed {
		return self::repository()->findByEmail($email);
	}

	/**
	 * Looks up an identity by primary identifier.
	 *
	 * The identifier may be numeric or string because access repositories can be backed by auto-increment ids, UUIDs,
	 * external provider subjects, or other application-defined keys.
	 *
	 * @param int|string $id Repository identity key.
	 * @return mixed Repository-specific user value, or the repository's missing-user sentinel.
	 */
	public static function findById(int|string $id): mixed {
		return self::repository()->findById($id);
	}

	/**
	 * Creates an identity through the repository.
	 *
	 * Attribute validation, default roles, password hashing, verification timestamps, and persistence side effects are
	 * repository responsibilities. This facade simply gives access flows one stable call point.
	 *
	 * @param array<string, mixed> $attributes Repository-specific identity attributes.
	 * @return mixed repository-created identity value after validation, hashing, defaults, and persistence.
	 */
	public static function create(array $attributes): mixed {
		return self::repository()->create($attributes);
	}

	/**
	 * Verifies a plaintext password against a repository identity.
	 *
	 * Hash algorithm selection, timing-safe comparison, disabled-user behavior, and opportunistic rehashing are controlled
	 * by the repository implementation.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @param string $password Plaintext candidate password supplied by the caller.
	 * @return bool Whether the repository accepts the password for that user.
	 */
	public static function verifyPassword(mixed $user, string $password): bool {
		return self::repository()->verifyPassword($user, $password);
	}

	/**
	 * Updates the credential secret for a repository identity.
	 *
	 * The repository owns hashing, persistence, password-history policy, and related token/session invalidation.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @param string $password Plaintext replacement password.
	 * @return bool Whether the repository stored the new password state.
	 */
	public static function setPassword(mixed $user, string $password): bool {
		return self::repository()->setPassword($user, $password);
	}

	/**
	 * Records that an identity's email address has been verified.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @return bool Whether the repository updated the verification marker.
	 */
	public static function markEmailVerified(mixed $user): bool {
		return self::repository()->markEmailVerified($user);
	}

	/**
	 * Extracts the stable access identifier from a repository identity.
	 *
	 * Null means the repository cannot derive an identifier for the supplied value, which is different from a denied guard
	 * decision and should usually be treated as an unauthenticated identity.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @return int|string|null Stable identifier used by sessions, tokens, and guard context.
	 */
	public static function identifier(mixed $user): int|string|null {
		return self::repository()->identifier($user);
	}

	/**
	 * Extracts an email address from a repository identity.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @return ?string Email address suitable for login, recovery, or display, or null when unavailable.
	 */
	public static function email(mixed $user): ?string {
		return self::repository()->email($user);
	}

	/**
	 * Reports whether the repository considers the identity's email verified.
	 *
	 * @param mixed $user Repository-specific user value.
	 * @return bool Whether the identity has completed the repository's email verification requirement.
	 */
	public static function emailVerified(mixed $user): bool {
		return self::repository()->emailVerified($user);
	}
}
