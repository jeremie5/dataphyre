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

final class Auth {

	public static function manager(): AuthManager {
		return AuthManager::instance();
	}

	public static function defaultGuard(): string {
		return self::manager()->defaultGuard();
	}

	public static function guards(): array {
		return self::manager()->guardNames();
	}

	public static function hasGuard(string $name): bool {
		return self::manager()->hasGuard($name);
	}

	public static function shouldUse(string $guard): void {
		self::manager()->shouldUse($guard);
	}

	public static function forgetGuardOverride(): void {
		self::manager()->forgetGuardOverride();
	}

	public static function guard(?string $name=null): Guard {
		return self::manager()->guard($name);
	}

	public static function provider(string $name): ?UserProvider {
		return self::manager()->provider($name);
	}

	public static function extendGuard(string $driver, callable $resolver): void {
		self::manager()->extendGuard($driver, $resolver);
	}

	public static function extendProvider(string $name, mixed $config): void {
		self::manager()->extendProvider($name, $config);
	}

	public static function flush(): void {
		AuthManager::flush();
	}

	public static function defaultType(): string {
		return \dataphyre\access::default_auth_type();
	}

	public static function currentType(): string {
		return \dataphyre\access::current_auth_type();
	}

	public static function enabledTypes(): array {
		return \dataphyre\access::enabled_auth_types();
	}

	public static function context(?string $guard=null): AuthContext {
		return self::guard($guard)->context();
	}

	public static function check(?string $guard=null): bool {
		return self::guard($guard)->check();
	}

	public static function guest(?string $guard=null): bool {
		return self::guard($guard)->guest();
	}

	public static function user(?string $guard=null): mixed {
		return self::guard($guard)->user();
	}

	public static function claims(?string $guard=null): array {
		$guard_instance=self::guard($guard);
		return method_exists($guard_instance, 'claims')
			? $guard_instance->claims()
			: [];
	}

	public static function token(?string $guard=null): ?string {
		$guard_instance=self::guard($guard);
		return method_exists($guard_instance, 'token')
			? $guard_instance->token()
			: null;
	}

	public static function id(?string $guard=null): int|string|null {
		return self::guard($guard)->id();
	}

	public static function loggedIn(?string $guard=null): bool {
		return self::check($guard);
	}

	public static function userId(?string $guard=null): int|string|null {
		return self::id($guard);
	}

	public static function createSession(int $userid, bool $keepalive=false, ?string $guard=null): bool {
		return self::guard($guard)->loginUsingId($userid, $keepalive);
	}

	public static function login(mixed $user, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->login($user, $remember);
	}

	public static function loginUsingId(int|string $identifier, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->loginUsingId($identifier, $remember);
	}

	public static function attempt(array $credentials, bool $remember=false, ?string $guard=null): bool {
		return self::guard($guard)->attempt($credentials, $remember);
	}

	public static function validate(bool $cache=true, ?string $guard=null): bool {
		return self::guard($guard)->validate($cache);
	}

	public static function recover(?string $guard=null): bool {
		return self::guard($guard)->recover();
	}

	public static function disable(?string $guard=null): bool {
		return self::guard($guard)->logout();
	}

	public static function logout(?string $guard=null): bool {
		return self::guard($guard)->logout();
	}

	public static function access(
		bool $session_required=true,
		bool $must_no_session=false,
		bool $prevent_mobile=false,
		bool $prevent_robot=false
	): bool {
		return \dataphyre\access::access($session_required, $must_no_session, $prevent_mobile, $prevent_robot);
	}

	public static function oauth(string $provider): \Dataphyre\Access\OAuthClient\Provider {
		return \Dataphyre\Access\OAuth::provider($provider);
	}
}
