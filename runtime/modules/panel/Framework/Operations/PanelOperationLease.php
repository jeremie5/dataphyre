<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable worker ownership proof with a monotonic fencing number. */
final class PanelOperationLease implements \JsonSerializable {
	private function __construct(
		private readonly string $operationId,
		private readonly string $worker,
		private readonly string $token,
		private readonly int $fence,
		private readonly string $acquiredAt,
		private readonly string $renewedAt,
		private readonly string $expiresAt
	){}

	public static function make(string $operationId,string $worker,string $token,int $fence,mixed $acquiredAt,mixed $expiresAt,mixed $renewedAt=null):self {
		$operationId=self::identifier($operationId,'operation id');
		$worker=self::identifier($worker,'worker id');
		if(strlen($token)<32 || strlen($token)>512 || str_contains($token,"\0")){ throw new \InvalidArgumentException('Panel operation lease tokens must contain 32-512 non-null bytes.'); }
		if($fence<1){ throw new \InvalidArgumentException('Panel operation lease fences must be positive.'); }
		$acquired=self::time($acquiredAt); $renewed=self::time($renewedAt ?? $acquired); $expires=self::time($expiresAt);
		if(strtotime($renewed)<strtotime($acquired) || strtotime($expires)<=strtotime($renewed)){ throw new \InvalidArgumentException('Panel operation lease timestamps must form an increasing window.'); }
		return new self($operationId,$worker,$token,$fence,$acquired,$renewed,$expires);
	}

	public function operationId():string { return $this->operationId; }
	public function worker():string { return $this->worker; }
	/** Sensitive bearer proof. Never serialize or log this value. */
	public function token():string { return $this->token; }
	public function tokenFingerprint():string { return substr(hash('sha256',$this->token),0,16); }
	public function fence():int { return $this->fence; }
	public function acquiredAt():string { return $this->acquiredAt; }
	public function renewedAt():string { return $this->renewedAt; }
	public function expiresAt():string { return $this->expiresAt; }
	public function expired(mixed $at=null):bool { return strtotime($this->expiresAt)<=strtotime(self::time($at)); }

	public function renewed(mixed $renewedAt,mixed $expiresAt):self { return self::make($this->operationId,$this->worker,$this->token,$this->fence,$this->acquiredAt,$expiresAt,$renewedAt); }

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return ['type'=>'panel_operation_lease','schema_version'=>1,'operation_id'=>$this->operationId,'worker'=>$this->worker,'fence'=>$this->fence,'acquired_at'=>$this->acquiredAt,'renewed_at'=>$this->renewedAt,'expires_at'=>$this->expiresAt,'token_fingerprint'=>$this->tokenFingerprint()];
	}

	private static function identifier(string $value,string $label):string {
		$value=trim($value);
		if($value==='' || strlen($value)>190 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D',$value)!==1){ throw new \InvalidArgumentException("Panel operation lease {$label} must be a safe identifier."); }
		return $value;
	}

	private static function time(mixed $value):string {
		if($value===null || $value===''){ return gmdate(DATE_ATOM); }
		try{
			if($value instanceof \DateTimeInterface){ $date=\DateTimeImmutable::createFromInterface($value); }
			elseif(is_int($value)){ $date=(new \DateTimeImmutable('@'.$value)); }
			else{ $date=new \DateTimeImmutable((string)$value); }
			return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
		}catch(\Throwable){ throw new \InvalidArgumentException('Invalid Panel operation lease timestamp.'); }
	}
}
