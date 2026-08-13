<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded identifiers, JSON, and canonical encodings shared by realtime contracts. */
final class PanelRealtimeGuard {
	public const MAX_JSON_DEPTH=16;
	public const MAX_JSON_NODES=10000;
	public const MAX_STRING_BYTES=65536;

	public static function identifier(string $value, string $label='identifier', int $maximum=96, bool $wildcard=false): string {
		$value=strtolower(trim($value));
		if($wildcard && $value==='*'){ return $value; }
		if(strlen($value)>$maximum || preg_match('/^[a-z][a-z0-9_.:-]*$/D', $value)!==1){ throw new \InvalidArgumentException("Panel realtime {$label} is invalid."); }
		return $value;
	}

	public static function text(mixed $value, string $label, int $maximum=256, bool $empty=false): string {
		if(!is_string($value) && !is_int($value)){ throw new \InvalidArgumentException("Panel realtime {$label} must be a string or integer."); }
		$raw=(string)$value; $value=trim($raw);
		if(preg_match('/[\x00-\x1F\x7F]/', $raw)===1 || (!$empty && $value==='') || strlen($value)>$maximum || preg_match('//u', $value)!==1){
			throw new \InvalidArgumentException("Panel realtime {$label} is not a bounded UTF-8 value.");
		}
		return $value;
	}

	/** @param array<string,mixed> $filters @return array<string,mixed> */
	public static function filters(array $filters): array {
		if(($filters!==[] && array_is_list($filters)) || count($filters)>16){ throw new \InvalidArgumentException('Panel realtime filters require an object-like map of at most 16 entries.'); }
		$normalized=[];
		foreach($filters as $key=>$value){
			$key=self::identifier((string)$key, 'filter name', 64);
			if(!(is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value))){ throw new \InvalidArgumentException('Panel realtime filters accept JSON scalar values only.'); }
			self::assertJson($value, 4096);
			$normalized[$key]=$value;
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	public static function canonicalJson(array $value): string {
		$value=self::canonicalize($value);
		self::assertJson($value);
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	public static function assertJson(mixed $value, int $maximumBytes=262144): void {
		$nodes=0;
		self::walk($value, 0, $nodes);
		$encoded=json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>$maximumBytes){ throw new \LengthException('Panel realtime JSON exceeds its configured byte bound.'); }
	}

	public static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

	public static function decode(string $value): ?string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ return null; }
		$padding=(4-(strlen($value)%4))%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		return is_string($decoded) && hash_equals(self::encode($decoded), $value) ? $decoded : null;
	}

	public static function digest(string $value, string $label='digest'): string {
		if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException("Panel realtime {$label} must be a SHA-256 digest."); }
		return $value;
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonicalize(array $value): array {
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonicalize($item); } }
		return $value;
	}

	private static function walk(mixed $value, int $depth, int &$nodes): void {
		if($depth>self::MAX_JSON_DEPTH){ throw new \LengthException('Panel realtime JSON exceeds its depth bound.'); }
		if(++$nodes>self::MAX_JSON_NODES){ throw new \LengthException('Panel realtime JSON exceeds its node bound.'); }
		if($value===null || is_bool($value) || is_int($value)){ return; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException('Panel realtime JSON numbers must be finite.'); }
			return;
		}
		if(is_string($value)){
			if(strlen($value)>self::MAX_STRING_BYTES || preg_match('//u', $value)!==1){ throw new \InvalidArgumentException('Panel realtime JSON strings must be bounded valid UTF-8.'); }
			return;
		}
		if(!is_array($value)){ throw new \InvalidArgumentException('Panel realtime JSON accepts scalar and array values only.'); }
		foreach($value as $key=>$item){
			if(is_string($key) && (strlen($key)>256 || preg_match('//u', $key)!==1)){ throw new \InvalidArgumentException('Panel realtime JSON keys must be bounded valid UTF-8.'); }
			self::walk($item, $depth+1, $nodes);
		}
	}
}
