<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Independent, signed and expiring approval bound to one activation plan. */
final class PanelDomainActivationApproval implements \JsonSerializable {
	private readonly string $digest;
	private function __construct(private readonly string $planFingerprint,private readonly string $actorId,private readonly string $issuedAt,private readonly string $expiresAt,private readonly string $nonce,private readonly string $keyId,private readonly string $signature){
		if(preg_match('/^[a-f0-9]{64}$/D',$planFingerprint)!==1){throw new \InvalidArgumentException('Domain activation approval plan fingerprint is invalid.');}
		PanelOperationsGuard::identifier($actorId,'domain activation approver');if(strcmp($issuedAt,$expiresAt)>=0){throw new \InvalidArgumentException('Domain activation approval expiration must follow issuance.');}PanelOperationsGuard::identifier($nonce,'domain activation approval nonce');PanelOperationsGuard::name($keyId,'domain activation approval key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Domain activation approval signature is invalid.');}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}
	public static function sign(string $planFingerprint,string|int $actorId,string|int|\DateTimeInterface $issuedAt,string|int|\DateTimeInterface $expiresAt,string $nonce,string $keyId,string $key):self {if(strlen($key)<32){throw new \InvalidArgumentException('Domain activation approval keys require at least 32 bytes.');}$issued=PanelOperationsGuard::instant($issuedAt);$expires=PanelOperationsGuard::instant($expiresAt);$prototype=new self($planFingerprint,(string)$actorId,$issued,$expires,$nonce,$keyId,str_repeat('0',64));return new self($planFingerprint,(string)$actorId,$issued,$expires,$nonce,$keyId,hash_hmac('sha256',$prototype->digest,$key));}
	/** @param array<string,mixed> $payload */public static function hydrate(array $payload):self {$required=['type','schema_version','api_version','version','plan_fingerprint','actor_id','issued_at','expires_at','nonce','digest','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);if($keys!==$required||($payload['type']??null)!=='panel_domain_activation_approval'||($payload['version']??null)!==1||!is_string($payload['plan_fingerprint']??null)||!is_string($payload['actor_id']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['nonce']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored domain activation approval shape is invalid.');}$self=new self($payload['plan_fingerprint'],$payload['actor_id'],PanelOperationsGuard::instant($payload['issued_at']),PanelOperationsGuard::instant($payload['expires_at']),$payload['nonce'],$payload['key_id'],$payload['signature']);if(!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored domain activation approval digest is invalid.');}return$self;}
	/** @param array<string,string> $keys */public function verify(array $keys,string|int|\DateTimeInterface $at):bool {$key=$keys[$this->keyId]??null;$instant=PanelOperationsGuard::instant($at);return is_string($key)&&strlen($key)>=32&&strcmp($instant,$this->issuedAt)>=0&&strcmp($instant,$this->expiresAt)<0&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function planFingerprint():string{return$this->planFingerprint;}public function actorId():string{return$this->actorId;}public function digest():string{return$this->digest;}public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_domain_activation_approval','version'=>1]+$this->unsigned()+['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}/** @return array<string,mixed> */private function unsigned():array{return['plan_fingerprint'=>$this->planFingerprint,'actor_id'=>$this->actorId,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'nonce'=>$this->nonce];}
}
