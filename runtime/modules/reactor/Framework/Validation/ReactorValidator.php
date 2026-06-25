<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Validates Reactor component state against compact rule definitions.
 *
 * Reactor forms use this validator to turn pipe-delimited field rules into a
 * stable error map keyed by state path, keeping server-side validation aligned
 * with component state shape and custom message overrides.
 */
final class ReactorValidator {

	private const PATH_SEGMENT_CACHE_LIMIT=512;

	/** @var array<string, list<string>> */
	private static array $pathSegmentCache=[];

	/** @var array<string, mixed>|null */
	private static ?array $lastValidateState=null;

	/** @var array<string, mixed>|null */
	private static ?array $lastValidateRules=null;

	/** @var array<string, string>|null */
	private static ?array $lastValidateMessages=null;

	/** @var array<string, array<int, string>>|null */
	private static ?array $lastValidateErrors=null;

	/**
	 * Applies validation rules to state and returns errors grouped by field path.
	 *
	 * Empty field names and unknown rule names are ignored so callers can compose
	 * rule sets incrementally without breaking older components.
	 *
	 * @param array<string, mixed> $state Nested component state addressed by dot-path rules.
	 * @param array<string, array<int, string>|string> $rules Field paths mapped to rule arrays or pipe-delimited rule strings.
	 * @param array<string, string> $messages Optional field.rule or field message overrides.
	 * @return array<string, array<int, string>> Validation errors grouped by field path.
	 */
	public static function validate(array $state, array $rules, array $messages=[]): array {
		$cacheable=self::isCacheableTree($state)
			&& self::isCacheableTree($rules)
			&& self::isCacheableTree($messages);
		if(
			$cacheable &&
			self::$lastValidateErrors!==null &&
			self::$lastValidateState===$state &&
			self::$lastValidateRules===$rules &&
			self::$lastValidateMessages===$messages
		){
			return self::$lastValidateErrors;
		}
		$errors=[];
		$normalizedRuleCache=[];
		$parsedRuleCache=[];
		foreach($rules as $field=>$ruleSet){
			$field=trim((string)$field);
			if($field===''){
				continue;
			}
			$value=self::value($state, $field);
			$cacheKey=is_string($ruleSet) ? 's:'.$ruleSet : 'a:'.serialize($ruleSet);
			$normalizedRules=$normalizedRuleCache[$cacheKey] ??= self::normalizeRules($ruleSet);
			foreach($normalizedRules as $rule){
				$error=self::check($field, $value, $rule, $messages, $parsedRuleCache);
				if($error!==null){
					$errors[$field][]=$error;
				}
			}
		}
		if($cacheable){
			self::$lastValidateState=$state;
			self::$lastValidateRules=$rules;
			self::$lastValidateMessages=$messages;
			self::$lastValidateErrors=$errors;
		}
		return $errors;
	}

	/**
	 * Converts rule declarations into trimmed rule tokens.
	 *
	 * @param array<int, mixed>|string $rules Pipe-delimited string or list of rule declarations.
	 * @return array<int, string> Non-empty rule tokens in evaluation order.
	 */
	private static function normalizeRules(array|string $rules): array {
		$normalized=[];
		if(is_string($rules)){
			foreach(explode('|', $rules) as $rule){
				$rule=trim($rule);
				if($rule!==''){
					$normalized[]=$rule;
				}
			}
			return $normalized;
		}
		foreach($rules as $rule){
			$rule=trim((string)$rule);
			if($rule!==''){
				$normalized[]=$rule;
			}
		}
		return $normalized;
	}

