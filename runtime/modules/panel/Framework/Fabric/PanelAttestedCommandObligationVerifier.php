<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Verifies ordinary command obligations plus encrypted signed approval evidence. */
final class PanelAttestedCommandObligationVerifier implements PanelCommandObligationVerifier,\JsonSerializable {
	/** @var array<string,string> */private readonly array $keys;
	private readonly \Closure $clock;

	/** @param array<string,string> $trustedKeys */
	public function __construct(array $trustedKeys,?callable $clock=null){
		$keys=[];foreach($trustedKeys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'command approval key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Command approval trust keys require at least 32 bytes.');}$keys[$id]=$key;}
		if($keys===[]){throw new \InvalidArgumentException('Command approval verification requires a trust keyring.');}
		$this->keys=$keys;$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');
	}

	public function verify(PanelCommandEnvelope $command,PanelPolicyDecision $decision):PanelCommandObligationResult {
		$obligations=$decision->obligations();$metadata=$command->metadata();$reasons=[];$attestation=null;
		if($decision->confirmationRequired()&&($metadata['confirmed']??false)!==true){$reasons[]='Explicit confirmation is required.';}
		if($decision->mfaLevel()>max(0,(int)($metadata['mfa_level']??0))){$reasons[]='The required MFA assurance level was not proven.';}
		if(($obligations['dry_run']??false)===true&&($metadata['dry_run']??false)!==true){$reasons[]='A dry-run execution is required.';}
		if(isset($obligations['max_cost_micros'])){$limit=max(0,(int)$obligations['max_cost_micros']);$cost=max(0,(int)($metadata['cost_micros']??PHP_INT_MAX));if($limit>0&&$cost>$limit){$reasons[]='The command exceeds its policy cost limit.';}}

		$payload=$command->evidence()['approval_attestation']??null;
		if($payload!==null){
			try{$attestation=is_array($payload)?PanelCommandApprovalAttestation::hydrate($payload):null;if(!$attestation instanceof PanelCommandApprovalAttestation||!$attestation->verify($this->keys,$command->executionTarget(),$this->now())){$reasons[]='Command approval attestation is invalid or expired.';}}
			catch(\Throwable){$reasons[]='Command approval attestation is invalid or expired.';}
		}
		$required=$decision->approvalCount();
		if($required>0&&(!$attestation instanceof PanelCommandApprovalAttestation||$attestation->approvedCount()<$required)){$reasons[]='The command does not have enough trusted independent approvals.';}
		if(($obligations['separation_of_duties']??false)===true&&(!$attestation instanceof PanelCommandApprovalAttestation||$attestation->includesActor($command->actorId()))){$reasons[]='Command approval evidence does not satisfy separation of duties.';}
		return new PanelCommandObligationResult($reasons===[],array_values(array_unique($reasons)),[
			'confirmed'=>($metadata['confirmed']??false)===true,'mfa_level'=>max(0,(int)($metadata['mfa_level']??0)),'dry_run'=>($metadata['dry_run']??false)===true,
			'approval_evidence'=>$attestation instanceof PanelCommandApprovalAttestation?'signed_attestation':'absent','approved_count'=>$attestation?->approvedCount()??0,'approval_source'=>$attestation?->source(),
		]);
	}

	public function jsonSerialize():array{return['type'=>'panel_attested_command_obligation_verifier','version'=>1,'confirmation'=>true,'mfa'=>true,'dry_run'=>true,'cost_limit'=>true,'signed_approvals'=>true,'separation_of_duties'=>true,'trusted_key_ids'=>array_keys($this->keys),'keys_exposed'=>false,'evidence_transport'=>'encrypted_command_envelope'];}
	private function now():string{$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Command approval clock must return an instant.');}return PanelOperationsGuard::instant($value);}
}
