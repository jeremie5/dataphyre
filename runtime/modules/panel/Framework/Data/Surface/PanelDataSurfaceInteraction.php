<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded untrusted interaction carried beside an already signed surface intent. */
final class PanelDataSurfaceInteraction implements \JsonSerializable {
	/** @param list<mixed> $values */private function __construct(private readonly string $type,private readonly array $values){}
	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self {
		$keys=array_keys($payload);sort($keys,SORT_STRING);if($keys!==['type','values']||($payload['type']??null)!=='cross_filter'||!is_array($payload['values']??null)||!array_is_list($payload['values'])){throw new PanelDataSurfaceException('interaction_invalid',422,'Panel DataSurface interaction is invalid.');}
		try{$values=self::normalizeValues($payload['values']);}catch(\Throwable){throw new PanelDataSurfaceException('interaction_invalid',422,'Panel DataSurface interaction is invalid.');}
		return new self('cross_filter',$values);
	}
	/** @param list<mixed> $values @return list<mixed> */
	public static function normalizeValues(array $values):array {
		if(!array_is_list($values)||count($values)>100){throw new \LengthException('Panel DataSurface interactions support at most 100 values.');}$out=[];$seen=[];
		foreach($values as$value){if(!is_null($value)&&!is_string($value)&&!is_int($value)&&!is_float($value)&&!is_bool($value)){throw new \InvalidArgumentException('Panel DataSurface interaction values must be scalar or null.');}if(is_float($value)&&!is_finite($value)){throw new \InvalidArgumentException('Panel DataSurface interaction values must be finite.');}if(is_string($value)&&(strlen($value)>1024||preg_match('//u',$value)!==1)){throw new \InvalidArgumentException('Panel DataSurface interaction strings are invalid.');}$key=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);if(isset($seen[$key])){continue;}$seen[$key]=true;$out[]=$value;}
		return$out;
	}
	public function type():string{return$this->type;}
	/** @return list<mixed> */public function values():array{return$this->values;}
	public function jsonSerialize():array{return['type'=>$this->type,'values'=>$this->values];}
}
