<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Direction-aware semantic compatibility report for generated SDK contracts. */
final class PanelSdkCompatibilityReport implements \JsonSerializable {
	/** @var list<array{severity:string,code:string,path:string,before:mixed,after:mixed}> */private array $changes=[];
	private readonly bool $versionCompliant;

	private function __construct(private readonly PanelSdkContract $before,private readonly PanelSdkContract $after){$this->compare();$this->versionCompliant=$this->checkVersion();}
	public static function between(PanelSdkContract $before,PanelSdkContract $after):self{return new self($before,$after);}
	/** @return list<array{severity:string,code:string,path:string,before:mixed,after:mixed}> */public function changes(?string $severity=null):array{return$severity===null?$this->changes:array_values(array_filter($this->changes,static fn(array $change):bool=>$change['severity']===$severity));}
	public function breaking():bool{return$this->changes('breaking')!==[];}public function additive():bool{return$this->changes('additive')!==[];}public function changed():bool{return$this->changes!==[];}public function requiredBump():string{return$this->breaking()?'major':($this->additive()?'minor':($this->changed()?'patch':'none'));}public function versionCompliant():bool{return$this->versionCompliant;}
	/** @return array<string,int> */public function summary():array{return['breaking'=>count($this->changes('breaking')),'additive'=>count($this->changes('additive')),'metadata'=>count($this->changes('metadata')),'total'=>count($this->changes)];}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_sdk_compatibility_report','version'=>1,'before'=>['id'=>$this->before->id(),'version'=>$this->before->version(),'fingerprint'=>$this->before->fingerprint()],'after'=>['id'=>$this->after->id(),'version'=>$this->after->version(),'fingerprint'=>$this->after->fingerprint()],'compatible'=>!$this->breaking(),'version_compliant'=>$this->versionCompliant,'required_bump'=>$this->requiredBump(),'summary'=>$this->summary(),'changes'=>$this->changes]);}

