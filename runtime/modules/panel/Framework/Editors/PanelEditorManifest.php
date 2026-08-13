<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Normalizes editor manifests without serializing callbacks, objects, or secrets. */
final class PanelEditorManifest {
	private const MAX_DEPTH=6;
	private const MAX_ITEMS=128;
	private const MAX_STRING_BYTES=4096;

	/** @return array<string|int,mixed> */
	public static function sanitize(array $manifest): array {
		$value=self::value($manifest, 0);
		return is_array($value) ? $value : [];
	}

	public static function name(string $name, string $fallback=''): string {
		$name=Resource::normalizeName($name);
		return $name!=='' ? $name : Resource::normalizeName($fallback);
	}

	private static function sensitive(string $key): bool {
		$key=preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key)) ?? $key;
		$key=strtolower(str_replace(['-', '.'], '_', $key));
		return preg_match('/(?:^|_)(?:secret|token|password|passwd|authorization|cookie|credential|private_key|signing_key|api_key|access_key|csrf|callback|callable|closure|handler|resolver|factory)(?:$|_)/', $key)===1;
	}

	private static function value(mixed $value, int $depth): mixed {
		if($depth>self::MAX_DEPTH || is_resource($value) || $value instanceof \Closure || is_object($value)){
			return null;
		}
		if(is_string($value)){
			if(strlen($value)<=self::MAX_STRING_BYTES){ return $value; }
			$value=substr($value, 0, self::MAX_STRING_BYTES);
			while($value!=='' && preg_match('//u', $value)!==1){ $value=substr($value, 0, -1); }
			return $value;
		}
		if(is_int($value) || is_float($value) || is_bool($value) || $value===null){
			return $value;
		}
		if(!is_array($value)){
			return null;
		}
		$clean=[];
		$list=array_is_list($value);
		$count=0;
		foreach($value as $key=>$item){
			if($count++>=self::MAX_ITEMS){
				break;
			}
			if(!is_int($key)){
				$key=trim((string)$key);
				if($key==='' || self::sensitive($key)){
					continue;
				}
			}
			if(is_array($item) && is_callable($item)){
				continue;
			}
			$normalized=self::value($item, $depth+1);
			if($normalized===null && $item!==null){
				continue;
			}
			if($list){ $clean[]=$normalized; }else{ $clean[$key]=$normalized; }
		}
		return $clean;
	}
}
