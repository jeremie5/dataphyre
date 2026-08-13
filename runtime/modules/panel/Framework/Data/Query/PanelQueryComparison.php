<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Scalar/string comparison query node. */
final class PanelQueryComparison implements PanelQueryExpression {
	public const OPERATORS=['eq','neq','gt','gte','lt','lte','contains','not_contains','starts_with','ends_with'];
	private const ALIASES=['='=>'eq','=='=>'eq','!='=>'neq','<>'=>'neq','>'=>'gt','>='=>'gte','<'=>'lt','<='=>'lte','not contains'=>'not_contains','starts with'=>'starts_with','ends with'=>'ends_with'];

	private function __construct(private readonly PanelQueryPath $field, private readonly string $operator, private readonly mixed $value){}

	public static function make(string|PanelQueryPath $field, string $operator, mixed $value): self {
		$field=$field instanceof PanelQueryPath ? $field : PanelQueryPath::make($field);
		$operator=strtolower(trim($operator));
		$operator=self::ALIASES[$operator] ?? str_replace(' ', '_', $operator);
		if(!in_array($operator, self::OPERATORS, true)){ throw new \InvalidArgumentException("Unsupported Panel comparison operator '{$operator}'."); }
		return new self($field, $operator, PanelQueryValue::normalize($value, 'comparison value'));
	}

	public function field(): PanelQueryPath { return $this->field; }
	public function operator(): string { return $this->operator; }
	public function value(): mixed { return $this->value; }
	public function type(): string { return 'comparison'; }
	public function depth(): int { return 1; }
	public function fields(): array { return [$this->field->value()]; }
	public function operators(): array { return [$this->operator]; }
	/** @return array{type:string,field:string,operator:string,value:mixed} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'field'=>$this->field->value(), 'operator'=>$this->operator, 'value'=>$this->value]; }
}
