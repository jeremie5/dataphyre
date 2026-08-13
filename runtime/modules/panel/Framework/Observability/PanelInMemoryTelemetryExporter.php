<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded reference exporter for tests, local diagnostics, and adapter development. */
final class PanelInMemoryTelemetryExporter implements PanelTelemetryExporter,\JsonSerializable {
	/** @var list<PanelTelemetrySignal> */ private array $signals=[]; private int $exported=0; private int $evicted=0; private int $flushes=0; private int $capacity;
	public function __construct(int $capacity=500){$this->capacity=max(1,min(10000,$capacity));}
	public function export(PanelTelemetrySignal $signal):void{$this->signals[]=$signal;$this->exported++;if(count($this->signals)>$this->capacity){$remove=count($this->signals)-$this->capacity;$this->signals=array_slice($this->signals,$remove);$this->evicted+=$remove;}}
	public function flush():void{$this->flushes++;}
	/** @return list<PanelTelemetrySignal> */ public function signals(?string $signal=null,?string $name=null):array{return array_values(array_filter($this->signals,static fn(PanelTelemetrySignal $item):bool=>($signal===null||$item->signal()===$signal)&&($name===null||$item->name()===Resource::normalizeName($name))));}
	/** @return list<array<string,mixed>> */ public function records(?string $signal=null,?string $name=null):array{return array_map(static fn(PanelTelemetrySignal $item):array=>$item->jsonSerialize(),$this->signals($signal,$name));}
	public function clear():void{$this->signals=[];}
	public function manifest():array{$kinds=[];foreach($this->signals as$signal){$kinds[$signal->signal()]=($kinds[$signal->signal()]??0)+1;}ksort($kinds);return['type'=>'panel_in_memory_telemetry_exporter','schema_version'=>1,'capacity'=>$this->capacity,'retained'=>count($this->signals),'exported'=>$this->exported,'evicted'=>$this->evicted,'flushes'=>$this->flushes,'signals'=>$kinds];}
	public function jsonSerialize():array{return$this->manifest();}
}
