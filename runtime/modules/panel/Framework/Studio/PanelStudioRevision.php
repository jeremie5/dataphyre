<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable hash-chained Studio revision/audit event with explicit artifact binding. */
final class PanelStudioRevision implements \JsonSerializable {
	public const STATES=['draft','published'];
	public const ACTIONS=['save','approve','publish','rollback'];
	public const REVISION_VERSION=2;
	private readonly string $contentHash;
	private readonly string $hash;
	/** @var list<string> */ private readonly array $approvals;
	/** @param list<string> $approvals */
	private function __construct(
		private readonly int $number,
		private readonly string $state,
		private readonly string $action,
		private readonly PanelStudioDefinition $definition,
		private readonly string $actor,
		private readonly string $createdAt,
		private readonly string $parentHash,
		private readonly ?int $sourceRevision,
		array $approvals,
		private readonly ?PanelStudioArtifact $artifact,
		private readonly int $formatVersion=self::REVISION_VERSION,
		?string $hash=null
	){
		if($number<1){throw new \InvalidArgumentException('Studio revision numbers must be positive.');}
		if(!in_array($state,self::STATES,true)||!in_array($action,self::ACTIONS,true)){throw new \InvalidArgumentException('Studio revision state or action is invalid.');}
		if(($state==='draft')!==in_array($action,['save','approve'],true)){throw new \InvalidArgumentException('Studio revision states must match their transition action.');}
		PanelStudioDocument::scope($actor,'principal');
		if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',$createdAt)!==1){throw new \InvalidArgumentException('Studio revision timestamps must be valid ISO timestamps.');}
		try{new \DateTimeImmutable($createdAt);}catch(\Throwable){throw new \InvalidArgumentException('Studio revision timestamps must be valid ISO timestamps.');}
		if($number===1&&$parentHash!==''){throw new \InvalidArgumentException('The first Studio revision cannot have a parent hash.');}
		if($number>1&&preg_match('/^[a-f0-9]{64}$/',$parentHash)!==1){throw new \InvalidArgumentException('Studio revision parent hashes must be SHA-256 digests.');}
		if($sourceRevision!==null&&($sourceRevision<1||$sourceRevision>=$number)){throw new \InvalidArgumentException('Studio source revisions must reference earlier history.');}
		if(($number===1&&($action!=='save'||$sourceRevision!==null))||($number>1&&$sourceRevision===null)){throw new \InvalidArgumentException('Studio revision ancestry is incomplete.');}
		if(!in_array($formatVersion,[1,self::REVISION_VERSION],true)||($formatVersion===1)!==($artifact===null)){throw new \InvalidArgumentException('Studio revision artifact binding format is invalid.');}
		if($artifact){$artifact->assertDefinition($definition);}
		$normalized=[];foreach($approvals as$principal){PanelStudioDocument::scope($principal,'approval principal');$normalized[$principal]=true;}$approvals=array_keys($normalized);sort($approvals,SORT_STRING);$this->approvals=$approvals;
		$this->contentHash=$definition->hash();$computed=self::digest($this->unsigned());if($hash!==null&&!hash_equals($computed,$hash)){throw new \UnexpectedValueException('Studio revision integrity check failed.');}$this->hash=$computed;
	}
	/** @param list<string> $approvals */ public static function make(int $number,string $state,string $action,PanelStudioDefinition $definition,string $actor,string $createdAt,string $parentHash='',?int $sourceRevision=null,array $approvals=[],?PanelStudioArtifact $artifact=null):self{return new self($number,$state,$action,$definition,$actor,$createdAt,$parentHash,$sourceRevision,$approvals,$artifact??PanelStudioArtifact::portable($definition));}
	/** @param array<string,mixed> $payload */ public static function hydrate(array $payload):self{
		if(!is_int($payload['version']??null)){throw new \UnexpectedValueException('Stored Studio revision version is invalid.');}$definition=is_array($payload['definition']['root']??null)?PanelStudioDefinition::from($payload['definition']['root']):throw new \UnexpectedValueException('Stored Studio revision definition is missing.');$version=$payload['version'];$artifact=null;if($version===self::REVISION_VERSION){if(!is_array($payload['artifact']??null)||($payload['binding_status']??null)!=='bound') {throw new \UnexpectedValueException('Stored Studio revision artifact is missing.');}$artifact=PanelStudioArtifact::hydrate($payload['artifact']);}elseif($version!==1){throw new \UnexpectedValueException('Stored Studio revision version is unsupported.');}
		$revision=new self(is_int($payload['number']??null)?$payload['number']:0,is_string($payload['state']??null)?$payload['state']:'',is_string($payload['action']??null)?$payload['action']:'',$definition,is_string($payload['actor']??null)?$payload['actor']:'',is_string($payload['created_at']??null)?$payload['created_at']:'',is_string($payload['parent_hash']??null)?$payload['parent_hash']:'',is_int($payload['source_revision']??null)?$payload['source_revision']:null,is_array($payload['approvals']??null)?$payload['approvals']:[],$artifact,$version,is_string($payload['hash']??null)?$payload['hash']:null);if(!self::same($payload,$revision->jsonSerialize())){throw new \UnexpectedValueException('Stored Studio revision is not canonical.');}return$revision;
	}
	public function number():int{return$this->number;}
	public function state():string{return$this->state;}
	public function action():string{return$this->action;}
	public function definition():PanelStudioDefinition{return$this->definition;}
	public function actor():string{return$this->actor;}
	public function createdAt():string{return$this->createdAt;}
	public function parentHash():string{return$this->parentHash;}
	public function sourceRevision():?int{return$this->sourceRevision;}
	public function approvals():array{return$this->approvals;}
	public function contentHash():string{return$this->contentHash;}
	public function hash():string{return$this->hash;}
	public function artifact():?PanelStudioArtifact{return$this->artifact;}
	public function artifactFingerprint():?string{return$this->artifact?->fingerprint();}
	public function bindingStatus():string{return$this->artifact?'bound':'unbound_legacy';}
	public function formatVersion():int{return$this->formatVersion;}
	public function jsonSerialize():array{return$this->unsigned()+['hash'=>$this->hash];}
	private function unsigned():array{
		$base=['type'=>'panel_studio_revision','version'=>$this->formatVersion,'number'=>$this->number,'state'=>$this->state,'action'=>$this->action,'definition'=>$this->definition->jsonSerialize(),'content_hash'=>$this->contentHash,'actor'=>$this->actor,'created_at'=>$this->createdAt,'parent_hash'=>$this->parentHash,'source_revision'=>$this->sourceRevision,'approvals'=>$this->approvals];if($this->formatVersion===self::REVISION_VERSION){$base['binding_status']='bound';$base['artifact']=$this->artifact?->jsonSerialize();}return$base;
	}
	private static function digest(array $value):string{return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
	private static function same(array $left,array $right):bool{self::sort($left);self::sort($right);return json_encode($left,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)===json_encode($right,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function sort(array &$value):void{if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as&$item){if(is_array($item)){self::sort($item);}}}
}
