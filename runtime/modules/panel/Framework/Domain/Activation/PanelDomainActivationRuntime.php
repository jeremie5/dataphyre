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
 * Signed transactional lifecycle for materialized domains.
 *
 * Activation is optimistic, idempotent, approval-gated, crash recoverable,
 * migration aware, compensating, and atomic across every attached runtime.
 */
final class PanelDomainActivationRuntime implements \JsonSerializable {
	/** @var array<string,string> */private array $domainKeys;/** @var array<string,string> */private array $activationKeys;/** @var array<string,string> */private array $approvalKeys;
	private ?\Closure $clock;private ?\Closure $nonceFactory;

	/** @param array<string,string> $domainKeys @param array<string,string> $activationKeys @param array<string,string> $approvalKeys */
	public function __construct(
		private readonly PanelDomainCompiler $compiler,
		private readonly PanelDomainMaterializer $materializer,
		private readonly PanelDomainRuntimeHost $host,
		private readonly PanelDomainActivationStore $store,
		array $domainKeys,
		array $activationKeys,
		private readonly string $activationKeyId,
		array $approvalKeys,
		private readonly string $approvalKeyId,
		private readonly ?PanelDomainMigrationExecutor $migrations=null,
		?callable $clock=null,
		?callable $nonceFactory=null,
		private readonly int $planTtlSeconds=900,
	){
		$this->domainKeys=$this->keys($domainKeys,'domain compilation');$this->activationKeys=$this->keys($activationKeys,'domain activation');$this->approvalKeys=$this->keys($approvalKeys,'domain approval');
		if(!isset($this->activationKeys[$activationKeyId])||!isset($this->approvalKeys[$approvalKeyId])){throw new \InvalidArgumentException('Domain activation current keys are not present in their keyrings.');}
		if($planTtlSeconds<30||$planTtlSeconds>86400){throw new \InvalidArgumentException('Domain activation plan TTL must be between 30 seconds and one day.');}
		$this->clock=$clock!==null?\Closure::fromCallable($clock):null;$this->nonceFactory=$nonceFactory!==null?\Closure::fromCallable($nonceFactory):null;$this->recover();
	}

	public function preview(PanelDomainCompilation $compilation,string $operation='activate'):PanelDomainActivationPlan {
		if(!in_array($operation,['activate','rollback','reconcile'],true)){throw new \InvalidArgumentException('Domain activation preview operation is invalid.');}$this->assertCompilation($compilation);$materialization=$this->materializer->materialize($compilation);$current=$this->activeCompilation($compilation->domainId());
		if($operation==='rollback'&&$current===null){throw new \LogicException('Cannot roll back a domain that is not active.');}
		if($operation==='reconcile'&&($current===null||!hash_equals($current->digest(),$compilation->digest()))){throw new \LogicException('Reconciliation must target the active domain compilation.');}
		$steps=[];$breaking=false;if($current!==null&&!hash_equals($current->digest(),$compilation->digest())){$diff=$this->compiler->diff($current,$compilation);$steps=$diff->migrationSteps();$breaking=$diff->breaking();}
		$approvals=$operation==='reconcile'?1:($breaking?2:($current!==null&&!hash_equals($current->digest(),$compilation->digest())?1:0));
		return$this->signedPlan($operation,$compilation->domainId(),$current,$compilation,$materialization->fingerprint(),$steps,$breaking,$approvals);
	}

	public function previewDeactivation(string $domainId):PanelDomainActivationPlan {
		$current=$this->activeCompilation($domainId);if($current===null){throw new \OutOfBoundsException('Domain is not active.');}
		return$this->signedPlan('deactivate',$current->domainId(),$current,null,null,[],true,2);
	}

