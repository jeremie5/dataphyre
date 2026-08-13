<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, optionally signed result of compiling a domain application. */
final class PanelDomainCompilation implements \JsonSerializable {
	public const VERSION=1;
	private readonly string $digest;
	private readonly string $domainId;
	private readonly string $domainVersion;
	private readonly string $sourceFingerprint;
	private readonly string $compilerFingerprint;
	/** @var array<string,mixed> */ private readonly array $artifacts;
	private readonly ?string $keyId;
	private readonly ?string $signature;

	/** @param array<string,mixed> $artifacts */
	public function __construct(
		string $domainId,
		string $domainVersion,
		string $sourceFingerprint,
		string $compilerFingerprint,
		array $artifacts,
		?string $keyId=null,
		?string $signature=null,
	){
		$this->domainId=PanelOperationsGuard::name($domainId,'compiled domain id');if($domainVersion===''||preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/D',$domainVersion)!==1){throw new \InvalidArgumentException('Compiled domain version is invalid.');}$this->domainVersion=$domainVersion;
		foreach([$sourceFingerprint,$compilerFingerprint]as$fingerprint){if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Compiled domain fingerprint is invalid.');}}
		$this->sourceFingerprint=$sourceFingerprint;$this->compilerFingerprint=$compilerFingerprint;if(($keyId===null)!==($signature===null)){throw new \InvalidArgumentException('Compiled domain signature metadata is incomplete.');}$this->keyId=$keyId!==null?PanelOperationsGuard::name($keyId,'compilation key id'):null;if($signature!==null&&preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Compiled domain signature is invalid.');}$this->signature=$signature;
		PanelOperationsGuard::object($artifacts,'compiled artifacts',64);$wrapped=PanelManifestContract::stamp(['type'=>'panel_domain_artifacts_manifest','artifacts'=>PanelOperationsGuard::canonical($artifacts)]);$normalized=$wrapped['artifacts']??null;if(!is_array($normalized)){throw new \UnexpectedValueException('Compiled artifacts could not be normalized.');}$this->artifacts=$normalized;$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	public function domainId():string{return$this->domainId;}
	public function domainVersion():string{return$this->domainVersion;}
	public function sourceFingerprint():string{return$this->sourceFingerprint;}
	public function compilerFingerprint():string{return$this->compilerFingerprint;}
	public function digest():string{return$this->digest;}
	public function signed():bool{return$this->signature!==null;}
	public function keyId():?string{return$this->keyId;}
	/** @return array<string,mixed> */ public function artifacts():array{return$this->artifacts;}
	public function artifact(string $name):mixed{if(!array_key_exists($name,$this->artifacts)){throw new \OutOfBoundsException('Compiled domain artifact does not exist: '.$name); }return$this->artifacts[$name];}

	public function sign(string $keyId,string $key):self {
		PanelOperationsGuard::name($keyId,'compilation key id');if(strlen($key)<32){throw new \InvalidArgumentException('Compilation signing keys require at least 32 bytes.');}
		$signature=hash_hmac('sha256',$this->digest,$key);return new self($this->domainId,$this->domainVersion,$this->sourceFingerprint,$this->compilerFingerprint,$this->artifacts,$keyId,$signature);
	}

	/** @param array<string,string> $keys */
	public function verify(array $keys):bool {$key=$this->keyId!==null?($keys[$this->keyId]??null):null;return is_string($key)&&strlen($key)>=32&&hash_equals((string)$this->signature,hash_hmac('sha256',$this->digest,$key));}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self {
		$expected=['type','schema_version','api_version','version','domain_id','domain_version','source_fingerprint','compiler_fingerprint','artifacts','digest','key_id','signature'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($expected,SORT_STRING);if($keys!==$expected||($payload['type']??null)!=='panel_domain_compilation'||($payload['version']??null)!==self::VERSION||!is_string($payload['domain_id']??null)||!is_string($payload['domain_version']??null)||!is_string($payload['source_fingerprint']??null)||!is_string($payload['compiler_fingerprint']??null)||!is_array($payload['artifacts']??null)||!is_string($payload['digest']??null)||(!is_string($payload['key_id'])&&$payload['key_id']!==null)||(!is_string($payload['signature'])&&$payload['signature']!==null)){throw new \UnexpectedValueException('Stored domain compilation shape is invalid.');}
		$self=new self($payload['domain_id'],$payload['domain_version'],$payload['source_fingerprint'],$payload['compiler_fingerprint'],$payload['artifacts'],$payload['key_id'],$payload['signature']);if(!hash_equals($self->digest(),$payload['digest'])){throw new \UnexpectedValueException('Stored domain compilation digest is invalid.');}return$self;
	}

	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_domain_compilation','version'=>self::VERSION]+$this->unsigned()+['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */ private function unsigned():array{return['domain_id'=>$this->domainId,'domain_version'=>$this->domainVersion,'source_fingerprint'=>$this->sourceFingerprint,'compiler_fingerprint'=>$this->compilerFingerprint,'artifacts'=>PanelOperationsGuard::canonical($this->artifacts)];}
}
