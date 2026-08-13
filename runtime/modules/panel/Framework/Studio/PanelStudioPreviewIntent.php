<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Scope-bound preview bearer intent; serialization deliberately omits its token. */
final class PanelStudioPreviewIntent implements \JsonSerializable {
	/** @param array<string,mixed> $claims */
	public function __construct(private readonly string $token,private readonly array $claims){
		if(trim($token)===''){throw new \InvalidArgumentException('Studio preview tokens cannot be empty.');}foreach(['tenant_id','principal_id','document_id','revision','content_hash','audience','issued_at','expires_at','nonce','key_id']as$key){if(!array_key_exists($key,$claims)){throw new \InvalidArgumentException('Studio preview claims are incomplete.');}}
		PanelStudioDocument::scope(is_string($claims['tenant_id'])?$claims['tenant_id']:'','tenant');PanelStudioDocument::scope(is_string($claims['principal_id'])?$claims['principal_id']:'','principal');PanelStudioDocument::scope(is_string($claims['document_id'])?$claims['document_id']:'','document');
		if(!is_int($claims['revision'])||$claims['revision']<1||!is_string($claims['content_hash'])||preg_match('/^[a-f0-9]{64}$/',$claims['content_hash'])!==1||!is_int($claims['issued_at'])||!is_int($claims['expires_at'])||$claims['expires_at']<=$claims['issued_at']||!is_string($claims['nonce'])||preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$claims['nonce'])!==1||!is_string($claims['key_id'])||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/',$claims['key_id'])!==1){throw new \InvalidArgumentException('Studio preview claims are invalid.');}
		if(!is_string($claims['audience'])||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/',$claims['audience'])!==1){throw new \InvalidArgumentException('Studio preview audience claim is invalid.');}
	}
	public function token():string{return$this->token;}
	public function claims():array{return$this->claims;}
	public function expiresAt():int{return(int)$this->claims['expires_at'];}
	public function jsonSerialize():array{return['type'=>'panel_studio_preview_intent','version'=>1,'token_serialized'=>false,'token_digest'=>hash('sha256',$this->token),'scope'=>['tenant_id'=>$this->claims['tenant_id'],'principal_id'=>$this->claims['principal_id'],'document_id'=>$this->claims['document_id'],'revision'=>$this->claims['revision'],'content_hash'=>$this->claims['content_hash'],'audience'=>$this->claims['audience']],'issued_at'=>$this->claims['issued_at'],'expires_at'=>$this->claims['expires_at'],'key_id'=>$this->claims['key_id']];}
}
