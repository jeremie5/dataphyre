<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Complete workflow graph with validated states, transitions, and public manifest.
 */
final class WorkflowDefinition implements \JsonSerializable {
	/** @var array<string,WorkflowState> */
	private array $states=[];
	/** @var array<string,WorkflowTransition> */
	private array $transitions=[];
	private ?string $initial=null;
	/** @var array<string,mixed> */
	private array $metadata=[];

	private function __construct(private readonly string $name, private readonly string $label) {
		if(WorkflowState::normalize($name)===''){
			throw new \InvalidArgumentException('Workflow definitions require a non-empty name.');
		}
	}

	public static function make(string $name, ?string $label=null): self {
		$name=WorkflowState::normalize($name);
		$label=trim((string)$label);
		return new self($name, $label!=='' ? $label : ucwords(str_replace('_', ' ', $name)));
	}

	/** @param WorkflowState|string $state @param array<string,mixed> $options */
	public function state(WorkflowState|string $state, array $options=[]): self {
		$state=is_string($state) ? WorkflowState::make($state, $options) : $state;
		$clone=clone $this;
		$clone->states[$state->name()]=$state;
		$clone->initial ??= $state->name();
		return $clone;
	}

	public function initial(string $state): self {
		$clone=clone $this;
		$clone->initial=WorkflowState::normalize($state);
		return $clone;
	}

	public function transition(WorkflowTransition $transition): self {
		$clone=clone $this;
		$clone->transitions[$transition->name()]=$transition;
		return $clone;
	}

	/** @param array<string,mixed> $metadata */
	public function metadata(array $metadata): self {
		$clone=clone $this;
		$clone->metadata=array_replace($clone->metadata, $metadata);
		return $clone;
	}

	public function name(): string { return WorkflowState::normalize($this->name); }
	public function label(): string { return $this->label; }
	public function initialState(): string { return $this->initial ?? ''; }
	/** @return array<string,WorkflowState> */
	public function states(): array { return $this->states; }
	/** @return array<string,WorkflowTransition> */
	public function transitions(): array { return $this->transitions; }
	public function stateNamed(string $name): ?WorkflowState { return $this->states[WorkflowState::normalize($name)] ?? null; }
	public function transitionNamed(string $name): ?WorkflowTransition { return $this->transitions[WorkflowState::normalize($name)] ?? null; }
	/** @return array<string,mixed> */
	public function metadataValues(): array { return $this->metadata; }

	/** @return list<string> */
	public function validationErrors(): array {
		$errors=[];
		if($this->states===[]){
			$errors[]='Workflow has no states.';
		}
		if($this->initial===null || !isset($this->states[$this->initial])){
			$errors[]='Workflow initial state does not exist.';
		}
		foreach($this->transitions as $transition){
			foreach($transition->from() as $from){
				if(!isset($this->states[$from])){
					$errors[]="Transition '{$transition->name()}' references missing source state '{$from}'.";
				}
			}
			if(!isset($this->states[$transition->to()])){
				$errors[]="Transition '{$transition->name()}' references missing target state '{$transition->to()}'.";
			}
		}
		return $errors;
	}

	public function assertValid(): self {
		$errors=$this->validationErrors();
		if($errors!==[]){
			throw new \LogicException(implode(' ', $errors));
		}
		return $this;
	}

	public function jsonSerialize(): array {
		$states=array_map(static fn(WorkflowState $state): array=>$state->jsonSerialize(), array_values($this->states));
		$transitions=array_map(static fn(WorkflowTransition $transition): array=>$transition->jsonSerialize(), array_values($this->transitions));
		return [
			'type'=>'panel_workflow_definition', 'name'=>$this->name(), 'label'=>$this->label,
			'initial_state'=>$this->initial, 'valid'=>$this->validationErrors()===[],
			'validation_errors'=>$this->validationErrors(), 'states'=>$states,
			'transitions'=>$transitions,
			'capabilities'=>[
				'drafts'=>count(array_filter($states, static fn(array $state): bool=>$state['draft']===true)),
				'terminal_states'=>count(array_filter($states, static fn(array $state): bool=>$state['terminal']===true)),
				'approvals'=>count(array_filter($transitions, static fn(array $transition): bool=>$transition['approval']!==null)),
				'guards'=>count(array_filter($transitions, static fn(array $transition): bool=>$transition['guarded']===true)),
				'rollback'=>count(array_filter($transitions, static fn(array $transition): bool=>$transition['reversible']===true)),
				'sla'=>count(array_filter($states, static fn(array $state): bool=>$state['sla_seconds']!==null)) + count(array_filter($transitions, static fn(array $transition): bool=>$transition['sla_seconds']!==null)),
			],
			'metadata'=>$this->metadata,
		];
	}
}
