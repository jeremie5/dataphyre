<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Guards;

use Dataphyre\Access\AuthContext;
use Dataphyre\Access\Contracts\Authenticatable;
use Dataphyre\Access\Contracts\Guard;
use Dataphyre\Access\Contracts\UserProvider;

/**
 * Framework guard adapter around the Dataphyre access kernel.
 *
 * AccessGuard exposes a modern Guard contract while preserving the kernel's
 * session authority. The guard name is framework-facing context, while authType is
 * the normalized kernel channel used for validation, recovery, login, and logout.
 * User hydration is delegated to an optional provider and cached per guard
 * instance until a session-changing operation invalidates that cache.
 */
final class AccessGuard implements Guard {

	private string $name;
	private string $authType;
	private ?UserProvider $provider;
	private bool $userResolved=false;
	private mixed $resolvedUser=null;

	/**
	 * Creates a guard for one framework name and kernel authentication channel.
	 *
	 * Construction has no session side effects. The auth type is lowercased and
	 * trimmed once so every kernel call uses the same channel key.
	 *
	 * @param string $name Framework-visible guard name.
	 * @param string $authType Kernel auth channel, such as user, admin, or api.
	 * @param UserProvider|null $provider Optional provider used for user hydration and credential checks.
	 */
	public function __construct(string $name, string $authType, ?UserProvider $provider=null){
		$this->name=$name;
		$this->authType=strtolower(trim($authType));
		$this->provider=$provider;
	}

	/**
	 * Returns the framework-visible guard name.
	 *
	 * @return string Name used in captured contexts and framework configuration.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	/**
	 * Returns the normalized kernel auth channel.
	 *
	 * @return string Lowercase auth type passed to Dataphyre access kernel calls.
	 */
	public function authType(): string {
		return $this->authType;
	}

	/**
	 * Checks whether the current request has an authenticated session.
	 *
	 * @return bool True when the access kernel considers this auth channel logged in.
	 */
	public function check(): bool {
		return \dataphyre\access::logged_in($this->authType);
	}

	/**
	 * Checks whether the current request is unauthenticated for this guard.
	 *
	 * @return bool True when no authenticated session is active for this auth channel.
	 */
	public function guest(): bool {
		return $this->check()===false;
	}

	/**
	 * Returns the authenticated principal identifier from the access kernel.
	 *
	 * Falsey kernel sentinels are normalized to null so framework consumers can
	 * treat null as the only unauthenticated identifier state.
	 *
	 * @return int|string|null Authenticated user identifier, or null for guests.
	 */
	public function id(): int|string|null {
		$identifier=\dataphyre\access::userid($this->authType);
		return ($identifier===false || $identifier===null) ? null : $identifier;
	}

	/**
	 * Hydrates and caches the authenticated user for this guard.
	 *
	 * The provider is consulted only after the kernel reports an authenticated
	 * session and returns a non-null identifier. The cached value may be null, which
	 * prevents repeated provider lookups for guests or unresolved sessions.
	 *
	 * @return mixed Provider-specific user object/array, or null when unavailable.
	 */
	public function user(): mixed {
		if($this->userResolved===true){
			return $this->resolvedUser;
		}
		$this->userResolved=true;
		if($this->provider===null || $this->check()===false){
			return $this->resolvedUser=null;
		}
		$identifier=$this->id();
		if($identifier===null){
			return $this->resolvedUser=null;
		}
		return $this->resolvedUser=$this->provider->retrieveById($identifier);
	}

	/**
	 * Captures the current authentication context for this guard.
	 *
	 * @return AuthContext Snapshot containing guard name, auth type, identifier, and login state.
	 */
	public function context(): AuthContext {
		return AuthContext::capture($this->authType, $this->name);
	}

	/**
	 * Validates the current kernel session for this auth channel.
	 *
	 * Validation is read-oriented from the framework perspective, but the kernel may
	 * refresh or cache session state depending on its configured validator.
	 *
	 * @param bool $cache Whether the kernel may reuse cached validation state.
	 * @return bool True when the current session is valid.
	 */
	public function validate(bool $cache=true): bool {
		return \dataphyre\access::validate_session($cache, $this->authType);
	}

	/**
	 * Attempts to recover an authenticated session for this auth channel.
	 *
	 * Recovery may read remember-me cookies or other kernel-managed persistence.
	 * When recovery succeeds the cached user is cleared so the provider reloads the
	 * principal attached to the recovered session.
	 *
	 * @return bool True when the kernel recovers a session.
	 */
	public function recover(): bool {
		$recovered=\dataphyre\access::recover_session($this->authType);
		if($recovered){
			$this->forgetResolvedUser();
		}
		return $recovered;
	}

