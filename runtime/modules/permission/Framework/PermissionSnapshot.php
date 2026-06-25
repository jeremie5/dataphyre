<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Captures and compares permission decisions for a subject.
 *
 * Snapshots make authorization state inspectable by recording subject identity, roles, rules,
 * requested permissions, allow/deny lists, and optional explanations. Diffs then show grants,
 * denials, unchanged decisions, role changes, and rule changes between two snapshots.
 */
final class PermissionSnapshot {

	/**
	 * @var array{left:array<string,mixed>, right:array<string,mixed>, report:array<string,mixed>}|null
	 */
	private static ?array $diffCache=null;

	/**
	 * Builds a permission decision snapshot for one subject.
	 *
	 * Permissions can be a catalog payload, iterable rows, or any input accepted by
	 * `PermissionRule::many()`. Decision ordering is normalized for stable diagnostics.
	 *
	 * @param mixed $subject Subject passed to the permission engine.
	 * @param mixed $permissions Permission catalog or permission input.
	 * @param array<string, mixed> $context Policy context.
	 * @param array{include_explain?:bool, include_generated_at?:bool} $options Snapshot options.
	 * @return array<string, mixed> Stable snapshot payload.
	 */
	public static function subject(mixed $subject, mixed $permissions, array $context=[], array $options=[]): array {
		$options=array_replace([
			'include_explain'=>false,
			'include_generated_at'=>false,
		], $options);
		$permissions=self::permissions($permissions);
		$engine=Permission::engine();
		$rules=$engine->rulesFor($subject, $context);
		$decisions=$engine->compile($rules['permissions'], $rules['roles'])->decisions($permissions, $context);
		$snapshot=[
			'version'=>1,
			'subject_id'=>SubjectResolver::id($subject),
			'roles'=>$rules['roles'],
			'rules'=>$rules['permissions'],
			'allowed'=>[],
			'denied'=>[],
			'decisions'=>[],
		];
		if(($options['include_generated_at'] ?? false)===true){
			$snapshot['generated_at']=gmdate('c');
		}
		foreach($decisions as $permission=>$decision){
			$allowed=($decision['allowed'] ?? false)===true;
			$snapshot['decisions'][$permission]=$allowed;
			if($allowed){
				$snapshot['allowed'][]=$permission;
			}
			else{
				$snapshot['denied'][]=$permission;
			}
			if(($options['include_explain'] ?? false)===true){
				$snapshot['explain'][$permission]=$decision;
			}
		}
		sort($snapshot['allowed'], SORT_NATURAL);
		sort($snapshot['denied'], SORT_NATURAL);
		ksort($snapshot['decisions'], SORT_NATURAL);
		if(isset($snapshot['explain'])){
			ksort($snapshot['explain'], SORT_NATURAL);
		}
		return $snapshot;
	}

	/**
	 * Compares two permission snapshots.
	 *
	 * @param array<string, mixed> $left Baseline snapshot.
	 * @param array<string, mixed> $right Comparison snapshot.
	 * @return array{ok:bool, granted:array<int, string>, denied:array<int, string>, unchanged:array<string, bool>, role_changes:array<string, array<int, string>>, rule_changes:array<string, array<int, string>>} Snapshot diff report.
	 */
	public static function diff(array $left, array $right): array {
		if(
			self::$diffCache!==null &&
			self::$diffCache['left']===$left &&
			self::$diffCache['right']===$right
		){
			return self::$diffCache['report'];
		}
		$cacheable=self::isCacheableTree($left) && self::isCacheableTree($right);
		$leftDecisions=self::decisionMap($left);
		$rightDecisions=self::decisionMap($right);
		$granted=[];
		$denied=[];
		$unchanged=[];
		foreach(array_values(array_unique(array_merge(array_keys($leftDecisions), array_keys($rightDecisions)))) as $permission){
			$before=(bool)($leftDecisions[$permission] ?? false);
			$after=(bool)($rightDecisions[$permission] ?? false);
			if($before===false && $after===true){
				$granted[]=$permission;
			}
			elseif($before===true && $after===false){
				$denied[]=$permission;
			}
			else{
				$unchanged[$permission]=$after;
			}
		}
		sort($granted, SORT_NATURAL);
		sort($denied, SORT_NATURAL);
		ksort($unchanged, SORT_NATURAL);
		$leftRoles=self::normalizedRuleList($left['roles'] ?? []);
		$rightRoles=self::normalizedRuleList($right['roles'] ?? []);
		$leftRules=self::normalizedRuleList($left['rules'] ?? []);
		$rightRules=self::normalizedRuleList($right['rules'] ?? []);
		$report=[
			'ok'=>$granted===[] && $denied===[],
			'granted'=>$granted,
			'denied'=>$denied,
			'unchanged'=>$unchanged,
			'role_changes'=>[
				'added'=>array_values(array_diff($rightRoles, $leftRoles)),
				'removed'=>array_values(array_diff($leftRoles, $rightRoles)),
			],
			'rule_changes'=>[
				'added'=>array_values(array_diff($rightRules, $leftRules)),
				'removed'=>array_values(array_diff($leftRules, $rightRules)),
			],
		];
		if($cacheable){
			self::$diffCache=[
				'left'=>$left,
				'right'=>$right,
				'report'=>$report,
			];
		}
		return $report;
	}

