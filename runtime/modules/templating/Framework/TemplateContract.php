<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable contract definition for template data and slots.
 *
 * A contract describes required and optional data keys, required and optional
 * slots, default values, expected prop types, and whether undeclared data or
 * slots are allowed. Builder methods clone before mutation so a base contract
 * can be reused safely across components, tests, and serialized examples.
 */
final class TemplateContract {

	private const NO_DEFAULT="\0__DATAPHYRE_NO_DEFAULT__\0";

	/** @var array<string, mixed>|null */
	private static ?array $lastDefinitionInput=null;

	/** @var array<string, mixed>|null */
	private static ?array $lastDefinitionResult=null;

	/** @var array<string, mixed>|null */
	private static ?array $previousDefinitionInput=null;

	/** @var array<string, mixed>|null */
	private static ?array $previousDefinitionResult=null;

	/**
	 * Stores an already-normalized contract definition.
	 *
	 * @param array<string, mixed> $definition Normalized contract payload produced by `normalize()`.
	 */
	private function __construct(private array $definition){}

	/**
	 * Creates a contract from a raw definition payload.
	 *
	 * Legacy aliases are accepted during normalization: `slots` feeds required
	 * slots and `types` feeds prop types. Invalid keys, blank names, duplicate
	 * strings, non-string type declarations, and non-string default keys are
	 * discarded to keep the serialized contract predictable.
	 *
	 * @param array<string, mixed> $definition Raw template contract definition.
	 * @return self Immutable contract containing normalized keys and flags.
	 */
	public static function fromArray(array $definition): self {
		if(self::$lastDefinitionInput!==null && $definition===self::$lastDefinitionInput){
			return new self(self::$lastDefinitionResult);
		}
		if(self::$previousDefinitionInput!==null && $definition===self::$previousDefinitionInput){
			return new self(self::$previousDefinitionResult);
		}
		$normalized=self::normalize($definition);
		self::$previousDefinitionInput=self::$lastDefinitionInput;
		self::$previousDefinitionResult=self::$lastDefinitionResult;
		self::$lastDefinitionInput=$definition;
		self::$lastDefinitionResult=$normalized;
		return new self($normalized);
	}

	/**
	 * Creates a contract from required and optional data keys.
	 *
	 *
	 * @param array<int, string> $required Data keys that templates must receive.
	 * @param array<int, string> $optional Data keys templates may receive.
	 * @return self Immutable contract with normalized required and optional data keys.
	 */
	public static function define(array $required=[], array $optional=[]): self {
		return self::fromArray([
			'required'=>$required,
			'optional'=>$optional,
		]);
	}

	/**
	 * Adds required data keys to a cloned contract.
	 *
	 * Blank keys and duplicates are ignored by the shared string normalizer.
	 *
	 * @param string ...$keys Data keys that must be present.
	 * @return self New contract with merged required keys.
	 */
	public function required(string ...$keys): self {
		$clone=clone $this;
		$clone->definition['required']=self::uniqueStrings(array_merge($clone->definition['required'], $keys));
		return $clone;
	}

	/**
	 * Adds optional data keys to a cloned contract.
	 *
	 * Optional keys document accepted input without making render calls fail
	 * when they are omitted.
	 *
	 * @param string ...$keys Data keys that may be present.
	 * @return self New contract with merged optional keys.
	 */
	public function optional(string ...$keys): self {
		$clone=clone $this;
		$clone->definition['optional']=self::uniqueStrings(array_merge($clone->definition['optional'], $keys));
		return $clone;
	}

	/**
	 * Adds a required data key with optional type and default metadata.
	 *
	 * The sentinel default distinguishes "no default declared" from a declared
	 * `null` default. Type strings are normalized to lowercase before storage.
	 *
	 * @param string $key Data key to require.
	 * @param ?string $type Optional expected type label.
	 * @param mixed $default Optional default value, including `null`.
	 * @return self New contract containing the required prop metadata.
	 */
	public function requiredProp(string $key, ?string $type=null, mixed $default=self::NO_DEFAULT): self {
		$clone=$this->required($key);
		if($type!==null){
			$clone=$clone->propType($key, $type);
		}
		if($default!==self::NO_DEFAULT){
			$clone=$clone->defaultValue($key, $default);
		}
		return $clone;
	}

