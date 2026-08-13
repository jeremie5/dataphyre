<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, scope-bound, idempotent write command for a Panel data source. */
final class PanelDataMutation implements \JsonSerializable {
	public const OPERATIONS=['create','update','upsert','delete'];
	private const OPTIONS=['idempotency_key','actor_id','tenant','authorization','metadata','expected_revision','reason','return_record'];
	private readonly string $operation;
	private readonly string|int $key;
	/** @var array<string,mixed> */ private readonly array $values;
	private readonly string $idempotencyKey;
	private readonly string $idempotencyDigest;
	private readonly string|int $actorId;
	private readonly string|int|null $tenant;
	/** @var array<string,mixed> */ private readonly array $authorization;
	/** @var array<string,mixed> */ private readonly array $metadata;
	private readonly ?int $expectedRevision;
	private readonly string $reason;
	private readonly bool $returnRecord;
	private readonly string $fingerprint;

	/** @param array<string,mixed> $values @param array<string,mixed> $options */
	private function __construct(string $operation,string|int $key,array $values,array $options){
		$unknown=array_values(array_diff(array_keys($options),self::OPTIONS));
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown Panel data-mutation option: '.(string)$unknown[0]); }
		$operation=strtolower(trim($operation));
		if(!in_array($operation,self::OPERATIONS,true)){ throw new \InvalidArgumentException("Unsupported Panel data mutation '{$operation}'."); }
		$key=self::identifier($key,'record key',512);
		$values=self::map($values,'values',1000,1048576,false);
		if($operation==='delete' && $values!==[]){ throw new \InvalidArgumentException('Delete mutations cannot carry record values.'); }
		if($operation!=='delete' && $values===[]){ throw new \InvalidArgumentException('Create, update, and upsert mutations require record values.'); }
		if(!is_string($options['idempotency_key']??null)){ throw new \InvalidArgumentException('Panel data mutations require an explicit idempotency key.'); }
		$idempotencyKey=trim($options['idempotency_key']);
		if(strlen($idempotencyKey)<8 || strlen($idempotencyKey)>512 || preg_match('//u',$idempotencyKey)!==1 || preg_match('/[\x00-\x1F\x7F]/',$idempotencyKey)===1){ throw new \InvalidArgumentException('Panel data-mutation idempotency keys must contain 8-512 safe bytes.'); }
		if(!array_key_exists('actor_id',$options)){ throw new \InvalidArgumentException('Panel data mutations require an explicit actor id.'); }
		$actorId=self::identifier($options['actor_id'],'actor id',255);
		$tenant=array_key_exists('tenant',$options) && $options['tenant']!==null ? self::identifier($options['tenant'],'tenant',255) : null;
		$authorization=self::map(is_array($options['authorization']??null)?$options['authorization']:[],'authorization',256,262144,true);
		$metadata=self::map(is_array($options['metadata']??null)?$options['metadata']:[],'metadata',256,262144,true);
		$expected=$options['expected_revision']??null;
		if($expected!==null && (!is_int($expected) || $expected<0)){ throw new \InvalidArgumentException('Panel data-mutation expected revision must be a non-negative integer.'); }
		if($operation==='create' && $expected!==null){ throw new \InvalidArgumentException('Create mutations cannot carry an expected revision.'); }
		if(in_array($operation,['update','delete'],true) && $expected===null){ throw new \InvalidArgumentException('Update and delete mutations require an expected revision.'); }
		if(array_key_exists('reason',$options) && !is_string($options['reason'])){ throw new \InvalidArgumentException('Panel data-mutation reason must be a string.'); }
		$reason=trim((string)($options['reason']??''));
		if(strlen($reason)>1000 || preg_match('//u',$reason)!==1){ throw new \InvalidArgumentException('Panel data-mutation reason exceeds its safe text limit.'); }
		if(array_key_exists('return_record',$options) && !is_bool($options['return_record'])){ throw new \InvalidArgumentException('Panel data-mutation return_record must be boolean.'); }
		$returnRecord=($options['return_record']??true)===true;
		$this->operation=$operation;$this->key=$key;$this->values=$values;$this->idempotencyKey=$idempotencyKey;
		$this->idempotencyDigest=hash('sha256',"panel-data-mutation-v1\0".$idempotencyKey);
		$this->actorId=$actorId;$this->tenant=$tenant;$this->authorization=$authorization;$this->metadata=$metadata;
		$this->expectedRevision=$expected;$this->reason=$reason;$this->returnRecord=$returnRecord;
		$this->fingerprint=hash('sha256',PanelQueryValue::stableJson([
			'type'=>'panel_data_mutation_fingerprint','version'=>1,'operation'=>$operation,'key'=>$key,'values'=>$values,
			'actor_id'=>$actorId,'tenant'=>$tenant,'authorization'=>$authorization,'expected_revision'=>$expected,
			'reason'=>$reason,'return_record'=>$returnRecord,
		]));
	}

