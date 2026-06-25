<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes global panel search providers and optional sampled results.
 *
 * Search manifests identify which resources participate in global search, the
 * columns they expose, tenant and authorization boundaries, and the shape of a
 * sampled query result set. Sampling is best-effort and reports failures as a
 * result row instead of interrupting manifest creation.
 */
final class SearchManifest {

	/**
	 * Stores the search source, request, query, and limit.
	 *
	 * @param PanelInstance|PanelManager|array|null $source Live panel source, serialized resources, or null for the global panel.
	 * @param ?PanelRequest $request Current request used by live search providers.
	 * @param ?string $query Optional sample query.
	 * @param int $limit Maximum sample results requested.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 */
	private function __construct(
		private readonly PanelInstance|PanelManager|array|null $source=null,
		private readonly ?PanelRequest $request=null,
		private readonly ?string $query=null,
		private readonly int $limit=12,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a search manifest builder.
	 *
	 * @param PanelInstance|PanelManager|array|null $source Search provider source.
	 * @param ?PanelRequest $request Current request context.
	 * @param ?string $query Optional sample query.
	 * @param int $limit Result limit clamped to the supported manifest range.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(PanelInstance|PanelManager|array|null $source=null, ?PanelRequest $request=null, ?string $query=null, int $limit=12, array $meta=[]): self {
		return new self($source, $request, $query, $limit, $meta);
	}

	/**
	 * Materializes the search_manifest payload.
	 *
	 * @return array{type:string,providers:array<string,array{name:string,label:string,columns:list<mixed>,tenant_scoped:bool,queryable:bool,authorizes:bool}>,provider_count:int,resource_columns:array<string,list<mixed>>,query:array{value:string,limit:int,sampled:bool,result_count:int,results:list<array{title:string,subtitle?:mixed,url?:mixed,resource?:mixed,key?:mixed}>},capabilities:array<string,mixed>,meta:array<string,mixed>} Search manifest payload.
	 */
	public function toArray(): array {
		$resources=$this->resourceManifests();
		$providers=[];
		foreach($resources as $name=>$resource){
			if(($resource['search']['global_searchable'] ?? false)!==true){
				continue;
			}
			$providers[$name]=[
				'name'=>(string)($resource['name'] ?? $name),
				'label'=>(string)($resource['plural_label'] ?? $resource['label'] ?? self::humanize((string)$name)),
				'columns'=>is_array($resource['search']['columns'] ?? null) ? array_values($resource['search']['columns']) : [],
				'tenant_scoped'=>($resource['tenant']['scoped'] ?? false)===true,
				'queryable'=>($resource['data']['queryable'] ?? false)===true,
				'authorizes'=>($resource['policies']['authorizes'] ?? false)===true,
			];
		}
		$query=trim((string)$this->query);
		$results=$query!=='' ? $this->sampleResults($query) : [];
		$manifest=[
			'type'=>'search_manifest',
			'providers'=>$providers,
			'provider_count'=>count($providers),
			'resource_columns'=>array_map(static fn(array $provider): array => $provider['columns'], $providers),
			'query'=>[
				'value'=>$query,
				'limit'=>max(1, min(50, $this->limit)),
				'sampled'=>$query!=='',
				'result_count'=>count($results),
				'results'=>$results,
			],
			'capabilities'=>self::capabilities($providers, $results),
			'meta'=>$this->meta,
		];
		PanelTrace::record('search.manifest.described', [
			'providers'=>count($providers),
			'query'=>$query,
			'results'=>count($results),
		]);
		return $manifest;
	}

	/**
	 * Resolves resource manifests from the configured source.
	 *
	 * @return array<string,array<string,mixed>> Resource manifests keyed by resource name.
	 */
	private function resourceManifests(): array {
		if($this->source instanceof PanelInstance || $this->source instanceof PanelManager){
			$resources=[];
			foreach($this->source->resources() as $name=>$resource){
				if($resource instanceof Resource){
					$manifest=$resource->resourceManifest($this->request, ['surface'=>'search_manifest']);
					$resources[(string)($manifest['name'] ?? $name)]=$manifest;
				}
			}
			return $resources;
		}
		if(is_array($this->source)){
			if(is_array($this->source['resources'] ?? null)){
				return array_filter($this->source['resources'], 'is_array');
			}
			return array_filter($this->source, 'is_array');
		}
		return PanelManifest::from(null, $this->request, ['surface'=>'search_manifest'])->toArray()['resources'] ?? [];
	}

	/**
	 * Executes a bounded sample search and converts provider failures into rows.
	 *
	 * @param string $query Non-empty sample query.
	 * @return list<array<string,mixed>> Normalized search result rows or a single error row.
	 */
	private function sampleResults(string $query): array {
		$request=$this->request ?? PanelRequest::fromArray([]);
		$limit=max(1, min(50, $this->limit));
		try{
			if($this->source instanceof PanelInstance){
				return $this->normalizeResults($this->source->globalSearch($query, $request, $limit));
			}
			if($this->source instanceof PanelManager){
				return $this->normalizeResults($this->source->globalSearch($query, $request, $limit));
			}
			return $this->normalizeResults(Panel::globalSearch($query, $request, $limit));
		}
		catch(\Throwable $exception){
			return [[
				'type'=>'search_error',
				'title'=>'Search sample failed',
				'subtitle'=>$exception->getMessage(),
			]];
		}
	}

	/**
	 * Normalizes provider-specific search results into a stable manifest shape.
	 *
	 * @param list<mixed> $results Raw results returned by global search.
	 * @return list<array{title:string,subtitle:mixed,url:mixed,resource:mixed,key?:mixed}> Rows containing title, subtitle, url, resource, and key fields.
	 */
	private function normalizeResults(array $results): array {
		return array_values(array_map(static function(mixed $result): array {
			if(!is_array($result)){
				return [
					'title'=>(string)$result,
					'subtitle'=>null,
					'url'=>null,
					'resource'=>null,
				];
			}
			return [
				'title'=>(string)($result['title'] ?? $result['label'] ?? $result['name'] ?? 'Result'),
				'subtitle'=>$result['subtitle'] ?? $result['description'] ?? null,
				'url'=>$result['url'] ?? $result['href'] ?? null,
				'resource'=>$result['resource'] ?? null,
				'key'=>$result['key'] ?? $result['id'] ?? null,
			];
		}, $results));
	}

	/**
	 * Summarizes search provider, column, and sampled-result capabilities.
	 *
	 * @param array<string,array{name:string,label:string,columns:list<mixed>,tenant_scoped:bool,queryable:bool,authorizes:bool}> $providers Search provider manifests.
	 * @param list<array{title:string,subtitle:mixed,url:mixed,resource:mixed,key?:mixed}> $results Normalized sample results.
	 * @return array{providers:array{total:int,tenant_scoped:int,queryable:int,authorizing:int},columns:array{total:int,max_per_provider:int},results:array{sampled:bool,total:int,with_urls:int}} Capability summary payload.
	 */
	private static function capabilities(array $providers, array $results): array {
		return [
			'providers'=>[
				'total'=>count($providers),
				'tenant_scoped'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['tenant_scoped'] ?? false)===true)),
				'queryable'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['queryable'] ?? false)===true)),
				'authorizing'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['authorizes'] ?? false)===true)),
			],
			'columns'=>[
				'total'=>array_sum(array_map(static fn(array $provider): int => count($provider['columns'] ?? []), $providers)),
				'max_per_provider'=>max([0, ...array_map(static fn(array $provider): int => count($provider['columns'] ?? []), $providers)]),
			],
			'results'=>[
				'sampled'=>count($results)>0,
				'total'=>count($results),
				'with_urls'=>count(array_filter($results, static fn(array $result): bool => is_string($result['url'] ?? null) && trim((string)$result['url'])!=='')),
			],
		];
	}

	/**
	 * Converts provider machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Search when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Search' : ucwords($value);
	}
}
