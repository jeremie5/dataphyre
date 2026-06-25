<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\UploadedFile;

/**
 * Validates request or model data against Dataphyre MVC rule declarations.
 *
 * The validator supports string and callable rules, dot-path data access,
 * wildcard field expansion, conditional required/prohibited/excluded rules,
 * custom messages, human-friendly attributes, uploaded file checks, and a
 * validated data payload that includes only fields that passed their rules.
 */
final class Validator {

	/** @var array<string, mixed> Source data under validation. */
	private array $data;
	/** @var array<string, mixed> Rule declarations keyed by field or wildcard field pattern. */
	private array $rules;
	/** @var array<string, string> Custom messages keyed by field.rule, field, rule, or wildcard pattern. */
	private array $messages;
	/** @var array<string, string> Human-readable attribute labels keyed by field or wildcard pattern. */
	private array $attributes;
	/** @var array<string, array<int, string>> Validation errors keyed by concrete field name. */
	private array $errors=[];
	/** @var array<string, mixed> Data that passed validation, preserving dot-path nesting. */
	private array $validated=[];
	/** @var bool True after the validator has executed its rule pass. */
	private bool $ran=false;
	/** @var bool True when validation should stop after the first recorded failure. */
	private bool $stopOnFirstFailure=false;

	/**
	 * Captures data, rules, messages, and attribute labels for one validation run.
	 *
	 * Validation is lazy; constructing the object does not evaluate rules. The
	 * first call to {@see passes()}, {@see errors()}, {@see validated()}, or
	 * {@see validateOrThrow()} executes the rule pass once and caches the result.
	 *
	 * @param array<string, mixed> $data Source data to validate.
	 * @param array<string, mixed> $rules Rule declarations keyed by field path or wildcard path.
	 * @param array<string, string> $messages Optional custom validation messages.
	 * @param array<string, string> $attributes Optional display labels for fields.
	 */
	public function __construct(array $data, array $rules, array $messages=[], array $attributes=[]){
		$this->data=$data;
		$this->rules=$rules;
		$this->messages=$messages;
		$this->attributes=$attributes;
	}

	/**
	 * Creates a validator instance without running validation.
	 *
	 * @param array<string, mixed> $data Source data to validate.
	 * @param array<string, mixed> $rules Rule declarations keyed by field path or wildcard path.
	 * @param array<string, string> $messages Optional custom validation messages.
	 * @param array<string, string> $attributes Optional display labels for fields.
	 * @return self Lazy validator ready for inspection or exception-based validation.
	 */
	public static function make(array $data, array $rules, array $messages=[], array $attributes=[]): self {
		return new self($data, $rules, $messages, $attributes);
	}

	/**
	 * Validates data immediately and returns the validated payload.
	 *
	 * This is the exception-oriented convenience path. Failed validation throws
	 * {@see ValidationException} containing the validator and its error bag.
	 *
	 * @param array<string, mixed> $data Source data to validate.
	 * @param array<string, mixed> $rules Rule declarations keyed by field path or wildcard path.
	 * @param array<string, string> $messages Optional custom validation messages.
	 * @param array<string, string> $attributes Optional display labels for fields.
	 * @return array<string, mixed> Validated data preserving dot-path nesting.
	 *
	 * @throws ValidationException When any rule fails.
	 */
	public static function validate(array $data, array $rules, array $messages=[], array $attributes=[]): array {
		return self::make($data, $rules, $messages, $attributes)->validateOrThrow();
	}

	/**
	 * Configures whether validation stops after the first recorded failure.
	 *
	 * The flag affects the next lazy run only. Once validation has run, toggling
	 * the flag does not re-run already cached results.
	 *
	 * @param bool $stop True to stop after the first failed field.
	 * @return self Same validator instance for fluent setup.
	 */
	public function stopOnFirstFailure(bool $stop=true): self {
		$this->stopOnFirstFailure=$stop;
		return $this;
	}

	/**
	 * Runs validation if needed and reports whether no errors were recorded.
	 *
	 * @return bool True when every evaluated rule passed.
	 */
	public function passes(): bool {
		$this->run();
		return $this->errors===[];
	}

	/**
	 * Runs validation if needed and reports whether any errors were recorded.
	 *
	 * @return bool True when at least one evaluated rule failed.
	 */
	public function fails(): bool {
		return $this->passes()===false;
	}

	/**
	 * Returns the validation error bag.
	 *
	 * Validation runs lazily before the bag is returned. Errors are grouped by
	 * concrete field name and retain insertion order for each failed rule.
	 *
	 * @return array<string, array<int, string>> Error messages keyed by field.
	 */
	public function errors(): array {
		$this->run();
		return $this->errors;
	}

	/**
	 * Returns data that passed validation.
	 *
	 * Fields excluded by exclusion rules, missing optional fields, prohibited
	 * fields, and fields with errors are not copied into this payload. Dot-path
	 * fields are written back into nested arrays.
	 *
	 * @return array<string, mixed> Validated data preserving dot-path nesting.
	 */
	public function validated(): array {
		$this->run();
		return $this->validated;
	}

