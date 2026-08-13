<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** One host-bound JSON operation consumed by generated Panel clients. */
final class PanelSdkOperation implements \JsonSerializable {
	private const METHODS=['GET','POST','PUT','PATCH','DELETE'];
	private readonly string $name;
	private readonly string $method;
	private readonly string $path;
	private readonly PanelSdkSchema $pathSchema;
	private readonly PanelSdkSchema $querySchema;
	private readonly ?PanelSdkSchema $bodySchema;
	private readonly PanelSdkSchema $responseSchema;
	/** @var array<string,PanelSdkSchema> */private readonly array $errors;
	/** @var list<string> */private readonly array $scopes;
	/** @var list<string> */private readonly array $tags;
	private readonly string $summary;
	private readonly bool $idempotent;
	private readonly bool $deprecated;
	private readonly string $fingerprint;

	/**
	 * @param array{path?:PanelSdkSchema,query?:PanelSdkSchema,body?:PanelSdkSchema|null,errors?:array<int|string,PanelSdkSchema>,scopes?:list<string>,tags?:list<string>,summary?:string,idempotent?:bool,deprecated?:bool} $options
	 */
	private function __construct(string $name,string $method,string $path,PanelSdkSchema $response,array $options){
		$unknown=array_diff(array_keys($options),['path','query','body','errors','scopes','tags','summary','idempotent','deprecated']);if($unknown!==[]){throw new \InvalidArgumentException('Panel SDK operation options contain unsupported keys.');}
		$this->name=PanelSdkGuard::identifier($name,'SDK operation name');$this->method=strtoupper(trim($method));if(!in_array($this->method,self::METHODS,true)){throw new \InvalidArgumentException('Panel SDK operation method is unsupported.');}$this->path=PanelSdkGuard::path($path);
		$parameters=PanelSdkGuard::pathParameters($this->path);$defaultProperties=[];foreach($parameters as$parameter){$defaultProperties[$parameter]=PanelSdkSchema::string(['minLength'=>1,'maxLength'=>512]);}
		$this->pathSchema=$options['path']??PanelSdkSchema::object($defaultProperties,$parameters);$this->querySchema=$options['query']??PanelSdkSchema::object([],[],true);$this->bodySchema=$options['body']??null;$this->responseSchema=$response;
		if(!$this->pathSchema instanceof PanelSdkSchema||!$this->pathSchema->isObject()||!$this->querySchema instanceof PanelSdkSchema||!$this->querySchema->isObject()||($this->bodySchema!==null&&!$this->bodySchema instanceof PanelSdkSchema)){throw new \InvalidArgumentException('Panel SDK operation path and query contracts must be object schemas and body must be a schema or null.');}
		$this->assertPathContract($parameters);
		if(in_array($this->method,['GET','DELETE'],true)&&$this->bodySchema!==null){throw new \InvalidArgumentException('Panel SDK GET and DELETE operations cannot declare JSON bodies.');}
		$errors=[];foreach((array)($options['errors']??[])as$status=>$schema){$key=(string)$status;if($key!=='default'&&(!ctype_digit($key)||(int)$key<400||(int)$key>599)){throw new \InvalidArgumentException('Panel SDK error response status is invalid.');}if(!$schema instanceof PanelSdkSchema){throw new \InvalidArgumentException('Panel SDK error responses must be schemas.');}$errors[$key]=$schema;}ksort($errors,SORT_NATURAL);$this->errors=$errors;
		$this->scopes=self::tokens((array)($options['scopes']??[]),'security scope',128);$this->tags=self::tokens((array)($options['tags']??[]),'tag',32);
		$this->summary=PanelSdkGuard::label((string)($options['summary']??str_replace('_',' ',$this->name)),'SDK operation summary',500);
		$this->idempotent=array_key_exists('idempotent',$options)?(bool)$options['idempotent']:in_array($this->method,['GET','PUT','DELETE'],true);$this->deprecated=(bool)($options['deprecated']??false);
		$this->fingerprint=PanelSdkGuard::fingerprint($this->contract());
	}

