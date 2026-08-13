<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** First-party marketplace review, quarantine, approval, revocation, and least-privilege activation. */
final class PanelMarketplaceGovernance implements \JsonSerializable {
	/** @var array<string,PanelMarketplaceReview> */
	private array $reviews=[];
	/** @var array<string,array<string,mixed>> */
	private array $securitySubjects=[];
	private readonly \Closure $clock;
	/** @var list<string> */
	private readonly array $allowedPublisherStatuses;

	/** @param list<string> $criticalPermissions @param list<string> $allowedPublisherStatuses */
	public function __construct(
		private readonly PanelPolicyControlPlane $policy,
		private readonly int $requiredApprovals=2,
		private readonly array $criticalPermissions=['filesystem.*','process.*','network.unrestricted','secrets.read'],
		?callable $clock=null,
		private readonly ?PanelPackageRevocationRegistry $revocations=null,
		private readonly ?PanelPackagePublisherTrustRegistry $publishers=null,
		array $allowedPublisherStatuses=['observed']
	){
		if($requiredApprovals<1||$requiredApprovals>16){throw new \InvalidArgumentException('Marketplace approval quorum is invalid.');}
		PanelOperationsGuard::abilityPatterns($criticalPermissions,'critical marketplace permission');
		$allowed=PanelOperationsGuard::names($allowedPublisherStatuses,'allowed publisher status');
		foreach($allowed as$status){if(!in_array($status,['unknown','observed','restricted','blocked'],true)){throw new \InvalidArgumentException('Allowed publisher status is unsupported.');}}
		$this->allowedPublisherStatuses=$allowed;
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');
	}

	/** @param array<string,mixed> $submission */
	public function review(PanelPackageManifest $package,PanelPackageTrustReport $trust,array $submission,PanelPolicyRequest|array $request):PanelMarketplaceReview {
		$request=$request instanceof PanelPolicyRequest?$request:PanelPolicyRequest::from($request);$this->authorize($request,'marketplace.review');
		$manifest=$package->jsonSerialize();$id=PanelOperationsGuard::name((string)($manifest['id']??''),'marketplace package id');$version=(string)($manifest['version']??'unversioned');
		$permissions=PanelOperationsGuard::abilityPatterns(is_array($submission['permissions']??null)?$submission['permissions']:[],'marketplace permission',2048);
		$sbom=is_array($submission['sbom']??null)?$submission['sbom']:[];$provenance=is_array($submission['provenance']??null)?$submission['provenance']:[];$compatibility=is_array($submission['compatibility']??null)?$submission['compatibility']:[];$findings=[];$risk=0;
		if(!$trust->ok()){$findings[]=$this->finding('trust_failed','critical','Package trust policy rejected this artifact.');$risk+=50;}
		if($sbom===[]){$findings[]=$this->finding('sbom_missing','high','A complete software bill of materials is required.');$risk+=25;}
		if(($provenance['builder']??null)===null||($provenance['source_digest']??null)===null){$findings[]=$this->finding('provenance_incomplete','high','Reproducible builder and source provenance are required.');$risk+=20;}
		if(($compatibility['passed']??false)!==true){$findings[]=$this->finding('compatibility_failed','high','The package compatibility matrix did not pass.');$risk+=25;}
		foreach($permissions as$permission){foreach($this->criticalPermissions as$pattern){if(PanelOperationsGuard::abilityMatches($pattern,$permission)){$findings[]=$this->finding('critical_permission','critical','Package requests a critical capability: '.$permission.'.');$risk+=20;break;}}}
		foreach($sbom as$component){if(is_array($component)&&in_array(($component['vulnerability_severity']??null),['critical','high'],true)){$severity=(string)$component['vulnerability_severity'];$findings[]=$this->finding('vulnerable_component',$severity,'SBOM contains a '.$severity.' severity vulnerability.');$risk+=$severity==='critical'?40:20;}}

		$subject=$this->securitySubject($manifest);
		$revocation=$this->revocationDecision($subject);
		if(is_array($revocation)&&($revocation['allowed']??false)!==true){$code=($revocation['revoked']??false)===true?'package_revoked':'revocation_state_unavailable';$findings[]=$this->finding($code,'critical',($revocation['revoked']??false)===true?'A verified marketplace revocation blocks this package.':'Marketplace revocation state is incomplete or stale.');$risk+=50;}
		$publisherProfile=$this->publisherProfile($subject);
		if(is_array($publisherProfile)&&(!$this->publisherEligible($publisherProfile))){$status=(string)($publisherProfile['status']??'unknown');$findings[]=$this->finding('publisher_evidence_'.$status,'critical','Publisher evidence does not satisfy marketplace activation policy.');$risk+=40;}

		$risk=min(100,$risk);$critical=array_filter($findings,static fn(array $finding):bool=>$finding['severity']==='critical')!==[];
		$status=$critical?'rejected':($risk>=40?'quarantined':'candidate');
		$sandbox=['network_allowlist'=>array_values(array_filter(array_map('strval',is_array($submission['network_allowlist']??null)?$submission['network_allowlist']:[]))),'filesystem_roots'=>[],'process_execution'=>false,'secret_access'=>false,'permission_allowlist'=>$permissions];
		$digest=PanelOperationsGuard::digest(['manifest'=>$manifest,'trust'=>$trust->jsonSerialize(),'submission'=>PanelSensitiveDataSanitizer::sanitize($submission),'revocation'=>$revocation,'publisher_profile'=>$publisherProfile]);
		$review=new PanelMarketplaceReview($id,$version,$digest,$status,$risk,$findings,$permissions,$sandbox,$this->now(),$this->requiredApprovals);
		$this->reviews[$id]=$review;$this->securitySubjects[$id]=$subject;ksort($this->reviews,SORT_STRING);ksort($this->securitySubjects,SORT_STRING);return$review;
	}

