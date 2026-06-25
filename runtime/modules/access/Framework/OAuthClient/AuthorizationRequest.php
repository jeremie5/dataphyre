<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Http\Response;

/**
 * Carries an OAuth authorization redirect and its verification material.
 *
 * AuthorizationRequest is produced before the browser leaves the application.
 * It stores the provider name, redirect URL, optional CSRF state, PKCE code
 * verifier, and OpenID Connect nonce so controllers can both redirect the user
 * and persist the values required to validate the callback.
 */
final class AuthorizationRequest {

	/** OAuth provider identifier that produced the redirect. */
	private string $provider;

	/** Fully composed authorization endpoint URL. */
	private string $url;

	/** Optional CSRF state value that must match on callback. */
	private ?string $state;

	/** Optional PKCE code verifier to exchange with the returned authorization code. */
	private ?string $codeVerifier;

	/** Optional OpenID Connect nonce for ID-token replay protection. */
	private ?string $nonce;

	/**
	 * Stores the redirect URL and callback verification values.
	 *
	 * This object does not persist secrets by itself. Callers must store state,
	 * code verifier, and nonce in the session or another trusted correlation
	 * store before returning the redirect response.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $url Authorization endpoint URL.
	 * @param ?string $state CSRF state value.
	 * @param ?string $codeVerifier PKCE verifier corresponding to the challenge.
	 * @param ?string $nonce OpenID Connect nonce.
	 */
	public function __construct(
		string $provider,
		string $url,
		?string $state=null,
		?string $codeVerifier=null,
		?string $nonce=null
	){
		$this->provider=$provider;
		$this->url=$url;
		$this->state=$state;
		$this->codeVerifier=$codeVerifier;
		$this->nonce=$nonce;
	}

	/**
	 * Returns the provider that created the authorization request.
	 *
	 * @return string Provider identifier.
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Returns the authorization redirect URL.
	 *
	 * @return string Absolute provider authorization URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Returns the callback state value.
	 *
	 * @return ?string CSRF state value, or null when state validation is not used.
	 */
	public function state(): ?string {
		return $this->state;
	}

	/**
	 * Returns the PKCE code verifier.
	 *
	 * @return ?string Verifier to use during token exchange, or null when PKCE is not active.
	 */
	public function codeVerifier(): ?string {
		return $this->codeVerifier;
	}

	/**
	 * Returns the OpenID Connect nonce.
	 *
	 * @return ?string Nonce to validate against the ID token, or null when unused.
	 */
	public function nonce(): ?string {
		return $this->nonce;
	}

	/**
	 * Builds the HTTP redirect response for the authorization URL.
	 *
	 * The response is intentionally empty and no-store so intermediaries should
	 * not cache the authorization redirect. The caller controls whether to use
	 * 302, 303, or another redirect status.
	 *
	 * @param int $status HTTP redirect status code.
	 * @return Response Redirect response with Location and Cache-Control headers.
	 */
	public function response(int $status=302): Response {
		return new Response('', $status, [
			'Location'=>$this->url,
			'Cache-Control'=>'no-store',
		]);
	}
}
