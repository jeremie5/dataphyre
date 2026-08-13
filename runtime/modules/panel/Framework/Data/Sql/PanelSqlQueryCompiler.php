<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Compiles the typed Panel query AST into parameterized, allowlisted SQL. */
final class PanelSqlQueryCompiler {
	private const MAX_PARAMETERS=2000;
	/** @var array<string,null|bool|int|float|string> */
	private array $parameters=[];
	private int $parameterSequence=0;
	private int $relationSequence=0;
	private string|int|null $tenant=null;

	public function __construct(private readonly PanelSqlSchema $schema, private readonly string $driver) {
		if(!in_array($driver, ['mysql','pgsql','sqlite'], true)){ throw new \InvalidArgumentException("Unsupported Panel SQL compiler driver '{$driver}'."); }
	}

	/**
	 * @param ?array{offset:int,values:list<null|bool|int|float|string>} $cursor
	 */
	public function compile(PanelDataQuery $query, ?PanelQueryExpression $securityScope=null, ?array $cursor=null): PanelSqlCompiledQuery {
		$this->parameters=[]; $this->parameterSequence=0; $this->relationSequence=0; $this->tenant=$query->tenantKey();
		if($query->limitValue()>$this->schema->maxLimit()){ throw new \InvalidArgumentException('Panel SQL query limit exceeds its schema allowance.'); }
		if($query->includes()!==[]){ throw new PanelUnsupportedQueryException(['include'], $this->capabilities()); }

		$table=$this->quotePath($this->schema->table()); $root='t0'; $predicates=[];
		if($query->expression()!==null){ $predicates[]=$this->expression($query->expression(), $this->schema, $root, 0); }
		if($securityScope!==null){ $predicates[]=$this->expression($securityScope, $this->schema, $root, 0); }
		if($this->schema->tenantField()!==null && $this->tenant!==null){
			$predicates[]=$this->column($this->schema, $this->schema->tenantField(), $root).' = '.$this->parameter($this->tenant);
		}
		if($query->searchTerm()!==null){ $predicates[]=$this->search($query, $this->schema, $root); }
		$baseWhere=$predicates===[] ? '' : ' WHERE '.implode(' AND ', array_map(static fn(string $predicate): string=>'('.$predicate.')', $predicates));
		$baseParameters=$this->parameters;

		$sorts=$this->sorts($query); $projection=$this->projection($query);
		$select=[];
		foreach($projection as $field){ $select[]=$this->column($this->schema, $field, $root).' AS '.$this->quoteAlias($field); }
		$cursorSorts=[];
		foreach($sorts as $index=>$sort){
			$alias=in_array($sort['field'], $projection, true) ? $sort['field'] : '__dp_cursor_'.$index;
			if(!in_array($sort['field'], $projection, true)){ $select[]=$this->column($this->schema, $sort['field'], $root).' AS '.$this->quoteAlias($alias); }
			$cursorSorts[]=$sort+['alias'=>$alias];
		}

		$mainWhere=$baseWhere; $offset=$query->offsetValue();
		if($cursor!==null){
			if(!isset($cursor['offset'],$cursor['values']) || !is_int($cursor['offset']) || !is_array($cursor['values']) || !array_is_list($cursor['values'])){ throw new \InvalidArgumentException('Panel SQL decoded cursor shape is invalid.'); }
			if(count($cursor['values'])!==count($sorts)){ throw new \InvalidArgumentException('Panel SQL cursor sort arity does not match the query.'); }
			$cursorPredicate=$this->keyset($sorts, $cursor['values'], $this->schema, $root);
			$mainWhere.=($mainWhere==='' ? ' WHERE ' : ' AND ').'('.$cursorPredicate.')'; $offset=$cursor['offset'];
		}

		$order=[];
		foreach($sorts as $sort){
			$column=$this->column($this->schema, $sort['field'], $root);
			$order[]=$this->nullRank($column, $sort['nulls']).' ASC';
			$order[]=$column.' '.strtoupper($sort['direction']);
		}
		$sql='SELECT '.implode(', ', $select).' FROM '.$table.' '.$root.$mainWhere.' ORDER BY '.implode(', ', $order).' LIMIT '.($query->limitValue()+1);
		if($cursor===null && $query->offsetValue()>0){ $sql.=' OFFSET '.$query->offsetValue(); }
		$countSql='SELECT COUNT(*) AS '.$this->quoteAlias('__dp_total').' FROM '.$table.' '.$root.$baseWhere;

		$aggregateSql=null; $aggregateSpecs=$query->aggregateList();
		if($aggregateSpecs!==[]){
			$aggregates=[];
			foreach($aggregateSpecs as $aggregate){
				$field=$aggregate['field']===null ? null : $this->column($this->schema, $aggregate['field'], $root);
				$body=match($aggregate['function']){
					'count'=>$field===null ? 'COUNT(*)' : 'COUNT('.$field.')',
					'distinct_count'=>'COUNT(DISTINCT '.$field.')',
					'sum'=>'SUM('.$field.')', 'avg'=>'AVG('.$field.')',
					'min'=>'MIN('.$field.')', 'max'=>'MAX('.$field.')',
					default=>throw new \LogicException('Panel SQL received an unsupported normalized aggregate.'),
				};
				$aggregates[]=$body.' AS '.$this->quoteAlias($aggregate['alias']);
			}
			$aggregateSql='SELECT '.implode(', ', $aggregates).' FROM '.$table.' '.$root.$baseWhere;
		}

		return new PanelSqlCompiledQuery(
			$sql, $this->parameters, $countSql, $baseParameters, $aggregateSql,
			$projection, $cursorSorts, $aggregateSpecs, $offset, $query->limitValue()
		);
	}

