<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic graph analysis and callback-free structural workflow simulation. */
final class PanelWorkflowSimulator implements \JsonSerializable {
	public function analyze(WorkflowDefinition $definition):PanelWorkflowGraphAnalysis{
		$definition->assertValid();$states=array_keys($definition->states());sort($states,SORT_STRING);$adjacency=array_fill_keys($states,[]);$reverse=array_fill_keys($states,[]);$approval=0;
		foreach($definition->transitions()as$transition){if($transition->approvalPolicy() instanceof WorkflowApprovalPolicy){$approval++;}foreach($transition->from()as$from){$adjacency[$from][]=$transition->to();$reverse[$transition->to()][]=$from;}}
		foreach($states as$state){$adjacency[$state]=array_values(array_unique($adjacency[$state]));$reverse[$state]=array_values(array_unique($reverse[$state]));sort($adjacency[$state],SORT_STRING);sort($reverse[$state],SORT_STRING);}
		$reachable=$this->walk([$definition->initialState()],$adjacency);$unreachable=array_values(array_diff($states,$reachable));$terminals=[];foreach($definition->states()as$name=>$state){if($state->terminal()){$terminals[]=$name;}}sort($terminals,SORT_STRING);$terminalReachable=$terminals===[]?[]:$this->walk($terminals,$reverse);$withoutTerminal=$terminals===[]?[]:array_values(array_diff($states,$terminalReachable));$cycles=$this->cycles($states,$adjacency);
		$fingerprint=hash('sha256',WorkflowEvent::canonicalJson(['definition'=>$definition->jsonSerialize(),'reachable'=>$reachable,'without_terminal'=>$withoutTerminal,'cycles'=>$cycles]));
		return new PanelWorkflowGraphAnalysis($definition->name(),$definition->initialState(),$states,$terminals,$reachable,$unreachable,$withoutTerminal,$cycles,count($definition->transitions()),$approval,$fingerprint);
	}

	/** @param list<string> $transitionNames */
	public function simulate(WorkflowDefinition $definition,array $transitionNames=[],?string $startState=null,int $maximumSteps=128,bool $strict=true):PanelWorkflowSimulation{
		$definition->assertValid();if(!array_is_list($transitionNames)||count($transitionNames)>1024){throw new \LengthException('Workflow simulation transition sequences support at most 1024 steps.');}if($maximumSteps<1||$maximumSteps>1024||count($transitionNames)>$maximumSteps){throw new \InvalidArgumentException('Workflow simulation maximum steps must be between 1 and 1024 and cover the requested sequence.');}
		$current=WorkflowState::normalize($startState??$definition->initialState());if(!$definition->stateNamed($current) instanceof WorkflowState){throw new \InvalidArgumentException('Workflow simulation start state does not exist.');}$states=[$current];$applied=[];$approvals=[];$explicit=$transitionNames!==[];
		for($step=0;$step<$maximumSteps;$step++){
			$state=$definition->stateNamed($current);if($state?->terminal()){return$this->result($definition,$states,$applied,$approvals,true,'terminal');}
			$transition=null;if($explicit){if(!array_key_exists($step,$transitionNames)){return$this->result($definition,$states,$applied,$approvals,false,'sequence_exhausted');}$name=WorkflowState::normalize((string)$transitionNames[$step]);$transition=$definition->transitionNamed($name);if(!$transition instanceof WorkflowTransition||!$transition->accepts($current)){throw new \InvalidArgumentException("Workflow simulation transition '{$name}' is unavailable from '{$current}'.");}}
			else{$available=array_values(array_filter($definition->transitions(),static fn(WorkflowTransition $candidate):bool=>$candidate->accepts($current)));usort($available,static fn(WorkflowTransition $a,WorkflowTransition $b):int=>$a->name()<=>$b->name());$transition=$available[0]??null;if(!$transition instanceof WorkflowTransition){return$this->result($definition,$states,$applied,$approvals,false,'dead_end');}}
			if($strict&&($transition->guardResolver()!==null||$transition->assignmentResolver()!==null||$transition->compensator()!==null)){throw new \LogicException('Strict workflow simulation rejects executable callbacks.');}
			$current=$transition->to();$applied[]=$transition->name();$states[]=$current;if($transition->approvalPolicy() instanceof WorkflowApprovalPolicy){$approvals[]=$transition->name();}
		}
		$terminal=$definition->stateNamed($current)?->terminal()??false;return$this->result($definition,$states,$applied,$approvals,$terminal,$terminal?'terminal':'step_limit');
	}

	/** @param list<string> $states @param list<string> $transitions @param list<string> $approvals */
	private function result(WorkflowDefinition $definition,array $states,array $transitions,array $approvals,bool $completed,string $reason):PanelWorkflowSimulation{$fingerprint=hash('sha256',WorkflowEvent::canonicalJson(['workflow'=>$definition->name(),'definition'=>$definition->jsonSerialize(),'states'=>$states,'transitions'=>$transitions,'approvals'=>$approvals,'completed'=>$completed,'reason'=>$reason]));return new PanelWorkflowSimulation($definition->name(),$states,$transitions,$approvals,$completed,$reason,$fingerprint);}
	/** @param list<string> $start @param array<string,list<string>> $graph @return list<string> */
	private function walk(array $start,array $graph):array{$queue=$start;$seen=[];while($queue!==[]){$state=array_shift($queue);if(!is_string($state)||isset($seen[$state])){continue;}$seen[$state]=true;foreach($graph[$state]??[]as$next){if(!isset($seen[$next])){$queue[]=$next;}}}$result=array_keys($seen);sort($result,SORT_STRING);return$result;}
	/** @param list<string> $states @param array<string,list<string>> $graph @return list<list<string>> */
	private function cycles(array $states,array $graph):array{$index=0;$indexes=[];$low=[];$stack=[];$onStack=[];$cycles=[];$visit=function(string $state)use(&$visit,&$index,&$indexes,&$low,&$stack,&$onStack,&$cycles,$graph):void{$indexes[$state]=$low[$state]=$index++;$stack[]=$state;$onStack[$state]=true;foreach($graph[$state]??[]as$next){if(!array_key_exists($next,$indexes)){$visit($next);$low[$state]=min($low[$state],$low[$next]);}elseif(($onStack[$next]??false)===true){$low[$state]=min($low[$state],$indexes[$next]);}}if($low[$state]!==$indexes[$state]){return;}$component=[];do{$member=array_pop($stack);if(!is_string($member)){break;}$onStack[$member]=false;$component[]=$member;}while($member!==$state);sort($component,SORT_STRING);if(count($component)>1||(count($component)===1&&in_array($component[0],$graph[$component[0]]??[],true))){$cycles[]=$component;}};foreach($states as$state){if(!array_key_exists($state,$indexes)){$visit($state);}}usort($cycles,static fn(array $a,array $b):int=>implode("\0",$a)<=>implode("\0",$b));return$cycles;}
	public function jsonSerialize():array{return['type'=>'panel_workflow_simulator','version'=>1,'capabilities'=>['reachability'=>true,'terminal_path_analysis'=>true,'cycle_detection'=>true,'deterministic_execution'=>true,'explicit_path_execution'=>true,'approval_visibility'=>true,'callback_free_strict_mode'=>true,'side_effect_free'=>true],'limits'=>['steps'=>1024]];}
}
