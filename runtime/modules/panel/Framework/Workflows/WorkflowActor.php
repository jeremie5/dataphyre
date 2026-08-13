<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable identity and authority snapshot used by workflow and automation operations.
 */
final class WorkflowActor implements \JsonSerializable {
	/** @var list<string> */
	private array $roles;
	/** @var list<string> */
	private array $permissions;

	/**
	 * @param list<string> $roles
	 * @param list<string> $permissions
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		private readonly string $id,
		array $roles=[],
		array $permissions=[],
		private readonly array $metadata=[]
	){
		$id=trim($id);
		if($id===''){
			throw new \InvalidArgumentException('A workflow actor requires a non-empty id.');
		}
		$this->roles=self::normalizeList($roles);
		$this->permissions=self::normalizeList($permissions, false);
	}

	/** @param self|array<string,mixed>|string $actor */
	public static function from(self|array|string $actor): self {
		if($actor instanceof self){
			return $actor;
		}
		if(is_string($actor)){
			return new self($actor);
		}
		return new self(
			(string)($actor['id'] ?? $actor['actor_id'] ?? ''),
			is_array($actor['roles'] ?? null) ? $actor['roles'] : [],
			is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [],
			is_array($actor['metadata'] ?? null) ? $actor['metadata'] : []
		);
	}

	public function id(): string { return $this->id; }
	/** @return list<string> */
	public function roles(): array { return $this->roles; }
	/** @return list<string> */
	public function permissions(): array { return $this->permissions; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	public function hasRole(string $role): bool {
		$role=self::normalizeToken($role);
		return $role!=='' && (in_array('*', $this->roles, true) || in_array($role, $this->roles, true));
	}

	public function can(string $permission): bool {
		$permission=strtolower(trim($permission));
		if($permission===''){
			return false;
		}
		foreach($this->permissions as $granted){
			if($granted==='*' || $granted===$permission){
				return true;
			}
			if(str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))){
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $roles */
	public function hasAnyRole(array $roles): bool {
		if($roles===[]){
			return true;
		}
		foreach($roles as $role){
			if($this->hasRole($role)){
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $permissions */
	public function hasAllPermissions(array $permissions): bool {
		foreach($permissions as $permission){
			if(!$this->can($permission)){
				return false;
			}
		}
		return true;
	}

	public function jsonSerialize(): array {
		return ['id'=>$this->id, 'roles'=>$this->roles, 'permissions'=>$this->permissions, 'metadata'=>$this->metadata];
	}

	/** @param array<int,mixed> $values @return list<string> */
	private static function normalizeList(array $values, bool $tokens=true): array {
		$result=[];
		foreach($values as $value){
			$value=$tokens ? self::normalizeToken((string)$value) : strtolower(trim((string)$value));
			if($value!=='' && !in_array($value, $result, true)){
				$result[]=$value;
			}
		}
		return $result;
	}

	private static function normalizeToken(string $value): string {
		$value=strtolower(trim($value));
		return trim((string)preg_replace('/[^a-z0-9_*.-]+/', '_', $value), '_');
	}
}
