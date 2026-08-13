<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Runtime-only typed builders produced from one verified domain compilation. */
final class PanelDomainMaterialization implements \JsonSerializable {
	/**
	 * @param array<string,Resource> $resources
	 * @param array<string,WorkflowDefinition> $workflows
	 * @param array<string,PanelDomainCommandDefinition> $commands
	 * @param array<string,AutomationAction> $automationActions
	 * @param array<string,PanelAgentTool> $agentTools
	 * @param array<string,array<string,mixed>> $queues
	 * @param array<string,array<string,mixed>> $surfaces
	 */
	public function __construct(
		private readonly PanelDomainCompilation $compilation,
		private readonly string $materializerFingerprint,
		private readonly array $resources,
		private readonly array $workflows,
		private readonly array $commands,
		private readonly array $automationActions,
		private readonly ?PanelPolicyBundle $policyBundle,
		private readonly array $agentTools,
		private readonly array $queues,
		private readonly array $surfaces,
		private readonly bool $commandsExecutable,
	){
		if(!$compilation->signed()){throw new \InvalidArgumentException('Domain materialization requires a signed compilation.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$materializerFingerprint)!==1){throw new \InvalidArgumentException('Domain materializer fingerprint is invalid.');}
		$this->typed($resources,Resource::class,'resource');$this->typed($workflows,WorkflowDefinition::class,'workflow');
		$this->typed($commands,PanelDomainCommandDefinition::class,'command');$this->typed($automationActions,AutomationAction::class,'automation action');
		$this->typed($agentTools,PanelAgentTool::class,'agent tool');
		PanelOperationsGuard::object($queues,'domain materialization queues',1024);PanelOperationsGuard::object($surfaces,'domain materialization surfaces',2048);
	}

	public function compilation():PanelDomainCompilation{return$this->compilation;}
	public function domainId():string{return$this->compilation->domainId();}
	public function domainVersion():string{return$this->compilation->domainVersion();}
	/** @return array<string,Resource> */public function resources():array{return$this->resources;}
	/** @return array<string,WorkflowDefinition> */public function workflows():array{return$this->workflows;}
	/** @return array<string,PanelDomainCommandDefinition> */public function commands():array{return$this->commands;}
	/** @return array<string,AutomationAction> */public function automationActions():array{return$this->automationActions;}
	public function policyBundle():?PanelPolicyBundle{return$this->policyBundle;}
	/** @return array<string,PanelAgentTool> */public function agentTools():array{return$this->agentTools;}
	/** @return array<string,array<string,mixed>> */public function queues():array{return$this->queues;}
	/** @return array<string,array<string,mixed>> */public function surfaces():array{return$this->surfaces;}
	public function commandsExecutable():bool{return$this->commandsExecutable;}

	public function fingerprint():string{return PanelOperationsGuard::digest($this->contract());}
	public function jsonSerialize():array{return PanelManifestContract::stamp($this->contract()+['fingerprint'=>$this->fingerprint()]);}

	/** @return array<string,mixed> */
	private function contract():array{return[
		'type'=>'panel_domain_materialization_manifest','version'=>1,'domain_id'=>$this->domainId(),'domain_version'=>$this->domainVersion(),
		'compilation_digest'=>$this->compilation->digest(),'materializer_fingerprint'=>$this->materializerFingerprint,
		'resources'=>array_keys($this->resources),'workflows'=>array_keys($this->workflows),'commands'=>array_map(static fn(PanelDomainCommandDefinition $command):array=>$command->jsonSerialize(),$this->commands),
		'automation_actions'=>array_keys($this->automationActions),'policy_bundle_digest'=>$this->policyBundle?->digest(),
		'agent_tools'=>array_keys($this->agentTools),'queues'=>$this->queues,'surfaces'=>$this->surfaces,
		'runtime'=>['actual_panel_builders'=>true,'commands_executable'=>$this->commandsExecutable,'callbacks_from_domain'=>false,'raw_php_from_domain'=>false,'objects_serialized'=>false],
	];}

	/** @param array<string,mixed> $values */
	private function typed(array $values,string $class,string $label):void {
		foreach($values as$name=>$value){if(!is_string($name)||!$value instanceof $class){throw new \InvalidArgumentException('Domain materialization '.$label.' map is invalid.');}}
	}
}
