<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable tenant/actor/idempotency boundary shared by every command runtime. */
final class PanelCommandEnvelope implements \JsonSerializable {
	/** @var list<string> */
	private array $roles;
	/** @var list<string> */
	private array $permissions;
	private readonly string $createdAt;
	private readonly string $fingerprint;

	/**
	 * @param array<string,mixed> $input
	 * @param list<string> $roles
	 * @param list<string> $permissions
	 * @param array<string,mixed> $metadata
	 * @param array<string,mixed> $evidence Encrypted authority evidence; never serialized publicly.
	 */
	public function __construct(
		private readonly string $command,
		private readonly string $ability,
		private readonly string $tenantId,
		private readonly string $actorId,
		private readonly string $idempotencyKey,
		private readonly array $input=[],
		private readonly string $risk='medium',
		array $roles=[],
		array $permissions=[],
		private readonly ?string $correlationId=null,
		private readonly ?string $causationId=null,
		private readonly ?int $expectedRevision=null,
		private readonly array $metadata=[],
		string|int|\DateTimeInterface|null $createdAt=null,
		private readonly array $evidence=[],
	){
		PanelOperationsGuard::name($command,'command fabric command',160);
		PanelOperationsGuard::abilityPatterns([$ability],'command fabric ability');
		PanelOperationsGuard::identifier($tenantId,'command fabric tenant');
		PanelOperationsGuard::identifier($actorId,'command fabric actor');
		if(trim($idempotencyKey)===''||strlen($idempotencyKey)>512||str_contains($idempotencyKey,"\0")){
			throw new \InvalidArgumentException('Command fabric idempotency key is invalid.');
		}
		PanelOperationsGuard::object($input,'command fabric input',4096);
		PanelOperationsGuard::canonical($input);
		if(!in_array($risk,['low','medium','high','critical'],true)){
			throw new \InvalidArgumentException('Command fabric risk is invalid.');
		}
		$this->roles=PanelOperationsGuard::roles($roles,'command fabric role');
		$this->permissions=PanelOperationsGuard::abilityPatterns($permissions,'command fabric permission');
		foreach([$correlationId,$causationId] as $id){
			if($id!==null){PanelOperationsGuard::identifier($id,'command fabric relation id');}
		}
		if($expectedRevision!==null&&$expectedRevision<0){
			throw new \InvalidArgumentException('Command fabric expected revision is invalid.');
		}
		PanelOperationsGuard::safeMetadata($metadata,512);
		PanelOperationsGuard::object($evidence,'command fabric evidence',128);
		PanelOperationsGuard::canonical($evidence);
		$this->createdAt=PanelOperationsGuard::instant($createdAt??gmdate('c'));
		$this->fingerprint=PanelOperationsGuard::digest($this->semanticContract());
	}

	public function command():string{return $this->command;}
	public function ability():string{return $this->ability;}
	public function tenantId():string{return $this->tenantId;}
	public function actorId():string{return $this->actorId;}
	public function idempotencyKey():string{return $this->idempotencyKey;}
	public function idempotencyHash():string{return hash('sha256',$this->tenantId."\0".$this->command."\0".$this->idempotencyKey);}
	/** @return array<string,mixed> */public function input():array{return $this->input;}
	public function risk():string{return $this->risk;}
	/** @return list<string> */public function roles():array{return $this->roles;}
	/** @return list<string> */public function permissions():array{return $this->permissions;}
	public function correlationId():?string{return $this->correlationId;}
	public function causationId():?string{return $this->causationId;}
	public function expectedRevision():?int{return $this->expectedRevision;}
	/** @return array<string,mixed> */public function metadata():array{return $this->metadata;}
	/** @return array<string,mixed> */public function evidence():array{return $this->evidence;}
	public function createdAt():string{return $this->createdAt;}
	public function fingerprint():string{return $this->fingerprint;}
	/** Digest of the complete executable target excluding its separately sealed authority evidence. */
	public function executionTarget():string{return PanelOperationsGuard::digest($this->executionContract());}

	/** @param array<string,mixed> $evidence */
	public function withEvidence(array $evidence):self {
		return new self(
			$this->command,$this->ability,$this->tenantId,$this->actorId,$this->idempotencyKey,$this->input,
			$this->risk,$this->roles,$this->permissions,$this->correlationId,$this->causationId,
			$this->expectedRevision,$this->metadata,$this->createdAt,$evidence,
		);
	}

