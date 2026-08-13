<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable, data-only property contract for one trusted Studio component kind. */
final class PanelStudioPropertySchema implements \JsonSerializable {
	public const TYPES=['string','boolean','integer','number','scalar','string_list','number_list','scalar_map'];
	private readonly bool $hasDefault;
	private readonly mixed $default;
	/** @var list<mixed> */ private readonly array $enum;
	private readonly ?float $minimum;
	private readonly ?float $maximum;
	private readonly ?int $minimumLength;
	private readonly ?int $maximumLength;
	private readonly ?string $pattern;
	private readonly bool $requiredFlag;
	private readonly bool $nullableFlag;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly string $name,private readonly string $type,array $options=[]){
		if(preg_match('/^[a-z][a-z0-9_]{0,63}$/',$name)!==1||PanelSensitiveDataSanitizer::isSensitiveKey($name)||preg_match('/(?:^|_)(?:php|class|callable|closure|raw_html|html|script|template|view)(?:_|$)/',$name)===1){throw new \InvalidArgumentException('Studio property schema names must be safe non-sensitive identifiers.');}
		if(!in_array($type,self::TYPES,true)){throw new \InvalidArgumentException('Studio property schema types must be explicit supported JSON types.');}
		$extra=array_diff(array_keys($options),['required','nullable','default','enum','min','max','min_length','max_length','pattern']);if($extra!==[]){throw new \InvalidArgumentException('Studio property schema options contain unsupported keys.');}
		foreach(['required','nullable']as$flag){if(array_key_exists($flag,$options)&&!is_bool($options[$flag])){throw new \InvalidArgumentException("Studio property schema {$flag} must be boolean.");}}$this->requiredFlag=$options['required']??false;$this->nullableFlag=$options['nullable']??false;
		$this->hasDefault=array_key_exists('default',$options);$this->default=$options['default']??null;
		if($this->requiredFlag&&$this->hasDefault){throw new \InvalidArgumentException('Required Studio property schemas cannot also synthesize a default.');}
		$enum=$options['enum']??[];if(!is_array($enum)||!array_is_list($enum)||count($enum)>PanelStudioDefinition::MAX_PROPERTIES){throw new \InvalidArgumentException('Studio property schema enums must be bounded lists.');}$seen=[];foreach($enum as$value){$fingerprint=self::canonical($value);if(isset($seen[$fingerprint])){throw new \InvalidArgumentException('Studio property schema enum values must be unique.');}$seen[$fingerprint]=true;}$this->enum=$enum;
		$numericBounds=array_key_exists('min',$options)||array_key_exists('max',$options);$lengthBounds=array_key_exists('min_length',$options)||array_key_exists('max_length',$options);if($numericBounds&&!in_array($type,['integer','number'],true)){throw new \InvalidArgumentException('Studio numeric bounds require an integer or number property schema.');}if($lengthBounds&&!in_array($type,['string','string_list','number_list','scalar_map'],true)){throw new \InvalidArgumentException('Studio length bounds require a string or collection property schema.');}if(array_key_exists('pattern',$options)&&$type!=='string'){throw new \InvalidArgumentException('Studio patterns require a string property schema.');}
		$this->minimum=array_key_exists('min',$options)?self::finite($options['min'],'minimum'):null;$this->maximum=array_key_exists('max',$options)?self::finite($options['max'],'maximum'):null;
		$this->minimumLength=array_key_exists('min_length',$options)?self::length($options['min_length'],'minimum length'):null;$this->maximumLength=array_key_exists('max_length',$options)?self::length($options['max_length'],'maximum length'):null;
		$this->pattern=array_key_exists('pattern',$options)?self::pattern($options['pattern']):null;
		if($this->minimum!==null&&$this->maximum!==null&&$this->minimum>$this->maximum){throw new \InvalidArgumentException('Studio property schema numeric bounds are inverted.');}
		if($this->minimumLength!==null&&$this->maximumLength!==null&&$this->minimumLength>$this->maximumLength){throw new \InvalidArgumentException('Studio property schema length bounds are inverted.');}
		foreach($this->enum as$value){if($this->typeError($value)!==null){throw new \InvalidArgumentException('Studio property schema enum values must match their declared type.');}}
		if($this->hasDefault&&$this->diagnostic($this->default,'schema.default')!==null){throw new \InvalidArgumentException('Studio property schema defaults must satisfy their complete contract.');}
		if(strlen(json_encode($this->manifest(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR))>PanelStudioDefinition::MAX_JSON_BYTES){throw new \LengthException('Studio property schema exceeds its serialized budget.');}
	}
	public static function make(string $name,string $type='string',array $options=[]):self{return new self($name,$type,$options);}
	public function name():string{return$this->name;}
	public function type():string{return$this->type;}
	public function required():bool{return$this->requiredFlag;}
	public function nullable():bool{return$this->nullableFlag;}
	public function hasDefault():bool{return$this->hasDefault;}
	public function defaultValue():mixed{if(!$this->hasDefault){throw new \LogicException('Studio property schema does not define a default.');}return$this->default;}
	public function diagnostic(mixed $value,string $path):?PanelStudioDiagnostic{
		$error=$this->typeError($value);if($error!==null){return new PanelStudioDiagnostic($path,'invalid_property_type',$error);}
		if($value===null){return null;}
		if($this->enum!==[]&&!self::contains($this->enum,$value)){return new PanelStudioDiagnostic($path,'property_not_in_enum','Studio property value is not one of the trusted enum values.');}
		if((is_int($value)||is_float($value))&&(($this->minimum!==null&&(float)$value<$this->minimum)||($this->maximum!==null&&(float)$value>$this->maximum))){return new PanelStudioDiagnostic($path,'property_out_of_bounds','Studio property value is outside its trusted numeric bounds.');}
		$length=is_string($value)?strlen($value):(is_array($value)?count($value):null);if($length!==null&&(($this->minimumLength!==null&&$length<$this->minimumLength)||($this->maximumLength!==null&&$length>$this->maximumLength))){return new PanelStudioDiagnostic($path,'property_out_of_bounds','Studio property value is outside its trusted length bounds.');}
		if(is_string($value)&&$this->pattern!==null&&preg_match($this->pattern,$value)!==1){return new PanelStudioDiagnostic($path,'property_pattern_mismatch','Studio property value does not match its trusted pattern.');}
		return null;
	}
	public function manifest():array{
		$value=['name'=>$this->name,'type'=>$this->type,'required'=>$this->required(),'nullable'=>$this->nullable(),'enum'=>$this->enum,'bounds'=>['min'=>$this->minimum,'max'=>$this->maximum,'min_length'=>$this->minimumLength,'max_length'=>$this->maximumLength],'pattern'=>$this->pattern,'has_default'=>$this->hasDefault];if($this->hasDefault){$value['default']=$this->default;}return$value;
	}
	public function jsonSerialize():array{return$this->manifest();}
	private function typeError(mixed $value):?string{
		if($value===null){return$this->nullable()?null:"Studio property '{$this->name}' cannot be null.";}
		$valid=match($this->type){'string'=>is_string($value)&&self::safeString($value),'boolean'=>is_bool($value),'integer'=>is_int($value),'number'=>(is_int($value)||is_float($value))&&(!is_float($value)||is_finite($value)),'scalar'=>(is_string($value)&&self::safeString($value))||is_bool($value)||is_int($value)||(is_float($value)&&is_finite($value)),'string_list'=>is_array($value)&&array_is_list($value)&&count($value)<=PanelStudioDefinition::MAX_PROPERTIES&&self::every($value,static fn(mixed $item):bool=>is_string($item)&&self::safeString($item)),'number_list'=>is_array($value)&&array_is_list($value)&&count($value)<=PanelStudioDefinition::MAX_PROPERTIES&&self::every($value,static fn(mixed $item):bool=>(is_int($item)||is_float($item))&&(!is_float($item)||is_finite($item))),'scalar_map'=>is_array($value)&&($value===[]||!array_is_list($value))&&count($value)<=PanelStudioDefinition::MAX_PROPERTIES&&self::safeMap($value),default=>false};
		return$valid?null:"Studio property '{$this->name}' must be {$this->type}.";
	}
	private static function safeMap(array $value):bool{foreach($value as$key=>$item){$normalized=is_string($key)?strtolower(str_replace(['.','-',':'],'_',$key)):'';if(!is_string($key)||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/',$key)!==1||PanelSensitiveDataSanitizer::isSensitiveKey($normalized)||preg_match('/(?:^|_)(?:php|class|callable|closure|raw_html|html|script|template|view)(?:_|$)/',$normalized)===1||(!is_null($item)&&!is_string($item)&&!is_bool($item)&&!is_int($item)&&!(is_float($item)&&is_finite($item)))||(is_string($item)&&!self::safeString($item))){return false;}}return true;}
	private static function safeString(string $value):bool{return strlen($value)<=PanelStudioDefinition::MAX_STRING_BYTES&&preg_match('//u',$value)===1&&!str_contains(strtolower($value),'<?php')&&preg_match('/<\/?[a-z!][^>]*>/i',$value)!==1&&PanelSensitiveDataSanitizer::sanitize($value,['max_string_bytes'=>PanelStudioDefinition::MAX_STRING_BYTES])===$value;}
	private static function every(array $values,callable $predicate):bool{foreach($values as$value){if(!$predicate($value)){return false;}}return true;}
	private static function contains(array $values,mixed $needle):bool{$encoded=self::canonical($needle);foreach($values as$value){if(hash_equals(self::canonical($value),$encoded)){return true;}}return false;}
	private static function canonical(mixed $value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function finite(mixed $value,string $label):float{if((!is_int($value)&&!is_float($value))||(is_float($value)&&!is_finite($value))){throw new \InvalidArgumentException("Studio property schema {$label} must be finite.");}return(float)$value;}
	private static function length(mixed $value,string $label):int{if(!is_int($value)||$value<0||$value>PanelStudioDefinition::MAX_STRING_BYTES){throw new \InvalidArgumentException("Studio property schema {$label} is invalid.");}return$value;}
	private static function pattern(mixed $value):string{if(!is_string($value)||strlen($value)>256||@preg_match($value,'')===false){throw new \InvalidArgumentException('Studio property schema patterns must be valid bounded regular expressions.');}return$value;}
}
