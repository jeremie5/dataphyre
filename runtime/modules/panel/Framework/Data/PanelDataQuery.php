<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, adapter-neutral query specification for Panel data surfaces. */
final class PanelDataQuery implements \JsonSerializable {

	private const OPERATORS=['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'not_contains', 'starts_with', 'ends_with', 'between', 'not_between', 'is_null', 'not_null'];
	private const AGGREGATES=['count', 'sum', 'avg', 'min', 'max', 'distinct_count'];

	/** @param list<array<string, mixed>> $filters @param list<array{field:string,direction:string}> $sorts @param list<PanelQuerySort> $sortNodes @param list<string> $select @param list<string> $include @param list<array{alias:string,function:string,field:?string}> $aggregates @param array<string, mixed> $authorization @param array<string, mixed> $metadata */
	private function __construct(
		private array $filters=[],
		private ?PanelQueryExpression $expression=null,
		private array $sorts=[],
		private array $sortNodes=[],
		private ?string $searchTerm=null,
		private array $searchFields=[],
		private array $select=[],
		private array $include=[],
		private ?string $cursor=null,
		private int $offset=0,
		private int $limit=50,
		private array $aggregates=[],
		private string|int|null $tenant=null,
		private array $authorization=[],
		private array $metadata=[]
	){}

	public static function make(): self { return new self(); }

	/** @param array<string, mixed> $data */
	public static function fromArray(array $data): self {
		$query=self::make();
		if(is_array($data['expression'] ?? null)){
			$query=$query->replaceExpression(PanelQueryExpressionCodec::fromArray($data['expression']));
			if(is_array($data['filters'] ?? null) && array_is_list($data['filters'])){
				$compatibility=[];
				foreach($data['filters'] as $filter){
					if(!is_array($filter)){ throw new \InvalidArgumentException('Panel data filters must contain arrays.'); }
					$single=self::make()->addWhere((string)($filter['field'] ?? ''), (string)($filter['operator'] ?? 'eq'), $filter['value'] ?? null, (string)($filter['boolean'] ?? 'and'));
					$compatibility[]=$single->filterList()[0];
				}
				$canonical=PanelQueryExpressionCodec::legacyFilters($query->expression());
				if(($canonical===null && $compatibility!==[]) || ($canonical!==null && !self::sameLegacyFilters($canonical, $compatibility))){
					throw new \InvalidArgumentException('Panel data expression and legacy filters describe different predicates.');
				}
				$query->filters=$compatibility;
			}
		}
		else{
			$filters=is_array($data['filters'] ?? null) ? $data['filters'] : [];
			if($filters!==[] && !array_is_list($filters)){
				foreach($filters as $field=>$value){
					$query=$query->addWhere(
						(string)$field,
						is_array($value) && isset($value['operator']) ? (string)$value['operator'] : 'eq',
						is_array($value) && isset($value['operator']) ? ($value['value'] ?? null) : $value,
						is_array($value) ? (string)($value['boolean'] ?? 'and') : 'and'
					);
				}
			}
			else{
				foreach($filters as $filter){
					if(!is_array($filter)){ throw new \InvalidArgumentException('Panel data filters must contain arrays.'); }
					$query=$query->addWhere(
						(string)($filter['field'] ?? ''),
						(string)($filter['operator'] ?? 'eq'),
						$filter['value'] ?? null,
						(string)($filter['boolean'] ?? 'and')
					);
				}
			}
		}
		$sorts=is_array($data['sort_nodes'] ?? null) && $data['sort_nodes']!==[] ? $data['sort_nodes'] : (is_array($data['sorts'] ?? null) ? $data['sorts'] : []);
		foreach($sorts as $sort){
			if(!is_array($sort)){ throw new \InvalidArgumentException('Panel data sorts must contain arrays.'); }
			$query=$query->sort((string)($sort['field'] ?? ''), (string)($sort['direction'] ?? 'asc'), (string)($sort['nulls'] ?? 'native'));
		}
		$search=$data['search'] ?? null;
		if(is_array($search)){ $query=$query->search((string)($search['term'] ?? ''), is_array($search['fields'] ?? null) ? $search['fields'] : []); }
		elseif(is_string($search)){ $query=$query->search($search); }
		if(isset($data['select'])){ $query=$query->select(is_array($data['select']) ? $data['select'] : [(string)$data['select']]); }
		if(isset($data['include'])){ $query=$query->include(is_array($data['include']) ? $data['include'] : [(string)$data['include']]); }
		if(isset($data['limit'])){ $query=$query->limit((int)$data['limit']); }
		if(isset($data['offset'])){ $query=$query->offset((int)$data['offset']); }
		if(array_key_exists('cursor', $data) && $data['cursor']!==null){ $query=$query->cursor((string)$data['cursor']); }
		foreach(is_array($data['aggregates'] ?? null) ? $data['aggregates'] : [] as $aggregate){
			if(!is_array($aggregate)){ throw new \InvalidArgumentException('Panel data aggregates must contain arrays.'); }
			$query=$query->aggregate((string)($aggregate['alias'] ?? ''), (string)($aggregate['function'] ?? ''), isset($aggregate['field']) ? (string)$aggregate['field'] : null);
		}
		if(array_key_exists('tenant', $data)){ $query=$query->tenant(is_string($data['tenant']) || is_int($data['tenant']) ? $data['tenant'] : null); }
		if(isset($data['authorization'])){ $query=$query->authorization(is_array($data['authorization']) ? $data['authorization'] : []); }
		if(isset($data['metadata'])){ $query=$query->metadata(is_array($data['metadata']) ? $data['metadata'] : []); }
		return $query;
	}

	public function where(string $field, mixed $operatorOrValue, mixed $value=null): self {
		if(func_num_args()===2 && is_string($operatorOrValue) && in_array(strtolower(trim($operatorOrValue)), ['is_null', 'not_null'], true)){
			return $this->addWhere($field, $operatorOrValue, null, 'and');
		}
		return func_num_args()===2 ? $this->addWhere($field, 'eq', $operatorOrValue, 'and') : $this->addWhere($field, (string)$operatorOrValue, $value, 'and');
	}

	public function orWhere(string $field, mixed $operatorOrValue, mixed $value=null): self {
		if(func_num_args()===2 && is_string($operatorOrValue) && in_array(strtolower(trim($operatorOrValue)), ['is_null', 'not_null'], true)){
			return $this->addWhere($field, $operatorOrValue, null, 'or');
		}
		return func_num_args()===2 ? $this->addWhere($field, 'eq', $operatorOrValue, 'or') : $this->addWhere($field, (string)$operatorOrValue, $value, 'or');
	}

	/** @param array<string, mixed> $filters */
	public function filters(array $filters): self {
		$query=$this;
		foreach($filters as $field=>$value){ $query=$query->where((string)$field, $value); }
		return $query;
	}

	public function sort(string $field, string $direction='asc', string $nulls='native'): self {
		return $this->sortBy(PanelQuerySort::make($field, $direction, $nulls));
	}

	public function sortBy(PanelQuerySort $sort): self {
		$field=$sort->field()->value();
		$clone=clone $this;
		$clone->sorts=array_values(array_filter($clone->sorts, static fn(array $sort): bool=>$sort['field']!==$field));
		$clone->sortNodes=array_values(array_filter($clone->sortNodes, static fn(PanelQuerySort $candidate): bool=>$candidate->field()->value()!==$field));
		$clone->sorts[]=['field'=>$field, 'direction'=>$sort->direction()];
		$clone->sortNodes[]=$sort;
		if(count($clone->sorts)>16){ throw new \LengthException('Panel data queries support at most 16 sort fields.'); }
		return $clone;
	}

	/** @param list<string> $fields */
	public function search(?string $term, array $fields=[]): self {
		$term=$term===null ? null : trim($term);
		if($term!==null && strlen($term)>1000){ throw new \LengthException('Panel data search term exceeds 1000 bytes.'); }
		$clone=clone $this;
		$clone->searchTerm=$term==='' ? null : $term;
		$clone->searchFields=self::fields($fields, 50);
		return $clone;
	}

	/** @param list<string> $fields */
	public function select(array $fields): self { $clone=clone $this; $clone->select=self::fields($fields, 100); return $clone; }
	/** @param list<string> $relations */
	public function include(array $relations): self { $clone=clone $this; $clone->include=self::fields($relations, 50); return $clone; }

	public function cursor(?string $cursor): self {
		$cursor=$cursor===null ? null : trim($cursor);
		if($cursor!==null && strlen($cursor)>4096){ throw new \LengthException('Panel data cursor exceeds 4096 bytes.'); }
		$clone=clone $this;
		$clone->cursor=$cursor==='' ? null : $cursor;
		if($clone->cursor!==null){ $clone->offset=0; }
		return $clone;
	}

	public function offset(int $offset): self {
		if($offset<0){ throw new \InvalidArgumentException('Panel data offset cannot be negative.'); }
		$clone=clone $this;
		$clone->offset=min(1000000000, $offset);
		if($offset>0){ $clone->cursor=null; }
		return $clone;
	}

	public function limit(int $limit): self {
		if($limit<1 || $limit>10000){ throw new \InvalidArgumentException('Panel data limit must be between 1 and 10000.'); }
		$clone=clone $this; $clone->limit=$limit; return $clone;
	}

	public function aggregate(string $alias, string $function, ?string $field=null): self {
		$alias=self::field($alias);
		$function=strtolower(trim($function));
		if(!in_array($function, self::AGGREGATES, true)){ throw new \InvalidArgumentException("Unsupported Panel data aggregate '{$function}'."); }
		$field=$field===null || trim($field)==='' ? null : self::field($field);
		if($function!=='count' && $field===null){ throw new \InvalidArgumentException("Aggregate '{$function}' requires a field."); }
		$clone=clone $this;
		$clone->aggregates=array_values(array_filter($clone->aggregates, static fn(array $aggregate): bool=>$aggregate['alias']!==$alias));
		$clone->aggregates[]=['alias'=>$alias, 'function'=>$function, 'field'=>$field];
		if(count($clone->aggregates)>32){ throw new \LengthException('Panel data queries support at most 32 aggregates.'); }
		return $clone;
	}

	public function tenant(string|int|null $tenant): self {
		if(is_string($tenant)){ $tenant=trim($tenant); $tenant=$tenant==='' ? null : $tenant; }
		if(is_string($tenant) && strlen($tenant)>255){ throw new \LengthException('Panel data tenant key exceeds 255 bytes.'); }
		$clone=clone $this; $clone->tenant=$tenant; return $clone;
	}

	/** @param array<string, mixed> $authorization */
	public function authorization(array $authorization): self { $clone=clone $this; $clone->authorization=self::jsonMap($authorization, 'authorization'); return $clone; }
	/** @param array<string, mixed> $metadata */
	public function metadata(array $metadata): self { $clone=clone $this; $clone->metadata=array_replace($clone->metadata, self::jsonMap($metadata, 'metadata')); return $clone; }

	/** @return list<array<string, mixed>> */ public function filterList(): array { return $this->filters; }
	public function expression(): ?PanelQueryExpression { return $this->expression; }
	/** @return list<array{field:string,direction:string}> */ public function sortList(): array { return $this->sorts; }
	/** @return list<PanelQuerySort> */ public function sortNodes(): array { return $this->sortNodes; }
	public function searchTerm(): ?string { return $this->searchTerm; }
	/** @return list<string> */ public function searchFields(): array { return $this->searchFields; }
	/** @return list<string> */ public function selectedFields(): array { return $this->select; }
	/** @return list<string> */ public function includes(): array { return $this->include; }
	public function cursorToken(): ?string { return $this->cursor; }
	public function offsetValue(): int { return $this->offset; }
	public function limitValue(): int { return $this->limit; }
	/** @return list<array{alias:string,function:string,field:?string}> */ public function aggregateList(): array { return $this->aggregates; }
	public function tenantKey(): string|int|null { return $this->tenant; }
	/** @return array<string, mixed> */ public function authorizationMetadata(): array { return $this->authorization; }
	/** @return array<string, mixed> */ public function meta(): array { return $this->metadata; }

	public function fingerprint(): string {
		$data=$this->jsonSerialize();
		unset($data['cursor'], $data['offset'], $data['limit'], $data['metadata']);
		return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_data_query', 'version'=>2, 'filters'=>$this->filters,
			'expression'=>$this->expression?->jsonSerialize(), 'sorts'=>$this->sorts,
			'sort_nodes'=>array_map(static fn(PanelQuerySort $sort): array=>$sort->jsonSerialize(), $this->sortNodes),
			'search'=>$this->searchTerm===null ? null : ['term'=>$this->searchTerm, 'fields'=>$this->searchFields],
			'select'=>$this->select, 'include'=>$this->include, 'cursor'=>$this->cursor,
			'offset'=>$this->offset, 'limit'=>$this->limit, 'aggregates'=>$this->aggregates,
			'tenant'=>$this->tenant, 'authorization'=>$this->authorization, 'metadata'=>$this->metadata,
		];
	}