	/**
	 * Fetches all validated data or one value from the validated payload.
	 *
	 * A null key returns the full validated payload. Non-null keys use dot-path
	 * lookup against validated data and return the supplied default when absent.
	 *
	 * @param ?string $key Optional validated data key or dot path.
	 * @param mixed $default Fallback value for missing keys.
	 * @return mixed Full validated payload, a validated value, or the default fallback.
	 */
	public function safe(?string $key=null, mixed $default=null): mixed {
		$validated=$this->validated();
		if($key===null){
			return $validated;
		}
		return $this->dataGet($validated, $key, $default);
	}

	/**
	 * Returns validated data or throws a validation exception.
	 *
	 * The exception keeps the validator available so callers can inspect the
	 * complete error bag and validation summary.
	 *
	 * @return array<string, mixed> Validated data preserving dot-path nesting.
	 *
	 * @throws ValidationException When validation fails.
	 */
	public function validateOrThrow(): array {
		if($this->fails()){
			throw new ValidationException($this);
		}
		return $this->validated();
	}

	/**
	 * Serializes the validation summary for diagnostics and generated validation reports.
	 *
	 * Calling this method triggers lazy validation and exposes the final boolean
	 * state, validated data, and grouped error messages.
	 *
	 * @return array{valid: bool, validated: array<string, mixed>, errors: array<string, array<int, string>>} Validation summary payload.
	 */
	public function toArray(): array {
		return [
			'valid'=>$this->passes(),
			'validated'=>$this->validated(),
			'errors'=>$this->errors(),
		];
	}

	/**
	 * Executes the validation pass once.
	 *
	 * Rules are expanded from wildcard patterns into concrete fields, each field
	 * is validated, and successful values are copied into the validated payload.
	 * Subsequent calls reuse the cached errors and validated data.
	 *
	 * @return void
	 */
	private function run(): void {
		if($this->ran){
			return;
		}
		$this->ran=true;
		$this->errors=[];
		$this->validated=[];
		foreach($this->rules as $field=>$rules){
			if(!is_string($field) || $field===''){
				continue;
			}
			foreach($this->expandRuleFields($field) as $expandedField){
				$this->validateField($field, $expandedField, $rules);
				if($this->stopOnFirstFailure && $this->errors!==[]){
					return;
				}
			}
		}
	}

	/**
	 * Validates one concrete field against its normalized rule list.
	 *
	 * This method owns the high-level rule lifecycle: conditional exclusion,
	 * sometimes/present handling, prohibited and required checks, nullable
	 * preservation, bail behavior, callable rules, and validated data writes.
	 *
	 * @param string $ruleField Original rule key, possibly containing wildcards.
	 * @param string $field Concrete data field being validated.
	 * @param mixed $rules Raw rule declaration for the field.
	 * @return void
	 */
	private function validateField(string $ruleField, string $field, mixed $rules): void {
		$ruleList=$this->normalizeRules($rules, $ruleField, $field);
		$exists=$this->dataHas($this->data, $field);
		$value=$exists ? $this->dataGet($this->data, $field) : null;
		$ruleNames=array_map(fn(mixed $rule): string => is_string($rule) ? $this->ruleName($rule) : '', $ruleList);
		$requiredRule=$this->requiredRule($ruleList);
		$required=$requiredRule!==null;
		$nullable=in_array('nullable', $ruleNames, true);
		$bail=in_array('bail', $ruleNames, true);
		$excluded=$this->excluded($ruleList);
		$prohibitedRule=$this->prohibitedRule($ruleList);
		if($excluded){
			return;
		}
		if($exists===false && in_array('sometimes', $ruleNames, true)){
			return;
		}
		if(in_array('present', $ruleNames, true) && $exists===false){
			$this->addError($field, 'present', $this->message($field, 'present', 'The :attribute field must be present.'));
			return;
		}
		if($prohibitedRule!==null){
			if($this->hasFilledValue($field)){
				$this->addError($field, $prohibitedRule, $this->message($field, $this->ruleName($prohibitedRule), 'The :attribute field is prohibited.'));
			}
			return;
		}
		if($exists===false || $value===null || $value===''){
			if($required){
				$this->addError($field, $requiredRule, $this->message($field, $this->ruleName($requiredRule), 'The :attribute field is required.'));
				return;
			}
			if($exists && $nullable){
				$this->dataSet($this->validated, $field, $value);
			}
			return;
		}
		foreach($ruleList as $rule){
			if(is_string($rule)){
				$this->applyRule($ruleField, $field, $value, $rule);
				if($bail && isset($this->errors[$field])){
					break;
				}
				continue;
			}
			if(is_callable($rule)){
				$this->applyCallableRule($field, $value, $rule);
				if($bail && isset($this->errors[$field])){
					break;
				}
			}
		}
		if(isset($this->errors[$field])===false){
			$this->dataSet($this->validated, $field, $value);
		}
	}