	public function contextFingerprint(PanelDataQuery $query, ?PanelQueryExpression $securityScope=null): string {
		$payload=[
			'v'=>1, 'driver'=>$this->driver, 'schema'=>$this->schema->fingerprint(),
			'query'=>$query->fingerprint(), 'sorts'=>$this->sorts($query),
			'security_scope'=>$securityScope?->jsonSerialize(),
		];
		return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
	}

	/** @return array<string,mixed> */
	public function capabilities(): array {
		return array_replace(PanelQueryCapabilities::full('sql'), [
			'relations'=>$this->schema->hasRelations(), 'relation_depth'=>$this->schema->relationDepth(),
			'search'=>true, 'select'=>true, 'include'=>false, 'aggregates'=>true,
		]);
	}

	private function expression(PanelQueryExpression $expression, PanelSqlSchema $schema, string $alias, int $depth): string {
		if($depth>16){ throw new \LengthException('Panel SQL relation depth exceeds 16.'); }
		if($expression instanceof PanelQueryGroup){
			$children=[];
			foreach($expression->children() as $child){ $children[]='('.$this->expression($child, $schema, $alias, $depth).')'; }
			return implode($expression->boolean()==='and' ? ' AND ' : ' OR ', $children);
		}
		if($expression instanceof PanelQueryRelation){ return $this->relation($expression, $schema, $alias, $depth); }
		if($expression instanceof PanelQueryComparison){
			$column=$this->column($schema, $expression->field()->value(), $alias); $value=$expression->value(); $operator=$expression->operator();
			if($value===null && in_array($operator, ['eq','neq'], true)){ return $column.($operator==='eq' ? ' IS NULL' : ' IS NOT NULL'); }
			if(in_array($operator, ['contains','not_contains','starts_with','ends_with'], true)){
				$value=$this->scalar($value, 'comparison'); $text=$this->like((string)$value);
				$pattern=match($operator){ 'contains','not_contains'=>'%'.$text.'%', 'starts_with'=>$text.'%', 'ends_with'=>'%'.$text };
				$sql='LOWER(COALESCE('.$this->text($column).", '')) LIKE LOWER(".$this->parameter($pattern).") ESCAPE '!'";
				return $operator==='not_contains' ? 'NOT ('.$sql.')' : $sql;
			}
			return $this->comparison($column, $operator, $this->scalar($value, 'comparison'));
		}
		if($expression instanceof PanelQueryNull){
			return $this->column($schema, $expression->field()->value(), $alias).($expression->negated() ? ' IS NOT NULL' : ' IS NULL');
		}
		if($expression instanceof PanelQueryBetween){
			$column=$this->column($schema, $expression->field()->value(), $alias);
			$range='('.$this->comparison($column, 'gte', $this->scalar($expression->from(), 'range')).') AND ('.$this->comparison($column, 'lte', $this->scalar($expression->to(), 'range')).')';
			return $expression->negated() ? 'NOT ('.$range.')' : $range;
		}
		if($expression instanceof PanelQueryIn){
			$column=$this->column($schema, $expression->field()->value(), $alias); $values=$expression->values();
			$hasNull=in_array(null, $values, true); $values=array_values(array_filter($values, static fn(mixed $value): bool=>$value!==null));
			$placeholders=[]; foreach($values as $value){ $placeholders[]=$this->parameter($this->scalar($value, 'membership')); }
			if(!$expression->negated()){
				$parts=[]; if($placeholders!==[]){ $parts[]=$column.' IN ('.implode(', ', $placeholders).')'; } if($hasNull){ $parts[]=$column.' IS NULL'; }
				return $parts===[] ? '0 = 1' : '('.implode(' OR ', $parts).')';
			}
			if($hasNull){ return $placeholders===[] ? $column.' IS NOT NULL' : '('.$column.' IS NOT NULL AND '.$column.' NOT IN ('.implode(', ', $placeholders).'))'; }
			return $placeholders===[] ? '1 = 1' : '('.$column.' IS NULL OR '.$column.' NOT IN ('.implode(', ', $placeholders).'))';
		}
		throw new \InvalidArgumentException('Panel SQL compiler received an unknown query-expression node.');
	}

