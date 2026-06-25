<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Guards;

use Dataphyre\Access\AuthContext;
use Dataphyre\Access\AuthType;
use Dataphyre\Access\Contracts\Guard;
use Dataphyre\Access\Contracts\UserProvider;
use Dataphyre\Access\Jwt\JwtCodec;
use Dataphyre\Access\Jwt\JwtPayload;

/**
 * Stateless access guard backed by bearer JWTs.
 *
 * JwtGuard reads a token from a configured resolver or the HTTP Authorization
 * header, decodes it with merged framework/guard JWT configuration, and exposes
 * the payload or provider-resolved user through the common Guard contract. The
 * guard never creates tokens itself; login-style methods deliberately return
 * false because JWT issuance belongs to the API/authentication flow.
 */
final class JwtGuard implements Guard {

	private string $name;
	private array $config;
	private ?UserProvider $provider;
	private bool $payloadResolved=false;
	private ?JwtPayload $payload=null;
	private mixed $resolvedUser=null;
	private bool $userResolved=false;

	/**
	 * Creates a JWT guard with optional per-guard configuration and user provider.
	 *
	 * @param string $name Guard name used for config overlays and auth context.
	 * @param array<string, mixed> $config Guard-level options such as `subject_claim` or `token_resolver`.
	 * @param ?UserProvider $provider Provider used to convert the subject claim into an application user.
	 */
	public function __construct(string $name, array $config=[], ?UserProvider $provider=null){
		$this->name=$name;
		$this->config=$config;
		$this->provider=$provider;
	}

	/**
	 * Returns the configured guard name.
	 *
	 * @return string Guard identifier registered in access configuration.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Identifies this guard as a JWT-backed authentication guard.
	 *
	 * @return string {@see AuthType::JWT}.
	 */
	public function authType(): string {
		return AuthType::JWT;
	}

	/**
	 * Checks whether the current request has a decodable JWT payload.
	 *
	 * @return bool True when a bearer token is present and accepted by JwtCodec.
	 */
	public function check(): bool {
		return $this->payload()!==null;
	}

	/**
	 * Checks whether the current request is unauthenticated for this guard.
	 *
	 * @return bool True when no valid JWT payload can be resolved.
	 */
	public function guest(): bool {
		return $this->check()===false;
	}

	/**
	 * Returns the subject identifier from the decoded JWT claims.
	 *
	 * The claim name defaults to `sub` and can be overridden with
	 * `subject_claim`. Non-scalar subject values are ignored.
	 *
	 * @return int|string|null User identifier from the JWT subject claim.
	 */
	public function id(): int|string|null {
		$payload=$this->payload();
		if($payload===null){
			return null;
		}
		$claim=$payload->claim((string)($this->config['subject_claim'] ?? 'sub'));
		return (is_int($claim) || is_string($claim)) ? $claim : null;
	}

	/**
	 * Resolves the authenticated principal for the current token.
	 *
	 * Without a provider, the JwtPayload itself is returned so API-only guards
	 * can work directly with claims. With a provider, the subject identifier is
	 * used to retrieve the application user and the result is cached for the
	 * remainder of the request.
	 *
	 * @return mixed Provider user, JwtPayload, or null when no principal can be resolved.
	 */
	public function user(): mixed {
		if($this->userResolved===true){
			return $this->resolvedUser;
		}
		$this->userResolved=true;
		$payload=$this->payload();
		if($payload===null){
			return $this->resolvedUser=null;
		}
		if($this->provider===null){
			return $this->resolvedUser=$payload;
		}
		$identifier=$this->id();
		if($identifier===null){
			return $this->resolvedUser=null;
		}
		return $this->resolvedUser=$this->provider->retrieveById($identifier);
	}

	/**
	 * Captures an auth context for downstream policy and audit code.
	 *
	 * @return AuthContext Context containing JWT auth type and guard name.
	 */
	public function context(): AuthContext {
		return AuthContext::capture($this->authType(), $this->name);
	}

	/**
	 * Validates the current bearer token, optionally bypassing cached resolution.
	 *
	 * @param bool $cache Whether to reuse a previously decoded payload.
	 * @return bool True when the request token is valid for this guard.
	 */
	public function validate(bool $cache=true): bool {
		if($cache===false){
			$this->forgetResolvedPayload();
		}
		return $this->check();
	}

	/**
	 * Re-evaluates guard state after access middleware asks for recovery.
	 *
	 * JWT guards have no session to refresh, so recovery is equivalent to
	 * checking the current request token.
	 *
	 * @return bool True when a valid payload is available.
	 */
	public function recover(): bool {
		return $this->check();
	}

