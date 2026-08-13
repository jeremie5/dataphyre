<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Safe trusted-device inventory record. */
final class PanelTrustedDevice implements \JsonSerializable {
	private function __construct(private readonly PanelAuthenticationRecord $record){}
	public static function fromRecord(PanelAuthenticationRecord $record):self{if($record->collection()!=='devices'){throw new \InvalidArgumentException('Not a device record.');}return new self($record);}
	public function id():string{return $this->record->id();} public function userId():string{return (string)$this->record->value('user_id');} public function label():string{return (string)$this->record->value('label');} public function expiresAt():int{return (int)$this->record->value('expires_at');} public function revokedAt():?int{$v=$this->record->value('revoked_at');return $v===null?null:(int)$v;} public function active(?int $now=null):bool{return $this->revokedAt()===null&&($now??time())<$this->expiresAt();}
	public function jsonSerialize():array{return ['type'=>'panel_trusted_device','id'=>$this->id(),'user_id'=>$this->userId(),'label'=>$this->label(),'created_at'=>$this->record->createdAt(),'last_seen_at'=>(int)$this->record->value('last_seen_at'),'expires_at'=>$this->expiresAt(),'revoked_at'=>$this->revokedAt(),'active'=>$this->active(),'metadata'=>$this->record->value('metadata',[])];}
}
