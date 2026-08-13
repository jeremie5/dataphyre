<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Secret-safe machine-readable report for a complete adapter contract run. */
final class PanelAdapterConformanceReport implements \JsonSerializable {
	/** @param list<PanelAdapterConformanceResult> $results @param array<string,mixed> $capabilities @param array<string,mixed> $meta */
	public function __construct(private readonly PanelAdapterConformanceSuite $suite, private readonly string $adapter, private readonly array $results, private readonly array $capabilities=[], private readonly array $meta=[]){ }
	/** @return list<PanelAdapterConformanceResult> */ public function results(): array { return $this->results; }
	public function passed(bool $allowSkipped=false): bool { $summary=$this->summary(); return $summary['failed']===0 && ($allowSkipped || $summary['skipped']===0); }
	/** @return array{total:int,passed:int,failed:int,skipped:int,assertions:int,duration_ms:float} */
	public function summary(): array {
		$summary=['total'=>count($this->results),'passed'=>0,'failed'=>0,'skipped'=>0,'assertions'=>0,'duration_ms'=>0.0];
		foreach($this->results as $result){ $summary[$result->status()]++; $summary['assertions']+=$result->assertions(); $summary['duration_ms']+=$result->durationMs(); }
		$summary['duration_ms']=round($summary['duration_ms'],3); return $summary;
	}
	/** @return array<string,mixed> */ public function jsonSerialize(): array { return ['type'=>'panel_adapter_conformance_report','schema_version'=>1,'suite'=>$this->suite->jsonSerialize(),'adapter'=>$this->adapter,'summary'=>$this->summary(),'capabilities'=>PanelSensitiveDataSanitizer::sanitize($this->capabilities),'results'=>array_map(static fn(PanelAdapterConformanceResult $result):array=>$result->jsonSerialize(),$this->results),'meta'=>PanelSensitiveDataSanitizer::sanitize($this->meta)]; }
}
