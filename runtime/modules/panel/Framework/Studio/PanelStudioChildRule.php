<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable cardinality rule for one child kind in a trusted Studio schema. */
final class PanelStudioChildRule implements \JsonSerializable {
	public function __construct(private readonly string $kind,private readonly int $minimum=0,private readonly int $maximum=512){
		if(!in_array($kind,PanelStudioDefinition::KINDS,true)){throw new \InvalidArgumentException('Studio child rules require an allow-listed component kind.');}
		if($minimum<0||$maximum<$minimum||$maximum>PanelStudioDefinition::MAX_NODES){throw new \InvalidArgumentException('Studio child rule cardinality is invalid.');}
	}
	public static function make(string $kind,int $minimum=0,int $maximum=512):self{return new self($kind,$minimum,$maximum);}
	public function kind():string{return$this->kind;}
	public function minimum():int{return$this->minimum;}
	public function maximum():int{return$this->maximum;}
	public function jsonSerialize():array{return['kind'=>$this->kind,'minimum'=>$this->minimum,'maximum'=>$this->maximum];}
}
