<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Deterministic parser/serializer and legacy projection for expression nodes. */
final class PanelQueryExpressionCodec {
	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): PanelQueryExpression {
		$type=strtolower(trim((string)($data['type'] ?? '')));
		$operator=strtolower(trim(str_replace(' ', '_', (string)($data['operator'] ?? 'eq'))));
		if($type==='' && isset($data['children'])){ $type='group'; }
		if($type==='' && isset($data['relation'])){ $type='relation'; }
		if($type==='' && isset($data['field'])){
			$type=match($operator){ 'is_null','not_null'=>'null', 'between','not_between'=>'between', 'in','not_in'=>'in', default=>'comparison' };
		}
		return match($type){
			'group'=>self::group($data),
			'comparison','compare'=>PanelQueryComparison::make((string)($data['field'] ?? ''), $operator, $data['value'] ?? null),
			'null'=>PanelQueryNull::make((string)($data['field'] ?? ''), ($data['negated'] ?? false)===true || $operator==='not_null'),
			'between'=>PanelQueryBetween::make(
				(string)($data['field'] ?? ''),
				$data['from'] ?? (is_array($data['value'] ?? null) ? ($data['value'][0] ?? null) : null),
				$data['to'] ?? (is_array($data['value'] ?? null) ? ($data['value'][1] ?? null) : null),
				($data['negated'] ?? false)===true || $operator==='not_between'
			),
			'in','membership'=>PanelQueryIn::make(
				(string)($data['field'] ?? ''),
				is_array($data['values'] ?? null) ? array_values($data['values']) : (is_array($data['value'] ?? null) ? array_values($data['value']) : throw new \InvalidArgumentException('Panel membership expressions require values.')),
				($data['negated'] ?? false)===true || $operator==='not_in'
			),
			'relation','nested'=>PanelQueryRelation::make(
				(string)($data['relation'] ?? $data['path'] ?? ''),
				self::fromArray(is_array($data['expression'] ?? null) ? $data['expression'] : (is_array($data['where'] ?? null) ? $data['where'] : throw new \InvalidArgumentException('Panel relation expressions require a nested expression.'))),
				(string)($data['quantifier'] ?? 'any'),
				is_array($data['scope'] ?? null) ? self::fromArray($data['scope']) : null
			),
			default=>throw new \InvalidArgumentException("Unknown Panel query expression type '{$type}'."),
		};
	}

	public static function fromLegacyFilter(string $field, string $operator, mixed $value=null): PanelQueryExpression {
		$operator=strtolower(trim(str_replace(' ', '_', $operator)));
		return match($operator){
			'is_null'=>PanelQueryNull::make($field),
			'not_null'=>PanelQueryNull::make($field, true),
			'between','not_between'=>is_array($value) && count($value)===2
				? PanelQueryBetween::make($field, array_values($value)[0], array_values($value)[1], $operator==='not_between')
				: throw new \InvalidArgumentException("Panel data '{$operator}' filter requires exactly two values."),
			'in','not_in'=>is_array($value)
				? PanelQueryIn::make($field, array_values($value), $operator==='not_in')
				: throw new \InvalidArgumentException("Panel data '{$operator}' filter requires an array value."),
			default=>PanelQueryComparison::make($field, $operator, $value),
		};
	}

	/** Returns a compatibility list only when grouping can be represented by the historical left-fold format. @return ?list<array{field:string,operator:string,value:mixed,boolean:string}> */
	public static function legacyFilters(?PanelQueryExpression $expression): ?array {
		if($expression===null){ return []; }
		$filters=self::flattenLegacy($expression);
		if($filters===null){ return null; }
		if($filters!==[]){ $filters[0]['boolean']='and'; }
		return $filters;
	}

	/** @return list<PanelQueryExpression> */
	public static function nodes(?PanelQueryExpression $expression): array {
		if($expression===null){ return []; }
		$nodes=[$expression];
		if($expression instanceof PanelQueryGroup){ foreach($expression->children() as $child){ array_push($nodes, ...self::nodes($child)); } }
		elseif($expression instanceof PanelQueryRelation){
			array_push($nodes, ...self::nodes($expression->expression()));
			if($expression->scope()!==null){ array_push($nodes, ...self::nodes($expression->scope())); }
		}
		return $nodes;
	}

	public static function containsRelations(?PanelQueryExpression $expression): bool {
		foreach(self::nodes($expression) as $node){ if($node instanceof PanelQueryRelation){ return true; } }
		return false;
	}

	/** @param array<string,mixed> $data */
	private static function group(array $data): PanelQueryExpression {
		$children=$data['children'] ?? $data['expressions'] ?? null;
		if(!is_array($children) || !array_is_list($children)){ throw new \InvalidArgumentException('Panel query group children must be a list.'); }
		$nodes=[];
		foreach($children as $child){
			if(!is_array($child)){ throw new \InvalidArgumentException('Panel query group children must be expression objects.'); }
			$nodes[]=self::fromArray($child);
		}
		return PanelQueryGroup::make((string)($data['boolean'] ?? $data['operator'] ?? 'and'), $nodes);
	}

	/** @return ?list<array{field:string,operator:string,value:mixed,boolean:string}> */
	private static function flattenLegacy(PanelQueryExpression $expression): ?array {
		$leaf=self::legacyLeaf($expression);
		if($leaf!==null){ return [$leaf+['boolean'=>'and']]; }
		if(!$expression instanceof PanelQueryGroup){ return null; }
		$out=[];
		foreach($expression->children() as $index=>$child){
			$nested=self::flattenLegacy($child);
			if($nested===null){ return null; }
			if($index>0 && count($nested)>1 && $expression->boolean()!==($child instanceof PanelQueryGroup ? $child->boolean() : $expression->boolean())){ return null; }
			foreach($nested as $nestedIndex=>$filter){
				if($index>0 && $nestedIndex===0){ $filter['boolean']=$expression->boolean(); }
				$out[]=$filter;
			}
		}
		return $out;
	}

	/** @return ?array{field:string,operator:string,value:mixed} */
	private static function legacyLeaf(PanelQueryExpression $expression): ?array {
		if($expression instanceof PanelQueryComparison){ return ['field'=>$expression->field()->value(), 'operator'=>$expression->operator(), 'value'=>$expression->value()]; }
		if($expression instanceof PanelQueryNull){ return ['field'=>$expression->field()->value(), 'operator'=>$expression->negated() ? 'not_null' : 'is_null', 'value'=>null]; }
		if($expression instanceof PanelQueryBetween){ return ['field'=>$expression->field()->value(), 'operator'=>$expression->negated() ? 'not_between' : 'between', 'value'=>[$expression->from(), $expression->to()]]; }
		if($expression instanceof PanelQueryIn){ return ['field'=>$expression->field()->value(), 'operator'=>$expression->negated() ? 'not_in' : 'in', 'value'=>$expression->values()]; }
		return null;
	}
}
