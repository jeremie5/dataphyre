<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fenced ownership or exact completed replay for one device request sequence. */
final class PanelLocalFirstReplayClaim implements \JsonSerializable {
	private function __construct(private readonly string $credentialId,private readonly int $sequence,private readonly string $requestDigest,private readonly ?string $leaseToken,private readonly ?string $leaseExpiresAt,private readonly ?PanelLocalFirstResponse $response){if(preg_match('/^lfc_[a-f0-9]{48}$/D',$credentialId)!==1||$sequence<1||preg_match('/^[a-f0-9]{64}$/D',$requestDigest)!==1){throw new \InvalidArgumentException('Local-first replay claim identity is invalid.');}if(($response===null)!==($leaseToken!==null)||($response===null)!==($leaseExpiresAt!==null)){throw new \InvalidArgumentException('Local-first replay claim state is invalid.');}if($leaseToken!==null&&(preg_match('/^[a-f0-9]{64}$/D',$leaseToken)!==1||PanelOperationsGuard::instant((string)$leaseExpiresAt)!==$leaseExpiresAt)){throw new \InvalidArgumentException('Local-first replay claim lease is invalid.');}if($response!==null&&($response->sequence()!==$sequence||!hash_equals($response->requestDigest(),$requestDigest))){throw new \InvalidArgumentException('Local-first replay response does not match its claim.');}}
	public static function acquired(string $credentialId,int $sequence,string $requestDigest,string $leaseToken,string $leaseExpiresAt):self{return new self($credentialId,$sequence,$requestDigest,$leaseToken,$leaseExpiresAt,null);}public static function replay(string $credentialId,int $sequence,string $requestDigest,PanelLocalFirstResponse $response):self{return new self($credentialId,$sequence,$requestDigest,null,null,$response);}
	public function credentialId():string{return$this->credentialId;}public function sequence():int{return$this->sequence;}public function requestDigest():string{return$this->requestDigest;}public function acquiredLease():bool{return$this->response===null;}public function leaseToken():?string{return$this->leaseToken;}public function leaseExpiresAt():?string{return$this->leaseExpiresAt;}public function response():?PanelLocalFirstResponse{return$this->response;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_replay_claim_manifest','version'=>1,'credential_hash'=>hash('sha256',$this->credentialId),'sequence'=>$this->sequence,'request_digest'=>$this->requestDigest,'state'=>$this->acquiredLease()?'acquired':'replay','lease_expires_at'=>$this->leaseExpiresAt,'response_digest'=>$this->response?->digest(),'lease_token_serialized'=>false]);}
}
