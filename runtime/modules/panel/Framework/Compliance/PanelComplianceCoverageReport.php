<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Evidence-coverage report that deliberately makes no compliance or certification claim. */
final class PanelComplianceCoverageReport implements \JsonSerializable {
	private readonly string $evaluatedAt;
	/** @var array<string,array<string,mixed>> */ private readonly array $controls;
	/** @var array<string,array<string,mixed>> */ private readonly array $frameworks;
	/** @var list<array<string,mixed>> */ private readonly array $crosswalks;
	/** @var array<string,mixed> */ private readonly array $summary;
	private readonly string $fingerprint;

	private function __construct(private readonly string $runDigest,string $evaluatedAt,array $controls,array $frameworks,array $crosswalks,array $summary){$this->evaluatedAt=PanelOperationsGuard::instant($evaluatedAt,'compliance coverage evaluation time');$this->controls=$controls;$this->frameworks=$frameworks;$this->crosswalks=$crosswalks;$this->summary=$summary;$this->fingerprint=PanelOperationsGuard::digest($this->payload());}

	public static function fromRun(PanelComplianceCollectionRun $run,PanelComplianceFrameworkCatalog $catalog,string|int|\DateTimeInterface $evaluatedAt):self {
		$now=PanelOperationsGuard::instant($evaluatedAt);$controls=[];$frameworks=[];$statusMap=[];
		foreach($run->results()as$result){$validUntil=is_string($result['valid_until']??null)?$result['valid_until']:null;$observedAt=is_string($result['observed_at']??null)?$result['observed_at']:null;$stale=$validUntil!==null&&strcmp($now,$validUntil)>0;$future=$observedAt!==null&&strcmp($now,$observedAt)<0;$status=(string)$result['status'];$current=$status!=='missing'&&!$stale&&!$future;
			$item=['entry_id'=>$result['entry_id'],'ledger_control_id'=>$result['ledger_control_id'],'framework_id'=>$result['framework_id'],'framework_control_id'=>$result['framework_control_id'],'status'=>$status,'stale'=>$stale,'future_observation'=>$future,'current_evidence'=>$current,'observed_at'=>$observedAt,'valid_until'=>$validUntil,'collector_count'=>count($result['observations']),'collector_hashes'=>array_values(array_map(static fn(array $observation):string=>(string)($observation['collector_hash']??''),$result['observations']))];$controls[$item['entry_id']]=$item;$statusMap[$item['entry_id']]=$item;
			$framework=$frameworks[$item['framework_id']]??=['total'=>0,'current_evidence'=>0,'stale'=>0,'future_observation'=>0,'missing'=>0,'satisfied'=>0,'not_satisfied'=>0,'indeterminate'=>0,'not_applicable'=>0,'error'=>0];$framework['total']++;if($current){$framework['current_evidence']++;}if($stale){$framework['stale']++;}if($future){$framework['future_observation']++;}$framework[$status]++;$frameworks[$item['framework_id']]=$framework;
		}
		ksort($controls,SORT_STRING);ksort($frameworks,SORT_STRING);$crosswalks=[];
		foreach($controls as$entryId=>$control){try{foreach($catalog->crosswalks($control['framework_id'],$control['framework_control_id'])as$mapping){$targetId=$mapping['framework'].'.'.$mapping['control'];$target=$statusMap[$targetId]??null;$crosswalks[]=['from'=>$entryId,'to'=>$targetId,'relation'=>$mapping['relation'],'target_present'=>is_array($target),'from_status'=>$control['status'],'to_status'=>is_array($target)?$target['status']:null,'equivalence_claimed'=>$mapping['equivalence_claimed']];}}catch(\Throwable){}}
		usort($crosswalks,static fn(array $a,array $b):int=>[$a['from'],$a['to'],$a['relation']]<=>[$b['from'],$b['to'],$b['relation']]);
		$total=count($controls);$current=count(array_filter($controls,static fn(array $item):bool=>$item['current_evidence']));$stale=count(array_filter($controls,static fn(array $item):bool=>$item['stale']));$future=count(array_filter($controls,static fn(array $item):bool=>$item['future_observation']));$statuses=['satisfied'=>0,'not_satisfied'=>0,'indeterminate'=>0,'not_applicable'=>0,'error'=>0,'missing'=>0];foreach($controls as$item){$statuses[$item['status']]++;}
		$summary=['total_controls'=>$total,'current_evidence_controls'=>$current,'evidence_coverage_basis_points'=>$total===0?0:(int)floor(($current*10000)/$total),'stale_controls'=>$stale,'future_observation_controls'=>$future,'statuses'=>$statuses,'all_controls_observed'=>$total>0&&$current===$total,'no_negative_observations'=>$total>0&&$current===$total&&$statuses['not_satisfied']===0&&$statuses['indeterminate']===0&&$statuses['error']===0&&$statuses['missing']===0];
		return new self($run->digest(),$now,$controls,$frameworks,$crosswalks,$summary);
	}

	public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,mixed> */ public function summary():array{return$this->summary;}
	/** @return array<string,array<string,mixed>> */ public function controls():array{return$this->controls;}
	/** Compares evidence state only, not regulatory compliance. @return array<string,mixed> */
	public function drift(self $baseline):array {
		$newGaps=[];$resolved=[];$changes=[];$collectorChanges=[];$all=array_unique([...array_keys($baseline->controls),...array_keys($this->controls)]);sort($all,SORT_STRING);
		foreach($all as$id){$before=$baseline->controls[$id]??null;$after=$this->controls[$id]??null;$beforeGap=!is_array($before)||!$before['current_evidence']||in_array($before['status'],['not_satisfied','indeterminate','error','missing'],true);$afterGap=!is_array($after)||!$after['current_evidence']||in_array($after['status'],['not_satisfied','indeterminate','error','missing'],true);if(!$beforeGap&&$afterGap){$newGaps[]=$id;}if($beforeGap&&!$afterGap){$resolved[]=$id;}if(is_array($before)&&is_array($after)&&($before['status']!==$after['status']||$before['stale']!==$after['stale']||$before['future_observation']!==$after['future_observation'])){$changes[]=['entry_id'=>$id,'before_status'=>$before['status'],'after_status'=>$after['status'],'before_stale'=>$before['stale'],'after_stale'=>$after['stale'],'before_future'=>$before['future_observation'],'after_future'=>$after['future_observation']];}if(is_array($before)&&is_array($after)&&$before['collector_hashes']!==$after['collector_hashes']){$collectorChanges[]=$id;}}
		return['type'=>'panel_compliance_evidence_drift','version'=>1,'baseline'=>$baseline->fingerprint,'current'=>$this->fingerprint,'new_gaps'=>$newGaps,'resolved_gaps'=>$resolved,'status_changes'=>$changes,'collector_changes'=>$collectorChanges,'compliance_claimed'=>false];
	}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp($this->payload()+['fingerprint'=>$this->fingerprint]);}
	/** @return array<string,mixed> */ private function payload():array{return['type'=>'panel_compliance_coverage_report','version'=>1,'run_digest'=>$this->runDigest,'evaluated_at'=>$this->evaluatedAt,'summary'=>$this->summary,'frameworks'=>$this->frameworks,'controls'=>$this->controls,'crosswalks'=>$this->crosswalks,'claims'=>['certification'=>false,'legal_advice'=>false,'compliance'=>false,'evidence_coverage_only'=>true]];}
}
