<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

use Dataphyre\Http\Response;

final class AuthorizationRequest {

	private string $provider;
	private string $url;
	private ?string $state;
	private ?string $code_verifier;
	private ?string $nonce;

	public function __construct(
		string $provider,
		string $url,
		?string $state=null,
		?string $code_verifier=null,
		?string $nonce=null
	){
		$this->provider=$provider;
		$this->url=$url;
		$this->state=$state;
		$this->code_verifier=$code_verifier;
		$this->nonce=$nonce;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function url(): string {
		return $this->url;
	}

	public function state(): ?string {
		return $this->state;
	}

	public function codeVerifier(): ?string {
		return $this->code_verifier;
	}

	public function nonce(): ?string {
		return $this->nonce;
	}

	public function response(int $status=302): Response {
		return new Response('', $status, [
			'Location'=>$this->url,
			'Cache-Control'=>'no-store',
		]);
	}
}
