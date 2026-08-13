<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Binds universal data sources to Panel Resource query contracts. */
final class PanelDataSourceResourceBridge {
	public function __construct(private readonly PanelDataSource $source, private readonly array $options=[]) {}

	public static function using(PanelDataSource $source, array $options=[]): self {
		return new self($source, $options);
	}

	/**
	 * Installs a resource-aware query proxy.
	 *
	 * Returning a proxy instead of a bare item array lets PanelManager distinguish a
	 * source-paginated result from an in-memory collection. This prevents the
	 * renderer from filtering, sorting, and slicing the current page a second time.
	 */
	public function bind(Resource $resource): Resource {
		$bridge=$this;
		return $resource->queryUsing(static fn(mixed $request=null): PanelDataSourceResourceQuery => new PanelDataSourceResourceQuery(
			$bridge,
			$request instanceof PanelRequest ? $request : null,
			$resource
		));
	}

	public function query(?PanelRequest $request=null, array $overrides=[], ?Resource $resource=null): PanelDataResult {
		$query=$this->querySpec($request, $overrides, $resource);
		PanelQueryCapabilities::fromArray($this->source->capabilities())->assertSupports($query);
		return $this->source->query($query);
	}

	public function find(string|int $id, ?PanelRequest $request=null, ?Resource $resource=null, array $scopeFilters=[], ?PanelQueryExpression $scopeExpression=null): mixed {
		$query=$this->querySpec($request, ['page'=>1, 'limit'=>1, 'cursor'=>null, 'scope_filters'=>$scopeFilters, 'scope_expression'=>$scopeExpression], $resource);
		PanelQueryCapabilities::fromArray($this->source->capabilities())->assertSupports($query);
		return $this->source->find($id, $query);
	}

	public function collectionLimit(): int {
		return max(1, min(10000, (int)($this->options['collection_limit'] ?? 10000)));
	}

