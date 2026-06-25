<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission;

/**
 * Analyzes permission rule sets for redundancy and conflicts.
 *
 * PermissionOptimizer normalizes loose rule input, reports duplicate grants,
 * positive/negative conflicts, and rules shadowed by broader grants, then returns
 * an optimized sorted rule list suitable for manifests, role editors, and audit
 * tooling.
 */
final class PermissionOptimizer {

	private static array|string|null $lastAnalyzeInput=null;

	/** @var array<string, mixed>|null */
	private static ?array $lastAnalyzeOutput=null;

	/**
	 * Builds an optimization report for a rule set.
	 *
	 * The report preserves input counts, unique counts, optimized counts, removed
	 * shadowed rules, and informational or warning findings so callers can explain
	 * how the final permission set changed.
	 *
	 * @param array|string $rules Raw permission rules, comma/space separated string, or structured rule definitions.
	 * @return array{ok: bool, input_count: int, unique_count: int, optimized_count: int, optimized: array<int, string>, findings: array<int, array<string, mixed>>, removed: array<int, string>}
	 */
	public static function analyze(array|string $rules): array {
		$cacheable=is_string($rules) || self::isCacheableRuleTree($rules);
		if($cacheable && self::$lastAnalyzeOutput!==null && self::$lastAnalyzeInput===$rules){
			return self::$lastAnalyzeOutput;
		}
		$input=$rules;
		$normalized=self::normalizedWithDuplicates($rules);
		$unique=[];
		$seen=[];
		foreach($normalized as $rule){
			if(isset($seen[$rule])){
				continue;
			}
			$seen[$rule]=true;
			$unique[]=$rule;
		}
		$findings=[];
		foreach(array_count_values($normalized) as $rule=>$count){
			if($count>1){
				$findings[]=self::finding('duplicate_rule', 'info', "Rule '{$rule}' appears {$count} times.", ['rule'=>$rule, 'count'=>$count]);
			}
		}
		$parsedRules=self::parseUnique($unique);
		$shadowCandidates=self::shadowCandidates($parsedRules);
		foreach($unique as $rule){
			$parsed=$parsedRules[$rule] ?? PermissionRule::unwrap($rule);
			$permission=(string)($parsed['permission'] ?? '');
			if($permission===''){
				continue;
			}
			$opposite=($parsed['negative'] ? '' : '-').$permission;
			if($parsed['negative']===false && isset($seen[$opposite])){
				$findings[]=self::finding('conflicting_rule', 'warning', "Permission '{$permission}' is both granted and denied.", ['permission'=>$permission]);
			}
			if(($shadow=self::shadowedByParsed($rule, $parsedRules, $shadowCandidates))!==null){
				$findings[]=self::finding('shadowed_rule', 'info', "Rule '{$rule}' is already covered by '{$shadow}'.", ['rule'=>$rule, 'covered_by'=>$shadow]);
			}
		}
		$optimized=self::optimizeUnique($unique, $parsedRules, $shadowCandidates);
		$report=[
			'ok'=>!self::hasSeverity($findings, ['warning', 'error']),
			'input_count'=>count($normalized),
			'unique_count'=>count($unique),
			'optimized_count'=>count($optimized),
			'optimized'=>$optimized,
			'findings'=>$findings,
			'removed'=>array_values(array_diff($unique, $optimized)),
		];
		if($cacheable){
			self::$lastAnalyzeInput=$input;
			self::$lastAnalyzeOutput=$report;
		}
		return $report;
	}

	/**
	 * Returns the sorted optimized rule list.
	 *
	 * Optimization removes rules covered by a broader rule with the same polarity;
	 * it never removes child-existence checks because those have distinct
	 * semantics from wildcard grants.
	 *
	 * @param array|string $rules Raw permission rules or structured rule definitions.
	 * @return array<int, string> Sorted optimized permission tokens.
	 */
	public static function optimize(array|string $rules): array {
		$unique=PermissionRule::many($rules);
		return self::optimizeUnique($unique);
	}

	/**
	 * Returns the sorted optimized rule list from already-normalized unique rules.
	 *
	 * @param array<int, string> $unique Normalized unique permission tokens.
	 * @param ?array<string, array<string, mixed>> $parsedRules Parsed candidate rules keyed by token.
	 * @param ?array<int, array<string, array<string, mixed>>> $shadowCandidates Same-polarity candidate map.
	 * @return array<int, string> Sorted optimized permission tokens.
	 */
	private static function optimizeUnique(array $unique, ?array $parsedRules=null, ?array $shadowCandidates=null): array {
		$parsedRules ??= self::parseUnique($unique);
		$shadowCandidates ??= self::shadowCandidates($parsedRules);
		$optimized=[];
		foreach($unique as $rule){
			if(self::shadowedByParsed($rule, $parsedRules, $shadowCandidates)!==null){
				continue;
			}
			$optimized[]=$rule;
		}
		sort($optimized, SORT_NATURAL);
		return $optimized;
	}

	/**
	 * Parses unique rules once for repeated shadow checks.
	 *
	 * @param array<int, string> $unique Normalized unique permission tokens.
	 * @return array<string, array{permission: string, negative: bool, strict: bool, wildcard: bool, child_exists: bool}>
	 */
	private static function parseUnique(array $unique): array {
		$parsed=[];
		foreach($unique as $rule){
			$parsed[$rule]=PermissionRule::unwrap($rule);
		}
		return $parsed;
	}

