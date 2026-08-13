<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Safe transport-facing replay-ledger failure. */
final class PanelLocalFirstReplayException extends \RuntimeException implements \JsonSerializable {
	public function __construct(private readonly string $publicCode,string $message,private readonly bool $retryable){if(preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$publicCode)!==1){throw new \InvalidArgumentException('Local-first replay error code is invalid.');}parent::__construct($message);}
	public function publicCode():string{return$this->publicCode;}public function retryable():bool{return$this->retryable;}public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_replay_error','version'=>1,'code'=>$this->publicCode,'message'=>$this->getMessage(),'retryable'=>$this->retryable]);}
}
