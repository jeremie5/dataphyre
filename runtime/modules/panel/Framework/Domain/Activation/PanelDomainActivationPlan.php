<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Signed, expiring, compilation-bound change plan for one domain activation. */
final class PanelDomainActivationPlan implements \JsonSerializable {
	public const VERSION=1;
	private const OPERATIONS=['activate','rollback','deactivate','reconcile'];
	private readonly string $digest;

	/** @param list<array<string,mixed>> $migrationSteps */
	private function __construct(
		private readonly string $operation,
		private readonly string $domainId,
		private readonly ?string $fromVersion,
		private readonly ?string $fromDigest,
		private readonly ?string $toVersion,
		private readonly ?string $toDigest,
		private readonly ?string $materializationFingerprint,
		private readonly array $migrationSteps,
		private readonly bool $breaking,
		private readonly int $approvalCount,
		private readonly string $issuedAt,
		private readonly string $expiresAt,
		private readonly string $nonce,
		private readonly string $keyId,
		private readonly string $signature,
	){
		if(!in_array($operation,self::OPERATIONS,true)){throw new \InvalidArgumentException('Domain activation plan operation is invalid.');}
		PanelOperationsGuard::name($domainId,'domain activation plan domain id');
		self::versionDigest($fromVersion,$fromDigest,'source');self::versionDigest($toVersion,$toDigest,'target');
		if($operation==='deactivate'&&($toVersion!==null||$toDigest!==null||$materializationFingerprint!==null)){throw new \InvalidArgumentException('Domain deactivation plans cannot declare a target materialization.');}
		if($operation!=='deactivate'&&($toVersion===null||$toDigest===null||$materializationFingerprint===null)){throw new \InvalidArgumentException('Domain activation plans require a target materialization.');}
		if($materializationFingerprint!==null&&preg_match('/^[a-f0-9]{64}$/D',$materializationFingerprint)!==1){throw new \InvalidArgumentException('Domain activation plan materialization fingerprint is invalid.');}
		if(!array_is_list($migrationSteps)||count($migrationSteps)>20000){throw new \InvalidArgumentException('Domain activation migration steps are invalid.');}
		PanelOperationsGuard::canonical($migrationSteps);
		if($approvalCount<0||$approvalCount>16){throw new \InvalidArgumentException('Domain activation approval count is invalid.');}
		if(strcmp($issuedAt,$expiresAt)>=0){throw new \InvalidArgumentException('Domain activation plan expiration must follow issuance.');}
		PanelOperationsGuard::identifier($nonce,'domain activation plan nonce');PanelOperationsGuard::name($keyId,'domain activation plan key id');
		if(preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Domain activation plan signature is invalid.');}
		$this->digest=PanelOperationsGuard::digest($this->unsigned());
	}

	/** @param list<array<string,mixed>> $migrationSteps */
	public static function sign(string $operation,string $domainId,?string $fromVersion,?string $fromDigest,?string $toVersion,?string $toDigest,?string $materializationFingerprint,array $migrationSteps,bool $breaking,int $approvalCount,string|int|\DateTimeInterface $issuedAt,string|int|\DateTimeInterface $expiresAt,string $nonce,string $keyId,string $key):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Domain activation plan signing keys require at least 32 bytes.');}
		$issued=PanelOperationsGuard::instant($issuedAt);$expires=PanelOperationsGuard::instant($expiresAt);
		$prototype=new self($operation,$domainId,$fromVersion,$fromDigest,$toVersion,$toDigest,$materializationFingerprint,PanelOperationsGuard::canonical($migrationSteps),$breaking,$approvalCount,$issued,$expires,$nonce,$keyId,str_repeat('0',64));
		return new self($operation,$domainId,$fromVersion,$fromDigest,$toVersion,$toDigest,$materializationFingerprint,$prototype->migrationSteps,$breaking,$approvalCount,$issued,$expires,$nonce,$keyId,hash_hmac('sha256',$prototype->digest,$key));
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self {
		$required=['type','schema_version','api_version','version','operation','domain_id','from_version','from_digest','to_version','to_digest','materialization_fingerprint','migration_steps','breaking','approval_count','issued_at','expires_at','nonce','digest','key_id','signature'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_domain_activation_plan'||($payload['version']??null)!==self::VERSION||!is_string($payload['operation']??null)||!is_string($payload['domain_id']??null)||(!is_string($payload['from_version'])&&$payload['from_version']!==null)||(!is_string($payload['from_digest'])&&$payload['from_digest']!==null)||(!is_string($payload['to_version'])&&$payload['to_version']!==null)||(!is_string($payload['to_digest'])&&$payload['to_digest']!==null)||(!is_string($payload['materialization_fingerprint'])&&$payload['materialization_fingerprint']!==null)||!is_array($payload['migration_steps']??null)||!is_bool($payload['breaking']??null)||!is_int($payload['approval_count']??null)||!is_string($payload['issued_at']??null)||!is_string($payload['expires_at']??null)||!is_string($payload['nonce']??null)||!is_string($payload['digest']??null)||!is_string($payload['key_id']??null)||!is_string($payload['signature']??null)){throw new \UnexpectedValueException('Stored domain activation plan shape is invalid.');}
		$self=new self($payload['operation'],$payload['domain_id'],$payload['from_version'],$payload['from_digest'],$payload['to_version'],$payload['to_digest'],$payload['materialization_fingerprint'],$payload['migration_steps'],$payload['breaking'],$payload['approval_count'],PanelOperationsGuard::instant($payload['issued_at']),PanelOperationsGuard::instant($payload['expires_at']),$payload['nonce'],$payload['key_id'],$payload['signature']);
		if(!hash_equals($self->digest,$payload['digest'])){throw new \UnexpectedValueException('Stored domain activation plan digest is invalid.');}return$self;
	}

	/** @param array<string,string> $keys */
	public function verify(array $keys,string|int|\DateTimeInterface $at):bool {$key=$keys[$this->keyId]??null;$instant=PanelOperationsGuard::instant($at);return is_string($key)&&strlen($key)>=32&&strcmp($instant,$this->issuedAt)>=0&&strcmp($instant,$this->expiresAt)<0&&hash_equals($this->signature,hash_hmac('sha256',$this->digest,$key));}
	public function operation():string{return$this->operation;}public function domainId():string{return$this->domainId;}public function fromVersion():?string{return$this->fromVersion;}public function fromDigest():?string{return$this->fromDigest;}public function toVersion():?string{return$this->toVersion;}public function toDigest():?string{return$this->toDigest;}public function materializationFingerprint():?string{return$this->materializationFingerprint;}/** @return list<array<string,mixed>> */public function migrationSteps():array{return$this->migrationSteps;}public function breaking():bool{return$this->breaking;}public function approvalCount():int{return$this->approvalCount;}public function issuedAt():string{return$this->issuedAt;}public function expiresAt():string{return$this->expiresAt;}public function digest():string{return$this->digest;}
	public function fingerprint():string{return PanelOperationsGuard::digest(['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_domain_activation_plan','version'=>self::VERSION]+$this->unsigned()+['digest'=>$this->digest,'key_id'=>$this->keyId,'signature'=>$this->signature]);}
	/** @return array<string,mixed> */private function unsigned():array{return['operation'=>$this->operation,'domain_id'=>$this->domainId,'from_version'=>$this->fromVersion,'from_digest'=>$this->fromDigest,'to_version'=>$this->toVersion,'to_digest'=>$this->toDigest,'materialization_fingerprint'=>$this->materializationFingerprint,'migration_steps'=>$this->migrationSteps,'breaking'=>$this->breaking,'approval_count'=>$this->approvalCount,'issued_at'=>$this->issuedAt,'expires_at'=>$this->expiresAt,'nonce'=>$this->nonce];}
	private static function versionDigest(?string $version,?string $digest,string $label):void {if(($version===null)!==($digest===null)){throw new \InvalidArgumentException('Domain activation plan '.$label.' version and digest are incomplete.');}if($version!==null&&($version===''||strlen($version)>64)){throw new \InvalidArgumentException('Domain activation plan '.$label.' version is invalid.');}if($digest!==null&&preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Domain activation plan '.$label.' digest is invalid.');}}
}
