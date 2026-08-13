<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable evidence envelope for one governed operator lifecycle. */
final class PanelOperatorRun implements \JsonSerializable {
	public const STATUSES=['denied','routing_failed','model_failed','evaluation_failed','awaiting_approval','awaiting_confirmation','planned','completed','execution_failed'];
	/** @param list<PanelOperatorEvaluation> $evaluations */
	public function __construct(private readonly PanelOperatorTask $task,private readonly PanelPolicyDecision $decision,private readonly string $status,private readonly ?PanelOperatorModel $model=null,private readonly ?PanelOperatorProposal $proposal=null,private readonly array $evaluations=[],private readonly mixed $output=null,private readonly ?string $error=null){if(!in_array($status,self::STATUSES,true)){throw new \InvalidArgumentException('Operator run status is invalid.');}foreach($evaluations as$evaluation){if(!$evaluation instanceof PanelOperatorEvaluation){throw new \InvalidArgumentException('Operator run evaluations must be typed.');}}if($proposal!==null&&($model===null||$proposal->modelId()!==$model->id()||!hash_equals($proposal->taskFingerprint(),$task->fingerprint()))){throw new \InvalidArgumentException('Operator run proposal binding is invalid.');}if($error!==null){PanelOperationsGuard::label($error,'operator error',2048);}}
	public function task():PanelOperatorTask{return$this->task;}public function decision():PanelPolicyDecision{return$this->decision;}public function status():string{return$this->status;}public function model():?PanelOperatorModel{return$this->model;}public function proposal():?PanelOperatorProposal{return$this->proposal;}/** @return list<PanelOperatorEvaluation> */public function evaluations():array{return$this->evaluations;}public function output():mixed{return$this->output;}public function error():?string{return$this->error;}
	public function executable():bool{return in_array($this->status,['planned','awaiting_approval','awaiting_confirmation'],true)&&$this->proposal!==null&&$this->decision->allowed();}
	public function approvalTarget():string {if($this->proposal===null||$this->model===null){throw new \LogicException('Operator run has no approval target.');}return PanelOperationsGuard::digest(['task'=>$this->task->fingerprint(),'proposal'=>$this->proposal->digest(),'model'=>$this->model->fingerprint(),'policy_revision'=>$this->decision->revision()]);}
	public function withStatus(string $status,mixed $output=null,?string $error=null):self{return new self($this->task,$this->decision,$status,$this->model,$this->proposal,$this->evaluations,$output,$error);}
	public function digest():string{return PanelOperationsGuard::digest($this->evidence());}
	/** @return array<string,mixed> */public function evidence():array{return['task'=>$this->task->jsonSerialize(),'decision'=>$this->decision->jsonSerialize(),'status'=>$this->status,'model'=>$this->model?->jsonSerialize(),'proposal'=>$this->proposal?->jsonSerialize(),'evaluations'=>array_map(static fn(PanelOperatorEvaluation $evaluation):array=>$evaluation->jsonSerialize(),$this->evaluations),'output'=>$this->output===null?null:PanelSensitiveDataSanitizer::sanitize($this->output),'error'=>$this->error];}
	public function jsonSerialize():array{return['type'=>'panel_operator_run']+$this->evidence()+['digest'=>$this->digest()];}
}