	private function relation(PanelQueryRelation $expression, PanelSqlSchema $parent, string $parentAlias, int $depth): string {
		$relation=$parent->relation($expression->relation()); $schema=$relation->schema(); $alias='r'.(++$this->relationSequence);
		$predicates=[
			$this->column($schema, $relation->foreignField(), $alias).' = '.$this->column($parent, $relation->localField(), $parentAlias),
		];
		if($schema->tenantField()!==null){
			if($this->tenant===null && $schema->requiresTenant()){ throw new PanelSqlAccessDeniedException('nested_tenant_required'); }
			if($this->tenant!==null){ $predicates[]=$this->column($schema, $schema->tenantField(), $alias).' = '.$this->parameter($this->tenant); }
		}
		if($expression->scope()!==null){ $predicates[]=$this->expression($expression->scope(), $schema, $alias, $depth+1); }
		$match=$this->expression($expression->expression(), $schema, $alias, $depth+1);
		$base=implode(' AND ', array_map(static fn(string $predicate): string=>'('.$predicate.')', $predicates));
		$table=$this->quotePath($schema->table()).' '.$alias;
		return match($expression->quantifier()){
			'any'=>'EXISTS (SELECT 1 FROM '.$table.' WHERE '.$base.' AND ('.$match.'))',
			'none'=>'NOT EXISTS (SELECT 1 FROM '.$table.' WHERE '.$base.' AND ('.$match.'))',
			'all'=>'NOT EXISTS (SELECT 1 FROM '.$table.' WHERE '.$base.' AND NOT COALESCE(('.$match.'), FALSE))',
			default=>throw new \LogicException('Unsupported normalized Panel SQL relation quantifier.'),
		};
	}

