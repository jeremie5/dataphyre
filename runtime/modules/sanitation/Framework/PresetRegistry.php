<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class PresetRegistry {

	/** @var array<string, array|callable> */
	private array $definitions;

	public function __construct() {
		$this->definitions=$this->builtInDefinitions();
	}

	public function names(): array {
		$names=array_keys($this->definitions);
		sort($names);
		return $names;
	}

	public function has(string $name): bool {
		return array_key_exists($this->normalizeName($name), $this->definitions);
	}

	public function register(string $name, array|callable $definition): void {
		$this->definitions[$this->normalizeName($name)]=$definition;
	}

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

	private function normalizeName(string $name): string {
		return strtolower(trim($name));
	}

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
