<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Providers;

use Dataphyre\Access\Contracts\Authenticatable;
use Dataphyre\Access\Contracts\UserProvider;

final class CallbackUserProvider implements UserProvider {

	private $retrieve_by_id;
	private $retrieve_by_credentials;
	private $validate_credentials;
	private $auth_identifier;

	public function __construct(
		callable|string|array|null $retrieve_by_id=null,
		callable|string|array|null $retrieve_by_credentials=null,
		callable|string|array|null $validate_credentials=null,
		callable|string|array|null $auth_identifier=null
	){
		$this->retrieve_by_id=$retrieve_by_id;
		$this->retrieve_by_credentials=$retrieve_by_credentials;
		$this->validate_credentials=$validate_credentials;
		$this->auth_identifier=$auth_identifier;
	}

	public static function fromConfig(array $config): self {
		return new self(
			$config['retrieve_by_id'] ?? null,
			$config['retrieve_by_credentials'] ?? null,
			$config['validate_credentials'] ?? null,
			$config['auth_identifier'] ?? null
		);
	}

	public function retrieveById(int|string $identifier): mixed {
		if($this->retrieve_by_id===null){
			return null;
		}
		return ($this->retrieve_by_id)($identifier);
	}

	public function retrieveByCredentials(array $credentials): mixed {
		if($this->retrieve_by_credentials===null){
			return null;
		}
		return ($this->retrieve_by_credentials)($credentials);
	}

	public function validateCredentials(mixed $user, array $credentials): bool {
		if($this->validate_credentials===null){
			return $user!==null && $user!==false;
		}
		return (bool)($this->validate_credentials)($user, $credentials);
	}

	public function authIdentifier(mixed $user): int|string|null {
		if($this->auth_identifier!==null){
			return ($this->auth_identifier)($user);
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
			if(isset($user->id)){
				return is_int($user->id) || is_string($user->id) ? $user->id : null;
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
}
