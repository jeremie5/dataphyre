<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Audited conversion from signed domain artifacts to existing typed Panel runtimes. */
final class PanelDomainMaterializer implements \JsonSerializable {
	public const VERSION=1;

	public function __construct(
		private readonly ?PanelPolicyControlPlane $policy=null,
		private readonly ?PanelDomainCommandExecutor $executor=null,
		private readonly ?PanelDomainCommandContextResolver $contextResolver=null,
	){
		if(($executor===null)!==($contextResolver===null)){throw new \InvalidArgumentException('Domain command execution requires both an executor and context resolver.');}
		if($executor!==null&&$policy===null){throw new \InvalidArgumentException('Executable domain commands require the unified policy control plane.');}
	}

	public function materialize(PanelDomainCompilation $compilation):PanelDomainMaterialization {
		if(!$compilation->signed()){throw new \LogicException('Unsigned domain compilations cannot be materialized.');}
		$source=$compilation->artifact('source');$artifacts=$compilation->artifact('resources');
		if(!is_array($source)||!is_array($artifacts)){throw new \UnexpectedValueException('Compiled domain materialization artifacts are invalid.');}
		$domain=$compilation->domainId();$version=$compilation->domainVersion();$commands=[];
		foreach($source['commands']??[]as$name=>$definition){if(!is_array($definition)){throw new \UnexpectedValueException('Compiled domain command is invalid.');}$commands[(string)$name]=PanelDomainCommandDefinition::from($domain,$version,(string)$name,$definition);}ksort($commands,SORT_STRING);

		$resources=[];
		foreach($artifacts as$entity=>$artifact){
			if(!is_string($entity)||!is_array($artifact)||!is_array(($source['entities']??[])[$entity]??null)){throw new \UnexpectedValueException('Compiled domain resource is invalid.');}
			$resources[$domain.'.'.$entity]=$this->resource($domain,$entity,$source['entities'][$entity],$artifact,$commands);
		}
		ksort($resources,SORT_STRING);

		$workflows=[];
		foreach($source['workflows']??[]as$name=>$definition){if(!is_array($definition)){throw new \UnexpectedValueException('Compiled domain workflow is invalid.');}$workflow=$this->workflow($domain,(string)$name,$definition,$commands);$workflows[$workflow->name()]=$workflow;}
		ksort($workflows,SORT_STRING);

		$automation=[];
		foreach($commands as$command){$action=$this->automation($command);$automation[$action->name()]=$action;}ksort($automation,SORT_STRING);
		$bundle=$this->policyBundle($compilation,$source);
		$tools=$this->agentTools($source,$commands);
		$queues=PanelOperationsGuard::canonical(is_array($source['queues']??null)?$source['queues']:[]);
		$surfaces=PanelOperationsGuard::canonical(is_array($source['surfaces']??null)?$source['surfaces']:[]);
		return new PanelDomainMaterialization($compilation,$this->fingerprint(),$resources,$workflows,$commands,$automation,$bundle,$tools,$queues,$surfaces,$this->executor!==null);
	}

	public function fingerprint():string{return PanelOperationsGuard::digest($this->baseManifest());}
	public function jsonSerialize():array{return PanelManifestContract::stamp($this->baseManifest()+['fingerprint'=>$this->fingerprint()]);}
	/** @return array<string,mixed> */private function baseManifest():array{return[
		'type'=>'panel_domain_materializer_manifest','version'=>self::VERSION,'input'=>'signed_panel_domain_compilation',
		'outputs'=>['resources','workflows','automation_actions','policy_bundle','agent_tools','queues','surfaces'],
		'executable_commands'=>$this->executor!==null,'default_deny'=>$this->policy!==null,'callbacks_from_domain'=>false,'raw_php_from_domain'=>false,
	];}

