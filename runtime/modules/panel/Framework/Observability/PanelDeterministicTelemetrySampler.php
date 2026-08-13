<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable cross-platform head sampler: the same seed and trace id always agree. */
final class PanelDeterministicTelemetrySampler implements \JsonSerializable {
	private float $ratio; private string $seed;
	public function __construct(float $ratio=1.0,string $seed='dataphyre-panel'){$this->ratio=max(0.0,min(1.0,$ratio));$this->seed=trim($seed)!==''?substr($seed,0,128):'dataphyre-panel';}
	/** @param array<string,mixed> $attributes */ public function sample(string $traceId,string $name='',array $attributes=[]):bool{if($this->ratio<=0){return false;}if($this->ratio>=1){return true;}$bucket=(int)hexdec(substr(hash('sha256',$this->seed.'|'.$traceId),0,7));return$bucket<(int)floor($this->ratio*268435456);}
	public function ratio():float{return$this->ratio;} public function jsonSerialize():array{return['type'=>'panel_deterministic_telemetry_sampler','schema_version'=>1,'algorithm'=>'sha256-threshold-v1','ratio'=>$this->ratio,'seed_fingerprint'=>substr(hash('sha256',$this->seed),0,16)];}
}
