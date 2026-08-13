<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable human or workload identity descriptor without authentication material. */
final class PanelIamPrincipal implements \JsonSerializable {
	/** @param array<string,mixed> $metadata */
	private function __construct(
		private readonly string $id,
		private readonly string $displayName,
		private readonly ?string $email,
		private readonly string $status,
		private readonly int $revision,
		private readonly array $metadata,
		private readonly string $createdAt,
		private readonly string $updatedAt
	){}

	/** @param array<string,mixed> $options */
	public static function make(string|int $id,string $displayName,array $options=[]):self {
		$now=PanelIamGuard::instant($options['now']??time(),'principal timestamp',false);
		return self::restore([
			'id'=>$id,'display_name'=>$displayName,'email'=>$options['email']??null,'status'=>$options['status']??'active',
			'revision'=>$options['revision']??0,'metadata'=>$options['metadata']??[],'created_at'=>$options['created_at']??$now,'updated_at'=>$options['updated_at']??$now,
		]);
	}

	/** @param array<string,mixed> $payload */
	public static function restore(array $payload):self {
		$id=PanelIamGuard::identifier((string)($payload['id']??''),'principal id');
		$name=PanelIamGuard::text((string)($payload['display_name']??''),'principal display name',190,true);
		$email=$payload['email']??null;
		if($email!==null){$email=strtolower(trim((string)$email));if(strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false){throw new \InvalidArgumentException('Panel IAM principal email is invalid.');}}
		$revision=(int)($payload['revision']??0);if($revision<0){throw new \InvalidArgumentException('Panel IAM principal revision cannot be negative.');}
		$metadata=$payload['metadata']??[];if(!is_array($metadata)){throw new \InvalidArgumentException('Panel IAM principal metadata must be an array.');}
		$created=PanelIamGuard::instant(is_int($payload['created_at']??null)||is_string($payload['created_at']??null)?$payload['created_at']:null,'principal created_at',false);
		$updated=PanelIamGuard::instant(is_int($payload['updated_at']??null)||is_string($payload['updated_at']??null)?$payload['updated_at']:null,'principal updated_at',false);
		if(strcmp((string)$updated,(string)$created)<0){throw new \InvalidArgumentException('Panel IAM principal updated_at cannot precede created_at.');}
		return new self($id,$name,$email!==null?$email:null,PanelIamGuard::status((string)($payload['status']??'active')),$revision,PanelIamGuard::metadata($metadata),(string)$created,(string)$updated);
	}

	public function id():string{return$this->id;}
	public function subjectType():string{return'principal';}
	public function displayName():string{return$this->displayName;}
	public function email():?string{return$this->email;}
	public function status():string{return$this->status;}
	public function revision():int{return$this->revision;}
	/** @return array<string,mixed> */ public function metadata():array{return$this->metadata;}
	public function createdAt():string{return$this->createdAt;}
	public function updatedAt():string{return$this->updatedAt;}

	public function withRevision(int $revision,string|int|null $updatedAt=null):self {
		if($revision<0){throw new \InvalidArgumentException('Panel IAM principal revision cannot be negative.');}
		return self::restore(array_replace($this->storagePayload(),['revision'=>$revision,'updated_at'=>$updatedAt??$this->updatedAt]));
	}

	public function withStatus(string $status,string|int $updatedAt):self {
		return self::restore(array_replace($this->storagePayload(),['status'=>$status,'updated_at'=>$updatedAt]));
	}

	/** @return array<string,mixed> */
	public function storagePayload():array{return['id'=>$this->id,'display_name'=>$this->displayName,'email'=>$this->email,'status'=>$this->status,'revision'=>$this->revision,'metadata'=>$this->metadata,'created_at'=>$this->createdAt,'updated_at'=>$this->updatedAt];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_principal','subject_type'=>'principal']+$this->storagePayload();}
}
