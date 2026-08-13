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
 * sampled query result set. Sampling is best-effort and reports failures as
 * bounded diagnostics instead of interrupting manifest creation.
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
	 * @return array<string,mixed> Search manifest payload.
	 */
	public function toArray(): array {
		$resources=$this->resourceManifests();
		$providers=[];
		foreach($resources as $name=>$resource){
			if(($resource['search']['global_searchable'] ?? false)!==true){
				continue;
			}
			$providers[$name]=[
				'kind'=>'resource',
				'name'=>(string)($resource['name'] ?? $name),
				'label'=>(string)($resource['plural_label'] ?? $resource['label'] ?? self::humanize((string)$name)),
				'columns'=>is_array($resource['search']['columns'] ?? null) ? array_values($resource['search']['columns']) : [],
				'tenant_scoped'=>($resource['tenant']['scoped'] ?? false)===true,
				'queryable'=>($resource['data']['queryable'] ?? false)===true,
				'authorizes'=>($resource['policies']['authorizes'] ?? false)===true,
				'visible_lazy'=>false,
				'authorization_lazy'=>($resource['policies']['authorizes'] ?? false)===true,
				'score_lazy'=>false,
				'dedupe_lazy'=>false,
				'limit'=>50,
				'iterable_results'=>false,
				'page_results'=>false,
				'cursor_aware'=>false,
			];
		}
		foreach($this->customProviderManifests() as $name=>$provider){
			$key=isset($providers[$name]) ? 'custom:'.$name : $name;
			$providers[$key]=array_replace([
				'kind'=>'custom',
				'name'=>$name,
				'label'=>self::humanize($name),
				'columns'=>[],
				'tenant_scoped'=>false,
				'queryable'=>false,
				'authorizes'=>false,
			], $provider);
		}
		$query=PanelSearchSanitizer::value(trim((string)$this->query));
		$query=is_string($query) ? $query : '';
		$page=$query!=='' ? $this->samplePage($query) : PanelSearchPage::make();
		$results=$this->normalizeResults(array_map(static fn(PanelSearchResult $result): array=>$result->toArray(), $page->results()));
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
				'complete'=>$page->isComplete(),
				'partial'=>$page->isPartial(),
				'next_cursor'=>PanelSearchSanitizer::publicCursor($page->nextCursor()),
				'diagnostics'=>$page->diagnostics(),
			],
			'capabilities'=>self::capabilities($providers, $results, $page, $query!==''),
			'meta'=>PanelSearchSanitizer::map($this->meta),
		];
		PanelTrace::record('search.manifest.described', [
			'providers'=>count($providers),
			'query_length'=>strlen($query),
			'sampled'=>$query!=='',
			'results'=>count($results),
		]);
		return PanelManifestContract::stamp($manifest);
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

	/** @return array<string,array<string,mixed>> */
	private function customProviderManifests(): array {
		$request=$this->request ?? PanelRequest::fromArray([]);
		if($this->source instanceof PanelInstance || $this->source instanceof PanelManager){
			$providers=[];
			foreach($this->source->searchProviders($request, true) as $name=>$provider){
				if(!$provider instanceof PanelSearchProvider){ continue; }
				$data=$provider->toArray();
				$providers[$name]=[
					'kind'=>'custom',
					'name'=>$provider->name(),
					'label'=>(string)$data['label'],
					'description'=>$data['description'],
					'icon'=>$data['icon'],
					'sort'=>(int)$data['sort'],
					'limit'=>(int)$data['limit'],
					'columns'=>[],
					'tenant_scoped'=>(bool)$data['tenant_scoped'],
					'tenant_required'=>(bool)$data['tenant_required'],
					'queryable'=>(bool)$data['search_lazy'],
					'authorizes'=>(bool)$data['authorization_lazy'],
					'visible_lazy'=>(bool)$data['visible_lazy'],
					'authorization_lazy'=>(bool)$data['authorization_lazy'],
					'score_lazy'=>(bool)$data['score_lazy'],
					'dedupe_lazy'=>(bool)$data['dedupe_lazy'],
					'iterable_results'=>(bool)$data['iterable_results'],
					'page_results'=>(bool)$data['page_results'],
					'cursor_aware'=>(bool)$data['cursor_aware'],
					'meta'=>PanelSearchSanitizer::map($data['meta']),
				];
			}
			return $providers;
		}
		if(is_array($this->source)){
			$definitions=is_array($this->source['search_providers'] ?? null) ? $this->source['search_providers'] : [];
			$providers=[];
			foreach($definitions as $key=>$definition){
				if(!is_array($definition) || !empty($definition['hidden'])){ continue; }
				$name=Resource::normalizeName((string)($definition['name'] ?? $key));
				if($name===''){ continue; }
				$providers[$name]=PanelSearchSanitizer::map(array_replace(['kind'=>'custom','name'=>$name,'columns'=>[],'tenant_scoped'=>false], $definition));
			}
			return $providers;
		}
		return [];
	}

	/**
	 * Executes a bounded sample search and converts provider failures into rows.
	 *
	 * @param string $query Non-empty sample query.
	 * @return list<array<string,mixed>> Normalized search result rows or a single error row.
	 */
	/** Executes a bounded sample and preserves page diagnostics/cursors. */
	private function samplePage(string $query): PanelSearchPage {
		$request=$this->request ?? PanelRequest::fromArray([]);
		$limit=max(1, min(50, $this->limit));
		return self::safeSample(function()use($query,$request,$limit): PanelSearchPage {
			if($this->source instanceof PanelInstance){
				return $this->source->globalSearchPage($query, $request, $limit);
			}
			if($this->source instanceof PanelManager){
				return $this->source->globalSearchPage($query, $request, $limit);
			}
			return Panel::globalSearchPage($query, $request, $limit);
		});
	}

	/** Turns unexpected coordinator failures into bounded public diagnostics. */
	private static function safeSample(callable $resolver): PanelSearchPage {
		try { return $resolver(); }
		catch(\Throwable $exception){
			PanelTrace::record('search_manifest.sample_error', ['exception'=>$exception::class]);
			return PanelSearchPage::make(partial:true, diagnostics:[[
				'code'=>'sample_error',
				'message'=>'Search sample failed.',
				'severity'=>'error',
				'exception'=>$exception::class,
			]]);
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
				'key'=>$result['record_key'] ?? $result['key'] ?? $result['id'] ?? null,
				'score'=>is_numeric($result['score'] ?? null) ? (float)$result['score'] : 0.0,
				'provider'=>$result['provider'] ?? $result['source'] ?? $result['resource'] ?? null,
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
	private static function capabilities(array $providers, array $results, ?PanelSearchPage $page=null, bool $sampled=false): array {
		return [
			'providers'=>[
				'total'=>count($providers),
				'custom'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['kind'] ?? null)==='custom')),
				'resource'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['kind'] ?? null)==='resource')),
				'tenant_scoped'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['tenant_scoped'] ?? false)===true)),
				'queryable'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['queryable'] ?? false)===true)),
				'authorizing'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['authorizes'] ?? false)===true)),
				'ranked'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['score_lazy'] ?? false)===true)),
				'deduplicating'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['dedupe_lazy'] ?? false)===true)),
				'cursor_aware'=>count(array_filter($providers, static fn(array $provider): bool => ($provider['cursor_aware'] ?? false)===true)),
			],
			'columns'=>[
				'total'=>array_sum(array_map(static fn(array $provider): int => count($provider['columns'] ?? []), $providers)),
				'max_per_provider'=>max([0, ...array_map(static fn(array $provider): int => count($provider['columns'] ?? []), $providers)]),
			],
			'results'=>[
				'sampled'=>$sampled,
				'total'=>count($results),
				'with_urls'=>count(array_filter($results, static fn(array $result): bool => is_string($result['url'] ?? null) && trim((string)$result['url'])!=='')),
				'complete'=>$page?->isComplete() ?? true,
				'partial'=>$page?->isPartial() ?? false,
				'diagnostics'=>count($page?->diagnostics() ?? []),
			],
			'contracts'=>[
				'immutable_results'=>true,
				'page_results'=>true,
				'iterable_adapters'=>true,
				'deterministic_ranking'=>true,
				'cross_provider_deduplication'=>true,
				'bounded_budgets'=>true,
				'partial_diagnostics'=>true,
				'fake_async_blocking'=>false,
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
