<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Release-gate result that keeps automated proof separate from declared AT work. */
final class PanelInclusiveQualityResult implements \JsonSerializable {
	private array $rows=[];
	private array $automated;
	private array $declaredManual;
	private array $failures=[];

	/** @param list<PanelQualityEvidence|array<string,mixed>> $evidence @param array<string,int> $budgets */
	public function __construct(
		private readonly PanelInclusiveQualityMatrix $matrix,
		private readonly PanelQualityCapabilityReport $capabilities,
		array $evidence,
		private readonly array $budgets
	){
		if(count($evidence)>($budgets['max_evidence'] ?? 0)){ throw new \OverflowException('Inclusive quality evidence exceeds its record budget.'); }
		$cases=[];
		foreach($matrix->cases() as $case){ $cases[$case['id']]=$case; }
		$evidenceByCase=[];
		foreach($evidence as $record){
			$record=$record instanceof PanelQualityEvidence?$record:(is_array($record)?PanelQualityEvidence::fromArray($record):throw new \InvalidArgumentException('Inclusive quality evidence records must be arrays or PanelQualityEvidence values.'));
			$id=$record->caseId();
			if(!isset($cases[$id])){ throw new \InvalidArgumentException("Inclusive quality evidence references unknown case '{$id}'."); }
			if(in_array($record->status(),['passed','failed'],true) && !hash_equals($matrix->digest(),$record->matrixDigest() ?? '')){ throw new \UnexpectedValueException("Executed inclusive quality evidence for '{$id}' is not bound to this matrix digest."); }
			if(isset($evidenceByCase[$id])){ throw new \InvalidArgumentException("Inclusive quality evidence duplicates case '{$id}'."); }
			$evidenceByCase[$id]=$record;
		}
		foreach($cases as $id=>$case){ $this->rows[]=$this->evaluateCase($case,$evidenceByCase[$id] ?? null); }
		$this->automated=$this->summary(true);
		$this->declaredManual=$this->summary(false);
		$this->failures=$this->buildFailures();
	}

	public function passed(): bool { return $this->failures===[]; }
	/** @return list<array<string,mixed>> */ public function rows(): array { return $this->rows; }
	/** @return array<string,int> */ public function automated(): array { return $this->automated; }
	/** @return array<string,int> */ public function declaredManual(): array { return $this->declaredManual; }
	/** @return list<array<string,mixed>> */ public function failures(): array { return $this->failures; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_inclusive_quality_result','version'=>1,'passed'=>$this->passed(),'matrix'=>['name'=>$this->matrix->name(),'digest'=>$this->matrix->digest()],'capabilities'=>$this->capabilities->jsonSerialize(),'budgets'=>$this->budgets,'automated'=>$this->automated,'declared_manual'=>$this->declaredManual,'rows'=>$this->rows,'failures'=>$this->failures];
	}

	/** @param array<string,mixed> $case @return array<string,mixed> */
	private function evaluateCase(array $case,?PanelQualityEvidence $evidence): array {
		$contract=$case['contract']; $execution=(string)$contract['execution']; $automated=in_array($execution,['php','browser'],true); $issues=[];
		$missing=[];
		foreach($contract['required_capabilities'] as $capability){ if(!$this->capabilities->supports($capability,$execution)){ $missing[]=$capability; } }
		if($missing!==[]){ $issues[]=['code'=>'capability_unavailable','capabilities'=>$missing]; }
		$status=$evidence?->status() ?? 'missing';
		if($evidence!==null){
			if($evidence->execution()!==$execution){ $issues[]=['code'=>'execution_mismatch','expected'=>$execution,'actual'=>$evidence->execution()]; }
			if($evidence->status()==='passed' && $evidence->assertions()<($this->budgets['minimum_assertions'] ?? 1)){ $issues[]=['code'=>'assertion_budget','minimum'=>$this->budgets['minimum_assertions'],'actual'=>$evidence->assertions()]; }
			if($evidence->durationMs()>(float)$contract['max_millis']){ $issues[]=['code'=>'duration_budget','maximum'=>$contract['max_millis'],'actual'=>$evidence->durationMs()]; }
			$missingEvidenceCaps=array_values(array_diff($contract['required_capabilities'],$evidence->capabilities()));
			if($evidence->status()==='passed' && $missingEvidenceCaps!==[]){ $issues[]=['code'=>'evidence_capability_missing','capabilities'=>$missingEvidenceCaps]; }
		}
		if($evidence===null){ $status=$missing!==[]?'unavailable':'missing'; }
		elseif($issues!==[] || $status==='failed'){ $status='failed'; }
		return ['id'=>$case['id'],'profile_id'=>$case['profile']['id'],'contract_id'=>$contract['id'],'execution'=>$execution,'automation'=>$contract['automation'],'automated'=>$automated,'status'=>$status,'issues'=>$issues,'evidence'=>$evidence?->jsonSerialize()];
	}

	/** @return array<string,int> */
	private function summary(bool $automated): array {
		$rows=array_values(array_filter($this->rows,static fn(array $row):bool=>$row['automated']===$automated));
		$counts=['total'=>count($rows),'passed'=>0,'failed'=>0,'missing'=>0,'unavailable'=>0,'blocked'=>0,'not_run'=>0];
		foreach($rows as $row){ $status=(string)$row['status']; if(array_key_exists($status,$counts)){ $counts[$status]++; } }
		return $counts;
	}

	/** @return list<array<string,mixed>> */
	private function buildFailures(): array {
		$failures=[];
		$automatedMissing=$this->automated['missing']+$this->automated['unavailable']+$this->automated['blocked']+$this->automated['not_run'];
		if($this->automated['failed']>$this->budgets['max_automated_failures']){ $failures[]=['code'=>'automated_failures_exceeded','maximum'=>$this->budgets['max_automated_failures'],'actual'=>$this->automated['failed']]; }
		if($automatedMissing>$this->budgets['max_automated_missing']){ $failures[]=['code'=>'automated_evidence_missing','maximum'=>$this->budgets['max_automated_missing'],'actual'=>$automatedMissing]; }
		if($this->declaredManual['failed']>$this->budgets['max_manual_failures']){ $failures[]=['code'=>'manual_failures_exceeded','maximum'=>$this->budgets['max_manual_failures'],'actual'=>$this->declaredManual['failed']]; }
		if($this->declaredManual['passed']<$this->budgets['min_manual_passes']){ $failures[]=['code'=>'manual_evidence_below_minimum','minimum'=>$this->budgets['min_manual_passes'],'actual'=>$this->declaredManual['passed']]; }
		return $failures;
	}
}
