<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Safe authentication-session inventory and authorization state. */
final class PanelAuthenticationSession implements \JsonSerializable {
	private function __construct(private readonly PanelAuthenticationRecord $record){}
	public static function fromRecord(PanelAuthenticationRecord $record):self{if($record->collection()!=='sessions'){throw new \InvalidArgumentException('Not a session record.');}return new self($record);}
	public function id():string{return $this->record->id();} public function userId():string{return (string)$this->record->value('user_id');} public function deviceId():?string{$v=$this->record->value('device_id');return $v===null?null:(string)$v;} public function authenticationLevel():int{return (int)$this->record->value('authentication_level',1);} public function expiresAt():int{return (int)$this->record->value('expires_at');} public function revokedAt():?int{$v=$this->record->value('revoked_at');return $v===null?null:(int)$v;} public function active(?int $now=null):bool{return $this->revokedAt()===null&&($now??time())<$this->expiresAt();}
	public function jsonSerialize():array{return ['type'=>'panel_authentication_session','id'=>$this->id(),'user_id'=>$this->userId(),'device_id'=>$this->deviceId(),'authentication_level'=>$this->authenticationLevel(),'created_at'=>$this->record->createdAt(),'last_seen_at'=>(int)$this->record->value('last_seen_at'),'expires_at'=>$this->expiresAt(),'step_up_at'=>$this->record->value('step_up_at'),'revoked_at'=>$this->revokedAt(),'active'=>$this->active(),'metadata'=>$this->record->value('metadata',[])];}
}
