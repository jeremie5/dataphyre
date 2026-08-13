<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Idempotent mutation receipt for one work graph event. */
final class PanelWorkReceipt implements \JsonSerializable {
	public function __construct(private readonly PanelWorkEvent $event,private readonly ?PanelWorkItem $item,private readonly bool $replayed=false){}
	public function event():PanelWorkEvent{return$this->event;}public function item():?PanelWorkItem{return$this->item;}public function replayed():bool{return$this->replayed;}
	public function asReplay():self{return$this->replayed?$this:new self($this->event,$this->item,true);}
	public function jsonSerialize():array{return['type'=>'panel_work_receipt','event'=>$this->event,'item'=>$this->item,'replayed'=>$this->replayed];}
}
