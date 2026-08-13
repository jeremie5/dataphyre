<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic input, schema, JSON, redaction, and hashing guard. */
final class PanelAgentGuard {
	public const MAX_DEPTH=12;
	public const MAX_NODES=4096;
	private const SCHEMA_KEYS=['type','properties','required','additionalProperties','items','enum','minLength','maxLength','pattern','minimum','maximum','minItems','maxItems'];

	public static function identifier(string $value, string $label, int $maximum=96): string {
		$value=strtolower(trim($value));
		if($value==='' || strlen($value)>$maximum || preg_match('/^[a-z][a-z0-9_.:-]*$/D', $value)!==1){
			throw new \InvalidArgumentException("Panel agent {$label} is invalid.");
		}
		return $value;
	}

	public static function boundedString(mixed $value, string $label, int $maximum, bool $allowEmpty=false): string {
		if(!is_string($value)){ throw new \InvalidArgumentException("Panel agent {$label} must be a string."); }
		$value=trim($value);
		if((!$allowEmpty && $value==='') || strlen($value)>$maximum || str_contains($value, "\0")){
			throw new \InvalidArgumentException("Panel agent {$label} is invalid.");
		}
		return $value;
	}

	public static function digest(string $value, string $label='digest'): string {
		$value=strtolower(trim($value));
		if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException("Panel agent {$label} is invalid."); }
		return $value;
	}

	public static function canonicalJson(mixed $value): string {
		return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	public static function assertJson(mixed $value, int $maximumBytes=262144, int $maximumDepth=self::MAX_DEPTH, int $maximumNodes=self::MAX_NODES): void {
		$nodes=0;
		self::walkJson($value, 0, $maximumDepth, $maximumNodes, $nodes);
		if(strlen(self::canonicalJson($value))>$maximumBytes){ throw new \LengthException('Panel agent JSON value exceeds its byte limit.'); }
	}

	/** @param array<string,mixed> $schema */
	public static function assertSchema(array $schema, int $depth=0): void {
		if($depth>self::MAX_DEPTH){ throw new \LengthException('Panel agent input schema exceeds its depth limit.'); }
		foreach(array_keys($schema) as $key){
			if(!is_string($key) || !in_array($key, self::SCHEMA_KEYS, true)){ throw new \InvalidArgumentException('Panel agent input schema contains an unsupported keyword.'); }
		}
		$type=$schema['type'] ?? 'object';
		if(!is_string($type) || !in_array($type, ['object','array','string','integer','number','boolean','null'], true)){
			throw new \InvalidArgumentException('Panel agent input schema type is unsupported.');
		}
		if(isset($schema['required']) && (!is_array($schema['required']) || !array_is_list($schema['required']))){ throw new \InvalidArgumentException('Panel agent required schema entries must be a list.'); }
		if(isset($schema['properties'])){
			if(!is_array($schema['properties']) || ($schema['properties']!==[] && array_is_list($schema['properties']))){ throw new \InvalidArgumentException('Panel agent schema properties must be an object map.'); }
			foreach($schema['properties'] as $name=>$nested){
				self::identifier((string)$name, 'schema property', 96);
				if(!is_array($nested)){ throw new \InvalidArgumentException('Panel agent nested schemas must be objects.'); }
				self::assertSchema($nested, $depth+1);
			}
		}
		if(isset($schema['items'])){
			if(!is_array($schema['items'])){ throw new \InvalidArgumentException('Panel agent array item schemas must be objects.'); }
			self::assertSchema($schema['items'], $depth+1);
		}
		if(isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])){ throw new \InvalidArgumentException('Panel agent additionalProperties must be boolean.'); }
		if(isset($schema['pattern']) && (!is_string($schema['pattern']) || @preg_match($schema['pattern'], '')===false)){ throw new \InvalidArgumentException('Panel agent schema patterns must be valid regular expressions.'); }
		if(isset($schema['enum']) && (!is_array($schema['enum']) || $schema['enum']===[])){ throw new \InvalidArgumentException('Panel agent schema enum must be a non-empty list.'); }
		self::assertJson($schema, 131072);
	}

	/** @param array<string,mixed> $arguments @param array<string,mixed> $schema @return array<string,mixed> */
	public static function normalizeArguments(array $arguments, array $schema): array {
		self::assertJson($arguments);
		self::assertSchema($schema);
		$value=self::normalizeValue($arguments, $schema, '$', 0);
		if(!is_array($value) || ($value!==[] && array_is_list($value))){ throw new PanelAgentException('arguments_invalid', 'Panel agent tool arguments must be an object.'); }
		return $value;
	}

	public static function boundedOutput(mixed $value, int $maximumBytes): mixed {
		self::assertJson($value, $maximumBytes);
		return self::redact($value);
	}

	public static function redact(mixed $value): mixed {
		return PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>self::MAX_DEPTH,'max_items'=>1000,'max_string_bytes'=>65536]);
	}

	public static function safeError(string $message, int $maximumBytes): string {
		$message=PanelSensitiveDataSanitizer::sanitize(trim($message),['max_depth'=>2,'max_items'=>2,'max_string_bytes'=>max(16,$maximumBytes-3)]);
		return !is_string($message) || $message==='' ? 'Tool execution failed.' : $message;
	}

	private static function walkJson(mixed $value, int $depth, int $maximumDepth, int $maximumNodes, int &$nodes): void {
		$nodes++;
		if($nodes>$maximumNodes){ throw new \LengthException('Panel agent JSON value exceeds its node limit.'); }
		if($depth>$maximumDepth){ throw new \LengthException('Panel agent JSON value exceeds its depth limit.'); }
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){ return; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException('Panel agent JSON numbers must be finite.'); }
			return;
		}
		if(!is_array($value)){ throw new \InvalidArgumentException('Panel agent values must contain JSON data only.'); }
		foreach($value as $key=>$nested){
			if(!is_int($key) && !is_string($key)){ throw new \InvalidArgumentException('Panel agent JSON keys must be strings or integers.'); }
			self::walkJson($nested, $depth+1, $maximumDepth, $maximumNodes, $nodes);
		}
	}

	private static function canonicalize(mixed $value): mixed {
		if(!is_array($value)){ return $value; }
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$nested){ $value[$key]=self::canonicalize($nested); }
		return $value;
	}

	/** @param array<string,mixed> $schema */
	private static function normalizeValue(mixed $value, array $schema, string $path, int $depth): mixed {
		if($depth>self::MAX_DEPTH){ throw new PanelAgentException('arguments_too_deep', 'Panel agent tool arguments exceed their depth limit.', 413); }
		$type=(string)($schema['type'] ?? 'object');
		$valid=match($type){
			'object'=>is_array($value) && ($value===[] || !array_is_list($value)),
			'array'=>is_array($value) && array_is_list($value),
			'string'=>is_string($value),
			'integer'=>is_int($value),
			'number'=>is_int($value) || (is_float($value) && is_finite($value)),
			'boolean'=>is_bool($value),
			'null'=>$value===null,
			default=>false,
		};
		if(!$valid){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} must be {$type}."); }
		if(isset($schema['enum']) && !in_array($value, $schema['enum'], true)){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} is not an allowed value."); }
		if(is_string($value)){
			$length=strlen($value);
			if(isset($schema['minLength']) && $length<(int)$schema['minLength']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} is too short."); }
			if(isset($schema['maxLength']) && $length>(int)$schema['maxLength']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} is too long."); }
			if(isset($schema['pattern']) && preg_match((string)$schema['pattern'], $value)!==1){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} has an invalid format."); }
		}
		if(is_int($value) || is_float($value)){
			if(isset($schema['minimum']) && $value<$schema['minimum']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} is below its minimum."); }
			if(isset($schema['maximum']) && $value>$schema['maximum']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} exceeds its maximum."); }
		}
		if($type==='array'){
			if(isset($schema['minItems']) && count($value)<(int)$schema['minItems']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} has too few items."); }
			if(isset($schema['maxItems']) && count($value)>(int)$schema['maxItems']){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path} has too many items."); }
			if(isset($schema['items'])){ foreach($value as $index=>$nested){ $value[$index]=self::normalizeValue($nested, $schema['items'], $path.'.'.$index, $depth+1); } }
		}
		if($type==='object'){
			$properties=is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
			foreach(is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required){
				if(!is_string($required) || !array_key_exists($required, $value)){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path}.{$required} is required."); }
			}
			foreach($value as $key=>$nested){
				$key=(string)$key;
				if(!isset($properties[$key])){
					if(($schema['additionalProperties'] ?? false)!==true){ throw new PanelAgentException('arguments_invalid', "Panel agent argument {$path}.{$key} is not allowed."); }
					continue;
				}
				$value[$key]=self::normalizeValue($nested, $properties[$key], $path.'.'.$key, $depth+1);
			}
			ksort($value, SORT_STRING);
		}
		return $value;
	}
}