	/**
	 * Declines stateful login for JWT guards.
	 *
	 * JWT authentication is bearer-token based; token issuance is handled
	 * outside the Guard login contract.
	 *
	 * @param mixed $user Ignored principal candidate.
	 * @param bool $remember Ignored remember-me flag.
	 * @return bool Always false.
	 */
	public function login(mixed $user, bool $remember=false): bool {
		return false;
	}

	/**
	 * Declines stateful login by identifier for JWT guards.
	 *
	 * @param int|string $identifier Ignored user identifier.
	 * @param bool $remember Ignored remember-me flag.
	 * @return bool Always false.
	 */
	public function loginUsingId(int|string $identifier, bool $remember=false): bool {
		return false;
	}

	/**
	 * Declines credential attempts for JWT guards.
	 *
	 * Credential verification and token minting are expected to happen before a
	 * request reaches this bearer-token guard.
	 *
	 * @param array<string, mixed> $credentials Ignored credentials.
	 * @param bool $remember Ignored remember-me flag.
	 * @return bool Always false.
	 */
	public function attempt(array $credentials, bool $remember=false): bool {
		return false;
	}

	/**
	 * Clears cached request state without revoking the bearer token.
	 *
	 * Stateless JWT logout requires caller-managed revocation or client-side
	 * token disposal, so this method returns false after clearing local caches.
	 *
	 * @return bool Always false.
	 */
	public function logout(): bool {
		$this->forgetResolvedPayload();
		return false;
	}

	/**
	 * Decodes and caches the JWT payload for the current request.
	 *
	 * Invalid tokens, missing tokens, expired tokens, and codec failures all
	 * resolve to null instead of escaping exceptions through guard checks.
	 *
	 * @return ?JwtPayload Decoded payload for the bearer token.
	 */
	public function payload(): ?JwtPayload {
		if($this->payloadResolved===true){
			return $this->payload;
		}
		$this->payloadResolved=true;
		$token=$this->resolveToken();
		if($token===null){
			return $this->payload=null;
		}
		try{
			return $this->payload=JwtCodec::decode($token, $this->jwtConfig());
		}
		catch(\Throwable){
			return $this->payload=null;
		}
	}

	/**
	 * Returns decoded JWT claims as an associative array.
	 *
	 * @return array<string, mixed> Claims from the current payload, or an empty array when unauthenticated.
	 */
	public function claims(): array {
		$payload=$this->payload();
		return $payload!==null ? $payload->claims() : [];
	}

	/**
	 * Returns the normalized bearer token associated with the decoded JWT.
	 *
	 * @return ?string Current JWT string, or null when no payload is valid.
	 */
	public function token(): ?string {
		$payload=$this->payload();
		return $payload!==null ? $payload->token() : null;
	}

	/**
	 * Resolves the request token from a custom resolver or Authorization header.
	 *
	 * Custom resolvers run first and must return a non-blank string. Header
	 * resolution accepts standard `Bearer <token>` syntax from direct or
	 * redirected authorization server variables.
	 *
	 * @return ?string Trimmed bearer token.
	 */
	private function resolveToken(): ?string {
		$resolver=$this->config['token_resolver'] ?? null;
		if(is_callable($resolver)){
			$token=$resolver();
			if(is_string($token) && trim($token)!==''){
				return trim($token);
			}
		}
		$authorization=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if(is_string($authorization) && preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches)===1){
			return trim($matches[1]);
		}
		return null;
	}

	/**
	 * Merges framework, guard, and named-guard JWT configuration.
	 *
	 * Global JWT options are read from `DP_ACCESS_CFG['framework']['jwt']`;
	 * `guards` is removed from the base options, per-instance config overlays
	 * it, and named guard config overlays last.
	 *
	 * @return array<string, mixed> JwtCodec configuration for this guard.
	 */
	private function jwtConfig(): array {
		$config=DP_ACCESS_CFG['framework']['jwt'] ?? null;
		$jwtConfig=is_array($config) ? $config : [];
		unset($jwtConfig['guards']);
		foreach($this->config as $key=>$value){
			if(in_array($key, ['driver', 'provider'], true)){
				continue;
			}
			$jwtConfig[$key]=$value;
		}
		$guardConfigs=is_array($config['guards'] ?? null) ? $config['guards'] : [];
		if(isset($guardConfigs[$this->name]) && is_array($guardConfigs[$this->name])){
			$jwtConfig=array_replace($jwtConfig, $guardConfigs[$this->name]);
		}
		return $jwtConfig;
	}

	/**
	 * Clears cached payload and provider user state.
	 */
	private function forgetResolvedPayload(): void {
		$this->payloadResolved=false;
		$this->payload=null;
		$this->userResolved=false;
		$this->resolvedUser=null;
	}
}
