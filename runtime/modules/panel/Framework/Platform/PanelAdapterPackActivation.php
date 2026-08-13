<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, secret-free evidence for one successful adapter-pack activation. */
final class PanelAdapterPackActivation implements \JsonSerializable {
	/**
	 * @param array<string,object> $adapters
	 * @param array<string,PanelAdapterConformanceReport> $conformance
	 * @param array<string,string> $targets
	 */
	public function __construct(
		private readonly string $pack,
		private readonly string $version,
		private readonly string $panel,
		private readonly string $planFingerprint,
		private readonly array $adapters,
		private readonly array $conformance,
		private readonly array $targets,
		private readonly int $activatedAt
	) {}

	public function pack(): string {return $this->pack;}
	public function version(): string {return $this->version;}
	public function panel(): string {return $this->panel;}
	public function fingerprint(): string {return $this->planFingerprint;}
	public function activatedAt(): int {return $this->activatedAt;}

	public function has(string $binding): bool {
		return isset($this->adapters[Resource::normalizeName($binding)]);
	}

	public function adapter(string $binding, ?string $expected=null): object {
		$binding=Resource::normalizeName($binding);
		$value=$this->adapters[$binding] ?? throw new \OutOfBoundsException("Adapter binding '{$binding}' is not active.");
		if($expected!==null && !is_a($value, $expected)){
			throw new \UnexpectedValueException("Adapter binding '{$binding}' does not implement {$expected}.");
		}
		return $value;
	}

	/** @return array<string,object> */
	public function adapters(): array {return $this->adapters;}
	/** @return array<string,PanelAdapterConformanceReport> */
	public function conformance(): array {return $this->conformance;}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		$bindings=[];
		foreach($this->adapters as $name=>$adapter){
			$bindings[$name]=[
				'class'=>$adapter::class,
				'target'=>$this->targets[$name] ?? null,
				'conformance'=>isset($this->conformance[$name])
					? $this->conformance[$name]->summary()
					: null,
			];
		}
		return [
			'type'=>'panel_adapter_pack_activation',
			'schema_version'=>1,
			'ok'=>true,
			'pack'=>$this->pack,
			'version'=>$this->version,
			'panel'=>$this->panel,
			'plan_fingerprint'=>$this->planFingerprint,
			'activated_at'=>gmdate('Y-m-d\TH:i:s\Z', $this->activatedAt),
			'binding_count'=>count($bindings),
			'bindings'=>$bindings,
			'adapter_objects_serialized'=>false,
			'configuration_serialized'=>false,
		];
	}
}
