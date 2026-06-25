<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Providers;

use Dataphyre\Access\Contracts\Authenticatable;
use Dataphyre\Access\Contracts\UserProvider;

/**
 * Adapts user-provider behavior to application supplied callbacks.
 *
 * CallbackUserProvider lets host projects plug Dataphyre authentication into an
 * existing user store without implementing a concrete provider class. Missing
 * callbacks are treated as unavailable retrieval paths, while credential
 * validation falls back to accepting any non-null/non-false retrieved user.
 */
final class CallbackUserProvider implements UserProvider {

	/** @var callable|string|array|null Callback that loads a user by persistent auth id. */
	private $retrieveById;

	/** @var callable|string|array|null Callback that loads a user from submitted credentials. */
	private $retrieveByCredentials;

	/** @var callable|string|array|null Callback that verifies credentials for a retrieved user. */
	private $validateCredentials;

	/** @var callable|string|array|null Callback that extracts a persistent auth id from a user value. */
	private $authIdentifier;

	/**
	 * Stores callback hooks for the user provider contract.
	 *
	 * The values are stored as supplied and invoked directly later, so
	 * configuration must provide PHP-callable values when a hook is enabled.
	 *
	 * @param callable|string|array|null $retrieveById Hook for session remember/auth id lookups.
	 * @param callable|string|array|null $retrieveByCredentials Hook for login credential lookups.
	 * @param callable|string|array|null $validateCredentials Hook for password or credential verification.
	 * @param callable|string|array|null $authIdentifier Hook for extracting a stable auth identifier.
	 */
	public function __construct(
		callable|string|array|null $retrieveById=null,
		callable|string|array|null $retrieveByCredentials=null,
		callable|string|array|null $validateCredentials=null,
		callable|string|array|null $authIdentifier=null
	){
		$this->retrieveById=$retrieveById;
		$this->retrieveByCredentials=$retrieveByCredentials;
		$this->validateCredentials=$validateCredentials;
		$this->authIdentifier=$authIdentifier;
	}

	/**
	 * Creates a provider from access configuration.
	 *
	 * Recognized keys map directly to constructor hooks: retrieve_by_id,
	 * retrieve_by_credentials, validate_credentials, and auth_identifier.
	 *
	 * @param array<string, mixed> $config Provider callback configuration.
	 * @return self Callback-backed user provider.
	 */
	public static function fromConfig(array $config): self {
		return new self(
			$config['retrieve_by_id'] ?? null,
			$config['retrieve_by_credentials'] ?? null,
			$config['validate_credentials'] ?? null,
			$config['auth_identifier'] ?? null
		);
	}

	/**
	 * Retrieves a user by persistent authentication identifier.
	 *
	 * Returning null indicates that this provider cannot resolve the id or that
	 * the user no longer exists.
	 *
	 * @param int|string $identifier Identifier stored in the auth session/token.
	 * @return mixed User value returned by the callback, or null when no callback is configured.
	 */
	public function retrieveById(int|string $identifier): mixed {
		if($this->retrieveById===null){
			return null;
		}
		return ($this->retrieveById)($identifier);
	}

	/**
	 * Retrieves a user candidate from submitted credentials.
	 *
	 * Password checking is intentionally separate from retrieval so applications
	 * can avoid leaking password hashes into the provider contract.
	 *
	 * @param array<string, mixed> $credentials Login credentials or lookup data.
	 * @return mixed User value returned by the callback, or null when no callback is configured.
	 */
	public function retrieveByCredentials(array $credentials): mixed {
		if($this->retrieveByCredentials===null){
			return null;
		}
		return ($this->retrieveByCredentials)($credentials);
	}

	/**
	 * Validates credentials for a retrieved user.
	 *
	 * When no validation callback is configured, any non-null and non-false user
	 * is accepted. That fallback is useful for providers where retrieval already
	 * performs the credential check.
	 *
	 * @param mixed $user User candidate returned by retrieveByCredentials().
	 * @param array<string, mixed> $credentials Original credentials.
	 * @return bool True when the user should be authenticated.
	 */
	public function validateCredentials(mixed $user, array $credentials): bool {
		if($this->validateCredentials===null){
			return $user!==null && $user!==false;
		}
		return (bool)($this->validateCredentials)($user, $credentials);
	}

	/**
	 * Extracts a persistent authentication identifier from a user value.
	 *
	 * The custom hook has priority. Without it, the provider supports
	 * Authenticatable implementations, conventional authIdentifier() and
	 * getAuthIdentifier() methods, public id properties, array id fields, and
	 * scalar user values.
	 *
	 * @param mixed $user User value returned by this provider.
	 * @return int|string|null Stable auth id, or null when one cannot be inferred.
	 */
	public function authIdentifier(mixed $user): int|string|null {
		if($this->authIdentifier!==null){
			return ($this->authIdentifier)($user);
		}
		if($user instanceof Authenticatable){
			return $user->authIdentifier();
		}
		if(is_object($user)){
			if(method_exists($user, 'authIdentifier')){
				return $user->authIdentifier();
			}
			if(method_exists($user, 'getAuthIdentifier')){
				return $user->getAuthIdentifier();
			}
			if(isset($user->id)){
				return is_int($user->id) || is_string($user->id) ? $user->id : null;
			}
		}
		if(is_array($user) && isset($user['id']) && (is_int($user['id']) || is_string($user['id']))){
			return $user['id'];
		}
		if(is_int($user) || is_string($user)){
			return $user;
		}
		return null;
	}
}
