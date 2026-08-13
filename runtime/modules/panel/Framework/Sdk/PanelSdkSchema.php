<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Closed JSON Schema subset used for generated clients and compatibility analysis. */
final class PanelSdkSchema implements \JsonSerializable {
	private const TYPES=['null','boolean','integer','number','string','array','object'];
	private const FORMATS=['date','date-time','email','uri','uuid'];
	private readonly array $definition;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $definition */
	private function __construct(array $definition){$this->definition=self::normalize($definition,0);$this->fingerprint=PanelSdkGuard::fingerprint($this->definition);}

	/** @param array<string,mixed> $definition */
	public static function fromArray(array $definition):self{return new self($definition);}
	public static function any(?string $description=null):self{return new self($description===null?[]:['description'=>$description]);}
	public static function null(?string $description=null):self{return self::scalar('null',[], $description);}
	public static function boolean(?string $description=null):self{return self::scalar('boolean',[], $description);}
	public static function integer(array $constraints=[],?string $description=null):self{return self::scalar('integer',$constraints,$description);}
	public static function number(array $constraints=[],?string $description=null):self{return self::scalar('number',$constraints,$description);}
	public static function string(array $constraints=[],?string $description=null):self{return self::scalar('string',$constraints,$description);}

	/** @param list<mixed> $values */
	public static function enum(array $values,?string $description=null):self {
		if($values===[]||!array_is_list($values)||count($values)>256){throw new \InvalidArgumentException('Panel SDK enum values must be a non-empty bounded list.');}
		$type=self::valueType($values[0]);foreach($values as$value){if(self::valueType($value)!==$type){throw new \InvalidArgumentException('Panel SDK enum values must share one scalar type.');}}
		$definition=['type'=>$type,'enum'=>$values];if($description!==null){$definition['description']=$description;}return new self($definition);
	}

	public static function arrayOf(self $items,array $constraints=[],?string $description=null):self {
		$definition=['type'=>'array','items'=>$items->definition()]+$constraints;if($description!==null){$definition['description']=$description;}return new self($definition);
	}

	/** @param array<string,self> $properties @param list<string> $required */
	public static function object(array $properties=[],array $required=[],bool|self $additionalProperties=false,?string $description=null):self {
		$mapped=[];foreach($properties as$name=>$schema){if(!is_string($name)||!$schema instanceof self){throw new \InvalidArgumentException('Panel SDK object properties must map names to schemas.');}$mapped[$name]=$schema->definition();}
		$definition=['type'=>'object','properties'=>$mapped,'required'=>$required,'additionalProperties'=>$additionalProperties instanceof self?$additionalProperties->definition():$additionalProperties];if($description!==null){$definition['description']=$description;}return new self($definition);
	}

	/** @param list<self> $schemas */
	public static function union(array $schemas,?string $description=null):self {
		if(count($schemas)<2||count($schemas)>16||!array_is_list($schemas)){throw new \InvalidArgumentException('Panel SDK unions require between two and sixteen schemas.');}
		$items=[];foreach($schemas as$schema){if(!$schema instanceof self){throw new \InvalidArgumentException('Panel SDK union members must be schemas.');}$items[]=$schema->definition();}
		$definition=['anyOf'=>$items];if($description!==null){$definition['description']=$description;}return new self($definition);
	}

	public static function nullable(self $schema):self{return self::union([$schema,self::null()]);}

	/** @return array<string,mixed> */public function definition():array{return$this->definition;}
	public function fingerprint():string{return$this->fingerprint;}
	public function isObject():bool{return($this->definition['type']??null)==='object';}
	public function isAny():bool{return!isset($this->definition['type'],$this->definition['anyOf'],$this->definition['enum']);}

	/** @return list<array{path:string,code:string,message:string}> */
	public function validate(mixed $value):array {$errors=[];$nodes=0;self::validateValue($value,$this->definition,'$',0,$nodes,$errors);return$errors;}
	public function accepts(mixed $value):bool{return$this->validate($value)===[];}
	public function assertValid(mixed $value,string $label='Panel SDK value'):void {$errors=$this->validate($value);if($errors!==[]){$first=$errors[0];throw new \UnexpectedValueException($label.' failed '.$first['code'].' at '.$first['path'].': '.$first['message']);}}
	public function jsonSerialize():array{return$this->definition;}

	private static function scalar(string $type,array $constraints,?string $description):self {$definition=['type'=>$type]+$constraints;if($description!==null){$definition['description']=$description;}return new self($definition);}

