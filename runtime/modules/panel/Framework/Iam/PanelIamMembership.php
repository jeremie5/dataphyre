<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, versioned tenant membership for a principal or service account. */
final class PanelIamMembership implements \JsonSerializable {
	/** @param list<string> $roles @param list<string> $permissions @param array<string,mixed> $metadata */
	private function __construct(
		private readonly string $tenantId,
		private readonly string $subjectType,
		private readonly string $subjectId,
		private readonly array $roles,
		private readonly array $permissions,
		private readonly string $status,
		private readonly ?string $expiresAt,
		private readonly int $revision,
		private readonly array $metadata,
		private readonly string $createdAt,
		private readonly string $updatedAt
	){}

	/** @param array<string,mixed> $options */
	public static function make(string|int $tenantId,string $subjectType,string|int $subjectId,array|string $roles=[],array|string $permissions=[],array $options=[]):self {
		$now=PanelIamGuard::instant($options['now']??time(),'membership timestamp',false);
		return self::restore([
			'tenant_id'=>$tenantId,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'roles'=>$roles,'permissions'=>$permissions,
			'status'=>$options['status']??'active','expires_at'=>$options['expires_at']??null,'revision'=>$options['revision']??0,
			'metadata'=>$options['metadata']??[],'created_at'=>$options['created_at']??$now,'updated_at'=>$options['updated_at']??$now,
		]);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		$tenant=PanelIamGuard::identifier((string)($payload['tenant_id']??''),'tenant id');
		$type=PanelIamGuard::subjectType((string)($payload['subject_type']??''));
		$id=PanelIamGuard::identifier((string)($payload['subject_id']??''),'membership subject id');
		$revision=(int)($payload['revision']??0);if($revision<0){throw new \InvalidArgumentException('Panel IAM membership revision cannot be negative.');}
		$metadata=$payload['metadata']??[];if(!is_array($metadata)){throw new \InvalidArgumentException('Panel IAM membership metadata must be an array.');}
		$created=PanelIamGuard::instant(is_int($payload['created_at']??null)||is_string($payload['created_at']??null)?$payload['created_at']:null,'membership created_at',false);
		$updated=PanelIamGuard::instant(is_int($payload['updated_at']??null)||is_string($payload['updated_at']??null)?$payload['updated_at']:null,'membership updated_at',false);
		if(strcmp((string)$updated,(string)$created)<0){throw new \InvalidArgumentException('Panel IAM membership updated_at cannot precede created_at.');}
		return new self($tenant,$type,$id,PanelIamGuard::names(is_array($payload['roles']??null)||is_string($payload['roles']??null)?$payload['roles']:[],'role'),PanelIamGuard::names(is_array($payload['permissions']??null)||is_string($payload['permissions']??null)?$payload['permissions']:[],'permission'),PanelIamGuard::status((string)($payload['status']??'active')),PanelIamGuard::instant(is_int($payload['expires_at']??null)||is_string($payload['expires_at']??null)?$payload['expires_at']:null,'membership expires_at',true),$revision,PanelIamGuard::metadata($metadata),(string)$created,(string)$updated);
	}

	public function tenantId():string{return$this->tenantId;}
	public function subjectType():string{return$this->subjectType;}
	public function subjectId():string{return$this->subjectId;}
	public function key():string{return$this->subjectType.':'.$this->subjectId;}
	/** @return list<string> */ public function roles():array{return$this->roles;}
	/** @return list<string> */ public function permissions():array{return$this->permissions;}
	public function status():string{return$this->status;}
	public function expiresAt():?string{return$this->expiresAt;}
	public function revision():int{return$this->revision;}
	/** @return array<string,mixed> */ public function metadata():array{return$this->metadata;}
	public function createdAt():string{return$this->createdAt;}
	public function updatedAt():string{return$this->updatedAt;}
	public function activeAt(string|int|null $at=null):bool {
		if($this->status!=='active'){return false;}
		if($this->expiresAt===null){return true;}
		$instant=PanelIamGuard::instant($at??time(),'membership active time',false);return strcmp($this->expiresAt,(string)$instant)>0;
	}

	/** @param array<string,mixed> $changes */
	public function evolve(array $changes,int $revision,string|int $updatedAt):self {
		if($revision<1){throw new \InvalidArgumentException('Panel IAM membership revision must be positive after mutation.');}
		return self::restore(array_replace($this->storagePayload(),$changes,['revision'=>$revision,'updated_at'=>$updatedAt]));
	}

	/** @return array<string,mixed> */
	public function storagePayload():array{return['tenant_id'=>$this->tenantId,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'roles'=>$this->roles,'permissions'=>$this->permissions,'status'=>$this->status,'expires_at'=>$this->expiresAt,'revision'=>$this->revision,'metadata'=>$this->metadata,'created_at'=>$this->createdAt,'updated_at'=>$this->updatedAt];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_membership','active'=>$this->activeAt()]+$this->storagePayload();}
}
