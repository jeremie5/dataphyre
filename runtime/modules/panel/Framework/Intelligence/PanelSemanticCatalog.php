<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Versioned semantic layer shared by tables, dashboards, agents, and APIs. */
final class PanelSemanticCatalog implements PanelCheckpointableService,\JsonSerializable {
	/** @var array<string,PanelSemanticMetric> */private array $metrics=[];private int $revision=0;private readonly string $checkpointOwner;
	public function __construct(){$this->checkpointOwner=bin2hex(random_bytes(16));}
	public function register(PanelSemanticMetric $metric,bool $replace=false):self {if(!$replace&&isset($this->metrics[$metric->id()])){throw new \LogicException('Semantic metric is already registered.');}$this->metrics[$metric->id()]=$metric;ksort($this->metrics,SORT_STRING);$this->revision++;return$this;}
	public function remove(string $id):self {$id=PanelOperationsGuard::name($id,'semantic metric id');if(isset($this->metrics[$id])){unset($this->metrics[$id]);$this->revision++;}return$this;}
	public function metric(string $id):PanelSemanticMetric {$id=PanelOperationsGuard::name($id,'semantic metric id');if(!isset($this->metrics[$id])){throw new \OutOfBoundsException('Semantic metric is not registered.');}return$this->metrics[$id];}
	/** @return array<string,PanelSemanticMetric> */public function metrics():array{return$this->metrics;}
	/** @param list<array<string,mixed>> $rows @param list<string> $groupBy @return list<array<string,mixed>> */public function query(string $id,array $rows,array $groupBy=[]):array{return$this->metric($id)->evaluate($rows,$groupBy);}
	public function plan(PanelSemanticQuery $query):PanelSemanticExecutionPlan{return PanelSemanticExecutionPlan::compile($this,$query);}
	public function execute(PanelSemanticQuery $query,PanelSemanticBackend $primary,?PanelSemanticBackend $fallback=null):PanelSemanticQueryResult{return(new PanelSemanticExecutor($this,$primary,$fallback))->execute($query);}
	public function revision():int{return$this->revision;}public function fingerprint():string{return PanelOperationsGuard::digest(array_map(static fn(PanelSemanticMetric $metric):string=>$metric->fingerprint(),$this->metrics));}
	public function checkpointType():string{return'panel_semantic_catalog_v1';}
	/** @return array<string,mixed> */public function checkpoint():array{$identities=[];foreach($this->metrics as$id=>$metric){$identities[$id]=spl_object_id($metric);}return['owner'=>$this->checkpointOwner,'metrics'=>$this->metrics,'revision'=>$this->revision,'digest'=>PanelOperationsGuard::digest(['owner'=>$this->checkpointOwner,'metrics'=>$identities,'revision'=>$this->revision])];}
	/** @param array<string,mixed> $checkpoint */public function restore(array $checkpoint):self {if(array_keys($checkpoint)!==['owner','metrics','revision','digest']||!is_string($checkpoint['owner']??null)||!hash_equals($this->checkpointOwner,$checkpoint['owner'])||!is_array($checkpoint['metrics']??null)||count($checkpoint['metrics'])>10000||!is_int($checkpoint['revision']??null)||$checkpoint['revision']<0||!is_string($checkpoint['digest']??null)){throw new \InvalidArgumentException('Semantic catalog checkpoint is invalid.');}$identities=[];foreach($checkpoint['metrics']as$id=>$metric){if(!is_string($id)||!$metric instanceof PanelSemanticMetric||$metric->id()!==$id){throw new \InvalidArgumentException('Semantic catalog checkpoint is invalid.');}$identities[$id]=spl_object_id($metric);}$digest=PanelOperationsGuard::digest(['owner'=>$this->checkpointOwner,'metrics'=>$identities,'revision'=>$checkpoint['revision']]);if(!hash_equals($checkpoint['digest'],$digest)){throw new \InvalidArgumentException('Semantic catalog checkpoint integrity check failed.');}$this->metrics=$checkpoint['metrics'];ksort($this->metrics,SORT_STRING);$this->revision=$checkpoint['revision'];return$this;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_semantic_catalog_manifest','version'=>1,'revision'=>$this->revision,'fingerprint'=>$this->fingerprint(),'metrics'=>array_map(static fn(PanelSemanticMetric $metric):array=>$metric->jsonSerialize(),$this->metrics),'capabilities'=>['shared_metric_definitions'=>true,'declared_dimensions'=>true,'deterministic_grouping'=>true,'ratio_metrics'=>true,'lineage_ready'=>true,'agent_safe_manifest'=>true,'typed_multi_metric_queries'=>true,'adapter_pushdown'=>true,'deterministic_fallback'=>true,'query_explain_plans'=>true,'tenant_and_authorization_scope'=>true,'snapshot_consistency_negotiated'=>true]]);}
}
