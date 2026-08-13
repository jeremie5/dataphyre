<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Canonical digests and bounded secret-safe migration manifests. */
final class PanelMigrationIntegrity {
	public static function digest(mixed $value):string { return hash('sha256',self::canonicalJson($value)); }
	public static function canonicalJson(mixed $value):string { return json_encode(self::canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR); }

	public static function identifier(string $value,string $label='identifier'):string {
		$value=trim($value);
		if($value===''||strlen($value)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$value)!==1){throw new \InvalidArgumentException("Panel migration {$label} must be a safe identifier.");}
		return$value;
	}

	public static function tenant(?string $value):?string {
		if($value===null){return null;}
		$value=trim($value);if($value===''){return null;}
		return self::identifier($value,'tenant id');
	}

	public static function redact(mixed $value,int $depth=0):mixed {
		if($depth>32){return'[TRUNCATED]';}
		if($value instanceof \Throwable){return['type'=>get_class($value),'message'=>self::redactString($value->getMessage()),'code'=>(int)$value->getCode()];}
		if($value instanceof \JsonSerializable){$value=$value->jsonSerialize();}
		if(is_object($value)){return['type'=>get_class($value)];}
		if(is_string($value)){return self::redactString(strlen($value)>4096?substr($value,0,4096).'[TRUNCATED]':$value);}
		if(!is_array($value)){return$value;}
		$out=[];$count=0;
		foreach($value as$key=>$item){
			if(++$count>500){$out['_truncated']='[TRUNCATED]';break;}
			$name=(string)$key;
			$out[$key]=preg_match('/(?:password|passwd|secret|token|authorization|cookie|credential|private[_-]?key|api[_-]?key)/i',$name)===1?'[REDACTED]':self::redact($item,$depth+1);
		}
		return$out;
	}

	private static function redactString(string $value):string {
		return preg_replace('/\b(password|passwd|secret|token|authorization|cookie|credential|api[_-]?key)\b\s*[:=]\s*[^\s,;]+/i','$1=[REDACTED]',$value)??'[REDACTED]';
	}

	private static function canonicalize(mixed $value):mixed {
		if($value instanceof \JsonSerializable){$value=$value->jsonSerialize();}
		if(is_object($value)){throw new \InvalidArgumentException('Panel migration digests only accept scalar, array, or JsonSerializable values.');}
		if(!is_array($value)){return$value;}
		if(!array_is_list($value)){ksort($value,SORT_STRING);}
		foreach($value as$key=>$item){$value[$key]=self::canonicalize($item);}
		return$value;
	}
}
