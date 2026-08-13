<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable canonical plan bound to one scope, catalog revision, and policy. */
final class PanelAgentPlan implements \JsonSerializable {
	/** @param list<PanelAgentPlanStep> $steps */
	public function __construct(
		private readonly string $title,
		private readonly string $scopeFingerprint,
		private readonly string $subjectFingerprint,
		private readonly string $catalogFingerprint,
		private readonly int $catalogRevision,
		private readonly string $policyFingerprint,
		private readonly array $steps,
		private readonly int $createdAt,
		private readonly ?string $confirmationVerifierFingerprint=null
	){
		PanelAgentGuard::boundedString($title, 'plan title', 512);
		PanelAgentGuard::digest($scopeFingerprint, 'scope fingerprint');
		PanelAgentGuard::digest($subjectFingerprint, 'subject fingerprint');
		PanelAgentGuard::digest($catalogFingerprint, 'catalog fingerprint');
		PanelAgentGuard::digest($policyFingerprint, 'policy fingerprint');
		if($confirmationVerifierFingerprint!==null){ PanelAgentGuard::digest($confirmationVerifierFingerprint, 'confirmation verifier fingerprint'); }
		if($catalogRevision<0){ throw new \InvalidArgumentException('Panel agent catalog revision cannot be negative.'); }
		if($steps===[] || count($steps)>32){ throw new \LengthException('Panel agent plans require between one and 32 steps.'); }
		foreach($steps as $index=>$step){
			if(!$step instanceof PanelAgentPlanStep || $step->ordinal()!==$index+1){ throw new \InvalidArgumentException('Panel agent plan steps must be ordered and contiguous.'); }
		}
		if($createdAt<0){ throw new \InvalidArgumentException('Panel agent plan creation time is invalid.'); }
	}

	public function title(): string { return trim($this->title); }
	public function scopeFingerprint(): string { return strtolower($this->scopeFingerprint); }
	public function subjectFingerprint(): string { return strtolower($this->subjectFingerprint); }
	public function catalogFingerprint(): string { return strtolower($this->catalogFingerprint); }
	public function catalogRevision(): int { return $this->catalogRevision; }
	public function policyFingerprint(): string { return strtolower($this->policyFingerprint); }
	public function confirmationVerifierFingerprint(): ?string { return $this->confirmationVerifierFingerprint===null ? null : strtolower($this->confirmationVerifierFingerprint); }
	/** @return list<PanelAgentPlanStep> */ public function steps(): array { return $this->steps; }
	public function createdAt(): int { return $this->createdAt; }
	public function approvalCount(): int { return max(array_map(static fn(PanelAgentPlanStep $step): int=>$step->approvalCount(), $this->steps)); }
	public function confirmationRequired(): bool { return array_filter($this->steps, static fn(PanelAgentPlanStep $step): bool=>$step->confirmationRequired())!==[]; }
	public function separationOfDuties(): bool { return array_filter($this->steps, static fn(PanelAgentPlanStep $step): bool=>$step->separationOfDuties())!==[]; }

	public function hash(): string { return hash('sha256', PanelAgentGuard::canonicalJson($this->canonicalPayload())); }

	/**
	 * Exports the complete immutable plan for an encrypted execution boundary.
	 *
	 * This is intentionally distinct from jsonSerialize(), whose nested steps
	 * redact arguments. Callers must seal this payload before persistence.
	 *
	 * @return array<string,mixed>
	 */
	public function executionPayload(): array {
		$payload=$this->canonicalPayload();
		$payload['steps']=array_map(static fn(PanelAgentPlanStep $step):array=>$step->executionPayload(),$this->steps);
		return ['type'=>'panel_agent_plan_execution','version'=>1,'hash'=>$this->hash()]+$payload;
	}

