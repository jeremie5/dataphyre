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
 * Process-local adapter factory context.
 *
 * Raw configuration, callbacks, services, and resolved adapter objects are never
 * serialized. Factories may only resolve dependencies that have already passed
 * contract validation in the pack's deterministic dependency order.
 */
final class PanelAdapterPackContext implements \JsonSerializable {
	/** @var array<string,object> */
	private array $resolved=[];

	/** @param array<string,array<string,mixed>> $config */
	public function __construct(
		private readonly PanelInstance $panel,
		private readonly PanelAdapterPack $pack,
		private readonly array $config
	) {}

	public function panel(): PanelInstance {return $this->panel;}
	public function platform(): PanelPlatform {return $this->panel->platform();}
	public function pack(): PanelAdapterPack {return $this->pack;}

	/** @return array<string,mixed> */
	public function config(string $binding): array {
		$binding=Resource::normalizeName($binding);
		return $this->config[$binding] ?? [];
	}

	public function option(string $binding, string $key, mixed $default=null): mixed {
		$config=$this->config($binding);
		return array_key_exists($key, $config) ? $config[$key] : $default;
	}

	public function requireOption(string $binding, string $key): mixed {
		$config=$this->config($binding);
		if(!array_key_exists($key, $config)){
			throw new \LogicException("Adapter binding '{$binding}' requires configuration key '{$key}'.");
		}
		return $config[$key];
	}

	public function has(string $binding): bool {
		return isset($this->resolved[Resource::normalizeName($binding)]);
	}

	public function adapter(string $binding, ?string $expected=null): object {
		$binding=Resource::normalizeName($binding);
		$value=$this->resolved[$binding] ?? throw new \OutOfBoundsException("Adapter binding '{$binding}' has not been resolved.");
		if($expected!==null && !is_a($value, $expected)){
			throw new \UnexpectedValueException("Adapter binding '{$binding}' does not implement {$expected}.");
		}
		return $value;
	}

	/** Internal activation hook. */
	public function resolved(string $binding, object $adapter): self {
		$binding=Resource::normalizeName($binding);
		if($binding==='' || isset($this->resolved[$binding])){
			throw new \LogicException('Adapter pack binding resolution is invalid or duplicated.');
		}
		$this->resolved[$binding]=$adapter;
		return $this;
	}

	/** @return array<string,object> */
	public function adapters(): array {return $this->resolved;}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$adapters=[];
		foreach($this->resolved as $name=>$adapter){$adapters[$name]=$adapter::class;}
		return [
			'type'=>'panel_adapter_pack_context',
			'pack'=>$this->pack->id(),
			'panel'=>$this->panel->name(),
			'config_serialized'=>false,
			'adapters'=>$adapters,
		];
	}
}
