<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Validates and compiles Studio documents into framework-neutral manifests. */
final class PanelStudioCompiler implements \JsonSerializable {
	public const COMPILER_VERSION=1;
	/** @return list<PanelStudioDiagnostic> */
	public function diagnose(mixed $definition):array{
		try{
			if($definition instanceof PanelStudioDefinition){return[];}
			if(!is_array($definition)||array_is_list($definition)){return[new PanelStudioDiagnostic('root','invalid_root','Studio definitions must have one object-like root component.')];}
			PanelStudioDefinition::from($definition);return[];
		}catch(\LengthException $error){return[new PanelStudioDiagnostic('root','limit_exceeded',$error->getMessage())];}
		catch(\Throwable $error){return[new PanelStudioDiagnostic('root','invalid_definition',$error->getMessage())];}
	}
	public function compile(PanelStudioDefinition|array $definition):array{
		$definition=$definition instanceof PanelStudioDefinition?$definition:PanelStudioDefinition::from($definition);
		$kinds=[];$this->collectKinds($definition->root(),$kinds);ksort($kinds,SORT_STRING);
		return PanelManifestContract::stamp(['type'=>'panel_studio_portable_blueprint_manifest','version'=>self::COMPILER_VERSION,'definition_hash'=>$definition->hash(),'component_kinds'=>$kinds,'root'=>$definition->root(),'runtime'=>['framework_neutral'=>true,'portable_blueprint'=>true,'executable_code'=>false,'kind_specific_schema_validation'=>false,'parent_child_grammar_validation'=>false,'trusted_schema_registry_required'=>true]]);
	}
	public function fromBlueprint(PanelSchemaBlueprint $blueprint,string $key='resource'):PanelStudioDefinition{
		$manifest=$blueprint->manifest();$fields=[];foreach($manifest['fields']as$index=>$field){$fields[]=['kind'=>'field','key'=>$this->safeKey((string)($field['name']??'field_'.$index),'field_'.$index),'properties'=>$this->propertiesFor('field',$field),'children'=>[]];}
		$columns=[];foreach($manifest['columns']as$index=>$column){$columns[]=['kind'=>'column','key'=>$this->safeKey((string)($column['name']??'column_'.$index),'column_'.$index),'properties'=>$this->propertiesFor('column',$column),'children'=>[]];}
		$pageKey=$this->safeKey($key,'resource');$children=[];if($fields!==[]){$children[]=['kind'=>'form','key'=>$pageKey.'_form','properties'=>[],'children'=>[['kind'=>'form_section','key'=>'main','properties'=>['label'=>'Details'],'children'=>$fields]]];}if($columns!==[]){$children[]=['kind'=>'table','key'=>$pageKey.'_table','properties'=>[],'children'=>$columns];}return PanelStudioDefinition::from(['kind'=>'page','key'=>$pageKey,'properties'=>['label'=>(string)$manifest['resource']],'children'=>$children]);
	}
	public function import(array|\JsonSerializable $manifest,string $key='imported'):PanelStudioDefinition{
		$value=$manifest instanceof \JsonSerializable?$manifest->jsonSerialize():$manifest;if(!is_array($value)){throw new \InvalidArgumentException('Studio imports require an array manifest.');}
		$groups=[];foreach(['fields'=>'field','columns'=>'column','widgets'=>'widget','navigation'=>'navigation_item']as$list=>$kind){$items=[];$used=[];foreach(is_array($value[$list]??null)?$value[$list]:[]as$index=>$item){if(!is_array($item)){continue;}$candidate=is_string($item['key']??null)?$item['key']:(is_string($item['name']??null)?$item['name']:$kind.'_'.$index);$itemKey=$this->uniqueKey($this->safeKey($candidate,$kind.'_'.$index),$used);$items[]=['kind'=>$kind,'key'=>$itemKey,'properties'=>$this->propertiesFor($kind,$item),'children'=>[]];}$groups[$kind]=$items;}
		$children=[];$pageKey=$this->safeKey($key,'imported');if($groups['field']!==[]){$children[]=['kind'=>'form','key'=>$pageKey.'_form','properties'=>[],'children'=>[['kind'=>'form_section','key'=>'main','properties'=>['label'=>'Details'],'children'=>$groups['field']]]];}if($groups['column']!==[]){$children[]=['kind'=>'table','key'=>$pageKey.'_table','properties'=>[],'children'=>$groups['column']];}if($groups['widget']!==[]){$children[]=['kind'=>'widget_grid','key'=>$pageKey.'_widgets','properties'=>[],'children'=>$groups['widget']];}if($groups['navigation_item']!==[]){$children[]=['kind'=>'navigation','key'=>$pageKey.'_navigation','properties'=>[],'children'=>$groups['navigation_item']];}
		$label=is_string($value['label']??null)?$value['label']:(is_string($value['name']??null)?$value['name']:$pageKey);return PanelStudioDefinition::from(['kind'=>'page','key'=>$pageKey,'properties'=>['label'=>$label],'children'=>$children]);
	}
	public function fingerprint():string{return hash('sha256',json_encode($this->baseManifest(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
	public function manifest():array{return$this->baseManifest()+['fingerprint'=>$this->fingerprint()];}
	public function jsonSerialize():array{return$this->manifest();}
	private function collectKinds(array $node,array &$kinds):void{$kind=(string)$node['kind'];$kinds[$kind]=($kinds[$kind]??0)+1;foreach($node['children']as$child){$this->collectKinds($child,$kinds);}}
	private function propertiesFor(string $kind,array $source):array{
		$safe=$this->safeImportedProperties($source);$allowed=match($kind){'field'=>['label','type','required','readonly','disabled','placeholder','help','default','options','minimum','maximum','step','rows','searchable','multiple','column_span'],'column'=>['label','type','sortable','searchable','toggleable','hidden_by_default','currency','limit','wrap'],'widget'=>['label','type','value','description','tone','icon','url','group','sort','unit','height','chart_type','labels','data'],'navigation_item'=>['label','url','icon','description','sort','badge','tone','new_tab','hidden'],default=>[]};$result=[];foreach($allowed as$name){if(array_key_exists($name,$safe)){$result[$name]=$safe[$name];}}
		if($kind==='field'){$rawType=strtolower((string)($safe['type']??'text'));$result['type']=match($rawType){'int','integer','bigint','smallint','tinyint'=>'integer','decimal','numeric','float','double','real'=>'number','text','longtext','mediumtext'=>'textarea','date'=>'date','datetime','datetime-local','timestamp'=>'datetime','time'=>'time','email'=>'email','password'=>'password','select','enum'=>'select','radio'=>'radio','checkbox','boolean','bool'=>'checkbox',default=>'text'};if(($source['nullable']??null)===false){$result['required']=true;}if(is_array($source['enum']??null)&&$source['enum']!==[]){$result['type']='select';$result['options']=[];foreach($source['enum']as$option){if(is_string($option)||is_int($option)||is_float($option)){$result['options'][(string)$option]=self::humanize((string)$option);}}}if($result['type']==='password'){unset($result['default']);}}
		if($kind==='column'){$rawType=strtolower((string)($safe['type']??'text'));$result['type']=in_array($rawType,['badge','money','date','datetime','boolean'],true)?$rawType:'text';}
		if($kind==='widget'){$result['type']=in_array(($safe['type']??null),['stat','chart'],true)?$safe['type']:'stat';}
		return$result;
	}
	private function safeImportedProperties(array $properties):array{
		unset($properties['key'],$properties['name'],$properties['children'],$properties['fields'],$properties['columns'],$properties['widgets'],$properties['navigation']);
		foreach(array_keys($properties)as$key){$normalized=is_string($key)?strtolower(str_replace(['.','-'],'_',$key)):'';if($normalized===''||PanelSensitiveDataSanitizer::isSensitiveKey($normalized)||preg_match('/(?:^|_)(?:php|class|callable|closure|raw_html|html|script|template|view|source)(?:_|$)/',$normalized)===1){unset($properties[$key]);}}
		return PanelSensitiveDataSanitizer::sanitize($properties,['max_depth'=>8,'max_items'=>128,'max_string_bytes'=>PanelStudioDefinition::MAX_STRING_BYTES]);
	}
	private function safeKey(string $value,string $fallback):string{$value=strtolower(trim($value));$value=(string)preg_replace('/[^a-z0-9_.-]+/','_',$value);$value=trim($value,'_.-');if($value===''||preg_match('/^[a-z]/',$value)!==1){$value='item_'.$value;}if(strlen($value)>120){$value=substr($value,0,111).'_'.substr(hash('sha256',$value),0,8);}return preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$value)===1?$value:$fallback;}
	/** @param array<string,bool> $used */ private function uniqueKey(string $key,array &$used):string{$candidate=$key;$suffix=1;while(isset($used[$candidate])){$candidate=substr($key,0,118).'_'.(++$suffix);}$used[$candidate]=true;return$candidate;}
	private static function humanize(string $value):string{$value=trim((string)preg_replace('/[_\-.]+/',' ',$value));return$value===''?'Option':ucwords($value);}
	private function baseManifest():array{return['type'=>'panel_studio_compiler_manifest','version'=>self::COMPILER_VERSION,'implementation_sha256'=>hash_file('sha256',__FILE__),'output'=>'portable_blueprint','allowed_component_kinds'=>PanelStudioDefinition::KINDS,'limits'=>['depth'=>PanelStudioDefinition::MAX_DEPTH,'nodes'=>PanelStudioDefinition::MAX_NODES,'properties'=>PanelStudioDefinition::MAX_PROPERTIES,'string_bytes'=>PanelStudioDefinition::MAX_STRING_BYTES,'json_bytes'=>PanelStudioDefinition::MAX_JSON_BYTES],'validation'=>['component_envelope'=>true,'kind_allow_list'=>true,'json_safety'=>true,'kind_specific_property_schema'=>false,'parent_child_grammar'=>false,'cardinality'=>false],'security'=>['json_only'=>true,'raw_html'=>false,'php'=>false,'classes'=>false,'callables'=>false,'sensitive_properties'=>false]];}
}
