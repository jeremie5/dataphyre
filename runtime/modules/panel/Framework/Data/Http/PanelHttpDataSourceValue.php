<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Internal bounded-value grammar shared by the remote data-source boundary. */
final class PanelHttpDataSourceValue {
	private const SECRET_KEY='/(?:authorization|cookie|password|passwd|secret|token|api[_-]?key|private[_-]?key|credential|client[_-]?secret|access[_-]?key|session)/i';

	public static function identifier(string $value, string $label, int $max=96): string {
		$value=strtolower(trim($value));
		if($value==='' || strlen($value)>$max || preg_match('/^[a-z][a-z0-9_.-]*$/D', $value)!==1){ throw new \InvalidArgumentException($label.' must be a safe identifier.'); }
		return $value;
	}

	public static function text(string $value, string $label, int $max, bool $allowEmpty=false): string {
		$value=trim($value);
		if((!$allowEmpty && $value==='') || strlen($value)>$max || preg_match('//u', $value)!==1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException($label.' is invalid or exceeds its byte limit.');
		}
		return $value;
	}

	/** @param array<string,mixed> $value @param list<string> $keys */
	public static function exactKeys(array $value, array $keys, string $label): void {
		if(array_is_list($value)){ throw new \InvalidArgumentException($label.' must be an object-like map.'); }
		$actual=array_keys($value); sort($actual, SORT_STRING); $expected=$keys; sort($expected, SORT_STRING);
		if($actual!==$expected){ throw new \InvalidArgumentException($label.' has an invalid shape.'); }
	}

	/** @param array<string,mixed> $value @return array<string,mixed> */
	public static function scopeMap(array $value, string $label='Remote authorization projection'): array {
		if($value!==[] && array_is_list($value)){ throw new \InvalidArgumentException($label.' must be an object-like map.'); }
		$nodes=0; $normalized=self::json($value, $label, 0, $nodes, true);
		if(!is_array($normalized) || ($normalized!==[] && array_is_list($normalized))){ throw new \InvalidArgumentException($label.' must be an object-like map.'); }
		if(strlen(self::encode($normalized))>16384){ throw new \LengthException($label.' exceeds 16384 bytes.'); }
		return $normalized;
	}

	public static function json(mixed $value, string $label, int $depth=0, ?int &$nodes=null, bool $rejectSecrets=false): mixed {
		$nodes ??=0; $nodes++;
		if($nodes>10000 || $depth>16){ throw new \LengthException($label.' exceeds the bounded JSON budget.'); }
		if($value===null || is_bool($value) || is_int($value)){ return $value; }
		if(is_float($value)){ if(!is_finite($value)){ throw new \InvalidArgumentException($label.' contains a non-finite number.'); } return $value; }
		if(is_string($value)){ return self::text($value, $label.' string', 16384, true); }
		if($value instanceof \JsonSerializable){ return self::json($value->jsonSerialize(), $label, $depth+1, $nodes, $rejectSecrets); }
		if(!is_array($value)){ throw new \InvalidArgumentException($label.' contains a non-JSON value.'); }
		if(count($value)>2000){ throw new \LengthException($label.' contains too many entries.'); }
		$out=[];
		foreach($value as $key=>$item){
			if(!is_int($key) && !is_string($key)){ throw new \InvalidArgumentException($label.' contains an invalid key.'); }
			if(is_string($key)){
				$key=self::text($key, $label.' key', 128);
				if($rejectSecrets && preg_match(self::SECRET_KEY, $key)===1){ throw new \InvalidArgumentException($label.' contains a secret-bearing key.'); }
			}
			$out[$key]=self::json($item, $label, $depth+1, $nodes, $rejectSecrets);
		}
		return $out;
	}

	public static function encode(mixed $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	public static function canonical(mixed $value): string { return self::encode(self::sort($value)); }

	private static function sort(mixed $value): mixed {
		if(!is_array($value)){ return $value; }
		if(array_is_list($value)){ return array_map(self::sort(...), $value); }
		ksort($value, SORT_STRING);
		foreach($value as $key=>$item){ $value[$key]=self::sort($item); }
		return $value;
	}
}