	/** URL-safe public state deliberately excludes tenant, authorization, metadata, aggregates, and cursor. @return array<string,mixed> */
	public function urlState(): array {
		return [
			'type'=>'panel_query_url', 'version'=>PanelQueryUrlCodec::VERSION,
			'expression'=>$this->expression?->jsonSerialize(),
			'sorts'=>array_map(static fn(PanelQuerySort $sort): array=>$sort->jsonSerialize(), $this->sortNodes),
			'search'=>$this->searchTerm===null ? null : ['term'=>$this->searchTerm, 'fields'=>$this->searchFields],
			'select'=>$this->select, 'include'=>$this->include, 'offset'=>$this->offset, 'limit'=>$this->limit,
		];
	}

	public function replaceExpression(?PanelQueryExpression $expression): self {
		$clone=clone $this;
		$clone->expression=$expression;
		$clone->filters=PanelQueryExpressionCodec::legacyFilters($expression) ?? [];
		return $clone;
	}

	public function whereExpression(PanelQueryExpression $expression, string $boolean='and'): self {
		$boolean=strtolower(trim($boolean));
		if(!in_array($boolean, ['and','or'], true)){ throw new \InvalidArgumentException('Panel data filter boolean must be and or or.'); }
		$combined=$this->expression===null ? $expression : PanelQueryGroup::make($boolean, [$this->expression, $expression]);
		$clone=$this->replaceExpression($combined);
		if(count(PanelQueryExpressionCodec::nodes($combined))>200){ throw new \LengthException('Panel data queries support at most 100 filters.'); }
		return $clone;
	}

