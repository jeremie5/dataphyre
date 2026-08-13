<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Rotatable HMAC authentication for remote mutation requests and responses. */
final class PanelHttpDataMutationAuthenticator implements \JsonSerializable {
	/** @var array<string,string> */private readonly array $keys;
	public function __construct(array $keys,private readonly string $activeKeyId,private readonly int $version=1){
		if($version<1||$version>1000000||$keys===[]||array_is_list($keys)||count($keys)>8){throw new \InvalidArgumentException('Remote mutation authentication key ring is invalid.');}
		$normalized=[];foreach($keys as$id=>$secret){$id=PanelHttpDataSourceValue::identifier((string)$id,'Remote mutation key id',64);if(!is_string($secret)||strlen($secret)<32||strlen($secret)>4096){throw new \InvalidArgumentException('Remote mutation signing keys must contain 32-4096 bytes.');}$normalized[$id]=$secret;}
		if(!isset($normalized[$activeKeyId])){throw new \InvalidArgumentException('Remote mutation active key id is not retained.');}$this->keys=$normalized;
	}
	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function seal(array $payload):array{if(isset($payload['key_id'])||isset($payload['signature'])){throw new \InvalidArgumentException('Remote mutation payload is already sealed.');}$payload['key_id']=$this->activeKeyId;$payload['signature']=$this->signature($payload,$this->activeKeyId);return$payload;}
	/** @param array<string,mixed> $payload */
	public function verify(array $payload):void{
		$keyId=$payload['key_id']??null;$signature=$payload['signature']??null;if(!is_string($keyId)||!is_string($signature)||!isset($this->keys[$keyId])||preg_match('/^[A-Za-z0-9_-]{43}$/D',$signature)!==1){throw new \UnexpectedValueException('Remote mutation signature is invalid.');}
		$expected=$this->signature($payload,$keyId);if(!hash_equals($expected,$signature)){throw new \UnexpectedValueException('Remote mutation signature does not verify.');}
	}
	public function bindingFingerprint(string $value):string{return hash_hmac('sha256',"panel-http-data-mutation-binding-v1\0".$value,$this->keys[$this->activeKeyId]);}
	/** @return array<string,mixed> */public function jsonSerialize():array{$ids=array_keys($this->keys);sort($ids,SORT_STRING);return['type'=>'panel_http_data_mutation_authenticator','version'=>$this->version,'active_key_id'=>$this->activeKeyId,'retained_key_ids'=>$ids,'retained_keys'=>count($ids),'algorithm'=>'hmac-sha256','domain_separated'=>true,'secrets_serialized'=>false];}
	/** @param array<string,mixed> $payload */private function signature(array $payload,string $keyId):string{$copy=$payload;unset($copy['signature']);$binary=hash_hmac('sha256',"panel-http-data-mutation-envelope-v1\0".$keyId."\0".PanelHttpDataSourceValue::canonical($copy),$this->keys[$keyId],true);return rtrim(strtr(base64_encode($binary),'+/','-_'),'=');}
}
