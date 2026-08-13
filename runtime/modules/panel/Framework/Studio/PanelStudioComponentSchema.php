<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable trusted property, child grammar, cardinality, and identity contract. */
final class PanelStudioComponentSchema implements \JsonSerializable {
	/** @var array<string,PanelStudioPropertySchema> */ private readonly array $properties;
	/** @var array<string,PanelStudioChildRule> */ private readonly array $children;
	public function __construct(private readonly string $kind,array $properties=[],array $children=[],private readonly int $minimumChildren=0,private readonly int $maximumChildren=512,private readonly string $identityScope='document_kind',private readonly int $schemaVersion=1){
		if(!in_array($kind,PanelStudioDefinition::KINDS,true)){throw new \InvalidArgumentException('Studio component schemas require an allow-listed kind.');}
		if($minimumChildren<0||$maximumChildren<$minimumChildren||$maximumChildren>PanelStudioDefinition::MAX_NODES){throw new \InvalidArgumentException('Studio component child cardinality is invalid.');}
		if(!in_array($identityScope,['sibling','document_kind','document'],true)){throw new \InvalidArgumentException('Studio component identity scope is invalid.');}
		if($schemaVersion<1){throw new \InvalidArgumentException('Studio component schema versions must be positive.');}
		if(count($properties)>PanelStudioDefinition::MAX_PROPERTIES||count($children)>count(PanelStudioDefinition::KINDS)){throw new \LengthException('Studio component schemas exceed their property or child-rule budgets.');}
		$propertyMap=[];foreach($properties as$property){if(!$property instanceof PanelStudioPropertySchema){throw new \InvalidArgumentException('Studio component properties must be typed property schemas.');}if(isset($propertyMap[$property->name()])){throw new \InvalidArgumentException('Studio component property schemas cannot be duplicated.');}$propertyMap[$property->name()]=$property;}ksort($propertyMap,SORT_STRING);$this->properties=$propertyMap;
		$childMap=[];$ruleMinimum=0;$ruleMaximum=0;foreach($children as$rule){if(!$rule instanceof PanelStudioChildRule){throw new \InvalidArgumentException('Studio component children must be typed child rules.');}if(isset($childMap[$rule->kind()])){throw new \InvalidArgumentException('Studio component child rules cannot be duplicated.');}$childMap[$rule->kind()]=$rule;$ruleMinimum+=$rule->minimum();$ruleMaximum+=$rule->maximum();}if($ruleMinimum>$maximumChildren||$ruleMaximum<$minimumChildren){throw new \InvalidArgumentException('Studio component child rules conflict with total child cardinality.');}ksort($childMap,SORT_STRING);$this->children=$childMap;
		if(strlen(json_encode($this->jsonSerialize(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR))>PanelStudioDefinition::MAX_JSON_BYTES){throw new \LengthException('Studio component schema exceeds its serialized budget.');}
	}
	public static function make(string $kind,array $properties=[],array $children=[],int $minimumChildren=0,int $maximumChildren=512,string $identityScope='document_kind',int $schemaVersion=1):self{return new self($kind,$properties,$children,$minimumChildren,$maximumChildren,$identityScope,$schemaVersion);}
	public function kind():string{return$this->kind;}
	public function property(string $name):?PanelStudioPropertySchema{return$this->properties[$name]??null;}
	public function properties():array{return$this->properties;}
	public function childRule(string $kind):?PanelStudioChildRule{return$this->children[$kind]??null;}
	public function childRules():array{return$this->children;}
	public function minimumChildren():int{return$this->minimumChildren;}
	public function maximumChildren():int{return$this->maximumChildren;}
	public function identityScope():string{return$this->identityScope;}
	public function schemaVersion():int{return$this->schemaVersion;}
	public function jsonSerialize():array{return['kind'=>$this->kind,'schema_version'=>$this->schemaVersion,'identity'=>['scope'=>$this->identityScope,'key_pattern'=>'^[a-z][a-z0-9_.-]{0,127}$'],'children'=>['minimum'=>$this->minimumChildren,'maximum'=>$this->maximumChildren,'rules'=>array_values(array_map(static fn(PanelStudioChildRule $rule):array=>$rule->jsonSerialize(),$this->children))],'properties'=>array_values(array_map(static fn(PanelStudioPropertySchema $property):array=>$property->manifest(),$this->properties))];}
}
