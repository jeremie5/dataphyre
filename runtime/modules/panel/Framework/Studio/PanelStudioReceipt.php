<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Idempotent immutable transition receipt bound to the revision publication artifact. */
final class PanelStudioReceipt implements \JsonSerializable {
	public const RECEIPT_VERSION=2;
	private function __construct(private readonly string $id,private readonly string $operation,private readonly string $tenantId,private readonly string $documentId,private readonly int $revision,private readonly string $revisionHash,private readonly string $requestHash,private readonly ?string $artifactFingerprint,private readonly bool $replayed=false,private readonly int $formatVersion=self::RECEIPT_VERSION){
		foreach([$id,$revisionHash,$requestHash]as$digest){if(preg_match('/^[a-f0-9]{64}$/',$digest)!==1){throw new \InvalidArgumentException('Studio receipt digests must be SHA-256 hashes.');}}if($artifactFingerprint!==null&&preg_match('/^[a-f0-9]{64}$/',$artifactFingerprint)!==1){throw new \InvalidArgumentException('Studio receipt artifact fingerprint is invalid.');}
		if(!in_array($operation,PanelStudioRevision::ACTIONS,true)||$revision<1||!in_array($formatVersion,[1,self::RECEIPT_VERSION],true)||($formatVersion===1)!==($artifactFingerprint===null)){throw new \InvalidArgumentException('Studio receipt operation, revision, or artifact binding is invalid.');}
		PanelStudioDocument::scope($tenantId,'tenant');PanelStudioDocument::scope($documentId,'document');if(!hash_equals(self::digest($operation,$tenantId,$documentId,$revision,$revisionHash,$requestHash,$artifactFingerprint,$formatVersion),$id)){throw new \UnexpectedValueException('Studio receipt integrity check failed.');}
	}
	public static function make(string $operation,string $tenantId,string $documentId,PanelStudioRevision $revision,string $requestHash):self{$artifact=$revision->artifactFingerprint();if($artifact===null){throw new \LogicException('New Studio receipts cannot bind unbound legacy revisions.');}$id=self::digest($operation,$tenantId,$documentId,$revision->number(),$revision->hash(),$requestHash,$artifact,self::RECEIPT_VERSION);return new self($id,$operation,$tenantId,$documentId,$revision->number(),$revision->hash(),$requestHash,$artifact);}
	/** @param array<string,mixed> $payload */ public static function hydrate(array $payload):self{if(!is_int($payload['version']??null)){throw new \UnexpectedValueException('Stored Studio receipt version is invalid.');}$version=$payload['version'];$artifact=$version===self::RECEIPT_VERSION&&is_string($payload['artifact_fingerprint']??null)?$payload['artifact_fingerprint']:null;$receipt=new self(is_string($payload['id']??null)?$payload['id']:'',is_string($payload['operation']??null)?$payload['operation']:'',is_string($payload['tenant_id']??null)?$payload['tenant_id']:'',is_string($payload['document_id']??null)?$payload['document_id']:'',is_int($payload['revision']??null)?$payload['revision']:0,is_string($payload['revision_hash']??null)?$payload['revision_hash']:'',is_string($payload['request_hash']??null)?$payload['request_hash']:'',$artifact,is_bool($payload['replayed']??null)?$payload['replayed']:false,$version);if(!self::same($payload,$receipt->jsonSerialize())){throw new \UnexpectedValueException('Stored Studio receipt is not canonical.');}return$receipt;}
	public function replay():self{return new self($this->id,$this->operation,$this->tenantId,$this->documentId,$this->revision,$this->revisionHash,$this->requestHash,$this->artifactFingerprint,true,$this->formatVersion);}
	public function id():string{return$this->id;}
	public function operation():string{return$this->operation;}
	public function tenantId():string{return$this->tenantId;}
	public function documentId():string{return$this->documentId;}
	public function revision():int{return$this->revision;}
	public function revisionHash():string{return$this->revisionHash;}
	public function requestHash():string{return$this->requestHash;}
	public function artifactFingerprint():?string{return$this->artifactFingerprint;}
	public function replayed():bool{return$this->replayed;}
	public function bindingStatus():string{return$this->artifactFingerprint?'bound':'unbound_legacy';}
	public function jsonSerialize():array{$value=['type'=>'panel_studio_receipt','version'=>$this->formatVersion,'id'=>$this->id,'operation'=>$this->operation,'tenant_id'=>$this->tenantId,'document_id'=>$this->documentId,'revision'=>$this->revision,'revision_hash'=>$this->revisionHash,'request_hash'=>$this->requestHash,'replayed'=>$this->replayed];if($this->formatVersion===self::RECEIPT_VERSION){$value['binding_status']='bound';$value['artifact_fingerprint']=$this->artifactFingerprint;}return$value;}
	private static function digest(string $operation,string $tenantId,string $documentId,int $revision,string $revisionHash,string $requestHash,?string $artifactFingerprint,int $version):string{$value=[$operation,$tenantId,$documentId,$revision,$revisionHash,$requestHash];if($version===self::RECEIPT_VERSION){$value[]=$artifactFingerprint;}return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
	private static function same(array $left,array $right):bool{self::sort($left);self::sort($right);return json_encode($left,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)===json_encode($right,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function sort(array &$value):void{if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as&$item){if(is_array($item)){self::sort($item);}}}
}
