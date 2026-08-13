<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** @internal Strict JSON-value validation shared by widget runtime contracts. */
final class PanelWidgetInteractionValue {
	public const MAX_BYTES=65536;
	public const MAX_DEPTH=8;
	public const MAX_NODES=1024;
	public const MAX_COLLECTION=128;
	private const SECRET_KEY_PATTERN='/(?:^|[_-])(?:pass(?:word)?|passwd|secret|credential|authorization|cookie|session|private[_-]?key|api[_-]?key|access[_-]?token|refresh[_-]?token|token)$/i';

	private function __construct(){}

	public static function assertMap(array $value, string $label='payload', int $maxBytes=self::MAX_BYTES): array {
		if(array_is_list($value) && $value!==[]){
			throw new \InvalidArgumentException(ucfirst($label).' must be an object-like map.');
		}
		$nodes=0;
		$normalized=self::value($value, $label, 0, $nodes);
		$encoded=self::encode($normalized);
		if(strlen($encoded)>$maxBytes){
			throw new \LengthException(ucfirst($label).' exceeds the byte limit.');
		}
		return $normalized;
	}

	public static function canonical(array $value): string {
		$value=self::sort($value);
		return self::encode($value);
	}

	public static function safeIdentifier(string $value, string $label, int $max=96): string {
		$value=strtolower(trim($value));
		if(strlen($value)>$max || preg_match('/^[a-z][a-z0-9_.-]*$/', $value)!==1){
			throw new \InvalidArgumentException($label.' must be a safe identifier.');
		}
		return $value;
	}

	public static function boundedString(string $value, string $label, int $max, bool $allowEmpty=false): string {
		$value=trim($value);
		if((!$allowEmpty && $value==='') || strlen($value)>$max || !self::validUtf8($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException($label.' is invalid or exceeds its byte limit.');
		}
		return $value;
	}

	private static function value(mixed $value, string $path, int $depth, int &$nodes): mixed {
		$nodes++;
		if($depth>self::MAX_DEPTH || $nodes>self::MAX_NODES){
			throw new \LengthException('Widget interaction JSON exceeds its structural limits.');
		}
		if($value===null || is_bool($value) || is_int($value)){
			return $value;
		}
		if(is_float($value)){
			if(!is_finite($value)){ throw new \InvalidArgumentException($path.' contains a non-finite number.'); }
			return $value;
		}
		if(is_string($value)){
			return self::boundedString($value, $path, 8192, true);
		}
		if(!is_array($value)){
			throw new \InvalidArgumentException($path.' contains a non-JSON value.');
		}
		if(count($value)>self::MAX_COLLECTION){
			throw new \LengthException($path.' contains too many entries.');
		}
		$list=array_is_list($value);
		$result=[];
		foreach($value as $key=>$nested){
			if(!$list){
				if(!is_string($key) || $key==='' || strlen($key)>64 || !self::validUtf8($key) || preg_match(self::SECRET_KEY_PATTERN, $key)===1){
					throw new \InvalidArgumentException($path.' contains an unsafe or sensitive key.');
				}
			}
			$result[$key]=self::value($nested, $path.'.'.(string)$key, $depth+1, $nodes);
		}
		return $result;
	}

	private static function sort(array $value): array {
		foreach($value as $key=>$nested){ if(is_array($nested)){ $value[$key]=self::sort($nested); } }
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		return $value;
	}

	private static function encode(array $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function validUtf8(string $value): bool {
		return preg_match('//u', $value)===1;
	}
}
