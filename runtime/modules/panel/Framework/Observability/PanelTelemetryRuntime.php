<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Validated instance-owned telemetry service bundle used by PanelPlatform and hosts. */
final class PanelTelemetryRuntime implements \JsonSerializable {
	private function __construct(private readonly PanelTelemetryExporter $exporter,private readonly PanelTelemetryHub $hub,private readonly PanelTelemetryBridge $bridge){}
	/** @param array<string,mixed> $config */ public static function fromConfig(array $config=[]):self{if(array_key_exists('exporter',$config)&&array_key_exists('exporters',$config)){throw new \InvalidArgumentException('Configure telemetry exporter or exporters, not both.');}$candidate=$config['exporter']??null;if(array_key_exists('exporters',$config)){$items=$config['exporters'];if(!is_array($items)||$items===[]){throw new \InvalidArgumentException('Telemetry exporters must be a non-empty list.');}$candidate=count($items)===1?reset($items):new PanelCompositeTelemetryExporter(array_values($items));}if($candidate===null){$candidate=new PanelInMemoryTelemetryExporter((int)($config['memory_capacity']??500));}if(!$candidate instanceof PanelTelemetryExporter){throw new \InvalidArgumentException('Telemetry exporter must implement PanelTelemetryExporter.');}$ratio=$config['sample_ratio']??1.0;if(!is_int($ratio)&&!is_float($ratio)&&!is_numeric($ratio)){throw new \InvalidArgumentException('Telemetry sample_ratio must be numeric.');}$ratio=(float)$ratio;if(!is_finite($ratio)){throw new \InvalidArgumentException('Telemetry sample_ratio must be finite.');}$sampler=$config['sampler']??new PanelDeterministicTelemetrySampler($ratio,is_scalar($config['sampling_seed']??null)?(string)$config['sampling_seed']:'dataphyre-panel');if(!$sampler instanceof PanelDeterministicTelemetrySampler){throw new \InvalidArgumentException('Telemetry sampler must be PanelDeterministicTelemetrySampler.');}$propagator=$config['propagator']??new PanelTelemetryPropagator();if(!$propagator instanceof PanelTelemetryPropagator){throw new \InvalidArgumentException('Telemetry propagator must be PanelTelemetryPropagator.');}$clock=$config['clock']??null;$ids=$config['id_factory']??null;if($clock!==null&&!is_callable($clock)){throw new \InvalidArgumentException('Telemetry clock must be callable.');}if($ids!==null&&!is_callable($ids)){throw new \InvalidArgumentException('Telemetry id_factory must be callable.');}$hub=new PanelTelemetryHub($candidate,$sampler,$propagator,$clock,$ids);return new self($candidate,$hub,new PanelTelemetryBridge($hub));}
	public function exporter():PanelTelemetryExporter{return$this->exporter;} public function hub():PanelTelemetryHub{return$this->hub;} public function bridge():PanelTelemetryBridge{return$this->bridge;}
	public function jsonSerialize():array{$hub=$this->hub->manifest();return['type'=>'panel_telemetry_runtime','schema_version'=>1,'exporter'=>$hub['exporter']??['type'=>$this->exporter::class],'hub'=>$hub,'bridge'=>['type'=>'panel_telemetry_bridge','schema_version'=>1]];}
}
