<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Catalog-bound, validated semantic execution plan shared by every backend. */
final class PanelSemanticExecutionPlan implements \JsonSerializable {
	/** @var array<string,PanelSemanticMetric> */private readonly array $metrics;/** @var list<string> */private readonly array $requiredFields;private readonly string $entity;private readonly string $fingerprint;
	/** @param array<string,PanelSemanticMetric> $metrics */
	private function __construct(private readonly PanelSemanticQuery $query,array $metrics,private readonly int $catalogRevision,private readonly string $catalogFingerprint){
		$entities=[];foreach($metrics as$metric){$entities[$metric->entity()]=true;}if(count($entities)!==1){throw new PanelSemanticUnsupported(['cross_entity_metrics'],'A semantic query may currently target one entity per execution plan.');}$this->entity=(string)array_key_first($entities);
		foreach($query->dimensions()as$dimension){foreach($metrics as$metric){if(!in_array($dimension,$metric->dimensions(),true)){throw new PanelSemanticUnsupported(['dimension:'.$dimension],'Every requested metric must declare each grouped dimension.');}}}
		$targets=array_fill_keys([...$query->metricIds(),...$query->dimensions()],true);foreach($query->sorts()as$sort){if(!isset($targets[$sort->target()])){throw new \InvalidArgumentException('Semantic sort targets must be requested metrics or dimensions.');}}
		$fields=$query->filter()?->fields()??[];foreach($metrics as$metric){if($metric->field()!==null){$fields[]=$metric->field();}$fields=[...$fields,...$metric->dimensions(),...array_keys($metric->filter()),...array_keys($metric->numeratorFilter()),...array_keys($metric->denominatorFilter())];}
		$fields=array_values(array_unique(array_map(static fn(string $field):string=>PanelQueryPath::make($field)->value(),$fields)));sort($fields,SORT_STRING);if(count($fields)>256){throw new \LengthException('Semantic execution plans support at most 256 required fields.');}
		$this->metrics=$metrics;$this->requiredFields=$fields;$this->fingerprint=PanelOperationsGuard::digest(['query'=>$query->fingerprint(),'catalog_revision'=>$catalogRevision,'catalog_fingerprint'=>$catalogFingerprint,'metrics'=>array_map(static fn(PanelSemanticMetric $metric):string=>$metric->fingerprint(),$metrics),'required_fields'=>$fields]);
	}
	public static function compile(PanelSemanticCatalog $catalog,PanelSemanticQuery $query):self{$metrics=[];foreach($query->metricIds()as$id){$metrics[$id]=$catalog->metric($id);}return new self($query,$metrics,$catalog->revision(),$catalog->fingerprint());}
	public function query():PanelSemanticQuery{return$this->query;}public function entity():string{return$this->entity;}/** @return array<string,PanelSemanticMetric> */public function metrics():array{return$this->metrics;}/** @return list<string> */public function requiredFields():array{return$this->requiredFields;}public function catalogRevision():int{return$this->catalogRevision;}public function catalogFingerprint():string{return$this->catalogFingerprint;}public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_execution_plan','version'=>1,'query'=>$this->query->jsonSerialize(),'entity'=>$this->entity,'metrics'=>array_map(static fn(PanelSemanticMetric $metric):array=>$metric->jsonSerialize(),$this->metrics),'required_fields'=>$this->requiredFields,'catalog_revision'=>$this->catalogRevision,'catalog_fingerprint'=>$this->catalogFingerprint,'fingerprint'=>$this->fingerprint,'authority_serialized'=>false];}
}
