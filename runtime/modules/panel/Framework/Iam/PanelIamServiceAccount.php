<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable tenant workload identity containing credential metadata, never credentials. */
final class PanelIamServiceAccount implements \JsonSerializable {
	/** @param array<string,mixed> $metadata @param array<string,mixed> $credentialMetadata */
	private function __construct(
		private readonly string $id,
		private readonly string $displayName,
		private readonly string $status,
		private readonly int $revision,
		private readonly array $metadata,
		private readonly array $credentialMetadata,
		private readonly string $createdAt,
		private readonly string $updatedAt
	){}

	/** @param array<string,mixed> $options */
	public static function make(string|int $id,string $displayName,array $options=[]):self {
		$now=PanelIamGuard::instant($options['now']??time(),'service account timestamp',false);
		return self::restore([
			'id'=>$id,'display_name'=>$displayName,'status'=>$options['status']??'active','revision'=>$options['revision']??0,
			'metadata'=>$options['metadata']??[],'credential_metadata'=>$options['credential_metadata']??[],
			'created_at'=>$options['created_at']??$now,'updated_at'=>$options['updated_at']??$now,
		]);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		$id=PanelIamGuard::identifier((string)($payload['id']??''),'service account id');
		$name=PanelIamGuard::text((string)($payload['display_name']??''),'service account display name',190,true);
		$revision=(int)($payload['revision']??0);if($revision<0){throw new \InvalidArgumentException('Panel IAM service account revision cannot be negative.');}
		$metadata=$payload['metadata']??[];$credential=$payload['credential_metadata']??[];
		if(!is_array($metadata)||!is_array($credential)){throw new \InvalidArgumentException('Panel IAM service account metadata must be arrays.');}
		$created=PanelIamGuard::instant(is_int($payload['created_at']??null)||is_string($payload['created_at']??null)?$payload['created_at']:null,'service account created_at',false);
		$updated=PanelIamGuard::instant(is_int($payload['updated_at']??null)||is_string($payload['updated_at']??null)?$payload['updated_at']:null,'service account updated_at',false);
		if(strcmp((string)$updated,(string)$created)<0){throw new \InvalidArgumentException('Panel IAM service account updated_at cannot precede created_at.');}
		return new self($id,$name,PanelIamGuard::status((string)($payload['status']??'active')),$revision,PanelIamGuard::metadata($metadata),PanelIamGuard::credentialMetadata($credential),(string)$created,(string)$updated);
	}

	public function id():string{return$this->id;}
	public function subjectType():string{return'service';}
	public function displayName():string{return$this->displayName;}
	public function status():string{return$this->status;}
	public function revision():int{return$this->revision;}
	/** @return array<string,mixed> */ public function metadata():array{return$this->metadata;}
	/** @return array<string,mixed> */ public function credentialMetadata():array{return$this->credentialMetadata;}
	public function createdAt():string{return$this->createdAt;}
	public function updatedAt():string{return$this->updatedAt;}

	public function withRevision(int $revision,string|int|null $updatedAt=null):self {
		if($revision<0){throw new \InvalidArgumentException('Panel IAM service account revision cannot be negative.');}
		return self::restore(array_replace($this->storagePayload(),['revision'=>$revision,'updated_at'=>$updatedAt??$this->updatedAt]));
	}

	public function withStatus(string $status,string|int $updatedAt):self {
		return self::restore(array_replace($this->storagePayload(),['status'=>$status,'updated_at'=>$updatedAt]));
	}

	/** @param array<string,mixed> $metadata */
	public function rotateCredential(array $metadata,string|int $updatedAt):self {
		return self::restore(array_replace($this->storagePayload(),['credential_metadata'=>PanelIamGuard::credentialMetadata($metadata,true),'updated_at'=>$updatedAt]));
	}

	/** @return array<string,mixed> */
	public function storagePayload():array{return['id'=>$this->id,'display_name'=>$this->displayName,'status'=>$this->status,'revision'=>$this->revision,'metadata'=>$this->metadata,'credential_metadata'=>$this->credentialMetadata,'created_at'=>$this->createdAt,'updated_at'=>$this->updatedAt];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_service_account','subject_type'=>'service']+$this->storagePayload()+['raw_credential_serialized'=>false];}
}
