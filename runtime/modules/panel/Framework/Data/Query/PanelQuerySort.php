<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Validated deterministic sort descriptor, including explicit null placement. */
final class PanelQuerySort implements \JsonSerializable {
	private function __construct(private readonly PanelQueryPath $field, private readonly string $direction, private readonly string $nulls){}
	public static function make(string|PanelQueryPath $field, string $direction='asc', string $nulls='native'): self {
		$direction=strtolower(trim($direction)); $nulls=strtolower(trim($nulls));
		if(!in_array($direction, ['asc','desc'], true)){ throw new \InvalidArgumentException("Invalid Panel query sort direction '{$direction}'."); }
		if(!in_array($nulls, ['native','first','last'], true)){ throw new \InvalidArgumentException("Invalid Panel query null placement '{$nulls}'."); }
		return new self($field instanceof PanelQueryPath ? $field : PanelQueryPath::make($field), $direction, $nulls);
	}
	public function field(): PanelQueryPath { return $this->field; }
	public function direction(): string { return $this->direction; }
	public function nulls(): string { return $this->nulls; }
	/** @return array{type:string,field:string,direction:string,nulls:string} */
	public function jsonSerialize(): array { return ['type'=>'sort', 'field'=>$this->field->value(), 'direction'=>$this->direction, 'nulls'=>$this->nulls]; }
}
