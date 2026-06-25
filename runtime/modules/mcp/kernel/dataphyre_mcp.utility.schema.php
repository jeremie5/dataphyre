<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/**
 * MCP schema naming and field-hint utility methods.
 */
trait dataphyre_mcp_utility_schema_methods {

	/**
	 * Normalizes a human name into a URL/file-safe slug.
	 *
	 * scaffold plans use this deterministic fallback to avoid empty
	 * artifact names while keeping generated plans dry-run only.
	 */
	private function slug_name(string $name): string {
		$slug=strtolower(implode('-', $this->name_tokens($name)));
		return $slug!=='' ? $slug : 'dataphyre-item';
	}

	/**
	 * Normalizes a human name into a PascalCase class-like token.
	 *
	 * scaffold plans use this value for proposed class names without
	 * creating PHP files or asserting that the class is available.
	 */
	private function studly_name(string $name): string {
		$value='';
		foreach($this->name_tokens($name) as $part){
			$value.=$this->studly_token($part);
		}
		return $value!=='' ? $value : 'DataphyreItem';
	}

	/**
	 * Normalizes one token for PascalCase output while preserving common enterprise acronyms.
	 *
	 * @param string $token Alphanumeric token.
	 * @return string PascalCase token.
	 */
	private function studly_token(string $token): string {
		$normalized=$this->enterprise_acronym_token($token);
		if($normalized!==null){
			return $normalized;
		}
		return ucfirst(strtolower($token));
	}

	/**
	 * Returns the canonical spelling for common acronym tokens.
	 *
	 * @param string $token Alphanumeric token.
	 * @return string|null Canonical acronym spelling, or null for ordinary words.
	 */
	private function enterprise_acronym_token(string $token): ?string {
		return match(strtolower($token)){
			'api'=>'API',
			'dpa'=>'DPA',
			'id'=>'ID',
			'jwt'=>'JWT',
			'kyc'=>'KYC',
			'mfa'=>'MFA',
			'oauth'=>'OAuth',
			'saml'=>'SAML',
			'scim'=>'SCIM',
			'scc'=>'SCC',
			'sla'=>'SLA',
			'sso'=>'SSO',
			'totp'=>'TOTP',
			'uri'=>'URI',
			'url'=>'URL',
			'uuid'=>'UUID',
			default=>null,
		};
	}

