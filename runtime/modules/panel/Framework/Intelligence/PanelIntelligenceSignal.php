<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed, expiring observation that can seed a governed command proposal. */
final class PanelIntelligenceSignal implements \JsonSerializable {
	public const KINDS=['anomaly','forecast','recommendation','conformance','threshold','drift','opportunity'];
	public const SEVERITIES=['info','low','medium','high','critical'];
	private readonly string $digest;
	private readonly string $evidenceDigest;
	/** @var array<string,mixed> */private readonly array $evidence;

	/** @param array<string,mixed>|null $evidence */
	private function __construct(
		private readonly string $id,
		private readonly string $kind,
		private readonly string $tenantId,
		private readonly string $source,
		private readonly string $subjectType,
		private readonly string $subjectId,
		private readonly string $summary,
		private readonly string $severity,
		private readonly int $confidenceBasisPoints,
		string $evidenceDigest,
		?array $evidence,
		private readonly string $observedAt,
		private readonly string $expiresAt,
		private readonly string $keyId,
		private readonly string $signature,
	){
		PanelOperationsGuard::identifier($id,'intelligence signal id',190);
		if(!in_array($kind,self::KINDS,true)){throw new \InvalidArgumentException('Intelligence signal kind is invalid.');}
		PanelOperationsGuard::identifier($tenantId,'intelligence signal tenant');
		PanelOperationsGuard::name($source,'intelligence signal source');
		PanelOperationsGuard::name($subjectType,'intelligence signal subject type');
		PanelOperationsGuard::identifier($subjectId,'intelligence signal subject id');
		PanelOperationsGuard::label($summary,'intelligence signal summary',1024);
		if(!in_array($severity,self::SEVERITIES,true)||$confidenceBasisPoints<0||$confidenceBasisPoints>10000){throw new \InvalidArgumentException('Intelligence signal severity or confidence is invalid.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$evidenceDigest)!==1){throw new \InvalidArgumentException('Intelligence signal evidence digest is invalid.');}
		$this->evidenceDigest=$evidenceDigest;
		$this->evidence=$evidence===null?[]:PanelOperationsGuard::safeMetadata($evidence,512);
		if($evidence!==null&&!hash_equals($evidenceDigest,PanelOperationsGuard::digest($this->evidence))){throw new \UnexpectedValueException('Intelligence signal evidence does not match its digest.');}
		if(PanelOperationsGuard::instant($observedAt)!==$observedAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||$expiresAt<=$observedAt){throw new \InvalidArgumentException('Intelligence signal validity window is invalid.');}
		PanelOperationsGuard::name($keyId,'intelligence signal key id');
		if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Intelligence signal signature is invalid.');}
		$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param array<string,mixed> $evidence */
	public static function sign(
		string $kind,string $tenantId,string $source,string $subjectType,string|int $subjectId,string $summary,
		string $severity,int $confidenceBasisPoints,array $evidence,string|int|\DateTimeInterface $observedAt,
		string|int|\DateTimeInterface $expiresAt,string $keyId,string $key,
	):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Intelligence signal signing keys require at least 32 bytes.');}
		$kind=strtolower(trim($kind));$severity=strtolower(trim($severity));
		$tenantId=PanelOperationsGuard::identifier($tenantId,'intelligence signal tenant');
		$source=PanelOperationsGuard::name($source,'intelligence signal source');
		$subjectType=PanelOperationsGuard::name($subjectType,'intelligence signal subject type');
		$subjectId=PanelOperationsGuard::identifier($subjectId,'intelligence signal subject id');
		$summary=PanelOperationsGuard::label($summary,'intelligence signal summary',1024);
		$evidence=PanelOperationsGuard::safeMetadata($evidence,512);$evidenceDigest=PanelOperationsGuard::digest($evidence);
		$observedAt=PanelOperationsGuard::instant($observedAt);$expiresAt=PanelOperationsGuard::instant($expiresAt);$keyId=PanelOperationsGuard::name($keyId,'intelligence signal key id');
		$identity=PanelOperationsGuard::digest(compact('kind','tenantId','source','subjectType','subjectId','summary','severity','confidenceBasisPoints','evidenceDigest','observedAt','expiresAt','keyId'));
		$id='signal_'.substr($identity,0,40);
		$prototype=new self($id,$kind,$tenantId,$source,$subjectType,$subjectId,$summary,$severity,$confidenceBasisPoints,$evidenceDigest,$evidence,$observedAt,$expiresAt,$keyId,str_repeat('0',64));
		return new self($id,$kind,$tenantId,$source,$subjectType,$subjectId,$summary,$severity,$confidenceBasisPoints,$evidenceDigest,$evidence,$observedAt,$expiresAt,$keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload @param array<string,mixed>|null $evidence */
	public static function hydrate(array $payload,?array $evidence=null):self {
		$required=['type','schema_version','api_version','version','id','kind','tenant_id','source','subject_type','subject_id','summary','severity','confidence_basis_points','evidence_digest','evidence_redacted','observed_at','expires_at','digest','key_id','signature'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_intelligence_signal'||($payload['version']??null)!==1||!is_string($payload['id']??null)||!is_string($payload['kind']??null)||!is_string($payload['tenant_id']??null)||!is_string($payload['source']??null)||!is_string($payload['subject_type']??null)||!is_string($payload['subject_id']??null)||!is_string($payload['summary']??null)||!is_string($payload['severity']??null)||!is_int($payload['confidence_basis_points']??null)||!is_string($payload['evidence_digest']??null)||($payload['evidence_redacted']??null)!==true||!is_string($payload['observed_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){
			throw new \UnexpectedValueException('Stored intelligence signal shape is invalid.');
		}
		$self=new self($payload['id'],$payload['kind'],$payload['tenant_id'],$payload['source'],$payload['subject_type'],$payload['subject_id'],$payload['summary'],$payload['severity'],$payload['confidence_basis_points'],$payload['evidence_digest'],$evidence,$payload['observed_at'],$payload['expires_at'],$payload['key_id'],$payload['signature']);
		if(!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored intelligence signal integrity check failed.');}
		return$self;
	}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string|int|\DateTimeInterface $now,int $futureSkewSeconds=300):bool {
		$key=$keys[$this->keyId]??null;$nowEpoch=(new \DateTimeImmutable(PanelOperationsGuard::instant($now)))->getTimestamp();$observed=(new \DateTimeImmutable($this->observedAt))->getTimestamp();$expires=(new \DateTimeImmutable($this->expiresAt))->getTimestamp();
		return $futureSkewSeconds>=0&&$futureSkewSeconds<=3600&&$observed<=$nowEpoch+$futureSkewSeconds&&$nowEpoch<$expires&&is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));
	}

	/** Cryptographic and structural verification that remains valid after expiry. @param array<string,string> $keys */
	public function verifyStored(array $keys):bool {$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function id():string{return$this->id;}public function kind():string{return$this->kind;}public function tenantId():string{return$this->tenantId;}public function source():string{return$this->source;}public function subjectType():string{return$this->subjectType;}public function subjectId():string{return$this->subjectId;}public function summary():string{return$this->summary;}public function severity():string{return$this->severity;}public function confidenceBasisPoints():int{return$this->confidenceBasisPoints;}
	/** @return array<string,mixed> */public function evidence():array{return$this->evidence;}public function evidenceDigest():string{return$this->evidenceDigest;}public function hasEvidencePayload():bool{return hash_equals($this->evidenceDigest,PanelOperationsGuard::digest($this->evidence));}
	public function observedAt():string{return$this->observedAt;}public function expiresAt():string{return$this->expiresAt;}public function digest():string{return$this->digest;}public function keyId():string{return$this->keyId;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_intelligence_signal','version'=>1]+$this->unsigned()+['evidence_redacted'=>true,'digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['id'=>$this->id,'kind'=>$this->kind,'tenant_id'=>$this->tenantId,'source'=>$this->source,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'summary'=>$this->summary,'severity'=>$this->severity,'confidence_basis_points'=>$this->confidenceBasisPoints,'evidence_digest'=>$this->evidenceDigest,'observed_at'=>$this->observedAt,'expires_at'=>$this->expiresAt];}
}