	private function addWhere(string $field, string $operator, mixed $value, string $boolean): self {
		$field=self::field($field);
		$operator=strtolower(trim(str_replace(' ', '_', $operator)));
		if(!in_array($operator, self::OPERATORS, true)){ throw new \InvalidArgumentException("Unsupported Panel data filter operator '{$operator}'."); }
		$boolean=strtolower(trim($boolean));
		if(!in_array($boolean, ['and', 'or'], true)){ throw new \InvalidArgumentException('Panel data filter boolean must be and or or.'); }
		if(in_array($operator, ['in', 'not_in', 'between', 'not_between'], true) && !is_array($value)){ throw new \InvalidArgumentException("Panel data '{$operator}' filter requires an array value."); }
		if(in_array($operator, ['between', 'not_between'], true) && count($value)!==2){ throw new \InvalidArgumentException('Panel data between filter requires exactly two values.'); }
		if(in_array($operator, ['is_null', 'not_null'], true)){ $value=null; }
		$value=self::jsonValue($value, 'filter');
		$clone=$this->whereExpression(PanelQueryExpressionCodec::fromLegacyFilter($field, $operator, $value), $boolean);
		$projected=PanelQueryExpressionCodec::legacyFilters($clone->expression);
		$clone->filters=$projected ?? [];
		if($projected!==null && count($projected)===count($this->filters)+1 && self::sameLegacyFilters(array_slice($projected, 0, -1), $this->filters)){
			foreach($this->filters as $index=>$filter){ $clone->filters[$index]['boolean']=$filter['boolean']; }
			$clone->filters[array_key_last($clone->filters)]['boolean']=$boolean;
		}
		if(count($clone->filters)>100){ throw new \LengthException('Panel data queries support at most 100 filters.'); }
		return $clone;
	}

