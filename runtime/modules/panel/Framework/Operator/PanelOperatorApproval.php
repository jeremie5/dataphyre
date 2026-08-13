<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed approval bound to one immutable operator execution target. */
final class PanelOperatorApproval implements \JsonSerializable {
	private function __construct(private readonly string $targetDigest,private readonly string $approverId,private readonly string $occurredAt,private readonly string $keyId,private readonly string $signature){if(preg_match('/^[a-f0-9]{64}$/D',$targetDigest)!==1||preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Operator approval digest or signature is invalid.');}PanelOperationsGuard::identifier($approverId,'operator approver id');if(PanelOperationsGuard::instant($occurredAt)!==$occurredAt){throw new \InvalidArgumentException('Operator approval instant must be canonical UTC.');}PanelOperationsGuard::name($keyId,'operator approval key id');}
	public static function sign(string $targetDigest,string|int $approverId,string|int|\DateTimeInterface $occurredAt,string $keyId,string $key):self {if(strlen($key)<32){throw new \InvalidArgumentException('Operator approval keys require at least 32 bytes.');}$approverId=PanelOperationsGuard::identifier($approverId,'operator approver id');$occurredAt=PanelOperationsGuard::instant($occurredAt);$keyId=PanelOperationsGuard::name($keyId,'operator approval key id');$payload=PanelOperationsGuard::json([$targetDigest,$approverId,$occurredAt,$keyId]);return new self($targetDigest,$approverId,$occurredAt,$keyId,hash_hmac('sha256',$payload,$key));}
	/** @param array<string,string> $keys */public function verify(array $keys,string $targetDigest):bool {$key=$keys[$this->keyId]??null;return hash_equals($this->targetDigest,$targetDigest)&&is_string($key)&&strlen($key)>=32&&hash_equals($this->signature,hash_hmac('sha256',PanelOperationsGuard::json([$this->targetDigest,$this->approverId,$this->occurredAt,$this->keyId]),$key));}
	public function approverId():string{return$this->approverId;}public function occurredAt():string{return$this->occurredAt;}public function keyId():string{return$this->keyId;}
	public function jsonSerialize():array{return['type'=>'panel_operator_approval','target_digest'=>$this->targetDigest,'approver_id'=>$this->approverId,'occurred_at'=>$this->occurredAt,'key_id'=>$this->keyId,'signature'=>$this->signature];}
}
