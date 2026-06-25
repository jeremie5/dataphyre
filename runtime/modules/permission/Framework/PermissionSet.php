<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Compiled allow/deny permission matcher for roles and direct grants.
 *
 * A set stores exact grants, wildcard-prefix grants, exact denies, wildcard-prefix
 * denies, aliases, roles, and configured super permissions. Deny matches are
 * evaluated before allow matches, strict permissions skip parent/super fallback,
 * and aliases are resolved before rules enter the compiled indexes.
 */
final class PermissionSet {

	private array $allowExact=[];
	private array $denyExact=[];
	private array $allowPrefix=[];
	private array $denyPrefix=[];
	private array $allPermissions=[];
	private bool $hasWildcardSuperPermission;
	private mixed $lastAllowsManyPermissions=null;
	private ?array $lastAllowsManyContext=null;
	private ?array $lastAllowsManyResult=null;

	/**
	 * Stores compiled indexes and source role/alias metadata.
	 *
	 * @param array<int|string, mixed> $roles Role identifiers associated with the subject.
	 * @param array<string, string> $aliases Permission alias map.
	 * @param array<int, string> $superPermissions Super-permission grants that imply broad access.
	 */
	private function __construct(
		private readonly array $roles,
		private readonly array $aliases,
		private readonly array $superPermissions
	){
		$this->hasWildcardSuperPermission=in_array('*', PermissionRule::many($superPermissions), true);
	}

	/**
	 * Compiles raw permission strings into fast allow/deny lookup indexes.
	 *
	 * @param array<int, string> $permissions Permission strings, including optional negative and wildcard rules.
	 * @param array<int|string, mixed> $roles Role identifiers carried for diagnostics.
	 * @param array<string, string> $aliases Permission alias map.
	 * @param array<int, string> $superPermissions Super-permission rules, defaulting to `*`.
	 * @return self Compiled permission set.
	 */
	public static function compile(array $permissions, array $roles=[], array $aliases=[], array $superPermissions=['*']): self {
		$set=new self($roles, $aliases, $superPermissions);
		foreach($permissions as $permission){
			$set->add($permission);
		}
		return $set;
	}

	/**
	 * Returns the boolean authorization decision for one required permission.
	*
	 * @param string $requiredPermission Permission rule required by the caller.
	 * @param array<string, mixed> $context Caller context reserved for trace/reporting integrations.
	 * @return bool `true` when the compiled set allows the permission.
	 */
	public function allows(string $requiredPermission, array $context=[]): bool {
		$rule=PermissionRule::unwrap($requiredPermission);
		$permission=$this->resolveAlias($rule['permission']);
		$strict=(bool)$rule['strict'];
		if($this->match($permission, true, $strict, $rule['child_exists'])!==null){
			return false;
		}
		return $this->match($permission, false, $strict, $rule['child_exists'])!==null;
	}

	/**
	 * Explains authorization decisions for multiple required permissions.
	*
	 * @param mixed $permissions Permission string, list, or nested structure accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $context Caller context reserved for trace/reporting integrations.
	 * @return array<string, array<string, mixed>> Decision payloads keyed by normalized permission.
	 */
	public function decisions(mixed $permissions, array $context=[]): array {
		$decisions=[];
		foreach(PermissionRule::many($permissions) as $permission){
			$result=$this->explain($permission, $context);
			$decisions[$result['permission'] ?? $permission]=$result;
		}
		return $decisions;
	}

	/**
	 * Returns boolean decisions for multiple permissions.
	*
	 * @param mixed $permissions Permission string, list, or nested structure accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $context Caller context reserved for trace/reporting integrations.
	 * @return array<string, bool> Allow/deny map keyed by normalized permission.
	 */
	public function allowsMany(mixed $permissions, array $context=[]): array {
		$cacheable=(is_string($permissions) || is_scalar($permissions) || $permissions===null || (is_array($permissions) && $this->isCacheableTree($permissions)))
			&& $this->isCacheableTree($context);
		if(
			$cacheable &&
			$this->lastAllowsManyResult!==null &&
			$this->lastAllowsManyPermissions===$permissions &&
			$this->lastAllowsManyContext===$context
		){
			return $this->lastAllowsManyResult;
		}
		$allowed=[];
		foreach(PermissionRule::many($permissions) as $permission){
			$rule=PermissionRule::unwrap($permission);
			$permission=$this->resolveAlias($rule['permission']);
			$strict=(bool)$rule['strict'];
			$allowed[$permission]=$this->match($permission, true, $strict, $rule['child_exists'])===null
				&& $this->match($permission, false, $strict, $rule['child_exists'])!==null;
		}
		if($cacheable){
			$this->lastAllowsManyPermissions=$permissions;
			$this->lastAllowsManyContext=$context;
			$this->lastAllowsManyResult=$allowed;
		}
		return $allowed;
	}

	/**
	 * Filters a permission list down to only allowed permission names.
	*
	 * @param mixed $permissions Permission string, list, or nested structure accepted by `PermissionRule::many()`.
	 * @param array<string, mixed> $context Caller context reserved for trace/reporting integrations.
	 * @return array<int, string> Permissions that evaluated to allowed.
	 */
	public function filterAllowed(mixed $permissions, array $context=[]): array {
		return array_keys(array_filter($this->allowsMany($permissions, $context)));
	}