	/**
	 * Reads a nested state value by dot path.
	 *
	 * @param array<string, mixed> $state Component state array.
	 * @param string $path Dot-separated field path.
	 * @return mixed Field value, or null when any path segment is missing.
	 */
	private static function value(array $state, string $path): mixed {
		$value=$state;
		$segments=self::$pathSegmentCache[$path] ?? null;
		if($segments===null){
			$segments=explode('.', $path);
			if(count(self::$pathSegmentCache)>=self::PATH_SEGMENT_CACHE_LIMIT){
				self::$pathSegmentCache=[];
			}
			self::$pathSegmentCache[$path]=$segments;
		}
		foreach($segments as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){
				return null;
			}
			$value=$value[$segment];
		}
		return $value;
	}

	/**
	 * Evaluates one normalized validation rule.
	 *
	 * @param string $field Dot-path of the field being validated.
	 * @param mixed $value Current field value.
	 * @param string $rule Normalized rule token with optional colon argument.
	 * @param array<string, string> $messages Custom validation messages.
	 * @param array<string, array{name:string,argument:?string}> $parsedRuleCache Parsed rule cache for one validate() pass.
	 * @return ?string Error message when the rule fails, otherwise null.
	 */
	private static function check(string $field, mixed $value, string $rule, array $messages, array &$parsedRuleCache): ?string {
		if(isset($parsedRuleCache[$rule])){
			$parsed=$parsedRuleCache[$rule];
			$name=$parsed['name'];
			$argument=$parsed['argument'];
		}
		else
		{
			$colon=strpos($rule, ':');
			if($colon===false){
				$name=$rule;
				$argument=null;
			}else{
				$name=substr($rule, 0, $colon);
				$argument=substr($rule, $colon + 1);
			}
			$name=ReactorName::normalize($name);
			$parsedRuleCache[$rule]=[
				'name'=>$name,
				'argument'=>$argument,
			];
		}
		$empty=$value===null || $value==='';
		$valid=match($name){
			'required'=>!$empty,
			'string'=>$empty || is_string($value),
			'numeric'=>$empty || is_numeric($value),
			'int', 'integer'=>$empty || filter_var($value, FILTER_VALIDATE_INT)!==false,
			'bool', 'boolean'=>$empty || is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
			'email'=>$empty || filter_var($value, FILTER_VALIDATE_EMAIL)!==false,
			'min'=>self::passesMin($value, (float)$argument),
			'max'=>self::passesMax($value, (float)$argument),
			'in'=>self::passesIn($value, (string)$argument),
			default=>true,
		};
		if($valid===true){
			return null;
		}
		return (string)($messages[$field.'.'.$name] ?? $messages[$field] ?? self::defaultMessage($field, $name, $argument));
	}

	/**
	 * Checks numeric or string minimum constraints while allowing empty optional values.
	 *
	 * @param mixed $value Field value being evaluated.
	 * @param float $min Minimum numeric value or string length.
	 * @return bool True when the value is empty or satisfies the minimum.
	 */
	private static function passesMin(mixed $value, float $min): bool {
		if($value===null || $value===''){
			return true;
		}
		return is_numeric($value) ? (float)$value>=$min : strlen((string)$value)>=$min;
	}

	/**
	 * Checks numeric or string maximum constraints while allowing empty optional values.
	 *
	 * @param mixed $value Field value being evaluated.
	 * @param float $max Maximum numeric value or string length.
	 * @return bool True when the value is empty or satisfies the maximum.
	 */
	private static function passesMax(mixed $value, float $max): bool {
		if($value===null || $value===''){
			return true;
		}
		return is_numeric($value) ? (float)$value<=$max : strlen((string)$value)<=$max;
	}

	/**
	 * Checks membership in a comma-delimited allow list.
	 *
	 * @param mixed $value Field value being evaluated.
	 * @param string $argument Comma-delimited list of allowed string values.
	 * @return bool True when the value is empty or appears in the allow list.
	 */
	private static function passesIn(mixed $value, string $argument): bool {
		if($value===null || $value===''){
			return true;
		}
		$value=(string)$value;
		foreach(explode(',', $argument) as $candidate){
			if($value===trim($candidate)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the fallback validation message for a failed rule.
	 *
	 * @param string $field Dot-path of the field being validated.
	 * @param string $rule Normalized rule name.
	 * @param ?string $argument Optional rule argument used by min, max, or in.
	 * @return string Human-readable message suitable for UI display.
	 */
	private static function defaultMessage(string $field, string $rule, ?string $argument): string {
		$label=str_replace('_', ' ', basename(str_replace('.', '/', $field)));
		return match($rule){
			'required'=>ucfirst($label).' is required.',
			'email'=>ucfirst($label).' must be a valid email address.',
			'min'=>ucfirst($label).' must be at least '.$argument.'.',
			'max'=>ucfirst($label).' must be at most '.$argument.'.',
			'in'=>ucfirst($label).' has an invalid value.',
			default=>ucfirst($label).' is invalid.',
		};
	}

	/**
	 * Checks whether a validation input tree can be cached by exact value.
	 *
	 * @param array<int|string,mixed> $values Candidate state/rules/messages tree.
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
