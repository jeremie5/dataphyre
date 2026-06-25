<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Fluent sanitizer for one input value.
 *
 * Sanitizer accumulates a single sanitation rule array, delegates execution to
 * SanitationManager, and caches the detailed result until another rule mutator
 * changes the contract.
 */
final class Sanitizer {

	private array $rule=['type'=>'default'];
	private ?array $detail=null;

	/**
	 * Creates a fluent sanitizer bound to one manager and value.
	 *
	 * @param SanitationManager $manager Sanitation manager that owns rule execution.
	 * @param mixed $value Input value to sanitize.
	 */
	public function __construct(
		private readonly SanitationManager $manager,
		private readonly mixed $value
	){}

	/**
	 * Sets the sanitation type handled by SanitationManager.
	 *
	 * Non-numeric types clear scalar casting so a previous integer, float, or
	 * boolean shortcut cannot leak into text-oriented rules.
	 *
	 * @param string $type Sanitation type token.
	 * @return self Current sanitizer for chaining.
	 */
	public function type(string $type): self {
		$this->rule['type']=$type;
		if(!in_array(strtolower(trim($type)), ['integer', 'float', 'boolean', 'int', 'bool'], true)){
			unset($this->rule['cast']);
		}
		return $this->touch();
	}

	/**
	 * Requires a non-blank value.
	 *
	 * @param bool $required Required flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function required(bool $required=true): self {
		$this->rule['required']=$required;
		return $this->touch();
	}

	/**
	 * Requires this value when another field matches any supplied value.
	 *
	 * @param string $field Related field name.
	 * @param mixed ...$values Trigger values.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredIf(string $field, mixed ...$values): self {
		$this->rule['required_if']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	/**
	 * Requires this value unless another field matches a supplied value.
	 *
	 * @param string $field Related field name.
	 * @param mixed ...$values Exemption values.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredUnless(string $field, mixed ...$values): self {
		$this->rule['required_unless']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	/**
	 * Requires this value when at least one related field is present.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredWith(string ...$fields): self {
		$this->rule['required_with']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires this value when all related fields are present.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredWithAll(string ...$fields): self {
		$this->rule['required_with_all']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires this value when at least one related field is missing.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredWithout(string ...$fields): self {
		$this->rule['required_without']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires this value when all related fields are missing.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function requiredWithoutAll(string ...$fields): self {
		$this->rule['required_without_all']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires the field key to exist even when the value is blank.
	 *
	 * @param bool $present Presence requirement flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function mustBePresent(bool $present=true): self {
		$this->rule['must_present']=$present;
		return $this->touch();
	}

	/**
	 * Requires field presence when another field matches any supplied value.
	 *
	 * @param string $field Related field name.
	 * @param mixed ...$values Trigger values.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentIf(string $field, mixed ...$values): self {
		$this->rule['present_if']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	/**
	 * Requires field presence unless another field matches a supplied value.
	 *
	 * @param string $field Related field name.
	 * @param mixed ...$values Exemption values.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentUnless(string $field, mixed ...$values): self {
		$this->rule['present_unless']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	/**
	 * Requires field presence when at least one related field is present.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentWith(string ...$fields): self {
		$this->rule['present_with']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires field presence when all related fields are present.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentWithAll(string ...$fields): self {
		$this->rule['present_with_all']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires field presence when at least one related field is missing.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentWithout(string ...$fields): self {
		$this->rule['present_without']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Requires field presence when all related fields are missing.
	 *
	 * @param string ...$fields Related field names.
	 * @return self Current sanitizer for chaining.
	 */
	public function presentWithoutAll(string ...$fields): self {
		$this->rule['present_without_all']=self::normalizeFieldList($fields);
		return $this->touch();
	}

