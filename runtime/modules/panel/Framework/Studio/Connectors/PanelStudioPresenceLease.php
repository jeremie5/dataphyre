<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One host-held Studio presence lease whose bearer proof is never serialized. */
final class PanelStudioPresenceLease implements \JsonSerializable {
	public function __construct(
		private readonly string $leaseId,
		private readonly string $leaseToken,
		private readonly string $expiresAt
	){
		if(preg_match('/^[a-f0-9]{24}$/D',$leaseId)!==1){throw new \InvalidArgumentException('Studio presence lease id is invalid.');}
		if(preg_match('/^[a-f0-9]{48}$/D',$leaseToken)!==1){throw new \InvalidArgumentException('Studio presence lease token is invalid.');}
		try{new \DateTimeImmutable($expiresAt);}catch(\Throwable $error){throw new \InvalidArgumentException('Studio presence lease expiry is invalid.',0,$error);}
	}
	public function leaseId():string{return$this->leaseId;}
	public function leaseToken():string{return$this->leaseToken;}
	public function expiresAt():string{return$this->expiresAt;}
	/** @return array<string,mixed> */
	public function jsonSerialize():array{return['type'=>'panel_studio_presence_lease','version'=>1,'lease_id'=>$this->leaseId,'expires_at'=>$this->expiresAt,'lease_token_serialized'=>false];}
}
