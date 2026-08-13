<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable normalized capability contract and query preflight gate. */
final class PanelQueryCapabilities implements \JsonSerializable {
	public const OPERATORS=['eq','neq','gt','gte','lt','lte','in','not_in','contains','not_contains','starts_with','ends_with','between','not_between','is_null','not_null'];
	/** @param list<string> $operators @param list<string> $groups @param list<string> $nullSorts @param array<string,mixed> $raw */
	private function __construct(
		private readonly string $adapter,
		private readonly bool $filters,
		private readonly bool $expressions,
		private readonly array $operators,
		private readonly array $groups,
		private readonly bool $relations,
		private readonly int $relationDepth,
		private readonly bool $sorts,
		private readonly array $nullSorts,
		private readonly bool $legacyFilters,
		private readonly array $raw
	){}

	/** @param array<string,mixed> $capabilities */
	public static function fromArray(array $capabilities): self {
		$filters=($capabilities['filters'] ?? false)===true;
		$operators=self::names($capabilities['operators'] ?? ($filters ? self::OPERATORS : []), self::OPERATORS);
		$groups=self::names($capabilities['groups'] ?? (($capabilities['query_expression'] ?? false)===true ? ['and','or'] : []), ['and','or']);
		$nullSorts=self::names($capabilities['sort_nulls'] ?? ['native'], ['native','first','last']);
		return new self(
			(string)($capabilities['adapter'] ?? 'unknown'), $filters,
			($capabilities['query_expression'] ?? false)===true,
			$operators, $groups, ($capabilities['relations'] ?? false)===true,
			max(0, min(16, (int)($capabilities['relation_depth'] ?? 0))),
			($capabilities['sorts'] ?? false)===true, $nullSorts,
			($capabilities['legacy_filters'] ?? true)===true, $capabilities
		);
	}

	/** @return array<string,mixed> */
	public static function full(string $adapter): array {
		return [
			'adapter'=>$adapter, 'filters'=>true, 'query_expression'=>true, 'expression_version'=>2,
			'operators'=>self::OPERATORS, 'groups'=>['and','or'], 'relations'=>true,
			'relation_depth'=>16, 'sorts'=>true, 'sort_nulls'=>['native','first','last'], 'legacy_filters'=>true,
		];
	}

	/** Conservative compatibility contract for application callbacks/repositories that have not opted into v2 AST handling. @return array<string,mixed> */
	public static function legacy(string $adapter): array {
		return [
			'adapter'=>$adapter, 'filters'=>true, 'query_expression'=>false, 'expression_version'=>1,
			'operators'=>self::OPERATORS, 'groups'=>[], 'relations'=>false,
			'relation_depth'=>0, 'sorts'=>true, 'sort_nulls'=>['native'], 'legacy_filters'=>true,
		];
	}

	public function assertSupports(PanelDataQuery $query): void {
		$unsupported=[]; $expression=$query->expression();
		if($expression!==null){
			if(!$this->filters){ $unsupported[]='filters'; }
			$legacy=PanelQueryExpressionCodec::legacyFilters($expression);
			if(!$this->expressions && $legacy===null){ $unsupported[]='query_expression'; }
			foreach(PanelQueryExpressionCodec::nodes($expression) as $node){
				if($node instanceof PanelQueryGroup){
					if(!in_array($node->boolean(), $this->groups, true) && (!$this->legacyFilters || $legacy===null)){ $unsupported[]='group:'.$node->boolean(); }
				}
				elseif($node instanceof PanelQueryRelation){
					if(!$this->relations){ $unsupported[]='relations'; }
				}
				else{
					foreach($node->operators() as $operator){ if(!in_array($operator, ['and','or'], true) && !in_array($operator, $this->operators, true)){ $unsupported[]='operator:'.$operator; } }
				}
			}
			if(self::relationDepth($expression)>$this->relationDepth){ $unsupported[]='relation_depth'; }
		}
		if($query->sortNodes()!==[] && !$this->sorts){ $unsupported[]='sorts'; }
		foreach($query->sortNodes() as $sort){ if(!in_array($sort->nulls(), $this->nullSorts, true)){ $unsupported[]='sort_nulls:'.$sort->nulls(); } }
		/*
		 * These capabilities predate the v2 expression contract and many legacy
		 * adapters omit them. An omitted key therefore remains "not negotiated"
		 * for compatibility, while an explicit false is a binding denial. New
		 * production adapters must publish every key they negotiate.
		 */
		$this->assertOptionalFeature($unsupported, 'search', $query->searchTerm()!==null);
		$this->assertOptionalFeature($unsupported, 'select', $query->selectedFields()!==[]);
		$this->assertOptionalFeature($unsupported, 'include', $query->includes()!==[]);
		$this->assertOptionalFeature($unsupported, 'aggregates', $query->aggregateList()!==[]);
		$this->assertOptionalFeature($unsupported, 'cursor', $query->cursorToken()!==null);
		$this->assertOptionalFeature($unsupported, 'offset', $query->offsetValue()>0);
		$this->assertOptionalFeature($unsupported, 'tenant', $query->tenantKey()!==null);
		$this->assertOptionalFeature($unsupported, 'authorization', $query->authorizationMetadata()!==[]);
		if(array_key_exists('max_limit', $this->raw)){
			$maximum=$this->raw['max_limit'];
			if(!is_int($maximum) || $maximum<1 || $maximum>10000 || $query->limitValue()>$maximum){ $unsupported[]='max_limit'; }
		}
		$unsupported=array_values(array_unique($unsupported));
		if($unsupported!==[]){ throw new PanelUnsupportedQueryException($unsupported, $this->jsonSerialize()); }
	}

	/** @param list<string> $unsupported */
	private function assertOptionalFeature(array &$unsupported, string $capability, bool $used): void {
		if($used && array_key_exists($capability, $this->raw) && $this->raw[$capability]!==true){ $unsupported[]=$capability; }
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_query_capabilities', 'adapter'=>$this->adapter, 'filters'=>$this->filters,
			'query_expression'=>$this->expressions, 'expression_version'=>$this->expressions ? 2 : null,
			'operators'=>$this->operators, 'groups'=>$this->groups, 'relations'=>$this->relations,
			'relation_depth'=>$this->relationDepth, 'sorts'=>$this->sorts, 'sort_nulls'=>$this->nullSorts,
			'legacy_filters'=>$this->legacyFilters, 'raw'=>$this->raw,
		];
	}

	/** @param mixed $values @param list<string> $allowed @return list<string> */
	private static function names(mixed $values, array $allowed): array {
		if(!is_array($values)){ return []; }
		$out=[];
		foreach($values as $value){ $value=strtolower(trim((string)$value)); if(in_array($value, $allowed, true)){ $out[]=$value; } }
		return array_values(array_unique($out));
	}

	private static function relationDepth(PanelQueryExpression $expression, int $depth=0): int {
		if($expression instanceof PanelQueryRelation){
			$next=self::relationDepth($expression->expression(), $depth+1);
			return $expression->scope()===null ? $next : max($next, self::relationDepth($expression->scope(), $depth+1));
		}
		if($expression instanceof PanelQueryGroup){
			$maximum=$depth;
			foreach($expression->children() as $child){ $maximum=max($maximum, self::relationDepth($child, $depth)); }
			return $maximum;
		}
		return $depth;
	}
}
