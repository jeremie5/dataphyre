<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable W3C-compatible correlation context. */
final class PanelTelemetryContext implements \JsonSerializable {
	private function __construct(private readonly string $traceId,private readonly string $spanId,private readonly int $traceFlags,private readonly string $traceState,private readonly array $baggage,private readonly bool $remote){self::identifier($traceId,32,'trace');self::identifier($spanId,16,'span');if($traceFlags<0||$traceFlags>255){throw new \InvalidArgumentException('Telemetry trace flags must fit in one byte.');}}
	/** @param array<string,string> $baggage */
	public static function root(bool $sampled=true,array $baggage=[],string $traceState='',?string $traceId=null,?string $spanId=null):self{[$traceState,$baggage]=self::propagation($traceState,$baggage);return new self($traceId??self::randomHex(16),$spanId??self::randomHex(8),$sampled?1:0,$traceState,$baggage,false);}
	/** @param array<string,string> $baggage */
	public static function remote(string $traceId,string $spanId,int $traceFlags=0,string $traceState='',array $baggage=[]):self{[$traceState,$baggage]=self::propagation($traceState,$baggage);return new self($traceId,$spanId,$traceFlags,$traceState,$baggage,true);}
	public function child(?string $spanId=null):self{return new self($this->traceId,$spanId??self::randomHex(8),$this->traceFlags,$this->traceState,$this->baggage,false);}
	public function traceId():string{return$this->traceId;} public function spanId():string{return$this->spanId;} public function traceFlags():int{return$this->traceFlags;} public function sampled():bool{return($this->traceFlags&1)===1;} public function traceState():string{return$this->traceState;} public function baggage():array{return$this->baggage;} public function remoteParent():bool{return$this->remote;}
	public function traceParent():string{return sprintf('00-%s-%s-%02x',$this->traceId,$this->spanId,$this->traceFlags);}
	public function jsonSerialize():array{return['type'=>'panel_telemetry_context','schema_version'=>1,'trace_id'=>$this->traceId,'span_id'=>$this->spanId,'sampled'=>$this->sampled(),'remote_parent'=>$this->remote,'tracestate_members'=>$this->traceState===''?0:substr_count($this->traceState,',')+1,'baggage_keys'=>array_keys($this->baggage)];}
	private static function identifier(string $value,int $length,string $label):void{if(strlen($value)!==$length||preg_match('/^[0-9a-f]+$/D',$value)!==1||trim($value,'0')===''){throw new \InvalidArgumentException("Telemetry {$label} id must be non-zero lowercase hexadecimal.");}}
	/** @param array<string,string> $baggage @return array{0:string,1:array<string,string>} */ private static function propagation(string $traceState,array $baggage):array{$propagator=new PanelTelemetryPropagator();$members=[];foreach($baggage as$key=>$value){if(is_string($key)&&is_string($value)){$members[]=rawurlencode($key).'='.rawurlencode($value);}}return[$propagator->normalizeTraceState($traceState),$propagator->normalizeBaggage(implode(',',$members))];}
	private static function randomHex(int $bytes):string{try{$value=bin2hex(random_bytes($bytes));}catch(\Throwable){$value=substr(hash('sha256',uniqid('panel-telemetry-',true).microtime(true)),0,$bytes*2);}return trim($value,'0')===''?str_repeat('0',$bytes*2-1).'1':$value;}
}
