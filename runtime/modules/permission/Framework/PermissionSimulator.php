<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Simulates permission and role changes without mutating persisted rules.
 *
 * The simulator compiles the subject's current rules, applies a normalized
 * change set in memory, compares before and after decisions for requested
 * checks, and returns the resulting delta for tooling or operator previews.
 */
final class PermissionSimulator {

	/**
	 * @var array{input:array<string,mixed>, output:array{grant_permissions:list<string>,deny_permissions:list<string>,remove_permissions:list<string>,grant_roles:list<string>,remove_roles:list<string>}}|null
	 */
	private static ?array $normalizedChangesCache=null;

	/**
	 * @var array{rules:array<string,mixed>, changes:array<string,mixed>, output:array{permissions:list<string>,roles:list<string>}}|null
	 */
	private static ?array $applyCache=null;

	/**
	 * Runs a before/after permission simulation for one subject.
	 *
	 * The returned report includes compiled decisions, changed grants and
	 * denials, original rules, simulated rules, and the normalized change set.
	 *
	 * @param mixed $subject User, role, token, or subject identifier understood by SubjectResolver.
	 * @param array{grant?:mixed,grants?:mixed,allow?:mixed,allows?:mixed,add?:mixed,add_permissions?:mixed,permissions?:mixed,deny?:mixed,denies?:mixed,deny_permissions?:mixed,remove?:mixed,removes?:mixed,revoke?:mixed,revokes?:mixed,remove_permissions?:mixed,revoke_permissions?:mixed,role?:mixed,roles?:mixed,grant_roles?:mixed,add_roles?:mixed,remove_roles?:mixed,revoke_roles?:mixed} $changes Permission and role grants, denials, and removals to simulate.
	 * @param mixed $checks Permission check or checks accepted by PermissionSet::allowsMany().
	 * @param array<string,mixed> $context Authorization context passed to rule lookup and compilation.
	 * @return array{ok:bool,subject_id:mixed,before:array<string,bool>,after:array<string,bool>,delta:array{granted:list<string>,denied:list<string>,unchanged:array<string,bool>},rules_before:array{permissions:list<string>,roles:list<string>},rules_after:array{permissions:list<string>,roles:list<string>},changes:array{grant_permissions:list<string>,deny_permissions:list<string>,remove_permissions:list<string>,grant_roles:list<string>,remove_roles:list<string>}} Simulation result.
	 */
	public static function run(mixed $subject, array $changes, mixed $checks, array $context=[]): array {
		$engine=Permission::engine();
		$beforeRules=$engine->rulesFor($subject, $context);
		$afterRules=self::apply($beforeRules, $changes);
		$before=$engine->compile($beforeRules['permissions'], $beforeRules['roles'])->allowsMany($checks, $context);
		$after=$engine->compile($afterRules['permissions'], $afterRules['roles'])->allowsMany($checks, $context);
		return [
			'ok'=>true,
			'subject_id'=>SubjectResolver::id($subject),
			'before'=>$before,
			'after'=>$after,
			'delta'=>self::delta($before, $after),
			'rules_before'=>$beforeRules,
			'rules_after'=>$afterRules,
			'changes'=>self::normalizedChanges($changes),
		];
	}

