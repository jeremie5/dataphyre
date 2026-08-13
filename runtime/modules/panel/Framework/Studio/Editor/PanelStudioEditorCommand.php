<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable allow-listed command for the route-free Studio editor engine. */
final class PanelStudioEditorCommand implements \JsonSerializable {
	public const TYPES=['select','add','remove','move','update','replace','undo','redo'];
	private const DIRECTIONS=['up','down','indent','outdent'];
	/** @param array<string,mixed> $payload */
	private function __construct(private readonly string $type,private readonly array $payload){
		if(!in_array($type,self::TYPES,true)){throw new \InvalidArgumentException('Studio editor command type is not supported.');}
		$expected=match($type){
			'select','remove'=>['path'],
			'add'=>['parent','kind','key'],
			'move'=>['path','direction'],
			'update'=>['path','key','properties'],
			'replace'=>['definition'],
			'undo','redo'=>[],
		};
		$keys=array_keys($payload);sort($keys,SORT_STRING);$sorted=$expected;sort($sorted,SORT_STRING);
		if($keys!==$sorted){throw new \InvalidArgumentException('Studio editor command payload is malformed.');}
		foreach(['path','parent']as$name){if(array_key_exists($name,$payload)){self::path($payload[$name]);}}
		if($type==='add'){
			if(!is_string($payload['kind'])||!in_array($payload['kind'],PanelStudioDefinition::KINDS,true)){throw new \InvalidArgumentException('Studio editor add commands require an allow-listed kind.');}
			if(!is_string($payload['key'])||($payload['key']!==''&&preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$payload['key'])!==1)){throw new \InvalidArgumentException('Studio editor add command keys are invalid.');}
		}
		if($type==='move'&&(!is_string($payload['direction'])||!in_array($payload['direction'],self::DIRECTIONS,true))){throw new \InvalidArgumentException('Studio editor move direction is invalid.');}
		if($type==='update'){
			if(!is_string($payload['key'])||preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$payload['key'])!==1){throw new \InvalidArgumentException('Studio editor component keys are invalid.');}
			if(!is_array($payload['properties'])||($payload['properties']!==[]&&array_is_list($payload['properties']))){throw new \InvalidArgumentException('Studio editor properties must be an object-like map.');}
		}
		if($type==='replace'&&!$payload['definition'] instanceof PanelStudioDefinition){throw new \InvalidArgumentException('Studio editor replacement commands require a definition.');}
	}
	public static function select(string $path):self{return new self('select',['path'=>$path]);}
	public static function add(string $parent,string $kind,string $key=''):self{return new self('add',['parent'=>$parent,'kind'=>$kind,'key'=>$key]);}
	public static function remove(string $path):self{return new self('remove',['path'=>$path]);}
	public static function move(string $path,string $direction):self{return new self('move',['path'=>$path,'direction'=>$direction]);}
	/** @param array<string,mixed> $properties */ public static function update(string $path,string $key,array $properties):self{return new self('update',['path'=>$path,'key'=>$key,'properties'=>$properties]);}
	public static function replace(PanelStudioDefinition $definition):self{return new self('replace',['definition'=>$definition]);}
	public static function undo():self{return new self('undo',[]);}
	public static function redo():self{return new self('redo',[]);}
	public function type():string{return$this->type;}
	public function payload():array{return$this->payload;}
	public function jsonSerialize():array{
		$payload=$this->payload;if(($payload['definition']??null)instanceof PanelStudioDefinition){$payload['definition']=$payload['definition']->jsonSerialize();}
		return['type'=>'panel_studio_editor_command','version'=>1,'command'=>$this->type,'payload'=>$payload];
	}
	public static function path(mixed $path):string{
		if(!is_string($path)||strlen($path)>1536||preg_match('/^[a-z][a-z0-9_.-]{0,127}(?:\/[a-z][a-z0-9_.-]{0,127}){0,12}$/',$path)!==1){throw new \InvalidArgumentException('Studio editor paths must be bounded component-key paths.');}
		return$path;
	}
}
