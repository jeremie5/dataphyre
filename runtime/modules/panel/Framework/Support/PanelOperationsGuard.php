<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Shared deterministic and bounded value rules for Panel's operations OS. */
final class PanelOperationsGuard {
	private function __construct() {}

	public static function identifier(string|int $value,string $label='identifier',int $maximum=128):string {
		$value=trim((string)$value);
		if($value===''||strlen($value)>$maximum||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D',$value)!==1){
			throw new \InvalidArgumentException(ucfirst($label).' is invalid.');
		}
		return$value;
	}

	public static function name(string|int $value,string $label='name',int $maximum=96):string {
		$value=strtolower(trim((string)$value));
		if($value===''||strlen($value)>$maximum||preg_match('/^[a-z][a-z0-9_.-]*$/D',$value)!==1){
			throw new \InvalidArgumentException(ucfirst($label).' is invalid.');
		}
		return$value;
	}

	public static function label(string $value,string $label='label',int $maximum=256):string {
		$value=trim($value);
		if($value===''||strlen($value)>$maximum||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1){
			throw new \InvalidArgumentException(ucfirst($label).' is invalid.');
		}
		return$value;
	}

	/** @param array<int,mixed> $values @return list<string> */
	public static function names(array $values,string $label='name',int $maximum=96,int $limit=512):array {
		if(count($values)>$limit){throw new \LengthException(ucfirst($label).' list exceeds its limit.');}
		$result=[];
		foreach($values as$value){$name=self::name((string)$value,$label,$maximum);$result[$name]=true;}
		$names=array_keys($result);sort($names,SORT_STRING);return$names;
	}

	/** @param array<int,mixed> $values @return list<string> */
	public static function identifiers(array $values,string $label='identifier',int $maximum=128,int $limit=512):array {
		if(count($values)>$limit){throw new \LengthException(ucfirst($label).' list exceeds its limit.');}
		$result=[];
		foreach($values as$value){$identifier=self::identifier(is_int($value)?$value:(string)$value,$label,$maximum);$result[$identifier]=true;}
		$identifiers=array_keys($result);sort($identifiers,SORT_STRING);return$identifiers;
	}

	/** @param array<int,mixed> $values @return list<string> */
	public static function abilityPatterns(array $values,string $label='ability',int $limit=512):array {
		if(count($values)>$limit){throw new \LengthException(ucfirst($label).' list exceeds its limit.');}$result=[];
		foreach($values as$value){$pattern=strtolower(trim((string)$value));if($pattern!=='*'&&(strlen($pattern)>160||preg_match('/^[a-z][a-z0-9_.:-]*(?:\.\*)?$/D',$pattern)!==1)){throw new \InvalidArgumentException(ucfirst($label).' pattern is invalid.');}$result[$pattern]=true;}
		$patterns=array_keys($result);sort($patterns,SORT_STRING);return$patterns;
	}

	/** @param array<int,mixed> $values @return list<string> */
	public static function roles(array $values,string $label='role',int $limit=512):array {
		if(count($values)>$limit){throw new \LengthException(ucfirst($label).' list exceeds its limit.');}$result=[];
		foreach($values as$value){$role=strtolower(trim((string)$value));if($role!=='*'){$role=self::name($role,$label);}$result[$role]=true;}$roles=array_keys($result);sort($roles,SORT_STRING);return$roles;
	}

	/** @return array<string,mixed> */
	public static function object(array $value,string $label='object',int $limit=1024):array {
		if($value!==[]&&array_is_list($value)){throw new \InvalidArgumentException(ucfirst($label).' must be an object-like map.');}
		if(count($value)>$limit){throw new \LengthException(ucfirst($label).' exceeds its item limit.');}
		return$value;
	}

	public static function finite(int|float $value,string $label='number'):int|float {
		if(is_float($value)&&(!is_finite($value)||is_nan($value))){throw new \InvalidArgumentException(ucfirst($label).' must be finite.');}
		return$value;
	}

	/** @return array<string,mixed> */
	public static function safeMetadata(array $value,int $maxItems=256):array {
		self::object($value,'metadata',$maxItems);
		$safe=PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>12,'max_items'=>$maxItems,'max_string_bytes'=>16384]);
		if(!is_array($safe)){throw new \InvalidArgumentException('Metadata must be serializable.');}
		return self::canonical($safe);
	}

	public static function instant(string|int|\DateTimeInterface $value,string $label='instant'):string {
		try{
			$date=$value instanceof \DateTimeInterface
				? \DateTimeImmutable::createFromInterface($value)
				: (is_int($value)?(new \DateTimeImmutable('@'.$value)):new \DateTimeImmutable(trim($value)));
		}catch(\Throwable $error){throw new \InvalidArgumentException(ucfirst($label).' is invalid.',0,$error);}
		return$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
	}

	public static function canonical(mixed $value,int $depth=0):mixed {
		if($depth>32){throw new \LengthException('Canonical value exceeds the maximum depth.');}
		if(is_array($value)){
			if(count($value)>4096){throw new \LengthException('Canonical value exceeds the maximum item count.');}
			$result=[];foreach($value as$key=>$item){if(!is_int($key)&&!is_string($key)){throw new \InvalidArgumentException('Canonical map keys must be strings or integers.');}$result[$key]=self::canonical($item,$depth+1);}
			if(!array_is_list($result)){ksort($result,SORT_STRING);}return$result;
		}
		if(is_float($value)){self::finite($value);return$value;}
		if(is_string($value)){if(strlen($value)>262144){throw new \LengthException('Canonical string exceeds its byte limit.');}return$value;}
		if(is_int($value)||is_bool($value)||$value===null){return$value;}
		throw new \InvalidArgumentException('Operations OS values must be JSON-compatible.');
	}

	public static function json(mixed $value):string {
		return json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	public static function digest(mixed $value):string {return hash('sha256',self::json($value));}

	public static function valueAt(array $source,string $path,mixed $default=null):mixed {
		if($path===''){return$source;}$cursor=$source;
		foreach(explode('.',$path)as$segment){if(!is_array($cursor)||!array_key_exists($segment,$cursor)){return$default;}$cursor=$cursor[$segment];}
		return$cursor;
	}

	public static function abilityMatches(string $pattern,string $ability):bool {
		$pattern=strtolower(trim($pattern));$ability=strtolower(trim($ability));
		return$pattern==='*'||hash_equals($pattern,$ability)||(str_ends_with($pattern,'.*')&&str_starts_with($ability,substr($pattern,0,-1)));
	}
}
