<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Authorized planner/runner with dry-run, resume, compensation, and snapshot recovery. */
final class PanelMigrationRunner {
	private ?\Closure $authorize;private bool $trustedExecution=false;
	public function __construct(private readonly PanelMigrationStore $store,private readonly PanelMigrationRegistry $registry,?callable $authorize=null){$this->authorize=$authorize===null?null:\Closure::fromCallable($authorize);}
	public function store():PanelMigrationStore{return$this->store;}public function registry():PanelMigrationRegistry{return$this->registry;}public function planner():PanelMigrationPlanner{return new PanelMigrationPlanner($this->registry);}
	/** Explicit non-request escape hatch for trusted installers and isolated maintenance jobs. */ public function allowTrustedExecution():self{$clone=clone$this;$clone->trustedExecution=true;return$clone;}
	public function trustedExecution():bool{return$this->trustedExecution;}
	/** @return array<string,mixed> */ public function manifest():array{return['type'=>'panel_migration_runner','manifest_version'=>1,'store'=>$this->store::class,'registry_digest'=>$this->registry->digest(),'authorization'=>['configured'=>$this->authorize!==null,'trusted_execution'=>$this->trustedExecution,'default_deny'=>true],'security'=>['actor_serialized'=>false,'raw_configuration_serialized'=>false]];}
	public function plan(string $scope,?string $tenant,PanelMigrationVersion $target,mixed $createdAt=null):PanelMigrationPlan{return$this->planner()->plan($this->store->state($scope,$tenant),$target,$createdAt);}

	/** @param list<string> $grants */
	public function preflight(PanelMigrationPlan $plan,mixed $actor=null,array $grants=[]):PanelMigrationReport {
		$issues=[];$checks=['registry'=>false,'state'=>false,'definitions'=>false,'capabilities'=>false,'authorization'=>false,'handlers'=>false];$existing=$this->store->reportByPlan($plan->digest());
		if(hash_equals($this->registry->digest(),$plan->registryDigest())){$checks['registry']=true;}else{$issues[]='Migration registry changed after this plan was created.';}
		$state=$this->store->state($plan->scope(),$plan->tenant());$existingPayload=$existing?->jsonSerialize()??[];$resumable=$existing!==null&&in_array($existing->status(),['running','paused','failed','completed'],true)&&(int)($existingPayload['state_revision']??-1)===$state->revision()&&hash_equals((string)($existingPayload['state_digest']??''),$state->digest());
		if($resumable||($state->revision()===$plan->stateRevision()&&hash_equals($state->digest(),$plan->stateDigest())&&$state->version()->equals($plan->source()))){$checks['state']=true;}else{$issues[]='Migration state changed after this plan was created.';}
		$definitions=[];try{foreach($plan->steps()as$step){$definition=$this->registry->get((string)($step['id']??''));if(!hash_equals((string)($step['definition_digest']??''),$definition->digest())||$definition->scope()!==$plan->scope()||!$definition->supportsTenant($plan->tenant())){throw new PanelMigrationStalePlan($plan->digest(),'A planned migration definition changed.');}$definitions[]=$definition;}$checks['definitions']=true;}catch(\Throwable $error){$issues[]=(string)(PanelMigrationIntegrity::redact($error)['message']??'Migration definition validation failed.');}
		[$granted,$invalidGrants]=$this->normalizeGrants($grants);if($invalidGrants){$issues[]='An invalid migration capability grant was supplied.';}$missing=array_values(array_diff($plan->capabilities(),$granted));if($missing===[]&&!$invalidGrants){$checks['capabilities']=true;}elseif($missing!==[]){$issues[]='Missing migration capabilities: '.implode(', ',$missing).'.';}
		$authorized=$this->authorized('panel.migrations.execute',$actor,['plan'=>$plan->jsonSerialize(),'required_capabilities'=>$plan->capabilities()]);if($authorized){$checks['authorization']=true;}else{$issues[]='Migration execution was not authorized.';}
		if($checks['definitions']&&$checks['state']){$handlerIssues=[];$start=$resumable?(int)($existingPayload['step_index']??0):0;if($existing?->status()==='completed'){$start=count($definitions);}foreach(array_slice($definitions,$start)as$definition){try{$result=$definition->inspect(new PanelMigrationContext($plan->scope(),$plan->tenant(),$definition->id(),'up',$state->data(),null,$definition->batchSize(),[],true,$actor));foreach($this->preflightIssues($result,$definition->id())as$issue){$handlerIssues[]=$issue;}}catch(\Throwable $error){$safe=PanelMigrationIntegrity::redact($error);$handlerIssues[]="{$definition->id()}: ".(string)($safe['message']??'preflight failed');}}if($handlerIssues===[]){$checks['handlers']=true;}else{$issues=array_merge($issues,$handlerIssues);}}
		return PanelMigrationReport::make(['run_id'=>null,'plan_digest'=>$plan->digest(),'scope'=>$plan->scope(),'tenant'=>$plan->tenant(),'source'=>$plan->source()->jsonSerialize(),'target'=>$plan->target()->jsonSerialize(),'status'=>$issues===[]?'preflight':'blocked','dry_run'=>true,'checks'=>$checks,'issues'=>$issues,'step_index'=>0,'total_steps'=>count($plan->steps()),'batch_count'=>0,'processed'=>0,'receipts'=>[],'errors'=>[],'created_at'=>$plan->createdAt()]);
	}

