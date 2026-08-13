<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Canonical validation, bounding, and secret-rejection rules for IAM state. */
final class PanelIamGuard {
	private const MAX_DEPTH=8;
	private const MAX_ITEMS=1000;
	private const MAX_STRING_BYTES=4096;
	private const CREDENTIAL_KEYS=['key_id','version','algorithm','provider','state','rotated_at','expires_at','last_four'];

	public static function identifier(string|int $value,string $label='identifier'):string {
		$value=trim((string)$value);
		if($value===''||strlen($value)>190||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D',$value)!==1){
			throw new \InvalidArgumentException('Panel IAM '.$label.' is invalid.');
		}
		return$value;
	}

	public static function operation(string $value):string {
		$value=strtolower(trim($value));
		if($value===''||strlen($value)>120||preg_match('/^[a-z][a-z0-9_.-]*$/D',$value)!==1){throw new \InvalidArgumentException('Panel IAM operation is invalid.');}
		return$value;
	}

	public static function subjectType(string $value):string {
		$value=strtolower(trim($value));
		if(!in_array($value,['principal','service'],true)){throw new \InvalidArgumentException('Panel IAM subject type must be principal or service.');}
		return$value;
	}

	public static function status(string $value):string {
		$value=strtolower(trim($value));
		if(!in_array($value,['active','suspended','revoked'],true)){throw new \InvalidArgumentException('Panel IAM status is invalid.');}
		return$value;
	}

	/** @return list<string> */
	public static function names(array|string $values,string $label='name'):array {
		$values=is_array($values)?$values:[$values];
		if(count($values)>100){throw new \LengthException('Panel IAM '.$label.' list exceeds 100 entries.');}
		$normalized=[];
		foreach($values as$value){
			if(!is_scalar($value)){throw new \InvalidArgumentException('Panel IAM '.$label.' entries must be scalar.');}
			$name=strtolower(trim((string)$value));
			if($name===''||strlen($name)>190||preg_match('/^[a-z0-9*][a-z0-9*_.:-]*$/D',$name)!==1){throw new \InvalidArgumentException('Panel IAM '.$label.' entry is invalid.');}
			$normalized[$name]=true;
		}
		$names=array_keys($normalized);sort($names,SORT_STRING);return$names;
	}

	public static function text(string $value,string $label,int $max=500,bool $required=true):string {
		$value=trim($value);
		if(($required&&$value==='')||strlen($value)>$max||($value!==''&&preg_match('//u',$value)!==1)||self::secretLike($value)){throw new \InvalidArgumentException('Panel IAM '.$label.' is invalid.');}
		return$value;
	}

