<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Membership query node; an empty positive set is deliberately unsatisfiable. */
final class PanelQueryIn implements PanelQueryExpression {
	/** @param list<mixed> $values */
	private function __construct(private readonly PanelQueryPath $field, private readonly array $values, private readonly bool $negated){}
	/** @param list<mixed> $values */
	public static function make(string|PanelQueryPath $field, array $values, bool $negated=false): self {
		if(!array_is_list($values)){ throw new \InvalidArgumentException('Panel membership values must be a list.'); }
		/** @var list<mixed> $values */
		$values=PanelQueryValue::normalize($values, 'membership values');
		return new self($field instanceof PanelQueryPath ? $field : PanelQueryPath::make($field), $values, $negated);
	}
	public function field(): PanelQueryPath { return $this->field; }
	/** @return list<mixed> */ public function values(): array { return $this->values; }
	public function negated(): bool { return $this->negated; }
	public function type(): string { return 'in'; }
	public function depth(): int { return 1; }
	public function fields(): array { return [$this->field->value()]; }
	public function operators(): array { return [$this->negated ? 'not_in' : 'in']; }
	/** @return array{type:string,field:string,values:list<mixed>,negated:bool,operator:string} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'field'=>$this->field->value(), 'values'=>$this->values, 'negated'=>$this->negated, 'operator'=>$this->negated ? 'not_in' : 'in']; }
}
