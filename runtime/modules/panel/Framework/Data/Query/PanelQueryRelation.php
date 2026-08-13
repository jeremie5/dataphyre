<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Explicit, authorizable nested-resource predicate. */
final class PanelQueryRelation implements PanelQueryExpression {
	private function __construct(private readonly string $relation, private readonly PanelQueryExpression $expression, private readonly string $quantifier, private readonly ?PanelQueryExpression $scope){}

	public static function make(string|PanelQueryPath $relation, PanelQueryExpression $expression, string $quantifier='any', ?PanelQueryExpression $scope=null): self {
		$path=$relation instanceof PanelQueryPath ? $relation : PanelQueryPath::make($relation);
		$quantifier=strtolower(trim($quantifier));
		if(!in_array($quantifier, ['any','all','none'], true)){ throw new \InvalidArgumentException('Panel relation query quantifier must be any, all, or none.'); }
		$tail=$path->tail();
		if($tail!==null){ $expression=self::make($tail, $expression, $quantifier, $scope); $quantifier='any'; $scope=null; }
		$node=new self($path->head(), $expression, $quantifier, $scope);
		if($node->depth()>16){ throw new \LengthException('Panel query expression depth exceeds 16.'); }
		return $node;
	}

	public function relation(): string { return $this->relation; }
	public function expression(): PanelQueryExpression { return $this->expression; }
	public function quantifier(): string { return $this->quantifier; }
	public function scope(): ?PanelQueryExpression { return $this->scope; }
	public function withScope(?PanelQueryExpression $scope): self { return self::make($this->relation, $this->expression, $this->quantifier, $scope); }
	public function type(): string { return 'relation'; }
	public function depth(): int { return 1+max($this->expression->depth(), $this->scope?->depth() ?? 0); }
	public function fields(): array {
		$fields=$this->expression->fields();
		if($this->scope!==null){ array_push($fields, ...$this->scope->fields()); }
		return array_map(fn(string $field): string=>$this->relation.'.'.$field, array_values(array_unique($fields)));
	}
	public function operators(): array { return array_values(array_unique(['relation:'.$this->quantifier, ...$this->expression->operators(), ...($this->scope?->operators() ?? [])])); }
	/** @return array{type:string,relation:string,quantifier:string,expression:array<string,mixed>,scope:?array<string,mixed>} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'relation'=>$this->relation, 'quantifier'=>$this->quantifier, 'expression'=>$this->expression->jsonSerialize(), 'scope'=>$this->scope?->jsonSerialize()]; }
}