	public function querySpec(?PanelRequest $request=null, array $overrides=[], ?Resource $resource=null): PanelDataQuery {
		$defaultPerPage=(int)($this->options['per_page'] ?? $resource?->resourceTable()->defaultPerPage() ?? 25);
		$limit=max(1, min(10000, (int)($overrides['limit'] ?? $request?->perPage($defaultPerPage) ?? $defaultPerPage)));
		$page=max(1, (int)($overrides['page'] ?? $request?->page() ?? 1));
		$requestQuery=$request?->query();
		$requestQuery=is_array($requestQuery) ? $requestQuery : [];
		$urlState=$overrides['query_spec'] ?? $overrides[PanelQueryUrlCodec::PARAMETER] ?? $requestQuery[PanelQueryUrlCodec::PARAMETER] ?? null;
		$query=(is_string($urlState) || is_array($urlState)) ? PanelQueryUrlCodec::decode($urlState) : PanelDataQuery::make();
		$query=$query->limit($limit)->offset(($page-1)*$limit);
		if(($overrides['expression'] ?? null) instanceof PanelQueryExpression){ $query=$query->replaceExpression($overrides['expression']); }
		elseif(is_array($overrides['expression'] ?? null)){ $query=$query->replaceExpression(PanelQueryExpressionCodec::fromArray($overrides['expression'])); }

		$search=$this->firstValue($overrides, ['search', 'q']);
		$searchProvided=$this->hasAny($overrides, ['search','q']);
		if(!$searchProvided && $request!==null && $this->hasAny($requestQuery, ['q','search'])){
			$search=$request->query('q', $request->query('search', ''));
			$searchProvided=true;
		}
		if($searchProvided){
			$search=trim((string)($search ?? ''));
			$query=$query->search($search==='' ? null : $search, $this->searchFields($resource));
		}

		$defaultSort=$resource?->resourceTable()->defaultSortDefinition();
		$sort=$this->firstValue($overrides, ['sort']);
		$sortProvided=array_key_exists('sort', $overrides);
		if(!$sortProvided && $request!==null && array_key_exists('sort', $requestQuery)){
			$sort=$request->query('sort', null);
			$sortProvided=true;
		}
		if(!$sortProvided && $query->sortNodes()===[]){ $sort=$this->options['default_sort'] ?? (is_array($defaultSort) ? $defaultSort['column'] ?? '' : ''); }
		$sort=trim((string)($sort ?? ''));
		if($sort!==''){
			$direction=$this->firstValue($overrides, ['direction', 'dir']);
			if($direction===null && $request!==null){
				$direction=$request->query('dir', $request->query('direction', null));
			}
			$direction=strtolower(trim((string)($direction ?? $this->options['default_direction'] ?? (is_array($defaultSort) ? $defaultSort['direction'] ?? 'asc' : 'asc'))))==='desc' ? 'desc' : 'asc';
			$query=$query->sort($sort, $direction);
		}

		$cursor=array_key_exists('cursor', $overrides) ? $overrides['cursor'] : $request?->query('cursor');
		if(is_string($cursor) && trim($cursor)!==''){
			$query=$query->cursor($cursor);
		}

		$explicitProvided=array_key_exists('filters', $overrides) || array_key_exists('filters', $requestQuery);
		$explicitFilters=array_key_exists('filters', $overrides)
			? $this->normalizeFilters($overrides['filters'])
			: $this->normalizeFilters($requestQuery['filters'] ?? []);
		if($explicitProvided){ $query=$this->applyFilters($query, $explicitFilters); }
		$query=$this->applyFilters($query, $this->normalizeFilters($overrides['scope_filters'] ?? []));
		$scopeExpression=$overrides['scope_expression'] ?? null;
		if($scopeExpression instanceof PanelQueryExpression){ $query=$query->whereExpression($scopeExpression); }
		elseif(is_array($scopeExpression)){ $query=$query->whereExpression(PanelQueryExpressionCodec::fromArray($scopeExpression)); }
		if(!array_key_exists('filters', $overrides) && $request!==null && $resource!==null){
			$query=$this->applyFilters($query, $this->resourceFilters($resource, $request, $explicitFilters));
		}

		$tenant=$overrides['tenant'] ?? $request?->tenantKey() ?? ($this->options['tenant'] ?? null);
		$tenant=is_string($tenant) || is_int($tenant) ? $tenant : null;
		$sourceCapabilities=$this->source->capabilities();
		$sourceAppliesTenant=($sourceCapabilities['tenant'] ?? false)===true;
		$resourceTenant=$resource?->tenantScopeDefinition() ?? ['scoped'=>false, 'field'=>null, 'required'=>false];
		$resourceRequiresSourceTenant=$resourceTenant['scoped'];
		if($tenant!==null && $resourceRequiresSourceTenant && !$sourceAppliesTenant){
			throw new PanelQueryScopeException('tenant_adapter_unsupported', 'The Panel data source cannot enforce the resource tenant boundary.', [
				'resource'=>$resource?->name(),
				'adapter'=>(string)($sourceCapabilities['adapter'] ?? 'unknown'),
			]);
		}
		if($tenant!==null && $sourceAppliesTenant){
			$query=$query->tenant($tenant);
		}
		$authorization=is_array($overrides['authorization'] ?? null)
			? $overrides['authorization']
			: (is_array($this->options['authorization'] ?? null) ? $this->options['authorization'] : []);
		if($authorization!==[]){
			$query=$query->authorization($authorization);
		}
		if(is_array($this->options['select'] ?? null)){
			$query=$query->select($this->options['select']);
		}
		if(is_array($this->options['include'] ?? null)){
			$query=$query->include($this->options['include']);
		}
		$aggregates=is_array($overrides['aggregates'] ?? null)
			? $overrides['aggregates']
			: (is_array($this->options['aggregates'] ?? null) ? $this->options['aggregates'] : []);
		foreach($aggregates as $aggregate){
			if(!is_array($aggregate)){
				continue;
			}
			$query=$query->aggregate(
				(string)($aggregate['alias'] ?? ''),
				(string)($aggregate['function'] ?? ''),
				isset($aggregate['field']) ? (string)$aggregate['field'] : null
			);
		}
		$metadata=is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
		if($resource!==null && PanelQueryExpressionCodec::containsRelations($query->expression())){
			$resolver=isset($this->options['nested_resource_resolver']) && is_callable($this->options['nested_resource_resolver']) ? $this->options['nested_resource_resolver'] : null;
			$scope=PanelQueryScopeGuard::apply($query->expression(), $resource, $request, $resolver, $tenant);
			$query=$query->replaceExpression($scope->expression());
			$metadata['nested_scope']=$scope->manifest()->jsonSerialize();
		}
		return $query->metadata(['surface'=>'resource', 'bridge'=>'panel_data_source', 'page'=>$page]+$metadata);
	}

	public function manifest(): array {
		$capabilities=PanelQueryCapabilities::fromArray($this->source->capabilities())->jsonSerialize();
		return [
			'type'=>'panel_data_source_resource_bridge',
			'source'=>$this->source->capabilities(),
			'options'=>array_map(static fn(mixed $value): mixed => is_callable($value) ? 'callable' : $value, $this->options),
			'capabilities'=>[
				'resource_query'=>true, 'search'=>true, 'filters'=>true, 'sorts'=>true,
				'cursor'=>true, 'offset'=>true, 'tenancy'=>true, 'authorization'=>true,
				'query_expression'=>true, 'nested_resource_scope'=>true, 'url_round_trip'=>true,
			],
			'query_contract'=>[
				'version'=>2, 'url_parameter'=>PanelQueryUrlCodec::PARAMETER,
				'capabilities'=>$capabilities,
				'legacy_filters'=>[
					'supported'=>true,
					'deprecated_since'=>PanelQueryUrlCodec::LEGACY_FILTERS_DEPRECATED_SINCE,
					'supported_until'=>PanelQueryUrlCodec::LEGACY_FILTERS_SUPPORTED_UNTIL,
				],
			],
		];
	}

