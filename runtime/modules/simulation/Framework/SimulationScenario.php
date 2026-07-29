<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use InvalidArgumentException;
use JsonSerializable;

/** App-defined scenario, controls, and causal rules for one business domain. */
final class SimulationScenario implements JsonSerializable {
	/** @var array<string,array<string,mixed>> */
	private array $controlSchema;
	/** @var array<string,SimulationRule> */
	private array $rules=[];

	/** @param array<string,array<string,mixed>> $controlSchema @param array<int,SimulationRule> $rules */
	public function __construct(private string $id, private string $label, private string $description='', array $controlSchema=[], array $rules=[]) {
		$this->id=self::name($this->id);
		$this->label=trim($this->label);
		$this->description=trim($this->description);
		if($this->id==='' || $this->label==='') throw new InvalidArgumentException('Simulation scenarios require an id and label.');
		$this->controlSchema=$this->normalizeSchema($controlSchema);
		foreach($rules as $rule) $this->addRule($rule);
	}

	public function addRule(SimulationRule $rule): self {
		$this->rules[$rule->id()]=$rule;
		return $this;
	}

	public function id(): string { return $this->id; }
	public function label(): string { return $this->label; }
	public function description(): string { return $this->description; }

	/** @return array<int,SimulationRule> */
	public function rules(): array {
		$rules=array_values($this->rules);
		usort($rules, static fn(SimulationRule $left, SimulationRule $right): int => $right->priorityValue()<=>$left->priorityValue() ?: strcmp($left->id(), $right->id()));
		return $rules;
	}

	/** @return array<string,array<string,mixed>> */
	public function controlSchema(): array { return $this->controlSchema; }

	/** @return array<string,mixed> */
	public function defaultControls(): array {
		$defaults=[];
		foreach($this->controlSchema as $name=>$definition) $defaults[$name]=$definition['default'];
		return $defaults;
	}

	/** Unknown controls are ignored; known values are type-normalized and bounded. @return array<string,mixed> */
	public function normalizeControls(array $controls): array {
		$normalized=$this->defaultControls();
		foreach($this->controlSchema as $name=>$definition){
			if(!array_key_exists($name, $controls)) continue;
			$normalized[$name]=$this->normalizeControlValue($definition, $controls[$name], $definition['default']);
		}
		return $normalized;
	}

	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'label'=>$this->label,
			'description'=>$this->description,
			'controls'=>$this->controlSchema,
			'rules'=>array_map(static fn(SimulationRule $rule): array => $rule->jsonSerialize(), $this->rules()),
		];
	}

	/** @param array<string,array<string,mixed>> $schema @return array<string,array<string,mixed>> */
	private function normalizeSchema(array $schema): array {
		$normalized=[];
		foreach($schema as $name=>$definition){
			$name=self::name((string)$name);
			if($name==='' || !is_array($definition)) continue;
			$type=strtolower(trim((string)($definition['type'] ?? 'string')));
			if(!in_array($type, ['boolean', 'integer', 'number', 'string', 'enum'], true)) $type='string';
			$options=array_values(array_unique(array_filter(array_map('strval', is_array($definition['options'] ?? null) ? $definition['options'] : []), static fn(string $value): bool => trim($value)!=='')));
			$entry=[
				'type'=>$type,
				'label'=>trim((string)($definition['label'] ?? $name)),
				'description'=>trim((string)($definition['description'] ?? '')),
				'default'=>$definition['default'] ?? ($type==='boolean' ? false : ($type==='integer' ? 0 : ($type==='number' ? 0.0 : ($options[0] ?? '')))),
			];
			if(isset($definition['min']) && is_numeric($definition['min'])) $entry['min']=$type==='integer' ? (int)$definition['min'] : (float)$definition['min'];
			if(isset($definition['max']) && is_numeric($definition['max'])) $entry['max']=$type==='integer' ? (int)$definition['max'] : (float)$definition['max'];
			if(isset($definition['step']) && is_numeric($definition['step'])) $entry['step']=$type==='integer' ? (int)$definition['step'] : (float)$definition['step'];
			if($options!==[]) $entry['options']=$options;
			$entry['default']=$this->normalizeControlValue($entry, $entry['default'], $type==='boolean' ? false : ($type==='integer' ? 0 : ($type==='number' ? 0.0 : ($options[0] ?? ''))));
			$normalized[$name]=$entry;
		}
		return $normalized;
	}

	/** @param array<string,mixed> $definition */
	private function normalizeControlValue(array $definition, mixed $value, mixed $fallback): mixed {
		$type=(string)$definition['type'];
		if($type==='boolean'){
			if(is_bool($value)) return $value;
			return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $fallback;
		}
		if($type==='integer' || $type==='number'){
			if(!is_numeric($value)) return $fallback;
			$value=$type==='integer' ? (int)$value : (float)$value;
			if(isset($definition['min'])) $value=max($value, $definition['min']);
			if(isset($definition['max'])) $value=min($value, $definition['max']);
			return $value;
		}
		$value=substr(trim((string)$value), 0, 256);
		$options=is_array($definition['options'] ?? null) ? array_map('strval', $definition['options']) : [];
		return $options!==[] && !in_array($value, $options, true) ? $fallback : $value;
	}

	private static function name(string $value): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
		return substr(trim($value, '_.-'), 0, 96);
	}
}
