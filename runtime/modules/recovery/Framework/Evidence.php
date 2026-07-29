<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use JsonSerializable;

/** Allowlist-only, bounded support evidence that rejects secret-bearing paths. */
final class Evidence implements JsonSerializable {
	private const BLOCKED_SEGMENTS=[
		'authorization','cookie','cookies','password','passphrase','secret','token',
		'access_token','refresh_token','api_key','private_key','ccv','cvv','cvc',
		'card_number','pan','session','session_id','stack','stacktrace','trace',
		'exception','raw','payload','body',
	];

	/** @param array<string,mixed> $values */
	private function __construct(private array $values) {}

	/** @param array<string,mixed> $source @param array<int,string> $allowlist */
	public static function from(array $source, array $allowlist, int $maxItems=24, int $maxStringLength=240): self {
		$values=[];
		foreach(array_slice(array_values(array_unique($allowlist)), 0, max(0, min(64, $maxItems))) as $path){
			$path=strtolower(trim((string)$path));
			if(!self::safePath($path)) continue;
			[$found, $value]=self::readPath($source, explode('.', $path));
			if(!$found) continue;
			$value=self::safeValue($value, max(16, min(2048, $maxStringLength)));
			if($value===null && $value!==self::readNull($source, explode('.', $path))) continue;
			self::writePath($values, explode('.', $path), $value);
		}
		return new self($values);
	}

	/** @return array<string,mixed> */
	public function all(): array {
		return $this->values;
	}

	public function jsonSerialize(): array {
		return $this->values;
	}

	private static function safePath(string $path): bool {
		if(preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}$/', $path)!==1) return false;
		foreach(explode('.', $path) as $segment){
			if(in_array($segment, self::BLOCKED_SEGMENTS, true)
				|| preg_match('/(?:password|secret|token|private.?key|card.?number|cvv|cvc|ccv|authorization|cookie)/', $segment)===1){
				return false;
			}
		}
		return true;
	}

	/** @return array{0:bool,1:mixed} */
	private static function readPath(array $source, array $segments): array {
		$value=$source;
		foreach($segments as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)) return [false, null];
			$value=$value[$segment];
		}
		return [true, $value];
	}

	private static function readNull(array $source, array $segments): mixed {
		[$found, $value]=self::readPath($source, $segments);
		return $found ? $value : new \stdClass();
	}

	private static function safeValue(mixed $value, int $maxStringLength, int $depth=0): mixed {
		if($value===null || is_bool($value) || is_int($value) || is_float($value)) return $value;
		if(is_string($value)) return substr(trim($value), 0, $maxStringLength);
		if(!is_array($value) || $depth>=3) return null;
		$safe=[];
		$count=0;
		foreach($value as $key=>$child){
			if($count>=24) break;
			$originalChild=$child;
			$key=is_int($key) ? $key : strtolower(trim((string)$key));
			if(is_string($key) && !self::safePath($key)) continue;
			$child=self::safeValue($child, $maxStringLength, $depth+1);
			if($child===null && $originalChild!==null) continue;
			$safe[$key]=$child;
			$count++;
		}
		return $safe;
	}

	private static function writePath(array &$target, array $segments, mixed $value): void {
		$cursor=&$target;
		$last=array_pop($segments);
		foreach($segments as $segment){
			if(!isset($cursor[$segment]) || !is_array($cursor[$segment])) $cursor[$segment]=[];
			$cursor=&$cursor[$segment];
		}
		if($last!==null) $cursor[$last]=$value;
	}
}
