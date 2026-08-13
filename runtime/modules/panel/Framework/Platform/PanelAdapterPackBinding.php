<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * One typed, dependency-aware contribution inside an adapter pack.
 *
 * Factory callbacks and conformance objects remain process-local. Public
 * manifests expose only the contract, target, requirements, and configuration
 * key grammar needed to preview an installation.
 */
final class PanelAdapterPackBinding implements \JsonSerializable {
	private readonly string $id;
	private readonly string $target;
	private readonly string $contract;
	private readonly \Closure $factory;
	/** @var list<string> */
	private readonly array $dependencies;
	/** @var list<class-string> */
	private readonly array $requiredClasses;
	/** @var list<string> */
	private readonly array $configKeys;
	/** @var list<string> */
	private readonly array $requiredConfigKeys;
	/** @var list<string> */
	private readonly array $capabilities;
	private readonly bool $optional;
	private readonly bool $replace;

	/**
	 * @param class-string $contract
	 * @param callable(PanelAdapterPackContext,array<string,mixed>):object $factory
	 * @param array{
	 *     dependencies?:list<string>,
	 *     required_classes?:list<class-string>,
	 *     config_keys?:list<string>,
	 *     required_config_keys?:list<string>,
	 *     capabilities?:list<string>,
	 *     optional?:bool,
	 *     replace?:bool
	 * } $options
	 */
	public function __construct(
		string $id,
		string $target,
		string $contract,
		callable $factory,
		private readonly ?PanelAdapterConformanceSuite $conformance=null,
		array $options=[]
	) {
		$this->id=self::name($id, 'adapter binding');
		$this->target=self::normalizeTarget($target);
		$this->contract=trim($contract);
		if($this->contract==='' || (!interface_exists($this->contract) && !class_exists($this->contract))){
			throw new \InvalidArgumentException('Adapter pack binding contracts must be loadable classes or interfaces.');
		}
		$targetType=$this->targetType();
		$targetContract=match($targetType){
			'search'=>PanelSearchProvider::class,
			'plugin'=>PanelPlugin::class,
			'data'=>PanelDataSource::class,
			default=>null,
		};
		if($targetContract!==null && !is_a($this->contract, $targetContract, true)){
			throw new \InvalidArgumentException("Adapter pack {$targetType}: targets require contracts compatible with {$targetContract}.");
		}
		if($conformance!==null && !is_a($this->contract, $conformance->contract(), true)){
			throw new \InvalidArgumentException('Adapter pack conformance contracts must accept the binding contract.');
		}
		$this->factory=\Closure::fromCallable($factory);
		$this->dependencies=self::names($options['dependencies'] ?? []);
		if(in_array($this->id, $this->dependencies, true)){
			throw new \InvalidArgumentException('Adapter pack bindings cannot depend on themselves.');
		}
		$this->requiredClasses=self::classes($options['required_classes'] ?? []);
		$this->configKeys=self::names($options['config_keys'] ?? []);
		$this->requiredConfigKeys=self::names($options['required_config_keys'] ?? []);
		if(array_diff($this->requiredConfigKeys, $this->configKeys)!==[]){
			throw new \InvalidArgumentException('Required adapter configuration keys must be declared configuration keys.');
		}
		$this->capabilities=self::names($options['capabilities'] ?? []);
		$this->optional=($options['optional'] ?? false)===true;
		$this->replace=($options['replace'] ?? false)===true;
	}

	/**
	 * @param class-string $contract
	 * @param callable(PanelAdapterPackContext,array<string,mixed>):object $factory
	 * @param array<string,mixed> $options
	 */
	public static function make(
		string $id,
		string $target,
		string $contract,
		callable $factory,
		?PanelAdapterConformanceSuite $conformance=null,
		array $options=[]
	): self {
		return new self($id, $target, $contract, $factory, $conformance, $options);
	}

