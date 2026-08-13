<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable structural analysis of one validated workflow graph. */
final class PanelWorkflowGraphAnalysis implements \JsonSerializable {
	/** @param list<string> $states @param list<string> $terminalStates @param list<string> $reachableStates @param list<string> $unreachableStates @param list<string> $statesWithoutTerminalPath @param list<list<string>> $cycles */
	public function __construct(
		private readonly string $workflow,private readonly string $initialState,private readonly array $states,
		private readonly array $terminalStates,private readonly array $reachableStates,private readonly array $unreachableStates,
		private readonly array $statesWithoutTerminalPath,private readonly array $cycles,private readonly int $transitionCount,
		private readonly int $approvalTransitionCount,private readonly string $fingerprint
	){
		foreach([$states,$terminalStates,$reachableStates,$unreachableStates,$statesWithoutTerminalPath]as$list){if(!array_is_list($list)){throw new \InvalidArgumentException('Workflow graph analysis lists must be ordered.');}foreach($list as$value){if(!is_string($value)||WorkflowState::normalize($value)!==$value){throw new \InvalidArgumentException('Workflow graph analysis state names are invalid.');}}}
		foreach($cycles as$cycle){if(!is_array($cycle)||!array_is_list($cycle)||$cycle===[]){throw new \InvalidArgumentException('Workflow graph analysis cycles are invalid.');}}
		if($transitionCount<0||$approvalTransitionCount<0||$approvalTransitionCount>$transitionCount||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Workflow graph analysis counts or fingerprint are invalid.');}
	}
	public function workflow():string{return$this->workflow;}
	public function initialState():string{return$this->initialState;}
	/** @return list<string> */public function states():array{return$this->states;}
	/** @return list<string> */public function terminalStates():array{return$this->terminalStates;}
	/** @return list<string> */public function reachableStates():array{return$this->reachableStates;}
	/** @return list<string> */public function unreachableStates():array{return$this->unreachableStates;}
	/** @return list<string> */public function statesWithoutTerminalPath():array{return$this->statesWithoutTerminalPath;}
	/** @return list<list<string>> */public function cycles():array{return$this->cycles;}
	public function transitionCount():int{return$this->transitionCount;}
	public function fingerprint():string{return$this->fingerprint;}
	public function conformant():bool{return$this->unreachableStates===[]&&($this->terminalStates===[]||$this->statesWithoutTerminalPath===[]);}
	public function jsonSerialize():array{return[
		'type'=>'panel_workflow_graph_analysis','version'=>1,'workflow'=>$this->workflow,'initial_state'=>$this->initialState,
		'state_count'=>count($this->states),'transition_count'=>$this->transitionCount,'approval_transition_count'=>$this->approvalTransitionCount,
		'states'=>$this->states,'terminal_states'=>$this->terminalStates,'reachable_states'=>$this->reachableStates,'unreachable_states'=>$this->unreachableStates,
		'states_without_terminal_path'=>$this->statesWithoutTerminalPath,'cycles'=>$this->cycles,'conformant'=>$this->conformant(),'fingerprint'=>$this->fingerprint,
	];}
}
