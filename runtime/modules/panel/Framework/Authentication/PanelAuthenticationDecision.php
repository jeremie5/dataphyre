<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Explainable, secret-free authentication verification result. */
final class PanelAuthenticationDecision implements \JsonSerializable {
	/** @param array<string,mixed> $evidence */
	public function __construct(private readonly bool $verified,private readonly string $reason,private readonly ?string $challengeId=null,private readonly int $authenticationLevel=0,private readonly array $evidence=[]){ }
	public function verified():bool{return $this->verified;} public function denied():bool{return !$this->verified;} public function reason():string{return $this->reason;} public function challengeId():?string{return $this->challengeId;} public function authenticationLevel():int{return $this->authenticationLevel;}
	public function jsonSerialize():array{return ['type'=>'panel_authentication_decision','verified'=>$this->verified,'reason'=>$this->reason,'challenge_id'=>$this->challengeId,'authentication_level'=>$this->authenticationLevel,'evidence'=>$this->evidence];}
}
