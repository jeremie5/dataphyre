<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Default-deny policy boundary with risk floors, revisioning, and kill switch. */
final class PanelAgentPolicyEngine implements PanelCheckpointableService, \JsonSerializable {
	private bool $killed=false;
	private string $killReason='';
	private int $revision=0;
	private readonly string $validatedResolverFingerprint;

	public function __construct(private readonly ?PanelAgentPolicyResolver $resolver=null) {
		$fingerprint=$resolver===null ? hash('sha256', 'panel-agent-default-deny-v1') : $resolver->fingerprint();
		$this->validatedResolverFingerprint=PanelAgentGuard::digest($fingerprint, 'policy resolver fingerprint');
	}

	/** @param array<string,mixed> $arguments */
	public function evaluate(PanelAgentRequestContext $context, PanelAgentTool $tool, array $arguments): PanelAgentPolicyDecision {
		if($this->killed){ return PanelAgentPolicyDecision::deny('The Panel agent kill switch is engaged.', ['kill_switch'=>true]); }
		if($this->resolver===null){ return PanelAgentPolicyDecision::deny('No host Panel agent policy resolver is installed.'); }
		try{ $decision=$this->resolver->decide($context, $tool, $arguments); }
		catch(\Throwable){ return PanelAgentPolicyDecision::deny('The host Panel agent policy resolver failed closed.', ['policy_error'=>true]); }
		if(!$decision->allowed()){ return $decision; }
		$riskApproval=match($tool->risk()){'high'=>1,'critical'=>2,default=>0};
		$riskConfirmation=in_array($tool->risk(), ['medium','high','critical'], true);
		$riskSeparation=$tool->risk()==='critical';
		return PanelAgentPolicyDecision::allow(
			$decision->reason(),
			max($riskApproval, $tool->approvalCount(), $decision->approvalCount()),
			$riskConfirmation || $tool->confirmationRequired() || $decision->confirmationRequired(),
			$riskSeparation || $tool->separationOfDuties() || $decision->separationOfDuties(),
			$decision->metadata()
		);
	}

	public function authorizeApproval(PanelAgentRequestContext $approver, PanelAgentPlan $plan): PanelAgentPolicyDecision {
		if($this->killed){ return PanelAgentPolicyDecision::deny('The Panel agent kill switch is engaged.', ['kill_switch'=>true]); }
		if($this->resolver===null){ return PanelAgentPolicyDecision::deny('No host Panel agent policy resolver is installed.'); }
		try{ return $this->resolver->approve($approver,$plan); }
		catch(\Throwable){ return PanelAgentPolicyDecision::deny('The host Panel agent approval policy failed closed.', ['policy_error'=>true]); }
	}

	public function engageKillSwitch(string $reason): self {
		$reason=PanelAgentGuard::boundedString($reason, 'kill switch reason', 1024);
		if(!$this->killed || $this->killReason!==$reason){ $this->killed=true; $this->killReason=$reason; $this->revision++; }
		return $this;
	}
	public function releaseKillSwitch(): self { if($this->killed){ $this->killed=false; $this->killReason=''; $this->revision++; } return $this; }
	public function killed(): bool { return $this->killed; }
	public function revision(): int { return $this->revision; }
	public function fingerprint(): string { return hash('sha256', PanelAgentGuard::canonicalJson(['resolver'=>$this->resolverFingerprint(),'revision'=>$this->revision,'killed'=>$this->killed])); }

	public function checkpointType(): string { return 'panel_agent_policy_engine_v1'; }
	public function checkpoint(): array { return ['type'=>$this->checkpointType(),'resolver_fingerprint'=>$this->resolverFingerprint(),'revision'=>$this->revision,'killed'=>$this->killed,'kill_reason'=>$this->killReason]; }
	public function restore(array $checkpoint): PanelCheckpointableService {
		if(array_keys($checkpoint)!==['type','resolver_fingerprint','revision','killed','kill_reason'] || $checkpoint['type']!==$this->checkpointType() || $checkpoint['resolver_fingerprint']!==$this->resolverFingerprint() || !is_int($checkpoint['revision']) || $checkpoint['revision']<0 || !is_bool($checkpoint['killed']) || !is_string($checkpoint['kill_reason'])){
			throw new \InvalidArgumentException('Panel agent policy checkpoint is invalid.');
		}
		if($checkpoint['killed']){ PanelAgentGuard::boundedString($checkpoint['kill_reason'], 'kill switch reason', 1024); }
		elseif($checkpoint['kill_reason']!==''){ throw new \InvalidArgumentException('Panel agent policy checkpoint kill state is inconsistent.'); }
		$this->revision=$checkpoint['revision']; $this->killed=$checkpoint['killed']; $this->killReason=$checkpoint['kill_reason'];
		return $this;
	}

	public function jsonSerialize(): array {
		$killReasonTag=$this->killed ? hash('sha256',"panel-agent-kill-reason-v1\0".$this->killReason) : null;
		return [
			'type'=>'panel_agent_policy_engine','version'=>1,'default_deny'=>$this->resolver===null,
			'resolver_installed'=>$this->resolver!==null,'revision'=>$this->revision,'fingerprint'=>$this->fingerprint(),
			'kill_switch'=>$this->killed,'kill_reason_configured'=>$this->killed,'kill_reason_tag'=>$killReasonTag,
			'permission_resolver_host_owned'=>true,'resolver_class_exposed'=>false,
		];
	}

	private function resolverFingerprint(): string { return $this->validatedResolverFingerprint; }
}
