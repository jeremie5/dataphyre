<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Shared bounded-value and canonical-encoding rules for DataSurface contracts. */
final class PanelDataSurfaceGuard {
	public const MAX_JSON_DEPTH=16;
	public const MAX_JSON_NODES=10000;
	public const MAX_STRING_BYTES=65536;
	public const MAX_RESPONSE_BYTES=2097152;

	public static function identifier(string $value, string $label='identifier', int $max=100): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
		$value=trim($value, '_');
		if($value==='' || strlen($value)>$max){ throw new \InvalidArgumentException("Panel DataSurface {$label} is invalid."); }
		return $value;
	}

	public static function boundedString(mixed $value, string $label, int $max=256, bool $allowEmpty=false): string {
		if(!is_string($value) && !is_int($value)){ throw new \InvalidArgumentException("Panel DataSurface {$label} must be a string or integer."); }
		$value=trim((string)$value);
		if((!$allowEmpty && $value==='') || strlen($value)>$max || preg_match('//u', $value)!==1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException("Panel DataSurface {$label} is not a bounded UTF-8 value.");
		}
		return $value;
	}

	public static function digest(string $value, string $label='digest'): string {
		if(preg_match('/^[a-f0-9]{64}$/D', $value)!==1){ throw new \InvalidArgumentException("Panel DataSurface {$label} must be a SHA-256 digest."); }
		return $value;
	}

	public static function field(string $field): string {
		return PanelQueryPath::make($field)->value();
	}

	public static function canonicalJson(array $value): string {
		$value=self::canonicalize($value);
		self::assertJson($value);
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	public static function assertJson(mixed $value, int $maximumBytes=self::MAX_RESPONSE_BYTES): void {
		$nodes=0;
		self::walk($value, 0, $nodes);
		$encoded=json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		if(strlen($encoded)>$maximumBytes){ throw new \LengthException('Panel DataSurface JSON exceeds its configured bound.'); }
	}

	public static function encode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	public static function decode(string $value): ?string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/D', $value)!==1){ return null; }
		$padding=(4-(strlen($value)%4))%4;
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
		return is_string($decoded) && hash_equals(self::encode($decoded), $value) ? $decoded : null;
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonicalize(array $value): array {
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonicalize($item); } }
		return $value;
	}

	private static function walk(mixed $value, int $depth, int &$nodes): void {
		if($depth>self::MAX_JSON_DEPTH){ throw new \LengthException('Panel DataSurface JSON exceeds the maximum depth.'); }
		if(++$nodes>self::MAX_JSON_NODES){ throw new \LengthException('Panel DataSurface JSON exceeds the maximum node count.'); }
		if($value===null || is_bool($value) || is_int($value)){ return; }
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException('Panel DataSurface JSON numbers must be finite.'); }
			return;
		}
		if(is_string($value)){
			if(strlen($value)>self::MAX_STRING_BYTES){ throw new \LengthException('Panel DataSurface JSON string exceeds 65536 bytes.'); }
			if(preg_match('//u', $value)!==1){ throw new \InvalidArgumentException('Panel DataSurface JSON strings must be valid UTF-8.'); }
			return;
		}
		if(!is_array($value)){ throw new \InvalidArgumentException('Panel DataSurface JSON accepts scalar and array values only.'); }
		foreach($value as $key=>$item){
			if(is_string($key) && (strlen($key)>256 || preg_match('//u', $key)!==1)){ throw new \InvalidArgumentException('Panel DataSurface JSON keys must be bounded valid UTF-8.'); }
			self::walk($item, $depth+1, $nodes);
		}
	}
}
