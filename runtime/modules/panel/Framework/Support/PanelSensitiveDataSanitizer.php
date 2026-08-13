<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Recursively removes secrets from diagnostic and public-error payloads.
 *
 * Sanitization is deliberately bounded by depth, item count, and string size.
 * Sensitive keys are detected after separator and camel-case normalization,
 * while common credential fragments embedded in otherwise safe strings are
 * scrubbed as a second line of defence.
 */
final class PanelSensitiveDataSanitizer {
	public const REDACTED='[REDACTED]';

	/**
	 * @param array{max_depth?:int,max_items?:int,max_string_bytes?:int,root_key?:string} $options
	 */
	public static function sanitize(mixed $value,array $options=[]):mixed{
		$maxDepth=max(1,min(32,(int)($options['max_depth']??8)));
		$maxItems=max(1,min(1000,(int)($options['max_items']??100)));
		$maxStringBytes=max(16,min(65536,(int)($options['max_string_bytes']??2048)));
		$rootKey=is_string($options['root_key']??null)?(string)$options['root_key']:null;
		return self::value($value,$rootKey,$rootKey!==null?[$rootKey]:[],0,$maxDepth,$maxItems,$maxStringBytes);
	}

	/** Returns a valid UTF-8 representation without throwing on arbitrary bytes. */
	public static function normalizeUtf8(string $value):string{
		if($value===''||preg_match('//u',$value)===1){return$value;}
		return (string)json_decode((string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),true);
	}

	/** Reports whether a field name conventionally carries secret material. */
	public static function isSensitiveKey(string $key,array $path=[]):bool{
		$key=self::normalizeUtf8($key);
		$key=self::normalizeKey($key);
		if($key==='code'){
			$parents=array_map([self::class,'normalizeKey'],$path);
			return array_intersect($parents,['input','query','authentication','challenge','mfa','totp','otp'])!==[];
		}
		return preg_match('/(?:^|_)(?:password|passwd|pwd|passphrase|token|secret|credential|authorization|cookie|csrf|recovery_codes?|totp|otp|api_key|access_key|private_key|encryption_key|signing_key|challenge_key|session_id|fingerprint|pepper)(?:_|$)/',$key)===1;
	}

	private static function value(mixed $value,?string $key,array $path,int $depth,int $maxDepth,int $maxItems,int $maxStringBytes):mixed{
		if($key!==null&&self::isSensitiveKey($key,array_slice($path,0,-1))){return self::REDACTED;}
		if($depth>$maxDepth){return ['type'=>get_debug_type($value),'truncated'=>'depth'];}
		if($value instanceof \JsonSerializable){
			try{$value=$value->jsonSerialize();}
			catch(\Throwable){return ['type'=>'object','class'=>$value::class,'serialization'=>'failed'];}
		}
		if($value instanceof \Throwable){
			$value=['exception'=>$value::class,'message'=>$value->getMessage()];
		}
		if(is_array($value)){
			$clean=[];$seen=0;$total=count($value);
			foreach($value as$itemKey=>$item){
				if($seen++>=$maxItems){break;}
				$childKey=is_string($itemKey)?$itemKey:null;
				$outputKey=is_string($itemKey)?self::safeKey($itemKey,$maxStringBytes):$itemKey;
				if(array_key_exists($outputKey,$clean)){
					$base=(string)$outputKey.'#'.substr(hash('sha256',self::normalizeUtf8((string)$itemKey)),0,12);$outputKey=$base;$collision=2;
					while(array_key_exists($outputKey,$clean)){$outputKey=$base.'_'.$collision++;}
				}
				$clean[$outputKey]=self::value($item,$childKey,[...$path,(string)$itemKey],$depth+1,$maxDepth,$maxItems,$maxStringBytes);
			}
			if($total>$maxItems){$clean['__truncated_items__']=$total-$maxItems;}
			return $clean;
		}
		if(is_object($value)){return ['type'=>'object','class'=>$value::class];}
		if(is_resource($value)){return ['type'=>'resource','resource_type'=>get_resource_type($value)];}
		if(is_float($value)&&!is_finite($value)){return (string)$value;}
		if(!is_string($value)){return $value;}
		$value=self::scrubString(self::normalizeUtf8($value));
		return self::truncateUtf8($value,$maxStringBytes);
	}

	private static function normalizeKey(string $key):string{
		$key=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',trim($key))??$key;
		return strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',$key)??'','_'));
	}

	private static function scrubString(string $value):string{
		if(preg_match('/-----BEGIN (?:(?:ENCRYPTED|RSA|EC|DSA|OPENSSH|PGP|SSH2 ENCRYPTED) )?PRIVATE KEY(?: BLOCK)?-----/i',$value)===1){return self::REDACTED;}
		$value=preg_replace('/([a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:)[^@\s\/]+(@)/i','$1'.self::REDACTED.'$2',$value)??$value;
		$names='password|passwd|pwd|passphrase|token|secret|credential|access[_-]?token|refresh[_-]?token|api[_-]?key|client[_-]?secret|authorization|cookie|csrf[_-]?token|session[_-]?id|private[_-]?key';
		$value=preg_replace('/(\b(?:'.$names.')\b\s*(?:=|:)\s*)(?:"[^"]*"|\'[^\']*\')/i','$1'.self::REDACTED,$value)??$value;
		$value=preg_replace('/(\b(?:'.$names.')\b\s*(?:=|:)\s*)(?!\[REDACTED\])[^&,;}\]\r\n]*?(?=(?:\s+[A-Za-z][A-Za-z0-9_.-]*\s*(?:=|:))|[&,;}\]\r\n]|$)/i','$1'.self::REDACTED,$value)??$value;
		$value=preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i','$1 '.self::REDACTED,$value)??$value;
		return preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',self::REDACTED,$value)??$value;
	}

	private static function safeKey(string $key,int $maxStringBytes):string{
		return self::truncateUtf8(self::scrubString(self::normalizeUtf8($key)),$maxStringBytes);
	}

	private static function truncateUtf8(string $value,int $maxBytes):string{
		if(strlen($value)<=$maxBytes){return$value;}
		$cut=substr($value,0,$maxBytes);
		while($cut!==''&&preg_match('//u',$cut)!==1){$cut=substr($cut,0,-1);}
		return$cut.'...';
	}
}
