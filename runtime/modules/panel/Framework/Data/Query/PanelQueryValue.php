<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Bounded JSON-value normalizer shared by query-expression DTOs. */
final class PanelQueryValue {
	public static function normalize(mixed $value, string $label='value', int $depth=0): mixed {
		if($depth>16){ throw new \InvalidArgumentException("Panel query {$label} exceeds maximum nesting depth."); }
		if($value===null || is_string($value) || is_int($value) || is_bool($value)){ return $value; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException("Panel query {$label} contains a non-finite number."); }
			return $value;
		}
		if($value instanceof \JsonSerializable){ return self::normalize($value->jsonSerialize(), $label, $depth+1); }
		if(is_array($value)){
			if(count($value)>1000){ throw new \LengthException("Panel query {$label} contains too many entries."); }
			$out=[];
			foreach($value as $key=>$item){
				if(!is_int($key) && !is_string($key)){ throw new \InvalidArgumentException("Panel query {$label} contains an invalid key."); }
				$out[$key]=self::normalize($item, $label, $depth+1);
			}
			if(!array_is_list($out)){ ksort($out, SORT_STRING); }
			return $out;
		}
		throw new \InvalidArgumentException("Panel query {$label} contains a non-serializable value.");
	}

	public static function stableJson(mixed $value): string {
		return json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private static function canonical(mixed $value): mixed {
		if(!is_array($value)){ return $value; }
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){ $value[$key]=self::canonical($item); }
		return $value;
	}
}