	/**
	 * Adds an optional data key with optional type and default metadata.
	 *
	 * The key is added to the optional set first, then type and default metadata
	 * are layered through the same helpers used by explicit prop configuration.
	 *
	 * @param string $key Data key to allow.
	 * @param ?string $type Optional expected type label.
	 * @param mixed $default Optional default value, including `null`.
	 * @return self New contract containing the optional prop metadata.
	 */
	public function optionalProp(string $key, ?string $type=null, mixed $default=self::NO_DEFAULT): self {
		$clone=$this->optional($key);
		if($type!==null){
			$clone=$clone->propType($key, $type);
		}
		if($default!==self::NO_DEFAULT){
			$clone=$clone->defaultValue($key, $default);
		}
		return $clone;
	}

	/**
	 * Adds required slot names to a cloned contract.
	 *
	 *
	 * @param string ...$slots Slot names that must be provided by the caller.
	 * @return self New contract with merged required slots.
	 */
	public function requiredSlots(string ...$slots): self {
		$clone=clone $this;
		$clone->definition['required_slots']=self::uniqueStrings(array_merge($clone->definition['required_slots'], $slots));
		return $clone;
	}

	/**
	 * Adds optional slot names to a cloned contract.
	 *
	 *
	 * @param string ...$slots Slot names that may be provided by the caller.
	 * @return self New contract with merged optional slots.
	 */
	public function optionalSlots(string ...$slots): self {
		$clone=clone $this;
		$clone->definition['optional_slots']=self::uniqueStrings(array_merge($clone->definition['optional_slots'], $slots));
		return $clone;
	}

	/**
	 * Controls whether undeclared data keys are accepted.
	 *
	 * The default normalized contract allows additional data, preserving
	 * backward-compatible rendering unless callers explicitly tighten the
	 * contract.
	 *
	 * @param bool $allow Whether data keys outside required/optional lists are allowed.
	 * @return self New contract with the data openness flag set.
	 */
	public function allowAdditionalData(bool $allow=true): self {
		$clone=clone $this;
		$clone->definition['allow_additional_data']=$allow;
		return $clone;
	}

	/**
	 * Controls whether undeclared slots are accepted.
	 *
	 * The default normalized contract allows additional slots so templates can
	 * evolve without breaking callers until a stricter contract is requested.
	 *
	 * @param bool $allow Whether slots outside required/optional lists are allowed.
	 * @return self New contract with the slot openness flag set.
	 */
	public function allowAdditionalSlots(bool $allow=true): self {
		$clone=clone $this;
		$clone->definition['allow_additional_slots']=$allow;
		return $clone;
	}

	/**
	 * Merges default values into a cloned contract.
	 *
	 * Defaults are shallow-merged by key after normalization; later defaults
	 * replace earlier values while invalid or blank keys are discarded.
	 *
	 * @param array<string, mixed> $defaults Default values keyed by data key.
	 * @return self New contract with merged defaults.
	 */
	public function defaults(array $defaults): self {
		$clone=clone $this;
		$clone->definition['defaults']=array_replace($clone->definition['defaults'], self::normalizeDefaults($defaults));
		return $clone;
	}

	/**
	 * Sets a single default value on a cloned contract.
	 *
	 * Blank keys leave the original contract unchanged. Any value, including
	 * `null`, can be stored as a declared default.
	 *
	 * @param string $key Data key that receives the default.
	 * @param mixed $value Default value to expose to render validation.
	 * @return self New contract with the default set, or the same contract when the key is blank.
	 */
	public function defaultValue(string $key, mixed $value): self {
		$key=trim($key);
		if($key===''){
			return $this;
		}
		$clone=clone $this;
		$clone->definition['defaults'][$key]=$value;
		return $clone;
	}

	/**
	 * Sets a single expected prop type on a cloned contract.
	 *
	 * Type labels are trimmed and lowercased. Blank keys or blank type labels
	 * leave the original contract unchanged.
	 *
	 * @param string $key Data key whose type is being documented.
	 * @param string $type Expected type label.
	 * @return self New contract with the prop type set, or the same contract when input is invalid.
	 */
	public function propType(string $key, string $type): self {
		$key=trim($key);
		$type=self::normalizeType($type);
		if($key==='' || $type===null){
			return $this;
		}
		$clone=clone $this;
		$clone->definition['prop_types'][$key]=$type;
		return $clone;
	}

