<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Collision-safe registry and discovery manifest for automation actions.
 */
final class AutomationRegistry implements PanelCheckpointableService, \JsonSerializable {
	private const MAX_ACTIONS=4096;
	/** @var array<string,AutomationAction> */
	private array $actions=[];
	private int $revision=0;
	private readonly string $checkpointOwner;

	/** @param iterable<AutomationAction> $actions */
	public function __construct(iterable $actions=[]) {
		$this->checkpointOwner=bin2hex(random_bytes(16));
		foreach($actions as $action){ $this->register($action); }
	}

	public function register(AutomationAction $action, bool $replace=false): self {
		$name=$action->name();
		if(isset($this->actions[$name]) && !$replace){
			throw new \LogicException("Automation action '{$name}' is already registered.");
		}
		if(!isset($this->actions[$name])&&count($this->actions)>=self::MAX_ACTIONS){throw new \OverflowException('Panel automation registry capacity is exhausted.');}
		$this->actions[$name]=$action;
		$this->revision++;
		return $this;
	}

	public function unregister(string $name): self { $name=WorkflowState::normalize($name);if(isset($this->actions[$name])){unset($this->actions[$name]);$this->revision++;}return $this; }
	public function get(string $name): ?AutomationAction { return $this->actions[WorkflowState::normalize($name)] ?? null; }
	public function has(string $name): bool { return $this->get($name) instanceof AutomationAction; }
	/** @return array<string,AutomationAction> */
	public function actions(): array { ksort($this->actions); return $this->actions; }
	public function revision():int{return$this->revision;}
	public function checkpointType():string{return'panel_automation_registry_v1';}
	/** @return array{owner:string,actions:array<string,AutomationAction>,revision:int,digest:string} */
	public function checkpoint():array{return['owner'=>$this->checkpointOwner,'actions'=>$this->actions,'revision'=>$this->revision,'digest'=>$this->checkpointDigest($this->actions,$this->revision)];}
	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self{
		if(array_keys($checkpoint)!==['owner','actions','revision','digest']||!is_string($checkpoint['owner'])||!hash_equals($this->checkpointOwner,$checkpoint['owner'])||!is_array($checkpoint['actions'])||count($checkpoint['actions'])>self::MAX_ACTIONS||!is_int($checkpoint['revision'])||$checkpoint['revision']<0||!is_string($checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel automation registry checkpoint.');}
		foreach($checkpoint['actions']as$name=>$action){if(!is_string($name)||WorkflowState::normalize($name)!==$name||!$action instanceof AutomationAction||$action->name()!==$name){throw new \InvalidArgumentException('Invalid Panel automation registry checkpoint.');}}
		if(!hash_equals($this->checkpointDigest($checkpoint['actions'],$checkpoint['revision']),$checkpoint['digest'])){throw new \InvalidArgumentException('Invalid Panel automation registry checkpoint.');}
		$this->actions=$checkpoint['actions'];ksort($this->actions,SORT_STRING);$this->revision=$checkpoint['revision'];return$this;
	}
	/** @param array<string,AutomationAction> $actions */ private function checkpointDigest(array $actions,int $revision):string{$identities=[];foreach($actions as$name=>$action){$identities[$name]=spl_object_id($action);}return hash('sha256',json_encode(['owner'=>$this->checkpointOwner,'actions'=>$identities,'revision'=>$revision],JSON_THROW_ON_ERROR));}

	public function jsonSerialize(): array {
		$actions=array_map(static fn(AutomationAction $action): array=>$action->jsonSerialize(), array_values($this->actions()));
		return [
			'type'=>'panel_automation_registry', 'action_count'=>count($actions), 'actions'=>$actions,'revision'=>$this->revision,
			'capabilities'=>[
				'rollback'=>count(array_filter($actions, static fn(array $action): bool=>$action['capabilities']['rollback']===true)),
				'idempotency_required'=>count(array_filter($actions, static fn(array $action): bool=>$action['idempotency']['required']===true)),
				'high_risk'=>count(array_filter($actions, static fn(array $action): bool=>in_array($action['risk'], ['high','critical'], true))),
			],
		];
	}
}
