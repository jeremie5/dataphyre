<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable JSON-only Studio component tree. */
final class PanelStudioDefinition implements \JsonSerializable {
	public const SCHEMA_VERSION=1;
	public const MAX_DEPTH=12;
	public const MAX_NODES=512;
	public const MAX_PROPERTIES=128;
	public const MAX_STRING_BYTES=4096;
	public const MAX_JSON_BYTES=524288;
	public const KINDS=['action','actions','board','board_column','column','data_surface','field','filter','filters','form','form_section','infolist','infolist_entry','navigation','navigation_group','navigation_item','page','show','show_section','tab','table','table_view','table_views','tabs','toolbar','widget','widget_grid','workflow','workflow_state','workflow_transition'];
	private readonly array $root;
	private readonly string $hash;

	/** @param array<string,mixed> $root */
	private function __construct(array $root){
		$count=0;$this->root=self::node($root,'root',0,$count);
		$json=self::canonicalJson($this->root);
		if(strlen($json)>self::MAX_JSON_BYTES){throw new \LengthException('Studio definitions cannot exceed 512 KiB.');}
		$this->hash=hash('sha256',$json);
	}
	/** @param array<string,mixed> $root */ public static function from(array $root):self{return new self($root);}
	public function root():array{return$this->root;}
	public function hash():string{return$this->hash;}
	public function jsonSerialize():array{return['type'=>'panel_studio_definition','version'=>self::SCHEMA_VERSION,'hash'=>$this->hash,'root'=>$this->root];}

	/** @param array<string,mixed> $node @return array<string,mixed> */
	private static function node(array $node,string $path,int $depth,int &$count):array{
		if($depth>self::MAX_DEPTH){throw new \LengthException('Studio component depth exceeds '.self::MAX_DEPTH.' at '.$path.'.');}
		if(++$count>self::MAX_NODES){throw new \LengthException('Studio definitions cannot contain more than '.self::MAX_NODES.' components.');}
		$extra=array_diff(array_keys($node),['kind','key','properties','children']);
		if($extra!==[]){throw new \InvalidArgumentException('Studio component '.$path.' contains unsupported keys: '.implode(', ',array_map('strval',$extra)).'.');}
		$kind=self::identifier($node['kind']??null,'kind',$path);
		if(!in_array($kind,self::KINDS,true)){throw new \InvalidArgumentException("Studio component kind '{$kind}' is not allow-listed at {$path}.");}
		$key=self::identifier($node['key']??null,'key',$path);
		$properties=$node['properties']??[];
		if(!is_array($properties)||($properties!==[]&&array_is_list($properties))){throw new \InvalidArgumentException('Studio component properties must be an object-like map at '.$path.'.');}
		if(count($properties)>self::MAX_PROPERTIES){throw new \LengthException('Studio component properties exceed '.self::MAX_PROPERTIES.' at '.$path.'.');}
		$clean=[];
		foreach($properties as$name=>$value){
			if(!is_string($name)||preg_match('/^[a-z][a-z0-9_]{0,63}$/',$name)!==1){throw new \InvalidArgumentException('Studio property names must be safe snake_case identifiers at '.$path.'.');}
			if(self::forbiddenKey($name)){throw new \InvalidArgumentException("Studio property '{$name}' is forbidden at {$path}.");}
			$clean[$name]=self::jsonValue($value,$path.'.properties.'.$name,0);
		}
		ksort($clean,SORT_STRING);
		if($kind==='field'&&($clean['type']??null)==='password'&&array_intersect(['default','value','initial_value'],array_keys($clean))!==[]){throw new \InvalidArgumentException('Studio password field definitions cannot carry credential values at '.$path.'.');}
		$children=$node['children']??[];
		if(!is_array($children)||!array_is_list($children)){throw new \InvalidArgumentException('Studio component children must be a list at '.$path.'.');}
		$output=[];$siblingKeys=[];
		foreach($children as$index=>$child){if(!is_array($child)||array_is_list($child)){throw new \InvalidArgumentException('Studio child nodes must be object-like maps at '.$path.'['.$index.'].');}$compiled=self::node($child,$path.'.children['.$index.']',$depth+1,$count);if(isset($siblingKeys[$compiled['key']])){throw new \InvalidArgumentException("Studio sibling component key '{$compiled['key']}' is duplicated at {$path}.");}$siblingKeys[$compiled['key']]=true;$output[]=$compiled;}
		return['kind'=>$kind,'key'=>$key,'properties'=>$clean,'children'=>$output];
	}
	private static function identifier(mixed $value,string $field,string $path):string{
		if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$value)!==1){throw new \InvalidArgumentException("Studio component {$field} must be a safe identifier at {$path}.");}
		return$value;
	}
	private static function forbiddenKey(string $key):bool{
		return PanelSensitiveDataSanitizer::isSensitiveKey($key)||preg_match('/(?:^|_)(?:php|class|callable|closure|raw_html|html|script|template|view)(?:_|$)/',$key)===1;
	}
	private static function jsonValue(mixed $value,string $path,int $depth):mixed{
		if($depth>self::MAX_DEPTH){throw new \LengthException('Studio property depth exceeds '.self::MAX_DEPTH.' at '.$path.'.');}
		if(is_object($value)||is_resource($value)||(!is_string($value)&&is_callable($value))){throw new \InvalidArgumentException('Studio properties must contain JSON-only values at '.$path.'.');}
		if(is_float($value)&&!is_finite($value)){throw new \InvalidArgumentException('Studio properties cannot contain non-finite numbers at '.$path.'.');}
		if(is_string($value)){
			if(strlen($value)>self::MAX_STRING_BYTES){throw new \LengthException('Studio strings cannot exceed '.self::MAX_STRING_BYTES.' bytes at '.$path.'.');}
			if(preg_match('//u',$value)!==1){throw new \InvalidArgumentException('Studio strings must be valid UTF-8 at '.$path.'.');}
			if(str_contains(strtolower($value),'<?php')||preg_match('/<\/?[a-z!][^>]*>/i',$value)===1){throw new \InvalidArgumentException('Studio strings cannot contain raw markup or PHP at '.$path.'.');}
			if(PanelSensitiveDataSanitizer::sanitize($value,['max_string_bytes'=>self::MAX_STRING_BYTES])!==$value){throw new \InvalidArgumentException('Studio strings cannot contain embedded credential material at '.$path.'.');}
			return$value;
		}
		if(!is_array($value)){return$value;}
		if(count($value)>self::MAX_PROPERTIES){throw new \LengthException('Studio property collections exceed '.self::MAX_PROPERTIES.' items at '.$path.'.');}
		$clean=[];
		foreach($value as$key=>$item){
			if(is_string($key)){if(preg_match('/^[a-z][a-z0-9_.-]{0,63}$/',$key)!==1||self::forbiddenKey(str_replace(['.','-'],'_',$key))){throw new \InvalidArgumentException('Studio nested property keys are forbidden at '.$path.'.');}}
			$clean[$key]=self::jsonValue($item,$path.'.'.(string)$key,$depth+1);
		}
		if(!array_is_list($clean)){ksort($clean,SORT_STRING);}
		return$clean;
	}
	private static function canonicalJson(array $value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
}
