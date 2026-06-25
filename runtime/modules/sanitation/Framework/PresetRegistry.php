<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Stores reusable sanitation schemas and option presets.
 *
 * Presets bundle field sanitation rules, default values, and resolver options for
 * common request data such as addresses, login forms, registration forms, and
 * search filters. Runtime callers can register project presets or resolve built
 * in presets with targeted overrides.
 */
final class PresetRegistry {

	/** @var array<string, array|callable> */
	private array $definitions;

	/**
	 * Creates a registry seeded with built-in sanitation presets.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->definitions=$this->builtInDefinitions();
	}

	/**
	 * Returns registered preset names.
	 *
	 * @return array<int, string> Sorted preset names.
	 */
	public function names(): array {
		$names=array_keys($this->definitions);
		sort($names);
		return $names;
	}

	/**
	 * Reports whether a preset is registered.
	 *
	 *
	 * @param string $name Preset name to check.
	 * @return bool True when the normalized preset name exists.
	 */
	public function has(string $name): bool {
		return array_key_exists($this->normalizeName($name), $this->definitions);
	}

	/**
	 * Registers or replaces a sanitation preset.
	 *
	 * Definitions may be arrays or callables. Callable definitions are resolved
	 * with the override array when resolve() is called, which lets presets build
	 * schema dynamically from project options. The registry stores definitions
	 * verbatim and validates their final shape only during resolution.
	 *
	 * @param string $name Preset name.
	 * @param array|callable $definition Preset definition or resolver.
	 * @return void
	 */
	public function register(string $name, array|callable $definition): void {
		$this->definitions[$this->normalizeName($name)]=$definition;
	}

	/**
	 * Resolves a preset into schema, defaults, and options.
	 *
	 * Overrides recursively merge into `schema`, shallow-replace `defaults`, and
	 * recursively merge `options`. Resolved definitions must contain a `schema`
	 * array; otherwise the preset is rejected before schema validation receives a
	 * malformed field map.
	 *
	 * @param string $name Preset name.
	 * @param array<string, mixed> $overrides Optional schema, defaults, and options overrides.
	 * @return array{name: string, schema: array<string, mixed>, defaults: array<string, mixed>, options: array<string, mixed>}
	 *
	 * @throws \InvalidArgumentException When the preset is unknown or resolves to an invalid definition.
	 */
	public function resolve(string $name, array $overrides=[]): array {
		$name=$this->normalizeName($name);
		if(!isset($this->definitions[$name])){
			throw new \InvalidArgumentException("Unknown sanitation preset '{$name}'.");
		}
		$definition=$this->definitions[$name];
		if(is_callable($definition) && !is_array($definition)){
			$definition=$definition($overrides);
		}
		elseif(is_array($definition) && !isset($definition['schema']) && isset($definition[0], $definition[1]) && is_callable($definition)){
			$definition=$definition($overrides);
		}
		if(!is_array($definition) || !isset($definition['schema']) || !is_array($definition['schema'])){
			throw new \InvalidArgumentException("Sanitation preset '{$name}' must resolve to an array with a schema key.");
		}

		$schema=$definition['schema'];
		$defaults=isset($definition['defaults']) && is_array($definition['defaults']) ? $definition['defaults'] : [];
		$options=isset($definition['options']) && is_array($definition['options']) ? $definition['options'] : [];

		if(isset($overrides['schema']) && is_array($overrides['schema'])){
			$schema=array_replace_recursive($schema, $overrides['schema']);
		}
		if(isset($overrides['defaults']) && is_array($overrides['defaults'])){
			$defaults=array_replace($defaults, $overrides['defaults']);
		}
		if(isset($overrides['options']) && is_array($overrides['options'])){
			$options=array_replace_recursive($options, $overrides['options']);
		}

		return [
			'name'=>$name,
			'schema'=>$schema,
			'defaults'=>$defaults,
			'options'=>$options,
		];
	}

	/**
	 * Normalizes preset names for registry keys.
	 *
	 * @param string $name Raw preset name.
	 * @return string Lowercase trimmed preset name.
	 */
	private function normalizeName(string $name): string {
		return strtolower(trim($name));
	}

	/**
	 * Returns built-in sanitation preset definitions.
	 *
	 * @return array<string, array<string, mixed>> Built-in preset map.
	 */
	private function builtInDefinitions(): array {
		return [
			'address'=>[
				'schema'=>[
					'full_name'=>'nullable|name|max:160',
					'company'=>'nullable|max:160',
					'address_line_1'=>'required|max:160',
					'address_line_2'=>'nullable|max:160',
					'city'=>'required|max:120',
					'state'=>'nullable|max:120',
					'postal_code'=>'required|postal',
					'country_code'=>'required|ascii|upper|min:2|max:2',
					'phone'=>'nullable|phone',
				],
				'options'=>[
					'labels'=>[
						'full_name'=>'full name',
						'address_line_1'=>'address line 1',
						'address_line_2'=>'address line 2',
						'postal_code'=>'postal code',
						'country_code'=>'country code',
					],
				],
			],
			'login'=>[
				'schema'=>[
					'email'=>'required|email|lower',
					'password'=>[
						'type'=>'default',
						'required'=>true,
						'trim'=>false,
						'escape_html'=>false,
						'min'=>8,
						'max'=>4096,
						'label'=>'password',
					],
					'remember'=>'nullable|boolean',
				],
			],
			'registration'=>[
				'schema'=>[
					'first_name'=>'required|name|max:120',
					'last_name'=>'required|name|max:120',
					'email'=>'required|email|lower',
					'username'=>'nullable|username|lower|max:32',
					'password'=>[
						'type'=>'default',
						'required'=>true,
						'trim'=>false,
						'escape_html'=>false,
						'min'=>8,
						'max'=>4096,
						'label'=>'password',
					],
					'password_confirmation'=>[
						'type'=>'default',
						'required'=>true,
						'trim'=>false,
						'escape_html'=>false,
						'same'=>'password',
						'min'=>8,
						'max'=>4096,
						'label'=>'password confirmation',
					],
					'terms'=>'accepted|boolean',
				],
				'options'=>[
					'messages'=>[
						'password_confirmation'=>[
							'same'=>'Your password confirmation must match your password.',
						],
					],
				],
			],
			'search_filters'=>[
				'schema'=>[
					'q'=>'nullable|trim|squish|max:120',
					'page'=>'nullable|integer|min_value:1',
					'per_page'=>'nullable|integer|min_value:1|max_value:250',
					'sort'=>'nullable|slug|max:64',
					'direction'=>'nullable|lower|in:asc,desc',
				],
			],
		];
	}
}
