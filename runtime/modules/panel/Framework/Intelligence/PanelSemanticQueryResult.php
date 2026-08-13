<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Canonical paginated multi-metric semantic result with execution evidence. */
final class PanelSemanticQueryResult implements \JsonSerializable {
	/** @var list<array{dimensions:array<string,mixed>,metrics:array<string,mixed>}> */private readonly array $rows;/** @var array<string,mixed> */private readonly array $execution;private readonly string $backend;
	/** @param list<array{dimensions:array<string,mixed>,metrics:array<string,mixed>}> $rows @param array<string,mixed> $execution */
	public function __construct(private readonly PanelSemanticExecutionPlan $plan,array $rows,private readonly int $total,string $backend,private readonly bool $pushdown,private readonly bool $snapshotConsistent,array $execution=[]){
		$query=$plan->query();if(!array_is_list($rows)||count($rows)>$query->limit()||$total<0||($rows!==[]&&$total<$query->offset()+count($rows))){throw new \InvalidArgumentException('Semantic query result page counters are invalid.');}$expectedDimensions=$query->dimensions();sort($expectedDimensions,SORT_STRING);$expectedMetrics=array_keys($plan->metrics());sort($expectedMetrics,SORT_STRING);
		$normalized=[];foreach($rows as$row){if(!is_array($row)||array_keys($row)!==['dimensions','metrics']||!is_array($row['dimensions'])||!is_array($row['metrics'])||array_is_list($row['dimensions'])||array_is_list($row['metrics'])){throw new \InvalidArgumentException('Semantic query result rows are invalid.');}$dimensionKeys=array_keys($row['dimensions']);sort($dimensionKeys,SORT_STRING);$metricKeys=array_keys($row['metrics']);sort($metricKeys,SORT_STRING);if($dimensionKeys!==$expectedDimensions||$metricKeys!==$expectedMetrics){throw new \InvalidArgumentException('Semantic query result row projection is invalid.');}$dimensions=PanelQueryValue::normalize($row['dimensions'],'semantic result dimensions');$metrics=PanelQueryValue::normalize($row['metrics'],'semantic result metrics');if(!is_array($dimensions)||!is_array($metrics)){throw new \InvalidArgumentException('Semantic result values are invalid.');}foreach($metrics as$value){if($value!==null&&!is_scalar($value)){throw new \InvalidArgumentException('Semantic metric results must be scalar or null.');}}$normalized[]=['dimensions'=>$dimensions,'metrics'=>$metrics];}
		$this->backend=PanelOperationsGuard::name($backend,'semantic backend');$safe=PanelSensitiveDataSanitizer::sanitize(PanelQueryValue::normalize($execution,'semantic execution metadata'));if(!is_array($safe)||($safe!==[]&&array_is_list($safe))){throw new \InvalidArgumentException('Semantic execution metadata is invalid.');}$this->rows=$normalized;$this->execution=$safe;
	}
	public function plan():PanelSemanticExecutionPlan{return$this->plan;}/** @return list<array{dimensions:array<string,mixed>,metrics:array<string,mixed>}> */public function rows():array{return$this->rows;}public function total():int{return$this->total;}public function backend():string{return$this->backend;}public function pushdown():bool{return$this->pushdown;}public function snapshotConsistent():bool{return$this->snapshotConsistent;}/** @return array<string,mixed> */public function execution():array{return$this->execution;}
	/** @param array<string,mixed> $metadata */public function withExecutionMetadata(array $metadata):self{return new self($this->plan,$this->rows,$this->total,$this->backend,$this->pushdown,$this->snapshotConsistent,array_replace($this->execution,$metadata));}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_query_result','version'=>1,'plan_fingerprint'=>$this->plan->fingerprint(),'catalog_fingerprint'=>$this->plan->catalogFingerprint(),'entity'=>$this->plan->entity(),'rows'=>$this->rows,'page'=>['offset'=>$this->plan->query()->offset(),'limit'=>$this->plan->query()->limit(),'returned'=>count($this->rows),'total'=>$this->total],'execution'=>['backend'=>$this->backend,'pushdown'=>$this->pushdown,'snapshot_consistent'=>$this->snapshotConsistent]+$this->execution,'authority_serialized'=>false];}
}
