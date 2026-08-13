<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable idempotent IAM mutation receipt linked to a hash-chained audit event. */
final class PanelIamReceipt implements \JsonSerializable {
	/** @param array<string,mixed> $payload */
	private function __construct(private readonly array $payload,private readonly bool $replayed=false){}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		foreach(['id','operation','tenant_id','subject_type','subject_id','actor_id','requester_id','reason','idempotency_digest','fingerprint','revision','status','occurred_at','audit_hash','metadata']as$key){if(!array_key_exists($key,$payload)){throw new \InvalidArgumentException('Panel IAM receipt is missing '.$key.'.');}}
		$payload['id']=PanelIamGuard::identifier((string)$payload['id'],'receipt id');
		$payload['operation']=PanelIamGuard::operation((string)$payload['operation']);
		$payload['tenant_id']=PanelIamGuard::identifier((string)$payload['tenant_id'],'tenant id');
		$payload['subject_type']=PanelIamGuard::subjectType((string)$payload['subject_type']);
		$payload['subject_id']=PanelIamGuard::identifier((string)$payload['subject_id'],'subject id');
		$payload['actor_id']=PanelIamGuard::identifier((string)$payload['actor_id'],'actor id');
		$payload['requester_id']=PanelIamGuard::identifier((string)$payload['requester_id'],'requester id');
		$payload['approver_id']=isset($payload['approver_id'])&&$payload['approver_id']!==null?PanelIamGuard::identifier((string)$payload['approver_id'],'approver id'):null;
		$payload['reason']=PanelIamGuard::text((string)$payload['reason'],'reason',500,true);
		foreach(['idempotency_digest','fingerprint','audit_hash']as$key){if(!is_string($payload[$key])||preg_match('/^[a-f0-9]{64}$/D',$payload[$key])!==1){throw new \InvalidArgumentException('Panel IAM receipt '.$key.' is invalid.');}}
		$payload['revision']=(int)$payload['revision'];if($payload['revision']<1){throw new \InvalidArgumentException('Panel IAM receipt revision must be positive.');}
		$payload['status']=PanelIamGuard::status((string)$payload['status']);
		$payload['occurred_at']=PanelIamGuard::instant(is_int($payload['occurred_at'])||is_string($payload['occurred_at'])?$payload['occurred_at']:null,'receipt occurred_at',false);
		if(!is_array($payload['metadata'])){throw new \InvalidArgumentException('Panel IAM receipt metadata must be an array.');}$payload['metadata']=PanelIamGuard::metadata($payload['metadata']);
		return new self($payload,($payload['replayed']??false)===true);
	}

	public function id():string{return(string)$this->payload['id'];}
	public function operation():string{return(string)$this->payload['operation'];}
	public function tenantId():string{return(string)$this->payload['tenant_id'];}
	public function subjectType():string{return(string)$this->payload['subject_type'];}
	public function subjectId():string{return(string)$this->payload['subject_id'];}
	public function revision():int{return(int)$this->payload['revision'];}
	public function status():string{return(string)$this->payload['status'];}
	public function auditHash():string{return(string)$this->payload['audit_hash'];}
	public function replayed():bool{return$this->replayed;}
	public function asReplay():self{return new self($this->payload,true);}
	/** @return array<string,mixed> */ public function storagePayload():array{$payload=$this->payload;unset($payload['replayed']);return$payload;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_receipt','replayed'=>$this->replayed]+$this->storagePayload();}
}
