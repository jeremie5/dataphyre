<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable privileged IAM command envelope with explicit scope and provenance. */
final class PanelIamMutation implements \JsonSerializable {
	private const OPERATIONS=['principal.create','service.create','membership.grant','membership.revoke','membership.suspend','membership.restore','service.rotate_credential'];

	private function __construct(
		private readonly string $operation,
		private readonly string $tenantId,
		private readonly string $subjectType,
		private readonly string $subjectId,
		private readonly string $actorId,
		private readonly string $requesterId,
		private readonly ?string $approverId,
		private readonly string $reason,
		private readonly string $idempotencyDigest,
		private readonly ?int $expectedRevision
	){}

	/** @param array<string,mixed> $options */
	public static function make(string $operation,string|int $tenantId,string $subjectType,string|int $subjectId,string|int $actorId,string $reason,string $idempotencyKey,?int $expectedRevision=null,array $options=[]):self {
		$operation=PanelIamGuard::operation($operation);
		if(!in_array($operation,self::OPERATIONS,true)){throw new \InvalidArgumentException('Panel IAM mutation operation is unsupported.');}
		if($expectedRevision!==null&&$expectedRevision<0){throw new \InvalidArgumentException('Panel IAM expected revision cannot be negative.');}
		$idempotencyKey=PanelIamGuard::text($idempotencyKey,'idempotency key',500,true);
		$actor=PanelIamGuard::identifier($actorId,'actor id');
		$requester=PanelIamGuard::identifier($options['requester_id']??$actor,'requester id');
		$approver=isset($options['approver_id'])&&trim((string)$options['approver_id'])!==''?PanelIamGuard::identifier((string)$options['approver_id'],'approver id'):null;
		return new self($operation,PanelIamGuard::identifier($tenantId,'tenant id'),PanelIamGuard::subjectType($subjectType),PanelIamGuard::identifier($subjectId,'subject id'),$actor,$requester,$approver,PanelIamGuard::text($reason,'reason',500,true),hash('sha256',$idempotencyKey),$expectedRevision);
	}

	public function operation():string{return$this->operation;}
	public function tenantId():string{return$this->tenantId;}
	public function subjectType():string{return$this->subjectType;}
	public function subjectId():string{return$this->subjectId;}
	public function actorId():string{return$this->actorId;}
	public function requesterId():string{return$this->requesterId;}
	public function approverId():?string{return$this->approverId;}
	public function reason():string{return$this->reason;}
	public function idempotencyDigest():string{return$this->idempotencyDigest;}
	public function expectedRevision():?int{return$this->expectedRevision;}

	public function assert(string $operation,string $subjectType,string|int $subjectId):void {
		if($this->operation!==$operation||$this->subjectType!==PanelIamGuard::subjectType($subjectType)||!hash_equals($this->subjectId,PanelIamGuard::identifier($subjectId,'subject id'))){throw new \InvalidArgumentException('Panel IAM mutation envelope does not match the requested operation.');}
	}

	public function fingerprint(array $payload=[]):string {
		return PanelIamGuard::digest([$this->operation,$this->tenantId,$this->subjectType,$this->subjectId,$this->actorId,$this->requesterId,$this->approverId,$this->reason,$this->expectedRevision,PanelIamGuard::metadata($payload)]);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{return['type'=>'panel_iam_mutation','operation'=>$this->operation,'tenant_id'=>$this->tenantId,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'actor_id'=>$this->actorId,'requester_id'=>$this->requesterId,'approver_id'=>$this->approverId,'reason'=>$this->reason,'idempotency_digest'=>$this->idempotencyDigest,'expected_revision'=>$this->expectedRevision,'raw_idempotency_serialized'=>false];}
}
