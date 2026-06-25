<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

/**
 * OAuth/OpenID Connect user profile with tokens and raw provider payloads.
 *
 * OAuthUser keeps normalized identity fields beside token response data,
 * userinfo/profile payloads, callback parameters, scopes, attributes, and decoded
 * ID-token claims for authentication and account-linking workflows.
 */
final class OAuthUser {

	private string $provider;
	private int|string|null $id;
	private ?string $nickname;
	private ?string $name;
	private ?string $email;
	private ?bool $emailVerified;
	private ?string $avatar;
	private ?string $accessToken;
	private ?string $refreshToken;
	private ?string $idToken;
	private ?string $tokenType;
	private ?int $expiresIn;
	private array $scopes;
	private array $attributes;
	private array $idTokenClaims;
	private array $tokenResponse;
	private array $profileResponse;
	private array $callbackParameters;

	/**
	 * Captures a normalized OAuth provider user, token set, and raw provider payloads.
	 *
	 * The value object intentionally keeps both normalized profile fields and raw
	 * token/profile/callback payloads so callers can persist trusted identity fields
	 * while still inspecting provider-specific data when needed.
	 *
	 * @param string $provider Provider name that produced the profile.
	 * @param int|string|null $id Provider subject/user id.
	 * @param string|null $nickname Provider nickname or username.
	 * @param string|null $name Display name.
	 * @param string|null $email Email address from profile or claims.
	 * @param bool|null $emailVerified Provider email verification state, or null when unknown.
	 * @param string|null $avatar Avatar/profile image URL.
	 * @param string|null $accessToken OAuth access token.
	 * @param string|null $refreshToken OAuth refresh token when issued.
	 * @param string|null $idToken OpenID Connect ID token when issued.
	 * @param string|null $tokenType Token type such as Bearer.
	 * @param int|null $expiresIn Access-token lifetime in seconds.
	 * @param array<int, string> $scopes Granted scopes.
	 * @param array<string, mixed> $attributes Normalized and provider-specific profile attributes.
	 * @param array<string, mixed> $idTokenClaims Decoded ID token claims.
	 * @param array<string, mixed> $tokenResponse Raw token endpoint response.
	 * @param array<string, mixed> $profileResponse Raw profile/userinfo endpoint response.
	 * @param array<string, mixed> $callbackParameters OAuth callback query/body parameters.
	 */
	public function __construct(
		string $provider,
		int|string|null $id=null,
		?string $nickname=null,
		?string $name=null,
		?string $email=null,
		?bool $emailVerified=null,
		?string $avatar=null,
		?string $accessToken=null,
		?string $refreshToken=null,
		?string $idToken=null,
		?string $tokenType=null,
		?int $expiresIn=null,
		array $scopes=[],
		array $attributes=[],
		array $idTokenClaims=[],
		array $tokenResponse=[],
		array $profileResponse=[],
		array $callbackParameters=[]
	){
		$this->provider=$provider;
		$this->id=$id;
		$this->nickname=$nickname;
		$this->name=$name;
		$this->email=$email;
		$this->emailVerified=$emailVerified;
		$this->avatar=$avatar;
		$this->accessToken=$accessToken;
		$this->refreshToken=$refreshToken;
		$this->idToken=$idToken;
		$this->tokenType=$tokenType;
		$this->expiresIn=$expiresIn;
		$this->scopes=$scopes;
		$this->attributes=$attributes;
		$this->idTokenClaims=$idTokenClaims;
		$this->tokenResponse=$tokenResponse;
		$this->profileResponse=$profileResponse;
		$this->callbackParameters=$callbackParameters;
	}

	/**
	 * Returns the provider name that produced this user.
	 *
	 * @return string Provider name.
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Returns the provider subject/user identifier.
	 *
	 * @return int|string|null Provider user id, or null when unavailable.
	 */
	public function id(): int|string|null {
		return $this->id;
	}

	/**
	 * Returns the provider nickname or username.
	 *
	 * @return string|null Nickname, username, or null.
	 */
	public function nickname(): ?string {
		return $this->nickname;
	}

	/**
	 * Returns the display name.
	 *
	 * @return string|null Display name, or null when unavailable.
	 */
	public function name(): ?string {
		return $this->name;
	}

	/**
	 * Returns the profile email address.
	 *
	 * @return string|null Email address, or null when unavailable.
	 */
	public function email(): ?string {
		return $this->email;
	}

