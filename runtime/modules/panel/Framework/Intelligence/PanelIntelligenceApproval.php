<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed, expiring approval bound to one immutable intelligence proposal. */
final class PanelIntelligenceApproval implements \JsonSerializable {
	private readonly string $digest;
	private readonly ?string $approverId;

	private function __construct(
		private readonly string $proposalId,private readonly string $proposalTarget,private readonly string $tenantId,
		private readonly string $approverHash,?string $approverId,private readonly string $occurredAt,
		private readonly string $expiresAt,private readonly string $keyId,private readonly string $signature,
	){
		PanelOperationsGuard::identifier($proposalId,'intelligence approval proposal id',190);if(preg_match('/^[a-f0-9]{64}$/D',$proposalTarget)!==1){throw new \InvalidArgumentException('Intelligence approval proposal target is invalid.');}PanelOperationsGuard::identifier($tenantId,'intelligence approval tenant');
		if(preg_match('/^[a-f0-9]{64}$/D',$approverHash)!==1){throw new \InvalidArgumentException('Intelligence approval approver hash is invalid.');}
		$this->approverId=$approverId===null?null:PanelOperationsGuard::identifier($approverId,'intelligence approver id');
		if($this->approverId!==null&&!hash_equals($approverHash,hash('sha256',$this->approverId))){throw new \UnexpectedValueException('Intelligence approval identity does not match its hash.');}
		if(PanelOperationsGuard::instant($occurredAt)!==$occurredAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||$expiresAt<=$occurredAt){throw new \InvalidArgumentException('Intelligence approval validity window is invalid.');}PanelOperationsGuard::name($keyId,'intelligence approval key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Intelligence approval signature is invalid.');}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	public static function sign(PanelIntelligenceProposal $proposal,string|int $approverId,string|int|\DateTimeInterface $occurredAt,string|int|\DateTimeInterface $expiresAt,string $keyId,string $key):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Intelligence approval signing keys require at least 32 bytes.');}$approverId=PanelOperationsGuard::identifier($approverId,'intelligence approver id');$approverHash=hash('sha256',$approverId);$occurredAt=PanelOperationsGuard::instant($occurredAt);$expiresAt=PanelOperationsGuard::instant($expiresAt);$prototype=new self($proposal->id(),$proposal->approvalTarget(),$proposal->tenantId(),$approverHash,$approverId,$occurredAt,$expiresAt,$keyId,str_repeat('0',64));return new self($proposal->id(),$proposal->approvalTarget(),$proposal->tenantId(),$approverHash,$approverId,$occurredAt,$expiresAt,$keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload,?string $approverId=null):self {
		$required=['type','schema_version','api_version','version','proposal_id','proposal_target','tenant_id','approver_hash','approver_identity_exposed','occurred_at','expires_at','digest','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_intelligence_approval'||($payload['version']??null)!==1||!is_string($payload['proposal_id']??null)||!is_string($payload['proposal_target']??null)||!is_string($payload['tenant_id']??null)||!is_string($payload['approver_hash']??null)||($payload['approver_identity_exposed']??null)!==false||!is_string($payload['occurred_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored intelligence approval shape is invalid.');}
		$self=new self($payload['proposal_id'],$payload['proposal_target'],$payload['tenant_id'],$payload['approver_hash'],$approverId,$payload['occurred_at'],$payload['expires_at'],$payload['key_id'],$payload['signature']);if(!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored intelligence approval integrity check failed.');}return$self;
	}

	/** @param array<string,string> $keys */public function verify(array $keys,PanelIntelligenceProposal $proposal,string|int|\DateTimeInterface $now):bool {$key=$keys[$this->keyId]??null;$now=PanelOperationsGuard::instant($now);return hash_equals($this->proposalId,$proposal->id())&&hash_equals($this->proposalTarget,$proposal->approvalTarget())&&hash_equals($this->tenantId,$proposal->tenantId())&&$now>=$this->occurredAt&&$now<$this->expiresAt&&is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	/** @param array<string,string> $keys */public function verifyStored(array $keys,PanelIntelligenceProposal $proposal):bool {$key=$keys[$this->keyId]??null;return hash_equals($this->proposalId,$proposal->id())&&hash_equals($this->proposalTarget,$proposal->approvalTarget())&&hash_equals($this->tenantId,$proposal->tenantId())&&is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function proposalId():string{return$this->proposalId;}public function approverId():string{if($this->approverId===null){throw new \LogicException('Intelligence approval identity is encrypted and unavailable in this projection.');}return$this->approverId;}public function hasApproverIdentity():bool{return$this->approverId!==null;}public function approverHash():string{return$this->approverHash;}public function occurredAt():string{return$this->occurredAt;}public function expiresAt():string{return$this->expiresAt;}public function digest():string{return$this->digest;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_intelligence_approval','version'=>1]+$this->unsigned()+['approver_identity_exposed'=>false,'digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['proposal_id'=>$this->proposalId,'proposal_target'=>$this->proposalTarget,'tenant_id'=>$this->tenantId,'approver_hash'=>$this->approverHash,'occurred_at'=>$this->occurredAt,'expires_at'=>$this->expiresAt];}
}