	/** @param list<string> $grants */
	public function execute(PanelMigrationPlan $plan,mixed $actor=null,array $grants=[],string $owner='migration-worker',bool $dryRun=false,int $maxBatches=10000):PanelMigrationReport {
		$maxBatches=self::budget($maxBatches);$preflight=$this->preflight($plan,$actor,$grants);if($preflight->status()==='blocked'||$dryRun){return$preflight;}$lease=$this->store->acquire($plan->scope(),$plan->tenant(),$owner,60);if($lease===null){return$this->blocked($plan,'Another worker owns the migration scope lease.');}
		$report=null;
		try{
			$report=$this->store->begin($lease,$plan,$actor);if($report->status()==='completed'){return$report;}$runId=$report->runId()??throw new PanelMigrationConflict('Migration store did not return a run id.');$batches=0;
			while((int)($report->jsonSerialize()['step_index']??0)<count($plan->steps())&&$batches<$maxBatches){$index=(int)$report->jsonSerialize()['step_index'];$definition=$this->registry->get((string)$plan->steps()[$index]['id']);$report=$this->store->applyBatch($lease,$runId,$plan,$definition,$actor);$lease=$this->store->renew($lease,60);$batches++;}
			if((int)($report->jsonSerialize()['step_index']??0)===count($plan->steps())){$report=$this->store->complete($lease,$runId,$plan);}return$report;
		}catch(PanelMigrationStalePlan|PanelMigrationLeaseLost $error){throw$error;}catch(\Throwable $error){if($report?->runId()!==null){try{$report=$this->store->fail($lease,$report->runId(),$error);return$report;}catch(\Throwable){}}throw$error;}finally{try{$this->store->release($lease);}catch(\Throwable){}}
	}