	/** @param array<string,mixed> $entity @param array<string,mixed> $artifact @param array<string,PanelDomainCommandDefinition> $commands */
	private function resource(string $domain,string $entity,array $definition,array $artifact,array $commands):Resource {
		$name=$domain.'.'.$entity;$fields=[];$columns=[];
		foreach($definition['fields']??[]as$fieldName=>$fieldDefinition){
			if(!is_array($fieldDefinition)){throw new \UnexpectedValueException('Compiled domain field is invalid.');}
			$artifactField=is_array(($artifact['fields']??[])[$fieldName]??null)?$artifact['fields'][$fieldName]:[];
			$type=(string)($artifactField['type']??'text');$field=Field::make((string)$fieldName,$type)->label((string)($fieldDefinition['label']??$fieldName))->nullable(($fieldDefinition['nullable']??true)!==false);
			if(($fieldDefinition['required']??false)===true){$field=$field->required();}
			if(isset($fieldDefinition['enum'])&&is_array($fieldDefinition['enum'])){$options=[];foreach($fieldDefinition['enum']as$option){$options[(string)$option]=(string)$option;}$field=$field->options($options);}
			if(array_key_exists('default',$fieldDefinition)){$field=$field->default($fieldDefinition['default']);}
			$field=$field->meta(['domain_id'=>$domain,'entity'=>$entity,'classification'=>$fieldDefinition['classification']??'internal','immutable'=>($fieldDefinition['immutable']??false)===true]);$fields[]=$field;
			$artifactColumn=is_array(($artifact['columns']??[])[$fieldName]??null)?$artifact['columns'][$fieldName]:[];
			$column=Column::make((string)$fieldName,(string)($artifactColumn['type']??'text'))->label((string)($fieldDefinition['label']??$fieldName));
			if(($artifactColumn['searchable']??false)===true){$column=$column->searchable();}if(($artifactColumn['sortable']??false)===true){$column=$column->sortable();}
			$columns[]=$column->meta(['domain_id'=>$domain,'entity'=>$entity,'classification'=>$fieldDefinition['classification']??'internal']);
		}
		$actions=[];foreach($commands as$command){if($command->entity()===$entity){$actions[]=$this->panelAction($command);}}
		$resource=Resource::make($name)->label((string)($definition['label']??$entity))->pluralLabel((string)($definition['label']??$entity))->url('/'.$domain.'/'.$entity)->recordKeyUsing((string)($definition['primary_key']??'id'));
		return$resource->form(ResourceForm::make()->fields($fields))->resourceTable(ResourceTable::make()->columns($columns))->actions($actions);
	}

	private function panelAction(PanelDomainCommandDefinition $command):Action {
		$action=Action::make($command->name())->label($command->label())->description('Execute '.$command->operation().' for this '.$command->entity().'.')->tone(in_array($command->risk(),['high','critical'],true)?'danger':($command->risk()==='medium'?'warning':'primary'))->meta(['domain_command'=>$command->qualifiedName(),'ability'=>$command->ability(),'risk'=>$command->risk(),'approval_count'=>$command->approvalCount(),'reversible'=>$command->reversible()]);
		if(in_array($command->risk(),['high','critical'],true)||$command->approvalCount()>0){$action=$action->requiresConfirmation()->confirmation('Confirm '.$command->label().'.');}
		if($this->executor===null||$this->contextResolver===null){return$action->disabled(true,'Domain command execution is not configured.');}
		if($command->approvalCount()>0){return$action->disabled(true,'This command must be executed through the approval center.');}
		return$action->handle(function(mixed $record,array $data,mixed $request,?Resource $resource)use($command):mixed{
			$invocation=$this->contextResolver?->resolve($command,$record,$data,$request,$resource);if(!$invocation instanceof PanelDomainCommandInvocation){throw new \LogicException('Domain command context resolution failed closed.');}
			return$this->execute($invocation);
		});
	}

	/** @param array<string,mixed> $definition @param array<string,PanelDomainCommandDefinition> $commands */
	private function workflow(string $domain,string $name,array $definition,array $commands):WorkflowDefinition {
		$runtimeName=WorkflowState::normalize($domain.'_'.$name);$workflow=WorkflowDefinition::make($runtimeName,(string)($definition['label']??$name));$outgoing=[];foreach($definition['transitions']??[]as$transition){if(is_array($transition)){$outgoing[(string)($transition['from']??'')]=true;}}
		foreach($definition['states']??[]as$state){$state=(string)$state;$workflow=$workflow->state(WorkflowState::make($state,['terminal'=>!isset($outgoing[$state]),'metadata'=>['domain_id'=>$domain,'entity'=>$definition['entity']??'']]));}
		$workflow=$workflow->initial((string)($definition['initial']??''));
		foreach($definition['transitions']??[]as$transition){
			if(!is_array($transition)){throw new \UnexpectedValueException('Compiled domain workflow transition is invalid.');}
			$commandName=(string)($transition['command']??'');$command=$commands[$commandName]??null;$edge=WorkflowTransition::make((string)$transition['name'],(string)$transition['from'],(string)$transition['to'])->metadata(['domain_id'=>$domain,'domain_workflow'=>$name,'domain_command'=>$command?->qualifiedName()]);
			if(($transition['sla_seconds']??0)>0){$edge=$edge->sla((int)$transition['sla_seconds']);}if(($transition['reversible']??false)===true||$command?->reversible()===true){$edge=$edge->reversible();}
			if(($command?->approvalCount()??0)>0){$edge=$edge->approval(new WorkflowApprovalPolicy($command->approvalCount(),[],[],true,false));}
			$workflow=$workflow->transition($edge);
		}
		return$workflow->metadata(['domain_id'=>$domain,'domain_workflow'=>$name,'entity'=>$definition['entity']??''])->assertValid();
	}