	/**
	 * Explains why one permission is allowed or denied.
	*
	 * Denies take precedence over allows. Strict rules match only exact grants or
	 * denies. Non-strict rules may fall back to super permissions, parent exact
	 * permissions, and wildcard prefixes. Child-existence checks invert the lookup
	 * direction so parent UI checks can ask whether any child permission exists.
	*
	 * @param string $requiredPermission Permission rule required by the caller.
	 * @param array<string, mixed> $context Caller context reserved for trace/reporting integrations.
	 * @return array{permission:string,allowed:bool,matched:?string,reason:string}
	 */
	public function explain(string $requiredPermission, array $context=[]): array {
		$rule=PermissionRule::unwrap($requiredPermission);
		$permission=$this->resolveAlias($rule['permission']);
		$strict=(bool)$rule['strict'];
		$denied=$this->match($permission, true, $strict, $rule['child_exists']);
		if($denied!==null){
			return [
				'permission'=>$permission,
				'allowed'=>false,
				'matched'=>$denied,
				'reason'=>'deny',
			];
		}
		$allowed=$this->match($permission, false, $strict, $rule['child_exists']);
		if($allowed!==null){
			return [
				'permission'=>$permission,
				'allowed'=>true,
				'matched'=>$allowed,
				'reason'=>'allow',
			];
		}
		return [
			'permission'=>$permission,
			'allowed'=>false,
			'matched'=>null,
			'reason'=>'missing',
		];
	}

	/**
	 * Returns the roles associated with this compiled set.
	 *
	 * @return array<int|string, mixed> Source role identifiers.
	 */
	public function roles(): array {
		return $this->roles;
	}

	/**
	 * Returns the normalized source permissions that were compiled.
	 *
	 * @return array<int, string> Permission strings, preserving negative and strict notation.
	 */
	public function permissions(): array {
		return $this->allPermissions;
	}

	/**
	 * Adds one raw permission string into the compiled indexes.
	 *
	 * @param string $permission Raw permission rule.
	 * @return void
	 */
	private function add(string $permission): void {
		$rule=PermissionRule::unwrap($permission);
		$permission=$this->resolveAlias($rule['permission']);
		if($permission===''){
			return;
		}
		$negative=(bool)$rule['negative'];
		$permission=$rule['strict'] ? '<'.$permission.'>' : $permission;
		$this->allPermissions[]=($negative ? '-' : '').$permission;
		if($rule['wildcard']){
			$prefix=substr($this->resolveAlias($rule['permission']), 0, -2);
			if($negative){
				$this->denyPrefix[$prefix]='-'.$prefix.'.*';
			}
			else{
				$this->allowPrefix[$prefix]=$prefix.'.*';
			}
			return;
		}
		$resolved=$this->resolveAlias($rule['permission']);
		if($negative){
			$this->denyExact[$resolved]='-'.$resolved;
		}
		else{
			$this->allowExact[$resolved]=$resolved;
		}
	}

	/**
	 * Finds the exact, wildcard, parent, or super rule that matches a permission.
	 *
	 * @param string $permission Normalized required permission.
	 * @param bool $deny Whether to search deny indexes instead of allow indexes.
	 * @param bool $strict Whether fallback matching is disabled.
	 * @param bool $childExists Whether the caller is asking for child permission existence.
	 * @return ?string Matched source rule, or `null`.
	 */
	private function match(string $permission, bool $deny, bool $strict, bool $childExists): ?string {
		$exact=$deny ? $this->denyExact : $this->allowExact;
		$prefixes=$deny ? $this->denyPrefix : $this->allowPrefix;
		if(isset($exact[$permission])){
			return $exact[$permission];
		}
		if($childExists){
			$base=substr($permission, 0, -2);
			if($deny){
				if(isset($exact[$permission])){
					return $exact[$permission];
				}
				foreach($prefixes as $candidate=>$source){
					if($candidate===$base || str_starts_with($base, $candidate.'.')){
						return $source;
					}
				}
				return null;
			}
			foreach($exact as $candidate=>$source){
				if(str_starts_with($candidate, $base.'.')){
					return $source;
				}
			}
			foreach($prefixes as $candidate=>$source){
				if($candidate===$base || str_starts_with($candidate, $base.'.') || str_starts_with($base, $candidate.'.')){
					return $source;
				}
			}
			return null;
		}
		if($strict){
			return null;
		}
		if($this->hasWildcardSuperPermission && isset($exact['*'])){
			return $exact['*'];
		}
		$parts=explode('.', $permission);
		array_pop($parts);
		while($parts!==[]){
			$prefix=implode('.', $parts);
			if(isset($prefixes[$prefix])){
				return $prefixes[$prefix];
			}
			if(isset($exact[$prefix])){
				return $exact[$prefix];
			}
			array_pop($parts);
		}
		return $prefixes['*'] ?? null;
	}

	/**
	 * Normalizes a permission and applies the configured alias map.
	 *
	 * @param string $permission Raw permission name.
	 * @return string Canonical permission name.
	 */
	private function resolveAlias(string $permission): string {
		$permission=PermissionRule::normalize($permission);
		return $this->aliases[$permission] ?? $permission;
	}

	/**
	 * Checks whether an input tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate input tree.
	 * @return bool True when the tree contains only scalar, null, and array values.
	 */
	private function isCacheableTree(array $values): bool {
		foreach($values as $value){
			if(is_array($value)){
				if(!$this->isCacheableTree($value)){
					return false;
				}
				continue;
			}
			if($value!==null && !is_scalar($value)){
				return false;
			}
		}
		return true;
	}
}