	public function id(): string {return $this->id;}
	public function target(): string {return $this->target;}
	public function targetType(): string {return strstr($this->target, ':', true) ?: '';}
	public function targetName(): string {return substr($this->target, strlen($this->targetType())+1);}
	/** @return class-string */
	public function contract(): string {return $this->contract;}
	/** @return list<string> */
	public function dependencies(): array {return $this->dependencies;}
	/** @return list<class-string> */
	public function requiredClasses(): array {return $this->requiredClasses;}
	/** @return list<string> */
	public function configKeys(): array {return $this->configKeys;}
	/** @return list<string> */
	public function requiredConfigKeys(): array {return $this->requiredConfigKeys;}
	/** @return list<string> */
	public function capabilities(): array {return $this->capabilities;}
	public function optional(): bool {return $this->optional;}
	public function replaceDefault(): bool {return $this->replace;}
	public function conformance(): ?PanelAdapterConformanceSuite {return $this->conformance;}

	/**
	 * Process-local identity used only to invalidate stale previews after a pack
	 * definition changes. It is intentionally absent from public manifests.
	 */
	public function runtimeFingerprint(): string {
		return hash('sha256', json_encode([
			$this->jsonSerialize(),
			'factory_object'=>spl_object_id($this->factory),
		], JSON_THROW_ON_ERROR));
	}

	/** @param array<string,mixed> $config */
	public function create(PanelAdapterPackContext $context, array $config): object {
		$value=($this->factory)($context, $config);
		if(!is_object($value) || !$value instanceof ($this->contract)){
			throw new \UnexpectedValueException(
				"Adapter pack binding '{$this->id}' must return {$this->contract}; received ".get_debug_type($value).'.'
			);
		}
		return $value;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_adapter_pack_binding',
			'schema_version'=>1,
			'id'=>$this->id,
			'target'=>$this->target,
			'contract'=>$this->contract,
			'dependencies'=>$this->dependencies,
			'required_classes'=>$this->requiredClasses,
			'config_keys'=>$this->configKeys,
			'required_config_keys'=>$this->requiredConfigKeys,
			'capabilities'=>$this->capabilities,
			'optional'=>$this->optional,
			'replace_default'=>$this->replace,
			'factory_supplied'=>true,
			'factory_serialized'=>false,
			'conformance'=>$this->conformance?->jsonSerialize(),
		];
	}

	private static function name(string $value, string $label): string {
		$normalized=Resource::normalizeName($value);
		if($normalized==='' || $normalized!==trim($value) || strlen($normalized)>128){
			throw new \InvalidArgumentException(ucfirst($label).' must be a canonical name.');
		}
		return $normalized;
	}

	/** @param mixed $values @return list<string> */
	private static function names(mixed $values): array {
		if(!is_array($values) || !array_is_list($values)){
			throw new \InvalidArgumentException('Adapter pack binding name collections must be lists.');
		}
		$normalized=[];
		foreach($values as $value){
			if(!is_string($value)){throw new \InvalidArgumentException('Adapter pack binding names must be strings.');}
			$normalized[]=self::name($value, 'adapter binding value');
		}
		$normalized=array_values(array_unique($normalized));
		sort($normalized, SORT_STRING);
		return $normalized;
	}

	/** @param mixed $values @return list<class-string> */
	private static function classes(mixed $values): array {
		if(!is_array($values) || !array_is_list($values)){
			throw new \InvalidArgumentException('Adapter pack class requirements must be lists.');
		}
		$classes=[];
		foreach($values as $value){
			if(!is_string($value) || trim($value)==='' || strlen($value)>512 || str_contains($value, "\0")){
				throw new \InvalidArgumentException('Adapter pack class requirements are invalid.');
			}
			$classes[]=ltrim(trim($value), '\\');
		}
		$classes=array_values(array_unique($classes));
		sort($classes, SORT_STRING);
		return $classes;
	}

	private static function normalizeTarget(string $target): string {
		$target=strtolower(trim($target));
		if(preg_match('/^(platform|search|plugin|data):[a-z][a-z0-9_.-]{0,189}$/D', $target)!==1){
			throw new \InvalidArgumentException('Adapter pack targets must use platform:, search:, plugin:, or data: followed by a canonical name.');
		}
		return $target;
	}
}
