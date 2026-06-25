<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

use Dataphyre\Access\Contracts\Guard;
use Dataphyre\Access\Contracts\UserProvider;

/**
 * Static facade for authentication guards, providers, sessions, tokens, and OAuth clients.
 *
 * Auth keeps Framework callers on the camelCase API while delegating guard state
 * to AuthManager and legacy auth-type/session checks to the kernel access module.
 * Methods do not implement authentication themselves; they route requests to the
 * active guard or configured provider and preserve each guard's security rules.
 */
final class Auth {

	/**
	 * Returns the singleton authentication manager.
	 *
	 * @return AuthManager Manager holding guards, providers, and guard overrides.
	 */
	public static function manager(): AuthManager {
		return AuthManager::instance();
	}

	/**
	 * Returns the configured default guard name.
	 *
	 * @return string Default guard identifier.
	 */
	public static function defaultGuard(): string {
		return self::manager()->defaultGuard();
	}

	/**
	 * Lists registered guard names.
	 *
	 * @return array Guard identifiers available to the manager.
	 */
	public static function guards(): array {
		return self::manager()->guardNames();
	}

	/**
	 * Reports whether a guard is registered.
	 *
	 * @param string $name Guard identifier.
	 * @return bool True when the manager can resolve the guard.
	 */
	public static function hasGuard(string $name): bool {
		return self::manager()->hasGuard($name);
	}

	/**
	 * Overrides the guard used by subsequent default-guard calls.
	 *
	 * @param string $guard Guard identifier to prefer for the current runtime.
	 */
	public static function shouldUse(string $guard): void {
		self::manager()->shouldUse($guard);
	}

	/**
	 * Clears the current guard override.
	 */
	public static function forgetGuardOverride(): void {
		self::manager()->forgetGuardOverride();
	}

	/**
	 * Resolves a guard by name or the current default/override.
	 *
	 * @param ?string $name Optional guard identifier.
	 * @return Guard Resolved authentication guard.
	 */
	public static function guard(?string $name=null): Guard {
		return self::manager()->guard($name);
	}

	/**
	 * Resolves a registered user provider.
	 *
	 * @param string $name Provider identifier.
	 * @return ?UserProvider Provider instance, or null when unknown.
	 */
	public static function provider(string $name): ?UserProvider {
		return self::manager()->provider($name);
	}

	/**
	 * Registers or replaces a custom guard driver resolver.
	 *
	 * @param string $driver Guard driver name.
	 * @param callable $resolver Resolver used by AuthManager when building the guard.
	 */
	public static function extendGuard(string $driver, callable $resolver): void {
		self::manager()->extendGuard($driver, $resolver);
	}

	/**
	 * Registers or replaces a user provider configuration.
	 *
	 * @param string $name Provider identifier.
	 * @param mixed $config Provider configuration or resolver.
	 */
	public static function extendProvider(string $name, mixed $config): void {
		self::manager()->extendProvider($name, $config);
	}

	/**
	 * Flushes the singleton manager and all cached guards/providers.
	 */
	public static function flush(): void {
		AuthManager::flush();
	}

	/**
	 * Returns the configured default authentication transport type.
	 *
	 * @return string Authentication type such as session or jwt.
	 */
	public static function defaultType(): string {
		if(!class_exists('\dataphyre\access', false)){
			return (string)(DP_ACCESS_CFG['default_auth_type'] ?? 'session');
		}
		return \dataphyre\access::default_auth_type();
	}

	/**
	 * Returns the current authentication transport type for this request.
	 *
	 * @return string Active authentication type.
	 */
	public static function currentType(): string {
		if(!class_exists('\dataphyre\access', false)){
			return self::defaultType();
		}
		return \dataphyre\access::current_auth_type();
	}

	/**
	 * Lists enabled authentication transport types.
	 *
	 * @return array Enabled auth type identifiers.
	 */
	public static function enabledTypes(): array {
		if(!class_exists('\dataphyre\access', false)){
			$types=DP_ACCESS_CFG['auth_types'] ?? DP_ACCESS_CFG['enabled_auth_types'] ?? ['session'];
			return is_array($types) ? array_values($types) : ['session'];
		}
		return \dataphyre\access::enabled_auth_types();
	}

	/**
	 * Returns the authentication context for a guard.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return AuthContext Guard context containing user, claims, token, and metadata.
	 */
	public static function context(?string $guard=null): AuthContext {
		return self::guard($guard)->context();
	}

	/**
	 * Reports whether the guard has an authenticated identity.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when authenticated.
	 */
	public static function check(?string $guard=null): bool {
		if(!class_exists('\dataphyre\access', false)){
			return false;
		}
		return self::guard($guard)->check();
	}

	/**
	 * Reports whether the guard has no authenticated identity.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when unauthenticated.
	 */
	public static function guest(?string $guard=null): bool {
		if(!class_exists('\dataphyre\access', false)){
			return true;
		}
		return self::guard($guard)->guest();
	}

