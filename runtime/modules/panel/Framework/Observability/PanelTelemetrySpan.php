<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit lifecycle handle for a trace or child span. */
final class PanelTelemetrySpan implements \JsonSerializable {
	private bool $ended=false;
	/** @param array<string,mixed> $attributes */ public function __construct(private readonly PanelTelemetryHub $hub,private readonly string $signal,private readonly string $name,private readonly PanelTelemetryContext $context,private readonly float $startedAt,private readonly array $attributes=[],private readonly ?string $parentSpanId=null,private readonly string $kind='internal'){if(!in_array($signal,[PanelTelemetrySignal::TRACE,PanelTelemetrySignal::SPAN],true)||!is_finite($startedAt)||$startedAt<0){throw new \InvalidArgumentException('Telemetry spans require a supported signal and finite start time.');}}
	public function context():PanelTelemetryContext{return$this->context;} public function name():string{return$this->name;} public function signal():string{return$this->signal;} public function startedAt():float{return$this->startedAt;} public function parentSpanId():?string{return$this->parentSpanId;} public function kind():string{return$this->kind;} public function attributes():array{return$this->attributes;} public function ended():bool{return$this->ended;}
	/** @param array<string,mixed> $attributes */ public function end(string $status='ok',array $attributes=[]):bool{if($this->ended){return false;}$this->ended=true;$this->hub->finishSpan($this,$status,array_replace($this->attributes,$attributes),null);return true;}
	/** @param array<string,mixed> $attributes */ public function fail(\Throwable|string $error,array $attributes=[]):bool{if($this->ended){return false;}$this->ended=true;$this->hub->finishSpan($this,'error',array_replace($this->attributes,$attributes),$error);return true;}
	/** @param array<string,mixed> $attributes */ public function event(string $name,array $attributes=[],string $severity='info'):void{$this->hub->event($name,$attributes,$this->context,$severity);}
	/** @param array<string,mixed> $attributes */ public function measurement(string $name,int|float $value,string $unit='1',array $attributes=[]):void{$this->hub->measurement($name,$value,$unit,$attributes,$this->context);}
	public function activate(callable $callback):mixed{return$this->hub->activate($this->context,$callback);}
	public function jsonSerialize():array{return['type'=>'panel_telemetry_span','schema_version'=>1,'signal'=>$this->signal,'name'=>Resource::normalizeName($this->name),'context'=>$this->context,'started'=>$this->startedAt,'ended'=>$this->ended,'kind'=>$this->kind,'parent_span_id'=>$this->parentSpanId,'attribute_count'=>count($this->attributes)];}
}
