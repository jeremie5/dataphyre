<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Plans, pins, executes, records, signs, and evaluates collector-driven evidence. */
final class PanelComplianceAutomation implements \JsonSerializable {
	private readonly \Closure $clock;
	private readonly int $collectorBudgetMillis;
	private readonly int $runBudgetMillis;
	private readonly int $maxEvidenceItems;

	/** @param array<string,mixed> $limits */
	public function __construct(private readonly PanelComplianceLedger $ledger,private readonly PanelComplianceCollectorRegistry $collectors,private readonly PanelComplianceFrameworkCatalog $frameworks,?callable $clock=null,array $limits=[]){
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');
		$this->collectorBudgetMillis=max(1,min(300000,(int)($limits['collector_millis']??5000)));
		$this->runBudgetMillis=max($this->collectorBudgetMillis,min(900000,(int)($limits['run_millis']??60000)));
		$this->maxEvidenceItems=max(1,min(512,(int)($limits['evidence_items']??128)));
	}

	public function ledger():PanelComplianceLedger{return$this->ledger;}
	public function collectors():PanelComplianceCollectorRegistry{return$this->collectors;}
	public function frameworks():PanelComplianceFrameworkCatalog{return$this->frameworks;}
	/** @param list<string> $frameworkIds @param array<string,mixed> $bindings @param array<string,mixed> $options */
	public function plan(array $frameworkIds,array $bindings=[],array $options=[]):PanelComplianceCollectionPlan{return PanelComplianceCollectionPlan::build($this->frameworks,$this->collectors,$frameworkIds,$bindings,$options);}

	/** Materializes exact, immutable framework controls into the durable ledger. @return array<string,array<string,mixed>> */
	public function install(PanelComplianceCollectionPlan $plan,string|int $actorId):array {
		$installed=[];foreach($plan->entries()as$entry){$installed[$entry['ledger_control_id']]=$this->ledger->ensureControl($entry['ledger_control_id'],[
			'title'=>$entry['title'],'framework'=>$entry['framework_id'],'owner'=>'compliance:automation','frequency'=>'continuous','automated'=>$entry['collectors']!==[],
			'metadata'=>['framework_version'=>$entry['framework_version'],'framework_hash'=>$entry['framework_fingerprint'],'framework_control_id'=>$entry['framework_control_id'],'control_hash'=>$entry['control_fingerprint'],'references'=>$entry['references'],'domains'=>$entry['domains'],'evidence_requirements'=>$entry['evidence_requirements'],'collection_plan_hash'=>$plan->fingerprint()],
		],$actorId);}return$installed;
	}