	/** @param array<string,mixed> $payload */
	public static function hydrateExecutionPayload(array $payload): self {
		$required=['type','version','hash','title','scope_fingerprint','subject_fingerprint','catalog_fingerprint','catalog_revision','policy_fingerprint','confirmation_verifier_fingerprint','steps','created_at'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if(
			$keys!==$required
			||($payload['type']??null)!=='panel_agent_plan_execution'
			||($payload['version']??null)!==1
			||!is_string($payload['hash']??null)
			||!is_string($payload['title']??null)
			||!is_string($payload['scope_fingerprint']??null)
			||!is_string($payload['subject_fingerprint']??null)
			||!is_string($payload['catalog_fingerprint']??null)
			||!is_int($payload['catalog_revision']??null)
			||!is_string($payload['policy_fingerprint']??null)
			||(!is_string($payload['confirmation_verifier_fingerprint']??null)&&($payload['confirmation_verifier_fingerprint']??null)!==null)
			||!is_array($payload['steps']??null)||!array_is_list($payload['steps'])
			||!is_int($payload['created_at']??null)
		){
			throw new \UnexpectedValueException('Stored Panel agent execution plan is invalid.');
		}
		try{
			$steps=array_map(
				static fn(mixed $step):PanelAgentPlanStep=>is_array($step)
					?PanelAgentPlanStep::hydrateExecutionPayload($step)
					:throw new \UnexpectedValueException('Stored Panel agent execution step is invalid.'),
				$payload['steps'],
			);
			$self=new self(
				$payload['title'],$payload['scope_fingerprint'],$payload['subject_fingerprint'],$payload['catalog_fingerprint'],
				$payload['catalog_revision'],$payload['policy_fingerprint'],$steps,$payload['created_at'],$payload['confirmation_verifier_fingerprint'],
			);
		}catch(\UnexpectedValueException $error){throw$error;}
		catch(\Throwable $error){throw new \UnexpectedValueException('Stored Panel agent execution plan is invalid.',0,$error);}
		if(!hash_equals($self->hash(),$payload['hash'])){
			throw new \UnexpectedValueException('Stored Panel agent execution plan integrity check failed.');
		}
		return$self;
	}

	private function canonicalPayload(): array {
		return [
			'title'=>$this->title(),'scope_fingerprint'=>$this->scopeFingerprint(),'subject_fingerprint'=>$this->subjectFingerprint(),
			'catalog_fingerprint'=>$this->catalogFingerprint(),'catalog_revision'=>$this->catalogRevision,
			'policy_fingerprint'=>$this->policyFingerprint(),'confirmation_verifier_fingerprint'=>$this->confirmationVerifierFingerprint(),
			'steps'=>array_map(static fn(PanelAgentPlanStep $step): array=>[
				'ordinal'=>$step->ordinal(),'tool'=>$step->tool(),'tool_version'=>$step->toolVersion(),
				'tool_fingerprint'=>$step->toolFingerprint(),'arguments'=>$step->arguments(),'dry_run'=>$step->dryRun(),
				'approval_count'=>$step->approvalCount(),'confirmation_required'=>$step->confirmationRequired(),
				'separation_of_duties'=>$step->separationOfDuties(),
			], $this->steps),
			'created_at'=>$this->createdAt,
		];
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_plan','version'=>1,'title'=>$this->title(),'hash'=>$this->hash(),
			'scope_fingerprint'=>$this->scopeFingerprint(),'subject_fingerprint'=>$this->subjectFingerprint(),
			'catalog_fingerprint'=>$this->catalogFingerprint(),'catalog_revision'=>$this->catalogRevision,
			'policy_fingerprint'=>$this->policyFingerprint(),'confirmation_verifier_fingerprint'=>$this->confirmationVerifierFingerprint(),'steps'=>$this->steps,'created_at'=>$this->createdAt,
			'requirements'=>['approval_count'=>$this->approvalCount(),'confirmation'=>$this->confirmationRequired(),'separation_of_duties'=>$this->separationOfDuties()],
		];
	}
}