	/** @param list<string> $grants */
	public function rollback(PanelMigrationPlan $plan,mixed $actor=null,array $grants=[],string $owner='migration-rollback',int $maxBatches=10000,bool $snapshotFallback=true):PanelMigrationReport {
		$maxBatches=self::budget($maxBatches);if(!hash_equals($this->registry->digest(),$plan->registryDigest())){return$this->blocked($plan,'Migration registry changed after this plan was created.');}if(!$this->authorized('panel.migrations.rollback',$actor,['plan'=>$plan->jsonSerialize()])){return$this->blocked($plan,'Migration rollback was not authorized.');}
		[$granted,$invalidGrants]=$this->normalizeGrants($grants);if($invalidGrants){return$this->blocked($plan,'An invalid migration capability grant was supplied.');}$missing=array_values(array_diff($plan->capabilities(),$granted));if($missing!==[]){return$this->blocked($plan,'Missing migration capabilities: '.implode(', ',$missing).'.');}
		$existing=$this->store->reportByPlan($plan->digest());if($existing===null||$existing->runId()===null){return$this->blocked($plan,'No migration run exists for this plan.');}$lease=$this->store->acquire($plan->scope(),$plan->tenant(),$owner,60);if($lease===null){return$this->blocked($plan,'Another worker owns the migration scope lease.');}
		$report=$existing;
		try{
			$runId=$existing->runId();$report=$this->store->beginRollback($lease,$runId,$plan);if($report->status()==='rolled_back'){return$report;}$batches=0;
			while((int)($report->jsonSerialize()['rollback_index']??-1)>=0&&$batches<$maxBatches){$index=(int)$report->jsonSerialize()['rollback_index'];$definition=$this->registry->get((string)$plan->steps()[$index]['id']);if(!$definition->reversible()){if($snapshotFallback){return$this->store->restoreSnapshot($lease,$runId,$plan);}throw new PanelMigrationConflict("Panel migration '{$definition->id()}' has no compensation handler.");}$report=$this->store->applyCompensation($lease,$runId,$plan,$definition,$actor);$lease=$this->store->renew($lease,60);$batches++;}
			if((int)($report->jsonSerialize()['rollback_index']??-1)<0){$report=$this->store->completeRollback($lease,$runId,$plan);}return$report;
		}catch(PanelMigrationLeaseLost|PanelMigrationStalePlan $error){throw$error;}catch(\Throwable $error){if($snapshotFallback){try{return$this->store->restoreSnapshot($lease,$existing->runId(),$plan);}catch(\Throwable){}}try{return$this->store->fail($lease,$existing->runId(),$error);}catch(\Throwable){throw$error;}}finally{try{$this->store->release($lease);}catch(\Throwable){}}
	}

	/** @param list<string> $grants */ public function resume(PanelMigrationPlan $plan,mixed $actor=null,array $grants=[],string $owner='migration-worker',int $maxBatches=10000):PanelMigrationReport{return$this->execute($plan,$actor,$grants,$owner,false,$maxBatches);}

	/** @return list<string> */ private function preflightIssues(mixed $result,string $id):array{if($result===true||$result===null){return[];}if($result===false){return["{$id}: preflight rejected the migration."];}if(is_string($result)){return["{$id}: ".$result];}if(is_array($result)){if(($result['ok']??false)===true){return[];}$issues=$result['issues']??[$result['message']??'preflight rejected the migration'];if(!is_array($issues)){$issues=[$issues];}return array_map(static fn(mixed $issue):string=>"{$id}: ".(string)PanelMigrationIntegrity::redact($issue),array_values($issues));}return["{$id}: preflight returned an unsupported result."];}
	/** @param array<string,mixed> $context */ private function authorized(string $ability,mixed $actor,array $context):bool{if($this->trustedExecution){return true;}if($this->authorize===null){return false;}try{return($this->authorize)($ability,$actor,$context)===true;}catch(\Throwable){return false;}}
	/** @param list<string> $grants @return array{0:list<string>,1:bool} */ private function normalizeGrants(array $grants):array{$normalized=[];$invalid=false;foreach($grants as$grant){try{$normalized[]=PanelMigrationIntegrity::identifier((string)$grant,'capability');}catch(\Throwable){$invalid=true;}}$normalized=array_values(array_unique($normalized));sort($normalized,SORT_STRING);return[$normalized,$invalid];}
	private function blocked(PanelMigrationPlan $plan,string $issue):PanelMigrationReport{return PanelMigrationReport::make(['run_id'=>null,'plan_digest'=>$plan->digest(),'scope'=>$plan->scope(),'tenant'=>$plan->tenant(),'source'=>$plan->source()->jsonSerialize(),'target'=>$plan->target()->jsonSerialize(),'status'=>'blocked','dry_run'=>true,'issues'=>[$issue],'step_index'=>0,'total_steps'=>count($plan->steps()),'batch_count'=>0,'processed'=>0,'receipts'=>[],'errors'=>[]]);}
	private static function budget(int $value):int{if($value<1||$value>10000){throw new \InvalidArgumentException('Panel migration runner batch budgets must be between 1 and 10000.');}return$value;}
}
