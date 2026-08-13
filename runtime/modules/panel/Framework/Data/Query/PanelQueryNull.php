<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Null-presence query node. */
final class PanelQueryNull implements PanelQueryExpression {
	private function __construct(private readonly PanelQueryPath $field, private readonly bool $negated){}
	public static function make(string|PanelQueryPath $field, bool $negated=false): self { return new self($field instanceof PanelQueryPath ? $field : PanelQueryPath::make($field), $negated); }
	public function field(): PanelQueryPath { return $this->field; }
	public function negated(): bool { return $this->negated; }
	public function type(): string { return 'null'; }
	public function depth(): int { return 1; }
	public function fields(): array { return [$this->field->value()]; }
	public function operators(): array { return [$this->negated ? 'not_null' : 'is_null']; }
	/** @return array{type:string,field:string,negated:bool,operator:string} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'field'=>$this->field->value(), 'negated'=>$this->negated, 'operator'=>$this->negated ? 'not_null' : 'is_null']; }
}