	/** @param array<string,mixed> $options */public static function make(string $name,string $method,string $path,PanelSdkSchema $response,array $options=[]):self{return new self($name,$method,$path,$response,$options);}
	/** @param array<string,mixed> $options */public static function get(string $name,string $path,PanelSdkSchema $response,array $options=[]):self{return new self($name,'GET',$path,$response,$options);}
	/** @param array<string,mixed> $options */public static function post(string $name,string $path,PanelSdkSchema $response,array $options=[]):self{return new self($name,'POST',$path,$response,$options);}
	/** @param array<string,mixed> $options */public static function patch(string $name,string $path,PanelSdkSchema $response,array $options=[]):self{return new self($name,'PATCH',$path,$response,$options);}
	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self {
		$request=$payload['request']??null;$security=$payload['security']??null;
		if(!is_string($payload['name']??null)||!is_string($payload['method']??null)||!is_string($payload['path']??null)||!is_array($request)||!is_array($request['path']??null)||!is_array($request['query']??null)||(!is_array($request['body']??null)&&($request['body']??null)!==null)||!is_array($payload['response']??null)||!is_array($payload['errors']??null)||!is_array($security)||!is_array($security['scopes']??null)||!is_array($payload['tags']??null)||!is_string($payload['summary']??null)||!is_bool($payload['idempotent']??null)||!is_bool($payload['deprecated']??null)){throw new \UnexpectedValueException('Panel SDK operation payload is malformed.');}
		$errors=[];foreach($payload['errors']as$status=>$schema){if(!is_array($schema)){throw new \UnexpectedValueException('Panel SDK operation error schema is malformed.');}$errors[$status]=PanelSdkSchema::fromArray($schema);}
		$self=new self($payload['name'],$payload['method'],$payload['path'],PanelSdkSchema::fromArray($payload['response']),['path'=>PanelSdkSchema::fromArray($request['path']),'query'=>PanelSdkSchema::fromArray($request['query']),'body'=>is_array($request['body'])?PanelSdkSchema::fromArray($request['body']):null,'errors'=>$errors,'scopes'=>$security['scopes'],'tags'=>$payload['tags'],'summary'=>$payload['summary'],'idempotent'=>$payload['idempotent'],'deprecated'=>$payload['deprecated']]);
		if(isset($payload['fingerprint'])&&(!is_string($payload['fingerprint'])||!hash_equals($self->fingerprint(),$payload['fingerprint']))){throw new \UnexpectedValueException('Panel SDK operation fingerprint does not verify.');}
		return$self;
	}

	public function name():string{return$this->name;}public function method():string{return$this->method;}public function path():string{return$this->path;}
	/** @return list<string> */public function pathParameters():array{return PanelSdkGuard::pathParameters($this->path);}
	public function pathSchema():PanelSdkSchema{return$this->pathSchema;}public function querySchema():PanelSdkSchema{return$this->querySchema;}public function bodySchema():?PanelSdkSchema{return$this->bodySchema;}public function responseSchema():PanelSdkSchema{return$this->responseSchema;}
	/** @return array<string,PanelSdkSchema> */public function errors():array{return$this->errors;}/** @return list<string> */public function scopes():array{return$this->scopes;}/** @return list<string> */public function tags():array{return$this->tags;}
	public function summary():string{return$this->summary;}public function idempotent():bool{return$this->idempotent;}public function deprecated():bool{return$this->deprecated;}public function fingerprint():string{return$this->fingerprint;}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{return['type'=>'panel_sdk_operation','version'=>1]+$this->contract()+['fingerprint'=>$this->fingerprint];}

	/** @return array<string,mixed> */
	private function contract():array {
		$errors=[];foreach($this->errors as$status=>$schema){$errors[$status]=$schema->definition();}
		return['name'=>$this->name,'method'=>$this->method,'path'=>$this->path,'path_parameters'=>$this->pathParameters(),'request'=>['path'=>$this->pathSchema->definition(),'query'=>$this->querySchema->definition(),'body'=>$this->bodySchema?->definition()],'response'=>$this->responseSchema->definition(),'errors'=>$errors,'security'=>['scopes'=>$this->scopes,'credentials_embedded'=>false],'tags'=>$this->tags,'summary'=>$this->summary,'idempotent'=>$this->idempotent,'deprecated'=>$this->deprecated];
	}

	/** @param list<string> $parameters */
	private function assertPathContract(array $parameters):void {
		$definition=$this->pathSchema->definition();$properties=array_keys((array)($definition['properties']??[]));$required=(array)($definition['required']??[]);sort($properties,SORT_STRING);sort($required,SORT_STRING);$expected=$parameters;sort($expected,SORT_STRING);
		if($properties!==$expected||$required!==$expected||($definition['additionalProperties']??true)!==false){throw new \InvalidArgumentException('Panel SDK path schema must contain exactly every required path parameter and reject extras.');}
	}

	/** @param list<mixed> $values @return list<string> */
	private static function tokens(array $values,string $label,int $max):array {
		if(!array_is_list($values)||count($values)>$max){throw new \InvalidArgumentException("Panel SDK {$label} list is invalid.");}$out=[];foreach($values as$value){if(!is_string($value))throw new \InvalidArgumentException("Panel SDK {$label} is invalid.");$value=trim($value);if($value===''||strlen($value)>160||preg_match('/^[A-Za-z0-9][A-Za-z0-9*_.:-]*$/D',$value)!==1){throw new \InvalidArgumentException("Panel SDK {$label} is invalid.");}$out[$value]=true;}$out=array_keys($out);sort($out,SORT_STRING);return$out;
	}
}
