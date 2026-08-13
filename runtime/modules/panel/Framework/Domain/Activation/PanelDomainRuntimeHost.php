<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Atomically contributes materialized domains to Panel's existing runtimes.
 *
 * Checkpoints are trusted in-process rollback units. They are deliberately not
 * persistence formats because they contain builders, executors, and closures.
 */
final class PanelDomainRuntimeHost implements \JsonSerializable {
	/** @var array<string,PanelDomainMaterialization> */private array $active=[];
	/** @var array<int,PanelManager> */private array $managers=[];
	/** @var array<string,string> */private array $policyKeys;

	/** @param array<string,string> $policyKeys @param iterable<PanelManager> $managers */
	public function __construct(
		private readonly ?WorkflowEngine $workflows=null,
		private readonly ?AutomationRegistry $automation=null,
		private readonly ?AutomationExecutor $automationExecutor=null,
		private readonly ?PanelPolicyControlPlane $policy=null,
		private readonly ?PanelAgentToolCatalog $agents=null,
		private readonly ?PanelSemanticCatalog $semantics=null,
		private readonly ?PanelLineageGraph $lineage=null,
		array $policyKeys=[],
		private readonly ?string $policyKeyId=null,
		iterable $managers=[],
	){
		$keys=[];foreach($policyKeys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'domain runtime policy key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Domain runtime policy keys require at least 32 bytes.');}$keys[$id]=$key;}ksort($keys,SORT_STRING);$this->policyKeys=$keys;
		if($policyKeyId!==null&&(!isset($keys[$policyKeyId])||PanelOperationsGuard::name($policyKeyId,'domain runtime current policy key')!==$policyKeyId)){throw new \InvalidArgumentException('Domain runtime current policy key is not present.');}
		if(($automationExecutor===null)!==($automation===null)){throw new \InvalidArgumentException('Domain runtime automation registry and executor must be configured together.');}
		foreach($managers as$manager){if(!$manager instanceof PanelManager){throw new \InvalidArgumentException('Domain runtime managers must be PanelManager instances.');}$this->managers[spl_object_id($manager)]=$manager;}
	}

	public function attachManager(PanelManager $manager):self {
		$id=spl_object_id($manager);if(isset($this->managers[$id])){return$this;}$checkpoint=$manager->contributionCheckpoint();
		try{$this->assertManagerCollisions($manager,null);foreach($this->active as$materialization){foreach($materialization->resources()as$resource){$manager->register($resource);}}$this->managers[$id]=$manager;}catch(\Throwable $error){$manager->restoreContributionCheckpoint($checkpoint);throw$error;}return$this;
	}

	public function detachManager(PanelManager $manager,bool $removeContributions=true):self {
		$id=spl_object_id($manager);if(!isset($this->managers[$id])){return$this;}if($removeContributions){$checkpoint=$manager->contributionCheckpoint();foreach($this->active as$materialization){foreach(array_keys($materialization->resources())as$name){unset($checkpoint['resources'][$name]);}}$manager->restoreContributionCheckpoint($checkpoint);}unset($this->managers[$id]);return$this;
	}

	public function activate(PanelDomainMaterialization $materialization):self {
		$domain=$materialization->domainId();$previous=$this->active[$domain]??null;
		if($previous instanceof PanelDomainMaterialization&&hash_equals($previous->fingerprint(),$materialization->fingerprint())){return$this;}
		$this->assertChannels($materialization);$this->assertCollisions($materialization,$previous);$checkpoint=$this->checkpoint();
		try{
			if($previous instanceof PanelDomainMaterialization){$this->remove($previous);}
			$this->contribute($materialization);$this->active[$domain]=$materialization;ksort($this->active,SORT_STRING);
		}catch(\Throwable $error){$this->restore($checkpoint);throw$error;}
		return$this;
	}

	public function deactivate(string $domainId):self {
		$domainId=PanelOperationsGuard::name($domainId,'domain runtime domain id');$materialization=$this->active[$domainId]??null;if(!$materialization instanceof PanelDomainMaterialization){return$this;}$checkpoint=$this->checkpoint();try{$this->remove($materialization);unset($this->active[$domainId]);}catch(\Throwable $error){$this->restore($checkpoint);throw$error;}return$this;
	}

	/** Re-applies the currently active materialization after verified drift. */
	public function reconcile(string $domainId):self {
		$domainId=PanelOperationsGuard::name($domainId,'domain runtime reconcile id');$materialization=$this->active[$domainId]??null;if(!$materialization instanceof PanelDomainMaterialization){throw new \OutOfBoundsException('Domain runtime is not active.');}$checkpoint=$this->checkpoint();
		try{$this->remove($materialization);$this->assertChannels($materialization);$this->assertCollisions($materialization,null);$this->contribute($materialization);$this->active[$domainId]=$materialization;}catch(\Throwable $error){$this->restore($checkpoint);throw$error;}return$this;
	}

	public function active(string $domainId):?PanelDomainMaterialization{return$this->active[PanelOperationsGuard::name($domainId,'domain runtime domain id')]??null;}
	/** @return array<string,PanelDomainMaterialization> */public function activeDomains():array{return$this->active;}

	/** @return array<string,mixed> */
	public function checkpoint():array {
		$managers=[];foreach($this->managers as$id=>$manager){$managers[(string)$id]=['manager'=>$manager,'checkpoint'=>$manager->contributionCheckpoint()];}
		return['active'=>$this->active,'workflows'=>$this->workflows?->checkpoint(),'automation'=>$this->automation?->checkpoint(),'policy'=>$this->policy?->checkpoint(),'agents'=>$this->agents?->checkpoint(),'semantics'=>$this->semantics?->checkpoint(),'lineage'=>$this->lineage?->checkpoint(),'managers'=>$managers];
	}

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self {
		if(array_keys($checkpoint)!==['active','workflows','automation','policy','agents','semantics','lineage','managers']||!is_array($checkpoint['active'])||!is_array($checkpoint['managers'])){throw new \InvalidArgumentException('Domain runtime host checkpoint is invalid.');}
		foreach($checkpoint['active']as$domain=>$materialization){if(!is_string($domain)||!$materialization instanceof PanelDomainMaterialization||$materialization->domainId()!==$domain){throw new \InvalidArgumentException('Domain runtime host checkpoint is invalid.');}}
		$this->restoreService($this->workflows,$checkpoint['workflows'],'workflow');$this->restoreService($this->automation,$checkpoint['automation'],'automation');
		if(($this->policy===null)!==($checkpoint['policy']===null)||($this->agents===null)!==($checkpoint['agents']===null)){throw new \InvalidArgumentException('Domain runtime host checkpoint services do not match.');}
		if($this->policy!==null){if(!is_array($checkpoint['policy'])){throw new \InvalidArgumentException('Domain runtime policy checkpoint is invalid.');}$this->policy->restore($checkpoint['policy']);}
		if($this->agents!==null){if(!is_array($checkpoint['agents'])){throw new \InvalidArgumentException('Domain runtime agent checkpoint is invalid.');}$this->agents->restore($checkpoint['agents']);}
		$this->restoreService($this->semantics,$checkpoint['semantics'],'semantic');$this->restoreService($this->lineage,$checkpoint['lineage'],'lineage');
		$managers=[];foreach($checkpoint['managers']as$id=>$entry){$managerId=(string)$id;if(!is_array($entry)||array_keys($entry)!==['manager','checkpoint']||!$entry['manager'] instanceof PanelManager||!is_array($entry['checkpoint'])||(string)spl_object_id($entry['manager'])!==$managerId){throw new \InvalidArgumentException('Domain runtime manager checkpoint is invalid.');}$entry['manager']->restoreContributionCheckpoint($entry['checkpoint']);$managers[(int)$managerId]=$entry['manager'];}
		$this->active=$checkpoint['active'];$this->managers=$managers;ksort($this->active,SORT_STRING);return$this;
	}

	public function fingerprint():string {$domains=[];foreach(array_keys($this->active)as$domain){$domains[$domain]=$this->domainState($domain);}return PanelOperationsGuard::digest(['domains'=>$domains,'channels'=>$this->channels()]);}
	/** @return array<string,mixed> */public function drift(string $domainId):array {$domainId=PanelOperationsGuard::name($domainId,'domain runtime drift domain id');$materialization=$this->active[$domainId]??null;if(!$materialization instanceof PanelDomainMaterialization){return['domain_id'=>$domainId,'active'=>false,'drifted'=>false,'mismatches'=>[],'fingerprint'=>PanelOperationsGuard::digest(['domain_id'=>$domainId,'active'=>false])];}$expected=$this->expectedState($materialization);$actual=$this->domainState($domainId);$mismatches=[];foreach($expected as$channel=>$value){if(($actual[$channel]??null)!==$value){$mismatches[]=$channel;}}return['domain_id'=>$domainId,'active'=>true,'drifted'=>$mismatches!==[],'mismatches'=>$mismatches,'expected'=>$expected,'actual'=>$actual,'fingerprint'=>PanelOperationsGuard::digest($actual)];}

	public function jsonSerialize():array {$drift=[];foreach(array_keys($this->active)as$domain){$drift[$domain]=$this->drift($domain);}return PanelManifestContract::stamp(['type'=>'panel_domain_runtime_host_manifest','version'=>1,'active_domains'=>array_map(static fn(PanelDomainMaterialization $item):string=>$item->compilation()->digest(),$this->active),'channels'=>$this->channels(),'drift'=>$drift,'fingerprint'=>$this->fingerprint(),'atomic_checkpoints'=>true,'unrelated_contributions_preserved'=>true,'runtime_objects_serialized'=>false]);}

	private function contribute(PanelDomainMaterialization $materialization):void {
		foreach($this->managers as$manager){foreach($materialization->resources()as$resource){$manager->register($resource);}}
		if($this->workflows!==null){foreach($materialization->workflows()as$definition){$this->workflows->register($definition);}}
		if($this->automation!==null){foreach($materialization->automationActions()as$action){$this->automation->register($action);}}
		$bundle=$this->signedBundle($materialization);if($bundle!==null&&$this->policy!==null){$this->policy->register($bundle);}
		if($this->agents!==null&&$this->automationExecutor!==null){$contributor=$this->contributor($materialization->domainId());foreach($materialization->agentTools()as$tool){$command=(string)($tool->metadata()['domain_command']??'');$action=WorkflowState::normalize($materialization->domainId().'_'.$command);$this->agents->register($tool,new PanelAgentAutomationToolExecutor($this->automationExecutor,$action,[$tool->permission()]),$contributor);}}
		if($this->semantics!==null){foreach($this->metrics($materialization)as$metric){$this->semantics->register($metric,true);}}
		if($this->lineage!==null){$this->absorbLineage($materialization);}
	}

	private function remove(PanelDomainMaterialization $materialization):void {
		foreach($this->managers as$manager){$checkpoint=$manager->contributionCheckpoint();foreach(array_keys($materialization->resources())as$name){unset($checkpoint['resources'][$name]);}$manager->restoreContributionCheckpoint($checkpoint);}
		if($this->workflows!==null){foreach(array_keys($materialization->workflows())as$name){$this->workflows->unregister($name);}}
		if($this->automation!==null){foreach(array_keys($materialization->automationActions())as$name){$this->automation->unregister($name);}}
		if($this->policy!==null&&$materialization->policyBundle()!==null){$this->policy->remove($materialization->policyBundle()->id());}
		$this->agents?->unregisterContributor($this->contributor($materialization->domainId()));
		if($this->semantics!==null){foreach(array_keys($this->metrics($materialization))as$id){$this->semantics->remove($id);}}
		$this->lineage?->forgetPrefix($materialization->domainId().':');
	}

	private function assertChannels(PanelDomainMaterialization $materialization):void {
		if($materialization->workflows()!==[]&&$this->workflows===null){throw new \LogicException('Domain activation requires the workflow runtime.');}
		if($materialization->automationActions()!==[]&&($this->automation===null||$this->automationExecutor===null)){throw new \LogicException('Domain activation requires the automation runtime.');}
		if($materialization->policyBundle()!==null&&($this->policy===null||$this->policyKeyId===null)){throw new \LogicException('Domain activation requires a policy runtime and signing key.');}
		if($materialization->agentTools()!==[]&&($this->agents===null||$this->automationExecutor===null)){throw new \LogicException('Domain activation requires the agent tool and automation runtimes.');}
		if($this->semantics===null||$this->lineage===null){throw new \LogicException('Domain activation requires semantic and lineage runtimes.');}
	}

	private function assertCollisions(PanelDomainMaterialization $next,?PanelDomainMaterialization $previous):void {
		foreach($this->managers as$manager){$this->assertManagerCollisions($manager,$previous,$next);}
		$oldWorkflows=$previous?->workflows()??[];if($this->workflows!==null){foreach($next->workflows()as$name=>$definition){$existing=$this->workflows->definition($name);if($existing!==null&&!isset($oldWorkflows[$name])){throw new \LogicException("Domain workflow '{$name}' collides with an unrelated definition.");}}}
		$oldAutomation=$previous?->automationActions()??[];if($this->automation!==null){foreach($next->automationActions()as$name=>$action){if($this->automation->has($name)&&!isset($oldAutomation[$name])){throw new \LogicException("Domain automation action '{$name}' collides with an unrelated action.");}}}
		if($next->policyBundle()!==null&&$this->policy!==null){$digests=$this->policy->jsonSerialize()['bundle_digests']??[];$id=$next->policyBundle()->id();if(isset($digests[$id])&&$previous?->policyBundle()?->id()!==$id){throw new \LogicException("Domain policy bundle '{$id}' collides with an unrelated bundle.");}}
		if($this->agents!==null){$contributor=$this->contributor($next->domainId());foreach($next->agentTools()as$name=>$tool){if($this->agents->has($name,true)&&$this->agents->contributor($name,true)!==$contributor){throw new \LogicException("Domain agent tool '{$name}' collides with an unrelated tool.");}}}
		if($this->semantics!==null){$oldMetrics=$previous instanceof PanelDomainMaterialization?$this->metrics($previous):[];foreach($this->metrics($next)as$id=>$metric){$existing=$this->semantics->metrics()[$id]??null;if($existing instanceof PanelSemanticMetric&&!isset($oldMetrics[$id])&&!hash_equals($existing->fingerprint(),$metric->fingerprint())){throw new \LogicException("Domain semantic metric '{$id}' collides with an unrelated metric.");}}}
	}

	private function assertManagerCollisions(PanelManager $manager,?PanelDomainMaterialization $previous,?PanelDomainMaterialization $next=null):void {
		$old=$previous?->resources()??[];$targets=$next?->resources()??array_merge(...array_map(static fn(PanelDomainMaterialization $item):array=>$item->resources(),array_values($this->active)));
		foreach($targets as$name=>$resource){$existing=$manager->get($name);if($existing!==null&&!isset($old[$name])){throw new \LogicException("Domain resource '{$name}' collides with an unrelated Panel resource.");}}
	}

	private function signedBundle(PanelDomainMaterialization $materialization):?PanelPolicyBundle {$bundle=$materialization->policyBundle();if($bundle===null){return null;}if($bundle->signed()){if(!$bundle->verify($this->policyKeys)){throw new \LogicException('Materialized domain policy signature is not trusted.');}return$bundle;}$id=$this->policyKeyId;if($id===null||!isset($this->policyKeys[$id])){throw new \LogicException('Domain policy signing is not configured.');}return$bundle->sign($id,$this->policyKeys[$id]);}

	/** @return array<string,mixed> */private function expectedState(PanelDomainMaterialization $materialization):array {$metrics=$this->metrics($materialization);return['materialization'=>$materialization->fingerprint(),'resources'=>$this->resourceState($materialization),'workflows'=>array_map(fn(WorkflowDefinition $item):string=>$this->manifestFingerprint($item->jsonSerialize()),$materialization->workflows()),'automation'=>array_map(fn(AutomationAction $item):string=>$this->manifestFingerprint($item->jsonSerialize()),$materialization->automationActions()),'policy'=>$materialization->policyBundle()?->digest(),'agents'=>array_map(static fn(PanelAgentTool $item):string=>$item->fingerprint(),$materialization->agentTools()),'semantics'=>array_map(static fn(PanelSemanticMetric $item):string=>$item->fingerprint(),$metrics),'lineage'=>$this->lineageFingerprint($materialization)];}
	/** @return array<string,mixed> */private function domainState(string $domainId):array {$materialization=$this->active[$domainId];$resources=[];foreach($this->managers as$id=>$manager){foreach(array_keys($materialization->resources())as$name){$resource=$manager->get($name);$resources[(string)$id][$name]=$resource instanceof Resource?$this->resourceFingerprint($resource):null;}ksort($resources[(string)$id],SORT_STRING);}ksort($resources,SORT_STRING);$workflows=[];foreach(array_keys($materialization->workflows())as$name){$value=$this->workflows?->definition($name);$workflows[$name]=$value instanceof WorkflowDefinition?$this->manifestFingerprint($value->jsonSerialize()):null;}$automation=[];foreach(array_keys($materialization->automationActions())as$name){$value=$this->automation?->get($name);$automation[$name]=$value instanceof AutomationAction?$this->manifestFingerprint($value->jsonSerialize()):null;}$policy=null;if($materialization->policyBundle()!==null){$policy=$this->policy?->jsonSerialize()['bundle_digests'][$materialization->policyBundle()->id()]??null;}$agents=[];foreach(array_keys($materialization->agentTools())as$name){$tool=$this->agents?->tool($name,true);$agents[$name]=$tool instanceof PanelAgentTool&&$this->agents?->contributor($name,true)===$this->contributor($domainId)?$tool->fingerprint():null;}$semantics=[];foreach(array_keys($this->metrics($materialization))as$id){try{$semantics[$id]=$this->semantics?->metric($id)->fingerprint();}catch(\Throwable){$semantics[$id]=null;}}return['materialization'=>$materialization->fingerprint(),'resources'=>$resources,'workflows'=>$workflows,'automation'=>$automation,'policy'=>$policy,'agents'=>$agents,'semantics'=>$semantics,'lineage'=>$this->actualLineageFingerprint($domainId)];}
	/** @return array<string,array<string,string>> */private function resourceState(PanelDomainMaterialization $materialization):array {$values=[];foreach($this->managers as$id=>$manager){foreach($materialization->resources()as$name=>$resource){$values[(string)$id][$name]=$this->resourceFingerprint($resource);}ksort($values[(string)$id],SORT_STRING);}ksort($values,SORT_STRING);return$values;}
	private function resourceFingerprint(Resource $resource):string {return$this->manifestFingerprint($resource->toArray());}
	private function manifestFingerprint(mixed $manifest):string {$safe=PanelSensitiveDataSanitizer::sanitize($manifest,['max_depth'=>32,'max_items'=>1000,'max_string_bytes'=>65536]);return PanelOperationsGuard::digest($safe);}
	/** @return array<string,mixed> */private function channels():array{return['manager_count'=>count($this->managers),'workflows'=>$this->workflows!==null,'automation'=>$this->automation!==null&&$this->automationExecutor!==null,'policy'=>$this->policy!==null,'agents'=>$this->agents!==null,'semantics'=>$this->semantics!==null,'lineage'=>$this->lineage!==null,'policy_signing'=>$this->policyKeyId!==null];}
	/** @return array<string,PanelSemanticMetric> */private function metrics(PanelDomainMaterialization $materialization):array {$source=$materialization->compilation()->artifact('source');if(!is_array($source)){throw new \UnexpectedValueException('Materialized domain source is invalid.');}$metrics=[];foreach($source['metrics']??[]as$id=>$definition){if(!is_array($definition)){throw new \UnexpectedValueException('Materialized domain metric is invalid.');}$name=$materialization->domainId().'.'.$id;$metrics[$name]=PanelSemanticMetric::from($name,$definition);}ksort($metrics,SORT_STRING);return$metrics;}
	private function absorbLineage(PanelDomainMaterialization $materialization):void {$graph=PanelLineageGraph::fromCompilation($materialization->compilation());$manifest=$graph->jsonSerialize();$prefix=$materialization->domainId().':';foreach($manifest['nodes']??[]as$node){if(is_array($node)){$this->lineage?->node($prefix.$node['id'],(string)$node['kind'],(string)$node['label'],is_array($node['metadata']??null)?$node['metadata']:[],true);}}foreach($manifest['edges']??[]as$edge){if(is_array($edge)){$this->lineage?->edge($prefix.$edge['from'],$prefix.$edge['to'],(string)$edge['kind'],is_array($edge['metadata']??null)?$edge['metadata']:[],true);}}}
	private function lineageFingerprint(PanelDomainMaterialization $materialization):string {$graph=PanelLineageGraph::fromCompilation($materialization->compilation());return PanelOperationsGuard::digest($this->lineageContract($graph->jsonSerialize()));}
	private function actualLineageFingerprint(string $domainId):?string {if($this->lineage===null){return null;}$manifest=$this->lineage->jsonSerialize();$prefix=$domainId.':';$nodes=[];$edges=[];foreach($manifest['nodes']??[]as$node){if(is_array($node)&&str_starts_with((string)($node['id']??''),$prefix)){$copy=$node;$copy['id']=substr((string)$copy['id'],strlen($prefix));$nodes[$copy['id']]=$copy;}}foreach($manifest['edges']??[]as$edge){if(is_array($edge)&&str_starts_with((string)($edge['from']??''),$prefix)&&str_starts_with((string)($edge['to']??''),$prefix)){$copy=$edge;$copy['from']=substr((string)$copy['from'],strlen($prefix));$copy['to']=substr((string)$copy['to'],strlen($prefix));$edges[]=$copy;}}return PanelOperationsGuard::digest($this->lineageContract(['nodes'=>$nodes,'edges'=>$edges]));}
	/** @param array<string,mixed> $manifest @return array<string,mixed> */private function lineageContract(array $manifest):array {$nodes=is_array($manifest['nodes']??null)?$manifest['nodes']:[];$edges=is_array($manifest['edges']??null)?array_values($manifest['edges']):[];ksort($nodes,SORT_STRING);usort($edges,static fn(array $left,array $right):int=>[(string)($left['from']??''),(string)($left['kind']??''),(string)($left['to']??'')]<=>[(string)($right['from']??''),(string)($right['kind']??''),(string)($right['to']??'')]);return['nodes'=>$nodes,'edges'=>$edges];}
	private function contributor(string $domainId):string{return'domain.'.$domainId;}
	private function restoreService(?PanelCheckpointableService $service,mixed $checkpoint,string $label):void {if(($service===null)!==($checkpoint===null)||($service!==null&&!is_array($checkpoint))){throw new \InvalidArgumentException('Domain runtime '.$label.' checkpoint does not match.');}if($service!==null){$service->restore($checkpoint);}}
}