	/**
	 * Returns provider email verification state.
	 *
	 * @return bool|null True/false when known, or null when the provider did not say.
	 */
	public function emailVerified(): ?bool {
		return $this->emailVerified;
	}

	/**
	 * Returns the profile image URL.
	 *
	 * @return string|null Avatar URL, or null when unavailable.
	 */
	public function avatar(): ?string {
		return $this->avatar;
	}

	/**
	 * Returns the OAuth access token.
	 *
	 * @return string|null Access token, or null when not issued.
	 */
	public function accessToken(): ?string {
		return $this->accessToken;
	}

	/**
	 * Returns the OAuth refresh token.
	 *
	 * @return string|null Refresh token, or null when not issued.
	 */
	public function refreshToken(): ?string {
		return $this->refreshToken;
	}

	/**
	 * Returns the OpenID Connect ID token.
	 *
	 * @return string|null ID token, or null when not issued.
	 */
	public function idToken(): ?string {
		return $this->idToken;
	}

	/**
	 * Returns the token type reported by the provider.
	 *
	 * @return string|null Token type such as Bearer, or null.
	 */
	public function tokenType(): ?string {
		return $this->tokenType;
	}

	/**
	 * Returns the access-token lifetime.
	 *
	 * @return int|null Lifetime in seconds, or null when unknown.
	 */
	public function expiresIn(): ?int {
		return $this->expiresIn;
	}

	/**
	 * Returns granted OAuth scopes.
	 *
	 * @return array<int, string> Granted scopes.
	 */
	public function scopes(): array {
		return $this->scopes;
	}

	/**
	 * Returns normalized and provider-specific profile attributes.
	 *
	 * @return array<string, mixed> Profile attributes.
	 */
	public function attributes(): array {
		return $this->attributes;
	}

	/**
	 * Returns decoded ID token claims.
	 *
	 * @return array<string, mixed> ID token claims.
	 */
	public function idTokenClaims(): array {
		return $this->idTokenClaims;
	}

	/**
	 * Reads one decoded ID token claim.
	 *
	 * @param string $key Claim name.
	 * @param mixed $default Value returned when the claim is absent.
	 * @return mixed decoded claim value, including null, or the caller default when absent.
	 */
	public function claim(string $key, mixed $default=null): mixed {
		return $this->idTokenClaims[$key] ?? $default;
	}

	/**
	 * Returns the raw token endpoint response.
	 *
	 * @return array<string, mixed> Token response payload.
	 */
	public function tokenResponse(): array {
		return $this->tokenResponse;
	}

	/**
	 * Returns the raw profile/userinfo endpoint response.
	 *
	 * @return array<string, mixed> Profile response payload.
	 */
	public function profileResponse(): array {
		return $this->profileResponse;
	}

	/**
	 * Returns OAuth callback parameters captured during login.
	 *
	 * @return array<string, mixed> Callback query/body parameters.
	 */
	public function callbackParameters(): array {
		return $this->callbackParameters;
	}

	/**
	 * Serializes normalized profile fields, tokens, and raw provider payloads.
	 *
	 * @return array{provider:string, id:int|string|null, nickname:?string, name:?string, email:?string, email_verified:?bool, avatar:?string, access_token:?string, refresh_token:?string, id_token:?string, token_type:?string, expires_in:?int, scopes:array<int, string>, attributes:array<string, mixed>, id_token_claims:array<string, mixed>, token_response:array<string, mixed>, profile_response:array<string, mixed>, callback_parameters:array<string, mixed>} OAuth user payload.
	 */
	public function toArray(): array {
		return [
			'provider'=>$this->provider,
			'id'=>$this->id,
			'nickname'=>$this->nickname,
			'name'=>$this->name,
			'email'=>$this->email,
			'email_verified'=>$this->emailVerified,
			'avatar'=>$this->avatar,
			'access_token'=>$this->accessToken,
			'refresh_token'=>$this->refreshToken,
			'id_token'=>$this->idToken,
			'token_type'=>$this->tokenType,
			'expires_in'=>$this->expiresIn,
			'scopes'=>$this->scopes,
			'attributes'=>$this->attributes,
			'id_token_claims'=>$this->idTokenClaims,
			'token_response'=>$this->tokenResponse,
			'profile_response'=>$this->profileResponse,
			'callback_parameters'=>$this->callbackParameters,
		];
	}
}