	/** @param array<string,mixed> $values @param array<string,mixed> $options */
	public static function make(string $operation,string|int $key,array $values,array $options):self{return new self($operation,$key,$values,$options);}
	public static function create(string|int $key,array $values,array $options):self{return self::make('create',$key,$values,$options);}
	public static function update(string|int $key,array $values,array $options):self{return self::make('update',$key,$values,$options);}
	public static function upsert(string|int $key,array $values,array $options):self{return self::make('upsert',$key,$values,$options);}
	public static function delete(string|int $key,array $options):self{return self::make('delete',$key,[],$options);}

	public function operation():string{return$this->operation;}
	public function key():string|int{return$this->key;}
	/** @return array<string,mixed> */ public function values():array{return$this->values;}
	public function idempotencyKey():string{return$this->idempotencyKey;}
	public function idempotencyDigest():string{return$this->idempotencyDigest;}
	public function actorId():string|int{return$this->actorId;}
	public function tenantKey():string|int|null{return$this->tenant;}
	/** @return array<string,mixed> */ public function authorizationMetadata():array{return$this->authorization;}
	/** @return array<string,mixed> */ public function metadata():array{return$this->metadata;}
	public function expectedRevision():?int{return$this->expectedRevision;}
	public function reason():string{return$this->reason;}
	public function returnsRecord():bool{return$this->returnRecord;}
	public function fingerprint():string{return$this->fingerprint;}

	/** Raw transport envelope; callers must keep it out of logs and public manifests. @return array<string,mixed> */
	public function trustedEnvelope():array{return['type'=>'panel_data_mutation','version'=>1,'operation'=>$this->operation,'key'=>$this->key,'values'=>$this->values,'idempotency_key'=>$this->idempotencyKey,'actor_id'=>$this->actorId,'tenant'=>$this->tenant,'authorization'=>$this->authorization,'metadata'=>$this->metadata,'expected_revision'=>$this->expectedRevision,'reason'=>$this->reason,'return_record'=>$this->returnRecord];}

	/** Public serialization deliberately excludes values and the replay credential. @return array<string,mixed> */
	public function jsonSerialize():array{return[
		'type'=>'panel_data_mutation_manifest','version'=>1,'operation'=>$this->operation,'key'=>$this->key,
		'value_fields'=>array_keys($this->values),'values_digest'=>hash('sha256',PanelQueryValue::stableJson($this->values)),
		'idempotency_digest'=>$this->idempotencyDigest,'raw_idempotency_serialized'=>false,'values_serialized'=>false,
		'actor_hash'=>hash('sha256',(string)$this->actorId),'tenant_hash'=>$this->tenant===null?null:hash('sha256',(string)$this->tenant),
		'authorization_keys'=>array_keys($this->authorization),'metadata_keys'=>array_keys($this->metadata),
		'expected_revision'=>$this->expectedRevision,'reason_present'=>$this->reason!=='','return_record'=>$this->returnRecord,'fingerprint'=>$this->fingerprint,
	];}

	private static function identifier(mixed $value,string $label,int $maximum):string|int{
		if(is_int($value)){return$value;}
		if(!is_string($value)){throw new \InvalidArgumentException("Panel data-mutation {$label} must be a string or integer.");}
		$value=trim($value);if($value===''||strlen($value)>$maximum||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value)===1){throw new \InvalidArgumentException("Panel data-mutation {$label} is invalid.");}return$value;
	}

	/** @param array<string,mixed> $value @return array<string,mixed> */
	private static function map(array $value,string $label,int $maximumItems,int $maximumBytes,bool $public):array{
		if($value!==[]&&array_is_list($value)){throw new \InvalidArgumentException("Panel data-mutation {$label} must be an object-like map.");}
		if(count($value)>$maximumItems){throw new \LengthException("Panel data-mutation {$label} exceeds its item limit.");}
		$normalized=PanelQueryValue::normalize($value,'mutation '.$label);
		if(!is_array($normalized)||($normalized!==[]&&array_is_list($normalized))){throw new \InvalidArgumentException("Panel data-mutation {$label} must remain an object-like map.");}
		foreach(array_keys($normalized)as$key){if(!is_string($key)||$key===''||strlen($key)>256||preg_match('//u',$key)!==1){throw new \InvalidArgumentException("Panel data-mutation {$label} contains an invalid key.");}if($label==='values'&&preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,255}$/D',$key)!==1){throw new \InvalidArgumentException('Panel data-mutation value fields must be single safe names.');}if($public&&PanelSensitiveDataSanitizer::isSensitiveKey($key)){throw new \InvalidArgumentException("Panel data-mutation {$label} cannot contain secret-shaped keys.");}}
		if(strlen(PanelQueryValue::stableJson($normalized))>$maximumBytes){throw new \LengthException("Panel data-mutation {$label} exceeds its byte limit.");}
		return$normalized;
	}
}
