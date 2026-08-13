<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Safe public view of a persisted step-up challenge. */
final class PanelStepUpChallenge implements \JsonSerializable {
	private function __construct(private readonly PanelAuthenticationRecord $record){}
	public static function fromRecord(PanelAuthenticationRecord $record):self{if($record->collection()!=='challenges'){throw new \InvalidArgumentException('Not a challenge record.');}return new self($record);}
	public function id():string{return $this->record->id();} public function userId():string{return (string)$this->record->value('user_id');} public function method():string{return (string)$this->record->value('method');} public function purpose():string{return (string)$this->record->value('purpose');} public function status():string{return (string)$this->record->value('status');} public function attempts():int{return (int)$this->record->value('attempts');} public function maxAttempts():int{return (int)$this->record->value('max_attempts');} public function expiresAt():int{return (int)$this->record->value('expires_at');} public function consumedAt():?int{$value=$this->record->value('consumed_at');return $value===null?null:(int)$value;} public function expired(?int $now=null):bool{return ($now??time())>=$this->expiresAt();} public function pending(?int $now=null):bool{return $this->status()==='pending'&&!$this->expired($now);}
	public function jsonSerialize():array{return ['type'=>'panel_step_up_challenge','id'=>$this->id(),'user_id'=>$this->userId(),'method'=>$this->method(),'purpose'=>$this->purpose(),'status'=>$this->status(),'attempts'=>$this->attempts(),'max_attempts'=>$this->maxAttempts(),'expires_at'=>$this->expiresAt(),'consumed_at'=>$this->consumedAt(),'session_id'=>$this->record->value('session_id'),'required_level'=>(int)$this->record->value('required_level',1),'pending'=>$this->pending()];}
}
