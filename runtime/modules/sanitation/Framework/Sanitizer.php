<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class Sanitizer {

	private array $rule=['type'=>'default'];
	private ?array $detail=null;

	public function __construct(
		private readonly SanitationManager $manager,
		private readonly mixed $value
	){}

	public function type(string $type): self {
		$this->rule['type']=$type;
		if(!in_array(strtolower(trim($type)), ['integer', 'float', 'boolean', 'int', 'bool'], true)){
			unset($this->rule['cast']);
		}
		return $this->touch();
	}

	public function required(bool $required=true): self {
		$this->rule['required']=$required;
		return $this->touch();
	}

	public function requiredIf(string $field, mixed ...$values): self {
		$this->rule['required_if']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	public function requiredUnless(string $field, mixed ...$values): self {
		$this->rule['required_unless']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	public function requiredWith(string ...$fields): self {
		$this->rule['required_with']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function requiredWithAll(string ...$fields): self {
		$this->rule['required_with_all']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function requiredWithout(string ...$fields): self {
		$this->rule['required_without']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function requiredWithoutAll(string ...$fields): self {
		$this->rule['required_without_all']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function mustBePresent(bool $present=true): self {
		$this->rule['must_present']=$present;
		return $this->touch();
	}

	public function presentIf(string $field, mixed ...$values): self {
		$this->rule['present_if']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	public function presentUnless(string $field, mixed ...$values): self {
		$this->rule['present_unless']=[
			'field'=>trim($field),
			'values'=>$values,
		];
		return $this->touch();
	}

	public function presentWith(string ...$fields): self {
		$this->rule['present_with']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function presentWithAll(string ...$fields): self {
		$this->rule['present_with_all']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function presentWithout(string ...$fields): self {
		$this->rule['present_without']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function presentWithoutAll(string ...$fields): self {
		$this->rule['present_without_all']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		return $this->touch();
	}

	public function nullable(bool $nullable=true): self {
		$this->rule['nullable']=$nullable;
		return $this->touch();
	}

	public function trim(bool $trim=true): self {
		$this->rule['trim']=$trim;
		return $this->touch();
	}

	public function squish(bool $squish=true): self {
		$this->rule['squish']=$squish;
		return $this->touch();
	}

	public function lower(bool $lower=true): self {
		$this->rule['lower']=$lower;
		return $this->touch();
	}

	public function upper(bool $upper=true): self {
		$this->rule['upper']=$upper;
		return $this->touch();
	}

	public function escapeHtml(bool $escape=true): self {
		$this->rule['escape_html']=$escape;
		return $this->touch();
	}

	public function raw(bool $raw=true): self {
		$this->rule['escape_html']=!$raw;
		return $this->touch();
	}

	public function min(int $length): self {
		$this->rule['min_length']=max(0, $length);
		return $this->touch();
	}

	public function max(int $length): self {
		$this->rule['max_length']=max(0, $length);
		return $this->touch();
	}

	public function fallback(mixed $value): self {
		$this->rule['default']=$value;
		$this->rule['default_provided']=true;
		return $this->touch();
	}

	public function withDefault(mixed $value): self {
		$this->rule['default']=$value;
		$this->rule['default_provided']=true;
		return $this->touch();
	}

	public function label(string $label): self {
		$this->rule['label']=$label;
		return $this->touch();
	}

	public function message(string $rule, string $message): self {
		$this->rule['messages'] ??= [];
		$this->rule['messages'][$rule]=$message;
		return $this->touch();
	}

	public function messages(array $messages): self {
		$this->rule['messages']=array_merge($this->rule['messages'] ?? [], $messages);
		return $this->touch();
	}

	public function accepted(bool $accepted=true): self {
		$this->rule['accepted']=$accepted;
		return $this->touch();
	}

	public function declined(bool $declined=true): self {
		$this->rule['declined']=$declined;
		return $this->touch();
	}

	public function same(string $field): self {
		$this->rule['same']=$field;
		return $this->touch();
	}

	public function different(string $field): self {
		$this->rule['different']=$field;
		return $this->touch();
	}

	public function regex(string $pattern): self {
		$this->rule['regex']=$pattern;
		return $this->touch();
	}

	public function digits(int $digits): self {
		$this->rule['digits']=max(0, $digits);
		return $this->touch();
	}

	public function minValue(int|float $value): self {
		$this->rule['min_value']=(float)$value;
		return $this->touch();
	}

	public function maxValue(int|float $value): self {
		$this->rule['max_value']=(float)$value;
		return $this->touch();
	}

	public function minItems(int $items): self {
		$this->rule['min_items']=max(0, $items);
		return $this->touch();
	}

	public function maxItems(int $items): self {
		$this->rule['max_items']=max(0, $items);
		return $this->touch();
	}

	public function distinct(bool $distinct=true): self {
		$this->rule['distinct']=$distinct;
		if($distinct===false){
			$this->rule['distinct_ignore_case']=false;
		}
		return $this->touch();
	}

	public function distinctIgnoreCase(bool $ignore_case=true): self {
		$this->rule['distinct']=true;
		$this->rule['distinct_ignore_case']=$ignore_case;
		return $this->touch();
	}

	public function uniqueBy(string ...$fields): self {
		$this->rule['unique_by']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		$this->rule['unique_by_ignore_case']=false;
		return $this->touch();
	}

	public function uniqueByIgnoreCase(string ...$fields): self {
		$this->rule['unique_by']=array_values(array_filter(array_map('trim', $fields), static fn(string $field): bool => $field!==''));
		$this->rule['unique_by_ignore_case']=true;
		return $this->touch();
	}

	public function excludeWhenBlank(bool $exclude=true): self {
		$this->rule['exclude_when_blank']=$exclude;
		return $this->touch();
	}

	public function in(array $values): self {
		$this->rule['in']=array_values($values);
		return $this->touch();
	}

	public function notIn(array $values): self {
		$this->rule['not_in']=array_values($values);
		return $this->touch();
	}

	public function startsWith(string ...$values): self {
		$this->rule['starts_with']=array_values($values);
		return $this->touch();
	}

	public function endsWith(string ...$values): self {
		$this->rule['ends_with']=array_values($values);
		return $this->touch();
	}

	public function contains(string ...$values): self {
		$this->rule['contains']=array_values($values);
		return $this->touch();
	}

	public function validate(callable $callback): self {
		$this->rule['callbacks'] ??= [];
		$this->rule['callbacks'][]=$callback;
		return $this->touch();
	}

	public function email(): self { return $this->type('email'); }
	public function url(): self { return $this->type('url'); }
	public function phone(): self { return $this->type('phone_number'); }
	public function name(): self { return $this->type('person_name'); }
	public function text(): self { return $this->type('default'); }
	public function textNoSpecial(): self { return $this->type('text_nospecial'); }
	public function basicHtml(): self { return $this->type('basic_html'); }
	public function unrestrictedHtml(): self { return $this->type('unrestricted'); }
	public function numeric(): self { return $this->type('numeric'); }
	public function integer(): self { $this->rule['cast']='integer'; return $this->type('integer'); }
	public function float(): self { $this->rule['cast']='float'; return $this->type('float'); }
	public function boolean(): self { $this->rule['cast']='boolean'; return $this->type('boolean'); }
	public function arrayValue(): self { return $this->type('array'); }
	public function listValue(): self { return $this->type('list'); }
	public function slug(): self { return $this->type('slug'); }
	public function username(): self { return $this->type('username'); }
	public function postalCode(): self { return $this->type('postal_code'); }

	public function sanitize(): mixed {
		return $this->detail()['value'];
	}

	public function get(): mixed {
		return $this->sanitize();
	}

	public function valid(): bool {
		return $this->detail()['failed']===false;
	}

	public function failed(): bool {
		return $this->detail()['failed']===true;
	}

	public function error(): ?string {
		return $this->detail()['error'];
	}

	private function detail(): array {
		return $this->detail ??= $this->manager->sanitizeDetailed($this->value, $this->rule, ['present'=>true]);
	}

	private function touch(): self {
		$this->detail=null;
		return $this;
	}
}