	private function search(PanelDataQuery $query, PanelSqlSchema $schema, string $alias): string {
		$fields=$query->searchFields()!==[] ? $query->searchFields() : $schema->searchFields();
		if($fields===[]){ throw new PanelUnsupportedQueryException(['search_fields'], $this->capabilities()); }
		$tokens=preg_split('/\s+/u', trim((string)$query->searchTerm()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if(count($tokens)>20){ throw new \LengthException('Panel SQL search supports at most 20 terms.'); }
		$groups=[];
		foreach($tokens as $token){
			$terms=[]; $pattern='%'.$this->like($token).'%';
			foreach($fields as $field){
				$column=$this->column($schema, $field, $alias);
				$terms[]='LOWER(COALESCE('.$this->text($column).", '')) LIKE LOWER(".$this->parameter($pattern).") ESCAPE '!'";
			}
			$groups[]='('.implode(' OR ', $terms).')';
		}
		return $groups===[] ? '1 = 1' : implode(' AND ', $groups);
	}

	/** @return list<string> */
	private function projection(PanelDataQuery $query): array {
		$fields=$query->selectedFields()!==[] ? $query->selectedFields() : $this->schema->fieldNames();
		foreach($fields as $field){ $this->schema->column($field); }
		if(!in_array($this->schema->primaryKey(), $fields, true)){ $fields[]=$this->schema->primaryKey(); }
		return array_values(array_unique($fields));
	}

	/** @return list<array{field:string,direction:string,nulls:string}> */
	private function sorts(PanelDataQuery $query): array {
		$sorts=[];
		foreach($query->sortNodes() as $sort){
			$field=$sort->field()->value(); $this->schema->column($field); $nulls=$sort->nulls();
			if($nulls==='native'){ $nulls=$sort->direction()==='asc' ? 'last' : 'first'; }
			$sorts[]=['field'=>$field, 'direction'=>$sort->direction(), 'nulls'=>$nulls];
		}
		$primary=$this->schema->primaryKey();
		if(!in_array($primary, array_column($sorts, 'field'), true)){ $sorts[]=['field'=>$primary, 'direction'=>'asc', 'nulls'=>'last']; }
		if(count($sorts)>PanelSqlCursorCodec::MAX_VALUES){ throw new \LengthException('Panel SQL deterministic sorts exceed the cursor value budget.'); }
		return $sorts;
	}

	/** @param list<array{field:string,direction:string,nulls:string}> $sorts @param list<null|bool|int|float|string> $values */
	private function keyset(array $sorts, array $values, PanelSqlSchema $schema, string $alias): string {
		$branches=[];
		foreach($sorts as $index=>$sort){
			$prefix=[];
			for($before=0;$before<$index;$before++){
				$column=$this->column($schema, $sorts[$before]['field'], $alias); $value=$values[$before];
				$prefix[]=$value===null ? $column.' IS NULL' : $column.' = '.$this->parameter($this->scalar($value, 'cursor'));
			}
			$column=$this->column($schema, $sort['field'], $alias); $value=$values[$index];
			$nullRank=$sort['nulls']==='first' ? 0 : 1; $valueRank=$value===null ? $nullRank : 1-$nullRank;
			$rank=$this->nullRank($column, $sort['nulls']); $after=['('.$rank.' > '.$valueRank.')'];
			if($value!==null){
				$operator=$sort['direction']==='asc' ? '>' : '<';
				$after[]='('.$rank.' = '.$valueRank.' AND '.$column.' '.$operator.' '.$this->parameter($this->scalar($value, 'cursor')).')';
			}
			$branch='('.implode(' AND ', [...$prefix, '('.implode(' OR ', $after).')']).')'; $branches[]=$branch;
		}
		return implode(' OR ', $branches);
	}

	private function nullRank(string $column, string $nulls): string {
		return 'CASE WHEN '.$column.' IS NULL THEN '.($nulls==='first' ? '0' : '1').' ELSE '.($nulls==='first' ? '1' : '0').' END';
	}

	private function comparison(string $column, string $operator, null|bool|int|float|string $value): string {
		if($value===null){
			return match($operator){
				'eq','lte'=>$column.' IS NULL', 'neq','gt'=>$column.' IS NOT NULL',
				'gte'=>'1 = 1', 'lt'=>'0 = 1',
				default=>throw new \LogicException('Unsupported normalized Panel SQL comparison.'),
			};
		}
		$sqlOperator=match($operator){ 'eq'=>'=', 'neq'=>'<>', 'gt'=>'>', 'gte'=>'>=', 'lt'=>'<', 'lte'=>'<=', default=>throw new \LogicException('Unsupported normalized Panel SQL comparison.') };
		$comparison=$column.' '.$sqlOperator.' '.$this->parameter($value);
		return in_array($operator, ['neq','lt','lte'], true)
			? '('.$column.' IS NULL OR '.$comparison.')'
			: '('.$column.' IS NOT NULL AND '.$comparison.')';
	}

	private function text(string $column): string { return 'CAST('.$column.' AS '.($this->driver==='mysql' ? 'CHAR' : 'TEXT').')'; }

	private function column(PanelSqlSchema $schema, string $field, string $alias): string { return $alias.'.'.$this->quoteIdentifier($schema->column($field)); }

	private function quotePath(string $path): string { return implode('.', array_map(fn(string $part): string=>$this->quoteIdentifier($part), explode('.', $path))); }

	private function quoteIdentifier(string $identifier): string {
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)!==1){ throw new \InvalidArgumentException('Panel SQL identifier escaped its schema allowlist.'); }
		return $this->driver==='mysql' ? '`'.$identifier.'`' : '"'.$identifier.'"';
	}

	private function quoteAlias(string $alias): string {
		if($alias==='' || strlen($alias)>191 || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $alias)!==1){ throw new \InvalidArgumentException('Panel SQL result alias is invalid.'); }
		return $this->driver==='mysql' ? '`'.$alias.'`' : '"'.$alias.'"';
	}

	private function parameter(null|bool|int|float|string $value): string {
		if($this->parameterSequence>=self::MAX_PARAMETERS){ throw new \LengthException('Panel SQL query exceeds the 2000-parameter budget.'); }
		$name='p'.(++$this->parameterSequence); $this->parameters[$name]=$value; return ':'.$name;
	}

	private function scalar(mixed $value, string $label): null|bool|int|float|string {
		if($value===null || is_bool($value) || is_int($value) || is_string($value)){ return $value; }
		if(is_float($value) && is_finite($value)){ return $value; }
		throw new \InvalidArgumentException("Panel SQL {$label} values must be finite JSON scalars.");
	}

	private function like(string $value): string { return strtr($value, ['!'=>'!!', '%'=>'!%', '_'=>'!_']); }
}