	/** Executes a pinned plan with collector isolation and a signed failure-aware result. @param array<string,mixed> $options */
	public function collect(PanelComplianceCollectionPlan $plan,string|int $actorId,array $options=[]):PanelComplianceCollectionRun {
		$actorId=PanelOperationsGuard::identifier($actorId,'compliance actor id');$startedAt=$this->now();$started=microtime(true);
		$runId=isset($options['run_id'])?PanelOperationsGuard::name((string)$options['run_id'],'compliance collection run id'):'run_'.substr(hash('sha256',$plan->fingerprint().'|'.$startedAt.'|'.random_bytes(24)),0,40);
		$results=[];$drift=$plan->drift($this->frameworks,$this->collectors);$driftByEntry=[];foreach($drift as$item){$driftByEntry[$item['entry_id']][]=$item;}
		$expired=$plan->expiredAt($startedAt);if(!$expired){$this->install($plan,$actorId);}
		foreach($plan->entries()as$entry){
			$elapsed=(microtime(true)-$started)*1000;
			if($expired){$results[]=$this->emptyResult($entry,'error','plan_expired');continue;}
			if($elapsed>$this->runBudgetMillis){$results[]=$this->emptyResult($entry,'error','run_budget_exhausted');continue;}
			if(isset($driftByEntry[$entry['id']])){$result=$this->emptyResult($entry,'error','dependency_drift');$result['drift']=$driftByEntry[$entry['id']];$results[]=$result;continue;}
			if($entry['collectors']===[]){$results[]=$this->emptyResult($entry,'missing','collector_unbound');continue;}
			$observations=[];
			foreach($entry['collectors']as$pin){
				if(((microtime(true)-$started)*1000)>$this->runBudgetMillis){$observations[]=$this->errorObservation($entry,$pin,'run_budget_exhausted',$startedAt,null);break;}
				$collector=$this->collectors->get($pin['id']);$context=new PanelComplianceCollectionContext($entry['ledger_control_id'],$entry['framework_id'],$entry['framework_control_id'],$entry['subject'],$startedAt,$plan->deadlineAt(),$plan->fingerprint(),$entry['references'],$plan->inputFor($entry['id']),['run_id'=>$runId,'collector_id'=>$pin['id']],$this->maxEvidenceItems);
				$probe=microtime(true);$observation=null;$errorCode=null;$exceptionClass=null;
				try{$observation=$collector->collect($context);if($observation->subject()!==$context->subject()){throw new \UnexpectedValueException('Compliance collector returned evidence for a different subject.');}$upper=(new \DateTimeImmutable($this->now()))->modify('+5 minutes')->format('Y-m-d\TH:i:s.u\Z');if(strcmp($observation->observedAt(),$upper)>0){throw new \UnexpectedValueException('Compliance collector observation time is too far in the future.');}}
				catch(\Throwable $error){$errorCode='collector_exception';$exceptionClass=$error::class;}
				$duration=(microtime(true)-$probe)*1000;
				if($duration>$this->collectorBudgetMillis){$errorCode='collector_budget_exceeded';$exceptionClass=null;}
				if($context->expiredAt($this->now())){$errorCode='collection_deadline_exceeded';$exceptionClass=null;}
				if($errorCode!==null||!$observation instanceof PanelComplianceObservation){$observation=PanelComplianceObservation::make('error',array_filter(['code'=>$errorCode??'invalid_observation','exception'=>$exceptionClass],static fn(mixed $value):bool=>$value!==null),['observed_at'=>$this->now(),'max_age_seconds'=>60,'subject'=>$entry['subject'],'source_reference'=>'collector:'.$pin['id'],'max_evidence_items'=>$this->maxEvidenceItems]);}
				$event=$this->ledger->recordObservation($entry['ledger_control_id'],$observation,$actorId,$pin['id'],['run_id'=>$runId,'plan_hash'=>$plan->fingerprint(),'framework_hash'=>$entry['framework_fingerprint'],'control_hash'=>$entry['control_fingerprint'],'collector_hash'=>$pin['fingerprint']]);
				$observations[]=['collector_id'=>$pin['id'],'collector_hash'=>$pin['fingerprint'],'status'=>$observation->status(),'observed_at'=>$observation->observedAt(),'valid_until'=>$observation->validUntil(),'observation_digest'=>$observation->digest(),'duration_ms'=>round($duration,3),'evidence_event_id'=>$event['id'],'evidence_event_hash'=>$event['hash']];
			}
			$results[]=$this->aggregate($entry,$observations);
		}
		$completedAt=$this->now();$summary=$this->summary($results);$payload=['type'=>'panel_compliance_collection_run','version'=>1,'run_id'=>$runId,'plan_fingerprint'=>$plan->fingerprint(),'catalog_fingerprint'=>$this->frameworks->fingerprint(),'registry_fingerprint'=>$this->collectors->fingerprint(),'started_at'=>$startedAt,'completed_at'=>$completedAt,'duration_ms'=>round((microtime(true)-$started)*1000,3),'subject'=>$plan->subject(),'expired_plan'=>$expired,'dependency_drift'=>$drift,'results'=>$results,'summary'=>$summary,'limits'=>['collector_millis'=>$this->collectorBudgetMillis,'run_millis'=>$this->runBudgetMillis,'evidence_items'=>$this->maxEvidenceItems],'claims'=>['certification'=>false,'compliance'=>false,'evidence_collection_only'=>true]];
		return$this->ledger->signCollectionRun($payload,$actorId);
	}

