<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Validated structured plan returned by an operator model. */
final class PanelOperatorProposal implements \JsonSerializable {
	/** @param list<array<string,mixed>> $steps @param list<string> $warnings */
	public function __construct(private readonly string $taskFingerprint,private readonly string $modelId,private readonly string $summary,private readonly array $steps,private readonly array $warnings,private readonly int $inputTokens,private readonly int $outputTokens,private readonly int $estimatedCostMicros){
		if(preg_match('/^[a-f0-9]{64}$/D',$taskFingerprint)!==1){throw new \InvalidArgumentException('Operator proposal task fingerprint is invalid.');}PanelOperationsGuard::name($modelId,'operator proposal model id');PanelOperationsGuard::label($summary,'operator proposal summary',4096);if(count($steps)>128||$inputTokens<0||$outputTokens<0||$estimatedCostMicros<0){throw new \InvalidArgumentException('Operator proposal exceeds its execution or economics bounds.');}foreach($steps as$index=>$step){if(!is_array($step)||($step!==[]&&array_is_list($step))){throw new \InvalidArgumentException('Operator proposal steps must be object-like maps.');}PanelOperationsGuard::name((string)($step['tool']??''),'operator step tool');PanelOperationsGuard::object(is_array($step['arguments']??null)?$step['arguments']:[],'operator step arguments',256);self::assertNoSecrets($step['arguments']??[]);if(isset($step['rationale'])&&trim((string)$step['rationale'])!==''){PanelOperationsGuard::label((string)$step['rationale'],'operator step rationale',2048);}}foreach($warnings as$warning){PanelOperationsGuard::label((string)$warning,'operator proposal warning',2048);}
	}
	/** @param array<string,mixed> $proposal */ public static function from(PanelOperatorTask $task,PanelOperatorModel $model,array $proposal):self {$steps=[];foreach(is_array($proposal['steps']??null)?$proposal['steps']:[]as$step){if(!is_array($step)){throw new \InvalidArgumentException('Operator proposal step is invalid.');}$steps[]=['tool'=>PanelOperationsGuard::name((string)($step['tool']??''),'operator step tool'),'arguments'=>PanelOperationsGuard::canonical(is_array($step['arguments']??null)?$step['arguments']:[]),'rationale'=>trim((string)($step['rationale']??''))];}$input=max($task->inputTokens(),(int)($proposal['input_tokens']??0));$output=max(0,(int)($proposal['output_tokens']??0));$warnings=array_values(array_filter(array_map(static fn(mixed $warning):string=>trim((string)$warning),is_array($proposal['warnings']??null)?$proposal['warnings']:[]),static fn(string $warning):bool=>$warning!==''));return new self($task->fingerprint(),$model->id(),PanelOperationsGuard::label((string)($proposal['summary']??'Operator proposed '.count($steps).' step(s).'),'operator proposal summary',4096),$steps,$warnings,$input,$output,$model->estimatedCost($input,$output));}
	public function taskFingerprint():string{return$this->taskFingerprint;}public function modelId():string{return$this->modelId;}public function summary():string{return$this->summary;}/** @return list<array<string,mixed>> */public function steps():array{return$this->steps;}/** @return list<string> */public function warnings():array{return$this->warnings;}public function inputTokens():int{return$this->inputTokens;}public function outputTokens():int{return$this->outputTokens;}public function estimatedCostMicros():int{return$this->estimatedCostMicros;}
	public function digest():string{return PanelOperationsGuard::digest($this->values());}
	public function jsonSerialize():array{return$this->values()+['digest'=>$this->digest()];}
	/** @return array<string,mixed> */private function values():array{return['type'=>'panel_operator_proposal','task_fingerprint'=>$this->taskFingerprint,'model_id'=>$this->modelId,'summary'=>$this->summary,'steps'=>$this->steps,'warnings'=>$this->warnings,'input_tokens'=>$this->inputTokens,'output_tokens'=>$this->outputTokens,'estimated_cost_micros'=>$this->estimatedCostMicros];}
	private static function assertNoSecrets(mixed $value,array $path=[]):void {if(!is_array($value)){return;}foreach($value as$key=>$item){$next=$path;if(is_string($key)){$next[]=$key;if(PanelSensitiveDataSanitizer::isSensitiveKey($key,$path)){throw new \InvalidArgumentException('Operator proposals cannot contain secret-bearing arguments.');}}self::assertNoSecrets($item,$next);}}
}