	/**
	 * Applies a normalized change set to a rule set.
	 *
	 * Removals are processed before grants and denies. Denied permissions are
	 * stored with Dataphyre's leading-minus rule form, and final permission and
	 * role arrays are unique and naturally sorted for stable comparisons.
	 *
	 * @param array{permissions?:mixed,roles?:mixed} $rules Existing subject rule set.
	 * @param array{grant?:mixed,grants?:mixed,allow?:mixed,allows?:mixed,add?:mixed,add_permissions?:mixed,permissions?:mixed,deny?:mixed,denies?:mixed,deny_permissions?:mixed,remove?:mixed,removes?:mixed,revoke?:mixed,revokes?:mixed,remove_permissions?:mixed,revoke_permissions?:mixed,role?:mixed,roles?:mixed,grant_roles?:mixed,add_roles?:mixed,remove_roles?:mixed,revoke_roles?:mixed} $changes Raw change aliases such as grant, deny, remove, role, and remove_roles.
	 * @return array{permissions:list<string>,roles:list<string>} Simulated subject rule set.
	 */
	public static function apply(array $rules, array $changes): array {
		if(
			self::$applyCache!==null &&
			self::$applyCache['rules']===$rules &&
			self::$applyCache['changes']===$changes
		){
			return self::$applyCache['output'];
		}
		$cacheable=self::isCacheableTree($rules) && self::isCacheableTree($changes);
		$normalized=self::normalizedChanges($changes);
		$permissions=PermissionRule::many($rules['permissions'] ?? []);
		$roles=PermissionRule::many($rules['roles'] ?? []);
		if($normalized['remove_permissions']!==[]){
			$remove=array_fill_keys(array_map([self::class, 'normalizedPermissionName'], $normalized['remove_permissions']), true);
			$filteredPermissions=[];
			foreach($permissions as $rule){
				$permission=self::normalizedPermissionName($rule);
				if(!isset($remove[$permission])){
					$filteredPermissions[]=$rule;
				}
			}
			$permissions=$filteredPermissions;
		}
		if($normalized['remove_roles']!==[]){
			$removeRoles=array_fill_keys($normalized['remove_roles'], true);
			$filteredRoles=[];
			foreach($roles as $role){
				if(!isset($removeRoles[$role])){
					$filteredRoles[]=$role;
				}
			}
			$roles=$filteredRoles;
		}
		$permissions=array_values(array_unique(array_merge($permissions, $normalized['grant_permissions'], array_map(static fn(string $permission): string => '-'.ltrim($permission, '-'), $normalized['deny_permissions']))));
		$roles=array_values(array_unique(array_merge($roles, $normalized['grant_roles'])));
		sort($permissions, SORT_NATURAL);
		sort($roles, SORT_NATURAL);
		$output=[
			'permissions'=>$permissions,
			'roles'=>$roles,
		];
		if($cacheable){
			self::$applyCache=[
				'rules'=>$rules,
				'changes'=>$changes,
				'output'=>$output,
			];
		}
		return $output;
	}

	/**
	 * Extracts the comparable permission name from a token already normalized by PermissionRule::many().
	 *
	 * @param string $rule Normalized permission rule token.
	 * @return string Permission name without negation or strict-match wrappers.
	 */
	private static function normalizedPermissionName(string $rule): string {
		if($rule!=='' && $rule[0]==='-'){
			$rule=substr($rule, 1);
		}
		if(str_starts_with($rule, '<') && str_ends_with($rule, '>')){
			return substr($rule, 1, -1);
		}
		return $rule;
	}

