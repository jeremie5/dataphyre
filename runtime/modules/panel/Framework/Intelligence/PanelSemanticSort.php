<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Typed ordering of one requested dimension or metric. */
final class PanelSemanticSort implements \JsonSerializable {
	public function __construct(private readonly string $target,private readonly string $direction='asc',private readonly string $nulls='last'){PanelOperationsGuard::name($target,'semantic sort target');if(!in_array($direction,['asc','desc'],true)||!in_array($nulls,['first','last'],true)){throw new \InvalidArgumentException('Semantic sort direction or null placement is invalid.');}}
	public static function asc(string $target,string $nulls='last'):self{return new self($target,'asc',$nulls);}public static function desc(string $target,string $nulls='last'):self{return new self($target,'desc',$nulls);}
	public function target():string{return$this->target;}public function direction():string{return$this->direction;}public function nulls():string{return$this->nulls;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return['type'=>'panel_semantic_sort','version'=>1,'target'=>$this->target,'direction'=>$this->direction,'nulls'=>$this->nulls];}
}
