<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Fail-closed verifier for obligations that can be proven by the trusted command envelope. */
final class PanelStrictCommandObligationVerifier implements PanelCommandObligationVerifier {
	public function verify(PanelCommandEnvelope $command,PanelPolicyDecision $decision):PanelCommandObligationResult {
		$obligations=$decision->obligations();
		$evidence=$command->metadata();
		$reasons=[];
		if($decision->confirmationRequired()&&($evidence['confirmed']??false)!==true){
			$reasons[]='Explicit confirmation is required.';
		}
		if($decision->mfaLevel()>max(0,(int)($evidence['mfa_level']??0))){
			$reasons[]='The required MFA assurance level was not proven.';
		}
		if(($obligations['dry_run']??false)===true&&($evidence['dry_run']??false)!==true){
			$reasons[]='A dry-run execution is required.';
		}
		if(isset($obligations['max_cost_micros'])){
			$limit=max(0,(int)$obligations['max_cost_micros']);
			$cost=max(0,(int)($evidence['cost_micros']??PHP_INT_MAX));
			if($limit>0&&$cost>$limit){$reasons[]='The command exceeds its policy cost limit.';}
		}
		if($decision->approvalCount()>0){
			$reasons[]='Trusted approval evidence requires a host approval verifier.';
		}
		if(($obligations['separation_of_duties']??false)===true){
			$reasons[]='Separation-of-duties evidence requires a host approval verifier.';
		}
		return new PanelCommandObligationResult($reasons===[],$reasons,[
			'confirmed'=>($evidence['confirmed']??false)===true,
			'mfa_level'=>max(0,(int)($evidence['mfa_level']??0)),
			'dry_run'=>($evidence['dry_run']??false)===true,
			'approval_evidence'=>'host_verifier_required',
		]);
	}

	public function jsonSerialize():array {
		return [
			'type'=>'panel_strict_command_obligation_verifier','version'=>1,'fail_closed'=>true,
			'confirmation'=>true,'mfa'=>true,'dry_run'=>true,'cost_limit'=>true,
			'approval_evidence'=>'host_verifier_required','separation_of_duties'=>'host_verifier_required',
		];
	}
}