	private function automation(PanelDomainCommandDefinition $command):AutomationAction {
		$action=AutomationAction::make($command->domainId().'_'.$command->name())->label($command->label())->description('Materialized domain command '.$command->qualifiedName().'.')->version($command->domainVersion())->risk($command->risk())->inputSchema($command->inputSchema())->metadata(['domain_id'=>$command->domainId(),'domain_command'=>$command->qualifiedName(),'ability'=>$command->ability(),'effects'=>$command->effects()]);
		if(in_array($command->risk(),['high','critical'],true)){$action=$action->confirmation('explicit');}
		$action=$action->policy(function(array $input,WorkflowActor $actor,array $context)use($command):AutomationPolicyDecision{
			if($this->policy===null){return AutomationPolicyDecision::deny('Domain policy control plane is not configured.');}
			$tenant=isset($context['tenant_id'])?(string)$context['tenant_id']:null;$recordId=isset($context['record_id'])?(string)$context['record_id']:null;
			try{$decision=$this->policy->evaluate(new PanelPolicyRequest($actor->id(),$command->ability(),$tenant,$recordId!==null?$command->entity():null,$recordId,$command->risk(),$actor->roles(),$actor->permissions(),['domain_command'=>$command->qualifiedName()]));}catch(\Throwable $error){return AutomationPolicyDecision::deny('Domain policy evaluation failed closed.',['error'=>$error::class]);}
			if(!$decision->allowed()){return AutomationPolicyDecision::deny(implode(' ',$decision->reasons()),['decision'=>$decision->jsonSerialize()]);}
			$decisionFingerprint=PanelOperationsGuard::digest($decision->jsonSerialize());
			$required=max($command->approvalCount(),(int)($decision->obligations()['approval_count']??0));if($required>0){return AutomationPolicyDecision::approval('Domain command requires independent approval.',['approval_count'=>$required,'domain_command'=>$command->qualifiedName(),'decision_fingerprint'=>$decisionFingerprint]);}
			return AutomationPolicyDecision::allow('Unified domain policy allowed execution.',['decision_fingerprint'=>$decisionFingerprint]);
		});
		if($this->executor===null){return$action;}
		return$action->handle(function(array $input,array $context,WorkflowActor $actor,AutomationPlan $plan)use($command):mixed{
			$tenant=PanelOperationsGuard::identifier((string)($context['tenant_id']??''),'domain command tenant id');$recordId=isset($context['record_id'])?PanelOperationsGuard::identifier((string)$context['record_id'],'domain command record id'):null;
			return$this->execute(new PanelDomainCommandInvocation($command,$tenant,$actor->id(),'automation:'.$plan->hash(),$input,$recordId,false,true,['automation_plan'=>$plan->hash()]));
		});
	}

	/** @param array<string,mixed> $source */
	private function policyBundle(PanelDomainCompilation $compilation,array $source):?PanelPolicyBundle {
		$rules=is_array($source['policies']??null)?$source['policies']:[];if($rules===[]){return null;}
		return PanelPolicyBundle::from(['id'=>'domain.'.$compilation->domainId(),'version'=>$compilation->domainVersion(),'rules'=>$rules,'metadata'=>['domain_id'=>$compilation->domainId(),'compilation_digest'=>$compilation->digest()]]);
	}

	/** @param array<string,mixed> $source @param array<string,PanelDomainCommandDefinition> $commands @return array<string,PanelAgentTool> */
	private function agentTools(array $source,array $commands):array {
		if($this->executor===null){return[];}
		$allowed=[];foreach($source['agents']??[]as$agentName=>$agent){if(!is_array($agent)){throw new \UnexpectedValueException('Compiled domain agent is invalid.');}foreach($agent['commands']??[]as$command){$allowed[(string)$command][]=(string)$agentName;}}
		$tools=[];foreach($allowed as$name=>$agents){$command=$commands[$name]??null;if(!$command){throw new \UnexpectedValueException('Compiled domain agent command is missing.');}if($command->approvalCount()>2){throw new \LogicException('Agent-safe domain commands currently support at most two approvals.');}$tool=new PanelAgentTool($command->qualifiedName(),$command->domainVersion(),'Execute '.$command->label().'.',$command->ability(),$command->risk(),true,in_array($command->risk(),['high','critical'],true),$command->approvalCount(),$command->approvalCount()>0,$command->inputSchema(),false,65536,2048,['domain_id'=>$command->domainId(),'domain_command'=>$command->name(),'agents'=>array_values(array_unique($agents))]);$tools[$tool->name()]=$tool;}ksort($tools,SORT_STRING);return$tools;
	}

	private function execute(PanelDomainCommandInvocation $invocation):mixed {
		if($this->policy===null||$this->executor===null){throw new \LogicException('Domain command execution is not configured.');}
		$command=$invocation->command();$decision=$this->policy->evaluate(new PanelPolicyRequest($invocation->actorId(),$command->ability(),$invocation->tenantId(),$command->entity(),$invocation->recordId(),$command->risk(),[],[],['domain_command'=>$command->qualifiedName(),'dry_run'=>$invocation->dryRun()]));$decision->assertAllowed();
		if($command->approvalCount()>0||(int)($decision->obligations()['approval_count']??0)>0){throw new \LogicException('Domain command execution requires an approved command envelope.');}
		if((($decision->obligations()['confirmation']??false)===true||in_array($command->risk(),['high','critical'],true))&&!$invocation->confirmed()){throw new \LogicException('Domain command execution requires explicit confirmation.');}
		return$this->executor->execute($invocation);
	}
}