	/**
	 * Allows null values to pass without being treated as invalid.
	 *
	 * @param bool $nullable Nullable flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function nullable(bool $nullable=true): self {
		$this->rule['nullable']=$nullable;
		return $this->touch();
	}

	/**
	 * Trims surrounding whitespace before validation and output.
	 *
	 * @param bool $trim Trim flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function trim(bool $trim=true): self {
		$this->rule['trim']=$trim;
		return $this->touch();
	}

	/**
	 * Collapses repeated internal whitespace before validation and output.
	 *
	 * @param bool $squish Squish flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function squish(bool $squish=true): self {
		$this->rule['squish']=$squish;
		return $this->touch();
	}

	/**
	 * Converts string output to lowercase.
	 *
	 * @param bool $lower Lowercase flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function lower(bool $lower=true): self {
		$this->rule['lower']=$lower;
		return $this->touch();
	}

	/**
	 * Converts string output to uppercase.
	 *
	 * @param bool $upper Uppercase flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function upper(bool $upper=true): self {
		$this->rule['upper']=$upper;
		return $this->touch();
	}

	/**
	 * Enables or disables HTML escaping in sanitized string output.
	 *
	 * @param bool $escape Escape flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function escapeHtml(bool $escape=true): self {
		$this->rule['escape_html']=$escape;
		return $this->touch();
	}

	/**
	 * Marks output as raw by disabling HTML escaping.
	 *
	 * @param bool $raw Raw output flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function raw(bool $raw=true): self {
		$this->rule['escape_html']=!$raw;
		return $this->touch();
	}

	/**
	 * Sets the minimum string length.
	 *
	 * @param int $length Minimum length, clamped to zero.
	 * @return self Current sanitizer for chaining.
	 */
	public function min(int $length): self {
		$this->rule['min_length']=max(0, $length);
		return $this->touch();
	}

	/**
	 * Sets the maximum string length.
	 *
	 * @param int $length Maximum length, clamped to zero.
	 * @return self Current sanitizer for chaining.
	 */
	public function max(int $length): self {
		$this->rule['max_length']=max(0, $length);
		return $this->touch();
	}

	/**
	 * Supplies a fallback value when the input is blank or absent.
	 *
	 * @param mixed $value Default value.
	 * @return self Current sanitizer for chaining.
	 */
	public function fallback(mixed $value): self {
		$this->rule['default']=$value;
		$this->rule['default_provided']=true;
		return $this->touch();
	}

	/**
	 * Supplies a default value using explicit naming for fluent readability.
	 *
	 * @param mixed $value Default value.
	 * @return self Current sanitizer for chaining.
	 */
	public function withDefault(mixed $value): self {
		$this->rule['default']=$value;
		$this->rule['default_provided']=true;
		return $this->touch();
	}

	/**
	 * Sets the human-readable field label used in validation messages.
	 *
	 * @param string $label Field label.
	 * @return self Current sanitizer for chaining.
	 */
	public function label(string $label): self {
		$this->rule['label']=$label;
		return $this->touch();
	}

	/**
	 * Overrides the validation message for one rule key.
	 *
	 * @param string $rule Rule key.
	 * @param string $message Message template.
	 * @return self Current sanitizer for chaining.
	 */
	public function message(string $rule, string $message): self {
		$this->rule['messages'] ??= [];
		$this->rule['messages'][$rule]=$message;
		return $this->touch();
	}

	/**
	 * Merges multiple validation message overrides.
	 *
	 * @param array<string,string> $messages Rule-keyed message map.
	 * @return self Current sanitizer for chaining.
	 */
	public function messages(array $messages): self {
		$this->rule['messages']=array_merge($this->rule['messages'] ?? [], $messages);
		return $this->touch();
	}

	/**
	 * Requires an accepted truthy form value.
	 *
	 * @param bool $accepted Accepted-rule flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function accepted(bool $accepted=true): self {
		$this->rule['accepted']=$accepted;
		return $this->touch();
	}

	/**
	 * Requires a declined falsy form value.
	 *
	 * @param bool $declined Declined-rule flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function declined(bool $declined=true): self {
		$this->rule['declined']=$declined;
		return $this->touch();
	}

	/**
	 * Requires this value to match another field.
	 *
	 * @param string $field Related field name.
	 * @return self Current sanitizer for chaining.
	 */
	public function same(string $field): self {
		$this->rule['same']=$field;
		return $this->touch();
	}

	/**
	 * Requires this value to differ from another field.
	 *
	 * @param string $field Related field name.
	 * @return self Current sanitizer for chaining.
	 */
	public function different(string $field): self {
		$this->rule['different']=$field;
		return $this->touch();
	}

	/**
	 * Requires the string value to match a regular expression.
	 *
	 * @param string $pattern PCRE pattern.
	 * @return self Current sanitizer for chaining.
	 */
	public function regex(string $pattern): self {
		$this->rule['regex']=$pattern;
		return $this->touch();
	}

	/**
	 * Requires a numeric string with an exact number of digits.
	 *
	 * @param int $digits Digit count, clamped to zero.
	 * @return self Current sanitizer for chaining.
	 */
	public function digits(int $digits): self {
		$this->rule['digits']=max(0, $digits);
		return $this->touch();
	}

