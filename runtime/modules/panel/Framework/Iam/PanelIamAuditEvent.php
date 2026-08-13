<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable HMAC hash-chained IAM audit event with bounded secret-free metadata. */
final class PanelIamAuditEvent implements \JsonSerializable {
	/** @param array<string,mixed> $payload */
	private function __construct(private readonly array $payload){}

	/** @param array<string,mixed> $metadata */
	public static function make(int $sequence,PanelIamMutation $mutation,string $receiptId,string $previousHash,array $metadata,string $keyId,string $integrityKey,string|int $occurredAt):self {
		self::key($integrityKey);
		if($sequence<1){throw new \InvalidArgumentException('Panel IAM audit sequence must be positive.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$previousHash)!==1){throw new \InvalidArgumentException('Panel IAM audit previous hash is invalid.');}
		$payload=['sequence'=>$sequence,'event'=>'iam.'.$mutation->operation(),'tenant_id'=>$mutation->tenantId(),'subject_type'=>$mutation->subjectType(),'subject_id'=>$mutation->subjectId(),'actor_id'=>$mutation->actorId(),'requester_id'=>$mutation->requesterId(),'approver_id'=>$mutation->approverId(),'reason'=>$mutation->reason(),'idempotency_digest'=>$mutation->idempotencyDigest(),'receipt_id'=>PanelIamGuard::identifier($receiptId,'receipt id'),'occurred_at'=>PanelIamGuard::instant($occurredAt,'audit occurred_at',false),'metadata'=>PanelIamGuard::metadata($metadata),'previous_hash'=>$previousHash,'integrity'=>'hmac-sha256-v1','key_id'=>PanelIamGuard::identifier($keyId,'audit key id')];
		$payload['hash']=hash_hmac('sha256',PanelIamGuard::canonicalJson($payload),$integrityKey);
		return new self($payload);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		foreach(['sequence','event','tenant_id','subject_type','subject_id','actor_id','requester_id','reason','idempotency_digest','receipt_id','occurred_at','metadata','previous_hash','integrity','key_id','hash']as$key){if(!array_key_exists($key,$payload)){throw new \InvalidArgumentException('Panel IAM audit event is missing '.$key.'.');}}
		$sequence=(int)$payload['sequence'];if($sequence<1){throw new \InvalidArgumentException('Panel IAM audit sequence must be positive.');}$payload['sequence']=$sequence;
		$payload['event']=PanelIamGuard::operation((string)$payload['event']);
		$payload['tenant_id']=PanelIamGuard::identifier((string)$payload['tenant_id'],'tenant id');$payload['subject_type']=PanelIamGuard::subjectType((string)$payload['subject_type']);$payload['subject_id']=PanelIamGuard::identifier((string)$payload['subject_id'],'subject id');
		$payload['actor_id']=PanelIamGuard::identifier((string)$payload['actor_id'],'actor id');$payload['requester_id']=PanelIamGuard::identifier((string)$payload['requester_id'],'requester id');$payload['approver_id']=isset($payload['approver_id'])&&$payload['approver_id']!==null?PanelIamGuard::identifier((string)$payload['approver_id'],'approver id'):null;$payload['reason']=PanelIamGuard::text((string)$payload['reason'],'reason',500,true);
		foreach(['idempotency_digest','previous_hash','hash']as$key){if(!is_string($payload[$key])||preg_match('/^[a-f0-9]{64}$/D',$payload[$key])!==1){throw new \InvalidArgumentException('Panel IAM audit '.$key.' is invalid.');}}
		$payload['receipt_id']=PanelIamGuard::identifier((string)$payload['receipt_id'],'receipt id');$payload['occurred_at']=PanelIamGuard::instant(is_int($payload['occurred_at'])||is_string($payload['occurred_at'])?$payload['occurred_at']:null,'audit occurred_at',false);
		if(!is_array($payload['metadata'])){throw new \InvalidArgumentException('Panel IAM audit metadata must be an array.');}$payload['metadata']=PanelIamGuard::metadata($payload['metadata']);
		if($payload['integrity']!=='hmac-sha256-v1'){throw new \InvalidArgumentException('Panel IAM audit integrity mode is unsupported.');}$payload['key_id']=PanelIamGuard::identifier((string)$payload['key_id'],'audit key id');
		return new self($payload);
	}

	public function sequence():int{return(int)$this->payload['sequence'];}
	public function tenantId():string{return(string)$this->payload['tenant_id'];}
	public function keyId():string{return(string)$this->payload['key_id'];}
	public function hash():string{return(string)$this->payload['hash'];}
	public function previousHash():string{return(string)$this->payload['previous_hash'];}
	public function verify(string $integrityKey):bool {self::key($integrityKey);$payload=$this->payload;$hash=(string)$payload['hash'];unset($payload['hash']);return hash_equals($hash,hash_hmac('sha256',PanelIamGuard::canonicalJson($payload),$integrityKey));}
	/** @return array<string,mixed> */ public function storagePayload():array{return$this->payload;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_audit_event']+$this->payload;}
	private static function key(string $key):void{if(strlen($key)<32){throw new \InvalidArgumentException('Panel IAM audit integrity key must contain at least 32 bytes.');}}
}