	private function compare():void {
		if($this->before->id()!==$this->after->id()){$this->add('breaking','contract_id_changed','id',$this->before->id(),$this->after->id());}
		$this->compareMembers($this->before->operations(),$this->after->operations(),'operations',function(string $name,PanelSdkOperation $old,PanelSdkOperation $new):void{$this->compareOperation($name,$old,$new);});
		$this->compareMembers($this->before->events(),$this->after->events(),'events',function(string $name,PanelSdkSchema $old,PanelSdkSchema $new):void{$this->compareSchema($old->definition(),$new->definition(),'events.'.$name,'response');});
		$this->compareMembers($this->before->artifacts(),$this->after->artifacts(),'artifacts',function(string $name,PanelSdkSchema $old,PanelSdkSchema $new):void{$this->compareSchema($old->definition(),$new->definition(),'artifacts.'.$name,'response');});
		if($this->before->bindings()!==$this->after->bindings()){$this->add('metadata','bindings_changed','bindings',$this->before->bindings(),$this->after->bindings());}
		if($this->before->metadata()!==$this->after->metadata()){$this->add('metadata','metadata_changed','metadata',$this->before->metadata(),$this->after->metadata());}
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $after */
	private function compareMembers(array $before,array $after,string $path,callable $compare):void {
		foreach(array_diff_key($before,$after)as$name=>$value){$this->add('breaking','member_removed',$path.'.'.$name,self::serial($value),null);}
		foreach(array_diff_key($after,$before)as$name=>$value){$this->add('additive','member_added',$path.'.'.$name,null,self::serial($value));}
		foreach(array_intersect_key($before,$after)as$name=>$value){$compare((string)$name,$value,$after[$name]);}
	}

	private function compareOperation(string $name,PanelSdkOperation $old,PanelSdkOperation $new):void {
		$path='operations.'.$name;
		if($old->method()!==$new->method()){$this->add('breaking','method_changed',$path.'.method',$old->method(),$new->method());}
		if($old->path()!==$new->path()){$this->add('breaking','path_changed',$path.'.path',$old->path(),$new->path());}
		$this->compareSchema($old->pathSchema()->definition(),$new->pathSchema()->definition(),$path.'.request.path','request');$this->compareSchema($old->querySchema()->definition(),$new->querySchema()->definition(),$path.'.request.query','request');
		if(($old->bodySchema()===null)!==($new->bodySchema()===null)){$severity=$old->bodySchema()===null?'breaking':'breaking';$this->add($severity,'body_contract_changed',$path.'.request.body',$old->bodySchema()?->definition(),$new->bodySchema()?->definition());}elseif($old->bodySchema()!==null&&$new->bodySchema()!==null){$this->compareSchema($old->bodySchema()->definition(),$new->bodySchema()->definition(),$path.'.request.body','request');}
		$this->compareSchema($old->responseSchema()->definition(),$new->responseSchema()->definition(),$path.'.response','response');
		$oldScopes=$old->scopes();$newScopes=$new->scopes();foreach(array_diff($newScopes,$oldScopes)as$scope){$this->add('breaking','security_scope_added',$path.'.security.scopes',null,$scope);}foreach(array_diff($oldScopes,$newScopes)as$scope){$this->add('additive','security_scope_removed',$path.'.security.scopes',$scope,null);}
		if($old->summary()!==$new->summary()||$old->tags()!==$new->tags()||$old->deprecated()!==$new->deprecated()||$old->idempotent()!==$new->idempotent()){$this->add('metadata','operation_metadata_changed',$path,['summary'=>$old->summary(),'tags'=>$old->tags(),'deprecated'=>$old->deprecated(),'idempotent'=>$old->idempotent()],['summary'=>$new->summary(),'tags'=>$new->tags(),'deprecated'=>$new->deprecated(),'idempotent'=>$new->idempotent()]);}
		$oldErrors=$old->errors();$newErrors=$new->errors();foreach(array_diff_key($oldErrors,$newErrors)as$status=>$schema){$this->add('breaking','error_contract_removed',$path.'.errors.'.$status,$schema->definition(),null);}foreach(array_diff_key($newErrors,$oldErrors)as$status=>$schema){$this->add('additive','error_contract_added',$path.'.errors.'.$status,null,$schema->definition());}foreach(array_intersect_key($oldErrors,$newErrors)as$status=>$schema){$this->compareSchema($schema->definition(),$newErrors[$status]->definition(),$path.'.errors.'.$status,'response');}
	}

	/** @param array<string,mixed> $old @param array<string,mixed> $new */
	private function compareSchema(array $old,array $new,string $path,string $direction):void {
		$old=self::semanticSchema($old);$new=self::semanticSchema($new);if($old===$new){return;}
		if(isset($old['anyOf'])||isset($new['anyOf'])||($old['type']??null)!==($new['type']??null)){$this->add('breaking','schema_shape_changed',$path,$old,$new);return;}
		$type=$old['type']??null;if($type===null){$this->add('breaking','unconstrained_schema_changed',$path,$old,$new);return;}
		if(isset($old['enum'])||isset($new['enum'])){$oldEnum=$old['enum']??null;$newEnum=$new['enum']??null;if(!is_array($oldEnum)||!is_array($newEnum)){$this->add('breaking','enum_contract_changed',$path,$oldEnum,$newEnum);return;}$removed=self::valueDiff($oldEnum,$newEnum);$added=self::valueDiff($newEnum,$oldEnum);if($direction==='request'){if($removed!==[])$this->add('breaking','request_enum_narrowed',$path,$removed,null);if($added!==[])$this->add('additive','request_enum_widened',$path,null,$added);}else{if($added!==[])$this->add('breaking','response_enum_widened',$path,null,$added);if($removed!==[])$this->add('additive','response_enum_narrowed',$path,$removed,null);}$old['enum']=$new['enum']=[];}
		if($type==='object'){$this->compareObject($old,$new,$path,$direction);return;}
		if($type==='array'){$this->compareSchema($old['items'],$new['items'],$path.'.items',$direction);$this->compareBounds($old,$new,$path,'minItems','maxItems',$direction);if(($old['uniqueItems']??false)!==($new['uniqueItems']??false)){$this->add(($new['uniqueItems']??false)?'breaking':'additive','array_uniqueness_changed',$path.'.uniqueItems',$old['uniqueItems']??false,$new['uniqueItems']??false);}return;}
		if($type==='string'){$this->compareBounds($old,$new,$path,'minLength','maxLength',$direction);foreach(['pattern','format']as$key){if(($old[$key]??null)!==($new[$key]??null)){$this->add('breaking','string_constraint_changed',$path.'.'.$key,$old[$key]??null,$new[$key]??null);}}return;}
		if($type==='integer'||$type==='number'){$this->compareBounds($old,$new,$path,'minimum','maximum',$direction);return;}
		if($old!==$new){$this->add('breaking','schema_changed',$path,$old,$new);}
	}

	/** @param array<string,mixed> $old @param array<string,mixed> $new */
	private function compareObject(array $old,array $new,string $path,string $direction):void {
		$oldProps=$old['properties']??[];$newProps=$new['properties']??[];$oldRequired=$old['required']??[];$newRequired=$new['required']??[];
		foreach(array_diff_key($oldProps,$newProps)as$name=>$schema){$this->add('breaking','property_removed',$path.'.'.$name,$schema,null);}
		foreach(array_diff_key($newProps,$oldProps)as$name=>$schema){$required=in_array($name,$newRequired,true);$severity=$direction==='request'&&$required?'breaking':'additive';$this->add($severity,$required?'required_property_added':'property_added',$path.'.'.$name,null,$schema);}
		foreach(array_intersect_key($oldProps,$newProps)as$name=>$schema){$this->compareSchema($schema,$newProps[$name],$path.'.'.$name,$direction);}
		foreach(array_diff($newRequired,$oldRequired)as$name){if(isset($oldProps[$name])){$this->add($direction==='request'?'breaking':'additive','property_became_required',$path.'.'.$name,false,true);}}
		foreach(array_diff($oldRequired,$newRequired)as$name){if(isset($newProps[$name])){$this->add($direction==='response'?'breaking':'additive','property_became_optional',$path.'.'.$name,true,false);}}
		$oldAdditional=$old['additionalProperties']??false;$newAdditional=$new['additionalProperties']??false;if($oldAdditional!==$newAdditional){$severity=$direction==='request'&&$oldAdditional===false&&$newAdditional!==false?'additive':'breaking';$this->add($severity,'additional_properties_changed',$path.'.additionalProperties',$oldAdditional,$newAdditional);}
	}

	/** @param array<string,mixed> $old @param array<string,mixed> $new */
	private function compareBounds(array $old,array $new,string $path,string $min,string $max,string $direction):void {
		$oldMin=$old[$min]??null;$newMin=$new[$min]??null;$oldMax=$old[$max]??null;$newMax=$new[$max]??null;
		if($oldMin!==$newMin){$tightened=$newMin!==null&&($oldMin===null||$newMin>$oldMin);$breaking=$direction==='request'?$tightened:!$tightened;$this->add($breaking?'breaking':'additive','minimum_changed',$path.'.'.$min,$oldMin,$newMin);}
		if($oldMax!==$newMax){$tightened=$newMax!==null&&($oldMax===null||$newMax<$oldMax);$breaking=$direction==='request'?$tightened:!$tightened;$this->add($breaking?'breaking':'additive','maximum_changed',$path.'.'.$max,$oldMax,$newMax);}
	}

	private function checkVersion():bool {$old=self::versionParts($this->before->version());$new=self::versionParts($this->after->version());if($new<=$old){return!$this->changed()&&$new===$old;}return match($this->requiredBump()){'major'=>$new[0]>$old[0],'minor'=>$new[0]>$old[0]||($new[0]===$old[0]&&$new[1]>$old[1]),'patch'=>$new[0]>$old[0]||$new[1]>$old[1]||$new[2]>$old[2],default=>true};}
	/** @return array{int,int,int} */private static function versionParts(string $version):array {$core=explode('-',explode('+',$version,2)[0],2)[0];$parts=array_map('intval',explode('.',$core));return[$parts[0],$parts[1],$parts[2]];}

	private function add(string $severity,string $code,string $path,mixed $before,mixed $after):void {if(count($this->changes)>=4096){throw new \LengthException('Panel SDK compatibility report exceeds its change bound.');}$this->changes[]=['severity'=>$severity,'code'=>$code,'path'=>$path,'before'=>$before,'after'=>$after];}
	/** @param array<string,mixed> $schema @return array<string,mixed> */private static function semanticSchema(array $schema):array {unset($schema['description']);return$schema;}
	/** @param list<mixed> $left @param list<mixed> $right @return list<mixed> */private static function valueDiff(array $left,array $right):array {$digests=[];foreach($right as$value){$digests[PanelSdkGuard::fingerprint($value)]=true;}return array_values(array_filter($left,static fn(mixed $value):bool=>!isset($digests[PanelSdkGuard::fingerprint($value)])));}
	private static function serial(mixed $value):mixed{return$value instanceof \JsonSerializable?$value->jsonSerialize():$value;}
}
