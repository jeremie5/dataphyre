<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fan-out exporter that attempts every sink and surfaces only a generic aggregate failure. */
final class PanelCompositeTelemetryExporter implements PanelTelemetryExporter,\JsonSerializable {
	/** @var list<PanelTelemetryExporter> */ private array $exporters; private int $failures=0;
	/** @param list<PanelTelemetryExporter> $exporters */ public function __construct(array $exporters){if($exporters===[]||count($exporters)>16){throw new \InvalidArgumentException('Composite telemetry exporters require 1-16 sinks.');}foreach($exporters as$exporter){if(!$exporter instanceof PanelTelemetryExporter){throw new \InvalidArgumentException('Composite telemetry sinks must implement PanelTelemetryExporter.');}}$this->exporters=array_values($exporters);}
	public function export(PanelTelemetrySignal $signal):void{$failed=false;foreach($this->exporters as$exporter){try{$exporter->export($signal);}catch(\Throwable){$this->failures++;$failed=true;}}if($failed){throw new \RuntimeException('One or more telemetry exporters failed.');}}
	public function flush():void{$failed=false;foreach($this->exporters as$exporter){try{$exporter->flush();}catch(\Throwable){$this->failures++;$failed=true;}}if($failed){throw new \RuntimeException('One or more telemetry exporters failed to flush.');}}
	public function manifest():array{$items=[];foreach($this->exporters as$exporter){try{$candidate=PanelSensitiveDataSanitizer::sanitize($exporter->manifest(),['max_depth'=>6,'max_items'=>64,'max_string_bytes'=>256]);$items[]=is_array($candidate)?$candidate:['type'=>$exporter::class];}catch(\Throwable){$items[]=['type'=>$exporter::class,'manifest'=>'failed'];}}return['type'=>'panel_composite_telemetry_exporter','schema_version'=>1,'exporters'=>$items,'failures'=>$this->failures];}
	public function jsonSerialize():array{return$this->manifest();}
}
