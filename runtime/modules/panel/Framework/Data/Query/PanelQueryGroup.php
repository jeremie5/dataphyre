<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Parenthesized AND/OR expression group. */
final class PanelQueryGroup implements PanelQueryExpression {
	/** @param non-empty-list<PanelQueryExpression> $children */
	private function __construct(private readonly string $boolean, private readonly array $children){}

	/** @param list<PanelQueryExpression> $children */
	public static function make(string $boolean, array $children): PanelQueryExpression {
		$boolean=strtolower(trim($boolean));
		if(!in_array($boolean, ['and','or'], true)){ throw new \InvalidArgumentException('Panel query group boolean must be and or or.'); }
		$normalized=[];
		foreach($children as $child){
			if(!$child instanceof PanelQueryExpression){ throw new \InvalidArgumentException('Panel query groups may contain only expression nodes.'); }
			if($child instanceof self && $child->boolean()===$boolean){ array_push($normalized, ...$child->children()); }
			else{ $normalized[]=$child; }
		}
		if($normalized===[]){ throw new \InvalidArgumentException('Panel query groups require at least one expression.'); }
		$unique=[]; $seen=[];
		foreach($normalized as $child){
			$key=PanelQueryValue::stableJson($child->jsonSerialize());
			if(isset($seen[$key])){ continue; }
			$seen[$key]=true; $unique[]=$child;
		}
		if(count($unique)>100){ throw new \LengthException('Panel query groups support at most 100 expressions.'); }
		if(count($unique)===1){ return $unique[0]; }
		/** @var non-empty-list<PanelQueryExpression> $unique */
		$group=new self($boolean, $unique);
		if($group->depth()>16){ throw new \LengthException('Panel query expression depth exceeds 16.'); }
		return $group;
	}

	/** @param PanelQueryExpression ...$children */ public static function all(PanelQueryExpression ...$children): PanelQueryExpression { return self::make('and', $children); }
	/** @param PanelQueryExpression ...$children */ public static function any(PanelQueryExpression ...$children): PanelQueryExpression { return self::make('or', $children); }
	public function boolean(): string { return $this->boolean; }
	/** @return non-empty-list<PanelQueryExpression> */ public function children(): array { return $this->children; }
	public function type(): string { return 'group'; }
	public function depth(): int { return 1+max(array_map(static fn(PanelQueryExpression $child): int=>$child->depth(), $this->children)); }
	public function fields(): array { return array_values(array_unique(array_merge(...array_map(static fn(PanelQueryExpression $child): array=>$child->fields(), $this->children)))); }
	public function operators(): array { return array_values(array_unique([$this->boolean, ...array_merge(...array_map(static fn(PanelQueryExpression $child): array=>$child->operators(), $this->children))])); }
	/** @return array{type:string,boolean:string,children:list<array<string,mixed>>} */
	public function jsonSerialize(): array { return ['type'=>$this->type(), 'boolean'=>$this->boolean, 'children'=>array_map(static fn(PanelQueryExpression $child): array=>$child->jsonSerialize(), $this->children)]; }
}
