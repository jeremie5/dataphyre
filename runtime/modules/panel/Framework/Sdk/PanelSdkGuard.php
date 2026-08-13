<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded normalization and canonical encoding shared by SDK contracts and generators. */
final class PanelSdkGuard {
	public const MAX_SCHEMA_DEPTH=24;
	public const MAX_SCHEMA_NODES=20000;
	public const MAX_STRING_BYTES=131072;

	public static function identifier(string $value,string $label='SDK identifier',int $max=96):string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9]+/','_',$value)??'';
		$value=trim($value,'_');
		if($value===''||strlen($value)>$max){throw new \InvalidArgumentException("Panel {$label} is invalid.");}
		return$value;
	}

	public static function packageId(string $value):string {
		$value=strtolower(trim($value));
		if(preg_match('/^(?:@?[a-z0-9][a-z0-9._-]{0,63}\/)?[a-z0-9][a-z0-9._-]{0,127}$/D',$value)!==1){throw new \InvalidArgumentException('Panel SDK package id is invalid.');}
		return$value;
	}

	public static function label(string $value,string $label='SDK label',int $max=240,bool $allowEmpty=false):string {
		$value=trim($value);
		if((!$allowEmpty&&$value==='')||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1){throw new \InvalidArgumentException("Panel {$label} is invalid.");}
		return$value;
	}

	public static function version(string $value):string {
		$value=trim($value);
		if(preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D',$value)!==1){throw new \InvalidArgumentException('Panel SDK version must be semantic versioning compatible.');}
		return$value;
	}

	public static function path(string $value):string {
		$value=trim($value);
		if($value===''||strlen($value)>2048||$value[0]!=='/'||str_starts_with($value,'//')||str_contains($value,'\\')||str_contains($value,'?')||str_contains($value,'#')||preg_match('/[\x00-\x20\x7F]/',$value)===1||str_contains($value,'..')){throw new \InvalidArgumentException('Panel SDK operation paths must be same-origin absolute paths without query, fragment, traversal, whitespace, or backslashes.');}
		if(preg_match_all('/\{([^{}]+)\}/',$value,$matches)!==false){
			$seen=[];
			foreach($matches[1]as$name){if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$name)!==1||isset($seen[$name])){throw new \InvalidArgumentException('Panel SDK path parameters are invalid or duplicated.');}$seen[$name]=true;}
		}
		$stripped=preg_replace('/\{[A-Za-z][A-Za-z0-9_]{0,63}\}/','x',$value);
		if(!is_string($stripped)||str_contains($stripped,'{')||str_contains($stripped,'}')){throw new \InvalidArgumentException('Panel SDK operation path templates are malformed.');}
		return$value;
	}

	/** @return list<string> */
	public static function pathParameters(string $path):array {
		preg_match_all('/\{([A-Za-z][A-Za-z0-9_]{0,63})\}/',$path,$matches);
		return array_values($matches[1]??[]);
	}

	public static function phpNamespace(string $value):string {
		$value=trim($value,' \\');
		if($value===''||strlen($value)>240||preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D',$value)!==1){throw new \InvalidArgumentException('Panel SDK PHP namespace is invalid.');}
		return$value;
	}

	public static function phpClass(string $value):string {
		$value=trim($value);
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D',$value)!==1){throw new \InvalidArgumentException('Panel SDK PHP class name is invalid.');}
		return$value;
	}

	public static function filePath(string $value):string {
		$value=str_replace('\\','/',trim($value));
		$segments=explode('/',$value);
		if($value===''||strlen($value)>512||str_starts_with($value,'/')||in_array('',$segments,true)||in_array('.',$segments,true)||in_array('..',$segments,true)||preg_match('/[\x00-\x1F\x7F]/',$value)===1){throw new \InvalidArgumentException('Panel SDK package file path is invalid.');}
		return$value;
	}

	/** @param list<mixed> $values @return list<string> */
	public static function names(array $values,string $label='SDK name',int $max=128):array {
		if(!array_is_list($values)||count($values)>$max){throw new \InvalidArgumentException("Panel {$label} list is invalid.");}
		$out=[];
		foreach($values as$value){if(!is_string($value)){throw new \InvalidArgumentException("Panel {$label} list is invalid.");}$name=self::identifier($value,$label);$out[$name]=true;}
		$out=array_keys($out);sort($out,SORT_STRING);return$out;
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	public static function metadata(array $metadata):array {
		if(array_is_list($metadata)&&$metadata!==[]){throw new \InvalidArgumentException('Panel SDK metadata must be an object.');}
		$forbidden='/token|secret|password|credential|cookie|authorization|private.?key/i';
		foreach($metadata as$key=>$value){if(!is_string($key)||preg_match($forbidden,$key)===1){throw new \InvalidArgumentException('Panel SDK metadata cannot contain credential-like keys.');}}
		self::assertJson($metadata,8,2000,65536);
		return self::canonicalize($metadata);
	}

	public static function digest(string $value,string $label='SDK digest'):string {
		if(preg_match('/^[a-f0-9]{64}$/D',$value)!==1){throw new \InvalidArgumentException("Panel {$label} must be a SHA-256 digest.");}
		return$value;
	}

	public static function canonicalJson(mixed $value):string {
		self::assertJson($value,self::MAX_SCHEMA_DEPTH,self::MAX_SCHEMA_NODES,4194304);
		$value=self::canonicalize($value);
		return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
	}

	public static function fingerprint(mixed $value):string {return hash('sha256',self::canonicalJson($value));}

	public static function assertJson(mixed $value,int $maxDepth=self::MAX_SCHEMA_DEPTH,int $maxNodes=self::MAX_SCHEMA_NODES,int $maxBytes=4194304):void {
		$nodes=0;self::walk($value,0,$nodes,$maxDepth,$maxNodes);
		$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		if(strlen($json)>$maxBytes){throw new \LengthException('Panel SDK JSON exceeds its configured byte bound.');}
	}

	private static function walk(mixed $value,int $depth,int &$nodes,int $maxDepth,int $maxNodes):void {
		if($depth>$maxDepth||++$nodes>$maxNodes){throw new \LengthException('Panel SDK JSON exceeds its structural bounds.');}
		if($value===null||is_bool($value)||is_int($value)){return;}
		if(is_float($value)){if(!is_finite($value)){throw new \InvalidArgumentException('Panel SDK JSON numbers must be finite.');}return;}
		if(is_string($value)){if(strlen($value)>self::MAX_STRING_BYTES||preg_match('//u',$value)!==1){throw new \InvalidArgumentException('Panel SDK JSON strings must be bounded UTF-8.');}return;}
		if(!is_array($value)){throw new \InvalidArgumentException('Panel SDK JSON accepts arrays and scalar values only.');}
		foreach($value as$key=>$item){if(is_string($key)&&(strlen($key)>256||preg_match('//u',$key)!==1)){throw new \InvalidArgumentException('Panel SDK JSON keys must be bounded UTF-8.');}self::walk($item,$depth+1,$nodes,$maxDepth,$maxNodes);}
	}

	private static function canonicalize(mixed $value):mixed {
		if(!is_array($value)){return$value;}
		if(!array_is_list($value)){ksort($value,SORT_STRING);}
		foreach($value as$key=>$item){$value[$key]=self::canonicalize($item);}
		return$value;
	}
}
