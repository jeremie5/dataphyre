<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed outcome observation used to measure recommendation effectiveness. */
final class PanelIntelligenceFeedback implements \JsonSerializable {
	public const OUTCOMES=['positive','neutral','negative','unknown'];
	private readonly string $digest;
	private readonly string $evidenceDigest;
	/** @var array<string,mixed> */private readonly array $evidence;

	/** @param array<string,mixed>|null $evidence */
	private function __construct(
		private readonly string $id,private readonly string $proposalId,private readonly string $signalId,
		private readonly string $tenantId,private readonly string $command,private readonly string $outcome,
		private readonly int $effectivenessBasisPoints,string $evidenceDigest,?array $evidence,
		private readonly string $reporterHash,private readonly string $observedAt,
		private readonly string $keyId,private readonly string $signature,
	){
		PanelOperationsGuard::identifier($id,'intelligence feedback id',190);PanelOperationsGuard::identifier($proposalId,'intelligence feedback proposal id',190);PanelOperationsGuard::identifier($signalId,'intelligence feedback signal id',190);PanelOperationsGuard::identifier($tenantId,'intelligence feedback tenant');PanelOperationsGuard::name($command,'intelligence feedback command',160);
		if(!in_array($outcome,self::OUTCOMES,true)||$effectivenessBasisPoints<0||$effectivenessBasisPoints>10000||preg_match('/^[a-f0-9]{64}$/D',$reporterHash)!==1||preg_match('/^[a-f0-9]{64}$/D',$evidenceDigest)!==1){throw new \InvalidArgumentException('Intelligence feedback outcome or digest is invalid.');}
		$this->evidenceDigest=$evidenceDigest;$this->evidence=$evidence===null?[]:PanelOperationsGuard::safeMetadata($evidence,256);
		if($evidence!==null&&!hash_equals($evidenceDigest,PanelOperationsGuard::digest($this->evidence))){throw new \UnexpectedValueException('Intelligence feedback evidence does not match its digest.');}
		if(PanelOperationsGuard::instant($observedAt)!==$observedAt){throw new \InvalidArgumentException('Intelligence feedback instant is invalid.');}PanelOperationsGuard::name($keyId,'intelligence feedback key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Intelligence feedback signature is invalid.');}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param array<string,mixed> $evidence */
	public static function sign(string $id,PanelIntelligenceProposal $proposal,string $outcome,int $effectivenessBasisPoints,array $evidence,string|int $reporterId,string|int|\DateTimeInterface $observedAt,string $keyId,string $key):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Intelligence feedback signing keys require at least 32 bytes.');}$outcome=strtolower(trim($outcome));$evidence=PanelOperationsGuard::safeMetadata($evidence,256);$evidenceDigest=PanelOperationsGuard::digest($evidence);$reporterHash=hash('sha256',PanelOperationsGuard::identifier($reporterId,'intelligence feedback reporter'));$observedAt=PanelOperationsGuard::instant($observedAt);
		$prototype=new self($id,$proposal->id(),$proposal->signalId(),$proposal->tenantId(),$proposal->command(),$outcome,$effectivenessBasisPoints,$evidenceDigest,$evidence,$reporterHash,$observedAt,$keyId,str_repeat('0',64));
		return new self($id,$proposal->id(),$proposal->signalId(),$proposal->tenantId(),$proposal->command(),$outcome,$effectivenessBasisPoints,$evidenceDigest,$evidence,$reporterHash,$observedAt,$keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload @param array<string,mixed>|null $evidence */
	public static function hydrate(array $payload,?array $evidence=null):self {
		$required=['type','schema_version','api_version','version','id','proposal_id','signal_id','tenant_id','command','outcome','effectiveness_basis_points','evidence_digest','evidence_redacted','reporter_hash','observed_at','digest','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_intelligence_feedback'||($payload['version']??null)!==1||!is_string($payload['id']??null)||!is_string($payload['proposal_id']??null)||!is_string($payload['signal_id']??null)||!is_string($payload['tenant_id']??null)||!is_string($payload['command']??null)||!is_string($payload['outcome']??null)||!is_int($payload['effectiveness_basis_points']??null)||!is_string($payload['evidence_digest']??null)||($payload['evidence_redacted']??null)!==true||!is_string($payload['reporter_hash']??null)||!is_string($payload['observed_at']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored intelligence feedback shape is invalid.');}
		$self=new self($payload['id'],$payload['proposal_id'],$payload['signal_id'],$payload['tenant_id'],$payload['command'],$payload['outcome'],$payload['effectiveness_basis_points'],$payload['evidence_digest'],$evidence,$payload['reporter_hash'],$payload['observed_at'],$payload['key_id'],$payload['signature']);if(!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored intelligence feedback integrity check failed.');}return$self;
	}

	/** @param array<string,string> $keys */public function verify(array $keys):bool {$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function id():string{return$this->id;}public function proposalId():string{return$this->proposalId;}public function signalId():string{return$this->signalId;}public function tenantId():string{return$this->tenantId;}public function command():string{return$this->command;}public function outcome():string{return$this->outcome;}public function effectivenessBasisPoints():int{return$this->effectivenessBasisPoints;}
	/** @return array<string,mixed> */public function evidence():array{return$this->evidence;}public function evidenceDigest():string{return$this->evidenceDigest;}public function hasEvidencePayload():bool{return hash_equals($this->evidenceDigest,PanelOperationsGuard::digest($this->evidence));}
	public function observedAt():string{return$this->observedAt;}public function digest():string{return$this->digest;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_intelligence_feedback','version'=>1]+$this->unsigned()+['evidence_redacted'=>true,'digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['id'=>$this->id,'proposal_id'=>$this->proposalId,'signal_id'=>$this->signalId,'tenant_id'=>$this->tenantId,'command'=>$this->command,'outcome'=>$this->outcome,'effectiveness_basis_points'=>$this->effectivenessBasisPoints,'evidence_digest'=>$this->evidenceDigest,'reporter_hash'=>$this->reporterHash,'observed_at'=>$this->observedAt];}
}
