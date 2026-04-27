<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplateContract {

	private const NO_DEFAULT="\0__DATAPHYRE_NO_DEFAULT__\0";

	private function __construct(private array $definition){}

	public static function fromArray(array $definition): self {
		return new self(self::normalize($definition));
	}

	public static function define(array $required=[], array $optional=[]): self {
		return self::fromArray([
			'required'=>$required,
			'optional'=>$optional,
		]);
	}

	public function required(string ...$keys): self {
		$clone=clone $this;
		$clone->definition['required']=self::uniqueStrings(array_merge($clone->definition['required'], $keys));
		return $clone;
	}

	public function optional(string ...$keys): self {
		$clone=clone $this;
		$clone->definition['optional']=self::uniqueStrings(array_merge($clone->definition['optional'], $keys));
		return $clone;
	}

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

	public function requiredSlots(string ...$slots): self {
		$clone=clone $this;
		$clone->definition['required_slots']=self::uniqueStrings(array_merge($clone->definition['required_slots'], $slots));
		return $clone;
	}

	public function optionalSlots(string ...$slots): self {
		$clone=clone $this;
		$clone->definition['optional_slots']=self::uniqueStrings(array_merge($clone->definition['optional_slots'], $slots));
		return $clone;
	}

	public function allowAdditionalData(bool $allow=true): self {
		$clone=clone $this;
		$clone->definition['allow_additional_data']=$allow;
		return $clone;
	}

	public function allowAdditionalSlots(bool $allow=true): self {
		$clone=clone $this;
		$clone->definition['allow_additional_slots']=$allow;
		return $clone;
	}

	public function defaults(array $defaults): self {
		$clone=clone $this;
		$clone->definition['defaults']=array_replace($clone->definition['defaults'], self::normalizeDefaults($defaults));
		return $clone;
	}

	public function defaultValue(string $key, mixed $value): self {
		$key=trim($key);
		if($key===''){
			return $this;
		}
		$clone=clone $this;
		$clone->definition['defaults'][$key]=$value;
		return $clone;
	}

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

	public function propTypes(array $types): self {
		$clone=clone $this;
		$clone->definition['prop_types']=array_replace($clone->definition['prop_types'], self::normalizeTypeMap($types));
		return $clone;
	}

	public function toArray(): array {
		return $this->definition;
	}

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

	private static function uniqueStrings(array $values): array {
		$normalized=[];
		foreach($values as $value){
			if(!is_string($value)){
				continue;
			}
			$value=trim($value);
			if($value!=='' && !in_array($value, $normalized, true)){
				$normalized[]=$value;
			}
		}
		return $normalized;
	}

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

	private static function normalizeType(string $type): ?string {
		$type=strtolower(trim($type));
		return $type!=='' ? $type : null;
	}
}
