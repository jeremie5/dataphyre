<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

use Dataphyre\Access\OAuthClient\Manager;
use Dataphyre\Access\OAuthClient\OAuthUser;
use Dataphyre\Access\OAuthClient\Provider;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

final class OAuth {

	public static function manager(): Manager {
		return Manager::instance();
	}

	public static function flush(): void {
		Manager::flush();
	}

	public static function providers(): array {
		return self::manager()->providerNames();
	}

	public static function hasProvider(string $name): bool {
		return self::manager()->hasProvider($name);
	}

	public static function provider(string $name): Provider {
		return self::manager()->provider($name);
	}

	public static function extendProvider(string $name, mixed $config): void {
		self::manager()->extendProvider($name, $config);
	}

	public static function authorizationUrl(string $provider, array $options=[]): string {
		return self::provider($provider)->with($options)->authorizationUrl();
	}

	public static function redirect(string $provider, array $options=[]): Response {
		return self::provider($provider)->with($options)->redirect();
	}

	public static function user(string $provider, Request|array|null $request=null): OAuthUser {
		return self::provider($provider)->user($request);
	}

	public static function userFromToken(string $provider, string $access_token): OAuthUser {
		return self::provider($provider)->userFromToken($access_token);
	}

	public static function refresh(string $provider, string|OAuthUser $refresh_token_or_user): array {
		return self::provider($provider)->refresh($refresh_token_or_user);
	}

	public static function refreshedUser(string $provider, string|OAuthUser $refresh_token_or_user): OAuthUser {
		return self::provider($provider)->refreshedUser($refresh_token_or_user);
	}

	public static function revoke(string $provider, string|OAuthUser $token_or_user, ?string $hint=null): bool {
		return self::provider($provider)->revoke($token_or_user, $hint);
	}

	public static function login(
		string $provider,
		Request|array|OAuthUser|null $request_or_user=null,
		?string $guard=null,
		bool $remember=true
	): bool {
		return self::provider($provider)->login($request_or_user, $guard, $remember);
	}
}
