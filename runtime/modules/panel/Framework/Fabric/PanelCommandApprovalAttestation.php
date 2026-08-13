<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Signed, privacy-bounded proof that independent approvers authorized one
 * immutable command execution target.
 */
final class PanelCommandApprovalAttestation implements \JsonSerializable {
	/** @var list<string> */
	private readonly array $approverHashes;
	private readonly string $digest;

	/** @param list<string> $approverHashes */
	private function __construct(
		private readonly string $targetDigest,
		private readonly string $source,
		private readonly string $subjectId,
		array $approverHashes,
		private readonly string $issuedAt,
		private readonly string $expiresAt,
		private readonly string $keyId,
		private readonly string $signature,
	){
		if(preg_match('/^[a-f0-9]{64}$/D',$targetDigest)!==1){throw new \InvalidArgumentException('Command approval target digest is invalid.');}
		PanelOperationsGuard::name($source,'command approval source');
		PanelOperationsGuard::identifier($subjectId,'command approval subject',190);
		$hashes=[];
		foreach($approverHashes as $hash){
			if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException('Command approval approver hash is invalid.');}
			$hashes[$hash]=true;
		}
		if(count($hashes)>32){throw new \LengthException('Command approval attestation exceeds its approver limit.');}
		$normalized=array_keys($hashes);sort($normalized,SORT_STRING);$this->approverHashes=$normalized;
		if(PanelOperationsGuard::instant($issuedAt)!==$issuedAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||$expiresAt<=$issuedAt){throw new \InvalidArgumentException('Command approval validity window is invalid.');}
		PanelOperationsGuard::name($keyId,'command approval key id');
		if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Command approval signature is invalid.');}
		$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param list<string|int> $approverIds */
	public static function sign(
		string $targetDigest,
		string $source,
		string $subjectId,
		array $approverIds,
		string|int|\DateTimeInterface $issuedAt,
		string|int|\DateTimeInterface $expiresAt,
		string $keyId,
		string $key,
	):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Command approval signing keys require at least 32 bytes.');}
		$hashes=[];
		foreach(PanelOperationsGuard::identifiers($approverIds,'command approver id',128,32) as $approver){$hashes[]=hash('sha256',$approver);}
		$issuedAt=PanelOperationsGuard::instant($issuedAt);$expiresAt=PanelOperationsGuard::instant($expiresAt);
		$prototype=new self($targetDigest,$source,$subjectId,$hashes,$issuedAt,$expiresAt,$keyId,str_repeat('0',64));
		return new self($targetDigest,$source,$subjectId,$hashes,$issuedAt,$expiresAt,$keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self {
		$required=['type','schema_version','api_version','version','target_digest','source','subject_id','approver_hashes','approved_count','issued_at','expires_at','digest','key_id','signature'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_command_approval_attestation'||($payload['version']??null)!==1||!is_string($payload['target_digest']??null)||!is_string($payload['source']??null)||!is_string($payload['subject_id']??null)||!is_array($payload['approver_hashes']??null)||!is_int($payload['approved_count']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){
			throw new \UnexpectedValueException('Stored command approval attestation shape is invalid.');
		}
		$self=new self($payload['target_digest'],$payload['source'],$payload['subject_id'],$payload['approver_hashes'],$payload['issued_at'],$payload['expires_at'],$payload['key_id'],$payload['signature']);
		if($payload['approved_count']!==$self->approvedCount()||!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored command approval attestation integrity check failed.');}
		return $self;
	}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string $targetDigest,string|int|\DateTimeInterface $now,?string $source=null):bool {
		$key=$keys[$this->keyId]??null;$now=PanelOperationsGuard::instant($now);
		return hash_equals($this->targetDigest,$targetDigest)
			&&($source===null||hash_equals($this->source,PanelOperationsGuard::name($source,'command approval source')))
			&&$now>=$this->issuedAt&&$now<$this->expiresAt
			&&is_string($key)&&strlen($key)>=32
			&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));
	}

	public function targetDigest():string{return $this->targetDigest;}
	public function source():string{return $this->source;}
	public function subjectId():string{return $this->subjectId;}
	/** @return list<string> */public function approverHashes():array{return $this->approverHashes;}
	public function approvedCount():int{return count($this->approverHashes);}
	public function issuedAt():string{return $this->issuedAt;}
	public function expiresAt():string{return $this->expiresAt;}
	public function keyId():string{return $this->keyId;}
	public function digest():string{return $this->digest;}
	public function includesActor(string|int $actorId):bool{return in_array(hash('sha256',PanelOperationsGuard::identifier($actorId,'command actor id')),$this->approverHashes,true);}

	public function jsonSerialize():array {
		return PanelManifestContract::stamp(['type'=>'panel_command_approval_attestation','version'=>1]+$this->unsigned()+['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);
	}

	/** @return array<string,mixed> */
	private function unsigned():array {
		return['target_digest'=>$this->targetDigest,'source'=>$this->source,'subject_id'=>$this->subjectId,'approver_hashes'=>$this->approverHashes,'approved_count'=>$this->approvedCount(),'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt];
	}
}