	/**
	 * Sets the minimum numeric value.
	 *
	 * @param int|float $value Minimum value.
	 * @return self Current sanitizer for chaining.
	 */
	public function minValue(int|float $value): self {
		$this->rule['min_value']=(float)$value;
		return $this->touch();
	}

	/**
	 * Sets the maximum numeric value.
	 *
	 * @param int|float $value Maximum value.
	 * @return self Current sanitizer for chaining.
	 */
	public function maxValue(int|float $value): self {
		$this->rule['max_value']=(float)$value;
		return $this->touch();
	}

	/**
	 * Sets the minimum item count for array/list values.
	 *
	 * @param int $items Minimum item count, clamped to zero.
	 * @return self Current sanitizer for chaining.
	 */
	public function minItems(int $items): self {
		$this->rule['min_items']=max(0, $items);
		return $this->touch();
	}

	/**
	 * Sets the maximum item count for array/list values.
	 *
	 * @param int $items Maximum item count, clamped to zero.
	 * @return self Current sanitizer for chaining.
	 */
	public function maxItems(int $items): self {
		$this->rule['max_items']=max(0, $items);
		return $this->touch();
	}

	/**
	 * Requires array/list values to contain distinct entries.
	 *
	 * Disabling distinct also disables case-insensitive distinctness so the rule
	 * state remains internally consistent.
	 *
	 * @param bool $distinct Distinctness flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function distinct(bool $distinct=true): self {
		$this->rule['distinct']=$distinct;
		if($distinct===false){
			$this->rule['distinct_ignore_case']=false;
		}
		return $this->touch();
	}

	/**
	 * Requires distinct array/list entries using optional case-insensitive checks.
	 *
	 * @param bool $ignore_case Case-insensitive distinctness flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function distinctIgnoreCase(bool $ignore_case=true): self {
		$this->rule['distinct']=true;
		$this->rule['distinct_ignore_case']=$ignore_case;
		return $this->touch();
	}

	/**
	 * Requires each item in a list of arrays/objects to be unique by field values.
	 *
	 * @param string ...$fields Item field names used as the uniqueness key.
	 * @return self Current sanitizer for chaining.
	 */
	public function uniqueBy(string ...$fields): self {
		$this->rule['unique_by']=self::normalizeFieldList($fields);
		$this->rule['unique_by_ignore_case']=false;
		return $this->touch();
	}

	/**
	 * Requires item uniqueness by fields using case-insensitive comparisons.
	 *
	 * @param string ...$fields Item field names used as the uniqueness key.
	 * @return self Current sanitizer for chaining.
	 */
	public function uniqueByIgnoreCase(string ...$fields): self {
		$this->rule['unique_by']=self::normalizeFieldList($fields);
		$this->rule['unique_by_ignore_case']=true;
		return $this->touch();
	}

	/**
	 * Removes blank values from sanitized output when enabled.
	 *
	 * @param bool $exclude Exclusion flag.
	 * @return self Current sanitizer for chaining.
	 */
	public function excludeWhenBlank(bool $exclude=true): self {
		$this->rule['exclude_when_blank']=$exclude;
		return $this->touch();
	}

	/**
	 * Restricts the value to an allow-list.
	 *
	 * @param list<scalar|null> $values Accepted values.
	 * @return self Current sanitizer for chaining.
	 */
	public function in(array $values): self {
		$this->rule['in']=array_values($values);
		return $this->touch();
	}

	/**
	 * Rejects values present in a deny-list.
	 *
	 * @param list<scalar|null> $values Rejected values.
	 * @return self Current sanitizer for chaining.
	 */
	public function notIn(array $values): self {
		$this->rule['not_in']=array_values($values);
		return $this->touch();
	}

	/**
	 * Requires a string to start with one of the supplied prefixes.
	 *
	 * @param string ...$values Accepted prefixes.
	 * @return self Current sanitizer for chaining.
	 */
	public function startsWith(string ...$values): self {
		$this->rule['starts_with']=array_values($values);
		return $this->touch();
	}

	/**
	 * Requires a string to end with one of the supplied suffixes.
	 *
	 * @param string ...$values Accepted suffixes.
	 * @return self Current sanitizer for chaining.
	 */
	public function endsWith(string ...$values): self {
		$this->rule['ends_with']=array_values($values);
		return $this->touch();
	}

