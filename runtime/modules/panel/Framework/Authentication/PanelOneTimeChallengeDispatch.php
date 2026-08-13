<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One-time adapter response; raw code is only available through an explicit accessor. */
final class PanelOneTimeChallengeDispatch implements \JsonSerializable {
	/** @param array<string,mixed> $metadata */
	public function __construct(private readonly string $challengeId,private readonly string $code,private readonly string $channel,private readonly int $expiresAt,private readonly array $metadata=[]){ if($code===''||preg_match('/^\d{6,8}$/D',$code)!==1){throw new \InvalidArgumentException('One-time challenge code must contain 6-8 digits.');} }
	public function challengeId():string{return $this->challengeId;} public function code():string{return $this->code;} public function channel():string{return $this->channel;} public function expiresAt():int{return $this->expiresAt;}
	public function jsonSerialize():array{return ['type'=>'panel_one_time_challenge_dispatch','challenge_id'=>$this->challengeId,'channel'=>$this->channel,'expires_at'=>$this->expiresAt,'metadata'=>$this->metadata,'contains_one_time_material'=>true];}
}