	/** @param list<array<string,mixed>> $left @param list<array<string,mixed>> $right */
	private static function sameLegacyFilters(array $left, array $right): bool {
		if(count($left)!==count($right)){ return false; }
		foreach($left as $index=>$filter){
			$other=$right[$index] ?? null;
			if(!is_array($other) || (string)($filter['field'] ?? '')!==(string)($other['field'] ?? '') || (string)($filter['operator'] ?? '')!==(string)($other['operator'] ?? '')){ return false; }
			if(PanelQueryValue::stableJson($filter['value'] ?? null)!==PanelQueryValue::stableJson($other['value'] ?? null)){ return false; }
			if($index>0 && (string)($filter['boolean'] ?? 'and')!==(string)($other['boolean'] ?? 'and')){ return false; }
		}
		return true;
	}

	private static function field(string $field): string {
		return PanelQueryPath::make($field)->value();
	}

	/** @param array<mixed> $fields @return list<string> */
	private static function fields(array $fields, int $max): array {
		$normalized=[];
		foreach($fields as $field){ $normalized[]=self::field((string)$field); }
		$normalized=array_values(array_unique($normalized));
		if(count($normalized)>$max){ throw new \LengthException("Panel data field list supports at most {$max} entries."); }
		return $normalized;
	}

	/** @return array<string, mixed> */
	private static function jsonMap(array $value, string $label): array {
		if($value!==[] && array_is_list($value)){ throw new \InvalidArgumentException("Panel data {$label} must be an object-like array."); }
		/** @var array<string, mixed> */ return self::jsonValue($value, $label);
	}

	private static function jsonValue(mixed $value, string $label, int $depth=0): mixed {
		if($depth>16){ throw new \InvalidArgumentException("Panel data {$label} exceeds maximum nesting depth."); }
		if($value===null || is_string($value) || is_int($value) || is_bool($value)){ return $value; }
		if(is_float($value)){ if(!is_finite($value)){ throw new \InvalidArgumentException("Panel data {$label} contains a non-finite number."); } return $value; }
		if($value instanceof \JsonSerializable){ return self::jsonValue($value->jsonSerialize(), $label, $depth+1); }
		if(is_array($value)){
			if(count($value)>10000){ throw new \LengthException("Panel data {$label} contains too many entries."); }
			$out=[]; foreach($value as $key=>$item){ $out[$key]=self::jsonValue($item, $label, $depth+1); } return $out;
		}
		throw new \InvalidArgumentException("Panel data {$label} contains a non-serializable value.");
	}
}
