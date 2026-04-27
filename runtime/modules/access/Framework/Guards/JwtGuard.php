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

final class JwtGuard implements Guard {

	private string $name;
	private array $config;
	private ?UserProvider $provider;
	private bool $payload_resolved=false;
	private ?JwtPayload $payload=null;
	private mixed $resolved_user=null;
	private bool $user_resolved=false;

	public function __construct(string $name, array $config=[], ?UserProvider $provider=null){
		$this->name=$name;
		$this->config=$config;
		$this->provider=$provider;
	}

	public function name(): string {
		return $this->name;
	}

	public function authType(): string {
		return AuthType::JWT;
	}

	public function check(): bool {
		return $this->payload()!==null;
	}

	public function guest(): bool {
		return $this->check()===false;
	}

	public function id(): int|string|null {
		$payload=$this->payload();
		if($payload===null){
			return null;
		}
		$claim=$payload->claim((string)($this->config['subject_claim'] ?? 'sub'));
		return (is_int($claim) || is_string($claim)) ? $claim : null;
	}

	public function user(): mixed {
		if($this->user_resolved===true){
			return $this->resolved_user;
		}
		$this->user_resolved=true;
		$payload=$this->payload();
		if($payload===null){
			return $this->resolved_user=null;
		}
		if($this->provider===null){
			return $this->resolved_user=$payload;
		}
		$identifier=$this->id();
		if($identifier===null){
			return $this->resolved_user=null;
		}
		return $this->resolved_user=$this->provider->retrieveById($identifier);
	}

	public function context(): AuthContext {
		return AuthContext::capture($this->authType(), $this->name);
	}

	public function validate(bool $cache=true): bool {
		if($cache===false){
			$this->forgetResolvedPayload();
		}
		return $this->check();
	}

	public function recover(): bool {
		return $this->check();
	}

	public function login(mixed $user, bool $remember=false): bool {
		return false;
	}

	public function loginUsingId(int|string $identifier, bool $remember=false): bool {
		return false;
	}

	public function attempt(array $credentials, bool $remember=false): bool {
		return false;
	}

	public function logout(): bool {
		$this->forgetResolvedPayload();
		return false;
	}

	public function payload(): ?JwtPayload {
		if($this->payload_resolved===true){
			return $this->payload;
		}
		$this->payload_resolved=true;
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

	public function claims(): array {
		$payload=$this->payload();
		return $payload!==null ? $payload->claims() : [];
	}

	public function token(): ?string {
		$payload=$this->payload();
		return $payload!==null ? $payload->token() : null;
	}

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

	private function jwtConfig(): array {
		$config=DP_ACCESS_CFG['framework']['jwt'] ?? null;
		$jwt_config=is_array($config) ? $config : [];
		unset($jwt_config['guards']);
		foreach($this->config as $key=>$value){
			if(in_array($key, ['driver', 'provider'], true)){
				continue;
			}
			$jwt_config[$key]=$value;
		}
		$guard_configs=is_array($config['guards'] ?? null) ? $config['guards'] : [];
		if(isset($guard_configs[$this->name]) && is_array($guard_configs[$this->name])){
			$jwt_config=array_replace($jwt_config, $guard_configs[$this->name]);
		}
		return $jwt_config;
	}

	private function forgetResolvedPayload(): void {
		$this->payload_resolved=false;
		$this->payload=null;
		$this->user_resolved=false;
		$this->resolved_user=null;
	}
}