	/**
	 * Normalizes requested permission inputs into a sorted permission list.
	 *
	 * @param mixed $permissions Permission catalog or permission input.
	 * @return array<int, string> Unique normalized permission names.
	 */
	private static function permissions(mixed $permissions): array {
		if(is_array($permissions) && is_array($permissions['catalog'] ?? null)){
			$permissions=$permissions['catalog'];
		}
		$result=[];
		foreach(is_array($permissions) ? $permissions : PermissionRule::many($permissions) as $key=>$row){
			$value=null;
			if(is_array($row)){
				$value=$row['permission'] ?? $row['name'] ?? null;
			}
			elseif(is_string($row)){
				$value=$row;
			}
			elseif(is_string($key)){
				$value=$key;
			}
			$permission=PermissionRule::normalize((string)$value);
			if($permission!==''){
				$result[]=$permission;
			}
		}
		$result=array_values(array_unique($result));
		sort($result, SORT_NATURAL);
		return $result;
	}

	/**
	 * Extracts a permission decision map from new or legacy snapshot shapes.
	 *
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 * @return array<string, bool> Permission decisions keyed by permission name.
	 */
	private static function decisionMap(array $snapshot): array {
		if(is_array($snapshot['decisions'] ?? null)){
			$decisions=$snapshot['decisions'];
			$booleanMap=true;
			foreach($decisions as $permission=>$decision){
				if(!is_string($permission) || !is_bool($decision)){
					$booleanMap=false;
					break;
				}
			}
			if($booleanMap){
				return $decisions;
			}
			$map=[];
			foreach($decisions as $permission=>$decision){
				if(is_array($decision)){
					$map[(string)$permission]=($decision['allowed'] ?? false)===true;
				}
				else{
					$map[(string)$permission]=(bool)$decision;
				}
			}
			return $map;
		}
		$map=[];
		foreach(PermissionRule::many($snapshot['allowed'] ?? []) as $permission){
			$map[$permission]=true;
		}
		foreach(PermissionRule::many($snapshot['denied'] ?? []) as $permission){
			$map[$permission]=false;
		}
		return $map;
	}

	/**
	 * Reuses simple normalized string lists without invoking the full rule parser.
	 *
	 * @param mixed $rules Raw or normalized rule input.
	 * @return array<int, string> Unique normalized permission tokens.
	 */
	private static function normalizedRuleList(mixed $rules): array {
		if(!is_array($rules)){
			return PermissionRule::many($rules);
		}
		$result=[];
		foreach($rules as $rule){
			if(!is_string($rule) || $rule===''){
				return PermissionRule::many($rules);
			}
			$token=$rule;
			if($token[0]==='-'){
				$token=substr($token, 1);
				if($token==='' || $token[0]==='-'){
					return PermissionRule::many($rules);
				}
			}
			if(
				$token[0]==='.' ||
				str_ends_with($token, '.') ||
				str_contains($token, '..') ||
				strpbrk($token, ':/\\')!==false ||
				strtolower($token)!==$token ||
				preg_match('/[^a-z0-9._*%-]/', $token)
			){
				return PermissionRule::many($rules);
			}
			$result[]=$rule;
		}
		return array_values(array_unique($result));
	}

	/**
	 * Checks whether an array tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate tree.
	 * @return bool True when the tree contains only scalar, null, and array values.
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