	/** @param list<string> $approvers */
	public function approve(PanelMarketplaceReview $review,array $approvers,PanelPolicyRequest|array $request):PanelMarketplaceReview {
		$request=$request instanceof PanelPolicyRequest?$request:PanelPolicyRequest::from($request);$this->authorize($request,'marketplace.publish');$approvers=PanelOperationsGuard::identifiers($approvers,'marketplace approver id');
		if(in_array($request->actorId(),$approvers,true)){throw new \LogicException('Marketplace requester cannot satisfy independent review quorum.');}
		$approved=$review->approve($approvers);$current=$this->reviews[$approved->packageId()]??null;
		if(!$current||!hash_equals($current->fingerprint(),$review->fingerprint())){throw new \LogicException('Marketplace review is stale or was not produced by this control plane.');}
		$this->reviews[$approved->packageId()]=$approved;return$approved;
	}

	/** @return array<string,mixed> */
	public function activation(string $packageId):array {
		$packageId=PanelOperationsGuard::name($packageId,'marketplace package id');$review=$this->reviews[$packageId]??null;
		if(!$review||$review->status()!=='approved'){throw new \LogicException('Marketplace package is not approved for activation.');}
		$subject=$this->securitySubjects[$packageId]??null;if(!is_array($subject)){throw new \LogicException('Marketplace activation security subject is missing.');}
		$revocation=$this->revocationDecision($subject);if(is_array($revocation)&&($revocation['allowed']??false)!==true){throw new \LogicException('Marketplace activation is blocked by revoked, incomplete, or stale package trust state.');}
		$publisherProfile=$this->publisherProfile($subject);if(is_array($publisherProfile)&&!$this->publisherEligible($publisherProfile)){throw new \LogicException('Marketplace activation is blocked by current publisher evidence.');}
		return['package_id'=>$packageId,'package_digest'=>$review->packageDigest(),'permissions'=>$review->permissions(),'sandbox'=>$review->sandbox(),'approvers'=>$review->approvers(),'review_fingerprint'=>$review->fingerprint(),'revocation'=>$revocation,'publisher_trust'=>$publisherProfile];
	}

	public function revocationRegistry():?PanelPackageRevocationRegistry{return$this->revocations;}
	public function publisherTrustRegistry():?PanelPackagePublisherTrustRegistry{return$this->publishers;}

	public function jsonSerialize():array{
		return PanelManifestContract::stamp(['type'=>'panel_marketplace_governance_manifest','version'=>1,'review_count'=>count($this->reviews),'reviews'=>array_map(static fn(PanelMarketplaceReview $review):array=>$review->jsonSerialize(),$this->reviews),'required_approvals'=>$this->requiredApprovals,'critical_permissions'=>$this->criticalPermissions,'allowed_publisher_statuses'=>$this->allowedPublisherStatuses,'policy_revision'=>$this->policy->revision(),'capabilities'=>['first_party_review'=>true,'trust_required'=>true,'sbom_required'=>true,'provenance_required'=>true,'compatibility_required'=>true,'vulnerability_gate'=>true,'permission_review'=>true,'quarantine'=>true,'independent_approvals'=>true,'least_privilege_sandbox'=>true,'revocation_recheck'=>$this->revocations instanceof PanelPackageRevocationRegistry,'publisher_evidence_recheck'=>$this->publishers instanceof PanelPackagePublisherTrustRegistry,'scalar_publisher_score'=>false]]);
	}

	private function authorize(PanelPolicyRequest $request,string $ability):void{$payload=$request->jsonSerialize();$payload['ability']=$ability;$this->policy->evaluate(PanelPolicyRequest::from($payload))->assertAllowed();}
	/** @return array{code:string,severity:string,message:string} */private function finding(string $code,string $severity,string $message):array{return['code'=>PanelOperationsGuard::name($code,'marketplace finding code'),'severity'=>$severity,'message'=>PanelOperationsGuard::label($message,'marketplace finding',2048)];}
	/** @param array<string,mixed> $manifest @return array<string,mixed> */private function securitySubject(array $manifest):array{$signature=is_array($manifest['signature']??null)?$manifest['signature']:[];$publisher=Resource::normalizeName((string)($signature['publisher']??$manifest['support']['owner']??$manifest['meta']['publisher']??''));$subject=['package'=>PanelOperationsGuard::name((string)$manifest['id'],'marketplace package id'),'version'=>(string)$manifest['version']];if($publisher!=='')$subject['publisher']=$publisher;$keyId=trim((string)($signature['key_id']??$signature['key']??''));if($keyId!=='')$subject['key_id']=PanelOperationsGuard::identifier($keyId,'marketplace signing key id',256);return$subject;}
	/** @param array<string,mixed> $subject @return array<string,mixed>|null */private function revocationDecision(array $subject):?array{return$this->revocations?->decision('package',$subject)->jsonSerialize();}
	/** @param array<string,mixed> $subject @return array<string,mixed>|null */private function publisherProfile(array $subject):?array{if(!$this->publishers instanceof PanelPackagePublisherTrustRegistry)return null;$publisher=$subject['publisher']??null;if(!is_string($publisher))return['complete'=>false,'stale'=>true,'status'=>'unknown'];return$this->publishers->profile($publisher)->jsonSerialize();}
	/** @param array<string,mixed> $profile */private function publisherEligible(array $profile):bool{return($profile['complete']??false)===true&&($profile['stale']??true)!==true&&in_array((string)($profile['status']??'unknown'),$this->allowedPublisherStatuses,true);}
	private function now():string{$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Marketplace clock must return an instant.');}return PanelOperationsGuard::instant($value);}
}
