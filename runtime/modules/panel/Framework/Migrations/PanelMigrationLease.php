<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Exclusive migration ownership proof; its bearer token is never serialized. */
final class PanelMigrationLease implements \JsonSerializable {
	private function __construct(private readonly string $scope,private readonly ?string $tenant,private readonly string $owner,private readonly string $token,private readonly int $fence,private readonly string $acquiredAt,private readonly string $renewedAt,private readonly string $expiresAt){}
	public static function make(string $scope,?string $tenant,string $owner,string $token,int $fence,mixed $acquiredAt,mixed $expiresAt,mixed $renewedAt=null):self {
		$scope=PanelMigrationIntegrity::identifier($scope,'scope');$tenant=PanelMigrationIntegrity::tenant($tenant);$owner=PanelMigrationIntegrity::identifier($owner,'lease owner');
		if(strlen($token)<32||strlen($token)>512||str_contains($token,"\0")){throw new \InvalidArgumentException('Panel migration lease tokens must contain 32-512 non-null bytes.');}if($fence<1){throw new \InvalidArgumentException('Panel migration lease fences must be positive.');}
		$acquired=self::time($acquiredAt);$renewed=self::time($renewedAt??$acquired);$expires=self::time($expiresAt);if(strtotime($renewed)<strtotime($acquired)||strtotime($expires)<=strtotime($renewed)){throw new \InvalidArgumentException('Panel migration lease timestamps must form an increasing window.');}
		return new self($scope,$tenant,$owner,$token,$fence,$acquired,$renewed,$expires);
	}
	public function scope():string{return$this->scope;}public function tenant():?string{return$this->tenant;}public function scopeKey():string{return$this->scope.'|'.($this->tenant??'*');}public function owner():string{return$this->owner;}public function token():string{return$this->token;}public function fence():int{return$this->fence;}public function acquiredAt():string{return$this->acquiredAt;}public function renewedAt():string{return$this->renewedAt;}public function expiresAt():string{return$this->expiresAt;}public function expired(mixed $at=null):bool{return strtotime($this->expiresAt)<=strtotime(self::time($at));}public function tokenFingerprint():string{return substr(hash('sha256',$this->token),0,16);}
	public function renewed(mixed $renewedAt,mixed $expiresAt):self{return self::make($this->scope,$this->tenant,$this->owner,$this->token,$this->fence,$this->acquiredAt,$expiresAt,$renewedAt);}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_migration_lease','manifest_version'=>1,'scope'=>$this->scope,'tenant'=>$this->tenant,'owner'=>$this->owner,'fence'=>$this->fence,'acquired_at'=>$this->acquiredAt,'renewed_at'=>$this->renewedAt,'expires_at'=>$this->expiresAt,'token_fingerprint'=>$this->tokenFingerprint()];}
	private static function time(mixed $value):string{try{if($value===null||$value===''){$date=new \DateTimeImmutable();}elseif($value instanceof \DateTimeInterface){$date=\DateTimeImmutable::createFromInterface($value);}elseif(is_int($value)){$date=new \DateTimeImmutable('@'.$value);}else{$date=new \DateTimeImmutable((string)$value);}return$date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);}catch(\Throwable){throw new \InvalidArgumentException('Invalid Panel migration lease timestamp.');}}
}
