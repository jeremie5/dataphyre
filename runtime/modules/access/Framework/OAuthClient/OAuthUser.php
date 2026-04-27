<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\OAuthClient;

final class OAuthUser {

	private string $provider;
	private int|string|null $id;
	private ?string $nickname;
	private ?string $name;
	private ?string $email;
	private ?bool $email_verified;
	private ?string $avatar;
	private ?string $access_token;
	private ?string $refresh_token;
	private ?string $id_token;
	private ?string $token_type;
	private ?int $expires_in;
	private array $scopes;
	private array $attributes;
	private array $id_token_claims;
	private array $token_response;
	private array $profile_response;
	private array $callback_parameters;

	public function __construct(
		string $provider,
		int|string|null $id=null,
		?string $nickname=null,
		?string $name=null,
		?string $email=null,
		?bool $email_verified=null,
		?string $avatar=null,
		?string $access_token=null,
		?string $refresh_token=null,
		?string $id_token=null,
		?string $token_type=null,
		?int $expires_in=null,
		array $scopes=[],
		array $attributes=[],
		array $id_token_claims=[],
		array $token_response=[],
		array $profile_response=[],
		array $callback_parameters=[]
	){
		$this->provider=$provider;
		$this->id=$id;
		$this->nickname=$nickname;
		$this->name=$name;
		$this->email=$email;
		$this->email_verified=$email_verified;
		$this->avatar=$avatar;
		$this->access_token=$access_token;
		$this->refresh_token=$refresh_token;
		$this->id_token=$id_token;
		$this->token_type=$token_type;
		$this->expires_in=$expires_in;
		$this->scopes=$scopes;
		$this->attributes=$attributes;
		$this->id_token_claims=$id_token_claims;
		$this->token_response=$token_response;
		$this->profile_response=$profile_response;
		$this->callback_parameters=$callback_parameters;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function id(): int|string|null {
		return $this->id;
	}

	public function nickname(): ?string {
		return $this->nickname;
	}

	public function name(): ?string {
		return $this->name;
	}

	public function email(): ?string {
		return $this->email;
	}

	public function emailVerified(): ?bool {
		return $this->email_verified;
	}

	public function avatar(): ?string {
		return $this->avatar;
	}

	public function accessToken(): ?string {
		return $this->access_token;
	}

	public function refreshToken(): ?string {
		return $this->refresh_token;
	}

	public function idToken(): ?string {
		return $this->id_token;
	}

	public function tokenType(): ?string {
		return $this->token_type;
	}

	public function expiresIn(): ?int {
		return $this->expires_in;
	}

	public function scopes(): array {
		return $this->scopes;
	}

	public function attributes(): array {
		return $this->attributes;
	}

	public function idTokenClaims(): array {
		return $this->id_token_claims;
	}

	public function claim(string $key, mixed $default=null): mixed {
		return $this->id_token_claims[$key] ?? $default;
	}

	public function tokenResponse(): array {
		return $this->token_response;
	}

	public function profileResponse(): array {
		return $this->profile_response;
	}

	public function callbackParameters(): array {
		return $this->callback_parameters;
	}

	public function toArray(): array {
		return [
			'provider'=>$this->provider,
			'id'=>$this->id,
			'nickname'=>$this->nickname,
			'name'=>$this->name,
			'email'=>$this->email,
			'email_verified'=>$this->email_verified,
			'avatar'=>$this->avatar,
			'access_token'=>$this->access_token,
			'refresh_token'=>$this->refresh_token,
			'id_token'=>$this->id_token,
			'token_type'=>$this->token_type,
			'expires_in'=>$this->expires_in,
			'scopes'=>$this->scopes,
			'attributes'=>$this->attributes,
			'id_token_claims'=>$this->id_token_claims,
			'token_response'=>$this->token_response,
			'profile_response'=>$this->profile_response,
			'callback_parameters'=>$this->callback_parameters,
		];
	}
}
