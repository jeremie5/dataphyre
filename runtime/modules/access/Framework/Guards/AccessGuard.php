<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Guards;

use Dataphyre\Access\AuthContext;
use Dataphyre\Access\Contracts\Authenticatable;
use Dataphyre\Access\Contracts\Guard;
use Dataphyre\Access\Contracts\UserProvider;

final class AccessGuard implements Guard {

	private string $name;
	private string $auth_type;
	private ?UserProvider $provider;
	private bool $user_resolved=false;
	private mixed $resolved_user=null;

	public function __construct(string $name, string $auth_type, ?UserProvider $provider=null){
		$this->name=$name;
		$this->auth_type=strtolower(trim($auth_type));
		$this->provider=$provider;
	}

	public function name(): string {
		return $this->name;
	}

	public function authType(): string {
		return $this->auth_type;
	}

	public function check(): bool {
		return \dataphyre\access::logged_in($this->auth_type);
	}

	public function guest(): bool {
		return $this->check()===false;
	}

	public function id(): int|string|null {
		$identifier=\dataphyre\access::userid($this->auth_type);
		return ($identifier===false || $identifier===null) ? null : $identifier;
	}

	public function user(): mixed {
		if($this->user_resolved===true){
			return $this->resolved_user;
		}
		$this->user_resolved=true;
		if($this->provider===null || $this->check()===false){
			return $this->resolved_user=null;
		}
		$identifier=$this->id();
		if($identifier===null){
			return $this->resolved_user=null;
		}
		return $this->resolved_user=$this->provider->retrieveById($identifier);
	}

	public function context(): AuthContext {
		return AuthContext::capture($this->auth_type, $this->name);
	}

	public function validate(bool $cache=true): bool {
		return \dataphyre\access::validate_session($cache, $this->auth_type);
	}

	public function recover(): bool {
		$recovered=\dataphyre\access::recover_session($this->auth_type);
		if($recovered){
			$this->forgetResolvedUser();
		}
		return $recovered;
	}

	public function login(mixed $user, bool $remember=false): bool {
		$identifier=$this->resolveUserIdentifier($user);
		if($identifier===null){
			return false;
		}
		$logged_in=$this->loginUsingId($identifier, $remember);
		if($logged_in){
			$this->user_resolved=true;
			$this->resolved_user=$user;
		}
		return $logged_in;
	}

	public function loginUsingId(int|string $identifier, bool $remember=false): bool {
		$kernel_identifier=$this->normalizeKernelIdentifier($identifier);
		if($kernel_identifier===null){
			return false;
		}
		$logged_in=\dataphyre\access::create_session($kernel_identifier, $remember, $this->auth_type);
		if($logged_in){
			$this->forgetResolvedUser();
		}
		return $logged_in;
	}

	public function attempt(array $credentials, bool $remember=false): bool {
		if($this->provider===null){
			return false;
		}
		$user=$this->provider->retrieveByCredentials($credentials);
		if($user===null || $user===false){
			return false;
		}
		if($this->provider->validateCredentials($user, $credentials)===false){
			return false;
		}
		return $this->login($user, $remember);
	}

	public function logout(): bool {
		$logged_out=\dataphyre\access::disable_session($this->auth_type);
		if($logged_out){
			$this->forgetResolvedUser();
		}
		return $logged_out;
	}

	private function resolveUserIdentifier(mixed $user): int|string|null {
		if($this->provider!==null){
			$identifier=$this->provider->authIdentifier($user);
			if($identifier!==null){
				return $identifier;
			}
		}
		if($user instanceof Authenticatable){
			return $user->authIdentifier();
		}
		if(is_object($user)){
			if(method_exists($user, 'authIdentifier')){
				return $user->authIdentifier();
			}
			if(method_exists($user, 'getAuthIdentifier')){
				return $user->getAuthIdentifier();
			}
			if(isset($user->id) && (is_int($user->id) || is_string($user->id))){
				return $user->id;
			}
		}
		if(is_array($user) && isset($user['id']) && (is_int($user['id']) || is_string($user['id']))){
			return $user['id'];
		}
		if(is_int($user) || is_string($user)){
			return $user;
		}
		return null;
	}

	private function normalizeKernelIdentifier(int|string $identifier): ?int {
		if(is_int($identifier)){
			return $identifier;
		}
		if(is_string($identifier) && preg_match('/^-?\d+$/', $identifier)===1){
			return (int)$identifier;
		}
		return null;
	}

	private function forgetResolvedUser(): void {
		$this->user_resolved=false;
		$this->resolved_user=null;
	}
}