	/** @return array<string,mixed> */
	public function sealedPayload():array {
		$payload=['input'=>$this->input,'idempotency_key'=>$this->idempotencyKey];
		if($this->evidence!==[]){$payload['evidence']=$this->evidence;}
		return $payload;
	}

	/** @param array<string,mixed> $manifest @param array<string,mixed> $sealed */
	public static function hydrate(array $manifest,array $sealed):self {
		if(
			($manifest['type']??null)!=='panel_command_envelope'
			||!is_string($manifest['command']??null)
			||!is_string($manifest['ability']??null)
			||!is_string($manifest['tenant_id']??null)
			||!is_string($manifest['actor_id']??null)
			||!is_array($manifest['roles']??null)
			||!is_array($manifest['permissions']??null)
			||!is_string($manifest['risk']??null)
			||(!is_string($manifest['correlation_id']??null)&&($manifest['correlation_id']??null)!==null)
			||(!is_string($manifest['causation_id']??null)&&($manifest['causation_id']??null)!==null)
			||(!is_int($manifest['expected_revision']??null)&&($manifest['expected_revision']??null)!==null)
			||!is_array($manifest['metadata']??null)
			||!is_string($manifest['created_at']??null)
			||!is_string($manifest['fingerprint']??null)
			||!is_array($sealed['input']??null)
			||!is_string($sealed['idempotency_key']??null)
			||(isset($sealed['evidence'])&&!is_array($sealed['evidence']))
		){
			throw new \UnexpectedValueException('Stored command envelope is invalid.');
		}
		$self=new self(
			$manifest['command'],$manifest['ability'],$manifest['tenant_id'],$manifest['actor_id'],
			$sealed['idempotency_key'],$sealed['input'],$manifest['risk'],$manifest['roles'],$manifest['permissions'],
			$manifest['correlation_id'],$manifest['causation_id'],$manifest['expected_revision'],$manifest['metadata'],$manifest['created_at'],
			is_array($sealed['evidence']??null)?$sealed['evidence']:[],
		);
		if(!hash_equals($self->fingerprint(),$manifest['fingerprint'])||!hash_equals($self->idempotencyHash(),(string)($manifest['idempotency_hash']??''))){
			throw new \UnexpectedValueException('Stored command envelope integrity check failed.');
		}
		return $self;
	}

	public function jsonSerialize():array {
		$manifest=PanelManifestContract::stamp([
			'type'=>'panel_command_envelope','version'=>1,
		]+$this->publicContract()+[
			'fingerprint'=>$this->fingerprint,
			'input_redacted'=>['redacted'=>true,'digest'=>PanelOperationsGuard::digest($this->input)],
			'idempotency_key_exposed'=>false,
		]);
		if($this->evidence!==[]){
			$manifest['evidence_redacted']=['redacted'=>true,'digest'=>PanelOperationsGuard::digest($this->evidence)];
			$manifest['authority_evidence_exposed']=false;
		}
		return $manifest;
	}

	/** @return array<string,mixed> */
	private function publicContract():array{return $this->semanticContract()+['created_at'=>$this->createdAt];}

	/** @return array<string,mixed> */
	private function semanticContract():array {
		$contract=$this->baseSemanticContract();
		if($this->evidence!==[]){$contract['evidence_digest']=PanelOperationsGuard::digest($this->evidence);}
		return $contract;
	}

	/** @return array<string,mixed> */
	private function executionContract():array {
		return $this->baseSemanticContract()+['created_at'=>$this->createdAt];
	}

	/** @return array<string,mixed> */
	private function baseSemanticContract():array {
		return [
			'command'=>$this->command,'ability'=>$this->ability,'tenant_id'=>$this->tenantId,'actor_id'=>$this->actorId,
			'idempotency_hash'=>$this->idempotencyHash(),'input_digest'=>PanelOperationsGuard::digest($this->input),
			'risk'=>$this->risk,'roles'=>$this->roles,'permissions'=>$this->permissions,
			'correlation_id'=>$this->correlationId,'causation_id'=>$this->causationId,
			'expected_revision'=>$this->expectedRevision,'metadata'=>PanelOperationsGuard::safeMetadata($this->metadata,512),
		];
	}
}
