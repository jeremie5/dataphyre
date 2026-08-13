<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed, expiring, audience-bound federation wire message with encrypted payload. */
final class PanelFederationMessage implements \JsonSerializable {
	public const KINDS=['heartbeat','desired_state','reconcile_request','acknowledgement'];
	private readonly string $digest;
	/** @var array<string,mixed>|null */private readonly ?array $sealed;

	/** @param array<string,mixed>|null $sealed */
	private function __construct(
		private readonly string $id,
		private readonly string $kind,
		private readonly string $sourceNode,
		private readonly string $targetNode,
		private readonly int $sequence,
		private readonly string $nonce,
		private readonly ?string $replyTo,
		private readonly string $issuedAt,
		private readonly string $expiresAt,
		private readonly string $payloadDigest,
		private readonly string $sealedDigest,
		private readonly string $keyId,
		private readonly string $signature,
		?array $sealed,
	) {
		PanelOperationsGuard::identifier($id,'federation message id',190);if(!in_array($kind,self::KINDS,true)){throw new \InvalidArgumentException('Federation message kind is invalid.');}PanelOperationsGuard::name($sourceNode,'federation source node');PanelOperationsGuard::name($targetNode,'federation target node');
		if($sequence<1||preg_match('/^[a-f0-9]{32}$/D',$nonce)!==1||($replyTo!==null&&trim($replyTo)==='')){throw new \InvalidArgumentException('Federation message sequence, nonce, or reply target is invalid.');}if($replyTo!==null){PanelOperationsGuard::identifier($replyTo,'federation reply target',190);}
		if(PanelOperationsGuard::instant($issuedAt)!==$issuedAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||strcmp($expiresAt,$issuedAt)<=0||preg_match('/^[a-f0-9]{64}$/D',$payloadDigest)!==1||preg_match('/^[a-f0-9]{64}$/D',$sealedDigest)!==1){throw new \InvalidArgumentException('Federation message validity window or payload digest is invalid.');}PanelOperationsGuard::name($keyId,'federation message key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Federation message signature is invalid.');}
		$this->sealed=$sealed===null?null:PanelOperationsGuard::safeMetadata($sealed,32);if($this->sealed!==null&&!hash_equals($sealedDigest,PanelOperationsGuard::digest($this->sealed))){throw new \UnexpectedValueException('Federation encrypted payload does not match its signed digest.');}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param array<string,mixed> $payload */
	public static function sign(string $kind,string $sourceNode,string $targetNode,int $sequence,array $payload,string|int|\DateTimeInterface $issuedAt,string|int|\DateTimeInterface $expiresAt,string $keyId,string $key,PanelCommandPayloadCodec $codec,?string $replyTo=null,?string $nonce=null):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Federation message signing keys require at least 32 bytes.');}$kind=strtolower(trim($kind));$sourceNode=PanelOperationsGuard::name($sourceNode,'federation source node');$targetNode=PanelOperationsGuard::name($targetNode,'federation target node');$payload=PanelOperationsGuard::safeMetadata($payload,1024);$payloadDigest=PanelOperationsGuard::digest($payload);$issuedAt=PanelOperationsGuard::instant($issuedAt);$expiresAt=PanelOperationsGuard::instant($expiresAt);$keyId=PanelOperationsGuard::name($keyId,'federation message key id');$nonce=$nonce??bin2hex(random_bytes(16));
		$identity=PanelOperationsGuard::digest(['kind'=>$kind,'source_node'=>$sourceNode,'target_node'=>$targetNode,'sequence'=>$sequence,'nonce'=>$nonce,'reply_to'=>$replyTo,'issued_at'=>$issuedAt,'expires_at'=>$expiresAt,'payload_digest'=>$payloadDigest,'key_id'=>$keyId]);$id='fmsg_'.substr($identity,0,40);
		$sealed=$codec->seal($payload,self::context($id,$payloadDigest));$sealedDigest=PanelOperationsGuard::digest($sealed);$prototype=new self($id,$kind,$sourceNode,$targetNode,$sequence,$nonce,$replyTo,$issuedAt,$expiresAt,$payloadDigest,$sealedDigest,$keyId,str_repeat('0',64),$sealed);
		return new self($id,$kind,$sourceNode,$targetNode,$sequence,$nonce,$replyTo,$issuedAt,$expiresAt,$payloadDigest,$sealedDigest,$keyId,hash_hmac('sha256',$prototype->digest,$key),$sealed);
	}

	/** @param array<string,mixed> $manifest @param array<string,mixed>|null $sealed */
	public static function hydrate(array $manifest,?array $sealed=null):self {
		$required=['type','schema_version','api_version','version','id','kind','source_node','target_node','sequence','nonce','reply_to','issued_at','expires_at','payload_digest','sealed_digest','payload_redacted','digest','key_id','signature'];$keys=array_keys($manifest);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($manifest['type']??null)!=='panel_federation_message'||($manifest['version']??null)!==1||!is_string($manifest['id']??null)||!is_string($manifest['kind']??null)||!is_string($manifest['source_node']??null)||!is_string($manifest['target_node']??null)||!is_int($manifest['sequence']??null)||!is_string($manifest['nonce']??null)||(!is_string($manifest['reply_to']??null)&&($manifest['reply_to']??null)!==null)||!is_string($manifest['issued_at']??null)||!is_string($manifest['expires_at']??null)||!is_string($manifest['payload_digest']??null)||!is_string($manifest['sealed_digest']??null)||($manifest['payload_redacted']??null)!==true||!is_string($manifest['digest']??null)||!is_string($manifest['key_id']??null)||!is_string($manifest['signature']??null)){throw new \UnexpectedValueException('Federation message manifest is invalid.');}
		$message=new self($manifest['id'],$manifest['kind'],$manifest['source_node'],$manifest['target_node'],$manifest['sequence'],$manifest['nonce'],$manifest['reply_to'],$manifest['issued_at'],$manifest['expires_at'],$manifest['payload_digest'],$manifest['sealed_digest'],$manifest['key_id'],$manifest['signature'],$sealed);if(!hash_equals($message->digest,$manifest['digest'])){throw new \UnexpectedValueException('Federation message digest is invalid.');}return$message;
	}

	/** @param array<string,mixed> $wire */
	public static function fromWire(array $wire):self {$keys=array_keys($wire);sort($keys,SORT_STRING);if($keys!==['manifest','sealed']||!is_array($wire['manifest'])||!is_array($wire['sealed'])){throw new \UnexpectedValueException('Federation wire envelope is invalid.');}return self::hydrate($wire['manifest'],$wire['sealed']);}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string|int|\DateTimeInterface $at,string $audience,int $futureSkewSeconds=300):bool {$key=$keys[$this->keyId]??null;$instant=PanelOperationsGuard::instant($at);$now=(new \DateTimeImmutable($instant))->getTimestamp();$issued=(new \DateTimeImmutable($this->issuedAt))->getTimestamp();$expires=(new \DateTimeImmutable($this->expiresAt))->getTimestamp();return $futureSkewSeconds>=0&&$futureSkewSeconds<=3600&&$this->targetNode===$audience&&$issued<=$now+$futureSkewSeconds&&$now<$expires&&is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	/** @return array<string,mixed> */public function open(PanelCommandPayloadCodec $codec):array {if($this->sealed===null){throw new \LogicException('Federation message payload is not available in this projection.');}$payload=$codec->open($this->sealed,self::context($this->id,$this->payloadDigest));if(!hash_equals($this->payloadDigest,PanelOperationsGuard::digest($payload))){throw new \UnexpectedValueException('Federation message payload digest is invalid.');}return$payload;}
	/** @return array{manifest:array<string,mixed>,sealed:array<string,mixed>} */public function wire():array {if($this->sealed===null){throw new \LogicException('Federation message wire payload is unavailable.');}return['manifest'=>$this->jsonSerialize(),'sealed'=>$this->sealed];}

	public function id():string{return$this->id;}public function kind():string{return$this->kind;}public function sourceNode():string{return$this->sourceNode;}public function targetNode():string{return$this->targetNode;}public function sequence():int{return$this->sequence;}public function nonce():string{return$this->nonce;}public function replyTo():?string{return$this->replyTo;}public function issuedAt():string{return$this->issuedAt;}public function expiresAt():string{return$this->expiresAt;}public function payloadDigest():string{return$this->payloadDigest;}public function sealedDigest():string{return$this->sealedDigest;}public function digest():string{return$this->digest;}public function hasPayload():bool{return$this->sealed!==null;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_federation_message','version'=>1]+$this->unsigned()+['payload_redacted'=>true,'digest'=>$this->digest,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['id'=>$this->id,'kind'=>$this->kind,'source_node'=>$this->sourceNode,'target_node'=>$this->targetNode,'sequence'=>$this->sequence,'nonce'=>$this->nonce,'reply_to'=>$this->replyTo,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'payload_digest'=>$this->payloadDigest,'sealed_digest'=>$this->sealedDigest,'key_id'=>$this->keyId];}
	private static function context(string $id,string $payloadDigest):string{return'federation.message.'.$id.'.'.substr($payloadDigest,0,16);}
}