	/**
	 * Merges multiple prop type declarations into a cloned contract.
	 *
	 * Invalid map entries are discarded by `normalizeTypeMap()`. Later entries
	 * replace earlier type declarations for the same data key.
	 *
	 * @param array<string, string> $types Expected type labels keyed by data key.
	 * @return self New contract with merged prop type declarations.
	 */
	public function propTypes(array $types): self {
		$clone=clone $this;
		$clone->definition['prop_types']=array_replace($clone->definition['prop_types'], self::normalizeTypeMap($types));
		return $clone;
	}

	/**
	 * Returns normalized template data and slot rules.
	 *
	 * @return array{required:array<int, string>, optional:array<int, string>, required_slots:array<int, string>, optional_slots:array<int, string>, defaults:array<string, mixed>, prop_types:array<string, string>, allow_additional_data:bool, allow_additional_slots:bool} Template data and slot rules consumed by render validation.
	 */
	public function toArray(): array {
		return $this->definition;
	}

	/**
	 * Normalizes a raw contract definition into the canonical payload shape.
	 *
	 * @param array<string, mixed> $definition Raw contract map.
	 * @return array<string, mixed> Canonical contract map with all expected keys present.
	 */
	private static function normalize(array $definition): array {
		return [
			'required'=>self::uniqueStrings(is_array($definition['required'] ?? null) ? $definition['required'] : []),
			'optional'=>self::uniqueStrings(is_array($definition['optional'] ?? null) ? $definition['optional'] : []),
			'required_slots'=>self::uniqueStrings(
				is_array($definition['required_slots'] ?? null)
					? $definition['required_slots']
					: (is_array($definition['slots'] ?? null) ? $definition['slots'] : [])
			),
			'optional_slots'=>self::uniqueStrings(is_array($definition['optional_slots'] ?? null) ? $definition['optional_slots'] : []),
			'defaults'=>self::normalizeDefaults(is_array($definition['defaults'] ?? null) ? $definition['defaults'] : []),
			'prop_types'=>self::normalizeTypeMap(
				is_array($definition['prop_types'] ?? null)
					? $definition['prop_types']
					: (is_array($definition['types'] ?? null) ? $definition['types'] : [])
			),
			'allow_additional_data'=>array_key_exists('allow_additional_data', $definition) ? (bool)$definition['allow_additional_data'] : true,
			'allow_additional_slots'=>array_key_exists('allow_additional_slots', $definition) ? (bool)$definition['allow_additional_slots'] : true,
		];
	}

	/**
	 * Filters strings, trims whitespace, removes blanks, and preserves first occurrence order.
	 *
	 * @param array<int, mixed> $values Candidate string values.
	 * @return array<int, string> Unique normalized strings.
	 */
	private static function uniqueStrings(array $values): array {
		$normalized=[];
		$seen=[];
		foreach($values as $value){
			if(!is_string($value)){
				continue;
			}
			$value=trim($value);
			if($value!=='' && !isset($seen[$value])){
				$normalized[]=$value;
				$seen[$value]=true;
			}
		}
		return $normalized;
	}

	/**
	 * Normalizes the default-value map by retaining only non-empty string keys.
	 *
	 * @param array<mixed, mixed> $defaults Candidate defaults keyed by prop name.
	 * @return array<string, mixed> Defaults safe to store in a contract payload.
	 */
	private static function normalizeDefaults(array $defaults): array {
		$normalized=[];
		foreach($defaults as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			$key=trim($key);
			if($key===''){
				continue;
			}
			$normalized[$key]=$value;
		}
		return $normalized;
	}

	/**
	 * Normalizes a prop type map by retaining string keys and string type labels.
	 *
	 * @param array<mixed, mixed> $types Candidate prop type declarations.
	 * @return array<string, string> Lowercase type labels keyed by normalized prop name.
	 */
	private static function normalizeTypeMap(array $types): array {
		$normalized=[];
		foreach($types as $key=>$type){
			if(!is_string($key) || !is_string($type)){
				continue;
			}
			$key=trim($key);
			$type=self::normalizeType($type);
			if($key==='' || $type===null){
				continue;
			}
			$normalized[$key]=$type;
		}
		return $normalized;
	}

	/**
	 * Normalizes a single type label.
	 *
	 * @param string $type Candidate type label.
	 * @return ?string Lowercase type label, or `null` when the label is blank.
	 */
	private static function normalizeType(string $type): ?string {
		$type=strtolower(trim($type));
		return $type!=='' ? $type : null;
	}
}