	/**
	 * Tokenizes human, snake/kebab, camelCase, and PascalCase names.
	 *
	 * @param string $name Raw name.
	 * @return array<int,string> Alphanumeric name tokens.
	 */
	private function name_tokens(string $name): array {
		$name=trim($name);
		$name=(string)preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
		$name=(string)preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name);
		$parts=preg_split('/[^A-Za-z0-9]+/', $name) ?: [];
		return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part!==''));
	}

	/**
	 * Extracts bounded field hints from scaffold input.
	 *
	 * field metadata is reduced to bounded name, type, required, options, and default hints so
	 * scaffold plans can guide implementation without treating user input as a
	 * complete schema definition.
	 */
	private function field_hints(array $fields): array {
		$hints=[];
		foreach(array_slice($fields, 0, 40, true) as $name=>$definition){
			$field_name=is_string($name) ? $name : (is_string($definition) ? $definition : (string)($definition['name'] ?? ''));
			if($field_name===''){
				continue;
			}
			$hint=[
				'name'=>$field_name,
				'type'=>$this->field_hint_type($definition),
				'required'=>$this->field_hint_required($definition),
			];
			$options=$this->field_hint_options($definition);
			if($options!==[]){
				$hint['options']=$options;
			}
			$default=$this->field_hint_default($definition);
			if($default!==null){
				$hint['default']=$default;
			}
			if($this->field_hint_unique($definition)){
				$hint['unique']=true;
			}
			$unique_with=$this->field_hint_unique_with($definition);
			if($unique_with!==[]){
				$hint['unique_with']=$unique_with;
			}
			if($this->field_hint_denies_foreign_key($definition)){
				$hint['not_foreign_key']=true;
			}
			$target=$this->field_hint_foreign_key_target($definition);
			if($target!==null){
				$hint['foreign_key_target']=$target;
			}
			$hints[]=$hint;
		}
		return $hints;
	}

	/**
	 * Normalizes field hint type metadata from structured or phrase-style input.
	 *
	 * @param mixed $definition Field definition input.
	 * @return string Normalized field type.
	 */
	private function field_hint_type(mixed $definition): string {
		$value=is_array($definition) ? (string)($definition['type'] ?? 'string') : (string)$definition;
		$lower=strtolower($value);
		$denies_foreign_key=$this->field_hint_denies_foreign_key($definition);
		if(!$denies_foreign_key && (str_contains($lower, 'foreign key') || str_contains($lower, 'foreign_key_target') || str_contains($lower, 'belongs to') || str_contains($lower, 'belongs_to'))){
			return 'integer';
		}
		if(preg_match('/\b(json|jsonb)\b/i', $lower)===1){
			return 'json';
		}
		if(preg_match('/\b(enum|select|choice|choices|status)\b/i', $lower)===1){
			return 'string';
		}
		if(str_contains($lower, 'datetime') || str_contains($lower, 'timestamp')){
			return 'datetime';
		}
		if(str_contains($lower, 'date')){
			return 'date';
		}
		if(preg_match('/\b(bool|boolean)\b/i', $lower)===1){
			return 'boolean';
		}
		if(preg_match('/\b(int|integer|number)\b/i', $lower)===1){
			return 'integer';
		}
		if(preg_match('/\b(float|decimal)\b/i', $lower)===1){
			return 'decimal';
		}
		if(str_contains($lower, 'text')){
			return 'text';
		}
		return 'string';
	}

	/**
	 * Detects field metadata that explicitly says an id-like value is not a relation.
	 *
	 * @param mixed $definition Field definition input.
	 * @return bool True when foreign-key inference should be suppressed.
	 */
	private function field_hint_denies_foreign_key(mixed $definition): bool {
		$values=[];
		if(is_array($definition)){
			foreach(['not_foreign_key', 'foreign_key', 'belongs_to', 'relationship', 'references'] as $key){
				if(!array_key_exists($key, $definition)){
					continue;
				}
				$value=$definition[$key];
				if($key==='not_foreign_key' && (bool)$value===true){
					return true;
				}
				if($value===false || $value===0 || in_array(strtolower(trim((string)$value)), ['false', 'no', 'none', 'not_fk', 'not_foreign_key'], true)){
					return true;
				}
			}
			foreach(['type', 'description', 'note', 'notes'] as $key){
				if(isset($definition[$key]) && (is_string($definition[$key]) || is_numeric($definition[$key]))){
					$values[]=(string)$definition[$key];
				}
			}
		} else {
			$values[]=(string)$definition;
		}
		$text=strtolower(trim(implode(' ', $values)));
		if($text===''){
			return false;
		}
		return preg_match('/\b(?:not|no|non)[ -]?(?:a\s+)?foreign[ -]?key\b|\bnot\s+(?:a\s+)?belongs(?:\s+to|_to)\b|\bnot\s+(?:a\s+)?relationship\b/i', $text)===1;
	}

	/**
	 * Normalizes field hint required metadata from structured or phrase-style input.
	 *
	 * @param mixed $definition Field definition input.
	 * @return bool True when the field should be treated as required.
	 */
	private function field_hint_required(mixed $definition): bool {
		if(is_array($definition)){
			if(array_key_exists('required', $definition)){
				return (bool)$definition['required'];
			}
			$values=[];
			foreach(['type', 'description', 'note', 'notes'] as $key){
				if(isset($definition[$key]) && (is_string($definition[$key]) || is_numeric($definition[$key]))){
					$values[]=(string)$definition[$key];
				}
			}
			$lower=strtolower(trim(implode(' ', $values)));
			return $lower!=='' && str_contains($lower, 'required') && !str_contains($lower, 'not required');
		}
		$lower=strtolower((string)$definition);
		return str_contains($lower, 'required') && !str_contains($lower, 'not required');
	}

	/**
	 * Extracts bounded select/enum options from structured or phrase-style metadata.
	 *
	 * @param mixed $definition Field definition input.
	 * @return array<int,string> Option labels or values.
	 */
	private function field_hint_options(mixed $definition): array {
		if(is_array($definition)){
			foreach(['options', 'choices', 'enum'] as $key){
				$value=$definition[$key] ?? null;
				if(is_array($value)){
					return $this->field_hint_option_list($value);
				}
				if(is_string($value) && trim($value)!==''){
					return $this->field_hint_options_from_text($value);
				}
			}
			$type=trim((string)($definition['type'] ?? ''));
			return $type!=='' ? $this->field_hint_options_from_text($type) : [];
		}
		return $this->field_hint_options_from_text((string)$definition);
	}

	/**
	 * Extracts a bounded default value from structured or phrase-style metadata.
	 *
	 * @param mixed $definition Field definition input.
	 * @return string|int|float|bool|null Default value when supplied.
	 */
	private function field_hint_default(mixed $definition): mixed {
		if(is_array($definition)){
			foreach(['default', 'default_value'] as $key){
				if(array_key_exists($key, $definition)){
					$value=$definition[$key];
					if(is_bool($value) || is_int($value) || is_float($value)){
						return $value;
					}
					if(is_string($value) || is_numeric($value)){
						$string_value=trim((string)$value);
						return $string_value!=='' ? substr($string_value, 0, 80) : null;
					}
					return null;
				}
			}
			$type=trim((string)($definition['type'] ?? ''));
			return $type!=='' ? $this->field_hint_default_from_text($type) : null;
		}
		return $this->field_hint_default_from_text((string)$definition);
	}

	/**
	 * Detects explicit uniqueness metadata from structured or phrase-style field hints.
	 *
	 * @param mixed $definition Field definition input.
	 * @return bool True when the field should be treated as unique.
	 */
	private function field_hint_unique(mixed $definition): bool {
		if(is_array($definition)){
			if(array_key_exists('unique', $definition)){
				return (bool)$definition['unique'];
			}
			$values=[];
			foreach(['type', 'description', 'note', 'notes'] as $key){
				if(isset($definition[$key]) && (is_string($definition[$key]) || is_numeric($definition[$key]))){
					$values[]=(string)$definition[$key];
				}
			}
			$text=strtolower(trim(implode(' ', $values)));
			return $text!=='' && preg_match('/\bunique\b/i', $text)===1 && !str_contains($text, 'not unique');
		}
		$text=strtolower((string)$definition);
		return $text!=='' && preg_match('/\bunique\b/i', $text)===1 && !str_contains($text, 'not unique');
	}

	/**
	 * Extracts compound uniqueness metadata from structured field hints.
	 *
	 * @param mixed $definition Field definition input.
	 * @return array<int,string> Companion fields for a unique-with constraint.
	 */
	private function field_hint_unique_with(mixed $definition): array {
		if(!is_array($definition)){
			return [];
		}
		$value=$definition['unique_with'] ?? $definition['unique_scope'] ?? null;
		$values=is_array($value) ? $value : (is_string($value) ? preg_split('/[,|]+/', $value) : []);
		$fields=[];
		foreach(is_array($values) ? $values : [] as $field){
			$field=trim((string)preg_replace('/[^A-Za-z0-9_]+/', '', (string)$field));
			if($field!==''){
				$fields[]=$field;
			}
		}
		return array_values(array_unique($fields));
	}

	/**
	 * Parses enum/select option text without broad schema interpretation.
	 *
	 * @param string $value Raw type/field hint text.
	 * @return array<int,string> Bounded option values.
	 */
	private function field_hint_options_from_text(string $value): array {
		$value=trim($value);
		if($value===''){
			return [];
		}
		if(preg_match('/\b(?:enum|select|choice|choices)\b\s*[:=]?\s*(.+?)(?:\s+\bdefault\b|\s+\brequired\b|\s+\bnullable\b|\s+\boptional\b|\s+\bnot\s+required\b|$)/i', $value, $match)!==1){
			return [];
		}
		$raw_options=(str_contains($match[1], ',') || str_contains($match[1], '|'))
			? (preg_split('/[,|]+/', $match[1]) ?: [])
			: (preg_split('/\s+/', $match[1]) ?: []);
		return $this->field_hint_option_list($raw_options);
	}

	/**
	 * Normalizes a caller-provided option list.
	 *
	 * @param array<int|string,mixed> $values Raw values.
	 * @return array<int,string> Bounded option values.
	 */
	private function field_hint_option_list(array $values): array {
		$options=[];
		foreach(array_slice($values, 0, 24, true) as $key=>$value){
			$option=is_string($value) || is_numeric($value) ? (string)$value : (is_string($key) ? $key : '');
			$option=trim((string)preg_replace('/[^A-Za-z0-9 _.:@-]+/', '', $option));
			if($option===''){
				continue;
			}
			$options[]=substr($option, 0, 80);
		}
		return array_values(array_unique($options));
	}

	/**
	 * Parses a compact default value from field hint text.
	 *
	 * @param string $value Raw type/field hint text.
	 * @return string|null Default value when supplied.
	 */
	private function field_hint_default_from_text(string $value): ?string {
		if(preg_match('/\bdefault\b\s*[:=]?\s*([A-Za-z0-9_.:@-]+)/i', $value, $match)!==1){
			return null;
		}
		$default=trim($match[1]);
		return $default!=='' ? substr($default, 0, 80) : null;
	}

	/**
	 * Extracts a relation target from structured or phrase-style field metadata.
	 *
	 * @param mixed $definition Field definition input.
	 * @return string|null Singular target label, or null when none is declared.
	 */
	private function field_hint_foreign_key_target(mixed $definition): ?string {
		if($this->field_hint_denies_foreign_key($definition)){
			return null;
		}
		if(is_array($definition)){
			foreach(['foreign_key_target', 'target', 'references', 'relation'] as $key){
				$value=trim((string)($definition[$key] ?? ''));
				if($value!==''){
					return $this->singular_relation_target($value);
				}
			}
			$type=trim((string)($definition['type'] ?? ''));
			if($type===''){
				return null;
			}
			$definition=$type;
		}
		$value=strtolower(trim((string)$definition));
		if($value===''){
			return null;
		}
		if(preg_match('/(?:foreign\s+key\s+to|foreign_key_target|belongs(?:\s+to|_to))\s+([a-z0-9 _-]+?)(?:\s+(?:required|nullable|optional|not\s+required)|$)/i', $value, $match)!==1){
			return null;
		}
		return $this->singular_relation_target($match[1]);
	}

	/**
	 * Normalizes plural relation target phrases into singular labels.
	 *
	 * @param string $target Raw relation target phrase.
	 * @return string Singular-ish target phrase.
	 */
	private function singular_relation_target(string $target): string {
		$target=trim((string)preg_replace('/[^A-Za-z0-9 _-]+/', ' ', $target));
		$target=(string)preg_replace('/\s+/', ' ', $target);
		if($target===''){
			return '';
		}
		$parts=preg_split('/[\s_-]+/', $target) ?: [];
		$last_index=count($parts)-1;
		if($last_index>=0){
			$last=$parts[$last_index];
			$lower=strtolower($last);
			if(str_ends_with($lower, 'ies') && strlen($last)>3){
				$parts[$last_index]=substr($last, 0, -3).'y';
			}elseif(str_ends_with($lower, 'sses') && strlen($last)>4){
				$parts[$last_index]=substr($last, 0, -2);
			}elseif(str_ends_with($lower, 'ses') && strlen($last)>3){
				$parts[$last_index]=substr($last, 0, -2);
			}elseif(str_ends_with($lower, 's') && !str_ends_with($lower, 'ss') && strlen($last)>1){
				$parts[$last_index]=substr($last, 0, -1);
			}
		}
		return implode(' ', $parts);
	}
}