	/**
	 * Returns the authenticated user for a guard.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return mixed Guard-specific user object, array, id, or null.
	 */
	public static function user(?string $guard=null): mixed {
		if(!class_exists('\dataphyre\access', false)){
			return null;
		}
		return self::guard($guard)->user();
	}

	/**
	 * Returns token claims when the guard exposes them.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return array Claims payload, or an empty array for guards without claims.
	 */
	public static function claims(?string $guard=null): array {
		$guard_instance=self::guard($guard);
		return method_exists($guard_instance, 'claims')
			? $guard_instance->claims()
			: [];
	}

	/**
	 * Returns the current bearer/session token when the guard exposes it.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return ?string Token string, or null for guards without token access.
	 */
	public static function token(?string $guard=null): ?string {
		$guard_instance=self::guard($guard);
		return method_exists($guard_instance, 'token')
			? $guard_instance->token()
			: null;
	}

	/**
	 * Returns the authenticated user identifier.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return int|string|null User id or null when unauthenticated.
	 */
	public static function id(?string $guard=null): int|string|null {
		if(!class_exists('\dataphyre\access', false)){
			return null;
		}
		return self::guard($guard)->id();
	}

	/**
	 * Alias for check() used by legacy callers.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when authenticated.
	 */
	public static function loggedIn(?string $guard=null): bool {
		return self::check($guard);
	}

	/**
	 * Alias for id() used by legacy callers.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return int|string|null User id or null when unauthenticated.
	 */
	public static function userId(?string $guard=null): int|string|null {
		return self::id($guard);
	}

	/**
	 * Creates a session by logging in a user id.
	 *
	 * @param int $userid User id to authenticate.
	 * @param bool $keepalive Whether the guard should persist the session longer.
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when the guard accepts the login.
	 */
	public static function createSession(int $userid, bool $keepalive=false, ?string $guard=null): bool {
		return self::guard($guard)->loginUsingId($userid, $keepalive);
	}

	/**
	 * Logs in a guard-specific user value.
	 *
	 * @param mixed $user User object, array, or identifier accepted by the guard.
	 * @param bool $remember Whether the guard should remember the login.
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when login succeeds.
	 */
	public static function login(mixed $user, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->login($user, $remember);
	}

	/**
	 * Logs in by user identifier.
	 *
	 * @param int|string $identifier User identifier accepted by the guard/provider.
	 * @param bool $remember Whether the guard should remember the login.
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when login succeeds.
	 */
	public static function loginUsingId(int|string $identifier, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->loginUsingId($identifier, $remember);
	}

	/**
	 * Attempts authentication with credential fields.
	 *
	 * @param array<string,mixed> $credentials Credential payload understood by the selected guard, commonly identifier/password plus provider-specific fields.
	 * @param bool $remember Whether the guard should remember the login.
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when credentials authenticate.
	 */
	public static function attempt(array $credentials, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->attempt($credentials, $remember);
	}

	/**
	 * Validates the current guard session or token.
	 *
	 * @param bool $cache Whether the guard may use cached validation state.
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when the current authentication state is valid.
	 */
	public static function validate(bool $cache=true, ?string $guard=null): bool {
		return self::guard($guard)->validate($cache);
	}

	/**
	 * Attempts guard-specific recovery of authentication state.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when recovery succeeds.
	 */
	public static function recover(?string $guard=null): bool {
		return self::guard($guard)->recover();
	}

	/**
	 * Disables the current authentication state by logging out.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when logout succeeds.
	 */
	public static function disable(?string $guard=null): bool {
		return self::guard($guard)->logout();
	}

	/**
	 * Logs out the current guard.
	 *
	 * @param ?string $guard Optional guard identifier.
	 * @return bool True when logout succeeds.
	 */
	public static function logout(?string $guard=null): bool {
		return self::guard($guard)->logout();
	}

	/**
	 * Runs the legacy access gate with session, guest, mobile, and robot constraints.
	 *
	 * @param bool $session_required Whether an authenticated session is required.
	 * @param bool $must_no_session Whether an existing session should be rejected.
	 * @param bool $prevent_mobile Whether mobile clients should be rejected.
	 * @param bool $prevent_robot Whether robot clients should be rejected.
	 * @return bool True when the request passes the access gate.
	 */
	public static function access(
		bool $session_required=true,
		bool $must_no_session=false,
		bool $prevent_mobile=false,
		bool $prevent_robot=false
	): bool {
		if(!class_exists('\dataphyre\access', false)){
			return !$session_required && !$must_no_session;
		}
		return \dataphyre\access::access($session_required, $must_no_session, $prevent_mobile, $prevent_robot);
	}

	/**
	 * Resolves an OAuth provider client.
	 *
	 * @param string $provider OAuth provider identifier.
	 * @return \Dataphyre\Access\OAuthClient\Provider Provider client.
	 */
	public static function oauth(string $provider): \Dataphyre\Access\OAuthClient\Provider {
		return \Dataphyre\Access\OAuth::provider($provider);
	}
}
