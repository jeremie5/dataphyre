<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Versioned collection of behavior contracts for one adapter interface. */
final class PanelAdapterConformanceSuite implements \JsonSerializable {
	/** @var list<PanelAdapterConformanceCase> */ private array $cases=[];

	/** @param class-string $contract @param array<string,mixed> $meta */
	private function __construct(private readonly string $name, private readonly string $contract, private readonly int $version, private readonly array $meta=[]){ }

	/** @param class-string $contract @param array<string,mixed> $meta */
	public static function make(string $name, string $contract, int $version=1, array $meta=[]): self {
		$name=Resource::normalizeName($name);
		if($name==='' || (!interface_exists($contract) && !class_exists($contract))){ throw new \InvalidArgumentException('Adapter conformance suites require a name and loadable contract.'); }
		return new self($name, $contract, max(1,$version), $meta);
	}

	public function add(PanelAdapterConformanceCase $case): self {
		foreach($this->cases as $existing){ if($existing->id()===$case->id()){ throw new \LogicException('Duplicate adapter conformance case: '.$case->id().'.'); } }
		if(count($this->cases)>=200){ throw new \LengthException('Adapter conformance suites support at most 200 cases.'); }
		$clone=clone $this; $clone->cases[]=$case; return $clone;
	}

	public function name(): string { return $this->name; }
	/** @return class-string */ public function contract(): string { return $this->contract; }
	public function version(): int { return $this->version; }
	/** @return list<PanelAdapterConformanceCase> */ public function cases(): array { return $this->cases; }
	/** @return array<string,mixed> */ public function meta(): array { return $this->meta; }
	/** @return array<string,mixed> */ public function jsonSerialize(): array { return ['type'=>'panel_adapter_conformance_suite','schema_version'=>1,'name'=>$this->name,'contract'=>$this->contract,'version'=>$this->version,'cases'=>array_map(static fn(PanelAdapterConformanceCase $case):array=>$case->jsonSerialize(),$this->cases),'meta'=>PanelSensitiveDataSanitizer::sanitize($this->meta)]; }
}