	public function coverage(PanelComplianceCollectionRun $run,string|int|\DateTimeInterface|null $evaluatedAt=null):PanelComplianceCoverageReport{return PanelComplianceCoverageReport::fromRun($run,$this->frameworks,$evaluatedAt??$this->now());}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp([
		'type'=>'panel_compliance_automation_manifest','version'=>1,'collector_budget_millis'=>$this->collectorBudgetMillis,'run_budget_millis'=>$this->runBudgetMillis,'max_evidence_items'=>$this->maxEvidenceItems,
		'collectors'=>$this->collectors->jsonSerialize(),'frameworks'=>$this->frameworks->jsonSerialize(),'ledger_verified'=>$this->ledger->verify(),
		'capabilities'=>['typed_collectors'=>true,'fingerprint_pinned_plans'=>true,'failure_isolation'=>true,'freshness'=>true,'signed_runs'=>true,'framework_crosswalks'=>true,'coverage_drift'=>true,'secret_free_manifests'=>true],
		'claims'=>['certification'=>false,'compliance'=>false,'legal_advice'=>false],
	]);}

	/** @param array<string,mixed> $entry @return array<string,mixed> */ private function emptyResult(array $entry,string $status,string $reason):array{return['entry_id'=>$entry['id'],'ledger_control_id'=>$entry['ledger_control_id'],'framework_id'=>$entry['framework_id'],'framework_control_id'=>$entry['framework_control_id'],'framework_hash'=>$entry['framework_fingerprint'],'control_hash'=>$entry['control_fingerprint'],'status'=>$status,'reason'=>$reason,'observed_at'=>null,'valid_until'=>null,'observations'=>[]];}
	/** @param array<string,mixed> $entry @param array<string,mixed> $pin @return array<string,mixed> */ private function errorObservation(array $entry,array $pin,string $code,string $observedAt,?string $exception):array {$observation=PanelComplianceObservation::make('error',array_filter(['code'=>$code,'exception'=>$exception],static fn(mixed $value):bool=>$value!==null),['observed_at'=>$observedAt,'max_age_seconds'=>60,'subject'=>$entry['subject'],'source_reference'=>'collector:'.$pin['id']]);return['collector_id'=>$pin['id'],'collector_hash'=>$pin['fingerprint'],'status'=>'error','observed_at'=>$observation->observedAt(),'valid_until'=>$observation->validUntil(),'observation_digest'=>$observation->digest(),'duration_ms'=>0.0,'evidence_event_id'=>null,'evidence_event_hash'=>null];}
	/** @param array<string,mixed> $entry @param list<array<string,mixed>> $observations @return array<string,mixed> */ private function aggregate(array $entry,array $observations):array {
		$statuses=array_column($observations,'status');$status=in_array('not_satisfied',$statuses,true)?'not_satisfied':(in_array('error',$statuses,true)?'error':(in_array('indeterminate',$statuses,true)?'indeterminate':((count(array_unique($statuses))===1&&$statuses[0]==='not_applicable')?'not_applicable':'satisfied')));
		$observed=array_values(array_filter(array_column($observations,'observed_at'),'is_string'));$valid=array_values(array_filter(array_column($observations,'valid_until'),'is_string'));sort($observed,SORT_STRING);sort($valid,SORT_STRING);
		return['entry_id'=>$entry['id'],'ledger_control_id'=>$entry['ledger_control_id'],'framework_id'=>$entry['framework_id'],'framework_control_id'=>$entry['framework_control_id'],'framework_hash'=>$entry['framework_fingerprint'],'control_hash'=>$entry['control_fingerprint'],'status'=>$status,'reason'=>null,'observed_at'=>$observed!==[]?$observed[array_key_last($observed)]:null,'valid_until'=>$valid[0]??null,'observations'=>$observations];
	}
	/** @param list<array<string,mixed>> $results @return array<string,mixed> */ private function summary(array $results):array {$statuses=['satisfied'=>0,'not_satisfied'=>0,'indeterminate'=>0,'not_applicable'=>0,'error'=>0,'missing'=>0];$observationCount=0;foreach($results as$result){$statuses[$result['status']]++;$observationCount+=count($result['observations']);}return['control_count'=>count($results),'observation_count'=>$observationCount,'statuses'=>$statuses,'failed_controls'=>$statuses['not_satisfied']+$statuses['indeterminate']+$statuses['error']+$statuses['missing'],'all_observed'=>$statuses['missing']===0,'all_positive'=>$statuses['not_satisfied']===0&&$statuses['indeterminate']===0&&$statuses['error']===0&&$statuses['missing']===0];}
	private function now():string {$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Compliance automation clock must return an instant.');}return PanelOperationsGuard::instant($value);}
}