	/**
	 * Applies one string rule to a non-empty field value.
	 *
	 * Control rules such as required, nullable, exclude, prohibited, bail, and
	 * sometimes are handled before this phase. This method evaluates type,
	 * comparison, file, date, size, pattern, and membership rules and records
	 * errors when a rule does not pass.
	 *
	 * @param string $ruleField Original rule key, used by wildcard-aware rules such as distinct.
	 * @param string $field Concrete field being validated.
	 * @param mixed $value Non-empty value being evaluated.
	 * @param string $rule String rule declaration, optionally with colon parameters.
	 * @return void
	 */
	private function applyRule(string $ruleField, string $field, mixed $value, string $rule): void {
		[$name, $parameters]=$this->parseRule($rule);
		if(in_array($name, [
			'required',
			'required_if',
			'required_unless',
			'required_with',
			'required_without',
			'present',
			'exclude',
			'exclude_if',
			'exclude_unless',
			'exclude_with',
			'exclude_without',
			'prohibited',
			'prohibited_if',
			'prohibited_unless',
			'bail',
			'sometimes',
			'nullable',
		], true)){
			return;
		}
		if($name==='boolean' || $name==='bool'){
			if($this->isBooleanLike($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be true or false.'));
			}
			return;
		}
		if($name==='accepted'){
			if($this->isAcceptedLike($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be accepted.'));
			}
			return;
		}
		if($name==='string'){
			if(is_string($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be a string.'));
			}
			return;
		}
		if($name==='int' || $name==='integer'){
			if($this->isIntegerLike($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be an integer.'));
			}
			return;
		}
		if($name==='numeric'){
			if(is_numeric($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be numeric.'));
			}
			return;
		}
		if($name==='array'){
			if(is_array($value)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be an array.'));
			}
			return;
		}
		if($name==='distinct'){
			if($this->passesDistinctRule($ruleField, $field, $value, $parameters)===false){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field has a duplicate value.'));
			}
			return;
		}
		if($name==='alpha'){
			if(is_string($value)===false || preg_match('/^[\pL]+$/u', $value)!==1){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must only contain letters.'));
			}
			return;
		}
		if($name==='alpha_num' || $name==='alpha_numeric'){
			if(is_string($value)===false || preg_match('/^[\pL\pN]+$/u', $value)!==1){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must only contain letters and numbers.'));
			}
			return;
		}
		if($name==='starts_with'){
			if($this->startsWithAny((string)$value, $parameters)===false){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must start with one of the following: :values.'));
			}
			return;
		}
		if($name==='ends_with'){
			if($this->endsWithAny((string)$value, $parameters)===false){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must end with one of the following: :values.'));
			}
			return;
		}
		if($name==='digits'){
			$digits=$this->numericParameter($parameters, 0);
			if($digits===null || preg_match('/^\d+$/', (string)$value)!==1 || strlen((string)$value)!==(int)$digits){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be :digits digits.'));
			}
			return;
		}
		if($name==='digits_between'){
			$minimum=$this->numericParameter($parameters, 0);
			$maximum=$this->numericParameter($parameters, 1);
			$length=strlen((string)$value);
			if($minimum===null || $maximum===null || preg_match('/^\d+$/', (string)$value)!==1 || $length<$minimum || $length>$maximum){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be between :min and :max digits.'));
			}
			return;
		}
		if($name==='file'){
			if(!$value instanceof UploadedFile || !$value->isValid()){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be a valid uploaded file.'));
			}
			return;
		}
		if($name==='image'){
			if(!$value instanceof UploadedFile || !$value->isValid() || !$this->isImageUpload($value)){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be an image.'));
			}
			return;
		}
		if($name==='mimes'){
			if(!$value instanceof UploadedFile || !$value->isValid() || !$this->passesMimesRule($value, $parameters)){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be a file of type: :values.'));
			}
			return;
		}
		if($name==='mimetypes'){
			if(!$value instanceof UploadedFile || !$value->isValid() || !$this->passesMimeTypesRule($value, $parameters)){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be a file of type: :values.'));
			}
			return;
		}
		if($name==='in'){
			if($this->passesInRule($value, $parameters)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The selected :attribute is invalid.'));
			}
			return;
		}
		if($name==='same'){
			$other=$parameters[0] ?? '';
			if($other==='' || !$this->dataHas($this->data, $other) || $value!=$this->dataGet($this->data, $other)){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must match :other.'));
			}
			return;
		}
		if($name==='different'){
			$other=$parameters[0] ?? '';
			if($other==='' || ($this->dataHas($this->data, $other) && $value==$this->dataGet($this->data, $other))){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field and :other must be different.'));
			}
			return;
		}
		if($name==='confirmed'){
			$confirmation=$field.'_confirmation';
			if(!$this->dataHas($this->data, $confirmation) || $value!=$this->dataGet($this->data, $confirmation)){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field confirmation does not match.'));
			}
			return;
		}
		if($name==='regex'){
			$pattern=$parameters[0] ?? '';
			if($pattern==='' || @preg_match($pattern, (string)$value)!==1){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field format is invalid.'));
			}
			return;
		}
		if($name==='email'){
			if(is_string($value)===false || filter_var($value, FILTER_VALIDATE_EMAIL)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be a valid email address.'));
			}
			return;
		}
		if($name==='url'){
			if(is_string($value)===false || filter_var($value, FILTER_VALIDATE_URL)===false){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be a valid URL.'));
			}
			return;
		}
		if($name==='date'){
			if($this->dateValue($value)===null){
				$this->addError($field, $name, $this->message($field, $name, 'The :attribute field must be a valid date.'));
			}
			return;
		}
		if(in_array($name, ['before', 'after', 'before_or_equal', 'after_or_equal'], true)){
			$other=$parameters[0] ?? '';
			$valueTime=$this->dateValue($value);
			$otherTime=$this->dateValue($this->dataHas($this->data, $other) ? $this->dataGet($this->data, $other) : $other);
			$passes=$valueTime!==null && $otherTime!==null && match($name){
				'before'=>$valueTime<$otherTime,
				'after'=>$valueTime>$otherTime,
				'before_or_equal'=>$valueTime<=$otherTime,
				'after_or_equal'=>$valueTime>=$otherTime,
			};
			if(!$passes){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be a valid date relative to :date.'));
			}
			return;
		}
		if($name==='min'){
			$minimum=$this->numericParameter($parameters, 0);
			if($minimum===null || $this->measure($value)<$minimum){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be at least :min.'));
			}
			return;
		}
		if($name==='between'){
			$minimum=$this->numericParameter($parameters, 0);
			$maximum=$this->numericParameter($parameters, 1);
			$measure=$this->measure($value);
			if($minimum===null || $maximum===null || $measure<$minimum || $measure>$maximum){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be between :min and :max.'));
			}
			return;
		}
		if($name==='size'){
			$size=$this->numericParameter($parameters, 0);
			if($size===null || $this->measure($value)!=$size){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must be :size.'));
			}
			return;
		}
		if($name==='max'){
			$maximum=$this->numericParameter($parameters, 0);
			if($maximum===null || $this->measure($value)>$maximum){
				$this->addError($field, $rule, $this->message($field, $name, 'The :attribute field must not be greater than :max.'));
			}
		}
	}

	/**
	 * Applies a custom callable validation rule.
	 *
	 * Callables receive value, field, and full source data. Returning true or
	 * null passes; returning a non-empty string uses that string as the error
	 * message; any other return value records the default custom-rule message.
	 *
	 * @param string $field Concrete field being validated.
	 * @param mixed $value Field value supplied to the custom rule.
	 * @param callable $rule Custom validation callback.
	 * @return void
	 */
	private function applyCallableRule(string $field, mixed $value, callable $rule): void {
		$result=$rule($value, $field, $this->data);
		if($result===true || $result===null){
			return;
		}
		$message=is_string($result) && trim($result)!==''
			? $result
			: $this->message($field, 'custom', 'The :attribute field is invalid.');
		$this->addError($field, 'custom', $message);
	}

	/**
	 * Normalizes a rule declaration into executable string and callable rules.
	 *
	 * Pipe-delimited strings become arrays, blank string rules are ignored, and
	 * wildcard parameters inside non-regex rules are concretized for expanded
	 * fields.
	 *
	 * @param mixed $rules Raw rule declaration.
	 * @param ?string $ruleField Original wildcard-capable rule key.
	 * @param ?string $field Concrete field produced from wildcard expansion.
	 * @return array<int, string|callable> Normalized rule list.
	 */
	private function normalizeRules(mixed $rules, ?string $ruleField=null, ?string $field=null): array {
		if(is_string($rules)){
			$rules=explode('|', $rules);
		}
		if(is_array($rules)===false){
			return [];
		}
		$normalized=[];
		foreach($rules as $rule){
			if(is_string($rule)){
				$rule=trim($rule);
				if($rule===''){
					continue;
				}
				if($ruleField!==null && $field!==null){
					$rule=$this->concretizeWildcardRule($rule, $ruleField, $field);
				}
				$normalized[]=$rule;
				continue;
			}
			if(is_callable($rule)){
				$normalized[]=$rule;
			}
		}
		return $normalized;
	}

	/**
	 * Parses a string rule into lowercase name and parameter list.
	 *
	 * Regex rules preserve the full pattern after the first colon; other rules
	 * split comma-separated parameters and trim each value.
	 *
	 * @param string $rule String rule declaration.
	 * @return array{0: string, 1: array<int, string>} Rule name and parameters.
	 */
	private function parseRule(string $rule): array {
		$parts=explode(':', $rule, 2);
		$name=$this->ruleName($rule);
		$parameters=[];
		if(isset($parts[1])){
			$parameters=$name==='regex'
				? [trim($parts[1])]
				: array_map('trim', explode(',', $parts[1]));
		}
		return [$name, $parameters];
	}

	/**
	 * Extracts the lowercase rule name from a string rule declaration.
	 *
	 * @param string $rule String rule declaration.
	 * @return string Lowercase rule name without parameters.
	 */
	private function ruleName(string $rule): string {
		return strtolower(trim(explode(':', $rule, 2)[0]));
	}

	/**
	 * Expands a field rule key into concrete source-data paths.
	 *
	 * Rule keys without wildcards are returned unchanged. Wildcard keys walk the
	 * source data shape to produce concrete dot paths for existing array entries.
	 *
	 * @param string $field Field key from the rule map.
	 * @return array<int, string> Concrete field paths to validate.
	 */
	private function expandRuleFields(string $field): array {
		if(!str_contains($field, '*')){
			return [$field];
		}
		return $this->expandWildcardSegments($this->data, explode('.', $field), []);
	}

	/**
	 * Recursively expands wildcard field segments through source data.
	 *
	 * @param mixed $current Current source-data node.
	 * @param array<int, string> $segments Remaining field path segments.
	 * @param array<int, string> $path Concrete path accumulated so far.
	 * @return array<int, string> Concrete field paths discovered under the wildcard pattern.
	 */
	private function expandWildcardSegments(mixed $current, array $segments, array $path): array {
		if($segments===[]){
			return [implode('.', $path)];
		}
		$segment=array_shift($segments);
		if($segment==='*'){
			if(!is_array($current)){
				return [];
			}
			$expanded=[];
			foreach($current as $key=>$value){
				foreach($this->expandWildcardSegments($value, $segments, [...$path, (string)$key]) as $field){
					$expanded[]=$field;
				}
			}
			return $expanded;
		}
		if(!is_array($current) || !array_key_exists($segment, $current)){
			if(in_array('*', $segments, true)){
				return [];
			}
			return [implode('.', [...$path, $segment, ...$segments])];
		}
		return $this->expandWildcardSegments($current[$segment], $segments, [...$path, $segment]);
	}

	/**
	 * Replaces wildcard references inside a rule parameter with concrete indices.
	 *
	 * This lets rules such as `same:items.*.confirmation` follow the field
	 * currently being validated. Regex rules are excluded because `*` can be part
	 * of the regex itself.
	 *
	 * @param string $rule Rule declaration that may contain wildcard parameters.
	 * @param string $ruleField Original wildcard-capable rule key.
	 * @param string $field Concrete field produced from wildcard expansion.
	 * @return string Rule declaration with wildcard parameters concretized.
	 */
	private function concretizeWildcardRule(string $rule, string $ruleField, string $field): string {
		if(!str_contains($rule, '*') || $this->ruleName($rule)==='regex'){
			return $rule;
		}
		$wildcards=$this->wildcardValues($ruleField, $field);
		foreach($wildcards as $wildcard){
			$position=strpos($rule, '*');
			if($position===false){
				break;
			}
			$rule=substr($rule, 0, $position).$wildcard.substr($rule, $position+1);
		}
		return $rule;
	}

	/**
	 * Extracts concrete segment values that replaced wildcards in a field path.
	 *
	 * @param string $ruleField Original wildcard-capable rule key.
	 * @param string $field Concrete expanded field path.
	 * @return array<int, string> Segment values corresponding to wildcard positions.
	 */
	private function wildcardValues(string $ruleField, string $field): array {
		$patternSegments=explode('.', $ruleField);
		$fieldSegments=explode('.', $field);
		$wildcards=[];
		foreach($patternSegments as $index=>$segment){
			if($segment==='*' && isset($fieldSegments[$index])){
				$wildcards[]=$fieldSegments[$index];
			}
		}
		return $wildcards;
	}

	/**
	 * Finds the required-style rule currently forcing a field to be present.
	 *
	 * Conditional variants inspect the source data before deciding whether the
	 * field is required.
	 *
	 * @param array<int, string|callable> $rules Normalized rules for one field.
	 * @return ?string Triggering required rule, or null when the field remains optional.
	 */
	private function requiredRule(array $rules): ?string {
		foreach($rules as $rule){
			if(!is_string($rule)){
				continue;
			}
			[$name, $parameters]=$this->parseRule($rule);
			if($name==='required'){
				return $rule;
			}
			if($name==='required_if' && $this->fieldValueMatches($parameters)){
				return $rule;
			}
			if($name==='required_unless' && $this->fieldValueMatches($parameters)===false){
				return $rule;
			}
			if($name==='required_with' && $this->anyFieldFilled($parameters)){
				return $rule;
			}
			if($name==='required_without' && $this->anyFieldMissingOrEmpty($parameters)){
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Finds the prohibited-style rule currently forbidding a filled field.
	 *
	 * @param array<int, string|callable> $rules Normalized rules for one field.
	 * @return ?string Triggering prohibited rule, or null when the field is allowed.
	 */
	private function prohibitedRule(array $rules): ?string {
		foreach($rules as $rule){
			if(!is_string($rule)){
				continue;
			}
			[$name, $parameters]=$this->parseRule($rule);
			if($name==='prohibited'){
				return $rule;
			}
			if($name==='prohibited_if' && $this->fieldValueMatches($parameters)){
				return $rule;
			}
			if($name==='prohibited_unless' && $this->fieldValueMatches($parameters)===false){
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Determines whether a field should be excluded from validation and output.
	 *
	 * Excluded fields skip all remaining validation and are not copied into the
	 * validated payload.
	 *
	 * @param array<int, string|callable> $rules Normalized rules for one field.
	 * @return bool True when an exclusion rule currently applies.
	 */
	private function excluded(array $rules): bool {
		foreach($rules as $rule){
			if(!is_string($rule)){
				continue;
			}
			[$name, $parameters]=$this->parseRule($rule);
			if($name==='exclude'){
				return true;
			}
			if($name==='exclude_if' && $this->fieldValueMatches($parameters)){
				return true;
			}
			if($name==='exclude_unless' && $this->fieldValueMatches($parameters)===false){
				return true;
			}
			if($name==='exclude_with' && $this->anyFieldFilled($parameters)){
				return true;
			}
			if($name==='exclude_without' && $this->anyFieldMissingOrEmpty($parameters)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates conditional rule parameters against another source-data field.
	 *
	 * The first parameter is the other field name; remaining parameters are
	 * compared as strings against the other field's current value.
	 *
	 * @param array<int, string> $parameters Conditional rule parameters.
	 * @return bool True when the other field exists and matches one allowed value.
	 */
	private function fieldValueMatches(array $parameters): bool {
		$field=$parameters[0] ?? null;
		if($field===null || $this->dataHas($this->data, $field)===false){
			return false;
		}
		$values=array_slice($parameters, 1);
		if($values===[]){
			return false;
		}
		foreach($values as $value){
			if((string)$this->dataGet($this->data, $field)===(string)$value){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether any referenced source field has a filled value.
	 *
	 * @param array<int, string> $fields Field names to inspect.
	 * @return bool True when at least one field is present and filled.
	 */
	private function anyFieldFilled(array $fields): bool {
		foreach($fields as $field){
			if($this->hasFilledValue((string)$field)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether any referenced source field is missing or empty.
	 *
	 * @param array<int, string> $fields Field names to inspect.
	 * @return bool True when at least one field is absent or empty.
	 */
	private function anyFieldMissingOrEmpty(array $fields): bool {
		foreach($fields as $field){
			if($this->hasFilledValue((string)$field)===false){
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines whether a source field exists and contains a non-empty value.
	 *
	 * Uploaded files count as filled only when the upload object reports itself
	 * valid.
	 *
	 * @param string $field Field path to inspect.
	 * @return bool True when the field exists and is not null, empty string, empty array, or invalid upload.
	 */
	private function hasFilledValue(string $field): bool {
		if($this->dataHas($this->data, $field)===false){
			return false;
		}
		$value=$this->dataGet($this->data, $field);
		if($value===null || $value==='' || $value===[]){
			return false;
		}
		if($value instanceof UploadedFile){
			return $value->isValid();
		}
		return true;
	}

	/**
	 * Checks whether an array contains a top-level or dot-path key.
	 *
	 * `array_key_exists` is used so null values still count as present.
	 *
	 * @param array<string, mixed> $data Data array to inspect.
	 * @param string $key Top-level key or dot path.
	 * @return bool True when the key exists.
	 */
	private function dataHas(array $data, string $key): bool {
		if(array_key_exists($key, $data)){
			return true;
		}
		if(!str_contains($key, '.')){
			return false;
		}
		$current=$data;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return false;
			}
			$current=$current[$segment];
		}
		return true;
	}

	/**
	 * Reads a top-level or dot-path value from an array.
	 *
	 * @param array<string, mixed> $data Data array to inspect.
	 * @param string $key Top-level key or dot path.
	 * @param mixed $default Fallback for missing path segments.
	 * @return mixed literal-key value, dotted-path value, or the caller fallback when absent.
	 */
	private function dataGet(array $data, string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $data)){
			return $data[$key];
		}
		if(!str_contains($key, '.')){
			return $default;
		}
		$current=$data;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	/**
	 * Writes a value into an array using a top-level key or dot path.
	 *
	 * Missing intermediate path segments are created as arrays.
	 *
	 * @param array<string, mixed> $data Data array mutated by reference.
	 * @param string $key Top-level key or dot path.
	 * @param mixed $value Value to store.
	 * @return void
	 */
	private function dataSet(array &$data, string $key, mixed $value): void {
		if(!str_contains($key, '.')){
			$data[$key]=$value;
			return;
		}
		$current=&$data;
		foreach(explode('.', $key) as $segment){
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
		$current=$value;
	}

	/**
	 * Resolves and interpolates the validation message for a failed rule.
	 *
	 * Attribute placeholders are replaced here; rule-specific placeholders are
	 * filled later when the error is recorded.
	 *
	 * @param string $field Concrete field name.
	 * @param string $rule Rule declaration that failed.
	 * @param string $default Default message when no custom message matches.
	 * @return string Message with field and attribute placeholders replaced.
	 */
	private function message(string $field, string $rule, string $default): string {
		$message=$this->messageFor($field, $rule, $default);
		$attribute=$this->attributeFor($field);
		return strtr($message, [
			':attribute'=>$attribute,
			':field'=>$field,
		]);
	}

	/**
	 * Selects the most specific custom message for a field and rule.
	 *
	 * Exact keys are checked before wildcard message keys. Rule keys may use the
	 * full rule declaration or the normalized rule name.
	 *
	 * @param string $field Concrete field name.
	 * @param string $rule Rule declaration that failed.
	 * @param string $default Default message when no custom message matches.
	 * @return string Selected message template.
	 */
	private function messageFor(string $field, string $rule, string $default): string {
		$name=$this->ruleName($rule);
		foreach([$field.'.'.$rule, $field.'.'.$name, $rule, $name] as $key){
			if(isset($this->messages[$key])){
				return $this->messages[$key];
			}
		}
		foreach($this->messages as $key=>$message){
			if(!is_string($key) || !is_string($message) || !str_contains($key, '*')){
				continue;
			}
			if($this->matchesWildcardKey($key, $field.'.'.$rule) || $this->matchesWildcardKey($key, $field.'.'.$name)){
				return $message;
			}
		}
		return $default;
	}

	/**
	 * Resolves the display label for a concrete field.
	 *
	 * Exact attribute labels win over wildcard labels. When no custom label
	 * matches, underscores are converted to spaces.
	 *
	 * @param string $field Concrete field name.
	 * @return string Human-readable field label.
	 */
	private function attributeFor(string $field): string {
		if(isset($this->attributes[$field])){
			return $this->attributes[$field];
		}
		foreach($this->attributes as $key=>$attribute){
			if(is_string($key) && is_string($attribute) && str_contains($key, '*') && $this->matchesWildcardKey($key, $field)){
				return $attribute;
			}
		}
		return str_replace('_', ' ', $field);
	}

	/**
	 * Tests whether a wildcard message or attribute key matches a concrete key.
	 *
	 * @param string $pattern Pattern containing `*` segments.
	 * @param string $field Concrete key to test.
	 * @return bool True when the wildcard pattern matches the concrete key.
	 */
	private function matchesWildcardKey(string $pattern, string $field): bool {
		$regex='/^'.str_replace('\\*', '[^.]+', preg_quote($pattern, '/')).'$/';
		return preg_match($regex, $field)===1;
	}

	/**
	 * Records an interpolated error message for a failed rule.
	 *
	 * Rule parameters replace placeholders such as `:min`, `:max`, `:other`,
	 * `:date`, and `:values` before the message is appended to the field's error
	 * list.
	 *
	 * @param string $field Concrete field name.
	 * @param string $rule Rule declaration that failed.
	 * @param string $message Message template after attribute interpolation.
	 * @return void
	 */
	private function addError(string $field, string $rule, string $message): void {
		[$name, $parameters]=$this->parseRule($rule);
		$replace=[];
		if(isset($parameters[0])){
			$replace[':'.$name]=$parameters[0];
		}
		if(in_array($name, ['required_if', 'required_unless', 'prohibited_if', 'prohibited_unless'], true) && isset($parameters[0])){
			$replace[':other']=$this->attributeFor($parameters[0]);
			$replace[':value']=$parameters[1] ?? '';
			$replace[':values']=implode(', ', array_slice($parameters, 1));
		}
		if($name==='min' && isset($parameters[0])){
			$replace[':min']=$parameters[0];
		}
		if($name==='max' && isset($parameters[0])){
			$replace[':max']=$parameters[0];
		}
		if(($name==='size' || $name==='digits') && isset($parameters[0])){
			$replace[':'.$name]=$parameters[0];
		}
		if(($name==='between' || $name==='digits_between') && isset($parameters[0], $parameters[1])){
			$replace[':min']=$parameters[0];
			$replace[':max']=$parameters[1];
		}
		if(in_array($name, ['before', 'after', 'before_or_equal', 'after_or_equal'], true) && isset($parameters[0])){
			$replace[':date']=$this->attributeFor($parameters[0]);
		}
		if(($name==='same' || $name==='different') && isset($parameters[0])){
			$replace[':other']=$this->attributeFor($parameters[0]);
		}
		if(($name==='mimes' || $name==='mimetypes') && $parameters!==[]){
			$replace[':values']=implode(', ', $parameters);
		}
		if(($name==='starts_with' || $name==='ends_with') && $parameters!==[]){
			$replace[':values']=implode(', ', $parameters);
		}
		$this->errors[$field][]=strtr($message, $replace);
	}

	/**
	 * Determines whether a value satisfies Dataphyre's integer rule.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool True for native integers and signed integer strings.
	 */
	private function isIntegerLike(mixed $value): bool {
		if(is_int($value)){
			return true;
		}
		return is_string($value) && preg_match('/^-?\d+$/', $value)===1;
	}

	/**
	 * Determines whether a value satisfies Dataphyre's boolean rule.
	 *
	 * Accepted values are booleans, integers 0 or 1, and strings `0`, `1`,
	 * `true`, or `false`.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool True when the value can represent a boolean.
	 */
	private function isBooleanLike(mixed $value): bool {
		if(is_bool($value)){
			return true;
		}
		if(is_int($value)){
			return $value===0 || $value===1;
		}
		if(!is_string($value)){
			return false;
		}
		return in_array(strtolower($value), ['0', '1', 'true', 'false'], true);
	}

	/**
	 * Determines whether a value satisfies the accepted rule.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool True for accepted truthy form values.
	 */
	private function isAcceptedLike(mixed $value): bool {
		if($value===true || $value===1){
			return true;
		}
		return is_string($value) && in_array(strtolower($value), ['yes', 'on', '1', 'true'], true);
	}

	/**
	 * Converts supported date values into timestamps for comparison rules.
	 *
	 * @param mixed $value DateTimeInterface, integer timestamp, or parseable date string.
	 * @return ?int Unix timestamp, or null when the value is not date-like.
	 */
	private function dateValue(mixed $value): ?int {
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		if(is_int($value)){
			return $value;
		}
		if(!is_string($value) || trim($value)===''){
			return null;
		}
		$timestamp=strtotime($value);
		return $timestamp===false ? null : $timestamp;
	}

	/**
	 * Checks whether a value matches one of an `in` rule's allowed parameters.
	 *
	 * @param mixed $value Value being validated.
	 * @param array<int, string> $parameters Allowed values from the rule declaration.
	 * @return bool True when the string-cast value matches an allowed parameter.
	 */
	private function passesInRule(mixed $value, array $parameters): bool {
		foreach($parameters as $parameter){
			if((string)$value===$parameter){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks the distinct rule for arrays or wildcard-expanded sibling fields.
	 *
	 * `strict` includes type in the comparison key. `ignore_case` lowercases
	 * string values before comparison.
	 *
	 * @param string $ruleField Original rule key, possibly containing wildcards.
	 * @param string $field Concrete field currently being validated.
	 * @param mixed $value Current field value.
	 * @param array<int, string> $parameters Distinct rule flags.
	 * @return bool True when no duplicate value is found.
	 */
	private function passesDistinctRule(string $ruleField, string $field, mixed $value, array $parameters): bool {
		$strict=in_array('strict', $parameters, true);
		$ignoreCase=in_array('ignore_case', $parameters, true);
		if(!str_contains($ruleField, '*')){
			if(!is_array($value)){
				return true;
			}
			$seen=[];
			foreach($value as $item){
				$key=$this->distinctKey($item, $strict, $ignoreCase);
				if(isset($seen[$key])){
					return false;
				}
				$seen[$key]=true;
			}
			return true;
		}
		foreach($this->expandRuleFields($ruleField) as $peerField){
			if($peerField===$field || !$this->dataHas($this->data, $peerField)){
				continue;
			}
			if($this->distinctKey($this->dataGet($this->data, $peerField), $strict, $ignoreCase)===$this->distinctKey($value, $strict, $ignoreCase)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Builds a comparable key for distinct-rule duplicate detection.
	 *
	 * @param mixed $value Value to normalize.
	 * @param bool $strict True to preserve type information in the key.
	 * @param bool $ignoreCase True to lowercase string values before comparison.
	 * @return string Comparable duplicate-detection key.
	 */
	private function distinctKey(mixed $value, bool $strict, bool $ignoreCase): string {
		if($ignoreCase && is_string($value)){
			$value=strtolower($value);
		}
		if($strict){
			return get_debug_type($value).':'.serialize($value);
		}
		if(is_bool($value) || $value===null){
			return (string)(int)(bool)$value;
		}
		if(is_scalar($value)){
			return (string)$value;
		}
		return serialize($value);
	}

	/**
	 * Checks whether a string starts with any non-empty candidate.
	 *
	 * @param string $value Value being validated.
	 * @param array<int, string> $needles Required prefixes.
	 * @return bool True when any non-empty prefix matches.
	 */
	private function startsWithAny(string $value, array $needles): bool {
		foreach($needles as $needle){
			$needle=(string)$needle;
			if($needle!=='' && str_starts_with($value, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether a string ends with any non-empty candidate.
	 *
	 * @param string $value Value being validated.
	 * @param array<int, string> $needles Required suffixes.
	 * @return bool True when any non-empty suffix matches.
	 */
	private function endsWithAny(string $value, array $needles): bool {
		foreach($needles as $needle){
			$needle=(string)$needle;
			if($needle!=='' && str_ends_with($value, $needle)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks an uploaded file against allowed client extensions.
	 *
	 * @param UploadedFile $file Uploaded file being validated.
	 * @param array<int, string> $parameters Allowed extensions, with optional leading dots.
	 * @return bool True when the normalized client extension is allowed.
	 */
	private function passesMimesRule(UploadedFile $file, array $parameters): bool {
		$extension=$file->clientExtension();
		foreach($parameters as $parameter){
			if($extension===strtolower(ltrim((string)$parameter, '.'))){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks an uploaded file against allowed MIME types or wildcard MIME groups.
	 *
	 * @param UploadedFile $file Uploaded file being validated.
	 * @param array<int, string> $parameters Allowed MIME types such as `image/png` or `image/*`.
	 * @return bool True when the uploaded MIME type is allowed.
	 */
	private function passesMimeTypesRule(UploadedFile $file, array $parameters): bool {
		$mime=strtolower($file->mimeType());
		foreach($parameters as $parameter){
			$parameter=strtolower(trim((string)$parameter));
			if($parameter==='' || $parameter===$mime){
				return true;
			}
			if(str_ends_with($parameter, '/*') && str_starts_with($mime, substr($parameter, 0, -1))){
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines whether an uploaded file should satisfy the image rule.
	 *
	 * MIME types beginning with `image/` pass, with common image extensions as a
	 * fallback for upload contexts where MIME detection is sparse.
	 *
	 * @param UploadedFile $file Uploaded file being validated.
	 * @return bool True when the upload appears to be an image.
	 */
	private function isImageUpload(UploadedFile $file): bool {
		if(str_starts_with(strtolower($file->mimeType()), 'image/')){
			return true;
		}
		return in_array($file->clientExtension(), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'], true);
	}

	/**
	 * Reads a numeric rule parameter as an integer or float.
	 *
	 * @param array<int, string> $parameters Rule parameter list.
	 * @param int $index Parameter offset to read.
	 * @return int|float|null Numeric parameter, or null when missing or non-numeric.
	 */
	private function numericParameter(array $parameters, int $index): int|float|null {
		if(isset($parameters[$index])===false || is_numeric($parameters[$index])===false){
			return null;
		}
		return str_contains((string)$parameters[$index], '.') ? (float)$parameters[$index] : (int)$parameters[$index];
	}

	/**
	 * Measures a value for size, min, max, and between rules.
	 *
	 * Uploaded files are measured in KiB rounded up, numeric values compare as
	 * numbers, arrays compare by count, and all other values compare by string
	 * length.
	 *
	 * @param mixed $value Value to measure.
	 * @return int|float Comparable size for validation rules.
	 */
	private function measure(mixed $value): int|float {
		if($value instanceof UploadedFile){
			return (int)ceil($value->size()/1024);
		}
		if(is_int($value) || is_float($value) || is_numeric($value)){
			return $value + 0;
		}
		if(is_array($value)){
			return count($value);
		}
		return strlen((string)$value);
	}
}