	/**
	 * Creates a session for the supplied user-like value.
	 *
	 * The user may be a provider-supported model, an Authenticatable instance, an
	 * object with conventional identifier methods, an array with id, or a raw scalar
	 * identifier. Non-numeric identifiers are rejected before reaching the kernel
	 * because the kernel session API stores integer user ids.
	 *
	 * @param mixed $user User value or identifier to authenticate.
	 * @param bool $remember Whether the kernel should create persistent remember state.
	 * @return bool True when a kernel session is created.
	 */
	public function login(mixed $user, bool $remember=false): bool {
		$identifier=$this->resolveUserIdentifier($user);
		if($identifier===null){
			return false;
		}
		$loggedIn=$this->loginUsingId($identifier, $remember);
		if($loggedIn){
			$this->userResolved=true;
			$this->resolvedUser=$user;
		}
		return $loggedIn;
	}

	/**
	 * Creates a session using a raw user identifier.
	 *
	 * String identifiers must be decimal integers. Other strings are rejected to
	 * avoid passing opaque external IDs into the kernel's integer session store.
	 *
	 * @param int|string $identifier Integer user id or numeric string.
	 * @param bool $remember Whether the kernel should create persistent remember state.
	 * @return bool True when the kernel accepts and stores the session.
	 */
	public function loginUsingId(int|string $identifier, bool $remember=false): bool {
		$kernelIdentifier=$this->normalizeKernelIdentifier($identifier);
		if($kernelIdentifier===null){
			return false;
		}
		$loggedIn=\dataphyre\access::create_session($kernelIdentifier, $remember, $this->authType);
		if($loggedIn){
			$this->forgetResolvedUser();
		}
		return $loggedIn;
	}

	/**
	 * Authenticates credentials through the configured user provider.
	 *
	 * The provider owns lookup and password/secret verification. This guard only
	 * creates a kernel session after a user is found and the provider validates the
	 * supplied credentials.
	 *
	 * @param array<string,mixed> $credentials Provider-specific credential payload, commonly identifier/password plus guard-specific secrets.
	 * @param bool $remember Whether the kernel should create persistent remember state.
	 * @return bool True when credentials are valid and login succeeds.
	 */
	public function attempt(array $credentials, bool $remember=false): bool {
		if($this->provider===null){
			return false;
		}
		$user=$this->provider->retrieveByCredentials($credentials);
		if($user===null || $user===false){
			return false;
		}
		if($this->provider->validateCredentials($user, $credentials)===false){
			return false;
		}
		return $this->login($user, $remember);
	}

	/**
	 * Disables the active kernel session for this auth channel.
	 *
	 * @return bool True when the kernel reports the session disabled.
	 */
	public function logout(): bool {
		$loggedOut=\dataphyre\access::disable_session($this->authType);
		if($loggedOut){
			$this->forgetResolvedUser();
		}
		return $loggedOut;
	}

	/**
	 * Extracts an authentication identifier from supported user representations.
	 *
	 * Provider identifiers take precedence because providers may adapt external
	 * domain objects to kernel ids. The remaining fallbacks keep the guard usable
	 * with lightweight framework models and arrays without forcing every project to
	 * implement a shared base class.
	 *
	 * @param mixed $user User object, array, or raw scalar identifier.
	 * @return int|string|null Identifier candidate, or null when none can be trusted.
	 */
	private function resolveUserIdentifier(mixed $user): int|string|null {
		if($this->provider!==null){
			$identifier=$this->provider->authIdentifier($user);
			if($identifier!==null){
				return $identifier;
			}
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
			if(isset($user->id) && (is_int($user->id) || is_string($user->id))){
				return $user->id;
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

	/**
	 * Converts a framework identifier into the integer shape required by the kernel.
	 *
	 * @param int|string $identifier Raw identifier extracted from credentials or user data.
	 * @return int|null Kernel-safe integer id, or null when the identifier is not numeric.
	 */
	private function normalizeKernelIdentifier(int|string $identifier): ?int {
		if(is_int($identifier)){
			return $identifier;
		}
		if(is_string($identifier) && preg_match('/^-?\d+$/', $identifier)===1){
			return (int)$identifier;
		}
		return null;
	}

	/**
	 * Clears the per-instance user cache after a session transition.
	 *
	 * @return void
	 */
	private function forgetResolvedUser(): void {
		$this->userResolved=false;
		$this->resolvedUser=null;
	}
}