	/**
	 * Requires a string to contain one of the supplied fragments.
	 *
	 * @param string ...$values Required fragments.
	 * @return self Current sanitizer for chaining.
	 */
	public function contains(string ...$values): self {
		$this->rule['contains']=array_values($values);
		return $this->touch();
	}

	/**
	 * Adds a custom validation callback to the rule.
	 *
	 * Callbacks are executed by SanitationManager with the detailed sanitation
	 * context, letting applications enforce constraints that are too specific for
	 * built-in rule keys.
	 *
	 * @param callable $callback Custom validator callback.
	 * @return self Current sanitizer for chaining.
	 */
	public function validate(callable $callback): self {
		$this->rule['callbacks'] ??= [];
		$this->rule['callbacks'][]=$callback;
		return $this->touch();
	}

	/**
	 * Uses the email sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function email(): self { return $this->type('email'); }
	/**
	 * Uses the URL sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function url(): self { return $this->type('url'); }
	/**
	 * Uses the phone-number sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function phone(): self { return $this->type('phone_number'); }
	/**
	 * Uses the person-name sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function name(): self { return $this->type('person_name'); }
	/**
	 * Uses the default text sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function text(): self { return $this->type('default'); }
	/**
	 * Uses the text-without-special-characters sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function textNoSpecial(): self { return $this->type('text_nospecial'); }
	/**
	 * Allows the basic safe-HTML sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function basicHtml(): self { return $this->type('basic_html'); }
	/**
	 * Allows unrestricted HTML sanitation.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function unrestrictedHtml(): self { return $this->type('unrestricted'); }
	/**
	 * Uses the numeric sanitizer type without forcing integer or float casting.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function numeric(): self { return $this->type('numeric'); }
	/**
	 * Casts sanitized output to integer and uses the integer sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function integer(): self { $this->rule['cast']='integer'; return $this->type('integer'); }
	/**
	 * Casts sanitized output to float and uses the float sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function float(): self { $this->rule['cast']='float'; return $this->type('float'); }
	/**
	 * Casts sanitized output to boolean and uses the boolean sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function boolean(): self { $this->rule['cast']='boolean'; return $this->type('boolean'); }
	/**
	 * Uses the associative-array sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function arrayValue(): self { return $this->type('array'); }
	/**
	 * Uses the list sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function listValue(): self { return $this->type('list'); }
	/**
	 * Uses the slug sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function slug(): self { return $this->type('slug'); }
	/**
	 * Uses the username sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function username(): self { return $this->type('username'); }
	/**
	 * Uses the postal-code sanitizer type.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	public function postalCode(): self { return $this->type('postal_code'); }

	/**
	 * Executes the current rule and returns the sanitized value.
	 *
	 * @return mixed sanitized/cast value, false on validation failure, or null for omitted nullable input.
	 */
	public function sanitize(): mixed {
		return $this->detail()['value'];
	}

	/**
	 * Alias for sanitize() for fluent read sites.
	 *
	 * @return mixed sanitized/cast value, false on validation failure, or null for omitted nullable input.
	 */
	public function get(): mixed {
		return $this->sanitize();
	}

	/**
	 * Checks whether the current rule passes validation.
	 *
	 * @return bool Validation success flag.
	 */
	public function valid(): bool {
		return $this->detail()['failed']===false;
	}

	/**
	 * Checks whether the current rule failed validation.
	 *
	 * @return bool Validation failure flag.
	 */
	public function failed(): bool {
		return $this->detail()['failed']===true;
	}

	/**
	 * Returns the first validation error for the current rule.
	 *
	 * @return ?string Validation error message, or null when valid.
	 */
	public function error(): ?string {
		return $this->detail()['error'];
	}

	/**
	 * Executes sanitation once and caches the detailed result.
	 *
	 * @return array Detailed sanitation payload from SanitationManager.
	 */
	private function detail(): array {
		return $this->detail ??= $this->manager->sanitizeDetailed($this->value, $this->rule, ['present'=>true]);
	}

	/**
	 * @param array<int, string> $fields
	 * @return array<int, string>
	 */
	private static function normalizeFieldList(array $fields): array {
		$normalized=[];
		foreach($fields as $field){
			$field=trim($field);
			if($field!==''){
				$normalized[]=$field;
			}
		}
		return $normalized;
	}

	/**
	 * Invalidates the cached detail result after a rule mutation.
	 *
	 * @return self Current sanitizer for chaining.
	 */
	private function touch(): self {
		$this->detail=null;
		return $this;
	}
}