	/**
	 * Groups rules that can cover other rules by polarity.
	 *
	 * @param array<string, array<string, mixed>> $parsedRules Parsed candidate rules keyed by rule token.
	 * @return array<int, array<string, array<string, mixed>>> Covering candidates keyed by negative flag.
	 */
	private static function shadowCandidates(array $parsedRules): array {
		$candidates=[
			0=>[],
			1=>[],
		];
		foreach($parsedRules as $rule=>$parsed){
			$permission=(string)($parsed['permission'] ?? '');
			if($permission==='' || ($parsed['child_exists'] ?? false)===true){
				continue;
			}
			if($permission==='*'){
				$parsed['_covers_all']=true;
				$parsed['_covers_wildcard']=false;
				$parsed['_covers_prefix']='';
			}elseif(str_ends_with($permission, '.*')){
				$parsed['_covers_all']=false;
				$parsed['_covers_wildcard']=true;
				$parsed['_covers_prefix']=substr($permission, 0, -2);
			}else{
				$parsed['_covers_all']=false;
				$parsed['_covers_wildcard']=false;
				$parsed['_covers_prefix']=$permission.'.';
			}
			$candidates[($parsed['negative'] ?? false) ? 1 : 0][$rule]=$parsed;
		}
		return $candidates;
	}

	/**
	 * Builds optimization reports for multiple roles.
	 *
	 * Empty role names are ignored after normalization. Output is sorted by role
	 * name for deterministic documentation and manifest rendering.
	 *
	 * @param array<string, mixed> $roles Role-to-rules map.
	 * @return array<string, array<string, mixed>> Optimization report keyed by role name.
	 */
	public static function roles(array $roles): array {
		$reports=[];
		foreach($roles as $role=>$rules){
			$role=PermissionRule::normalize((string)$role);
			if($role===''){
				continue;
			}
			$reports[$role]=self::analyze($rules);
		}
		ksort($reports, SORT_NATURAL);
		return $reports;
	}

	/**
	 * Finds a same-polarity rule that already covers a rule.
	 *
	 * @param string $rule Normalized rule being tested.
	 * @param array<int, string> $rules Candidate normalized rules.
	 * @return ?string Covering rule, or null when the rule is not shadowed.
	 */
	private static function shadowedBy(string $rule, array $rules): ?string {
		return self::shadowedByParsed($rule, self::parseUnique($rules));
	}

	/**
	 * Finds a same-polarity covering rule using pre-parsed rule metadata.
	 *
	 * @param string $rule Normalized rule being tested.
	 * @param array<string, array<string, mixed>> $parsedRules Parsed candidate rules keyed by rule token.
	 * @param ?array<int, array<string, array<string, mixed>>> $shadowCandidates Same-polarity candidate map.
	 * @return ?string Covering rule, or null when the rule is not shadowed.
	 */
	private static function shadowedByParsed(string $rule, array $parsedRules, ?array $shadowCandidates=null): ?string {
		$parsed=$parsedRules[$rule] ?? PermissionRule::unwrap($rule);
		$permission=(string)($parsed['permission'] ?? '');
		$negative=(bool)($parsed['negative'] ?? false);
		if($permission==='' || $parsed['child_exists']===true){
			return null;
		}
		$shadowCandidates ??= self::shadowCandidates($parsedRules);
		foreach($shadowCandidates[$negative ? 1 : 0] as $candidate=>$candidateParsed){
			if($candidate===$rule){
				continue;
			}
			if(($candidateParsed['_covers_all'] ?? false)===true){
				return $candidate;
			}
			$candidatePrefix=(string)($candidateParsed['_covers_prefix'] ?? '');
			if(($candidateParsed['_covers_wildcard'] ?? false)===true){
				if($permission===$candidatePrefix || str_starts_with($permission, $candidatePrefix.'.')){
					return $candidate;
				}
				continue;
			}
			if($candidatePrefix!=='' && str_starts_with($permission, $candidatePrefix)){
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Normalizes rules while preserving duplicate entries.
	 *
	 * @param array|string $rules Raw rule input.
	 * @return array<int, string> Normalized rules in input order, including duplicates.
	 */
	private static function normalizedWithDuplicates(array|string $rules): array {
		if(is_string($rules)){
			$rules=preg_split('/[\s,]+/', $rules) ?: [];
		}
		$normalized=[];
		foreach($rules as $rule){
			if(is_array($rule)){
				foreach(PermissionRule::fromDefinition($rule) as $nested){
					$normalized[]=$nested;
				}
				continue;
			}
			$rule=PermissionRule::normalize((string)$rule);
			if($rule!==''){
				$normalized[]=$rule;
			}
		}
		return $normalized;
	}

	/**
	 * Creates a permission optimizer finding payload.
	 *
	 * @param string $type Stable finding type.
	 * @param string $severity Finding severity.
	 * @param string $message Human-readable finding.
	 * @param array<string, mixed> $context Additional finding context.
	 * @return array{type: string, severity: string, message: string, context: array<string, mixed>}
	 */
	private static function finding(string $type, string $severity, string $message, array $context=[]): array {
		return [
			'type'=>$type,
			'severity'=>$severity,
			'message'=>$message,
			'context'=>$context,
		];
	}

	/**
	 * Reports whether any finding has one of the requested severities.
	 *
	 * @param array<int, array<string, mixed>> $findings Finding payloads.
	 * @param array<int, string> $severities Severities to match.
	 * @return bool True when a matching severity is present.
	 */
	private static function hasSeverity(array $findings, array $severities): bool {
		foreach($findings as $finding){
			if(in_array((string)($finding['severity'] ?? ''), $severities, true)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether a rule tree can be cached by exact value.
	 *
	 * @param array<int|string, mixed> $rules Candidate rule tree.
	 * @return bool True when the tree contains only scalar/null/array values.
	 */
	private static function isCacheableRuleTree(array $rules): bool {
		foreach($rules as $rule){
			if(is_array($rule)){
				if(!self::isCacheableRuleTree($rule)){
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

}