	/** @param array<string,mixed> $definition @return array<string,mixed> */
	private static function normalize(array $definition,int $depth):array {
		if($depth>PanelSdkGuard::MAX_SCHEMA_DEPTH){throw new \LengthException('Panel SDK schema exceeds its depth bound.');}
		if(array_is_list($definition)&&$definition!==[]){throw new \InvalidArgumentException('Panel SDK schemas must be objects.');}
		$allowed=['type','description','enum','anyOf','properties','required','additionalProperties','items','minItems','maxItems','uniqueItems','minLength','maxLength','pattern','format','minimum','maximum'];
		foreach(array_keys($definition)as$key){if(!is_string($key)||!in_array($key,$allowed,true)){throw new \InvalidArgumentException("Unsupported Panel SDK schema keyword '{$key}'.");}}
		$out=[];
		if(isset($definition['description'])){$out['description']=PanelSdkGuard::label((string)$definition['description'],'SDK schema description',1000);}
		if(isset($definition['anyOf'])){
			$exclusive=array_diff(array_keys($definition),['description','anyOf']);if($exclusive!==[]){throw new \InvalidArgumentException('Panel SDK union schemas cannot mix anyOf with structural keywords.');}
			$members=$definition['anyOf'];if(!is_array($members)||!array_is_list($members)||count($members)<2||count($members)>16){throw new \InvalidArgumentException('Panel SDK anyOf must contain between two and sixteen schemas.');}
			$seen=[];$out['anyOf']=[];foreach($members as$member){if(!is_array($member)){throw new \InvalidArgumentException('Panel SDK anyOf members must be schemas.');}$normalized=self::normalize($member,$depth+1);$digest=PanelSdkGuard::fingerprint($normalized);if(isset($seen[$digest])){throw new \InvalidArgumentException('Panel SDK anyOf members must be distinct.');}$seen[$digest]=true;$out['anyOf'][]=$normalized;}return$out;
		}
		$type=$definition['type']??null;
		if($type===null){if(array_diff(array_keys($definition),['description'])!==[]){throw new \InvalidArgumentException('Panel SDK unconstrained schemas only accept a description.');}return$out;}
		if(!is_string($type)||!in_array($type,self::TYPES,true)){throw new \InvalidArgumentException('Panel SDK schema type is invalid.');}$out=['type'=>$type]+$out;
		if(isset($definition['enum'])){$values=$definition['enum'];if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>256){throw new \InvalidArgumentException('Panel SDK enum is invalid.');}$seen=[];foreach($values as$value){if(!self::matchesPrimitiveType($value,$type)){throw new \InvalidArgumentException('Panel SDK enum value does not match its schema type.');}$key=PanelSdkGuard::fingerprint($value);if(isset($seen[$key])){throw new \InvalidArgumentException('Panel SDK enum values must be unique.');}$seen[$key]=true;}$out['enum']=$values;}
		if($type==='string'){
			self::copyIntegerBound($definition,$out,'minLength',0,1048576);self::copyIntegerBound($definition,$out,'maxLength',0,1048576);self::assertOrdered($out,'minLength','maxLength');
			if(isset($definition['pattern'])){$pattern=PanelSdkGuard::label((string)$definition['pattern'],'SDK schema pattern',512);if(@preg_match('~'.$pattern.'~u','')===false){throw new \InvalidArgumentException('Panel SDK schema pattern is invalid.');}$out['pattern']=$pattern;}
			if(isset($definition['format'])){$format=(string)$definition['format'];if(!in_array($format,self::FORMATS,true)){throw new \InvalidArgumentException('Panel SDK schema format is unsupported.');}$out['format']=$format;}
		}
		if($type==='integer'||$type==='number'){
			foreach(['minimum','maximum']as$key){if(array_key_exists($key,$definition)){if(!is_int($definition[$key])&&!is_float($definition[$key])||is_float($definition[$key])&&!is_finite($definition[$key])){throw new \InvalidArgumentException('Panel SDK numeric schema bound is invalid.');}$out[$key]=$definition[$key];}}self::assertOrdered($out,'minimum','maximum');
		}
		if($type==='array'){
			if(!isset($definition['items'])||!is_array($definition['items'])){throw new \InvalidArgumentException('Panel SDK array schemas require an item schema.');}$out['items']=self::normalize($definition['items'],$depth+1);
			self::copyIntegerBound($definition,$out,'minItems',0,1000000);self::copyIntegerBound($definition,$out,'maxItems',0,1000000);self::assertOrdered($out,'minItems','maxItems');if(isset($definition['uniqueItems'])){if(!is_bool($definition['uniqueItems'])){throw new \InvalidArgumentException('Panel SDK uniqueItems must be boolean.');}$out['uniqueItems']=$definition['uniqueItems'];}
		}
		if($type==='object'){
			$properties=$definition['properties']??[];if(!is_array($properties)||(array_is_list($properties)&&$properties!==[])||count($properties)>256){throw new \InvalidArgumentException('Panel SDK object properties are invalid.');}$out['properties']=[];
			foreach($properties as$name=>$schema){if(!is_string($name)||preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D',$name)!==1||!is_array($schema)){throw new \InvalidArgumentException('Panel SDK object property is invalid.');}$out['properties'][$name]=self::normalize($schema,$depth+1);}ksort($out['properties'],SORT_STRING);
			$required=$definition['required']??[];if(!is_array($required)||!array_is_list($required)||count($required)>256){throw new \InvalidArgumentException('Panel SDK required properties are invalid.');}$required=array_values(array_unique($required));foreach($required as$name){if(!is_string($name)||!array_key_exists($name,$out['properties'])){throw new \InvalidArgumentException('Panel SDK required properties must exist in properties.');}}sort($required,SORT_STRING);$out['required']=$required;
			$additional=$definition['additionalProperties']??false;if(is_bool($additional)){$out['additionalProperties']=$additional;}elseif(is_array($additional)){$out['additionalProperties']=self::normalize($additional,$depth+1);}else{throw new \InvalidArgumentException('Panel SDK additionalProperties must be boolean or a schema.');}
		}
		$validKeys=match($type){
			'string'=>['type','description','enum','minLength','maxLength','pattern','format'],
			'integer','number'=>['type','description','enum','minimum','maximum'],
			'array'=>['type','description','enum','items','minItems','maxItems','uniqueItems'],
			'object'=>['type','description','enum','properties','required','additionalProperties'],
			default=>['type','description','enum'],
		};
		$unsupported=array_diff(array_keys($definition),$validKeys);if($unsupported!==[]){throw new \InvalidArgumentException('Panel SDK schema contains keywords unsupported for its type.');}
		PanelSdkGuard::assertJson($out);return$out;
	}

	/** @param array<string,mixed> $source @param array<string,mixed> $target */
	private static function copyIntegerBound(array $source,array &$target,string $key,int $min,int $max):void {if(!array_key_exists($key,$source)){return;}$value=$source[$key];if(!is_int($value)||$value<$min||$value>$max){throw new \InvalidArgumentException("Panel SDK schema {$key} is invalid.");}$target[$key]=$value;}
	/** @param array<string,mixed> $value */private static function assertOrdered(array $value,string $min,string $max):void {if(isset($value[$min],$value[$max])&&$value[$min]>$value[$max]){throw new \InvalidArgumentException('Panel SDK schema minimum cannot exceed maximum.');}}

	private static function valueType(mixed $value):string {if($value===null){return'null';}if(is_bool($value)){return'boolean';}if(is_int($value)){return'integer';}if(is_float($value)&&is_finite($value)){return'number';}if(is_string($value)){return'string';}throw new \InvalidArgumentException('Panel SDK enums accept scalar JSON values only.');}
	private static function matchesPrimitiveType(mixed $value,string $type):bool{return match($type){'null'=>$value===null,'boolean'=>is_bool($value),'integer'=>is_int($value),'number'=>(is_int($value)||is_float($value))&&(!is_float($value)||is_finite($value)),'string'=>is_string($value),'array'=>is_array($value)&&array_is_list($value),'object'=>is_array($value)&&(!array_is_list($value)||$value===[]),default=>false};}

	/** @param array<string,mixed> $schema @param list<array{path:string,code:string,message:string}> $errors */
	private static function validateValue(mixed $value,array $schema,string $path,int $depth,int &$nodes,array &$errors):void {
		if(count($errors)>=100){return;}if($depth>PanelSdkGuard::MAX_SCHEMA_DEPTH||++$nodes>PanelSdkGuard::MAX_SCHEMA_NODES){$errors[]=['path'=>$path,'code'=>'value_too_complex','message'=>'Value exceeds SDK validation bounds.'];return;}
		if(isset($schema['anyOf'])){foreach($schema['anyOf']as$member){$candidate=[];$candidateNodes=$nodes;self::validateValue($value,$member,$path,$depth+1,$candidateNodes,$candidate);if($candidate===[]){$nodes=$candidateNodes;return;}}$errors[]=['path'=>$path,'code'=>'union_mismatch','message'=>'Value does not match any allowed schema.'];return;}
		$type=$schema['type']??null;if($type===null){try{PanelSdkGuard::assertJson($value);}catch(\Throwable){$errors[]=['path'=>$path,'code'=>'json_invalid','message'=>'Value is not bounded JSON.'];}return;}
		if(!self::matchesPrimitiveType($value,$type)){$errors[]=['path'=>$path,'code'=>'type_mismatch','message'=>"Expected {$type}."];return;}
		if(isset($schema['enum'])){$matched=false;foreach($schema['enum']as$allowed){if($allowed===$value){$matched=true;break;}}if(!$matched){$errors[]=['path'=>$path,'code'=>'enum_mismatch','message'=>'Value is not in the allowed enumeration.'];return;}}
		if($type==='string'){self::validateString($value,$schema,$path,$errors);return;}
		if($type==='integer'||$type==='number'){if(isset($schema['minimum'])&&$value<$schema['minimum']){$errors[]=['path'=>$path,'code'=>'minimum','message'=>'Number is below the minimum.'];}if(isset($schema['maximum'])&&$value>$schema['maximum']){$errors[]=['path'=>$path,'code'=>'maximum','message'=>'Number exceeds the maximum.'];}return;}
		if($type==='array'){self::validateArray($value,$schema,$path,$depth,$nodes,$errors);return;}
		if($type==='object'){self::validateObject($value,$schema,$path,$depth,$nodes,$errors);}
	}

	/** @param array<string,mixed> $schema @param list<array{path:string,code:string,message:string}> $errors */
	private static function validateString(string $value,array $schema,string $path,array &$errors):void {
		$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);if(isset($schema['minLength'])&&$length<$schema['minLength']){$errors[]=['path'=>$path,'code'=>'min_length','message'=>'String is shorter than allowed.'];}if(isset($schema['maxLength'])&&$length>$schema['maxLength']){$errors[]=['path'=>$path,'code'=>'max_length','message'=>'String is longer than allowed.'];}
		if(isset($schema['pattern'])&&preg_match('~'.$schema['pattern'].'~u',$value)!==1){$errors[]=['path'=>$path,'code'=>'pattern','message'=>'String does not match the required pattern.'];}
		if(!isset($schema['format'])){return;}$valid=match($schema['format']){'email'=>filter_var($value,FILTER_VALIDATE_EMAIL)!==false,'uri'=>filter_var($value,FILTER_VALIDATE_URL)!==false,'uuid'=>preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$value)===1,'date'=>self::validDate($value),'date-time'=>self::validDateTime($value),default=>true};if(!$valid){$errors[]=['path'=>$path,'code'=>'format','message'=>'String does not match the required format.'];}
	}

	private static function validDate(string $value):bool {if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$match)!==1){return false;}return checkdate((int)$match[2],(int)$match[3],(int)$match[1]);}
	private static function validDateTime(string $value):bool {if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)!==1){return false;}try{new \DateTimeImmutable($value);return true;}catch(\Throwable){return false;}}

	/** @param list<mixed> $value @param array<string,mixed> $schema @param list<array{path:string,code:string,message:string}> $errors */
	private static function validateArray(array $value,array $schema,string $path,int $depth,int &$nodes,array &$errors):void {
		$count=count($value);if(isset($schema['minItems'])&&$count<$schema['minItems']){$errors[]=['path'=>$path,'code'=>'min_items','message'=>'Array contains too few items.'];}if(isset($schema['maxItems'])&&$count>$schema['maxItems']){$errors[]=['path'=>$path,'code'=>'max_items','message'=>'Array contains too many items.'];}
		if(($schema['uniqueItems']??false)===true){$seen=[];foreach($value as$item){$key=PanelSdkGuard::fingerprint($item);if(isset($seen[$key])){$errors[]=['path'=>$path,'code'=>'unique_items','message'=>'Array items must be unique.'];break;}$seen[$key]=true;}}
		foreach($value as$index=>$item){self::validateValue($item,$schema['items'],$path.'['.$index.']',$depth+1,$nodes,$errors);if(count($errors)>=100){return;}}
	}

	/** @param array<string|int,mixed> $value @param array<string,mixed> $schema @param list<array{path:string,code:string,message:string}> $errors */
	private static function validateObject(array $value,array $schema,string $path,int $depth,int &$nodes,array &$errors):void {
		foreach($schema['required']as$name){if(!array_key_exists($name,$value)){$errors[]=['path'=>$path.'.'.$name,'code'=>'required','message'=>'Required property is missing.'];}}
		foreach($value as$name=>$item){$name=(string)$name;if(isset($schema['properties'][$name])){self::validateValue($item,$schema['properties'][$name],$path.'.'.$name,$depth+1,$nodes,$errors);continue;}$additional=$schema['additionalProperties'];if($additional===false){$errors[]=['path'=>$path.'.'.$name,'code'=>'additional_property','message'=>'Additional property is not allowed.'];}elseif(is_array($additional)){self::validateValue($item,$additional,$path.'.'.$name,$depth+1,$nodes,$errors);}if(count($errors)>=100){return;}}
	}
}
