<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Normalizes permission rule tokens used by policy checks.
 *
 * Permission rules are lowercase dot-delimited strings with optional negation,
 * strict-match wrappers, wildcard suffixes, and child-existence suffixes. This
 * helper accepts loose project input and converts it into comparable tokens used
 * by manifests, subject resolution, guards, and role imports.
 */
final class PermissionRule {

	private const NORMALIZE_CACHE_LIMIT=512;
	private const DEFINITION_CACHE_LIMIT=128;

	/** @var array<string, string> */
	private static array $normalizedCache=[];

	/** @var array<string, array<int, string>> */
	private static array $definitionCache=[];

	private static mixed $lastManyInput=null;

	/** @var array<int, string>|null */
	private static ?array $lastManyOutput=null;

	/**
	 * Normalizes one or more rule definitions into unique tokens.
	 *
	 * Strings may contain comma or whitespace separated rules. Arrays may contain
	 * nested definition maps with allow, deny, permission, or role fields. Null and
	 * false are treated as empty rule sets.
	 *
	 * @param mixed $rules String, scalar, array, definition map, null, or false rule input.
	 * @return array<int, string> Unique normalized permission tokens.
	 */
	public static function many(mixed $rules): array {
		if($rules===null || $rules===false){
			return [];
		}
		if(self::$lastManyOutput!==null && self::$lastManyInput===$rules){
			return self::$lastManyOutput;
		}
		$cacheable=is_string($rules) || (is_array($rules) && self::isCacheableDefinition($rules));
		$input=$rules;
		if(is_string($rules)){
			$rules=preg_split('/[\s,]+/', $rules) ?: [];
		}
		elseif(!is_array($rules)){
			$rules=[$rules];
		}
		$normalized=[];
		foreach($rules as $rule){
			if(is_array($rule)){
				foreach(self::fromDefinition($rule) as $nested){
					$normalized[]=$nested;
				}
				continue;
			}
			$rule=self::normalize((string)$rule);
			if($rule!==''){
				$normalized[]=$rule;
			}
		}
		$normalized=array_values(array_unique($normalized));
		if($cacheable){
			self::$lastManyInput=$input;
			self::$lastManyOutput=$normalized;
		}
		return $normalized;
	}

	/**
	 * Extracts rules from a structured permission definition.
	 *
	 * `allow`, `allows`, and `permissions` add positive rules. `deny` and
	 * `denies` add negated rules. `role`, `roles`, and `groups` are represented
	 * as `role.<name>` grants so role membership can move through the same rule
	 * pipeline as direct permissions.
	 *
	 * @param array<string, mixed> $definition Structured permission definition.
	 * @return array<int, string> Normalized rules derived from the definition.
	 */
	public static function fromDefinition(array $definition): array {
		$cacheKey=serialize($definition);
		if(isset(self::$definitionCache[$cacheKey])){
			return self::$definitionCache[$cacheKey];
		}
		$rules=[];
		foreach(['allow', 'allows', 'permissions'] as $key){
			if(array_key_exists($key, $definition)){
				$rules=array_merge($rules, self::many($definition[$key]));
			}
		}
		foreach(['deny', 'denies'] as $key){
			if(array_key_exists($key, $definition)){
				foreach(self::many($definition[$key]) as $rule){
					$rules[]='-'.ltrim($rule, '-');
				}
			}
		}
		foreach(['role', 'roles', 'groups'] as $key){
			if(array_key_exists($key, $definition)){
				foreach(self::many($definition[$key]) as $role){
					$rules[]='role.'.preg_replace('/^role\./', '', $role);
				}
			}
		}
		return self::rememberDefinition($cacheKey, self::many($rules));
	}

	/**
	 * Normalizes a single permission rule token.
	 *
	 * Separators such as `::`, `:`, `/`, and backslashes become dots. Unsupported
	 * characters collapse to dots, repeated dots collapse, and empty results are
	 * discarded. A leading `-` preserves negation, while `<rule>` preserves strict
	 * matching semantics around the normalized inner rule.
	 *
	 * @param string $rule Raw rule string.
	 * @return string Normalized token, or an empty string when no usable token remains.
	 */
	public static function normalize(string $rule): string {
		if(isset(self::$normalizedCache[$rule])){
			return self::$normalizedCache[$rule];
		}
		$original=$rule;
		$rule=strtolower(trim($rule));
		if($rule===''){
			return self::rememberNormalized($original, '');
		}
		$strict=str_starts_with($rule, '<') && str_ends_with($rule, '>');
		if($strict){
			$rule=substr($rule, 1, -1);
		}
		$negative=str_starts_with($rule, '-');
		if($negative){
			$rule=ltrim($rule, '-');
		}
		$rule=str_replace(['::', ':', '/', '\\'], '.', $rule);
		$rule=preg_replace('/[^a-z0-9._*%-]+/', '.', $rule) ?? '';
		$rule=preg_replace('/\.+/', '.', $rule) ?? '';
		$rule=trim($rule, '.');
		if($rule===''){
			return self::rememberNormalized($original, '');
		}
		return self::rememberNormalized($original, ($negative ? '-' : '').($strict ? '<'.$rule.'>' : $rule));
	}

	/**
	 * Stores a normalized rule token in the bounded exact-input cache.
	 *
	 * @param string $rule Raw rule string.
	 * @param string $normalized Normalized token.
	 * @return string Normalized token.
	 */
	private static function rememberNormalized(string $rule, string $normalized): string {
		if(count(self::$normalizedCache)>=self::NORMALIZE_CACHE_LIMIT){
			self::$normalizedCache=[];
		}
		self::$normalizedCache[$rule]=$normalized;
		return $normalized;
	}

	/**
	 * Stores a normalized structured definition result in the bounded exact-input cache.
	 *
	 * @param string $key Serialized definition cache key.
	 * @param array<int, string> $rules Normalized rule list.
	 * @return array<int, string> Normalized rule list.
	 */
	private static function rememberDefinition(string $key, array $rules): array {
		if(count(self::$definitionCache)>=self::DEFINITION_CACHE_LIMIT){
			self::$definitionCache=[];
		}
		self::$definitionCache[$key]=$rules;
		return $rules;
	}

	/**
	 * Checks whether an input definition can be safely cached by exact value.
	 *
	 * @param array<int|string, mixed> $rules Candidate rule tree.
	 * @return bool True when the tree contains only scalar/null/array values.
	 */
	private static function isCacheableDefinition(array $rules): bool {
		foreach($rules as $rule){
			if(is_array($rule)){
				if(!self::isCacheableDefinition($rule)){
					return false;
				}
				continue;
			}
			if($rule!==null && !is_scalar($rule)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Breaks a normalized rule into policy flags.
	 *
	 * The returned shape is useful for guard implementations that need to compare
	 * positive/negative rules, strict tokens, wildcard grants, and child-existence
	 * checks without repeatedly parsing the string syntax.
	 *
	 * @param string $rule Raw or normalized permission rule.
	 * @return array{permission: string, negative: bool, strict: bool, wildcard: bool, child_exists: bool}
	 */
	public static function unwrap(string $rule): array {
		$rule=self::normalize($rule);
		$negative=str_starts_with($rule, '-');
		if($negative){
			$rule=substr($rule, 1);
		}
		$strict=str_starts_with($rule, '<') && str_ends_with($rule, '>');
		if($strict){
			$rule=substr($rule, 1, -1);
		}
		return [
			'permission'=>$rule,
			'negative'=>$negative,
			'strict'=>$strict,
			'wildcard'=>str_ends_with($rule, '.*'),
			'child_exists'=>str_ends_with($rule, '.%'),
		];
	}
}
