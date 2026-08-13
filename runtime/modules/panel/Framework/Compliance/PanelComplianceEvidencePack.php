<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed point-in-time compliance evidence export. */
final class PanelComplianceEvidencePack implements \JsonSerializable {
	private readonly string $digest;
	/** @param array<string,mixed> $payload */private function __construct(private readonly array $payload,private readonly string $keyId,private readonly string $signature){PanelOperationsGuard::name($keyId,'compliance key id');if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Compliance evidence signature is invalid.');}$this->digest=PanelOperationsGuard::digest($payload);}
	/** @param array<string,mixed> $payload */public static function sign(array $payload,string $keyId,string $key):self {if(strlen($key)<32){throw new \InvalidArgumentException('Compliance signing keys require at least 32 bytes.');}$payload=PanelOperationsGuard::canonical($payload);$digest=PanelOperationsGuard::digest($payload);return new self($payload,PanelOperationsGuard::name($keyId,'compliance key id'),hash_hmac('sha256',$digest,$key));}
	/** @param array<string,string> $keys */public function verify(array $keys):bool {$key=$keys[$this->keyId]??null;return is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}public function digest():string{return$this->digest;}/** @return array<string,mixed> */public function payload():array{return$this->payload;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_compliance_evidence_pack_manifest','version'=>1,'payload'=>$this->payload,'digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
}
