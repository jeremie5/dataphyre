<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Cost-, residency-, capability-, health-, and policy-aware deterministic model router. */
final class PanelOperatorRouter implements \JsonSerializable {
	/** @var array<string,array{model:PanelOperatorModel,adapter:PanelOperatorModelAdapter}> */ private array $models=[];
	private int $revision=0;
	public function register(PanelOperatorModel $model,PanelOperatorModelAdapter $adapter,bool $replace=false):self {if(!$replace&&isset($this->models[$model->id()])){throw new \LogicException('Operator model is already registered.');}$this->models[$model->id()]=['model'=>$model,'adapter'=>$adapter];ksort($this->models,SORT_STRING);$this->revision++;return$this;}
	public function remove(string $modelId):self {$modelId=PanelOperationsGuard::name($modelId,'operator model id');if(isset($this->models[$modelId])){unset($this->models[$modelId]);$this->revision++;}return$this;}
	public function model(string $modelId):PanelOperatorModel {$modelId=PanelOperationsGuard::name($modelId,'operator model id');$entry=$this->models[$modelId]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Operator model is not registered.');}return$entry['model'];}
	public function adapter(string $modelId):PanelOperatorModelAdapter {$modelId=PanelOperationsGuard::name($modelId,'operator model id');$entry=$this->models[$modelId]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Operator model is not registered.');}return$entry['adapter'];}
	/** @param array<string,mixed> $obligations */ public function route(PanelOperatorTask $task,array $obligations=[]):PanelOperatorModel {$allowed=is_array($obligations['allowed_models']??null)?$obligations['allowed_models']:[];$denied=is_array($obligations['denied_models']??null)?$obligations['denied_models']:[];$policyBudget=max(0,(int)($obligations['max_cost_micros']??0));$budget=$task->maxCostMicros();if($policyBudget>0){$budget=$budget>0?min($budget,$policyBudget):$policyBudget;}$eligible=[];foreach($this->models as$entry){$model=$entry['model'];if(!$model->supports($task)||($allowed!==[]&&!in_array($model->id(),$allowed,true))||in_array($model->id(),$denied,true)){continue;}$cost=$model->estimatedCost($task->inputTokens(),$task->maxOutputTokens());if($budget>0&&$cost>$budget){continue;}$eligible[]=['model'=>$model,'cost'=>$cost];}if($eligible===[]){throw new \LogicException('No governed operator model satisfies the task and policy constraints.');}usort($eligible,static fn(array $a,array $b):int=>[$a['model']->health()==='ready'?0:1,$a['cost'],$a['model']->latencyRank(),$a['model']->id()]<=>[$b['model']->health()==='ready'?0:1,$b['cost'],$b['model']->latencyRank(),$b['model']->id()]);return$eligible[0]['model'];}
	public function revision():int{return$this->revision;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_operator_router_manifest','version'=>1,'revision'=>$this->revision,'models'=>array_map(static fn(array $entry):array=>$entry['model']->jsonSerialize(),$this->models),'adapter_count'=>count($this->models),'adapters_exposed'=>false,'deterministic_routing'=>true,'residency_aware'=>true,'classification_aware'=>true,'budget_aware'=>true,'health_aware'=>true]);}
}
