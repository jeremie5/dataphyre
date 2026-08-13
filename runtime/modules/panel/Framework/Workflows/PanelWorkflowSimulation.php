<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Side-effect-free structural execution trace for a workflow definition. */
final class PanelWorkflowSimulation implements \JsonSerializable {
	private const REASONS=['terminal','dead_end','sequence_exhausted','step_limit'];
	/** @param list<string> $states @param list<string> $transitions @param list<string> $approvalTransitions */
	public function __construct(private readonly string $workflow,private readonly array $states,private readonly array $transitions,private readonly array $approvalTransitions,private readonly bool $completed,private readonly string $stopReason,private readonly string $fingerprint){
		if($states===[]||!array_is_list($states)||!array_is_list($transitions)||!array_is_list($approvalTransitions)||count($states)!==count($transitions)+1||!in_array($stopReason,self::REASONS,true)||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Workflow simulation trace is invalid.');}
		foreach([...$states,...$transitions,...$approvalTransitions]as$value){if(!is_string($value)||WorkflowState::normalize($value)!==$value){throw new \InvalidArgumentException('Workflow simulation names are invalid.');}}
		if($completed!==($stopReason==='terminal')){throw new \InvalidArgumentException('Workflow simulation completion and stop reason disagree.');}
	}
	public function workflow():string{return$this->workflow;}
	/** @return list<string> */public function states():array{return$this->states;}
	/** @return list<string> */public function transitions():array{return$this->transitions;}
	/** @return list<string> */public function approvalTransitions():array{return$this->approvalTransitions;}
	public function completed():bool{return$this->completed;}
	public function stopReason():string{return$this->stopReason;}
	public function fingerprint():string{return$this->fingerprint;}
	public function jsonSerialize():array{return['type'=>'panel_workflow_simulation','version'=>1,'workflow'=>$this->workflow,'states'=>$this->states,'transitions'=>$this->transitions,'step_count'=>count($this->transitions),'approval_transitions'=>$this->approvalTransitions,'completed'=>$this->completed,'stop_reason'=>$this->stopReason,'side_effect_free'=>true,'fingerprint'=>$this->fingerprint];}
}
