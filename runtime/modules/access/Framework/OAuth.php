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

/**
 * Static facade for Dataphyre OAuth provider flows.
 *
 * The facade keeps application code concise while the OAuth manager owns provider
 * registration, client configuration, authorization redirects, callback exchange,
 * token refresh, token revocation, and guard login integration. Methods delegate
 * immediately to the configured provider and therefore inherit each provider's
 * network side effects, OAuth state validation, and exception behavior.
 */
final class OAuth {

	/**
	 * Returns the shared OAuth manager instance.
	 *
	 * @return Manager Singleton that stores provider configuration and runtime extensions.
	 */
	public static function manager(): Manager {
		return Manager::instance();
	}

	/**
	 * Clears the shared OAuth manager instance and provider cache.
	 *
	 * @return void
	 */
	public static function flush(): void {
		Manager::flush();
	}

	/**
	 * Lists provider names currently known to the OAuth manager.
	 *
	 * @return list<string> Provider aliases that can be passed to provider(), redirect(), or user().
	 */
	public static function providers(): array {
		return self::manager()->providerNames();
	}

	/**
	 * Checks whether a provider alias is configured.
	 *
	 * @param string $name Provider alias to inspect.
	 * @return bool True when the manager can resolve the provider alias.
	 */
	public static function hasProvider(string $name): bool {
		return self::manager()->hasProvider($name);
	}

	/**
	 * Resolves a provider client by alias.
	 *
	 * The manager may instantiate the provider lazily and apply configuration or
	 * extension callbacks before returning it.
	 *
	 * @param string $name Provider alias from OAuth configuration.
	 * @return Provider OAuth provider client ready for authorization and token calls.
	 */
	public static function provider(string $name): Provider {
		return self::manager()->provider($name);
	}

	/**
	 * Registers or replaces provider configuration at runtime.
	 *
	 * Config shape is interpreted by Manager and Provider. Typical payloads include
	 * client credentials, redirect URIs, scopes, endpoint overrides, and mapper hooks.
	 *
	 * @param string $name Provider alias to configure.
	 * @param mixed $config Provider config array, Provider instance, or manager-supported factory.
	 * @return void
	 */
	public static function extendProvider(string $name, mixed $config): void {
		self::manager()->extendProvider($name, $config);
	}

	/**
	 * Builds the provider authorization URL for an outbound OAuth redirect.
	 *
	 * Options are merged into a temporary provider clone/configuration via with().
	 * Common options include scopes, state, prompt, redirect URI, and provider-specific
	 * authorization parameters.
	 *
	 * @param string $provider Provider alias.
	 * @param array{scopes?:list<string>|string,state?:string,prompt?:string,redirect_uri?:string,params?:array<string,scalar|null>} $options Authorization options merged into a temporary provider configuration for this request.
	 * @return string Absolute URL that should be sent to the user's browser.
	 */
	public static function authorizationUrl(string $provider, array $options=[]): string {
		return self::provider($provider)->with($options)->authorizationUrl();
	}

	/**
	 * Creates an HTTP redirect response to the provider authorization URL.
	 *
	 * @param string $provider Provider alias.
	 * @param array{scopes?:list<string>|string,state?:string,prompt?:string,redirect_uri?:string,params?:array<string,scalar|null>} $options Authorization options merged into a temporary provider configuration for this request.
	 * @return Response Redirect response targeting the provider authorization endpoint.
	 */
	public static function redirect(string $provider, array $options=[]): Response {
		return self::provider($provider)->with($options)->redirect();
	}

	/**
	 * Exchanges a callback request for an OAuth user profile.
	 *
	 * The provider reads authorization code, state, and error fields from the request
	 * payload, validates the callback, exchanges tokens when needed, and maps the
	 * remote identity into an OAuthUser object.
	 *
	 * @param string $provider Provider alias.
	 * @param Request|array|null $request Callback request object or raw request payload.
	 * @return OAuthUser Remote identity plus token metadata.
	 */
	public static function user(string $provider, Request|array|null $request=null): OAuthUser {
		return self::provider($provider)->user($request);
	}

	/**
	 * Fetches an OAuth user profile using an existing access token.
	 *
	 * @param string $provider Provider alias.
	 * @param string $accessToken Bearer token issued by the remote provider.
	 * @return OAuthUser Remote identity resolved from the token.
	 */
	public static function userFromToken(string $provider, string $accessToken): OAuthUser {
		return self::provider($provider)->userFromToken($accessToken);
	}

	/**
	 * Refreshes provider tokens using a refresh token or OAuthUser.
	 *
	 * Returned payload shape is provider-defined but should include the refreshed
	 * access token and may include expiration, scope, token type, and replacement
	 * refresh token fields.
	 *
	 * @param string $provider Provider alias.
	 * @param string|OAuthUser $refreshTokenOrUser Refresh token string or user carrying one.
	 * @return array<string, mixed> Refreshed token response returned by the provider.
	 */
	public static function refresh(string $provider, string|OAuthUser $refreshTokenOrUser): array {
		return self::provider($provider)->refresh($refreshTokenOrUser);
	}

	/**
	 * Refreshes tokens and returns an OAuthUser updated with the new token payload.
	 *
	 * @param string $provider Provider alias.
	 * @param string|OAuthUser $refreshTokenOrUser Refresh token string or user carrying one.
	 * @return OAuthUser User profile carrying refreshed token metadata.
	 */
	public static function refreshedUser(string $provider, string|OAuthUser $refreshTokenOrUser): OAuthUser {
		return self::provider($provider)->refreshedUser($refreshTokenOrUser);
	}

	/**
	 * Revokes a provider token when the provider supports revocation.
	 *
	 * The token may be supplied directly or extracted from an OAuthUser. The optional
	 * hint can disambiguate access and refresh tokens for providers that implement
	 * RFC 7009-style revocation endpoints.
	 *
	 * @param string $provider Provider alias.
	 * @param string|OAuthUser $tokenOrUser Token string or user containing token metadata.
	 * @param string|null $hint Provider-specific token type hint.
	 * @return bool True when the provider accepts the revocation request.
	 */
	public static function revoke(string $provider, string|OAuthUser $tokenOrUser, ?string $hint=null): bool {
		return self::provider($provider)->revoke($tokenOrUser, $hint);
	}

	/**
	 * Resolves an OAuth user and logs it into a Dataphyre access guard.
	 *
	 * The second argument may already be an OAuthUser, a callback Request, an array
	 * callback payload, or null to let the provider read the current request. The
	 * provider maps the remote identity into a local user before creating the guard
	 * session.
	 *
	 * @param string $provider Provider alias.
	 * @param Request|array|OAuthUser|null $requestOrUser Callback input or already-resolved OAuth user.
	 * @param string|null $guard Guard name/auth channel selected by the provider.
	 * @param bool $remember Whether the created local session should be persistent.
	 * @return bool True when the OAuth user is mapped and the guard login succeeds.
	 */
	public static function login(
		string $provider,
		Request|array|OAuthUser|null $requestOrUser=null,
		?string $guard=null,
		bool $remember=true
	): bool {
		return self::provider($provider)->login($requestOrUser, $guard, $remember);
	}
}
