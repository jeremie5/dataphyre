<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed anti-replay capability and state attestation for one Panel installation. */
final class PanelFederationNode implements \JsonSerializable {
	private readonly string $digest;
	private readonly string $metadataDigest;
	/** @var array<string,mixed> */ private readonly array $metadata;

	/** @param list<string> $capabilities @param array<string,string> $stateDigests @param array<string,mixed>|null $metadata */
	private function __construct(
		private readonly string $id,
		private readonly string $environment,
		private readonly string $region,
		private readonly int $sequence,
		private readonly string $issuedAt,
		private readonly string $expiresAt,
		private readonly array $capabilities,
		private readonly array $stateDigests,
		string $metadataDigest,
		?array $metadata,
		private readonly string $keyId,
		private readonly string $signature,
	) {
		PanelOperationsGuard::name($id,'federation node id');PanelOperationsGuard::name($environment,'federation environment');PanelOperationsGuard::name($region,'federation region');
		if($sequence<1||PanelOperationsGuard::instant($issuedAt)!==$issuedAt||PanelOperationsGuard::instant($expiresAt)!==$expiresAt||strcmp($expiresAt,$issuedAt)<=0){throw new \InvalidArgumentException('Federation node sequence or validity window is invalid.');}
		PanelOperationsGuard::names($capabilities,'federation capability');foreach($stateDigests as$name=>$digest){PanelOperationsGuard::name((string)$name,'federation state name');if(!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Federation state digest is invalid.');}}
		if(preg_match('/^[a-f0-9]{64}$/D',$metadataDigest)!==1){throw new \InvalidArgumentException('Federation metadata digest is invalid.');}$this->metadataDigest=$metadataDigest;$this->metadata=$metadata===null?[]:PanelOperationsGuard::safeMetadata($metadata,256);if($metadata!==null&&!hash_equals($metadataDigest,PanelOperationsGuard::digest($this->metadata))){throw new \UnexpectedValueException('Federation node metadata does not match its digest.');}
		PanelOperationsGuard::name($keyId,'federation key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Federation node signature is invalid.');}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param list<string> $capabilities @param array<string,string> $stateDigests @param array<string,mixed> $metadata */
	public static function sign(string $id,string $environment,string $region,int $sequence,string|int|\DateTimeInterface $issuedAt,string|int|\DateTimeInterface $expiresAt,array $capabilities,array $stateDigests,array $metadata,string $keyId,string $key):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Federation signing keys require at least 32 bytes.');}$metadata=PanelOperationsGuard::safeMetadata($metadata,256);$metadataDigest=PanelOperationsGuard::digest($metadata);
		$prototype=new self(PanelOperationsGuard::name($id,'federation node id'),PanelOperationsGuard::name($environment,'federation environment'),PanelOperationsGuard::name($region,'federation region'),$sequence,PanelOperationsGuard::instant($issuedAt),PanelOperationsGuard::instant($expiresAt),PanelOperationsGuard::names($capabilities,'federation capability'),PanelOperationsGuard::canonical($stateDigests),$metadataDigest,$metadata,PanelOperationsGuard::name($keyId,'federation key id'),str_repeat('0',64));
		return new self($prototype->id,$prototype->environment,$prototype->region,$prototype->sequence,$prototype->issuedAt,$prototype->expiresAt,$prototype->capabilities,$prototype->stateDigests,$metadataDigest,$metadata,$prototype->keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload @param array<string,mixed>|null $metadata */
	public static function hydrate(array $payload,?array $metadata=null):self {
		$expected=['type','schema_version','api_version','version','id','environment','region','sequence','issued_at','expires_at','capabilities','state_digests','metadata_digest','metadata_redacted','key_id','digest','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
		if($keys!==$expected||($payload['type']??null)!=='panel_federation_node_manifest'||($payload['schema_version']??null)!==PanelManifestContract::SCHEMA_VERSION||($payload['api_version']??null)!==PanelManifestContract::API_VERSION||($payload['version']??null)!==1||!is_string($payload['id']??null)||!is_string($payload['environment']??null)||!is_string($payload['region']??null)||!is_int($payload['sequence']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['expires_at']??null)||!is_array($payload['capabilities']??null)||!is_array($payload['state_digests']??null)||!is_string($payload['metadata_digest']??null)||($payload['metadata_redacted']??null)!==true||!is_string($payload['key_id']??null)||!is_string($payload['digest']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored federation node shape is invalid.');}
		$node=new self($payload['id'],$payload['environment'],$payload['region'],$payload['sequence'],$payload['issued_at'],$payload['expires_at'],$payload['capabilities'],$payload['state_digests'],$payload['metadata_digest'],$metadata,$payload['key_id'],$payload['signature']);if(!hash_equals($node->digest(),$payload['digest'])){throw new \UnexpectedValueException('Stored federation node digest is invalid.');}return$node;
	}

	public function id():string{return$this->id;}public function environment():string{return$this->environment;}public function region():string{return$this->region;}public function sequence():int{return$this->sequence;}public function issuedAt():string{return$this->issuedAt;}public function expiresAt():string{return$this->expiresAt;}public function digest():string{return$this->digest;}public function keyId():string{return$this->keyId;}
	/** @return list<string> */public function capabilities():array{return$this->capabilities;}/** @return array<string,string> */public function stateDigests():array{return$this->stateDigests;}/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}public function metadataDigest():string{return$this->metadataDigest;}public function hasMetadataPayload():bool{return hash_equals($this->metadataDigest,PanelOperationsGuard::digest($this->metadata));}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string|int|\DateTimeInterface $at):bool {$key=$keys[$this->keyId]??null;$instant=PanelOperationsGuard::instant($at);return is_string($key)&&strlen($key)>=32&&strcmp($instant,$this->issuedAt)>=0&&strcmp($instant,$this->expiresAt)<0&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	/** @param array<string,string> $keys */public function verifyStored(array $keys):bool {$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}

	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_federation_node_manifest','version'=>1]+$this->unsigned()+['metadata_redacted'=>true,'digest'=>$this->digest,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['id'=>$this->id,'environment'=>$this->environment,'region'=>$this->region,'sequence'=>$this->sequence,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'capabilities'=>$this->capabilities,'state_digests'=>$this->stateDigests,'metadata_digest'=>$this->metadataDigest,'key_id'=>$this->keyId];}
}