	public static function instant(string|int|null $value,string $label,bool $nullable=true):?string {
		if($value===null||$value===''){if($nullable){return null;}throw new \InvalidArgumentException('Panel IAM '.$label.' is required.');}
		try{$date=is_int($value)?(new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('UTC')):new \DateTimeImmutable((string)$value);}
		catch(\Throwable $exception){throw new \InvalidArgumentException('Panel IAM '.$label.' is invalid.',0,$exception);}
		return$date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	public static function metadata(array $metadata):array {
		if($metadata!==[]&&array_is_list($metadata)){throw new \InvalidArgumentException('Panel IAM metadata must be an object-like array.');}
		$count=0;$value=self::safeValue($metadata,0,$count,false);
		return is_array($value)?$value:[];
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	public static function credentialMetadata(array $metadata,bool $required=false):array {
		if($metadata===[]){if($required){throw new \InvalidArgumentException('Panel IAM credential rotation metadata is required.');}return[];}
		if(array_is_list($metadata)){throw new \InvalidArgumentException('Panel IAM credential metadata must be an object-like array.');}
		$unknown=array_values(array_diff(array_keys($metadata),self::CREDENTIAL_KEYS));
		if($unknown!==[]){throw new \InvalidArgumentException('Panel IAM credential metadata contains unsupported fields.');}
		$out=[];
		foreach($metadata as$key=>$value){
			$key=(string)$key;
			$out[$key]=match($key){
				'version'=>self::positiveInt($value,'credential version'),
				'rotated_at'=>self::instant(is_int($value)||is_string($value)?$value:null,'credential rotated_at',false),
				'expires_at'=>self::instant(is_int($value)||is_string($value)?$value:null,'credential expires_at',true),
				'key_id'=>self::identifier(is_scalar($value)?(string)$value:'','credential key id'),
				'last_four'=>self::lastFour($value),
				default=>self::text(is_scalar($value)?(string)$value:'','credential '.$key,120,true),
			};
		}
		if($required){foreach(['key_id','version','rotated_at']as$key){if(!array_key_exists($key,$out)){throw new \InvalidArgumentException('Panel IAM credential metadata requires '.$key.'.');}}}
		ksort($out,SORT_STRING);return$out;
	}

	public static function digest(mixed $value):string {return hash('sha256',self::canonicalJson($value));}
	public static function canonicalJson(mixed $value):string {
		$count=0;$safe=self::safeValue($value,0,$count,true);
		$sort=static function(mixed $item)use(&$sort):mixed{if(!is_array($item)){return$item;}if(!array_is_list($item)){ksort($item,SORT_STRING);}foreach($item as$key=>$child){$item[$key]=$sort($child);}return$item;};
		return json_encode($sort($safe),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
	}

	private static function positiveInt(mixed $value,string $label):int {
		if(!is_int($value)&&!(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)===1)){throw new \InvalidArgumentException('Panel IAM '.$label.' is invalid.');}
		$value=(int)$value;if($value<1){throw new \InvalidArgumentException('Panel IAM '.$label.' is invalid.');}return$value;
	}

	private static function lastFour(mixed $value):string {
		$value=is_scalar($value)?trim((string)$value):'';
		if(preg_match('/^[A-Za-z0-9]{4,8}$/D',$value)!==1){throw new \InvalidArgumentException('Panel IAM credential last_four is invalid.');}
		return$value;
	}

	private static function safeValue(mixed $value,int $depth,int &$count,bool $allowRootList):mixed {
		if($depth>self::MAX_DEPTH){throw new \LengthException('Panel IAM data exceeds maximum nesting depth.');}
		$count++;if($count>self::MAX_ITEMS){throw new \LengthException('Panel IAM data exceeds maximum item count.');}
		if(is_array($value)){
			if($depth===0&&!$allowRootList&&$value!==[]&&array_is_list($value)){throw new \InvalidArgumentException('Panel IAM data must be object-like.');}
			$out=[];
			foreach($value as$key=>$child){
				if(!is_int($key)){if($key===''||strlen((string)$key)>190||preg_match('/(?:pass(?:word|wd)?|secret|token|authorization|cookie|private[_-]?key|api[_-]?key|credential|raw[_-]?key)/i',(string)$key)===1){throw new \InvalidArgumentException('Raw secret-like fields cannot be persisted in Panel IAM data.');}}
				$out[$key]=self::safeValue($child,$depth+1,$count,true);
			}
			return$out;
		}
		if(is_string($value)){
			if(strlen($value)>self::MAX_STRING_BYTES){throw new \LengthException('Panel IAM string exceeds 4096 bytes.');}
			if($value!==''&&preg_match('//u',$value)!==1){throw new \InvalidArgumentException('Panel IAM strings must be valid UTF-8.');}
			if(self::secretLike($value)){throw new \InvalidArgumentException('Raw secret-like values cannot be persisted in Panel IAM data.');}
			return$value;
		}
		if(is_float($value)&&!is_finite($value)){throw new \InvalidArgumentException('Panel IAM data contains a non-finite number.');}
		if($value!==null&&!is_scalar($value)){throw new \InvalidArgumentException('Panel IAM data contains a non-serializable value.');}
		return$value;
	}

	private static function secretLike(string $value):bool {
		if($value===''){return false;}
		return preg_match('/-----BEGIN [^-]*(?:PRIVATE|SECRET) KEY-----|(?:pass(?:word|wd)?|secret|token|authorization|api[_-]?key)\s*[:=]\s*\S+|\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]{8,}|\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/i',$value)===1;
	}
}