	/** @param list<string> $keys */
	private function firstValue(array $values, array $keys): mixed {
		foreach($keys as $key){
			if(array_key_exists($key, $values)){
				return $values[$key];
			}
		}
		return null;
	}

	/** @param list<string> $keys */
	private function hasAny(array $values, array $keys): bool {
		foreach($keys as $key){ if(array_key_exists($key, $values)){ return true; } }
		return false;
	}

	/** @return list<string> */
	private function searchFields(?Resource $resource): array {
		if(is_array($this->options['search_fields'] ?? null)){
			return array_values($this->options['search_fields']);
		}
		$fields=[];
		$fallback=[];
		foreach($resource?->resourceTable()->columnsList() ?? [] as $column){
			if($column instanceof Column){
				$fallback[]=$column->name();
				if(($column->toArray()['searchable'] ?? false)===true){
					$fields[]=$column->name();
				}
			}
		}
		return $fields!==[] ? $fields : $fallback;
	}

	/** @return list<array{field:string,operator:string,value:mixed,boolean:string}> */
	private function normalizeFilters(mixed $filters): array {
		if(is_string($filters)){
			$decoded=json_decode($filters, true);
			$filters=is_array($decoded) ? $decoded : [];
		}
		if(!is_array($filters)){
			return [];
		}
		$normalized=[];
		if(array_is_list($filters)){
			foreach($filters as $filter){
				if(!is_array($filter) || trim((string)($filter['field'] ?? ''))===''){
					continue;
				}
				$normalized[]=[
					'field'=>(string)$filter['field'],
					'operator'=>(string)($filter['operator'] ?? 'eq'),
					'value'=>$filter['value'] ?? null,
					'boolean'=>strtolower((string)($filter['boolean'] ?? 'and'))==='or' ? 'or' : 'and',
				];
			}
			return $normalized;
		}
		foreach($filters as $field=>$value){
			$operator=is_array($value) && isset($value['operator']) ? (string)$value['operator'] : 'eq';
			$normalized[]=[
				'field'=>(string)$field,
				'operator'=>$operator,
				'value'=>is_array($value) && isset($value['operator']) ? ($value['value'] ?? null) : $value,
				'boolean'=>is_array($value) && strtolower((string)($value['boolean'] ?? 'and'))==='or' ? 'or' : 'and',
			];
		}
		return $normalized;
	}

	/** @param list<array{field:string,operator:string,value:mixed,boolean:string}> $filters */
	private function applyFilters(PanelDataQuery $query, array $filters): PanelDataQuery {
		foreach($filters as $filter){
			$query=$filter['boolean']==='or'
				? $query->orWhere($filter['field'], $filter['operator'], $filter['value'])
				: $query->where($filter['field'], $filter['operator'], $filter['value']);
		}
		return $query;
	}

	/**
	 * Translates the direct query keys emitted by Panel table controls.
	 *
	 * @param list<array{field:string,operator:string,value:mixed,boolean:string}> $explicit
	 * @return list<array{field:string,operator:string,value:mixed,boolean:string}>
	 */
	private function resourceFilters(Resource $resource, PanelRequest $request, array $explicit): array {
		$explicitFields=array_fill_keys(array_column($explicit, 'field'), true);
		$filters=[];
		$table=$resource->resourceTable();
		foreach($table->filtersList() as $filter){
			if(!$filter instanceof TableFilter || !$filter->isVisible($request, $resource, $table)){
				continue;
			}
			$value=$filter->activeValue($request);
			if($value===null){
				continue;
			}
			$definition=$filter->toArray();
			$source=is_array($definition['meta']['data_source'] ?? null) ? $definition['meta']['data_source'] : [];
			if(($definition['has_predicate'] ?? false)===true && $source===[]){
				throw new \LogicException("Active Panel filter '{$filter->name()}' uses a predicate that cannot be translated by the data-source bridge; configure meta.data_source.");
			}
			$field=trim((string)($source['field'] ?? $definition['column'] ?? $filter->name()));
			if($field==='' || isset($explicitFields[$field])){
				continue;
			}
			if(($definition['range'] ?? false)===true && is_array($value)){
				if(array_key_exists('from', $value) && $value['from']!==null){
					$filters[]=['field'=>$field, 'operator'=>(string)($source['from_operator'] ?? 'gte'), 'value'=>$value['from'], 'boolean'=>'and'];
				}
				if(array_key_exists('to', $value) && $value['to']!==null){
					$filters[]=['field'=>$field, 'operator'=>(string)($source['to_operator'] ?? 'lte'), 'value'=>$value['to'], 'boolean'=>'and'];
				}
				continue;
			}
			$type=(string)($definition['type'] ?? 'text');
			$operator=(string)($source['operator'] ?? (in_array($type, ['text', 'search'], true) ? 'contains' : 'eq'));
			$filters[]=['field'=>$field, 'operator'=>$operator, 'value'=>$value, 'boolean'=>'and'];
		}
		return $filters;
	}
}
