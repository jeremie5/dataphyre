<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable mutation outcome suitable for replay, transport, and audit evidence. */
final class PanelDataMutationReceipt implements \JsonSerializable {
	private const OUTCOMES=['created','updated','deleted','unchanged'];
	private readonly mixed $record;
	/** @var list<string> */private readonly array $changedFields;
	/** @var array<string,mixed> */private readonly array $metadata;
	private readonly string $receiptId;
	private readonly string $correlationId;

	/** @param list<string> $changedFields @param array<string,mixed> $metadata */
	public function __construct(
		private readonly string $source,
		private readonly string $operation,
		private readonly string|int $key,
		private readonly string $outcome,
		private readonly int $revision,
		private readonly string $mutationFingerprint,
		private readonly string $idempotencyDigest,
		private readonly string $occurredAt,
		mixed $record=null,
		array $changedFields=[],
		array $metadata=[],
		private readonly bool $replayed=false
	){
		if(preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$source)!==1){throw new \InvalidArgumentException('Panel data-mutation receipt source is invalid.');}
		if(!in_array($operation,PanelDataMutation::OPERATIONS,true)||!in_array($outcome,self::OUTCOMES,true)){throw new \InvalidArgumentException('Panel data-mutation receipt operation or outcome is invalid.');}
		if($revision<1){throw new \InvalidArgumentException('Panel data-mutation receipt revision must be positive.');}
		foreach([$mutationFingerprint,$idempotencyDigest]as$digest){if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Panel data-mutation receipt digest is invalid.');}}
		try{new \DateTimeImmutable($occurredAt);}catch(\Throwable $error){throw new \InvalidArgumentException('Panel data-mutation receipt instant is invalid.',0,$error);}
		$record=$record===null?null:PanelQueryValue::normalize($record,'mutation receipt record');
		$fields=[];foreach($changedFields as$field){if(!is_string($field)||preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,255}$/D',$field)!==1){throw new \InvalidArgumentException('Panel data-mutation changed field is invalid.');}$fields[$field]=true;}$fields=array_keys($fields);sort($fields,SORT_STRING);
		if($metadata!==[]&&array_is_list($metadata)){throw new \InvalidArgumentException('Panel data-mutation receipt metadata must be object-like.');}
		$safe=PanelSensitiveDataSanitizer::sanitize(PanelQueryValue::normalize($metadata,'mutation receipt metadata'));if(!is_array($safe)||($safe!==[]&&array_is_list($safe))){throw new \InvalidArgumentException('Panel data-mutation receipt metadata is invalid.');}
		$this->record=$record;$this->changedFields=$fields;$this->metadata=$safe;
		$this->receiptId='mutation_'.substr(hash('sha256',$source.'|'.$idempotencyDigest.'|'.$mutationFingerprint),0,40);
		$this->correlationId='corr_'.substr(hash('sha256',$source.'|'.$idempotencyDigest),0,40);
	}

	public function source():string{return$this->source;}
	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload):self{
		$required=['type','version','id','correlation_id','source','operation','key','outcome','revision','mutation_fingerprint','idempotency_digest','raw_idempotency_serialized','occurred_at','record','changed_fields','metadata','replayed'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_data_mutation_receipt'||($payload['version']??null)!==1||!is_string($payload['id']??null)||!is_string($payload['correlation_id']??null)||!is_string($payload['source']??null)||!is_string($payload['operation']??null)||(!is_string($payload['key']??null)&&!is_int($payload['key']??null))||!is_string($payload['outcome']??null)||!is_int($payload['revision']??null)||!is_string($payload['mutation_fingerprint']??null)||!is_string($payload['idempotency_digest']??null)||($payload['raw_idempotency_serialized']??null)!==false||!is_string($payload['occurred_at']??null)||!is_array($payload['changed_fields']??null)||!array_is_list($payload['changed_fields'])||!is_array($payload['metadata']??null)||!is_bool($payload['replayed']??null)){
			throw new \UnexpectedValueException('Stored Panel data-mutation receipt shape is invalid.');
		}
		try{$receipt=new self($payload['source'],$payload['operation'],$payload['key'],$payload['outcome'],$payload['revision'],$payload['mutation_fingerprint'],$payload['idempotency_digest'],$payload['occurred_at'],$payload['record'],$payload['changed_fields'],$payload['metadata'],$payload['replayed']);}
		catch(\Throwable $error){throw new \UnexpectedValueException('Stored Panel data-mutation receipt is invalid.',0,$error);}
		if(!hash_equals($receipt->receiptId(),$payload['id'])||!hash_equals($receipt->correlationId(),$payload['correlation_id'])){throw new \UnexpectedValueException('Stored Panel data-mutation receipt identity is invalid.');}
		return$receipt;
	}
	public function operation():string{return$this->operation;}
	public function key():string|int{return$this->key;}
	public function outcome():string{return$this->outcome;}
	public function revision():int{return$this->revision;}
	public function mutationFingerprint():string{return$this->mutationFingerprint;}
	public function idempotencyDigest():string{return$this->idempotencyDigest;}
	public function occurredAt():string{return$this->occurredAt;}
	public function record():mixed{return$this->record;}
	/** @return list<string> */public function changedFields():array{return$this->changedFields;}
	/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}
	public function replayed():bool{return$this->replayed;}
	public function receiptId():string{return$this->receiptId;}
	public function correlationId():string{return$this->correlationId;}
	public function asReplay():self{return$this->replayed?$this:new self($this->source,$this->operation,$this->key,$this->outcome,$this->revision,$this->mutationFingerprint,$this->idempotencyDigest,$this->occurredAt,$this->record,$this->changedFields,$this->metadata,true);}
	public function jsonSerialize():array{return['type'=>'panel_data_mutation_receipt','version'=>1,'id'=>$this->receiptId,'correlation_id'=>$this->correlationId,'source'=>$this->source,'operation'=>$this->operation,'key'=>$this->key,'outcome'=>$this->outcome,'revision'=>$this->revision,'mutation_fingerprint'=>$this->mutationFingerprint,'idempotency_digest'=>$this->idempotencyDigest,'raw_idempotency_serialized'=>false,'occurred_at'=>$this->occurredAt,'record'=>$this->record,'changed_fields'=>$this->changedFields,'metadata'=>$this->metadata,'replayed'=>$this->replayed];}
}