	/**
	 * Converts supported change aliases into canonical permission simulator buckets.
	 *
	 * @param array{grant?:mixed,grants?:mixed,allow?:mixed,allows?:mixed,add?:mixed,add_permissions?:mixed,permissions?:mixed,deny?:mixed,denies?:mixed,deny_permissions?:mixed,remove?:mixed,removes?:mixed,revoke?:mixed,revokes?:mixed,remove_permissions?:mixed,revoke_permissions?:mixed,role?:mixed,roles?:mixed,grant_roles?:mixed,add_roles?:mixed,remove_roles?:mixed,revoke_roles?:mixed} $changes Raw operator or API change set.
	 * @return array{grant_permissions:list<string>,deny_permissions:list<string>,remove_permissions:list<string>,grant_roles:list<string>,remove_roles:list<string>}
	 */
	private static function normalizedChanges(array $changes): array {
		if(self::$normalizedChangesCache!==null && self::$normalizedChangesCache['input']===$changes){
			return self::$normalizedChangesCache['output'];
		}
		$grantPermissions=[];
		foreach(['grant', 'grants', 'allow', 'allows', 'add', 'add_permissions', 'permissions'] as $key){
			if(array_key_exists($key, $changes)){
				$grantPermissions=array_merge($grantPermissions, PermissionRule::many($changes[$key]));
			}
		}
		$denyPermissions=[];
		foreach(['deny', 'denies', 'deny_permissions'] as $key){
			if(array_key_exists($key, $changes)){
				$denyPermissions=array_merge($denyPermissions, array_map(static fn(string $rule): string => ltrim($rule, '-'), PermissionRule::many($changes[$key])));
			}
		}
		$removePermissions=[];
		foreach(['remove', 'removes', 'revoke', 'revokes', 'remove_permissions', 'revoke_permissions'] as $key){
			if(array_key_exists($key, $changes)){
				$removePermissions=array_merge($removePermissions, PermissionRule::many($changes[$key]));
			}
		}
		$grantRoles=[];
		foreach(['role', 'roles', 'grant_roles', 'add_roles'] as $key){
			if(array_key_exists($key, $changes)){
				$grantRoles=array_merge($grantRoles, PermissionRule::many($changes[$key]));
			}
		}
		$removeRoles=[];
		foreach(['remove_roles', 'revoke_roles'] as $key){
			if(array_key_exists($key, $changes)){
				$removeRoles=array_merge($removeRoles, PermissionRule::many($changes[$key]));
			}
		}
		$normalized=[
			'grant_permissions'=>self::sortedNormalized($grantPermissions),
			'deny_permissions'=>self::sortedNormalized($denyPermissions),
			'remove_permissions'=>self::sortedNormalized($removePermissions),
			'grant_roles'=>self::sortedNormalized($grantRoles),
			'remove_roles'=>self::sortedNormalized($removeRoles),
		];
		self::$normalizedChangesCache=[
			'input'=>$changes,
			'output'=>$normalized,
		];
		return $normalized;
	}

	/**
	 * Compares boolean permission decisions before and after the simulated edit.
	 *
	 * @param array<string,bool> $before Permission decisions from the current rules.
	 * @param array<string,bool> $after Permission decisions from the simulated rules.
	 * @return array{granted:list<string>,denied:list<string>,unchanged:array<string,bool>} Decision delta.
	 */
	private static function delta(array $before, array $after): array {
		$delta=[
			'granted'=>[],
			'denied'=>[],
			'unchanged'=>[],
		];
		foreach(array_unique(array_merge(array_keys($before), array_keys($after))) as $permission){
			$was=(bool)($before[$permission] ?? false);
			$is=(bool)($after[$permission] ?? false);
			if($was===false && $is===true){
				$delta['granted'][]=$permission;
			}
			elseif($was===true && $is===false){
				$delta['denied'][]=$permission;
			}
			else{
				$delta['unchanged'][$permission]=$is;
			}
		}
		sort($delta['granted'], SORT_NATURAL);
		sort($delta['denied'], SORT_NATURAL);
		ksort($delta['unchanged'], SORT_NATURAL);
		return $delta;
	}

	/**
	 * Returns a stable sorted list from rule strings already normalized by PermissionRule::many().
	 *
	 * @param list<string> $rules Normalized permission or role rule strings.
	 * @return list<string> Natural-sorted unique rule strings.
	 */
	private static function sortedNormalized(array $rules): array {
		$rules=array_values(array_unique($rules));
		sort($rules, SORT_NATURAL);
		return $rules;
	}

	/**
	 * Checks whether an input tree can be cached by exact value.
	 *
	 * @param array<int|string, mixed> $values Candidate input tree.
	 * @return bool True when the tree contains only scalar/null/array values.
	 */
	private static function isCacheableTree(array $values): bool {
		foreach($values as $value){
			if(is_array($value)){
				if(!self::isCacheableTree($value)){
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
