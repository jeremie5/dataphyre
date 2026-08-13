<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/**
 * Bounded, secret-redacted records supplied to the Studio visual runtime.
 *
 * Dataset values remain available to the in-process renderer, but serialization
 * publishes only counts and a digest. Objects, resources, callbacks, malformed
 * UTF-8, non-finite numbers, and oversized values are rejected before rendering.
 */
final class PanelStudioVisualDataset implements \JsonSerializable {
	public const MAX_RECORDS=50;
	public const MAX_FIELDS=256;
	public const MAX_ITEMS=4096;
	public const MAX_DEPTH=8;
	public const MAX_STRING_BYTES=4096;

	/** @var list<array<string,mixed>> */
	private readonly array $records;
	/** @var array<string,mixed> */
	private readonly array $record;
	private readonly string $digest;

	/**
	 * @param list<array<string,mixed>> $records
	 * @param array<string,mixed>|null $record
	 */
	public function __construct(array $records=[],?array $record=null,private readonly bool $synthetic=false){
		if(!array_is_list($records)||count($records)>self::MAX_RECORDS){
			throw new \LengthException('Studio visual datasets require a bounded record list.');
		}
		$items=0;$normalized=[];
		foreach($records as$index=>$candidate){
			$normalized[]=self::normalizeRecord($candidate,'records['.$index.']',$items);
		}
		$record=self::normalizeRecord($record??($normalized[0]??[]),'record',$items);
		$this->records=$normalized;$this->record=$record;
		$canonical=['records'=>$this->records,'record'=>$this->record,'synthetic'=>$this->synthetic];
		self::sort($canonical);
		$this->digest=hash('sha256',json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
	}

	/** Creates deterministic representative data from a trusted Studio definition. */
	public static function sample(PanelStudioDefinition $definition):self{
		$values=['id'=>'preview-1','name'=>'Preview record','title'=>'Preview record','status'=>'Draft'];$boards=[];$surfaces=[];
		self::collect($definition->root(),$values,$boards,$surfaces);
		$records=[];
		if($boards!==[]){
			$board=$boards[0];$statuses=$board['statuses']!==[]?$board['statuses']:['Draft'];
			foreach(array_slice($statuses,0,self::MAX_RECORDS)as$index=>$status){
				$records[]=array_replace($values,['id'=>'preview-'.($index+1),'name'=>'Preview '.($index+1),'title'=>'Preview record '.($index+1),$board['field']=>$status]);
			}
		}else{
			for($index=0;$index<3;$index++){
				$records[]=array_replace($values,['id'=>'preview-'.($index+1),'name'=>'Preview '.($index+1),'title'=>'Preview record '.($index+1)]);
			}
		}
		self::applySurfaceSamples($records,$surfaces);
		return new self($records,$records[0]??$values,true);
	}

	/** @return list<array<string,mixed>> */ public function records():array{return$this->records;}
	/** @return array<string,mixed> */ public function record():array{return$this->record;}
	public function digest():string{return$this->digest;}
	public function synthetic():bool{return$this->synthetic;}

	/** Returns metadata only; record values are deliberately never serialized. */
	public function jsonSerialize():array{
		$fields=[];foreach([...$this->records,$this->record]as$item){foreach(array_keys($item)as$key){$fields[(string)$key]=true;}}
		return[
			'type'=>'panel_studio_visual_dataset','version'=>1,'record_count'=>count($this->records),
			'field_count'=>count($fields),'digest'=>$this->digest,'synthetic'=>$this->synthetic,
			'values_serialized'=>false,'limits'=>['records'=>self::MAX_RECORDS,'fields'=>self::MAX_FIELDS,'items'=>self::MAX_ITEMS,'depth'=>self::MAX_DEPTH,'string_bytes'=>self::MAX_STRING_BYTES],
			'security'=>['json_only'=>true,'secrets_redacted'=>true,'callbacks'=>false,'objects'=>false],
		];
	}

	/** @param array<string,mixed> $record @return array<string,mixed> */
	private static function normalizeRecord(array $record,string $path,int &$items):array{
		if($record!==[]&&array_is_list($record)){throw new \InvalidArgumentException("Studio visual {$path} must be an object-like record.");}
		if(count($record)>self::MAX_FIELDS){throw new \LengthException("Studio visual {$path} exceeds the field budget.");}
		$value=self::value($record,$path,0,$items);
		$value=PanelSensitiveDataSanitizer::sanitize($value,['max_depth'=>self::MAX_DEPTH,'max_items'=>self::MAX_FIELDS,'max_string_bytes'=>self::MAX_STRING_BYTES]);
		if(!is_array($value)||($value!==[]&&array_is_list($value))){throw new \LogicException('Studio visual record normalization failed.');}
		return$value;
	}

	private static function value(mixed $value,string $path,int $depth,int &$items):mixed{
		if($depth>self::MAX_DEPTH){throw new \LengthException("Studio visual data exceeds its depth budget at {$path}.");}
		if(++$items>self::MAX_ITEMS){throw new \LengthException('Studio visual data exceeds its total item budget.');}
		if(is_object($value)||is_resource($value)||(!is_string($value)&&is_callable($value))){throw new \InvalidArgumentException("Studio visual data must remain JSON-only at {$path}.");}
		if(is_float($value)&&!is_finite($value)){throw new \InvalidArgumentException("Studio visual data contains a non-finite number at {$path}.");}
		if(is_string($value)){
			if(strlen($value)>self::MAX_STRING_BYTES){throw new \LengthException("Studio visual data contains an oversized string at {$path}.");}
			if(preg_match('//u',$value)!==1){throw new \InvalidArgumentException("Studio visual data must contain valid UTF-8 at {$path}.");}
			return$value;
		}
		if(!is_array($value)){return$value;}
		if(count($value)>self::MAX_FIELDS){throw new \LengthException("Studio visual data contains an oversized collection at {$path}.");}
		$out=[];foreach($value as$key=>$item){if(!is_int($key)&&!is_string($key)){throw new \InvalidArgumentException("Studio visual data contains an invalid key at {$path}.");}$out[$key]=self::value($item,$path.'.'.(string)$key,$depth+1,$items);}return$out;
	}

	/** @param array<string,mixed> $values @param list<array{field:string,statuses:list<string>}> $boards @param list<array<string,mixed>> $surfaces */
	private static function collect(array $node,array &$values,array &$boards,array &$surfaces):void{
		$kind=(string)$node['kind'];$key=(string)$node['key'];$properties=$node['properties'];
		if(in_array($kind,['field','column','infolist_entry'],true)){$values[$key]=self::sampleValue((string)($properties['type']??'text'),$key,$properties);}
		if($kind==='board'){
			$statuses=[];foreach($node['children']as$child){if(($child['kind']??null)==='board_column'){$statuses[]=trim((string)($child['properties']['status']??$child['key']));}}
			$boards[]=['field'=>(string)($properties['status_field']??'status'),'statuses'=>$statuses];
		}
		if($kind==='data_surface'){$surfaces[]=$properties;}
		foreach($node['children']as$child){self::collect($child,$values,$boards,$surfaces);}
	}

	/** @param list<array<string,mixed>> $records @param list<array<string,mixed>> $surfaces */
	private static function applySurfaceSamples(array &$records,array $surfaces):void{
		foreach($surfaces as$surface){$fields=is_array($surface['fields']??null)?array_values($surface['fields']):[];$slots=is_array($surface['slots']??null)?$surface['slots']:[];$stable=is_string($surface['stable_key']??null)?$surface['stable_key']:'id';
			foreach($records as$index=>&$record){foreach($fields as$field){if(is_string($field)&&$field!==''){self::setPath($record,$field,'Sample '.ucwords(str_replace(['_','-','.'],' ',$field)));}}self::setPath($record,$stable,'preview-'.($index+1));foreach($slots as$role=>$field){if(is_string($role)&&is_string($field)&&$field!==''){self::setPath($record,$field,self::surfaceValue($role,$index));}}}unset($record);
		}
	}

	private static function surfaceValue(string $role,int $index):mixed{return match(strtolower($role)){
		'title'=>'Canvas record '.($index+1),'description'=>'Representative Studio data',
		'parent'=>$index===0?null:'preview-1','source'=>'Node '.chr(65+$index),'target'=>'Node '.chr(66+$index),
		'row'=>$index%2===0?'North':'South','column'=>$index%2===0?'Q1':'Q2','value'=>($index+1)*10,'group'=>$index%2===0?'Primary':'Secondary',
		'start'=>'2026-07-'.str_pad((string)(14+$index),2,'0',STR_PAD_LEFT).'T12:00:00+00:00','end'=>'2026-07-'.str_pad((string)(15+$index),2,'0',STR_PAD_LEFT).'T12:00:00+00:00','progress'=>min(100,25+($index*30)),
		'latitude'=>45.50+($index*.25),'longitude'=>-73.56+($index*.3),'x'=>10+($index*24),'y'=>15+($index*18),'width'=>20,'height'=>16,'color'=>$index%2===0?'blue':'green','cross_filter'=>$index%2===0?'active':'paused',
		default=>'Sample '.ucwords(str_replace(['_','-','.'],' ',$role)),
	};}

	/** @param array<string,mixed> $record */
	private static function setPath(array &$record,string $path,mixed $value):void{$segments=explode('.',$path);$cursor=&$record;foreach($segments as$index=>$segment){if($segment===''){return;}if($index===array_key_last($segments)){$cursor[$segment]=$value;return;}if(!isset($cursor[$segment])||!is_array($cursor[$segment])){$cursor[$segment]=[];}$cursor=&$cursor[$segment];}}

	/** @param array<string,mixed> $properties */
	private static function sampleValue(string $type,string $key,array $properties):mixed{
		if(array_key_exists('default',$properties)){return$properties['default'];}
		return match($type){
			'email'=>'preview@example.test','number','money'=>123.45,'integer'=>42,'checkbox','toggle','boolean'=>true,
			'date'=>'2026-07-14','datetime'=>'2026-07-14T12:00:00+00:00','time'=>'12:00',
			'select','radio'=>self::firstOption($properties['options']??[]),
			'badge'=>'Active','image'=>'',default=>'Sample '.ucwords(str_replace(['_','-','.'],' ',$key)),
		};
	}

	private static function firstOption(mixed $options):mixed{
		if(!is_array($options)||$options===[]){return'Option A';}$key=array_key_first($options);return is_int($key)?$options[$key]:$key;
	}

	private static function sort(array &$value):void{if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as&$item){if(is_array($item)){self::sort($item);}}}
}
