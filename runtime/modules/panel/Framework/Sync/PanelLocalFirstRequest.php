<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Device-signed, sequence-bound browser request for mutations or CRDT synchronization. */
final class PanelLocalFirstRequest implements \JsonSerializable {
	public const KINDS=['mutation_batch','document_sync'];private readonly string $digest;/** @var array<string,mixed> */private readonly array $payload;
	/** @param array<string,mixed> $payload */
	private function __construct(private readonly PanelLocalFirstDeviceCredential $credential,private readonly int $sequence,private readonly string $issuedAt,private readonly string $nonce,private readonly string $kind,array $payload,private readonly string $signature){
		if($sequence<1){throw new \InvalidArgumentException('Local-first request sequence must be positive.');}if(PanelOperationsGuard::instant($issuedAt)!==$issuedAt){throw new \InvalidArgumentException('Local-first request instant is invalid.');}if(strlen($nonce)<16||strlen($nonce)>128||preg_match('/^[A-Za-z0-9_-]+$/D',$nonce)!==1){throw new \InvalidArgumentException('Local-first request nonce is invalid.');}if(!in_array($kind,self::KINDS,true)){throw new \InvalidArgumentException('Local-first request kind is invalid.');}
		if($payload!==[]&&array_is_list($payload)){throw new \InvalidArgumentException('Local-first request payload must be object-like.');}$payload=PanelOperationsGuard::canonical($payload);if(strlen(PanelOperationsGuard::json($payload))>2097152){throw new \LengthException('Local-first request payload exceeds 2 MiB.');}$this->payload=$payload;
		PanelLocalFirstCanonical::value($payload);if(strlen(PanelLocalFirstCrypto::base64UrlDecode($signature,'local-first device signature',64))!==64){throw new \InvalidArgumentException('Local-first request signature must be a P-256 signature.');}$this->digest=PanelLocalFirstCanonical::digest($this->unsigned());
	}
	/** @param array<string,mixed> $payload */
	public static function make(PanelLocalFirstDeviceCredential $credential,int $sequence,string|int|\DateTimeInterface $issuedAt,string $nonce,string $kind,array $payload,string $signature):self{return new self($credential,$sequence,PanelOperationsGuard::instant($issuedAt),$nonce,$kind,$payload,$signature);}
	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self{
		$expected=['type','schema_version','api_version','version','credential','credential_id','credential_fingerprint','sequence','issued_at','nonce','kind','payload','digest','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
		if($keys!==$expected||($payload['type']??null)!=='panel_local_first_request'||($payload['schema_version']??null)!==1||($payload['api_version']??null)!==1||($payload['version']??null)!==1||!is_array($payload['credential']??null)||!is_string($payload['credential_id']??null)||!is_string($payload['credential_fingerprint']??null)||!is_int($payload['sequence']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['nonce']??null)||!is_string($payload['kind']??null)||!is_array($payload['payload']??null)||!is_string($payload['digest']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored local-first request shape is invalid.');}
		try{$credential=PanelLocalFirstDeviceCredential::hydrate($payload['credential']);$request=new self($credential,$payload['sequence'],$payload['issued_at'],$payload['nonce'],$payload['kind'],$payload['payload'],$payload['signature']);}catch(\Throwable $error){throw new \UnexpectedValueException('Stored local-first request is invalid.',0,$error);}if(!hash_equals($credential->credentialId(),$payload['credential_id'])||!hash_equals($credential->fingerprint(),$payload['credential_fingerprint'])||!hash_equals($request->digest(),$payload['digest'])){throw new \UnexpectedValueException('Stored local-first request digest is invalid.');}return$request;
	}
	public function verifyDeviceSignature():bool{return PanelLocalFirstCrypto::verifyP256($this->digest,$this->signature,$this->credential->publicKey());}
	public function credential():PanelLocalFirstDeviceCredential{return$this->credential;}public function sequence():int{return$this->sequence;}public function issuedAt():string{return$this->issuedAt;}public function kind():string{return$this->kind;}public function digest():string{return$this->digest;}/** @return array<string,mixed> */public function payload():array{return$this->payload;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_request','version'=>1,'credential'=>$this->credential->jsonSerialize()]+$this->unsigned()+['digest'=>$this->digest,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['credential_id'=>$this->credential->credentialId(),'credential_fingerprint'=>$this->credential->fingerprint(),'sequence'=>$this->sequence,'issued_at'=>$this->issuedAt,'nonce'=>$this->nonce,'kind'=>$this->kind,'payload'=>$this->payload];}
	/** @param array<string,mixed> $payload */public static function signingDigest(PanelLocalFirstDeviceCredential $credential,int $sequence,string|int|\DateTimeInterface $issuedAt,string $nonce,string $kind,array $payload):string{$placeholder=PanelLocalFirstCrypto::base64UrlEncode(str_repeat("\0",64));return(new self($credential,$sequence,PanelOperationsGuard::instant($issuedAt),$nonce,$kind,$payload,$placeholder))->digest();}
}
