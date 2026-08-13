<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Inclusive range query node. */
final class PanelQueryBetween implements PanelQueryExpression {
	private function __construct(private readonly PanelQueryPath $field, private readonly mixed $from, private readonly mixed $to, private readonly bool $negated){}
	public static function make(string|PanelQueryPath $field, mixed $from, mixed $to, bool $negated=false): self {
		return new self($field instanceof PanelQueryPath ? $field : PanelQueryPath::make($field), PanelQueryValue::normalize($from, 'range lower bound'), PanelQueryValue::normalize($to, 'range upper bound'), $negated);
	}
	public function field(): PanelQueryPath { return $this->field; }
	public function from(): mixed { return $this->from; }
	public function to(): mixed { return $this->to; }
	public function negated(): bool { return $this->negated; }
	public function type(): string { return 'between'; }
	public function depth(): int { return 1; }
	public function fields(): array { return [$this->field->value()]; }
	public function operators(): array { return [$this->negated ? 'not_between' : 'between']; }
	/** @return array{type:string,field:string,from:mixed,to:mixed,negated:bool,operator:string} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'field'=>$this->field->value(), 'from'=>$this->from, 'to'=>$this->to, 'negated'=>$this->negated, 'operator'=>$this->negated ? 'not_between' : 'between']; }
}
