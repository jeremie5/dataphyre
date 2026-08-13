<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Server-attested browser-device authority with an independently held P-256 key. */
final class PanelLocalFirstDeviceCredential implements \JsonSerializable {
	private readonly string $credentialId;private readonly string $publicKeyFingerprint;
	/** @param list<string> $sources @param list<string> $abilities */
	private function __construct(private readonly string $actorId,private readonly ?string $tenantId,private readonly string $deviceId,private readonly string $publicKey,array $sources,array $abilities,private readonly string $issuedAt,private readonly string $expiresAt,private readonly string $keyId,private readonly string $signature){
		PanelOperationsGuard::identifier($actorId,'local-first actor');if($tenantId!==null){PanelOperationsGuard::identifier($tenantId,'local-first tenant');}PanelOperationsGuard::identifier($deviceId,'local-first device');$key=PanelLocalFirstCrypto::p256PublicKey($publicKey);$this->publicKeyFingerprint=$key['fingerprint'];
		$this->sources=PanelOperationsGuard::names($sources,'local-first source',96,128);$this->abilities=PanelOperationsGuard::names($abilities,'local-first ability',96,32);if($this->abilities===[]){throw new \InvalidArgumentException('Local-first credentials require at least one ability.');}
		if(PanelOperationsGuard::instant($issuedAt)!==$issuedAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||strtotime($expiresAt)<=strtotime($issuedAt)){throw new \InvalidArgumentException('Local-first credential validity is invalid.');}PanelOperationsGuard::name($keyId,'local-first credential key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Local-first credential signature is invalid.');}
		$this->credentialId='lfc_'.substr(PanelOperationsGuard::digest($this->unsigned()),0,48);
	}
	/** @var list<string> */private readonly array $sources;/** @var list<string> */private readonly array $abilities;

	/** @param list<string> $sources @param list<string> $abilities */
	public static function issue(string|int $actorId,string|int|null $tenantId,string $deviceId,string $publicKey,array $sources,array $abilities,string|int|\DateTimeInterface $issuedAt,string|int|\DateTimeInterface $expiresAt,string $keyId,string $key):self{
		if(strlen($key)<32){throw new \InvalidArgumentException('Local-first credential signing keys require at least 32 bytes.');}$actorId=PanelOperationsGuard::identifier($actorId,'local-first actor');$tenantId=$tenantId===null?null:PanelOperationsGuard::identifier($tenantId,'local-first tenant');$deviceId=PanelOperationsGuard::identifier($deviceId,'local-first device');$issuedAt=PanelOperationsGuard::instant($issuedAt);$expiresAt=PanelOperationsGuard::instant($expiresAt);$keyId=PanelOperationsGuard::name($keyId,'local-first credential key id');$sources=PanelOperationsGuard::names($sources,'local-first source',96,128);$abilities=PanelOperationsGuard::names($abilities,'local-first ability',96,32);$publicKeyFingerprint=PanelLocalFirstCrypto::p256PublicKey($publicKey)['fingerprint'];
		$unsigned=['actor_id'=>$actorId,'tenant_id'=>$tenantId,'device_id'=>$deviceId,'public_key'=>$publicKey,'public_key_fingerprint'=>$publicKeyFingerprint,'sources'=>$sources,'abilities'=>$abilities,'issued_at'=>$issuedAt,'expires_at'=>$expiresAt,'key_id'=>$keyId];$signature=hash_hmac('sha256',PanelOperationsGuard::digest($unsigned),$key);
		return new self($actorId,$tenantId,$deviceId,$publicKey,$sources,$abilities,$issuedAt,$expiresAt,$keyId,$signature);
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self{
		$expected=['type','schema_version','api_version','version','credential_id','actor_id','tenant_id','device_id','public_key','public_key_fingerprint','sources','abilities','issued_at','expires_at','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
		if($keys!==$expected||($payload['type']??null)!=='panel_local_first_device_credential'||($payload['schema_version']??null)!==1||($payload['api_version']??null)!==1||($payload['version']??null)!==1||!is_string($payload['credential_id']??null)||!is_string($payload['actor_id']??null)||(!is_string($payload['tenant_id']??null)&&($payload['tenant_id']??null)!==null)||!is_string($payload['device_id']??null)||!is_string($payload['public_key']??null)||!is_string($payload['public_key_fingerprint']??null)||!is_array($payload['sources']??null)||!array_is_list($payload['sources'])||!is_array($payload['abilities']??null)||!array_is_list($payload['abilities'])||!is_string($payload['issued_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored local-first credential shape is invalid.');}
		try{$credential=new self($payload['actor_id'],$payload['tenant_id'],$payload['device_id'],$payload['public_key'],$payload['sources'],$payload['abilities'],$payload['issued_at'],$payload['expires_at'],$payload['key_id'],$payload['signature']);}catch(\Throwable $error){throw new \UnexpectedValueException('Stored local-first credential is invalid.',0,$error);}
		if(!hash_equals($credential->credentialId(),$payload['credential_id'])||!hash_equals($credential->publicKeyFingerprint(),$payload['public_key_fingerprint'])){throw new \UnexpectedValueException('Stored local-first credential identity is invalid.');}return$credential;
	}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string|int|\DateTimeInterface $now):bool{$key=$keys[$this->keyId]??null;if(!is_string($key)||strlen($key)<32||strtotime(PanelOperationsGuard::instant($now))<strtotime($this->issuedAt)||strtotime(PanelOperationsGuard::instant($now))>=strtotime($this->expiresAt)){return false;}return hash_equals($this->signature,hash_hmac('sha256',PanelOperationsGuard::digest($this->unsigned()),$key));}
	public function credentialId():string{return$this->credentialId;}public function actorId():string{return$this->actorId;}public function tenantId():?string{return$this->tenantId;}public function deviceId():string{return$this->deviceId;}public function sourceActor():string{return$this->actorId.'@'.$this->deviceId;}public function publicKey():string{return$this->publicKey;}public function publicKeyFingerprint():string{return$this->publicKeyFingerprint;}public function expiresAt():string{return$this->expiresAt;}
	/** @return list<string> */public function sources():array{return$this->sources;}/** @return list<string> */public function abilities():array{return$this->abilities;}
	public function allowsSource(string $source):bool{return in_array(PanelOperationsGuard::name($source,'local-first source'),$this->sources,true);}public function allows(string $ability):bool{return in_array(PanelOperationsGuard::name($ability,'local-first ability'),$this->abilities,true);}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->unsigned()+['signature'=>$this->signature]);}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_device_credential','version'=>1,'credential_id'=>$this->credentialId]+$this->unsigned()+['signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['actor_id'=>$this->actorId,'tenant_id'=>$this->tenantId,'device_id'=>$this->deviceId,'public_key'=>$this->publicKey,'public_key_fingerprint'=>$this->publicKeyFingerprint,'sources'=>$this->sources,'abilities'=>$this->abilities,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'key_id'=>$this->keyId];}
}
