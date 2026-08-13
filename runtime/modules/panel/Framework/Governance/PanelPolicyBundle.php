<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Versioned, immutable, optionally signed policy distribution unit. */
final class PanelPolicyBundle implements \JsonSerializable {
	/** @var list<PanelPolicyRule> */ private readonly array $rules;
	private readonly string $digest;
	/** @param list<PanelPolicyRule> $rules @param array<string,mixed> $metadata */
	public function __construct(private readonly string $id,private readonly string $version,array $rules,private readonly array $metadata=[],private readonly ?string $keyId=null,private readonly ?string $signature=null){
		PanelOperationsGuard::name($id,'policy bundle id');if($version===''||strlen($version)>64||preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/D',$version)!==1){throw new \InvalidArgumentException('Policy bundle version is invalid.');}if($rules===[]||count($rules)>10000){throw new \InvalidArgumentException('Policy bundles require 1 to 10000 rules.');}$indexed=[];foreach($rules as$rule){if(!$rule instanceof PanelPolicyRule){throw new \InvalidArgumentException('Policy bundle rules must be PanelPolicyRule instances.');}if(isset($indexed[$rule->id()])){throw new \InvalidArgumentException('Policy bundle rule ids must be unique.');}$indexed[$rule->id()]=$rule;}usort($rules,static fn(PanelPolicyRule $a,PanelPolicyRule $b):int=>[$b->priority(),$a->id()]<=>[$a->priority(),$b->id()]);$this->rules=array_values($rules);PanelOperationsGuard::safeMetadata($metadata,512);if(($keyId===null)!==($signature===null)){throw new \InvalidArgumentException('Policy bundle signature metadata is incomplete.');}if($keyId!==null){PanelOperationsGuard::name($keyId,'policy key id');if(preg_match('/^[a-f0-9]{64}$/D',(string)$signature)!==1){throw new \InvalidArgumentException('Policy bundle signature is invalid.');}}$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param array<string,mixed> $manifest */
	public static function from(array $manifest):self {$rules=[];$source=$manifest['rules']??[];if(!is_array($source)||($source!==[]&&array_is_list($source))){throw new \InvalidArgumentException('Policy bundle rules must be an object-like map.');}foreach($source as$id=>$rule){if(!is_array($rule)){throw new \InvalidArgumentException('Policy bundle rule definitions must be maps.');}$rules[]=PanelPolicyRule::from((string)$id,$rule);}return new self(PanelOperationsGuard::name((string)($manifest['id']??''),'policy bundle id'),(string)($manifest['version']??''),$rules,PanelOperationsGuard::safeMetadata(is_array($manifest['metadata']??null)?$manifest['metadata']:[],512));}
	public function id():string{return$this->id;}public function version():string{return$this->version;}public function digest():string{return$this->digest;}public function signed():bool{return$this->signature!==null;}public function keyId():?string{return$this->keyId;}/** @return list<PanelPolicyRule> */public function rules():array{return$this->rules;}
	public function sign(string $keyId,string $key):self {PanelOperationsGuard::name($keyId,'policy key id');if(strlen($key)<32){throw new \InvalidArgumentException('Policy signing keys require at least 32 bytes.');}return new self($this->id,$this->version,$this->rules,$this->metadata,$keyId,hash_hmac('sha256',$this->digest,$key));}
	/** @param array<string,string> $keys */ public function verify(array $keys):bool {$key=$this->keyId!==null?($keys[$this->keyId]??null):null;return is_string($key)&&strlen($key)>=32&&hash_equals((string)$this->signature,hash_hmac('sha256',$this->digest,$key));}
	/** @param array<string,mixed> $payload */ public static function hydrate(array $payload):self {$required=['type','schema_version','api_version','id','version','rules','metadata','digest','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);if($keys!==$required||$payload['type']!=='panel_policy_bundle'||!is_string($payload['id'])||!is_string($payload['version'])||!is_array($payload['rules'])||!is_array($payload['metadata'])||!is_string($payload['digest'])||(!is_string($payload['key_id'])&&$payload['key_id']!==null)||(!is_string($payload['signature'])&&$payload['signature']!==null)){throw new \UnexpectedValueException('Stored policy bundle shape is invalid.');}$rules=[];foreach($payload['rules']as$rule){if(!is_array($rule)||!is_string($rule['id']??null)){throw new \UnexpectedValueException('Stored policy rule shape is invalid.');}$rules[]=PanelPolicyRule::from($rule['id'],$rule);}$bundle=new self($payload['id'],$payload['version'],$rules,$payload['metadata'],$payload['key_id'],$payload['signature']);if(!hash_equals($bundle->digest(),$payload['digest'])){throw new \UnexpectedValueException('Stored policy bundle digest is invalid.');}return$bundle;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_policy_bundle']+$this->unsigned()+['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */ private function unsigned():array{return['id'=>$this->id,'version'=>$this->version,'rules'=>array_map(static fn(PanelPolicyRule $rule):array=>$rule->jsonSerialize(),$this->rules),'metadata'=>$this->metadata];}
}