	public function issueApproval(PanelDomainActivationPlan $plan,string|int $actorId,int $ttlSeconds=300):PanelDomainActivationApproval {
		$now=$this->now();if(!$plan->verify($this->activationKeys,$now)){throw new \LogicException('Cannot approve an untrusted or expired domain activation plan.');}$ttlSeconds=max(30,min($this->planTtlSeconds,$ttlSeconds));$expires=$this->plusSeconds($now,$ttlSeconds);if(strcmp($expires,$plan->expiresAt())>0){$expires=$plan->expiresAt();}
		return PanelDomainActivationApproval::sign($plan->fingerprint(),PanelOperationsGuard::identifier($actorId,'domain activation approver'),$now,$expires,$this->nonce(),$this->approvalKeyId,$this->approvalKeys[$this->approvalKeyId]);
	}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function activate(PanelDomainCompilation $compilation,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {
		$operation=$plan?->operation()??'activate';if(!in_array($operation,['activate','rollback'],true)){throw new \InvalidArgumentException('Domain activation requires an activate or rollback plan.');}
		return$this->transition($operation,$compilation,$actorId,$idempotencyKey,$plan??$this->preview($compilation,$operation),$approvals,$expectedRevision);
	}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function rollback(string $domainId,string $version,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {
		$target=$this->compilationAt($domainId,$version);return$this->transition('rollback',$target,$actorId,$idempotencyKey,$plan??$this->preview($target,'rollback'),$approvals,$expectedRevision);
	}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function deactivate(string $domainId,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {
		$actor=PanelOperationsGuard::identifier($actorId,'domain deactivation actor');$key=$this->idempotency($idempotencyKey);$hash=hash('sha256',$key);$state=PanelDomainActivationState::validate($this->store->payload());$entry=$state['active'][PanelOperationsGuard::name($domainId,'domain deactivation id')]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Domain is not active.');}$current=PanelDomainCompilation::hydrate($entry['compilation']);$fingerprint=PanelOperationsGuard::digest(['operation'=>'deactivate','domain_id'=>$current->domainId(),'from_digest'=>$current->digest(),'actor_id'=>$actor]);if(($replay=$this->replay($state,$hash,$fingerprint))!==null){return$replay;}
		$this->expectedRevision($state,$expectedRevision);$plan??=$this->previewDeactivation($current->domainId());$now=$this->now();$this->assertPlan($plan,'deactivate',$current,null,null,$now);$this->approvals($plan,$approvals,$actor,$now);
		$checkpoint=$this->host->checkpoint();$before=$this->host->fingerprint();try{$this->host->deactivate($current->domainId());$after=$this->host->fingerprint();$revision=$state['revision']+1;$receipt=$this->receipt('deactivate',$current->domainId(),$current->digest(),null,$plan,$actor,$revision,$hash,$before,$after,['required'=>false,'status'=>'data_preserved']);$this->commit($state,$hash,$fingerprint,$receipt,null,$entry,$expectedRevision);return$receipt;}catch(\Throwable $error){$this->host->restore($checkpoint);throw$error;}
	}

	/** @param list<PanelDomainActivationApproval> $approvals */
	public function reconcile(string $domainId,string|int $actorId,string $idempotencyKey,?PanelDomainActivationPlan $plan=null,array $approvals=[],?int $expectedRevision=null):PanelDomainActivationReceipt {
		$actor=PanelOperationsGuard::identifier($actorId,'domain reconciliation actor');$key=$this->idempotency($idempotencyKey);$hash=hash('sha256',$key);$state=PanelDomainActivationState::validate($this->store->payload());$entry=$state['active'][PanelOperationsGuard::name($domainId,'domain reconciliation id')]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Domain is not active.');}$current=PanelDomainCompilation::hydrate($entry['compilation']);$fingerprint=PanelOperationsGuard::digest(['operation'=>'reconcile','domain_id'=>$current->domainId(),'to_digest'=>$current->digest(),'actor_id'=>$actor]);if(($replay=$this->replay($state,$hash,$fingerprint))!==null){return$replay;}$this->expectedRevision($state,$expectedRevision);$plan??=$this->preview($current,'reconcile');$materialization=$this->materializer->materialize($current);$now=$this->now();$this->assertPlan($plan,'reconcile',$current,$current,$materialization,$now);$this->approvals($plan,$approvals,$actor,$now);$checkpoint=$this->host->checkpoint();$before=$this->host->fingerprint();try{$this->host->reconcile($current->domainId());$after=$this->host->fingerprint();$revision=$state['revision']+1;$receipt=$this->receipt('reconcile',$current->domainId(),$current->digest(),$current->digest(),$plan,$actor,$revision,$hash,$before,$after,['required'=>false,'status'=>'not_required']);$next=['compilation'=>$current->jsonSerialize(),'materialization_fingerprint'=>$materialization->fingerprint(),'host_fingerprint'=>$after,'receipt_id'=>$receipt->id()];$this->commit($state,$hash,$fingerprint,$receipt,$next,$entry,$expectedRevision);return$receipt;}catch(\Throwable $error){$this->host->restore($checkpoint);throw$error;}
	}

	public function activeCompilation(string $domainId):?PanelDomainCompilation {$domainId=PanelOperationsGuard::name($domainId,'activated domain id');$entry=$this->store->payload()['active'][$domainId]??null;return is_array($entry)&&is_array($entry['compilation']??null)?PanelDomainCompilation::hydrate($entry['compilation']):null;}
	/** @return array<string,PanelDomainCompilation> */public function activeCompilations():array {$result=[];foreach($this->store->payload()['active']??[]as$domain=>$entry){if(is_string($domain)&&is_array($entry)&&is_array($entry['compilation']??null)){$result[$domain]=PanelDomainCompilation::hydrate($entry['compilation']);}}ksort($result,SORT_STRING);return$result;}
	public function compilationAt(string $domainId,string $version):PanelDomainCompilation {$domainId=PanelOperationsGuard::name($domainId,'domain activation history id');$version=trim($version);foreach($this->store->payload()['history'][$domainId]??[]as$entry){if(is_array($entry)&&is_array($entry['compilation']??null)){$compilation=PanelDomainCompilation::hydrate($entry['compilation']);if($compilation->domainVersion()===$version){return$compilation;}}}throw new \OutOfBoundsException('Domain activation version is not retained.');}
	/** @return list<PanelDomainCompilation> */public function history(string $domainId):array {$domainId=PanelOperationsGuard::name($domainId,'domain activation history id');$result=[];foreach($this->store->payload()['history'][$domainId]??[]as$entry){if(is_array($entry)&&is_array($entry['compilation']??null)){$result[]=PanelDomainCompilation::hydrate($entry['compilation']);}}return$result;}
	public function revision():int{return(int)$this->store->payload()['revision'];}
	/** @return array<string,mixed> */public function drift(string $domainId):array{return$this->host->drift($domainId);}
	public function attachManager(PanelManager $manager):self {$this->host->attachManager($manager);return$this;}
	public function detachManager(PanelManager $manager,bool $removeContributions=true):self {$this->host->detachManager($manager,$removeContributions);return$this;}

	public function jsonSerialize():array {$state=PanelDomainActivationState::validate($this->store->payload());$drift=[];foreach(array_keys($state['active'])as$domain){$drift[$domain]=$this->host->drift((string)$domain);}return PanelManifestContract::stamp(['type'=>'panel_domain_activation_runtime_manifest','version'=>1,'revision'=>$state['revision'],'active_domains'=>array_map(static fn(array $entry):string=>(string)$entry['compilation']['digest'],$state['active']),'history_depth'=>array_map('count',$state['history']),'receipt_count'=>count($state['receipts']),'drift'=>$drift,'host'=>$this->host->jsonSerialize(),'security'=>['signed_compilations'=>true,'signed_plans'=>true,'expiring_plans'=>true,'signed_independent_approvals'=>true,'separation_of_duties'=>true,'signed_receipts'=>true,'idempotency_keys_hashed'=>true,'default_deny'=>true],'capabilities'=>['optimistic_concurrency'=>true,'atomic_host_rollback'=>true,'durable_restart_recovery'=>true,'migration_compensation'=>true,'version_rollback'=>true,'deactivation_preserves_data'=>true,'drift_reconciliation'=>true]]);}

	/** @param list<PanelDomainActivationApproval> $approvals */
	private function transition(string $operation,PanelDomainCompilation $compilation,string|int $actorId,string $idempotencyKey,PanelDomainActivationPlan $plan,array $approvals,?int $expectedRevision):PanelDomainActivationReceipt {
		$this->assertCompilation($compilation);$actor=PanelOperationsGuard::identifier($actorId,'domain activation actor');$key=$this->idempotency($idempotencyKey);$hash=hash('sha256',$key);$state=PanelDomainActivationState::validate($this->store->payload());$currentEntry=$state['active'][$compilation->domainId()]??null;$current=is_array($currentEntry)?PanelDomainCompilation::hydrate($currentEntry['compilation']):null;$fingerprint=PanelOperationsGuard::digest(['operation'=>$operation,'domain_id'=>$compilation->domainId(),'to_digest'=>$compilation->digest(),'actor_id'=>$actor]);if(($replay=$this->replay($state,$hash,$fingerprint))!==null){return$replay;}$this->expectedRevision($state,$expectedRevision);$materialization=$this->materializer->materialize($compilation);$now=$this->now();$this->assertPlan($plan,$operation,$current,$compilation,$materialization,$now);$this->approvals($plan,$approvals,$actor,$now);
		$migration=['required'=>false,'status'=>'not_required'];$migrated=false;$checkpoint=$this->host->checkpoint();$before=$this->host->fingerprint();
		try{
			if($this->structuralMigration($plan)){if($this->migrations===null){throw new \LogicException('Structural domain changes require a migration executor.');}$migration=PanelOperationsGuard::safeMetadata($this->migrations->migrate($plan,$current,$compilation),1024);$migration=['required'=>true,'status'=>'applied']+$migration;$migrated=true;}
			$this->host->activate($materialization);$after=$this->host->fingerprint();$revision=$state['revision']+1;$receipt=$this->receipt($operation,$compilation->domainId(),$current?->digest(),$compilation->digest(),$plan,$actor,$revision,$hash,$before,$after,$migration);$next=['compilation'=>$compilation->jsonSerialize(),'materialization_fingerprint'=>$materialization->fingerprint(),'host_fingerprint'=>$after,'receipt_id'=>$receipt->id()];$this->commit($state,$hash,$fingerprint,$receipt,$next,is_array($currentEntry)?$currentEntry:null,$expectedRevision);return$receipt;
		}catch(\Throwable $error){$this->host->restore($checkpoint);if($migrated&&$this->migrations!==null){try{$this->migrations->compensate($plan,$migration,$current,$compilation);}catch(\Throwable $compensation){throw new \RuntimeException('Domain activation failed and migration compensation also failed.',0,new \RuntimeException($compensation->getMessage(),0,$error));}}throw$error;}
	}

	private function recover():void {$state=PanelDomainActivationState::validate($this->store->payload());$checkpoint=$this->host->checkpoint();try{foreach($state['active']as$domain=>$entry){$compilation=PanelDomainCompilation::hydrate($entry['compilation']);$this->assertCompilation($compilation);$receipt=$state['receipts'][$entry['receipt_id']]??null;if(!is_array($receipt)||!PanelDomainActivationReceipt::hydrate($receipt)->verify($this->activationKeys)){throw new \UnexpectedValueException('Activated domain receipt is missing or untrusted.');}$materialization=$this->materializer->materialize($compilation);if(!hash_equals($materialization->fingerprint(),$entry['materialization_fingerprint'])){throw new \UnexpectedValueException('Activated domain materialization fingerprint has drifted across restart.');}$this->host->activate($materialization);}}catch(\Throwable $error){$this->host->restore($checkpoint);throw$error;}}

	private function signedPlan(string $operation,string $domainId,?PanelDomainCompilation $from,?PanelDomainCompilation $to,?string $materializationFingerprint,array $steps,bool $breaking,int $approvals):PanelDomainActivationPlan {$now=$this->now();return PanelDomainActivationPlan::sign($operation,$domainId,$from?->domainVersion(),$from?->digest(),$to?->domainVersion(),$to?->digest(),$materializationFingerprint,$steps,$breaking,$approvals,$now,$this->plusSeconds($now,$this->planTtlSeconds),$this->nonce(),$this->activationKeyId,$this->activationKeys[$this->activationKeyId]);}
	private function assertPlan(PanelDomainActivationPlan $plan,string $operation,?PanelDomainCompilation $from,?PanelDomainCompilation $to,?PanelDomainMaterialization $materialization,string $at):void {if(!$plan->verify($this->activationKeys,$at)){throw new \LogicException('Domain activation plan is untrusted or expired.');}if($plan->operation()!==$operation||$plan->domainId()!==($to?->domainId()??$from?->domainId())||$plan->fromDigest()!==$from?->digest()||$plan->toDigest()!==$to?->digest()||$plan->materializationFingerprint()!==$materialization?->fingerprint()){throw new \LogicException('Domain activation plan does not match the requested state transition.');}}
	/** @param list<PanelDomainActivationApproval> $approvals */private function approvals(PanelDomainActivationPlan $plan,array $approvals,string $actor,string $at):void {$actors=[];foreach($approvals as$approval){if(!$approval instanceof PanelDomainActivationApproval||!$approval->verify($this->approvalKeys,$at)||!hash_equals($approval->planFingerprint(),$plan->fingerprint())){throw new \LogicException('Domain activation approval is untrusted, expired, or bound to another plan.');}$approver=$approval->actorId();if($approver===$actor){throw new \LogicException('Domain activation approvers must be independent from the executor.');}$actors[$approver]=true;}if(count($actors)<$plan->approvalCount()){throw new \LogicException('Domain activation requires '.($plan->approvalCount()-count($actors)).' more independent approval(s).');}}
	private function assertCompilation(PanelDomainCompilation $compilation):void {if(!$compilation->signed()||!$compilation->verify($this->domainKeys)){throw new \LogicException('Domain activation requires a trusted signed compilation.');}}
	private function structuralMigration(PanelDomainActivationPlan $plan):bool {foreach($plan->migrationSteps()as$step){if(is_array($step)&&in_array($step['section']??null,['entities','relationships'],true)){return true;}}return false;}
	/** @param array<string,mixed> $state */private function replay(array $state,string $hash,string $fingerprint):?PanelDomainActivationReceipt {$entry=$state['idempotency'][$hash]??null;if(!is_array($entry)){return null;}if(!hash_equals((string)$entry['fingerprint'],$fingerprint)){throw new \LogicException('Domain activation idempotency key was reused for a different transition.');}$payload=$state['receipts'][$entry['receipt_id']]??null;if(!is_array($payload)){throw new \UnexpectedValueException('Domain activation replay receipt is missing.');}$receipt=PanelDomainActivationReceipt::hydrate($payload);if(!$receipt->verify($this->activationKeys)){throw new \UnexpectedValueException('Domain activation replay receipt is untrusted.');}return$receipt;}
	/** @param array<string,mixed> $state */private function expectedRevision(array $state,?int $expected):void {if($expected!==null&&$expected!==$state['revision']){throw new \RuntimeException('Domain activation state changed; refresh the plan and retry.');}}
	/** @param array<string,mixed> $state @param array<string,mixed>|null $next @param array<string,mixed>|null $previous */private function commit(array $state,string $hash,string $fingerprint,PanelDomainActivationReceipt $receipt,?array $next,?array $previous,?int $expectedRevision):void {$domain=$receipt->domainId();$baseRevision=$state['revision'];$this->store->transaction(function(array &$live)use($hash,$fingerprint,$receipt,$next,$previous,$domain,$baseRevision,$expectedRevision):void {PanelDomainActivationState::validate($live);if($live['revision']!==$baseRevision||($expectedRevision!==null&&$live['revision']!==$expectedRevision)){throw new \RuntimeException('Domain activation state changed during commit.');}$live['revision']=$receipt->revision();$live['receipts'][$receipt->id()]=$receipt->jsonSerialize();$live['idempotency'][$hash]=['fingerprint'=>$fingerprint,'receipt_id'=>$receipt->id()];$history=$live['history'][$domain]??[];foreach(array_filter([$previous,$next])as$entry){$digest=$entry['compilation']['digest']??null;$found=false;foreach($history as$known){if(($known['compilation']['digest']??null)===$digest){$found=true;break;}}if(!$found){$history[]=$entry;}}if(count($history)>256){$history=array_slice($history,-256);}$live['history'][$domain]=$history;if($next===null){unset($live['active'][$domain]);}else{$live['active'][$domain]=$next;}ksort($live['active'],SORT_STRING);ksort($live['history'],SORT_STRING);ksort($live['receipts'],SORT_STRING);ksort($live['idempotency'],SORT_STRING);},'domain.'.$receipt->operation(),['domain_id'=>$domain,'receipt_id'=>$receipt->id(),'revision'=>$receipt->revision()]);}
	/** @param array<string,mixed> $migration */private function receipt(string $operation,string $domain,?string $from,?string $to,PanelDomainActivationPlan $plan,string $actor,int $revision,string $hash,string $before,string $after,array $migration):PanelDomainActivationReceipt {$id=$domain.':'.$revision.':'.substr($hash,0,16);return PanelDomainActivationReceipt::sign($id,$operation,$domain,$from,$to,$plan->fingerprint(),$actor,$revision,$hash,$before,$after,$migration,$this->now(),$this->activationKeyId,$this->activationKeys[$this->activationKeyId]);}
	private function idempotency(string $key):string {$key=trim($key);if($key===''||strlen($key)>512||str_contains($key,"\0")){throw new \InvalidArgumentException('Domain activation idempotency key is invalid.');}return$key;}
	private function now():string {$value=$this->clock!==null?($this->clock)():gmdate('c');if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Domain activation clock must return an instant.');}return PanelOperationsGuard::instant($value);}
	private function plusSeconds(string $instant,int $seconds):string {return PanelOperationsGuard::instant((new \DateTimeImmutable($instant))->modify('+'.$seconds.' seconds'));}
	private function nonce():string {$value=$this->nonceFactory!==null?($this->nonceFactory)():bin2hex(random_bytes(16));if(!is_string($value)){throw new \UnexpectedValueException('Domain activation nonce factory must return a string.');}return PanelOperationsGuard::identifier($value,'domain activation nonce');}
	/** @param array<string,string> $keys @return array<string,string> */private function keys(array $keys,string $label):array {if($keys===[]){throw new \InvalidArgumentException(ucfirst($label).' keyring cannot be empty.');}$normalized=[];foreach($keys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,$label.' key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException(ucfirst($label).' keys require at least 32 bytes.');}$normalized[$id]=$key;}ksort($normalized,SORT_STRING);return$normalized;}
}
